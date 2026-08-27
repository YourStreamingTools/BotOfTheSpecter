<?php
$spa = dirname(__DIR__) . '/app/index.html';
if (!is_file($spa)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Status UI is not built.\n";
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile($spa);
exit;
