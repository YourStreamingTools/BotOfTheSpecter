import os
import datetime
import logging
import ssl
import asyncio
import argparse
import time
from asyncio import subprocess
import aiomysql
from dotenv import load_dotenv
from pyrtmp import StreamClosedException
from pyrtmp.flv import FLVFileWriter, FLVMediaType
from pyrtmp.session_manager import SessionManager
from pyrtmp.rtmp import SimpleRTMPController, RTMPProtocol, SimpleRTMPServer

# Patch SessionManager.peername to avoid unpacking None
def safe_peername(self):
    info = self.writer.get_extra_info("peername")
    if not info or not isinstance(info, tuple) or len(info) != 2:
        return ("unknown", 0)
    return info

SessionManager.peername = property(safe_peername)

# Define Twitch ingest servers
TWITCH_INGEST_SERVERS = {
    "sydney": "rtmps://syd03.contribute.live-video.net/app/",
    "us-west": "rtmps://sea02.contribute.live-video.net/app/",
    "us-east": "rtmps://atl.contribute.live-video.net/app/",
    "eu-central": "rtmps://fra05.contribute.live-video.net/app/",
    # Add more regions as needed
}

# Define SSL domain mappings for Let's Encrypt certificates
SSL_DOMAIN_MAPPING = {
    "sydney": "au-east-1.botofthespecter.video",
    "us-west": "us-west-1.botofthespecter.video",
    "us-east": "us-east-1.botofthespecter.video",
    "eu-central": "eu-central-1.botofthespecter.video"
}

DEFAULT_INGEST_SERVER = "sydney"

# Parse command line arguments
def parse_args():
    parser = argparse.ArgumentParser(description='RTMP Server with Twitch forwarding')
    parser.add_argument('-server', type=str, default=DEFAULT_INGEST_SERVER,
                       help='Twitch ingest server location (sydney, us-west, us-east, eu-central)')
    return parser.parse_args()

# Load environment variables
load_dotenv()

# Parse and validate command line arguments
args = parse_args()
if args.server in TWITCH_INGEST_SERVERS:
    server_location = args.server
    _server_warning = None
else:
    _server_warning = f"Invalid server location: {args.server}. Using default: {DEFAULT_INGEST_SERVER}"
    server_location = DEFAULT_INGEST_SERVER

log_dir = "/home/botofthespecter/logs"
log_file = os.path.join(log_dir, f"{server_location}.txt")

os.makedirs(log_dir, exist_ok=True)
if not os.path.exists(log_file):
    with open(log_file, 'w') as f:
        pass

formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
file_handler = logging.FileHandler(log_file)
file_handler.setFormatter(formatter)
logging.basicConfig(level=logging.INFO, handlers=[file_handler])
logger = logging.getLogger()

if _server_warning:
    logger.warning(_server_warning)

# RTMP(S) Server Settings
RTMPS_PORT = 1935
RTMPS_HOST = "0.0.0.0"
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')

async def access_website_database():
    # Connect to your MySQL database
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website",
    )

async def userdb_connect(username):
    # Connect to the user's database
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=username,
    )

async def get_username_from_api_key(api_key):
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT username FROM users WHERE api_key = %s", (api_key,))
            row = await cursor.fetchone()
            return row['username'] if row else None
    except Exception as e:
        logger.error(f"Error in get_username_from_api_key: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()

async def validate_api_key(api_key):
    return await get_username_from_api_key(api_key)

async def get_streaming_settings(username):
    userdb = None
    try:
        userdb = await userdb_connect(username)
        async with userdb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT twitch_key, forward_to_twitch FROM streaming_settings WHERE id = 1")
            row = await cursor.fetchone()
            if row:
                return row['twitch_key'], row['forward_to_twitch']
            return None, False
    except Exception as e:
        logger.error(f"Error in get_streaming_settings for {username}: {e}")
        return None, False
    finally:
        if userdb is not None:
            await userdb.ensure_closed()

class _StreamPipeSink:
    def __init__(self, real_file, ffmpeg_stdin):
        self._real = real_file
        self._stdin = ffmpeg_stdin
        self._broken = False

    def write(self, data):
        result = self._real.write(data)
        if not self._broken and self._stdin is not None and not self._stdin.is_closing():
            try:
                self._stdin.write(data)
            except Exception as e:
                logger.warning(f"FFmpeg stdin write failed; tee disabled: {e}")
                self._broken = True
        return result

    def flush(self):
        try:
            self._real.flush()
        except Exception:
            pass

    def close(self):
        try:
            self._real.close()
        finally:
            if self._stdin is not None and not self._stdin.is_closing():
                try:
                    self._stdin.close()
                except Exception:
                    pass

    def __getattr__(self, name):
        return getattr(self._real, name)


class TeeFLVFileWriter(FLVFileWriter):
    def __init__(self, output, sink_stdin):
        super().__init__(output=output)
        try:
            self.buffer.flush()
        except Exception:
            pass
        try:
            with open(output, "rb") as fh:
                header_bytes = fh.read()
            if header_bytes and sink_stdin is not None and not sink_stdin.is_closing():
                sink_stdin.write(header_bytes)
        except Exception as e:
            logger.warning(f"Could not replay FLV header to ffmpeg: {e}")
        self.buffer = _StreamPipeSink(self.buffer, sink_stdin)

class RTMP2FLVController(SimpleRTMPController):
    def __init__(self, output_directory: str, twitch_server: str):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        super().__init__()

    async def on_connect(self, session, message):
        # Record the connection start time
        session.connection_start_time = datetime.datetime.now()
        session.closed = False
        # Schedule monitoring to disconnect after 48 hours; store so we can cancel on stream close
        session.duration_monitor_task = asyncio.create_task(self.monitor_connection_duration(session))
        await super().on_connect(session, message)

    async def monitor_connection_duration(self, session):
        max_duration = 48 * 3600  # 48 hours in seconds
        try:
            await asyncio.sleep(max_duration)
        except asyncio.CancelledError:
            return
        if session.writer and not session.writer.is_closing():
            logger.info(f"Disconnecting session {getattr(session, 'publishing_name', 'unknown')} after 48 hours.")
            session.writer.close()
            try:
                await session.writer.wait_closed()
            except Exception as e:
                logger.warning(f"Error during disconnection: {e}")

    async def on_ns_publish(self, session, message) -> None:
        # Validate API Key
        publishing_name = message.publishing_name
        username = await validate_api_key(publishing_name)
        if not username:
            logger.warning(f"Unauthorized API key: {publishing_name}")
            session.writer.close()
            try:
                await session.writer.wait_closed()
            except ssl.SSLError as e:
                logger.warning(f"Ignored SSL error after close notify: {e}")
            return
        # Fetch streaming settings
        twitch_key, forward_to_twitch = await get_streaming_settings(username)
        session.publishing_name = publishing_name
        start_date = datetime.datetime.now().strftime("%d-%m-%Y_%H-%M-%S")
        file_path = os.path.join(self.output_directory, f"{start_date}_{publishing_name}.flv")
        session.flv_file_path = file_path
        session.twitch_key = twitch_key
        session.ffmpeg_process = None
        # Set up FLV recording, optionally tee'd to ffmpeg for live Twitch forwarding
        if forward_to_twitch and twitch_key:
            twitch_server_url = TWITCH_INGEST_SERVERS.get(self.twitch_server, TWITCH_INGEST_SERVERS[DEFAULT_INGEST_SERVER])
            twitch_url = f"{twitch_server_url}{twitch_key}"
            logger.info(f"Using Twitch ingest server: {self.twitch_server} ({twitch_server_url})")
            await self._start_twitch_forwarding(session, file_path, twitch_url)
        else:
            if forward_to_twitch and not twitch_key:
                logger.warning(f"forward_to_twitch enabled for {username} but twitch_key is missing; recording only.")
            session.state = FLVFileWriter(output=file_path)
        logger.info(f"Started recording stream {publishing_name} to {file_path}")
        await super().on_ns_publish(session, message)

    async def _spawn_ffmpeg_forwarder(self, twitch_url):
        command = [
            "ffmpeg",
            "-hide_banner",
            "-loglevel", "warning",
            "-f", "flv",
            "-i", "pipe:0",
            "-c", "copy",
            "-f", "flv",
            twitch_url,
        ]
        return await asyncio.create_subprocess_exec(
            *command,
            stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )

    async def _start_twitch_forwarding(self, session, file_path, twitch_url):
        proc = None
        try:
            proc = await self._spawn_ffmpeg_forwarder(twitch_url)
            writer = TeeFLVFileWriter(output=file_path, sink_stdin=proc.stdin)
        except Exception as e:
            logger.error(f"Failed to start Twitch forwarding: {e}", exc_info=True)
            if proc is not None:
                try:
                    proc.kill()
                    await proc.wait()
                except Exception:
                    pass
            session.state = FLVFileWriter(output=file_path)
            return
        session.state = writer
        session.ffmpeg_process = proc
        session.twitch_forward_start_time = datetime.datetime.now()
        session.last_health_check = time.time()
        session.drain_task = asyncio.create_task(self._drain_ffmpeg_stdin(session))
        session.health_monitor_task = asyncio.create_task(self.monitor_ffmpeg_health(session))
        session.ffmpeg_watcher_task = asyncio.create_task(self._watch_ffmpeg(session))
        logger.info(f"Forwarding stream to Twitch via ffmpeg PID {proc.pid}: {twitch_url}")

    async def _drain_ffmpeg_stdin(self, session):
        proc = session.ffmpeg_process
        if proc is None or proc.stdin is None:
            return
        try:
            while not proc.stdin.is_closing() and proc.returncode is None:
                await proc.stdin.drain()
                await asyncio.sleep(0.1)
        except (BrokenPipeError, ConnectionResetError):
            return
        except asyncio.CancelledError:
            return
        except Exception as e:
            logger.warning(f"Drainer for ffmpeg stdin exited: {e}")

    async def _read_ffmpeg_stderr(self, session):
        proc = session.ffmpeg_process
        if proc is None or proc.stderr is None:
            return
        try:
            async for line in proc.stderr:
                decoded = line.decode(errors="replace").rstrip()
                if decoded:
                    logger.info(f"[ffmpeg] {decoded}")
        except asyncio.CancelledError:
            return
        except Exception:
            return

    async def _watch_ffmpeg(self, session):
        proc = session.ffmpeg_process
        if proc is None:
            return
        stderr_task = asyncio.create_task(self._read_ffmpeg_stderr(session))
        try:
            rc = await proc.wait()
        except asyncio.CancelledError:
            stderr_task.cancel()
            return
        try:
            await asyncio.wait_for(stderr_task, timeout=2.0)
        except (asyncio.TimeoutError, asyncio.CancelledError):
            stderr_task.cancel()
        if hasattr(session, "twitch_forward_start_time"):
            duration = datetime.datetime.now() - session.twitch_forward_start_time
            hours, remainder = divmod(duration.seconds, 3600)
            minutes, seconds = divmod(remainder, 60)
            tail = f" after {hours}h {minutes}m {seconds}s"
        else:
            tail = ""
        if rc == 0:
            logger.info(f"FFmpeg exited cleanly{tail}.")
        else:
            logger.error(f"FFmpeg exited with code {rc}{tail}; Twitch forwarding stopped.")

    async def monitor_ffmpeg_health(self, session):
        try:
            check_interval = 300  # Check every 5 minutes
            while True:
                await asyncio.sleep(check_interval)
                current_time = time.time()
                if not hasattr(session, 'ffmpeg_process') or session.ffmpeg_process is None:
                    logger.error("FFmpeg process reference lost, monitoring stopping")
                    return
                if session.ffmpeg_process.returncode is not None:
                    logger.error(f"FFmpeg process terminated unexpectedly with code {session.ffmpeg_process.returncode}")
                    return
                # Calculate uptime
                uptime = datetime.datetime.now() - session.twitch_forward_start_time
                hours, remainder = divmod(uptime.seconds + uptime.days * 86400, 3600)
                minutes, seconds = divmod(remainder, 60)
                # Log process status
                logger.info(f"FFmpeg health check: Process is running (PID: {session.ffmpeg_process.pid}, Uptime: {hours}h {minutes}m {seconds}s)")
                # Check if the stream file is still growing
                if hasattr(session, 'flv_file_path') and os.path.exists(session.flv_file_path):
                    current_size = os.path.getsize(session.flv_file_path)
                    logger.info(f"Current FLV file size: {current_size / (1024*1024):.2f} MB")
                # If approaching 8 hours, log more frequently
                if 7 <= hours < 8:
                    logger.warning(f"Stream approaching 8 hour mark: {hours}h {minutes}m {seconds}s - monitoring closely")
                    check_interval = 60  # Check every minute when approaching the 8-hour mark
                session.last_health_check = current_time
        except asyncio.CancelledError:
            logger.info("FFmpeg health monitoring cancelled")
        except Exception as e:
            logger.error(f"Error in FFmpeg health monitoring: {e}", exc_info=True)

    async def terminate_ffmpeg(self, session):
        if not hasattr(session, "ffmpeg_process") or session.ffmpeg_process is None:
            return
        proc = session.ffmpeg_process
        try:
            # Cancel auxiliary tasks attached to this session
            for attr in ("drain_task", "health_monitor_task", "ffmpeg_watcher_task"):
                t = getattr(session, attr, None)
                if t is not None and not t.done():
                    t.cancel()
                    try:
                        await asyncio.wait_for(t, timeout=1.0)
                    except (asyncio.CancelledError, asyncio.TimeoutError):
                        pass
            if hasattr(session, "twitch_forward_start_time"):
                duration = datetime.datetime.now() - session.twitch_forward_start_time
                hours, remainder = divmod(duration.seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
                duration_str = f" (ran for {hours}h {minutes}m {seconds}s)"
            else:
                duration_str = ""
            if proc.returncode is not None:
                logger.info(f"FFmpeg already exited with code {proc.returncode}{duration_str}")
                return
            # Graceful shutdown: closing stdin makes ffmpeg flush its output and exit.
            if proc.stdin is not None and not proc.stdin.is_closing():
                try:
                    proc.stdin.close()
                except Exception:
                    pass
            try:
                await asyncio.wait_for(proc.wait(), timeout=5.0)
                logger.info(f"FFmpeg exited cleanly after stdin close{duration_str}")
                return
            except asyncio.TimeoutError:
                logger.warning("FFmpeg didn't exit after stdin close; sending SIGTERM")
            proc.terminate()
            try:
                await asyncio.wait_for(proc.wait(), timeout=5.0)
                logger.info(f"FFmpeg terminated{duration_str}")
            except asyncio.TimeoutError:
                logger.warning("FFmpeg didn't respond to SIGTERM; killing")
                proc.kill()
                await proc.wait()
                logger.info(f"FFmpeg killed{duration_str}")
        except Exception as e:
            logger.error(f"Error while terminating FFmpeg: {e}", exc_info=True)

    async def on_metadata(self, session, message) -> None:
        session.state.write(0, message.to_raw_meta(), FLVMediaType.OBJECT)
        await super().on_metadata(session, message)

    async def on_video_message(self, session, message) -> None:
        session.state.write(message.timestamp, message.payload, FLVMediaType.VIDEO)
        await super().on_video_message(session, message)

    async def on_audio_message(self, session, message) -> None:
        session.state.write(message.timestamp, message.payload, FLVMediaType.AUDIO)
        await super().on_audio_message(session, message)

    async def on_stream_closed(self, session: SessionManager, exception: StreamClosedException) -> None:
        # Mark closed so any forwarder loop exits instead of restarting ffmpeg
        session.closed = True
        # Cancel the 48-hour duration monitor if still pending
        monitor_task = getattr(session, "duration_monitor_task", None)
        if monitor_task is not None and not monitor_task.done():
            monitor_task.cancel()
        # Terminate FFmpeg process if it exists
        await self.terminate_ffmpeg(session)
        # Close the FLV file after the stream ends
        session.state.close()
        # Convert FLV to MP4 using FFmpeg
        flv_file_path = session.flv_file_path
        asyncio.create_task(self.convert_flv_to_mp4_background(session, flv_file_path))
        logger.info(f"Started background conversion for {session.publishing_name}.")
        await super().on_stream_closed(session, exception)

    async def convert_flv_to_mp4_background(self, session, flv_file_path: str):
        # Get username and set user folder as destination
        username = await get_username_from_api_key(session.publishing_name)
        if not username:
            logger.error(
                f"Could not resolve username for stream key on {flv_file_path}; "
                f"skipping MP4 conversion and keeping FLV."
            )
            return
        user_dir = os.path.join(self.output_directory, username)
        os.makedirs(user_dir, exist_ok=True)
        date_part = os.path.basename(flv_file_path).split('_')[0]
        final_mp4_path = os.path.join(user_dir, f"{date_part}.mp4")
        # Check if file exists inside user folder and append a part number if needed
        if os.path.exists(final_mp4_path):
            base, ext = os.path.splitext(final_mp4_path)
            part = 2
            while os.path.exists(f"{base}-p{part}{ext}"):
                part += 1
            final_mp4_path = f"{base}-p{part}{ext}"
        # Run FFmpeg to convert the FLV file to MP4
        command = ["ffmpeg", "-i", flv_file_path, "-c", "copy", final_mp4_path]
        logger.info(f"Running FFmpeg process in async for {flv_file_path}...")
        process = await asyncio.create_subprocess_exec(*command)
        await process.communicate()
        if process.returncode == 0:
            os.remove(flv_file_path)
            logger.info(f"Converted file saved to {final_mp4_path}; removed source {flv_file_path}")
        else:
            logger.error(
                f"FFmpeg conversion failed (exit code {process.returncode}). "
                f"Keeping FLV file: {flv_file_path}"
            )

    async def on_command_message(self, session, message):
        if message.command in ["releaseStream", "FCPublish", "FCUnpublish"]:
            logger.info(f"Ignored command message: {message.command}")
            return
        await super().on_command_message(session, message)

class SimpleServer(SimpleRTMPServer):
    def __init__(self, output_directory: str, twitch_server: str):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        super().__init__()

    async def create(self, host: str, port: int, ssl_context=None):
        loop = asyncio.get_event_loop()
        self.server = await loop.create_server(
            lambda: RTMPProtocol(controller=RTMP2FLVController(self.output_directory, self.twitch_server)),
            host=host,
            port=port,
            ssl=ssl_context
        )

def create_ssl_context(server_location):
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    # Get the domain for the server location
    domain = SSL_DOMAIN_MAPPING.get(server_location, SSL_DOMAIN_MAPPING[DEFAULT_INGEST_SERVER])
    # Let's Encrypt certificate paths for the specific domain
    cert_path = f"/etc/letsencrypt/live/{domain}/fullchain.pem"
    key_path = f"/etc/letsencrypt/live/{domain}/privkey.pem"
    # Fallback to local SSL directory if Let's Encrypt certs don't exist
    if not os.path.exists(cert_path) or not os.path.exists(key_path):
        current_dir = os.path.dirname(os.path.abspath(__file__))
        cert_path = f"{current_dir}/ssl/fullchain.pem"
        key_path = f"{current_dir}/ssl/privkey.pem"
        logger.warning(f"Let's Encrypt certificates not found for {domain}, falling back to local SSL directory")
    context.load_cert_chain(certfile=cert_path, keyfile=key_path)
    logger.info(f"SSL context created for domain: {domain}")
    return context

async def start_rtmp_server(twitch_server):
    # Determine output directory based on server location
    if twitch_server in ("us-west", "us-east", "eu-central"):
        output_directory = "/mnt/s3/bots-stream"
    else:
        output_directory = os.path.dirname(os.path.abspath(__file__))
    ssl_context = create_ssl_context(twitch_server)
    server = SimpleServer(output_directory=output_directory, twitch_server=twitch_server)
    await server.create(host=RTMPS_HOST, port=RTMPS_PORT, ssl_context=ssl_context)
    domain = SSL_DOMAIN_MAPPING.get(twitch_server, SSL_DOMAIN_MAPPING[DEFAULT_INGEST_SERVER])
    logger.info(f"RTMPS server started on {RTMPS_HOST}:{RTMPS_PORT} with SSL for domain: {domain}")
    logger.info(f"Using Twitch ingest server location: {twitch_server}")
    await server.start()
    await server.wait_closed()

if __name__ == "__main__":
    try:
        asyncio.run(start_rtmp_server(server_location))
    except KeyboardInterrupt:
        logger.info("Server shutdown gracefully due to CTRL+C")