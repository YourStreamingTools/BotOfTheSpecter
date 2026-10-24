<?php
// GET: every program, in the old page's order.
$programs = [];
$res = $conn->query('SELECT id, slug, name, description, is_active, created_at FROM beta_programs ORDER BY is_active DESC, name ASC');
if ($res) $programs = $res->fetch_all(MYSQLI_ASSOC);
foreach ($programs as &$p) { $p['id'] = (int) $p['id']; $p['is_active'] = (int) $p['is_active']; $p['created'] = date('Y-m-d', strtotime($p['created_at'])); }
unset($p);
ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'programs' => $programs]);
exit;
