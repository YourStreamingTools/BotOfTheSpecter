<?php
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json');
echo json_encode(['success' => true, 'users' => $users, 'L' => badm_lang('admin_user_schema_')], JSON_UNESCAPED_UNICODE);
exit;
