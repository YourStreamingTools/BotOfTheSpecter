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
    // "https://..." or "//evil.com" - those would send the browser off-site.
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