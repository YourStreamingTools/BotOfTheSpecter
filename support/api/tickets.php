<?php
require_once __DIR__ . '/../includes/session.php';
require_login_json();

$db = support_db();
$twid = (string) ($_SESSION['twitch_user_id'] ?? '');
$staff = is_staff();
$registered = is_registered_user();

function ticket_row(array $t): array {
    $meta = json_decode($t['meta'] ?? '{}', true);
    if (!is_array($meta)) {
        $meta = [];
    }
    return [
        'id' => (int) $t['id'],
        'ticket_number' => $t['ticket_number'],
        'twitch_user_id' => $t['twitch_user_id'],
        'username' => $t['username'],
        'display_name' => $t['display_name'],
        'category' => $t['category'],
        'subject' => $t['subject'],
        'status' => $t['status'],
        'priority' => $t['priority'],
        'meta' => $meta,
        'created_at' => $t['created_at'],
        'updated_at' => $t['updated_at'] ?? $t['created_at'],
    ];
}

function beta_programs_active(mysqli $wdb): array {
    $out = [];
    $res = $wdb->query('SELECT slug, name FROM beta_programs WHERE is_active = 1 ORDER BY name ASC');
    if ($res) {
        $out = $res->fetch_all(MYSQLI_ASSOC);
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
    if ($id !== '') {
        if ($staff) {
            $stmt = $db->prepare('SELECT * FROM tickets WHERE ticket_number = ? LIMIT 1');
            $stmt->bind_param('s', $id);
        } else {
            $stmt = $db->prepare('SELECT * FROM tickets WHERE ticket_number = ? AND twitch_user_id = ? LIMIT 1');
            $stmt->bind_param('ss', $id, $twid);
        }
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ticket) {
            json_out(['ok' => false, 'error' => 'Ticket not found or access denied.'], 404);
        }
        $tid = (int) $ticket['id'];
        $rs = $db->prepare('SELECT id, author_twitch_id, author_display_name, is_staff, message, created_at FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC');
        $rs->bind_param('i', $tid);
        $rs->execute();
        $replies = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
        $rs->close();
        json_out([
            'ok' => true,
            'ticket' => ticket_row($ticket),
            'replies' => $replies,
            'is_staff' => $staff,
        ]);
    }

    $queue = isset($_GET['view']) && $_GET['view'] === 'queue';
    if ($queue && !$staff) {
        json_out(['ok' => false, 'error' => 'Insufficient permissions.'], 403);
    }

    $status = (string) ($_GET['status'] ?? 'all');
    $priority = (string) ($_GET['priority'] ?? 'all');
    $validSt = ['open', 'in_progress', 'resolved', 'closed'];
    $validPr = ['low', 'normal', 'high'];

    $sql = 'SELECT * FROM tickets WHERE 1=1';
    $types = '';
    $params = [];
    if (!$queue) {
        $sql .= ' AND twitch_user_id = ?';
        $types .= 's';
        $params[] = $twid;
    }
    if ($status !== 'all' && in_array($status, $validSt, true)) {
        $sql .= ' AND status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($queue && $priority !== 'all' && in_array($priority, $validPr, true)) {
        $sql .= ' AND priority = ?';
        $types .= 's';
        $params[] = $priority;
    }
    $sql .= $queue
        ? ' ORDER BY FIELD(priority,"high","normal","low"), created_at ASC'
        : ' ORDER BY updated_at DESC';

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $wdb = website_db();
    $programs = beta_programs_active($wdb);
    $wdb->close();

    json_out([
        'ok' => true,
        'tickets' => array_map('ticket_row', $rows),
        'queue' => $queue,
        'is_staff' => $staff,
        'is_registered' => $registered,
        'beta_programs' => $programs,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$body = json_body();
if (!verify_csrf_json()) {
    json_out(['ok' => false, 'error' => 'Security token mismatch. Please try again.'], 400);
}

$action = (string) ($body['_action'] ?? '');
$displayName = (string) ($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User');

if ($action === 'new_ticket') {
    if (!$registered) {
        json_out(['ok' => false, 'error' => 'You must have a BotOfTheSpecter account to submit a ticket.'], 403);
    }
    $subject = trim((string) ($body['subject'] ?? ''));
    $category = (string) ($body['category'] ?? 'general');
    $priority = (string) ($body['priority'] ?? 'normal');
    $message = trim((string) ($body['message'] ?? ''));
    $validCats = ['billing', 'technical', 'account', 'general', 'feedback', 'beta_request'];
    $allowedPrios = $staff ? ['low', 'normal', 'high'] : ['low', 'normal'];
    $errors = [];
    $ticketMeta = null;

    if ($category === 'feedback') {
        $subject = 'Feedback from ' . $displayName;
        $priority = 'normal';
    }
    if ($category === 'beta_request') {
        $reqSlug = trim((string) ($body['program_slug'] ?? ''));
        $wdb = website_db();
        $programs = beta_programs_active($wdb);
        $wdb->close();
        $bpMap = [];
        foreach ($programs as $bp) {
            $bpMap[$bp['slug']] = $bp['name'];
        }
        if (!isset($bpMap[$reqSlug])) {
            $errors[] = 'Please select a valid beta program.';
        } else {
            $dupStmt = $db->prepare(
                "SELECT id FROM tickets WHERE twitch_user_id = ? AND category = 'beta_request'
                 AND JSON_EXTRACT(meta, '$.program') = ? AND status IN ('open','in_progress') LIMIT 1"
            );
            $dupStmt->bind_param('ss', $twid, $reqSlug);
            $dupStmt->execute();
            $dupStmt->store_result();
            if ($dupStmt->num_rows > 0) {
                $errors[] = 'You already have a pending request for that beta program.';
            }
            $dupStmt->close();
            $subject = 'Beta Access Request: ' . $bpMap[$reqSlug];
            $priority = 'normal';
            $ticketMeta = json_encode(['program' => $reqSlug, 'program_name' => $bpMap[$reqSlug]]);
        }
    }
    if ($category !== 'feedback' && $category !== 'beta_request' && strlen($subject) < 5) {
        $errors[] = 'Subject must be at least 5 characters.';
    }
    if ($category !== 'feedback' && $category !== 'beta_request' && strlen($subject) > 255) {
        $errors[] = 'Subject must be 255 characters or fewer.';
    }
    if (!in_array($category, $validCats, true)) {
        $errors[] = 'Invalid category.';
    }
    if ($category !== 'feedback' && $category !== 'beta_request' && !in_array($priority, $allowedPrios, true)) {
        $errors[] = 'Invalid priority.';
    }
    if (strlen($message) < 20) {
        $errors[] = 'Message must be at least 20 characters.';
    }
    if (strlen($message) > 5000) {
        $errors[] = 'Message must be 5000 characters or fewer.';
    }
    if ($errors) {
        json_out(['ok' => false, 'errors' => $errors], 400);
    }

    $max = $db->query('SELECT MAX(id) AS max_id FROM tickets');
    $row = $max ? $max->fetch_assoc() : ['max_id' => 0];
    $nextId = ((int) ($row['max_id'] ?? 0)) + 1;
    $tickNum = 'SPT-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
    $uname = (string) ($_SESSION['username'] ?? '');
    $stmt = $db->prepare(
        'INSERT INTO tickets (ticket_number, twitch_user_id, username, display_name, category, subject, status, priority, meta)
         VALUES (?, ?, ?, ?, ?, ?, "open", ?, ?)'
    );
    $stmt->bind_param('ssssssss', $tickNum, $twid, $uname, $displayName, $category, $subject, $priority, $ticketMeta);
    $stmt->execute();
    $newTicketId = $stmt->insert_id;
    $stmt->close();
    $isStaffInt = $staff ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issis', $newTicketId, $twid, $displayName, $isStaffInt, $message);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'ticket_number' => $tickNum]);
}

if ($action === 'reply') {
    $replyTickId = (int) ($body['ticket_id'] ?? 0);
    $message = trim((string) ($body['message'] ?? ''));
    if (strlen($message) < 1) {
        json_out(['ok' => false, 'errors' => ['Reply cannot be empty.']], 400);
    }
    if (strlen($message) > 5000) {
        json_out(['ok' => false, 'errors' => ['Reply must be 5000 characters or fewer.']], 400);
    }
    if ($staff) {
        $tstmt = $db->prepare('SELECT id, ticket_number FROM tickets WHERE id = ?');
        $tstmt->bind_param('i', $replyTickId);
    } else {
        $tstmt = $db->prepare('SELECT id, ticket_number FROM tickets WHERE id = ? AND twitch_user_id = ?');
        $tstmt->bind_param('is', $replyTickId, $twid);
    }
    $tstmt->execute();
    $trow = $tstmt->get_result()->fetch_assoc();
    $tstmt->close();
    if (!$trow) {
        json_out(['ok' => false, 'error' => 'Ticket not found or access denied.'], 404);
    }
    $isStaffInt = $staff ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issis', $replyTickId, $twid, $displayName, $isStaffInt, $message);
    $stmt->execute();
    $stmt->close();
    if (!$staff) {
        $upd = $db->prepare("UPDATE tickets SET status = 'open' WHERE id = ? AND status IN ('resolved','closed')");
        $upd->bind_param('i', $replyTickId);
        $upd->execute();
        $upd->close();
    }
    json_out(['ok' => true, 'ticket_number' => $trow['ticket_number']]);
}

if ($action === 'staff_update') {
    if (!$staff) {
        json_out(['ok' => false, 'error' => 'Insufficient permissions.'], 403);
    }
    $upTickId = (int) ($body['ticket_id'] ?? 0);
    $newStatus = (string) ($body['status'] ?? '');
    $newPrio = (string) ($body['priority'] ?? '');
    $validSt = ['open', 'in_progress', 'resolved', 'closed'];
    $validPr = ['low', 'normal', 'high'];
    $sets = [];
    $types = '';
    $params = [];
    if (in_array($newStatus, $validSt, true)) {
        $sets[] = 'status = ?';
        $types .= 's';
        $params[] = $newStatus;
    }
    if (in_array($newPrio, $validPr, true)) {
        $sets[] = 'priority = ?';
        $types .= 's';
        $params[] = $newPrio;
    }
    if ($sets) {
        $sql = 'UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $upTickId;
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
    json_out(['ok' => true]);
}

if ($action === 'approve_beta' || $action === 'decline_beta') {
    if (!$staff) {
        json_out(['ok' => false, 'error' => 'Insufficient permissions.'], 403);
    }
    $bTicketId = (int) ($body['ticket_id'] ?? 0);
    $catKey = 'beta_request';
    $tstmt = $db->prepare('SELECT id, ticket_number, twitch_user_id, display_name, meta FROM tickets WHERE id = ? AND category = ?');
    $tstmt->bind_param('is', $bTicketId, $catKey);
    $tstmt->execute();
    $trow = $tstmt->get_result()->fetch_assoc();
    $tstmt->close();
    if (!$trow) {
        json_out(['ok' => false, 'error' => 'Ticket not found or is not a beta request.'], 404);
    }
    $bMeta = json_decode($trow['meta'] ?? '{}', true);
    $bSlug = $bMeta['program'] ?? '';
    $bProgName = $bMeta['program_name'] ?? $bSlug;
    $staffName = $displayName;
    if ($action === 'approve_beta' && $bSlug) {
        $wdb = website_db();
        $wstmt = $wdb->prepare('SELECT beta_programs FROM users WHERE twitch_user_id = ? LIMIT 1');
        $wstmt->bind_param('s', $trow['twitch_user_id']);
        $wstmt->execute();
        $wstmt->bind_result($rawProgs);
        $wstmt->fetch();
        $wstmt->close();
        $enrolled = json_decode($rawProgs ?? '[]', true) ?? [];
        if (!in_array($bSlug, $enrolled, true)) {
            $enrolled[] = $bSlug;
        }
        $newJson = json_encode(array_values($enrolled));
        $uw = $wdb->prepare('UPDATE users SET beta_programs = ? WHERE twitch_user_id = ?');
        $uw->bind_param('ss', $newJson, $trow['twitch_user_id']);
        $uw->execute();
        $uw->close();
        $wdb->close();
        $approveMsg = "Your request for access to the \"{$bProgName}\" beta program has been approved! You now have access — head to your dashboard to get started.";
        $rs = $db->prepare('INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message) VALUES (?, ?, ?, 1, ?)');
        $rs->bind_param('isss', $bTicketId, $twid, $staffName, $approveMsg);
        $rs->execute();
        $rs->close();
        $st = $db->prepare("UPDATE tickets SET status = 'resolved' WHERE id = ?");
        $st->bind_param('i', $bTicketId);
        $st->execute();
        $st->close();
    } else {
        $reason = trim((string) ($body['reason'] ?? ''));
        $declineMsg = "Your request for access to the \"{$bProgName}\" beta program has been declined."
            . ($reason ? "\n\nReason: {$reason}" : '');
        $rs = $db->prepare('INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message) VALUES (?, ?, ?, 1, ?)');
        $rs->bind_param('isss', $bTicketId, $twid, $staffName, $declineMsg);
        $rs->execute();
        $rs->close();
        $st = $db->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?");
        $st->bind_param('i', $bTicketId);
        $st->execute();
        $st->close();
    }
    json_out(['ok' => true, 'ticket_number' => $trow['ticket_number']]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
