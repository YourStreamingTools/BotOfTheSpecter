<?php
// Dashboard landing page - main entry point

// Set session timeout to 24 hours (86400 seconds)
session_set_cookie_params(86400, "/", "", true, true);
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);

// Start session
session_start();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['access_token']);
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];

if ($isLoggedIn) {
    // User is logged in - show dashboard interface
    $pageTitle = 'Management Dashboard';
    // Include authentication and user data
    require_once "/var/www/config/db_connect.php";
    include '/var/www/config/twitch.php';
    include '/var/www/config/ssh.php';
    include 'userdata.php';
    include 'bot_control.php';
    include "mod_access.php";
    include_once 'usr_database.php';
    include 'user_db.php';
    include 'storage_used.php';

    // Channel info metrics (followers, subscribers, raids)
    $followerCount = 0;
    $subscriberCount = null;
    $subscriberSubtext = 'Live Twitch subscriptions';
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
                    $subscriberSubtext = 'Live Twitch subscriptions';
                } else {
                    $subscriberSubtext = 'Unable to read subscriber total from Twitch';
                }
            } else {
                $subscriberSubtext = 'Unable to fetch subscribers from Twitch right now';
            }
        } else {
            $subscriberSubtext = 'Channel is not Affiliate/Partner (no Twitch subs endpoint access)';
        }
    } else {
        $subscriberSubtext = 'Missing Twitch auth/config for live subscriber count';
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
    $stableRunningVersion = $stableRunning ? ($versionRunning ?? 'Unknown') : 'Not Running';
    $betaRunningVersion = $betaRunning ? ($betaVersionRunning ?? 'Unknown') : 'Not Running';
    $v6RunningVersion = $v6Running ? ($v6VersionRunning ?? 'Unknown') : 'Not Running';
    $stableLatestVersion = $newVersion ?? 'N/A';
    $betaLatestVersion = $betaNewVersion ?? 'N/A';
    $v6LatestVersion = $v6NewVersion ?? 'N/A';
    // Determine single active bot runtime (only one should run at a time)
    $activeBotSystem = 'none';
    $activeBotLabel = 'Not Running';
    $activeBotRunning = false;
    $activeBotCurrentVersion = 'Not Running';
    $activeBotLatestVersion = 'N/A';
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
        <h1><i class="fas fa-robot"></i> Welcome, <?php echo htmlspecialchars($twitchDisplayName); ?>!</h1>
        <p>Live overview of your bot systems, storage, and community activity.</p>
    </div>
    <!-- Channel Info Stats -->
    <div class="db-section-label">Channel Info</div>
    <div class="sp-stat-row" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); margin-bottom: 2rem;">
        <div class="sp-stat">
            <div class="sp-stat-label">Followers</div>
            <div class="sp-stat-value"><?php echo number_format($followerCount); ?></div>
            <div class="sp-stat-sub">Tracked follower records</div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label">Subscribers</div>
            <div class="sp-stat-value"><?php echo $subscriberCount !== null ? number_format($subscriberCount) : 'N/A'; ?></div>
            <div class="sp-stat-sub"><?php echo htmlspecialchars($subscriberSubtext); ?></div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label">Raids</div>
            <div class="sp-stat-value"><?php echo number_format($raidCount); ?></div>
            <div class="sp-stat-sub"><?php echo number_format($raidViewersTotal); ?> total raider viewers &middot; <?php echo number_format($raidUniqueRaiders); ?> unique raiders</div>
        </div>
    </div>
    <!-- Main Metrics -->
    <div class="db-section-label">Dashboard Metrics</div>
    <div class="sp-stat-row" style="margin-bottom: 2rem;">
        <div class="sp-stat <?php echo $activeBotRunning ? 'online' : 'offline'; ?>">
            <div class="sp-stat-label">Active Bot</div>
            <div class="sp-stat-value"><?php echo htmlspecialchars($activeBotLabel); ?></div>
            <div class="sp-stat-sub">
                <?php if ($activeBotRunning): ?>
                    v<?php echo htmlspecialchars($activeBotCurrentVersion); ?> &bull; <?php echo htmlspecialchars($activeBotLabel); ?>
                <?php else: ?>
                    No active bot runtime
                <?php endif; ?>
            </div>
        </div>
        <div class="sp-stat<?php echo $storagePercent >= 90 ? ' warn' : ''; ?>">
            <div class="sp-stat-label">Storage Usage</div>
            <div class="sp-stat-value"><?php echo number_format($storagePercent, 1); ?>%</div>
            <div class="sp-stat-sub"><?php echo number_format($storageUsedMb, 2); ?> MB of <?php echo number_format($storageMaxMb, 2); ?> MB</div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label">Commands</div>
            <div class="sp-stat-value"><?php echo $customCommandCount + $builtinEnabledCount; ?></div>
            <div class="sp-stat-sub"><?php echo $customCommandCount; ?> Custom &middot; <?php echo $builtinEnabledCount; ?>/<?php echo $builtinCommandCount; ?> Built-in</div>
        </div>
        <div class="sp-stat">
            <div class="sp-stat-label">To-Do Progress</div>
            <div class="sp-stat-value"><?php echo $todoCompletedCount; ?>/<?php echo $todoTotalCount; ?></div>
            <div class="sp-stat-sub"><?php echo $todoOpenCount; ?> task<?php echo $todoOpenCount !== 1 ? 's' : ''; ?> remaining</div>
        </div>
    </div>
    <!-- Runtime + Activity -->
    <div class="db-two-col">
        <div class="sp-card">
            <div class="sp-card-header">
                <span class="sp-card-title"><i class="fas fa-server"></i> Bot Runtime</span>
                <span class="sp-badge <?php echo $activeBotRunning ? 'sp-badge-green' : 'sp-badge-red'; ?>"><?php echo $activeBotRunning ? 'Running' : 'Stopped'; ?></span>
            </div>
            <div class="sp-card-body">
                <p style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">System: <?php echo htmlspecialchars($activeBotLabel); ?></p>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.25rem;">Current: <?php echo htmlspecialchars($activeBotCurrentVersion); ?></p>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem;">Latest: <?php echo htmlspecialchars($activeBotLatestVersion); ?></p>
                <a href="bot.php" class="sp-btn sp-btn-primary">
                    <i class="fas fa-cogs"></i> Open Bot Control
                </a>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">
                <span class="sp-card-title"><i class="fas fa-chart-line"></i> Activity Snapshot</span>
            </div>
            <div class="sp-card-body">
                <div class="db-snapshot-item"><span>Known Users</span><strong><?php echo number_format($knownUsersCount); ?></strong></div>
                <div class="db-snapshot-item"><span>Live Lurkers</span><strong><?php echo number_format($liveLurkersCount); ?></strong></div>
                <div class="db-snapshot-item"><span>Watch Time Profiles</span><strong><?php echo number_format($watchUsersCount); ?></strong></div>
                <div class="db-snapshot-item"><span>Rewards Configured</span><strong><?php echo number_format($rewardsCount); ?></strong></div>
                <div class="db-snapshot-item"><span>Quotes Saved</span><strong><?php echo number_format($quotesCount); ?></strong></div>
                <div class="db-snapshot-item"><span>Moderator Channels</span><strong><?php echo number_format($modChannelCount); ?></strong></div>
            </div>
        </div>
    </div>
    <!-- Quick Links -->
    <div class="db-section-label">Quick Links</div>
    <div class="db-quick-grid">
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--blue);"><i class="fas fa-robot fa-2x"></i></div>
                <h4 class="db-quick-title">Bot Control</h4>
                <p class="db-quick-desc">Start, stop, and monitor your bot</p>
                <a href="bot.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cogs"></i> Manage Bot</a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--green);"><i class="fas fa-terminal fa-2x"></i></div>
                <h4 class="db-quick-title">Commands</h4>
                <p class="db-quick-desc">Create and edit custom commands</p>
                <a href="custom_commands.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-plus"></i> Edit Commands</a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--accent-hover);"><i class="fab fa-discord fa-2x"></i></div>
                <h4 class="db-quick-title">Discord Bot</h4>
                <p class="db-quick-desc">Manage your Discord integration</p>
                <a href="discordbot.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cog"></i> Manage Discord</a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--amber);"><i class="fas fa-list-check fa-2x"></i></div>
                <h4 class="db-quick-title">To-Do List</h4>
                <p class="db-quick-desc">Manage your streaming tasks</p>
                <a href="../todolist" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-list"></i> Open To-Do</a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--red);"><i class="fas fa-gift fa-2x"></i></div>
                <h4 class="db-quick-title">Rewards</h4>
                <p class="db-quick-desc">Manage channel rewards</p>
                <a href="channel_rewards.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-star"></i> Setup Rewards</a>
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
                <h4 class="db-quick-title">DMCA Music</h4>
                <p class="db-quick-desc">Safe music for streaming</p>
                <a href="music.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-play"></i> Browse Music</a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--text-muted);"><i class="fas fa-book fa-2x"></i></div>
                <h4 class="db-quick-title">Documentation</h4>
                <p class="db-quick-desc">Learn how to use BotOfTheSpecter</p>
                <a href="/generate_handoff.php" class="sp-btn sp-btn-secondary" style="width: 100%; justify-content: center;"><i class="fas fa-external-link-alt"></i> View Docs</a>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    include "layout.php";
} else {
    // User is not logged in - show landing page
    $pageTitle = 'Dashboard Information';
    // Function to generate a UUID v4 for cache busting
    function uuidv4() { return bin2hex(random_bytes(4)); }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>BotOfTheSpecter - <?php echo $pageTitle; ?></title>
        <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
        <link rel="stylesheet" href="/css/dashboard.css?v=<?php echo uuidv4(); ?>">
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
            <a href="login.php" class="sp-btn sp-btn-primary" style="border-radius: var(--radius-pill);"><i class="fab fa-twitch"></i> Login with Twitch</a>
        </header>
        <!-- Hero -->
        <section class="db-hero">
            <h1><i class="fas fa-robot"></i> BotOfTheSpecter Dashboard</h1>
            <p class="db-hero-sub">Your Complete Twitch Bot Management Solution</p>
            <p class="db-hero-desc">Take control of your Twitch channel with our powerful, feature-rich bot and dashboard. Manage commands, configure your alerts, track analytics, and so much more.</p>
        </section>
        <!-- Login card -->
        <div class="db-login-card">
            <h3><i class="fas fa-sign-in-alt"></i> Access Your Dashboard</h3>
            <p>Join the rest of the streamers who use BotOfTheSpecter to enhance and manage their Twitch channel.</p>
            <a href="login.php" class="db-twitch-btn"><i class="fab fa-twitch"></i> Login with Twitch</a>
            <p class="db-login-note"><i class="fas fa-shield-alt"></i> Your data is secure and protected using SHA-384 encryption.</p>
        </div>
        <!-- Features -->
        <div class="db-landing-section">
            <div class="db-landing-section-header">
                <h2>Dashboard Features</h2>
                <p>Explore the powerful features that make BotOfTheSpecter the ultimate Twitch bot management solution, with many more features that aren't listed below.</p>
            </div>
            <div class="db-features-grid">
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-robot"></i></div>
                    <h4>Bot Control</h4>
                    <p>Start, stop, and monitor your bot with real-time status updates and comprehensive logging.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--green);"><i class="fas fa-terminal"></i></div>
                    <h4>Custom Commands</h4>
                    <p>Create and manage custom chat commands with advanced features and permission levels.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--amber);"><i class="fas fa-chart-line"></i></div>
                    <h4>Analytics &amp; Logs</h4>
                    <p>Track your channel's growth, monitor user activity, and analyze command usage statistics.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--red);"><i class="fas fa-gift"></i></div>
                    <h4>Channel Rewards</h4>
                    <p>Manage Twitch channel point rewards and create engaging interactive experiences.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-volume-up"></i></div>
                    <h4>Stream Alerts</h4>
                    <p>Configure sound alerts, video alerts, and walk-on alerts for followers and subscribers.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--accent-hover);"><i class="fas fa-plug"></i></div>
                    <h4>Integrations</h4>
                    <p>Connect with Discord, Spotify, StreamElements, and other popular streaming platforms.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--amber);"><i class="fas fa-coins"></i></div>
                    <h4>Points System</h4>
                    <p>Reward your viewers with a custom points system and create point-based mini-games.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-layer-group"></i></div>
                    <h4>Stream Overlays</h4>
                    <p>Create dynamic overlays for recent followers, latest donations, and now playing music.</p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--green);"><i class="fas fa-users"></i></div>
                    <h4>User Management</h4>
                    <p>Manage your community with tools for moderators, VIPs, subscribers, and regular viewers.</p>
                </div>
            </div>
        </div>
        <!-- Premium Plans -->
        <div class="db-landing-section" style="padding-top: 0;">
            <div class="db-landing-section-header">
                <h2>Premium Plans</h2>
                <p>Unlock additional features and support the development of BotOfTheSpecter by subscribing to one of our premium plans via Twitch.</p>
            </div>
            <div class="db-plans-grid">
                <!-- Free Plan -->
                <div class="db-plan-card">
                    <div class="db-plan-card-icon" style="color: var(--text-muted);"><i class="fas fa-rocket"></i></div>
                    <h3>Free</h3>
                    <div class="db-plan-price">$0/month</div>
                    <ul>
                        <li><i class="fas fa-check"></i> Core Bot Features</li>
                        <li><i class="fas fa-check"></i> Community Support</li>
                        <li><i class="fas fa-check"></i> 20MB Storage</li>
                        <li><i class="fas fa-check"></i> Shared Bot Name (BotOfTheSpecter)</li>
                        <li><i class="fas fa-flask"></i> Custom Bot Name (Experimental/Coming Soon)</li>
                    </ul>
                    <a href="login.php" class="sp-btn sp-btn-success" style="width: 100%; justify-content: center;"><i class="fas fa-sign-in-alt"></i> Get Started</a>
                    <p style="font-size: 0.75rem; color: var(--text-muted); text-align: center; margin-top: 0.75rem;"><strong>90-95% of the bot is FREE!</strong></p>
                </div>
                <?php
                $plans = [
                    '1000' => [
                        'name' => 'Tier 1',
                        'price' => '$4.99/month',
                        'features' => ['Song Request Command', 'Priority Support', 'Beta Access', '50MB Storage'],
                        'icon' => 'fas fa-star',
                        'icon_color' => 'var(--blue)',
                    ],
                    '2000' => [
                        'name' => 'Tier 2',
                        'price' => '$9.99/month',
                        'features' => ['Everything in Tier 1', 'Personal Support', 'AI Features', '100MB Storage'],
                        'icon' => 'fas fa-crown',
                        'icon_color' => 'var(--amber)',
                    ],
                    '3000' => [
                        'name' => 'Tier 3',
                        'price' => '$24.99/month',
                        'features' => ['Everything in Tier 2', '200MB Storage'],
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
                    <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-plus-circle"></i> Subscribe</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Footer -->
        <footer class="db-landing-footer">
            &copy; 2023&ndash;<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            <?php include '/var/www/config/project-time.php'; ?>
            BotOfTheSpecter is a project operated under the business name &ldquo;YourStreamingTools&rdquo;, registered in Australia (ABN 20 447 022 747).<br>
            This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
            All trademarks, logos, and brand names are the property of their respective owners and are used for identification purposes only.
            <br><span class="sp-version-badge" style="margin-top: 0.5rem; display: inline-flex;">Dashboard v<?php echo $dashboardVersion; ?></span>
        </footer>
    </body>
    </html>
    <?php
}
?>