<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
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

// Get the username from the URL path
$username = isset($_GET['user']) ? sanitize_input($_GET['user']) : null;
$buildResults = "Welcome " . $_SESSION['display_name'];
if ($username) {
    try {
        // Connect to the MySQL database
        $db = new PDO("mysql:host=sql.botofthespecter.com;dbname={$username}", "specter", "Rg8sJ2h3FyL9");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Update Title for the Username
        $title = "Member: $username";
        // Fetch custom commands
        $query = "SELECT command FROM custom_commands";
        $result = $db->query($query);
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $commands[] = $row;
        }
        // Lurkers
        $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times ORDER BY start_time DESC");
        $lurkerUserIds = $getLurkers->fetchAll(PDO::FETCH_COLUMN, 0);
        // Use the Twitch API to get usernames based on user_ids
        if (!empty($lurkerUserIds)) {
            $twitchUsers = getTwitchUsernames($lurkerUserIds);
            foreach ($twitchUsers as $user) {
                $lurkers[] = [
                    'user_id' => $user['id'],
                    'username' => $user['display_name'],
                    'start_time' => $user['start_time'] ?? ''
                ];
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
        $buildResults = "Welcome " . $_SESSION['display_name'] . ". Your viewing information for: " . $username;
    } catch (PDOException $e) {
        $buildResults = "Error: " . $e->getMessage();
    }
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
    <link rel="stylesheet" href="custom.css">
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
                                            <p><?php echo date("n/j/Y", strtotime($lurker['start_time'])); ?></p>
                                            <p><?php echo date("g:i:s A", strtotime($lurker['start_time'])); ?></p>
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
// Function to redirect form submission to the new URL structure
function redirectToUser(event) {
    event.preventDefault();
    const username = document.getElementById('user_search').value.trim();
    if (username) {
        // Redirect to the desired URL structure
        window.location.href = `https://members.botofthespecter.com/${encodeURIComponent(username)}`;
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
        modal.classList.add('is-active');
    });
});

document.querySelectorAll('.modal-close, .modal-background').forEach(close => {
    close.addEventListener('click', () => {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('is-active');
        });
    });
});
</script>
</body>
</html>