<?php
// "Using Beta Bot? Enables 500 character limit." Lives on website.users.beta_char_limit.
// The column is added by migrations/website/20261011_0001_add_users_beta_char_limit.php.

if (!function_exists('beta_char_limit_save')) {
    function beta_char_limit_save(bool $enabled): bool
    {
        global $conn;
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 || !($conn instanceof mysqli)) {
            return false;
        }
        $flag = $enabled ? 1 : 0;
        try {
            $stmt = $conn->prepare('UPDATE users SET beta_char_limit = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ii', $flag, $userId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('beta_char_limit_save: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('beta_char_limit_handle_request')) {
    function beta_char_limit_handle_request(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if (($_POST['ajax_action'] ?? '') !== 'beta_char_limit') {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = beta_char_limit_save($enabled);
        echo json_encode(['success' => (bool) $ok, 'enabled' => $enabled ? 1 : 0]);
        exit;
    }
}
