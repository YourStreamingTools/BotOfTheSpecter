<?php
// This file serves as a template for all moderator dashboard pages
include_once dirname(__FILE__) . "/../mod_access.php";

// Add language support for moderator layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once dirname(__FILE__) . "/../lang/i18n.php";

$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];

if (!isset($pageTitle)) $pageTitle = "BotOfTheSpecter Moderator";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter Moderator Dashboard";
if (!isset($pageContent)) $pageContent = "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'Moderator - BotOfTheSpecter Dashboard'; ?></title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.0/css/bulma.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/custom.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body style="min-height: 100vh; display: flex; flex-direction: column;">
    <!-- Moderator Notice Banner -->
    <div style="background:rgb(0, 123, 255); color: #fff; font-weight: bold; text-align: center; padding: 0.75rem 1rem; letter-spacing: 0.5px;">
        <span>
            You are using the <strong>MODERATOR</strong> dashboard. Actions here affect channels you moderate.
        </span>
    </div>
    <!-- Top Navigation Bar -->
    <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <a class="navbar-item" href="index.php">
                <img src="https://cdn.botofthespecter.com/logo.png" width="28" height="28" alt="BotOfTheSpecter Logo">
                <strong class="ml-2">BotOfTheSpecter Mod</strong>
            </a>
            <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarMain">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>
        <div id="navbarMain" class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item" href="index.php">
                    <span class="icon"><i class="fas fa-shield-alt"></i></span>
                    <span>Mod Dashboard</span>
                </a>                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-terminal"></i></span>
                        <span><?php echo t('navbar_commands'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="commands.php">
                            <span class="icon"><i class="fas fa-terminal"></i></span>
                            <span><?php echo t('navbar_view_custom_commands'); ?></span>
                        </a>
                        <a class="navbar-item" href="builtin.php">
                            <span class="icon"><i class="fas fa-cogs"></i></span>
                            <span><?php echo t('navbar_view_builtin_commands'); ?></span>
                        </a>
                        <a class="navbar-item" href="manage_custom_commands.php">
                            <span class="icon"><i class="fas fa-pen"></i></span>
                            <span><?php echo t('navbar_edit_custom_commands'); ?></span>
                        </a>
                    </div>
                </div>
                <a class="navbar-item" href="timed_messages.php">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span><?php echo t('navbar_timed_messages'); ?></span>
                </a>
                <a class="navbar-item" href="bot_points.php">
                    <span class="icon"><i class="fas fa-coins"></i></span>
                    <span><?php echo t('navbar_points_system'); ?></span>
                </a>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-calculator"></i></span>
                        <span><?php echo t('navbar_counters'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="counters.php">
                            <span class="icon"><i class="fas fa-calculator"></i></span>
                            <span><?php echo t('navbar_counters'); ?></span>
                        </a>
                        <a class="navbar-item" href="edit_counters.php">
                            <span class="icon"><i class="fas fa-edit"></i></span>
                            <span><?php echo t('navbar_edit_counters'); ?></span>
                        </a>
                    </div>
                </div>
                <a class="navbar-item" href="known_users.php">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span><?php echo t('known_users_title'); ?></span>
                </a>                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-bell"></i></span>
                        <span><?php echo t('navbar_alerts'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
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
            </div>
            <div class="navbar-end">
                <?php if (!empty($showModDropdown) && !empty($modChannels)): ?>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-user-shield"></i></span>
                        <span><?php echo t('navbar_mod_for'); ?></span>
                    </a>
                    <div class="navbar-dropdown">
                        <?php foreach ($modChannels as $modChannel): ?>
                            <a class="navbar-item" href="../switch_channel.php?user_id=<?php echo urlencode($modChannel['twitch_user_id']); ?>">
                                <span class="icon">
                                    <img src="<?php echo htmlspecialchars($modChannel['profile_image']); ?>" alt="" style="width: 24px; height: 24px; border-radius: 50%;">
                                </span>
                                <span><?php echo htmlspecialchars($modChannel['twitch_display_name']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link" style="display: flex; align-items: center;">
                        <span>
                            <?php echo isset($_SESSION['editing_display_name']) ? htmlspecialchars($_SESSION['editing_display_name']) : t('navbar_channel'); ?>
                        </span>
                        <?php if (!empty($_SESSION['editing_profile_image'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['editing_profile_image']); ?>" alt="Profile" style="width: 28px; height: 28px; border-radius: 50%; margin-left: 8px;">
                        <?php endif; ?>
                    </a>
                    <div class="navbar-dropdown is-right">
                        <a class="navbar-item" href="mod_return_home.php">
                            <span class="icon"><i class="fas fa-home"></i></span>
                            <span><?php echo t('navbar_return_home'); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <!-- Main content -->
    <div class="columns" style="flex: 1;">
        <div class="column is-10 is-offset-1 main-content">
            <section class="section">
                <div class="container is-fluid">
                    <?php echo $content; ?>
                </div>
            </section>
        </div>
    </div>
    <!-- Footer -->
    <footer class="footer is-dark has-text-white" style="width:100%; display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; margin-top: auto;">
        <div style="max-width: 1500px;">
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
    </footer>
    <!-- JavaScript dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Custom Update Script -->
    <script src="https://uptime.botofthespecter.com/en/cca64861/widget/script.js"></script>
    <!-- Custom JS -->
    <script src="../js/dashboard.js"></script>
    <?php if (!empty($scripts)) { echo $scripts; } ?>
</body>
</html>
