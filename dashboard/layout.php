<?php
// This file serves as a template for all dashboard pages
include_once __DIR__ . '/mod_access.php';

if (!isset($pageTitle))
    $pageTitle = "BotOfTheSpecter";
if (!isset($pageDescription))
    $pageDescription = "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.";
if (!isset($pageContent))
    $pageContent = "";
if (!isset($scripts))
    $scripts = "";

// Add language support for layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$profileUsername = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : (isset($user['username']) ? htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') : '');
$profileNavLabel = t('navbar_profile') . ' | ' . $profileUsername;
// default layout mode (pages may override by setting $layoutMode before including layout.php)
// If not set, infer from the request URI path segments: /admin, /moderator, /todolist -> respective modes; otherwise 'default'
if (!isset($layoutMode)) {
    $layoutMode = 'default';
    if (isset($_SERVER['REQUEST_URI'])) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = strtolower($path);
        $norm = rtrim($path, '/');
        // Detect role by path segment anywhere in the request path (robust for nested locations)
        if ($norm !== '') {
            if (strpos($norm, '/admin') !== false) {
                $layoutMode = 'admin';
            } elseif (strpos($norm, '/moderator') !== false) {
                $layoutMode = 'moderator';
            } elseif (strpos($norm, '/todolist') !== false) {
                $layoutMode = 'todolist';
            }
        }
    }
}
// brand text/href vary by layout mode
switch ($layoutMode) {
    case 'admin':
        $brandText = 'Admin Panel';
        $brandHref = 'index.php';
        break;
    case 'moderator':
        $brandText = 'Moderator Panel';
        $brandHref = 'index.php';
        break;
    case 'todolist':
        $brandText = 'To Do List';
        $brandHref = 'index.php';
        break;
    default:
        $brandText = 'BotOfTheSpecter';
        $brandHref = 'dashboard.php';
}
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];

// Check if dev stream is online
$devStreamOnline = false;
try {
    include '/var/www/config/admin_actions.php';
    if (!empty($admin_key)) {
        $apiUrl = "https://api.botofthespecter.com/streamonline?api_key=" . urlencode($admin_key) . "&channel=gfaundead";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['online']) && $data['online'] === true) {
                $devStreamOnline = true;
            }
        }
    }
} catch (Exception $e) {
    // Silently fail - don't display errors to end users
}

// Function to generate a UUID v4 for cache busting
function uuidv4()
{
    return bin2hex(random_bytes(4));
}
?>
<!DOCTYPE html>
<html lang="en" class="dark-theme" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Bulma Switch Extension -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma-switch@2.0.4/dist/css/bulma-switch.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/css/bulma-responsive-tables.css">
    <link rel="stylesheet" href="/css/custom.css?v=<?php echo uuidv4(); ?>">
    <?php if (isset($layoutMode) && $layoutMode === 'admin'): ?>
        <link rel="stylesheet" href="/css/admin.css?v=<?php echo uuidv4(); ?>">
    <?php endif; ?>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body class="page-wrapper<?php echo (isset($layoutMode) && $layoutMode === 'admin') ? ' admin-mode' : ''; ?>">
    <!-- LAYOUT DEBUG: layoutMode=<?php echo htmlspecialchars($layoutMode, ENT_QUOTES); ?> request_uri=<?php echo isset($_SERVER['REQUEST_URI']) ? htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) : 'N/A'; ?> -->
    <div id="cookieConsentBox" class="box has-background-dark has-text-white"
        style="display:none; position:fixed; z-index:9999; right:24px; bottom:24px; max-width:360px; width:90vw; box-shadow:0 2px 16px #000a;">
        <div class="mb-3">
            <?php echo t('cookie_consent_help'); ?>
            <br>
            <span>
                <a href="https://botofthespecter.com/privacy-policy.php" target="_blank"
                    class="has-text-link has-text-weight-bold">
                    <?php echo t('privacy_policy'); ?>
                </a>
            </span>
        </div>
        <div class="buttons is-right">
            <button id="cookieAcceptBtn"
                class="button is-success has-text-weight-bold"><?php echo t('cookie_accept_btn'); ?></button>
            <button id="cookieDeclineBtn"
                class="button is-danger has-text-weight-bold"><?php echo t('cookie_decline_btn'); ?></button>
        </div>
    </div>
    <!-- Mobile Top Navbar: visible only on mobile devices -->
    <nav class="top-navbar mobile-only" id="mobileTopNavbar"
        style="position:fixed; top:0; left:0; right:0; z-index:1100; display:flex; align-items:center; padding:0.5rem 0.75rem; background:rgba(20,20,20,0.95);">
        <div style="display:flex; align-items:center; gap:0.5rem; width:100%;">
            <button id="mobileSidebarToggle" class="button is-dark" aria-label="Open navigation"
                style="min-width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center;">
                <span class="icon"><i class="fas fa-bars"></i></span>
            </button>
            <div style="flex:1; display:flex; align-items:center; justify-content:center;">
                <a href="<?php echo $brandHref; ?>" style="color:#fff; font-weight:700; text-decoration:none;"><?php echo $brandText; ?></a>
            </div>
            <div style="width:44px; height:44px;"></div>
        </div>
    </nav>
    <!-- Mobile Menu (off-canvas panel) -->
    <div id="mobileMenu" class="mobile-menu" aria-hidden="true">
        <div class="mobile-menu-header"
            style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem; background:#141414;">
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="logo" style="width:28px; height:28px;">
                <span style="color:#fff; font-weight:700;"><?php echo $brandText; ?></span>
            </div>
            <button id="mobileMenuClose" class="button is-dark" aria-label="Close navigation">
                <span class="icon"><i class="fas fa-times"></i></span>
            </button>
        </div>
        <div class="mobile-menu-body" style="padding:0.75rem; overflow-y:auto; max-height:calc(100vh - 56px);">
            <?php include_once __DIR__ . '/menu.php'; renderMenu('mobile', $layoutMode); ?>
            <div style="padding-top:0.75rem; border-top:1px solid rgba(255,255,255,0.04); margin-top:0.75rem;">
                <a href="mod_channels.php" class="sidebar-user-item"
                    style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                    <span class="sidebar-user-text">Mod Channels</span>
                </a>
                <?php if (!empty($is_admin)): ?>
                    <a href="admin/" class="sidebar-user-item" title="<?php echo t('navbar_admin_panel'); ?>"
                        style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                        <span class="sidebar-user-icon"><i class="fas fa-shield-alt has-text-danger"></i></span>
                        <span class="sidebar-user-text"><?php echo t('navbar_admin_panel'); ?></span>
                    </a>
                <?php endif; ?>
                <a href="profile.php" class="sidebar-user-item"
                    style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-id-card"></i></span>
                    <span class="sidebar-user-text"><?php echo $profileNavLabel; ?></span>
                </a>
                <a href="logout.php" class="sidebar-user-item"
                    style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; color:#fff;">
                    <span class="sidebar-user-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="sidebar-user-text"><?php echo t('navbar_logout'); ?></span>
                </a>
            </div>
        </div>
    </div>
    <!-- Sidebar Navigation (Desktop Only - Hidden on Mobile/Tablet) -->
    <aside class="sidebar-nav desktop-only" id="sidebarNav">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo">
                <span class="sidebar-brand-text"><?php echo $brandText; ?></span>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <span class="icon"><i class="fas fa-bars"></i></span>
            </button>
        </div>
        <div class="sidebar-content-wrapper">
            <?php include_once __DIR__ . '/menu.php'; renderMenu('desktop', $layoutMode); ?>
            <div class="sidebar-user-section">
                <a href="mod_channels.php" class="sidebar-user-item">
                    <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                    <span class="sidebar-user-text">Mod Channels</span>
                </a>
                    <?php if (!empty($is_admin)): ?>
                    <a href="admin/" class="sidebar-user-item" title="<?php echo t('navbar_admin_panel'); ?>">
                        <span class="sidebar-user-icon"><i class="fas fa-shield-alt has-text-danger"></i></span>
                        <span class="sidebar-user-text"><?php echo t('navbar_admin_panel'); ?></span>
                    </a>
                    <?php endif; ?>
                <a href="profile.php" class="sidebar-user-item">
                    <span class="sidebar-user-icon"><i class="fas fa-id-card"></i></span>
                    <span class="sidebar-user-text"><?php echo $profileNavLabel; ?></span>
                </a>
                <a href="logout.php" class="sidebar-user-item">
                    <span class="sidebar-user-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="sidebar-user-text"><?php echo t('navbar_logout'); ?></span>
                </a>
                <!-- Version Badge -->
                <div class="sidebar-version">
                    <span class="tag is-info is-light">v<?php echo $dashboardVersion; ?></span>
                </div>
            </div>
        </div>
    </aside>
    <?php if ($layoutMode === 'admin'): ?>
        <!-- Admin Banner -->
        <div style="background:rgb(0, 0, 0); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px;"><span><strong>ADMIN DASHBOARD</strong> &mdash; Restricted Access</span></div>
    <?php elseif ($layoutMode === 'moderator'):
        $modDisplay = isset($_SESSION['editing_display_name']) ? htmlspecialchars($_SESSION['editing_display_name'], ENT_QUOTES, 'UTF-8') : null;
        $modUsername = isset($_SESSION['editing_username']) ? htmlspecialchars($_SESSION['editing_username'], ENT_QUOTES, 'UTF-8') : null; ?>
        <!-- Moderator Banner -->
        <div style="background:rgb(0, 123, 255); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px;">
            <?php if ($modDisplay || $modUsername): ?>
                <span>You are moderating: <strong><?php echo $modDisplay ? $modDisplay : $modUsername; ?></strong><?php echo ($modDisplay && $modUsername) ? ' (@' . $modUsername . ')' : ''; ?></span>
            <?php else: ?>
                <span>You are using the <strong>MODERATOR</strong> dashboard. No channel selected — <a href="mod_channels.php" style="color:#fff; text-decoration:underline;">select a channel to moderate</a>.</span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($layoutMode === 'default' && $devStreamOnline): ?>
            <!-- Dev Stream Online Banner -->
            <div style="background:rgb(138, 43, 226); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"><span><i class="fas fa-video"></i> Dev Stream Online - Watch live at <a href="https://twitch.tv/gfaundead" target="_blank" style="color: #fff; text-decoration: underline;">twitch.tv/gfaundead</a></span></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($maintenanceMode):
        $modalAcknowledged = isset($_COOKIE['maintenance_modal_acknowledged']) && $_COOKIE['maintenance_modal_acknowledged'] === 'true'; ?>
        <!-- Maintenance Notice Banner -->
        <div
            style="background:rgb(255, 165, 0); color: #222; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
            <span style="color:rgb(0, 0, 0);">
                <i class="fas fa-tools"></i> Maintenance in progress - Some features may be temporarily unavailable
            </span>
        </div>
            <?php if (!$modalAcknowledged): ?>
            <!-- Maintenance Modal -->
            <div id="maintenanceModal" class="modal is-active">
                <div class="modal-background"></div>
                <div class="modal-card">
                    <header class="modal-card-head has-background-warning">
                        <p class="modal-card-title has-text-dark">
                            <i class="fas fa-tools"></i> Maintenance Notice
                        </p>
                        <button class="delete" aria-label="close" onclick="closeMaintenanceModal()"></button>
                    </header>
                    <section class="modal-card-body">
                        <div class="content">
                            <p class="has-text-weight-bold">
                                We are currently performing maintenance on BotOfTheSpecter.
                            </p>
                            <p>
                                During this time, some features may be temporarily unavailable or experience reduced
                                functionality.
                                We apologize for any inconvenience and appreciate your patience.
                            </p>
                            <p>
                                <strong>What you can expect:</strong>
                            </p>
                            <ul>
                                <li>The dashboard will remain accessible</li>
                                <li>Some features may be temporarily disabled</li>
                                <li>Normal service will resume shortly</li>
                            </ul>
                            <p class="has-text-grey">
                                Thank you for your understanding!
                            </p>
                        </div>
                    </section>
                    <footer class="modal-card-foot">
                        <button class="button is-warning" onclick="closeMaintenanceModal()">I Understand</button>
                        <button class="button" onclick="dontShowAgain()">Don't show again today</button>
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
    <footer class="footer is-dark has-text-white"
        style="display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
        <div class="is-hidden-mobile">
            &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
                <?php include '/var/www/config/project-time.php'; ?>
            BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia
            (ABN 20 447 022 747).<br>
            This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live
            Momentum Ltd., or StreamElements Inc.<br>
            All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are the
            property of their respective owners and are used for identification purposes only.
        </div>
        <div style="max-width: 1500px;" class="is-hidden-tablet">
            &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            <span class="tag is-info is-light mt-2"><?php echo ($layoutMode==='admin')? 'Admin Dashboard Version: ' . $dashboardVersion : (($layoutMode==='moderator')? 'Mod Dashboard Version: ' . $dashboardVersion : ($layoutMode==='todolist' ? 'To Do List Version: ' . $dashboardVersion : 'Dashboard Version: ' . $dashboardVersion)); ?></span><br>
            BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia
            (ABN 20 447 022 747).<br>
            This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live
            Momentum Ltd., or StreamElements Inc.<br>
            All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are the
            property of their respective owners and are used for identification purposes only.
        </div>
    </footer>
    <!-- JavaScript dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Custom Uptime Script -->
    <script src="https://uptime.botofthespecter.com/en/cca64861/widget/script.js"></script>
    <!-- Custom JS -->
    <script src="/js/dashboard.js?v=<?php echo uuidv4(); ?>"></script>
    <script src="/js/search.js?v=<?php echo uuidv4(); ?>"></script>
    <script src="/js/bulmaModals.js?v=<?php echo uuidv4(); ?>"></script>
    <script src="/js/sidebar-mobile.js?v=<?php echo uuidv4(); ?>"></script>
        <?php echo $scripts; ?>
        <?php include_once "usr_database.php"; ?>
    <script>
        function setCookie(name, value, days) {
            var d = new Date();
            d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
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
        document.addEventListener('DOMContentLoaded', function () {
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
        document.addEventListener('DOMContentLoaded', function () {
            var consent = getCookie('cookie_consent');
            if (!consent) {
                showCookieConsentBox();
            }
            document.getElementById('cookieAcceptBtn').onclick = function () {
                setCookie('cookie_consent', 'accepted', 7);
                hideCookieConsentBox();
            };
            document.getElementById('cookieDeclineBtn').onclick = function () {
                setCookie('cookie_consent', 'declined', 14);
                hideCookieConsentBox();
            };
        });
        // Generic client-side active-menu helper (works for default, moderator and admin menus)
        document.addEventListener('DOMContentLoaded', function () {
            try {
                const path = window.location.pathname || '';
                const file = path.substring(path.lastIndexOf('/') + 1) || '';
                document.querySelectorAll('.sidebar-menu-link').forEach(link => {
                    const href = (link.getAttribute('href') || '').trim();
                    if (!href || href === '#') return;
                    // Normalize and match by full path, by filename, or by trailing match
                    if (href === path || href === file || path.endsWith(href) || (href.startsWith('/') && path.endsWith(href.replace(/^\//, '')))) {
                        link.classList.add('active');
                        const parent = link.closest('.sidebar-menu-item.has-submenu');
                        if (parent) parent.classList.add('open');
                        const submenu = link.closest('.sidebar-submenu');
                        if (submenu) submenu.style.display = 'block';
                    } else if (href.startsWith('/')) {
                        // also match by path segment for leading-slash links (e.g. '/admin' should match '/admin/index.php' or '/dashboard/admin/xyz')
                        try {
                            const segment = href.replace(/^\//, '').replace(/\/$/, '');
                            const parts = path.split('/').filter(Boolean);
                            if (segment && parts.includes(segment)) {
                                link.classList.add('active');
                                const parent = link.closest('.sidebar-menu-item.has-submenu');
                                if (parent) parent.classList.add('open');
                                const submenu = link.closest('.sidebar-submenu');
                                if (submenu) submenu.style.display = 'block';
                            }
                        } catch (e) { /* no-op */ }
                    }
                });
            } catch (e) {
                // no-op on error
            }
        });
    </script>
</body>
</html>