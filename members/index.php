<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

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
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// Function to fetch usernames from Twitch API using user_id
function getTitchUsernames($userIds) {
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
function sanitize_custom_vars($response) {
    $switches = [ '(customapi.' ];
    foreach ($switches as $switch) {
        $pattern = '/' . preg_quote($switch, '/') . '[^)]*\)/';
        $replacement = rtrim($switch, '.') . ')';
        $response = preg_replace($pattern, $replacement, $response);
    }
    $response = preg_replace('/\)\)+/', ')', $response);
    return $response;
}

// PAGE TITLE
$title = "Members";

// Database credentials
include '/var/www/config/database.php';

$path = trim($_SERVER['REQUEST_URI'], '/');
$path = parse_url($path, PHP_URL_PATH);
$username = isset($_GET['user']) ? sanitize_input($_GET['user']) : null;
$page = isset($_GET['page']) ? sanitize_input($_GET['page']) : null;
$buildResults = "Welcome " . $_SESSION['display_name'];
$notFound = false;

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
}

if (isset($_SESSION['redirect_url'])) {
    $redirectUrl = $_SESSION['redirect_url'];
    unset($_SESSION['redirect_url']);
    header("Location: $redirectUrl");
    exit();
}

if (isset($_GET['user'])) {
    $username = $_GET['user'];
    $_SESSION['username'] = $username;
    $buildResults = "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . (isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown user');
    include "/var/www/dashboard/user_db.php";
    // Sanitize custom command responses
    $commands = array_map('sanitize_custom_vars', $commands);
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
    <script type="text/javascript">
        // Pass PHP data to JavaScript
        const customCommands = <?php echo json_encode(array_map('sanitize_custom_vars', $commands)); ?>;
        const lurkers = <?php echo json_encode($lurkers); ?>;
        const typos = <?php echo json_encode($typos); ?>;
        const gameDeaths = <?php echo json_encode($gameDeaths); ?>;
        const hugCounts = <?php echo json_encode($hugCounts); ?>;
        const kissCounts = <?php echo json_encode($kissCounts); ?>;
        const customCounts = <?php echo json_encode($customCounts); ?>;
        const userCounts = <?php echo json_encode($userCounts); ?>;
        const watchTimeData = <?php echo json_encode($watchTimeData); ?>;
        const todos = <?php echo json_encode($todos); ?>;
        const todoCategories = <?php echo json_encode($todoCategories); ?>;
        const highfiveCounts = <?php echo json_encode($highfiveCounts); ?>;
        const rewardCounts = <?php echo json_encode($rewardCounts); ?>;
        const quotesData = <?php echo json_encode($quotesData); ?>;
    </script>
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
        <div class="column is-fullwidth">
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
                <div class="notification is-info"><?php echo "Welcome " . $_SESSION['display_name'] . ". You're viewing information for: " . $_SESSION['username']; ?> </div>
                <div class="buttons is-centered">
                    <button class="button is-info" onclick="setActiveButton(this, 'customCommands')">Custom Commands</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'lurkers')">Lurkers</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'typos')">Typo Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'deaths')">Deaths Overview</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'hugs')">Hug Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'kisses')">Kiss Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'highfives')">High-Five Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'custom')">Custom Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'userCounts')">User Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'rewardCounts')">Reward Counts</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'watchTime')">Watch Time</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'quotes')">Quotes</button>
                    <button class="button is-info" onclick="setActiveButton(this, 'todos')">To-Do Items</button>
                </div>
                <div class="content">
                    <div class="box">
                        <h3 id="table-title" class="title has-text-centered" style="color: white;"></h3>
                        <table class="table is-striped is-fullwidth" style="table-layout: fixed; width: 100%;">
                            <thead>
                                <tr>
                                    <th id="info-column-data" style="color: white;"></th>
                                    <th id="data-column-info" style="color: white;"></th>
                                    <th id="additional-column1" style="color: white; display: none;"></th>
                                    <th id="additional-column2" style="color: white; display: none;"></th>
                                    <th id="additional-column3" style="color: white; display: none;"></th>
                                    <th id="additional-column4" style="color: white; display: none;"></th>
                                    <th id="additional-column5" style="color: white; display: none;"></th>
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
function redirectToUser(event) {
    event.preventDefault();
    const username = document.getElementById('user_search').value.trim();
    if (username) {
        window.location.href = '/' + encodeURIComponent(username) + '/';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadData('customCommands');
});

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
    
    // Update active button state - highlight the currently selected button
    document.querySelectorAll('.buttons .button').forEach(button => {
        // First reset all buttons to the default state
        button.classList.remove('is-primary');
        button.classList.add('is-info');
    });
    
    // Find the button that corresponds to the current data type and highlight it
    const buttonMapping = {
        'customCommands': 'Custom Commands',
        'lurkers': 'Lurkers',
        'typos': 'Typo Counts',
        'deaths': 'Deaths Overview',
        'hugs': 'Hug Counts',
        'kisses': 'Kiss Counts',
        'highfives': 'High-Five Counts',
        'custom': 'Custom Counts',
        'userCounts': 'User Counts',
        'rewardCounts': 'Reward Counts',
        'watchTime': 'Watch Time',
        'quotes': 'Quotes',
        'todos': 'To-Do Items'
    };
    
    const buttonText = buttonMapping[type];
    if (buttonText) {
        const activeButton = Array.from(document.querySelectorAll('.buttons .button')).find(
            button => button.textContent.trim() === buttonText
        );
        if (activeButton) {
            activeButton.classList.remove('is-info');
            activeButton.classList.add('is-primary');
        }
    }
    
    switch(type) {
        case 'customCommands':
            additionalColumnVisible = true;
            additionalColumnVisible2 = true;
            data = customCommands;
            title = 'Custom Commands';
            infoColumn = 'Command';
            dataColumn = 'Response';
            additionalColumnName = 'Status';
            additionalColumnName2 = 'Cooldown';
            break;
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
                output += `<tr><td>${item.username}</td><td><span class='has-text-success'>${item.lurkDuration}</span></td></tr>`;
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
                    const commandClass = item.status === 'Enabled' ? 'has-text-success' : 'has-text-danger';
                    output += `<td>!${item.command}</td><td>${item.response}</td><td class="${commandClass}">${item.status}</td><td>${item.cooldown}</td>`;
                } else if (type === 'typos') {
                    output += `<td>${item.username}</td><td><span class='has-text-success'>${item.typo_count}</span></td>`; 
                } else if (type === 'deaths') {
                    output += `<td>${item.game_name}</td><td><span class='has-text-success'>${item.death_count}</span></td>`; 
                } else if (type === 'hugs') {
                    output += `<td>${item.username}</td><td><span class='has-text-success'>${item.hug_count}</span></td>`; 
                } else if (type === 'kisses') {
                    output += `<td>${item.username}</td><td><span class='has-text-success'>${item.kiss_count}</span></td>`; 
                } else if (type === 'highfives') {
                    output += `<td>${item.username}</td><td><span class='has-text-success'>${item.highfive_count}</span></td>`;
                } else if (type === 'custom') {
                    output += `<td>${item.command}</td><td><span class='has-text-success'>${item.count}</span></td>`; 
                } else if (type === 'userCounts') {
                    output += `<td>${item.user}</td><td><span class='has-text-success'>${item.command}</td><td><span class='has-text-success'>${item.count}</span></td>`; 
                } else if (type === 'rewardCounts') {
                    output += `<td>${item.reward_title}</td><td>${item.user}</td><td><span class='has-text-success'>${item.count}</span></td>`;
                } else if (type === 'watchTime') { 
                    output += `<td>${item.username}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td>`;
                } else if (type === 'quotes') {
                    output += `<td>${item.id}</td><td><span class='has-text-success'>${item.quote}</span></td>`;
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
    const days = Math.floor((diff % (1000 * 60 * 60 * 24 * 30)) / (1000 * 60 * 60 * 24));
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
        return "<span class='has-text-danger'>Not Recorded</span>";
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
    return `<span class='has-text-success'>${parts.join(', ')}</span>`;
}

// Function to format date and time
function formatDateTime(dateTime) {
    const date = new Date(dateTime);
    const options = { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: 'numeric' };
    return date.toLocaleDateString(undefined, options);
}

function setActiveButton(button, type) {
    document.querySelectorAll('.buttons .button').forEach(btn => {
        btn.classList.remove('is-primary');
        btn.classList.add('is-info');
    });
    button.classList.remove('is-info');
    button.classList.add('is-primary');
    console.log(`Button clicked: ${button.textContent.trim()}`);
    loadData(type);
}
</script>
</body>
</html>