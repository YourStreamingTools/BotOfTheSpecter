<?php
// home/layout.php
if (!isset($pageTitle))       $pageTitle       = "BotOfTheSpecter";
if (!isset($pageDescription)) $pageDescription = "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.";
if (!isset($pageContent))     $pageContent     = "";
$config           = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];

function uuidv4() { return bin2hex(random_bytes(4)); }

$currentFile = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" type="text/css" href="style.css?v=<?php echo uuidv4(); ?>">
    <script src="navbar.js?v=<?= htmlspecialchars($dashboardVersion) ?>" defer></script>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@Tools4Streaming">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</head>
<body>
<!-- Top navigation -->
<nav class="hs-topnav" role="navigation" aria-label="main navigation">
    <div class="hs-topnav-inner">
        <a href="/" class="hs-topnav-brand" aria-label="BotOfTheSpecter Home">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" width="32" height="32">
            <span class="hs-topnav-brand-name">BotOfTheSpecter</span>
        </a>
        <div class="hs-topnav-links" id="hsDesktopNav">
            <a href="/" class="hs-topnav-link<?= $currentFile === 'index.php' ? ' active' : '' ?>">Home</a>
            <a href="privacy-policy.php" class="hs-topnav-link<?= $currentFile === 'privacy-policy.php' ? ' active' : '' ?>">Privacy Policy</a>
            <a href="terms-of-service.php" class="hs-topnav-link<?= $currentFile === 'terms-of-service.php' ? ' active' : '' ?>">Terms of Service</a>
            <a href="feedback.php" class="hs-topnav-link<?= $currentFile === 'feedback.php' ? ' active' : '' ?>">Feedback</a>
        </div>
        <div class="hs-topnav-right">
            <a href="https://dashboard.botofthespecter.com/dashboard.php" class="hs-btn hs-btn-primary hs-btn-sm">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <button class="hs-hamburger" id="hsHamburger" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="hsMobileNav">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</nav>
<!-- Mobile nav dropdown -->
<div class="hs-mobile-nav" id="hsMobileNav" role="navigation" aria-label="mobile navigation">
    <a href="/" class="hs-topnav-link<?= $currentFile === 'index.php' ? ' active' : '' ?>"><i class="fa-solid fa-house"></i> Home</a>
    <a href="privacy-policy.php" class="hs-topnav-link<?= $currentFile === 'privacy-policy.php' ? ' active' : '' ?>"><i class="fa-solid fa-shield-halved"></i> Privacy Policy</a>
    <a href="terms-of-service.php" class="hs-topnav-link<?= $currentFile === 'terms-of-service.php' ? ' active' : '' ?>"><i class="fa-solid fa-file-lines"></i> Terms of Service</a>
    <a href="feedback.php" class="hs-topnav-link<?= $currentFile === 'feedback.php' ? ' active' : '' ?>"><i class="fa-solid fa-comment"></i> Feedback</a>
    <div class="hs-mobile-nav-cta">
        <a href="https://dashboard.botofthespecter.com/dashboard.php" class="hs-btn hs-btn-primary" style="width:100%;justify-content:center;">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
    </div>
</div>
<!-- Page content -->
<main class="hs-main">
    <div class="hs-container">
        <?= $pageContent ?>
    </div>
</main>
<!-- Footer -->
<footer class="hs-footer" role="contentinfo">
    <div class="hs-footer-inner">
        <div class="hs-footer-version">
            <span class="hs-version-badge">Dashboard v<?php echo htmlspecialchars($dashboardVersion); ?></span>
        </div>
        <p>
            &copy; 2023&ndash;<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            <?php include '/var/www/config/project-time.php'; ?>
            BotOfTheSpecter is operated under the business name &ldquo;YourStreamingTools&rdquo;, registered in Australia (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).<br>
            Not affiliated with Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
            All trademarks and brand names are property of their respective owners and are used for identification purposes only.
        </p>
    </div>
</footer>
<?= isset($customPageScript) ? $customPageScript : '' ?>
</body>
</html>
