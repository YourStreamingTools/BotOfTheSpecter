<?php
return [
    'description' => 'YouTube upload jobs: Twitch VOD source + pulling status',
    'preview' => "ADD youtube_vod_uploads.source, twitch_video_id; extend status ENUM with pulling",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'youtube_vod_uploads')) {
            return;
        }
        if (!$conn->query(
            "ALTER TABLE youtube_vod_uploads
             MODIFY status ENUM('queued','pulling','uploading','done','failed','cancelled') NOT NULL DEFAULT 'queued'"
        )) {
            throw new Exception($conn->error);
        }
        if (!migration_column_exists($conn, 'youtube_vod_uploads', 'source')) {
            if (!$conn->query(
                "ALTER TABLE youtube_vod_uploads
                 ADD COLUMN source ENUM('stream','twitch_vod') NOT NULL DEFAULT 'stream'"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_column_exists($conn, 'youtube_vod_uploads', 'twitch_video_id')) {
            if (!$conn->query(
                "ALTER TABLE youtube_vod_uploads
                 ADD COLUMN twitch_video_id VARCHAR(32) DEFAULT NULL,
                 ADD KEY idx_youtube_upload_twitch (user_id, twitch_video_id)"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'youtube_vod_uploads')) {
            return;
        }
        if (!$conn->query(
            "UPDATE youtube_vod_uploads SET status = 'queued' WHERE status = 'pulling'"
        )) {
            throw new Exception($conn->error);
        }
        if (migration_column_exists($conn, 'youtube_vod_uploads', 'twitch_video_id')) {
            if (!$conn->query("ALTER TABLE youtube_vod_uploads DROP COLUMN twitch_video_id")) {
                throw new Exception($conn->error);
            }
        }
        if (migration_column_exists($conn, 'youtube_vod_uploads', 'source')) {
            if (!$conn->query("ALTER TABLE youtube_vod_uploads DROP COLUMN source")) {
                throw new Exception($conn->error);
            }
        }
        if (!$conn->query(
            "ALTER TABLE youtube_vod_uploads
             MODIFY status ENUM('queued','uploading','done','failed','cancelled') NOT NULL DEFAULT 'queued'"
        )) {
            throw new Exception($conn->error);
        }
    },
];
