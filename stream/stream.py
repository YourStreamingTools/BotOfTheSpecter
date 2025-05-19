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
DEFAULT_INGEST_SERVER = "sydney"

# Parse command line arguments
def parse_args():
    parser = argparse.ArgumentParser(description='RTMP Server with Twitch forwarding')
    parser.add_argument('-server', type=str, default=DEFAULT_INGEST_SERVER,
                       help='Twitch ingest server location (sydney, us-west, us-east, eu-central)')
    return parser.parse_args()

# Load environment variables
load_dotenv()

# Ensure the logging file is saved in the running directory
current_dir = os.path.dirname(os.path.abspath(__file__))
log_file = os.path.join(current_dir, "stream_server.log")

# Create the log file if it doesn't exist
if not os.path.exists(log_file):
    with open(log_file, 'w') as f:
        pass

# Logging Configuration
logging.basicConfig(
    filename=log_file,
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] - %(message)s",
)
logger = logging.getLogger()

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

async def userdb_conenct(username):
    # Connect to the user's database
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=username,
    )

async def get_username_from_api_key(api_key):
    # Connect to the website database
    try:
        sqldb = await access_website_database()
        if sqldb is None:
            logger.error(f"Failed to connect to database when looking up API key: {api_key}")
            return None
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT username FROM users WHERE api_key = %s", (api_key,))
            row = await cursor.fetchone()
            if row:
                username = row['username']
                return username
            else:
                return f"Unknown-{api_key}"
        # Close the database connection
        await sqldb.close()
    except Exception as e:
        logger.error(f"Error in get_username_from_api_key: {e}")
        return None

async def get_valid_stream_keys():
    # Connect to the website database
    sqldb = await access_website_database()
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        await cursor.execute("SELECT api_key, username FROM users")
        rows = await cursor.fetchall()
        keys = {row['api_key']: row['username'] for row in rows}
        # Close the database connection
        sqldb.close()
        return keys

async def validate_api_key(api_key):
    valid_keys = await get_valid_stream_keys()
    return valid_keys.get(api_key, None)

async def get_streaming_settings(username):
    # Connect to the user's database
    userdb = await userdb_conenct(username)
    async with userdb.cursor(aiomysql.DictCursor) as cursor:
        await cursor.execute("SELECT twitch_key, forward_to_twitch FROM streaming_settings WHERE id = 1")
        row = await cursor.fetchone()
        # Close the database connection
        userdb.close()
        if row:
            return row['twitch_key'], row['forward_to_twitch']
        return None, False

class RTMP2FLVController(SimpleRTMPController):
    def __init__(self, output_directory: str, twitch_server: str):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        super().__init__()

    async def on_connect(self, session, message):
        # Record the connection start time
        session.connection_start_time = datetime.datetime.now()
        # Schedule monitoring to disconnect after 48 hours
        asyncio.create_task(self.monitor_connection_duration(session))
        await super().on_connect(session, message)

    async def monitor_connection_duration(self, session):
        max_duration = 48 * 3600  # 48 hours in seconds
        await asyncio.sleep(max_duration)
        if session.writer and not session.writer.is_closing():
            logger.info(f"Disconnecting session {session.publishing_name} after 48 hours.")
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
        # Assign publishing_name to session for later use
        session.publishing_name = publishing_name
        start_date = datetime.datetime.now().strftime("%d-%m-%Y_%H-%M-%S")
        file_path = os.path.join(self.output_directory, f"{start_date}_{publishing_name}.flv")
        # Store the actual FLV file path in the session
        session.flv_file_path = file_path
        session.state = FLVFileWriter(output=file_path)
        logger.info(f"Started recording stream {publishing_name} to {file_path}")
        # Forward to Twitch if enabled
        session.twitch_key = twitch_key
        session.ffmpeg_process = None
        if forward_to_twitch:
            # Use the correct Twitch ingest server based on command line argument
            twitch_server_url = TWITCH_INGEST_SERVERS.get(self.twitch_server, TWITCH_INGEST_SERVERS[DEFAULT_INGEST_SERVER])
            twitch_url = f"{twitch_server_url}{twitch_key}"
            logger.info(f"Using Twitch ingest server: {self.twitch_server} ({twitch_server_url})")
            asyncio.create_task(self.forward_to_twitch(session, twitch_url))
        await super().on_ns_publish(session, message)

    async def forward_to_twitch(self, session, twitch_url):
        try:
            logger.info(f"Waiting 1 seconds for FLV file to receive initial data...")
            await asyncio.sleep(1)
            restart_count = 0
            while True:
                # Check if the FLV file exists and has content
                if not os.path.exists(session.flv_file_path):
                    logger.warning(f"FLV file {session.flv_file_path} does not exist. Skipping forwarding to Twitch.")
                    return
                # Wait for the file to reach a minimum size
                min_file_size = 100 * 1024  # 100KB
                max_wait_time = 10  # 10 seconds
                start_time = datetime.datetime.now()
                while os.path.getsize(session.flv_file_path) < min_file_size:
                    if (datetime.datetime.now() - start_time).total_seconds() > max_wait_time:
                        logger.warning(f"Timeout waiting for FLV file to reach minimum size. Current size: {os.path.getsize(session.flv_file_path)} bytes")
                        if os.path.getsize(session.flv_file_path) == 0:
                            logger.error("FLV file is empty. Skipping forwarding to Twitch.")
                            return
                        logger.info("Continuing with forwarding despite not reaching ideal minimum size")
                        break
                    await asyncio.sleep(1)
                logger.info(f"FLV file has reached adequate size ({os.path.getsize(session.flv_file_path)} bytes). Starting forwarding to Twitch.")
                command = [
                    "ffmpeg",
                    "-re",
                    "-i", session.flv_file_path,
                    "-c", "copy",
                    "-f", "flv",
                    twitch_url
                ]
                logger.info(f"Forwarding stream to Twitch: {twitch_url}")
                session.twitch_forward_start_time = datetime.datetime.now()
                session.last_health_check = time.time()
                session.ffmpeg_process = await asyncio.create_subprocess_exec(
                    *command,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                )
                logger.info(f"FFmpeg process started with PID: {session.ffmpeg_process.pid}")
                health_monitor_task = asyncio.create_task(self.monitor_ffmpeg_health(session))
                session.health_monitor_task = health_monitor_task
                _ , stderr = await session.ffmpeg_process.communicate()
                end_time = datetime.datetime.now()
                duration = end_time - session.twitch_forward_start_time
                hours, remainder = divmod(duration.seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
                logger.error(f"FFmpeg process exited (code {session.ffmpeg_process.returncode}) after {hours}h {minutes}m {seconds}s. Restart count: {restart_count}")
                logger.error(f"FFmpeg stderr output:\n{stderr.decode(errors='replace')}")
                # Cancel health monitoring task if it's still running
                if not health_monitor_task.done():
                    health_monitor_task.cancel()
                    try:
                        await health_monitor_task
                    except asyncio.CancelledError:
                        pass
                # Check if the session is still active (user still streaming)
                if hasattr(session, "state") and not getattr(session, "closed", False):
                    restart_count += 1
                    logger.warning(f"Twitch forwarding stopped unexpectedly. Restarting forwarding (restart #{restart_count}) as user is still streaming.")
                    await asyncio.sleep(2)
                    continue
                else:
                    logger.info("Session is closed, not restarting Twitch forwarding.")
                    break
        except asyncio.CancelledError:
            logger.info("Twitch forwarding task was cancelled")
            await self.terminate_ffmpeg(session)
        except Exception as e:
            logger.error(f"Exception while running FFmpeg: {e}", exc_info=True)
            await self.terminate_ffmpeg(session)

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
        if hasattr(session, 'ffmpeg_process') and session.ffmpeg_process:
            try:
                # Cancel health monitoring task if it exists
                if hasattr(session, 'health_monitor_task') and session.health_monitor_task:
                    if not session.health_monitor_task.done():
                        session.health_monitor_task.cancel()
                        try:
                            await asyncio.wait_for(session.health_monitor_task, timeout=1.0)
                        except (asyncio.CancelledError, asyncio.TimeoutError):
                            pass
                if session.ffmpeg_process.returncode is None:  # Process is still running
                    logger.info("Terminating FFmpeg process...")
                    # Log duration before terminating
                    if hasattr(session, 'twitch_forward_start_time'):
                        duration = datetime.datetime.now() - session.twitch_forward_start_time
                        hours, remainder = divmod(duration.seconds, 3600)
                        minutes, seconds = divmod(remainder, 60)
                        logger.info(f"FFmpeg process ran for {hours}h {minutes}m {seconds}s before termination")
                    session.ffmpeg_process.terminate()
                    try:
                        # Wait for a short time for graceful termination
                        await asyncio.wait_for(session.ffmpeg_process.wait(), timeout=5.0)
                    except asyncio.TimeoutError:
                        logger.warning("FFmpeg didn't terminate gracefully, killing it")
                        session.ffmpeg_process.kill()
                    finally:
                        logger.info("FFmpeg process terminated")
                elif hasattr(session, 'twitch_forward_start_time'):
                    # Log info about terminated process
                    duration = datetime.datetime.now() - session.twitch_forward_start_time
                    hours, remainder = divmod(duration.seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)
                    logger.info(f"FFmpeg process already terminated with code {session.ffmpeg_process.returncode} after running for {hours}h {minutes}m {seconds}s")
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
        if process:
            await process.communicate()
        logger.info("FFmpeg process finished.")
        # Remove the original FLV file after conversion
        os.remove(flv_file_path)
        logger.info(f"Converted file saved to {final_mp4_path}")

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

def create_ssl_context():
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    current_dir = os.path.dirname(os.path.abspath(__file__))
    cert_path = f"{current_dir}/ssl/fullchain.pem"
    key_path = f"{current_dir}/ssl/privkey.pem"
    context.load_cert_chain(certfile=cert_path, keyfile=key_path)
    return context

async def start_rtmp_server(twitch_server):
    current_dir = os.path.dirname(os.path.abspath(__file__))
    ssl_context = create_ssl_context()
    server = SimpleServer(output_directory=current_dir, twitch_server=twitch_server)
    await server.create(host=RTMPS_HOST, port=RTMPS_PORT, ssl_context=ssl_context)
    logger.info(f"RTMPS server started on {RTMPS_HOST}:{RTMPS_PORT} with SSL.")
    logger.info(f"Using Twitch ingest server location: {twitch_server}")
    await server.start()
    await server.wait_closed()

if __name__ == "__main__":
    try:
        args = parse_args()
        # Validate server selection
        if args.server not in TWITCH_INGEST_SERVERS:
            logger.warning(f"Invalid server location: {args.server}. Using default: {DEFAULT_INGEST_SERVER}")
            server_location = DEFAULT_INGEST_SERVER
        else:
            server_location = args.server
        asyncio.run(start_rtmp_server(server_location))
    except KeyboardInterrupt:
        logger.info("Server shutdown gracefully due to CTRL+C")