<?php
// support/includes/session.php
// Shared session helpers for the support portal

if (!function_exists('support_session_start')) {
    function support_session_start() {
        // Now backed by the shared web_sessions row scoped to .botofthespecter.com,
        // so signing in on home/dashboard auto-authenticates the user here too.
        // The bootstrap include is idempotent — calling this function from
        // multiple includes is safe.
        require_once '/var/www/lib/session_bootstrap.php';
    }
}

if (!function_exists('validate_twitch_token')) {
    function validate_twitch_token(string $access_token): bool {
        $ch = curl_init('https://id.twitch.tv/oauth2/validate');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: OAuth ' . $access_token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($response !== false && $http === 200);
    }
}

if (!function_exists('refresh_twitch_token')) {
    function refresh_twitch_token(string $refresh_token) {
        require_once '/var/www/config/twitch.php';
        $ch = curl_init('https://id.twitch.tv/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $clientID,
            'client_secret' => $clientSecret,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $http !== 200) return false;
        $data = json_decode($response, true);
        if (empty($data['access_token'])) return false;
        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refresh_token,
        ];
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        support_session_start();
        if (empty($_SESSION['access_token'])) {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
            header('Location: /login.php');
            exit;
        }
        // Validate the stored token
        if (!validate_twitch_token($_SESSION['access_token'])) {
            // Try to refresh
            if (!empty($_SESSION['refresh_token'])) {
                $new = refresh_twitch_token($_SESSION['refresh_token']);
                if ($new) {
                    $_SESSION['access_token']  = $new['access_token'];
                    $_SESSION['refresh_token'] = $new['refresh_token'];
                    return; // refreshed successfully
                }
            }
            // Refresh failed — destroy session and redirect
            $_SESSION = [];
            session_destroy();
            header('Location: /login.php?reason=session_expired');
            exit;
        }
    }
}

if (!function_exists('is_staff')) {
    function is_staff(): bool {
        return !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
    }
}

if (!function_exists('support_db')) {
    function support_db(): mysqli {
        require_once '/var/www/config/database.php';
        $conn = new mysqli($db_servername, $db_username, $db_password, 'support_tickets');
        if ($conn->connect_error) {
            error_log('support_db connect error: ' . $conn->connect_error);
            die('Database connection error. Please try again later.');
        }
        return $conn;
    }
}

if (!function_exists('website_db')) {
    function website_db(): mysqli {
        require_once '/var/www/config/database.php';
        $conn = new mysqli($db_servername, $db_username, $db_password, 'website');
        if ($conn->connect_error) {
            error_log('website_db connect error: ' . $conn->connect_error);
            die('Database connection error. Please try again later.');
        }
        return $conn;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        support_session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): bool {
        $token = $_POST['csrf_token'] ?? '';
        return !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
