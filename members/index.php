<?php
require_once '/var/www/lib/session_bootstrap.php';

// Initialize all variables as empty arrays or values
$commands = [];
$builtinCommands = [];
$typos = [];
$lurkers = [];
$watchTimeData = [];
$totalDeaths = [];
$gameDeaths = [];
$totalHugs = 0;
$hugCounts = [];
$totalKisses = 0;
$kissCounts = [];
$customCounts = [];
$userCounts = [];
$highfiveCounts = [];
$rewardCounts = [];
$quotesData = [];
$seenUsersData = [];
$timedMessagesData = [];
$channelPointRewards = [];
$profileData = [];
$todos = [];
$todoCategories = [];

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: https://members.botofthespecter.com/login.php');
    exit();
}

// Function to sanitize input
function sanitize_input($input)
{
    return htmlspecialchars(trim($input));
}

// Function to fetch usernames from Twitch API using user_id
function getTitchUsernames($userIds)
{
    $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
    $accessToken = sanitize_input($_SESSION['access_token']);
    $twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . implode('&id=', array_map('sanitize_input', $userIds));
    $headers = [
        "Client-ID: $clientID",
        "Authorization: Bearer $accessToken",
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $twitchApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    if ($response === false) {
        // Handle cURL error
        error_log('cURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    $decodedResponse = json_decode($response, true);
    if (isset($decodedResponse['error'])) {
        // Handle API error
        error_log('Twitch API Error: ' . $decodedResponse['message']);
        return [];
    }
    $usernames = [];
    foreach ($decodedResponse['data'] as $user) {
        $usernames[] = $user['display_name'];
    }
    return $usernames;
}

// Function to sanitize custom variables in the response
function sanitize_custom_vars($response)
{
    $switches = ['(customapi.'];
    foreach ($switches as $switch) {
        $pattern = '/' . preg_quote($switch, '/') . '[^)]*\)/';
        $replacement = rtrim($switch, '.') . ')';
        $response = preg_replace($pattern, $replacement, $response);
    }
    $response = preg_replace('/\)\)+/', ')', $response);
    return $response;
}

// Format seconds into a human-readable watch time string (top 2 units)
function formatWatchTimePHP($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return 'Not recorded';
    $units = ['year' => 31536000, 'month' => 2592000, 'day' => 86400, 'hour' => 3600, 'minute' => 60];
    $parts = [];
    foreach ($units as $name => $div) {
        $q = (int)($seconds / $div);
        if ($q > 0) {
            $parts[] = $q . ' ' . $name . ($q !== 1 ? 's' : '');
            $seconds -= $q * $div;
        }
    }
    return implode(', ', array_slice($parts, 0, 2)) ?: 'Less than a minute';
}

// Resolve Twitch user IDs to display names via the Helix API
function resolveTwitchUsernames($userIds, $accessToken, $clientId) {
    if (empty($userIds)) return [];
    $url = 'https://api.twitch.tv/helix/users?' . implode('&', array_map(fn($id) => 'id=' . rawurlencode($id), $userIds));
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Client-ID: ' . $clientId, 'Authorization: Bearer ' . $accessToken]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    $map = [];
    if (isset($data['data'])) {
        foreach ($data['data'] as $u) { $map[$u['id']] = $u['display_name']; }
    }
    return $map;
}

// PAGE TITLE
$title = "Members"; 

// Database credentials
include '/var/www/config/database.php';
require_once '/var/www/config/twitch.php';

$path = trim($_SERVER['REQUEST_URI'], '/');
$path = parse_url($path, PHP_URL_PATH);

// Try to get username from GET or from the path (for /username/ URLs)
if (isset($_GET['user'])) {
    $username = strtolower(sanitize_input($_GET['user']));
} else {
    // Extract username from path if not set in GET
    $parts = explode('/', $path);
    // The first part after the domain is the username if it exists and is not 'members' or empty
    if (isset($parts[0]) && $parts[0] !== '' && $parts[0] !== 'members') {
        $username = strtolower(sanitize_input($parts[0]));
    } else {
        $username = null;
    }
}

$page = isset($_GET['page']) ? sanitize_input($_GET['page']) : null;
$buildResults = "Welcome " . $_SESSION['display_name'];
$notFound = false;
$isRestricted = false;
$isDeceased = false;
$memberProfileImage = null;
$memberDisplayName = null;

if ($username) {
    try {
        $checkDb = new mysqli($db_servername, $db_username, $db_password);
        if ($checkDb->connect_error) {
            throw new Exception("Connection failed: " . $checkDb->connect_error);
        }
        $escapedUsername = $checkDb->real_escape_string($username);
        $stmt = $checkDb->prepare("SHOW DATABASES LIKE ?");
        $stmt->bind_param('s', $escapedUsername);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $checkDb->close();
        if (!$result) {
            $notFound = true;
            throw new Exception("Database does not exist", 1049);
        }
    } catch (Exception $e) {
        if ($e->getCode() == 1049) {
            $notFound = true;
        } else {
            $buildResults = "Error: " . $e->getMessage();
        }
    }

    // Check memorial and restricted status from the website DB
    if (!$notFound) {
        $websiteConn = new mysqli($db_servername, $db_username, $db_password, 'website');
        if (!$websiteConn->connect_error) {
            $ustmt = $websiteConn->prepare("SELECT is_deceased, profile_image, twitch_display_name FROM users WHERE username = ? LIMIT 1");
            $ustmt->bind_param('s', $username);
            $ustmt->execute();
            $ustmt->bind_result($isDeceasedVal, $profileImageVal, $displayNameVal);
            if ($ustmt->fetch()) {
                $isDeceased = (int)$isDeceasedVal === 1;
                $memberProfileImage = $profileImageVal;
                $memberDisplayName = $displayNameVal ?: $username;
            }
            $ustmt->close();
            if (!$isDeceased) {
                $rstmt = $websiteConn->prepare("SELECT 1 FROM restricted_users WHERE username = ? LIMIT 1");
                $rstmt->bind_param('s', $username);
                $rstmt->execute();
                $rstmt->store_result();
                $isRestricted = $rstmt->num_rows > 0;
                $rstmt->close();
            }
            $websiteConn->close();
        }
    }
}

if (isset($_SESSION['redirect_url'])) {
    $redirectUrl = $_SESSION['redirect_url'];
    unset($_SESSION['redirect_url']);
    header("Location: $redirectUrl");
    exit();
}

if ($username && !$notFound && !$isRestricted && !$isDeceased) {
    $_SESSION['username'] = $username;
    $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . (isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown user');
    $dbname = $username;
    include "user_db.php";
    // Sanitize custom command responses
    $commands = array_map('sanitize_custom_vars', $commands);
}

// Fetch top-5 community data for memorial pages
$memorialData = ['lurkers' => [], 'typos' => [], 'deaths' => [], 'hugs' => [], 'watchtime' => []];
if ($username && !$notFound && $isDeceased) {
    try {
        $memDb = new mysqli($db_servername, $db_username, $db_password, $username);
        if (!$memDb->connect_error) {
            // Top 5 lurkers — oldest start_time = longest lurking
            $r = $memDb->query("SELECT user_id, start_time FROM lurk_times ORDER BY start_time ASC LIMIT 5");
            if ($r) {
                $lurkerRows = $r->fetch_all(MYSQLI_ASSOC);
                if (!empty($lurkerRows)) {
                    $userIds = array_column($lurkerRows, 'user_id');
                    $usernameMap = resolveTwitchUsernames($userIds, sanitize_input($_SESSION['access_token']), $clientID);
                    foreach ($lurkerRows as &$row) {
                        $row['display_name'] = $usernameMap[$row['user_id']] ?? '#' . $row['user_id'];
                    }
                    unset($row);
                }
                $memorialData['lurkers'] = $lurkerRows;
            }
            $r = $memDb->query("SELECT username, typo_count FROM user_typos ORDER BY typo_count DESC LIMIT 5");
            if ($r) $memorialData['typos'] = $r->fetch_all(MYSQLI_ASSOC);
            $r = $memDb->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC LIMIT 5");
            if ($r) $memorialData['deaths'] = $r->fetch_all(MYSQLI_ASSOC);
            $r = $memDb->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC LIMIT 5");
            if ($r) $memorialData['hugs'] = $r->fetch_all(MYSQLI_ASSOC);
            $r = $memDb->query("SELECT username, total_watch_time_live FROM watch_time ORDER BY total_watch_time_live DESC LIMIT 5");
            if ($r) $memorialData['watchtime'] = $r->fetch_all(MYSQLI_ASSOC);
            $memDb->close();
        }
    } catch (Exception $e) { /* Silently fail — memorial banner still shows */ }
}
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter — <?php echo htmlspecialchars($title); ?></title>
    <meta name="description" content="BotOfTheSpecter Members Portal — view channel data, commands, stats and more.">
    <meta property="og:title" content="BotOfTheSpecter — <?php echo htmlspecialchars($title); ?>">
    <meta property="og:description" content="BotOfTheSpecter Members Portal — view channel data, commands, stats and more.">
    <meta property="og:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@Tools4Streaming">
    <meta name="twitter:title" content="BotOfTheSpecter — <?php echo htmlspecialchars($title); ?>">
    <meta name="twitter:description" content="BotOfTheSpecter Members Portal — view channel data, commands, stats and more.">
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="<?php echo '/style.css?v=' . filemtime(__DIR__.'/style.css'); ?>">
    <script>
        const customCommands = <?php echo json_encode(array_map('sanitize_custom_vars', $commands)); ?>;
        const lurkers        = <?php echo json_encode($lurkers); ?>;
        const typos          = <?php echo json_encode($typos); ?>;
        const gameDeaths     = <?php echo json_encode($gameDeaths); ?>;
        const hugCounts      = <?php echo json_encode($hugCounts); ?>;
        const kissCounts     = <?php echo json_encode($kissCounts); ?>;
        const customCounts   = <?php echo json_encode($customCounts); ?>;
        const userCounts     = <?php echo json_encode($userCounts); ?>;
        const watchTimeData  = <?php echo json_encode($watchTimeData); ?>;
        const todos          = <?php echo json_encode($todos); ?>;
        const todoCategories = <?php echo json_encode($todoCategories); ?>;
        const highfiveCounts = <?php echo json_encode($highfiveCounts); ?>;
        const rewardCounts   = <?php echo json_encode($rewardCounts); ?>;
        const quotesData     = <?php echo json_encode($quotesData); ?>;
    </script>
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
                <a href="/" class="sp-nav-link<?php echo !$username ? ' active' : ''; ?>">
                    <i class="fa-solid fa-magnifying-glass"></i> Search Channels
                </a>
                <a href="/freegames.php" class="sp-nav-link">
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
                    <img src="<?php echo htmlspecialchars($_SESSION['profile_image_url'], ENT_QUOTES); ?>"
                         alt="<?php echo htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES); ?>"
                         class="sp-user-avatar">
                <?php else: ?>
                    <div class="sp-user-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div style="min-width:0;">
                    <div class="sp-user-name"><?php echo htmlspecialchars($_SESSION['display_name'] ?? ''); ?></div>
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
            <span class="sp-topbar-title"><?php echo htmlspecialchars($title); ?></span>
            <div class="sp-topbar-actions">
                <?php if ($username && !$notFound && !$isRestricted && !$isDeceased): ?>
                    <a href="/" class="sp-btn sp-btn-secondary sp-btn-sm">
                        <i class="fa-solid fa-arrow-left"></i> Back to Search
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <main class="sp-content">
                <?php if (!$username): ?>
                <div class="sp-page-header">
                    <div class="sp-page-header-row">
                        <div>
                            <h1>Member Lookup</h1>
                            <p>Search for a Twitch channel using BotOfTheSpecter.</p>
                        </div>
                    </div>
                </div>
                <div class="ms-search-card">
                    <form id="usernameForm" onsubmit="redirectToUser(event)">
                        <div class="ms-search-row">
                            <div class="ac-wrapper">
                                <input type="text" id="user_search" name="user" class="sp-input"
                                       placeholder="Enter a Twitch username…" autocomplete="off" required>
                                <div id="ac-dropdown" class="ac-dropdown" style="display:none;"></div>
                            </div>
                            <button type="submit" class="sp-btn sp-btn-primary">
                                <i class="fa-solid fa-magnifying-glass"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
                <div class="sp-card" style="margin-top:1.5rem;">
                    <div class="sp-card-header">
                        <i class="fa-solid fa-circle-info"></i>
                        <h2>Member Information</h2>
                    </div>
                    <div class="sp-card-body">
                        <div class="sp-doc-grid">
                            <a href="/freegames.php" class="sp-doc-card">
                                <div class="sp-doc-card-icon"><i class="fa-solid fa-gamepad"></i></div>
                                <div class="sp-doc-card-title">FreeStuff (System): Recent Free Games</div>
                                <div class="sp-doc-card-desc">System-wide announcements of free games used by our Discord and Twitch bots. The Twitch bot posts the most recent free game in chat.</div>
                            </a>
                        </div>
                    </div>
                </div>
                <?php elseif ($notFound): ?>
                <div class="sp-empty">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <h3>Channel Not Found</h3>
                    <p>We couldn&rsquo;t find a channel named <strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong> on BotOfTheSpecter.<br>The channel may not have signed up yet, or the username may be spelled incorrectly.</p>
                    <a href="/" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-arrow-left"></i> Search Again</a>
                </div>
                <?php elseif ($isDeceased): ?>
                    <div class="memorial-page">
                        <div class="memorial-stars-bg" aria-hidden="true"></div>
                        <!-- Candlelight -->
                        <div class="memorial-candles" aria-hidden="true">
                            <div class="candle">
                                <div class="candle-flame"></div>
                                <div class="candle-wick"></div>
                                <div class="candle-body"></div>
                                <div class="candle-base"></div>
                            </div>
                            <div class="candle">
                                <div class="candle-flame"></div>
                                <div class="candle-wick"></div>
                                <div class="candle-body"></div>
                                <div class="candle-base"></div>
                            </div>
                            <div class="candle">
                                <div class="candle-flame"></div>
                                <div class="candle-wick"></div>
                                <div class="candle-body"></div>
                                <div class="candle-base"></div>
                            </div>
                        </div>
                        <!-- Dove -->
                        <div class="memorial-dove-icon">
                            <i class="fas fa-dove fa-3x"></i>
                        </div>
                        <!-- Title -->
                        <h1 class="memorial-title">In Memoriam</h1>
                        <!-- Profile image -->
                        <?php if ($memberProfileImage): ?>
                        <div class="memorial-profile-wrap">
                            <img class="memorial-profile-img"
                                 src="<?php echo htmlspecialchars($memberProfileImage, ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="Profile image of <?php echo htmlspecialchars($memberDisplayName ?: $username, ENT_QUOTES, 'UTF-8'); ?>"
                                 onerror="this.src='https://cdn.botofthespecter.com/logo.png';">
                        </div>
                        <?php endif; ?>
                        <!-- Name -->
                        <p class="memorial-name"><?php echo htmlspecialchars($memberDisplayName ?: $username, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="memorial-username">@<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></p>
                        <!-- Decorative divider -->
                        <div class="memorial-divider" aria-hidden="true"><span>✦</span></div>
                        <!-- Memorial message -->
                        <div class="memorial-message">
                            <p class="preserved-note">This channel has been preserved as a permanent memorial.</p>
                            <p>The account holder has passed away. Their channel, community, and memories remain here as a tribute to the person they were and the community they built.</p>
                        </div>
                        <!-- Community highlights divider -->
                        <div class="memorial-divider" aria-hidden="true"><span>&#10022;</span></div>
                        <!-- Community highlights -->
                        <div class="memorial-stats">
                            <p class="memorial-stats-heading">Community Highlights</p>
                            <div class="memorial-stats-grid">
                                <!-- Top Lurkers -->
                                <div class="memorial-stat-card">
                                    <div class="memorial-stat-card-header">
                                        <i class="fas fa-eye-slash"></i>
                                        <span class="memorial-stat-card-title">Top Lurkers</span>
                                    </div>
                                    <?php if (empty($memorialData['lurkers'])): ?>
                                        <p class="memorial-stat-empty">No data recorded</p>
                                    <?php else: ?>
                                        <?php foreach ($memorialData['lurkers'] as $i => $row): ?>
                                        <div class="memorial-stat-row">
                                            <span class="memorial-stat-rank<?php echo $i < 3 ? ' rank-' . ($i + 1) : ''; ?>"><?php echo $i + 1; ?></span>
                                            <div class="memorial-stat-name-wrap">
                                                <span class="memorial-stat-name"><?php echo htmlspecialchars($row['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="memorial-stat-value">since <?php echo date('M Y', strtotime($row['start_time'])); ?></span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <!-- Top Typos -->
                                <div class="memorial-stat-card">
                                    <div class="memorial-stat-card-header">
                                        <i class="fas fa-keyboard"></i>
                                        <span class="memorial-stat-card-title">Top Typos</span>
                                    </div>
                                    <?php if (empty($memorialData['typos'])): ?>
                                        <p class="memorial-stat-empty">No data recorded</p>
                                    <?php else: ?>
                                        <?php foreach ($memorialData['typos'] as $i => $row): ?>
                                        <div class="memorial-stat-row">
                                            <span class="memorial-stat-rank<?php echo $i < 3 ? ' rank-' . ($i + 1) : ''; ?>"><?php echo $i + 1; ?></span>
                                            <div class="memorial-stat-name-wrap">
                                                <span class="memorial-stat-name"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="memorial-stat-value"><?php echo (int)$row['typo_count']; ?> typos</span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <!-- Top Game Deaths -->
                                <div class="memorial-stat-card">
                                    <div class="memorial-stat-card-header">
                                        <i class="fas fa-skull"></i>
                                        <span class="memorial-stat-card-title">Top Deaths</span>
                                    </div>
                                    <?php if (empty($memorialData['deaths'])): ?>
                                        <p class="memorial-stat-empty">No data recorded</p>
                                    <?php else: ?>
                                        <?php foreach ($memorialData['deaths'] as $i => $row): ?>
                                        <div class="memorial-stat-row">
                                            <span class="memorial-stat-rank<?php echo $i < 3 ? ' rank-' . ($i + 1) : ''; ?>"><?php echo $i + 1; ?></span>
                                            <div class="memorial-stat-name-wrap">
                                                <span class="memorial-stat-name"><?php echo htmlspecialchars($row['game_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="memorial-stat-value"><?php echo (int)$row['death_count']; ?> deaths</span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <!-- Top Hugs -->
                                <div class="memorial-stat-card">
                                    <div class="memorial-stat-card-header">
                                        <i class="fas fa-heart"></i>
                                        <span class="memorial-stat-card-title">Top Hugs</span>
                                    </div>
                                    <?php if (empty($memorialData['hugs'])): ?>
                                        <p class="memorial-stat-empty">No data recorded</p>
                                    <?php else: ?>
                                        <?php foreach ($memorialData['hugs'] as $i => $row): ?>
                                        <div class="memorial-stat-row">
                                            <span class="memorial-stat-rank<?php echo $i < 3 ? ' rank-' . ($i + 1) : ''; ?>"><?php echo $i + 1; ?></span>
                                            <div class="memorial-stat-name-wrap">
                                                <span class="memorial-stat-name"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="memorial-stat-value"><?php echo (int)$row['hug_count']; ?> hugs</span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <!-- Top Watch Time -->
                                <div class="memorial-stat-card">
                                    <div class="memorial-stat-card-header">
                                        <i class="fas fa-clock"></i>
                                        <span class="memorial-stat-card-title">Top Watchers</span>
                                    </div>
                                    <?php if (empty($memorialData['watchtime'])): ?>
                                        <p class="memorial-stat-empty">No data recorded</p>
                                    <?php else: ?>
                                        <?php foreach ($memorialData['watchtime'] as $i => $row): ?>
                                        <div class="memorial-stat-row">
                                            <span class="memorial-stat-rank<?php echo $i < 3 ? ' rank-' . ($i + 1) : ''; ?>"><?php echo $i + 1; ?></span>
                                            <div class="memorial-stat-name-wrap">
                                                <span class="memorial-stat-name"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="memorial-stat-value"><?php echo htmlspecialchars(formatWatchTimePHP((int)$row['total_watch_time_live']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Footer accent -->
                        <div class="memorial-footer-stars" aria-hidden="true">✦ &nbsp; ✦ &nbsp; ✦</div>
                        <!-- Help & Crisis Resources -->
                        <div class="memorial-helplines">
                            <div class="memorial-local-helpline" id="memorial-local-helpline" style="display:none">
                                <div class="memorial-local-helpline-label">
                                    <i class="fas fa-location-dot"></i>
                                    <span>Your local helpline</span>
                                </div>
                                <div class="memorial-local-helpline-body">
                                    <span class="memorial-local-country" id="local-helpline-country"></span>
                                    <span class="memorial-local-name" id="local-helpline-name"></span>
                                    <span class="memorial-local-number" id="local-helpline-number"></span>
                                </div>
                            </div>
                            <p class="memorial-helplines-note">We share these resources simply because we care about you &mdash; no assumptions, no judgement.</p>
                            <button class="memorial-helplines-toggle" onclick="toggleHelplines(this)" aria-expanded="false">
                                <i class="fas fa-hands-holding-heart"></i>
                                <span>Help is always available &mdash; view all crisis helplines</span>
                                <span class="toggle-arrow"><i class="fas fa-chevron-down"></i></span>
                            </button>
                            <p class="memorial-helplines-sub">These numbers are here for anyone who simply needs someone to talk to &mdash; for any reason at all. Reaching out is always okay.</p>
                            <div class="memorial-helplines-grid" id="helplines-grid">
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Australia</span><span class="memorial-helpline-name">Lifeline</span><span class="memorial-helpline-number">13 11 14</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">United States</span><span class="memorial-helpline-name">SAMHSA Helpline</span><span class="memorial-helpline-number">800-662-4357</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Canada</span><span class="memorial-helpline-name">Wellness Together Canada</span><span class="memorial-helpline-number">1-866-585-0445</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">United Kingdom</span><span class="memorial-helpline-name">Samaritans</span><span class="memorial-helpline-number">116 123</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Ireland</span><span class="memorial-helpline-name">Samaritans</span><span class="memorial-helpline-number">116 123</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">France</span><span class="memorial-helpline-name">SOS Amitié</span><span class="memorial-helpline-number">09 72 39 40 50</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Germany</span><span class="memorial-helpline-name">TelefonSeelsorge</span><span class="memorial-helpline-number">0800 111 0111</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Spain</span><span class="memorial-helpline-name">Tel&eacute;fono de la Esperanza</span><span class="memorial-helpline-number">717 003 717</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Italy</span><span class="memorial-helpline-name">Telefono Amico</span><span class="memorial-helpline-number">199 284 284</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Netherlands</span><span class="memorial-helpline-name">De Luisterlijn</span><span class="memorial-helpline-number">0900 0767</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Belgium</span><span class="memorial-helpline-name">Zelfmoordlijn</span><span class="memorial-helpline-number">1813</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Sweden</span><span class="memorial-helpline-name">Jourhavande medmänniska</span><span class="memorial-helpline-number">08-702 16 80</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Denmark</span><span class="memorial-helpline-name">Livslinien</span><span class="memorial-helpline-number">70 201 201</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Norway</span><span class="memorial-helpline-name">Mental Helse</span><span class="memorial-helpline-number">116 123</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Finland</span><span class="memorial-helpline-name">MIELI Crisis Helpline</span><span class="memorial-helpline-number">09 2525 0111</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Switzerland</span><span class="memorial-helpline-name">La Main Tendue</span><span class="memorial-helpline-number">143</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Poland</span><span class="memorial-helpline-name">Befrienders</span><span class="memorial-helpline-number">22 484 88 01</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Austria</span><span class="memorial-helpline-name">TelefonSeelsorge</span><span class="memorial-helpline-number">142</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Brazil</span><span class="memorial-helpline-name">Centro de Valoriza&ccedil;&atilde;o da Vida</span><span class="memorial-helpline-number">188</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Mexico</span><span class="memorial-helpline-name">SAPTEL</span><span class="memorial-helpline-number">800 472 7835</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Argentina</span><span class="memorial-helpline-name">Línea de Salud Mental</span><span class="memorial-helpline-number">0800-999-0091</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">New Zealand</span><span class="memorial-helpline-name">Lifeline Aotearoa</span><span class="memorial-helpline-number">0800 543 354</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">India</span><span class="memorial-helpline-name">AASRA</span><span class="memorial-helpline-number">+91 22 2754 6669</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">China</span><span class="memorial-helpline-name">National Mental Health Helpline</span><span class="memorial-helpline-number">12320-5</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Japan</span><span class="memorial-helpline-name">TELL Lifeline</span><span class="memorial-helpline-number">03 5774 0992</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">South Korea</span><span class="memorial-helpline-name">Mental Health Welfare Center</span><span class="memorial-helpline-number">1577-0199</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Singapore</span><span class="memorial-helpline-name">Samaritans of Singapore</span><span class="memorial-helpline-number">1800 221 4444</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Malaysia</span><span class="memorial-helpline-name">Befrienders KL</span><span class="memorial-helpline-number">03 7956 8145</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Philippines</span><span class="memorial-helpline-name">NCMH Crisis Hotline</span><span class="memorial-helpline-number">1553</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Israel</span><span class="memorial-helpline-name">ERAN Emotional First Aid</span><span class="memorial-helpline-number">1201</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">United Arab Emirates</span><span class="memorial-helpline-name">Al Amal Mental Health</span><span class="memorial-helpline-number">800 4673</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Saudi Arabia</span><span class="memorial-helpline-name">National Center for Mental Health</span><span class="memorial-helpline-number">920033360</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Iran</span><span class="memorial-helpline-name">National Counseling Helpline</span><span class="memorial-helpline-number">1480</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Lebanon</span><span class="memorial-helpline-name">Embrace Lifeline</span><span class="memorial-helpline-number">1564</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Egypt</span><span class="memorial-helpline-name">Befrienders Cairo</span><span class="memorial-helpline-number">762 1602</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">South Africa</span><span class="memorial-helpline-name">SADAG General Helpline</span><span class="memorial-helpline-number">0800 21 22 23</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Kenya</span><span class="memorial-helpline-name">Befrienders Nairobi</span><span class="memorial-helpline-number">+254 722 178 177</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Nepal</span><span class="memorial-helpline-name">National Mental Health Helpline</span><span class="memorial-helpline-number">1166</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Thailand</span><span class="memorial-helpline-name">Samaritans Thailand</span><span class="memorial-helpline-number">02 713 6793</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Taiwan</span><span class="memorial-helpline-name">Taiwan Lifeline</span><span class="memorial-helpline-number">1995</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Hong Kong</span><span class="memorial-helpline-name">Samaritan Befrienders Hong Kong</span><span class="memorial-helpline-number">2382 0000</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Indonesia</span><span class="memorial-helpline-name">Ministry of Health Hotline</span><span class="memorial-helpline-number">1500 567</span></div>
                                <div class="memorial-helpline-entry"><span class="memorial-helpline-country">Bangladesh</span><span class="memorial-helpline-name">Kaan Pete Roi</span><span class="memorial-helpline-number">+88 09639 678 999</span></div>
                            </div>
                        </div>
                        <div class="memorial-actions">
                            <a href="/" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Search</a>
                        </div>
                    </div>
                <?php elseif ($isRestricted): ?>
                <div class="sp-empty">
                    <i class="fa-solid fa-user-lock" style="color:var(--amber);"></i>
                    <h3>Channel Restricted</h3>
                    <p>The channel <strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong> is currently restricted and cannot be viewed. Access has been suspended by an administrator.</p>
                    <a href="/" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Search</a>
                </div>
                <?php else: ?>
                    <div class="sp-alert sp-alert-info">
                        <?php echo "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $_SESSION['username']; ?>
                    </div>
                    <div class="ms-tabs-container">
                        <div class="ms-tabs-wrap">
                            <div class="data-tabs">
                                <div class="tab-item active" onclick="loadData('customCommands')">
                                    <i class="fas fa-terminal"></i>
                                    <span>Custom Commands</span>
                                </div>
                                <div class="tab-item" onclick="loadData('lurkers')">
                                    <i class="fas fa-eye-slash"></i>
                                    <span>Lurkers</span>
                                </div>
                                <div class="tab-item" onclick="loadData('typos')">
                                    <i class="fas fa-keyboard"></i>
                                    <span>Typo Counts</span>
                                </div>
                                <div class="tab-item" onclick="loadData('deaths')">
                                    <i class="fas fa-skull"></i>
                                    <span>Deaths</span>
                                </div>
                                <div class="tab-item" onclick="loadData('hugs')">
                                    <i class="fas fa-heart"></i>
                                    <span>Hugs</span>
                                </div>
                                <div class="tab-item" onclick="loadData('kisses')">
                                    <i class="fas fa-kiss"></i>
                                    <span>Kisses</span>
                                </div>
                                <div class="tab-item" onclick="loadData('highfives')">
                                    <i class="fas fa-hand"></i>
                                    <span>High-Fives</span>
                                </div>
                                <div class="tab-item" onclick="loadData('custom')">
                                    <i class="fas fa-hashtag"></i>
                                    <span>Custom Counts</span>
                                </div>
                                <div class="tab-item" onclick="loadData('userCounts')">
                                    <i class="fas fa-users"></i>
                                    <span>User Counts</span>
                                </div>
                                <div class="tab-item" onclick="loadData('rewardCounts')">
                                    <i class="fas fa-gift"></i>
                                    <span>Rewards</span>
                                </div>
                                <div class="tab-item" onclick="loadData('watchTime')">
                                    <i class="fas fa-clock"></i>
                                    <span>Watch Time</span>
                                </div>
                                <div class="tab-item" onclick="loadData('quotes')">
                                    <i class="fas fa-quote-left"></i>
                                    <span>Quotes</span>
                                </div>
                                <div class="tab-item" onclick="loadData('todos')">
                                    <i class="fas fa-check-square"></i>
                                    <span>To-Do</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sp-card">
                        <h3 id="table-title"></h3>
                        <p id="command-totals" style="display:none;"></p>
                        <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th id="info-column-data"></th>
                                    <th id="data-column-info"></th>
                                    <th id="additional-column1" style="display: none;"></th>
                                    <th id="additional-column2" style="display: none;"></th>
                                    <th id="additional-column3" style="display: none;"></th>
                                    <th id="additional-column4" style="display: none;"></th>
                                    <th id="additional-column5" style="display: none;"></th>
                                </tr>
                            </thead>
                            <tbody id="table-body">
                                <!-- Content will be dynamically injected here -->
                            </tbody>
                        </table>
                        </div>
                    </div>
                    <script>
                        // Only run loadData if username is set (i.e., after user search)
                        document.addEventListener('DOMContentLoaded', function () {
                            loadData('customCommands');
                        });
                    </script>
                <?php endif; ?>
        </main>
    </div>
</div>
    <footer class="sp-footer">
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter &mdash; All Rights Reserved.
    </footer>
    <script>
        function redirectToUser(event) {
            event.preventDefault();
            const username = document.getElementById('user_search').value.trim();
            if (username) {
                window.location.href = '/' + encodeURIComponent(username) + '/';
            }
        }
        function toggleHelplines(btn) {
            const isOpen = btn.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', isOpen);
            const container = btn.closest('.memorial-helplines');
            container.querySelector('.memorial-helplines-sub').classList.toggle('is-visible', isOpen);
            container.querySelector('.memorial-helplines-grid').classList.toggle('is-open', isOpen);
        }
        // Detect visitor country via IP and show local helpline
        (function () {
            var card = document.getElementById('memorial-local-helpline');
            if (!card) return;
            var helplines = {
                'AU': {country: 'Australia',           name: 'Lifeline',                            number: '13 11 14'},
                'US': {country: 'United States',        name: 'SAMHSA Helpline',                      number: '800-662-4357'},
                'CA': {country: 'Canada',               name: 'Wellness Together Canada',             number: '1-866-585-0445'},
                'GB': {country: 'United Kingdom',       name: 'Samaritans',                           number: '116 123'},
                'IE': {country: 'Ireland',              name: 'Samaritans',                           number: '116 123'},
                'FR': {country: 'France',               name: 'SOS Amiti\u00e9',                      number: '09 72 39 40 50'},
                'DE': {country: 'Germany',              name: 'TelefonSeelsorge',                     number: '0800 111 0111'},
                'ES': {country: 'Spain',                name: 'Tel\u00e9fono de la Esperanza',        number: '717 003 717'},
                'IT': {country: 'Italy',                name: 'Telefono Amico',                       number: '199 284 284'},
                'NL': {country: 'Netherlands',          name: 'De Luisterlijn',                       number: '0900 0767'},
                'BE': {country: 'Belgium',              name: 'Zelfmoordlijn',                        number: '1813'},
                'SE': {country: 'Sweden',               name: 'Jourhavande medm\u00e4nniska',         number: '08-702 16 80'},
                'DK': {country: 'Denmark',              name: 'Livslinien',                           number: '70 201 201'},
                'NO': {country: 'Norway',               name: 'Mental Helse',                        number: '116 123'},
                'FI': {country: 'Finland',              name: 'MIELI Crisis Helpline',                number: '09 2525 0111'},
                'CH': {country: 'Switzerland',          name: 'La Main Tendue',                       number: '143'},
                'PL': {country: 'Poland',               name: 'Befrienders',                          number: '22 484 88 01'},
                'AT': {country: 'Austria',              name: 'TelefonSeelsorge',                     number: '142'},
                'BR': {country: 'Brazil',               name: 'Centro de Valoriza\u00e7\u00e3o da Vida', number: '188'},
                'MX': {country: 'Mexico',               name: 'SAPTEL',                               number: '800 472 7835'},
                'AR': {country: 'Argentina',            name: 'L\u00ednea de Salud Mental',           number: '0800-999-0091'},
                'NZ': {country: 'New Zealand',          name: 'Lifeline Aotearoa',                    number: '0800 543 354'},
                'IN': {country: 'India',                name: 'AASRA',                                number: '+91 22 2754 6669'},
                'CN': {country: 'China',                name: 'National Mental Health Helpline',      number: '12320-5'},
                'JP': {country: 'Japan',                name: 'TELL Lifeline',                        number: '03 5774 0992'},
                'KR': {country: 'South Korea',          name: 'Mental Health Welfare Center',         number: '1577-0199'},
                'SG': {country: 'Singapore',            name: 'Samaritans of Singapore',              number: '1800 221 4444'},
                'MY': {country: 'Malaysia',             name: 'Befrienders KL',                       number: '03 7956 8145'},
                'PH': {country: 'Philippines',          name: 'NCMH Crisis Hotline',                  number: '1553'},
                'IL': {country: 'Israel',               name: 'ERAN Emotional First Aid',             number: '1201'},
                'AE': {country: 'United Arab Emirates', name: 'Al Amal Mental Health',                number: '800 4673'},
                'SA': {country: 'Saudi Arabia',         name: 'National Center for Mental Health',    number: '920033360'},
                'IR': {country: 'Iran',                 name: 'National Counseling Helpline',         number: '1480'},
                'LB': {country: 'Lebanon',              name: 'Embrace Lifeline',                     number: '1564'},
                'EG': {country: 'Egypt',                name: 'Befrienders Cairo',                    number: '762 1602'},
                'ZA': {country: 'South Africa',         name: 'SADAG General Helpline',               number: '0800 21 22 23'},
                'KE': {country: 'Kenya',                name: 'Befrienders Nairobi',                  number: '+254 722 178 177'},
                'NP': {country: 'Nepal',                name: 'National Mental Health Helpline',      number: '1166'},
                'TH': {country: 'Thailand',             name: 'Samaritans Thailand',                  number: '02 713 6793'},
                'TW': {country: 'Taiwan',               name: 'Taiwan Lifeline',                      number: '1995'},
                'HK': {country: 'Hong Kong',            name: 'Samaritan Befrienders Hong Kong',      number: '2382 0000'},
                'ID': {country: 'Indonesia',            name: 'Ministry of Health Hotline',           number: '1500 567'},
                'BD': {country: 'Bangladesh',           name: 'Kaan Pete Roi',                        number: '+88 09639 678 999'}
            };
            fetch('https://ipapi.co/json/')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var code = (data.country_code || '').trim().toUpperCase();
                    var h = helplines[code];
                    if (!h) return;
                    document.getElementById('local-helpline-country').textContent = h.country;
                    document.getElementById('local-helpline-name').textContent = h.name;
                    document.getElementById('local-helpline-number').textContent = h.number;
                    card.style.display = '';
                })
                .catch(function () { /* geolocation unavailable — card stays hidden */ });
        })();
        // Autocomplete
        (function () {
            const input = document.getElementById('user_search');
            const dropdown = document.getElementById('ac-dropdown');
            if (!input || !dropdown) return;
            let debounceTimer = null;
            let activeIndex = -1;
            let suggestions = [];
            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const q = input.value.trim();
                if (q.length === 0) { closeDropdown(); return; }
                debounceTimer = setTimeout(() => fetchSuggestions(q), 200);
            });
            input.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.ac-item');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActive(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, -1);
                    updateActive(items);
                } else if (e.key === 'Enter') {
                    if (activeIndex >= 0 && items[activeIndex]) {
                        e.preventDefault();
                        selectItem(suggestions[activeIndex].username);
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown();
                }
            });
            document.addEventListener('click', function (e) {
                if (!e.target.closest('.ac-wrapper')) closeDropdown();
            });
            function fetchSuggestions(q) {
                fetch('/autocomplete.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        suggestions = data;
                        renderDropdown(data);
                    })
                    .catch(() => closeDropdown());
            }
            function renderDropdown(data) {
                if (!data.length) { closeDropdown(); return; }
                activeIndex = -1;
                dropdown.innerHTML = '';
                data.forEach((item, i) => {
                    const el = document.createElement('div');
                    el.className = 'ac-item';
                    const avatar = item.avatar
                        ? `<img class="ac-avatar" src="${escHtml(item.avatar)}" alt="" onerror="this.style.display='none'">`
                        : `<span class="ac-avatar ac-avatar-placeholder"><i class="fas fa-user"></i></span>`;
                    el.innerHTML = `${avatar}<span class="ac-name">${escHtml(item.display_name)}</span><span class="ac-username">@${escHtml(item.username)}</span>`;
                    el.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        selectItem(item.username);
                    });
                    dropdown.appendChild(el);
                });
                dropdown.style.display = 'block';
            }
            function selectItem(username) {
                input.value = username;
                closeDropdown();
                window.location.href = '/' + encodeURIComponent(username) + '/';
            }
            function updateActive(items) {
                items.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
                if (activeIndex >= 0 && suggestions[activeIndex]) {
                    input.value = suggestions[activeIndex].username;
                }
            }
            function closeDropdown() {
                dropdown.style.display = 'none';
                activeIndex = -1;
            }
            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
        })();
        // Function to load the data based on type
        async function loadData(type) {
            let data;
            let title;
            let dataColumn;
            let infoColumn;
            let additionalColumnName;
            let additionalColumnName2;
            let additionalColumnName3;
            let additionalColumnName4;
            let additionalColumnName5;
            let dataColumnVisible = true;
            let infoColumnVisible = true;
            let additionalColumnVisible = false;
            let additionalColumnVisible2 = false;
            let additionalColumnVisible3 = false;
            let additionalColumnVisible4 = false;
            let additionalColumnVisible5 = false;
            let output = '';
            // Hide command totals summary by default
            document.getElementById('command-totals').style.display = 'none';
            // Update active button state - highlight the currently selected button
            document.querySelectorAll('.tab-item').forEach(tab => {
                // First reset all tabs to the default state
                tab.classList.remove('active');
            });
            // Find the tab that corresponds to the current data type and highlight it
            const buttonMapping = {
                'customCommands': 'Custom Commands',
                'lurkers': 'Lurkers',
                'typos': 'Typo Counts',
                'deaths': 'Deaths',
                'hugs': 'Hugs',
                'kisses': 'Kisses',
                'highfives': 'High-Fives',
                'custom': 'Custom Counts',
                'userCounts': 'User Counts',
                'rewardCounts': 'Rewards',
                'watchTime': 'Watch Time',
                'quotes': 'Quotes',
                'todos': 'To-Do'
            };
            const buttonText = buttonMapping[type];
            if (buttonText) {
                const activeTab = Array.from(document.querySelectorAll('.tab-item')).find(
                    tab => tab.querySelector('span') && tab.querySelector('span').textContent.trim() === buttonText
                );
                if (activeTab) {
                    activeTab.classList.add('active');
                }
            }
            switch (type) {
                case 'customCommands': {
                    additionalColumnVisible = true;
                    additionalColumnVisible2 = true;
                    additionalColumnVisible3 = true;
                    const enabledCommands = customCommands.filter(c => c.status === 'Enabled');
                    const disabledCount = customCommands.length - enabledCommands.length;
                    data = enabledCommands;
                    title = 'Custom Commands';
                    infoColumn = 'Command';
                    dataColumn = 'Response';
                    additionalColumnName = 'Status';
                    additionalColumnName2 = 'Cooldown';
                    additionalColumnName3 = 'Permission';
                    const totalsEl = document.getElementById('command-totals');
                    totalsEl.textContent = `Command Totals: ${enabledCommands.length} Enabled / ${disabledCount} Disabled`;
                    totalsEl.style.display = '';
                    break;
                }
                case 'lurkers':
                    data = lurkers;
                    title = 'Currently Lurking Users';
                    infoColumn = 'Username';
                    dataColumn = 'Time';
                    const userIds = data.map(item => item.user_id);
                    const usernames = await getTitchUsernames(userIds);
                    data.forEach((item, index) => {
                        item.username = usernames[index];
                        item.lurkDuration = calculateLurkDuration(item.start_time);
                    });
                    data.sort((a, b) => new Date(a.start_time) - new Date(b.start_time));
                    data.forEach(item => {
                        output += `<tr><td>${item.username}</td><td><span class='text-success'>${item.lurkDuration}</span></td></tr>`;
                    });
                    break;
                case 'typos':
                    data = typos;
                    title = 'Typo Counts';
                    infoColumn = 'Username';
                    dataColumn = 'Typo Count';
                    break;
                case 'deaths':
                    data = gameDeaths;
                    title = 'Deaths Overview';
                    infoColumn = 'Game';
                    dataColumn = 'Death Count';
                    break;
                case 'hugs':
                    data = hugCounts;
                    title = 'Hug Counts';
                    infoColumn = 'Username';
                    dataColumn = 'Hug Count';
                    break;
                case 'kisses':
                    data = kissCounts;
                    title = 'Kiss Counts';
                    infoColumn = 'Username';
                    dataColumn = 'Kiss Count';
                    break;
                case 'highfives':
                    data = highfiveCounts;
                    title = 'High-Five Counts';
                    infoColumn = 'Username';
                    dataColumn = 'High-Five Count';
                    break;
                case 'custom':
                    data = customCounts;
                    title = 'Custom Counts';
                    infoColumn = 'Command';
                    dataColumn = 'Used';
                    break;
                case 'userCounts':
                    additionalColumnVisible = true;
                    data = userCounts;
                    title = 'User Counts for Commands';
                    additionalColumnName = 'Count';
                    infoColumn = 'User';
                    dataColumn = 'Command';
                    break;
                case 'rewardCounts':
                    additionalColumnVisible = true;
                    data = rewardCounts;
                    title = 'Reward Counts';
                    infoColumn = 'Reward Name';
                    dataColumn = 'Username';
                    additionalColumnName = 'Count';
                    break;
                case 'watchTime':
                    additionalColumnVisible = true;
                    data = watchTimeData;
                    title = 'Watch Time';
                    infoColumn = 'Username';
                    dataColumn = 'Online Watch Time';
                    additionalColumnName = 'Offline Watch Time';
                    data.sort((a, b) => b.total_watch_time_live - a.total_watch_time_live || b.total_watch_time_offline - a.total_watch_time_offline);
                    break;
                case 'quotes':
                    data = quotesData;
                    title = 'Quotes';
                    infoColumn = 'ID';
                    dataColumn = 'What was said';
                    break;
                case 'todos':
                    data = todos;
                    title = 'To-Do Items';
                    infoColumn = 'ID';
                    dataColumn = 'Task';
                    additionalColumnVisible = true;
                    additionalColumnVisible2 = true;
                    additionalColumnVisible3 = true;
                    additionalColumnVisible4 = true;
                    additionalColumnName = 'Category';
                    additionalColumnName2 = 'Completed';
                    additionalColumnName3 = 'Created At';
                    additionalColumnName4 = 'Updated At';
                    break;
            }
            if (type !== 'lurkers') {
                if (Array.isArray(data)) {
                    data.forEach(item => {
                        output += `<tr>`;
                        if (type === 'customCommands') {
                            const commandClass = item.status === 'Enabled' ? 'text-success' : 'text-danger';
                            const permissionLabels = { 'everyone': ['Everyone', '#2ecc71'], 'vip': ['VIPs', '#a855f7'], 'all-subs': ['All Subscribers', '#ffd700'], 't1-sub': ['Tier 1 Subscriber', '#c0c0c0'], 't2-sub': ['Tier 2 Subscriber', '#cd7f32'], 't3-sub': ['Tier 3 Subscriber', '#ffd700'], 'mod': ['Mods', '#f5a623'], 'broadcaster': ['Broadcaster', '#e74c3c'] };
                            const [permLabel, permColor] = permissionLabels[item.permission] || [item.permission, ''];
                            const cooldown = parseInt(item.cooldown, 10);
                            const cooldownColor = cooldown <= 15 ? '#2ecc71' : cooldown <= 60 ? '#f5a623' : '#e74c3c';
                            output += `<td>!${item.command}</td><td>${item.response}</td><td class="${commandClass}">${item.status}</td><td style="color:${cooldownColor};font-weight:600;">${item.cooldown}s</td><td style="color:${permColor};font-weight:600;">${permLabel}</td>`;
                        } else if (type === 'typos') {
                            output += `<td>${item.username}</td><td><span class='text-success'>${item.typo_count}</span></td>`;
                        } else if (type === 'deaths') {
                            output += `<td>${item.game_name}</td><td><span class='text-success'>${item.death_count}</span></td>`;
                        } else if (type === 'hugs') {
                            output += `<td>${item.username}</td><td><span class='text-success'>${item.hug_count}</span></td>`;
                        } else if (type === 'kisses') {
                            output += `<td>${item.username}</td><td><span class='text-success'>${item.kiss_count}</span></td>`;
                        } else if (type === 'highfives') {
                            output += `<td>${item.username}</td><td><span class='text-success'>${item.highfive_count}</span></td>`;
                        } else if (type === 'custom') {
                            output += `<td>${item.command}</td><td><span class='text-success'>${item.count}</span></td>`;
                        } else if (type === 'userCounts') {
                            output += `<td>${item.user}</td><td><span class='text-success'>${item.command}</span></td><td><span class='text-success'>${item.count}</span></td>`;
                        } else if (type === 'rewardCounts') {
                            output += `<td>${item.reward_title}</td><td>${item.user}</td><td><span class='text-success'>${item.count}</span></td>`;
                        } else if (type === 'watchTime') {
                            output += `<td>${item.username}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td>`;
                        } else if (type === 'quotes') {
                            output += `<td>${item.id}</td><td><span class='text-success'>${item.quote}</span></td>`;
                        } else if (type === 'todos') {
                            const categoryName = todoCategories.find(category => category.id === parseInt(item.category))?.category || item.category;
                            output += `<td>${item.id}</td><td>${item.objective}</td><td>${categoryName}</td><td>${item.completed}</td><td>${formatDateTime(item.created_at)}</td><td>${formatDateTime(item.updated_at)}</td>`;
                        }
                        output += `</tr>`;
                    });
                }
            }
            document.getElementById('data-column-info').innerText = dataColumn;
            document.getElementById('info-column-data').innerText = infoColumn;
            document.getElementById('additional-column1').innerText = additionalColumnName;
            document.getElementById('additional-column2').innerText = additionalColumnName2;
            document.getElementById('additional-column3').innerText = additionalColumnName3;
            document.getElementById('additional-column4').innerText = additionalColumnName4;
            document.getElementById('additional-column5').innerText = additionalColumnName5;
            document.getElementById('additional-column1').style.display = additionalColumnVisible ? '' : 'none';
            document.getElementById('additional-column2').style.display = additionalColumnVisible2 ? '' : 'none';
            document.getElementById('additional-column3').style.display = additionalColumnVisible3 ? '' : 'none';
            document.getElementById('additional-column4').style.display = additionalColumnVisible4 ? '' : 'none';
            document.getElementById('additional-column5').style.display = additionalColumnVisible5 ? '' : 'none';
            document.getElementById('data-column-info').style.display = dataColumnVisible ? '' : 'none';
            document.getElementById('info-column-data').style.display = infoColumnVisible ? '' : 'none';
            document.getElementById('table-title').innerText = title;
            document.getElementById('table-body').innerHTML = output;
            // Remove any existing filter buttons first
            const existingFilters = document.querySelector('.reward-filters');
            if (existingFilters) {
                existingFilters.remove();
            }
            // Add filter buttons for reward counts
            if (type === 'rewardCounts' && Array.isArray(data)) {
                // Get unique reward names
                const uniqueRewards = [...new Set(data.map(item => item.reward_title))];
                // Create filter buttons HTML
                let filterHTML = '<div class="reward-filters">';
                filterHTML += '<button class="reward-filter-btn active" onclick="filterRewards(\'All\', event)">All</button>';
                uniqueRewards.forEach(rewardName => {
                    const escapedName = rewardName.replace(/'/g, "\\'");
                    filterHTML += `<button class="reward-filter-btn" onclick="filterRewards('${escapedName}', event)">${rewardName}</button>`;
                });
                filterHTML += '</div>';
                // Insert filter buttons after the title
                const titleElement = document.getElementById('table-title');
                titleElement.insertAdjacentHTML('afterend', filterHTML);
            }
        }
        // Filter rewards table by reward name
        function filterRewards(rewardName, event) {
            const rows = document.querySelectorAll('#table-body tr');
            const rewardNameHeader = document.getElementById('info-column-data');
            rows.forEach(row => {
                const rewardCell = row.cells[0]; // First column = Reward Name
                if (rewardName === 'All' || rewardCell.textContent === rewardName) {
                    row.style.display = '';
                    // Show/hide Reward Name cell based on filter
                    if (rewardName === 'All') {
                        rewardCell.style.display = '';
                    } else {
                        rewardCell.style.display = 'none';
                    }
                } else {
                    row.style.display = 'none';
                }
            });
            // Show/hide the Reward Name column header
            if (rewardName === 'All') {
                rewardNameHeader.style.display = '';
            } else {
                rewardNameHeader.style.display = 'none';
            }
            // Update active button state
            document.querySelectorAll('.reward-filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
        }
        // Fetch the username from Twitch API based on userId
        async function getTitchUsernames(userIds) {
            const clientId = "mrjucsmsnri89ifucl66jj1n35jkj8";
            const authToken = "<?php echo $_SESSION['access_token']; ?>";
            const url = `https://api.twitch.tv/helix/users?id=${userIds.join('&id=')}`;
            const response = await fetch(url, {
                headers: {
                    'Client-ID': clientId,
                    'Authorization': `Bearer ${authToken}`,
                },
            });
            const data = await response.json();
            if (data.error) {
                console.error('Twitch API Error:', data.message);
                return [];
            }
            return data.data.map(user => user.display_name);
        }
        // Function to calculate the duration of the lurk based on the start time
        function calculateLurkDuration(startTime) {
            const start = new Date(startTime);
            const now = new Date();
            if (isNaN(start)) { return 'Invalid Date'; }
            const diff = now - start;
            const years = Math.floor(diff / (1000 * 60 * 60 * 24 * 365));
            const months = Math.floor((diff % (1000 * 60 * 60 * 24 * 365)) / (1000 * 60 * 60 * 24 * 30));
            const days = Math.floor((diff % (1000 * 60 * 60 * 24 * 30)) / (1000 * 60 * 60));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            let duration = '';
            if (years > 0) duration += `${years} year(s) `;
            if (months > 0) duration += `${months} month(s) `;
            if (days > 0) duration += `${days} day(s) `;
            if (hours > 0) duration += `${hours} hour(s) `;
            if (minutes > 0) duration += `${minutes} minute(s)`;
            return duration.trim() || 'Less than a minute';
        }
        // Formatting the watch time
        function formatWatchTime(seconds) {
            if (seconds === 0) {
                return "<span class='text-danger'>Not Recorded</span>";
            }
            const units = {
                year: 31536000,
                month: 2592000,
                day: 86400,
                hour: 3600,
                minute: 60
            };
            const parts = [];
            for (const [name, divisor] of Object.entries(units)) {
                const quotient = Math.floor(seconds / divisor);
                if (quotient > 0) {
                    parts.push(`${quotient} ${name}${quotient > 1 ? 's' : ''}`);
                    seconds -= quotient * divisor;
                }
            }
            return `<span class='text-success'>${parts.join(', ')}</span>`;
        }
        // Function to format date and time
        function formatDateTime(dateTime) {
            const date = new Date(dateTime);
            const options = { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: 'numeric' };
            return date.toLocaleDateString(undefined, options);
        }
        // Sidebar hamburger
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