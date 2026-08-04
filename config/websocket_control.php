<?php
/**
 * WebSocket host control API (private process control).
 *
 * Production: /var/www/config/websocket_control.php
 * Dev:        ./config/websocket_control.php
 *
 * Auth: admin API key with service name "websocket" (Admin → API Keys).
 * Never expose keys to browsers / end users.
 */
return [
    // Base URL including /control path (no trailing slash) — Caddy strips prefix to host API
    'base_url' => 'https://websocket.botofthespecter.com/control',
    // admin_api_keys.service name
    'admin_service' => 'websocket',
    // Optional override; leave empty to load from admin_api_keys
    'control_key' => '',
    'timeout' => 15,
];
