<?php
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

    // Twitch API endpoint
    $twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . implode('&id=', $userIds);

    // Set up headers for the API request
    $headers = [
        "Client-ID: $clientID",
        "Authorization: Bearer $accessToken",
    ];

    // Initialize cURL session
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $twitchApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute API request
    $response = curl_exec($ch);
    curl_close($ch);

    // Decode the JSON response
    $data = json_decode($response, true);

    // Check for valid response
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

// Mapping of page paths to modal IDs
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
        // Check if the database exists for the given username
        $checkDb = new PDO("mysql:host=$dbHost", $dbUsername, $dbPassword);
        $checkDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Escape underscore (_) only for the SHOW DATABASES LIKE query
        $username = isset($_GET['user']) ? strtolower(sanitize_input($_GET['user'])) : null;
        $escapedUsername = str_replace('_', '\\_', $username);
        // Prepare the statement to prevent SQL injection
        $stmt = $checkDb->prepare("SHOW DATABASES LIKE :username");
        $stmt->bindParam(':username', $escapedUsername, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch();
        if (!$result) {
            $notFound = true;
            throw new PDOException("Database does not exist", 1049);
        }
        // Use the real username (without escaping) when connecting to the actual database
        $db = new PDO("mysql:host=$dbHost;dbname={$username}", $dbUsername, $dbPassword);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $username;
        $query = "SELECT command FROM custom_commands";
        $result = $db->query($query);
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $commands[] = $row;
        }
        // Lurkers
        $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times ORDER BY start_time DESC");
        $lurkerData = $getLurkers->fetchAll(PDO::FETCH_ASSOC);
        // Use the Twitch API to get usernames based on user_ids
        if (!empty($lurkerData)) {
            $lurkerUserIds = array_column($lurkerData, 'user_id');
            $twitchUsers = getTwitchUsernames($lurkerUserIds);
            foreach ($twitchUsers as $user) {
                foreach ($lurkerData as $lurker) {
                    if ($lurker['user_id'] == $user['id']) {
                        $lurkers[] = [
                            'user_id' => $user['id'],
                            'username' => $user['display_name'],
                            'start_time' => $lurker['start_time']
                        ];
                        break;
                    }
                }
            }
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
        // Close database connection
        $db = null;
        $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $username;
    } catch (PDOException $e) {
        // Check if the error is due to "Unknown database" (error code 1049)
        if ($e->getCode() === '1049') {
            $notFound = true;  // The database does not exist, mark user as not found
        } else {
            // For other database-related errors, display the error message
            $buildResults = "Error: " . $e->getMessage();
        }
    }
}

// Function to calculate the time difference and return it in a human-readable format
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

<div class="container">
    <div class="columns is-centered">
        <div class="column is-three-quarters">
        <?php if ($notFound): ?>
            <div class="notification is-danger">The username "<?php echo htmlspecialchars($username); ?>" was not found in our system.</div>
        <?php else: ?>
            <div class="notification is-info"><?php echo $buildResults; ?></div>
            <?php if ($username): ?>
                <div class="buttons">
                    <button class="button is-link" data-target="#commands-modal" aria-haspopup="true">Commands</button>
                    <button class="button is-link" data-target="#custom-command-modal" aria-haspopup="true">Custom Command Count</button>
                    <button class="button is-link" data-target="#lurkers-modal" aria-haspopup="true">Lurkers</button>
                    <button class="button is-link" data-target="#typos-modal" aria-haspopup="true">Typos</button>
                    <button class="button is-link" data-target="#deaths-modal" aria-haspopup="true">Deaths</button>
                    <button class="button is-link" data-target="#hugs-modal" aria-haspopup="true">Hugs</button>
                    <button class="button is-link" data-target="#kisses-modal" aria-haspopup="true">Kisses</button>
                    <!--<button class="button is-link" data-target="#todo-modal" aria-haspopup="true">To Do List</button>-->
                </div>
                <!-- Commands Modal -->
                <div id="commands-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Commands</h2>
                            <div class="columns is-multiline">
                                <?php foreach ($commands as $command): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered command-box" data-command="!<?php echo htmlspecialchars($command['command']); ?>" style="cursor: pointer;">
                                            <p>!<?php echo htmlspecialchars($command['command']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Custom Command Count Modal -->
                <div id="custom-command-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Custom Command Count</h2>
                            <div class="columns is-multiline">
                                <?php foreach ($customCounts as $custom): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered">
                                            <p><?php echo htmlspecialchars($custom['command']); ?></p>
                                            <p><?php echo htmlspecialchars($custom['count']); ?> uses</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Lurkers Modal -->
                <div id="lurkers-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Lurkers</h2>
                            <div class="columns is-multiline">
                                <?php foreach ($lurkers as $lurker): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered">
                                            <p><?php echo htmlspecialchars($lurker['username']); ?></p>
                                            <p><?php echo getTimeDifference($lurker['start_time']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Typos Modal -->
                <div id="typos-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Typos</h2>
                            <div class="columns is-multiline">
                                <?php foreach ($typos as $typo): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered">
                                            <p><?php echo htmlspecialchars($typo['username']); ?></p>
                                            <p><?php echo htmlspecialchars($typo['typo_count']); ?> typos</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Deaths Modal -->
                <div id="deaths-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Deaths</h2>
                            <div class="columns is-multiline">
                                <div class="column is-full">
                                    <p>Total Deaths: <?php echo htmlspecialchars($totalDeaths['death_count']); ?></p>
                                </div>
                                <?php foreach ($gameDeaths as $gameDeath): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered">
                                            <p><?php echo htmlspecialchars($gameDeath['game_name']); ?></p>
                                            <p><?php echo htmlspecialchars($gameDeath['death_count']); ?> deaths</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Hugs Modal -->
                <div id="hugs-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Hugs</h2>
                            <div class="columns is-multiline">
                                <div class="column is-full">
                                    <p>Total Hugs: <?php echo htmlspecialchars($totalHugs); ?></p>
                                </div>
                                <?php foreach ($hugCounts as $hug): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered">
                                            <p><?php echo htmlspecialchars($hug['username']); ?></p>
                                            <p><?php echo htmlspecialchars($hug['hug_count']); ?> hugs</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Kisses Modal -->
                <div id="kisses-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Kisses</h2>
                            <div class="columns is-multiline">
                                <div class="column is-full">
                                    <p>Total Kisses: <?php echo htmlspecialchars($totalKisses); ?></p>
                                </div>
                                <?php foreach ($kissCounts as $kiss): ?>
                                    <div class="column is-one-third">
                                        <div class="box has-text-centered">
                                            <p><?php echo htmlspecialchars($kiss['username']); ?></p>
                                            <p><?php echo htmlspecialchars($kiss['kiss_count']); ?> kisses</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- To Do List Modal --><!--
                <div id="todo-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">To Do List</h2>
                            <!-- Your To Do List content goes here --><!--
                            <p>Coming soon!</p>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>-->
            <?php else: ?>
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
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<footer class="footer">
    <div class="content has-text-centered">
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.
    </div>
</footer>

<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
// Pass the modal mapping to JavaScript
var modalMapping = <?php echo json_encode($modalMapping); ?>;
var page = '<?php echo $page; ?>';

// Function to redirect form submission to the new URL structure
function redirectToUser(event) {
    event.preventDefault();
    const username = document.getElementById('user_search').value.trim();
    if (username) {
        // Redirect to the desired URL structure
        window.location.href = `/${encodeURIComponent(username)}`;
    }
}

// Attach event listener to all command boxes to copy command to clipboard
document.querySelectorAll('.command-box').forEach(box => {
    box.addEventListener('click', () => {
        const command = box.getAttribute('data-command');
        // Create a temporary text area to copy the command to clipboard
        const tempTextArea = document.createElement('textarea');
        tempTextArea.value = command;
        document.body.appendChild(tempTextArea);
        tempTextArea.select();
        document.execCommand('copy');
        document.body.removeChild(tempTextArea);
        Toastify({
            text: `Copied: ${command}`,
            duration: 3000,
            gravity: "top",
            position: "center",
            backgroundColor: "#4CAF50",
            stopOnFocus: true,
        }).showToast();
    });
});

// Script to handle modal open and close
document.querySelectorAll('.button').forEach(button => {
    button.addEventListener('click', () => {
        const target = button.dataset.target;
        const modal = document.querySelector(target);
        if (modal) {
            modal.classList.add('is-active');
        }
    });
});

document.querySelectorAll('.modal-close, .modal-background').forEach(close => {
    close.addEventListener('click', () => {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('is-active');
        });
    });
});

// Auto-open modal based on the page variable
document.addEventListener('DOMContentLoaded', function() {
    if (page) {
        var modalId = modalMapping[page];
        if (modalId) {
            var modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('is-active');
            }
        }
    }
});
</script>
</body>
</html>