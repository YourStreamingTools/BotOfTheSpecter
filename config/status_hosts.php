<?php
/**
 * Public status page host inventory (home/status.php).
 *
 * Production: /var/www/config/status_hosts.php
 * Dev:        ./config/status_hosts.php
 *
 * Adding another web server:
 *  1. Deploy home/health_metrics.php + home/includes/host_metrics.php to that host.
 *  2. On that host set config/web_identity.php server_name to the new id (e.g. web2).
 *  3. Append a web_hosts row here (id, label, ping_host, metrics_url).
 *  4. No status.php code change required.
 */
return [
    // Optional: which web id is "this" machine for local /proc fallback when
    // metrics_url is unreachable from itself. Overridden by web_identity.php.
    'local_web_id' => 'web1',

    'web_hosts' => [
        [
            'id' => 'web1',
            'label' => 'Web Server 1',
            // Ping the host label; metrics hit the site that actually serves PHP
            // (web1.botofthespecter.com currently redirects to apex).
            'ping_host' => 'web1.botofthespecter.com',
            'ping_port' => 443,
            'metrics_url' => 'https://botofthespecter.com/health_metrics.php',
        ],
        // Example for a second web host (uncomment when live):
        // [
        //     'id' => 'web2',
        //     'label' => 'Web Server 2',
        //     'ping_host' => 'web2.botofthespecter.com',
        //     'ping_port' => 443,
        //     'metrics_url' => 'https://web2.botofthespecter.com/health_metrics.php',
        // ],
    ],

    // Non-web services: ping + optional /health/metrics
    'services' => [
        [
            'id' => 'sql',
            'label' => 'Database Service',
            'ping_host' => 'sql.botofthespecter.com',
            'ping_port' => 3306,
            'metrics_url' => 'https://sql.botofthespecter.com/health/metrics',
        ],
        [
            'id' => 'api',
            'label' => 'API Service',
            'ping_host' => 'api.botofthespecter.com',
            'ping_port' => 443,
            'metrics_url' => 'https://api.botofthespecter.com/health/metrics',
        ],
        [
            'id' => 'websocket',
            'label' => 'WebSocket Service',
            'ping_host' => 'websocket.botofthespecter.com',
            'ping_port' => 443,
            'metrics_url' => 'https://websocket.botofthespecter.com/health/metrics',
        ],
        [
            'id' => 'bots',
            'label' => 'Bot Server',
            'ping_host' => 'bots.botofthespecter.com',
            'ping_port' => 22,
            'metrics_url' => 'https://bots.botofthespecter.com/health/metrics',
        ],
    ],
];
