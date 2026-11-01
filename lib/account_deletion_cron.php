<?php
// /var/www/lib/account_deletion_cron.php
// Carries out account deletion requests from the Profile page once their 30 days are up
// (website.account_deletion_requests, written by /var/www/dashboard/includes/account_deletion.php).
// Run from cron, e.g. hourly:
//
//   0 * * * *  /usr/bin/php /var/www/lib/account_deletion_cron.php
//
// For each due request whose user still matches (same users.id and twitch_user_id):
//   1. drops the user's databases (<username> and <username>_custom_modules), as admin/users.php does
//   2. deletes the user's rows from every website table with a user_id or twitch_user_id column
//      (tokens, Discord links, VOD settings, sessions, ...) and from the username-keyed camera tables
//   3. deletes the users row (Cloud Studio rows follow through their ON DELETE CASCADE keys)
//   4. marks the request completed
// Recordings on the stream servers and S3 are not touched.
//
// Flags:
//   --dry-run   Show what would be deleted, don't delete anything.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "account_deletion_cron.php is cron-only\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once '/var/www/config/database.php';

$conn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    fwrite(STDERR, "[account_deletion] connect failed: {$conn->connect_error}\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

// Kept: the request ledger itself, the migration ledger and the admin audit trail.
$KEEP_TABLES = ['users', 'account_deletion_requests', 'schema_migrations', 'admin_audit_log'];
$RESERVED_DBS = ['website', 'mysql', 'information_schema', 'performance_schema', 'sys'];

$due = $conn->query("SELECT id, user_id, twitch_user_id, username FROM account_deletion_requests
    WHERE cancelled_at IS NULL AND completed_at IS NULL AND delete_after <= UTC_TIMESTAMP() ORDER BY id");
if (!$due) {
    fwrite(STDERR, "[account_deletion] query failed: {$conn->error}\n");
    exit(1);
}

$userTables = [];
$res = $conn->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'website' AND COLUMN_NAME IN ('user_id', 'twitch_user_id')");
while ($row = $res->fetch_assoc()) {
    if (!in_array($row['TABLE_NAME'], $KEEP_TABLES, true)) $userTables[] = [$row['TABLE_NAME'], $row['COLUMN_NAME']];
}
// Tables keyed by username only (phone camera pairing).
foreach (['camera_devices', 'camera_pair_codes'] as $table) {
    $res = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'website' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'username'");
    if ($res && $res->num_rows) $userTables[] = [$table, 'username'];
}

$done = 0;
while ($req = $due->fetch_assoc()) {
    $reqId = (int) $req['id'];
    $userId = (int) $req['user_id'];
    $twitchId = (string) $req['twitch_user_id'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND twitch_user_id = ? LIMIT 1");
    $stmt->bind_param('is', $userId, $twitchId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        // Already gone (deleted by an admin): nothing left to do.
        echo "[account_deletion] request {$reqId}: user {$userId} no longer exists, marking completed\n";
        if (!$dryRun) $conn->query("UPDATE account_deletion_requests SET completed_at = UTC_TIMESTAMP() WHERE id = {$reqId}");
        continue;
    }
    $username = (string) $user['username'];
    if (!preg_match('/^[A-Za-z0-9_]{1,25}$/', $username) || in_array(strtolower($username), $RESERVED_DBS, true)) {
        fwrite(STDERR, "[account_deletion] request {$reqId}: unsafe username '{$username}', skipped\n");
        continue;
    }
    echo "[account_deletion] request {$reqId}: deleting user {$userId} ({$username})" . ($dryRun ? ' [dry run]' : '') . "\n";
    try {
        foreach ([$username, strtolower($username) . '_custom_modules'] as $db) {
            echo "  drop database {$db}\n";
            if (!$dryRun && !$conn->query("DROP DATABASE IF EXISTS `{$db}`")) throw new Exception("drop {$db}: {$conn->error}");
        }
        foreach ($userTables as [$table, $col]) {
            $value = $col === 'user_id' ? (string) $userId : ($col === 'username' ? $username : $twitchId);
            if ($dryRun) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM `{$table}` WHERE `{$col}` = ?");
                $stmt->bind_param('s', $value);
                $stmt->execute();
                $n = (int) $stmt->get_result()->fetch_assoc()['n'];
                $stmt->close();
                if ($n) echo "  {$table}: {$n} row(s) by {$col}\n";
                continue;
            }
            $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE `{$col}` = ?");
            $stmt->bind_param('s', $value);
            if (!$stmt->execute()) throw new Exception("{$table}: {$stmt->error}");
            if ($stmt->affected_rows > 0) echo "  {$table}: {$stmt->affected_rows} row(s) by {$col}\n";
            $stmt->close();
        }
        if (!$dryRun) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) throw new Exception("users: {$stmt->error}");
            $stmt->close();
            $conn->query("UPDATE account_deletion_requests SET completed_at = UTC_TIMESTAMP() WHERE id = {$reqId}");
        }
        $done++;
    } catch (Throwable $e) {
        // Left pending, so the next run tries again.
        fwrite(STDERR, "[account_deletion] request {$reqId}: failed: {$e->getMessage()}\n");
    }
}
echo "[account_deletion] " . ($dryRun ? 'would delete' : 'deleted') . " {$done} account(s)\n";
$conn->close();
