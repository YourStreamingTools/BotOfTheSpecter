<?php
/**
 * Identity of this web host for /health_metrics.php.
 *
 * Production: /var/www/config/web_identity.php
 * Set server_name per machine (web1, web2, …). Same codebase on every web host.
 */
return [
    'server_name' => 'web1',
    'service' => 'web',
];
