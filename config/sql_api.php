<?php
/**
 * SQL data API (tenant-scoped FastAPI on sql.botofthespecter.com).
 *
 * Production: /var/www/config/sql_api.php
 * Dev:        ./config/sql_api.php
 *
 * Auth: each SpecterBotApp subdomain uses that streamer's user API key
 * (website.users.api_key), loaded from a server-only key file — never MySQL
 * passwords and never expose keys to browsers.
 *
 * PHP never reads .env (see .grok/rules/php-config.md).
 */
return [
    // Base URL of the SQL data API (no trailing slash)
    'base_url' => 'https://sql.botofthespecter.com',
    // Seconds for HTTP timeouts
    'timeout' => 15,
    // Directory of per-username key files: {username}.key (one line = api_key)
    // Production default; override if keys live elsewhere.
    'keys_dir' => '/var/www/specterbotapp/keys',
    // Migration only: allow database.php to open mysqli with shared MySQL
    // credentials. Set false once modules use sql_api_* and MySQL is firewalled
    // off this host. Never leave true long-term in production.
    'allow_legacy_mysql' => true,
];
