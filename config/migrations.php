<?php
// Managed system/shared databases (NOT per-user - those stay in usr_database.php).
// Add a key + a ./migrations/{key}/ folder (server: /var/www/migrations/{key}/, OUTSIDE the public
// web root) to bring a new system DB under migrations.
return [
    'website'      => ['label' => 'Website'],
    'specterdiscordbot' => ['label' => 'Discord Bot'],
    'roadmap'      => ['label' => 'Roadmap'],
    'spam_pattern' => ['label' => 'Spam Patterns'],
];
