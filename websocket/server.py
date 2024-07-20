import os
import pathlib
import socket
import signal
import asyncio
import logging
import ssl
import argparse
import socketio
from aiohttp import web
from google.cloud import texttospeech

class BotOfTheSpecterWebsocketServer:
    def __init__(self, logger):
        # Set up Google Cloud credentials
        os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = "/var/www/websocket/service-account-file.json"
        
        # Initialize the WebSocket server.
        self.logger = logger
        self.ip = self.get_own_ip()
        self.script_dir = os.path.dirname(os.path.realpath(__file__)).replace("\\", "/")
        self.registered_clients = {}
        self.sio = socketio.AsyncServer(logger=logger, engineio_logger=logger, cors_allowed_origins='*')
        self.app = web.Application()
        self.app.on_shutdown.append(self.on_shutdown)
        self.setup_routes()
        self.setup_event_handlers()
        self.sio.attach(self.app)
        self.loop = None
        signal.signal(signal.SIGTERM, self.sig_handler)
        signal.signal(signal.SIGINT, self.sig_handler)

        # Initialize Google Text-to-Speech client
        self.tts_client = texttospeech.TextToSpeechClient()

    def setup_routes(self):
        # Set up the routes for the web application.
        self.app.add_routes([
            web.get("/", self.index),
            web.get("/notify", self.notify),
            web.get("/include/{tail:.*}", self.static_file),
            web.get("/heartbeat", self.heartbeat),
            web.get("/clients", self.list_clients)
        ])

    def setup_event_handlers(self):
        # Set up the event handlers for the SocketIO server.
        event_handlers = [
            ("connect", self.connect),
            ("disconnect", self.disconnect),
            ("REGISTER", self.register),
            ("LIST_CLIENTS", self.list_clients_event),
            ("DEATH", self.death),
            ("TWITCH_FOLLOW", self.twitch_follow),
            ("TWITCH_CHEER", self.twitch_cheer),
            ("TWITCH_RAID", self.twitch_raid),
            ("TWITCH_SUB", self.twitch_sub),
            ("WALKON", self.walkon),
            ("TTS", self.tts),
            ("*", self.event)
        ]
        for event, handler in event_handlers:
            self.sio.on(event, handler)

    async def walkon(self, sid, data):
        # Handle the walkon event for SocketIO.
        self.logger.info(f"Walkon event from SID [{sid}]: {data}")
        # Broadcast the walkon event to all clients
        await self.sio.emit("WALKON", data)

    async def index(self, request):
        # Handle the index route.
        with open(os.path.join(self.script_dir, 'static', 'test.html'), "r", encoding="utf8") as f:
            return web.Response(text=f.read(), content_type='text/html')
    
    async def heartbeat(self, request):
        # Handle the heartbeat route.
        return web.json_response({"status": "OK"})

    async def list_clients(self, request):
        # List the registered clients.
        return web.json_response(self.registered_clients)

    async def list_clients_event(self, sid, data):
        # Handle the LIST_CLIENTS event for SocketIO.
        self.logger.info(f"LIST_CLIENTS event from SID [{sid}]")
        await self.sio.emit("LIST_CLIENTS", self.registered_clients, to=sid)

    async def static_file(self, request):
        # Serve static files.
        local_path = os.path.join(self.script_dir, request.rel_url.path[1:])
        if os.path.isfile(local_path):
            ext = pathlib.Path(local_path).suffix
            content_type = self.ext_to_content_type(ext)
            try:
                with open(local_path, "rb" if "text" not in content_type else "r", encoding=None if "text" not in content_type else "utf8") as f:
                    return web.Response(body=f.read() if "text" not in content_type else None, text=f.read() if "text" in content_type else None, content_type=content_type)
            except UnicodeDecodeError as e:
                self.logger.error(f"Unicode Decode error reading file {local_path}: {e}")
                raise web.HTTPServerError()
        raise web.HTTPNotFound()

    async def notify(self, request):
        # Handle the notify route.
        code = request.query.get("code")
        event = request.query.get("event")
        text = request.query.get("text")
        self.logger.info(f"Received notify request with code: {code}, event: {event}, text: {text}")
        if not code or not event:
            raise web.HTTPBadRequest(text="400 Bad Request: code or event is missing")
        data = {k: v for k, v in request.query.items()}
        event = event.upper().replace(" ", "_")
        if event == "TTS" and text:
            response = self.generate_speech(text)
            audio_file = f'tts_output_{code}.mp3'
            with open(audio_file, 'wb') as out:
                out.write(response.audio_content)
                self.logger.info(f'Audio content written to file "{audio_file}"')
            data['audio_file'] = audio_file
        count = 0
        for sid, registered_code in self.registered_clients.items():
            if registered_code == code:
                count += 1
                await self.sio.emit(event, data, sid)
                self.logger.info(f"Emitted event '{event}' to client {sid}")
        self.logger.info(f"Broadcasted event to {count} clients")
        return web.json_response({"success": 1, "count": count, "msg": f"Broadcasted event to {count} clients"})

    def generate_speech(self, text):
        input_text = texttospeech.SynthesisInput(text=text)
        voice = texttospeech.VoiceSelectionParams(
            language_code="en-US",
            ssml_gender=texttospeech.SsmlVoiceGender.NEUTRAL
        )
        audio_config = texttospeech.AudioConfig(audio_encoding=texttospeech.AudioEncoding.MP3)
        response = self.tts_client.synthesize_speech(
            input=input_text, voice=voice, audio_config=audio_config
        )
        return response

    async def event(self, sid, event, data):
        # Handle generic events for SocketIO.
        self.logger.debug(f"Event {event} from SID [{sid}]: {data}")

    async def connect(self, sid, environ, auth):
        # Handle the connect event for SocketIO.
        self.logger.info(f"Connect event: {sid}")
        if environ["REMOTE_ADDR"] in ['0.0.0.0', self.ip]:
            self.logger.debug(f"Client [{sid}] is a local user with elevated access")
        self.logger.debug(environ)
        await self.sio.emit("WELCOME", {"message": "Please register your code"}, to=sid)

    async def disconnect(self, sid):
        # Handle the disconnect event for SocketIO.
        self.logger.info(f"Disconnect event: {sid}")
        code = self.registered_clients.pop(sid, None)
        if code:
            self.logger.info(f"Unregistered SID [{sid}] successfully")
        else:
            self.logger.info(f"Unregistering SID [{sid}] failed. Code not found.")

    async def register(self, sid, data):
        # Handle the register event for SocketIO.
        code = data.get("code")
        self.logger.info(f"Register event received from SID {sid} with code: {code}")
        if code:
            self.logger.info(f"Client [{sid}] registered with code: {code}")
            self.registered_clients[sid] = code
            self.logger.info(f"Total registered clients: {len(self.registered_clients)}")
        else:
            self.logger.info("Code not provided")

    async def death(self, sid, data):
        # Handle the death event for SocketIO.
        self.logger.info(f"Death event from SID [{sid}]: {data}")
        # Broadcast the death event to all clients
        await self.sio.emit("DEATH", data)

    async def twitch_follow(self, sid, data):
        # Handle the Twitch follow event for SocketIO.
        self.logger.info(f"Twitch follow event from SID [{sid}]: {data}")
        # Broadcast the follow event to all clients
        await self.sio.emit("TWITCH_FOLLOW", data)

    async def twitch_cheer(self, sid, data):
        # Handle the Twitch cheer event for SocketIO.
        self.logger.info(f"Twitch cheer event from SID [{sid}]: {data}")
        # Broadcast the cheer event to all clients
        await self.sio.emit("TWITCH_CHEER", data)

    async def twitch_raid(self, sid, data):
        # Handle the Twitch raid event for SocketIO.
        self.logger.info(f"Twitch raid event from SID [{sid}]: {data}")
        # Broadcast the raid event to all clients
        await self.sio.emit("TWITCH_RAID", data)

    async def twitch_sub(self, sid, data):
        # Handle the Twitch sub event for SocketIO.
        self.logger.info(f"Twitch sub event from SID [{sid}]: {data}")
        # Broadcast the sub event to all clients
        await self.sio.emit("TWITCH_SUB", data)

    async def tts(self, sid, data):
        # Handle the TTS event for SocketIO.
        self.logger.info(f"TTS event from SID [{sid}]: {data}")
        text = data.get("text")
        if text:
            response = self.generate_speech(text)
            audio_file = f'tts_output_{sid}.mp3'
            with open(audio_file, 'wb') as out:
                out.write(response.audio_content)
                self.logger.info(f'Audio content written to file "{audio_file}"')

            # Send the audio file path to the requesting client
            await self.sio.emit("TTS_AUDIO", {"audio_file": audio_file}, to=sid)

    def generate_speech(self, text):
        input_text = texttospeech.SynthesisInput(text=text)
        voice = texttospeech.VoiceSelectionParams(
            language_code="en-US",
            ssml_gender=texttospeech.SsmlVoiceGender.NEUTRAL
        )
        audio_config = texttospeech.AudioConfig(audio_encoding=texttospeech.AudioEncoding.MP3)
        response = self.tts_client.synthesize_speech(
            input=input_text, voice=voice, audio_config=audio_config
        )
        return response

    async def send_notification(self, message):
        # Broadcast a notification to all registered clients
        for sid in self.registered_clients.keys():
            await self.sio.emit("NOTIFY", {"message": message}, to=sid)

    def run_app(self, host="0.0.0.0", port=8080):
        # Run the web application.
        self.logger.info("=== Starting BotOfTheSpecter Websocket Server ===")
        self.logger.info(f"Host: {host} Port: {port}")
        self.loop = asyncio.new_event_loop()
        web.run_app(self.app, 
                    loop=self.loop, 
                    host=host, 
                    port=port, 
                    ssl_context=self.create_ssl_context(), 
                    handle_signals=True, 
                    shutdown_timeout=10)
    
    def stop(self):
        # Stop the SocketIO server.
        self.logger.info("Stopping SocketIO Server")
        future = asyncio.run_coroutine_threadsafe(self.sio.shutdown(), self.loop)
        try:
            future.result(5)
        except TimeoutError:
            self.logger.error("Timeout error - SocketIO Server didn't respond. Forcing close.")
        raise web.GracefulExit

    def create_ssl_context(self):
        # Create the SSL context for secure connections.
        ssl_context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
        ssl_context.load_cert_chain(certfile='/etc/letsencrypt/live/botofthespecter.com-0001/fullchain.pem', keyfile='/etc/letsencrypt/live/botofthespecter.com-0001/privkey.pem')
        return ssl_context

    @staticmethod
    def ext_to_content_type(ext, default="text/html"):
        # Get the content type based on file extension.
        content_types = {
            ".js": "text/javascript",
            ".css": "text/css",
            ".json": "application/json",
            ".png": "image/png",
            ".jpg": "image/jpeg",
            ".gif": "image/gif",
            ".ico": "image/vnd.microsoft.icon"
        }
        return content_types.get(ext, default)

    @staticmethod
    def get_own_ip():
        # Get the IP address of the current machine.
        hostname = socket.gethostname()
        return socket.gethostbyname(hostname)

if __name__ == '__main__':
    SCRIPT_DIR = os.path.dirname(__file__)

    parser = argparse.ArgumentParser(
        prog='BotOfTheSpecter Websocket Server',
        description='A WebSocket server for handling notifications and real-time communication between the website and the bot itself.'
    )

    parser.add_argument("-H", "--host", default="0.0.0.0", help="Specify the listener host. Default is 0.0.0.0")
    parser.add_argument("-p", "--port", default=8080, type=int, help="Specify the listener port number. Default is 8080")
    parser.add_argument("-l", "--loglevel", choices=["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"], default="INFO", help="Specify the log level. INFO is the default.")
    parser.add_argument("-f", "--logfile", help="Specify log file location. Production location should be <WEBROOT>/log/noti_server.log")

    args = parser.parse_args()
    log_level = logging.getLevelName(args.loglevel)
    log_file = args.logfile if args.logfile else os.path.join(SCRIPT_DIR, "noti_server.log")

    logging.basicConfig(
        filename=log_file,
        level=log_level,
        filemode="a",
        format="%(asctime)s - %(levellevel)s - %(message)s"
    )

    logging.getLogger().addHandler(logging.StreamHandler())

    server = BotOfTheSpecterWebsocketServer(logging)
    server.run_app(host=args.host, port=args.port)