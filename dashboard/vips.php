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
<!-- VIPs List -->
<div class="sp-card mb-5">
  <header class="sp-card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <div class="sp-card-title">
      <span class="icon mr-2"><i class="fas fa-star"></i></span>
      <?php echo t('vips_list_title'); ?>
    </div>
    <div style="display:flex;align-items:center;gap:0.75rem;">
      <button class="sp-btn sp-btn-primary" id="manage-vip-btn">
        <span class="icon"><i class="fas fa-user-cog"></i></span>
        <span>Manage VIPs</span>
      </button>
      <div style="position:relative;">
        <input class="sp-input" type="text" id="vip-search" placeholder="<?php echo t('vips_search_placeholder'); ?>" style="width:300px;padding-left:2.25rem;">
        <span style="position:absolute;left:0.7rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><i class="fas fa-search"></i></span>
      </div>
    </div>
  </header>
  <div class="sp-card-body">
          <?php if (!empty($VIPUserStatus)): ?>
            <div class="sp-alert sp-alert-info mb-4">
              <span class="icon"><i class="fas fa-info-circle"></i></span>
              <?php echo $VIPUserStatus; ?>
            </div>
          <?php endif; ?>
          <?php if (empty($allVIPs)): ?>
            <div style="text-align:center;padding:3rem 0;">
              <span class="icon is-large sp-text-muted" style="margin-bottom:0.75rem;">
                <i class="fas fa-star fa-3x"></i>
              </span>
              <p class="sp-text-muted" style="font-size:1.1rem;">No VIPs found</p>
            </div>
          <?php else: ?>
            <div class="followers-grid">
              <?php foreach ($allVIPs as $vip) : 
                  $vipDisplayName = $vip['user_name'];
                  $profileImg = !empty($vip['profile_image_url'])
                      ? '<img src="' . htmlspecialchars($vip['profile_image_url']) . '" alt="' . htmlspecialchars($vipDisplayName) . '" class="follower-avatar-img">'
                      : '<span class="follower-avatar-initials">' . strtoupper(substr($vipDisplayName, 0, 1)) . '</span>';
              ?>
              <div class="follower-card-col follower-box">
                <div class="sp-card">
                  <div class="follower-card-media sp-card-body">
                    <div class="follower-card-avatar">
                      <?php echo $profileImg; ?>
                    </div>
                    <div class="follower-card-content">
                      <span class="vip-name"><?php echo $vipDisplayName; ?></span>
                      <div style="margin-top:0.35rem;">
                        <span class="sp-badge sp-badge-accent">
                          <span class="icon is-small"><i class="fas fa-star"></i></span>
                          <span>VIP</span>
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
  </div>
</div>

<!-- VIP Management Modal -->
<div class="sp-modal-backdrop" id="vip-modal">
  <div class="sp-modal">
    <header class="sp-modal-head">
      <p class="sp-modal-title">
        <span class="icon mr-2"><i class="fas fa-user-plus"></i></span>
        <?php echo t('vips_add_remove_title'); ?>
      </p>
      <button class="sp-modal-close" aria-label="close" id="close-modal">&times;</button>
    </header>
    <section class="sp-modal-body">
      <form method="POST">
        <div class="sp-form-group">
          <label class="sp-label" for="vip-username"><?php echo t('vips_username_label'); ?></label>
          <input class="sp-input" type="text" id="vip-username" name="vip-username" required placeholder="Enter username">
        </div>
        <div style="display:flex;gap:0.75rem;margin-top:1rem;">
          <button class="sp-btn sp-btn-success" type="submit" name="action" value="add">
            <span class="icon"><i class="fas fa-plus"></i></span>
            <span><?php echo t('vips_add_btn'); ?></span>
          </button>
          <button class="sp-btn sp-btn-danger" type="submit" name="action" value="remove">
            <span class="icon"><i class="fas fa-minus"></i></span>
            <span><?php echo t('vips_remove_btn'); ?></span>
          </button>
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
    // Fade in all VIP cards
    $('.follower-box').each(function(index) {
        var $el = $(this);
        setTimeout(function() {
            $el.addClass('visible');
        }, index * 40);
    });
    $('#vip-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.follower-box').each(function() {
            var vipName = $(this).find('.vip-name').text().toLowerCase();
            if (vipName.includes(searchTerm)) {
                $(this).css('display', '').addClass('visible');
            } else {
                $(this).css('display', 'none');
            }
        });
    });
    // Modal functionality
    $('#manage-vip-btn').on('click', function() {
        $('#vip-modal').addClass('is-active');
    });
    $('#close-modal').on('click', function() {
        $('#vip-modal').removeClass('is-active');
    });
    $('#vip-modal').on('click', function(e) {
        if ($(e.target).is('#vip-modal')) {
            $('#vip-modal').removeClass('is-active');
        }
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