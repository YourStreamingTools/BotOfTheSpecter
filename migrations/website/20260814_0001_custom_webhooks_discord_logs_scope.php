<?php
return [
    'description' => 'Allow custom_webhooks.scope = discord_logs (route inbound webhooks to the Discord logs channel)',
    'preview' => "ALTER TABLE custom_webhooks MODIFY scope ENUM('channel','global','discord_logs') NOT NULL DEFAULT 'channel'",
    'up' => function (mysqli $conn) {
        if (!$conn->query(
            "ALTER TABLE custom_webhooks MODIFY scope ENUM('channel','global','discord_logs') NOT NULL DEFAULT 'channel'"
        )) {
            throw new Exception($conn->error);
        }
    },
    'down' => function (mysqli $conn) {
        if (!$conn->query(
            "UPDATE custom_webhooks SET scope = 'global' WHERE scope = 'discord_logs'"
        )) {
            throw new Exception($conn->error);
        }
        if (!$conn->query(
            "ALTER TABLE custom_webhooks MODIFY scope ENUM('channel','global') NOT NULL DEFAULT 'channel'"
        )) {
            throw new Exception($conn->error);
        }
    },
];
