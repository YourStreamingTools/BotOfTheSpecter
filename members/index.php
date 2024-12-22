<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: logout.php');
    exit();
}

// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// Function to fetch usernames from Twitch API using user_id
function getTwitchUsernames($userIds) {
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

// PAGE TITLE
$title = "Members";
// Initialize all variables as empty arrays or values
$commands = [];
$typos = [];
$lurkers = [];
$watchTimeData = [];
$totalDeaths = [];
$gameDeaths = [];
$hugCounts = [];
$kissCounts = [];
$customCounts = [];
$userCounts = [];
$seenUsersData = [];
$timedMessagesData = [];
$channelPointRewards = [];
$profileData = [];
$totalHugs = 0;
$totalKisses = 0;

require_once "db_connect.php";

// Database credentials
$dbHost = 'sql.botofthespecter.com';
$dbUsername = 'USERNAME';
$dbPassword = 'PASSWORD';

$path = trim($_SERVER['REQUEST_URI'], '/');
$path = parse_url($path, PHP_URL_PATH);
$username = isset($_GET['user']) ? sanitize_input($_GET['user']) : null;
$page = isset($_GET['page']) ? sanitize_input($_GET['page']) : null;
$buildResults = "Welcome " . $_SESSION['display_name'];
$notFound = false;

if ($username) {
    try {
        $checkDb = new mysqli($dbHost, $dbUsername, $dbPassword);
        $escapedUsername = $checkDb->real_escape_string($username);
        $stmt = $checkDb->prepare("SHOW DATABASES LIKE ?");
        $stmt->bind_param('s', $escapedUsername);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$result) {
            $notFound = true;
            throw new Exception("Database does not exist", 1049);
        }
        $db = new mysqli($dbHost, $dbUsername, $dbPassword, $username);
        if ($db->connect_error) {
            throw new Exception("Connection failed: " . $db->connect_error);
        }
        $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $username;

        // Fetch all custom commands
        $getCustomCommands = $db->query("SELECT * FROM custom_commands");
        $customCommands = $getCustomCommands->fetch_all(MYSQLI_ASSOC);
        // Fetch lurkers
        $getLurkers = $db->query("SELECT * FROM lurk_times");
        $lurkers = $getLurkers->fetch_all(MYSQLI_ASSOC);
        // Fetch watch time from the database
        $getWatchTime = $db->query("SELECT * FROM watch_time");
        $watchTimeData = $getWatchTime->fetch_all(MYSQLI_ASSOC);
        // Fetch typo counts
        $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
        $typos = $getTypos->fetch_all(MYSQLI_ASSOC);
        // Fetch total deaths
        $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
        $totalDeaths = $getTotalDeaths->fetch_all(MYSQLI_ASSOC);
        // Fetch game-specific deaths
        $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC");
        $gameDeaths = $getGameDeaths->fetch_all(MYSQLI_ASSOC);
        // Fetch total hug counts
        $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
        $totalHugs = $getTotalHugs->fetch_all(MYSQLI_ASSOC);
        // Fetch hug username-specific counts
        $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
        $hugCounts = $getHugCounts->fetch_all(MYSQLI_ASSOC);
        // Fetch total kiss counts
        $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
        $totalKisses = $getTotalKisses->fetch_all(MYSQLI_ASSOC);
        // Fetch kiss counts
        $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
        $kissCounts = $getKissCounts->fetch_all(MYSQLI_ASSOC);
        // Fetch custom counts
        $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
        $customCounts = $getCustomCounts->fetch_all(MYSQLI_ASSOC);
        // Fetch custom user counts
        $getUserCounts = $db->query("SELECT command, user, count FROM user_counts");
        $userCounts = $getUserCounts->fetch_all(MYSQLI_ASSOC);
        // Fetch seen users data
        $getSeenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id");
        $seenUsersData = $getSeenUsersData->fetch_all(MYSQLI_ASSOC);
        // Fetch timed messages
        $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
        $timedMessagesData = $getTimedMessages->fetch_all(MYSQLI_ASSOC);
        // Fetch channel point rewards sorted by cost (low to high)
        $getChannelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
        $channelPointRewards = $getChannelPointRewards->fetch_all(MYSQLI_ASSOC);
        // Fetch profile data
        $getProfileSettings = $db->query("SELECT * FROM profile");
        $profileData = $getProfileSettings->fetch_all(MYSQLI_ASSOC);
        // Fetch todo items
        $getTodos = $db->query("
            SELECT 
                t.id, 
                t.objective, 
                t.completed, 
                t.created_at, 
                t.updated_at,
                c.category AS category_name  
            FROM 
                todos t
            JOIN 
                categories c ON t.category = c.id 
            ORDER BY 
                t.id ASC
        ");
        $todos = $getTodos->fetch_all(MYSQLI_ASSOC);
        // Close database connection
        $db->close();
    } catch (Exception $e) {
        if ($e->getCode() == 1049) {
            $notFound = true;
        } else {
            $buildResults = "Error: " . $e->getMessage();
        }
        // Close database connection if it was opened
        if (isset($db)) {
            $db->close();
        }
    }
}

// Ensure $todos is defined before using it
$todos = isset($todos) ? $todos : [];

function getTimeDifference($start_time) {
    $startDateTime = new DateTime($start_time);
    $currentDateTime = new DateTime();
    $interval = $startDateTime->diff($currentDateTime);
    $timeString = "";
    if ($interval->y > 0) {
        $timeString .= $interval->y . " year" . ($interval->y > 1 ? "s" : "") . ", ";
    }
    if ($interval->m > 0) {
        $timeString .= $interval->m . " month" . ($interval->m > 1 ? "s" : "") . ", ";
    }
    if ($interval->d > 0) {
        $timeString .= $interval->d . " day" . ($interval->d > 1 ? "s" : "") . ", ";
    }
    if ($interval->h > 0) {
        $timeString .= $interval->h . " hour" . ($interval->h > 1 ? "s" : "") . ", ";
    }
    $timeString .= $interval->i . " minute" . ($interval->i > 1 ? "s" : "");
    return rtrim($timeString, ', ');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.0/css/bulma.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="../custom.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description" content="BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics." />
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
            <div class="navbar-item">
                <img class="is-rounded" id="profile-image" src="<?php echo $_SESSION['profile_image_url']; ?>" alt="Profile Image">&nbsp;&nbsp;<span class="display-name"><?php echo $_SESSION['display_name']; ?></span>
            </div>
        </div>
    </div>
</div>

<div class="container mt-6">
    <br><br>
    <div class="columns is-centered">
        <div class="column is-three-quarters">
            <?php if (!$username): ?> 
                <br>
                <div class="box">
                    <h2 class="title">Enter the Twitch Username:</h2>
                    <form id="usernameForm" class="field is-grouped" onsubmit="redirectToUser(event)">
                        <div class="control is-expanded">
                            <input type="text" id="user_search" name="user" class="input" placeholder="Enter username" required>
                        </div>
                        <div class="control">
                            <input type="submit" value="Search" class="button is-link">
                        </div>
                    </form>
                </div>
            <?php else: ?> 
                <div class="notification is-info"><?php echo "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $username; ?> </div>
                <div class="buttons">
                    <button class="button is-link" onclick="updateTable('customCommands')">Custom Commands</button>
                    <button class="button is-link" onclick="updateTable('lurkers')">Lurkers</button>
                    <button class="button is-link" onclick="updateTable('typos')">Typos</button>
                    <button class="button is-link" onclick="updateTable('deaths')">Deaths</button>
                    <button class="button is-link" onclick="updateTable('hugs')">Hugs</button>
                    <button class="button is-link" onclick="updateTable('kisses')">Kisses</button>
                    <button class="button is-link" onclick="updateTable('watchTime')">Watch Time</button>
                    <button class="button is-link" onclick="updateTable('todos')">To-Do Items</button>
                </div>
                <table class="table is-fullwidth is-striped">
                    <thead>
                        <tr id="table-header">
                            <!-- Default table header -->
                        </tr>
                    </thead>
                    <tbody id="table-body">
                        <!-- Dynamic table content goes here -->
                    </tbody>
                </table>
            <?php endif; ?> 
        </div>
    </div>
</div>
<br><br>
<footer class="footer">
    <div class="content has-text-centered">
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.
    </div>
</footer>

<script>
const data = {
    customCommands: <?php echo json_encode($customCommands); ?>,
    lurkers: <?php echo json_encode($lurkers); ?>,
    typos: <?php echo json_encode($typos); ?>,
    deaths: {
        total: <?php echo json_encode($totalDeaths); ?>,
        games: <?php echo json_encode($gameDeaths); ?>
    },
    hugs: {
        total: <?php echo json_encode($totalHugs); ?>,
        users: <?php echo json_encode($hugCounts); ?>
    },
    kisses: {
        total: <?php echo json_encode($totalKisses); ?>,
        users: <?php echo json_encode($kissCounts); ?>
    },
    todos: <?php echo json_encode($todos); ?>,
    watchTime: <?php echo json_encode($watchTimeData); ?>
};

function updateTable(type) {
    const tableHeader = document.getElementById('table-header');
    const tableBody = document.getElementById('table-body');
    if (!tableHeader || !tableBody) {
        return;
    }
    tableHeader.innerHTML = ''; // Clear existing headers
    tableBody.innerHTML = ''; // Clear existing rows
    if (type === 'customCommands') {
        tableHeader.innerHTML = '<th>Custom Command</th>';
        data.customCommands.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.command}</td></tr>`;
        });
    } else if (type === 'lurkers') {
        tableHeader.innerHTML = '<th>Username</th><th>Duration</th>';
        data.lurkers.forEach(item => {
            const duration = calculateDuration(item.start_time);
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${duration}</td></tr>`;
        });
    } else if (type === 'typos') {
        tableHeader.innerHTML = '<th>Username</th><th>Count</th>';
        data.typos.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${item.typo_count}</td></tr>`;
        });
    } else if (type === 'deaths') {
        tableHeader.innerHTML = '<th>Game</th><th>Death Count</th>';
        tableBody.innerHTML = `<tr><td>Total</td><td>${data.deaths.total.length > 0 ? data.deaths.total[0].death_count : 0}</td></tr>`;
        data.deaths.games.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.game_name}</td><td>${item.death_count}</td></tr>`;
        });
    } else if (type === 'hugs') {
        tableHeader.innerHTML = '<th>Username</th><th>Hug Count</th>';
        tableBody.innerHTML = `<tr><td>Total</td><td>${data.hugs.total.length > 0 ? data.hugs.total[0].total_hug_count : 0}</td></tr>`;
        data.hugs.users.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${item.hug_count}</td></tr>`;
        });
    } else if (type === 'kisses') {
        tableHeader.innerHTML = '<th>Username</th><th>Kiss Count</th>';
        tableBody.innerHTML = `<tr><td>Total</td><td>${data.kisses.total.length > 0 ? data.kisses.total[0].total_kiss_count : 0}</td></tr>`;
        data.kisses.users.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${item.kiss_count}</td></tr>`;
        });
    } else if (type === 'todos') { 
        tableHeader.innerHTML = '<th>Objective</th><th>Category</th><th>Created</th><th>Last Updated</th><th>Completed</th>';
        data.todos.forEach(item => {
            tableBody.innerHTML += `<tr>
                <td>${item.completed == 'Yes' ? '<s>' + item.objective + '</s>' : item.objective}</td>
                <td>${item.category_name}</td>
                <td>${item.created_at}</td>
                <td>${item.updated_at}</td>
                <td>${item.completed}</td>
            </tr>`;
        });
    } else if (type === 'watchTime') {
        tableHeader.innerHTML = '<th>Username</th><th>Online Watch Time</th><th>Offline Watch Time</th>';
        data.watchTime.sort((a, b) => b.total_watch_time_live - a.total_watch_time_live || b.total_watch_time_offline - a.total_watch_time_offline);
        data.watchTime.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td></tr>`;
        });
    }
}

document.addEventListener('DOMContentLoaded', () => updateTable('customCommands')); 

function calculateDuration(startTime) {
    const start = new Date(startTime);
    const now = new Date();
    const diff = now - start; // Difference in milliseconds
    const years = Math.floor(diff / (1000 * 60 * 60 * 24 * 365));
    const months = Math.floor((diff % (1000 * 60 * 60 * 24 * 365)) / (1000 * 60 * 60 * 24 * 30));
    const days = Math.floor((diff % (1000 * 60 * 60 * 24 * 30)) / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const parts = [];
    if (years > 0) parts.push(`${years} year${years > 1 ? 's' : ''}`);
    if (months > 0) parts.push(`${months} month${months > 1 ? 's' : ''}`);
    if (days > 0) parts.push(`${days} day${days > 1 ? 's' : ''}`);
    if (hours > 0) parts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
    if (minutes > 0) parts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
    return parts.length > 0 ? parts.join(', ') : 'Just now';
}

function formatWatchTime(minutes) {
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    return `${hours}h ${remainingMinutes}m`;
}

function redirectToUser(event) {
    event.preventDefault();
    const username = document.getElementById('user_search').value.trim();
    if (username) {
        window.location.href = `/${encodeURIComponent(username)}/`;
    }
}
</script>
</body>
</html>