<?php
// This file serves as a template for all dashboard pages
include "mod_access.php";

if (!isset($scripts)) $scripts = '';

// Add language support for layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$dashboardVersion = '2.1.1';
// Maintenance mode control
$maintenanceMode = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Bulma Switch Extension -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma-switch@2.0.4/dist/css/bulma-switch.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/custom.css?v=<?php echo $dashboardVersion; ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body style="display: flex; flex-direction: column; min-height: 100vh;">
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
                    <h4 class="has-text-weight-bold mt-4">What This Means:</h4>
                    <ul>
                        <li>Some features may be temporarily unavailable</li>
                        <li>Response times may be slower than usual</li>
                        <li>Some integrations might not function properly</li>
                    </ul>
                    <h4 class="has-text-weight-bold mt-4">What We're Doing:</h4>
                    <p>Our team is actively working with the provider to resolve these issues and restore full service as quickly as possible. We appreciate your patience during this time.</p>
                </div>
            </section>
            <footer class="modal-card-foot">
                <button class="button is-warning" onclick="closeMaintenanceModal()">Acknowledge</button>
            </footer>
        </div>
    </div>
    <?php endif; endif; ?>
    <!-- Top Navigation Bar -->
    <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <div class="navbar-item">
                <img src="https://cdn.botofthespecter.com/logo.png" width="28" height="28" alt="BotOfTheSpecter Logo">
                <strong class="ml-2">BotOfTheSpecter</strong>
            </div>
            <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarMain">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>
        <div id="navbarMain" class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item" href="dashboard.php">
                    <span class="icon"><i class="fas fa-home"></i></span>
                    <span><?php echo t('navbar_home'); ?></span>
                </a>
                <a class="navbar-item" href="bot.php">
                    <span class="icon"><i class="fas fa-robot"></i></span>
                    <span><?php echo t('navbar_bot_control'); ?></span>
                </a>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-cogs"></i></span>
                        <span><?php echo t('navbar_configuration'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="manage_custom_commands.php">
                            <span class="icon"><i class="fas fa-terminal"></i></span>
                            <span><?php echo t('navbar_edit_custom_commands'); ?></span>
                        </a>
                        <a class="navbar-item" href="manage_custom_user_commands.php">
                            <span class="icon"><i class="fas fa-user-cog"></i></span>
                            <span><?php echo t('navbar_manage_user_commands'); ?></span>
                        </a>
                        <a class="navbar-item" href="timed_messages.php">
                            <span class="icon"><i class="fas fa-clock"></i></span>
                            <span><?php echo t('navbar_timed_messages'); ?></span>
                        </a>
                        <a class="navbar-item" href="edit_counters.php">
                            <span class="icon"><i class="fas fa-pen-to-square"></i></span>
                            <span><?php echo t('navbar_edit_counters'); ?></span>
                        </a>
                        <a class="navbar-item" href="bot_points.php">
                            <span class="icon"><i class="fas fa-coins"></i></span>
                            <span><?php echo t('navbar_points_system'); ?></span>
                        </a>
                        <a class="navbar-item" href="subathon.php">
                            <span class="icon"><i class="fas fa-hourglass-half"></i></span>
                            <span><?php echo t('navbar_subathon'); ?></span>
                        </a>
                        <hr class="navbar-divider">
                        <a class="navbar-item" href="channel_rewards.php">
                            <span class="icon"><i class="fas fa-gift"></i></span>
                            <span><?php echo t('navbar_channel_rewards'); ?></span>
                        </a>
                    </div>
                </div>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-chart-line"></i></span>
                        <span><?php echo t('navbar_analytics'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="logs.php">
                            <span class="icon"><i class="fas fa-clipboard-list"></i></span>
                            <span><?php echo t('navbar_bot_logs'); ?></span>
                        </a>
                        <a class="navbar-item" href="counters.php">
                            <span class="icon"><i class="fas fa-calculator"></i></span>
                            <span><?php echo t('navbar_counters'); ?></span>
                        </a>
                        <a class="navbar-item" href="known_users.php">
                            <span class="icon"><i class="fas fa-users"></i></span>
                            <span><?php echo t('navbar_user_management'); ?></span>
                        </a>
                        <hr class="navbar-divider">
                        <a class="navbar-item" href="followers.php">
                            <span class="icon"><i class="fas fa-user-plus"></i></span>
                            <span><?php echo t('navbar_followers'); ?></span>
                        </a>
                        <a class="navbar-item" href="subscribers.php">
                            <span class="icon"><i class="fas fa-star"></i></span>
                            <span><?php echo t('navbar_subscribers'); ?></span>
                        </a>
                        <a class="navbar-item" href="mods.php">
                            <span class="icon"><i class="fas fa-user-shield"></i></span>
                            <span><?php echo t('navbar_moderators'); ?></span>
                        </a>
                        <a class="navbar-item" href="vips.php">
                            <span class="icon"><i class="fas fa-crown"></i></span>
                            <span><?php echo t('navbar_vips'); ?></span>
                        </a>
                    </div>
                </div>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-broadcast-tower"></i></span>
                        <span><?php echo t('navbar_streaming'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="builtin.php">
                            <span class="icon"><i class="fas fa-terminal"></i></span>
                            <span><?php echo t('navbar_view_builtin_commands'); ?></span>
                        </a>
                        <a class="navbar-item" href="commands.php">
                            <span class="icon"><i class="fas fa-terminal"></i></span>
                            <span><?php echo t('navbar_view_custom_commands'); ?></span>
                        </a>
                        <a class="navbar-item" href="streaming.php">
                            <span class="icon"><i class="fas fa-video"></i></span>
                            <span><?php echo t('navbar_stream_recording'); ?></span>
                        </a>
                        <a class="navbar-item" href="overlays.php">
                            <span class="icon"><i class="fas fa-layer-group"></i></span>
                            <span><?php echo t('navbar_stream_overlays'); ?></span>
                        </a>
                        <hr class="navbar-divider">
                        <a class="navbar-item" href="sound-alerts.php">
                            <span class="icon"><i class="fas fa-volume-up"></i></span>
                            <span><?php echo t('navbar_sound_alerts'); ?></span>
                        </a>
                        <a class="navbar-item" href="video-alerts.php">
                            <span class="icon"><i class="fas fa-film"></i></span>
                            <span><?php echo t('navbar_video_alerts'); ?></span>
                        </a>
                        <a class="navbar-item" href="walkons.php">
                            <span class="icon"><i class="fas fa-door-open"></i></span>
                            <span><?php echo t('navbar_walkon_alerts'); ?></span>
                        </a>
                    </div>
                </div>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-plug"></i></span>
                        <span><?php echo t('navbar_integrations'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="modules.php">
                            <span class="icon"><i class="fa fa-puzzle-piece"></i></span>
                            <span><?php echo t('navbar_specter_modules'); ?></span>
                        </a>
                        <a class="navbar-item" href="discordbot.php">
                            <span class="icon"><i class="fab fa-discord"></i></span>
                            <span><?php echo t('navbar_discord_bot'); ?></span>
                        </a>
                        <a class="navbar-item" href="spotifylink.php">
                            <span class="icon"><i class="fab fa-spotify"></i></span>
                            <span><?php echo t('navbar_spotify'); ?></span>
                        </a>
                        <a class="navbar-item" href="streamelements.php">
                            <span class="icon"><i class="fas fa-globe"></i></span>
                            <span><?php echo t('navbar_streamelements'); ?></span>
                        </a>
                        <hr class="navbar-divider">
                        <a class="navbar-item" href="integrations.php">
                            <span class="icon"><i class="fas fa-globe"></i></span>
                            <span><?php echo t('navbar_platform_integrations'); ?></span>
                        </a>
                    </div>
                </div>
                <a class="navbar-item" href="premium.php">
                    <span class="icon"><i class="fas fa-crown"></i></span>
                    <span><?php echo t('navbar_premium'); ?></span>
                </a>
                <a class="navbar-item" href="music.php">
                    <span class="icon"><i class="fas fa-music"></i></span>
                    <span><?php echo t('navbar_vod_music'); ?></span>
                </a>
                <a class="navbar-item" href="todolist/index.php">
                    <span class="icon"><i class="fas fa-list-check"></i></span>
                    <span><?php echo t('navbar_todo_list'); ?></span>
                </a>
            </div>
            <div class="navbar-end">
                <?php if (!empty($showModDropdown) && !empty($modChannels)): ?>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-user-shield"></i></span>
                    </a>
                    <div class="navbar-dropdown">
                        <?php foreach ($modChannels as $modChannel): ?>
                            <a class="navbar-item" href="switch_channel.php?user_id=<?php echo urlencode($modChannel['twitch_user_id']); ?>">
                                <span class="icon">
                                    <img src="<?php echo htmlspecialchars($modChannel['profile_image']); ?>" alt="" style="width: 24px; height: 24px; border-radius: 50%;">
                                </span>
                                <span><?php echo htmlspecialchars($modChannel['twitch_display_name']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($is_admin)): ?>
                <div class="navbar-item">
                    <a href="admin/" title="<?php echo t('navbar_admin_panel'); ?>">
                        <span class="icon has-text-danger"><i class="fas fa-shield-alt"></i></span>
                    </a>
                </div>
                <?php endif; ?>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span><?php echo isset($_SESSION['username']) ? $_SESSION['username'] : 'User'; ?></span>
                    </a>
                    <div class="navbar-dropdown is-right">
                        <a class="navbar-item" href="profile.php">
                            <span class="icon"><i class="fas fa-id-card"></i></span>
                            <span><?php echo t('navbar_profile'); ?></span>
                        </a>
                        <a class="navbar-item" href="logout.php">
                            <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span><?php echo t('navbar_logout'); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <!-- Main content -->
    <div class="columns" style="flex: 1 0 auto;">
        <div class="column is-10 is-offset-1 main-content">
            <section class="section">
                <div class="container is-fluid">
                    <?php echo $content; ?>
                </div>
            </section>
        </div>
    </div>
    <!-- Footer -->
    <footer class="footer is-dark has-text-white" style="width:100%; display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
        <!-- Version Badge positioned in footer -->
        <div style="position: absolute; bottom: 12px; left: 12px;" class="is-hidden-mobile">
            <span class="tag is-info is-light">Dashboard Version: <?php echo $dashboardVersion; ?></span>
        </div>
        <div style="max-width: 1500px; padding-left: 140px;" class="is-hidden-mobile">
            &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            <?php
                $tz = new DateTimeZone("Australia/Sydney");
                $launchDate = new DateTime("2023-10-17 11:54:58", $tz);
                $now = new DateTime("now", $tz);
                $interval = $launchDate->diff($now);
                echo "Project has been running since 17th October 2023, 11:54:58 AEDT";
                echo "<br>";
                echo "As of now, ";
                echo "it's been {$interval->y} year" . ($interval->y != 1 ? "s" : "") . ", ";
                echo "{$interval->m} month" . ($interval->m != 1 ? "s" : "") . ", ";
                echo "{$interval->d} day" . ($interval->d != 1 ? "s" : "") . ", ";
                echo "{$interval->h} hour" . ($interval->h != 1 ? "s" : "") . ", ";
                echo "{$interval->i} minute" . ($interval->i != 1 ? "s" : "") . " since launch.<br>";
            ?>
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
    <script src="js/dashboard.js"></script>
    <script src="/js/search.js"></script>
    <script src="/js/bulmaModals.js"></script>
    <?php echo $scripts; ?>
    <?php include_once "usr_database.php"; ?>
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