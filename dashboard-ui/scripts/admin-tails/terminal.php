<?php
// GET: the CSRF token the stream endpoint expects, plus the page strings. Super admins only (checked above).
header('Content-Type: application/json');
echo json_encode(['success' => true, 'csrf' => $terminalCsrf, 'L' => badm_lang('admin_terminal_')], JSON_UNESCAPED_UNICODE);
exit;
