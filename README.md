# BotOfTheSpecter Twitch Bot

## Overview
BotOfTheSpecter is a powerful Twitch chat bot designed to elevate your streaming experience on Twitch. With a wide array of features ranging from chat interaction and moderation to detailed analytics and user management, it's your all-in-one solution for managing your Twitch channel.

## Features

### Twitch Integration
- **Moderation**: Keep your chat environment healthy with automated moderation tools.
- **Follower, Subscriber, and VIP Insights**: Gain insights into your channel's audience with detailed data on followers, subscribers, and VIPs.
- **Custom Commands**: Personalize your chat experience by creating and managing custom commands.
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

- **Enhanced Chat Protection**: Elevate your chat experience with Specter's Dashboard. Soon, you'll have the power to activate advanced protection measures such as ``URL Blocking``, ensuring unwanted links stay out. With the `!permit` command, grant temporary exemptions for approved links. Additionally, ``Caps Lock Usage`` Protection will maintain a calm chat environment by preventing excessive capitalization, while still allowing designated members to bypass this restriction and finally a ``Profanity Filter`` keep those unwanted bad words out of chat, Specter will delete the message and provide back a clean message to read for the user.
- **User Groups**: Introducing a convenient feature to organize and manage your Twitch community effortlessly. Create user groups and assign Twitch usernames, facilitating swift access management.

Stay tuned for updates on the release of these exciting features!

## Versions

### Version 4.4 - BotOfTheSpecter

- Added new commands: 'roulette', 'kill', 'slots'
- Enhanced message counting and user level identification
- Dynamic polling updates for ongoing and concluded polls
- Welcome messages for new and returning users based on roles
- Implemented token refresh mechanism
- Integrated Twitch EventSub for various events
- Added comprehensive logging for bot, chat, Twitch, API, and chat history
- Fixed broadcaster user level identification and 'Session is closed' error in `known_users` function
- Improved raid event processing, custom command handling, and URL blocking
- Updated ad break messaging duration format and token refresh log messages
- Optimized command handling and event message processing

[View Full Changelog for Version 4.4](/bot/changelog/4.4.md)

## Contributing Guidelines

We're thrilled that you're interested in contributing to BotOfTheSpecter! Whether you're looking to report a bug, suggest a feature, or contribute code, your help is greatly appreciated. Please follow these guidelines to ensure a smooth contribution process.

### Reporting Bugs
If you've found a bug in the BotOfTheSpecter, please check the [Issues](https://github.com/YourStreamingTools/BotOfTheSpecter/issues) section first to see if it has already been reported. If not, feel free to open a new issue, providing as much detail as possible, including:
- A clear and descriptive title
- Steps to reproduce the bug
- Expected behavior vs. actual behavior
- Any relevant logs or error messages
- Bot version

### Suggesting Enhancements
We love to hear your ideas for making BotOfTheSpecter better! For feature requests or suggestions, please open an issue with the tag "enhancement". Provide as much detail as possible about the feature you're envisioning, including how it might work and why it would be a valuable addition to the bot.

### Pull Requests
Before making any changes, please first discuss the change you wish to make via an issue. This helps prevent duplication of effort and ensures that your contributions align with the project goals and will be considered for merging.

Here are some general guidelines for pull requests:
- Fork the repository and create your branch from `main`.
- If you've added code, write clear, commented, and comprehensible code.
- Ensure any new code or changes do not break existing functionality.
- Update the README.md with details of changes, including new environment variables, exposed ports, useful file locations, and any other parameters.
- Open a pull request with a clear title and description. Link the issue your pull request addresses.

### Code of Conduct
This project and everyone participating in it is governed by the [BotOfTheSpecter Code of Conduct](CODEOFCONDUCT.md). By contributing, you are expected to uphold this code. Please report unacceptable behavior to code@botofthespecter.com.

### Questions?
If you have any questions or need further clarification about contributing, please feel free to reach out to us. You can open an issue with the tag "question" or contact questions@botofthespecter.com.

We look forward to your contributions. Thank you for supporting BotOfTheSpecter!
