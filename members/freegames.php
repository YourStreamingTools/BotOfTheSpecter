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
return ['error' => true, 'message' => 'Unable to fetch data from API'];
    }
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
<?php
// Layout variables for layout.php
$pageTitle     = $title;
$activeNav     = 'freegames';
$topbarActions = '<a href="/" class="sp-btn sp-btn-secondary sp-btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Search</a>';

ob_start();
?>
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
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';


