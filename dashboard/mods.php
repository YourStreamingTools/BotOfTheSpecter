<?php 
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
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
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
    // Insert the new moderator access into the database
    $stmt = $pdo->prepare('INSERT INTO moderator_access (moderator_id, broadcaster_id) VALUES (:moderator_id, :broadcaster_id)');
    $stmt->execute([
        ':moderator_id' => $moderator_id,
        ':broadcaster_id' => $broadcaster_id
    ]);
    echo 'success';
    exit();
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
    <div class="table-container">
        <table class="table is-striped is-fullwidth">
            <thead>
                <tr>
                    <th>Moderator Name</th>
                    <th>Specter Moderator Access</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allModerators as $moderator) : $modDisplayName = $moderator['user_name']; ?>
                <tr>
                    <td><?php echo $modDisplayName; ?></td>
                    <td><button class="button is-primary add-access" data-user-id="<?php echo $moderator['user_id']; ?>">Add Access</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('.add-access').on('click', function() {
        var twitchUserId = $(this).data('user-id');
        $.ajax({
            url: 'mods.php',
            type: 'POST',
            data: { moderator_id: twitchUserId },
            success: function(response) {
                alert('Moderator access granted successfully!');
            },
            error: function(xhr, status, error) {
                console.error('Error: ' + error);
                alert('Failed to grant moderator access. Please try again.');
            }
        });
    });
});
</script>
</body>
</html>