<?php
/**
 * Bot-host control API (private process control on bots.botofthespecter.com).
 *
 * Production: /var/www/config/bots_api.php
 * Dev:        ./config/bots_api.php
 *
 * The control key must match BOTS_CONTROL_KEY (or ADMIN_KEY fallback) on the bot host.
 * Never expose this key to browsers / end users — only server-side PHP and the public API.
 */
return [
    // Base URL of the bot-host control API (no trailing slash)
    'base_url' => 'https://bots.botofthespecter.com',
    // Shared secret → sent as X-API-KEY (or X-BOTS-CONTROL-KEY)
    'control_key' => '',
    // Seconds for HTTP timeouts
    'timeout' => 15,
];
