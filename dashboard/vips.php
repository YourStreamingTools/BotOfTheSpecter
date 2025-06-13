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
$pageTitle = t('vips_page_title');

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

// API endpoint to fetch VIPs of the channel
$vipsURL = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID";
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
$allVIPs = [];
$VIPUserStatus="";
do {
  // Set up cURL request with headers
  $curl = curl_init($vipsURL);
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
  // Process and append VIP information to the array
  $vipsData = json_decode($response, true);
  $allVIPs = array_merge($allVIPs, $vipsData['data']);
  // Check if there are more pages of VIPs
  $cursor = $vipsData['pagination']['cursor'] ?? null;
  $vipsURL = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID&after=$cursor";
} while ($cursor);
$vipUserIds = array_column($allVIPs, 'user_id');
$vipProfileImages = [];
if (!empty($vipUserIds)) {
  $chunks = array_chunk($vipUserIds, 100);
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
    curl_close($curl);
    if ($usersResponse !== false) {
      $usersData = json_decode($usersResponse, true);
      if (isset($usersData['data'])) {
        foreach ($usersData['data'] as $user) {
          $vipProfileImages[$user['id']] = $user['profile_image_url'];
        }
      }
    }
  }
}
// Attach profile_image_url to each VIP
foreach ($allVIPs as &$vip) { $vip['profile_image_url'] = $vipProfileImages[$vip['user_id']] ?? null; }
unset($vip);

// Check if the form has been submitted for adding or removing VIPs from the list
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Extract the username and action from the form submission
  $VIPusername = trim($_POST['vip-username']);
  $action = $_POST['action'];
  // Fetch the user ID using the external API
  $userID = file_get_contents("https://decapi.me/twitch/id/$VIPusername");
  if ($userID) {
    // Set up the Twitch API endpoint and headers
    $addVIP = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID&user_id=$userID";
    $headers = [
      "Client-ID: $clientID",
      'Authorization: Bearer ' . $authToken,
      "Content-Type: application/json"
    ];
    // Initialize cURL session
    $ch = curl_init();
    // Set cURL options for adding or removing VIP
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($action === 'add') {
      curl_setopt($ch, CURLOPT_URL, $addVIP);
      curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($action === 'remove') {
      curl_setopt($ch, CURLOPT_URL, $addVIP);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    }
    // Execute the API request
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Handle API response
    if ($httpcode == 204) {
      $VIPUserStatus = "Operation successful: User '$VIPusername' has been $action" . "ed as a VIP.";
    } else {
      $VIPUserStatus = "Operation failed: Unable to $action user '$VIPusername' as a VIP. Response code: $httpcode";
    }
  } else {
    $VIPUserStatus = "Could not retrieve user ID for username: $VIPusername";
  }
}

ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <!-- VIPs List -->
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header is-flex is-align-items-center is-justify-content-space-between" style="border-bottom: 1px solid #23272f; padding: 1rem 1.5rem;">
        <div class="card-header-title is-size-4 has-text-white" style="font-weight:700; padding: 0;">
          <span class="icon mr-2"><i class="fas fa-star"></i></span>
          <?php echo t('vips_list_title'); ?>
        </div>
        <div class="is-flex is-align-items-center">
          <button class="button is-primary mr-3" id="manage-vip-btn">
            <span class="icon"><i class="fas fa-user-cog"></i></span>
            <span>Manage VIPs</span>
          </button>
          <div class="field" style="margin-bottom: 0;">
            <div class="control has-icons-left">
              <input class="input" type="text" id="vip-search" placeholder="<?php echo t('vips_search_placeholder'); ?>" style="width: 300px; background-color: #363636; border-color: #4a4a4a; color: white;">
              <span class="icon is-small is-left has-text-grey-light">
                <i class="fas fa-search"></i>
              </span>
            </div>
          </div>
        </div>
      </header>
      <div class="card-content">
        <div class="content">
          <?php if (!empty($VIPUserStatus)): ?>
            <div class="notification is-info is-light has-text-dark mb-4">
              <span class="icon"><i class="fas fa-info-circle"></i></span>
              <?php echo $VIPUserStatus; ?>
            </div>
          <?php endif; ?>
          <?php if (empty($allVIPs)): ?>
            <div class="has-text-centered py-6">
              <span class="icon is-large has-text-grey-light mb-3">
                <i class="fas fa-star fa-3x"></i>
              </span>
              <p class="has-text-grey-light is-size-5">No VIPs found</p>
            </div>
          <?php else: ?>
            <div class="columns is-multiline is-centered">
              <?php foreach ($allVIPs as $vip) : 
                  $vipDisplayName = $vip['user_name'];
                  $profileImg = !empty($vip['profile_image_url'])
                      ? '<img src="' . htmlspecialchars($vip['profile_image_url']) . '" alt="' . htmlspecialchars($vipDisplayName) . '" class="is-rounded" style="width:64px;height:64px;">'
                      : '<span class="has-background-primary has-text-white is-flex is-justify-content-center is-align-items-center is-rounded" style="width:64px;height:64px;font-size:2rem;font-weight:700;">' . strtoupper(substr($vipDisplayName, 0, 1)) . '</span>';
              ?>
              <div class="column is-12-mobile is-6-tablet is-3-desktop follower-box">
                <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px;">
                  <article class="media is-align-items-center">
                    <figure class="media-left">
                      <p class="image is-64x64">
                        <?php echo $profileImg; ?>
                      </p>
                    </figure>
                    <div class="media-content">
                      <div class="content">
                        <p>
                          <span class="has-text-weight-semibold has-text-white"><?php echo $vipDisplayName; ?></span><br>
                          <span class="tag is-primary is-small mt-1">
                            <span class="icon is-small"><i class="fas fa-star"></i></span>
                            <span>VIP</span>
                          </span>
                        </p>
                      </div>
                    </div>
                  </article>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- VIP Management Modal -->
<div class="modal" id="vip-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">
        <span class="icon mr-2"><i class="fas fa-user-plus"></i></span>
        <?php echo t('vips_add_remove_title'); ?>
      </p>
      <button class="delete" aria-label="close" id="close-modal"></button>
    </header>
    <section class="modal-card-body has-background-grey-darker has-text-white">
      <form method="POST">
        <div class="field">
          <label class="label has-text-white" for="vip-username"><?php echo t('vips_username_label'); ?></label>
          <div class="control">
            <input class="input" type="text" id="vip-username" name="vip-username" required placeholder="Enter username">
          </div>
        </div>
        <div class="field is-grouped">
          <div class="control">
            <button class="button is-success" type="submit" name="action" value="add">
              <span class="icon"><i class="fas fa-plus"></i></span>
              <span><?php echo t('vips_add_btn'); ?></span>
            </button>
          </div>
          <div class="control">
            <button class="button is-danger" type="submit" name="action" value="remove">
              <span class="icon"><i class="fas fa-minus"></i></span>
              <span><?php echo t('vips_remove_btn'); ?></span>
            </button>
          </div>
        </div>
      </form>
    </section>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
$(document).ready(function() {
    $('#vip-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.follower-box').each(function() {
            var vipName = $(this).find('.has-text-weight-semibold').text().toLowerCase();
            if (vipName.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    // Modal functionality
    $('#manage-vip-btn').on('click', function() {
        $('#vip-modal').addClass('is-active');
    });

    $('#close-modal, .modal-background').on('click', function() {
        $('#vip-modal').removeClass('is-active');
    });
    // Close modal on escape key
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape') {
            $('#vip-modal').removeClass('is-active');
        }
    });
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>