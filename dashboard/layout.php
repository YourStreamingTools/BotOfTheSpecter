<?php
// This file serves as a template for all dashboard pages
include_once __DIR__ . '/includes/mod_access.php';

if (!isset($pageTitle))
    $pageTitle = "BotOfTheSpecter";
if (!isset($pageContent))
    $pageContent = "";
if (!isset($scripts))
    $scripts = "";

// Add language support for layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
if (!isset($pageDescription))
    $pageDescription = t('layout_meta_description');
$profileUsername = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : (isset($user['username']) ? htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') : '');
$profileNavLabel = t('navbar_profile') . ' | ' . $profileUsername;
$showAdminPanelLink = isset($is_admin) && $is_admin === true;
$isActingAsUser = isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true;
$actingAsDisplayName = isset($_SESSION['admin_act_as_target_display_name']) ? (string) $_SESSION['admin_act_as_target_display_name'] : '';
$actingAsUsername = isset($_SESSION['admin_act_as_target_username']) ? (string) $_SESSION['admin_act_as_target_username'] : '';
$actingAsLabelRaw = trim($actingAsDisplayName !== '' ? $actingAsDisplayName : $actingAsUsername);
$actingAsLabel = htmlspecialchars($actingAsLabelRaw !== '' ? $actingAsLabelRaw : t('layout_selected_user'), ENT_QUOTES, 'UTF-8');
$actingAsReturnLabel = t('layout_stop_acting_as');
$stopActAsHref = '/api/stop_act_as.php';
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
        $brandText = t('layout_brand_admin_panel');
        $brandHref = 'index.php';
        break;
    case 'todolist':
        $brandText = t('layout_brand_todo_list');
        $brandHref = 'index.php';
        break;
    default:
        $brandText = 'BotOfTheSpecter';
        $brandHref = 'dashboard.php';
}
if ($layoutMode === 'admin' || $layoutMode === 'todolist') {
    $stopActAsHref = '/api/stop_act_as.php';
}
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];
$maintenanceMessage = $config['maintenanceMessage'] ?? '';

// Dev-stream topbar tag is filled client-side after first paint (see footer JS).
// Never block HTML on a remote streamonline probe — that delayed every dashboard page by up to ~3s.

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
    <!-- Theme bootstrap: apply saved/OS theme before stylesheets paint (avoids flash) -->
    <script>
        (function () {
            try {
                var t = localStorage.getItem('sp-theme');
                if (t !== 'light' && t !== 'dark') {
                    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
                }
                document.documentElement.setAttribute('data-theme', t);
                document.documentElement.className = (t === 'light' ? 'light-theme' : 'dark-theme');
            } catch (e) {}
        })();
    </script>
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.3.1/css/all.css">
    <!-- Dashboard CSS -->
    <link rel="stylesheet" href="/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/css/dashboard.css'); ?>">
    <?php if ($isAdminCssPage): ?>
        <link rel="stylesheet" href="/css/admin.css?v=<?php echo filemtime(__DIR__ . '/css/admin.css'); ?>">
    <?php endif; ?>
    <?php if (basename($_SERVER['SCRIPT_NAME']) === 'alerts.php'): ?>
        <link rel="stylesheet" href="/css/alerts.css?v=<?php echo filemtime(__DIR__ . '/css/alerts.css'); ?>">
    <?php endif; ?>
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
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
                            <span class="sidebar-user-text"><?php echo t('layout_user_dashboard'); ?></span>
                        </a>
                    <?php endif; ?>
                    <a href="../mod_channels.php" class="sidebar-user-item">
                        <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                        <span class="sidebar-user-text"><?php echo t('layout_mod_channels'); ?></span>
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
            <?php $hasTopbarTags = ($layoutMode === 'admin') || ($layoutMode === 'default') || $isActingAsUser || $maintenanceMode; ?>
            <header class="sp-topbar<?= $hasTopbarTags ? '' : ' sp-topbar-no-tags' ?>">
                <button class="sp-hamburger" id="spHamburger" aria-label="<?php echo htmlspecialchars(t('layout_toggle_navigation')); ?>">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sp-topbar-title"><?php echo $brandText; ?></span>
                <div class="sp-topbar-center">
                    <?php if ($layoutMode === 'admin'): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-admin"><i class="fas fa-shield-alt"></i> <?= t('layout_topbar_admin_dashboard') ?></span>
                    <?php elseif ($layoutMode === 'default'): ?>
                        <span id="spDevStreamTag" class="sp-topbar-tag sp-topbar-tag-dev" hidden><i class="fas fa-video"></i> <?= t('layout_topbar_dev_stream_online') ?> &mdash; <a href="https://twitch.tv/gfaundead" target="_blank" rel="noopener">twitch.tv/gfaundead</a></span>
                    <?php endif; ?>
                    <?php if ($isActingAsUser): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-act-as"><i class="fas fa-user-secret"></i> <?= t('layout_topbar_viewing_as') ?> <strong><?php echo $actingAsLabel; ?></strong> &mdash; <a href="<?php echo $stopActAsHref; ?>"><?php echo htmlspecialchars($actingAsReturnLabel, ENT_QUOTES, 'UTF-8'); ?></a></span>
                    <?php endif; ?>
                    <?php if ($maintenanceMode): ?>
                        <span class="sp-topbar-tag sp-topbar-tag-maintenance"><i class="fas fa-tools"></i> <?= $maintenanceMessage ?></span>
                    <?php endif; ?>
                </div>
                <div class="sp-topbar-actions">
                    <button class="sp-theme-toggle" id="spThemeToggle" type="button" aria-label="<?php echo htmlspecialchars(t('layout_toggle_theme_aria')); ?>" title="<?php echo htmlspecialchars(t('layout_toggle_theme_title')); ?>">
                        <i class="fas fa-moon"></i>
                    </button>
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
                        <div class="db-modal-title"><i class="fas fa-tools"></i> <?= t('layout_maintenance_notice_title') ?></div>
                        <button class="db-modal-close" aria-label="<?php echo htmlspecialchars(t('layout_close')); ?>" onclick="closeMaintenanceModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="db-modal-body">
                        <p><strong><?= $maintenanceMessage ?></strong></p>
                        <p><?= t('layout_maintenance_what_to_expect') ?></p>
                        <ul>
                            <li><?= t('layout_maintenance_expect_dashboard') ?></li>
                            <li><?= t('layout_maintenance_expect_features') ?></li>
                            <li><?= t('layout_maintenance_expect_resume') ?></li>
                        </ul>
                        <p style="color:var(--text-muted);"><?= t('layout_maintenance_thank_you') ?></p>
                    </div>
                    <div class="db-modal-foot">
                        <button class="sp-btn sp-btn-warning" onclick="closeMaintenanceModal()"><?= t('layout_maintenance_i_understand') ?></button>
                        <button class="sp-btn sp-btn-secondary" onclick="dontShowAgain()"><?= t('layout_maintenance_dont_show_again') ?></button>
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
                &copy; 2023&ndash;<?php echo date('Y'); ?> <?= t('layout_footer_rights') ?><br>
                <?php include '/var/www/config/project-time.php'; ?>
                <?= t('layout_footer_business') ?><br>
                <?= t('layout_footer_not_affiliated') ?><br>
                <?= t('layout_footer_trademarks') ?>
            </footer>
        </div><!-- /.sp-main -->
    </div><!-- /.sp-layout -->
    <!-- JavaScript dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Custom JS -->
    <script src="/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/js/dashboard.js'); ?>"></script>
    <script src="/js/search.js?v=<?php echo filemtime(__DIR__ . '/js/search.js'); ?>"></script>
        <?php echo $scripts; ?>
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
                location.reload();
            };
            document.getElementById('cookieDeclineBtn').onclick = function () {
                setCookie('cookie_consent', 'declined', 14);
                location.reload();
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
    <script>
        // Light/dark theme toggle (topbar). The <head> bootstrap sets the initial theme.
        (function () {
            var btn = document.getElementById('spThemeToggle');
            function current() { return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
            function syncIcon(theme) {
                if (!btn) return;
                var icon = btn.querySelector('i');
                if (icon) icon.className = (theme === 'light' ? 'fas fa-sun' : 'fas fa-moon');
            }
            function apply(theme, persist) {
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.className = (theme === 'light' ? 'light-theme' : 'dark-theme');
                if (persist) { try { localStorage.setItem('sp-theme', theme); } catch (e) {} }
                syncIcon(theme);
            }
            syncIcon(current());
            if (btn) btn.addEventListener('click', function () {
                apply(current() === 'light' ? 'dark' : 'light', true);
            });
            // Keep other open tabs in sync
            window.addEventListener('storage', function (e) {
                if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) {
                    apply(e.newValue, false);
                }
            });
        })();
    </script>
    <?php if ($layoutMode === 'default'): ?>
    <script>
        // Dev stream badge: after first paint only (never block PHP TTFB for this).
        (function () {
            var tag = document.getElementById('spDevStreamTag');
            if (!tag) return;
            fetch('/api/dev_stream_status.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (d && d.online) {
                        tag.hidden = false;
                        var topbar = tag.closest('.sp-topbar');
                        if (topbar) topbar.classList.remove('sp-topbar-no-tags');
                    }
                })
                .catch(function () { /* ignore */ });
        })();
    </script>
    <?php endif; ?>
    <?php
    // Replay usr_database.php console messages after paint; schema runs after the HTML response finishes.
    $usrSchemaNeedsConsole = (!defined('BOTS_SKIP_USR_DATABASE') || !BOTS_SKIP_USR_DATABASE)
        && !empty($_SESSION['username'])
        && (
            empty($_SESSION['usr_schema_ok'])
            || (string) $_SESSION['usr_schema_ok'] !== (string) $_SESSION['username']
        );
    if ($usrSchemaNeedsConsole):
    ?>
    <script>
        (function () {
            var attempts = 0;
            var maxAttempts = 240;
            function printLogs(logs) {
                if (!logs || !logs.length) return;
                if (console.group) console.group('Per-user schema');
                logs.forEach(function (entry) {
                    var msg = (entry && typeof entry === 'object') ? (entry.message || '') : String(entry);
                    if (!msg) return;
                    if (entry && entry.level === 'error') console.error(msg);
                    else console.log(msg);
                });
                if (console.groupEnd) console.groupEnd();
            }
            function tick() {
                fetch('/api/usr_schema.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d) return;
                        if (d.pending) {
                            if (attempts++ < maxAttempts) setTimeout(tick, 250);
                            return;
                        }
                        if (!d.skipped) printLogs(d.logs);
                    })
                    .catch(function () { /* ignore */ });
            }
            tick();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
<?php
// Per-user schema bootstrap after </html> so the shell can paint first.
if (!defined('BOTS_SKIP_USR_DATABASE') || !BOTS_SKIP_USR_DATABASE) {
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
    include_once __DIR__ . '/includes/usr_database.php';
}