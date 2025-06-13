<?php
include "../mod_access.php";

// Add language support for todolist layout
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

if (!isset($scripts)) $scripts = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo isset($pageTitle) ? $pageTitle : 'To Do List'; ?></title>
    <!-- Bulma CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/custom.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body style="min-height: 100vh; display: flex; flex-direction: column;">
    <!-- Cookie Consent Box (bottom right, non-blocking, Bulma styles) -->
    <div id="cookieConsentBox" class="box has-background-dark has-text-white" style="display:none; position:fixed; z-index:9999; right:24px; bottom:24px; max-width:360px; width:90vw; box-shadow:0 2px 16px #000a;">
        <div class="mb-3">
            We use cookies to enhance your experience on our site. By clicking 
            <strong>Accept</strong>, you consent to the use of cookies in accordance with our 
            <a href="https://botofthespecter.com/privacy-policy.php" target="_blank" class="has-text-link has-text-weight-bold">Privacy Policy</a>.
            We use cookies to remember your bot version preference. This helps us provide a better experience for you.<br>
            If you choose to decline cookies, we will not be able to remember your preference and you may need to select your bot version each time you visit our site.
        </div>
        <div class="buttons is-right">
            <button id="cookieAcceptBtn" class="button is-success has-text-weight-bold">Accept</button>
            <button id="cookieDeclineBtn" class="button is-danger has-text-weight-bold">Decline</button>
        </div>
    </div>
    <!-- Main Dashboard Navigation -->
    <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <a class="navbar-item" href="/bot.php">
                <img src="https://cdn.botofthespecter.com/logo.png" width="28" height="28" alt="BotOfTheSpecter Logo">
                <strong class="ml-2">BotOfTheSpecter</strong>
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
                    <span class="icon"><i class="fas fa-list-check"></i></span>
                    <span>View Tasks</span>
                </a>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-list-check"></i></span>
                        <span>Tasks</span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="insert.php">
                            <span class="icon"><i class="fas fa-plus"></i></span>
                            <span>Add Task</span>
                        </a>
                        <a class="navbar-item" href="remove.php">
                            <span class="icon"><i class="fas fa-trash"></i></span>
                            <span>Remove Task</span>
                        </a>
                        <a class="navbar-item" href="update_objective.php">
                            <span class="icon"><i class="fas fa-edit"></i></span>
                            <span>Update Task</span>
                        </a>
                        <a class="navbar-item" href="completed.php">
                            <span class="icon"><i class="fas fa-check-double"></i></span>
                            <span>Completed Tasks</span>
                        </a>
                    </div>
                </div>
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span class="icon"><i class="fas fa-folder"></i></span>
                        <span>Categories</span>
                    </a>
                    <div class="navbar-dropdown">
                        <a class="navbar-item" href="categories.php">
                            <span class="icon"><i class="fas fa-folder"></i></span>
                            <span>View Categories</span>
                        </a>
                        <a class="navbar-item" href="add_category.php">
                            <span class="icon"><i class="fas fa-plus-square"></i></span>
                            <span>Add Category</span>
                        </a>
                    </div>
                </div>
                <a class="navbar-item" href="obs_options.php">
                    <span class="icon"><i class="fas fa-cog"></i></span>
                    <span>OBS Options</span>
                </a>
            </div>
            <div class="navbar-end">
                <div class="navbar-item has-dropdown is-hoverable">
                    <a class="navbar-link">
                        <span><?php echo isset($_SESSION['username']) ? $_SESSION['username'] : 'User'; ?></span>
                    </a>
                    <div class="navbar-dropdown is-right">
                        <a class="navbar-item" href="../bot.php">
                            <span class="icon"><i class="fas fa-robot"></i></span>
                            <span>Back to Bot</span>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bulma/0.9.3/js/bulma.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/dashboard.js"></script>
    <script src="../js/search.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const $navbarBurgers = Array.prototype.slice.call(document.querySelectorAll('.navbar-burger'), 0);
            if ($navbarBurgers.length > 0) {
                $navbarBurgers.forEach( el => {
                    el.addEventListener('click', () => {
                        const target = el.dataset.target;
                        const $target = document.getElementById(target);
                        el.classList.toggle('is-active');
                        $target.classList.toggle('is-active');
                    });
                });
            }
        });
        function setCookie(name, value, days) {
            var d = new Date();
            d.setTime(d.getTime() + (days*24*60*60*1000));
            document.cookie = name + "=" + value + ";expires=" + d.toUTCString() + ";path=/";
        }
        function getCookie(name) {
            var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : null;
        }
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
    <?php echo $scripts; ?>
</body>
</html>