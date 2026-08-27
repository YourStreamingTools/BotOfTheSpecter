<?php
// Shared session helpers for the members portal JSON APIs.

if (!function_exists('members_session_start')) {
    function members_session_start(): void
    {
        require_once '/var/www/lib/session_bootstrap.php';
    }
}

if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('members_logged_in')) {
    function members_logged_in(): bool
    {
        return !empty($_SESSION['access_token']);
    }
}

if (!function_exists('members_require_login_json')) {
    function members_require_login_json(): void
    {
        members_session_start();
        if (!members_logged_in()) {
            json_out([
                'ok' => false,
                'session_expired' => true,
                'redirect' => '/login.php',
                'error' => 'Please sign in to continue.',
            ], 401);
        }
    }
}

if (!function_exists('members_sanitize_channel')) {
    function members_sanitize_channel($input): ?string
    {
        $u = strtolower(trim((string) $input));
        return preg_match('/^[a-z0-9_]{1,64}$/', $u) ? $u : null;
    }
}

if (!function_exists('members_website_db')) {
    function members_website_db(): mysqli
    {
        require_once '/var/www/config/database.php';
        global $db_servername, $db_username, $db_password;
        $conn = new mysqli($db_servername, $db_username, $db_password, 'website');
        if ($conn->connect_error) {
            json_out(['ok' => false, 'error' => 'Database unavailable.'], 503);
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('members_user_db')) {
    function members_user_db(string $channel): ?mysqli
    {
        require_once '/var/www/config/database.php';
        global $db_servername, $db_username, $db_password;
        $conn = new mysqli($db_servername, $db_username, $db_password, $channel);
        if ($conn->connect_error) {
            return null;
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

if (!function_exists('members_db_exists')) {
    function members_db_exists(mysqli $conn, string $channel): bool
    {
        $escaped = $conn->real_escape_string($channel);
        $result = $conn->query("SHOW DATABASES LIKE '" . $escaped . "'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('members_table_exists')) {
    function members_table_exists(mysqli $db, string $table): bool
    {
        $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
        $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('members_store_csrf')) {
    function members_store_csrf(): string
    {
        members_session_start();
        if (empty($_SESSION['store_csrf'])) {
            $_SESSION['store_csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['store_csrf'];
    }
}

if (!function_exists('members_sanitize_custom_vars')) {
    function members_sanitize_custom_vars($response): string
    {
        $response = (string) $response;
        $switches = ['(customapi.'];
        foreach ($switches as $switch) {
            $pattern = '/' . preg_quote($switch, '/') . '[^)]*\)/';
            $replacement = rtrim($switch, '.') . ')';
            $response = preg_replace($pattern, $replacement, $response) ?? $response;
        }
        $response = preg_replace('/\)\)+/', ')', $response) ?? $response;
        return $response;
    }
}

if (!function_exists('members_resolve_twitch_usernames')) {
    function members_resolve_twitch_usernames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if ($userIds === []) {
            return [];
        }
        require_once '/var/www/config/twitch.php';
        global $clientID;
        $accessToken = (string) ($_SESSION['access_token'] ?? '');
        if ($accessToken === '' || empty($clientID)) {
            return [];
        }
        $map = [];
        foreach (array_chunk($userIds, 100) as $chunk) {
            $qs = implode('&', array_map(static fn($id) => 'id=' . rawurlencode($id), $chunk));
            $ch = curl_init('https://api.twitch.tv/helix/users?' . $qs);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Client-ID: ' . $clientID,
                'Authorization: Bearer ' . $accessToken,
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string) $res, true);
            if (!isset($data['data']) || !is_array($data['data'])) {
                continue;
            }
            foreach ($data['data'] as $u) {
                if (!empty($u['id'])) {
                    $map[(string) $u['id']] = (string) ($u['display_name'] ?? $u['login'] ?? $u['id']);
                }
            }
        }
        return $map;
    }
}
