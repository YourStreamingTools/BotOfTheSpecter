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
    def __init__(self, config_file=None):
        self.config = self.load_config(config_file)
        self.tts_model = None
        self.ssh_client = None
    def load_config(self, config_file):
        default_config = {
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
            }
        }
        if config_file and os.path.exists(config_file):
            try:
                with open(config_file, 'r') as f:
                    loaded_config = json.load(f)
                    # Merge with defaults
                    for key, value in loaded_config.items():
                        if isinstance(value, dict) and key in default_config:
                            default_config[key].update(value)
                        else:
                            default_config[key] = value
            except Exception as e:
                logger.error(f"Error loading config file: {e}")
                logger.info("Using default configuration")
        return default_config
    def initialize_tts(self):
        try:
            logger.info(f"Initializing TTS model: {self.config['tts_model']}")
            with suppress_stderr():
                self.tts_model = TTS(self.config['tts_model'])
            logger.info("TTS model initialized successfully")
        except Exception as e:
            logger.error(f"Failed to initialize TTS model: {e}")
            raise
    def generate_filename(self, text, voice=None):
        # Create hash of text and voice for unique filename
        content = f"{text}_{voice or 'default'}"
        hash_object = hashlib.md5(content.encode())
        hash_hex = hash_object.hexdigest()
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        return f"tts_{timestamp}_{hash_hex[:8]}.wav"
    def generate_tts(self, text, output_path, voice=None):
        try:
            if not self.tts_model:
                self.initialize_tts()
            logger.info(f"Generating TTS for text: {text[:50]}...")
            # Generate TTS with stderr suppression to hide NNPACK warnings
            with suppress_stderr():
                if voice:
                    # If voice is specified and model supports it
                    self.tts_model.tts_to_file(text=text, file_path=output_path, speaker=voice)
                else:
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
            # Convert to MP3
            cmd = [
                'ffmpeg', '-i', wav_path, 
                '-acodec', 'mp3', 
                '-ab', '128k',
                '-y',  # Overwrite output file
                mp3_path
            ]
            result = subprocess.run(cmd, capture_output=True, text=True)
            if result.returncode == 0:
                logger.info(f"Successfully converted to MP3: {mp3_path}")
                # Remove original WAV file
                os.remove(wav_path)
                return mp3_path
            else:
                logger.error(f"FFmpeg conversion failed: {result.stderr}")
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
    def process_tts_request(self, text, voice=None, keep_local=False):
        try:
            # Create temporary directory for processing
            with tempfile.TemporaryDirectory() as temp_dir:
                # Generate filename
                wav_filename = self.generate_filename(text, voice)
                wav_path = os.path.join(temp_dir, wav_filename)
                # Generate TTS
                if not self.generate_tts(text, wav_path, voice):
                    return None
                # Convert to MP3 if required
                final_path = wav_path
                if self.config['audio_settings']['format'].lower() == 'mp3':
                    mp3_filename = wav_filename.replace('.wav', '.mp3')
                    mp3_path = os.path.join(temp_dir, mp3_filename)
                    final_path = self.convert_to_mp3(wav_path, mp3_path)
                # Transfer to server
                remote_path = self.transfer_file(final_path)
                # Keep local copy if requested
                if keep_local:
                    local_output_dir = "local_tts_output"
                    os.makedirs(local_output_dir, exist_ok=True)
                    local_copy_path = os.path.join(local_output_dir, os.path.basename(final_path))
                    
                    import shutil
                    shutil.copy2(final_path, local_copy_path)
                    logger.info(f"Local copy saved: {local_copy_path}")
                return {
                    'success': True,
                    'remote_path': remote_path,
                    'filename': os.path.basename(final_path),
                    'local_path': local_copy_path if keep_local else None
                }
        except Exception as e:
            logger.error(f"TTS processing failed: {e}")
            return {'success': False, 'error': str(e)}
        finally:
            self.cleanup_ssh()

def create_sample_config():
    sample_config = {
        "tts_model": "tts_models/en/ljspeech/tacotron2-DDC",
        "ssh_config": {
            "hostname": "your-server.com",
            "username": "your-username",
            "key_filename": "/path/to/your/private/key",
            "password": None,
            "port": 22
        },
        "remote_paths": {
            "tts_directory": "/var/www/html/tts/",
            "temp_directory": "/tmp/tts/"
        },
        "audio_settings": {
            "sample_rate": 22050,
            "format": "mp3"
        }
    }
    with open('tts_config.json', 'w') as f:
        json.dump(sample_config, f, indent=4)
    print("Sample configuration file created: tts_config.json")
    print("Please edit this file with your server details before using the script.")

def main():
    parser = argparse.ArgumentParser(description='Local TTS Generator with SSH Transfer')
    parser.add_argument('--text', '-t', required=True, help='Text to convert to speech')
    parser.add_argument('--voice', '-v', help='Voice to use (if supported by model)')
    parser.add_argument('--config', '-c', default='tts_config.json', help='Configuration file path')
    parser.add_argument('--keep-local', action='store_true', help='Keep local copy of generated file')
    parser.add_argument('--create-config', action='store_true', help='Create sample configuration file')
    args = parser.parse_args()
    if args.create_config:
        create_sample_config()
        return
    # Initialize TTS generator
    generator = TTSGenerator(args.config)
    # Process TTS request
    result = generator.process_tts_request(
        text=args.text,
        voice=args.voice,
        keep_local=args.keep_local
    )
    if result['success']:
        print(f"TTS generation successful!")
        print(f"Remote file: {result['remote_path']}")
        if result.get('local_path'):
            print(f"Local copy: {result['local_path']}")
    else:
        print(f"TTS generation failed: {result.get('error', 'Unknown error')}")
        sys.exit(1)

if __name__ == "__main__":
    main()
