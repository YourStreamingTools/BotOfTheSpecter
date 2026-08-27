<?php
// roadmap/includes/session.php
// Shared session helpers for the roadmap portal.

if (!function_exists('roadmap_session_start')) {
    function roadmap_session_start(): void {
        require_once '/var/www/lib/session_bootstrap.php';
        roadmap_sync_auth();
    }
}

if (!function_exists('roadmap_sync_auth')) {
    function roadmap_sync_auth(): void {
        if (empty($_SESSION['access_token']) && empty($_SESSION['username'])) {
            $_SESSION['admin'] = false;
            return;
        }
        $_SESSION['admin'] = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
    }
}

if (!function_exists('roadmap_is_logged_in')) {
    function roadmap_is_logged_in(): bool {
        return !empty($_SESSION['access_token']) || !empty($_SESSION['username']);
    }
}

if (!function_exists('roadmap_is_admin')) {
    function roadmap_is_admin(): bool {
        roadmap_sync_auth();
        return !empty($_SESSION['admin']);
    }
}

if (!function_exists('roadmap_init_admin_db')) {
    function roadmap_init_admin_db(): void {
        if (!roadmap_is_admin()) {
            return;
        }
        require_once dirname(__DIR__) . '/admin/database.php';
        initializeRoadmapDatabase();
    }
}

if (!function_exists('roadmap_safe_redirect')) {
    // Only allow local, non-protocol-relative paths (e.g. "/foo"), never
    // "https://..." or "//evil.com" — those would send the browser off-site.
    function roadmap_safe_redirect($path): string {
        $path = (string)$path;
        if (strncmp($path, '/', 1) !== 0 || strncmp($path, '//', 2) === 0) {
            return '/index.php';
        }
        return $path;
    }
}

if (!function_exists('website_db')) {
    function website_db(): mysqli {
        require_once '/var/www/config/database.php';
        global $db_servername, $db_username, $db_password;
        $conn = new mysqli($db_servername, $db_username, $db_password, 'website');
        if ($conn->connect_error) {
            error_log('roadmap website_db connect error: ' . $conn->connect_error);
            die('Database connection error. Please try again later.');
        }
        return $conn;
    }
}

if (!function_exists('roadmap_csrf_token')) {
    function roadmap_csrf_token(): string {
        roadmap_session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_body')) {
    function json_body(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw ?: '', true);
            $cached = is_array($data) ? $data : [];
        } else {
            $cached = $_POST;
        }
        return $cached;
    }
}

if (!function_exists('verify_csrf_json')) {
    function verify_csrf_json(): bool {
        roadmap_session_start();
        $body = json_body();
        $token = (string) ($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('require_login_json')) {
    function require_login_json(): void {
        roadmap_session_start();
        if (!roadmap_is_logged_in()) {
            json_out([
                'ok' => false,
                'session_expired' => true,
                'redirect' => '/login.php',
                'error' => 'Please sign in.',
            ], 401);
        }
    }
}

if (!function_exists('require_admin_json')) {
    function require_admin_json(): void {
        require_login_json();
        if (!roadmap_is_admin()) {
            json_out(['ok' => false, 'error' => 'Admin only.'], 403);
        }
    }
}