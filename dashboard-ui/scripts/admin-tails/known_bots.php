<?php
// GET: the list the old page rendered.
$known_bots = [];
$result = $conn->query("SELECT id, bot_login, is_active, added_by, notes, created_at FROM known_bots ORDER BY bot_login");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $known_bots[] = $row;
    }
    $result->free();
}
kb_json(true, '', ['bots' => $known_bots, 'username' => $username ?? 'admin']);
