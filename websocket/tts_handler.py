import os
import uuid
import json
import asyncio
import subprocess
import shutil
from pathlib import Path

class TTSHandler:
    def __init__(self, logger, ssh_manager, sio=None, get_clients=None):
        self.logger = logger
        self.ssh_manager = ssh_manager
        self.sio = sio
        self.get_clients = get_clients
        self.tts_dir = "/home/botofthespecter/tts"
        self.tts_config = self.load_tts_config()
        self.tts_queue = asyncio.Queue()
        self.processing_task = None

    def load_tts_config(self):
        config_path = "/home/botofthespecter/websocket_tts_config.json"
        try:
            with open(config_path, 'r') as f:
                config = json.load(f)
                self.logger.info("TTS configuration loaded successfully")
                return config
        except Exception as e:
            self.logger.error(f"Failed to load TTS config from {config_path}: {e}")
            return None

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

    async def add_tts_request(self, text, code, language_code=None, gender=None, voice_name=None):
        await self.tts_queue.put({
            "text": text,
            "code": code,
            "language_code": language_code,
            "gender": gender,
            "voice_name": voice_name
        })
        self.logger.info(f"TTS request added to queue: {text[:50]}...")

    async def process_tts_queue(self):
        while True:
            try:
                # Wait for the next TTS request in the queue
                request_data = await self.tts_queue.get()
                text = request_data.get('text')
                code = request_data.get('code')
                language_code = request_data.get('language_code')
                gender = request_data.get('gender')
                voice_name = request_data.get('voice_name')
                # Process the TTS request
                await self.process_tts_request(text, code, language_code, gender, voice_name) # Mark the task as done
                self.tts_queue.task_done()
            except asyncio.CancelledError:
                break
            except Exception as e:
                self.logger.error(f"Error processing TTS queue: {e}")

    async def process_tts_request(self, text, code, language_code=None, gender=None, voice_name=None):
        self.logger.info(f"Processing TTS request for code {code} with text: {text}")
        # Generate TTS using the local script in its own environment
        audio_file = await self.generate_local_tts(text, code, voice_name)
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
        # Estimate the duration of the audio and wait for it to finish
        duration = self.estimate_audio_duration(audio_file, text)
        self.logger.info(f"TTS event emitted. Waiting for {duration} seconds before continuing.")
        await asyncio.sleep(duration + 5)
        # After playback, delete the TTS file from both local and remote
        try:
            await self.cleanup_tts_file(audio_file)
        except Exception as e:
            self.logger.error(f"Error cleaning up TTS file: {e}")

    async def generate_local_tts(self, text, code, voice_name=None):
        try:
            unique_id = uuid.uuid4().hex[:8]
            # Absolute paths for TTS script and environment (both in /home/botofthespecter/)
            tts_script_path = "/home/botofthespecter/local_tts_generator.py"
            python_exe = "/home/botofthespecter/tts_env/bin/python"
            config_path = "/home/botofthespecter/websocket_tts_config.json"
            desired_filename = f'tts_output_{code}_{unique_id}.mp3'
            cmd = [
                python_exe,
                tts_script_path,
                "--text", text,
                "--config", config_path,
                "--filename", desired_filename,
                "--keep-local"  # Keep local copy for SFTP transfer
            ]
            # Add voice parameter if specified
            if voice_name:
                cmd.extend(["--voice", voice_name])
            self.logger.info(f"Running TTS command: {' '.join(cmd)}")
            # Set environment variables to suppress NNPACK warnings
            env = os.environ.copy()
            env.update({
                'NNPACK_DISABLE': '1',
                'PYTORCH_DISABLE_NNPACK_RUNTIME_ERROR': '1',
                'OMP_NUM_THREADS': '1',
                'MKL_NUM_THREADS': '1'
            })
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                cwd="/home/botofthespecter",
                env=env
            )
            stdout, stderr = await process.communicate()
            if process.returncode == 0:
                self.logger.info(f"TTS generation successful: {stdout.decode()}")
                # Check for local copy
                local_output_dir = "/home/botofthespecter/local_tts_output"
                local_file_path = os.path.join(local_output_dir, desired_filename)
                if os.path.exists(local_file_path):
                    # Move the local file to the TTS directory for serving
                    final_file_path = os.path.join(self.tts_dir, desired_filename)
                    shutil.move(local_file_path, final_file_path)
                    self.logger.info(f"TTS file ready: {final_file_path}")
                    return final_file_path
                else:
                    self.logger.error(f"Generated TTS file not found: {local_file_path}")
                    return None
            else:
                self.logger.error(f"TTS generation failed: {stderr.decode()}")
                return None
        except Exception as e:
            self.logger.error(f"Error generating local TTS: {e}")
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
        if not self.tts_config or not self.tts_config.get('ssh_config'):
            self.logger.warning("No SSH config available for remote cleanup")
            return
        try:
            # Get SSH connection from manager
            ssh_client = await self.ssh_manager.get_connection(self.tts_config['ssh_config'])
            # Build remote file path
            remote_dir = self.tts_config['remote_paths']['tts_directory']
            remote_file_path = f"{remote_dir.rstrip('/')}/{filename}"
            # Execute delete command
            command = f"rm -f '{remote_file_path}'"
            self.logger.info(f"Executing remote cleanup: {command}")
            stdin, stdout, stderr = ssh_client.exec_command(command)
            exit_status = stdout.channel.recv_exit_status()
            if exit_status == 0:
                self.logger.info(f"Successfully deleted remote file: {remote_file_path}")
            else:
                error_msg = stderr.read().decode().strip()
                self.logger.warning(f"Remote delete command returned {exit_status}: {error_msg}")
        except Exception as e:
            self.logger.error(f"Error in remote cleanup for {filename}: {e}")

    async def move_file_to_remote(self, local_file_path, remote_filename):
        if not self.tts_config or not self.tts_config.get('ssh_config'):
            self.logger.warning("No SSH config available for file transfer")
            return None
        try:
            # Get SSH connection from manager
            ssh_client = await self.ssh_manager.get_connection(self.tts_config['ssh_config'])
            # Build remote path
            remote_dir = self.tts_config['remote_paths']['tts_directory']
            remote_file_path = f"{remote_dir.rstrip('/')}/{remote_filename}"
            # Create remote directory if needed
            mkdir_command = f"mkdir -p '{remote_dir}'"
            ssh_client.exec_command(mkdir_command)
            # Transfer file using SCP
            from scp import SCPClient
            with SCPClient(ssh_client.get_transport()) as scp:
                scp.put(local_file_path, remote_file_path)
            self.logger.info(f"File transferred successfully: {remote_file_path}")
            return remote_file_path
        except Exception as e:
            self.logger.error(f"Error transferring file {local_file_path}: {e}")
            return None

    async def emit_tts_event(self, code, audio_filename, text):
        if not self.sio or not self.get_clients:
            self.logger.warning("Cannot emit TTS event: socketio or get_clients not available")
            return
        try:
            registered_clients = self.get_clients()
            self.logger.info(f"Attempting to emit TTS event for code: {code}")
            self.logger.info(f"Registered clients: {registered_clients}")
            if code in registered_clients:
                clients_for_code = registered_clients[code]
                self.logger.info(f"Clients for code {code}: {clients_for_code}")
                # Construct the audio file URL
                audio_url = f"https://tts.botofthespecter.com/{audio_filename}"
                # Prepare the TTS event data
                tts_data = {"audio_file": audio_url,"text": text,"filename": audio_filename}
                self.logger.info(f"TTS data to emit: {tts_data}")
                # Emit to all clients registered with this code
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
                    self.logger.error(f"Expected list of clients but got: {type(clients_for_code)} - {clients_for_code}")
            else:
                self.logger.warning(f"No registered clients found for code {code}")
                self.logger.info(f"Available codes: {list(registered_clients.keys())}")
        except Exception as e:
            self.logger.error(f"Error emitting TTS event: {e}")
            import traceback
            self.logger.error(f"Full traceback: {traceback.format_exc()}")