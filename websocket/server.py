import os
import socket
import signal
import asyncio
import logging
import ssl
import argparse
import socketio
from aiohttp import web
import time
import threading
import paramiko
import uuid
import json
import aiomysql
import shutil
import subprocess

# Import our modular components
from music_handler import MusicHandler
from tts_handler import TTSHandler
from database_manager import DatabaseManager
from event_handler import EventHandler
from security_manager import SecurityManager
from donation_handler import DonationEventHandler
from settings_manager import SettingsManager
from obs_handler import ObsHandler

# Load ENV file
from dotenv import load_dotenv, find_dotenv
load_dotenv(find_dotenv("/home/botofthespecter/.env"))

class SSHConnectionManager:
    def __init__(self, logger, timeout_minutes=2):
        self.logger = logger
        self.timeout_seconds = timeout_minutes * 60
        self.connections = {}  # hostname -> connection info
        self.lock = threading.Lock()
    async def get_connection(self, ssh_config):
        hostname = ssh_config['hostname']
        with self.lock:
            # Check if we have an active connection
            if hostname in self.connections:
                conn_info = self.connections[hostname]
                # Check if connection is still valid and not timed out
                if (time.time() - conn_info['last_used'] < self.timeout_seconds and 
                    self._is_connection_alive(conn_info['client'])):
                    conn_info['last_used'] = time.time()
                    self.logger.debug(f"Reusing SSH connection to {hostname}")
                    return conn_info['client']
                else:
                    # Connection expired or dead, clean it up
                    self._cleanup_connection(hostname)
            # Create new connection
            return await self._create_connection(hostname, ssh_config)
    def _is_connection_alive(self, ssh_client):
        try:
            transport = ssh_client.get_transport()
            return transport and transport.is_active()
        except:
            return False
    async def _create_connection(self, hostname, ssh_config):
        try:
            self.logger.info(f"Creating new SSH connection to {hostname}")
            ssh_client = paramiko.SSHClient()
            ssh_client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            # Connect with provided credentials
            connect_kwargs = {
                'hostname': ssh_config['hostname'],
                'port': ssh_config.get('port', 22),
                'username': ssh_config['username'],
                'timeout': 30
            }
            if ssh_config.get('password'):
                connect_kwargs['password'] = ssh_config['password']
            elif ssh_config.get('key_filename'):
                connect_kwargs['key_filename'] = ssh_config['key_filename']
            else:
                raise ValueError("Either password or key_filename must be provided in SSH config")
            # Run connection in thread to avoid blocking
            await asyncio.get_event_loop().run_in_executor(
                None, lambda: ssh_client.connect(**connect_kwargs)
            )
            # Store connection info
            self.connections[hostname] = {
                'client': ssh_client,
                'last_used': time.time(),
                'config': ssh_config
            }
            self.logger.info(f"SSH connection established to {hostname}")
            return ssh_client
        except Exception as e:
            self.logger.error(f"Failed to create SSH connection to {hostname}: {e}")
            raise
    def _cleanup_connection(self, hostname):
        if hostname in self.connections:
            try:
                self.connections[hostname]['client'].close()
                self.logger.debug(f"Closed SSH connection to {hostname}")
            except:
                pass
            del self.connections[hostname]
    async def cleanup_expired_connections(self):
        current_time = time.time()
        expired_hosts = []
        with self.lock:
            for hostname, conn_info in self.connections.items():
                if current_time - conn_info['last_used'] > self.timeout_seconds:
                    expired_hosts.append(hostname)
            for hostname in expired_hosts:
                self.logger.info(f"Cleaning up expired SSH connection to {hostname}")
                self._cleanup_connection(hostname)
    def cleanup_all_connections(self):
        with self.lock:
            for hostname in list(self.connections.keys()):
                self._cleanup_connection(hostname)
            self.logger.info("All SSH connections cleaned up")

class BotOfTheSpecter_WebsocketServer:
    def __init__(self, logger):
        # Initialize the WebSocket server.
        self.logger = logger
        self.ip = self.get_own_ip()
        self.script_dir = os.path.dirname(os.path.realpath(__file__)).replace("\\", "/")
        self.registered_clients = {}
        # Global listeners that receive events from all channels
        self.global_listeners = []
        # Track explicitly registered event names so wildcard handling doesn't duplicate
        self.explicit_event_handlers = set()
        # Load admin code from environment variable
        self.admin_code = os.getenv("ADMIN_KEY")
        if not self.admin_code:
            self.logger.warning("ADMIN_KEY not set - global listeners will not be available")
        else:
            self.logger.info("Admin key loaded for global listener authentication")
        # Initialize modular components
        self.ssh_manager = SSHConnectionManager(logger, timeout_minutes=2)
        self.security_manager = SecurityManager(logger)
        self.database_manager = DatabaseManager(logger)
        self.settings_manager = SettingsManager(logger)
        self.tts_handler = TTSHandler(logger, self.ssh_manager)
        self.event_handler = EventHandler(None, logger, lambda: self.registered_clients, self.broadcast_event_with_globals, self.get_code_by_sid)
        # OBS handler: dedicated OBS websocket event routing and state tracking
        self.obs_handler = ObsHandler(logger, lambda: self.registered_clients, self.broadcast_event_with_globals, self.get_code_by_sid)
        self.donation_handler = DonationEventHandler(None, logger, lambda: self.registered_clients, self.broadcast_event_with_globals)
        socketio_logger = logging.getLogger('socketio')
        socketio_logger.setLevel(logging.WARNING)  # Only log warnings and errors from socketio
        engineio_logger = logging.getLogger('engineio')
        engineio_logger.setLevel(logging.WARNING)  # Only log warnings and errors from engineio
        # Initialize SocketIO server
        self.sio = socketio.AsyncServer(
            logger=socketio_logger, 
            engineio_logger=engineio_logger,
            cors_allowed_origins='*',
            ping_timeout=30,
            ping_interval=25
        )
        # Update event handlers with the sio instance
        self.event_handler.sio = self.sio
        self.donation_handler.sio = self.sio
        # Update OBS handler with socketio instance and clients accessor
        self.obs_handler.sio = self.sio
        self.obs_handler.get_clients = lambda: self.registered_clients
        # Update TTS handler with socketio instance and get_clients function
        self.tts_handler.sio = self.sio
        self.tts_handler.get_clients = lambda: self.registered_clients
        # Initialize web application with security middleware
        self.app = web.Application(middlewares=[self.security_manager.ip_restriction_middleware])
        self.app.on_startup.append(self.on_startup)
        self.app.on_shutdown.append(self.on_shutdown)
        self.setup_routes()
        # Initialize music handler
        self.music_handler = MusicHandler(
            sio=self.sio,
            logger=self.logger,
            get_clients=lambda: self.registered_clients,
            save_settings=self.settings_manager.save_music_settings,
            load_settings=self.settings_manager.load_music_settings,
        )
        self.setup_event_handlers()
        self.sio.attach(self.app)
        self.loop = None
        signal.signal(signal.SIGTERM, self.sig_handler)
        signal.signal(signal.SIGINT, self.sig_handler)

    def setup_routes(self):
        # Set up the routes for the web application.
        self.app.add_routes([
            web.get("/", self.index),
            web.get("/notify", self.notify_http),
            web.get("/system-update", self.system_update_http),
            web.get("/heartbeat", self.heartbeat),
            web.get("/clients", self.list_clients),
            web.post("/admin/disconnect", self.admin_disconnect_client),
            web.get("/favicon.ico", self.favicon_redirect)
        ])

    def setup_event_handlers(self):
        # Set up the event handlers for the SocketIO server.
        event_handlers = [
            ("connect", self.connect),
            ("disconnect", self.disconnect),
            ("REGISTER", self.register),
            ("LIST_CLIENTS", self.list_clients_event),
            ("NOTIFY", self.event_handler.handle_generic_notify),
            ("DEATHS", self.event_handler.handle_deaths),
            ("WEATHER", self.event_handler.handle_weather),
            ("WEATHER_DATA", self.event_handler.handle_weather_data),
            ("TWITCH_FOLLOW", self.event_handler.handle_twitch_follow),
            ("TWITCH_CHEER", self.event_handler.handle_twitch_cheer),
            ("TWITCH_RAID", self.event_handler.handle_twitch_raid),
            ("TWITCH_SUB", self.event_handler.handle_twitch_sub),
            ("TWITCH_CHANNELPOINTS", self.event_handler.handle_twitch_channelpoints),
            ("WALKON", self.event_handler.handle_walkon),
            ("TTS", self.tts),
            ("SOUND_ALERT", self.event_handler.handle_sound_alert),
            ("VIDEO_ALERT", self.event_handler.handle_video_alert),
            ("STREAM_ONLINE", self.event_handler.handle_stream_online),
            ("STREAM_OFFLINE", self.event_handler.handle_stream_offline),
            ("DISCORD_JOIN", self.event_handler.handle_discord_join),
            ("FOURTHWALL", self.handle_fourthwall_event),
            ("KOFI", self.handle_kofi_event),
            ("PATREON", self.handle_patreon_event),
            ("SYSTEM_UPDATE", self.event_handler.handle_system_update),
            # OBS events: FROM OBS TO Specter (incoming notifications from OBS)
            ("OBS_EVENT", self.obs_handler.handle_obs_event),
            ("OBS_EVENT_RECEIVED", self.obs_handler.handle_obs_event_received),
            # OBS commands: FROM Specter TO OBS (outgoing commands to control OBS)
            ("OBS_REQUEST", self.obs_handler.handle_obs_request),
            ("MUSIC_COMMAND", self.music_handler.music_command),
            ("*", self.event)
        ]
        for event, handler in event_handlers:
            self.explicit_event_handlers.add(event)
            self.sio.on(event, handler)
        # Treat key wildcard-managed events as already handled
        self.explicit_event_handlers.update({"NOW_PLAYING", "MUSIC_SETTINGS"})

    async def ip_restriction_middleware(self, app, handler):
        async def middleware_handler(request):
            if request.path in ['/clients', '/notify']:
                peername = request.transport.get_extra_info('peername')
                if peername is not None:
                    ip = peername[0]
                    if not self.is_ip_allowed(ip):
                        self.logger.warning(f"Unauthorized access attempt from IP: {ip}")
                        return web.HTTPForbidden(text="403 Forbidden: Access denied")
            return await handler(request)
        return middleware_handler

    async def event(self, sid, event, data):
        # Handle generic events for SocketIO.
        self.logger.debug(f"Event {event} from SID [{sid}]: {data}")
        # Relay NOW_PLAYING and MUSIC_SETTINGS to all clients with same code
        if event in ("NOW_PLAYING", "MUSIC_SETTINGS"):
            code = None
            for c, clients in self.registered_clients.items():
                for client in clients:
                    if client['sid'] == sid:
                        code = c
                        break
                if code:
                    break
            if code:
                for client in self.registered_clients[code]:
                    if client['sid'] != sid:
                        await self.sio.emit(event, data, to=client['sid'])
                self.logger.info(f"Relayed {event} from SID [{sid}] to other clients for code {code}")
            # Save settings if MUSIC_SETTINGS event received
            if event == "MUSIC_SETTINGS" and code:
                self.save_music_settings(code, data)
                self.logger.info(f"Saved MUSIC_SETTINGS from event for code {code}")
        elif event and event not in self.explicit_event_handlers:
            broadcast_code = self.get_code_by_sid(sid)
            await self.broadcast_event_with_globals(event, data or {}, broadcast_code)

    async def connect(self, sid, environ, auth):
        # Handle the connect event for SocketIO.
        self.logger.info(f"Connect event: {sid}")
        if environ["REMOTE_ADDR"] in ['0.0.0.0', self.ip]:
            self.logger.debug(f"Client [{sid}] is a local user with elevated access")
        self.logger.debug(environ)
        await self.sio.emit("WELCOME", {"message": "Please register your code"}, to=sid)

    async def disconnect(self, sid):
        # Handle the disconnect event for SocketIO.
        self.logger.info(f"Disconnect event: {sid}")
        # Check if it's a global listener
        global_listener_removed = False
        for listener in list(self.global_listeners):
            if listener['sid'] == sid:
                self.global_listeners.remove(listener)
                self.logger.info(f"Removed global listener [{sid}] - {listener['name']}")
                global_listener_removed = True
                break
        # If not a global listener, check regular clients
        if not global_listener_removed:
            # Iterate through all registered clients and remove the disconnected SID
            for code, clients in list(self.registered_clients.items()):
                for client in list(clients):
                    if client['sid'] == sid:
                        clients.remove(client)
                        self.logger.info(f"Unregistered SID [{sid}] from code [{code}]")
                        if not clients:
                            del self.registered_clients[code]
                            self.logger.info(f"No more clients for code [{code}]. Removed code from registered clients.")
                        break
                else:
                    continue
                break
            else:
                self.logger.info(f"SID [{sid}] not found in registered clients.")
        # Log the current state of registered clients after disconnect
        self.logger.info(f"Current registered clients: {self.registered_clients}")
        self.logger.info(f"Current global listeners: {len(self.global_listeners)}")

    async def register(self, sid, data):
        # Handle the register event for SocketIO.
        code = data.get("code")
        channel = str(data.get("channel", "Unknown-Channel"))
        sid_name = data.get("name", f"Unnamed-{sid}")
        is_global_listener = data.get("global_listener", False)
        self.logger.info(f"Register event received from SID {sid} with code: '{code}', channel: '{channel}', name: '{sid_name}', global_listener: {is_global_listener}")
        name = f"{channel} - {sid_name}"
        # Handle global listener registration
        if is_global_listener:
            # Validate admin code for global listeners
            if not self.admin_code:
                self.logger.warning(f"Global listener registration denied for SID [{sid}] - admin key not configured")
                await self.sio.emit("ERROR", {"message": "Global listener registration not available - admin key not configured"}, to=sid)
                return
            if code != self.admin_code:
                self.logger.warning(f"Global listener registration denied for SID [{sid}] - invalid admin key provided: '{code}'")
                await self.sio.emit("ERROR", {"message": "Global listener registration denied - invalid admin key"}, to=sid)
                return
            # Check if there's already a global listener with the same name
            for listener in list(self.global_listeners):
                if listener['name'] == name:
                    # Disconnect the old session
                    old_sid = listener['sid']
                    self.logger.info(f"Disconnecting old global listener [{old_sid}] for name [{name}] before registering new session [{sid}]")
                    await self.sio.emit("ERROR", {"message": f"Disconnected: Duplicate global listener for name {name}"}, to=old_sid)
                    await self.sio.disconnect(old_sid)
                    # Remove the old listener
                    self.global_listeners.remove(listener)
                    break
            # Register the new global listener
            listener_data = {"sid": sid, "name": name, "admin_authenticated": True}
            self.global_listeners.append(listener_data)
            self.logger.info(f"Global listener [{sid}] with name [{name}] registered successfully with admin authentication")
            await self.sio.emit("SUCCESS", {"message": "Global listener registration successful", "name": name, "admin_authenticated": True}, to=sid)
            self.logger.info(f"Total global listeners: {len(self.global_listeners)}")
        elif code:
            # Handle regular client registration
            # Check if this is admin key being used for regular registration
            is_admin = (code == self.admin_code) if self.admin_code else False
            # Initialize the list for the code if it doesn't exist
            if code not in self.registered_clients:
                self.registered_clients[code] = []
            # Check if there's already a client with the same name
            for client in self.registered_clients[code]:
                if client['name'] == name:
                    # Disconnect the old session
                    old_sid = client['sid']
                    self.logger.info(f"Disconnecting old session [{old_sid}] for name [{name}] before registering new session [{sid}]")
                    await self.sio.emit("ERROR", {"message": f"Disconnected: Duplicate session for name {name}"}, to=old_sid)
                    await self.sio.disconnect(old_sid)
                    # Remove the old client, but check if code still exists
                    if code in self.registered_clients:
                        self.registered_clients[code] = [c for c in self.registered_clients[code] if c['sid'] != old_sid]
                    else:
                        self.logger.warning(f"Code [{code}] was removed from registered_clients before old client removal. Skipping removal.")
                    break # Register the new client
            client_data = {"sid": sid, "name": name, "is_admin": is_admin}
            # Ensure the code key exists before appending
            if code not in self.registered_clients:
                self.registered_clients[code] = []
            self.registered_clients[code].append(client_data)
            if is_admin:
                self.logger.info(f"Admin client [{sid}] with name [{name}] registered with admin key: {code}")
                await self.sio.emit("SUCCESS", {"message": "Admin registration successful", "code": code, "name": name, "admin_authenticated": True}, to=sid)
            else:
                self.logger.info(f"Client [{sid}] with name [{name}] registered with code: {code}")
                await self.sio.emit("SUCCESS", {"message": "Registration successful", "code": code, "name": name}, to=sid)
            self.logger.info(f"Total registered clients for code {code}: {len(self.registered_clients[code])}")
        else:
            self.logger.warning("Code not provided and not a global listener during registration")
            await self.sio.emit("ERROR", {"message": "Registration failed: code missing and not global listener"}, to=sid)
        # Log the current state of registered clients after registration
        self.logger.info(f"Current registered clients: {self.registered_clients}")
        self.logger.info(f"Current global listeners: {len(self.global_listeners)}")

    async def index(self, request):
        # Redirect to the main page
        raise web.HTTPFound(location="https://botofthespecter.com")
    
    async def favicon_redirect(self, request):
        # Redirect favicon.ico requests to the actual favicon.ico file
        raise web.HTTPFound(location="https://cdn.botofthespecter.com/favicon.ico")
    
    async def heartbeat(self, request):
        if request.method == 'OPTIONS':
            response = web.Response(status=204)
        else:
            response = web.json_response({"status": "OK"})
        response.headers['Access-Control-Allow-Origin'] = '*'
        response.headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS'
        response.headers['Access-Control-Allow-Headers'] = 'Content-Type'
        return response

    async def list_clients(self, request):
        # List the registered clients and global listeners.
        output = {
            "clients": self.registered_clients,
            "global_listeners": [
                {"sid": l["sid"], "name": l["name"], "admin_authenticated": l.get("admin_authenticated", False)}
                for l in self.global_listeners
            ]
        }
        return web.json_response(output)

    async def list_clients_event(self, sid):
        # Handle the LIST_CLIENTS event for SocketIO.
        self.logger.info(f"LIST_CLIENTS event from SID [{sid}]")
        output = {
            "clients": self.registered_clients,
            "global_listeners": [
                {"sid": l["sid"], "name": l["name"], "admin_authenticated": l.get("admin_authenticated", False)}
                for l in self.global_listeners
            ]
        }
        await self.sio.emit("LIST_CLIENTS", output, to=sid)

    async def broadcast_event_with_globals(self, event_name, data, code=None):
        count = 0
        # Broadcast to specific code clients if code is provided
        if code and code in self.registered_clients:
            for client in self.registered_clients[code]:
                await self.sio.emit(event_name, {**data, "channel_code": code}, to=client['sid'])
                count += 1
        # Broadcast to all global listeners
        for listener in self.global_listeners:
            await self.sio.emit(event_name, {**data, "channel_code": code or "unknown"}, to=listener['sid'])
            count += 1
        self.logger.info(f"Broadcasted {event_name} to {count} clients (code: {code})")
        return count

    def get_code_by_sid(self, sid):
        for code, clients in self.registered_clients.items():
            for client in clients:
                if client['sid'] == sid:
                    return code
        return None

    async def notify_http(self, request):
        # Extract query parameters
        code = request.query.get("code")
        event = request.query.get("event")
        text = request.query.get("text")
        language_code = request.query.get("language_code", None)
        gender = request.query.get("gender", None)
        voice_name = request.query.get("voice_name", None)
        # Validate mandatory parameters
        if not code:
            raise web.HTTPBadRequest(text="400 Bad Request: API Key is missing")
        if not event:
            raise web.HTTPBadRequest(text="400 Bad Request: Event is missing")
        data = {k: v for k, v in request.query.items()}
        self.logger.info(f"Notify request data: {data}")
        event = event.upper().replace(" ", "_")
        count = 0
        if event == "TWITCH_FOLLOW":
            await self.broadcast_event_with_globals(event, data, code)
        elif event == "TWITCH_CHEER":
            await self.broadcast_event_with_globals(event, data, code)
        elif event == "TWITCH_RAID":
            await self.broadcast_event_with_globals(event, data, code)
        elif event == "TWITCH_SUB":
            await self.broadcast_event_with_globals(event, data, code)
        elif event == "TTS" and text:
            # Add TTS request to queue with additional parameters
            await self.tts_handler.add_tts_request(text, code, language_code, gender, voice_name)
            self.logger.info(f"TTS request added to queue: {text}")
            count = 1
        elif event == "FOURTHWALL":
            # Handle Fourthwall-specific event
            await self.handle_fourthwall_event(code, data)
        elif event == "KOFI":
            # Handle Ko-Fi-specific event
            await self.handle_kofi_event(code, data)
        elif event == "PATREON":
            # Handle Patreon-specific event
            await self.handle_patreon_event(code, data)
        elif event in ["STREAM_ONLINE", "STREAM_OFFLINE", "POST_REACTION_ROLES_MESSAGE", "POST_RULES_MESSAGE"]:
            # Handle stream status events, reaction roles message, and rules message with proper global broadcasting
            count = await self.broadcast_event_with_globals(event, data, code)
            self.logger.info(f"Broadcasted {event} event to {count} clients (including global listeners)")
        else:
            # Broadcast other events to connected clients
            if code in self.registered_clients:
                for client in self.registered_clients[code]:
                    sid = client['sid']
                    await self.sio.emit(event, data, to=sid)
                    self.logger.info(f"Emitted event '{event}' to client {sid}")
                    count += 1
            # Broadcast to all global listeners as well
            for listener in self.global_listeners:
                await self.sio.emit(event, {**data, "channel_code": code or "unknown"}, to=listener['sid'])
                self.logger.info(f"Emitted event '{event}' to global listener SID [{listener['sid']}] (name: {listener['name']})")
                count += 1
            self.logger.info(f"Broadcasted event to {count} clients (including global listeners)")
        # Return a JSON response indicating success
        return web.json_response({"success": 1, "count": count, "msg": f"Broadcasted event to {count} clients"})

    async def system_update_http(self, request):
        # Handle system update requests with admin key validation
        admin_key = request.query.get("admin_key")
        if not admin_key or admin_key != self.admin_code:
            raise web.HTTPForbidden(text="403 Forbidden: Invalid or missing admin key")
        # Broadcast SYSTEM_UPDATE event to all clients
        count = await self.broadcast_event_with_globals("SYSTEM_UPDATE", {}, None)
        self.logger.info(f"System update triggered, broadcasted to {count} clients")
        return web.json_response({"success": 1, "count": count, "msg": f"System update notification sent to {count} clients"})

    async def admin_disconnect_client(self, request):
        # Handle admin disconnect requests with proper authentication
        try:
            # Check for admin authentication using query parameter (matching system_update_http pattern)
            admin_key = request.query.get("admin_key")
            if not admin_key or admin_key != self.admin_code:
                return web.json_response({'success': False, 'message': 'Unauthorized - Invalid or missing admin key'}, status=401)
            # Get request data
            try:
                data = await request.json()
            except Exception as e:
                return web.json_response({'success': False, 'message': 'Invalid JSON data'}, status=400)
            if not data or 'sid' not in data:
                return web.json_response({'success': False, 'message': 'Socket ID (sid) is required'}, status=400)
            sid = data['sid']
            self.logger.info(f"Admin disconnect request for SID: {sid}")
            # Check if the SID exists in registered clients or global listeners
            client_found = False
            client_info = None
            # Check in registered clients
            for code, clients in self.registered_clients.items():
                for client in clients:
                    if client.get('sid') == sid:
                        client_found = True
                        client_info = f"Registered client in code '{code}': {client.get('name', 'Unknown')}"
                        break
                if client_found:
                    break
            # Check in global listeners if not found in registered clients
            if not client_found:
                for listener in self.global_listeners:
                    if listener.get('sid') == sid:
                        client_found = True
                        client_info = f"Global listener: {listener.get('name', 'Unknown')}"
                        break
            if not client_found:
                return web.json_response({'success': False, 'message': f'Client with SID {sid} not found'}, status=404)
            # Attempt to disconnect the client
            try:
                # Send a notification to the client before disconnecting (optional)
                await self.sio.emit('admin_disconnect', {
                    'message': 'You have been disconnected by an administrator',
                    'reason': 'Admin action'
                }, room=sid)
                # Wait a moment for the message to be sent
                await asyncio.sleep(0.1)
                # Disconnect the client
                await self.sio.disconnect(sid)
                self.logger.info(f"Successfully disconnected client: {client_info}")
                return web.json_response({
                    'success': True, 
                    'message': f'Client disconnected successfully: {client_info}'
                })
            except Exception as e:
                self.logger.error(f"Failed to disconnect client {sid}: {str(e)}")
                return web.json_response({
                    'success': False, 
                    'message': f'Failed to disconnect client: {str(e)}'
                }, status=500)
        except Exception as e:
            self.logger.error(f"Error in admin_disconnect_client: {str(e)}")
            return web.json_response({
                'success': False, 
                'message': f'Internal server error: {str(e)}'
            }, status=500)

    async def notify(self, sid, data):
        self.logger.info(f"Notify event from SID [{sid}]: {data}")
        event = data.get('event')
        if not event:
            self.logger.error('Missing event information for NOTIFY event')
            return
        await self.sio.emit(event, data, sid)

    async def walkon(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_walkon(sid, data)

    async def handle_fourthwall_event(self, code, data):
        # Use the donation handler to handle FOURTHWALL events
        return await self.donation_handler.handle_fourthwall_event(code, data)

    async def handle_kofi_event(self, code, data):
        # Use the donation handler to handle KOFI events
        return await self.donation_handler.handle_kofi_event(code, data)

    async def handle_patreon_event(self, code, data):
        # Use the donation handler to handle PATREON events
        return await self.donation_handler.handle_patreon_event(code, data)

    async def handle_obs_event(self, sid, data):
        # Redirect OBS events to the dedicated OBS handler
        return await self.obs_handler.handle_obs_event(sid, data)

    async def tts(self, sid, data):
        # Handle TTS event for SocketIO
        text = data.get("text")
        code = data.get("code") or self.get_code_by_sid(sid)
        language_code = data.get("language_code")
        gender = data.get("gender")
        voice_name = data.get("voice_name")
        if text and code:
            await self.tts_handler.add_tts_request(text, code, language_code, gender, voice_name)
            self.logger.info(f"TTS request added to queue: {text}")
        else:
            self.logger.warning(f"TTS event missing required parameters: text={text}, code={code}")

    async def deaths(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_deaths(sid, data)

    async def twitch_follow(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_twitch_follow(sid, data)

    async def twitch_cheer(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_twitch_cheer(sid, data)

    async def twitch_raid(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_twitch_raid(sid, data)

    async def twitch_sub(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_twitch_sub(sid, data)

    async def twitch_channelpoints(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_twitch_channelpoints(sid, data)

    async def stream_online(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_stream_online(sid, data)

    async def stream_offline(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_stream_offline(sid, data)

    async def weather(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_weather(sid, data)

    async def weather_data(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_weather_data(sid, data)

    async def discord_join(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_discord_join(sid, data)

    async def sound_alert(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_sound_alert(sid, data)

    async def video_alert(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_video_alert(sid, data)

    async def system_update(self, sid, data):
        # Redirect to event handler for proper global broadcasting
        return await self.event_handler.handle_system_update(sid, data)

    async def send_notification(self, message):
        # Broadcast a notification to all registered clients
        for sid in self.registered_clients.keys():
            await self.sio.emit("NOTIFY", {"message": message}, to=sid)

    async def on_shutdown(self, app):
        self.logger.info("Shutting down...")
        # Cancel SSH cleanup task if running
        if hasattr(self, 'ssh_cleanup_task') and self.ssh_cleanup_task:
            self.ssh_cleanup_task.cancel()
        # Stop TTS processing
        await self.tts_handler.stop_processing()
        # Clean up all SSH connections
        self.ssh_manager.cleanup_all_connections()
        # Disconnect all registered clients properly
        for code, sids in list(self.registered_clients.items()):
            for client in sids:
                sid = client['sid']
                self.logger.info(f"Disconnecting SID [{sid}] during shutdown.")
                try:
                    await self.sio.disconnect(sid)
                except Exception as e:
                    self.logger.error(f"Error disconnecting SID [{sid}]: {e}")
        self.logger.info("All clients disconnected.")

    async def on_startup(self, app):
        self.logger.info("Starting application startup tasks...")        # Start the SSH cleanup task
        await self.start_ssh_cleanup_task()
        # Start the TTS processing task
        await self.tts_handler.start_processing()
        self.logger.info("Application startup completed.")

    async def start_ssh_cleanup_task(self):
        async def periodic_ssh_cleanup():
            while True:
                try:
                    await asyncio.sleep(60)  # Check every minute
                    await self.ssh_manager.cleanup_expired_connections()
                except asyncio.CancelledError:
                    break
                except Exception as e:
                    self.logger.error(f"Error in SSH cleanup task: {e}")
        self.ssh_cleanup_task = asyncio.create_task(periodic_ssh_cleanup())
        self.logger.info("SSH cleanup task started")

    def sig_handler(self, signum, frame):
        # Handle system signals for graceful shutdown.
        signame = signal.Signals(signum).name
        self.logger.info(f'Caught signal {signame} ({signum})')
        self.stop()
        self.logger.info("Server stopped")

    def run_app(self, host="0.0.0.0", port=443):
        # Run the web application.
        self.logger.info("=== Starting BotOfTheSpecter Websocket Server ===")
        # Test database connection first
        self.loop = asyncio.new_event_loop()
        asyncio.set_event_loop(self.loop)
        db_test_result = self.loop.run_until_complete(self.test_database_connection())
        if not db_test_result:
            self.logger.warning("⚠ Database connection test failed, but server will continue starting...")
        # Try to create SSL context
        ssl_context = self.create_ssl_context()
        if ssl_context is not None:
            # SSL certificates available - run secure server
            self.logger.info(f"🔒 Starting secure WebSocket server on {host}:{port}")
            web.run_app(self.app, loop=self.loop, host=host, port=port, ssl_context=ssl_context, handle_signals=True, shutdown_timeout=10)
        else:
            # No SSL certificates - fallback to insecure server
            fallback_port = 80 if port == 443 else port
            self.logger.warning(f"⚠ Starting insecure WebSocket server on {host}:{fallback_port}")
            self.logger.warning("  Consider setting up SSL certificates for production use.")
            web.run_app(self.app, loop=self.loop, host=host, port=fallback_port, ssl_context=None, handle_signals=True, shutdown_timeout=10)

    def stop(self):
        # Stop the SocketIO server.
        self.logger.info("Stopping SocketIO Server")
        future = asyncio.run_coroutine_threadsafe(self.sio.shutdown(), self.loop)
        try:
            future.result(5)
        except TimeoutError:
            self.logger.error("Timeout error - SocketIO Server didn't respond. Forcing close.")
        raise web.GracefulExit

    def create_ssl_context(self):
        # Create the SSL context for secure connections.
        domain = 'websocket.botofthespecter.com'
        # Check for Let's Encrypt certificates first
        letsencrypt_cert = f'/etc/letsencrypt/live/{domain}/fullchain.pem'
        letsencrypt_key = f'/etc/letsencrypt/live/{domain}/privkey.pem'
        ssl_context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
        # Try Let's Encrypt certificates first
        if os.path.exists(letsencrypt_cert) and os.path.exists(letsencrypt_key):
            try:
                ssl_context.load_cert_chain(certfile=letsencrypt_cert, keyfile=letsencrypt_key)
                self.logger.info(f"✓ Using Let's Encrypt SSL certificates for {domain}")
                return ssl_context
            except Exception as e:
                self.logger.warning(f"Failed to load Let's Encrypt certificates: {e}")
        else:
            self.logger.info("Let's Encrypt certificates not found, checking local certificates...")
        # No SSL certificates available
        self.logger.error("✗ No SSL certificates found. Server will not start with SSL.")
        return None

    @staticmethod
    def ext_to_content_type(ext, default="text/html"):
        # Get the content type based on file extension.
        content_types = {
            ".js": "text/javascript",
            ".css": "text/css",
            ".json": "application/json",
            ".png": "image/png",
            ".jpg": "image/jpeg",
            ".gif": "image/gif",
            ".ico": "image/vnd.microsoft.icon"
        }
        return content_types.get(ext, default)

    @staticmethod
    def get_own_ip():
        # Get the IP address of the current machine.
        hostname = socket.gethostname()
        return socket.gethostbyname(hostname)

    def save_music_settings(self, code, settings):
        MUSIC_SETTINGS_DIR = "/home/botofthespecter/music-settings"
        os.makedirs(MUSIC_SETTINGS_DIR, exist_ok=True)
        settings_file = os.path.join(MUSIC_SETTINGS_DIR, f"{code}.json")
        try:
            # If file exists, update existing settings
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    current = json.load(f)
            else:
                current = {}
            for k in ("repeat", "shuffle"):
                if k in settings:
                    settings[k] = bool(settings[k])
            # Add validation for volume to ensure it's an integer between 0-100
            if 'volume' in settings:
                try:
                    settings['volume'] = max(0, min(100, int(settings['volume'])))
                except ValueError:
                    settings.pop('volume')
                    self.logger.warning(f"Invalid volume value in settings for {code}")
            current.update(settings)
            with open(settings_file, "w") as f:
                json.dump(current, f)
        except Exception as e:
            self.logger.error(f"Failed to save music settings for {code}: {e}")

    def load_music_settings(self, code):
        MUSIC_SETTINGS_DIR = "/home/botofthespecter/music-settings"
        settings_file = os.path.join(MUSIC_SETTINGS_DIR, f"{code}.json")
        try:
            if os.path.exists(settings_file):
                with open(settings_file, "r") as f:
                    settings = json.load(f)
                    for k in ("repeat", "shuffle"):
                        if k in settings:
                            settings[k] = bool(settings[k])
                    return settings
        except Exception as e:
            self.logger.error(f"Failed to load music settings for {code}: {e}")
        return None

    async def get_database_connection(self, database_name='website'):
        try:
            # Get database configuration from environment variables
            db_host = os.getenv('SQL_HOST')
            db_user = os.getenv('SQL_USER')
            db_password = os.getenv('SQL_PASSWORD')
            db_port = os.getenv('SQL_PORT')
            # Validate required environment variables
            if not all([db_host, db_user, db_password, db_port]):
                missing_vars = []
                if not db_host: missing_vars.append('SQL_HOST')
                if not db_user: missing_vars.append('SQL_USER')
                if not db_password: missing_vars.append('SQL_PASSWORD')
                if not db_port: missing_vars.append('SQL_PORT')
                self.logger.error(f"✗ Missing required environment variables: {', '.join(missing_vars)}")
                return None 
            try:
                db_port = int(db_port)
            except ValueError:
                self.logger.error(f"✗ Invalid SQL_PORT value: {db_port}. Must be a number.")
                return None
            conn = await aiomysql.connect(
                host=db_host,
                user=db_user,
                password=db_password,
                db=database_name,
                port=db_port,
                autocommit=True
            )
            self.logger.info(f"✓ Database connection established to {db_host}:{db_port} for database '{database_name}'")
            return conn
        except Exception as e:
            self.logger.error(f"✗ Failed to connect to database: {e}")
            return None

    async def execute_query(self, query, params=None, database_name='website'):
        conn = None
        try:
            conn = await self.get_database_connection(database_name)
            if not conn:
                return None
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(query, params)
                if query.strip().upper().startswith('SELECT'):
                    result = await cursor.fetchall()
                    return result
                else:
                    return cursor.rowcount
        except Exception as e:
            self.logger.error(f"Database query error: {e}")
            return None
        finally:
            if conn:
                conn.close()

    async def get_user_settings(self, channel_name):
        query = "SELECT * FROM profile WHERE id = 1"
        result = await self.execute_query(query, database_name=channel_name)
        return result[0] if result else None

    async def test_database_connection(self):
        self.logger.info("Testing database connection...")
        try:
            conn = await self.get_database_connection('website')
            if conn:
                async with conn.cursor() as cursor:
                    await cursor.execute("SELECT 1")
                    result = await cursor.fetchone()
                    if result:
                        self.logger.info("✓ Database connection test successful")
                        return True
                conn.close()
            else:
                self.logger.error("✗ Database connection test failed")
                return False
        except Exception as e:
            self.logger.error(f"✗ Database connection test error: {e}")
            return False

    async def get_user_api_key_info(self, api_key):
        try:
            query = "SELECT username, twitch_user_id FROM users WHERE api_key = %s"
            result = await self.execute_query(query, (api_key,), 'website')
            return result[0] if result else None
        except Exception as e:
            self.logger.error(f"Failed to get user API key info: {e}")
            return None

    async def generate_local_tts(self, text, code, voice_name=None):
        try:
            unique_id = uuid.uuid4().hex[:8]
            # Absolute paths for TTS script and environment (both in /home/botofthespecter/)
            tts_script_path = "/home/botofthespecter/local_tts_generator.py"
            python_exe = "/home/botofthespecter/tts_env/bin/python"
            config_path = "/home/botofthespecter/websocket_tts_config.json"
            desired_filename = f'tts_output_{code}_{unique_id}.mp3'
            cmd = [
                python_exe,
                tts_script_path,
                "--text", text,
                "--config", config_path,
                "--filename", desired_filename,
                "--keep-local"  # Keep local copy for SFTP transfer
            ]
            # Add voice parameter if specified
            if voice_name:
                cmd.extend(["--voice", voice_name])
            self.logger.info(f"Running TTS command: {' '.join(cmd)}")
            # Run the subprocess without overriding environment variables
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                cwd="/home/botofthespecter"
            )
            stdout, stderr = await process.communicate()
            if process.returncode == 0:
                self.logger.info(f"TTS generation successful: {stdout.decode()}")
                # Check if the file was created on the remote server (transferred via SSH)
                remote_file_path = f"/var/www/html/tts/{desired_filename}"
                # Also check for local copy
                local_output_dir = "/home/botofthespecter/local_tts_output"
                local_file_path = os.path.join(local_output_dir, desired_filename)
                if os.path.exists(local_file_path):
                    # Move the local file to the TTS directory for serving
                    final_file_path = os.path.join(self.tts_dir, desired_filename)
                    shutil.move(local_file_path, final_file_path)
                    self.logger.info(f"TTS file ready: {final_file_path}")
                    return final_file_path
                else:
                    self.logger.error(f"Generated TTS file not found: {local_file_path}")
                    return None
            else:
                self.logger.error(f"TTS generation failed: {stderr.decode()}")
                return None
        except Exception as e:
            self.logger.error(f"Error generating local TTS: {e}")
            return None

    def estimate_audio_duration(self, audio_file, text):
        try:
            # Try to get actual duration from the audio file if possible
            result = subprocess.run([
                'ffprobe', '-v', 'quiet', '-show_entries', 'format=duration',
                '-of', 'csv=p=0', audio_file
            ], capture_output=True, text=True, timeout=10)
            if result.returncode == 0 and result.stdout.strip():
                duration = float(result.stdout.strip())
                self.logger.info(f"Actual audio duration: {duration} seconds")
                return duration
        except Exception as e:
            self.logger.warning(f"Could not get actual audio duration: {e}")
        words = len(text.split())
        estimated_duration = (words / 180) * 60  # 180 words per minute
        estimated_duration = max(2, estimated_duration)  # Minimum 2 seconds
        self.logger.info(f"Estimated audio duration: {estimated_duration} seconds (based on {words} words)")
        return estimated_duration

    async def cleanup_tts_file(self, file_path, delay_seconds=0):
        try:
            if delay_seconds > 0:
                await asyncio.sleep(delay_seconds)
            if os.path.exists(file_path):
                os.remove(file_path)
                self.logger.info(f"Cleaned up TTS file: {file_path}")
                # Also try to clean up from remote server via SSH
                filename = os.path.basename(file_path)
                await self.cleanup_remote_tts_file(filename)
            else:
                self.logger.warning(f"TTS file not found for cleanup: {file_path}")
        except Exception as e:
            self.logger.error(f"Error cleaning up TTS file {file_path}: {e}")

    async def cleanup_remote_tts_file(self, filename):
        if not self.tts_config or not self.tts_config.get('ssh_config'):
            self.logger.warning("No SSH config available for remote cleanup")
            return
        try:
            # Get SSH connection from manager
            ssh_client = await self.ssh_manager.get_connection(self.tts_config['ssh_config'])
            # Build remote file path
            remote_dir = self.tts_config['remote_paths']['tts_directory']
            remote_file_path = f"{remote_dir.rstrip('/')}/{filename}"
            # Execute delete command
            command = f"rm -f '{remote_file_path}'"
            self.logger.info(f"Executing remote cleanup: {command}")
            stdin, stdout, stderr = ssh_client.exec_command(command)
            exit_status = stdout.channel.recv_exit_status()
            if exit_status == 0:
                self.logger.info(f"Successfully deleted remote file: {remote_file_path}")
            else:
                error_msg = stderr.read().decode().strip()
                self.logger.warning(f"Remote delete command returned {exit_status}: {error_msg}")
        except Exception as e:
            self.logger.error(f"Error in remote cleanup for {filename}: {e}")

    async def move_file_to_remote(self, local_file_path, remote_filename):
        if not self.tts_config or not self.tts_config.get('ssh_config'):
            self.logger.warning("No SSH config available for file transfer")
            return None
        try:
            # Get SSH connection from manager
            ssh_client = await self.ssh_manager.get_connection(self.tts_config['ssh_config'])
            # Build remote path
            remote_dir = self.tts_config['remote_paths']['tts_directory']
            remote_file_path = f"{remote_dir.rstrip('/')}/{remote_filename}"
            # Create remote directory if needed
            mkdir_command = f"mkdir -p '{remote_dir}'"
            ssh_client.exec_command(mkdir_command)
            # Transfer file using SCP
            from scp import SCPClient
            with SCPClient(ssh_client.get_transport()) as scp:
                scp.put(local_file_path, remote_file_path)
            self.logger.info(f"File transferred successfully: {remote_file_path}")
            return remote_file_path
        except Exception as e:
            self.logger.error(f"Error transferring file {local_file_path}: {e}")
            return None

    def get_code_by_sid(self, sid):
        for code, clients in self.registered_clients.items():
            for client in clients:
                if client['sid'] == sid:
                    return code
        return None

if __name__ == '__main__':
    SCRIPT_DIR = os.path.dirname(__file__)
    parser = argparse.ArgumentParser(prog='BotOfTheSpecter Websocket Server', description='A WebSocket server for handling notifications and real-time communication between the Website, System Overlays, Twitch Chat Bot, and Discord Bot.')
    parser.add_argument("-H", "--host", default="0.0.0.0", help="Specify the listener host. Default is 0.0.0.0")
    parser.add_argument("-p", "--port", default=443, type=int, help="Specify the listener port number. Default is 443")
    parser.add_argument("-l", "--loglevel", choices=["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"], default="INFO", help="Specify the log level. INFO is the default.")
    parser.add_argument("-f", "--logfile", help="Specify log file location. Production location should be <WEBROOT>/log/noti_server.log")
    args = parser.parse_args()
    log_level = {"DEBUG": logging.DEBUG, "INFO": logging.INFO, "WARNING": logging.WARNING, "ERROR": logging.ERROR, "CRITICAL": logging.CRITICAL}[args.loglevel]
    log_file = args.logfile if args.logfile else os.path.join(SCRIPT_DIR, "noti_server.log")
    # Create custom formatter with specific date format
    formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    # Configure file handler
    file_handler = logging.FileHandler(log_file, mode="a")
    file_handler.setFormatter(formatter)
    file_handler.setLevel(log_level)
    # Configure console handler
    console_handler = logging.StreamHandler()
    console_handler.setFormatter(formatter)
    console_handler.setLevel(log_level)
    # Configure root logger
    root_logger = logging.getLogger()
    root_logger.setLevel(log_level)
    root_logger.addHandler(file_handler)
    root_logger.addHandler(console_handler)
    server = BotOfTheSpecter_WebsocketServer(logging)
    server.run_app(host=args.host, port=args.port)