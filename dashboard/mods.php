<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Twitch Mods";

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$stmt = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$stmt->bind_param("s", $access_token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$broadcasterID = $user['twitch_user_id'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

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
    <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <h1 class="title is-4">Your Moderators:</h1>
    <div class="columns is-multiline is-centered">
        <?php foreach ($moderatorsForCurrentPage as $moderator) : 
            $modDisplayName = $moderator['user_name'];
        ?>
        <div class="column is-one-quarter">
            <div class="centered-box"><?php echo $modDisplayName; ?></div>
        </div>
        <?php endforeach; ?>
    </div>  
    <!-- Pagination -->
    <nav class="pagination is-centered" role="navigation" aria-label="pagination">
        <?php if ($totalPages > 1) : ?>
            <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
                <?php if ($page === $currentPage) : ?>
                    <span class="pagination-link is-current"><?php echo $page; ?></span>
                <?php else : ?>
                    <a class="pagination-link" href="?page=<?php echo $page; ?>"><?php echo $page; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        <?php endif; ?>
    </nav>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>