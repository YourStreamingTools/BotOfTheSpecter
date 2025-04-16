<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Twitch Data - Subscribers";

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

// API endpoint to fetch subscribers
$subscribersURL = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=$broadcasterID";
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

$allSubscribers = [];
do {
    // Set up cURL request with headers
    $curl = curl_init($subscribersURL);
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
    // Process and append subscriber information to the array
    $subscribersData = json_decode($response, true);
    $allSubscribers = array_merge($allSubscribers, $subscribersData['data']);
    // Check if there are more pages of subscribers
    $cursor = $subscribersData['pagination']['cursor'] ?? null;
    $subscribersURL = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=$broadcasterID&after=$cursor";
} while ($cursor);

// Number of subscribers per page
$subscribersPerPage = 50;

// Calculate the total number of pages
$totalPages = ceil(count($allSubscribers) / $subscribersPerPage);

// Current page (default to 1 if not specified)
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, intval($_GET['page']))) : 1;

// Calculate the start and end index for the current page
$startIndex = ($currentPage - 1) * $subscribersPerPage;
$endIndex = $startIndex + $subscribersPerPage;

// Get subscribers for the current page
$subscribersForCurrentPage = array_slice($allSubscribers, $startIndex, $subscribersPerPage);
$displaySearchBar = count($allSubscribers) > $subscribersPerPage;
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
    <?php if ($displaySearchBar) : ?>
        <div class="field">
            <div class="control">
                <input class="input" type="text" id="subscriber-search" placeholder="Search for Subscribers...">
            </div>
        </div>
    <?php endif; ?>
    <h1 class="title is-4">Your Subscribers:</h1>
    <div class="columns is-multiline">
        <?php
        // Define a custom sorting function to sort by subscription tier in descending order
        usort($subscribersForCurrentPage, function ($a, $b) {
            // Subscription tiers in descending order (Tier 3, Tier 2, Tier 1)
            $tierOrder = ['3000', '2000', '1000'];
            // Get the tier values for $a and $b
            $tierA = $a['tier'];
            $tierB = $b['tier'];
            // Compare the positions of the tiers in the order defined
            $indexA = array_search($tierA, $tierOrder);
            $indexB = array_search($tierB, $tierOrder);
            // Compare the positions and return the comparison result
            return $indexA - $indexB;
        });
        // Loop through the sorted array
        foreach ($subscribersForCurrentPage as $subscriber) :
            $subscriberDisplayName = $subscriber['user_name'];
            $isGift = $subscriber['is_gift'] ?? false;
            $gifterName = $subscriber['gifter_name'] ?? '';
            $subscriptionTier = '';
            // Determine the subscription tier based on the subscription plan ID
            $subscriptionPlanId = $subscriber['tier'];
            if ($subscriptionPlanId == '1000') { $subscriptionTier = '1';
            } elseif ($subscriptionPlanId == '2000') { $subscriptionTier = '2';
            } elseif ($subscriptionPlanId == '3000') { $subscriptionTier = '3';
            } else { $subscriptionTier = '<font color="red">Unknown</font>'; }
            // Check if $username is the same as $subscriberDisplayName
            if ($twitchDisplayName == $subscriberDisplayName) {
                echo "<div class='column is-one-fifth'>
                        <div class='box has-equal-height'>
                            <span class='has-text-weight-bold'>$subscriberDisplayName</span>
                            <span>Subscription Tier: $subscriptionTier</span>
                            <p class='has-text-info'>This is your subscription!</p>
                        </div>
                      </div>";
            } else {
                // Check if it's a gift subscription
                if ($isGift) {
                    echo "<div class='column is-one-fifth'>
                            <div class='box has-equal-height'>
                                <span class='has-text-weight-bold'>$subscriberDisplayName</span>
                                <span>Subscription Tier: $subscriptionTier</span><br>
                                <span>Gift Sub from $gifterName</span>
                            </div>
                          </div>";
                // else show everything else as not gift subscription
                } else {
                    echo "<div class='column is-one-fifth'>
                            <div class='box has-equal-height'>
                                <span class='has-text-weight-bold'>$subscriberDisplayName</span>
                                <span>Subscription Tier: $subscriptionTier</span>
                            </div>
                          </div>";
                }
            }
        endforeach;
        ?>
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
<script>
$(document).ready(function() {
    // Ensure all boxes have the same height
    var maxHeight = 0;
    $('.box').each(function() {
        if ($(this).height() > maxHeight) {
            maxHeight = $(this).height();
        }
    });
    $('.box').height(maxHeight);
    
    <?php if ($displaySearchBar) : ?>
    $('#subscriber-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.column').each(function() {
            var subscriberName = $(this).find('.box span').first().text().toLowerCase();
            if (subscriberName.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    <?php endif; ?>
});
</script>
</body>
</html>