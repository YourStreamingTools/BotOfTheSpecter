# BotOfTheSpecter Twitch Bot

## Overview
BotOfTheSpecter is a comprehensive Twitch chat bot designed to enhance the streaming experience on Twitch. It offers a wide range of functionalities, from chat interaction and moderation to in-depth analytics and user management.

## Features

### Twitch Integration
- **Moderation**: Automated moderation tools for maintaining a healthy chat environment.
- **Followers, Subscribers, and VIPs**: Fetch and display detailed data about the channel's followers, subscribers, and VIPs.
- **Custom Commands**: Create and manage custom chat commands.

### User Management
- **Authentication**: Secure login system with Twitch OAuth.
- **Role-based Access**: Different access levels for viewers, moderators, and broadcasters.
- **User Profiles**: Detailed user profiles including Twitch display names and profile images.

### Dashboard
- **Web-based Interface**: A user-friendly dashboard for managing bot settings and viewing Twitch data.
- **Real-time Analytics**: Display real-time data about chat interactions, subscriptions, and more.

### Logging System
- **Extensive Logging**: Detailed logs for bot activities, chat messages, Twitch events, and script errors.
- **Log Management**: Easy access and review of different log types via the dashboard.

## Changelog

### Version 2.0
- Added modules for handling date and time operations (`datetime`) and making HTTP requests (`requests`).
- Integrated a new function `fetch_counters_from_db` from the `server_communication` module for database operations.
- Expanded import statement in `app.py` to include `fetch_counters_from_db`.
- Added modules `os` and `sqlite3` to `server_communication.py`.
- Updated `updates.py` to set the VERSION variable as "2.0".
