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
$pageTitle = t('followers_page_title');

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

// Handle AJAX request to load followers
if (isset($_GET['load']) && $_GET['load'] == 'followers') {
  header('Content-Type: application/json'); // Ensure the output is JSON
  // Fetch existing followers from the database, sorted by newest to oldest
  $existingFollowers = [];
  $result = $db->query("SELECT user_id, user_name, followed_at FROM followers_data ORDER BY followed_at DESC");
  if ($result) {
    while ($row = $result->fetch_assoc()) {
      $existingFollowers[] = $row;
    }
    $result->free();
  }
  // Check for updates from Twitch API and update the database accordingly
  $followersURL = "https://api.twitch.tv/helix/channels/followers?broadcaster_id=$broadcasterID&first=100";
  $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
  $apiFollowers = [];
  $apiFollowerDetails = [];
  do {
      $response = fetchFollowers($followersURL, $authToken, $clientID);
      if ($response === false) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch followers"]);
        exit();
      }
      $followerData = json_decode($response, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(["status" => "error", "message" => "Error decoding JSON response"]);
        exit();
      }
      foreach ($followerData["data"] as $follower) {
        $apiFollowers[] = $follower["user_id"];
        $apiFollowerDetails[$follower["user_id"]] = [
          "user_id" => $follower["user_id"],
          "user_name" => $follower["user_name"],
          "followed_at" => $follower["followed_at"]
        ];
        $followedAt = date('Y-m-d H:i:s', strtotime($follower["followed_at"]));
        // Check if the follower exists in the database
        $stmt = $db->prepare("SELECT COUNT(*) FROM followers_data WHERE user_id = ?");
        $stmt->bind_param("s", $follower["user_id"]);
        $stmt->execute();
        $stmt->bind_result($exists);
        $stmt->fetch();
        $stmt->close();
        if (!$exists) {
          // Insert new follower into the database
          $insertStmt = $db->prepare("INSERT INTO followers_data (user_id, user_name, followed_at) VALUES (?, ?, ?)");
          $insertStmt->bind_param("sss", $follower["user_id"], $follower["user_name"], $followedAt);
          $insertStmt->execute();
          $insertStmt->close();
        }
      }
      $cursor = $followerData["pagination"]["cursor"] ?? null;
      if ($cursor) {
        $followersURL = "https://api.twitch.tv/helix/channels/followers?broadcaster_id=$broadcasterID&first=100&after=$cursor";
      }
  } while ($cursor);
  // Delete followers from the database if they are no longer in the Twitch API response
  foreach ($existingFollowers as $existingFollower) {
    if (!in_array($existingFollower['user_id'], $apiFollowers)) {
      $deleteStmt = $db->prepare("DELETE FROM followers_data WHERE user_id = ?");
      $deleteStmt->bind_param("s", $existingFollower['user_id']);
      $deleteStmt->execute();
      $deleteStmt->close();
    }
  }
  // Fetch the updated list of followers from the database
  $updatedFollowers = [];
  $result = $db->query("SELECT user_id, user_name, followed_at FROM followers_data ORDER BY followed_at DESC");
  if ($result) {
    while ($row = $result->fetch_assoc()) {
      $updatedFollowers[] = $row;
    }
    $result->free();
  }
  $userIds = array_column($updatedFollowers, 'user_id');
  $profileImages = [];
  if (!empty($userIds)) {
    // Twitch API allows up to 100 ids per request
    $chunks = array_chunk($userIds, 100);
    foreach ($chunks as $chunk) {
      $idsParam = implode('&id=', $chunk);
      $usersUrl = "https://api.twitch.tv/helix/users?id=" . $idsParam;
      $usersResponse = fetchFollowers($usersUrl, $authToken, $clientID);
      if ($usersResponse !== false) {
        $usersData = json_decode($usersResponse, true);
        if (isset($usersData['data'])) {
          foreach ($usersData['data'] as $user) {
            $profileImages[$user['id']] = $user['profile_image_url'];
          }
        }
      }
    }
  }
  // Attach profile_image_url to each follower
  foreach ($updatedFollowers as &$follower) {
    $follower['profile_image_url'] = $profileImages[$follower['user_id']] ?? null;
  }
  unset($follower);
  // Calculate metrics
  $totalFollowers = count($updatedFollowers);
  $newToday = 0;
  $newThisWeek = 0;
  $newThisMonth = 0;
  $newThisYear = 0;
  $now = new DateTime();
  $today = $now->format('Y-m-d');
  $thisWeekStart = (new DateTime())->modify('-7 days')->format('Y-m-d H:i:s');
  $thisMonthStart = date('Y-m-01 00:00:00'); // Start of current month
  $thisYearStart = date('Y-01-01 00:00:00');
  foreach ($updatedFollowers as $follower) {
    $followedDate = new DateTime($follower['followed_at']);
    if ($followedDate->format('Y-m-d') == $today) $newToday++;
    if ($follower['followed_at'] >= $thisWeekStart) $newThisWeek++;
    if ($follower['followed_at'] >= $thisMonthStart) $newThisMonth++;
    if ($follower['followed_at'] >= $thisYearStart) $newThisYear++;
  }
  // Calculate chart data for follower growth - all time, monthly
  $chartData = [];
  $monthlyData = [];
  // Group all followers by year-month
  foreach ($updatedFollowers as $follower) {
    $year = date('Y', strtotime($follower['followed_at']));
    $month = date('m', strtotime($follower['followed_at']));
    $monthKey = $year . '-' . $month;
    if (!isset($monthlyData[$monthKey])) {
      $monthlyData[$monthKey] = 0;
    }
    $monthlyData[$monthKey]++;
  }
  // Build chart data points - cumulative growth from first follower to most recent
  $cumulative = 0;
  ksort($monthlyData); // Sort by date ascending
  foreach ($monthlyData as $monthKey => $count) {
    $cumulative += $count;
    // Get the last day of the month
    $date = DateTime::createFromFormat('Y-m', $monthKey);
    $lastDay = $date->format('Y-m-t');
    $chartData[] = ['x' => $lastDay, 'y' => $cumulative]; // Cumulative at end of month
  }
  echo json_encode(["status" => "success", "data" => $updatedFollowers, "metrics" => ["total" => $totalFollowers, "today" => $newToday, "week" => $newThisWeek, "month" => $newThisMonth, "year" => $newThisYear], "chartData" => $chartData]);
  exit();
}

// Function to fetch followers with error handling
function fetchFollowers($url, $authToken, $clientID) {
  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $authToken,
    'Client-ID: ' . $clientID
  ]);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($curl);
  if ($response === false) {
    // Handle cURL error
    $errorMessage = 'cURL error: ' . curl_error($curl);
    $errorDetails = 'URL: ' . curl_getinfo($curl, CURLINFO_EFFECTIVE_URL) . ' | HTTP Code: ' . curl_getinfo($curl, CURLINFO_HTTP_CODE);
    error_log($errorMessage . ' | ' . $errorDetails, 3, 'curl_errors.log');
    curl_close($curl);
    return false;
  }
  curl_close($curl);
  return $response;
}

ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header" style="border-bottom: 1px solid #23272f;">
        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
          <span class="icon mr-2"><i class="fas fa-users"></i></span>
          <?php echo t('followers_page_title'); ?>
        </span>
      </header>
      <div class="mb-4">
        <canvas id="followerChart" width="400" height="200"></canvas>
      </div>
      <div class="card-content">
        <div class="content">
          <div class="columns is-multiline mb-4">
            <div class="column is-6-mobile" style="width: 20%;">
              <div class="card has-background-grey-darker has-text-white" style="border-radius: 8px;">
                <div class="card-content has-text-centered">
                  <p class="title is-4" id="total-followers">0</p>
                  <p class="subtitle is-6 has-text-grey-light"><?php echo t('total_followers'); ?></p>
                </div>
              </div>
            </div>
            <div class="column is-6-mobile" style="width: 20%;">
              <div class="card has-background-grey-darker has-text-white" style="border-radius: 8px;">
                <div class="card-content has-text-centered">
                  <p class="title is-4" id="new-today">0</p>
                  <p class="subtitle is-6 has-text-grey-light"><?php echo t('new_followers_today'); ?></p>
                </div>
              </div>
            </div>
            <div class="column is-6-mobile" style="width: 20%;">
              <div class="card has-background-grey-darker has-text-white" style="border-radius: 8px;">
                <div class="card-content has-text-centered">
                  <p class="title is-4" id="new-week">0</p>
                  <p class="subtitle is-6 has-text-grey-light"><?php echo t('new_followers_week'); ?></p>
                </div>
              </div>
            </div>
            <div class="column is-6-mobile" style="width: 20%;">
              <div class="card has-background-grey-darker has-text-white" style="border-radius: 8px;">
                <div class="card-content has-text-centered">
                  <p class="title is-4" id="new-month">0</p>
                  <p class="subtitle is-6 has-text-grey-light"><?php echo t('new_followers_month'); ?></p>
                </div>
              </div>
            </div>
            <div class="column is-6-mobile" style="width: 20%;">
              <div class="card has-background-grey-darker has-text-white" style="border-radius: 8px;">
                <div class="card-content has-text-centered">
                  <p class="title is-4" id="new-year">0</p>
                  <p class="subtitle is-6 has-text-grey-light"><?php echo t('new_followers_year'); ?></p>
                </div>
              </div>
            </div>
          </div>
          <h3 id="live-data" class="subtitle is-6 has-text-grey mb-4"><?php echo t('followers_loading'); ?></h3>
          <div id="followers-list" class="columns is-multiline is-centered">
            <!-- AJAX appended followers -->
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@2.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script>
function getOrdinal(n) {
  var s=["th","st","nd","rd"],
      v=n%100;
  return n+(s[(v-20)%10]||s[v]||s[0]);
}
function formatDate(dateStr) {
  var d = new Date(dateStr);
  var day = d.getDate();
  var month = d.toLocaleString('default', { month: 'long' });
  var year = d.getFullYear();
  return getOrdinal(day) + ' ' + month + ' ' + year;
}
function getInitials(name) {
  // Returns the first letter of the username, uppercase
  if (!name) return "?";
  return name.charAt(0).toUpperCase();
}
$(document).ready(function() {
  let followerChart = null; // To hold the chart instance
  // Initialize empty chart on page load
  function initializeChart() {
    const ctx = document.getElementById('followerChart').getContext('2d');
    followerChart = new Chart(ctx, {
      type: 'line',
      data: {
        datasets: [{
          label: 'Follower Growth',
          data: [],
          borderColor: 'rgb(75, 192, 192)',
          backgroundColor: 'rgba(75, 192, 192, 0.2)',
          tension: 0.1
        }]
      },
      options: {
        responsive: true,
        layout: {
          padding: {
            left: 20,
            right: 20
          }
        },
        scales: {
          x: {
            type: 'time',
            time: {
              unit: 'month'
            }
          },
          y: {
            beginAtZero: true
          }
        }
      }
    });
  }
  // Update chart with data
  function updateChart(chartData) {
    if (followerChart) {
      followerChart.data.datasets[0].data = chartData;
      followerChart.update(); // Use default animation
    }
  }
  // Add a single point to the chart
  function addChartPoint(point) {
    if (followerChart) {
      followerChart.data.datasets[0].data.push(point);
      followerChart.update();
    }
  }
  // Initialize chart immediately
  initializeChart();
  // Automatically fetch followers when the page loads
  function fetchNewFollowers() {
    $('#live-data').text("<?php echo t('followers_loading'); ?>");
    $('#followers-list').empty();
    $.ajax({
      url: window.location.href,
      method: 'GET',
      data: { load: 'followers' },
      dataType: 'json',
      success: function(response) {
        if (response.status === 'success') {
          // Update metrics
          $('#total-followers').text(response.metrics.total);
          $('#new-today').text(response.metrics.today);
          $('#new-week').text(response.metrics.week);
          $('#new-month').text(response.metrics.month);
          $('#new-year').text(response.metrics.year);
          // Clear chart data and start fresh
          if (followerChart) {
            followerChart.data.datasets[0].data = [];
            followerChart.update('none');
          }
          const totalFollowers = response.data.length;
          let loadedFollowers = 0;
          if (totalFollowers === 0) {
            $('#live-data').text("<?php echo t('followers_no_followers_found'); ?>");
            return;
          }
          // Update loading text with total count
          $('#live-data').text(`Loading 0/${totalFollowers} followers`);
          // Process followers in original order (newest first for display)
          response.data.forEach(function(follower, index) {
            setTimeout(function() {
              // Add chart point progressively (chartData is already sorted chronologically)
              if (index < response.chartData.length) {
                addChartPoint(response.chartData[index]);
              }
              // Use profile image if available, otherwise fallback to initials
              var profileImg = follower.profile_image_url 
                ? `<img src="${follower.profile_image_url}" alt="${follower.user_name}" class="is-rounded" style="width:64px;height:64px;">`
                : `<span class="has-background-primary has-text-white is-flex is-justify-content-center is-align-items-center is-rounded" style="width:64px;height:64px;font-size:2rem;font-weight:700;">${getInitials(follower.user_name)}</span>`;
              var followerHTML = `
                <div class="column is-12-mobile is-6-tablet is-3-desktop follower-box">
                  <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px;">
                    <article class="media is-align-items-center">
                      <figure class="media-left">
                        <p class="image is-64x64">
                          ${profileImg}
                        </p>
                      </figure>
                      <div class="media-content">
                        <div class="content">
                          <p>
                            <span class="has-text-weight-semibold has-text-white">${follower.user_name}</span><br>
                            <span class="is-size-7 has-text-grey-light">${formatDate(follower.followed_at)}</span><br>
                            <span class="is-size-7 has-text-grey-light">${new Date(follower.followed_at).toLocaleTimeString()}</span>
                          </p>
                        </div>
                      </div>
                    </article>
                  </div>
                </div>
              `;
              var $followerElement = $(followerHTML);
              $('#followers-list').append($followerElement);
              // Update progress counter
              loadedFollowers++;
              $('#live-data').text(`Loading ${loadedFollowers}/${totalFollowers} followers`);
              // Hide loading text when all followers are loaded
              if (loadedFollowers === totalFollowers) {
                setTimeout(function() {
                  $('#live-data').fadeOut(500);
                }, 500);
              }
              setTimeout(function() {
                $followerElement.addClass('visible');
              }, 10);
            }, index * 50);
          });
        } else {
          // Show backend error message if available
          let msg = "<?php echo t('followers_failed'); ?>";
          if (response.message) {
            msg += " (" + response.message + ")";
          }
          $('#live-data').text(msg);
        }
      },
      error: function(xhr, status, error) {
        // Try to show backend error message if available
        let msg = "<?php echo t('followers_failed'); ?>";
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg += " (" + xhr.responseJSON.message + ")";
        } else if (xhr.responseText) {
          msg += " (" + xhr.responseText + ")";
        }
        $('#live-data').text(msg);
        console.error('AJAX Error: ' + error, xhr);
      }
    });
  }
  // Trigger the AJAX request on page load
  fetchNewFollowers();
  // Check for new followers every 5 minutes
  setInterval(fetchNewFollowers, 300000);
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>