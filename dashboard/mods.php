<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Twitch Data - Mods";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// API endpoint to fetch moderators
$moderatorsURL = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=$broadcasterID";
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

$allModerators = [];
do {
    // Set up cURL request with headers
    $curl = curl_init($moderatorsURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $authToken,
        'Client-ID: ' . $clientID
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    // Execute cURL request
    $response = curl_exec($curl);
    if ($response === false) {
        // Handle cURL error
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        // Handle non-successful HTTP response
        $HTTPError = 'HTTP error: ' . $httpCode;
        exit;
    }
    curl_close($curl);
    // Process and append moderator information to the array
    $moderatorsData = json_decode($response, true);
    $allModerators = array_merge($allModerators, $moderatorsData['data']);
    // Check if there are more pages of moderators
    $cursor = $moderatorsData['pagination']['cursor'] ?? null;
    $moderatorsURL = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=$broadcasterID&after=$cursor";
} while ($cursor);

// Number of moderators per page
$moderatorsPerPage = 50;

// Calculate the total number of pages
$totalPages = ceil(count($allModerators) / $moderatorsPerPage);

// Current page (default to 1 if not specified)
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, intval($_GET['page']))) : 1;

// Calculate the start and end index for the current page
$startIndex = ($currentPage - 1) * $moderatorsPerPage;
$endIndex = $startIndex + $moderatorsPerPage;

// Get moderators for the current page
$moderatorsForCurrentPage = array_slice($allModerators, $startIndex, $moderatorsPerPage);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $moderator_id = $_POST['moderator_id'];
    $broadcaster_id = $_SESSION['twitchUserId'];
    $action = $_POST['action'];
    if ($action === 'add') {
        // Insert the new moderator access into the database
        $stmt = $conn->prepare('INSERT INTO moderator_access (moderator_id, broadcaster_id) VALUES (?, ?)');
        $stmt->bind_param('ss', $moderator_id, $broadcaster_id);
        $stmt->execute();
    } elseif ($action === 'remove') {
        // Remove the moderator access from the database
        $stmt = $conn->prepare('DELETE FROM moderator_access WHERE moderator_id = ? AND broadcaster_id = ?');
        $stmt->bind_param('ss', $moderator_id, $broadcaster_id);
        $stmt->execute();
    }
    exit();
}

// Fetch all moderators and their access status
$stmt = $conn->prepare('SELECT * FROM moderator_access WHERE broadcaster_id = ?');
$stmt->bind_param('s', $_SESSION['twitchUserId']);
$stmt->execute();
$moderatorsAccess = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all registered users from the users table
$registeredUsers = [];
$userStmt = $conn->prepare('SELECT twitch_display_name FROM users');
$userStmt->execute();
$result = $userStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $registeredUsers[] = strtolower($row['twitch_display_name']);
}

// Filter out common bot accounts
$botAccounts = [
    'streamelements',
    'lumiastream',
    'kofistreambot',
    'fourthwallhq',
    'nightbot',
    'moobot',
    'streamlabs',
    'commanderroot',
    'botisimo',
    'fossabot',
    'wizebot',
    'deepbot',
    'streamcaptainbot',
    'moderator',
    'raidshield',
    'ankhbot',
    'phantombot',
    'streamlooter',
    'revlobot',
    'scottybot'
];

$filteredModerators = array_filter($allModerators, function($moderator) use ($botAccounts) {
    return !in_array(strtolower($moderator['user_name']), $botAccounts);
});

// Check if BotOfTheSpecter is already in the list
$botOfTheSpecterExists = false;
foreach ($filteredModerators as $mod) {
    if (strtolower($mod['user_name']) === 'botofthespecter') {
        $botOfTheSpecterExists = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <!-- Header -->
        <?php include('header.php'); ?>
        <!-- /Header -->
    </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <h1 class="title is-4">Your Moderators:</h1>
    <div class="notification is-info">
        <div class="columns is-vcentered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-user-shield fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                <p><span class="has-text-weight-bold">Moderator Dashboard Access: Coming Soon!</span></p>
                <p>We're working on a feature to let your Twitch moderators access your Specter dashboard. This will allow them to manage Specter settings without using chat commands.</p>
                <hr class="has-background-white-ter my-2" style="height: 1px;">
                <p><span class="has-text-weight-bold">Note:</span> For clarity, common known bot accounts have been removed from this list, including: StreamElements, LumiaStream, KofiStreamBot, FourthwallHQ, NightBot, MooBot, Streamlabs, and others.</p>
            </div>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-striped is-fullwidth">
            <thead>
                <tr>
                    <th>Moderator Name</th>
                    <th>Specter Dashboard Registration</th>
                    <th>Specter Moderator Access</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($filteredModerators as $moderator) : 
                    $modDisplayName = $moderator['user_name'];
                    $modUserId = $moderator['user_id'];
                    $hasAccess = in_array($modUserId, array_column($moderatorsAccess, 'moderator_id'));
                    $isRegistered = in_array(strtolower($modDisplayName), $registeredUsers);
                    // BotOfTheSpecter always has access
                    if (strtolower($modDisplayName) === 'botofthespecter') {
                        $hasAccess = true;
                        $isRegistered = true;
                    }
                ?>
                <tr>
                    <td><?php echo $modDisplayName; ?></td>
                    <td>
                        <?php if ($isRegistered) : ?>
                            <span class="has-text-success">True</span>
                        <?php else : ?>
                            <span class="has-text-danger">False</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (strtolower($modDisplayName) === 'botofthespecter') : ?>
                            <button class="button is-success" disabled>Always Has Access</button>
                        <?php elseif ($hasAccess) : ?>
                            <button class="button is-danger access-control" data-user-id="<?php echo $modUserId; ?>" data-action="remove">Remove Access</button>
                        <?php else : ?>
                            <button class="button is-primary access-control" data-user-id="<?php echo $modUserId; ?>" data-action="add">Add Access</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('.access-control').on('click', function() {
        var twitchUserId = $(this).data('user-id');
        var action = $(this).data('action');
        $.ajax({
            url: 'mods.php',
            type: 'POST',
            data: { moderator_id: twitchUserId, action: action },
            success: function(response) { location.reload(); },
            error: function(xhr, status, error) {
                console.error('Error: ' + error);
                alert('Failed to update moderator access. Please try again.');
            }
        });
    });
});
</script>
</body>
</html>