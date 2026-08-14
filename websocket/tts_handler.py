import os
import re
import uuid
import asyncio
import shutil
import subprocess
import aiohttp

# OpenAI TTS API Configuration
OPENAI_TTS_URL = "https://api.openai.com/v1/audio/speech"
MODEL_NAME = "gpt-4o-mini-tts"
DEFAULT_VOICE = "alloy"
ELEVENLABS_TTS_URL = "https://api.elevenlabs.io/v1/text-to-speech/{voice_id}"
ELEVENLABS_MODEL = "eleven_v3"
LAUGH_RE = re.compile(r"\b(lols?|lmao+|haha+h?|hahah+|hehe+)\b", re.IGNORECASE)
SHOUT_WORD_RE = re.compile(r"\b[A-Z]{3,}\b")
# OpenAI gpt-4o-mini-tts voice ids (lowercase). "verse" is used by several streamers.
AVAILABLE_VOICES = [
    "alloy", "ash", "ballad", "coral", "echo", "fable",
    "nova", "onyx", "sage", "shimmer", "verse",
]
# Where Caddy serves tts.botofthespecter.com (web host). Overridable via env.
DEFAULT_REMOTE_TTS_DIR = "/var/www/tts"
DEFAULT_LOCAL_TTS_DIR = "/home/botofthespecter/tts"


class TTSHandler:
    def __init__(self, logger, ssh_manager, sio=None, get_clients=None):
        self.logger = logger
        self.ssh_manager = ssh_manager
        self.sio = sio
        self.get_clients = get_clients
        # Staging dir on the websocket host (OpenAI writes here first)
        self.tts_dir = (os.getenv("TTS_LOCAL_DIR") or DEFAULT_LOCAL_TTS_DIR).strip()
        # Public web path on WEB (Caddy root). Same path used over SSH.
        self.remote_tts_dir = (
            os.getenv("TTS_REMOTE_DIR") or os.getenv("TTS_PUBLISH_DIR") or DEFAULT_REMOTE_TTS_DIR
        ).strip().rstrip("/") + "/"
        # SSH to WEB for publish — same .env keys as bot/api (not a separate JSON file)
        self.ssh_config = self._load_ssh_config_from_env()
        self.publish_dir = self._resolve_publish_dir()
        self.tts_queue = asyncio.Queue()
        self.processing_task = None
        self.openai_api_key = os.getenv("OPENAI_KEY")
        if not self.openai_api_key:
            self.logger.error("OPENAI_KEY env var not set - TTS will not work")
        self.elevenlabs_api_key = (os.getenv("ELEVENLABS_API_KEY") or "").strip()
        if not self.elevenlabs_api_key:
            self.logger.warning("ELEVENLABS_API_KEY env var not set - expressive TTS will fall back to normal")
        self.model_name = MODEL_NAME
        self.default_voice = DEFAULT_VOICE
        self.available_voices = AVAILABLE_VOICES

    def _load_ssh_config_from_env(self):
        """Build SSH target from the shared .env (WEB-HOST + SSH_USERNAME/PASSWORD).

        Do not use websocket_tts_config.json for hosts/credentials — that file had a
        placeholder hostname (your-server.com) that resolved to localhost and wrote
        MP3s to the wrong machine.
        """
        hostname = (os.getenv("WEB-HOST") or os.getenv("WEB_HOST") or "").strip()
        username = (os.getenv("SSH_USERNAME") or "").strip()
        password = os.getenv("SSH_PASSWORD") or ""
        port_raw = (os.getenv("SSH_PORT") or "22").strip()
        try:
            port = int(port_raw)
        except ValueError:
            port = 22
        if not hostname:
            self.logger.warning(
                "WEB-HOST not set in .env — TTS can only publish locally "
                f"(TTS_PUBLISH_DIR / {DEFAULT_REMOTE_TTS_DIR} if present on this host)"
            )
            return None
        if not username or not password:
            self.logger.warning(
                "SSH_USERNAME/SSH_PASSWORD not set in .env — TTS SSH publish to WEB disabled"
            )
            return None
        # Never log password
        self.logger.info(
            f"TTS SSH publish configured from .env: host={hostname} user={username} "
            f"remote_dir={self.remote_tts_dir}"
        )
        return {
            "hostname": hostname,
            "username": username,
            "password": password,
            "port": port,
        }

    def _resolve_publish_dir(self):
        """Local path only when this host can write the web-facing TTS tree directly."""
        env_dir = (os.getenv("TTS_PUBLISH_DIR") or "").strip()
        if env_dir and os.path.isdir(env_dir):
            return env_dir.rstrip("/")
        # Same-machine deploy: websocket on web1 with /var/www/tts
        if os.path.isdir(DEFAULT_REMOTE_TTS_DIR.rstrip("/")):
            # Prefer local only when we can write (avoids false "local" when dir is root-only junk)
            try:
                test = os.path.join(DEFAULT_REMOTE_TTS_DIR, ".tts_write_test")
                with open(test, "w") as f:
                    f.write("ok")
                os.remove(test)
                return DEFAULT_REMOTE_TTS_DIR.rstrip("/")
            except OSError:
                self.logger.info(
                    f"{DEFAULT_REMOTE_TTS_DIR} exists but not writable here — will use SSH to WEB-HOST"
                )
        return None

    async def get_available_voices(self):
        return self.available_voices

    async def start_processing(self):
        if not self.processing_task:
            self.processing_task = asyncio.create_task(self.process_tts_queue())
            self.logger.info("TTS queue processing started")

    async def stop_processing(self):
        if self.processing_task:
            self.processing_task.cancel()
            try:
                await self.processing_task
            except asyncio.CancelledError:
                pass
            self.processing_task = None
            self.logger.info("TTS queue processing stopped")

    def apply_expressive_tags(self, text):
        if not text:
            return text
        tagged = LAUGH_RE.sub("[laughs]", text)
        letters = [c for c in tagged if c.isalpha()]
        if letters and len(letters) >= 3 and all(c.isupper() for c in letters):
            if "[shouts]" not in tagged.lower():
                tagged = "[shouts] " + tagged
            return tagged
        return SHOUT_WORD_RE.sub(lambda m: f"[shouts] {m.group(0)}", tagged)

    async def add_tts_request(self, text, code, language_code=None, gender=None, voice_name=None, style=None, expressive_voice=None):
        if not text:
            self.logger.warning(f"add_tts_request called with empty/None text for code={code}; ignoring")
            return
        request_id = uuid.uuid4().hex[:8]
        self.logger.info(f"[TTS-ADD-{request_id}] add_tts_request called with text='{text[:50]}...', code={code}, voice={voice_name}, style={style}")
        await self.tts_queue.put({
            "text": text,
            "code": code,
            "language_code": language_code,
            "gender": gender,
            "voice_name": voice_name,
            "style": (style or "normal").strip().lower(),
            "expressive_voice": expressive_voice,
            "request_id": request_id
        })
        queue_size = self.tts_queue.qsize()
        self.logger.info(f"[TTS-ADD-{request_id}] TTS request added to queue. Text: '{text[:50]}...', Queue size: {queue_size}")

    async def process_tts_queue(self):
        batch_size = 3  # Process up to 3 TTS requests concurrently
        while True:
            try:
                # Collect a batch of requests
                batch = []
                for _ in range(batch_size):
                    try:
                        # Try to get a request with a short timeout
                        request_data = await asyncio.wait_for(self.tts_queue.get(), timeout=0.1)
                        batch.append(request_data)
                    except asyncio.TimeoutError:
                        # No more requests available, break
                        break
                if not batch:
                    # No requests available, wait for the next one
                    request_data = await self.tts_queue.get()
                    batch = [request_data]
                # Process the batch concurrently
                await self.process_tts_batch(batch)
                # Mark all tasks as done
                for _ in batch:
                    self.tts_queue.task_done()
            except asyncio.CancelledError:
                break
            except Exception as e:
                self.logger.error(f"Error processing TTS queue: {e}")

    async def process_tts_batch(self, batch):
        batch_ids = [req.get('request_id', 'unknown') for req in batch]
        self.logger.info(f"[TTS-BATCH] Processing batch of {len(batch)} TTS requests. IDs: {batch_ids}")
        # Create tasks for concurrent API calls
        tasks = []
        for request_data in batch:
            task = self.generate_tts_audio(request_data)
            tasks.append(task)
        # Execute all API calls concurrently
        results = await asyncio.gather(*tasks, return_exceptions=True)
        # Process results in order (maintain sequence)
        for i, (request_data, result) in enumerate(zip(batch, results)):
            text = request_data.get('text')
            code = request_data.get('code')
            request_id = request_data.get('request_id', 'unknown')
            self.logger.info(f"[TTS-PROCESS-{request_id}] Processing result for text: '{text[:30]}...'")
            if isinstance(result, Exception):
                self.logger.error(f"Failed to generate TTS for batch item {i}: {result}")
                continue
            audio_file = result
            if audio_file is None:
                self.logger.error(f"Failed to generate TTS audio for batch item {i}, code {code}")
                continue
            # Process this completed TTS request (transfer, emit, wait, cleanup)
            await self.process_completed_tts(audio_file, code, text)

    async def process_completed_tts(self, audio_file, code, text):
        try:
            # Transfer file to remote server if needed
            remote_filename = os.path.basename(audio_file)
            remote_path = await self.move_file_to_remote(audio_file, remote_filename)
            if remote_path:
                self.logger.info(f"TTS file transferred to remote server: {remote_path}")
                # Emit TTS event to registered clients
                await self.emit_tts_event(code, remote_filename, text)
        except Exception as e:
            self.logger.error(f"Error transferring TTS file: {e}")
            return
        # Estimate the duration of the audio and wait for it to finish
        duration = self.estimate_audio_duration(audio_file, text)
        # Keep file longer than playback so OBS can finish the GET (no ffprobe on many hosts)
        wait_s = max(duration + 10, 15)
        self.logger.info(f"TTS event emitted. Waiting {wait_s}s before cleanup (est. play {duration}s).")
        await asyncio.sleep(wait_s)
        # After playback, delete the TTS file from both local and remote
        try:
            await self.cleanup_tts_file(audio_file)
        except Exception as e:
            self.logger.error(f"Error cleaning up TTS file: {e}")

    async def process_tts_request(self, text, code, language_code=None, gender=None, voice_name=None, style=None, expressive_voice=None):
        self.logger.info(f"Processing TTS request for code {code} with text: {text}")
        audio_file = await self.generate_tts_audio({
            "text": text,
            "code": code,
            "voice_name": voice_name,
            "style": style,
            "expressive_voice": expressive_voice,
        })
        if audio_file is None:
            self.logger.error(f"Failed to generate TTS audio for code {code}")
            return
        try:
            # Transfer file to remote server if needed
            remote_filename = os.path.basename(audio_file)
            remote_path = await self.move_file_to_remote(audio_file, remote_filename)
            if remote_path:
                self.logger.info(f"TTS file transferred to remote server: {remote_path}")
                # Emit TTS event to registered clients
                await self.emit_tts_event(code, remote_filename, text)
        except Exception as e:
            self.logger.error(f"Error transferring TTS file: {e}")
            return
        duration = self.estimate_audio_duration(audio_file, text)
        wait_s = max(duration + 10, 15)
        self.logger.info(f"TTS event emitted. Waiting {wait_s}s before cleanup (est. play {duration}s).")
        await asyncio.sleep(wait_s)
        # After playback, delete the TTS file from both local and remote
        try:
            await self.cleanup_tts_file(audio_file)
        except Exception as e:
            self.logger.error(f"Error cleaning up TTS file: {e}")

    async def generate_tts_audio(self, request_data):
        text = request_data.get("text")
        code = request_data.get("code")
        voice_name = request_data.get("voice_name")
        style = (request_data.get("style") or "normal").strip().lower()
        expressive_voice = request_data.get("expressive_voice")
        if style == "expressive" and expressive_voice:
            tagged = self.apply_expressive_tags(text)
            audio_file = await self.generate_elevenlabs_tts(tagged, code, expressive_voice)
            if audio_file:
                return audio_file
            self.logger.warning("Expressive TTS failed; falling back to normal TTS")
        return await self.generate_api_tts(text, code, voice_name)

    async def generate_elevenlabs_tts(self, text, code, voice_id):
        if not self.elevenlabs_api_key:
            self.logger.error("ELEVENLABS_API_KEY not set")
            return None
        if not voice_id:
            self.logger.error("Expressive TTS missing voice id")
            return None
        if len(text) > 5000:
            self.logger.error(f"Text too long for expressive TTS: {len(text)} characters")
            return None
        unique_id = uuid.uuid4().hex[:8]
        code_tag = (code or "anon")[:12]
        filename = f'tts_output_{code_tag}_{unique_id}.mp3'
        filepath = os.path.join(self.tts_dir, filename)
        url = ELEVENLABS_TTS_URL.format(voice_id=voice_id)
        headers = {
            "xi-api-key": self.elevenlabs_api_key,
            "Accept": "audio/mpeg",
            "Content-Type": "application/json",
        }
        body = {
            "text": text,
            "model_id": ELEVENLABS_MODEL,
        }
        try:
            os.makedirs(self.tts_dir, exist_ok=True)
            async with aiohttp.ClientSession() as session:
                async with session.post(
                    url,
                    params={"output_format": "mp3_44100_128"},
                    headers=headers,
                    json=body,
                    timeout=aiohttp.ClientTimeout(total=60),
                ) as response:
                    if response.status != 200:
                        err_text = await response.text()
                        self.logger.error(f"Expressive TTS API error {response.status}: {err_text[:500]}")
                        return None
                    with open(filepath, "wb") as f:
                        async for chunk in response.content.iter_chunked(8192):
                            f.write(chunk)
            self.logger.info(f"Expressive TTS audio generated and saved: {filepath}")
            return filepath
        except asyncio.TimeoutError:
            self.logger.error("Expressive TTS API timeout after 60s")
            return None
        except Exception as e:
            self.logger.error(f"Error generating expressive TTS: {e}")
            return None

    async def generate_api_tts(self, text, code, voice_name=None):
        if not self.openai_api_key:
            self.logger.error("OPENAI_KEY not set")
            return None
        # Validate text length (OpenAI limit is 4096 characters)
        if len(text) > 4096:
            self.logger.error(f"Text too long: {len(text)} characters (max 4096)")
            return None
        # Validate voice (normalise to lowercase to match OpenAI identifiers)
        if voice_name:
            voice_name = voice_name.lower()
        if not voice_name or voice_name not in self.available_voices:
            voice_name = self.default_voice
            self.logger.info(f"Using default voice: {voice_name}")
        unique_id = uuid.uuid4().hex[:8]
        # Short id only — do not embed the full API key in the public filename/URL.
        code_tag = (code or "anon")[:12]
        filename = f'tts_output_{code_tag}_{unique_id}.mp3'
        filepath = os.path.join(self.tts_dir, filename)
        headers = {
            "Authorization": f"Bearer {self.openai_api_key}",
            "Content-Type": "application/json",
        }
        body = {
            "model": self.model_name,
            "voice": voice_name,
            "input": text,
            "response_format": "mp3",
        }
        try:
            os.makedirs(self.tts_dir, exist_ok=True)
            async with aiohttp.ClientSession() as session:
                async with session.post(
                    OPENAI_TTS_URL,
                    headers=headers,
                    json=body,
                    timeout=aiohttp.ClientTimeout(total=60),
                ) as response:
                    if response.status != 200:
                        err_text = await response.text()
                        self.logger.error(f"OpenAI TTS API error {response.status}: {err_text[:500]}")
                        return None
                    with open(filepath, "wb") as f:
                        async for chunk in response.content.iter_chunked(8192):
                            f.write(chunk)
            self.logger.info(f"TTS audio generated and saved: {filepath}")
            return filepath
        except asyncio.TimeoutError:
            self.logger.error("OpenAI TTS API timeout after 60s")
            return None
        except Exception as e:
            self.logger.error(f"Error generating TTS via OpenAI API: {e}")
            return None

    def estimate_audio_duration(self, audio_file, text):
        try:
            # Try to get actual duration from the audio file if possible
            result = subprocess.run([
                'ffprobe', '-v', 'quiet', '-show_entries', 'format=duration',
                '-of', 'csv=p=0', audio_file
            ], capture_output=True, text=True, timeout=10)
            if result.returncode == 0 and result.stdout.strip():
                duration = float(result.stdout.strip())
                self.logger.info(f"Actual audio duration: {duration} seconds")
                return duration
        except Exception as e:
            self.logger.warning(f"Could not get actual audio duration: {e}")
        # Fallback to estimation based on text length
        words = len(text.split())
        estimated_duration = (words / 180) * 60  # 180 words per minute
        estimated_duration = max(2, estimated_duration)  # Minimum 2 seconds
        self.logger.info(f"Estimated audio duration: {estimated_duration} seconds (based on {words} words)")
        return estimated_duration

    async def cleanup_tts_file(self, file_path, delay_seconds=0):
        try:
            if delay_seconds > 0:
                await asyncio.sleep(delay_seconds)
            if os.path.exists(file_path):
                os.remove(file_path)
                self.logger.info(f"Cleaned up TTS file: {file_path}")
                # Also try to clean up from remote server via SSH
                filename = os.path.basename(file_path)
                await self.cleanup_remote_tts_file(filename)
            else:
                self.logger.warning(f"TTS file not found for cleanup: {file_path}")
        except Exception as e:
            self.logger.error(f"Error cleaning up TTS file {file_path}: {e}")

    async def cleanup_remote_tts_file(self, filename):
        # Prefer local publish dir
        if self.publish_dir:
            path = os.path.join(self.publish_dir, filename)
            try:
                if os.path.isfile(path):
                    os.remove(path)
                    self.logger.info(f"Deleted published TTS file: {path}")
                    return
            except OSError as e:
                self.logger.warning(f"Local TTS cleanup failed for {path}: {e}")
        if not self.ssh_config:
            return
        try:
            conn = await self.ssh_manager.get_connection(self.ssh_config)
            remote_dir = self.remote_tts_dir
            remote_file_path = f"{remote_dir.rstrip('/')}/{filename}"
            self.logger.info(f"Executing remote cleanup on {self.ssh_config['hostname']}: rm -f '{remote_file_path}'")
            result = await conn.run(f"rm -f '{remote_file_path}'")
            if result.exit_status == 0:
                self.logger.info(f"Successfully deleted remote file: {remote_file_path}")
            else:
                self.logger.warning(f"Remote delete returned {result.exit_status}: {result.stderr.strip()}")
        except Exception as e:
            self.logger.error(f"Error in remote cleanup for {filename}: {e}")

    async def move_file_to_remote(self, local_file_path, remote_filename):
        # Prefer local write when this host serves /var/www/tts (or TTS_PUBLISH_DIR)
        publish_dir = self.publish_dir
        if publish_dir:
            try:
                os.makedirs(publish_dir, exist_ok=True)
                dest = os.path.join(publish_dir, remote_filename)
                await asyncio.to_thread(shutil.copy2, local_file_path, dest)
                self.logger.info(f"TTS published locally: {dest}")
                return dest
            except Exception as e:
                self.logger.error(f"Local TTS publish failed ({publish_dir}): {e}")
        if not self.ssh_config:
            self.logger.error(
                "No local TTS publish dir and no SSH config "
                "(need WEB-HOST + SSH_USERNAME + SSH_PASSWORD in .env)"
            )
            return None
        try:
            host = self.ssh_config["hostname"]
            self.logger.info(f"TTS SFTP to WEB host={host} path={self.remote_tts_dir}{remote_filename}")
            conn = await self.ssh_manager.get_connection(self.ssh_config)
            remote_dir = self.remote_tts_dir
            remote_file_path = f"{remote_dir.rstrip('/')}/{remote_filename}"
            await conn.run(f"mkdir -p '{remote_dir}'")
            async with conn.start_sftp_client() as sftp:
                await sftp.put(local_file_path, remote_file_path)
            result = await conn.run(f"chown www-data:www-data '{remote_file_path}'")
            if result.exit_status == 0:
                self.logger.info("File ownership set successfully")
            else:
                self.logger.warning(f"Failed to set ownership: {result.stderr.strip()}")
            self.logger.info(f"File transferred successfully via SSH to {host}:{remote_file_path}")
            return remote_file_path
        except Exception as e:
            self.logger.error(f"Error transferring file {local_file_path}: {e}")
            return None

    async def emit_tts_event(self, code, audio_filename, text):
        self.logger.info(f"[TTS-EMIT] emit_tts_event called for code={code}, file={audio_filename}, text='{text[:30]}...'")
        if not self.sio or not self.get_clients:
            self.logger.warning("Cannot emit TTS event: socketio or get_clients not available")
            return
        try:
            registered_clients = self.get_clients()
            if code in registered_clients:
                clients_for_code = registered_clients[code]
                audio_url = f"https://tts.botofthespecter.com/{audio_filename}"
                tts_data = {"audio_file": audio_url, "text": text, "filename": audio_filename}
                if isinstance(clients_for_code, list):
                    for client in clients_for_code:
                        if isinstance(client, dict) and 'sid' in client:
                            sid = client['sid']
                        else:
                            sid = client  # Assume it's directly the SID
                        try:
                            await self.sio.emit('TTS', tts_data, to=sid)
                            self.logger.info(f"TTS event sent to SID {sid} with audio: {audio_url}")
                        except Exception as emit_error:
                            self.logger.error(f"Failed to emit to SID {sid}: {emit_error}")
                    self.logger.info(f"TTS event emitted to {len(clients_for_code)} clients for code {code}")
                else:
                    self.logger.error(f"Expected list of clients but got: {type(clients_for_code)}")
            else:
                self.logger.warning(f"No registered clients found for code {code} ({len(registered_clients)} other codes connected)")
        except Exception as e:
            self.logger.error(f"Error emitting TTS event: {e}")
            import traceback
            self.logger.error(f"Full traceback: {traceback.format_exc()}")