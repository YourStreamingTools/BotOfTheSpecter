<?php
// help/layout.php
if (!isset($pageTitle)) $pageTitle = "BotOfTheSpecter Help & Wiki";
if (!isset($pageDescription)) $pageDescription = "Comprehensive help and documentation for BotOfTheSpecter, your ultimate streaming bot for Twitch, Discord, and beyond.";
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];
$maintenanceMode = $config['maintenanceMode'];
?>
<!DOCTYPE html>
<html lang="en" class="theme-dark" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>BotOfTheSpecter Help & Wiki</title>
    <!-- Bulma CSS 1.0.4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" type="text/css" href="../home/style.css?v=<?= $dashboardVersion ?>">
    <script src="../home/navbar.js" defer></script>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>" />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</head>
<body class="has-background-dark has-text-light">
<nav class="navbar is-fixed-top is-dark" role="navigation" aria-label="main navigation">
    <div class="navbar-brand">
        <span class="navbar-item has-text-weight-bold is-unselectable no-hover">BotOfTheSpecter Help</span>
        <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasic">
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </a>
    </div>
    <div id="navbarBasic" class="navbar-menu">
        <div class="navbar-start">
            <a href="../" class="navbar-item">Home</a>
            <a href="#commands" class="navbar-item">Custom Command Variables</a>
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
        <?php echo $content; ?>
    </div>
</main>

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

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const $navbarBurgers = Array.prototype.slice.call(document.querySelectorAll('.navbar-burger'), 0);
            $navbarBurgers.forEach( el => {
                el.addEventListener('click', () => {
                    const target = el.dataset.target;
                    const $target = document.getElementById(target);
                    el.classList.toggle('is-active');
                    $target.classList.toggle('is-active');
                });
            });
        });
    </script>
</body>
</html>
