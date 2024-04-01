# BotOfTheSpecter Twitch Bot

## Overview
BotOfTheSpecter is a powerful Twitch chat bot designed to elevate your streaming experience on Twitch. With a wide array of features ranging from chat interaction and moderation to detailed analytics and user management, it's your all-in-one solution for managing your Twitch channel.

## Features

### Twitch Integration
- **Moderation**: Keep your chat environment healthy with automated moderation tools.
- **Follower, Subscriber, and VIP Insights**: Gain insights into your channel's audience with detailed data on followers, subscribers, and VIPs.
- **Custom Commands**: Personalize your chat experience by creating and managing custom commands. [More Info](#coming-soon)
- **Subscription and Bits Alerts**: Enhance viewer engagement by allowing the bot to respond to subscriptions and bits in chat.

### User Management
- **Secure Authentication**: Safely log in with Twitch OAuth for streamlined user management.
- **Role-based Access**: Assign different access levels for viewers, moderators, and broadcasters.
- **User Profiles**: Access detailed user profiles including Twitch display names and profile images.

### Dashboard
- **User-friendly Interface**: Manage bot settings and view Twitch data effortlessly with our web-based dashboard.
- **Real-time Analytics**: Stay informed with real-time data on chat interactions, subscriptions, and more.

### Logging System
- **Comprehensive Logs**: Review detailed logs covering bot activities, chat messages, Twitch events, and script errors.
- **Easy Log Management**: Access and manage different log types conveniently through the dashboard.

## Coming Soon!

### Custom Commands
- **Personalize Your Chat Experience**: Soon, you'll be able to create and manage custom commands to tailor your chat experience.
- **Enhanced Interaction**: Engage with your viewers in unique ways by setting up custom responses and actions.
- **Flexible Configuration**: Easily manage and update your custom commands through the user-friendly dashboard.

Stay tuned for updates on the release of the Custom Commands feature!

### Version 3.5 - BotOfTheSpecter

- Implemented the `clip` command, allowing users to create clips from the Twitch stream when online. The command generates a clip URL and sends it to the chat. Additionally, it creates a stream marker for the clip.

- Introduced the `marker` command, which enables moderators and the broadcaster to create stream markers with custom descriptions. These markers are used to denote important moments during the stream.

- Implemented the `subscription` command, accessible via the aliases `mysub`. This command allows users to check their subscription status to the Twitch channel. It retrieves subscription information using the Twitch API and provides details such as subscription tier and gifter information if applicable.

[View Full Changelog for Version 3.6](/bot/changelog/3.6.md)

### Version 2.0 - Application
- Added a "Counters" feature to provide real-time metrics and analytics within the application interface.
- Improved Twitch integration: the bot now responds to subscriptions and bits in chat, boosting viewer interaction and engagement.

[View Full Changelog for Version 2.0](/api/app/changelog.2.0.md)
