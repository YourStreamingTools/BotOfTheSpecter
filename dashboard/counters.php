<?php
require_once '/var/www/lib/session_bootstrap.php';
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

// Bounce to login if no session (handles both never-logged-in AND
// session_bootstrap-just-destroyed-the-session cases).
require_once '/var/www/lib/require_auth.php';

// Page title
$pageTitle = t('navbar_counters');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

function counters_query_all(mysqli $db, string $sql): array
{
    $rows = [];
    if ($result = $db->query($sql)) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    return $rows;
}

function counters_format_lurk_duration(DateTime $startTime): array
{
    $interval = (new DateTime())->diff($startTime);
    $totalDuration = ($interval->y * 365 * 24 * 3600)
        + ($interval->m * 30 * 24 * 3600)
        + ($interval->d * 24 * 3600)
        + ($interval->h * 3600)
        + ($interval->i * 60)
        + $interval->s;
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
    return [
        'total_duration' => $totalDuration,
        'lurk_duration' => implode(', ', $timeStringParts),
    ];
}

function counters_helix_usernames(array $userIds, string $authToken): array
{
    $names = [];
    $userIds = array_values(array_unique(array_filter($userIds, static function ($id) {
        return $id !== null && $id !== '';
    })));
    if ($userIds === [] || $authToken === '') {
        return $names;
    }
    $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
    foreach (array_chunk($userIds, 100) as $chunk) {
        $url = 'https://api.twitch.tv/helix/users?id=' . implode('&id=', $chunk);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Client-ID: $clientID",
            "Authorization: Bearer $authToken",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false) {
            continue;
        }
        $userData = json_decode($response, true);
        if (!isset($userData['data']) || !is_array($userData['data'])) {
            continue;
        }
        foreach ($userData['data'] as $helixUser) {
            if (!isset($helixUser['id'])) {
                continue;
            }
            $names[$helixUser['id']] = $helixUser['display_name'] ?? ($helixUser['login'] ?? '');
        }
    }
    return $names;
}

function counters_decode_many_options(string $raw): array
{
    $items = [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $items;
    }
    foreach ($decoded as $item) {
        if (!is_scalar($item)) {
            continue;
        }
        $value = trim((string) $item);
        if ($value !== '') {
            $items[] = $value;
        }
    }
    return $items;
}

function counters_build_list_payload(mysqli $db, string $authToken): array
{
    $stmt = $db->prepare("SELECT timezone FROM profile");
    $stmt->execute();
    $channelData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    date_default_timezone_set($channelData['timezone'] ?? 'UTC');

    $lurkers = counters_query_all($db, "SELECT user_id, start_time FROM lurk_times");
    foreach ($lurkers as $key => $lurker) {
        try {
            $formatted = counters_format_lurk_duration(new DateTime($lurker['start_time']));
            $lurkers[$key]['total_duration'] = $formatted['total_duration'];
            $lurkers[$key]['lurk_duration'] = $formatted['lurk_duration'];
        } catch (Exception $e) {
            $lurkers[$key]['total_duration'] = 0;
            $lurkers[$key]['lurk_duration'] = t('counters_unknown_user');
        }
    }
    usort($lurkers, static function ($a, $b) {
        return ($b['total_duration'] ?? 0) - ($a['total_duration'] ?? 0);
    });
    $helixNames = counters_helix_usernames(array_column($lurkers, 'user_id'), $authToken);
    foreach ($lurkers as $key => $lurker) {
        $lurkers[$key]['username'] = $helixNames[$lurker['user_id']] ?? t('counters_unknown_user');
    }

    $totalDeaths = 0;
    $stmt = $db->prepare("SELECT death_count FROM total_deaths LIMIT 1");
    $stmt->execute();
    $deathRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($deathRow) {
        $totalDeaths = (int) $deathRow['death_count'];
    }

    $manyOptions = [];
    foreach (counters_query_all($db, "SELECT command, many_options_enabled, options FROM custom_command_random_pick_options WHERE many_options_enabled = 1 ORDER BY command ASC") as $row) {
        $items = counters_decode_many_options($row['options'] ?? '[]');
        $manyOptions[] = [
            'command' => $row['command'],
            'items' => $items,
            'items_count' => count($items),
        ];
    }

    return [
        'success' => true,
        'lurkers' => $lurkers,
        'typos' => counters_query_all($db, "SELECT username, typo_count FROM user_typos ORDER BY typo_count DESC"),
        'deaths' => counters_query_all($db, "SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC"),
        'hugs' => counters_query_all($db, "SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC"),
        'kisses' => counters_query_all($db, "SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC"),
        'highfives' => counters_query_all($db, "SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC"),
        'customCounts' => counters_query_all($db, "SELECT command, count FROM custom_counts ORDER BY count DESC"),
        'userCounts' => counters_query_all($db, "SELECT command, user, count FROM user_counts"),
        'rewardCounts' => counters_query_all($db, "SELECT rc.reward_id, rc.user, rc.count, cpr.reward_title FROM reward_counts rc LEFT JOIN channel_point_rewards cpr ON rc.reward_id = cpr.reward_id"),
        'rewardStreaks' => counters_query_all($db, "SELECT rs.reward_id, rs.current_user, rs.streak, cpr.reward_title FROM reward_streaks rs LEFT JOIN channel_point_rewards cpr ON rs.reward_id COLLATE utf8mb4_unicode_ci = cpr.reward_id COLLATE utf8mb4_unicode_ci"),
        'rewardUsage' => counters_query_all($db, "SELECT reward_title, usage_count FROM channel_point_rewards WHERE usage_count > 0"),
        'watchTime' => counters_query_all($db, "SELECT * FROM watch_time"),
        'quotes' => counters_query_all($db, "SELECT id, quote, added FROM quotes ORDER BY added DESC"),
        'manyOptions' => $manyOptions,
        'totalDeaths' => $totalDeaths,
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        echo json_encode(counters_build_list_payload($db, (string) ($authToken ?? '')));
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

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

// Handling form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    session_start(); // Reopen session for POST writes (flash messages)
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
                        $_SESSION['status'] = t('counters_flash_typo_updated', [$formUsername]);
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = t('counters_flash_error', [$stmt->error]);
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
                        $_SESSION['status'] = t('counters_flash_command_updated', [$formCommand]);
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = t('counters_flash_error', [$stmt->error]);
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
                            $_SESSION['status'] = t('counters_flash_death_updated', [$formGame]);
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
                        $_SESSION['status'] = t('counters_flash_hug_updated', [$formHugUser]);
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
                        $_SESSION['status'] = t('counters_flash_kiss_updated', [$formKissUser]);
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
                        $_SESSION['status'] = t('counters_flash_highfive_updated', [$formHighfiveUser]);
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
                        $_SESSION['status'] = t('counters_flash_usercount_updated');
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
                        $_SESSION['status'] = t('counters_flash_typo_removed', [$typoUsernameRemove]);
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
                        $_SESSION['status'] = t('counters_flash_command_removed', [$commandRemove]);
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
                        $_SESSION['status'] = t('counters_flash_death_removed', [$deathGameRemove]);
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
                        $_SESSION['status'] = t('counters_flash_hug_removed', [$hugUsernameRemove]);
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
                        $_SESSION['status'] = t('counters_flash_kiss_removed', [$kissUsernameRemove]);
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
                        $_SESSION['status'] = t('counters_flash_highfive_removed', [$highfiveUsernameRemove]);
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
                        $_SESSION['status'] = t('counters_flash_usercount_removed');
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
                        $_SESSION['status'] = t('counters_flash_death_added', [$deathGameAdd]);
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
                        $_SESSION['status'] = t('counters_flash_quote_added');
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = t('counters_flash_error', [$stmt->error]);
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
                        $_SESSION['status'] = t('counters_flash_quote_updated');
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = t('counters_flash_error', [$stmt->error]);
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
                        $_SESSION['status'] = t('counters_flash_quote_removed');
                        $_SESSION['notification_status'] = "is-success";
                    } else {
                        $_SESSION['status'] = t('counters_flash_error', [$stmt->error]);
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
            $_SESSION['status'] = t('counters_flash_action_processed', [$action]);
            $_SESSION['notification_status'] = "is-info";
            header('Location: counters.php');
            exit();
            break;
    }
}

// Start output buffering for main content
ob_start();
?>
<?php if (isset($status) && !empty($status)): ?>
    <?php
    $spAlertClass = 'sp-alert-info';
    if (isset($notification_status)) {
        if (str_contains($notification_status, 'danger'))      $spAlertClass = 'sp-alert-danger';
        elseif (str_contains($notification_status, 'success')) $spAlertClass = 'sp-alert-success';
        elseif (str_contains($notification_status, 'warning')) $spAlertClass = 'sp-alert-warning';
    }
    ?>
    <div class="sp-alert <?php echo $spAlertClass; ?>"><?php echo $status; ?></div>
<?php endif; ?>
<div class="sp-card">
  <div class="sp-card-header">
    <h2 class="sp-card-title"><i class="fas fa-stopwatch"></i> <?php echo t('navbar_counters'); ?> & <?php echo t('counters_management'); ?></h2>
  </div>
  <div class="sp-card-body">
    <!-- Tab Navigation -->
    <ul class="sp-tabs-nav">
      <li class="is-active" data-tab="view">
        <a onclick="switchMode('view')"><i class="fas fa-eye"></i> <?php echo t('counters_view_data'); ?></a>
      </li>
      <li data-tab="edit">
        <a onclick="switchMode('edit')"><i class="fas fa-edit"></i> <?php echo t('counters_edit_data'); ?></a>
      </li>
    </ul>
    <!-- View Mode -->
    <div id="view-mode" class="mode-content">
      <div class="sp-btn-group">
        <button class="sp-btn sp-btn-info" data-type="lurkers" onclick="loadData('lurkers')"><?php echo t('counters_lurkers'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="typos" onclick="loadData('typos')"><?php echo t('edit_counters_edit_user_typos'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="deaths" onclick="loadData('deaths')"><?php echo t('counters_deaths'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="hugs" onclick="loadData('hugs')"><?php echo t('counters_hugs'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="kisses" onclick="loadData('kisses')"><?php echo t('counters_kisses'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="highfives" onclick="loadData('highfives')"><?php echo t('counters_highfives'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="customCounts" onclick="loadData('customCounts')"><?php echo t('counters_custom_counts'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="userCounts" onclick="loadData('userCounts')"><?php echo t('counters_user_counts'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="rewardCounts" onclick="loadData('rewardCounts')"><?php echo t('counters_reward_counts'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="rewardStreaks" onclick="loadData('rewardStreaks')"><?php echo t('counters_reward_streaks'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="rewardUsage" onclick="loadData('rewardUsage')"><?php echo t('counters_reward_usage'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="watchTime" onclick="loadData('watchTime')"><?php echo t('counters_watch_time'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="quotes" onclick="loadData('quotes')"><?php echo t('counters_quotes'); ?></button>
        <button class="sp-btn sp-btn-info" data-type="manyOptions" onclick="loadData('manyOptions')"><?php echo t('counters_random_pick_lists'); ?></button>
      </div>
      <div class="sp-table-wrap">
        <h3 id="table-title" style="font-size:1.1rem; font-weight:700; text-align:center; margin-bottom:0.75rem; color:var(--text-primary);"><span class="sp-skeleton-line w-40"></span></h3>
        <table class="sp-table" style="table-layout: fixed; width: 100%;">
          <thead>
            <tr>
              <th id="info-column-data" style="width: 33%;"><span class="sp-skeleton-line w-50"></span></th>
              <th id="data-column-info" style="width: 33%;"><span class="sp-skeleton-line w-50"></span></th>
              <th id="count-column" style="width: 33%; display: none;"></th>
            </tr>
          </thead>
          <tbody id="table-body" aria-busy="true">
            <?php for ($sk = 0; $sk < 5; $sk++): ?>
            <tr aria-hidden="true">
              <td><span class="sp-skeleton-line w-70"></span></td>
              <td><span class="sp-skeleton-line w-50"></span></td>
              <td style="display:none;"><span class="sp-skeleton-line w-40"></span></td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </div>
    <!-- Edit Mode -->
    <div id="edit-mode" class="mode-content" style="display: none;">
      <div class="sp-btn-group">
        <button class="sp-btn sp-btn-info" data-edit-type="typos" onclick="showEditTab('typos')"><?php echo t('edit_counters_edit_user_typos'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="customCounts" onclick="showEditTab('customCounts')"><?php echo t('counters_custom_counts'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="deaths" onclick="showEditTab('deaths')"><?php echo t('counters_deaths'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="hugs" onclick="showEditTab('hugs')"><?php echo t('counters_hugs'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="kisses" onclick="showEditTab('kisses')"><?php echo t('counters_kisses'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="highfives" onclick="showEditTab('highfives')"><?php echo t('counters_highfives'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="userCounts" onclick="showEditTab('userCounts')"><?php echo t('counters_user_counts'); ?></button>
        <button class="sp-btn sp-btn-info" data-edit-type="quotes" onclick="showEditTab('quotes')"><?php echo t('counters_quotes'); ?></button>
      </div>
      <!-- Typos Edit Tab -->
      <div id="edit-tab-typos" class="edit-tab-content">
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_user_typos'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="typo-username" name="typo-username" required onchange="updateCurrentCount('typo', this.value); enableButton('typo-username','typo-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_typo_count'); ?></label>
                <input class="sp-input" type="number" id="typo_count" name="typo_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="typo-edit-btn" disabled><?php echo t('edit_counters_update_typo_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_user_typo'); ?></h4>
            <form action="" method="post" id="typo-remove-form" data-type="typo">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="typo-username-remove" name="typo-username-remove" required onchange="enableButton('typo-username-remove','typo-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="typo-remove-btn" disabled><?php echo t('edit_counters_remove_typo_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- Custom Counts Edit Tab -->
      <div id="edit-tab-customCounts" class="edit-tab-content" style="display:none;">
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_custom_counter'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_command_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="command" name="command" required onchange="updateCurrentCount('command', this.value); enableButton('command','command-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_command'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_command_count'); ?></label>
                <input class="sp-input" type="number" id="command_count" name="command_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="command-edit-btn" disabled><?php echo t('edit_counters_update_command_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_custom_counter'); ?></h4>
            <form action="" method="post" id="command-remove-form">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_command_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="command-remove" name="command-remove" required onchange="enableButton('command-remove','command-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_command'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="command-remove-btn" disabled><?php echo t('edit_counters_remove_command_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- Deaths Edit Tab -->
      <div id="edit-tab-deaths" class="edit-tab-content" style="display:none;">
        <div class="sp-alert sp-alert-info" style="text-align:center; margin-bottom:1rem;">
          <strong><?php echo t('edit_counters_total_deaths'); ?>:</strong>
          <span id="edit-total-deaths" aria-busy="true" style="font-weight:700; color:var(--red);"><span class="sp-skeleton-line w-40"></span></span>
        </div>
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_game_deaths'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_game_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="death-game" name="death-game" required onchange="updateCurrentCount('death', this.value); enableButton('death-game','death-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_game'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_death_count'); ?></label>
                <input class="sp-input" type="number" id="death_count" name="death_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="death-edit-btn" disabled><?php echo t('edit_counters_update_death_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_game_death_counter'); ?></h4>
            <form action="" method="post" id="death-remove-form">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_game_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="death-game-remove" name="death-game-remove" required onchange="enableButton('death-game-remove','death-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_game'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="death-remove-btn" disabled><?php echo t('edit_counters_remove_game_death_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- Hugs Edit Tab -->
      <div id="edit-tab-hugs" class="edit-tab-content" style="display:none;">
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_user_hugs'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="hug-username" name="hug-username" required onchange="updateCurrentCount('hug', this.value); enableButton('hug-username','hug-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_hug_count'); ?></label>
                <input class="sp-input" type="number" id="hug_count" name="hug_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="hug-edit-btn" disabled><?php echo t('edit_counters_update_hug_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_user_hug'); ?></h4>
            <form action="" method="post" id="hug-remove-form">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="hug-username-remove" name="hug-username-remove" required onchange="enableButton('hug-username-remove','hug-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="hug-remove-btn" disabled><?php echo t('edit_counters_remove_hug_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- Kisses Edit Tab -->
      <div id="edit-tab-kisses" class="edit-tab-content" style="display:none;">
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_user_kisses'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="kiss-username" name="kiss-username" required onchange="updateCurrentCount('kiss', this.value); enableButton('kiss-username','kiss-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_kiss_count'); ?></label>
                <input class="sp-input" type="number" id="kiss_count" name="kiss_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="kiss-edit-btn" disabled><?php echo t('edit_counters_update_kiss_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_user_kiss'); ?></h4>
            <form action="" method="post" id="kiss-remove-form">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="kiss-username-remove" name="kiss-username-remove" required onchange="enableButton('kiss-username-remove','kiss-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="kiss-remove-btn" disabled><?php echo t('edit_counters_remove_kiss_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- Highfives Edit Tab -->
      <div id="edit-tab-highfives" class="edit-tab-content" style="display:none;">
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_user_highfives'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="highfive-username" name="highfive-username" required onchange="updateCurrentCount('highfive', this.value); enableButton('highfive-username','highfive-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_highfive_count'); ?></label>
                <input class="sp-input" type="number" id="highfive_count" name="highfive_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="highfive-edit-btn" disabled><?php echo t('edit_counters_update_highfive_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_user_highfive'); ?></h4>
            <form action="" method="post" id="highfive-remove-form">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="highfive-username-remove" name="highfive-username-remove" required onchange="enableButton('highfive-username-remove','highfive-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="highfive-remove-btn" disabled><?php echo t('edit_counters_remove_highfive_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- User Counts Edit Tab -->
      <div id="edit-tab-userCounts" class="edit-tab-content" style="display:none;">
        <div class="sp-two-col">
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_user_counts'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_command_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="usercount-command" name="usercount-command" required onchange="updateUserCountUsers(this.value); enableButton('usercount-command','usercount-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_command'); ?></option>
                </select>
              </div>
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="usercount-user" name="usercount-user" required onchange="updateUserCountValue(); enableButton('usercount-user','usercount-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_new_count'); ?></label>
                <input class="sp-input" type="number" id="usercount_count" name="usercount_count" min="0" required>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="usercount-edit-btn" disabled><?php echo t('edit_counters_update_btn'); ?></button>
            </form>
          </div></div>
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_user_count'); ?></h4>
            <form action="" method="post" id="usercount-remove-form">
              <input type="hidden" name="action" value="remove">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_command_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="usercount-command-remove" name="usercount-command-remove" required onchange="updateUserCountUsersRemove(this.value); enableButton('usercount-command-remove','usercount-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_command'); ?></option>
                </select>
              </div>
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_username_label'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="usercount-user-remove" name="usercount-user-remove" required onchange="enableButton('usercount-user-remove','usercount-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_user'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="usercount-remove-btn" disabled><?php echo t('edit_counters_remove_btn'); ?></button>
            </form>
          </div></div>
        </div>
      </div>
      <!-- Quotes Edit Tab -->
      <div id="edit-tab-quotes" class="edit-tab-content" style="display:none;">
        <!-- Add New Quote -->
        <div class="sp-card" style="margin-bottom:1rem;"><div class="sp-card-body">
          <h4 class="sp-card-title"><?php echo t('edit_counters_add_quote'); ?></h4>
          <form action="" method="post">
            <input type="hidden" name="action" value="add_quote">
            <div class="sp-form-group">
              <label class="sp-label"><?php echo t('edit_counters_quote_text'); ?></label>
              <textarea class="sp-textarea" name="quote_text" rows="3" required placeholder="<?php echo t('edit_counters_quote_placeholder'); ?>"></textarea>
            </div>
            <button type="submit" class="sp-btn sp-btn-success"><?php echo t('edit_counters_add_quote_btn'); ?></button>
          </form>
        </div></div>
        <div class="sp-two-col">
          <!-- Edit Existing Quote -->
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_edit_quote'); ?></h4>
            <form action="" method="post">
              <input type="hidden" name="action" value="update_quote">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_select_quote'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="quote-id" name="quote_id" required onchange="updateQuoteText(this.value); enableButton('quote-id','quote-edit-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_quote'); ?></option>
                </select>
              </div>
              <div class="sp-form-group">
                <label class="sp-label"><?php echo t('edit_counters_quote_text'); ?></label>
                <textarea class="sp-textarea" id="quote_text_edit" name="quote_text" rows="3" required></textarea>
              </div>
              <button type="submit" class="sp-btn sp-btn-primary" id="quote-edit-btn" disabled><?php echo t('edit_counters_update_quote_btn'); ?></button>
            </form>
          </div></div>
          <!-- Remove Quote -->
          <div class="sp-card"><div class="sp-card-body">
            <h4 class="sp-card-title"><?php echo t('edit_counters_remove_quote'); ?></h4>
            <form action="" method="post" id="quote-remove-form">
              <input type="hidden" name="action" value="remove_quote">
              <div class="sp-form-group counters-select-host" aria-busy="true">
                <label class="sp-label"><?php echo t('edit_counters_select_quote'); ?></label>
                <span class="sp-skeleton-line w-90 counters-select-skeleton"></span>
                <select class="sp-select" id="quote-id-remove" name="quote_id" required onchange="enableButton('quote-id-remove','quote-remove-btn');" style="display:none;">
                  <option value=""><?php echo t('edit_counters_select_quote'); ?></option>
                </select>
              </div>
              <button type="submit" class="sp-btn sp-btn-danger" id="quote-remove-btn" disabled><?php echo t('edit_counters_remove_quote_btn'); ?></button>
            </form>
          </div></div>
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
  const defaultType = <?php echo json_encode($defaultDataType); ?>;
  const defaultMode = <?php echo json_encode($defaultMode); ?>;
  const defaultEditTab = <?php echo json_encode($defaultEditTab); ?>;
  currentType = defaultType;
  if (defaultMode === 'edit') {
    switchMode('edit', defaultEditTab);
  } else {
    highlightViewType(defaultType);
  }
  wireRemoveForm('typo-remove-form', 'typo-username-remove', <?php echo json_encode(t('counters_type_typo')); ?>);
  wireRemoveForm('command-remove-form', 'command-remove', <?php echo json_encode(t('counters_type_custom_command')); ?>);
  wireRemoveForm('death-remove-form', 'death-game-remove', <?php echo json_encode(t('counters_type_death_counter')); ?>);
  wireRemoveForm('hug-remove-form', 'hug-username-remove', <?php echo json_encode(t('counters_type_hug')); ?>);
  wireRemoveForm('kiss-remove-form', 'kiss-username-remove', <?php echo json_encode(t('counters_type_kiss')); ?>);
  wireRemoveForm('highfive-remove-form', 'highfive-username-remove', <?php echo json_encode(t('counters_type_highfive')); ?>);
  wireRemoveForm('usercount-remove-form', 'usercount-user-remove', <?php echo json_encode(t('counters_type_user_count')); ?>);
  wireRemoveForm('quote-remove-form', 'quote-id-remove', <?php echo json_encode(t('counters_type_quote')); ?>);
  loadCounters();
});

const COUNTERS_COOKIE_CONSENT = <?php echo $cookieConsent ? 'true' : 'false'; ?>;
const COUNTERS_I18N = {
  loadError: <?php echo json_encode(t('counters_flash_error', [''])); ?>,
  selectUser: <?php echo json_encode(t('edit_counters_select_user')); ?>,
  selectCommand: <?php echo json_encode(t('edit_counters_select_command')); ?>,
  selectGame: <?php echo json_encode(t('edit_counters_select_game')); ?>,
  selectQuote: <?php echo json_encode(t('edit_counters_select_quote')); ?>,
  noOptions: <?php echo json_encode(t('counters_no_options_saved')); ?>,
  viewAll: <?php echo json_encode(t('counters_view_all')); ?>,
  hideList: <?php echo json_encode(t('counters_hide_list')); ?>,
  lurkers: <?php echo json_encode(t('counters_lurkers')); ?>,
  typos: <?php echo json_encode(t('edit_counters_edit_user_typos')); ?>,
  deaths: <?php echo json_encode(t('counters_deaths')); ?>,
  hugs: <?php echo json_encode(t('counters_hugs')); ?>,
  kisses: <?php echo json_encode(t('counters_kisses')); ?>,
  highfives: <?php echo json_encode(t('counters_highfives')); ?>,
  customCounts: <?php echo json_encode(t('counters_custom_counts')); ?>,
  userCounts: <?php echo json_encode(t('counters_user_counts')); ?>,
  rewardCounts: <?php echo json_encode(t('counters_reward_counts')); ?>,
  rewardStreaks: <?php echo json_encode(t('counters_reward_streaks')); ?>,
  rewardUsage: <?php echo json_encode(t('counters_reward_usage')); ?>,
  watchTime: <?php echo json_encode(t('counters_watch_time')); ?>,
  quotes: <?php echo json_encode(t('counters_quotes')); ?>,
  manyOptions: <?php echo json_encode(t('counters_random_pick_lists')); ?>,
  timeColumn: <?php echo json_encode(t('counters_time_column')); ?>,
  usernameColumn: <?php echo json_encode(t('counters_username_column')); ?>,
  typoCountColumn: <?php echo json_encode(t('edit_counters_new_typo_count')); ?>,
  countColumn: <?php echo json_encode(t('counters_count_column')); ?>,
  gameColumn: <?php echo json_encode(t('counters_game_column')); ?>,
  usedColumn: <?php echo json_encode(t('counters_used_column')); ?>,
  commandColumn: <?php echo json_encode(t('counters_command_column')); ?>,
  rewardNameColumn: <?php echo json_encode(t('counters_reward_name_column')); ?>,
  rewardColumn: <?php echo json_encode(t('counters_reward_column')); ?>,
  streakColumn: <?php echo json_encode(t('counters_streak_column')); ?>,
  usageCountColumn: <?php echo json_encode(t('counters_usage_count_column')); ?>,
  onlineWatchColumn: <?php echo json_encode(t('counters_online_watch_time_column')); ?>,
  offlineWatchColumn: <?php echo json_encode(t('counters_offline_watch_time_column')); ?>,
  idColumn: <?php echo json_encode(t('counters_id_column')); ?>,
  saidColumn: <?php echo json_encode(t('counters_what_was_said_column')); ?>,
  itemsColumn: <?php echo json_encode(t('counters_items_column')); ?>,
  totalColumn: <?php echo json_encode(t('counters_total_column')); ?>,
  usernameLabel: <?php echo json_encode(t('edit_counters_username_label')); ?>
};

let countersLoaded = false;
let currentType = 'lurkers';
let countersData = {
  lurkers: [],
  typos: [],
  deaths: [],
  hugs: [],
  kisses: [],
  highfives: [],
  customCounts: [],
  userCounts: [],
  rewardCounts: [],
  rewardStreaks: [],
  rewardUsage: [],
  watchTime: [],
  quotes: [],
  manyOptions: [],
  totalDeaths: 0
};
let typoCounts = {};
let commandCounts = {};
let deathCounts = {};
let hugCounts = {};
let kissCounts = {};
let highfiveCounts = {};
let userCountUsersByCommand = {};
let userCountData = [];
let quotesData = [];

function escapeHtml(str) {
  return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
  });
}

function formatWatchTime(seconds) {
  if (seconds == 0) {
    return "<span class='sp-text-danger'><?php echo t('counters_watch_time_not_recorded'); ?></span>";
  }
  const units = {
      year: 31536000,
      month: 2592000,
      day: 86400,
      hour: 3600,
      minute: 60
  };
  const unitLabels = {
    year: { one: <?php echo json_encode(t('counters_unit_year_one')); ?>, other: <?php echo json_encode(t('counters_unit_year_other')); ?> },
    month: { one: <?php echo json_encode(t('counters_unit_month_one')); ?>, other: <?php echo json_encode(t('counters_unit_month_other')); ?> },
    day: { one: <?php echo json_encode(t('counters_unit_day_one')); ?>, other: <?php echo json_encode(t('counters_unit_day_other')); ?> },
    hour: { one: <?php echo json_encode(t('counters_unit_hour_one')); ?>, other: <?php echo json_encode(t('counters_unit_hour_other')); ?> },
    minute: { one: <?php echo json_encode(t('counters_unit_minute_one')); ?>, other: <?php echo json_encode(t('counters_unit_minute_other')); ?> }
  };
  const parts = [];
  for (const [name, divisor] of Object.entries(units)) {
    const quotient = Math.floor(seconds / divisor);
    if (quotient > 0) {
      const label = quotient > 1 ? unitLabels[name].other : unitLabels[name].one;
      parts.push(`${quotient} ${label}`);
      seconds -= quotient * divisor;
    }
  }
  return `<span class='sp-text-success'>${parts.join(', ')}</span>`;
}

function highlightViewType(type) {
  document.querySelectorAll('#view-mode .sp-btn').forEach(button => {
    if (button.getAttribute('data-type') === type) {
      button.classList.remove('sp-btn-info');
      button.classList.add('sp-btn-primary');
    } else {
      button.classList.remove('sp-btn-primary');
      button.classList.add('sp-btn-info');
    }
  });
}

function fillSelect(selectId, items, placeholder, getValue, getLabel) {
  const select = document.getElementById(selectId);
  if (!select) return;
  const previous = select.value;
  select.innerHTML = '';
  const placeholderOpt = document.createElement('option');
  placeholderOpt.value = '';
  placeholderOpt.textContent = placeholder;
  select.appendChild(placeholderOpt);
  items.forEach(function(item) {
    const opt = document.createElement('option');
    opt.value = getValue(item);
    opt.textContent = getLabel(item);
    select.appendChild(opt);
  });
  if (previous) {
    select.value = previous;
  }
}

function quoteOptionLabel(quote) {
  const text = String(quote.quote == null ? '' : quote.quote);
  const preview = text.length > 50 ? text.slice(0, 50) + '...' : text;
  return '#' + quote.id + ' - ' + preview;
}

function revealEditSelects() {
  document.querySelectorAll('.counters-select-host').forEach(function(host) {
    host.setAttribute('aria-busy', 'false');
    const skeleton = host.querySelector('.counters-select-skeleton');
    const select = host.querySelector('select');
    if (skeleton) skeleton.style.display = 'none';
    if (select) select.style.display = '';
  });
}

function populateEditForms() {
  fillSelect('typo-username', countersData.typos, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('typo-username-remove', countersData.typos, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('command', countersData.customCounts, COUNTERS_I18N.selectCommand, function(item) { return item.command; }, function(item) { return item.command; });
  fillSelect('command-remove', countersData.customCounts, COUNTERS_I18N.selectCommand, function(item) { return item.command; }, function(item) { return item.command; });
  fillSelect('death-game', countersData.deaths, COUNTERS_I18N.selectGame, function(item) { return item.game_name; }, function(item) { return item.game_name; });
  fillSelect('death-game-remove', countersData.deaths, COUNTERS_I18N.selectGame, function(item) { return item.game_name; }, function(item) { return item.game_name; });
  fillSelect('hug-username', countersData.hugs, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('hug-username-remove', countersData.hugs, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('kiss-username', countersData.kisses, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('kiss-username-remove', countersData.kisses, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('highfive-username', countersData.highfives, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  fillSelect('highfive-username-remove', countersData.highfives, COUNTERS_I18N.selectUser, function(item) { return item.username; }, function(item) { return item.username; });
  const userCountCommands = Object.keys(userCountUsersByCommand);
  fillSelect('usercount-command', userCountCommands, COUNTERS_I18N.selectCommand, function(item) { return item; }, function(item) { return item; });
  fillSelect('usercount-command-remove', userCountCommands, COUNTERS_I18N.selectCommand, function(item) { return item; }, function(item) { return item; });
  fillSelect('quote-id', countersData.quotes, COUNTERS_I18N.selectQuote, function(item) { return item.id; }, quoteOptionLabel);
  fillSelect('quote-id-remove', countersData.quotes, COUNTERS_I18N.selectQuote, function(item) { return item.id; }, quoteOptionLabel);
  const totalEl = document.getElementById('edit-total-deaths');
  if (totalEl) {
    totalEl.setAttribute('aria-busy', 'false');
    totalEl.textContent = String(countersData.totalDeaths || 0);
  }
  revealEditSelects();
}

function applyCountersPayload(payload) {
  countersData.lurkers = Array.isArray(payload.lurkers) ? payload.lurkers : [];
  countersData.typos = Array.isArray(payload.typos) ? payload.typos : [];
  countersData.deaths = Array.isArray(payload.deaths) ? payload.deaths : [];
  countersData.hugs = Array.isArray(payload.hugs) ? payload.hugs : [];
  countersData.kisses = Array.isArray(payload.kisses) ? payload.kisses : [];
  countersData.highfives = Array.isArray(payload.highfives) ? payload.highfives : [];
  countersData.customCounts = Array.isArray(payload.customCounts) ? payload.customCounts : [];
  countersData.userCounts = Array.isArray(payload.userCounts) ? payload.userCounts : [];
  countersData.rewardCounts = Array.isArray(payload.rewardCounts) ? payload.rewardCounts : [];
  countersData.rewardStreaks = Array.isArray(payload.rewardStreaks) ? payload.rewardStreaks : [];
  countersData.rewardUsage = Array.isArray(payload.rewardUsage) ? payload.rewardUsage : [];
  countersData.watchTime = Array.isArray(payload.watchTime) ? payload.watchTime : [];
  countersData.quotes = Array.isArray(payload.quotes) ? payload.quotes : [];
  countersData.manyOptions = Array.isArray(payload.manyOptions) ? payload.manyOptions : [];
  countersData.totalDeaths = Number(payload.totalDeaths || 0);
  typoCounts = {};
  countersData.typos.forEach(function(row) { typoCounts[row.username] = row.typo_count; });
  commandCounts = {};
  countersData.customCounts.forEach(function(row) { commandCounts[row.command] = row.count; });
  deathCounts = {};
  countersData.deaths.forEach(function(row) { deathCounts[row.game_name] = row.death_count; });
  hugCounts = {};
  countersData.hugs.forEach(function(row) { hugCounts[row.username] = row.hug_count; });
  kissCounts = {};
  countersData.kisses.forEach(function(row) { kissCounts[row.username] = row.kiss_count; });
  highfiveCounts = {};
  countersData.highfives.forEach(function(row) { highfiveCounts[row.username] = row.highfive_count; });
  userCountUsersByCommand = {};
  userCountData = countersData.userCounts.slice();
  countersData.userCounts.forEach(function(row) {
    if (!userCountUsersByCommand[row.command]) {
      userCountUsersByCommand[row.command] = [];
    }
    userCountUsersByCommand[row.command].push(row.user);
  });
  quotesData = countersData.quotes.slice();
  countersLoaded = true;
  populateEditForms();
  const viewMode = document.getElementById('view-mode');
  if (!viewMode || viewMode.style.display !== 'none') {
    loadData(currentType);
  } else {
    const tbody = document.getElementById('table-body');
    if (tbody) tbody.setAttribute('aria-busy', 'false');
  }
}

function renderCountersError(message) {
  countersLoaded = false;
  revealEditSelects();
  const tbody = document.getElementById('table-body');
  if (!tbody) return;
  tbody.setAttribute('aria-busy', 'false');
  tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">' + escapeHtml(message || COUNTERS_I18N.loadError) + '</td></tr>';
}

function loadCounters() {
  const url = new URL(window.location.pathname, window.location.origin);
  url.searchParams.set('ajax_action', 'list');
  fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.success) {
        renderCountersError(data && data.error ? data.error : COUNTERS_I18N.loadError);
        return;
      }
      applyCountersPayload(data);
    })
    .catch(function() {
      renderCountersError(COUNTERS_I18N.loadError);
    });
}

function loadData(type) {
  currentType = type;
  if (COUNTERS_COOKIE_CONSENT) {
    setCookie('preferred_data_type', type, 30);
  }
  highlightViewType(type);
  if (!countersLoaded) {
    return;
  }

  let data = [];
  let title = '';
  let dataColumn = '';
  let infoColumn = '';
  let countColumnVisible = false;
  let additionalColumnName = '';
  let output = '';

  switch(type) {
    case 'lurkers':
      data = countersData.lurkers;
      title = COUNTERS_I18N.lurkers;
      dataColumn = COUNTERS_I18N.timeColumn;
      infoColumn = COUNTERS_I18N.usernameColumn;
      break;
    case 'typos':
      data = countersData.typos;
      title = COUNTERS_I18N.typos;
      dataColumn = COUNTERS_I18N.typoCountColumn;
      infoColumn = COUNTERS_I18N.usernameLabel;
      break;
    case 'deaths':
      data = countersData.deaths;
      title = COUNTERS_I18N.deaths;
      dataColumn = COUNTERS_I18N.countColumn;
      infoColumn = COUNTERS_I18N.gameColumn;
      break;
    case 'hugs':
      data = countersData.hugs;
      title = COUNTERS_I18N.hugs;
      dataColumn = COUNTERS_I18N.countColumn;
      infoColumn = COUNTERS_I18N.usernameColumn;
      break;
    case 'kisses':
      data = countersData.kisses;
      title = COUNTERS_I18N.kisses;
      dataColumn = COUNTERS_I18N.countColumn;
      infoColumn = COUNTERS_I18N.usernameColumn;
      break;
    case 'highfives':
      data = countersData.highfives;
      title = COUNTERS_I18N.highfives;
      dataColumn = COUNTERS_I18N.countColumn;
      infoColumn = COUNTERS_I18N.usernameColumn;
      break;
    case 'customCounts':
      data = countersData.customCounts;
      title = COUNTERS_I18N.customCounts;
      dataColumn = COUNTERS_I18N.usedColumn;
      infoColumn = COUNTERS_I18N.commandColumn;
      break;
    case 'userCounts':
      data = countersData.userCounts;
      countColumnVisible = true;
      title = COUNTERS_I18N.userCounts;
      infoColumn = COUNTERS_I18N.usernameColumn;
      dataColumn = COUNTERS_I18N.commandColumn;
      additionalColumnName = COUNTERS_I18N.countColumn;
      break;
    case 'rewardCounts':
      data = countersData.rewardCounts;
      countColumnVisible = true;
      title = COUNTERS_I18N.rewardCounts;
      infoColumn = COUNTERS_I18N.rewardNameColumn;
      dataColumn = COUNTERS_I18N.usernameColumn;
      additionalColumnName = COUNTERS_I18N.countColumn;
      break;
    case 'rewardStreaks':
      data = countersData.rewardStreaks;
      countColumnVisible = true;
      title = COUNTERS_I18N.rewardStreaks;
      infoColumn = COUNTERS_I18N.rewardColumn;
      dataColumn = COUNTERS_I18N.usernameColumn;
      additionalColumnName = COUNTERS_I18N.streakColumn;
      break;
    case 'rewardUsage':
      data = countersData.rewardUsage;
      title = COUNTERS_I18N.rewardUsage;
      dataColumn = COUNTERS_I18N.usageCountColumn;
      infoColumn = COUNTERS_I18N.rewardNameColumn;
      break;
    case 'watchTime':
      data = countersData.watchTime.slice();
      title = COUNTERS_I18N.watchTime;
      infoColumn = COUNTERS_I18N.usernameColumn;
      dataColumn = COUNTERS_I18N.onlineWatchColumn;
      additionalColumnName = COUNTERS_I18N.offlineWatchColumn;
      countColumnVisible = true;
      data.sort(function(a, b) {
        return (b.total_watch_time_live - a.total_watch_time_live) || (b.total_watch_time_offline - a.total_watch_time_offline);
      });
      break;
    case 'quotes':
      data = countersData.quotes;
      title = COUNTERS_I18N.quotes;
      infoColumn = COUNTERS_I18N.idColumn;
      dataColumn = COUNTERS_I18N.saidColumn;
      break;
    case 'manyOptions':
      data = countersData.manyOptions;
      countColumnVisible = true;
      title = COUNTERS_I18N.manyOptions;
      infoColumn = COUNTERS_I18N.commandColumn;
      dataColumn = COUNTERS_I18N.itemsColumn;
      additionalColumnName = COUNTERS_I18N.totalColumn;
      break;
  }

  document.getElementById('data-column-info').innerText = dataColumn;
  document.getElementById('info-column-data').innerText = infoColumn;
  if (countColumnVisible) {
    document.getElementById('count-column').style.display = '';
    document.getElementById('count-column').innerText = additionalColumnName;
  } else {
    document.getElementById('count-column').style.display = 'none';
  }
  data.forEach(function(item) {
    output += `<tr>`;
    if (type === 'lurkers') {
      output += `<td>${escapeHtml(item.username)}</td><td><span class='sp-text-success'>${escapeHtml(item.lurk_duration)}</span></td>`;
    } else if (type === 'typos') {
      output += `<td>${escapeHtml(item.username)}</td><td><span class='sp-text-success'>${escapeHtml(item.typo_count)}</span></td>`;
    } else if (type === 'deaths') {
      output += `<td>${escapeHtml(item.game_name)}</td><td><span class='sp-text-success'>${escapeHtml(item.death_count)}</span></td>`;
    } else if (type === 'hugs') {
      output += `<td>${escapeHtml(item.username)}</td><td><span class='sp-text-success'>${escapeHtml(item.hug_count)}</span></td>`;
    } else if (type === 'kisses') {
      output += `<td>${escapeHtml(item.username)}</td><td><span class='sp-text-success'>${escapeHtml(item.kiss_count)}</span></td>`;
    } else if (type === 'highfives') {
      output += `<td>${escapeHtml(item.username)}</td><td><span class='sp-text-success'>${escapeHtml(item.highfive_count)}</span></td>`;
    } else if (type === 'customCounts') {
      output += `<td>${escapeHtml(item.command)}</td><td><span class='sp-text-success'>${escapeHtml(item.count)}</span></td>`;
    } else if (type === 'userCounts') {
      output += `<td>${escapeHtml(item.user)}</td><td><span class='sp-text-success'>${escapeHtml(item.command)}</span></td><td><span class='sp-text-success'>${escapeHtml(item.count)}</span></td>`;
    } else if (type === 'rewardCounts') {
      output += `<td>${escapeHtml(item.reward_title)}</td><td>${escapeHtml(item.user)}</td><td><span class='sp-text-success'>${escapeHtml(item.count)}</span></td>`;
    } else if (type === 'rewardStreaks') {
      output += `<td>${escapeHtml(item.reward_title)}</td><td>${escapeHtml(item.current_user)}</td><td><span class='sp-text-success'>${escapeHtml(item.streak)}</span></td>`;
    } else if (type === 'rewardUsage') {
      output += `<td>${escapeHtml(item.reward_title)}</td><td><span class='sp-text-success'>${escapeHtml(item.usage_count)}</span></td>`;
    } else if (type === 'watchTime') {
      output += `<td>${escapeHtml(item.username)}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td>`;
    } else if (type === 'quotes') {
      output += `<td>${escapeHtml(item.id)}</td><td><span class='sp-text-success'>${escapeHtml(item.quote)}</span></td>`;
    } else if (type === 'manyOptions') {
      const optionItems = Array.isArray(item.items) ? item.items : [];
      const escapedItems = optionItems.map(function(option) { return escapeHtml(option); });
      let renderedOptions = "<span class='sp-text-muted'>" + escapeHtml(COUNTERS_I18N.noOptions) + "</span>";
      if (escapedItems.length > 0) {
        if (escapedItems.length <= 5) {
          renderedOptions = escapedItems.join('<br>');
        } else {
          const preview = escapedItems.slice(0, 3).join(', ');
          const allItemsHtml = escapedItems.map(function(option) { return `<div>${option}</div>`; }).join('');
          renderedOptions =
            `<details ontoggle="toggleManyOptionsSummary(this)">` +
              `<summary style="cursor:pointer;">` +
                `${preview}, ... ` +
                `<span class="many-options-summary-closed">(${escapeHtml(COUNTERS_I18N.viewAll)} ${escapedItems.length})</span>` +
                `<span class="many-options-summary-open" style="display:none;">(${escapeHtml(COUNTERS_I18N.hideList)} ${escapedItems.length})</span>` +
              `</summary>` +
              `<div style="margin-top: 0.5rem;">${allItemsHtml}</div>` +
            `</details>`;
        }
      }
      output += `<td>!${escapeHtml(item.command)}</td><td>${renderedOptions}</td><td><span class='sp-text-success'>${escapeHtml(item.items_count)}</span></td>`;
    }
    if (countColumnVisible) {
        if (type !== 'userCounts' && type !== 'rewardCounts' && type !== 'rewardStreaks' && type !== 'watchTime' && type !== 'manyOptions') {
             output += `<td></td>`;
        }
    }
    output += `</tr>`;
  });
  const tbody = document.getElementById('table-body');
  document.getElementById('table-title').innerText = title;
  tbody.setAttribute('aria-busy', 'false');
  tbody.innerHTML = output;
}

function setCookie(name, value, days) {
  const d = new Date();
  d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
  const expires = "expires=" + d.toUTCString();
  document.cookie = name + "=" + value + ";" + expires + ";path=/";
}

function switchMode(mode, editTab) {
  const viewMode = document.getElementById('view-mode');
  const editMode = document.getElementById('edit-mode');
  const tabs = document.querySelectorAll('.sp-tabs-nav li');
  if (COUNTERS_COOKIE_CONSENT) {
    setCookie('preferred_mode', mode, 30);
  }
  if (mode === 'view') {
    viewMode.style.display = 'block';
    editMode.style.display = 'none';
    tabs[0].classList.add('is-active');
    tabs[1].classList.remove('is-active');
    if (countersLoaded) {
      loadData(currentType);
    }
  } else {
    viewMode.style.display = 'none';
    editMode.style.display = 'block';
    tabs[0].classList.remove('is-active');
    tabs[1].classList.add('is-active');
    showEditTab(editTab || 'typos');
  }
}

function showEditTab(type) {
  document.querySelectorAll('.edit-tab-content').forEach(tab => {
    tab.style.display = 'none';
  });
  const selectedTab = document.getElementById('edit-tab-' + type);
  if (selectedTab) {
    selectedTab.style.display = 'block';
  }
  document.querySelectorAll('#edit-mode .sp-btn').forEach(button => {
    if (button.getAttribute('data-edit-type') === type) {
      button.classList.remove('sp-btn-info');
      button.classList.add('sp-btn-primary');
    } else {
      button.classList.remove('sp-btn-primary');
      button.classList.add('sp-btn-info');
    }
  });
  if (COUNTERS_COOKIE_CONSENT) {
    setCookie('preferred_edit_tab', type, 30);
  }
}

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
    const confirmText = <?php echo json_encode(t('counters_remove_confirm_text', ['type' => ':type'])); ?>.replace(':type', type);
    Swal.fire({
      title: <?php echo json_encode(t('edit_counters_swal_title')); ?>,
      text: confirmText,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#3085d6',
      cancelButtonColor: '#d33',
      cancelButtonText: <?php echo json_encode(t('edit_counters_swal_cancel')); ?>,
      confirmButtonText: <?php echo json_encode(t('edit_counters_swal_confirm')); ?>
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
}

function updateUserCountUsers(command) {
  const userSelect = document.getElementById('usercount-user');
  userSelect.innerHTML = '<option value="">' + escapeHtml(COUNTERS_I18N.selectUser) + '</option>';
  if (command && userCountUsersByCommand[command]) {
    userCountUsersByCommand[command].forEach(user => {
      const option = document.createElement('option');
      option.value = user;
      option.textContent = user;
      userSelect.appendChild(option);
    });
  }
  document.getElementById('usercount_count').value = '';
  const btn = document.getElementById('usercount-edit-btn');
  if (btn) btn.disabled = true;
}

function updateUserCountUsersRemove(command) {
  const userSelect = document.getElementById('usercount-user-remove');
  userSelect.innerHTML = '<option value="">' + escapeHtml(COUNTERS_I18N.selectUser) + '</option>';
  if (command && userCountUsersByCommand[command]) {
    userCountUsersByCommand[command].forEach(user => {
      const option = document.createElement('option');
      option.value = user;
      option.textContent = user;
      userSelect.appendChild(option);
    });
  }
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

function toggleManyOptionsSummary(detailsElement) {
  const closedLabel = detailsElement.querySelector('.many-options-summary-closed');
  const openLabel = detailsElement.querySelector('.many-options-summary-open');
  if (!closedLabel || !openLabel) {
    return;
  }
  if (detailsElement.open) {
    closedLabel.style.display = 'none';
    openLabel.style.display = 'inline';
  } else {
    closedLabel.style.display = 'inline';
    openLabel.style.display = 'none';
  }
}
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();

// Use layout.php to render the page
include 'layout.php';
?>
