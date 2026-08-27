<?php
require_once __DIR__ . '/../includes/session.php';
require_login_json();

$db = support_db();
$wdb = website_db();
$twid = (string) ($_SESSION['twitch_user_id'] ?? '');
$staff = is_staff();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $allPrograms = [];
    if ($staff) {
        $res = $wdb->query('SELECT * FROM beta_programs ORDER BY is_active DESC, name ASC');
    } else {
        $res = $wdb->query('SELECT * FROM beta_programs WHERE is_active = 1 ORDER BY name ASC');
    }
    if ($res) {
        $allPrograms = $res->fetch_all(MYSQLI_ASSOC);
    }

    $userPrograms = [];
    $wstmt = $wdb->prepare('SELECT beta_programs FROM users WHERE twitch_user_id = ? LIMIT 1');
    if ($wstmt) {
        $wstmt->bind_param('s', $twid);
        $wstmt->execute();
        $wstmt->bind_result($rawProgs);
        $wstmt->fetch();
        $wstmt->close();
        $userPrograms = json_decode($rawProgs ?? '[]', true) ?? [];
    }

    $pendingPrograms = [];
    $pstmt = $db->prepare("SELECT meta FROM tickets WHERE twitch_user_id = ? AND category = 'beta_request' AND status IN ('open','in_progress')");
    $pstmt->bind_param('s', $twid);
    $pstmt->execute();
    $prows = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pstmt->close();
    foreach ($prows as $prow) {
        $pm = json_decode($prow['meta'] ?? '{}', true);
        if (!empty($pm['program'])) {
            $pendingPrograms[] = $pm['program'];
        }
    }

    $pendingRequests = [];
    if ($staff) {
        $qres = $db->query(
            "SELECT t.id, t.ticket_number, t.username, t.display_name, t.meta, t.created_at
             FROM tickets t
             WHERE t.category = 'beta_request' AND t.status IN ('open','in_progress')
             ORDER BY t.created_at ASC"
        );
        if ($qres) {
            foreach ($qres->fetch_all(MYSQLI_ASSOC) as $req) {
                $rm = json_decode($req['meta'] ?? '{}', true);
                $pendingRequests[] = [
                    'ticket_number' => $req['ticket_number'],
                    'username' => $req['username'],
                    'display_name' => $req['display_name'],
                    'program' => $rm['program'] ?? '',
                    'program_name' => $rm['program_name'] ?? ($rm['program'] ?? '—'),
                    'created_at' => $req['created_at'],
                ];
            }
        }
    }

    json_out([
        'ok' => true,
        'is_staff' => $staff,
        'programs' => $allPrograms,
        'enrolled' => $userPrograms,
        'pending' => $pendingPrograms,
        'pending_requests' => $pendingRequests,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$body = json_body();
if (!$staff) {
    json_out(['ok' => false, 'error' => 'Insufficient permissions.'], 403);
}
if (!verify_csrf_json()) {
    json_out(['ok' => false, 'error' => 'Security token mismatch. Please try again.'], 400);
}

$action = (string) ($body['_action'] ?? '');

if ($action === 'save_program') {
    $editId = (int) ($body['edit_id'] ?? 0);
    $slug = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($body['slug'] ?? ''))));
    $name = trim((string) ($body['name'] ?? ''));
    $desc = trim((string) ($body['description'] ?? ''));
    $errors = [];
    if ($editId === 0 && strlen($slug) < 2) {
        $errors[] = 'Slug must be at least 2 characters (lowercase letters, numbers, hyphens, underscores).';
    }
    if (strlen($name) < 2) {
        $errors[] = 'Program name is required.';
    }
    if ($errors) {
        json_out(['ok' => false, 'errors' => $errors], 400);
    }
    if ($editId > 0) {
        $stmt = $wdb->prepare('UPDATE beta_programs SET name=?, description=? WHERE id=?');
        $stmt->bind_param('ssi', $name, $desc, $editId);
        $stmt->execute();
        $stmt->close();
        json_out(['ok' => true, 'message' => 'Program updated.']);
    }
    $chk = $wdb->prepare('SELECT id FROM beta_programs WHERE slug=?');
    $chk->bind_param('s', $slug);
    $chk->execute();
    $chk->store_result();
    $exists = $chk->num_rows > 0;
    $chk->close();
    if ($exists) {
        json_out(['ok' => false, 'errors' => ['A program with that slug already exists.']], 400);
    }
    $stmt = $wdb->prepare('INSERT INTO beta_programs (slug, name, description) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $slug, $name, $desc);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => "Program \"{$name}\" created."]);
}

if ($action === 'toggle_program') {
    $pid = (int) ($body['program_id'] ?? 0);
    $stmt = $wdb->prepare('UPDATE beta_programs SET is_active = NOT is_active WHERE id = ?');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $stmt->close();
    json_out(['ok' => true, 'message' => 'Program status updated.']);
}

if ($action === 'delete_program') {
    $pid = (int) ($body['program_id'] ?? 0);
    $stmt = $wdb->prepare('SELECT slug, name FROM beta_programs WHERE id = ?');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        json_out(['ok' => false, 'error' => 'Program not found.'], 404);
    }
    $pend = $db->prepare("SELECT COUNT(*) AS cnt FROM tickets WHERE category='beta_request' AND JSON_EXTRACT(meta,'$.program')=? AND status IN('open','in_progress')");
    $pend->bind_param('s', $row['slug']);
    $pend->execute();
    $cnt = (int) ($pend->get_result()->fetch_assoc()['cnt'] ?? 0);
    $pend->close();
    if ($cnt > 0) {
        json_out(['ok' => false, 'error' => "Cannot delete \"{$row['name']}\": {$cnt} pending request(s) still reference it. Resolve them first."], 400);
    }
    $del = $wdb->prepare('DELETE FROM beta_programs WHERE id = ?');
    $del->bind_param('i', $pid);
    $del->execute();
    $del->close();
    json_out(['ok' => true, 'message' => "Program \"{$row['name']}\" deleted."]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
