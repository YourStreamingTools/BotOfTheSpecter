<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '/var/www/lib/session_bootstrap.php';

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: https://members.botofthespecter.com/login.php');
    exit();
}
session_write_close();

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
    <title>BotOfTheSpecter &mdash; <?php echo esc($title); ?></title>
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="<?php echo '/style.css?v=' . filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@Tools4Streaming">
    <meta name="twitter:title" content="BotOfTheSpecter">
    <meta name="twitter:description" content="BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience.">
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
</head>
<body>
<div id="sp-sidebar-overlay" class="sp-sidebar-overlay"></div>
<div class="sp-layout">
    <!-- SIDEBAR -->
    <aside id="sp-sidebar" class="sp-sidebar">
        <div class="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter">
            <div class="sp-brand-text">
                <span class="sp-brand-title">BotOfTheSpecter</span>
                <span class="sp-brand-sub">Members Portal</span>
            </div>
        </div>
        <nav class="sp-nav">
            <div class="sp-nav-section">
                <div class="sp-nav-label">Navigation</div>
                <a href="/" class="sp-nav-link">
                    <i class="fa-solid fa-magnifying-glass"></i> Search Channels
                </a>
                <a href="/freegames.php" class="sp-nav-link active">
                    <i class="fa-solid fa-gamepad"></i> Free Games
                </a>
            </div>
            <div class="sp-nav-section">
                <div class="sp-nav-label">Resources</div>
                <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-gauge"></i> Dashboard <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
                <a href="https://support.botofthespecter.com" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-circle-question"></i> Support <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
            </div>
        </nav>
        <div class="sp-sidebar-footer">
            <div class="sp-user-block">
                <?php if (!empty($_SESSION['profile_image_url'])): ?>
                    <img src="<?php echo esc($_SESSION['profile_image_url']); ?>"
                         alt="<?php echo esc($_SESSION['display_name'] ?? ''); ?>"
                         class="sp-user-avatar">
                <?php else: ?>
                    <div class="sp-user-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div style="min-width:0;">
                    <div class="sp-user-name"><?php echo esc($_SESSION['display_name'] ?? ''); ?></div>
                    <div class="sp-user-role">Member</div>
                </div>
            </div>
            <a href="/logout.php" class="sp-nav-link sp-text-small">
                <i class="fa-solid fa-right-from-bracket"></i> Log Out
            </a>
        </div>
    </aside>
    <!-- MAIN -->
    <div class="sp-main">
        <header class="sp-topbar">
            <button id="sp-hamburger" class="sp-hamburger" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <span class="sp-topbar-title"><?php echo esc($title); ?></span>
            <div class="sp-topbar-actions">
                <a href="/" class="sp-btn sp-btn-secondary sp-btn-sm">
                    <i class="fa-solid fa-arrow-left"></i> Back to Search
                </a>
            </div>
        </header>
        <main class="sp-content">
            <div class="sp-page-header">
                <div>
                    <h1>FreeStuff &mdash; System Announcements</h1>
                    <p>System-wide FreeStuff announcements used by the Discord and Twitch bots.</p>
                </div>
            </div>
            <?php if ($result['error']): ?>
                <div class="sp-alert sp-alert-warning">
                    <strong>Notice:</strong> <?php echo esc($result['message']); ?>
                </div>
            <?php elseif ($result['count'] == 0): ?>
                <div class="sp-empty">
                    <i class="fa-solid fa-gamepad"></i>
                    <h3>No Games Found</h3>
                    <p>No recent free games were found.</p>
                </div>
            <?php else: ?>
                <?php $latest = $result['games'][0]; ?>
                <div class="ms-game-featured">
                    <?php if (!empty($latest['game_thumbnail'])): ?>
                        <img class="ms-game-featured-img" src="<?php echo esc($latest['game_thumbnail']); ?>" alt="<?php echo esc($latest['game_title']); ?>">
                    <?php endif; ?>
                    <div class="ms-game-featured-body">
                        <div class="ms-game-featured-badge">Latest Free Game</div>
                        <div class="ms-game-featured-title"><?php echo esc($latest['game_title']); ?></div>
                        <div class="ms-game-featured-meta"><strong><?php echo esc($latest['game_org']); ?></strong> &middot; <?php echo esc($latest['game_price']); ?> &middot; Received: <?php echo esc(format_date($latest['received_at'])); ?></div>
                        <div class="ms-game-featured-desc"><?php echo esc(mb_substr($latest['game_description'] ?? '', 0, 400)); ?></div>
                        <div class="ms-game-featured-actions">
                            <?php if (!empty($latest['game_url'])): ?>
                                <a href="<?php echo esc($latest['game_url']); ?>" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Claim / View
                                </a>
                            <?php endif; ?>
                            <a href="#all-games" class="sp-btn sp-btn-secondary">View All Recent Games</a>
                        </div>
                    </div>
                </div>
                <div id="all-games" class="ms-games-grid">
                    <?php foreach ($result['games'] as $game): ?>
                        <div class="ms-game-card">
                            <?php if (!empty($game['game_thumbnail'])): ?>
                                <div class="ms-game-card-img">
                                    <img src="<?php echo esc($game['game_thumbnail']); ?>" alt="<?php echo esc($game['game_title']); ?>">
                                </div>
                            <?php endif; ?>
                            <div class="ms-game-card-body">
                                <div class="ms-game-card-title"><?php echo esc($game['game_title']); ?></div>
                                <div class="ms-game-card-meta"><strong><?php echo esc($game['game_org']); ?></strong> &middot; <?php echo esc($game['game_price']); ?></div>
                                <div class="ms-game-card-desc"><?php echo esc(mb_substr($game['game_description'] ?? '', 0, 300)); ?></div>
                            </div>
                            <div class="ms-game-card-footer">
                                <?php if (!empty($game['game_url'])): ?>
                                    <a href="<?php echo esc($game['game_url']); ?>" target="_blank" rel="noopener" class="sp-btn sp-btn-primary sp-btn-sm">Claim / View</a>
                                <?php else: ?>
                                    <span class="sp-badge">No Link</span>
                                <?php endif; ?>
                                <span class="ms-game-card-date">Received: <?php echo esc(format_date($game['received_at'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>
<footer class="sp-footer">
    &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter &mdash; All Rights Reserved.
</footer>
<script>
    (function () {
        const overlay = document.getElementById('sp-sidebar-overlay');
        const sidebar = document.getElementById('sp-sidebar');
        const hamburger = document.getElementById('sp-hamburger');
        if (!hamburger) return;
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('visible'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('visible'); }
        hamburger.addEventListener('click', openSidebar);
        overlay.addEventListener('click', closeSidebar);
    })();
</script>
</body>
</html>