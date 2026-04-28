<?php
// /var/www/lib/session_gc.php
// ----------------------------------------------------------------
// Garbage-collects stale rows from website.web_sessions and
// website.handoff_tokens. Designed to be run from cron, e.g.:
//
//   */15 * * * *  /usr/bin/php /var/www/lib/session_gc.php
//
// Three sweeps, in order:
//
//   1. web_sessions where last_seen_at < NOW() - 4h, OR last_seen_at
//      IS NULL. NULL is treated as "ancient" — it shows up on rows
//      whose schema doesn't default last_seen_at to CURRENT_TIMESTAMP
//      and which haven't yet been touched by the ON DUPLICATE KEY
//      UPDATE branch in the session handler.
//
//   2. web_sessions where access_token IS NULL or empty AND the row
//      is older than the StreamersConnect grace window (or NULL —
//      same logic). These are sessions where the user got a cookie
//      but never finished auth. They pile up over time.
//
//   3. handoff_tokens where expires_at < NOW() - 1 day. The live TTL
//      is 5 minutes; anything past a day is debris regardless of `used`.
//
// Refuses to run from a webserver — cron-only by design. Outputs
// before/after counts to stdout for cron-mail / log capture.
//
// Flags:
//   --dry-run   Show what would be deleted, don't actually delete.
// ----------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "session_gc.php is cron-only\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once '/var/www/config/database.php';

// Tunables. Adjust here if you want to be more or less aggressive.
$STALE_SESSION_HOURS       = 4;   // matches session.gc_maxlifetime in the bootstrap
$INCOMPLETE_AUTH_GRACE_MIN = 30;  // give the SC round-trip plenty of time
$HANDOFF_DEBRIS_DAYS       = 1;   // handoff_tokens are 5min TTL; 1d is conservative

$conn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    fwrite(STDERR, "[session_gc] connect failed: {$conn->connect_error}\n");
    exit(2);
}
$conn->set_charset('utf8mb4');

$started = microtime(true);

// ----------------------------------------------------------------
// Pre-flight: count what we'd hit. Useful when the script reports 0
// deletions and you want to know whether that's because there really
// is nothing to clean, or because the predicates miss real rows.
// ----------------------------------------------------------------
$totalSessions = (int)$conn->query("SELECT COUNT(*) FROM web_sessions")->fetch_row()[0];
$nullLastSeen  = (int)$conn->query("SELECT COUNT(*) FROM web_sessions WHERE last_seen_at IS NULL")->fetch_row()[0];
$emptyToken    = (int)$conn->query("SELECT COUNT(*) FROM web_sessions WHERE access_token IS NULL OR access_token = ''")->fetch_row()[0];
$totalHandoffs = (int)$conn->query("SELECT COUNT(*) FROM handoff_tokens")->fetch_row()[0];

printf(
    "[session_gc] pre  total=%d null_last_seen=%d empty_token=%d handoff_total=%d\n",
    $totalSessions, $nullLastSeen, $emptyToken, $totalHandoffs
);

// ----------------------------------------------------------------
// Sweep 1 — stale by lifetime.
// NULL last_seen_at is treated as ancient.
// ----------------------------------------------------------------
$sql1 = "DELETE FROM web_sessions
          WHERE last_seen_at IS NULL
             OR last_seen_at < NOW() - INTERVAL ? HOUR";

// ----------------------------------------------------------------
// Sweep 2 — never-finished-auth rows. access_token blank AND row is
// older than the SC grace window (or has no last_seen_at at all).
// ----------------------------------------------------------------
$sql2 = "DELETE FROM web_sessions
          WHERE (access_token IS NULL OR access_token = '')
            AND (last_seen_at IS NULL
                 OR last_seen_at < NOW() - INTERVAL ? MINUTE)";

// ----------------------------------------------------------------
// Sweep 3 — old handoff_tokens.
// ----------------------------------------------------------------
$sql3 = "DELETE FROM handoff_tokens
          WHERE expires_at < NOW() - INTERVAL ? DAY";

if ($dryRun) {
    // Run them as SELECT COUNTs so we report what *would* go.
    $countStale = (int)$conn->query(
        "SELECT COUNT(*) FROM web_sessions
          WHERE last_seen_at IS NULL
             OR last_seen_at < NOW() - INTERVAL {$STALE_SESSION_HOURS} HOUR"
    )->fetch_row()[0];
    $countIncomplete = (int)$conn->query(
        "SELECT COUNT(*) FROM web_sessions
          WHERE (access_token IS NULL OR access_token = '')
            AND (last_seen_at IS NULL
                 OR last_seen_at < NOW() - INTERVAL {$INCOMPLETE_AUTH_GRACE_MIN} MINUTE)"
    )->fetch_row()[0];
    $countHandoff = (int)$conn->query(
        "SELECT COUNT(*) FROM handoff_tokens
          WHERE expires_at < NOW() - INTERVAL {$HANDOFF_DEBRIS_DAYS} DAY"
    )->fetch_row()[0];
    printf(
        "[session_gc] DRY-RUN would delete: stale=%d incomplete=%d handoff=%d\n",
        $countStale, $countIncomplete, $countHandoff
    );
    $conn->close();
    exit(0);
}

$stmt = $conn->prepare($sql1);
$stmt->bind_param('i', $STALE_SESSION_HOURS);
$stmt->execute();
$staleDeleted = $stmt->affected_rows;
$stmt->close();

$stmt = $conn->prepare($sql2);
$stmt->bind_param('i', $INCOMPLETE_AUTH_GRACE_MIN);
$stmt->execute();
$incompleteDeleted = $stmt->affected_rows;
$stmt->close();

$stmt = $conn->prepare($sql3);
$stmt->bind_param('i', $HANDOFF_DEBRIS_DAYS);
$stmt->execute();
$handoffDeleted = $stmt->affected_rows;
$stmt->close();

$conn->close();

$elapsedMs = (int)round((microtime(true) - $started) * 1000);
printf(
    "[session_gc] post deleted: stale=%d incomplete=%d handoff=%d (%dms)\n",
    $staleDeleted, $incompleteDeleted, $handoffDeleted, $elapsedMs
);
exit(0);
