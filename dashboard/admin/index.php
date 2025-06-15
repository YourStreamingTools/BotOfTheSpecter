<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_dashboard_title');
ob_start();
?>
<div class="box">
    <h1 class="title is-3"><span class="icon"><i class="fas fa-shield-alt"></i></span> Welcome, Admin!</h1>
    <p class="mb-4">This is the admin dashboard. Use the links below to manage users, view logs, and perform other administrative tasks.</p>
    <div class="buttons">
        <a href="admin_users.php" class="button is-link is-light">
            <span class="icon"><i class="fas fa-users-cog"></i></span>
            <span>User Management</span>
        </a>
        <a href="admin_logs.php" class="button is-info is-light">
            <span class="icon"><i class="fas fa-clipboard-list"></i></span>
            <span>Log Management</span>
        </a>
        <!-- Add more quick links as needed -->
    </div>
</div>
<?php
$content = ob_get_clean();
include "admin_layout.php";
?>