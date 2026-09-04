<?php
/**
 * YourChat narrator (Piper on the websocket VM).
 *
 * Production: /var/www/config/yourchat.php
 * Dev:        ./config/yourchat.php
 *
 * PHP never reads .env. The hop secret must match NARRATOR_HOP_SECRET
 * on the websocket host. Never expose this to browsers.
 */
return [
    // Private LAN only — not websocket.botofthespecter.com
    'piper_url' => 'http://10.240.0.5:8094',
    'hop_secret' => '',
    'timeout' => 10,
    'default_voice' => 'en_US-lessac-medium',
];
