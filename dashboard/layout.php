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
$showAdminPanelLink = isset($is_admin) && $is_admin === true;
$isActingAsUser = isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true;
$actingAsDisplayName = isset($_SESSION['admin_act_as_target_display_name']) ? (string) $_SESSION['admin_act_as_target_display_name'] : '';
$actingAsUsername = isset($_SESSION['admin_act_as_target_username']) ? (string) $_SESSION['admin_act_as_target_username'] : '';
$actingAsLabelRaw = trim($actingAsDisplayName !== '' ? $actingAsDisplayName : $actingAsUsername);
$actingAsLabel = htmlspecialchars($actingAsLabelRaw !== '' ? $actingAsLabelRaw : 'selected user', ENT_QUOTES, 'UTF-8');
$actingAsReturnLabel = 'Stop Acting As';
$stopActAsHref = 'stop_act_as.php';
// default layout mode (pages may override by setting $layoutMode before including layout.php)
// If not set, infer from the request URI path segments: /admin, /todolist -> respective modes; otherwise 'default'
if (!isset($layoutMode)) {
    $layoutMode = 'default';
    $candidatePaths = [];
    if (isset($_SERVER['REQUEST_URI'])) {
        $candidatePaths[] = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $candidatePaths[] = (string) $_SERVER['SCRIPT_NAME'];
    }
    if (isset($_SERVER['PHP_SELF'])) {
        $candidatePaths[] = (string) $_SERVER['PHP_SELF'];
    }
    if (isset($_SERVER['SCRIPT_FILENAME'])) {
        $candidatePaths[] = (string) $_SERVER['SCRIPT_FILENAME'];
    }
    foreach ($candidatePaths as $candidatePath) {
        $path = strtolower(str_replace('\\', '/', trim($candidatePath)));
        $norm = rtrim($path, '/');
        if ($norm === '') {
            continue;
        }
        if (strpos($norm, '/admin') !== false) {
            $layoutMode = 'admin';
            break;
        }
        if (strpos($norm, '/todolist') !== false) {
            $layoutMode = 'todolist';
            break;
        }
    }
}
// brand text/href vary by layout mode
switch ($layoutMode) {
    case 'admin':
        $brandText = 'Admin Panel';
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
if ($layoutMode === 'admin' || $layoutMode === 'todolist') {
    $stopActAsHref = '../stop_act_as.php';
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

$isAdminCssPage = isset($layoutMode) && $layoutMode === 'admin';
if (!$isAdminCssPage && isset($_SERVER['REQUEST_URI'])) {
    $cssPath = strtolower((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $isAdminCssPage = strpos(rtrim($cssPath, '/'), '/admin') !== false;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark-theme" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <!-- Dashboard CSS -->
    <link rel="stylesheet" href="/css/dashboard.css?v=<?php echo uuidv4(); ?>">
    <?php if ($isAdminCssPage): ?>
        <link rel="stylesheet" href="/css/admin.css?v=<?php echo uuidv4(); ?>">
    <?php endif; ?>
    <link rel="shortcut icon" href="https://cdn.botofthespecter.com/favicon.ico?v=<?php echo $dashboardVersion; ?>">
    <link rel="icon" type="image/x-icon" href="https://cdn.botofthespecter.com/favicon.ico?v=<?php echo $dashboardVersion; ?>">
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/favicon.png?v=<?php echo $dashboardVersion; ?>">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png?v=<?php echo $dashboardVersion; ?>">
</head>
<body>
    <!-- Cookie Consent Box -->
    <div id="cookieConsentBox" class="db-cookie-box">
        <div class="mb-3">
            <?php echo t('cookie_consent_help'); ?>
            <br>
            <a href="https://botofthespecter.com/privacy-policy.php" target="_blank">
                <?php echo t('privacy_policy'); ?>
            </a>
        </div>
        <div class="db-cookie-actions">
            <button id="cookieAcceptBtn" class="sp-btn sp-btn-success"><?php echo t('cookie_accept_btn'); ?></button>
            <button id="cookieDeclineBtn" class="sp-btn sp-btn-danger"><?php echo t('cookie_decline_btn'); ?></button>
        </div>
    </div>
    <!-- Sidebar overlay (mobile) -->
    <div class="sp-overlay" id="spOverlay"></div>
    <!-- Layout shell -->
    <div class="sp-layout">
        <!-- Sidebar -->
        <aside class="sp-sidebar" id="spSidebar">
            <a href="<?php echo $brandHref; ?>" class="sp-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo">
                <span class="sp-brand-text">
                    <span class="sp-brand-title"><?php echo $brandText; ?></span>
                </span>
            </a>
            <nav class="sp-nav">
                <?php include_once __DIR__ . '/menu.php'; renderMenu('desktop', $layoutMode); ?>
            </nav>
            <div class="sp-sidebar-footer">
                <div class="sidebar-user-section">
                    <?php if ($layoutMode === 'admin' || $layoutMode === 'todolist'): ?>
                        <a href="../dashboard.php" class="sidebar-user-item">
                            <span class="sidebar-user-icon"><i class="fas fa-house"></i></span>
                            <span class="sidebar-user-text">User Dashboard</span>
                        </a>
                    <?php endif; ?>
                    <a href="../mod_channels.php" class="sidebar-user-item">
                        <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                        <span class="sidebar-user-text">Mod Channels</span>
                    </a>
                    <?php if ($showAdminPanelLink): ?>
                        <a href="../admin/" class="sidebar-user-item" title="<?php echo t('navbar_admin_panel'); ?>">
                            <span class="sidebar-user-icon"><i class="fas fa-shield-alt"></i></span>
                            <span class="sidebar-user-text"><?php echo t('navbar_admin_panel'); ?></span>
                        </a>
                    <?php endif; ?>
                    <a href="../profile.php" class="sidebar-user-item">
                        <span class="sidebar-user-icon"><i class="fas fa-id-card"></i></span>
                        <span class="sidebar-user-text"><?php echo $profileNavLabel; ?></span>
                    </a>
                    <a href="../logout.php" class="sidebar-user-item">
                        <span class="sidebar-user-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="sidebar-user-text"><?php echo t('navbar_logout'); ?></span>
                    </a>
                </div>
                <div class="sp-version-row">
                    <span class="sp-version-badge">v<?php echo $dashboardVersion; ?></span>
                </div>
            </div>
        </aside>
        <!-- Main -->
        <div class="sp-main">
            <!-- Topbar -->
            <header class="sp-topbar">
                <button class="sp-hamburger" id="spHamburger" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sp-topbar-title"><?php echo $brandText; ?></span>
                <div class="sp-topbar-center">
                    <?php if ($layoutMode === 'admin'): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-admin"><i class="fas fa-shield-alt"></i> ADMIN DASHBOARD &mdash; Restricted Access</span>
                    <?php elseif ($layoutMode === 'default' && $devStreamOnline): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-dev"><i class="fas fa-video"></i> Dev Stream Online &mdash; <a href="https://twitch.tv/gfaundead" target="_blank">twitch.tv/gfaundead</a></span>
                    <?php endif; ?>
                    <?php if ($isActingAsUser): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-act-as"><i class="fas fa-user-secret"></i> Viewing as <strong><?php echo $actingAsLabel; ?></strong> &mdash; <a href="<?php echo $stopActAsHref; ?>"><?php echo htmlspecialchars($actingAsReturnLabel, ENT_QUOTES, 'UTF-8'); ?></a></span>
                    <?php endif; ?>
                    <?php if ($maintenanceMode): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-maintenance"><i class="fas fa-tools"></i> Maintenance in progress &mdash; Some features may be temporarily unavailable</span>
                    <?php endif; ?>
                </div>
                <div class="sp-topbar-actions">
                    <?php if ($profileUsername): ?>
                        <span style="font-size:0.82rem; color:var(--text-muted);"><?php echo $profileUsername; ?></span>
                    <?php endif; ?>
                </div>
            </header>
            <?php if ($maintenanceMode):
                $modalAcknowledged = isset($_COOKIE['maintenance_modal_acknowledged']) && $_COOKIE['maintenance_modal_acknowledged'] === 'true';
                if (!$modalAcknowledged): ?>
            <!-- Maintenance Modal -->
            <div id="maintenanceModal" class="db-modal-backdrop">
                <div class="db-modal">
                    <div class="db-modal-head">
                        <div class="db-modal-title"><i class="fas fa-tools"></i> Maintenance Notice</div>
                        <button class="db-modal-close" aria-label="close" onclick="closeMaintenanceModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="db-modal-body">
                        <p><strong>We are currently performing maintenance on BotOfTheSpecter.</strong></p>
                        <p>During this time, some features may be temporarily unavailable or experience reduced functionality. We apologize for any inconvenience and appreciate your patience.</p>
                        <p><strong>What you can expect:</strong></p>
                        <ul>
                            <li>The dashboard will remain accessible</li>
                            <li>Some features may be temporarily disabled</li>
                            <li>Normal service will resume shortly</li>
                        </ul>
                        <p style="color:var(--text-muted);">Thank you for your understanding!</p>
                    </div>
                    <div class="db-modal-foot">
                        <button class="sp-btn sp-btn-warning" onclick="closeMaintenanceModal()">I Understand</button>
                        <button class="sp-btn sp-btn-secondary" onclick="dontShowAgain()">Don't show again today</button>
                    </div>
                </div>
            </div>
            <?php endif; endif; ?>
            <!-- Content -->
            <main class="sp-content">
                <?php echo $content; ?>
            </main>
            <!-- Footer -->
            <footer class="sp-footer">
                &copy; 2023&ndash;<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
                <?php include '/var/www/config/project-time.php'; ?>
                BotOfTheSpecter is a project operated under the business name &ldquo;YourStreamingTools&rdquo;, registered in Australia (ABN 20 447 022 747).<br>
                This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
                All trademarks, logos, and brand names are the property of their respective owners and are used for identification purposes only.
            </footer>
        </div><!-- /.sp-main -->
    </div><!-- /.sp-layout -->
    <!-- JavaScript dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Custom Uptime Script -->
    <script src="https://uptime.botofthespecter.com/en/cca64861/widget/script.js"></script>
    <!-- Custom JS -->
    <script src="/js/dashboard.js?v=<?php echo uuidv4(); ?>"></script>
    <script src="/js/search.js?v=<?php echo uuidv4(); ?>"></script>
        <?php echo $scripts; ?>
        <?php include_once "usr_database.php"; ?>
    <script>
        // Sidebar toggle (mobile)
        (function () {
            const sidebar  = document.getElementById('spSidebar');
            const overlay  = document.getElementById('spOverlay');
            const hamburger = document.getElementById('spHamburger');
            function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('active'); }
            function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('active'); }
            if (hamburger) hamburger.addEventListener('click', function () {
                sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
            });
            if (overlay) overlay.addEventListener('click', closeSidebar);
        })();
        // Submenu toggle
        function toggleSubmenu(e, el) {
            e.preventDefault();
            var item = el.closest('.sidebar-menu-item');
            if (!item) return;
            item.classList.toggle('open');
        }
    </script>
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
            var modal = document.getElementById('maintenanceModal');
            if (modal) modal.classList.add('hidden');
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
                    var modal = document.getElementById('maintenanceModal');
                    if (modal) modal.classList.remove('hidden');
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