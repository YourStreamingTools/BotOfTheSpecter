<?php
header('Content-Type: application/json');
session_start();

echo json_encode([
    'logged_in' => isset($_SESSION['username']),
    'username' => $_SESSION['username'] ?? null,
    'admin' => isset($_SESSION['admin']) && $_SESSION['admin']
]);
?>