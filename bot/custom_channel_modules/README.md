# Custom Channel Modules

This folder holds custom, channel-specific modules that are written exclusively for a single Twitch channel. Each module contains logic, commands, or behaviour that is unique to that channel and is not part of the core bot.

## Public vs. Hidden Modules

Not all modules in this folder are visible in the public repository. By default, custom channel modules are **hidden from commits** via `.gitignore` to protect the privacy of channel-specific ideas and logic.

However, a channel owner may choose to make their module public. For example:

- **`botofthespecter.py`** — The module for the BotOfTheSpecter channel itself is public, as there is no need to hide it.
- **`<channel_name>.py`** — Channel-specific modules for other streamers are kept private and excluded from public commits by default.

If you are a channel owner and would like your custom module included in the public repository, reach out to the developer.

## Structure

Each module is a self-contained Python file named after the channel it serves.

```text
custom_channel_modules/
├── botofthespecter.py   # Public — BotOfTheSpecter's own channel module
├── <your_channel>.py    # Hidden by default — channel-specific private module
└── ...
```

## Contributing a Module

If you want your custom module shared with the wider BotOfTheSpecter community, reach out to the developer. The developer manages what is included in the public repository and will ensure no private credentials, API keys, or channel-specific secrets are exposed before publishing.
