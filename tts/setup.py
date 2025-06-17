import os
import subprocess
import venv
import json
from pathlib import Path

def run_command(command, check=True):
    print(f"Running: {command}")
    try:
        result = subprocess.run(command, shell=True, check=check, capture_output=True, text=True)
        if result.stdout:
            print(result.stdout)
        return result
    except subprocess.CalledProcessError as e:
        print(f"Error running command: {e}")
        if e.stderr:
            print(f"Error output: {e.stderr}")
        if check:
            raise
        return e

def create_virtual_environment(env_name="tts_env"):
    env_path = Path(env_name)
    if env_path.exists():
        print(f"Virtual environment '{env_name}' already exists.")
        return env_path
    print(f"Creating virtual environment: {env_name}")
    venv.create(env_path, with_pip=True)
    return env_path

def install_requirements(env_path):
    if os.name == 'nt':  # Windows
        pip_path = env_path / "Scripts" / "pip.exe"
        python_path = env_path / "Scripts" / "python.exe"
    else:  # Unix/Linux/Mac
        pip_path = env_path / "bin" / "pip"
        python_path = env_path / "bin" / "python"
    print("Upgrading pip...")
    run_command(f'"{python_path}" -m pip install --upgrade pip')
    print("Installing TTS and dependencies...")
    requirements = [
        "TTS[all]",
        "paramiko>=2.9.0",
        "scp>=0.14.0",
        "pydub>=0.25.0",
        "numpy>=1.21.0"
    ]
    for req in requirements:
        print(f"Installing {req}...")
        run_command(f'"{pip_path}" install "{req}"')

def create_run_script(env_path):
    if os.name == 'nt':  # Windows
        python_path = env_path / "Scripts" / "python.exe"
        script_content = f"""@echo off
"{python_path}" local_tts_generator.py %*
"""
        script_name = "run_tts.bat"
    else:  # Unix/Linux/Mac
        python_path = env_path / "bin" / "python"
        script_content = f"""#!/bin/bash
"{python_path}" local_tts_generator.py "$@"
"""
        script_name = "run_tts.sh"
    with open(script_name, 'w') as f:
        f.write(script_content)
    if os.name != 'nt':
        os.chmod(script_name, 0o755)
    print(f"Created run script: {script_name}")

def check_system_dependencies():
    print("Checking system dependencies...")
    # Check for FFmpeg
    try:
        result = subprocess.run(['ffmpeg', '-version'], 
                              capture_output=True, text=True)
        if result.returncode == 0:
            print("✓ FFmpeg found")
        else:
            print("✗ FFmpeg not found")
    except FileNotFoundError:
        print("✗ FFmpeg not found")
        print("  Install FFmpeg for MP3 conversion support:")
        if os.name == 'nt':
            print("  - Download from https://ffmpeg.org/download.html")
            print("  - Or use chocolatey: choco install ffmpeg")
        else:
            print("  - Ubuntu/Debian: sudo apt install ffmpeg")
            print("  - macOS: brew install ffmpeg")

def create_sample_config():
    config_file = "tts_config.json"
    if not os.path.exists(config_file):
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
        with open(config_file, 'w') as f:
            json.dump(sample_config, f, indent=4)
        print(f"Created sample configuration: {config_file}")
        print("Please edit this file with your server details.")
    else:
        print(f"Configuration file already exists: {config_file}")

def main():
    print("Setting up Local TTS Generator Environment")
    print("=" * 50)
    # Check system dependencies
    check_system_dependencies()
    print()
    # Create virtual environment
    env_path = create_virtual_environment()
    print()
    # Install requirements
    install_requirements(env_path)
    print()
    # Create run script
    create_run_script(env_path)
    print()
    # Create sample config
    create_sample_config()
    print()
    print("Setup complete!")
    print("\nNext steps:")
    print("1. Edit tts_config.json with your server details")
    print("2. Test the setup:")
    if os.name == 'nt':
        print('   run_tts.bat --text "Hello, this is a test" --keep-local')
    else:
        print('   ./run_tts.sh --text "Hello, this is a test" --keep-local')
    print("\nFor help with usage:")
    if os.name == 'nt':
        print("   run_tts.bat --help")
    else:
        print("   ./run_tts.sh --help")

if __name__ == "__main__":
    main()
