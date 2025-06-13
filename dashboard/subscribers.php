<?php 
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('subscribers_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
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

// Fetch profile images for all subscribers (batch up to 100 per request)
$userIds = array_column($allSubscribers, 'user_id');
$profileImages = [];
if (!empty($userIds)) {
    $chunks = array_chunk($userIds, 100);
    foreach ($chunks as $chunk) {
        $idsParam = implode('&id=', $chunk);
        $usersUrl = "https://api.twitch.tv/helix/users?id=" . $idsParam;
        $curl = curl_init($usersUrl);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Client-ID: ' . $clientID
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $usersResponse = curl_exec($curl);
        if ($usersResponse !== false) {
            $usersData = json_decode($usersResponse, true);
            if (isset($usersData['data'])) {
                foreach ($usersData['data'] as $user) {
                    $profileImages[$user['id']] = $user['profile_image_url'];
                }
            }
        }
        curl_close($curl);
    }
}

ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-star"></i></span>
                    <?php echo t('subscribers_heading'); ?>
                </span>
            </header>
            <div class="card-content">
                <div class="content">
                    <?php if ($displaySearchBar) : ?>
                        <div class="field mb-4">
                            <div class="control">
                                <input class="input has-background-grey-darker has-text-white" type="text" id="subscriber-search" placeholder="<?php echo t('subscribers_search_placeholder'); ?>" style="border: 1px solid #4a4a4a;">
                            </div>
                        </div>
                    <?php endif; ?>
                    <h3 id="live-data" class="subtitle is-6 has-text-grey mb-4"></h3>
                    <div id="subscribers-list" class="columns is-multiline is-centered">
                        <?php
                        usort($subscribersForCurrentPage, function ($a, $b) {
                            $tierOrder = ['3000', '2000', '1000'];
                            $tierA = $a['tier'];
                            $tierB = $b['tier'];
                            $indexA = array_search($tierA, $tierOrder);
                            $indexB = array_search($tierB, $tierOrder);
                            return $indexA - $indexB;
                        });
                        foreach ($subscribersForCurrentPage as $subscriber) :
                            $subscriberDisplayName = $subscriber['user_name'];
                            $isGift = $subscriber['is_gift'] ?? false;
                            $gifterName = $subscriber['gifter_name'] ?? '';
                            $subscriptionTier = '';
                            $badgeColor = '';
                            $subscriptionPlanId = $subscriber['tier'];
                            if ($subscriptionPlanId == '1000') {
                                $subscriptionTier = '1';
                                $badgeColor = 'background: linear-gradient(90deg,#cd7f32,#b87333); color: #fff;';
                            } elseif ($subscriptionPlanId == '2000') {
                                $subscriptionTier = '2';
                                $badgeColor = 'background: linear-gradient(90deg,#c0c0c0,#e0e0e0); color: #333;';
                            } elseif ($subscriptionPlanId == '3000') {
                                $subscriptionTier = '3';
                                $badgeColor = 'background: linear-gradient(90deg,#ffd700,#ffec8b); color: #333;';
                            } else {
                                $subscriptionTier = t('subscribers_tier_unknown');
                                $badgeColor = 'background: #eee; color: #333;';
                            }
                            $badgeTitle = $isGift && $gifterName ? "title=\"" . str_replace('{gifter}', htmlspecialchars($gifterName), t('subscribers_gifted_by')) . "\"" : "";
                            $profileImg = isset($profileImages[$subscriber['user_id']]) && $profileImages[$subscriber['user_id']]
                                ? "<img src=\"{$profileImages[$subscriber['user_id']]}\" alt=\"" . htmlspecialchars($subscriberDisplayName) . "\" class=\"is-rounded\" style=\"width:64px;height:64px;\">"
                                : "<span class=\"has-background-primary has-text-white is-flex is-justify-content-center is-align-items-center is-rounded\" style=\"width:64px;height:64px;font-size:2rem;font-weight:700;\">" . strtoupper(mb_substr($subscriberDisplayName, 0, 1)) . "</span>";
                            $badgeHtml = "<span class='sub-tier-badge' style='display:block;width:100%;padding:0.4em 0.8em 0.4em 0.8em;margin-bottom:0.3em;border-radius:12px;font-weight:600;font-size:1em;{$badgeColor};text-align:left;' {$badgeTitle}>"
                                . t('subscribers_tier_label') . " " . htmlspecialchars($subscriptionTier) . "</span>";
                            echo "<div class='column is-12-mobile is-6-tablet is-3-desktop subscriber-box'>
                                    <div class='box has-background-grey-darker has-text-white' style='border-radius: 8px;'>
                                        <article class='media is-align-items-center'>
                                            <figure class='media-left'>
                                                <p class='image is-64x64'>
                                                    {$profileImg}
                                                </p>
                                            </figure>
                                            <div class='media-content'>
                                                <div class='content'>
                                                    {$badgeHtml}
                                                    <span class='has-text-weight-semibold has-text-white' style='display:block;margin-top:0.2em;'>" . htmlspecialchars($subscriberDisplayName) . "</span>
                                                </div>
                                            </div>
                                        </article>
                                    </div>
                                  </div>";
                        endforeach;
                        ?>
                    </div>
                    <!-- Pagination -->
                    <?php if ($totalPages > 1) : ?>
                        <nav class="pagination is-centered" role="navigation" aria-label="pagination">
                            <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
                                <?php if ($page === $currentPage) : ?>
                                    <span class="pagination-link is-current has-background-primary has-text-white"><?php echo $page; ?></span>
                                <?php else : ?>
                                    <a class="pagination-link has-background-grey-darker has-text-white" href="?page=<?php echo $page; ?>" style="border: 1px solid #4a4a4a;"><?php echo $page; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
$(document).ready(function() {
    <?php if ($displaySearchBar) : ?>
    $('#subscriber-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.subscriber-box').each(function() {
            var subscriberName = $(this).find('.has-text-weight-semibold').first().text().toLowerCase();
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
<?php
$content = ob_get_clean();
include 'layout.php';
?>