import os
import json
import shutil
import datetime

class SettingsManager:
    def __init__(self, logger, base_dir="/home/botofthespecter"):
        self.logger = logger
        self.base_dir = base_dir
        self.music_settings_dir = os.path.join(base_dir, "music-settings")
        self.ensure_directories()

    def ensure_directories(self):
        directories = [self.music_settings_dir]
        for directory in directories:
            os.makedirs(directory, exist_ok=True)

    def save_music_settings(self, code, settings):
        settings_file = os.path.join(self.music_settings_dir, f"{code}.json")
        try:
            # If file exists, update existing settings
            current = {}
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    current = json.load(f)
            # Validate and format boolean settings
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
            # Update current settings
            current.update(settings)
            # Save to file
            with open(settings_file, "w") as f:
                json.dump(current, f, indent=2)
            self.logger.info(f"Saved music settings for {code}: {settings}")
        except Exception as e:
            self.logger.error(f"Failed to save music settings for {code}: {e}")

    def load_music_settings(self, code):
        settings_file = os.path.join(self.music_settings_dir, f"{code}.json")
        try:
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    settings = json.load(f)
                    # Ensure boolean types for repeat and shuffle
                    for k in ("repeat", "shuffle"):
                        if k in settings:
                            settings[k] = bool(settings[k])
                    self.logger.debug(f"Loaded music settings for {code}: {settings}")
                    return settings
        except Exception as e:
            self.logger.error(f"Failed to load music settings for {code}: {e}")
        return None

    def delete_music_settings(self, code):
        settings_file = os.path.join(self.music_settings_dir, f"{code}.json")
        try:
            if os.path.exists(settings_file):
                os.remove(settings_file)
                self.logger.info(f"Deleted music settings for {code}")
                return True
        except Exception as e:
            self.logger.error(f"Failed to delete music settings for {code}: {e}")
        return False

    def get_all_music_settings(self):
        settings = {}
        try:
            if os.path.exists(self.music_settings_dir):
                for filename in os.listdir(self.music_settings_dir):
                    if filename.endswith('.json'):
                        code = filename[:-5]  # Remove .json extension
                        settings[code] = self.load_music_settings(code)
        except Exception as e:
            self.logger.error(f"Failed to get all music settings: {e}")
        return settings

    def save_general_setting(self, key, value, filename="general_settings.json"):
        settings_file = os.path.join(self.base_dir, filename)
        try:
            current = {}
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    current = json.load(f)
            current[key] = value
            with open(settings_file, "w") as f:
                json.dump(current, f, indent=2)
            self.logger.info(f"Saved general setting {key}: {value}")
        except Exception as e:
            self.logger.error(f"Failed to save general setting {key}: {e}")

    def load_general_setting(self, key, default=None, filename="general_settings.json"):
        settings_file = os.path.join(self.base_dir, filename)
        try:
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    settings = json.load(f)
                    return settings.get(key, default)
        except Exception as e:
            self.logger.error(f"Failed to load general setting {key}: {e}")
        return default

    def load_all_general_settings(self, filename="general_settings.json"):
        settings_file = os.path.join(self.base_dir, filename)
        try:
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    return json.load(f)
        except Exception as e:
            self.logger.error(f"Failed to load general settings from {filename}: {e}")
        return {}

    def backup_settings(self, backup_dir=None):
        if backup_dir is None:
            backup_dir = os.path.join(self.base_dir, "settings_backups")
        os.makedirs(backup_dir, exist_ok=True)
        try:
            timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
            backup_path = os.path.join(backup_dir, f"settings_backup_{timestamp}")
            # Copy music settings directory
            if os.path.exists(self.music_settings_dir):
                shutil.copytree(self.music_settings_dir, os.path.join(backup_path, "music-settings"))
            # Copy general settings files
            general_files = ["general_settings.json", "websocket_tts_config.json"]
            for filename in general_files:
                source_file = os.path.join(self.base_dir, filename)
                if os.path.exists(source_file):
                    os.makedirs(backup_path, exist_ok=True)
                    shutil.copy2(source_file, backup_path)
            self.logger.info(f"Settings backup created: {backup_path}")
            return backup_path
        except Exception as e:
            self.logger.error(f"Failed to create settings backup: {e}")
            return None

    def restore_settings(self, backup_path):
        try:
            if not os.path.exists(backup_path):
                self.logger.error(f"Backup path does not exist: {backup_path}")
                return False
            # Restore music settings
            backup_music_dir = os.path.join(backup_path, "music-settings")
            if os.path.exists(backup_music_dir):
                if os.path.exists(self.music_settings_dir):
                    shutil.rmtree(self.music_settings_dir)
                shutil.copytree(backup_music_dir, self.music_settings_dir)
            # Restore general settings files
            general_files = ["general_settings.json", "websocket_tts_config.json"]
            for filename in general_files:
                backup_file = os.path.join(backup_path, filename)
                if os.path.exists(backup_file):
                    target_file = os.path.join(self.base_dir, filename)
                    shutil.copy2(backup_file, target_file)
            self.logger.info(f"Settings restored from backup: {backup_path}")
            return True
        except Exception as e:
            self.logger.error(f"Failed to restore settings from backup: {e}")
            return False