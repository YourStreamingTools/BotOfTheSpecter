import os
import socket
import signal
import asyncio
import logging
import ssl
import argparse
import socketio
from aiohttp import web
from google.cloud import texttospeech
import ipaddress
import paramiko
import uuid
from dotenv import load_dotenv, find_dotenv
import json
import aiomysql
from music_handler import MusicHandler

# Load ENV file
load_dotenv(find_dotenv("/home/websocket/.env"))

class BotOfTheSpecter_WebsocketServer:
    def __init__(self, logger):
        # Set up Google Cloud credentials
        os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = "/home/websocket/service-account-file.json"
        # Initialize the WebSocket server.
        self.logger = logger
        self.ip = self.get_own_ip()
        self.script_dir = os.path.dirname(os.path.realpath(__file__)).replace("\\", "/")
        self.tts_dir = "/home/websocket/tts"
        self.registered_clients = {}
        self.sio = socketio.AsyncServer(
            logger=logger, 
            engineio_logger=logger, 
            cors_allowed_origins='*', 
            ping_timeout=30,
            ping_interval=25
        )
        self.app = web.Application(middlewares=[self.ip_restriction_middleware])
        self.app.on_shutdown.append(self.on_shutdown)
        self.setup_routes()
        self.music_handler = MusicHandler(
            sio=self.sio,
            logger=self.logger,
            get_clients=lambda: self.registered_clients,
            save_settings=self.save_music_settings,
            load_settings=self.load_music_settings,
        )
        self.setup_event_handlers()
        self.sio.attach(self.app)
        self.loop = None
        signal.signal(signal.SIGTERM, self.sig_handler)
        signal.signal(signal.SIGINT, self.sig_handler)
        self.tts_client = texttospeech.TextToSpeechClient()
        self.tts_queue = asyncio.Queue()
        self.processing_task = None
        ips_file = "/home/websocket/ips.txt"
        self.allowed_ips = self.load_ips(ips_file)

    def load_ips(self, ips_file):
        allowed_ips = []
        try:
            with open(ips_file, 'r') as file:
                for line in file:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        allowed_ips.append(ipaddress.ip_network(line))
        except FileNotFoundError:
            self.logger.error(f"IPs file not found.")
        return allowed_ips

    def is_ip_allowed(self, ip):
        ip_address = ipaddress.ip_address(ip)
        for allowed_ip in self.allowed_ips:
            if ip_address in allowed_ip:
                return True
        return False

    def setup_routes(self):
        # Set up the routes for the web application.
        self.app.add_routes([
            web.get("/", self.index),
            web.get("/notify", self.notify_http),
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
            ("NOTIFY", self.notify),
            ("DEATHS", self.deaths),
            ("WEATHER", self.weather),
            ("WEATHER_DATA", self.weather_data),
            ("TWITCH_FOLLOW", self.twitch_follow),
            ("TWITCH_CHEER", self.twitch_cheer),
            ("TWITCH_RAID", self.twitch_raid),
            ("TWITCH_SUB", self.twitch_sub),
            ("TWITCH_CHANNELPOINTS", self.twitch_channelpoints),
            ("WALKON", self.walkon),
            ("TTS", self.tts),
            ("SOUND_ALERT", self.sound_alert),
            ("VIDEO_ALERT", self.video_alert),
            ("STREAM_ONLINE", self.stream_online),
            ("STREAM_OFFLINE", self.stream_offline),
            ("DISCORD_JOIN", self.discord_join),
            ("FOURTHWALL", self.handle_fourthwall_event),
            ("KOFI", self.handle_kofi_event),
            ("PATREON", self.handle_patreon_event),
            ("SEND_OBS_EVENT", self.handle_obs_event),
            ("MUSIC_COMMAND", self.music_handler.music_command),
            ("*", self.event)
        ]
        for event, handler in event_handlers:
            self.sio.on(event, handler)

    async def ip_restriction_middleware(self, app, handler):
        async def middleware_handler(request):
            if request.path in ['/clients', '/notify']:
                peername = request.transport.get_extra_info('peername')
                if peername is not None:
                    ip = peername[0]
                    if not self.is_ip_allowed(ip):
                        self.logger.warning(f"Unauthorized access attempt from IP: {ip}")
                        return web.HTTPForbidden(text="403 Forbidden: Access denied")
            return await handler(request)
        return middleware_handler

    async def event(self, sid, event, data):
        # Handle generic events for SocketIO.
        self.logger.debug(f"Event {event} from SID [{sid}]: {data}")
        # Relay NOW_PLAYING and MUSIC_SETTINGS to all clients with same code
        if event in ("NOW_PLAYING", "MUSIC_SETTINGS"):
            code = None
            for c, clients in self.registered_clients.items():
                for client in clients:
                    if client['sid'] == sid:
                        code = c
                        break
                if code:
                    break
            if code:
                for client in self.registered_clients[code]:
                    if client['sid'] != sid:
                        await self.sio.emit(event, data, to=client['sid'])
                self.logger.info(f"Relayed {event} from SID [{sid}] to other clients for code {code}")
            # Save settings if MUSIC_SETTINGS event received
            if event == "MUSIC_SETTINGS" and code:
                self.save_music_settings(code, data)
                self.logger.info(f"Saved MUSIC_SETTINGS from event for code {code}")

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
        # Iterate through all registered clients and remove the disconnected SID
        for code, sids in list(self.registered_clients.items()):
            if sid in sids:
                sids.remove(sid)
                self.logger.info(f"Unregistered SID [{sid}] from code [{code}]")
                if not sids:
                    del self.registered_clients[code]
                    self.logger.info(f"No more clients for code [{code}]. Removed code from registered clients.")
                break
        else:
            self.logger.info(f"SID [{sid}] not found in registered clients.")
        # Log the current state of registered clients after disconnect
        self.logger.info(f"Current registered clients: {self.registered_clients}")

    async def register(self, sid, data):
        # Handle the register event for SocketIO.
        code = data.get("code")
        channel = str(data.get("channel", "Unknown-Channel"))
        sid_name = data.get("name", f"Unnamed-{sid}")
        self.logger.info(f"Register event received from SID {sid} with code: '{code}', channel: '{channel}', name: '{sid_name}'")
        name = f"{channel} - {sid_name}"
        if code:
            # Initialize the list for the code if it doesn't exist
            if code not in self.registered_clients:
                self.registered_clients[code] = []
            # Check if there's already a client with the same name
            for client in self.registered_clients[code]:
                if client['name'] == name:
                    # Disconnect the old session
                    old_sid = client['sid']
                    self.logger.info(f"Disconnecting old session [{old_sid}] for name [{name}] before registering new session [{sid}]")
                    await self.sio.emit("ERROR", {"message": f"Disconnected: Duplicate session for name {name}"}, to=old_sid)
                    await self.sio.disconnect(old_sid)
                    # Remove the old client
                    self.registered_clients[code] = [c for c in self.registered_clients[code] if c['sid'] != old_sid]
                    break # Register the new client
            client_data = {"sid": sid, "name": name}
            self.registered_clients[code].append(client_data)
            self.logger.info(f"Client [{sid}] with name [{name}] registered with code: {code}")
            # Log registration activity to database (if available)
            try:
                user_info = await self.get_user_api_key_info(code)
                if user_info:
                    channel_name = user_info.get('username', 'unknown')
                    await self.log_websocket_activity(channel_name, 'client_registration', {
                        'sid': sid,
                        'name': name,
                        'channel': channel
                    })
            except Exception as e:
                self.logger.debug(f"Database logging failed (non-critical): {e}")
            # Send success message to the new client
            await self.sio.emit("SUCCESS", {"message": "Registration successful", "code": code, "name": name}, to=sid)
            self.logger.info(f"Total registered clients for code {code}: {len(self.registered_clients[code])}")
        else:
            self.logger.warning("Code not provided during registration")
            await self.sio.emit("ERROR", {"message": "Registration failed: code missing"}, to=sid)
        # Log the current state of registered clients after registration
        self.logger.info(f"Current registered clients: {self.registered_clients}")

    async def index(self, request):
        # Redirect to the main page
        raise web.HTTPFound(location="https://botofthespecter.com")
    
    async def heartbeat(self, request):
        if request.method == 'OPTIONS':
            response = web.Response(status=204)
        else:
            response = web.json_response({"status": "OK"})
        response.headers['Access-Control-Allow-Origin'] = '*'
        response.headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS'
        response.headers['Access-Control-Allow-Headers'] = 'Content-Type'
        return response

    async def list_clients(self, request):
        # List the registered clients.
        return web.json_response(self.registered_clients)

    async def list_clients_event(self, sid):
        # Handle the LIST_CLIENTS event for SocketIO.
        self.logger.info(f"LIST_CLIENTS event from SID [{sid}]")
        await self.sio.emit("LIST_CLIENTS", self.registered_clients, to=sid)

    async def notify_http(self, request):
        # Extract query parameters
        code = request.query.get("code")
        event = request.query.get("event")
        text = request.query.get("text")
        language_code = request.query.get("language_code", None)
        gender = request.query.get("gender", None)
        voice_name = request.query.get("voice_name", None)
        # Validate mandatory parameters
        if not code:
            raise web.HTTPBadRequest(text="400 Bad Request: API Key is missing")
        if not event:
            raise web.HTTPBadRequest(text="400 Bad Request: Event is missing")
        # Log incoming request data
        data = {k: v for k, v in request.query.items()}
        self.logger.info(f"Notify request data: {data}")
        event = event.upper().replace(" ", "_")
        count = 0
        if event == "TTS" and text:
            # Add TTS request to queue with additional parameters
            await self.tts_queue.put({"text": text, "session_id": code, "language_code": language_code, "gender": gender,"voice_name": voice_name})
            self.logger.info(f"TTS request added to queue: {text}")
        elif event == "FOURTHWALL":
            # Handle Fourthwall-specific event
            await self.handle_fourthwall_event(code, data)
        elif event == "KOFI":
            # Handle Ko-Fi-specific event
            await self.handle_kofi_event(code, data)
        elif event == "PATREON":
            # Handle Patreon-specific event
            await self.handle_patreon_event(code, data)
        else:
            # Broadcast other events to connected clients
            if code in self.registered_clients:
                for client in self.registered_clients[code]:
                    sid = client['sid']
                    await self.sio.emit(event, data, to=sid)
                    self.logger.info(f"Emitted event '{event}' to client {sid}")
                    count += 1
            self.logger.info(f"Broadcasted event to {count} clients")
        # Return a JSON response indicating success
        return web.json_response({"success": 1, "count": count, "msg": f"Broadcasted event to {count} clients"})

    async def notify(self, sid, data):
        self.logger.info(f"Notify event from SID [{sid}]: {data}")
        event = data.get('event')
        if not event:
            self.logger.error('Missing event information for NOTIFY event')
            return
        # Broadcast the event to all clients
        await self.sio.emit(event, data, sid)

    async def process_tts_queue(self):
        while True:
            try:
                # Wait for the next TTS request in the queue
                request_data = await self.tts_queue.get()
                # Unpack the data
                text = request_data.get('text')
                session_id = request_data.get('session_id')
                language_code = request_data.get('language_code', None)
                gender = request_data.get('gender', None)
                voice_name = request_data.get('voice_name', None)
                # Log the request
                self.logger.info(f"Dequeued TTS request for session ID: {session_id} with text: {text}")
                # Process the request in a separate task
                asyncio.create_task(self.process_tts_request(text, session_id, language_code, gender, voice_name))
                # Mark the task as done
                self.tts_queue.task_done()
                self.logger.info(f"TTS request for session ID {session_id} marked as done")
            except Exception as e:
                # Log and handle any errors that occur during queue processing
                self.logger.error(f"Error while processing TTS queue: {e}")

    async def process_tts_request(self, text, code, language_code=None, gender=None, voice_name=None):
        # Generate the TTS audio
        self.logger.info(f"Processing TTS request for code {code} with text: {text}")
        response = self.generate_speech(text, language_code, gender, voice_name)
        if response is None:
            self.logger.error(f"Failed to generate speech for text: {text}")
            return
        # Generate a unique filename using code and timestamp/UUID
        unique_id = uuid.uuid4().hex[:8]
        audio_file = os.path.join(self.tts_dir, f'tts_output_{code}_{unique_id}.mp3')
        # Save the generated audio to a file
        try:
            with open(audio_file, 'wb') as out:
                out.write(response.audio_content)
            self.logger.info(f'Audio content written to file "{audio_file}"')
        except Exception as e:
            self.logger.error(f'Failed to write audio content to file "{audio_file}": {e}')
            return
        try:
            # Attempt to transfer the file via SFTP
            await self.sftp_transfer(audio_file)
            self.logger.info(f'File "{audio_file}" successfully transferred to SFTP server.')
            # Emit the TTS event only if the file was successfully transferred
            sids = self.registered_clients.get(code, [])
            if sids:
                self.logger.info(f"Emitting TTS event to clients with code {code}")
                for sid in sids:
                    self.logger.info(f"Emitting TTS event to SID {sid}")
                    try:
                        if sid and isinstance(sid, dict) and 'sid' in sid:
                            await self.sio.emit("TTS", {"audio_file": f"https://tts.botofthespecter.com/{os.path.basename(audio_file)}"}, to=sid['sid'])
                        else:
                            self.logger.error(f"Invalid SID structure for code {code}: {sid}")
                    except KeyError as e:
                        self.logger.error(f"KeyError while emitting TTS event: {e}")
            else:
                self.logger.error(f"No clients found with code {code}. Unable to emit TTS event.")
        except Exception as e:
            self.logger.error(f'Failed to transfer file "{audio_file}" via SFTP: {e}')
            return
        # Estimate the duration of the audio and wait for it to finish
        duration = self.estimate_duration(response)
        self.logger.info(f"TTS event emitted. Waiting for {duration} seconds before continuing.")
        await asyncio.sleep(duration + 5)
        # After playback, delete the TTS file from the SFTP server
        try:
            await self.sftp_delete(audio_file)
            self.logger.info(f'Audio file "{audio_file}" successfully deleted from SFTP server.')
        except Exception as e:
            self.logger.error(f'Failed to delete audio file "{audio_file}" from SFTP server: {e}')

    def generate_speech(self, text, language_code=None, gender=None, voice_name=None):
        voice_params = {
            "language_code": language_code if language_code else "en-US",
            "ssml_gender": getattr(texttospeech.SsmlVoiceGender, gender.upper() if gender else "NEUTRAL", texttospeech.SsmlVoiceGender.NEUTRAL),
        }
        if voice_name:
            voice_params["name"] = voice_name
        input_text = texttospeech.SynthesisInput(text=text)
        voice = texttospeech.VoiceSelectionParams(**voice_params)
        audio_config = texttospeech.AudioConfig(audio_encoding=texttospeech.AudioEncoding.MP3)
        try:
            response = self.tts_client.synthesize_speech(
                input=input_text, voice=voice, audio_config=audio_config
            )
            if not hasattr(response, 'audio_content'):
                raise ValueError("TTS API response does not contain audio content.")
            return response
        except Exception as e:
            self.logger.error(f"Failed to generate speech: {e}")
            return None

    async def sftp_transfer(self, local_file_path):
        # Set up the SFTP connection details from .env file
        hostname = "10.240.0.169"
        username = os.getenv("SFTP_USERNAME")
        password = os.getenv("SFTP_PASSWORD")
        remote_file_path = "/var/www/tts/" + os.path.basename(local_file_path)
        try:
            # Establish an SFTP session
            transport = paramiko.Transport((hostname, 22))
            transport.connect(username=username, password=password)
            sftp = paramiko.SFTPClient.from_transport(transport)
            # Upload the file
            sftp.put(local_file_path, remote_file_path)
            self.logger.info(f"File {local_file_path} transferred to {remote_file_path} on webserver {hostname}")
            # Close the SFTP session
            sftp.close()
            transport.close()
        except Exception as e:
            self.logger.error(f"Failed to transfer file via SFTP: {e}")

    async def sftp_delete(self, local_file_path):
        # Set up the SFTP connection details from .env file
        hostname = "10.240.0.169"
        username = os.getenv("SFTP_USERNAME")
        password = os.getenv("SFTP_PASSWORD")
        remote_file_path = "/var/www/tts/" + os.path.basename(local_file_path)
        try:
            # Establish an SFTP session
            transport = paramiko.Transport((hostname, 22))
            transport.connect(username=username, password=password)
            sftp = paramiko.SFTPClient.from_transport(transport)
            # Delete the file
            sftp.remove(remote_file_path)
            self.logger.info(f"File {remote_file_path} deleted from webserver {hostname}")
            # Close the SFTP session
            sftp.close()
            transport.close()
        except Exception as e:
            self.logger.error(f"Failed to delete file via SFTP: {e}")

    def estimate_duration(self, response):
        # Calculate the duration based on the audio content length and bitrate
        return len(response.audio_content) / 64000 # Duration in seconds for 64kbps MP3

    async def walkon(self, sid, data):
        # Handle the walkon event for SocketIO.
        self.logger.info(f"Walkon event from SID [{sid}]: {data}")
        channel = data.get('channel')
        user = data.get('user')
        ext = data.get('ext', 'mp3')
        if not channel or not user:
            self.logger.error('Missing channel or user information for WALKON event')
            return
        walkon_data = {
            'channel': channel,
            'user': user,
            'ext': ext
        }
        # Broadcast the walkon event to all clients
        await self.sio.emit("WALKON", walkon_data)

    async def handle_fourthwall_event(self, code, data):
        # Log and broadcast the FOURTHWALL event to the clients
        self.logger.info(f"Handling FOURTHWALL event with data: {data}")
        count = 0
        if code in self.registered_clients:
            for client in self.registered_clients[code]:
                sid = client['sid']
                await self.sio.emit("FOURTHWALL", data, to=sid)
                self.logger.info(f"Emitted FOURTHWALL event to client {sid}")
                count += 1
        self.logger.info(f"Broadcasted FOURTHWALL event to {count} clients")

    async def handle_kofi_event(self, code, data):
        # Log and broadcast the KOFI event to the clients
        self.logger.info(f"Handling KOFI event with data: {data}")
        count = 0
        if code in self.registered_clients:
            for client in self.registered_clients[code]:
                sid = client['sid']
                await self.sio.emit("KOFI", data, to=sid)
                self.logger.info(f"Emitted KOFI event to client {sid}")
                count += 1
        self.logger.info(f"Broadcasted KOFI event to {count} clients")

    async def handle_patreon_event(self, code, data):
        # Log and broadcast the PATREON event to the clients
        self.logger.info(f"Handling PATREON event with data: {data}")
        count = 0
        if code in self.registered_clients:
            for client in self.registered_clients[code]:
                sid = client['sid']
                await self.sio.emit("PATREON", data, to=sid)
                self.logger.info(f"Emitted PATREON event to client {sid}")
                count += 1
        self.logger.info(f"Broadcasted PATREON event to {count} clients")

    async def handle_obs_event(self, sid, data):
        # Handle the OBS_EVENT event
        self.logger.info(f"SEND_OBS_EVENT received from SID [{sid}]: {data}")
        # Process the data here (e.g., extract event-name, scene-name, etc.)
        # and decide how to handle different OBS events at a later time.

    async def deaths(self, sid, data):
        self.logger.info(f"Death event from SID [{sid}]: {data}")
        death_text = data.get('death-text')
        game = data.get('game')
        if not death_text or not game:
            self.logger.error('Missing death-text or game information for DEATHS event')
            return
        death_data = {
            'death-text': death_text,
            'game': game
        }
        self.logger.info(f"Broadcasting DEATHS event with data: {death_data}")
        # Broadcast the death event to all clients
        await self.sio.emit("DEATHS", death_data)

    async def tts(self, sid, data):
        # Log the incoming TTS request
        self.logger.info(f"TTS event from SID [{sid}]: {data}")
        # Extract required and optional parameters from the data
        text = data.get("text")
        language_code = data.get("language_code", None)
        gender = data.get("gender", None)
        voice_name = data.get("voice_name", None)
        if text:
            # Add the TTS request to the queue with all parameters
            await self.tts_queue.put({"text": text, "session_id": sid, "language_code": language_code, "gender": gender, "voice_name": voice_name})
            self.logger.info(f"TTS request added to queue from SID [{sid}]: {text}")
        else:
            # Log an error if no text was provided
            self.logger.error(f"No text provided in TTS event from SID [{sid}]")

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

    async def twitch_channelpoints(self, sid, data):
        # Handle the Twitch Channel Points event for SocketIO.
        self.logger.info(f"Twitch Channel Points event from SID [{sid}]: {data}")
        # Broadcast the Channel Points event to all clients
        await self.sio.emit("TWITCH_CHANNELPOINTS", data)

    async def stream_online(self, sid, data):
        # Handle the STREAM_ONLINE event for SocketIO.
        self.logger.info(f"Stream online event from SID [{sid}]: {data}")
        # Broadcast the stream online event to all clients
        await self.sio.emit("STREAM_ONLINE", data)

    async def stream_offline(self, sid, data):
        # Handle the STREAM_OFFLINE event for SocketIO.
        self.logger.info(f"Stream offline event from SID [{sid}]: {data}")
        # Broadcast the stream offline event to all clients
        await self.sio.emit("STREAM_OFFLINE", data)

    async def weather(self, sid, data):
        self.logger.info(f"Weather event from SID [{sid}]: {data}")
        weather_data = data.get("weather_data")
        if not weather_data:
            self.logger.error('Missing weather data for WEATHER event')
            return
        self.logger.info(f"Broadcasting WEATHER event with data: {weather_data}")
        # Broadcast the weather event to all clients
        await self.sio.emit("WEATHER", weather_data)

    async def weather_data(self, sid, data):
        self.logger.info(f"Weather data event from SID [{sid}]: {data}")
        weather_data = data.get("weather_data")
        if not weather_data:
            self.logger.error('Missing weather data for WEATHER_DATA event')
            return
        self.logger.info(f"Broadcasting WEATHER_DATA event with data: {weather_data}")
        # Broadcast the weather data event to all clients
        await self.sio.emit("WEATHER_DATA", weather_data)

    async def discord_join(self, sid, data):
        # Handle the DISCORD_JOIN event for SocketIO.
        self.logger.info(f"DISCORD_JOIN event from SID [{sid}]: {data}")
        member = data.get("member")
        if not member:
            self.logger.error("Missing member information for DISCORD_JOIN event")
            return
        join_data = {
            "member": member
        }
        # Broadcast the DISCORD_JOIN event to all clients
        await self.sio.emit("DISCORD_JOIN", join_data)

    async def sound_alert(self, sid, data):
        self.logger.info(f"Sound Alert event from SID [{sid}]: {data}")
        await self.sio.emit("SOUND_ALERT", data)

    async def video_alert(self, sid, data):
        self.logger.info(f"Video Alert event from SID [{sid}]: {data}")
        await self.sio.emit("VIDEO_ALERT", data)

    async def send_notification(self, message):
        # Broadcast a notification to all registered clients
        for sid in self.registered_clients.keys():
            await self.sio.emit("NOTIFY", {"message": message}, to=sid)

    async def on_shutdown(self, app):
        self.logger.info("Shutting down...")
        # Disconnect all registered clients properly
        for code, sids in list(self.registered_clients.items()):
            for client in sids:
                sid = client['sid']
                self.logger.info(f"Disconnecting SID [{sid}] during shutdown.")
                try:
                    await self.sio.disconnect(sid)
                except Exception as e:
                    self.logger.error(f"Error disconnecting SID [{sid}]: {e}")
        self.logger.info("All clients disconnected.")

    def sig_handler(self, signum, frame):
        # Handle system signals for graceful shutdown.
        signame = signal.Signals(signum).name
        self.logger.info(f'Caught signal {signame} ({signum})')
        self.stop()
        self.logger.info("Server stopped")

    def run_app(self, host="0.0.0.0", port=443):
        # Run the web application.
        self.logger.info("=== Starting BotOfTheSpecter Websocket Server ===")
        # Test database connection first
        self.loop = asyncio.new_event_loop()
        asyncio.set_event_loop(self.loop)
        db_test_result = self.loop.run_until_complete(self.test_database_connection())
        if not db_test_result:
            self.logger.warning("⚠ Database connection test failed, but server will continue starting...")
        # Try to create SSL context
        ssl_context = self.create_ssl_context()
        if ssl_context is not None:
            # SSL certificates available - run secure server
            self.logger.info(f"🔒 Starting secure WebSocket server on {host}:{port}")
            self.processing_task = self.loop.create_task(self.process_tts_queue())
            web.run_app(self.app, loop=self.loop, host=host, port=port, ssl_context=ssl_context, handle_signals=True, shutdown_timeout=10)
        else:
            # No SSL certificates - fallback to insecure server
            fallback_port = 80 if port == 443 else port
            self.logger.warning(f"⚠ Starting insecure WebSocket server on {host}:{fallback_port}")
            self.logger.warning("  Consider setting up SSL certificates for production use.")
            self.processing_task = self.loop.create_task(self.process_tts_queue())
            web.run_app(self.app, loop=self.loop, host=host, port=fallback_port, ssl_context=None, handle_signals=True, shutdown_timeout=10)

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
        domain = 'websocket.botofthespecter.com'
        # Check for Let's Encrypt certificates first
        letsencrypt_cert = f'/etc/letsencrypt/live/{domain}/fullchain.pem'
        letsencrypt_key = f'/etc/letsencrypt/live/{domain}/privkey.pem'
        # Local certificate paths as fallback
        local_cert = '/home/websocket/ssl/fullchain.pem'
        local_key = '/home/websocket/ssl/privkey.pem'
        ssl_context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
        # Try Let's Encrypt certificates first
        if os.path.exists(letsencrypt_cert) and os.path.exists(letsencrypt_key):
            try:
                ssl_context.load_cert_chain(certfile=letsencrypt_cert, keyfile=letsencrypt_key)
                self.logger.info(f"✓ Using Let's Encrypt SSL certificates for {domain}")
                return ssl_context
            except Exception as e:
                self.logger.warning(f"Failed to load Let's Encrypt certificates: {e}")
        else:
            self.logger.info("Let's Encrypt certificates not found, checking local certificates...")
        # Fallback to local certificates
        if os.path.exists(local_cert) and os.path.exists(local_key):
            try:
                ssl_context.load_cert_chain(certfile=local_cert, keyfile=local_key)
                self.logger.info("✓ Using local SSL certificates")
                return ssl_context
            except Exception as e:
                self.logger.error(f"Failed to load local certificates: {e}")
        else:
            self.logger.warning("Local SSL certificates not found")
        # No SSL certificates available
        self.logger.error("✗ No SSL certificates found. Server will not start with SSL.")
        return None

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

    def save_music_settings(self, code, settings):
        MUSIC_SETTINGS_DIR = "/home/websocket/music-settings"
        os.makedirs(MUSIC_SETTINGS_DIR, exist_ok=True)
        settings_file = os.path.join(MUSIC_SETTINGS_DIR, f"{code}.json")
        try:
            # If file exists, update existing settings
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    current = json.load(f)
            else:
                current = {}
            for k in ("repeat", "shuffle"):
                if k in settings:
                    settings[k] = bool(settings[k])
            # Add validation for volume to ensure it's an integer between 0-100
            if 'volume' in settings:
                try:
                    settings['volume'] = max(0, min(100, int(settings['volume'])))
                except ValueError:
                    settings.pop('volume')
                    self.logger.warning(f"Invalid volume value in settings for {code}")
            current.update(settings)
            with open(settings_file, "w") as f:
                json.dump(current, f)
        except Exception as e:
            self.logger.error(f"Failed to save music settings for {code}: {e}")

    def load_music_settings(self, code):
        MUSIC_SETTINGS_DIR = "/home/websocket/music-settings"
        settings_file = os.path.join(MUSIC_SETTINGS_DIR, f"{code}.json")
        try:
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    settings = json.load(f)
                    for k in ("repeat", "shuffle"):
                        if k in settings:
                            settings[k] = bool(settings[k])
                    return settings
        except Exception as e:
            self.logger.error(f"Failed to load music settings for {code}: {e}")
        return None

    async def get_database_connection(self, database_name='website'):
        try:
            # Get database configuration from environment variables
            db_host = os.getenv('SQL_HOST')
            db_user = os.getenv('SQL_USER')
            db_password = os.getenv('SQL_PASSWORD')
            db_port = os.getenv('SQL_PORT')
            # Validate required environment variables
            if not all([db_host, db_user, db_password, db_port]):
                missing_vars = []
                if not db_host: missing_vars.append('SQL_HOST')
                if not db_user: missing_vars.append('SQL_USER')
                if not db_password: missing_vars.append('SQL_PASSWORD')
                if not db_port: missing_vars.append('SQL_PORT')
                self.logger.error(f"✗ Missing required environment variables: {', '.join(missing_vars)}")
                return None 
            try:
                db_port = int(db_port)
            except ValueError:
                self.logger.error(f"✗ Invalid SQL_PORT value: {db_port}. Must be a number.")
                return None
            conn = await aiomysql.connect(
                host=db_host,
                user=db_user,
                password=db_password,
                db=database_name,
                port=db_port,
                autocommit=True
            )
            self.logger.info(f"✓ Database connection established to {db_host}:{db_port} for database '{database_name}'")
            return conn
        except Exception as e:
            self.logger.error(f"✗ Failed to connect to database: {e}")
            return None

    async def execute_query(self, query, params=None, database_name='website'):
        conn = None
        try:
            conn = await self.get_database_connection(database_name)
            if not conn:
                return None
                
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(query, params)
                if query.strip().upper().startswith('SELECT'):
                    result = await cursor.fetchall()
                    return result
                else:
                    return cursor.rowcount
                    
        except Exception as e:
            self.logger.error(f"Database query error: {e}")
            return None
        finally:
            if conn:
                conn.close()

    async def get_user_settings(self, channel_name):
        query = "SELECT * FROM profile WHERE id = 1"
        result = await self.execute_query(query, database_name=channel_name)
        return result[0] if result else None

    async def test_database_connection(self):
        self.logger.info("Testing database connection...")
        try:
            conn = await self.get_database_connection('website')
            if conn:
                async with conn.cursor() as cursor:
                    await cursor.execute("SELECT 1")
                    result = await cursor.fetchone()
                    if result:
                        self.logger.info("✓ Database connection test successful")
                        return True
                conn.close()
            else:
                self.logger.error("✗ Database connection test failed")
                return False
        except Exception as e:
            self.logger.error(f"✗ Database connection test error: {e}")
            return False

    async def log_websocket_activity(self, channel_name, event_type, data=None):
        try:
            query = """
                INSERT INTO websocket_activity_log 
                (channel_name, event_type, data, timestamp) 
                VALUES (%s, %s, %s, NOW())
            """
            params = (channel_name, event_type, json.dumps(data) if data else None)
            
            result = await self.execute_query(query, params, 'website')
            if result:
                self.logger.debug(f"Logged WebSocket activity: {event_type} for {channel_name}")
            return result
        except Exception as e:
            self.logger.error(f"Failed to log WebSocket activity: {e}")
            return None

    async def get_user_api_key_info(self, api_key):
        try:
            query = "SELECT username, user_id FROM users WHERE api_key = %s"
            result = await self.execute_query(query, (api_key,), 'website')
            return result[0] if result else None
        except Exception as e:
            self.logger.error(f"Failed to get user API key info: {e}")
            return None

if __name__ == '__main__':
    SCRIPT_DIR = os.path.dirname(__file__)
    parser = argparse.ArgumentParser(prog='BotOfTheSpecter Websocket Server', description='A WebSocket server for handling notifications and real-time communication between the Website, System Overlays, Twitch Chat Bot, and Discord Bot.')
    parser.add_argument("-H", "--host", default="0.0.0.0", help="Specify the listener host. Default is 0.0.0.0")
    parser.add_argument("-p", "--port", default=443, type=int, help="Specify the listener port number. Default is 443")
    parser.add_argument("-l", "--loglevel", choices=["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"], default="INFO", help="Specify the log level. INFO is the default.")
    parser.add_argument("-f", "--logfile", help="Specify log file location. Production location should be <WEBROOT>/log/noti_server.log")
    args = parser.parse_args()
    log_level = {"DEBUG": logging.DEBUG, "INFO": logging.INFO, "WARNING": logging.WARNING, "ERROR": logging.ERROR, "CRITICAL": logging.CRITICAL}[args.loglevel]
    log_file = args.logfile if args.logfile else os.path.join(SCRIPT_DIR, "noti_server.log")
    logging.basicConfig(filename=log_file, level=log_level, filemode="a", format="%(asctime)s - %(levelname)s - %(message)s")
    logging.getLogger().addHandler(logging.StreamHandler())
    server = BotOfTheSpecter_WebsocketServer(logging)
    server.run_app(host=args.host, port=args.port)