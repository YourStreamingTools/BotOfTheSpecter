def command_handler(command_name):
    def decorator(func):
        func._command_name = command_name
        return func
    return decorator

class MediaHandler:
    def __init__(self, sio, logger, get_clients, execute_query, get_user_info):
        self.sio = sio
        self.logger = logger
        self.get_clients = get_clients
        self.execute_query = execute_query          # async (sql, params, database_name) -> list|None
        self.get_user_info = get_user_info          # async (api_key) -> {username,...}|None
        self._command_handlers = {}
        for attr_name in dir(self):
            attr = getattr(self, attr_name)
            if callable(attr) and hasattr(attr, "_command_name"):
                self._command_handlers[attr._command_name] = attr

    def _code_for_sid(self, sid):
        return next((c for c, clients in self.get_clients().items()
                     for client in clients if client['sid'] == sid), None)

    async def _username_for_code(self, code):
        info = await self.get_user_info(code)
        return info['username'] if info else None

    async def media_command(self, sid, data):
        command = (data or {}).get("command")
        code = self._code_for_sid(sid)
        if not code:
            self.logger.warning(f"MEDIA_COMMAND from unknown SID [{sid}]")
            return
        handler = self._command_handlers.get(command)
        if handler:
            await handler(sid, code, data)
        else:
            self.logger.warning(f"Unknown MEDIA_COMMAND '{command}' for code {code}")

    async def _emit_to_names(self, code, name_substrings, event, payload):
        for client in self.get_clients().get(code, []):
            if any(s in client['name'].lower() for s in name_substrings):
                await self.sio.emit(event, payload, to=client['sid'])

    async def _emit_all(self, code, event, payload):
        for client in self.get_clients().get(code, []):
            await self.sio.emit(event, payload, to=client['sid'])

    async def _now_playing(self, username):
        rows = await self.execute_query("SELECT id, video_id, title, requested_by FROM media_queue WHERE status='playing' ORDER BY id LIMIT 1", (), username)
        return rows[0] if rows else None

    async def _queue(self, username):
        return await self.execute_query("SELECT id, video_id, title, requested_by FROM media_queue WHERE status='queued' ORDER BY id", (), username) or []

    async def _broadcast_state(self, code, username):
        now = await self._now_playing(username)
        queue = await self._queue(username)
        await self._emit_all(code, "MEDIA_QUEUE_UPDATE", {"now_playing": now, "queue": queue})

    async def _promote_next(self, code, username):
        """If nothing playing, move the oldest queued row to playing and tell the overlay."""
        if await self._now_playing(username):
            return
        nxt = await self.execute_query("SELECT id, video_id, title, requested_by FROM media_queue WHERE status='queued' ORDER BY id LIMIT 1", (), username)
        if not nxt:
            await self._emit_to_names(code, ["media player"], "MEDIA_STOP", {})
            return
        row = nxt[0]
        await self.execute_query("UPDATE media_queue SET status='playing' WHERE id=%s", (row['id'],), username)
        await self._emit_to_names(code, ["media player"], "MEDIA_PLAY",
                                  {"video_id": row['video_id'], "title": row['title'], "requested_by": row['requested_by']})

    @command_handler("enqueue")
    async def handle_enqueue(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        await self._promote_next(code, username)
        await self._broadcast_state(code, username)

    @command_handler("ended")
    async def handle_ended(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        ended_id = (data or {}).get("video_id")
        now = await self._now_playing(username)
        if now and ended_id and now['video_id'] != ended_id:
            return  # stale ended event; ignore (anti-double-skip)
        if now:
            await self.execute_query("UPDATE media_queue SET status='played' WHERE id=%s", (now['id'],), username)
        await self._promote_next(code, username)
        await self._broadcast_state(code, username)

    @command_handler("skip")
    async def handle_skip(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        now = await self._now_playing(username)
        if now:
            await self.execute_query("UPDATE media_queue SET status='played' WHERE id=%s", (now['id'],), username)
        await self._promote_next(code, username)
        await self._broadcast_state(code, username)

    @command_handler("clear")
    async def handle_clear(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        await self.execute_query("DELETE FROM media_queue WHERE status='queued'", (), username)
        await self._broadcast_state(code, username)

    @command_handler("remove")
    async def handle_remove(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        row_id = (data or {}).get("id")
        if row_id is not None:
            await self.execute_query("DELETE FROM media_queue WHERE id=%s AND status='queued'", (row_id,), username)
        await self._broadcast_state(code, username)

    @command_handler("volume")
    async def handle_volume(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        value = (data or {}).get("value")
        if value is None:
            return
        await self.execute_query("UPDATE media_request_settings SET volume=%s WHERE id=1", (int(value),), username)
        await self._emit_to_names(code, ["media player"], "MEDIA_VOLUME", {"value": int(value)})

    @command_handler("request_state")
    async def handle_request_state(self, sid, code, data):
        username = await self._username_for_code(code)
        if not username:
            return
        now = await self._now_playing(username)
        if now:
            await self.sio.emit("MEDIA_PLAY", {"video_id": now['video_id'], "title": now['title'], "requested_by": now['requested_by']}, to=sid)
        settings = await self.execute_query("SELECT volume FROM media_request_settings WHERE id=1", (), username)
        if settings:
            await self.sio.emit("MEDIA_VOLUME", {"value": settings[0]['volume']}, to=sid)
        queue = await self._queue(username)
        await self.sio.emit("MEDIA_QUEUE_UPDATE", {"now_playing": now, "queue": queue}, to=sid)
