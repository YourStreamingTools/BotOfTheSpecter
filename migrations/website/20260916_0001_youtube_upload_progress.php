<?php
return [
    'description' => 'YouTube VOD upload byte progress for the dashboard bar',
    'preview' => 'ADD youtube_vod_uploads.bytes_sent, bytes_total, progress_percent',
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'youtube_vod_uploads')) {
            return;
        }
        if (!migration_column_exists($conn, 'youtube_vod_uploads', 'bytes_sent')) {
            if (!$conn->query(
                "ALTER TABLE youtube_vod_uploads
                 ADD COLUMN bytes_sent BIGINT NOT NULL DEFAULT 0"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_column_exists($conn, 'youtube_vod_uploads', 'bytes_total')) {
            if (!$conn->query(
                "ALTER TABLE youtube_vod_uploads
                 ADD COLUMN bytes_total BIGINT NOT NULL DEFAULT 0"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_column_exists($conn, 'youtube_vod_uploads', 'progress_percent')) {
            if (!$conn->query(
                "ALTER TABLE youtube_vod_uploads
                 ADD COLUMN progress_percent DECIMAL(5,1) NOT NULL DEFAULT 0"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'youtube_vod_uploads')) {
            return;
        }
        foreach (['progress_percent', 'bytes_total', 'bytes_sent'] as $col) {
            if (migration_column_exists($conn, 'youtube_vod_uploads', $col)) {
                if (!$conn->query("ALTER TABLE youtube_vod_uploads DROP COLUMN {$col}")) {
                    throw new Exception($conn->error);
                }
            }
        }
    },
];
