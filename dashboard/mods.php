<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('mods_page_title');

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
    if ($response === false) { exit; }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        if ($httpCode === 401) {
            echo "<div style='color:red;font-weight:bold;'>Your Twitch authentication token is invalid or expired. Please <a href='logout.php'>log in again</a> to refresh your session.</div>";
        }
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
    'yourstreamingtools',
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
    'scottybot',
    'ai_licia'
];

$filteredModerators = array_filter($allModerators, function($moderator) use ($botAccounts) {
    return !in_array(strtolower($moderator['user_name']), $botAccounts);
});

// Move BotOfTheSpecter to the top if present
$botOfTheSpecterMod = null;
foreach ($filteredModerators as $key => $mod) {
    if (strtolower($mod['user_name']) === 'botofthespecter') {
        $botOfTheSpecterMod = $mod;
        unset($filteredModerators[$key]);
        break;
    }
}
if ($botOfTheSpecterMod) {
    $filteredModerators = array_merge([$botOfTheSpecterMod], $filteredModerators);
}

// Check if BotOfTheSpecter is already in the list
$botOfTheSpecterExists = false;
foreach ($filteredModerators as $mod) {
    if (strtolower($mod['user_name']) === 'botofthespecter') {
        $botOfTheSpecterExists = true;
        break;
    }
}

// Fetch profile images for all moderators (batch up to 100 per request)
$modUserIds = array_column($filteredModerators, 'user_id');
$modProfileImages = [];
if (!empty($modUserIds)) {
    $chunks = array_chunk($modUserIds, 100);
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
                    $modProfileImages[$user['id']] = $user['profile_image_url'];
                }
            }
        }
        curl_close($curl);
    }
}

// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-user-shield"></i></span>
                    <?php echo t('mods_heading'); ?>
                </span>
            </header>
            <div class="card-content">
                <div class="content">
                    <div class="notification has-background-grey-darker has-text-white mb-4" style="border: 1px solid #4a4a4a;">
                        <div class="columns is-vcentered">
                            <div class="column is-narrow">
                                <span class="icon is-large has-text-primary">
                                    <i class="fas fa-user-shield fa-2x"></i> 
                                </span>
                            </div>
                            <div class="column">
                                <p><span class="has-text-weight-bold"><?php echo t('mods_dashboard_access_title'); ?></span></p>
                                <p><?php echo t('mods_dashboard_access_desc'); ?></p>
                                <hr class="has-background-grey my-2" style="height: 1px;">
                                <p><span class="has-text-weight-bold"><?php echo t('mods_security_warning'); ?></span></p>
                            </div>
                        </div>
                    </div>
                    <div class="notification has-background-grey-dark has-text-white mb-4" style="border: 1px solid #4a4a4a;">
                        <p class="has-text-weight-bold mb-2"><i class="fas fa-info-circle mr-2"></i><?php echo t('mods_how_it_works_title'); ?></p>
                        <div class="content is-small">
                            <p><span class="has-text-weight-semibold"><?php echo t('mods_table_name'); ?>:</span> <?php echo t('mods_column_name_desc'); ?></p>
                            <p><span class="has-text-weight-semibold"><?php echo t('mods_table_registered'); ?>:</span> <?php echo t('mods_column_registration_desc'); ?></p>
                            <p><span class="has-text-weight-semibold"><?php echo t('mods_table_access'); ?>:</span> <?php echo t('mods_column_access_desc'); ?></p>
                            <hr class="has-background-grey my-2" style="height: 1px;">
                            <p class="is-size-7 has-text-grey-light"><span class="has-text-info has-text-weight-semibold"><?php echo t('mods_automatic_access_note'); ?></span></p>
                            <p class="is-size-7 has-text-grey-light"><span class="has-text-info has-text-weight-semibold"><?php echo t('mods_bot_filtering_note'); ?></span></p>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table is-fullwidth has-text-white">
                            <thead>
                                <tr style="background-color: #2b2b2b;">
                                    <th class="has-text-white"><?php echo t('mods_table_name'); ?></th>
                                    <th class="has-text-white"><?php echo t('mods_table_registered'); ?></th>
                                    <th class="has-text-white"><?php echo t('mods_table_access'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($filteredModerators as $moderator) : 
                                    $modDisplayName = $moderator['user_name'];
                                    $modUserId = $moderator['user_id'];
                                    $hasAccess = in_array($modUserId, array_column($moderatorsAccess, 'moderator_id'));
                                    $isRegistered = in_array(strtolower($modDisplayName), $registeredUsers);
                                    if (strtolower($modDisplayName) === 'botofthespecter') {
                                        $hasAccess = true;
                                        $isRegistered = true;
                                    }
                                    $profileImg = isset($modProfileImages[$modUserId]) && $modProfileImages[$modUserId]
                                        ? '<img src="' . htmlspecialchars($modProfileImages[$modUserId]) . '" alt="' . htmlspecialchars($modDisplayName) . '" style="width:32px;height:32px;margin-right:0.5em;border-radius:50%;object-fit:cover;">'
                                        : '<span class="has-background-primary has-text-white is-flex is-justify-content-center is-align-items-center" style="width:32px;height:32px;font-size:1.2rem;font-weight:700;display:inline-flex;margin-right:0.5em;border-radius:50%;">' . strtoupper(mb_substr($modDisplayName, 0, 1)) . '</span>';
                                ?>
                                <tr style="background-color: #363636;">
                                    <td class="has-text-white">
                                        <span style="display:flex;align-items:center;">
                                            <?php echo $profileImg; ?>
                                            <?php echo htmlspecialchars($modDisplayName); ?>
                                        </span>
                                    </td>
                                    <td class="has-text-white">
                                        <?php if ($isRegistered) : ?>
                                            <span class="has-text-success"><?php echo t('yes'); ?></span>
                                        <?php else : ?>
                                            <span class="has-text-danger"><?php echo t('no'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="has-text-white">
                                        <?php if (strtolower($modDisplayName) === 'botofthespecter') : ?>
                                            <button class="button is-success" disabled><?php echo t('mods_always_has_access'); ?></button>
                                        <?php elseif ($hasAccess) : ?>
                                            <button class="button is-danger access-control" data-user-id="<?php echo $modUserId; ?>" data-action="remove"><?php echo t('mods_remove_access'); ?></button>
                                        <?php else : ?>
                                            <button class="button is-primary access-control" data-user-id="<?php echo $modUserId; ?>" data-action="add"><?php echo t('mods_add_access'); ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
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
                alert('<?php echo t('mods_access_update_failed'); ?>');
            }
        });
    });
});
</script>
<?php
// Include the layout template
include 'layout.php';
?>