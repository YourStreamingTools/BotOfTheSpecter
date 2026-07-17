<?php
// MEGA S4 object-storage configuration.
// Regional endpoint - pick the region nearest the web host.
// Other options include eu-central, us-east, us-west, etc.
$megas4_endpoint = "https://s3.g.megas4.com";

// The AWS SDK requires a non-empty region string; MEGA S4 ignores its value.
// Keep 'us-east-1' to match the convention used throughout the project.
$megas4_region = "g";

// Bucket name in MEGA S4.
$megas4_bucket = "botofthespecter";

// Access credentials
$megas4_access_key = "";
$megas4_secret_key = "";

// Store map: prefix => display name + public CDN domain served by Caddy.
// Domains match the static-asset host blocks in web/Caddyfile.
// per_user: true when top-level folders are per-username directories.
$megas4_stores = [
    'cdn'         => ['name' => 'CDN',           'domain' => 'cdn.botofthespecter.com',         'per_user' => false],
    'media'       => ['name' => 'Media',         'domain' => 'media.botofthespecter.com',       'per_user' => true],
    'usermusic'   => ['name' => 'User Music',    'domain' => 'music.botofthespecter.com',       'per_user' => true],
    'walkons'     => ['name' => 'Walk-ons',      'domain' => 'walkons.botofthespecter.com',     'per_user' => true],
    'soundalerts' => ['name' => 'Sound Alerts',  'domain' => 'soundalerts.botofthespecter.com', 'per_user' => true],
    'tts'         => ['name' => 'TTS',           'domain' => 'tts.botofthespecter.com',         'per_user' => true],
    'videoalerts' => ['name' => 'Video Alerts',  'domain' => 'videoalerts.botofthespecter.com', 'per_user' => true],
];
