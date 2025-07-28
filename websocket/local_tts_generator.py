import os
import sys
import tempfile
import argparse
import logging
from pathlib import Path
os.environ['NNPACK_DISABLE'] = '1'
os.environ['PYTORCH_DISABLE_NNPACK_RUNTIME_ERROR'] = '1'
os.environ['OMP_NUM_THREADS'] = '1'
os.environ['MKL_NUM_THREADS'] = '1'
import warnings
warnings.filterwarnings("ignore", category=UserWarning, module="torch")
import paramiko
from scp import SCPClient
import json
import hashlib
from datetime import datetime
import subprocess
import contextlib
import torch
torch.backends.disable_global_flags()
from TTS.api import TTS

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('tts_generator.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

@contextlib.contextmanager
def suppress_stderr():
    with open(os.devnull, "w") as devnull:
        old_stderr = sys.stderr
        sys.stderr = devnull
        try:
            yield
        finally:
            sys.stderr = old_stderr

class TTSGenerator:
    def __init__(self):
        self.config = self.load_config()
        self.tts_model = None
        self.ssh_client = None
        # Performance optimizations
        self.use_cache = self.config.get('performance', {}).get('use_cache', False)
        self.cache_dir = self.config.get('performance', {}).get('cache_dir', './tts_cache')
        # Set TTS model cache directory if specified
        self.model_cache_dir = self.config.get('performance', {}).get('model_cache_dir')
        if self.model_cache_dir:
            os.makedirs(self.model_cache_dir, exist_ok=True)
            os.environ['TTS_CACHE_PATH'] = self.model_cache_dir
        if self.use_cache:
            os.makedirs(self.cache_dir, exist_ok=True)
    def load_config(self):
        default_model_cache_dir = os.path.join(str(Path.home()), '.local_tts_model_cache')
        return {
            "tts_model": "tts_models/en/ljspeech/tacotron2-DDC",
            "ssh_config": {
                "hostname": "your-server.com",
                "username": "username",
                "key_filename": None,  # Path to SSH private key
                "password": None,      # Use either key_filename or password
                "port": 22
            },
            "remote_paths": {
                "tts_directory": "/path/to/server/tts/",
                "temp_directory": "/tmp/tts/"
            },
            "audio_settings": {
                "sample_rate": 22050,
                "format": "mp3"
            },
            "performance": {
                "use_cache": False,
                "cache_dir": './tts_cache',
                "model_cache_dir": default_model_cache_dir
            }
        }

    def initialize_tts(self):
        try:
            model_name = self.config['tts_model']
            logger.info(f"Initializing TTS model: {model_name}")
            # Performance settings
            performance_config = self.config.get('performance', {})
            use_gpu = performance_config.get('use_gpu', True)
            # Set TTS_CACHE_PATH if not already set and model_cache_dir is specified
            if self.model_cache_dir and not os.environ.get('TTS_CACHE_PATH'):
                os.environ['TTS_CACHE_PATH'] = self.model_cache_dir
            # Check GPU availability
            gpu_available = torch.cuda.is_available()
            if use_gpu and gpu_available:
                logger.info("GPU detected, using CUDA acceleration")
                device = "cuda"
            else:
                logger.info("Using CPU for TTS generation")
                device = "cpu"
            with suppress_stderr():
                # Initialize with performance optimizations
                self.tts_model = TTS(model_name, gpu=use_gpu and gpu_available)
            logger.info(f"TTS model initialized successfully on {device}")
        except Exception as e:
            logger.error(f"Failed to initialize TTS model: {e}")
            raise
    def generate_filename(self, text):
        # Always generate a unique filename based on text
        content = f"{text}_default"
        hash_object = hashlib.md5(content.encode())
        hash_hex = hash_object.hexdigest()
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        return f"tts_{timestamp}_{hash_hex[:8]}.wav"
    def generate_tts(self, text, output_path):
        try:
            if not self.tts_model:
                self.initialize_tts()
            logger.info(f"Generating TTS for text: {text[:50]}...")
            # Always use default model voice
            with suppress_stderr():
                self.tts_model.tts_to_file(text=text, file_path=output_path)
            logger.info(f"TTS generated successfully: {output_path}")
            return True
        except Exception as e:
            logger.error(f"Error generating TTS: {e}")
            return False
    def convert_to_mp3(self, wav_path, mp3_path):
        try:
            # Check if ffmpeg is available
            result = subprocess.run(['ffmpeg', '-version'], capture_output=True, text=True)
            if result.returncode != 0:
                logger.warning("FFmpeg not found. Audio will remain in WAV format.")
                return wav_path
            # Get voice processing settings
            voice_processing = self.config.get('voice_processing', {})
            pitch_shift = voice_processing.get('pitch_shift', 0)
            speed_adjustment = voice_processing.get('speed_adjustment', 1.0)
            # Build ffmpeg command with voice processing
            cmd = ['ffmpeg', '-i', wav_path]
            # Apply pitch shift and speed adjustment if configured
            if pitch_shift != 0 or speed_adjustment != 1.0:
                # Use rubberband filter for pitch and tempo adjustment
                filter_parts = []
                if pitch_shift != 0:
                    filter_parts.append(f"rubberband=pitch={pitch_shift}")
                if speed_adjustment != 1.0:
                    filter_parts.append(f"atempo={speed_adjustment}")
                
                if filter_parts:
                    cmd.extend(['-af', ','.join(filter_parts)])
            cmd.extend([
                '-acodec', 'mp3', 
                '-ab', '128k',
                '-y',  # Overwrite output file
                mp3_path
            ])
            logger.info(f"Converting to MP3 with voice processing: {' '.join(cmd)}")
            result = subprocess.run(cmd, capture_output=True, text=True)
            if result.returncode == 0:
                logger.info(f"Successfully converted to MP3: {mp3_path}")
                # Remove original WAV file
                os.remove(wav_path)
                return mp3_path
            else:
                logger.error(f"FFmpeg conversion failed: {result.stderr}")
                # If advanced processing fails, try simple conversion
                logger.info("Trying simple conversion without voice processing...")
                simple_cmd = [
                    'ffmpeg', '-i', wav_path, 
                    '-acodec', 'mp3', 
                    '-ab', '128k',
                    '-y', mp3_path
                ]
                simple_result = subprocess.run(simple_cmd, capture_output=True, text=True)
                if simple_result.returncode == 0:
                    logger.info(f"Simple conversion successful: {mp3_path}")
                    os.remove(wav_path)
                    return mp3_path
                return wav_path
        except Exception as e:
            logger.error(f"Error converting to MP3: {e}")
            return wav_path
    def setup_ssh_connection(self):
        try:
            self.ssh_client = paramiko.SSHClient()
            self.ssh_client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            ssh_config = self.config['ssh_config']
            # Connect using key or password
            if ssh_config.get('key_filename'):
                self.ssh_client.connect(
                    hostname=ssh_config['hostname'],
                    username=ssh_config['username'],
                    key_filename=ssh_config['key_filename'],
                    port=ssh_config['port']
                )
            elif ssh_config.get('password'):
                self.ssh_client.connect(
                    hostname=ssh_config['hostname'],
                    username=ssh_config['username'],
                    password=ssh_config['password'],
                    port=ssh_config['port']
                )
            else:
                raise ValueError("Either key_filename or password must be provided in SSH config")
            logger.info("SSH connection established")
            return True
        except Exception as e:
            logger.error(f"Failed to establish SSH connection: {e}")
            return False
    def transfer_file(self, local_path, remote_filename=None):
        try:
            if not self.ssh_client:
                if not self.setup_ssh_connection():
                    return False
            remote_filename = remote_filename or os.path.basename(local_path)
            remote_path = os.path.join(
                self.config['remote_paths']['tts_directory'], 
                remote_filename
            ).replace('\\', '/')
            # Create remote directory if it doesn't exist
            stdin, stdout, stderr = self.ssh_client.exec_command(
                f"mkdir -p {self.config['remote_paths']['tts_directory']}"
            )
            # Transfer file
            with SCPClient(self.ssh_client.get_transport()) as scp:
                scp.put(local_path, remote_path)
            logger.info(f"File transferred successfully: {remote_path}")
            return remote_path
        except Exception as e:
            logger.error(f"File transfer failed: {e}")
            return None
    def cleanup_ssh(self):
        if self.ssh_client:
            self.ssh_client.close()
            logger.info("SSH connection closed")
    def process_tts_request(self, text):
        try:
            # Create temporary directory for processing
            with tempfile.TemporaryDirectory() as temp_dir:
                wav_filename = self.generate_filename(text)
                wav_path = os.path.join(temp_dir, wav_filename)
                if not self.generate_tts(text, wav_path):
                    return None
                # Convert to MP3 if required
                final_path = wav_path
                if self.config['audio_settings']['format'].lower() == 'mp3':
                    mp3_filename = wav_filename.replace('.wav', '.mp3')
                    mp3_path = os.path.join(temp_dir, mp3_filename)
                    final_path = self.convert_to_mp3(wav_path, mp3_path)
                # Transfer to server
                remote_path = self.transfer_file(final_path)
                return {
                    'success': True,
                    'remote_path': remote_path,
                    'filename': os.path.basename(final_path)
                }
        except Exception as e:
            logger.error(f"TTS processing failed: {e}")
            return {'success': False, 'error': str(e)}
        finally:
            self.cleanup_ssh()

def main():
    parser = argparse.ArgumentParser(description='Local TTS Generator')
    parser.add_argument('--text', '-t', required=True, help='Text to convert to speech')
    args = parser.parse_args()
    generator = TTSGenerator()
    result = generator.process_tts_request(text=args.text)
    if result and result.get('success'):
        print(f"TTS generation successful!")
        print(f"Remote file: {result['remote_path']}")
    else:
        error_msg = result.get('error', 'Unknown error') if result else 'Failed to generate TTS'
        print(f"TTS generation failed: {error_msg}")
        sys.exit(1)

if __name__ == "__main__":
    main()
