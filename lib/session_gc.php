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
//   1. web_sessions where last_seen_at < NOW() - 4h
//      (cookie lifetime is 4h; anything older is dead by definition).
//
//   2. web_sessions where access_token IS empty AND last_seen_at older
//      than the StreamersConnect grace window. These are rows created
//      when a user hit *.botofthespecter.com but never finished the
//      auth round-trip (closed the SC tab, blocked cookies, etc.). They
//      pile up over time and have no login data attached.
//
//   3. handoff_tokens where expires_at < NOW() - 1 day. The token TTL
//      is 5 minutes; anything past a day is debris regardless of `used`.
//
// Refuses to run from a webserver — this script is intentionally cron-
// only so it can never be triggered by a stray HTTP request. Outputs
// counts to stdout for cron-mail / log capture, exit code 0 on success.
// ----------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "session_gc.php is cron-only\n";
    exit(1);
}

require_once '/var/www/config/database.php';

// Tunables. Adjust here if you want to be more or less aggressive.
$STALE_SESSION_HOURS         = 4;   // matches session.gc_maxlifetime in the bootstrap
$INCOMPLETE_AUTH_GRACE_MIN   = 30;  // give the SC round-trip plenty of time
$HANDOFF_DEBRIS_DAYS         = 1;   // handoff_tokens are 5min TTL; 1d is conservative

$conn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    fwrite(STDERR, "[session_gc] connect failed: {$conn->connect_error}\n");
    exit(2);
}
$conn->set_charset('utf8mb4');

$started = microtime(true);

// 1. Stale sessions — anything past the cookie lifetime is dead weight.
$stmt = $conn->prepare(
    "DELETE FROM web_sessions
      WHERE last_seen_at < NOW() - INTERVAL ? HOUR"
);
$stmt->bind_param('i', $STALE_SESSION_HOURS);
$stmt->execute();
$staleDeleted = $stmt->affected_rows;
$stmt->close();

// 2. Incomplete-auth rows — session_id assigned, never logged in.
//    Treats both NULL and '' as empty. The grace window keeps in-flight
//    StreamersConnect roundtrips safe.
$stmt = $conn->prepare(
    "DELETE FROM web_sessions
      WHERE (access_token IS NULL OR access_token = '')
        AND last_seen_at < NOW() - INTERVAL ? MINUTE"
);
$stmt->bind_param('i', $INCOMPLETE_AUTH_GRACE_MIN);
$stmt->execute();
$incompleteDeleted = $stmt->affected_rows;
$stmt->close();

// 3. Old handoff_tokens — used or unused, anything past the debris
//    window is no longer interesting (5min TTL on the live ones).
$stmt = $conn->prepare(
    "DELETE FROM handoff_tokens
      WHERE expires_at < NOW() - INTERVAL ? DAY"
);
$stmt->bind_param('i', $HANDOFF_DEBRIS_DAYS);
$stmt->execute();
$handoffDeleted = $stmt->affected_rows;
$stmt->close();

$conn->close();

$elapsedMs = (int)round((microtime(true) - $started) * 1000);
printf(
    "[session_gc] stale=%d incomplete=%d handoff=%d (%dms)\n",
    $staleDeleted,
    $incompleteDeleted,
    $handoffDeleted,
    $elapsedMs
);
exit(0);
