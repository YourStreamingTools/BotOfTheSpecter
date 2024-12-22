<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
        $customCommands = $getCustomCommands ? $getCustomCommands->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch lurkers
        $getLurkers = $db->query("SELECT * FROM lurk_times");
        $lurkers = $getLurkers ? $getLurkers->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch watch time from the database
        $getWatchTime = $db->query("SELECT * FROM watch_time");
        $watchTimeData = $getWatchTime ? $getWatchTime->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch typo counts
        $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
        $typos = $getTypos ? $getTypos->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch total deaths
        $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
        $totalDeaths = $getTotalDeaths ? $getTotalDeaths->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch game-specific deaths
        $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC");
        $gameDeaths = $getGameDeaths ? $getGameDeaths->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch total hug counts
        $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
        $totalHugs = $getTotalHugs ? $getTotalHugs->fetch_assoc()['total_hug_count'] : 0;

        // Fetch hug username-specific counts
        $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
        $hugCounts = $getHugCounts ? $getHugCounts->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch total kiss counts
        $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
        $totalKisses = $getTotalKisses ? $getTotalKisses->fetch_assoc()['total_kiss_count'] : 0;

        // Fetch kiss counts
        $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
        $kissCounts = $getKissCounts ? $getKissCounts->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch custom counts
        $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
        $customCounts = $getCustomCounts ? $getCustomCounts->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch custom user counts
        $getUserCounts = $db->query("SELECT command, user, count FROM user_counts");
        $userCounts = $getUserCounts ? $getUserCounts->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch seen users data
        $getSeenUsersData = $db->query("SELECT * FROM seen_users ORDER BY id");
        $seenUsersData = $getSeenUsersData ? $getSeenUsersData->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch timed messages
        $getTimedMessages = $db->query("SELECT * FROM timed_messages ORDER BY id DESC");
        $timedMessagesData = $getTimedMessages ? $getTimedMessages->fetch_all(MYSQLI_ASSOC) : [];

        // Fetch channel point rewards sorted by cost (low to high)
        $getChannelPointRewards = $db->query("SELECT * FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
        $channelPointRewards = $getChannelPointRewards ? $getChannelPointRewards->fetch_all(MYSQLI_ASSOC) : [];

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
        $todos = $getTodos ? $getTodos->fetch_all(MYSQLI_ASSOC) : [];

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
$customCommands = isset($customCommands) ? $customCommands : [];
$lurkers = isset($lurkers) ? $lurkers : [];
$typos = isset($typos) ? $typos : [];
$totalDeaths = isset($totalDeaths) ? $totalDeaths : [];
$gameDeaths = isset($gameDeaths) ? $gameDeaths : [];
$hugCounts = isset($hugCounts) ? $hugCounts : [];
$kissCounts = isset($kissCounts) ? $kissCounts : [];
$watchTimeData = isset($watchTimeData) ? $watchTimeData : [];
$totalHugs = isset($totalHugs) ? $totalHugs : 0;
$totalKisses = isset($totalKisses) ? $totalKisses : 0;

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
                    <button class="button is-link" onclick="loadData('customCommands')">Custom Commands</button>
                    <button class="button is-link" onclick="loadData('lurkers')">Lurkers</button>
                    <button class="button is-link" onclick="loadData('typos')">Typos</button>
                    <button class="button is-link" onclick="loadData('deaths')">Deaths</button>
                    <button class="button is-link" onclick="loadData('hugs')">Hugs</button>
                    <button class="button is-link" onclick="loadData('kisses')">Kisses</button>
                    <button class="button is-link" onclick="loadData('watchTime')">Watch Time</button>
                    <button class="button is-link" onclick="loadData('todos')">To-Do Items</button>
                </div>
                <div class="content">
                    <div class="box">
                        <h3 id="table-title" class="title" style="color: white;">Custom Commands</h3>
                        <table class="table is-striped is-fullwidth" style="table-layout: fixed; width: 100%;">
                            <thead>
                                <tr>
                                    <th id="info-column-data" style="color: white; width: 33%;">Command</th>
                                    <th id="data-column-info" style="color: white; width: 33%;">Count</th>
                                    <th id="count-column" style="color: white; width: 33%; display: none;">Additional Info</th>
                                </tr>
                            </thead>
                            <tbody id="table-body">
                                <!-- Content will be dynamically injected here -->
                            </tbody>
                        </table>
                    </div>
                </div>
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
function loadData(type) {
    let data;
    let title;
    let dataColumn;
    let infoColumn;
    let countColumnVisible = false;
    let additionalColumnName;
    let output = '';
    switch(type) {
        case 'lurkers':
            data = <?php echo json_encode($lurkers); ?>;
            title = 'Currently Lurking Users';
            dataColumn = 'Time';
            infoColumn = 'Username'; 
            break;
        case 'typos':
            data = <?php echo json_encode($typos); ?>;
            title = 'Typo Counts';
            dataColumn = 'Typo Count';
            infoColumn = 'Username';
            break;
        case 'deaths':
            data = <?php echo json_encode($gameDeaths); ?>;
            title = 'Deaths Overview';
            dataColumn = 'Death Count';
            infoColumn = 'Game'; 
            break;
        case 'hugs':
            data = <?php echo json_encode($hugCounts); ?>;
            title = 'Hug Counts';
            dataColumn = 'Hug Count';
            infoColumn = 'Username'; 
            break;
        case 'kisses':
            data = <?php echo json_encode($kissCounts); ?>;
            title = 'Kiss Counts';
            dataColumn = 'Kiss Count';
            infoColumn = 'Username'; 
            break;
        case 'custom':
            data = <?php echo json_encode($customCounts); ?>;
            title = 'Custom Counts';
            dataColumn = 'Used';
            infoColumn = 'Command'; 
            break;
        case 'userCounts':
            data = <?php echo json_encode($userCounts); ?>;
            title = 'User Counts for Commands';
            infoColumn = 'Command';
            dataColumn = 'Count';
            break;
        case 'watchTime': 
            data = <?php echo json_encode($watchTimeData); ?>;
            title = 'Watch Time';
            infoColumn = 'Username';
            dataColumn = 'Online Watch Time';
            additionalColumnName = 'Offline Watch Time';
            countColumnVisible = true;
            data.sort((a, b) => b.total_watch_time_live - a.total_watch_time_live || b.total_watch_time_offline - a.total_watch_time_offline);
            break;
    }
    document.getElementById('data-column-info').innerText = dataColumn;
    document.getElementById('info-column-data').innerText = infoColumn;
    if (countColumnVisible) {
        document.getElementById('count-column').style.display = '';
        document.getElementById('count-column').innerText = additionalColumnName;
    } else {
        document.getElementById('count-column').style.display = 'none';
    }
    data.forEach(item => {
        output += `<tr>`;
        if (type === 'lurkers') {
            output += `<td>${item.username}</td><td><span class='has-text-success'>${item.lurk_duration}</span></td>`; 
        } else if (type === 'typos') {
            output += `<td>${item.username}</td><td><span class='has-text-success'>${item.typo_count}</span></td>`; 
        } else if (type === 'deaths') {
            output += `<td>${item.game_name}</td><td><span class='has-text-success'>${item.death_count}</span></td>`; 
        } else if (type === 'hugs') {
            output += `<td>${item.username}</td><td><span class='has-text-success'>${item.hug_count}</span></td>`; 
        } else if (type === 'kisses') {
            output += `<td>${item.username}</td><td><span class='has-text-success'>${item.kiss_count}</span></td>`; 
        } else if (type === 'custom') {
            output += `<td>${item.command}</td><td><span class='has-text-success'>${item.count}</span></td>`; 
        } else if (type === 'userCounts') {
            output += `<td>${item.user}</td><td><span class='has-text-success'>${item.command}</span></td><td><span class='has-text-success'>${item.count}</span></td>`; 
        } else if (type === 'watchTime') { 
            output += `<td>${item.username}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td>`;
        }
        output += `</tr>`;
    });
    document.getElementById('table-title').innerText = title;
    document.getElementById('table-body').innerHTML = output;
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