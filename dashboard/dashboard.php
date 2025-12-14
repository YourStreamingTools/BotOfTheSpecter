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
    $pageTitle = 'Bot Management Dashboard';
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
    // Start output buffering for layout system
    ob_start();
    ?>
    <!-- Dashboard Welcome Section -->
    <div class="hero is-small">
        <div class="hero-body">
            <div class="container">
                <h1 class="title is-2 has-text-white">
                    <i class="fas fa-robot"></i> Welcome, <?php echo htmlspecialchars($twitchDisplayName); ?>!
                </h1>
            </div>
        </div>
    </div>
    <!-- Quick Actions Section -->
    <div class="section quick-actions">
        <div class="container">
            <h2 class="title is-3">Quick Actions</h2>
            <div class="columns is-multiline">
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-info">
                                    <i class="fas fa-robot fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">Bot Control</h4>
                            <p class="subtitle is-6">Start, stop, and monitor your bot</p>
                            <a href="bot.php" class="button is-info is-fullwidth">
                                <span class="icon"><i class="fas fa-cogs"></i></span>
                                <span>Manage Bot</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-success">
                                    <i class="fas fa-terminal fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">Commands</h4>
                            <p class="subtitle is-6">Create and edit custom commands</p>
                            <a href="custom_commands.php" class="button is-success is-fullwidth">
                                <span class="icon"><i class="fas fa-plus"></i></span>
                                <span>Edit Commands</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-primary">
                                    <i class="fab fa-discord fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">Discord Bot</h4>
                            <p class="subtitle is-6">Manage your Discord integration</p>
                            <a href="discordbot.php" class="button is-primary is-fullwidth">
                                <span class="icon"><i class="fas fa-cog"></i></span>
                                <span>Manage Discord Bot</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-warning">
                                    <i class="fas fa-list-check fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">To-Do List</h4>
                            <p class="subtitle is-6">Manage your streaming tasks</p>
                            <a href="../todolist" class="button is-warning is-fullwidth">
                                <span class="icon"><i class="fas fa-list"></i></span>
                                <span>Open To-Do</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-danger">
                                    <i class="fas fa-gift fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">Rewards</h4>
                            <p class="subtitle is-6">Manage channel rewards</p>
                            <a href="channel_rewards.php" class="button is-danger is-fullwidth">
                                <span class="icon"><i class="fas fa-star"></i></span>
                                <span>Setup Rewards</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-link">
                                    <i class="fas fa-plug fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">OBSConnector</h4>
                            <p class="subtitle is-6">Connect your OBS Studio to the Specter System</p>
                            <a href="obsconnector.php" class="button is-link is-fullwidth">
                                <span class="icon"><i class="fas fa-cogs"></i></span>
                                <span>OBS Connector</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-info">
                                    <i class="fas fa-music fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">DMCA Music</h4>
                            <p class="subtitle is-6">Safe music for streaming</p>
                            <a href="music.php" class="button is-info is-fullwidth">
                                <span class="icon"><i class="fas fa-play"></i></span>
                                <span>Browse Music</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop is-hidden">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-link">
                                    <i class="fas fa-video fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">Streaming</h4>
                            <p class="subtitle is-6">Our custom streaming service</p>
                            <a href="streaming.php" class="button is-link is-fullwidth">
                                <span class="icon"><i class="fas fa-broadcast-tower"></i></span>
                                <span>Manage Streaming</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="column is-6-tablet is-3-desktop">
                    <div class="card">
                        <div class="card-content has-text-centered">
                            <div class="mb-3">
                                <span class="icon is-large has-text-grey-light">
                                    <i class="fas fa-book fa-2x"></i>
                                </span>
                            </div>
                            <h4 class="title is-5">Documentation</h4>
                            <p class="subtitle is-6">Learn how to use BotOfTheSpecter</p>
                            <a href="https://help.botofthespecter.com/" class="button is-light is-fullwidth" target="_blank">
                                <span class="icon"><i class="fas fa-external-link-alt"></i></span>
                                <span>View Docs</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    include "layout.php";
} else {
    // User is not logged in - show landing page
    $pageTitle = 'Dashboard Login';
    // Start output buffering for content
    ob_start();
    ?>
    <!-- Hero Section -->
    <section class="hero is-small hero-gradient">
        <div class="hero-body">
            <div class="container has-text-centered">
                <h1 class="title is-2 has-text-white mt-4">
                    <i class="fas fa-robot"></i> BotOfTheSpecter Dashboard
                </h1>
                <h2 class="subtitle is-4 has-text-white">
                    Your Complete Twitch Bot Management Solution
                </h2>
                <p class="is-size-6 has-text-white mb-4">
                    Take control of your Twitch channel with our powerful, feature-rich bot dashboard.<br>
                    Manage commands, configure alerts, track analytics, and so much more!
                </p>
            </div>
        </div>
    </section>
    <!-- Login Section -->
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-6">
                    <div class="box login-section has-text-centered">
                        <h3 class="title is-3 has-text-white mb-4">
                            <i class="fas fa-sign-in-alt"></i> Access Your Dashboard
                        </h3>
                        <p class="has-text-grey-light mb-5">
                            Connect your Twitch account to access your personalized bot dashboard and start managing your channel like a pro.
                        </p>
                        <a href="login.php" class="button is-large twitch-purple has-text-white">
                            <span class="icon">
                                <i class="fab fa-twitch"></i>
                            </span>
                            <span>Login with Twitch</span>
                        </a>
                        <p class="is-size-7 has-text-grey mt-4">
                            <i class="fas fa-shield-alt"></i> Your data is secure and protected
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Features Section -->
    <section class="section">
        <div class="container">
            <h2 class="title is-2 has-text-centered has-text-white mb-6">
                Dashboard Features
            </h2>
            <div class="columns is-multiline">
                <!-- Bot Control -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-info">
                                    <i class="fas fa-robot fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Bot Control</h4>
                            <p class="has-text-grey-light">
                                Start, stop, and monitor your bot with real-time status updates and comprehensive logging.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Custom Commands -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-success">
                                    <i class="fas fa-terminal fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Custom Commands</h4>
                            <p class="has-text-grey-light">
                                Create and manage custom chat commands with advanced features and permission levels.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Analytics -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-warning">
                                    <i class="fas fa-chart-line fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Analytics & Logs</h4>
                            <p class="has-text-grey-light">
                                Track your channel's growth, monitor user activity, and analyze command usage statistics.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Channel Rewards -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-danger">
                                    <i class="fas fa-gift fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Channel Rewards</h4>
                            <p class="has-text-grey-light">
                                Manage Twitch channel point rewards and create engaging interactive experiences.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Stream Alerts -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-link">
                                    <i class="fas fa-volume-up fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Stream Alerts</h4>
                            <p class="has-text-grey-light">
                                Configure sound alerts, video alerts, and walk-on alerts for followers and subscribers.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Integrations -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-primary">
                                    <i class="fas fa-plug fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Integrations</h4>
                            <p class="has-text-grey-light">
                                Connect with Discord, Spotify, StreamElements, and other popular streaming platforms.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Points System -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-warning">
                                    <i class="fas fa-coins fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Points System</h4>
                            <p class="has-text-grey-light">
                                Reward your viewers with a custom points system and create point-based mini-games.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Overlays -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-info">
                                    <i class="fas fa-layer-group fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">Stream Overlays</h4>
                            <p class="has-text-grey-light">
                                Create dynamic overlays for recent followers, latest donations, and now playing music.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- User Management -->
                <div class="column is-4">
                    <div class="card feature-card has-background-dark">
                        <div class="card-content has-text-centered">
                            <div class="mb-4">
                                <span class="icon is-large has-text-success">
                                    <i class="fas fa-users fa-3x"></i>
                                </span>
                            </div>
                            <h4 class="title is-4 has-text-white">User Management</h4>
                            <p class="has-text-grey-light">
                                Manage your community with tools for moderators, VIPs, subscribers, and regular viewers.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Premium Plans Section -->
    <section class="section">
        <div class="container">
            <h2 class="title is-2 has-text-centered has-text-white mb-6">
                Premium Plans
            </h2>
            <div class="columns is-multiline is-variable is-5">
                <!-- Free Plan -->
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card feature-card has-shadow is-shadowless-mobile has-background-dark" style="height: 100%; border-radius: 12px;">
                        <div class="card-content" style="height: 100%; display: flex; flex-direction: column;">
                            <div class="has-text-centered mb-4">
                                <div class="icon is-large has-text-grey-light mb-2">
                                    <i class="fas fa-rocket fa-2x"></i>
                                </div>
                                <h3 class="title is-4 has-text-weight-bold has-text-white mb-2">
                                    Free
                                </h3>
                                <p class="subtitle is-5 has-text-weight-semibold has-text-primary">
                                    $0/month
                                </p>
                            </div>
                            <div class="content" style="flex-grow: 1;">
                                <ul class="is-size-6" style="list-style: none; padding-left: 0;">
                                    <li class="mb-2">
                                        <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                        Core Bot Features
                                    </li>
                                    <li class="mb-2">
                                        <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                        Community Support
                                    </li>
                                    <li class="mb-2">
                                        <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                        20MB Storage
                                    </li>
                                    <li class="mb-2">
                                        <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                        Shared Bot Name (BotOfTheSpecter)
                                    </li>
                                    <li class="mb-2">
                                        <span class="icon has-text-warning"><i class="fas fa-flask"></i></span>
                                        Custom Bot Name (Your Custom Bot Name, Experimental/Coming Soon)
                                    </li>
                                </ul>
                                <p class="is-size-7 has-text-grey mt-3 has-text-centered">
                                    <strong>90-95% of the bot is FREE!</strong>
                                </p>
                            </div>
                            <footer class="mt-4">
                                <a href="login.php" class="button is-success is-fullwidth is-rounded has-text-weight-semibold">
                                    <span class="icon"><i class="fas fa-sign-in-alt"></i></span>
                                    <span>Get Started</span>
                                </a>
                            </footer>
                        </div>
                    </div>
                </div>
                <!-- Premium Plans -->
                <?php
                $plans = [
                    '1000' => [
                        'name' => 'Tier 1',
                        'price' => '$4.99/month',
                            'features' => [
                            'Song Request Command',
                            'Priority Support',
                            'Beta Access',
                            '50MB Storage',
                        ],
                        'icon' => 'fas fa-star',
                        'color' => 'has-text-info',
                    ],
                    '2000' => [
                        'name' => 'Tier 2',
                        'price' => '$9.99/month',
                        'features' => [
                            'Everything in Tier 1',
                            'Personal Support',
                            'AI Features',
                            '100MB Storage',
                        ],
                        'icon' => 'fas fa-crown',
                        'color' => 'has-text-warning',
                    ],
                    '3000' => [
                        'name' => 'Tier 3',
                        'price' => '$24.99/month',
                            'features' => [
                            'Everything in Tier 2',
                            '200MB Storage',
                        ],
                        'icon' => 'fas fa-gem',
                        'color' => 'has-text-danger',
                    ],
                ];
                foreach ($plans as $planKey => $planDetails):
                ?>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card feature-card has-shadow is-shadowless-mobile has-background-dark" style="height: 100%; border-radius: 12px;">
                        <div class="card-content" style="height: 100%; display: flex; flex-direction: column;">
                            <div class="has-text-centered mb-4">
                                <div class="icon is-large <?php echo $planDetails['color']; ?> mb-2">
                                    <i class="<?php echo $planDetails['icon']; ?> fa-2x"></i>
                                </div>
                                <h3 class="title is-4 has-text-weight-bold has-text-white mb-2">
                                    <?php echo htmlspecialchars($planDetails['name']); ?>
                                </h3>
                                <p class="subtitle is-5 has-text-weight-semibold has-text-primary">
                                    <?php echo htmlspecialchars($planDetails['price']); ?>
                                </p>
                            </div>
                            <div class="content" style="flex-grow: 1;">
                                <ul class="is-size-6" style="list-style: none; padding-left: 0;">
                                    <?php foreach ($planDetails['features'] as $feature): ?>
                                    <li class="mb-2">
                                        <span class="icon has-text-success"><i class="fas fa-check"></i></span>
                                        <?php echo htmlspecialchars($feature); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <footer class="mt-4">
                                <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="button is-primary is-fullwidth is-rounded has-text-weight-semibold">
                                    <span class="icon">
                                        <i class="fas fa-plus-circle"></i>
                                    </span>
                                    <span>Subscribe</span>
                                </a>
                            </footer>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <!-- CTA Section -->
    <section class="section has-background-black-bis">
        <div class="container has-text-centered">
            <h2 class="title is-2 has-text-white mb-4">
                Ready to Get Started?
            </h2>
            <p class="subtitle is-4 has-text-grey-light mb-5">
                Join thousands of streamers who trust BotOfTheSpecter to enhance their Twitch experience.
            </p>
            <a href="login.php" class="button is-large twitch-purple has-text-white">
                <span class="icon">
                    <i class="fab fa-twitch"></i>
                </span>
                <span>Login with Twitch Now</span>
            </a>
        </div>
    </section>
    <script>
        // Add smooth scrolling for internal links
        $(document).ready(function() {
            // Add animation to feature cards on scroll
            $(window).scroll(function() {
                $('.feature-card').each(function() {
                    var elementTop = $(this).offset().top;
                    var elementBottom = elementTop + $(this).outerHeight();
                    var viewportTop = $(window).scrollTop();
                    var viewportBottom = viewportTop + $(window).height();
                    if (elementBottom > viewportTop && elementTop < viewportBottom) {
                        $(this).addClass('animate__animated animate__fadeInUp');
                    }
                });
            });
        });
    </script>
    <?php
    $content = ob_get_clean();
    // For non-logged in users, we'll create a custom layout without the dashboard navigation
    // Function to generate a UUID v4 for cache busting
    function uuidv4() { return bin2hex(random_bytes(4)); }
    ?>
    <!DOCTYPE html>
    <html lang="en" class="theme-dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>BotOfTheSpecter - <?php echo $pageTitle; ?></title>
        <!-- Bulma CSS 1.0.0 -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
        <!-- Custom CSS -->
        <link rel="stylesheet" href="css/custom.css?v=<?php echo uuidv4(); ?>">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
        <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    </head>
    <body style="display: flex; flex-direction: column; min-height: 100vh;">
        <!-- Main content -->
        <div style="flex: 1 0 auto;">
            <?php echo $content; ?>
        </div>
        <!-- Footer -->
        <footer class="footer is-dark has-text-white" style="width:100%; max-width:none; margin-left:0; display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
            <div style="position: absolute; bottom: 12px; left: 12px;" class="is-hidden-mobile">
                <span class="tag is-info is-light">Dashboard Version: <?php echo $dashboardVersion; ?></span>
            </div>
            <div style="width: 100%; max-width: none; padding: 0 1.5rem;" class="is-hidden-mobile">
                &copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
                <?php include '/var/www/config/project-time.php'; ?>
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
        <!-- jQuery is still included because some page scripts (animations) rely on it -->
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    </body>
    </html>
    <?php
}
?>
