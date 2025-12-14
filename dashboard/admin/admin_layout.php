<?php
require_once "/var/www/config/db_connect.php";

// Add language support for admin layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];

// Function to generate a UUID v4 for cache busting
function uuidv4() {
    return bin2hex(random_bytes(4));
}

if (!isset($pageTitle)) $pageTitle = "BotOfTheSpecter Admin";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter Admin Dashboard";
if (!isset($pageContent)) $pageContent = "";

// Database query to check if user is admin
function isAdmin() {
    global $conn;
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($is_admin);
    $result = $stmt->fetch();
    $stmt->close();
    return $result && $is_admin == 1;
}

// Show access denied message instead of redirecting
if (!function_exists('isAdmin') || !isAdmin()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>BotOfTheSpecter - Access Denied</title>
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
        <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <!-- Custom CSS -->
        <link rel="stylesheet" href="../css/custom.css?v=<?php echo uuidv4(); ?>">
        <link rel="stylesheet" href="admin.css?v=<?php echo uuidv4(); ?>">
    </head>
    <body>
        <div style="background:rgb(0, 0, 0); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px;">
            <span>
                <strong>ADMIN DASHBOARD</strong> &mdash; Restricted Access
            </span>
        </div>
        <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
            <div class="navbar-brand">
                <a class="navbar-item" href="/">
                    <img src="https://cdn.botofthespecter.com/logo.png" width="28" height="28" alt="BotOfTheSpecter Logo">
                    <strong class="ml-2">BotOfTheSpecter</strong>
                </a>
            </div>
            <div class="navbar-menu">
                <div class="navbar-start">
                    <a class="navbar-item" href="../bot.php">
                        <span class="icon"><i class="fas fa-arrow-left"></i></span>
                        <span>Back to Dashboard</span>
                    </a>
                </div>
                <div class="navbar-end">
                    <div class="navbar-item has-dropdown is-hoverable">
                        <a class="navbar-link">
                            <span><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'User'; ?></span>
                        </a>
                        <div class="navbar-dropdown is-right">
                            <a class="navbar-item" href="../logout.php">
                                <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                                <span>Log Out</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
        <section class="section">
            <div class="container">
                <div class="notification is-danger has-text-centered">
                    <h1 class="title is-3">Access Denied</h1>
                    <p>You do not have permission to access this page.</p>
                </div>
            </div>
        </section>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($scripts)) $scripts = '';
ob_start();

// Determine active menu item based on current URI
$current_file = basename($_SERVER['PHP_SELF']);
$current_uri = $_SERVER['REQUEST_URI'] ?? '';
$active_menu = '';
if ($current_file == 'index.php' || $current_uri == '/admin') {
    $active_menu = 'dashboard';
} elseif ($current_file == 'users.php') {
    $active_menu = 'users';
} elseif ($current_file == 'logs.php') {
    $active_menu = 'logs';
} elseif ($current_file == 'feedback.php') {
    $active_menu = 'feedback';
} elseif ($current_file == 'twitch_tokens.php') {
    $active_menu = 'twitch';
} elseif ($current_file == 'discordbot_overview.php') {
    $active_menu = 'discord';
} elseif ($current_file == 'websocket_clients.php') {
    $active_menu = 'websocket';
} elseif ($current_file == 'terminal.php') {
    $active_menu = 'terminal';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Admin - <?php echo isset($pageTitle) ? $pageTitle : 'Admin Dashboard'; ?></title>
    <!-- Bulma CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Bulma Switch Extension -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma-switch@2.0.4/dist/css/bulma-switch.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/custom.css?v=<?php echo uuidv4(); ?>">
    <link rel="stylesheet" href="admin.css?v=<?php echo uuidv4(); ?>">
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
                    Privacy Policy
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
                <a href="/admin" style="color:#fff; font-weight:700; text-decoration:none;">Admin Panel</a>
            </div>
            <div style="width:44px; height:44px;"></div>
        </div>
    </nav>
    <!-- Mobile Menu (off-canvas panel) -->
    <div id="mobileMenu" class="mobile-menu" aria-hidden="true">
        <div class="mobile-menu-header" style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem; background:#141414;">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="logo" style="width:28px; height:28px;">
                <span style="color:#fff; font-weight:700;">Admin Panel</span>
            </div>
            <button id="mobileMenuClose" class="button is-dark" aria-label="Close navigation">
                <span class="icon"><i class="fas fa-times"></i></span>
            </button>
        </div>
        <div class="mobile-menu-body" style="padding:0.75rem; overflow-y:auto; max-height:calc(100vh - 56px);">
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item">
                    <a href="/admin" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span class="sidebar-menu-text">Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="users.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fas fa-users-cog"></i></span>
                        <span class="sidebar-menu-text">User Management</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="logs.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fas fa-clipboard-list"></i></span>
                        <span class="sidebar-menu-text">Log Management</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="feedback.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fas fa-comments"></i></span>
                        <span class="sidebar-menu-text">Feedback</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="twitch_tokens.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fab fa-twitch"></i></span>
                        <span class="sidebar-menu-text">Twitch Tokens</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="discordbot_overview.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fab fa-discord"></i></span>
                        <span class="sidebar-menu-text">Discord Bot Overview</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="websocket_clients.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fas fa-plug"></i></span>
                        <span class="sidebar-menu-text">Websocket Clients</span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="terminal.php" class="sidebar-menu-link">
                        <span class="icon sidebar-menu-icon"><i class="fas fa-terminal"></i></span>
                        <span class="sidebar-menu-text">Web Terminal</span>
                    </a>
                </li>
            </ul>
            <div style="padding-top:0.75rem; border-top:1px solid rgba(255,255,255,0.04); margin-top:0.75rem;">
                <a href="../bot.php" class="sidebar-user-item" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-home"></i></span>
                    <span class="sidebar-menu-text">Back to Bot</span>
                </a>
            </div>
        </div>
    </div>
    <!-- Admin Banner -->
    <div style="background:rgb(0, 0, 0); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px;">
        <span>
            <strong>ADMIN DASHBOARD</strong> &mdash; Restricted Access
        </span>
    </div>
    <!-- Sidebar Navigation (Desktop Only - Hidden on Mobile/Tablet) -->
    <aside class="sidebar-nav desktop-only" id="sidebarNav">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo">
                <span class="sidebar-brand-text">Admin Panel</span>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <span class="icon"><i class="fas fa-bars"></i></span>
            </button>
        </div>
        <div class="sidebar-content-wrapper">
        <ul class="sidebar-menu">
            <li class="sidebar-menu-item">
                <a href="/admin" class="sidebar-menu-link <?php echo $active_menu == 'dashboard' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="sidebar-menu-text">Dashboard</span>
                </a>
                <div class="sidebar-tooltip">Dashboard</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="users.php" class="sidebar-menu-link <?php echo $active_menu == 'users' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="sidebar-menu-text">User Management</span>
                </a>
                <div class="sidebar-tooltip">User Management</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="logs.php" class="sidebar-menu-link <?php echo $active_menu == 'logs' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-clipboard-list"></i></span>
                    <span class="sidebar-menu-text">Log Management</span>
                </a>
                <div class="sidebar-tooltip">Log Management</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="feedback.php" class="sidebar-menu-link <?php echo $active_menu == 'feedback' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-comments"></i></span>
                    <span class="sidebar-menu-text">Feedback</span>
                </a>
                <div class="sidebar-tooltip">User Feedback</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="twitch_tokens.php" class="sidebar-menu-link <?php echo $active_menu == 'twitch' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fab fa-twitch"></i></span>
                    <span class="sidebar-menu-text">Twitch Tokens</span>
                </a>
                <div class="sidebar-tooltip">Twitch Tokens</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="discord_tracking.php" class="sidebar-menu-link <?php echo $active_menu == 'discord' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fab fa-discord"></i></span>
                    <span class="sidebar-menu-text">Discord Tracking</span>
                </a>
                <div class="sidebar-tooltip">Discord Tracking</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="websocket_clients.php" class="sidebar-menu-link <?php echo $active_menu == 'websocket' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-plug"></i></span>
                    <span class="sidebar-menu-text">Websocket Clients</span>
                </a>
                <div class="sidebar-tooltip">Websocket Clients</div>
            </li>
            <li class="sidebar-menu-item">
                <a href="terminal.php" class="sidebar-menu-link <?php echo $active_menu == 'terminal' ? 'active' : ''; ?>">
                    <span class="icon sidebar-menu-icon"><i class="fas fa-terminal"></i></span>
                    <span class="sidebar-menu-text">Web Terminal</span>
                </a>
                <div class="sidebar-tooltip">Web Terminal</div>
            </li>
        </ul>
        <div class="sidebar-user-section">
            <a href="../bot.php" class="sidebar-user-item">
                <span class="icon sidebar-menu-icon"><i class="fas fa-home"></i></span>
                <span class="sidebar-menu-text">Back to Bot</span>
            </a>
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
                <p class="modal-card-title">Scheduled Maintenance</p>
                <button class="delete" aria-label="close" onclick="closeMaintenanceModal()"></button>
            </header>
            <section class="modal-card-body">
                <p>We are currently performing scheduled maintenance on our systems.</p>
                <p>During this time, some features may be unavailable or unstable.</p>
                <p>We apologize for any inconvenience this may cause.</p>
                <p>Please check back later or contact support if you need assistance.</p>
            </section>
            <footer class="modal-card-foot">
                <button class="button is-warning" onclick="dontShowAgain()">Don't Show Again</button>
                <button class="button" onclick="closeMaintenanceModal()">Close</button>
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
            All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are used for identification purposes only.
        </div>
        <div style="max-width: 1500px;" class="is-hidden-tablet">
            &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            <span class="tag is-info is-light mt-2">Admin Dashboard Version: <?php echo $dashboardVersion; ?></span><br>
            BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia (ABN 20 447 022 747).<br>
            This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
            All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are used for identification purposes only.
        </div>
        </div>
    </footer>
    <!-- JavaScript dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Custom Update Script -->
    <script src="https://uptime.botofthespecter.com/en/cca64861/widget/script.js"></script>
    <!-- Custom JS -->
    <script src="../js/dashboard.js"></script>
    <script src="../js/search.js"></script>
    <script src="../js/bulmaModals.js"></script>
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
    // Set active menu based on current URL
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        // Remove existing active classes
        document.querySelectorAll('.sidebar-menu-link.active').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.navbar-item.is-active').forEach(el => el.classList.remove('is-active'));
        // Admin active menu logic
        if (currentPath.includes('/admin') && !currentPath.includes('users.php') && !currentPath.includes('logs.php') && !currentPath.includes('feedback.php') && !currentPath.includes('twitch_tokens.php') && !currentPath.includes('discord_tracking.php') && !currentPath.includes('websocket_clients.php') && !currentPath.includes('terminal.php')) {
            // Dashboard
            const dashboardLinks = document.querySelectorAll('a[href="/admin"]');
            dashboardLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('users.php')) {
            // User Management
            const userLinks = document.querySelectorAll('a[href*="users.php"]');
            userLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('logs.php')) {
            // Log Management
            const logLinks = document.querySelectorAll('a[href*="logs.php"]');
            logLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('feedback.php')) {
            // Feedback
            const feedbackLinks = document.querySelectorAll('a[href*="feedback.php"]');
            feedbackLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('twitch_tokens.php')) {
            // Twitch Tokens
            const twitchLinks = document.querySelectorAll('a[href*="twitch_tokens.php"]');
            twitchLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('discord_tracking.php')) {
            // Discord Tracking
            const discordLinks = document.querySelectorAll('a[href*="discord_tracking.php"]');
            discordLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('websocket_clients.php')) {
            // Websocket Clients
            const websocketLinks = document.querySelectorAll('a[href*="websocket_clients.php"]');
            websocketLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('terminal.php')) {
            // Web Terminal
            const terminalLinks = document.querySelectorAll('a[href*="terminal.php"]');
            terminalLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        }
    });
    </script>
</body>
</html>
