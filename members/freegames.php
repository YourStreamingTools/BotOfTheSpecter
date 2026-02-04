<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: https://members.botofthespecter.com/login.php');
    exit();
}

$title = "Free Games"; 

function fetch_freegames() {
    $url = 'https://api.botofthespecter.com/freestuff/games';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $httpCode !== 200) {
        curl_close($ch);
        return ['error' => true, 'message' => 'Unable to fetch data from API'];
    }
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['games'])) {
        return ['error' => true, 'message' => 'Invalid response from API'];
    }
    return ['error' => false, 'games' => $data['games'], 'count' => $data['count'] ?? count($data['games'])];
}

$result = fetch_freegames();

function esc($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function format_date($datetime) {
    if (empty($datetime)) return 'Unknown';
    $ts = strtotime($datetime);
    if ($ts === false || $ts === -1) return 'Unknown';
    return date('M j, Y g:i A', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo '/custom.css?v=' . filemtime(__DIR__.'/custom.css'); ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description"
        content="BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics." />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
</head>

<body>
    <div class="navbar is-fixed-top" role="navigation" aria-label="main navigation" style="height: 75px;">
        <div class="navbar-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" height="175px" alt="BotOfTheSpecter Logo Image">
            <p class="navbar-item" style="font-size: 24px;">BotOfTheSpecter</p>
        </div>
        <div id="navbarMenu" class="navbar-menu">
            <div class="navbar-end">
                <div class="navbar-item" style="display: flex; align-items: center; gap: 0.5rem;">
                    <img class="is-rounded" id="profile-image" src="<?php echo esc($_SESSION['profile_image_url'] ?? 'https://cdn.botofthespecter.com/logo.png'); ?>" alt="Profile Image">
                    <span class="display-name"><?php echo esc($_SESSION['display_name'] ?? 'Member'); ?></span>
                </div>
                <div class="navbar-item">
                    <a href="/logout.php" class="button is-danger is-outlined" title="Logout">
                        <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <section class="section freegames" style="padding-top: 100px;">
        <div class="container">
            <nav class="breadcrumb" aria-label="breadcrumbs">
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="/members/">Members</a></li>
                    <li class="is-active"><a href="#" aria-current="page">Free Games</a></li>
                </ul>
            </nav>
            <h1 class="title">FreeStuff — System Announcements</h1>
            <p class="subtitle">This page shows system-wide FreeStuff announcements used by our Discord and Twitch bots. The Twitch bot posts the most recent free game in chat with a link to view more here.</p>
            <?php if ($result['error']): ?>
                <div class="notification is-warning">
                    <strong>Notice:</strong> <?php echo esc($result['message']); ?>
                </div>
            <?php else: ?>
                <?php if ($result['count'] == 0): ?>
                    <div class="notification is-info">No recent free games found.</div>
                <?php else: ?>
                    <!-- Highlight the most recent game first for easy sharing -->
                    <?php $latest = $result['games'][0]; ?>
                    <div class="box latest-banner">
                        <article class="media">
                            <?php if (!empty($latest['game_thumbnail'])): ?>
                                <figure class="media-left">
                                    <p class="image is-128x128">
                                        <img src="<?php echo esc($latest['game_thumbnail']); ?>" alt="<?php echo esc($latest['game_title']); ?>">
                                    </p>
                                </figure>
                            <?php endif; ?>
                            <div class="media-content">
                                <div class="content">
                                    <p>
                                        <strong class="is-size-5"><?php echo esc($latest['game_title']); ?></strong>
                                        <br>
                                        <small><strong><?php echo esc($latest['game_org']); ?></strong> &middot; <?php echo esc($latest['game_price']); ?> &middot; Received: <?php echo esc(format_date($latest['received_at'])); ?></small>
                                        <br>
                                        <span><?php echo esc(mb_substr($latest['game_description'] ?? '', 0, 400)); ?></span>
                                    </p>
                                </div>
                                <nav class="level is-mobile">
                                    <div class="level-left">
                                        <?php if (!empty($latest['game_url'])): ?>
                                            <a href="<?php echo esc($latest['game_url']); ?>" target="_blank" class="button is-link level-item">Claim / View</a>
                                        <?php endif; ?>
                                        <a href="#all" class="button is-light level-item">View All Recent Games</a>
                                    </div>
                                </nav>
                            </div>
                        </article>
                    </div>
                    <div id="all" class="columns is-multiline">
                        <?php foreach ($result['games'] as $game): ?>
                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                <div class="card">
                                    <?php if (!empty($game['game_thumbnail'])): ?>
                                        <div class="card-image">
                                            <figure class="image is-3by2">
                                                <img src="<?php echo esc($game['game_thumbnail']); ?>" alt="<?php echo esc($game['game_title']); ?>">
                                            </figure>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-content">
                                        <p class="title is-6"><?php echo esc($game['game_title']); ?></p>
                                        <p class="subtitle is-7"><strong><?php echo esc($game['game_org']); ?></strong> &middot; <?php echo esc($game['game_price']); ?></p>
                                        <div class="content game-description">
                                            <?php echo esc(mb_substr($game['game_description'] ?? '', 0, 300)); ?>
                                        </div>
                                    </div>
                                    <footer class="card-footer">
                                        <?php if (!empty($game['game_url'])): ?>
                                            <a href="<?php echo esc($game['game_url']); ?>" target="_blank" class="card-footer-item">Claim / View</a>
                                        <?php else: ?>
                                            <a class="card-footer-item">No Link</a>
                                        <?php endif; ?>
                                        <a class="card-footer-item">Received: <?php echo esc(format_date($game['received_at'])); ?></a>
                                    </footer>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div style="margin-top: 2rem;">
                <a href="/members/" class="button is-light">Back to Members</a>
            </div>
        </div>
    </section>
    <script defer src="https://use.fontawesome.com/releases/v5.14.0/js/all.js"></script>
</body>
</html>