<?php
// This file serves as a template for all dashboard pages
include "mod_access.php";

if (!isset($pageTitle)) $pageTitle = "BotOfTheSpecter";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.";
if (!isset($pageContent)) $pageContent = "";
if (!isset($scripts)) $scripts = "";

// Add language support for layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
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
?>
<!DOCTYPE html>
<html lang="en" class="theme-dark">
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
    <link rel="stylesheet" href="css/bulma-responsive-tables.css">
    <link rel="stylesheet" href="css/custom.css?v=<?php echo uuidv4(); ?>">
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
                <a href="dashboard.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-home"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_home'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_home'); ?></div>
            </li>
            <li class="sidebar-menu-item">
                <a href="bot.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-robot"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_bot_control'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_bot_control'); ?></div>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-terminal"></i></span>
                    <span class="sidebar-menu-text">Commands</span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip">Commands</div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="custom_commands.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-terminal"></i></span>
                            <span class="sidebar-menu-text">Custom Commands</span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_custom_user_commands.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="sidebar-menu-text">User Commands</span>
                        </a>
                    </li>
                    <li>
                        <a href="builtin.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-terminal"></i></span>
                            <span class="sidebar-menu-text">Builtin Commands</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-cogs"></i></span>
                    <span class="sidebar-menu-text">Settings</span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip">Settings</div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="timed_messages.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-clock"></i></span>
                            <span class="sidebar-menu-text">Auto Messages</span>
                        </a>
                    </li>
                    <li>
                        <a href="edit_counters.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-pen-to-square"></i></span>
                            <span class="sidebar-menu-text">Stream Counters</span>
                        </a>
                    </li>
                    <li>
                        <a href="bot_points.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-coins"></i></span>
                            <span class="sidebar-menu-text">Loyalty Points</span>
                        </a>
                    </li>
                    <li>
                        <a href="subathon.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-hourglass-half"></i></span>
                            <span class="sidebar-menu-text">Subathon</span>
                        </a>
                    </li>
                    <li>
                        <a href="known_users.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-users"></i></span>
                            <span class="sidebar-menu-text">Welcome Messages</span>
                        </a>
                    </li>
                    <li>
                        <a href="channel_rewards.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-gift"></i></span>
                            <span class="sidebar-menu-text">Channel Rewards</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_analytics'); ?></span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_analytics'); ?></div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="logs.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_bot_logs'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="counters.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-calculator"></i></span>
                            <span class="sidebar-menu-text">Counter Stats</span>
                        </a>
                    </li>
                    <li><hr class="navbar-divider" style="margin: 0.5rem 0; background-color: #333;"></li>
                    <li>
                        <a href="followers.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-user-plus"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_followers'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="subscribers.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-star"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_subscribers'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="mods.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-user-shield"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_moderators'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="vips.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-crown"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_vips'); ?></span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-video"></i></span>
                    <span class="sidebar-menu-text">Stream Tools</span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip">Stream Tools</div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="streaming.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-video"></i></span>
                            <span class="sidebar-menu-text">Recording</span>
                        </a>
                    </li>
                    <li>
                        <a href="overlays.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-layer-group"></i></span>
                            <span class="sidebar-menu-text">Overlays</span>
                        </a>
                    </li>
                    <li><hr class="navbar-divider" style="margin: 0.5rem 0; background-color: #333;"></li>
                    <li>
                        <a href="sound-alerts.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-volume-up"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_sound_alerts'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="video-alerts.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-film"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_video_alerts'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="walkons.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-door-open"></i></span>
                            <span class="sidebar-menu-text">Walk-on Alerts</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item has-submenu">
                <a href="#" class="sidebar-menu-link" onclick="toggleSubmenu(event, this)">
                    <span class="sidebar-menu-icon"><i class="fas fa-plug"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_integrations'); ?></span>
                    <span class="sidebar-submenu-toggle"><i class="fas fa-chevron-down"></i></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_integrations'); ?></div>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="modules.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fa fa-puzzle-piece"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_specter_modules'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="discordbot.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fab fa-discord"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_discord_bot'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="spotifylink.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fab fa-spotify"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_spotify'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="streamelements.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-globe"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_streamelements'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="bingo.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-trophy"></i></span>
                            <span class="sidebar-menu-text">Stream Bingo</span>
                        </a>
                    </li>
                    <li><hr class="navbar-divider" style="margin: 0.5rem 0; background-color: #333;"></li>
                    <li>
                        <a href="integrations.php" class="sidebar-submenu-link">
                            <span class="sidebar-submenu-icon"><i class="fas fa-globe"></i></span>
                            <span class="sidebar-menu-text"><?php echo t('navbar_platform_integrations'); ?></span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-menu-item">
                <a href="premium.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-crown"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_premium'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_premium'); ?></div>
            </li>
            <li class="sidebar-menu-item">
                <a href="music.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-music"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_vod_music'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_vod_music'); ?></div>
            </li>
            <li class="sidebar-menu-item">
                <a href="todolist/index.php" class="sidebar-menu-link">
                    <span class="sidebar-menu-icon"><i class="fas fa-list-check"></i></span>
                    <span class="sidebar-menu-text"><?php echo t('navbar_todo_list'); ?></span>
                </a>
                <div class="sidebar-tooltip"><?php echo t('navbar_todo_list'); ?></div>
            </li>
        </ul>
        <div class="sidebar-user-section">
            <?php if (!empty($showModDropdown) && !empty($modChannels)): ?>
            <a href="mod_channels.php" class="sidebar-user-item">
                <span class="sidebar-user-icon"><i class="fas fa-user-shield"></i></span>
                <span class="sidebar-user-text">Mod Channels</span>
            </a>
            <?php endif; ?>
            <?php if (!empty($is_admin)): ?>
            <a href="admin/" class="sidebar-user-item" title="<?php echo t('navbar_admin_panel'); ?>">
                <span class="sidebar-user-icon"><i class="fas fa-shield-alt has-text-danger"></i></span>
                <span class="sidebar-user-text"><?php echo t('navbar_admin_panel'); ?></span>
            </a>
            <?php endif; ?>
            <a href="profile.php" class="sidebar-user-item">
                <span class="sidebar-user-icon"><i class="fas fa-id-card"></i></span>
                <span class="sidebar-user-text"><?php echo isset($_SESSION['username']) ? $_SESSION['username'] : 'User'; ?></span>
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
    <!-- Top Navigation Bar (Mobile/Tablet Only - Hidden on Desktop) -->
    <nav class="navbar is-dark top-navbar mobile-only" id="topNavbar" role="navigation" aria-label="main navigation">
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
                        <span class="icon"><i class="fas fa-terminal"></i></span>
                        <span>Commands</span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="custom_commands.php">
                            <span class="icon"><i class="fas fa-terminal"></i></span>
                            <span>Custom Commands</span>
                        </a>
                        <a class="navbar-item" href="manage_custom_user_commands.php">
                            <span class="icon"><i class="fas fa-user-cog"></i></span>
                            <span>User Commands</span>
                        </a>
                        <a class="navbar-item" href="builtin.php">
                            <span class="icon"><i class="fas fa-terminal"></i></span>
                            <span>Builtin Commands</span>
                        </a>
                    </div>
                </div>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-cogs"></i></span>
                        <span>Settings</span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="timed_messages.php">
                            <span class="icon"><i class="fas fa-clock"></i></span>
                            <span>Auto Messages</span>
                        </a>
                        <a class="navbar-item" href="edit_counters.php">
                            <span class="icon"><i class="fas fa-pen-to-square"></i></span>
                            <span>Stream Counters</span>
                        </a>
                        <a class="navbar-item" href="bot_points.php">
                            <span class="icon"><i class="fas fa-coins"></i></span>
                            <span>Loyalty Points</span>
                        </a>
                        <a class="navbar-item" href="subathon.php">
                            <span class="icon"><i class="fas fa-hourglass-half"></i></span>
                            <span>Subathon</span>
                        </a>
                        <a class="navbar-item" href="known_users.php">
                            <span class="icon"><i class="fas fa-users"></i></span>
                            <span>Welcome Messages</span>
                        </a>
                        <a class="navbar-item" href="channel_rewards.php">
                            <span class="icon"><i class="fas fa-gift"></i></span>
                            <span>Channel Rewards</span>
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
                            <span>Counter Stats</span>
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
                        <span class="icon"><i class="fas fa-video"></i></span>
                        <span>Stream Tools</span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="streaming.php">
                            <span class="icon"><i class="fas fa-video"></i></span>
                            <span>Recording</span>
                        </a>
                        <a class="navbar-item" href="overlays.php">
                            <span class="icon"><i class="fas fa-layer-group"></i></span>
                            <span>Overlays</span>
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
                            <span>Walk-on Alerts</span>
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
                        <a class="navbar-item" href="bingo.php">
                            <span class="icon"><i class="fas fa-trophy"></i></span>
                            <span>Stream Bingo</span>
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
                <div class="navbar-item">
                    <a href="mod_channels.php" title="<?php echo t('navbar_mod_channels'); ?>">
                        <span class="icon"><i class="fas fa-user-shield"></i></span>
                    </a>
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
<footer class="footer is-dark has-text-white" style="width:100%; display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
    <div class="is-hidden-mobile">
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
<script src="js/dashboard.js?v=<?php echo uuidv4(); ?>"></script>
<script src="/js/search.js?v=<?php echo uuidv4(); ?>"></script>
<script src="/js/bulmaModals.js?v=<?php echo uuidv4(); ?>"></script>
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