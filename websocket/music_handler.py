def command_handler(command_name):
    def decorator(func):
        func._command_name = command_name
        return func
    return decorator

class MusicHandler:
    def __init__(self, sio, logger, get_clients, save_settings, load_settings):
        self.sio = sio
        self.logger = logger
        self.get_clients = get_clients
        self.save_music_settings = save_settings
        self.load_music_settings = load_settings
        self._command_handlers = {}
        for attr_name in dir(self):
            attr = getattr(self, attr_name)
            if callable(attr) and hasattr(attr, "_command_name"):
                self._command_handlers[attr._command_name] = attr

    async def music_command(self, sid, data):
        self.logger.info(f"MUSIC_COMMAND from SID [{sid}]: {data}")
        command = data.get("command")
        code = next(
            (c for c, clients in self.get_clients().items()
                for client in clients if client['sid'] == sid),
            None
        )
        if not code:
            self.logger.warning(f"MUSIC_COMMAND from unknown SID [{sid}]")
            return
        handler = self._command_handlers.get(command)
        if handler:
            await handler(sid, code, data)
        else:
            await self._broadcast_command(code, sid, data)

    async def _broadcast_command(self, code, sid, data):
        command = data.get("command")
        broadcast_commands = [
            "play", "pause", "next", "prev", "repeat", "shuffle", "volume", "play_index", "NOW_PLAYING"
        ]
        if command in broadcast_commands and command != "volume":
            for client in self.get_clients().get(code, []):
                if client['sid'] != sid:
                    await self.sio.emit("MUSIC_COMMAND", data, to=client['sid'])
            self.logger.info(f"Broadcasted MUSIC_COMMAND '{command}' for code {code}")

    @command_handler("repeat")
    async def handle_repeat(self, sid, code, data):
        value = data.get("value")
        if value is not None:
            self.save_music_settings(code, {"repeat": bool(value)})
            self.logger.info(f"Saved repeat {value} for code {code}")
            await self._emit_settings(code)

    @command_handler("shuffle")
    async def handle_shuffle(self, sid, code, data):
        value = data.get("value")
        if value is not None:
            self.save_music_settings(code, {"shuffle": bool(value)})
            self.logger.info(f"Saved shuffle {value} for code {code}")
            await self._emit_settings(code)

    @command_handler("volume")
    async def handle_volume(self, sid, code, data):
        value = data.get("value")
        if value is not None:
            self.save_music_settings(code, {"volume": int(value)})
            self.logger.info(f"Saved volume {value} for code {code}")
            await self._emit_settings(code)

    @command_handler("next")
    async def handle_next(self, sid, code, data):
        for client in self.get_clients().get(code, []):
            if "overlay - dmca" in client['name'].lower():
                await self.sio.emit("MUSIC_COMMAND", {"command": "next"}, to=client['sid'])
                self.logger.info(f"Emitted next command to {client['name']} for code {code}")
                return

    @command_handler("MUSIC_SETTINGS")
    async def handle_settings(self, sid, code, data):
        to_save = {key: data[key] for key in ("volume", "repeat", "shuffle") if key in data}
        if to_save:
            self.save_music_settings(code, to_save)
            self.logger.info(f"Saved MUSIC_SETTINGS {to_save} for code {code}")
        settings = self.load_music_settings(code)
        if settings:
            for client in self.get_clients().get(code, []):
                await self.sio.emit("MUSIC_SETTINGS", settings, to=client['sid'])
            self.logger.info(f"Emitted MUSIC_SETTINGS live update for code {code}")
        else:
            for client in self.get_clients().get(code, []):
                if client['sid'] != sid:
                    await self.sio.emit("MUSIC_SETTINGS_REQUEST", {}, to=client['sid'])
            self.logger.info(f"Requested MUSIC_SETTINGS from clients for code {code}")

    @command_handler("WHAT_IS_PLAYING")
    async def handle_what_is_playing(self, sid, code, data):
        found = False
        for client in self.get_clients().get(code, []):
            if "overlay - dmca" in client['name'].lower():
                await self.sio.emit("WHAT_IS_PLAYING", {}, to=client['sid'])
                self.logger.info(f"Requested WHAT_IS_PLAYING from {client['name']} for code {code}")
                found = True
        if not found:
            self.logger.warning(f"No overlay client found to answer WHAT_IS_PLAYING for code {code}")
            await self.sio.emit("NOW_PLAYING", {"error": "No overlay client found for this code."}, to=sid)

    @command_handler("NOW_PLAYING")
    async def handle_NOWPLAYING(self, sid, code, data):
        for client in self.get_clients().get(code, []):
            if "dashboard - music controller" in client['name'].lower():
                await self.sio.emit("NOW_PLAYING", data, to=client['sid'])
                self.logger.info(f"Emitted NOW_PLAYING to {client['name']} for code {code}")
                return

    async def _emit_settings(self, code):
        settings = self.load_music_settings(code)
        if settings:
            for client in self.get_clients().get(code, []):
                await self.sio.emit("MUSIC_SETTINGS", settings, to=client['sid'])
