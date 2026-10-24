<?php
// GET: the rows the old page rendered (already fetched above, same query).
foreach ($feedback as &$f) { $f['id'] = (int) $f['id']; $f['is_bug_report'] = (int) $f['is_bug_report']; }
unset($f);
ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'feedback' => $feedback], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
