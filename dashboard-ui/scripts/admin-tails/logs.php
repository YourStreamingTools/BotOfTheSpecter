<?php
// GET: dropdown data (users, grouped system log types) and the page strings. The log
// reads above answer ?admin_audit_log / ?admin_log_user / ?admin_system_log_type / ?admin_token_log_type.
header('Content-Type: application/json');
echo json_encode(['success' => true, 'users' => $users, 'system_types' => $systemLogTypes, 'L' => badm_lang('admin_logs_')], JSON_UNESCAPED_UNICODE);
exit;
