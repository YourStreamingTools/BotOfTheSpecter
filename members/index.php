<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// Function to fetch usernames from Twitch API using user_id
function getTwitchUsernames($userIds) {
    $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
    $accessToken = $_SESSION['access_token'];
    $twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . implode('&id=', $userIds);

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
        return [];
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['data'])) {
        return $data['data'];
    }
    return [];
}

// PAGE TITLE
$title = "Members";
$commands = [];
$typos = [];
$lurkers = [];
$totalDeaths = [];
$gameDeaths = [];
$totalHugs = 0;
$hugCounts = [];
$totalKisses = 0;
$kissCounts = [];
$customCounts = [];

require_once "db_connect.php";

// Database credentials
$dbHost = 'sql.botofthespecter.com';
$dbUsername = 'USERNAME';
$dbPassword = 'PASSWORD';

$path = trim($_SERVER['REQUEST_URI'], '/');
$path = parse_url($path, PHP_URL_PATH);
$pathParts = explode('/', $path);
$username = isset($_GET['user']) ? sanitize_input($_GET['user']) : null;
$page = isset($_GET['page']) ? sanitize_input($_GET['page']) : null;
$buildResults = "Welcome " . $_SESSION['display_name'];
$notFound = false;

$modalMapping = [
    'commands' => 'commands-modal',
    'command-counts' => 'custom-command-modal',
    'lurkers' => 'lurkers-modal',
    'typos' => 'typos-modal',
    'deaths' => 'deaths-modal',
    'hugs' => 'hugs-modal',
    'kisses' => 'kisses-modal',
];

if ($username) {
    try {
        $checkDb = new PDO("mysql:host=$dbHost", $dbUsername, $dbPassword);
        $checkDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $escapedUsername = str_replace('_', '\\_', $username);
        $stmt = $checkDb->prepare("SHOW DATABASES LIKE :username");
        $stmt->bindParam(':username', $escapedUsername, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $notFound = true;
            throw new PDOException("Database does not exist", 1049);
        }

        $db = new PDO("mysql:host=$dbHost;dbname={$username}", $dbUsername, $dbPassword);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $username;
        $query = "SELECT command FROM custom_commands";
        $result = $db->query($query);
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $commands[] = $row;
        }

        // Lurkers
        $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times");
        $lurkerData = $getLurkers->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($lurkerData)) {
            $lurkerUserIds = array_column($lurkerData, 'user_id');
            $twitchUsers = getTwitchUsernames($lurkerUserIds);
            $currentTime = time(); // Current timestamp
            foreach ($twitchUsers as $user) {
                foreach ($lurkerData as $lurker) {
                    if ($lurker['user_id'] == $user['id']) {
                        $lurkers[] = [
                            'user_id' => $user['id'],
                            'username' => $user['display_name'],
                            'start_time' => $lurker['start_time'],
                            'duration' => $currentTime - strtotime($lurker['start_time'])
                        ];
                        break;
                    }
                }
            }
            // Sort the array by 'duration' in descending order (longest lurking time first)
            usort($lurkers, function ($a, $b) {
                return $b['duration'] <=> $a['duration'];
            });
        }
        // Typos
        $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
        $typos = $getTypos->fetchAll(PDO::FETCH_ASSOC);
        // Hugs
        $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
        $totalHugs = $getTotalHugs->fetch(PDO::FETCH_ASSOC)['total_hug_count'];
        $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
        $hugCounts = $getHugCounts->fetchAll(PDO::FETCH_ASSOC);
        // Kisses
        $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
        $totalKisses = $getTotalKisses->fetch(PDO::FETCH_ASSOC)['total_kiss_count'];
        $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
        $kissCounts = $getKissCounts->fetchAll(PDO::FETCH_ASSOC);
        // Custom Command Counts
        $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
        $customCounts = $getCustomCounts->fetchAll(PDO::FETCH_ASSOC);
        // Fetch total deaths & game-specific deaths
        $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
        $totalDeaths = $getTotalDeaths->fetch(PDO::FETCH_ASSOC);
        $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC");
        $gameDeaths = $getGameDeaths->fetchAll(PDO::FETCH_ASSOC);
        // Fetch todo items
        $getTodos = $db->query("SELECT * FROM todos ORDER BY id ASC");
        $todos = $getTodos->fetchAll(PDO::FETCH_ASSOC);
        // Close database connection
        $db = null;
        $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $username;
    } catch (PDOException $e) {
        if ($e->getCode() === '1049') {
            $notFound = true;
        } else {
            $buildResults = "Error: " . $e->getMessage();
        }
    }
}

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
                <div class="buttons">
                    <button class="button is-link" onclick="updateTable('commands')">Commands</button>
                    <button class="button is-link" onclick="updateTable('customCommands')">Custom Commands</button>
                    <button class="button is-link" onclick="updateTable('lurkers')">Lurkers</button>
                    <button class="button is-link" onclick="updateTable('typos')">Typos</button>
                    <button class="button is-link" onclick="updateTable('deaths')">Deaths</button>
                    <button class="button is-link" onclick="updateTable('hugs')">Hugs</button>
                    <button class="button is-link" onclick="updateTable('kisses')">Kisses</button>
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
    commands: <?php echo json_encode($commands); ?>,
    customCommands: <?php echo json_encode($customCounts); ?>,
    lurkers: <?php echo json_encode($lurkers); ?>,
    typos: <?php echo json_encode($typos); ?>,
    deaths: {
        total: "<?php echo htmlspecialchars($totalDeaths['death_count']); ?>",
        games: <?php echo json_encode($gameDeaths); ?>
    },
    hugs: {
        total: "<?php echo htmlspecialchars($totalHugs); ?>",
        users: <?php echo json_encode($hugCounts); ?>
    },
    kisses: {
        total: "<?php echo htmlspecialchars($totalKisses); ?>",
        users: <?php echo json_encode($kissCounts); ?>
    },
    todos: <?php echo json_encode($todos); ?> 
};

function updateTable(type) {
    const tableHeader = document.getElementById('table-header');
    const tableBody = document.getElementById('table-body');
    tableHeader.innerHTML = ''; // Clear existing headers
    tableBody.innerHTML = ''; // Clear existing rows
    if (type === 'commands') {
        tableHeader.innerHTML = '<th>Command</th>';
        data.commands.forEach(item => {
            tableBody.innerHTML += `<tr><td>!${item.command}</td></tr>`;
        });
    } else if (type === 'customCommands') {
        tableHeader.innerHTML = '<th>Custom Command</th><th>Count</th>';
        data.customCommands.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.command}</td><td>${item.count}</td></tr>`;
        });
    } else if (type === 'lurkers') {
        tableHeader.innerHTML = '<th>Username</th><th>Duration</th>';
        data.lurkers.forEach(item => {
            const duration = calculateDuration(item.start_time);
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${duration}</td></tr>`;
        });
    } else if (type === 'typos') {
        tableHeader.innerHTML = '<th>Username</th><th>Typo Count</th>';
        data.typos.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${item.typo_count}</td></tr>`;
        });
    } else if (type === 'deaths') {
        tableHeader.innerHTML = '<th>Game</th><th>Death Count</th>';
        tableBody.innerHTML = `<tr><td>Total</td><td>${data.deaths.total}</td></tr>`;
        data.deaths.games.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.game_name}</td><td>${item.death_count}</td></tr>`;
        });
    } else if (type === 'hugs') {
        tableHeader.innerHTML = '<th>Username</th><th>Hug Count</th>';
        tableBody.innerHTML = `<tr><td>Total</td><td>${data.hugs.total}</td></tr>`;
        data.hugs.users.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${item.hug_count}</td></tr>`;
        });
    } else if (type === 'kisses') {
        tableHeader.innerHTML = '<th>Username</th><th>Kiss Count</th>';
        tableBody.innerHTML = `<tr><td>Total</td><td>${data.kisses.total}</td></tr>`;
        data.kisses.users.forEach(item => {
            tableBody.innerHTML += `<tr><td>${item.username}</td><td>${item.kiss_count}</td></tr>`;
        });
    } else if (type === 'todos') { 
        tableHeader.innerHTML = '<th>Objective</th><th>Created</th><th>Last Updated</th><th>Completed</th>';
        data.todos.forEach(item => {
            tableBody.innerHTML += `<tr>
                <td>${item.completed == 'Yes' ? '<s>' + item.objective + '</s>' : item.objective}</td>
                <td>${item.created_at}</td>
                <td>${item.updated_at}</td>
                <td>${item.completed}</td>
            </tr>`;
        });
    }
}

document.addEventListener('DOMContentLoaded', () => updateTable('commands')); 

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