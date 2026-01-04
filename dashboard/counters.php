<?php
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check for session-based status messages
if (isset($_SESSION['status'])) {
    $status = $_SESSION['status'];
    $notification_status = $_SESSION['notification_status'] ?? 'is-info';
    unset($_SESSION['status'], $_SESSION['notification_status']);
}

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page title
$pageTitle = t('navbar_counters');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
require_once '/var/www/config/database.php';
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

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

// Fetch usernames from the user_typos table
$usernames = [];
if ($result = $db->query("SELECT username FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $usernames[] = $row['username'];
    }
    $result->free();
}

// Fetch commands from the custom_counts table
$commands = [];
if ($result = $db->query("SELECT command FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commands[] = $row['command'];
    }
    $result->free();
}

// Fetch games from the deaths table
$games = [];
if ($result = $db->query("SELECT game_name FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $games[] = $row['game_name'];
    }
    $result->free();
}

// Fetch total deaths
$totalDeaths = 0;
$stmt = $db->prepare("SELECT death_count FROM total_deaths LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $totalDeaths = $row['death_count'];
}
$stmt->close();

// Fetch hugs, kisses, highfives users
$hugUsers = [];
if ($result = $db->query("SELECT username FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugUsers[] = $row['username'];
    }
    $result->free();
}

$kissUsers = [];
if ($result = $db->query("SELECT username FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissUsers[] = $row['username'];
    }
    $result->free();
}

$highfiveUsers = [];
if ($result = $db->query("SELECT username FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveUsers[] = $row['username'];
    }
    $result->free();
}

// Fetch user counts
$userCountCommands = [];
$userCountUsersByCommand = [];
$userCountArr = [];
if ($result = $db->query("SELECT command, user, count FROM user_counts")) {
    while ($row = $result->fetch_assoc()) {
        $cmd = $row['command'];
        $user = $row['user'];
        $count = $row['count'];
        if (!in_array($cmd, $userCountCommands)) {
            $userCountCommands[] = $cmd;
        }
        if (!isset($userCountUsersByCommand[$cmd])) {
            $userCountUsersByCommand[$cmd] = [];
        }
        $userCountUsersByCommand[$cmd][] = $user;
        $userCountArr[] = ['command' => $cmd, 'user' => $user, 'count' => $count];
    }
    $result->free();
}

// Fetch reward counts and reward titles
$rewardCountsData = [];
$rewardIds = [];
$rewardUsersById = [];
$rewardTitles = [];
if ($result = $db->query("SELECT rc.reward_id, rc.user, rc.count, cpr.reward_title FROM reward_counts rc LEFT JOIN channel_point_rewards cpr ON rc.reward_id = cpr.reward_id")) {
    while ($row = $result->fetch_assoc()) {
        $rid = $row['reward_id'];
        $user = $row['user'];
        $count = $row['count'];
        $title = $row['reward_title'] ?? $rid;
        $rewardCountsData[] = ['reward_id' => $rid, 'user' => $user, 'count' => $count, 'reward_title' => $title];
        if (!in_array($rid, $rewardIds)) {
            $rewardIds[] = $rid;
        }
        if (!isset($rewardUsersById[$rid])) {
            $rewardUsersById[$rid] = [];
        }
        $rewardUsersById[$rid][] = $user;
        $rewardTitles[$rid] = $title;
    }
    $result->free();
}

// Fetch reward streaks data
$rewardStreaksData = [];
$rewardStreaksIds = [];
if ($result = $db->query("SELECT rs.reward_id, rs.current_user, rs.streak, cpr.reward_title FROM reward_streaks rs LEFT JOIN channel_point_rewards cpr ON rs.reward_id COLLATE utf8mb4_unicode_ci = cpr.reward_id COLLATE utf8mb4_unicode_ci")) {
    while ($row = $result->fetch_assoc()) {
        $rewardStreaksData[] = $row;
        if (!in_array($row['reward_id'], $rewardStreaksIds)) {
            $rewardStreaksIds[] = $row['reward_id'];
        }
        if (!isset($rewardTitles[$row['reward_id']])) {
            $rewardTitles[$row['reward_id']] = $row['reward_title'] ?? $row['reward_id'];
        }
    }
    $result->free();
}

// Fetch reward usage data
$rewardUsageData = [];
$rewardUsageTitles = [];
if ($result = $db->query("SELECT reward_title, usage_count FROM channel_point_rewards WHERE usage_count > 0")) {
    while ($row = $result->fetch_assoc()) {
        $rewardUsageData[] = $row;
        $rewardUsageTitles[] = $row['reward_title'];
    }
    $result->free();
}

// Fetch initial data for all counters
$typoData = [];
$commandData = [];
$deathData = [];
$hugData = [];
$kissData = [];
$highfiveData = [];

if ($result = $db->query("SELECT username, typo_count FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $typoData[] = $row;
    }
    $result->free();
}

if ($result = $db->query("SELECT command, count FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commandData[] = $row;
    }
    $result->free();
}

if ($result = $db->query("SELECT game_name, death_count FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $deathData[] = $row;
    }
    $result->free();
}

if ($result = $db->query("SELECT username, hug_count FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugData[] = $row;
    }
    $result->free();
}

if ($result = $db->query("SELECT username, kiss_count FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissData[] = $row;
    }
    $result->free();
}

if ($result = $db->query("SELECT username, highfive_count FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveData[] = $row;
    }
    $result->free();
}

// Fetch quotes from the quotes table
$quotesData = [];
if ($result = $db->query("SELECT id, quote, added FROM quotes ORDER BY added DESC")) {
    while ($row = $result->fetch_assoc()) {
        $quotesData[] = $row;
    }
    $result->free();
}

// Prepare JS objects for reward counts and titles
$rewardCountsJs = [];
foreach ($rewardCountsData as $row) {
    $key = $row['reward_id'] . '|' . $row['user'];
    $rewardCountsJs[$key] = $row['count'];
}
$rewardTitlesJs = $rewardTitles;

// Prepare JS objects for reward streaks
$rewardStreaksJs = [];
foreach ($rewardStreaksData as $row) {
    $rewardStreaksJs[$row['reward_id']] = ['user' => $row['current_user'], 'streak' => $row['streak']];
}

// Prepare JS objects for reward usage
$rewardUsageJs = array_column($rewardUsageData, 'usage_count', 'reward_title');

// Handling form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'update': 
            $formUsername = $_POST['typo-username'] ?? '';
            $typoCount = $_POST['typo_count'] ?? '';
            $formCommand = $_POST['command'] ?? '';
            $commandCount = $_POST['command_count'] ?? '';
            $formGame = $_POST['death-game'] ?? '';
            $deathCount = $_POST['death_count'] ?? '';
            $formHugUser = $_POST['hug-username'] ?? '';
            $hugCount = $_POST['hug_count'] ?? '';
            $formKissUser = $_POST['kiss-username'] ?? '';
            $kissCount = $_POST['kiss_count'] ?? '';
            $formHighfiveUser = $_POST['highfive-username'] ?? '';
            $highfiveCount = $_POST['highfive_count'] ?? '';
            $formUserCountCommand = $_POST['usercount-command'] ?? '';
            $formUserCountUser = $_POST['usercount-user'] ?? '';
            $userCountValue = $_POST['usercount_count'] ?? '';
            
            // Update typo count
            if ($formUsername && is_numeric($typoCount)) {
                $stmt = $db->prepare("UPDATE user_typos SET typo_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $typoCount, $formUsername);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Typo count updated successfully for user {$formUsername}.";
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = "Error: " . $stmt->error;
                        $_SESSION['notification_status'] = "is-danger";
                    }
                    $stmt->close();
                }
            }
            
            // Update command count
            if ($formCommand && is_numeric($commandCount)) {
                $stmt = $db->prepare("UPDATE custom_counts SET count = ? WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $commandCount, $formCommand);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Count updated successfully for the command {$formCommand}.";
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = "Error: " . $stmt->error;
                        $_SESSION['notification_status'] = "is-danger";
                    }
                    $stmt->close();
                }
            }
            
            // Update death count
            if ($formGame && is_numeric($deathCount)) {
                $oldDeathCount = 0;
                $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formGame);
                    $stmt->execute();
                    $stmt->bind_result($oldDeathCount);
                    $stmt->fetch();
                    $stmt->close();
                }
                $diff = $deathCount - $oldDeathCount;
                if ($diff !== 0) {
                    $stmt = $db->prepare("UPDATE game_deaths SET death_count = ? WHERE game_name = ?");
                    if ($stmt) {
                        $stmt->bind_param('is', $deathCount, $formGame);
                        if ($stmt->execute()) {
                            $stmt2 = $db->prepare("UPDATE total_deaths SET death_count = death_count + ? LIMIT 1");
                            if ($stmt2) {
                                $stmt2->bind_param('i', $diff);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                            $_SESSION['status'] = "Death count updated successfully for game {$formGame}.";
                            $_SESSION['notification_status'] = "is-success";
                        }
                        $stmt->close();
                    }
                }
            }
            
            // Update hug, kiss, highfive counts
            if ($formHugUser && is_numeric($hugCount)) {
                $stmt = $db->prepare("UPDATE hug_counts SET hug_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $hugCount, $formHugUser);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Hug count updated successfully for user {$formHugUser}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($formKissUser && is_numeric($kissCount)) {
                $stmt = $db->prepare("UPDATE kiss_counts SET kiss_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $kissCount, $formKissUser);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Kiss count updated successfully for user {$formKissUser}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($formHighfiveUser && is_numeric($highfiveCount)) {
                $stmt = $db->prepare("UPDATE highfive_counts SET highfive_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $highfiveCount, $formHighfiveUser);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "High-five count updated successfully for user {$formHighfiveUser}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            // Update user counts
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE user_counts SET count = ? WHERE command = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "User count updated successfully.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            header('Location: counters.php');
            exit();
            break;
            
        case 'remove':
            $typoUsernameRemove = $_POST['typo-username-remove'] ?? '';
            $commandRemove = $_POST['command-remove'] ?? '';
            $deathGameRemove = $_POST['death-game-remove'] ?? '';
            $hugUsernameRemove = $_POST['hug-username-remove'] ?? '';
            $kissUsernameRemove = $_POST['kiss-username-remove'] ?? '';
            $highfiveUsernameRemove = $_POST['highfive-username-remove'] ?? '';
            $usercountCommandRemove = $_POST['usercount-command-remove'] ?? '';
            $usercountUserRemove = $_POST['usercount-user-remove'] ?? '';
            
            if ($typoUsernameRemove) {
                $stmt = $db->prepare("DELETE FROM user_typos WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $typoUsernameRemove);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Typo record removed successfully for {$typoUsernameRemove}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($commandRemove) {
                $stmt = $db->prepare("DELETE FROM custom_counts WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $commandRemove);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Command counter removed successfully for {$commandRemove}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($deathGameRemove) {
                $oldDeathCount = 0;
                $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $deathGameRemove);
                    $stmt->execute();
                    $stmt->bind_result($oldDeathCount);
                    $stmt->fetch();
                    $stmt->close();
                }
                $stmt = $db->prepare("DELETE FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $deathGameRemove);
                    if ($stmt->execute()) {
                        $negDiff = -$oldDeathCount;
                        $stmt2 = $db->prepare("UPDATE total_deaths SET death_count = death_count + ? LIMIT 1");
                        if ($stmt2) {
                            $stmt2->bind_param('i', $negDiff);
                            $stmt2->execute();
                            $stmt2->close();
                        }
                        $_SESSION['status'] = "Death counter removed successfully for {$deathGameRemove}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($hugUsernameRemove) {
                $stmt = $db->prepare("DELETE FROM hug_counts WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $hugUsernameRemove);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Hug record removed successfully for {$hugUsernameRemove}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($kissUsernameRemove) {
                $stmt = $db->prepare("DELETE FROM kiss_counts WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $kissUsernameRemove);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Kiss record removed successfully for {$kissUsernameRemove}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($highfiveUsernameRemove) {
                $stmt = $db->prepare("DELETE FROM highfive_counts WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $highfiveUsernameRemove);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "High-five record removed successfully for {$highfiveUsernameRemove}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            if ($usercountCommandRemove && $usercountUserRemove) {
                $stmt = $db->prepare("DELETE FROM user_counts WHERE command = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('ss', $usercountCommandRemove, $usercountUserRemove);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "User count removed successfully.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            
            header('Location: counters.php');
            exit();
            break;
            
        case 'add_death':
            $deathGameAdd = $_POST['death-game-add'] ?? '';
            $deathCountAdd = $_POST['death_count_add'] ?? 0;
            if ($deathGameAdd && is_numeric($deathCountAdd)) {
                $stmt = $db->prepare("INSERT INTO game_deaths (game_name, death_count) VALUES (?, ?) ON DUPLICATE KEY UPDATE death_count = ?");
                if ($stmt) {
                    $stmt->bind_param('sii', $deathGameAdd, $deathCountAdd, $deathCountAdd);
                    if ($stmt->execute()) {
                        $stmt2 = $db->prepare("UPDATE total_deaths SET death_count = death_count + ? LIMIT 1");
                        if ($stmt2) {
                            $stmt2->bind_param('i', $deathCountAdd);
                            $stmt2->execute();
                            $stmt2->close();
                        }
                        $_SESSION['status'] = "Game death counter added successfully for {$deathGameAdd}.";
                        $_SESSION['notification_status'] = "is-success";
                    }
                    $stmt->close();
                }
            }
            header('Location: counters.php');
            exit();
            break;
        case 'add_quote':
            $quoteText = $_POST['quote_text'] ?? '';
            if ($quoteText) {
                $stmt = $db->prepare("INSERT INTO quotes (quote, added) VALUES (?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('s', $quoteText);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Quote added successfully.";
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = "Error: " . $stmt->error;
                        $_SESSION['notification_status'] = "is-danger";
                    }
                    $stmt->close();
                }
            }
            header('Location: counters.php');
            exit();
            break;
        case 'update_quote':
            $quoteId = $_POST['quote_id'] ?? '';
            $quoteText = $_POST['quote_text'] ?? '';
            if ($quoteId && $quoteText) {
                $stmt = $db->prepare("UPDATE quotes SET quote = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $quoteText, $quoteId);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Quote updated successfully.";
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = "Error: " . $stmt->error;
                        $_SESSION['notification_status'] = "is-danger";
                    }
                    $stmt->close();
                }
            }
            header('Location: counters.php');
            exit();
            break;
        case 'remove_quote':
            $quoteId = $_POST['quote_id'] ?? '';
            if ($quoteId) {
                $stmt = $db->prepare("DELETE FROM quotes WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $quoteId);
                    if ($stmt->execute()) {
                        $_SESSION['status'] = "Quote removed successfully.";
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = "Error: " . $stmt->error;
                        $_SESSION['notification_status'] = "is-danger";
                    }
                    $stmt->close();
                }
            }
            header('Location: counters.php');
            exit();
            break;
        case 'update_reward_streak':
        case 'remove_reward_streak':
        case 'update_reward_usage':
        case 'remove_reward_usage':
            // Handle reward streaks and usage (to be implemented)
            $_SESSION['status'] = "Action {$action} processed.";
            $_SESSION['notification_status'] = "is-info";
            header('Location: counters.php');
            exit();
            break;
    }
}

try {
  // Calculate lurk durations for each user
  foreach ($lurkers as $key => $lurker) {
    $startTime = new DateTime($lurker['start_time']);
    $currentTime = new DateTime();
    $interval = $currentTime->diff($startTime);
    // Calculate total duration in seconds for sorting
    $totalDuration = ($interval->y * 365 * 24 * 3600) + 
                    ($interval->m * 30 * 24 * 3600) + 
                    ($interval->d * 24 * 3600) + 
                    ($interval->h * 3600) + 
                    ($interval->i * 60) +
                    $interval->s;
    $lurkers[$key]['total_duration'] = $totalDuration;
    $timeStringParts = [];
    if ($interval->y > 0) {
      $timeStringParts[] = "{$interval->y} " . t('time_years');
    }
    if ($interval->m > 0) {
      $timeStringParts[] = "{$interval->m} " . t('time_months');
    }
    if ($interval->d > 0) {
      $timeStringParts[] = "{$interval->d} " . t('time_days');
    }
    if ($interval->h > 0) {
      $timeStringParts[] = "{$interval->h} " . t('time_hours');
    }
    if ($interval->i > 0) {
      $timeStringParts[] = "{$interval->i} " . t('time_minutes');
    }
    if ($interval->s > 0 || empty($timeStringParts)) {
      $timeStringParts[] = "{$interval->s} " . t('time_seconds');
    }
    $lurkers[$key]['lurk_duration'] = implode(', ', $timeStringParts);
  }
  // Sort the lurkers array by total_duration (longest to shortest)
  usort($lurkers, function ($a, $b) {
    return $b['total_duration'] - $a['total_duration'];
  });
} catch (Exception $e) {
  echo 'Error: ' . $e->getMessage();
}

// Prepare the Twitch API request for user data
$userIds = array_column($lurkers, 'user_id');
$userIdParams = implode('&id=', $userIds);
$twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . $userIdParams;
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
$headers = [
  "Client-ID: $clientID",
  "Authorization: Bearer $authToken",
];

// Execute the Twitch API request
$ch = curl_init($twitchApiUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

// Decode the JSON response
$userData = json_decode($response, true);

// Check if data exists and is not null
if (isset($userData['data']) && is_array($userData['data'])) {
  // Map user IDs to usernames
  $usernames = [];
  foreach ($userData['data'] as $user) {
    $usernames[$user['id']] = $user['display_name'];
  }
  // Map the Twitch usernames to the lurkers based on their user_id
  foreach ($lurkers as $key => $lurker) {
    if (isset($usernames[$lurker['user_id']])) {
      $lurkers[$key]['username'] = $usernames[$lurker['user_id']];
    } else {
      $lurkers[$key]['username'] = 'Unknown'; // Fallback if username not found
    }
  }
} else {
  $usernames = [];
}

// Get the default data type to display - either from cookie or default to 'lurkers'
$defaultDataType = 'lurkers';
if ($cookieConsent && isset($_COOKIE['preferred_data_type'])) {
  $defaultDataType = $_COOKIE['preferred_data_type'];
}

// Get the default mode - either from cookie or default to 'view'
$defaultMode = 'view';
if ($cookieConsent && isset($_COOKIE['preferred_mode'])) {
  $defaultMode = $_COOKIE['preferred_mode'];
}

// Get the default edit tab - either from cookie or default to 'typos'
$defaultEditTab = 'typos';
if ($cookieConsent && isset($_COOKIE['preferred_edit_tab'])) {
  $defaultEditTab = $_COOKIE['preferred_edit_tab'];
}

// Start output buffering for main content
ob_start();
?>
<?php if (isset($status) && !empty($status)): ?>
    <div class="notification <?php echo $notification_status; ?> is-light"><?php echo $status; ?></div>
<?php endif; ?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header" style="border-bottom: 1px solid #23272f;">
        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
          <span class="icon mr-2"><i class="fas fa-stopwatch"></i></span>
          <?php echo t('navbar_counters'); ?> & Management
        </span>
      </header>
      <div class="card-content">
        <!-- Tab Navigation -->
        <div class="tabs is-centered is-toggle is-toggle-rounded mb-4">
          <ul>
            <li class="is-active" data-tab="view">
              <a onclick="switchMode('view')">
                <span class="icon is-small"><i class="fas fa-eye"></i></span>
                <span>View Data</span>
              </a>
            </li>
            <li data-tab="edit">
              <a onclick="switchMode('edit')">
                <span class="icon is-small"><i class="fas fa-edit"></i></span>
                <span>Edit Data</span>
              </a>
            </li>
          </ul>
        </div>
        <!-- View Mode -->
        <div id="view-mode" class="mode-content">
          <div class="buttons is-centered mb-4 is-flex-wrap-wrap">
            <button class="button is-info" data-type="lurkers" onclick="loadData('lurkers')"><?php echo t('counters_lurkers'); ?></button>
            <button class="button is-info" data-type="typos" onclick="loadData('typos')"><?php echo t('edit_counters_edit_user_typos'); ?></button>
            <button class="button is-info" data-type="deaths" onclick="loadData('deaths')"><?php echo t('counters_deaths'); ?></button>
            <button class="button is-info" data-type="hugs" onclick="loadData('hugs')"><?php echo t('counters_hugs'); ?></button>
            <button class="button is-info" data-type="kisses" onclick="loadData('kisses')"><?php echo t('counters_kisses'); ?></button>
            <button class="button is-info" data-type="highfives" onclick="loadData('highfives')"><?php echo t('counters_highfives'); ?></button>
            <button class="button is-info" data-type="customCounts" onclick="loadData('customCounts')"><?php echo t('counters_custom_counts'); ?></button>
            <button class="button is-info" data-type="userCounts" onclick="loadData('userCounts')"><?php echo t('counters_user_counts'); ?></button>
            <button class="button is-info" data-type="rewardCounts" onclick="loadData('rewardCounts')"><?php echo t('counters_reward_counts'); ?></button>
            <button class="button is-info" data-type="rewardStreaks" onclick="loadData('rewardStreaks')"><?php echo t('counters_reward_streaks'); ?></button>
            <button class="button is-info" data-type="rewardUsage" onclick="loadData('rewardUsage')"><?php echo t('counters_reward_usage'); ?></button>
            <button class="button is-info" data-type="watchTime" onclick="loadData('watchTime')"><?php echo t('counters_watch_time'); ?></button>
            <button class="button is-info" data-type="quotes" onclick="loadData('quotes')"><?php echo t('counters_quotes'); ?></button>
          </div>
          <div class="content">
            <div class="table-container">
              <h3 id="table-title" class="title is-4 has-text-white has-text-centered mb-3"></h3>
              <table class="table is-fullwidth" style="table-layout: fixed; width: 100%;">
                <thead>
                  <tr>
                    <th id="info-column-data" class="has-text-white" style="width: 33%;"></th>
                    <th id="data-column-info" class="has-text-white" style="width: 33%;"></th>
                    <th id="count-column" class="has-text-white" style="width: 33%; display: none;"></th>
                  </tr>
                </thead>
                <tbody id="table-body">
                  <!-- Content will be dynamically injected here -->
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <!-- Edit Mode -->
        <div id="edit-mode" class="mode-content" style="display: none;">
          <div class="buttons is-centered mb-4 is-flex-wrap-wrap">
            <button class="button is-info" data-edit-type="typos" onclick="showEditTab('typos')"><?php echo t('edit_counters_edit_user_typos'); ?></button>
            <button class="button is-info" data-edit-type="customCounts" onclick="showEditTab('customCounts')"><?php echo t('counters_custom_counts'); ?></button>
            <button class="button is-info" data-edit-type="deaths" onclick="showEditTab('deaths')"><?php echo t('counters_deaths'); ?></button>
            <button class="button is-info" data-edit-type="hugs" onclick="showEditTab('hugs')"><?php echo t('counters_hugs'); ?></button>
            <button class="button is-info" data-edit-type="kisses" onclick="showEditTab('kisses')"><?php echo t('counters_kisses'); ?></button>
            <button class="button is-info" data-edit-type="highfives" onclick="showEditTab('highfives')"><?php echo t('counters_highfives'); ?></button>
            <button class="button is-info" data-edit-type="userCounts" onclick="showEditTab('userCounts')"><?php echo t('counters_user_counts'); ?></button>
            <button class="button is-info" data-edit-type="quotes" onclick="showEditTab('quotes')"><?php echo t('counters_quotes'); ?></button>
          </div>
          <!-- Typos Edit Tab -->
          <div id="edit-tab-typos" class="edit-tab-content">
            <div class="columns is-desktop is-multiline">
              <div class="column is-6">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_edit_user_typos'); ?></h4>
                  <form action="" method="post">
                    <input type="hidden" name="action" value="update">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_username_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="typo-username" name="typo-username" required onchange="updateCurrentCount('typo', this.value); enableButton('typo-username','typo-edit-btn');">
                            <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                            <?php foreach ($usernames as $name): ?>
                              <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_new_typo_count'); ?></label>
                      <div class="control">
                        <input class="input" type="number" id="typo_count" name="typo_count" min="0" required>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-primary" id="typo-edit-btn" disabled><?php echo t('edit_counters_update_typo_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <div class="column is-6">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_remove_user_typo'); ?></h4>
                  <form action="" method="post" id="typo-remove-form" data-type="typo">
                    <input type="hidden" name="action" value="remove">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_username_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="typo-username-remove" name="typo-username-remove" required onchange="enableButton('typo-username-remove','typo-remove-btn');">
                            <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                            <?php foreach ($usernames as $name): ?>
                              <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-danger" id="typo-remove-btn" disabled><?php echo t('edit_counters_remove_typo_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
          <!-- Additional edit tabs for other counter types would go here similar to edit_counters.php -->
          <!-- Deaths, Hugs, Kisses, High-fives, User Counts, Quotes, etc. -->
          <div id="edit-tab-customCounts" class="edit-tab-content" style="display:none;">
            <p class="has-text-centered has-text-white">Custom Counts Edit Forms (to be implemented)</p>
          </div>
          <div id="edit-tab-deaths" class="edit-tab-content" style="display:none;">
            <div class="notification is-info has-text-centered mb-4">
              <strong><?php echo t('edit_counters_total_deaths'); ?>:</strong>
              <span id="edit-total-deaths" class="has-text-weight-bold has-text-danger"><?php echo (int)$totalDeaths; ?></span>
            </div>
            <p class="has-text-centered has-text-white">Deaths Edit Forms (to be implemented)</p>
          </div>
          <div id="edit-tab-hugs" class="edit-tab-content" style="display:none;">
            <p class="has-text-centered has-text-white">Hugs Edit Forms (to be implemented)</p>
          </div>
          <div id="edit-tab-kisses" class="edit-tab-content" style="display:none;">
            <p class="has-text-centered has-text-white">Kisses Edit Forms (to be implemented)</p>
          </div>
          <div id="edit-tab-highfives" class="edit-tab-content" style="display:none;">
            <p class="has-text-centered has-text-white">High-fives Edit Forms (to be implemented)</p>
          </div>
          <div id="edit-tab-userCounts" class="edit-tab-content" style="display:none;">
            <div class="columns is-desktop is-multiline">
              <div class="column is-6">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_edit_user_counts'); ?></h4>
                  <form action="" method="post">
                    <input type="hidden" name="action" value="update">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_command_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="usercount-command" name="usercount-command" required onchange="updateUserCountUsers(this.value); enableButton('usercount-command','usercount-edit-btn');">
                            <option value=""><?php echo t('edit_counters_select_command'); ?></option>
                            <?php foreach ($userCountCommands as $cmd): ?>
                              <option value="<?php echo htmlspecialchars($cmd); ?>"><?php echo htmlspecialchars($cmd); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_username_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="usercount-user" name="usercount-user" required onchange="updateUserCountValue(); enableButton('usercount-user','usercount-edit-btn');">
                            <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_new_count'); ?></label>
                      <div class="control">
                        <input class="input" type="number" id="usercount_count" name="usercount_count" min="0" required>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-primary" id="usercount-edit-btn" disabled><?php echo t('edit_counters_update_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <div class="column is-6">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_remove_user_count'); ?></h4>
                  <form action="" method="post" id="usercount-remove-form">
                    <input type="hidden" name="action" value="remove">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_command_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="usercount-command-remove" name="usercount-command-remove" required onchange="updateUserCountUsersRemove(this.value); enableButton('usercount-command-remove','usercount-remove-btn');">
                            <option value=""><?php echo t('edit_counters_select_command'); ?></option>
                            <?php foreach ($userCountCommands as $cmd): ?>
                              <option value="<?php echo htmlspecialchars($cmd); ?>"><?php echo htmlspecialchars($cmd); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_username_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="usercount-user-remove" name="usercount-user-remove" required onchange="enableButton('usercount-user-remove','usercount-remove-btn');">
                            <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-danger" id="usercount-remove-btn" disabled><?php echo t('edit_counters_remove_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
          <div id="edit-tab-quotes" class="edit-tab-content" style="display:none;">
            <div class="columns is-desktop is-multiline">
              <!-- Add New Quote -->
              <div class="column is-12">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_add_quote'); ?></h4>
                  <form action="" method="post">
                    <input type="hidden" name="action" value="add_quote">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_quote_text'); ?></label>
                      <div class="control">
                        <textarea class="textarea" name="quote_text" rows="3" required placeholder="<?php echo t('edit_counters_quote_placeholder'); ?>"></textarea>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-success"><?php echo t('edit_counters_add_quote_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <!-- Edit Existing Quote -->
              <div class="column is-6">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_edit_quote'); ?></h4>
                  <form action="" method="post">
                    <input type="hidden" name="action" value="update_quote">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_select_quote'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="quote-id" name="quote_id" required onchange="updateQuoteText(this.value); enableButton('quote-id','quote-edit-btn');">
                            <option value=""><?php echo t('edit_counters_select_quote'); ?></option>
                            <?php foreach ($quotesData as $quote): ?>
                              <option value="<?php echo htmlspecialchars($quote['id']); ?>">
                                #<?php echo htmlspecialchars($quote['id']); ?> - <?php echo htmlspecialchars(substr($quote['quote'], 0, 50)); ?><?php echo strlen($quote['quote']) > 50 ? '...' : ''; ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_quote_text'); ?></label>
                      <div class="control">
                        <textarea class="textarea" id="quote_text_edit" name="quote_text" rows="3" required></textarea>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-primary" id="quote-edit-btn" disabled><?php echo t('edit_counters_update_quote_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <!-- Remove Quote -->
              <div class="column is-6">
                <div class="box has-background-dark">
                  <h4 class="title is-5 has-text-white"><?php echo t('edit_counters_remove_quote'); ?></h4>
                  <form action="" method="post" id="quote-remove-form">
                    <input type="hidden" name="action" value="remove_quote">
                    <div class="field">
                      <label class="label has-text-white"><?php echo t('edit_counters_select_quote'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="quote-id-remove" name="quote_id" required onchange="enableButton('quote-id-remove','quote-remove-btn');">
                            <option value=""><?php echo t('edit_counters_select_quote'); ?></option>
                            <?php foreach ($quotesData as $quote): ?>
                              <option value="<?php echo htmlspecialchars($quote['id']); ?>">
                                #<?php echo htmlspecialchars($quote['id']); ?> - <?php echo htmlspecialchars(substr($quote['quote'], 0, 50)); ?><?php echo strlen($quote['quote']) > 50 ? '...' : ''; ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button type="submit" class="button is-danger" id="quote-remove-btn" disabled><?php echo t('edit_counters_remove_quote_btn'); ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
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
document.addEventListener('DOMContentLoaded', function() {
  // Set initial active button and load data
  const defaultType = '<?php echo $defaultDataType; ?>';
  const defaultMode = '<?php echo $defaultMode; ?>';
  const defaultEditTab = '<?php echo $defaultEditTab; ?>';
  // Restore last mode
  if (defaultMode === 'edit') {
    switchMode('edit', defaultEditTab);
  } else {
    // Highlight the default button using data-type attribute
    document.querySelectorAll('.buttons .button').forEach(button => {
      if (button.getAttribute('data-type') === defaultType) {
        button.classList.remove('is-info');
        button.classList.add('is-primary');
      } else {
        button.classList.remove('is-primary');
        button.classList.add('is-info');
      }
    });
    loadData(defaultType);
  }
  // Wire up remove form confirmations
  wireRemoveForm('typo-remove-form', 'typo-username-remove', 'typo');
  wireRemoveForm('usercount-remove-form', 'usercount-user-remove', 'user count');
  wireRemoveForm('quote-remove-form', 'quote-id-remove', 'quote');
});

function formatWatchTime(seconds) {
  if (seconds == 0) {
    return "<span class='has-text-danger'><?php echo t('counters_watch_time_not_recorded'); ?></span>";
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

function loadData(type) {
  let data;
  let title;
  let dataColumn;
  let infoColumn;
  let countColumnVisible = false;
  let additionalColumnName;
  let output = '';

  // Store the user's preference in a cookie if consent is given
  if (<?php echo $cookieConsent ? 'true' : 'false'; ?>) {
    setCookie('preferred_data_type', type, 30); // Store for 30 days
  }

  switch(type) {
    case 'lurkers':
      data = <?php echo json_encode($lurkers); ?>;
      title = <?php echo json_encode(t('counters_lurkers')); ?>;
      dataColumn = <?php echo json_encode(t('counters_time_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'typos':
      data = <?php echo json_encode($typos); ?>;
      title = <?php echo json_encode(t('edit_counters_edit_user_typos')); ?>;
      dataColumn = <?php echo json_encode(t('edit_counters_new_typo_count')); ?>;
      infoColumn = <?php echo json_encode(t('edit_counters_username_label')); ?>;
      break;
    case 'deaths':
      data = <?php echo json_encode($gameDeaths); ?>;
      title = <?php echo json_encode(t('counters_deaths')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_game_column')); ?>;
      break;
    case 'hugs':
      data = <?php echo json_encode($hugCounts); ?>;
      title = <?php echo json_encode(t('counters_hugs')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'kisses':
      data = <?php echo json_encode($kissCounts); ?>;
      title = <?php echo json_encode(t('counters_kisses')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'highfives':
      data = <?php echo json_encode($highfiveCounts); ?>;
      title = <?php echo json_encode(t('counters_highfives')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'customCounts':
      data = <?php echo json_encode($customCounts); ?>;
      title = <?php echo json_encode(t('counters_custom_counts')); ?>;
      dataColumn = <?php echo json_encode(t('counters_used_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_command_column')); ?>;
      break;
    case 'userCounts':
      data = <?php echo json_encode($userCounts); ?>;
      countColumnVisible = true;
      title = <?php echo json_encode(t('counters_user_counts')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_command_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_count_column')); ?>;
      break;
    case 'rewardCounts':
      data = <?php echo json_encode($rewardCountsData); ?>;
      countColumnVisible = true;
      title = <?php echo json_encode(t('counters_reward_counts')); ?>;
      infoColumn = <?php echo json_encode(t('counters_reward_name_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_count_column')); ?>;
      break;
    case 'rewardStreaks':
      data = <?php echo json_encode($rewardStreaksData); ?>;
      countColumnVisible = true;
      title = <?php echo json_encode(t('counters_reward_streaks')); ?>;
      infoColumn = <?php echo json_encode(t('counters_reward_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_streak_column')); ?>;
      break;
    case 'rewardUsage':
      data = <?php echo json_encode($rewardUsageData); ?>;
      title = <?php echo json_encode(t('counters_reward_usage')); ?>;
      dataColumn = <?php echo json_encode(t('counters_usage_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_reward_name_column')); ?>;
      break;
    case 'watchTime':
      data = <?php echo json_encode($watchTimeData); ?>;
      title = <?php echo json_encode(t('counters_watch_time')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_online_watch_time_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_offline_watch_time_column')); ?>;
      countColumnVisible = true;
      data.sort((a, b) => b.total_watch_time_live - a.total_watch_time_live || b.total_watch_time_offline - a.total_watch_time_offline);
      break;
    case 'quotes':
      data = <?php echo json_encode($quotesData); ?>;
      title = <?php echo json_encode(t('counters_quotes')); ?>;
      infoColumn = <?php echo json_encode(t('counters_id_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_what_was_said_column')); ?>;
      break;
  }

  // Update active button state using data-type attribute
  document.querySelectorAll('.buttons .button').forEach(button => {
    if (button.getAttribute('data-type') === type) {
      button.classList.remove('is-info');
      button.classList.add('is-primary');
    } else {
      button.classList.remove('is-primary');
      button.classList.add('is-info');
    }
  });

  document.getElementById('data-column-info').innerText = dataColumn;
  document.getElementById('info-column-data').innerText = infoColumn;
  if (countColumnVisible) {
    document.getElementById('count-column').style.display = ''; // Ensure it's table-cell or empty for default
    document.getElementById('count-column').innerText = additionalColumnName;
  } else {
    document.getElementById('count-column').style.display = 'none';
  }
  data.forEach(item => {
    output += `<tr class="has-text-white">`; // Add has-text-white to table rows
    if (type === 'lurkers') {
      output += `<td>${item.username}</td><td><span class='has-text-success'>${item.lurk_duration}</span></td>`;
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
    } else if (type === 'customCounts') {
      output += `<td>${item.command}</td><td><span class='has-text-success'>${item.count}</span></td>`;
    } else if (type === 'userCounts') {
      output += `<td>${item.user}</td><td><span class='has-text-success'>${item.command}</span></td><td><span class='has-text-success'>${item.count}</span></td>`;
    } else if (type === 'rewardCounts') {
      output += `<td>${item.reward_title}</td><td>${item.user}</td><td><span class='has-text-success'>${item.count}</span></td>`;
    } else if (type === 'rewardStreaks') {
      output += `<td>${item.reward_title}</td><td>${item.current_user}</td><td><span class='has-text-success'>${item.streak}</span></td>`;
    } else if (type === 'rewardUsage') {
      output += `<td>${item.reward_title}</td><td><span class='has-text-success'>${item.usage_count}</span></td>`;
    } else if (type === 'watchTime') { 
      output += `<td>${item.username}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td>`;
    } else if (type === 'quotes') {
      output += `<td>${item.id}</td><td><span class='has-text-success'>${item.quote}</span></td>`;
    }
    // Ensure three cells are added if countColumn is visible and the type doesn't already add three
    if (countColumnVisible) {
        if (type !== 'userCounts' && type !== 'rewardCounts' && type !== 'watchTime') {
             output += `<td></td>`; 
        }
    }
    output += `</tr>`;
  });
  document.getElementById('table-title').innerText = title;
  document.getElementById('table-body').innerHTML = output;
}

// Function to set a cookie
function setCookie(name, value, days) {
  const d = new Date();
  d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
  const expires = "expires=" + d.toUTCString();
  document.cookie = name + "=" + value + ";" + expires + ";path=/";
}

// Mode switching
function switchMode(mode, editTab) {
  const viewMode = document.getElementById('view-mode');
  const editMode = document.getElementById('edit-mode');
  const tabs = document.querySelectorAll('.tabs li');
  // Store mode preference in cookie if consent is given
  if (<?php echo $cookieConsent ? 'true' : 'false'; ?>) {
    setCookie('preferred_mode', mode, 30);
  }
  if (mode === 'view') {
    viewMode.style.display = 'block';
    editMode.style.display = 'none';
    tabs[0].classList.add('is-active');
    tabs[1].classList.remove('is-active');
  } else {
    viewMode.style.display = 'none';
    editMode.style.display = 'block';
    tabs[0].classList.remove('is-active');
    tabs[1].classList.add('is-active');
    // Use provided editTab or default to 'typos'
    showEditTab(editTab || 'typos');
  }
}

// Edit tab switching
function showEditTab(type) {
  document.querySelectorAll('.edit-tab-content').forEach(tab => {
    tab.style.display = 'none';
  });
  
  const selectedTab = document.getElementById('edit-tab-' + type);
  if (selectedTab) {
    selectedTab.style.display = 'block';
  }
  
  document.querySelectorAll('#edit-mode .buttons .button').forEach(button => {
    if (button.getAttribute('data-edit-type') === type) {
      button.classList.remove('is-info');
      button.classList.add('is-primary');
    } else {
      button.classList.remove('is-primary');
      button.classList.add('is-info');
    }
  });
  
  if (<?php echo $cookieConsent ? 'true' : 'false'; ?>) {
    setCookie('preferred_edit_tab', type, 30);
  }
}

// Helper functions for edit forms
const typoCounts = <?php echo json_encode(array_column($typoData, 'typo_count', 'username')); ?>;
const commandCounts = <?php echo json_encode(array_column($commandData, 'count', 'command')); ?>;
const deathCounts = <?php echo json_encode(array_column($deathData, 'death_count', 'game_name')); ?>;
const hugCounts = <?php echo json_encode(array_column($hugData, 'hug_count', 'username')); ?>;
const kissCounts = <?php echo json_encode(array_column($kissData, 'kiss_count', 'username')); ?>;
const highfiveCounts = <?php echo json_encode(array_column($highfiveData, 'highfive_count', 'username')); ?>;
const userCountUsersByCommand = <?php echo json_encode($userCountUsersByCommand); ?>;
const userCountData = <?php echo json_encode($userCountArr); ?>;
const quotesData = <?php echo json_encode($quotesData); ?>;

function updateCurrentCount(type, value) {
  let count = 0;
  switch(type) {
    case 'typo':
      count = typoCounts[value] || 0;
      document.getElementById('typo_count').value = count;
      break;
    case 'command':
      count = commandCounts[value] || 0;
      document.getElementById('command_count').value = count;
      break;
    case 'death':
      count = deathCounts[value] || 0;
      document.getElementById('death_count').value = count;
      break;
    case 'hug':
      count = hugCounts[value] || 0;
      document.getElementById('hug_count').value = count;
      break;
    case 'kiss':
      count = kissCounts[value] || 0;
      document.getElementById('kiss_count').value = count;
      break;
    case 'highfive':
      count = highfiveCounts[value] || 0;
      document.getElementById('highfive_count').value = count;
      break;
  }
}

function enableButton(selectId, buttonId) {
  const select = document.getElementById(selectId);
  const button = document.getElementById(buttonId);
  if (select && button) {
    button.disabled = !select.value;
  }
}

function wireRemoveForm(formId, selectId, type) {
  const form = document.getElementById(formId);
  if (!form) return;
  
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    const select = document.getElementById(selectId);
    const value = select ? select.value : '';
    
    Swal.fire({
      title: 'Are you sure?',
      text: `Do you want to remove this ${type} record?`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#3085d6',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, remove it!'
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
}

// User count functions
function updateUserCountUsers(command) {
  const userSelect = document.getElementById('usercount-user');
  userSelect.innerHTML = '<option value="">Select User</option>';
  if (command && userCountUsersByCommand[command]) {
    userCountUsersByCommand[command].forEach(user => {
      const option = document.createElement('option');
      option.value = user;
      option.textContent = user;
      userSelect.appendChild(option);
    });
  }
  // Reset count and disable button
  document.getElementById('usercount_count').value = '';
  const btn = document.getElementById('usercount-edit-btn');
  if (btn) btn.disabled = true;
}

function updateUserCountUsersRemove(command) {
  const userSelect = document.getElementById('usercount-user-remove');
  userSelect.innerHTML = '<option value="">Select User</option>';
  if (command && userCountUsersByCommand[command]) {
    userCountUsersByCommand[command].forEach(user => {
      const option = document.createElement('option');
      option.value = user;
      option.textContent = user;
      userSelect.appendChild(option);
    });
  }
  // Disable button
  const btn = document.getElementById('usercount-remove-btn');
  if (btn) btn.disabled = true;
}

function updateUserCountValue() {
  const command = document.getElementById('usercount-command').value;
  const user = document.getElementById('usercount-user').value;
  if (command && user) {
    const entry = userCountData.find(item => item.command === command && item.user === user);
    if (entry) {
      document.getElementById('usercount_count').value = entry.count;
    }
  }
}

// Quote functions
function updateQuoteText(quoteId) {
  if (quoteId) {
    const quote = quotesData.find(q => q.id == quoteId);
    if (quote) {
      document.getElementById('quote_text_edit').value = quote.quote;
    }
  } else {
    document.getElementById('quote_text_edit').value = '';
  }
}
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();

// Use layout.php to render the page
include 'layout.php';
?>