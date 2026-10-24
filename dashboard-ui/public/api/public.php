<?php
// Public landing info, no auth.
require __DIR__ . '/_session.php';
header('Cache-Control: public, max-age=300');
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'dashboard_version' => BETA_DASHBOARD_VERSION]);
