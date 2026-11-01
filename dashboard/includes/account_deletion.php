<?php
// Account deletion requests (Profile page, stable and beta dashboards).
// A request is stored in website.account_deletion_requests and carried out 30 days later by
// /var/www/lib/account_deletion_cron.php unless the user cancels it first.
// Only the signed-in owner can ask: never while an admin or moderator is acting as the channel.

const ACCOUNT_DELETION_DAYS = 30;

if (!function_exists('t')) {
    function t($key, $replacements = []) { return $key; }
}

function account_deletion_json(array $body, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit();
}

function account_deletion_iso(?string $utc): ?string {
    return $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
}

// The signed-in owner's users row, by the stable Twitch id; null while acting as someone else.
function account_deletion_owner(mysqli $conn): ?array {
    if (!empty($_SESSION['admin_act_as_active']) || !empty($_SESSION['mod_act_as_active'])) return null;
    $twitchId = (string) ($_SESSION['twitchUserId'] ?? $_SESSION['twitch_user_id'] ?? '');
    if ($twitchId === '') return null;
    $stmt = $conn->prepare("SELECT id, username, twitch_user_id FROM users WHERE twitch_user_id = ? LIMIT 1");
    $stmt->bind_param('s', $twitchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function account_deletion_pending(mysqli $conn, int $userId): ?array {
    $stmt = $conn->prepare("SELECT id, requested_at, delete_after FROM account_deletion_requests
        WHERE user_id = ? AND cancelled_at IS NULL AND completed_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function account_deletion_status_json(?array $pending): array {
    return [
        'success' => true,
        'pending' => $pending !== null,
        'requested_at' => account_deletion_iso($pending['requested_at'] ?? null),
        'delete_after' => account_deletion_iso($pending['delete_after'] ?? null),
    ];
}

// GET: status. POST action=request|cancel (XHR only). Called by dashboard/account_deletion.php and the beta's api/profile/deletion.php.
function account_deletion_handle(mysqli $conn): void {
    $owner = account_deletion_owner($conn);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$owner) account_deletion_json(['success' => true, 'pending' => false, 'allowed' => false]);
        try {
            account_deletion_json(account_deletion_status_json(account_deletion_pending($conn, (int) $owner['id'])) + ['allowed' => true]);
        } catch (Throwable $e) {
            error_log('account_deletion status: ' . $e->getMessage());
            account_deletion_json(['success' => false, 'error' => t('profile_delete_err')]);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        account_deletion_json(['success' => false, 'error' => t('profile_delete_err')], 400);
    }
    if (!$owner) account_deletion_json(['success' => false, 'error' => t('profile_delete_err_act_as')], 403);
    $userId = (int) $owner['id'];
    $action = (string) ($_POST['action'] ?? '');
    try {
        $pending = account_deletion_pending($conn, $userId);
        if ($action === 'request') {
            if (!$pending) {
                $stmt = $conn->prepare("INSERT INTO account_deletion_requests (user_id, twitch_user_id, username, requested_at, delete_after)
                    VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL " . ACCOUNT_DELETION_DAYS . " DAY)");
                $stmt->bind_param('iss', $userId, $owner['twitch_user_id'], $owner['username']);
                $stmt->execute();
                $stmt->close();
                error_log("account_deletion: requested for user {$userId} ({$owner['username']})");
            }
            account_deletion_json(account_deletion_status_json(account_deletion_pending($conn, $userId)) + ['allowed' => true, 'message' => t('profile_delete_requested')]);
        }
        if ($action === 'cancel') {
            $stmt = $conn->prepare("UPDATE account_deletion_requests SET cancelled_at = UTC_TIMESTAMP()
                WHERE user_id = ? AND cancelled_at IS NULL AND completed_at IS NULL");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            if ($pending) error_log("account_deletion: cancelled for user {$userId} ({$owner['username']})");
            account_deletion_json(account_deletion_status_json(null) + ['allowed' => true, 'message' => t('profile_delete_cancelled')]);
        }
    } catch (Throwable $e) {
        error_log('account_deletion: ' . $e->getMessage());
        account_deletion_json(['success' => false, 'error' => t('profile_delete_err')]);
    }
    account_deletion_json(['success' => false, 'error' => t('profile_delete_err')], 400);
}
