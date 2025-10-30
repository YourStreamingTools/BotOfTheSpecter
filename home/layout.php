<?php
// home/layout.php
if (!isset($pageTitle)) $pageTitle = "BotOfTheSpecter";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.";
if (!isset($pageContent)) $pageContent = "";
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
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" type="text/css" href="style.css?v=<?php echo uuidv4(); ?>">
    <script src="navbar.js?v=<?= $dashboardVersion ?>" defer></script>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>" />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</head>
<body>
<nav class="navbar is-fixed-top is-dark" role="navigation" aria-label="main navigation">
    <div class="navbar-brand">
        <span class="navbar-item has-text-weight-bold is-unselectable no-hover">BotOfTheSpecter</span>
        <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasic">
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </a>
    </div>
    <div id="navbarBasic" class="navbar-menu">
        <div class="navbar-start">
            <a href="/" class="navbar-item">Home</a>
            <a href="privacy-policy.php" class="navbar-item">Privacy Policy</a>
            <a href="terms-of-service.php" class="navbar-item">Terms of Service</a>
        </div>
        <div class="navbar-end">
            <div class="navbar-item no-hover">
                <a href="https://dashboard.botofthespecter.com/dashboard.php" class="button is-primary">DASHBOARD</a>
            </div>
        </div>
    </div>
</nav>
<main class="section">
    <div class="container">
        <?= $pageContent ?>
    </div>
</main>
<footer class="footer is-dark has-text-white" style="width:100%; display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
    <!-- Version Badge positioned in footer -->
    <div style="position: absolute; bottom: 12px; left: 12px;" class="is-hidden-mobile">
        <span class="tag is-info is-light">Dashboard Version: <?php echo $dashboardVersion; ?></span>
    </div>
    <div style="max-width: 1500px; padding-left: 140px;" class="is-hidden-mobile">
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
</body>
</html>
