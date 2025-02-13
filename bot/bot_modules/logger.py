import os
import logging
from logging.handlers import RotatingFileHandler

# Logs
webroot = "/var/www/"
logs_directory = os.path.join(webroot, "logs")
log_types = ["bot", "chat", "twitch", "api", "chat_history", "event_log", "websocket"]

# Ensure directories exist
for log_type in log_types:
    directory_path = os.path.join(logs_directory, log_type)
    os.makedirs(directory_path, mode=0o755, exist_ok=True)

# Create a function to setup individual loggers for clarity
def setup_logger(name, log_file, level=logging.INFO):
    logger = logging.getLogger(name)
    logger.setLevel(level)
    # Clear any existing handlers to prevent duplicates
    if logger.hasHandlers():
        logger.handlers.clear()
    # Setup rotating file handler
    handler = logging.handlers.RotatingFileHandler(
        log_file,
        maxBytes=10485760, # 10MB
        backupCount=5,
        encoding='utf-8'
    )
    formatter = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    return logger

def initialize_loggers(channel_name):
    loggers = {}
    for log_type in log_types:
        log_file = os.path.join(logs_directory, log_type, f"{channel_name}.txt")
        loggers[log_type] = setup_logger(f"bot.{log_type}", log_file)
    # Access individual loggers
    global bot_logger, chat_logger, twitch_logger, api_logger, chat_history_logger, event_logger, websocket_logger
    bot_logger = loggers['bot']
    chat_logger = loggers['chat']
    twitch_logger = loggers['twitch']
    api_logger = loggers['api']
    chat_history_logger = loggers['chat_history']
    event_logger = loggers['event_log']
    websocket_logger = loggers['websocket']
    # Log startup messages
    startup_msg = f"Logger initialized for channel: {channel_name}"
    for logger in loggers.values():
        logger.info(startup_msg)