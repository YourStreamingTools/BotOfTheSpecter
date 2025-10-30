<?php
include "../mod_access.php";

// Add language support for todolist layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

if (!isset($scripts)) $scripts = '';
if (!isset($pageTitle)) $pageTitle = "To Do List";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter To Do List - Manage your streaming tasks and objectives.";
if (!isset($pageContent)) $pageContent = "";

// Function to generate a UUID v4 for cache busting
function uuidv4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];
?>
<!DOCTYPE html>
<html lang="en" class="theme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'To Do List'; ?></title>
    <!-- Bulma CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Bulma Switch Extension -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma-switch@2.0.4/dist/css/bulma-switch.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/bulma-responsive-tables.css">
    <link rel="stylesheet" href="../css/custom.css?v=<?php echo uuidv4(); ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body class="page-wrapper">
    <div id="cookieConsentBox" class="box has-background-dark has-text-white" style="display:none; position:fixed; z-index:9999; right:24px; bottom:24px; max-width:360px; width:90vw; box-shadow:0 2px 16px #000a;">
        <div class="mb-3">
            <?php echo t('cookie_consent_help'); ?>
            <br>
            <span>
                <a href="https://botofthespecter.com/privacy-policy.php" target="_blank" class="has-text-link has-text-weight-bold">
                    <?php echo t('privacy_policy'); ?>
                </a>
            </span>
        </div>
        <div class="buttons is-right">
            <button id="cookieAcceptBtn" class="button is-success has-text-weight-bold"><?php echo t('cookie_accept_btn'); ?></button>
            <button id="cookieDeclineBtn" class="button is-danger has-text-weight-bold"><?php echo t('cookie_decline_btn'); ?></button>
        </div>
    </div>
    <!-- Mobile Top Navbar: visible only on mobile devices -->
    <nav class="top-navbar mobile-only" id="mobileTopNavbar" style="position:fixed; top:0; left:0; right:0; z-index:1100; display:flex; align-items:center; padding:0.5rem 0.75rem; background:rgba(20,20,20,0.95);">
        <div style="display:flex; align-items:center; gap:0.5rem; width:100%;">
            <button id="mobileSidebarToggle" class="button is-dark" aria-label="Open navigation" style="min-width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center;">
                <span class="icon"><i class="fas fa-bars"></i></span>
            </button>
            <div style="flex:1; display:flex; align-items:center; justify-content:center;">
                <a href="../dashboard.php" style="color:#fff; font-weight:700; text-decoration:none;">BotOfTheSpecter</a>
            </div>
            <div style="width:44px; height:44px;"></div>
        </div>
    </nav>
    <!-- Mobile Menu (off-canvas panel) -->
    <div id="mobileMenu" class="mobile-menu" aria-hidden="true">
        <div class="mobile-menu-header" style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem; background:#141414;">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="logo" style="width:28px; height:28px;">
                <span style="color:#fff; font-weight:700;">BotOfTheSpecter</span>
            </div>
            <button id="mobileMenuClose" class="button is-dark" aria-label="Close navigation">
                <span class="icon"><i class="fas fa-times"></i></span>
            </button>
        </div>
        <div class="mobile-menu-body" style="padding:0.75rem; overflow-y:auto; max-height:calc(100vh - 56px);">
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item">
                    <a href="index.php" class="sidebar-menu-link">
                        <span class="sidebar-menu-icon"><i class="fas fa-list-check"></i></span>
                        <span class="sidebar-menu-text">View Tasks</span>
                    </a>
                </li>
                <li class="sidebar-menu-item has-submenu">
                    <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                        <span class="sidebar-menu-icon"><i class="fas fa-tasks"></i></span>
                        <span class="sidebar-menu-text">Tasks</span>
                        <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="insert.php" class="sidebar-submenu-link"><span class="sidebar-submenu-icon"><i class="fas fa-plus"></i></span><span class="sidebar-menu-text">Add Task</span></a></li>
                        <li><a href="remove.php" class="sidebar-submenu-link"><span class="sidebar-submenu-icon"><i class="fas fa-trash"></i></span><span class="sidebar-menu-text">Remove Task</span></a></li>
                        <li><a href="update_objective.php" class="sidebar-submenu-link"><span class="sidebar-submenu-icon"><i class="fas fa-edit"></i></span><span class="sidebar-menu-text">Update Task</span></a></li>
                        <li><a href="completed.php" class="sidebar-submenu-link"><span class="sidebar-submenu-icon"><i class="fas fa-check-double"></i></span><span class="sidebar-menu-text">Completed Tasks</span></a></li>
                    </ul>
                </li>
                <li class="sidebar-menu-item has-submenu">
                    <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                        <span class="sidebar-menu-icon"><i class="fas fa-folder"></i></span>
                        <span class="sidebar-menu-text">Categories</span>
                        <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="categories.php" class="sidebar-submenu-link"><span class="sidebar-submenu-icon"><i class="fas fa-folder"></i></span><span class="sidebar-menu-text">View Categories</span></a></li>
                        <li><a href="add_category.php" class="sidebar-submenu-link"><span class="sidebar-submenu-icon"><i class="fas fa-plus-square"></i></span><span class="sidebar-menu-text">Add Category</span></a></li>
                    </ul>
                </li>
                <li class="sidebar-menu-item">
                    <a href="obs_options.php" class="sidebar-menu-link">
                        <span class="sidebar-menu-icon"><i class="fas fa-cog"></i></span>
                        <span class="sidebar-menu-text">OBS Options</span>
                    </a>
                </li>
            </ul>
            <div style="padding-top:0.75rem; border-top:1px solid rgba(255,255,255,0.04); margin-top:0.75rem;">
                <a href="../bot.php" class="sidebar-user-item" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-arrow-left"></i></span>
                    <span class="sidebar-user-text">Back to Bot</span>
                </a>
                <div class="sidebar-version">
                    <span class="tag is-info is-light">v<?php echo $dashboardVersion; ?></span>
                </div>
            </div>
        </div>
    </div>
    <!-- Sidebar Navigation (Desktop Only - Hidden on Mobile/Tablet) -->
    <aside class="sidebar-nav desktop-only" id="sidebarNav">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo">
                <span class="sidebar-brand-text">BotOfTheSpecter</span>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <span class="icon"><i class="fas fa-bars"></i></span>
            </button>
        </div>
        <div class="sidebar-content-wrapper">
        <ul class="sidebar-menu">
            <li class="sidebar-menu-item">
                <a href="index.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-list-check"></i></span>
                    <span class="sidebar-menu-text">View Tasks</span>
                </a>
                <div class="sidebar-tooltip">View Tasks</div>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-tasks"></i></span>
                    <span class="sidebar-menu-text">Tasks</span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip">Tasks</div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="insert.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-plus"></i></span>
                            <span class="sidebar-menu-text">Add Task</span>
                        </a>
                    </li>
                    <li>
                        <a href="remove.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-trash"></i></span>
                            <span class="sidebar-menu-text">Remove Task</span>
                        </a>
                    </li>
                    <li>
                        <a href="update_objective.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-edit"></i></span>
                            <span class="sidebar-menu-text">Update Task</span>
                        </a>
                    </li>
                    <li>
                        <a href="completed.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-check-double"></i></span>
                            <span class="sidebar-menu-text">Completed Tasks</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-folder"></i></span>
                    <span class="sidebar-menu-text">Categories</span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip">Categories</div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="categories.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-folder"></i></span>
                            <span class="sidebar-menu-text">View Categories</span>
                        </a>
                    </li>
                    <li>
                        <a href="add_category.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-plus-square"></i></span>
                            <span class="sidebar-menu-text">Add Category</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item">
                <a href="obs_options.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-cog"></i></span>
                    <span class="sidebar-menu-text">OBS Options</span>
                </a>
                <div class="sidebar-tooltip">OBS Options</div>
            </li>
        </ul>
        <div class="sidebar-user-section">
            <a href="../bot.php" class="sidebar-user-item">
                <span class="sidebar-user-icon"><i class="fas fa-arrow-left"></i></span>
                <span class="sidebar-user-text">Back to Bot</span>
            </a>
            <div class="sidebar-version">
                <span class="tag is-info is-light">v<?php echo $dashboardVersion; ?></span>
            </div>
        </div>
        </div>
    </aside>
    <?php if ($maintenanceMode): $modalAcknowledged = isset($_COOKIE['maintenance_modal_acknowledged']) && $_COOKIE['maintenance_modal_acknowledged'] === 'true'; ?>
    <!-- Maintenance Notice Banner -->
    <div style="background:rgb(255, 165, 0); color: #222; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
        <span style="color:rgb(0, 0, 0);">
            We are currently experiencing an outage with an external provider that our website relies on.<br>
            Some functions and services may not be working as expected. 
            We are actively working with the provider to restore full service as soon as possible.
        </span>
    </div>
    <?php if (!$modalAcknowledged): ?>
    <!-- Maintenance Modal -->
    <div id="maintenanceModal" class="modal is-active">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head has-background-warning">
                <p class="modal-card-title has-text-black"><i class="fas fa-exclamation-triangle"></i> Service Interruption Notice</p>
                <button class="delete" aria-label="close" onclick="closeMaintenanceModal()"></button>
            </header>
            <section class="modal-card-body">
                <div class="content">
                    <h4 class="has-text-weight-bold">Current System Status:</h4>
                    <p>We are currently experiencing an outage with an external provider that our website relies on. This may affect various functions and services across the platform.</p>
                    <h4 class="has-text-weight-bold">What This Means:</h4>
                    <ul>
                        <li>Some features may be temporarily unavailable</li>
                        <li>Response times may be slower than usual</li>
                        <li>Some integrations might not function properly</li>
                    </ul>
                    <h4 class="has-text-weight-bold">What We're Doing:</h4>
                    <p>Our team is actively working with the provider to resolve these issues and restore full service as quickly as possible. We appreciate your patience during this time.</p>
                </div>
            </section>
            <footer class="modal-card-foot">
                <button class="button is-warning" onclick="closeMaintenanceModal()">Acknowledge</button>
            </footer>
        </div>
    </div>
    <?php endif; endif; ?>
    <!-- Main content wrapper -->
    <div class="page-content">
        <div class="columns" style="flex: 1 0 auto;">
            <div class="column is-10 is-offset-1 main-content">
                <section class="section">
                    <div class="container is-fluid">
                        <?php echo $content; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>
<!-- Footer -->
<footer class="footer is-dark has-text-white" style="display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
    <div class="is-hidden-mobile">
        &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
        <?php include '/var/www/config/project-time.php'; ?>
        BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia (ABN 20 447 022 747).<br>
        This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
        All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are the property of their respective owners and are used for identification purposes only.
    </div>
    <div style="max-width: 1500px;" class="is-hidden-tablet">
        &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
        <span class="tag is-info is-light mt-2">Dashboard Version: <?php echo $dashboardVersion; ?></span><br>
        BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia (ABN 20 447 022 747).<br>
        This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
        All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are the property of their respective owners and are used for identification purposes only.
    </div>
</footer>
<!-- JavaScript dependencies -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Custom Uptime Script -->
<script src="https://uptime.botofthespecter.com/en/cca64861/widget/script.js"></script>
<!-- Custom JS -->
<script src="../js/dashboard.js?v=<?php echo uuidv4(); ?>"></script>
<script src="../js/search.js?v=<?php echo uuidv4(); ?>"></script>
<script src="../js/bulmaModals.js?v=<?php echo uuidv4(); ?>"></script>
<script src="../js/sidebar-mobile.js?v=<?php echo uuidv4(); ?>"></script>
<?php echo $scripts; ?>
<?php include_once "../usr_database.php"; ?>
<script>
function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = name + "=" + value + ";expires=" + d.toUTCString() + ";path=/";
}
function getCookie(name) {
    var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
}
// Maintenance Modal Functions
function closeMaintenanceModal() {
    document.getElementById('maintenanceModal').classList.remove('is-active');
    // Set a cookie to expire in exactly 24 hours, only for the modal
    setCookie('maintenance_modal_acknowledged', 'true', 1);
    // Reload the page to update the server-side state
    window.location.reload();
}
function dontShowAgain() {
    const today = new Date().toDateString();
    setCookie('maintenance_notice', today, 1);
    closeMaintenanceModal();
}
// Check if we should show the maintenance modal
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($maintenanceMode && !$modalAcknowledged): ?>
        const lastShown = getCookie('maintenance_notice');
        const today = new Date().toDateString();
        // Show modal only if "don't show again" isn't set
        if (!lastShown) {
            document.getElementById('maintenanceModal').classList.add('is-active');
        }
    <?php else: ?>
        // Clean up maintenance cookies when maintenance mode is disabled
        if (getCookie('maintenance_notice')) {
            document.cookie = 'maintenance_notice=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        }
        if (getCookie('maintenance_acknowledged')) {
            document.cookie = 'maintenance_acknowledged=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        }
    <?php endif; ?>
});
function showCookieConsentBox() {
    document.getElementById('cookieConsentBox').style.display = '';
}
function hideCookieConsentBox() {
    document.getElementById('cookieConsentBox').style.display = 'none';
}
function hasCookieConsent() {
    return getCookie('cookie_consent') === 'accepted';
}
document.addEventListener('DOMContentLoaded', function() {
    var consent = getCookie('cookie_consent');
    if (!consent) {
        showCookieConsentBox();
    }
    document.getElementById('cookieAcceptBtn').onclick = function() {
        setCookie('cookie_consent', 'accepted', 7);
        hideCookieConsentBox();
    };
    document.getElementById('cookieDeclineBtn').onclick = function() {
        setCookie('cookie_consent', 'declined', 14);
        hideCookieConsentBox();
    };
});
</script>
</body>
</html>