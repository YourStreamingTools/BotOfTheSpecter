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
    <!-- CUSTOM -->
    <script src="navbar.js" defer></script>
    <script src="search.js" defer></script>
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
<div class="columns is-fullheight">
    <div class="column is-narrow has-background-grey-darker" style="min-width: 200px; padding: 1rem;">
        <a href="../" class="is-3 title has-text-white">BotOfTheSpecter Help</a>
        <aside class="menu mt-3">
            <ul class="menu-list">
                <li><a href="setup.php" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">First Time Setup</a></li>
                <li><a href="run_yourself.php" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">Run Yourself</a></li>
                <li><a href="spotify_setup.php" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">Spotify Setup</a></li>
                <li><a href="custom_command_variables.php" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">Custom Command Variables</a></li>
                <li><a href="twitch_channel_points.php" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">Twitch Channel Points</a></li>
                <li><a href="custom_api.php" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">Custom API</a></li>
                <li><a href="https://api.botofthespecter.com/docs" target="_blank" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">API Documentation</a></li>
                <li><a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" style="background: none;" onmouseover="this.style.color='#3273dc'" onmouseout="this.style.color=''">Bot Dashboard</a></li>
            </ul>
        </aside>
    </div>
    <div class="column">
        <div class="is-flex is-justify-content-flex-end mb-3" style="padding: 1rem; position: relative;">
            <form action="search.php" method="get" id="search-form">
                <div class="field">
                    <div class="control has-icons-left">
                        <input class="input" type="text" name="q" placeholder="Search help..." id="search-input" autocomplete="off" style="border-radius: 25px; box-shadow: 0 4px 8px rgba(0,0,0,0.3); background-color: #2c2c2c; color: #ffffff; border: 1px solid #4a4a4a; padding-left: 2.5rem; font-size: 1rem; transition: all 0.3s ease;">
                        <span class="icon is-small is-left" style="color: #888;">
                            <i class="fas fa-search"></i>
                        </span>
                    </div>
                </div>
            </form>
            <div id="search-results" style="position: absolute; top: 100%; right: 0; width: 300px; max-height: 400px; overflow-y: auto; z-index: 1000; display: none;"></div>
        </div>
        <main class="section">
            <div class="container">
                <?php echo $content; ?>
            </div>
        </main>
    </div>
</div>
<footer class="footer is-dark has-text-white" style="width:100%; display:flex; align-items:center; justify-content:center; text-align:center; padding:0.75rem 1rem; flex-shrink:0; position: relative;">
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
        BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia (ABN 20 447 022 747).<br>
        This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.<br>
        All trademarks, logos, and brand names including Twitch, Discord, Spotify, and StreamElements are the property of their respective owners and are used for identification purposes only.
    </div>
</footer>
</body>
</html>
