<?php
// This file serves as a template for all moderator dashboard pages
include_once dirname(__FILE__) . "/../mod_access.php";

// Add language support for moderator layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once dirname(__FILE__) . "/../lang/i18n.php";

$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];

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

if (!isset($pageTitle)) $pageTitle = "BotOfTheSpecter Moderator";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter Moderator Dashboard";
if (!isset($pageContent)) $pageContent = "";

// Determine active menu item based on current URI
$current_file = basename($_SERVER['PHP_SELF']);
$active_menu = '';
if ($current_file == 'index.php') {
    $active_menu = 'dashboard';
} elseif ($current_file == 'commands.php' || $current_file == 'builtin.php' || $current_file == 'manage_custom_commands.php') {
    $active_menu = 'commands';
} elseif ($current_file == 'timed_messages.php') {
    $active_menu = 'timed_messages';
} elseif ($current_file == 'bot_points.php') {
    $active_menu = 'points';
} elseif ($current_file == 'counters.php' || $current_file == 'edit_counters.php') {
    $active_menu = 'counters';
} elseif ($current_file == 'known_users.php') {
    $active_menu = 'users';
} elseif ($current_file == 'sound-alerts.php' || $current_file == 'video-alerts.php' || $current_file == 'walkons.php') {
    $active_menu = 'alerts';
}
?>
<!DOCTYPE html>
<html lang="en" class="dark-theme" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'Moderator - BotOfTheSpecter Dashboard'; ?></title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.0/css/bulma.min.css">
    <!-- Bulma Switch Extension -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma-switch@2.0.4/dist/css/bulma-switch.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
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
                <a href="index.php" style="color:#fff; font-weight:700; text-decoration:none;">Moderator Panel</a>
            </div>
            <div style="width:44px; height:44px;"></div>
        </div>
    </nav>
    <!-- Mobile Menu (off-canvas panel) -->
    <div id="mobileMenu" class="mobile-menu" aria-hidden="true">
        <div class="mobile-menu-header" style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem; background:#141414;">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="logo" style="width:28px; height:28px;">
                <span style="color:#fff; font-weight:700;">Moderator Panel</span>
            </div>
            <button id="mobileMenuClose" class="button is-dark" aria-label="Close navigation">
                <span class="icon"><i class="fas fa-times"></i></span>
            </button>
        </div>
        <div class="mobile-menu-body" style="padding:0.75rem; overflow-y:auto; max-height:calc(100vh - 56px);">
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item">
                    <a href="index.php" class="sidebar-menu-link">
                        <span class="sidebar-menu-icon"><i class="fas fa-shield-alt"></i></span>
                        <span class="sidebar-menu-text">Mod Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-menu-item has-submenu">
                    <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                        <span class="sidebar-menu-icon"><i class="fas fa-terminal"></i></span>
                        <span class="sidebar-menu-text"><?php echo t('navbar_commands'); ?></span>
                        <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="commands.php" class="sidebar-submenu-link"><?php echo t('navbar_view_custom_commands'); ?></a></li>
                        <li><a href="builtin.php" class="sidebar-submenu-link"><?php echo t('navbar_view_builtin_commands'); ?></a></li>
                        <li><a href="manage_custom_commands.php" class="sidebar-submenu-link"><?php echo t('navbar_edit_custom_commands'); ?></a></li>
                    </ul>
                </li>
                <li class="sidebar-menu-item">
                    <a href="timed_messages.php" class="sidebar-menu-link">
                        <span class="sidebar-menu-icon"><i class="fas fa-clock"></i></span>
                        <span class="sidebar-menu-text"><?php echo t('navbar_timed_messages'); ?></span>
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="bot_points.php" class="sidebar-menu-link">
                        <span class="sidebar-menu-icon"><i class="fas fa-coins"></i></span>
                        <span class="sidebar-menu-text"><?php echo t('navbar_points_system'); ?></span>
                    </a>
                </li>
                <li class="sidebar-menu-item has-submenu">
                    <a href="#" role="button" aria-expanded="false" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                        <span class="sidebar-menu-icon"><i class="fas fa-calculator"></i></span>
                        <span class="sidebar-menu-text"><?php echo t('navbar_counters'); ?></span>
                        <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="counters.php" class="sidebar-submenu-link"><?php echo t('navbar_counters'); ?></a></li>
                        <li><a href="edit_counters.php" class="sidebar-submenu-link"><?php echo t('navbar_edit_counters'); ?></a></li>
                    </ul>
                </li>
                <li class="sidebar-menu-item">
                    <a href="known_users.php" class="sidebar-menu-link">
                        <span class="sidebar-menu-icon"><i class="fas fa-users"></i></span>
                        <span class="sidebar-menu-text"><?php echo t('known_users_title'); ?></span>
                    </a>
                </li>
                <li class="sidebar-menu-item has-submenu">
                    <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                        <span class="sidebar-menu-icon"><i class="fas fa-bell"></i></span>
                        <span class="sidebar-menu-text"><?php echo t('navbar_alerts'); ?></span>
                        <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="sound-alerts.php" class="sidebar-submenu-link"><?php echo t('navbar_sound_alerts'); ?></a></li>
                        <li><a href="video-alerts.php" class="sidebar-submenu-link"><?php echo t('navbar_video_alerts'); ?></a></li>
                        <li><a href="walkons.php" class="sidebar-submenu-link"><?php echo t('navbar_walkon_alerts'); ?></a></li>
                    </ul>
                </li>
            </ul>
            <div style="padding-top:0.75rem; border-top:1px solid rgba(255,255,255,0.04); margin-top:0.75rem;">
                <a href="../mod_channels.php" class="sidebar-user-item" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                    <span class="sidebar-user-text"><?php echo t('navbar_mod_for'); ?></span>
                </a>
                <a href="../profile.php" class="sidebar-user-item" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-user"></i></span>
                    <span class="sidebar-user-text"><?php echo t('navbar_profile'); ?></span>
                </a>
                <a href="mod_return_home.php" class="sidebar-user-item" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-home"></i></span>
                    <span class="sidebar-user-text"><?php echo t('navbar_return_home'); ?></span>
                </a>
                <div class="sidebar-version">
                    <span class="tag is-info is-light">Mod v<?php echo $dashboardVersion; ?></span>
                </div>
            </div>
        </div>
    </div>
    <!-- Moderator Notice Banner -->
    <?php
    $modDisplay = isset($_SESSION['editing_display_name']) ? htmlspecialchars($_SESSION['editing_display_name'], ENT_QUOTES, 'UTF-8') : null;
    $modUsername = isset($_SESSION['editing_username']) ? htmlspecialchars($_SESSION['editing_username'], ENT_QUOTES, 'UTF-8') : null;
    ?>
    <div style="background:rgb(0, 123, 255); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px;">
        <?php if ($modDisplay || $modUsername): ?>
            <span>
                You are moderating: <strong><?php echo $modDisplay ? $modDisplay : $modUsername; ?></strong><?php echo ($modDisplay && $modUsername) ? ' (@' . $modUsername . ')' : ''; ?>
            </span>
        <?php else: ?>
            <span>
                You are using the <strong>MODERATOR</strong> dashboard. No channel selected —
                <a href="../mod_channels.php" style="color:#fff; text-decoration:underline;">select a channel to moderate</a>.
            </span>
        <?php endif; ?>
    </div>
    <!-- Sidebar Navigation (Desktop Only - Hidden on Mobile/Tablet) -->
    <aside class="sidebar-nav desktop-only" id="sidebarNav">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo">
                <span class="sidebar-brand-text">Moderator Panel</span>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <span class="icon"><i class="fas fa-bars"></i></span>
            </button>
        </div>
        <div class="sidebar-content-wrapper">
        <ul class="sidebar-menu">
            <li class="sidebar-menu-item">
                <a href="index.php" class="sidebar-menu-link <?php echo $active_menu == 'dashboard' ? 'active' : ''; ?>">
                    <span class="sidebar-menu-icon"><i class="fas fa-shield-alt"></i></span>
                    <span class="sidebar-menu-text">Mod Dashboard</span>
                </a>
                <div class="sidebar-tooltip">Mod Dashboard</div>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link <?php echo $active_menu == 'commands' ? 'active' : ''; ?>" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-terminal"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_commands'); ?></span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_commands'); ?></div>
                <ul class="sidebar-submenu">
                    <li><a href="commands.php" class="sidebar-submenu-link"><?php echo t('navbar_view_custom_commands'); ?></a></li>
                    <li><a href="builtin.php" class="sidebar-submenu-link"><?php echo t('navbar_view_builtin_commands'); ?></a></li>
                    <li><a href="manage_custom_commands.php" class="sidebar-submenu-link"><?php echo t('navbar_edit_custom_commands'); ?></a></li>
                </ul>
            </li>
            <li class="sidebar-menu-item">
                <a href="timed_messages.php" class="sidebar-menu-link <?php echo $active_menu == 'timed_messages' ? 'active' : ''; ?>">
                    <span class="sidebar-menu-icon"><i class="fas fa-clock"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_timed_messages'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_timed_messages'); ?></div>
            </li>
            <li class="sidebar-menu-item">
                <a href="bot_points.php" class="sidebar-menu-link <?php echo $active_menu == 'points' ? 'active' : ''; ?>">
                    <span class="sidebar-menu-icon"><i class="fas fa-coins"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_points_system'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_points_system'); ?></div>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link <?php echo $active_menu == 'counters' ? 'active' : ''; ?>" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-calculator"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_counters'); ?></span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_counters'); ?></div>
                <ul class="sidebar-submenu">
                    <li><a href="counters.php" class="sidebar-submenu-link"><?php echo t('navbar_counters'); ?></a></li>
                    <li><a href="edit_counters.php" class="sidebar-submenu-link"><?php echo t('navbar_edit_counters'); ?></a></li>
                </ul>
            </li>
            <li class="sidebar-menu-item">
                <a href="known_users.php" class="sidebar-menu-link <?php echo $active_menu == 'users' ? 'active' : ''; ?>">
                    <span class="sidebar-menu-icon"><i class="fas fa-users"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('known_users_title'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('known_users_title'); ?></div>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link <?php echo $active_menu == 'alerts' ? 'active' : ''; ?>" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-bell"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_alerts'); ?></span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_alerts'); ?></div>
                <ul class="sidebar-submenu">
                    <li><a href="sound-alerts.php" class="sidebar-submenu-link"><?php echo t('navbar_sound_alerts'); ?></a></li>
                    <li><a href="video-alerts.php" class="sidebar-submenu-link"><?php echo t('navbar_video_alerts'); ?></a></li>
                    <li><a href="walkons.php" class="sidebar-submenu-link"><?php echo t('navbar_walkon_alerts'); ?></a></li>
                </ul>
            </li>
        </ul>
        <div class="sidebar-user-section">
            <a href="../mod_channels.php" class="sidebar-user-item">
                <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                <span class="sidebar-user-text"><?php echo t('navbar_mod_for'); ?></span>
            </a>
            <a href="mod_return_home.php" class="sidebar-user-item">
                <span class="sidebar-user-icon"><i class="fas fa-home"></i></span>
                <span class="sidebar-user-text"><?php echo t('navbar_return_home'); ?></span>
            </a>
            <!-- Version Badge -->
            <div class="sidebar-version">
                <span class="tag is-info is-light">Mod v<?php echo $dashboardVersion; ?></span>
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
            <span class="tag is-info is-light mt-2">Mod Dashboard Version: <?php echo $dashboardVersion; ?></span><br>
            BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia (ABN 20 447 022 747).<br>
            This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
            All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are used for identification purposes only.
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
    <?php if (!empty($scripts)) { echo $scripts; } ?>
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
            // remove the modal acknowledgement cookie (name used elsewhere in this file)
            if (getCookie('maintenance_modal_acknowledged')) {
                document.cookie = 'maintenance_modal_acknowledged=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
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
        // Moderator active menu logic
        if (currentPath.includes('index.php')) {
            // Mod Dashboard
            const dashboardLinks = document.querySelectorAll('a[href="index.php"]');
            dashboardLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('commands.php') || currentPath.includes('builtin.php') || currentPath.includes('manage_custom_commands.php')) {
            // Commands
            const commandLinks = document.querySelectorAll('a[href*="commands.php"], a[href*="builtin.php"], a[href*="manage_custom_commands.php"]');
            commandLinks.forEach(link => {
                if (link.closest('.sidebar-menu-item.has-submenu')) {
                    link.classList.add('active');
                }
                if (link.closest('.navbar-item.has-dropdown')) {
                    link.closest('.navbar-item').classList.add('is-active');
                }
            });
        } else if (currentPath.includes('timed_messages.php')) {
            // Timed Messages
            const timedLinks = document.querySelectorAll('a[href*="timed_messages.php"]');
            timedLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('bot_points.php')) {
            // Bot Points
            const pointsLinks = document.querySelectorAll('a[href*="bot_points.php"]');
            pointsLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('counters.php') || currentPath.includes('edit_counters.php')) {
            // Counters
            const counterLinks = document.querySelectorAll('a[href*="counters.php"], a[href*="edit_counters.php"]');
            counterLinks.forEach(link => {
                if (link.closest('.sidebar-menu-item.has-submenu')) {
                    link.classList.add('active');
                }
                if (link.closest('.navbar-item.has-dropdown')) {
                    link.closest('.navbar-item').classList.add('is-active');
                }
            });
        } else if (currentPath.includes('known_users.php')) {
            // Known Users
            const usersLinks = document.querySelectorAll('a[href*="known_users.php"]');
            usersLinks.forEach(link => {
                if (link.classList.contains('sidebar-menu-link')) link.classList.add('active');
                if (link.classList.contains('navbar-item')) link.classList.add('is-active');
            });
        } else if (currentPath.includes('sound-alerts.php') || currentPath.includes('video-alerts.php') || currentPath.includes('walkons.php')) {
            // Alerts
            const alertLinks = document.querySelectorAll('a[href*="sound-alerts.php"], a[href*="video-alerts.php"], a[href*="walkons.php"]');
            alertLinks.forEach(link => {
                if (link.closest('.sidebar-menu-item.has-submenu')) {
                    link.classList.add('active');
                }
                if (link.closest('.navbar-item.has-dropdown')) {
                    link.closest('.navbar-item').classList.add('is-active');
                }
            });
        }
    });
    </script>
</body>
</html>
