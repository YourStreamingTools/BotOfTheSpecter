# BotOfTheSpecter Twitch Bot

## Overview
BotOfTheSpecter is a comprehensive Twitch chat bot designed to enhance the streaming experience on Twitch. It offers a wide range of functionalities, from chat interaction and moderation to in-depth analytics and user management.

## Features

### Twitch Integration
- **Moderation**: Automated moderation tools for maintaining a healthy chat environment.
- **Followers, Subscribers, and VIPs**: Fetch and display detailed data about the channel's followers, subscribers, and VIPs.
- **Custom Commands**: Create and manage custom chat commands.
- **Subscription and Bits Alerts**: The bot now responds to subscriptions and bits in chat, enhancing viewer interaction and engagement.

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

### Version 2.5 - Bot
- **Twitch Integration Update:**
  - Updated Twitch integration for improved functionality, including changes to client initialization and method naming conventions.
   
- **Bot Constructor and Commands:**
  - Introduced a constructor for the bot class to initialize specific parameters, along with new commands such as `roadmap` and `mybits` for enhanced user interaction.
   
- **Token Handling and Error Fixes:**
  - Revised token handling for better security and corrected various errors related to token references and method calls, ensuring seamless functionality with the Twitch API.

View Full Changlog [Version 2.5](/bot/changelog/2.5.md)

### Version 2.0 - Application
- Added a "Counters" feature to the app, providing users with real-time metrics and analytics directly within the interface.
- Enhanced Twitch integration: the bot now responds to subscriptions and bits in chat, improving viewer interaction and engagement.

View Full Changlog [Version 2.0](/api/app/changelog.2.0.md)