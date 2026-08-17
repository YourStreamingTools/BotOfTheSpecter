# Custom Channel Modules

This folder holds custom, channel-specific modules that are written exclusively for a single Twitch channel. Each module contains logic, commands, or behaviour that is unique to that channel and is not part of the core bot.

## Opt-in loading (required)

Modules are **not** auto-imported for every bot process.

1. Operator deploys `{channel_login}.py` under this directory on the bot host.
2. The bot host reports whether that file is present.
3. Channel owner enables **Custom Module** on the dashboard Bot page.
4. On start, the module loads only if the flag is set **and** the file exists.
5. **beta** / **v6** import **only** that channel’s package; other channels’ modules are never imported.

Stable does not load custom modules. Changing the toggle requires a **bot restart**.

Default is **off** after deploy: existing module channels must enable once and restart.

## Public vs. Hidden Modules

Not all modules in this folder are visible in the public repository. By default, custom channel modules are **hidden from commits** via `.gitignore` to protect the privacy of channel-specific ideas and logic.

However, a channel owner may choose to make their module public. For example:

- **`botofthespecter.py`** - Platform helpers for the BotOfTheSpecter home channel (public). Not the same as the opt-in Module class system unless it exports `claims_channel` classes.
- **`<channel_name>.py`** - Channel-specific modules for other streamers are kept private and excluded from public commits by default.

If you are a channel owner and would like your custom module included in the public repository, reach out to the developer.

## Structure

Each module is a self-contained Python file named after the Twitch **login** (lowercase) it serves.

```text
custom_channel_modules/
├── botofthespecter.py   # Public - home-channel helpers
├── <your_channel>.py    # Hidden by default - channel-specific private module
└── ...
```

Module classes used by the opt-in loader must implement:

```python
@classmethod
def claims_channel(cls, channel_name: str) -> bool:
    return str(channel_name).lower() == "your_channel"
```

The bot discovers such classes on the loaded package and instantiates those that claim the current channel.

## Contributing a Module

If you want your custom module shared with the wider BotOfTheSpecter community, reach out to the developer. The developer manages what is included in the public repository and will ensure no private credentials, API keys, or channel-specific secrets are exposed before publishing.
