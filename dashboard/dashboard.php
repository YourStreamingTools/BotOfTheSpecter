<?php
// Dashboard landing page - main entry point.
// Cookie + session config is owned by the shared bootstrap so the cookie
// is scoped to .botofthespecter.com and the session row lives in
// website.web_sessions. Do not re-call session_set_cookie_params() here:
// passing domain="" used to override the bootstrap's .botofthespecter.com
// scope, which broke the shared login across home/dashboard/support/members.
require_once '/var/www/lib/session_bootstrap.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['access_token']);
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];

if ($isLoggedIn) {
    // User is logged in - show dashboard interface
    // Include authentication and user data
    require_once "/var/www/config/db_connect.php";
    include '/var/www/config/twitch.php';
    include '/var/www/config/ssh.php';
    include 'userdata.php';
    $pageTitle = t('dashboard_page_title_management');
    include 'bot_control.php';
    include "mod_access.php";
    include_once 'usr_database.php';
    include 'user_db.php';
    include 'storage_used.php';
session_write_close();

    // Channel info metrics (followers, subscribers, raids)
    $followerCount = 0;
    $subscriberCount = null;
    $subscriberSubtext = t('dashboard_live_twitch_subscriptions');
    $raidCount = 0;
    $raidViewersTotal = 0;
    $raidUniqueRaiders = 0;

    if (isset($db) && $db instanceof mysqli) {
        $followersResult = $db->query("SELECT COUNT(*) AS total FROM followers_data");
        if ($followersResult) {
            $followersRow = $followersResult->fetch_assoc();
            $followerCount = (int)($followersRow['total'] ?? 0);
        }

        $raidsResult = $db->query("SELECT
            COALESCE(SUM(CASE WHEN raid_count IS NULL OR raid_count < 1 THEN 1 ELSE raid_count END), 0) AS total_raids,
            COALESCE(SUM(viewers), 0) AS total_viewers,
            COUNT(DISTINCT raider_id) AS unique_raiders
            FROM raid_data");
        if ($raidsResult) {
            $raidsRow = $raidsResult->fetch_assoc();
            $raidCount = (int)($raidsRow['total_raids'] ?? 0);
            $raidViewersTotal = (int)($raidsRow['total_viewers'] ?? 0);
            $raidUniqueRaiders = (int)($raidsRow['unique_raiders'] ?? 0);
        }
    }

    // Twitch subscriber count (live from Twitch API)
    if (!empty($broadcasterID) && !empty($authToken) && !empty($clientID)) {
        $usersUrl = "https://api.twitch.tv/helix/users?id=" . rawurlencode((string)$broadcasterID);
        $usersCurl = curl_init($usersUrl);
        curl_setopt($usersCurl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Client-ID: ' . $clientID,
        ]);
        curl_setopt($usersCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($usersCurl, CURLOPT_TIMEOUT, 8);
        $usersResponse = curl_exec($usersCurl);
        $usersHttpCode = curl_getinfo($usersCurl, CURLINFO_HTTP_CODE);
        curl_close($usersCurl);

        $isEligibleForSubs = false;
        if ($usersResponse !== false && $usersHttpCode === 200) {
            $usersData = json_decode($usersResponse, true);
            $broadcasterType = (string)($usersData['data'][0]['broadcaster_type'] ?? '');
            $isEligibleForSubs = ($broadcasterType !== '');
        }

        if ($isEligibleForSubs) {
            $subsUrl = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=" . rawurlencode((string)$broadcasterID);
            $subsCurl = curl_init($subsUrl);
            curl_setopt($subsCurl, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $authToken,
                'Client-ID: ' . $clientID,
            ]);
            curl_setopt($subsCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($subsCurl, CURLOPT_TIMEOUT, 8);
            $subsResponse = curl_exec($subsCurl);
            $subsHttpCode = curl_getinfo($subsCurl, CURLINFO_HTTP_CODE);
            curl_close($subsCurl);

            if ($subsResponse !== false && $subsHttpCode === 200) {
                $subsData = json_decode($subsResponse, true);
                if (isset($subsData['total'])) {
                    $subscriberCount = (int)$subsData['total'];
                    $subscriberSubtext = t('dashboard_live_twitch_subscriptions');
                } else {
                    $subscriberSubtext = t('dashboard_unable_read_subscriber_total');
                }
            } else {
                $subscriberSubtext = t('dashboard_unable_fetch_subscribers');
            }
        } else {
            $subscriberSubtext = t('dashboard_not_affiliate_partner');
        }
    } else {
        $subscriberSubtext = t('dashboard_missing_twitch_auth');
    }

    // Dashboard metrics (real data only)
    $stableRunning = !empty($botSystemStatus);
    $betaRunning = !empty($betaBotSystemStatus);
    $v6Running = !empty($v6BotSystemStatus);
    $botsOnlineCount = (int)$stableRunning + (int)$betaRunning + (int)$v6Running;
    $storagePercent = max(0, min(100, (float)$storage_percentage));
    $storageUsedMb = round(((float)$current_storage_used / 1024 / 1024), 2);
    $storageMaxMb = round(((float)$max_storage_size / 1024 / 1024), 2);
    $customCommandCount = is_array($commands) ? count($commands) : 0;
    $builtinCommandCount = is_array($builtinCommands) ? count($builtinCommands) : 0;
    $builtinEnabledCount = 0;
    if (is_array($builtinCommands)) {
        foreach ($builtinCommands as $builtinCommand) {
            if (isset($builtinCommand['status']) && strcasecmp((string)$builtinCommand['status'], 'Enabled') === 0) {
                $builtinEnabledCount++;
            }
        }
    }
    $rewardsCount = is_array($channelPointRewards) ? count($channelPointRewards) : 0;
    $quotesCount = is_array($quotesData) ? count($quotesData) : 0;
    $knownUsersCount = is_array($seenUsersData) ? count($seenUsersData) : 0;
    $modChannelCount = is_array($modChannels) ? count($modChannels) : 0;
    $liveLurkersCount = is_array($lurkers) ? count($lurkers) : 0;
    $watchUsersCount = is_array($watchTimeData) ? count($watchTimeData) : 0;
    $todoTotalCount = is_array($todos) ? count($todos) : 0;
    $todoCompletedCount = 0;
    if (is_array($todos)) {
        foreach ($todos as $todoItem) {
            if (isset($todoItem['completed']) && strcasecmp((string)$todoItem['completed'], 'Yes') === 0) {
                $todoCompletedCount++;
            }
        }
    }
    $todoOpenCount = max(0, $todoTotalCount - $todoCompletedCount);
    $stableRunningVersion = $stableRunning ? ($versionRunning ?? t('dashboard_version_unknown')) : t('dashboard_not_running');
    $betaRunningVersion = $betaRunning ? ($betaVersionRunning ?? t('dashboard_version_unknown')) : t('dashboard_not_running');
    $v6RunningVersion = $v6Running ? ($v6VersionRunning ?? t('dashboard_version_unknown')) : t('dashboard_not_running');
    $stableLatestVersion = $newVersion ?? t('dashboard_version_na');
    $betaLatestVersion = $betaNewVersion ?? t('dashboard_version_na');
    $v6LatestVersion = $v6NewVersion ?? t('dashboard_version_na');
    // Determine single active bot runtime (only one should run at a time)
    $activeBotSystem = 'none';
    $activeBotLabel = t('dashboard_not_running');
    $activeBotRunning = false;
    $activeBotCurrentVersion = t('dashboard_not_running');
    $activeBotLatestVersion = t('dashboard_version_na');
    if ($stableRunning) {
        $activeBotSystem = 'stable';
        $activeBotLabel = 'Stable';
        $activeBotRunning = true;
        $activeBotCurrentVersion = (string)$stableRunningVersion;
        $activeBotLatestVersion = (string)$stableLatestVersion;
    } elseif ($betaRunning) {
        $activeBotSystem = 'beta';
        $activeBotLabel = 'Beta';
        $activeBotRunning = true;
        $activeBotCurrentVersion = (string)$betaRunningVersion;
        $activeBotLatestVersion = (string)$betaLatestVersion;
    } elseif ($v6Running) {
        $activeBotSystem = 'v6';
        $activeBotLabel = 'v6';
        $activeBotRunning = true;
        $activeBotCurrentVersion = (string)$v6RunningVersion;
        $activeBotLatestVersion = (string)$v6LatestVersion;
    }
    // Start output buffering for layout system
    ob_start();
    ?>
    <!-- Welcome -->
    <div class="sp-page-header">
        <h1><i class="fas fa-robot"></i> <?= t('dashboard_welcome') ?>, <?php echo htmlspecialchars($twitchDisplayName); ?>!</h1>
        <p><?= t('dashboard_welcome_subtitle') ?></p>
    </div>
    <!-- Channel Info Stats -->
    <div class="db-section-label"><?= t('dashboard_channel_info') ?></div>
    <div class="sp-stat-row" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); margin-bottom: 2rem;">
        <div class="sp-stat">
            <div class="sp-stat-label"><?= t('dashboard_followers') ?></div>
            <div class="sp-stat-value"><?php echo number_format($followerCount); ?></div>
            <div class="sp-stat-sub"><?= t('dashboard_tracked_follower_records') ?></div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label"><?= t('dashboard_subscribers') ?></div>
            <div class="sp-stat-value"><?php echo $subscriberCount !== null ? number_format($subscriberCount) : t('dashboard_version_na'); ?></div>
            <div class="sp-stat-sub"><?php echo htmlspecialchars($subscriberSubtext); ?></div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label"><?= t('dashboard_raids') ?></div>
            <div class="sp-stat-value"><?php echo number_format($raidCount); ?></div>
            <div class="sp-stat-sub"><?php echo number_format($raidViewersTotal); ?> <?= t('dashboard_total_raider_viewers') ?> &middot; <?php echo number_format($raidUniqueRaiders); ?> <?= t('dashboard_unique_raiders') ?></div>
        </div>
    </div>
    <!-- Main Metrics -->
    <div class="db-section-label"><?= t('dashboard_dashboard_metrics') ?></div>
    <div class="sp-stat-row" style="margin-bottom: 2rem;">
        <div class="sp-stat <?php echo $activeBotRunning ? 'online' : 'offline'; ?>">
            <div class="sp-stat-label"><?= t('dashboard_active_bot') ?></div>
            <div class="sp-stat-value"><?php echo htmlspecialchars($activeBotLabel); ?></div>
            <div class="sp-stat-sub">
                <?php if ($activeBotRunning): ?>
                    v<?php echo htmlspecialchars($activeBotCurrentVersion); ?> &bull; <?php echo htmlspecialchars($activeBotLabel); ?>
                <?php else: ?>
                    <?= t('dashboard_no_active_bot_runtime') ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="sp-stat<?php echo $storagePercent >= 90 ? ' warn' : ''; ?>">
            <div class="sp-stat-label"><?= t('dashboard_storage_usage') ?></div>
            <div class="sp-stat-value"><?php echo number_format($storagePercent, 1); ?>%</div>
            <div class="sp-stat-sub"><?php echo number_format($storageUsedMb, 2); ?> MB <?= t('dashboard_of') ?> <?php echo number_format($storageMaxMb, 2); ?> MB</div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label"><?= t('dashboard_commands') ?></div>
            <div class="sp-stat-value"><?php echo $customCommandCount + $builtinEnabledCount; ?></div>
            <div class="sp-stat-sub"><?php echo $customCommandCount; ?> <?= t('dashboard_custom') ?> &middot; <?php echo $builtinEnabledCount; ?>/<?php echo $builtinCommandCount; ?> <?= t('dashboard_builtin') ?></div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label"><?= t('dashboard_todo_progress') ?></div>
            <div class="sp-stat-value"><?php echo $todoCompletedCount; ?>/<?php echo $todoTotalCount; ?></div>
            <div class="sp-stat-sub"><?php echo $todoOpenCount; ?> <?php echo $todoOpenCount !== 1 ? t('dashboard_tasks_remaining') : t('dashboard_task_remaining'); ?></div>
        </div>
    </div>
    <!-- Runtime + Activity -->
    <div class="db-two-col">
        <div class="sp-card">
            <div class="sp-card-header">
                <span class="sp-card-title"><i class="fas fa-server"></i> <?= t('dashboard_bot_runtime') ?></span>
                <span class="sp-badge <?php echo $activeBotRunning ? 'sp-badge-green' : 'sp-badge-red'; ?>"><?php echo $activeBotRunning ? t('dashboard_running') : t('dashboard_stopped'); ?></span>
            </div>
            <div class="sp-card-body">
                <p style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;"><?= t('dashboard_system') ?>: <?php echo htmlspecialchars($activeBotLabel); ?></p>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.25rem;"><?= t('dashboard_current') ?>: <?php echo htmlspecialchars($activeBotCurrentVersion); ?></p>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem;"><?= t('dashboard_latest') ?>: <?php echo htmlspecialchars($activeBotLatestVersion); ?></p>
                <a href="bot.php" class="sp-btn sp-btn-primary">
                    <i class="fas fa-cogs"></i> <?= t('dashboard_open_bot_control') ?>
                </a>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">
                <span class="sp-card-title"><i class="fas fa-chart-line"></i> <?= t('dashboard_activity_snapshot') ?></span>
            </div>
            <div class="sp-card-body">
                <div class="db-snapshot-item"><span><?= t('dashboard_known_users') ?></span><strong><?php echo number_format($knownUsersCount); ?></strong></div>
                <div class="db-snapshot-item"><span><?= t('dashboard_live_lurkers') ?></span><strong><?php echo number_format($liveLurkersCount); ?></strong></div>
                <div class="db-snapshot-item"><span><?= t('dashboard_watch_time_profiles') ?></span><strong><?php echo number_format($watchUsersCount); ?></strong></div>
                <div class="db-snapshot-item"><span><?= t('dashboard_rewards_configured') ?></span><strong><?php echo number_format($rewardsCount); ?></strong></div>
                <div class="db-snapshot-item"><span><?= t('dashboard_quotes_saved') ?></span><strong><?php echo number_format($quotesCount); ?></strong></div>
                <div class="db-snapshot-item"><span><?= t('dashboard_moderator_channels') ?></span><strong><?php echo number_format($modChannelCount); ?></strong></div>
            </div>
        </div>
    </div>
    <!-- Quick Links -->
    <div class="db-section-label"><?= t('dashboard_quick_links') ?></div>
    <div class="db-quick-grid">
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--blue);"><i class="fas fa-robot fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_bot_control') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_bot_control_desc') ?></p>
                <a href="bot.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cogs"></i> <?= t('dashboard_manage_bot') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--green);"><i class="fas fa-terminal fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_commands') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_commands_desc') ?></p>
                <a href="custom_commands.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-plus"></i> <?= t('dashboard_edit_commands') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--accent-hover);"><i class="fab fa-discord fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_discord_bot') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_discord_bot_desc') ?></p>
                <a href="discordbot.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cog"></i> <?= t('dashboard_manage_discord') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--amber);"><i class="fas fa-list-check fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_todo_list') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_todo_list_desc') ?></p>
                <a href="../todolist" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-list"></i> <?= t('dashboard_open_todo') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--red);"><i class="fas fa-gift fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_rewards') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_rewards_desc') ?></p>
                <a href="channel_rewards.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-star"></i> <?= t('dashboard_setup_rewards') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--blue);"><i class="fas fa-plug fa-2x"></i></div>
                <h4 class="db-quick-title"><?php echo t('obsconnector_title'); ?></h4>
                <p class="db-quick-desc"><?php echo t('obsconnector_banner_title'); ?></p>
                <a href="controllerapp.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cogs"></i> <?php echo t('obsconnector_title'); ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--blue);"><i class="fas fa-music fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_dmca_music') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_dmca_music_desc') ?></p>
                <a href="music.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-play"></i> <?= t('dashboard_browse_music') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--text-muted);"><i class="fas fa-book fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_documentation') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_documentation_desc') ?></p>
                <a href="/api/generate_handoff.php" class="sp-btn sp-btn-secondary" style="width: 100%; justify-content: center;"><i class="fas fa-external-link-alt"></i> <?= t('dashboard_view_docs') ?></a>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    include "layout.php";
} else {
    // User is not logged in - show landing page
    // This branch renders its own HTML document (no layout.php), so load the
    // i18n helper here to make t() available for the landing-page strings.
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    include_once __DIR__ . '/lang/i18n.php';
    $pageTitle = t('dashboard_page_title_landing');
    ?>
    <!DOCTYPE html>
    <html lang="en">
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
        <title>BotOfTheSpecter - <?php echo $pageTitle; ?></title>
        <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
        <link rel="stylesheet" href="/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/css/dashboard.css'); ?>">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
        <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    </head>
    <body>
        <!-- Top nav -->
        <header class="db-topnav">
            <a href="dashboard.php" class="db-topnav-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter">
                BotOfTheSpecter
            </a>
            <button class="sp-theme-toggle" id="spThemeToggle" type="button" aria-label="<?= htmlspecialchars(t('dashboard_toggle_theme_aria')) ?>" title="<?= htmlspecialchars(t('dashboard_toggle_theme_title')) ?>">
                <i class="fas fa-moon"></i>
            </button>
            <a href="login.php" class="sp-btn sp-btn-primary" style="border-radius: var(--radius-pill);"><i class="fab fa-twitch"></i> <?= t('dashboard_login_with_twitch') ?></a>
        </header>
        <!-- Hero -->
        <section class="db-hero">
            <h1><i class="fas fa-robot"></i> <?= t('dashboard_hero_title') ?></h1>
            <p class="db-hero-sub"><?= t('dashboard_hero_sub') ?></p>
            <p class="db-hero-desc"><?= t('dashboard_hero_desc') ?></p>
        </section>
        <!-- Login card -->
        <div class="db-login-card">
            <h3><i class="fas fa-sign-in-alt"></i> <?= t('dashboard_access_your_dashboard') ?></h3>
            <p><?= t('dashboard_access_desc') ?></p>
            <a href="login.php" class="db-twitch-btn"><i class="fab fa-twitch"></i> <?= t('dashboard_login_with_twitch') ?></a>
            <p class="db-login-note"><i class="fas fa-shield-alt"></i> <?= t('dashboard_data_secure_note') ?></p>
        </div>
        <!-- Features -->
        <div class="db-landing-section">
            <div class="db-landing-section-header">
                <h2><?= t('dashboard_features_title') ?></h2>
                <p><?= t('dashboard_features_subtitle') ?></p>
            </div>
            <div class="db-features-grid">
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-robot"></i></div>
                    <h4><?= t('dashboard_feature_bot_control') ?></h4>
                    <p><?= t('dashboard_feature_bot_control_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--green);"><i class="fas fa-terminal"></i></div>
                    <h4><?= t('dashboard_feature_custom_commands') ?></h4>
                    <p><?= t('dashboard_feature_custom_commands_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--amber);"><i class="fas fa-chart-line"></i></div>
                    <h4><?= t('dashboard_feature_analytics_logs') ?></h4>
                    <p><?= t('dashboard_feature_analytics_logs_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--red);"><i class="fas fa-gift"></i></div>
                    <h4><?= t('dashboard_feature_channel_rewards') ?></h4>
                    <p><?= t('dashboard_feature_channel_rewards_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-volume-up"></i></div>
                    <h4><?= t('dashboard_feature_stream_alerts') ?></h4>
                    <p><?= t('dashboard_feature_stream_alerts_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--accent-hover);"><i class="fas fa-plug"></i></div>
                    <h4><?= t('dashboard_feature_integrations') ?></h4>
                    <p><?= t('dashboard_feature_integrations_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--amber);"><i class="fas fa-coins"></i></div>
                    <h4><?= t('dashboard_feature_points_system') ?></h4>
                    <p><?= t('dashboard_feature_points_system_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-layer-group"></i></div>
                    <h4><?= t('dashboard_feature_stream_overlays') ?></h4>
                    <p><?= t('dashboard_feature_stream_overlays_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--green);"><i class="fas fa-users"></i></div>
                    <h4><?= t('dashboard_feature_user_management') ?></h4>
                    <p><?= t('dashboard_feature_user_management_desc') ?></p>
                </div>
            </div>
        </div>
        <!-- Premium Plans -->
        <div class="db-landing-section" style="padding-top: 0;">
            <div class="db-landing-section-header">
                <h2><?= t('dashboard_premium_plans') ?></h2>
                <p><?= t('dashboard_premium_plans_subtitle') ?></p>
            </div>
            <div class="db-plans-grid">
                <!-- Free Plan -->
                <div class="db-plan-card">
                    <div class="db-plan-card-icon" style="color: var(--text-muted);"><i class="fas fa-rocket"></i></div>
                    <h3><?= t('dashboard_plan_free') ?></h3>
                    <div class="db-plan-price"><?= t('dashboard_plan_free_price') ?></div>
                    <ul>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_core_bot_features') ?></li>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_community_support') ?></li>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_20mb_storage') ?></li>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_shared_bot_name') ?></li>
                        <li><i class="fas fa-flask"></i> <?= t('dashboard_plan_custom_bot_name') ?></li>
                    </ul>
                    <a href="login.php" class="sp-btn sp-btn-success" style="width: 100%; justify-content: center;"><i class="fas fa-sign-in-alt"></i> <?= t('dashboard_get_started') ?></a>
                    <p style="font-size: 0.75rem; color: var(--text-muted); text-align: center; margin-top: 0.75rem;"><?= t('dashboard_free_percent_note') ?></p>
                </div>
                <?php
                $plans = [
                    '1000' => [
                        'name' => 'Tier 1',
                        'price' => '$4.99/month',
                        'features' => [t('dashboard_plan_song_request_command'), t('dashboard_plan_priority_support'), t('dashboard_plan_beta_access'), t('dashboard_plan_50mb_storage')],
                        'icon' => 'fas fa-star',
                        'icon_color' => 'var(--blue)',
                    ],
                    '2000' => [
                        'name' => 'Tier 2',
                        'price' => '$9.99/month',
                        'features' => [t('dashboard_plan_everything_tier1'), t('dashboard_plan_personal_support'), t('dashboard_plan_ai_features'), t('dashboard_plan_100mb_storage')],
                        'icon' => 'fas fa-crown',
                        'icon_color' => 'var(--amber)',
                    ],
                    '3000' => [
                        'name' => 'Tier 3',
                        'price' => '$24.99/month',
                        'features' => [t('dashboard_plan_everything_tier2'), t('dashboard_plan_200mb_storage')],
                        'icon' => 'fas fa-gem',
                        'icon_color' => 'var(--red)',
                    ],
                ];
                foreach ($plans as $planDetails): ?>
                <div class="db-plan-card">
                    <div class="db-plan-card-icon" style="color: <?php echo $planDetails['icon_color']; ?>;"><i class="<?php echo $planDetails['icon']; ?>"></i></div>
                    <h3><?php echo htmlspecialchars($planDetails['name']); ?></h3>
                    <div class="db-plan-price"><?php echo htmlspecialchars($planDetails['price']); ?></div>
                    <ul>
                        <?php foreach ($planDetails['features'] as $feature): ?>
                        <li><i class="fas fa-check"></i> <?php echo htmlspecialchars($feature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-plus-circle"></i> <?= t('dashboard_subscribe') ?></a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Footer -->
        <footer class="db-landing-footer">
            &copy; 2023&ndash;<?php echo date('Y'); ?> <?= t('dashboard_footer_rights') ?><br>
            <?php include '/var/www/config/project-time.php'; ?>
            <?= t('dashboard_footer_business_name') ?><br>
            <?= t('dashboard_footer_not_affiliated') ?><br>
            <?= t('dashboard_footer_trademarks') ?>
            <br><span class="sp-version-badge" style="margin-top: 0.5rem; display: inline-flex;"><?= t('dashboard_footer_version_label') ?> v<?php echo $dashboardVersion; ?></span>
        </footer>
        <script>
            // Light/dark theme toggle (landing top nav). The <head> bootstrap sets the initial theme.
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
                if (btn) btn.addEventListener('click', function () { apply(current() === 'light' ? 'dark' : 'light', true); });
                window.addEventListener('storage', function (e) {
                    if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) { apply(e.newValue, false); }
                });
            })();
        </script>
    </body>
    </html>
    <?php
}
?>