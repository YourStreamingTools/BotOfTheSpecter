<?php
require_once __DIR__ . '/includes/session.php';
support_session_start();
require_login();
require __DIR__ . '/includes/spa.php';
