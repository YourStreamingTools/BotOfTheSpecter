<?php
/**
 * Bot-host control API (private process control on bots.botofthespecter.com).
 *
 * Production: /var/www/config/bots_api.php
 * Dev:        ./config/bots_api.php
 *
 * Auth: admin API key with service name "bots" (Admin → API Keys).
 * The PHP client loads that key from website.admin_api_keys automatically.
 * Optional control_key override is only for local/dev break-glass.
 * Never expose keys to browsers / end users.
 */
return [
    // Base URL of the bot-host control API (no trailing slash)
    'base_url' => 'https://bots.botofthespecter.com',
    // admin_api_keys.service name (create this on Admin → API Keys)
    'admin_service' => 'bots',
    // Optional override; leave empty to load from admin_api_keys
    'control_key' => '',
    // Seconds for HTTP timeouts
    'timeout' => 15,
];
