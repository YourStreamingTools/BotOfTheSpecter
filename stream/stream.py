import os
import sys
import socket
import secrets
import datetime
import logging
import ssl
import asyncio
import argparse
import time
from asyncio import subprocess
from functools import wraps
from urllib.parse import quote
import aiomysql
from dotenv import load_dotenv
from pyrtmp import StreamClosedException
from pyrtmp.flv import FLVFileWriter, FLVMediaType
from pyrtmp.session_manager import SessionManager
from pyrtmp.rtmp import SimpleRTMPController, RTMPProtocol, SimpleRTMPServer
from quart import Quart, render_template_string, request, jsonify, redirect, session

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

# Display titles for the operator web UI (one UI per server / region)
SERVER_DISPLAY_NAMES = {
    "sydney": "RTMP Server - Sydney, Australia (au-east-1)",
    "us-east": "RTMP Server - Ashburn, Virginia, USA (us-east-1)",
    "us-west": "RTMP Server - Hillsboro, Oregon, USA (us-west-1)",
    "eu-central": "RTMP Server - Nuremberg, Germany (eu-central-1)",
}

DEFAULT_WEB_HOST = "0.0.0.0"
DEFAULT_WEB_PORT = 8080
# Where twitch-recorder.py writes its per-user recordings (matches its STREAM_ROOT_PATH default)
DEFAULT_RECORDER_STORAGE_PATH = os.getenv('STREAM_ROOT_PATH', '/mnt/blockstorage')

# ---------------------------------------------------------------------------
# Cross-eTLD SSO (.com authority -> .video consumer)
# Browsers can't share a cookie across .botofthespecter.com and .video, so
# the user is bounced to home/sso.php which mints a single-use handoff token
# in website.handoff_tokens. We verify the token on /sso/login and create
# a Quart session cookie scoped to .botofthespecter.video.
# ---------------------------------------------------------------------------
SSO_AUTHORITY_URL = "https://botofthespecter.com/sso.php"
SSO_TARGET_BY_REGION = {
    "sydney":     "rtmp-sydney",
    "us-east":    "rtmp-us-east",
    "us-west":    "rtmp-us-west",
    "eu-central": "rtmp-eu-central",
}
WEB_SESSION_COOKIE_NAME = "bots_video_session"
WEB_SESSION_COOKIE_DOMAIN = ".botofthespecter.video"
WEB_SESSION_LIFETIME_SECONDS = 14400  # 4h, matches the .com side
# Signed-cookie key. Set the SAME value across every regional .env so a single
# login cookie scopes to .botofthespecter.video and works on every region.
# Falls back to an ephemeral key if missing — sessions then survive only until
# this process restarts.
WEB_SECRET_KEY = os.getenv('WEB_SECRET_KEY')

# Parse command line arguments
def parse_args():
    parser = argparse.ArgumentParser(description='RTMP Server with Twitch forwarding')
    parser.add_argument('-server', type=str, default=DEFAULT_INGEST_SERVER,
                       help='Twitch ingest server location (sydney, us-west, us-east, eu-central)')
    parser.add_argument('--web-host', type=str, default=DEFAULT_WEB_HOST,
                       help='Bind address for the operator web UI')
    parser.add_argument('--web-port', type=int, default=DEFAULT_WEB_PORT,
                       help='Port for the operator web UI')
    parser.add_argument('--recorder-path', type=str, default=DEFAULT_RECORDER_STORAGE_PATH,
                       help='Filesystem root where twitch-recorder.py stores per-user recordings')
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
ADMIN_KEY_SERVICE = "rtmp-server"
SERVER_START_TIME = datetime.datetime.now()
FFMPEG_VERSION: str = "unknown"

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

class SessionRegistry:
    def __init__(self):
        self._sessions: dict[int, dict] = {}

    def register_connection(self, session_id: int, peer: str, disconnect_callback=None) -> None:
        self._sessions[session_id] = {
            "id": session_id,
            "peer": peer,
            "connected_at": datetime.datetime.now(),
            "publishing_name": None,
            "username": None,
            "flv_file_path": None,
            "publish_started_at": None,
            "forwarding": None,
            "_disconnect_callback": disconnect_callback,
        }

    def get(self, session_id: int) -> dict | None:
        return self._sessions.get(session_id)

    def force_disconnect(self, session_id: int) -> bool:
        s = self._sessions.get(session_id)
        if s is None:
            return False
        cb = s.get("_disconnect_callback")
        if cb is None:
            return False
        try:
            cb()
        except Exception as e:
            logger.warning(f"force_disconnect callback raised: {e}")
        return True

    def attach_publish(self, session_id: int, *, publishing_name: str, username: str, flv_file_path: str) -> None:
        s = self._sessions.get(session_id)
        if s is None:
            return
        s["publishing_name"] = publishing_name
        s["username"] = username
        s["flv_file_path"] = flv_file_path
        s["publish_started_at"] = datetime.datetime.now()

    def attach_forwarding(self, session_id: int, *, target_url: str, ffmpeg_pid: int) -> None:
        s = self._sessions.get(session_id)
        if s is None:
            return
        # Mask the stream key portion of the Twitch ingest URL
        masked = target_url
        if "/app/" in target_url:
            head, _, _ = target_url.partition("/app/")
            masked = f"{head}/app/****"
        s["forwarding"] = {
            "target_url": masked,
            "ffmpeg_pid": ffmpeg_pid,
            "started_at": datetime.datetime.now(),
        }

    def deregister(self, session_id: int) -> None:
        self._sessions.pop(session_id, None)

    def snapshot(self) -> list[dict]:
        return list(self._sessions.values())

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
    def __init__(self, output_directory: str, twitch_server: str, session_registry: SessionRegistry):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        self.session_registry = session_registry
        super().__init__()

    async def on_connect(self, session, message):
        # Record the connection start time
        session.connection_start_time = datetime.datetime.now()
        session.closed = False
        # Register with the operator web UI registry, with a callback the API can use to kick the session
        try:
            host, port = session.peername
            peer_str = f"{host}:{port}"
        except Exception:
            peer_str = "unknown"
        def _force_close():
            try:
                if session.writer is not None and not session.writer.is_closing():
                    session.writer.close()
            except Exception:
                pass
        self.session_registry.register_connection(id(session), peer_str, disconnect_callback=_force_close)
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
        self.session_registry.attach_publish(
            id(session), publishing_name=publishing_name, username=username, flv_file_path=file_path
        )
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
        self.session_registry.attach_forwarding(id(session), target_url=twitch_url, ffmpeg_pid=proc.pid)
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
        # Remove from the operator web UI registry
        self.session_registry.deregister(id(session))
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
    def __init__(self, output_directory: str, twitch_server: str, session_registry: SessionRegistry):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        self.session_registry = session_registry
        super().__init__()

    async def create(self, host: str, port: int, ssl_context=None):
        loop = asyncio.get_event_loop()
        self.server = await loop.create_server(
            lambda: RTMPProtocol(controller=RTMP2FLVController(self.output_directory, self.twitch_server, self.session_registry)),
            host=host,
            port=port,
            ssl=ssl_context
        )

def resolve_cert_paths(server_location):
    domain = SSL_DOMAIN_MAPPING.get(server_location, SSL_DOMAIN_MAPPING[DEFAULT_INGEST_SERVER])
    cert_path = f"/etc/letsencrypt/live/{domain}/fullchain.pem"
    key_path = f"/etc/letsencrypt/live/{domain}/privkey.pem"
    if not os.path.exists(cert_path) or not os.path.exists(key_path):
        current_dir = os.path.dirname(os.path.abspath(__file__))
        cert_path = f"{current_dir}/ssl/fullchain.pem"
        key_path = f"{current_dir}/ssl/privkey.pem"
        logger.warning(f"Let's Encrypt certificates not found for {domain}, falling back to local SSL directory")
    return domain, cert_path, key_path

def create_ssl_context(server_location):
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    domain, cert_path, key_path = resolve_cert_paths(server_location)
    context.load_cert_chain(certfile=cert_path, keyfile=key_path)
    logger.info(f"SSL context created for domain: {domain}")
    return context

_BASE_CSS = """
  body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0e1116; color: #e6edf3; margin: 0; padding: 24px; }
  h1 { margin: 0 0 4px 0; font-size: 20px; }
  h2 { margin: 24px 0 6px 0; font-size: 15px; color: #e6edf3; }
  .meta { color: #8b949e; font-size: 12px; margin-bottom: 14px; }
  nav { display: flex; gap: 18px; margin: 4px 0 18px 0; border-bottom: 1px solid #21262d; }
  nav a { color: #8b949e; text-decoration: none; padding: 6px 0 8px 0; font-size: 13px; }
  nav a:hover { color: #e6edf3; }
  nav a.active { color: #e6edf3; border-bottom: 2px solid #58a6ff; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #21262d; vertical-align: top; }
  th { font-weight: 600; color: #8b949e; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; }
  tr:hover td { background: #161b22; }
  code { font-family: ui-monospace, SFMono-Regular, monospace; background: #161b22; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
  .empty { color: #8b949e; padding: 32px; text-align: center; border: 1px dashed #21262d; border-radius: 4px; }
  .yes { color: #3fb950; }
  .no  { color: #6e7681; }
  section { margin-bottom: 24px; }
"""

_NAV_HTML = """  <nav>
    <a href="/" class="{{ 'active' if page == 'dashboard' else '' }}">Live sessions</a>
    <a href="/recordings" class="{{ 'active' if page == 'recordings' else '' }}">Recordings</a>
  </nav>
"""

DASHBOARD_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="5">
<title>{{ server_title }}</title>
<style>""" + _BASE_CSS + """</style>
</head>
<body>
  <h1>{{ server_title }}</h1>
""" + _NAV_HTML + """  <div class="meta">
    {{ sessions|length }} active session{{ '' if sessions|length == 1 else 's' }} ·
    refreshed {{ generated_at }} (auto-refresh 5s)
  </div>
  {% if sessions %}
  <table>
    <thead>
      <tr>
        <th>API key (stream key)</th>
        <th>User</th>
        <th>Incoming peer</th>
        <th>Connected</th>
        <th>FLV size</th>
        <th>Outgoing target</th>
        <th>FFmpeg PID</th>
        <th>Forwarding for</th>
      </tr>
    </thead>
    <tbody>
      {% for s in sessions %}
      <tr>
        <td><code>{{ s.publishing_name }}</code></td>
        <td>{{ s.username }}</td>
        <td><code>{{ s.peer }}</code></td>
        <td>{{ s.connected_for }}</td>
        <td>{{ s.flv_size }}</td>
        {% if s.forwarding_target %}
          <td class="yes"><code>{{ s.forwarding_target }}</code></td>
          <td>{{ s.forwarding_pid }}</td>
          <td>{{ s.forwarding_duration }}</td>
        {% else %}
          <td class="no">not forwarding</td>
          <td class="no">&mdash;</td>
          <td class="no">&mdash;</td>
        {% endif %}
      </tr>
      {% endfor %}
    </tbody>
  </table>
  {% else %}
    <div class="empty">No active sessions on this server.</div>
  {% endif %}
</body>
</html>
"""

RECORDINGS_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="30">
<title>{{ server_title }} &mdash; Recordings</title>
<style>""" + _BASE_CSS + """</style>
</head>
<body>
  <h1>{{ server_title }}</h1>
""" + _NAV_HTML + """  <div class="meta">
    Recorder storage: <code>{{ root_path }}</code> ·
    {{ users|length }} user folder{{ '' if users|length == 1 else 's' }} ·
    refreshed {{ generated_at }} (auto-refresh 30s)
  </div>
  {% if users %}
    {% for u in users %}
    <section>
      <h2>{{ u.username }}</h2>
      <div class="meta">{{ u.file_count }} file{{ '' if u.file_count == 1 else 's' }} &middot; {{ u.total_size_str }}</div>
      <table>
        <thead><tr><th>File</th><th>Size</th><th>Modified</th></tr></thead>
        <tbody>
          {% for f in u.files %}
          <tr>
            <td><code>{{ f.name }}</code></td>
            <td>{{ f.size_str }}</td>
            <td>{{ f.mtime_str }}</td>
          </tr>
          {% endfor %}
        </tbody>
      </table>
    </section>
    {% endfor %}
  {% else %}
    <div class="empty">No recordings found at <code>{{ root_path }}</code>.</div>
  {% endif %}
</body>
</html>
"""

def _humanize_bytes(n: float) -> str:
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024.0:
            return f"{n:.1f} {unit}"
        n /= 1024.0
    return f"{n:.1f} PB"

def _humanize_duration(delta: datetime.timedelta) -> str:
    total = max(0, int(delta.total_seconds()))
    h, rem = divmod(total, 3600)
    m, s = divmod(rem, 60)
    if h > 0:
        return f"{h}h {m}m {s}s"
    if m > 0:
        return f"{m}m {s}s"
    return f"{s}s"

def list_recorder_files(root_path: str) -> list[dict]:
    if not root_path or not os.path.isdir(root_path):
        return []
    try:
        entries = sorted(os.listdir(root_path))
    except OSError as e:
        logger.warning(f"Could not list recorder storage at {root_path}: {e}")
        return []
    users = []
    for entry in entries:
        user_dir = os.path.join(root_path, entry)
        if not os.path.isdir(user_dir):
            continue
        try:
            file_entries = os.listdir(user_dir)
        except OSError:
            continue
        files = []
        total_size = 0
        for fname in file_entries:
            fpath = os.path.join(user_dir, fname)
            if not os.path.isfile(fpath):
                continue
            try:
                stat = os.stat(fpath)
            except OSError:
                continue
            files.append({"name": fname, "size": stat.st_size, "mtime": stat.st_mtime})
            total_size += stat.st_size
        files.sort(key=lambda f: f["mtime"], reverse=True)
        users.append({
            "username": entry,
            "files": files,
            "total_size": total_size,
            "file_count": len(files),
        })
    return users

async def _detect_ffmpeg_version() -> str:
    try:
        proc = await asyncio.create_subprocess_exec(
            "ffmpeg", "-version",
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=5.0)
        first_line = stdout.decode(errors="replace").splitlines()[0].strip() if stdout else ""
        return first_line or "unknown"
    except Exception as e:
        logger.warning(f"Could not detect ffmpeg version: {e}")
        return "unknown"

async def _verify_admin_key(api_key: str) -> bool:
    if not api_key:
        return False
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                "SELECT service FROM admin_api_keys WHERE api_key = %s",
                (api_key,),
            )
            row = await cursor.fetchone()
            if not row:
                return False
            service = (row.get("service") or "").lower()
            if service == "admin":
                return True
            if service == ADMIN_KEY_SERVICE.lower():
                return True
            logger.warning(f"Admin key for service '{service}' tried to access {ADMIN_KEY_SERVICE}; denied.")
            return False
    except Exception as e:
        logger.error(f"Error verifying admin key: {e}")
        return False
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()

def _require_api_key(view):
    @wraps(view)
    async def wrapper(*args, **kwargs):
        provided = request.headers.get("X-API-Key", "")
        if not provided:
            return jsonify({"error": "missing X-API-Key header"}), 401
        if not await _verify_admin_key(provided):
            return jsonify({"error": "incorrect API key"}), 401
        return await view(*args, **kwargs)
    return wrapper

def _session_to_json(s: dict, now: datetime.datetime) -> dict:
    flv_path = s.get("flv_file_path")
    flv_size = None
    if flv_path and os.path.exists(flv_path):
        try:
            flv_size = os.path.getsize(flv_path)
        except OSError:
            flv_size = None
    forwarding = s.get("forwarding")
    fwd_json = None
    if forwarding:
        fwd_json = {
            "target_url": forwarding["target_url"],
            "ffmpeg_pid": forwarding["ffmpeg_pid"],
            "started_at": forwarding["started_at"].isoformat(),
            "duration_seconds": int((now - forwarding["started_at"]).total_seconds()),
        }
    publish_started = s.get("publish_started_at")
    return {
        "id": s["id"],
        "peer": s["peer"],
        "publishing_name": s.get("publishing_name"),
        "username": s.get("username"),
        "connected_at": s["connected_at"].isoformat(),
        "connected_seconds": int((now - s["connected_at"]).total_seconds()),
        "publish_started_at": publish_started.isoformat() if publish_started else None,
        "flv_file_path": flv_path,
        "flv_size_bytes": flv_size,
        "forwarding": fwd_json,
    }

def create_web_app(server_title: str, region: str, session_registry: SessionRegistry, recorder_storage_path: str) -> Quart:
    app = Quart(__name__)

    # ------------------------------------------------------------------
    # Quart session config — signed cookie scoped to .botofthespecter.video
    # so a single login covers every regional RTMP UI (provided every region
    # uses the SAME WEB_SECRET_KEY).
    # ------------------------------------------------------------------
    if WEB_SECRET_KEY:
        app.config["SECRET_KEY"] = WEB_SECRET_KEY
    else:
        app.config["SECRET_KEY"] = secrets.token_hex(32)
        logger.warning(
            "WEB_SECRET_KEY env var not set; using ephemeral key. "
            "Sessions will be lost on restart and won't share across regions."
        )
    app.config["SESSION_COOKIE_NAME"]        = WEB_SESSION_COOKIE_NAME
    app.config["SESSION_COOKIE_DOMAIN"]      = WEB_SESSION_COOKIE_DOMAIN
    app.config["SESSION_COOKIE_SECURE"]      = True
    app.config["SESSION_COOKIE_HTTPONLY"]    = True
    app.config["SESSION_COOKIE_SAMESITE"]    = "Lax"
    app.config["PERMANENT_SESSION_LIFETIME"] = WEB_SESSION_LIFETIME_SECONDS

    sso_target = SSO_TARGET_BY_REGION.get(region, f"rtmp-{region}")

    def _require_sso_session(view):
        @wraps(view)
        async def wrapper(*args, **kwargs):
            if not session.get("twitchUserId"):
                # Stash the path the user was trying to reach so SSO can drop them back.
                qs = request.query_string.decode() if request.query_string else ""
                back = request.path + (("?" + qs) if qs else "")
                return redirect(
                    f"{SSO_AUTHORITY_URL}?target={sso_target}&return={quote(back, safe='')}"
                )
            return await view(*args, **kwargs)
        return wrapper

    async def _verify_and_consume_handoff(token: str) -> dict | None:
        """Atomically claim a handoff token. Returns the token's row dict on
        success, None if the token is missing / expired / wrong target /
        already used. Marks the row used so it can't be replayed."""
        if not token:
            return None
        sqldb = None
        try:
            sqldb = await access_website_database()
            async with sqldb.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT twitch_user_id, username, display_name, profile_image, "
                    "       is_admin, used, expires_at, target "
                    "FROM handoff_tokens WHERE token = %s LIMIT 1",
                    (token,),
                )
                row = await cur.fetchone()
                if not row:
                    return None
                if row["used"] or row["expires_at"] < datetime.datetime.now():
                    return None
                if (row.get("target") or "") != sso_target:
                    logger.warning(
                        f"Handoff token target='{row.get('target')}' presented "
                        f"to region expecting '{sso_target}'; rejecting."
                    )
                    return None
                # Race-safe consume: only succeed if `used` was still 0 at UPDATE time.
                await cur.execute(
                    "UPDATE handoff_tokens SET used = 1 WHERE token = %s AND used = 0",
                    (token,),
                )
                if cur.rowcount != 1:
                    return None
                await sqldb.commit()
                return row
        except Exception as e:
            logger.error(f"Handoff token verification failed: {e}")
            return None
        finally:
            if sqldb is not None:
                await sqldb.ensure_closed()

    @app.get("/")
    @_require_sso_session
    async def dashboard():
        now = datetime.datetime.now()
        rows = []
        for s in session_registry.snapshot():
            connected_for = _humanize_duration(now - s["connected_at"])
            publishing = s["publishing_name"] or "(handshake)"
            username = s["username"] or "—"
            flv_size_str = "—"
            flv = s["flv_file_path"]
            if flv and os.path.exists(flv):
                flv_size_str = _humanize_bytes(float(os.path.getsize(flv)))
            forwarding = s["forwarding"]
            if forwarding:
                fwd_target = forwarding["target_url"]
                fwd_pid = forwarding["ffmpeg_pid"]
                fwd_duration = _humanize_duration(now - forwarding["started_at"])
            else:
                fwd_target = None
                fwd_pid = None
                fwd_duration = None
            rows.append({
                "publishing_name": publishing,
                "username": username,
                "peer": s["peer"],
                "connected_for": connected_for,
                "flv_size": flv_size_str,
                "forwarding_target": fwd_target,
                "forwarding_pid": fwd_pid,
                "forwarding_duration": fwd_duration,
            })
        rows.sort(key=lambda r: r["publishing_name"])
        return await render_template_string(
            DASHBOARD_TEMPLATE,
            server_title=server_title,
            sessions=rows,
            generated_at=now.strftime("%Y-%m-%d %H:%M:%S"),
            page="dashboard",
        )

    @app.get("/recordings")
    @_require_sso_session
    async def recordings():
        now = datetime.datetime.now()
        users = list_recorder_files(recorder_storage_path)
        for u in users:
            u["total_size_str"] = _humanize_bytes(float(u["total_size"]))
            for f in u["files"]:
                f["size_str"] = _humanize_bytes(float(f["size"]))
                f["mtime_str"] = datetime.datetime.fromtimestamp(f["mtime"]).strftime("%Y-%m-%d %H:%M:%S")
        return await render_template_string(
            RECORDINGS_TEMPLATE,
            server_title=server_title,
            users=users,
            root_path=recorder_storage_path,
            generated_at=now.strftime("%Y-%m-%d %H:%M:%S"),
            page="recordings",
        )

    @app.get("/api/sessions")
    @_require_api_key
    async def api_sessions():
        now = datetime.datetime.now()
        sessions = [_session_to_json(s, now) for s in session_registry.snapshot()]
        return jsonify({
            "sessions": sessions,
            "count": len(sessions),
            "generated_at": now.isoformat(),
        })

    @app.get("/api/sessions/<int:session_id>")
    @_require_api_key
    async def api_session_detail(session_id: int):
        s = session_registry.get(session_id)
        if s is None:
            return jsonify({"error": "session not found", "session_id": session_id}), 404
        return jsonify({"session": _session_to_json(s, datetime.datetime.now())})

    @app.post("/api/sessions/<int:session_id>/disconnect")
    @_require_api_key
    async def api_session_disconnect(session_id: int):
        ok = session_registry.force_disconnect(session_id)
        if not ok:
            return jsonify({"error": "session not found", "session_id": session_id}), 404
        logger.info(f"Admin API force-disconnected session {session_id}")
        return jsonify({"disconnected": True, "session_id": session_id})

    @app.get("/api/recordings")
    @_require_api_key
    async def api_recordings():
        now = datetime.datetime.now()
        users = list_recorder_files(recorder_storage_path)
        out = []
        for u in users:
            out.append({
                "username": u["username"],
                "file_count": u["file_count"],
                "total_size_bytes": u["total_size"],
                "files": [
                    {
                        "name": f["name"],
                        "size_bytes": f["size"],
                        "modified_at": datetime.datetime.fromtimestamp(f["mtime"]).isoformat(),
                    }
                    for f in u["files"]
                ],
            })
        return jsonify({
            "users": out,
            "root_path": recorder_storage_path,
            "generated_at": now.isoformat(),
        })

    @app.get("/api/server")
    @_require_api_key
    async def api_server():
        now = datetime.datetime.now()
        uptime = now - SERVER_START_TIME
        return jsonify({
            "region": region,
            "region_display_name": server_title,
            "hostname": socket.gethostname(),
            "rtmps_port": RTMPS_PORT,
            "active_sessions": len(session_registry.snapshot()),
            "started_at": SERVER_START_TIME.isoformat(),
            "uptime_seconds": int(uptime.total_seconds()),
            "ffmpeg_version": FFMPEG_VERSION,
            "python_version": sys.version.split()[0],
            "generated_at": now.isoformat(),
        })

    # ------------------------------------------------------------------
    # SSO consumer: verify a handoff token minted by home/sso.php and
    # create the Quart session cookie on .botofthespecter.video.
    # ------------------------------------------------------------------
    @app.get("/sso/login")
    async def sso_login():
        token = request.args.get("handoff", "")
        return_path = request.args.get("return", "/")
        row = await _verify_and_consume_handoff(token)
        if row is None:
            # Invalid / expired / wrong target / replayed. Send the user back
            # through SSO to mint a fresh token.
            return redirect(
                f"{SSO_AUTHORITY_URL}?target={sso_target}&return={quote(return_path, safe='')}"
            )
        session.clear()
        session.permanent = True
        session["twitchUserId"]  = row["twitch_user_id"]
        session["username"]      = row["username"]
        session["display_name"]  = row["display_name"]
        session["profile_image"] = row["profile_image"]
        session["is_admin"]      = bool(row["is_admin"])
        session["signed_in_at"]  = datetime.datetime.now().isoformat()
        # Drop the user back where they were trying to go. Only honour purely
        # local paths — anything else falls back to "/".
        safe_return = "/"
        if isinstance(return_path, str) and return_path.startswith("/") and not return_path.startswith("//"):
            safe_return = return_path
        return redirect(safe_return)

    @app.get("/logout")
    async def logout():
        session.clear()
        return redirect("/")

    return app

async def _serve_rtmp(server: SimpleServer) -> None:
    await server.start()
    await server.wait_closed()

async def _serve_web(app: Quart, host: str, port: int, certfile: str, keyfile: str) -> None:
    # Quart delegates to hypercorn under the hood; wants cert/key paths, not an SSLContext.
    await app.run_task(host=host, port=port, certfile=certfile, keyfile=keyfile)

async def start_rtmp_server(twitch_server: str, web_host: str, web_port: int, recorder_storage_path: str) -> None:
    global FFMPEG_VERSION
    # Determine output directory based on server location
    if twitch_server in ("us-west", "us-east", "eu-central"):
        output_directory = "/mnt/s3/bots-stream"
    else:
        output_directory = os.path.dirname(os.path.abspath(__file__))
    # Detect ffmpeg up front so /api/server can report it without re-shelling on every request
    FFMPEG_VERSION = await _detect_ffmpeg_version()
    logger.info(f"Detected ffmpeg: {FFMPEG_VERSION}")
    ssl_context = create_ssl_context(twitch_server)
    domain, cert_path, key_path = resolve_cert_paths(twitch_server)
    session_registry = SessionRegistry()
    server = SimpleServer(
        output_directory=output_directory,
        twitch_server=twitch_server,
        session_registry=session_registry,
    )
    await server.create(host=RTMPS_HOST, port=RTMPS_PORT, ssl_context=ssl_context)
    logger.info(f"RTMPS server started on {RTMPS_HOST}:{RTMPS_PORT} with SSL for domain: {domain}")
    logger.info(f"Using Twitch ingest server location: {twitch_server}")
    server_title = SERVER_DISPLAY_NAMES.get(twitch_server, f"RTMP Server - {twitch_server}")
    web_app = create_web_app(server_title, twitch_server, session_registry, recorder_storage_path)
    logger.info(f"Operator web UI: https://{domain}:{web_port}/ (binding {web_host}:{web_port})")
    logger.info(f"Recordings page reading from: {recorder_storage_path}")
    await asyncio.gather(
        _serve_rtmp(server),
        _serve_web(web_app, web_host, web_port, cert_path, key_path),
    )

if __name__ == "__main__":
    try:
        asyncio.run(start_rtmp_server(server_location, args.web_host, args.web_port, args.recorder_path))
    except KeyboardInterrupt:
        logger.info("Server shutdown gracefully due to CTRL+C")