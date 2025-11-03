class ObsHandler:
    def __init__(self, logger, get_clients, broadcast_with_globals=None, get_code_by_sid=None):
        self.logger = logger
        self.get_clients = get_clients
        self.broadcast_with_globals = broadcast_with_globals
        self.get_code_by_sid = get_code_by_sid
        self.obs_state = {}  # Track current OBS state per channel code

    # Scene Events
    async def handle_scene_change(self, sid, data):
        self.logger.info(f"OBS Scene Change from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        scene_name = data.get("scene_name")
        if scene_name:
            # Store current scene in state
            if code not in self.obs_state:
                self.obs_state[code] = {}
            self.obs_state[code]["current_scene"] = scene_name
            self.logger.info(f"Updated current scene for {code}: {scene_name}")
        # Broadcast scene change event
        payload = {
            "event_type": "scene_change",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SCENE_CHANGE", payload, code)

    async def handle_scene_created(self, sid, data):
        self.logger.info(f"OBS Scene Created from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "scene_created",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SCENE_CREATED", payload, code)

    async def handle_scene_removed(self, sid, data):
        self.logger.info(f"OBS Scene Removed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "scene_removed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SCENE_REMOVED", payload, code)

    # Source Events
    async def handle_source_created(self, sid, data):
        self.logger.info(f"OBS Source Created from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_created",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_CREATED", payload, code)

    async def handle_source_removed(self, sid, data):
        self.logger.info(f"OBS Source Removed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_removed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_REMOVED", payload, code)

    async def handle_source_visibility_changed(self, sid, data):
        self.logger.info(f"OBS Source Visibility Changed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_visibility_changed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_VISIBILITY", payload, code)

    async def handle_source_muted(self, sid, data):
        self.logger.info(f"OBS Source Muted from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_muted",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_MUTED", payload, code)

    async def handle_source_unmuted(self, sid, data):
        self.logger.info(f"OBS Source Unmuted from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_unmuted",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_UNMUTED", payload, code)

    # Recording Events
    async def handle_record_state_changed(self, sid, data):
        self.logger.info(f"OBS Record State Changed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        record_state = data.get("output_active")
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_recording"] = record_state
        payload = {
            "event_type": "record_state_changed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_RECORD_STATE_CHANGED", payload, code)

    async def handle_recording_started(self, sid, data):
        self.logger.info(f"OBS Recording Started from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_recording"] = True
        payload = {
            "event_type": "recording_started",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_RECORDING_STARTED", payload, code)

    async def handle_recording_stopped(self, sid, data):
        self.logger.info(f"OBS Recording Stopped from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_recording"] = False
        payload = {
            "event_type": "recording_stopped",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_RECORDING_STOPPED", payload, code)

    # Streaming Events
    async def handle_stream_state_changed(self, sid, data):
        self.logger.info(f"OBS Stream State Changed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        stream_state = data.get("output_active")
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_streaming"] = stream_state
        payload = {
            "event_type": "stream_state_changed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_STREAM_STATE_CHANGED", payload, code)

    async def handle_streaming_started(self, sid, data):
        self.logger.info(f"OBS Streaming Started from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_streaming"] = True
        payload = {
            "event_type": "streaming_started",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_STREAMING_STARTED", payload, code)

    async def handle_streaming_stopped(self, sid, data):
        self.logger.info(f"OBS Streaming Stopped from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_streaming"] = False
        payload = {
            "event_type": "streaming_stopped",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_STREAMING_STOPPED", payload, code)

    # Filter Events
    async def handle_source_filter_created(self, sid, data):
        self.logger.info(f"OBS Source Filter Created from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_filter_created",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_FILTER_CREATED", payload, code)

    async def handle_source_filter_removed(self, sid, data):
        self.logger.info(f"OBS Source Filter Removed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_filter_removed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_FILTER_REMOVED", payload, code)

    async def handle_source_filter_enabled_state_changed(self, sid, data):
        self.logger.info(f"OBS Source Filter Enabled State Changed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "source_filter_enabled_state_changed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_SOURCE_FILTER_ENABLED_STATE", payload, code)

    # Virtual Camera Events
    async def handle_virtualcam_state_changed(self, sid, data):
        self.logger.info(f"OBS Virtual Camera State Changed from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        vcam_state = data.get("output_active")
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code]["is_vcam_active"] = vcam_state
        payload = {
            "event_type": "virtualcam_state_changed",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_VIRTUALCAM_STATE_CHANGED", payload, code)

    # Transition Events
    async def handle_transition_began(self, sid, data):
        self.logger.info(f"OBS Transition Began from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "transition_began",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_TRANSITION_BEGAN", payload, code)

    async def handle_transition_ended(self, sid, data):
        self.logger.info(f"OBS Transition Ended from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        payload = {
            "event_type": "transition_ended",
            **(data or {}),
            "channel_code": code or "unknown"
        }
        await self.broadcast_with_globals("OBS_TRANSITION_ENDED", payload, code)

    # Generic OBS Event Handler
    async def handle_obs_event(self, sid, data):
        self.logger.info(f"OBS Event received from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        event_type = data.get("event_type") or data.get("type")
        # Map event types to handlers
        event_handlers = {
            # Scene events
            "scene_change": self.handle_scene_change,
            "SceneChanged": self.handle_scene_change,
            "scene_created": self.handle_scene_created,
            "SceneCreated": self.handle_scene_created,
            "scene_removed": self.handle_scene_removed,
            "SceneRemoved": self.handle_scene_removed,
            # Source events
            "source_created": self.handle_source_created,
            "SourceCreated": self.handle_source_created,
            "source_removed": self.handle_source_removed,
            "SourceRemoved": self.handle_source_removed,
            "source_visibility_changed": self.handle_source_visibility_changed,
            "SourceVisibilityChanged": self.handle_source_visibility_changed,
            "source_muted": self.handle_source_muted,
            "SourceMuteStateChanged": self.handle_source_muted,
            "source_unmuted": self.handle_source_unmuted,
            # Recording events
            "record_state_changed": self.handle_record_state_changed,
            "RecordStateChanged": self.handle_record_state_changed,
            "recording_started": self.handle_recording_started,
            "RecordingStarted": self.handle_recording_started,
            "recording_stopped": self.handle_recording_stopped,
            "RecordingStopped": self.handle_recording_stopped,
            # Streaming events
            "stream_state_changed": self.handle_stream_state_changed,
            "StreamStateChanged": self.handle_stream_state_changed,
            "streaming_started": self.handle_streaming_started,
            "StreamStarted": self.handle_streaming_started,
            "streaming_stopped": self.handle_streaming_stopped,
            "StreamStopped": self.handle_streaming_stopped,
            # Filter events
            "source_filter_created": self.handle_source_filter_created,
            "SourceFilterCreated": self.handle_source_filter_created,
            "source_filter_removed": self.handle_source_filter_removed,
            "SourceFilterRemoved": self.handle_source_filter_removed,
            "source_filter_enabled_state_changed": self.handle_source_filter_enabled_state_changed,
            "SourceFilterEnableStateChanged": self.handle_source_filter_enabled_state_changed,
            # Virtual camera events
            "virtualcam_state_changed": self.handle_virtualcam_state_changed,
            "VirtualcamStateChanged": self.handle_virtualcam_state_changed,
            # Transition events
            "transition_began": self.handle_transition_began,
            "TransitionBegan": self.handle_transition_began,
            "transition_ended": self.handle_transition_ended,
            "TransitionEnded": self.handle_transition_ended,
        }
        handler = event_handlers.get(event_type)
        if handler:
            await handler(sid, data)
        else:
            # If no specific handler, broadcast as generic OBS_EVENT
            self.logger.debug(f"No specific handler for OBS event type: {event_type}, broadcasting as generic event")
            payload = {
                "event_type": event_type,
                **(data or {}),
                "channel_code": code or "unknown"
            }
            await self.broadcast_with_globals("OBS_EVENT", payload, code)

    def get_obs_state(self, code):
        return self.obs_state.get(code, {})

    def update_obs_state(self, code, state_data):
        if code not in self.obs_state:
            self.obs_state[code] = {}
        self.obs_state[code].update(state_data)
        self.logger.debug(f"Updated OBS state for {code}: {self.obs_state[code]}")

    def clear_obs_state(self, code):
        if code in self.obs_state:
            del self.obs_state[code]
            self.logger.debug(f"Cleared OBS state for {code}")

    async def handle_obs_event_received(self, sid, data):
        self.logger.info(f"OBS Event Received from SID [{sid}]: {data}")
        # Broadcast OBS_EVENT_RECEIVED to all global listeners
        await self.broadcast_with_globals("OBS_EVENT_RECEIVED", data, None)

    async def handle_obs_request(self, sid, data):
        """
        Handle OBS_REQUEST - commands/actions being sent FROM Specter TO OBS.
        Examples: set scene, toggle source visibility, start recording, etc.
        """
        self.logger.info(f"OBS Request from SID [{sid}]: {data}")
        code = self.get_code_by_sid(sid) if self.get_code_by_sid else None
        
        request_type = data.get("request_type") or data.get("action")
        action_id = data.get("action_id") or data.get("id")
        
        # Log the request for auditing
        self.logger.info(f"OBS Request: type={request_type}, action_id={action_id}, code={code}")
        
        # Forward the request to OBS-connected client(s) for the same code
        if code and code in self.get_clients():
            forwarded_count = 0
            for client in self.get_clients()[code]:
                # Send to OBS WebSocket connector clients (typically overlay or bridge clients)
                if "obs" in client['name'].lower():
                    await self.sio.emit("OBS_REQUEST", data, to=client['sid'])
                    self.logger.info(f"Forwarded OBS request to {client['name']} [{client['sid']}]")
                    forwarded_count += 1
            
            if forwarded_count == 0:
                self.logger.warning(f"No OBS connector found for code {code} to forward request")
                # Broadcast error back to requestor
                await self.sio.emit("OBS_REQUEST_ERROR", {
                    "error": f"No OBS connector available for code {code}",
                    "action_id": action_id
                }, to=sid)
        else:
            self.logger.warning(f"Invalid code or no clients for code {code}")
            await self.sio.emit("OBS_REQUEST_ERROR", {
                "error": f"Invalid code: {code}",
                "action_id": action_id
            }, to=sid)
