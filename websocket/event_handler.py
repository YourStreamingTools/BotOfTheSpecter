class EventHandler:
    def __init__(self, sio, logger, get_clients):
        self.sio = sio
        self.logger = logger
        self.get_clients = get_clients

    async def handle_twitch_follow(self, sid, data):
        self.logger.info(f"Twitch follow event from SID [{sid}]: {data}")
        # Broadcast the follow event to all clients
        await self.sio.emit("TWITCH_FOLLOW", data)

    async def handle_twitch_cheer(self, sid, data):
        self.logger.info(f"Twitch cheer event from SID [{sid}]: {data}")
        # Broadcast the cheer event to all clients
        await self.sio.emit("TWITCH_CHEER", data)

    async def handle_twitch_raid(self, sid, data):
        self.logger.info(f"Twitch raid event from SID [{sid}]: {data}")
        # Broadcast the raid event to all clients
        await self.sio.emit("TWITCH_RAID", data)

    async def handle_twitch_sub(self, sid, data):
        self.logger.info(f"Twitch sub event from SID [{sid}]: {data}")
        # Broadcast the sub event to all clients
        await self.sio.emit("TWITCH_SUB", data)

    async def handle_twitch_channelpoints(self, sid, data):
        self.logger.info(f"Twitch Channel Points event from SID [{sid}]: {data}")
        # Broadcast the Channel Points event to all clients
        await self.sio.emit("TWITCH_CHANNELPOINTS", data)

    async def handle_stream_online(self, sid, data):
        self.logger.info(f"Stream online event from SID [{sid}]: {data}")
        # Broadcast the stream online event to all clients
        await self.sio.emit("STREAM_ONLINE", data)

    async def handle_stream_offline(self, sid, data):
        self.logger.info(f"Stream offline event from SID [{sid}]: {data}")
        # Broadcast the stream offline event to all clients
        await self.sio.emit("STREAM_OFFLINE", data)

    async def handle_weather(self, sid, data):
        self.logger.info(f"Weather event from SID [{sid}]: {data}")
        weather_data = data.get("weather_data")
        if weather_data:
            # Broadcast weather data to all clients
            await self.sio.emit("WEATHER", {"weather_data": weather_data})

    async def handle_weather_data(self, sid, data):
        self.logger.info(f"Weather data event from SID [{sid}]: {data}")
        # Process and broadcast weather data
        await self.sio.emit("WEATHER_DATA", data)

    async def handle_discord_join(self, sid, data):
        self.logger.info(f"Discord join event from SID [{sid}]: {data}")
        # Broadcast Discord join event to all clients
        await self.sio.emit("DISCORD_JOIN", data)

    async def handle_sound_alert(self, sid, data):
        self.logger.info(f"Sound alert event from SID [{sid}]: {data}")
        # Broadcast sound alert to all clients
        await self.sio.emit("SOUND_ALERT", data)

    async def handle_video_alert(self, sid, data):
        self.logger.info(f"Video alert event from SID [{sid}]: {data}")
        # Broadcast video alert to all clients
        await self.sio.emit("VIDEO_ALERT", data)

    async def handle_walkon(self, sid, data):
        self.logger.info(f"Walkon event from SID [{sid}]: {data}")
        channel = data.get('channel')
        user = data.get('user')
        ext = data.get('ext', '.mp3')
        if not channel or not user:
            self.logger.error('Missing channel or user information for WALKON event')
            return
        # Validate and normalize file extension
        if ext and not ext.startswith('.'):
            ext = '.' + ext
        # Validate supported file types
        supported_extensions = ['.mp3', '.mp4', '.wav', '.webm']
        if ext not in supported_extensions:
            self.logger.warning(f"Unsupported walkon file extension '{ext}' for user {user}. Supported: {supported_extensions}")
            ext = '.mp3'  # Default fallback
        # Determine media type for frontend
        audio_extensions = ['.mp3', '.wav']
        video_extensions = ['.mp4', '.webm']
        media_type = 'audio' if ext in audio_extensions else 'video' if ext in video_extensions else None
        walkon_data = {
            'channel': channel,
            'user': user,
            'ext': ext,
            'media_type': media_type,
            'file_url': f"https://walkons.botofthespecter.com/{channel}/{user}{ext}"
        }
        self.logger.info(f"Broadcasting WALKON event for {user} with {media_type} file ({ext})")
        # Broadcast the walkon event to all clients
        await self.sio.emit("WALKON", walkon_data)

    async def handle_deaths(self, sid, data):
        self.logger.info(f"Death event from SID [{sid}]: {data}")
        death_text = data.get('death-text')
        game = data.get('game')
        if not death_text or not game:
            self.logger.error('Missing death-text or game information for DEATHS event')
            return
        death_data = {'death-text': death_text,'game': game}
        self.logger.info(f"Broadcasting DEATHS event with data: {death_data}")
        # Broadcast the death event to all clients
        await self.sio.emit("DEATHS", death_data)

    async def handle_obs_event(self, sid, data):
        self.logger.info(f"SEND_OBS_EVENT received from SID [{sid}]: {data}")
        # Process the data here (e.g., extract event-name, scene-name, etc.)
        # and decide how to handle different OBS events at a later time.
        # For now, just broadcast to all clients
        await self.sio.emit("OBS_EVENT", data)

    async def handle_generic_notify(self, sid, data):
        self.logger.info(f"Notify event from SID [{sid}]: {data}")
        event = data.get('event')
        if not event:
            self.logger.error('Missing event information for NOTIFY event')
            return
        # Broadcast the event to all clients
        await self.sio.emit(event, data, sid)

    async def broadcast_to_code_clients(self, code, event, data):
        count = 0
        if code in self.get_clients():
            for client in self.get_clients()[code]:
                sid = client['sid']
                await self.sio.emit(event, data, to=sid)
                self.logger.info(f"Emitted {event} event to client {sid}")
                count += 1
        self.logger.info(f"Broadcasted {event} event to {count} clients")
        return count

    async def broadcast_to_all_clients(self, event, data):
        count = 0
        for code, clients in self.get_clients().items():
            for client in clients:
                sid = client['sid']
                await self.sio.emit(event, data, to=sid)
                count += 1
        self.logger.info(f"Broadcasted {event} event to {count} total clients")
        return count