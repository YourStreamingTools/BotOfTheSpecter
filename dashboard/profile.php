<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('profile_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load

$userId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));
$isTechnical = isset($user['is_technical']) ? (bool) $user['is_technical'] : false;
$profileImageUrl = $user['profile_image'] ?? ($twitch_profile_image_url ?? ($_SESSION['profile_image'] ?? 'https://cdn.botofthespecter.com/logo.png'));
if (!isset($_SESSION['language']) && !empty($user['language'])) {
    $userLanguage = $user['language'];
} elseif (isset($_SESSION['language'])) {
    $userLanguage = $_SESSION['language'];
    $user['language'] = $_SESSION['language'];
}

// Show session message after redirect (e.g. after language change or bot config save)
$message = '';
$alertClass = '';
if (isset($_SESSION['profile_message'])) {
    $message = $_SESSION['profile_message'];
    $alertClass = $_SESSION['profile_alert_class'] ?? 'is-success';
    unset($_SESSION['profile_message'], $_SESSION['profile_alert_class']);
}

session_write_close();

function resolveTwitchUserId($username) {
    global $clientID, $authToken;
    $username = trim($username);
    if ($username === '') return array(false, t('bot_username_empty'));
    $url = 'https://api.twitch.tv/helix/users?login=' . urlencode($username);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $clientID,
        'Authorization: Bearer ' . $authToken,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        return array(false, t('twitch_api_error') . ': ' . ($err ?: "HTTP {$code}"));
    }
    $data = json_decode($resp, true);
    if (!isset($data['data'][0]['id'])) {
        return array(false, t('twitch_user_not_found'));
    }
    return array($data['data'][0]['id'], null);
}

function formatUserDate($datetime, $timezone) {
    if (!$datetime) return t('profile_unknown');
    try {
        $serverTz = new DateTimeZone('Australia/Sydney');
        $dt = new DateTime($datetime, $serverTz);
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format('M j, Y - g:ia ') . $dt->format('T');
    } catch (Exception $e) {
        return htmlspecialchars($datetime);
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// List endpoint first so the browser can paint skeletons, then fetch storage + link status.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        if (!isset($user['beta_access']) || $user['beta_access'] != 1) {
            $checkUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/api/check_subscription.php';
            $ch = curl_init($checkUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            if (is_resource($ch) || (is_object($ch) && get_class($ch) === 'CurlHandle')) {
                curl_close($ch);
            }
        }

        include 'includes/storage_used.php';
        $storageUsedFormatted = formatBytes($current_storage_used ?? 0);
        $storageMaxFormatted = formatBytes($max_storage_size ?? 0);
        $storagePercent = isset($storage_percentage) ? max(0, min(100, round($storage_percentage, 2))) : 0;

        $discordLinked = false;
        $discordStmt = $conn->prepare('SELECT 1 FROM discord_users WHERE user_id = ?');
        if ($discordStmt) {
            $discordStmt->bind_param('i', $userId);
            $discordStmt->execute();
            $discordLinked = ($discordStmt->get_result()->num_rows > 0);
            $discordStmt->close();
        }

        $spotifyLinked = false;
        $spotifyStmt = $conn->prepare('SELECT 1 FROM spotify_tokens WHERE user_id = ?');
        if ($spotifyStmt) {
            $spotifyStmt->bind_param('i', $userId);
            $spotifyStmt->execute();
            $spotifyLinked = ($spotifyStmt->get_result()->num_rows > 0);
            $spotifyStmt->close();
        }

        $streamelementsLinked = false;
        if (isset($_SESSION['twitchUserId'])) {
            $streamelementsStmt = $conn->prepare('SELECT access_token FROM streamelements_tokens WHERE twitch_user_id = ?');
            if ($streamelementsStmt) {
                $streamelementsStmt->bind_param('s', $_SESSION['twitchUserId']);
                $streamelementsStmt->execute();
                $streamelementsResult = $streamelementsStmt->get_result();
                if ($streamelementsRow = $streamelementsResult->fetch_assoc()) {
                    $accessToken = $streamelementsRow['access_token'];
                    $ch = curl_init('https://api.streamelements.com/oauth2/validate');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
                    $validate_response = curl_exec($ch);
                    $validate_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $validate_data = json_decode($validate_response, true);
                    if (is_resource($ch) || (is_object($ch) && get_class($ch) === 'CurlHandle')) {
                        curl_close($ch);
                    }
                    if ($validate_code === 200 && isset($validate_data['channel_id'])) {
                        $streamelementsLinked = true;
                    }
                }
                $streamelementsStmt->close();
            }
        }

        $streamlabsLinked = false;
        if (isset($_SESSION['twitchUserId'])) {
            $streamlabsStmt = $conn->prepare('SELECT 1 FROM streamlabs_tokens WHERE twitch_user_id = ?');
            if ($streamlabsStmt) {
                $streamlabsStmt->bind_param('s', $_SESSION['twitchUserId']);
                $streamlabsStmt->execute();
                $streamlabsLinked = ($streamlabsStmt->get_result()->num_rows > 0);
                $streamlabsStmt->close();
            }
        }

        require_once __DIR__ . '/includes/kick_bot.php';
        $kickLinked = kick_bot_is_linked($conn, (string)($username ?? ''));

        echo json_encode([
            'success' => true,
            'storage' => [
                'used' => (int) ($current_storage_used ?? 0),
                'max' => (int) ($max_storage_size ?? 0),
                'used_formatted' => $storageUsedFormatted,
                'max_formatted' => $storageMaxFormatted,
                'percent' => $storagePercent,
                'tier' => $_SESSION['tier'] ?? 'None',
                'beta_access' => !empty($user['beta_access']) && (int) $user['beta_access'] === 1,
            ],
            'links' => [
                'discord' => $discordLinked,
                'spotify' => $spotifyLinked,
                'streamelements' => $streamelementsLinked,
                'streamlabs' => $streamlabsLinked,
                'kick' => $kickLinked,
            ],
        ]);
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start(); // Reopen session for flash messages / session writes
    $action = $_POST['action'] ?? '';
    if ($action === 'resolve_bot_id' || $action === 'save_custom_bot') {
        require_once '/var/www/config/twitch.php';
    }
    // AJAX endpoint to resolve bot username to Twitch ID
    if ($action === 'resolve_bot_id') {
        header('Content-Type: application/json');
        $botName = trim($_POST['bot_username'] ?? '');
        if ($botName === '') {
            echo json_encode(['success' => false, 'error' => t('bot_username_empty')]);
            exit();
        }
        list($resolvedId, $resolveErr) = resolveTwitchUserId($botName);
        if ($resolvedId === false) {
            echo json_encode(['success' => false, 'error' => $resolveErr]);
            exit();
        }
        echo json_encode(['success' => true, 'bot_id' => $resolvedId]);
        exit();
    }
    if ($action === 'update_timezone') {
        $timezone = $_POST['timezone'] ?? 'UTC';
        // Check if profile row exists
        $checkStmt = mysqli_prepare($db, "SELECT COUNT(*) as cnt FROM profile");
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $row = mysqli_fetch_assoc($checkResult);
        
        if ($row['cnt'] == 0) {
            // Insert new row
            $updateQuery = "INSERT INTO profile (timezone) VALUES (?)";
        } else {
            // Update existing row
            $updateQuery = "UPDATE profile SET timezone = ?";
        }
        
        $stmt = mysqli_prepare($db, $updateQuery);
        if ($stmt === false) {
            $message = t('timezone_update_error') . ': ' . mysqli_error($db);
            $alertClass = 'is-danger';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $timezone);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['timezone'] = $timezone;
                // Store the message in session and reload to show updated value
                $_SESSION['profile_message'] = t('timezone_updated_success');
                $_SESSION['profile_alert_class'] = 'is-success';
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit();
            } else {
                $message = t('timezone_update_error') . ': ' . mysqli_error($db);
                $alertClass = 'is-danger';
            }
        }
    } elseif ($action === 'update_weather_location') {
        $weatherLocation = $_POST['weather_location'] ?? '';
        // Check if profile row exists
        $checkStmt = mysqli_prepare($db, "SELECT COUNT(*) as cnt FROM profile");
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $row = mysqli_fetch_assoc($checkResult);
        
        if ($row['cnt'] == 0) {
            // Insert new row
            $updateQuery = "INSERT INTO profile (weather_location) VALUES (?)";
        } else {
            // Update existing row
            $updateQuery = "UPDATE profile SET weather_location = ?";
        }
        $stmt = mysqli_prepare($db, $updateQuery);
        if ($stmt === false) {
            $message = t('weather_location_update_error') . ': ' . mysqli_error($db);
            $alertClass = 'is-danger';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $weatherLocation);
            if (mysqli_stmt_execute($stmt)) {
                // Store the message in session and reload to show updated value
                $_SESSION['profile_message'] = t('weather_location_updated_success');
                $_SESSION['profile_alert_class'] = 'is-success';
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit();
            } else {
                $message = t('weather_location_update_error') . ': ' . mysqli_error($db);
                $alertClass = 'is-danger';
            }
        }
    } elseif ($action === 'regenerate_api_key') {
        // Handle API key regeneration
        $newApiKey = bin2hex(random_bytes(16));
        $updateQuery = "UPDATE users SET api_key = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $updateQuery);
        mysqli_stmt_bind_param($stmt, 'si', $newApiKey, $userId);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['api_key'] = $newApiKey;
            $user['api_key'] = $newApiKey;
            $message = t('api_key_regenerated_success');
            $alertClass = 'is-success';
        } else {
            $message = t('api_key_regenerate_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_heartrate_code') {
        // Handle heart rate code update
        $newCode = trim($_POST['heartrate_code'] ?? '');
        if ($newCode !== '') {
            $updateQuery = "UPDATE users SET heartrate_code = ? WHERE id = ?";
            // users table belongs to the website DB ($conn)
            $stmt = mysqli_prepare($conn, $updateQuery);
            if ($stmt === false) {
                $message = t('heartrate_code_update_error') . ': ' . mysqli_error($conn);
                $alertClass = 'is-danger';
            } else {
                mysqli_stmt_bind_param($stmt, 'si', $newCode, $userId);
                if (mysqli_stmt_execute($stmt)) {
                $message = t('heartrate_code_updated_success');
                $alertClass = 'is-success';
                // Refresh user data to get the latest code from DB
                $userQuery = "SELECT heartrate_code, api_key, created_at, last_login FROM users WHERE id = ?";
                $stmt2 = mysqli_prepare($conn, $userQuery);
                mysqli_stmt_bind_param($stmt2, 'i', $userId);
                mysqli_stmt_execute($stmt2);
                $result2 = mysqli_stmt_get_result($stmt2);
                $userRow = mysqli_fetch_assoc($result2);
                } else {
                    $message = t('heartrate_code_update_error') . ': ' . mysqli_error($conn);
                    $alertClass = 'is-danger';
                }
            }
        } else {
            $message = t('heartrate_code_update_empty_error');
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_technical_mode') {
        $isTechnicalNew = isset($_POST['is_technical']) ? (int)$_POST['is_technical'] : 0;
        $updateQuery = "UPDATE users SET is_technical = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $updateQuery);
        mysqli_stmt_bind_param($stmt, 'ii', $isTechnicalNew, $userId);
        if (mysqli_stmt_execute($stmt)) {
            $message = t('technical_mode_updated_success');
            $alertClass = 'is-success';
            $isTechnical = (bool)$isTechnicalNew;
            $_SESSION['is_technical'] = $isTechnical;
            $user['is_technical'] = $isTechnicalNew;
        } else {
            $message = t('technical_mode_update_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'update_language') {
        $language = $_POST['language'] ?? 'EN';
        $updateUserQuery = "UPDATE users SET language = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $updateUserQuery);
        mysqli_stmt_bind_param($stmt, 'si', $language, $userId);
        $userSuccess = mysqli_stmt_execute($stmt);
        if ($userSuccess) {
            $_SESSION['language'] = $language;
            // Store the message in session and reload
            $_SESSION['profile_message'] = t('language_updated_success');
            $_SESSION['profile_alert_class'] = 'is-success';
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        } else {
            $message = t('language_update_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'disconnect_discord') {
        $deleteQuery = "DELETE FROM discord_users WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        if (mysqli_stmt_execute($stmt)) {
            $message = t('discord_disconnected_success');
            $alertClass = 'is-success';
        } else {
            $message = t('discord_disconnect_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'disconnect_kick') {
        require_once __DIR__ . '/includes/kick_bot.php';
        $kickChannel = strtolower((string)($username ?? ''));
        if (kick_bot_delete_tokens($conn, $kickChannel)) {
            $botsClient = __DIR__ . '/includes/bots_api_client.php';
            if (is_file($botsClient)) {
                require_once $botsClient;
                if (function_exists('bots_api_stop_bot')) {
                    bots_api_stop_bot($kickChannel, 'kick');
                }
            }
            $message = t('kick_disconnected_success');
            $alertClass = 'is-success';
        } else {
            $message = t('kick_disconnect_error');
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'disconnect_spotify') {
        $deleteQuery = "DELETE FROM spotify_tokens WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        if (mysqli_stmt_execute($stmt)) {
            $message = t('spotify_disconnected_success');
            $alertClass = 'is-success';
        } else {
            $message = t('spotify_disconnect_error') . ': ' . mysqli_error($conn);
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'disconnect_streamelements') {
        if (isset($_SESSION['twitchUserId'])) {
            $deleteQuery = "DELETE FROM streamelements_tokens WHERE twitch_user_id = ?";
            $stmt = mysqli_prepare($conn, $deleteQuery);
            mysqli_stmt_bind_param($stmt, 's', $_SESSION['twitchUserId']);
            if (mysqli_stmt_execute($stmt)) {
                $message = t('streamelements_disconnected_success');
                $alertClass = 'is-success';
            } else {
                $message = t('streamelements_disconnect_error') . ': ' . mysqli_error($conn);
                $alertClass = 'is-danger';
            }
        } else {
            $message = t('streamelements_disconnect_error') . ': ' . t('profile_no_twitch_user_id');
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'disconnect_streamlabs') {
        if (isset($_SESSION['twitchUserId'])) {
            $deleteQuery = "DELETE FROM streamlabs_tokens WHERE twitch_user_id = ?";
            $stmt = mysqli_prepare($conn, $deleteQuery);
            mysqli_stmt_bind_param($stmt, 's', $_SESSION['twitchUserId']);
            if (mysqli_stmt_execute($stmt)) {
                $message = t('streamlabs_disconnected_success');
                $alertClass = 'is-success';
            } else {
                $message = t('streamlabs_disconnect_error') . ': ' . mysqli_error($conn);
                $alertClass = 'is-danger';
            }
        } else {
            $message = t('streamlabs_disconnect_error') . ': ' . t('profile_no_twitch_user_id');
            $alertClass = 'is-danger';
        }
    } elseif ($action === 'disconnect_twitch') {
        // Twitch disconnect is essentially a logout since it's the primary auth
        // Clear all session data and redirect to logout
        session_unset();
        session_destroy();
        header('Location: logout.php');
        exit();
    } elseif ($action === 'set_app_password') {
        $newPassword = $_POST['app_password'] ?? '';
        $confirmPassword = $_POST['app_password_confirm'] ?? '';
        if ($newPassword === '') {
            $message = t('profile_app_password_empty');
            $alertClass = 'is-danger';
        } elseif ($newPassword !== $confirmPassword) {
            $message = t('profile_app_passwords_no_match');
            $alertClass = 'is-danger';
        } elseif (strlen($newPassword) < 6) {
            $message = t('profile_app_password_too_short');
            $alertClass = 'is-danger';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET app_password = ? WHERE id = ?");
            if ($stmt === false) {
                error_log('profile.php set_app_password prepare failed: ' . mysqli_error($conn));
                $message = t('profile_app_password_set_unavailable');
                $alertClass = 'is-danger';
            } else {
                mysqli_stmt_bind_param($stmt, 'si', $hashed, $userId);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $user['app_password'] = $hashed;
                    $_SESSION['profile_message'] = t('profile_app_password_set_success');
                    $_SESSION['profile_alert_class'] = 'is-success';
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit();
                } else {
                    $message = t('profile_app_password_set_failed') . ': ' . mysqli_error($conn);
                    $alertClass = 'is-danger';
                    mysqli_stmt_close($stmt);
                }
            }
        }
    } elseif ($action === 'clear_app_password') {
        $stmt = mysqli_prepare($conn, "UPDATE users SET app_password = NULL WHERE id = ?");
        if ($stmt === false) {
            error_log('profile.php clear_app_password prepare failed: ' . mysqli_error($conn));
            $message = t('profile_app_password_remove_unavailable');
            $alertClass = 'is-danger';
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $user['app_password'] = null;
                $_SESSION['profile_message'] = t('profile_app_password_removed');
                $_SESSION['profile_alert_class'] = 'is-success';
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit();
            } else {
                $message = t('profile_app_password_remove_failed') . ': ' . mysqli_error($conn);
                $alertClass = 'is-danger';
                mysqli_stmt_close($stmt);
            }
        }
    } elseif ($action === 'save_custom_bot') {
        // Handle saving custom bot
        $botName = trim($_POST['bot_username'] ?? '');
        $botId = trim($_POST['bot_channel_id'] ?? '');
        if ($botName === '') {
            $_SESSION['profile_message'] = t('profile_bot_username_required');
            $_SESSION['profile_alert_class'] = 'is-danger';
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        } else {
            // If bot ID not provided, try to resolve via Helix
            if ($botId === '') {
                list($resolvedId, $resolveErr) = resolveTwitchUserId($botName);
                if ($resolvedId === false) {
                    $_SESSION['profile_message'] = $resolveErr;
                    $_SESSION['profile_alert_class'] = 'is-danger';
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit();
                }
                $botId = $resolvedId;
            }
            // Now insert or update into custom_bots table
            $channelId = $userId; // channel which is setting up the custom bot
            // Determine whether an existing custom bot record exists so we only reset verification on changes
            $isVerified = 0; // default for new records or when changed
            $selectSQL = "SELECT bot_username, bot_channel_id, is_verified FROM custom_bots WHERE channel_id = ? LIMIT 1";
            $selStmt = mysqli_prepare($conn, $selectSQL);
            $existingRow = null;
            if ($selStmt) {
                mysqli_stmt_bind_param($selStmt, 'i', $channelId);
                mysqli_stmt_execute($selStmt);
                $res = mysqli_stmt_get_result($selStmt);
                if ($res && ($row = mysqli_fetch_assoc($res))) {
                    $existingRow = $row;
                }
                mysqli_stmt_close($selStmt);
            }
            // If an existing record exists and the username+id did not change, preserve its verified state
            if ($existingRow) {
                $storedName = $existingRow['bot_username'] ?? '';
                $storedId = $existingRow['bot_channel_id'] ?? '';
                $storedVerified = intval($existingRow['is_verified'] ?? 0);
                if (strtolower(trim($storedName)) === strtolower(trim($botName)) && trim($storedId) === trim($botId)) {
                    // No change to bot identity; keep previous verified state
                    $isVerified = $storedVerified;
                } else {
                    // Bot name or id changed - reset verification
                    $isVerified = 0;
                }
            } else {
                // No existing record - default not verified
                $isVerified = 0;
            }
            // Determine whether to UPDATE or INSERT based on whether record exists
            if ($existingRow) {
                // Record exists, do UPDATE
                $updateSQL = "UPDATE custom_bots SET bot_username = ?, bot_channel_id = ?, is_verified = ? WHERE channel_id = ?";
                $stmt = mysqli_prepare($conn, $updateSQL);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ssii', $botName, $botId, $isVerified, $channelId);
                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION['profile_message'] = t('custom_bot_updated_success');
                        $_SESSION['profile_alert_class'] = 'is-success';
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit();
                    } else {
                        $_SESSION['profile_message'] = t('custom_bot_save_error') . ': ' . mysqli_error($conn);
                        $_SESSION['profile_alert_class'] = 'is-danger';
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit();
                    }
                } else {
                    $_SESSION['profile_message'] = t('custom_bot_save_error') . ': ' . mysqli_error($conn);
                    $_SESSION['profile_alert_class'] = 'is-danger';
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit();
                }
            } else {
                // No existing record, do INSERT
                $insertSQL = "INSERT INTO custom_bots (channel_id, bot_username, bot_channel_id, is_verified, access_token, token_expires, refresh_token) VALUES (?, ?, ?, ?, '', NULL, NULL)";
                $stmt = mysqli_prepare($conn, $insertSQL);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'issi', $channelId, $botName, $botId, $isVerified);
                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION['profile_message'] = t('custom_bot_saved_success');
                        $_SESSION['profile_alert_class'] = 'is-success';
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit();
                    } else {
                        $_SESSION['profile_message'] = t('custom_bot_save_error') . ': ' . mysqli_error($conn);
                        $_SESSION['profile_alert_class'] = 'is-danger';
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit();
                    }
                } else {
                    $_SESSION['profile_message'] = t('custom_bot_save_error') . ': ' . mysqli_error($conn);
                    $_SESSION['profile_alert_class'] = 'is-danger';
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit();
                }
            }
        }
    }
}

// Cheap shell data only — storage scan and link-status curl stay on ajax_action=list.
$profileData = [];
$heartrateCode = null;
$profileStmt = $db->prepare('SELECT timezone, weather_location, heartrate_code FROM profile LIMIT 1');
if ($profileStmt) {
    $profileStmt->execute();
    $profileResult = $profileStmt->get_result();
    if ($profileRow = $profileResult->fetch_assoc()) {
        $profileData = $profileRow;
        $heartrateCode = $profileRow['heartrate_code'] ?? null;
    }
    $profileStmt->close();
}

$userTimezone = !empty($profileData['timezone']) ? $profileData['timezone'] : 'UTC';
date_default_timezone_set($userTimezone);
$joinedFormatted = isset($user['signup_date']) ? formatUserDate($user['signup_date'], $userTimezone) : t('profile_unknown');
$lastLoginFormatted = isset($user['last_login']) ? formatUserDate($user['last_login'], $userTimezone) : t('profile_unknown');

$timezoneOptions = DateTimeZone::listIdentifiers();

// Dashboard languages shown in the profile dropdown.
// Codes are stored uppercase in users.language; lang files live at lang/{lowercase}.php.
$defaultLanguages = [
    'EN' => 'English',
    'FR' => 'French',
    'DE' => 'German',
    'ES' => 'Spanish',
    'ZH' => 'Mandarin',
];
$languages = [];
$seenCodes = [];
$langQuery = "SELECT name, code FROM languages";
$langResult = $conn->query($langQuery);
if ($langResult && $langResult->num_rows > 0) {
    while ($row = $langResult->fetch_assoc()) {
        $code = strtoupper(trim((string)($row['code'] ?? '')));
        if ($code === '' || isset($seenCodes[$code])) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '' && isset($defaultLanguages[$code])) {
            $name = $defaultLanguages[$code];
        }
        if ($name === '') {
            $name = $code;
        }
        $languages[] = ['name' => $name, 'code' => $code];
        $seenCodes[$code] = true;
    }
}
// Ensure every shipped language file is selectable even if the languages table is stale.
foreach ($defaultLanguages as $code => $name) {
    if (!isset($seenCodes[$code])) {
        $languages[] = ['name' => $name, 'code' => $code];
        $seenCodes[$code] = true;
        // Best-effort seed so admin/other UIs reading the languages table stay in sync.
        $seedStmt = $conn->prepare("INSERT INTO languages (name, code) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM languages WHERE code = ?)");
        if ($seedStmt) {
            $seedStmt->bind_param('sss', $name, $code, $code);
            $seedStmt->execute();
            $seedStmt->close();
        }
    }
}

// Start output buffering for layout
ob_start();
?>
<?php if ($message): ?>
<div class="sp-alert <?php echo str_replace(['is-success','is-danger','is-warning','is-info'],['sp-alert-success','sp-alert-danger','sp-alert-warning','sp-alert-info'],$alertClass); ?>">
    <?php echo $message; ?>
</div>
<?php endif; ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <div style="grid-column:1/-1;">
        <div class="sp-card" id="general">
            <div class="sp-card-header">
                <h2 class="sp-card-title"><?php echo t('profile_title'); ?></h2>
            </div>
            <div class="sp-card-body">
                <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:1.5rem;align-items:start;">
                    <div style="flex:0 0 auto;text-align:center;">
                        <img style="width:120px;height:120px;object-fit:cover;border-radius:50%;display:block;" src="<?php echo htmlspecialchars($profileImageUrl, ENT_QUOTES); ?>" alt="Profile">
                    </div>
                    <div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('username'); ?></label>
                            <input class="sp-input" type="text" value="<?php echo $_SESSION['username']; ?>" disabled>
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('twitch_username_help'); ?></p>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('display_name'); ?></label>
                            <input class="sp-input" type="text" value="<?php echo $_SESSION['display_name'] ?? $_SESSION['username']; ?>" disabled>
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('twitch_display_name_help'); ?></p>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('you_joined'); ?></label>
                            <input class="sp-input" type="text" value="<?php echo $joinedFormatted; ?>" disabled>
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('joined_help'); ?></p>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('last_login'); ?></label>
                            <input class="sp-input" type="text" value="<?php echo $lastLoginFormatted; ?>" disabled>
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('last_login_help'); ?></p>
                        </div>
                    </div>
                    <div>
                        <div class="sp-form-group">
                            <form method="post" action="" id="technical-mode-form" style="margin-bottom:0;">
                                <input type="hidden" name="action" value="update_technical_mode">
                                <label class="sp-label" for="is-technical-select"><?php echo t('technical_terms'); ?></label>
                                <select name="is_technical" id="is-technical-select" class="sp-select" onchange="document.getElementById('technical-mode-form').submit();">
                                    <option value="1" <?php echo $isTechnical ? 'selected' : ''; ?>><?php echo t('yes'); ?></option>
                                    <option value="0" <?php echo !$isTechnical ? 'selected' : ''; ?>><?php echo t('no'); ?></option>
                                </select>
                                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('technical_help'); ?></p>
                            </form>
                        </div>
                        <div class="sp-form-group">
                            <form method="post" action="" id="language-form" style="margin-bottom:0;">
                                <input type="hidden" name="action" value="update_language">
                                <label class="sp-label" for="language-select"><?php echo t('language'); ?></label>
                                <select name="language" id="language-select" class="sp-select" onchange="document.getElementById('language-form').submit();">
                                    <?php foreach ($languages as $lang): ?>
                                        <option value="<?php echo htmlspecialchars($lang['code']); ?>" <?php if ($userLanguage === $lang['code']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($lang['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('language_help'); ?></p>
                            </form>
                        </div>
                        <div>
                            <button id="export-data-profile-btn" class="sp-btn sp-btn-warning" type="button" data-user-id="<?php echo $userId; ?>" data-user-email="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES); ?>" data-user-username="<?php echo htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES); ?>" onclick="exportProfileData()"><?php echo t('profile_export_my_data'); ?></button>
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('profile_export_help'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="cookie-consent-info">
        <div class="sp-card" style="height:100%;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <i class="fas fa-cookie-bite fa-2x" style="color:var(--amber);"></i>
                    <div>
                        <h3 class="sp-card-title" style="margin:0;"><?php echo t('cookie_consent_title'); ?></h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;"><?php echo t('cookie_consent_help'); ?></p>
                    </div>
                </div>
            </div>
            <div class="sp-card-body">
                <?php
                $cookieConsent = isset($_COOKIE['cookie_consent']) ? $_COOKIE['cookie_consent'] : null;
                $tagHtml = '';
                if ($cookieConsent === 'accepted') {
                    $tagHtml = '<span class="sp-badge sp-badge-green">' . t('cookie_accepted') . '</span>';
                    $infoText = t('cookie_accepted_info');
                } elseif ($cookieConsent === 'declined') {
                    $tagHtml = '<span class="sp-badge sp-badge-red">' . t('cookie_declined') . '</span>';
                    $infoText = t('cookie_declined_info');
                } else {
                    $tagHtml = '<span class="sp-badge sp-badge-amber">' . t('cookie_not_set') . '</span>';
                    $infoText = t('cookie_not_set_info');
                }
                $cookieBtnLabel = ($cookieConsent === 'declined') ? t('cookie_accept_btn') : t('cookie_decline_btn');
                $cookieBtnId = ($cookieConsent === 'declined') ? 'accept-cookies-btn' : 'decline-cookies-btn';
                $cookieBtnClass = ($cookieConsent === 'declined') ? 'sp-btn sp-btn-success sp-btn-sm' : 'sp-btn sp-btn-danger sp-btn-sm';
                ?>
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                    <?php echo $tagHtml; ?>
                    <span><?php echo $infoText; ?></span>
                </div>
                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('cookie_change_help'); ?></p>
                <button class="<?php echo $cookieBtnClass; ?>" id="<?php echo $cookieBtnId; ?>" type="button" style="margin-top:0.5rem;">
                    <?php echo $cookieBtnLabel; ?>
                </button>
            </div>
        </div>
    </div>
    <div id="timezone-box">
        <div class="sp-card" style="height:100%;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <i class="fas fa-globe fa-2x" style="color:var(--blue);"></i>
                    <div>
                        <h3 class="sp-card-title" style="margin:0;"><?php echo t('timezone_title'); ?></h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;"><?php echo t('timezone_help'); ?></p>
                    </div>
                </div>
            </div>
            <div class="sp-card-body">
                <form method="post" action="" id="timezone-form">
                    <input type="hidden" name="action" value="update_timezone">
                    <div class="sp-form-group">
                        <select name="timezone" class="sp-select">
                            <?php foreach ($timezoneOptions as $tz): ?>
                                <option value="<?php echo $tz; ?>" <?php echo ($profileData['timezone'] ?? 'UTC') === $tz ? 'selected' : ''; ?>>
                                    <?php echo $tz; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sp-form-group">
                        <button type="submit" class="sp-btn sp-btn-primary" style="width:100%;"><?php echo t('save_timezone'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div id="weather-location-box">
        <div class="sp-card" style="height:100%;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <i class="fas fa-cloud-sun fa-2x" style="color:var(--blue);"></i>
                    <div>
                        <h3 class="sp-card-title" style="margin:0;"><?php echo t('weather_location_title'); ?></h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;"><?php echo t('weather_location_help'); ?></p>
                    </div>
                </div>
            </div>
            <div class="sp-card-body">
                <form method="post" action="" id="weather-form">
                    <input type="hidden" name="action" value="update_weather_location">
                    <div class="sp-form-group" style="position:relative;">
                        <input class="sp-input" type="text" name="weather_location" id="weather-location-input" value="<?php echo $profileData['weather_location'] ?? ''; ?>" style="padding-right:2.5rem;">
                        <span id="weather-location-status" style="display:none;position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);"></span>
                    </div>
                    <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;" id="weather-location-help"><?php echo t('weather_location_input_help'); ?></p>
                    <div class="sp-form-group" style="margin-top:0.75rem;">
                        <button type="submit" class="sp-btn sp-btn-primary" style="width:100%;"><?php echo t('save_weather_location'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div id="api">
        <div class="sp-card" style="height:100%;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <i class="fas fa-key fa-2x" style="color:var(--accent);"></i>
                    <div>
                        <h3 class="sp-card-title" style="margin:0;"><?php echo t('api_access_title'); ?></h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;"><?php echo t('api_access_help'); ?></p>
                    </div>
                </div>
            </div>
            <div class="sp-card-body">
                <div class="sp-form-group">
                    <label class="sp-label"><?php echo t('api_key_label'); ?></label>
                    <div style="display:flex;gap:0.5rem;">
                        <input class="sp-input" type="text" name="api_key_display" value="<?php echo $user['api_key'] ?? ''; ?>" id="api-key-field" readonly autocomplete="off" form="regenerate-api-key-form">
                        <button class="sp-btn sp-btn-secondary" onclick="copyApiKey()" title="<?php echo t('copy_api_key'); ?>" id="copy-api-key-btn" style="flex:0 0 auto;width:2.5rem;height:2.5rem;padding:0;">
                            <i class="fas fa-copy" id="copy-icon"></i>
                        </button>
                        <button class="sp-btn sp-btn-secondary" onclick="toggleApiKeyVisibility()" title="<?php echo t('show_hide_api_key'); ?>" style="flex:0 0 auto;width:2.5rem;height:2.5rem;padding:0;">
                            <i class="fas fa-eye" id="visibility-icon"></i>
                        </button>
                        <form method="post" action="" id="regenerate-api-key-form" style="margin:0;">
                            <input type="hidden" name="action" value="regenerate_api_key">
                            <button type="button" class="sp-btn sp-btn-warning" id="regenerate-api-key-btn" title="<?php echo t('regenerate_api_key'); ?>" style="width:2.5rem;height:2.5rem;padding:0;">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="storage-usage" aria-busy="true">
        <div class="sp-card" style="height:100%;display:flex;flex-direction:column;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <i class="fas fa-database fa-2x" style="color:var(--blue);"></i>
                        <div>
                            <h3 class="sp-card-title" style="margin:0;"><?php echo t('storage_usage_title'); ?></h3>
                            <p style="font-size:0.85rem;color:var(--text-muted);margin:0;"><?php echo t('storage_usage_help'); ?></p>
                        </div>
                    </div>
                    <div id="storage-tier-badges" style="display:inline-flex;gap:0.25rem;flex-wrap:wrap;">
                        <span class="sp-skeleton-badge" aria-hidden="true"></span>
                    </div>
                </div>
            </div>
            <div class="sp-card-body" id="storage-meter-body" style="flex:1;">
                <div class="sp-skeleton-stack" aria-hidden="true">
                    <span class="sp-skeleton-line w-50"></span>
                    <span class="sp-skeleton-line w-90"></span>
                    <span class="sp-skeleton-line w-70"></span>
                </div>
            </div>
        </div>
    </div>
    <div id="heartrate">
        <div class="sp-card" style="height:100%;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <i class="fas fa-heartbeat fa-2x" style="color:var(--red);"></i>
                    <div>
                        <h3 class="sp-card-title" style="margin:0;"><?php echo t('heartrate_code_title'); ?></h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;">
                            <?php echo t('heartrate_code_help'); ?>
                            <a href="https://www.hyperate.io/" target="_blank" rel="noopener noreferrer">HypeRate.io</a>
                        </p>
                    </div>
                </div>
            </div>
            <div class="sp-card-body">
                <form method="post" action="" id="heartrate-form" style="margin-bottom:1em;">
                    <input type="hidden" name="action" value="update_heartrate_code">
                    <div class="sp-form-group" style="display:flex;gap:0;">
                        <input class="sp-input" type="text" name="heartrate_code" value="<?php echo htmlspecialchars($heartrateCode ?? '', ENT_QUOTES); ?>" placeholder="<?php echo t('heartrate_code_placeholder'); ?>" style="border-radius:var(--radius) 0 0 var(--radius);">
                        <button type="submit" class="sp-btn sp-btn-primary" style="border-radius:0 var(--radius) var(--radius) 0;white-space:nowrap;"><?php echo t('heartrate_save'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div style="grid-column:1/-1;" id="connections" aria-busy="true">
        <div class="sp-card">
            <div class="sp-card-header">
                <h2 class="sp-card-title"><?php echo t('connected_accounts_title'); ?></h2>
            </div>
            <div class="sp-card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;">
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/idIwZCwD2f/theme/dark/symbol.svg?c=1bxid64Mup7aczewSAYMX&t=1668070397594" alt="Twitch" style="width:2.5em;height:2.5em;object-fit:contain;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('twitch'); ?></p>
                            </div>
                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" style="width:100%;" onclick="disconnectTwitch()">
                                <i class="fas fa-sign-out-alt"></i>
                                <span><?php echo t('logout'); ?></span>
                            </button>
                        </div>
                    </div>
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/idM8Hlme1a/theme/dark/symbol.svg?c=1bxid64Mup7aczewSAYMX&t=1668075051777" alt="Discord" style="width:2.5em;height:2.5em;object-fit:contain;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('discord'); ?></p>
                            </div>
                            <div id="discord-link-action" aria-busy="true">
                                <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/id20mQyGeY/theme/dark/symbol.svg?c=1bxid64Mup7aczewSAYMX&t=1737597212873" alt="Spotify" style="width:2.5em;height:2.5em;object-fit:contain;border-radius:50%;background:#222;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('spotify'); ?></p>
                            </div>
                            <div id="spotify-link-action" aria-busy="true">
                                <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/idj4DI2QBL/w/400/h/400/theme/dark/icon.png?c=1dxbfHSJFAPEGdCLU4o5B" alt="StreamElements" style="width:2.5em;height:2.5em;object-fit:cover;border-radius:50%;background:#222;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('streamelements'); ?></p>
                            </div>
                            <div id="streamelements-link-action" aria-busy="true">
                                <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/idIDKnQFO2/w/400/h/400/theme/dark/icon.jpeg?c=1bxid64Mup7aczewSAYMX&t=1767309079648" alt="StreamLabs" style="width:2.5em;height:2.5em;object-fit:cover;border-radius:50%;background:#222;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('streamlabs'); ?></p>
                            </div>
                            <div id="streamlabs-link-action" aria-busy="true">
                                <span class="sp-skeleton-line w-80" aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/id3gkQXO6j/w/400/h/400/theme/dark/icon.jpeg" alt="Kick" style="width:2.5em;height:2.5em;object-fit:cover;border-radius:50%;background:#222;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('kick'); ?></p>
                            </div>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" style="width:100%;" disabled>
                                <i class="fas fa-clock"></i>
                                <span><?php echo t('coming_soon'); ?></span>
                            </button>
                        </div>
                    </div>
                    <div class="sp-card" style="margin-bottom:0;">
                        <div class="sp-card-body" style="display:flex;flex-direction:column;gap:0.75rem;padding:0.75rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <span style="width:2.5em;height:2.5em;display:flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">
                                    <img src="https://cdn.brandfetch.io/idVfYwcuQz/theme/dark/symbol.svg?c=1bxid64Mup7aczewSAYMX&t=1728452988041" alt="YouTube" style="width:2.5em;height:2.5em;object-fit:contain;display:block;">
                                </span>
                                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:0;"><?php echo t('youtube'); ?></p>
                            </div>
                            <button type="button" class="sp-btn sp-btn-warning sp-btn-sm" style="width:100%;" disabled>
                                <i class="fas fa-clock"></i>
                                <span><?php echo t('coming_soon'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="app-password">
        <div class="sp-card" style="height:100%;">
            <div class="sp-card-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <i class="fas fa-mobile-alt fa-2x" style="color:var(--accent);"></i>
                    <div>
                        <h3 class="sp-card-title" style="margin:0;"><?php echo t('profile_app_password_title'); ?></h3>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin:0;"><?php echo t('profile_app_password_help'); ?></p>
                    </div>
                </div>
            </div>
            <div class="sp-card-body">
                <?php $appPasswordSet = !empty($user['app_password']); ?>
                <div style="margin-bottom:1rem;">
                    <?php if ($appPasswordSet): ?>
                        <span class="sp-badge sp-badge-green"><i class="fas fa-lock" style="margin-right:0.25rem;"></i><?php echo t('profile_app_password_set'); ?></span>
                    <?php else: ?>
                        <span class="sp-badge sp-badge-amber"><i class="fas fa-lock-open" style="margin-right:0.25rem;"></i><?php echo t('profile_app_password_not_set'); ?></span>
                    <?php endif; ?>
                </div>
                <form method="post" action="" id="app-password-form">
                    <input type="hidden" name="action" value="set_app_password">
                    <div class="sp-form-group">
                        <label class="sp-label"><?php echo $appPasswordSet ? t('profile_new_password') : t('profile_password'); ?></label>
                        <input class="sp-input" type="password" name="app_password" id="app-password-input" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('profile_enter_app_password')); ?>" required minlength="6">
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label"><?php echo t('profile_confirm_password'); ?></label>
                        <input class="sp-input" type="password" name="app_password_confirm" id="app-password-confirm" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('profile_confirm_app_password')); ?>" required minlength="6">
                    </div>
                    <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
                        <button type="submit" class="sp-btn sp-btn-primary"><?php echo $appPasswordSet ? t('profile_update_password') : t('profile_set_password'); ?></button>
                        <?php if ($appPasswordSet): ?>
                        <button type="button" class="sp-btn sp-btn-danger" id="clear-app-password-btn"><?php echo t('profile_remove_password'); ?></button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($appPasswordSet): ?>
                <form method="post" action="" id="clear-app-password-form" style="display:none;">
                    <input type="hidden" name="action" value="clear_app_password">
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div id="custom-bot">
        <div class="sp-card">
            <div class="sp-card-header">
                <h2 class="sp-card-title"><?php echo t('profile_custom_bot_title'); ?> <span class="sp-badge sp-badge-amber" style="margin-left:0.5rem;">Beta 5.8</span></h2>
            </div>
            <div class="sp-card-body">
                <p style="margin-bottom:1rem;"><?php echo t('profile_custom_bot_intro'); ?></p>
                <?php
                // Load existing custom bot for this channel if present (include verification state)
                $existingBot = null;
                $cbStmt = $conn->prepare("SELECT bot_username, bot_channel_id, is_verified FROM custom_bots WHERE channel_id = ? LIMIT 1");
                if ($cbStmt) {
                    $cbStmt->bind_param('i', $userId);
                    $cbStmt->execute();
                    $cbRes = $cbStmt->get_result();
                    if ($row = $cbRes->fetch_assoc()) {
                        $existingBot = $row;
                    }
                }
                ?>
                <form method="post" id="custom-bot-form" style="margin-top:1rem;">
                    <input type="hidden" name="action" value="save_custom_bot">
                    <div style="margin-bottom:0.5rem;">
                        <?php if (isset($existingBot['is_verified']) && intval($existingBot['is_verified']) !== 1): ?>
                            <span class="sp-badge sp-badge-red"><?php echo t('profile_custom_bot_not_verified'); ?></span>
                            <div style="margin-left:0.5rem;margin-top:0.5rem;font-size:0.9rem;color:var(--text-secondary);">
                                <p><?php echo t('profile_custom_bot_verify_heading'); ?></p>
                                <ol style="margin-left:1.5rem;margin-top:0.5rem;">
                                    <li><?php echo t('profile_custom_bot_verify_step1'); ?></li>
                                    <li><?php echo t('profile_custom_bot_verify_step2'); ?></li>
                                    <li><?php echo t('profile_custom_bot_verify_step3'); ?></li>
                                </ol>
                                <p style="margin-top:0.5rem;"><?php echo t('profile_custom_bot_verify_note'); ?></p>
                            </div>
                        <?php elseif (isset($existingBot['is_verified']) && intval($existingBot['is_verified']) === 1): ?>
                            <span class="sp-badge sp-badge-green"><?php echo t('profile_custom_bot_verified'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="sp-form-group" style="position:relative;">
                        <label class="sp-label"><?php echo t('profile_custom_bot_name_label'); ?></label>
                        <input class="sp-input" type="text" name="bot_username" id="bot-username" value="<?php echo htmlspecialchars($existingBot['bot_username'] ?? '', ENT_QUOTES); ?>" required style="padding-right:2.5rem;">
                        <span id="bot-lookup-status" style="display:none;position:absolute;right:0.75rem;top:calc(50% + 0.75rem);transform:translateY(-50%);"></span>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('profile_custom_bot_name_help'); ?></p>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label"><?php echo t('profile_custom_bot_id_label'); ?></label>
                        <input class="sp-input" type="text" name="bot_channel_id" id="bot-id" value="<?php echo htmlspecialchars($existingBot['bot_channel_id'] ?? '', ENT_QUOTES); ?>" readonly>
                        <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;"><?php echo t('profile_custom_bot_id_help'); ?></p>
                    </div>
                    <div style="display:flex;gap:0.5rem;margin-top:1rem;">
                        <button type="submit" class="sp-btn sp-btn-primary"><?php echo t('profile_custom_bot_save'); ?></button>
                        <button type="button" class="sp-btn sp-btn-secondary" id="resolve-bot-btn"><?php echo t('profile_custom_bot_resolve_id'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
var PROFILE_I18N = {
    percentUsed: <?php echo json_encode(t('percent_used')); ?>,
    storageLimit: <?php echo json_encode(t('storage_limit_reached')); ?>,
    storageAlmostFull: <?php echo json_encode(t('storage_almost_full')); ?>,
    disconnect: <?php echo json_encode(t('disconnect')); ?>,
    connect: <?php echo json_encode(t('connect')); ?>
};

function profileLinkActionHtml(linked, disconnectFn, connectHref) {
    if (linked) {
        return '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm" style="width:100%;" onclick="' + disconnectFn + '()">' +
            '<i class="fas fa-unlink"></i><span>' + PROFILE_I18N.disconnect + '</span></button>';
    }
    return '<a href="' + connectHref + '" class="sp-btn sp-btn-secondary sp-btn-sm" style="width:100%;text-align:center;">' +
        '<i class="fas fa-link"></i><span>' + PROFILE_I18N.connect + '</span></a>';
}

function profileTierBadgeHtml(tier, betaAccess) {
    var html = '';
    if (tier && tier !== 'None' && ['1000', '2000', '3000'].indexOf(tier) !== -1) {
        var labels = { '1000': 'Tier 1', '2000': 'Tier 2', '3000': 'Tier 3' };
        var classes = { '1000': 'sp-badge sp-badge-blue', '2000': 'sp-badge sp-badge-amber', '3000': 'sp-badge sp-badge-red' };
        html += '<span class="' + classes[tier] + '"><i class="fas fa-crown" style="margin-right:0.25rem;"></i>' + labels[tier] + '</span>';
    }
    if (betaAccess) {
        html += '<span class="sp-badge sp-badge-accent"><i class="fas fa-flask" style="margin-right:0.25rem;"></i>Beta</span>';
    }
    return html;
}

function profileRenderStorage(storage) {
    var host = document.getElementById('storage-usage');
    var body = document.getElementById('storage-meter-body');
    var badges = document.getElementById('storage-tier-badges');
    if (badges) {
        badges.innerHTML = profileTierBadgeHtml(storage.tier, !!storage.beta_access);
    }
    if (!body) {
        if (host) host.setAttribute('aria-busy', 'false');
        return;
    }
    var percent = typeof storage.percent === 'number' ? storage.percent : 0;
    var barColor = percent >= 90 ? 'var(--red)' : (percent >= 70 ? 'var(--amber)' : 'var(--accent)');
    var textColor = percent >= 90 ? 'var(--red)' : (percent >= 70 ? 'var(--amber)' : 'var(--green)');
    var minWidth = (percent > 0 && percent < 1) ? 'min-width:4px;' : '';
    var alertHtml = '';
    if (percent >= 100) {
        alertHtml = '<div class="sp-alert sp-alert-danger" style="margin-top:1rem;margin-bottom:0;"><strong>' + PROFILE_I18N.storageLimit + '</strong></div>';
    } else if (percent >= 90) {
        alertHtml = '<div class="sp-alert sp-alert-warning" style="margin-top:1rem;margin-bottom:0;"><strong>' + PROFILE_I18N.storageAlmostFull + '</strong></div>';
    }
    body.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:0.75rem;">' +
            '<span><strong>' + (storage.used_formatted || '') + '</strong>' +
            '<span style="color:var(--text-muted);"> / ' + (storage.max_formatted || '') + '</span></span>' +
            '<span style="font-weight:600;color:' + textColor + ';">' + Number(percent).toFixed(2) + PROFILE_I18N.percentUsed + '</span>' +
        '</div>' +
        '<div style="position:relative;height:10px;border-radius:100px;background:var(--border);overflow:hidden;margin-bottom:0.5rem;">' +
            '<div style="position:absolute;top:0;left:0;height:100%;width:' + percent + '%;border-radius:100px;background:' + barColor + ';transition:width 0.4s ease;' + minWidth + '"></div>' +
        '</div>' +
        '<div style="display:flex;justify-content:space-between;font-size:0.75rem;color:var(--text-muted);">' +
            '<span>0%</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span>' +
        '</div>' +
        alertHtml;
    if (host) host.setAttribute('aria-busy', 'false');
}

function profileRenderLinks(links) {
    var map = {
        discord: { id: 'discord-link-action', fn: 'disconnectDiscord', href: 'discordbot.php' },
        spotify: { id: 'spotify-link-action', fn: 'disconnectSpotify', href: 'spotifylink.php' },
        streamelements: { id: 'streamelements-link-action', fn: 'disconnectStreamelements', href: 'streamelements.php' },
        streamlabs: { id: 'streamlabs-link-action', fn: 'disconnectStreamlabs', href: 'streamlabs.php' }
    };
    Object.keys(map).forEach(function(key) {
        var el = document.getElementById(map[key].id);
        if (!el) return;
        el.innerHTML = profileLinkActionHtml(!!(links && links[key]), map[key].fn, map[key].href);
        el.setAttribute('aria-busy', 'false');
    });
    var host = document.getElementById('connections');
    if (host) host.setAttribute('aria-busy', 'false');
}

function loadProfileList() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) throw new Error('bad');
            profileRenderStorage(data.storage || {});
            profileRenderLinks(data.links || {});
        })
        .catch(function() {
            var storageHost = document.getElementById('storage-usage');
            var storageBody = document.getElementById('storage-meter-body');
            if (storageBody) storageBody.innerHTML = '';
            if (storageHost) storageHost.setAttribute('aria-busy', 'false');
            ['discord-link-action', 'spotify-link-action', 'streamelements-link-action', 'streamlabs-link-action'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.setAttribute('aria-busy', 'false');
            });
            var connHost = document.getElementById('connections');
            if (connHost) connHost.setAttribute('aria-busy', 'false');
        });
}

(function(){
    const resolveBtn = document.getElementById('resolve-bot-btn');
    const usernameInput = document.getElementById('bot-username');
    const idField = document.getElementById('bot-id');
    const status = document.getElementById('bot-lookup-status');
    function setStatus(html, cls) {
        if (!status) return;
        status.style.display = html ? '' : 'none';
        status.innerHTML = html || '';
        status.className = cls || '';
    }
    resolveBtn && resolveBtn.addEventListener('click', function(e){
        const name = usernameInput.value.trim();
        if (!name) {
            setStatus('<i class="fas fa-exclamation-triangle" style="color:var(--red);"></i>', '');
            return;
        }
        setStatus('<i class="fas fa-spinner fa-spin"></i>','');
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'resolve_bot_id', bot_username: name})
        }).then(r=>r.json()).then(j=>{
            if (j && j.success) {
                idField.value = j.bot_id || '';
                setStatus('<i class="fas fa-check" style="color:var(--green);"></i>','');
            } else {
                setStatus('<i class="fas fa-times" style="color:var(--red);"></i>','');
                alert(j.error || <?php echo json_encode(t('profile_resolve_bot_failed')); ?>);
            }
        }).catch(err=>{
            setStatus('<i class="fas fa-times" style="color:var(--red);"></i>','');
            console.error(err);
            alert(<?php echo json_encode(t('profile_resolve_bot_error')); ?>);
        });
    });
})();

function copyApiKey() {
    const apiKeyField = document.getElementById('api-key-field');
    const copyBtn = document.getElementById('copy-api-key-btn');
    // Try modern clipboard API first, fall back to older method
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(apiKeyField.value).then(() => {
            showCopyNotification();
            showCopyFeedback();
        }).catch(err => {
            console.error('Failed to copy: ', err);
            fallbackCopy();
        });
    } else {
        fallbackCopy();
    }
    function fallbackCopy() {
        // Create a temporary textarea to copy the text
        const tempTextarea = document.createElement('textarea');
        tempTextarea.value = apiKeyField.value;
        tempTextarea.style.position = 'fixed';
        tempTextarea.style.left = '-999999px';
        tempTextarea.style.top = '-999999px';
        document.body.appendChild(tempTextarea);
        tempTextarea.focus();
        tempTextarea.select();
        try {
            document.execCommand('copy');
            showCopyNotification();
            showCopyFeedback();
        } catch (err) {
            console.error('Fallback copy failed: ', err);
        }
        
        document.body.removeChild(tempTextarea);
    }
    function showCopyFeedback() {
        // Visual feedback on button
        const copyIcon = document.getElementById('copy-icon');
        copyIcon.classList.remove('fa-copy');
        copyIcon.classList.add('fa-check');
        copyBtn.classList.add('sp-btn-success');
        setTimeout(() => {
            copyIcon.classList.remove('fa-check');
            copyIcon.classList.add('fa-copy');
            copyBtn.classList.remove('sp-btn-success');
        }, 2000);
    }
}

function showCopyNotification() {
    // Remove any existing notifications
    const existingNotifications = document.querySelectorAll('.copy-notification');
    existingNotifications.forEach(notification => notification.remove());
    // Create notification
    const notification = document.createElement('div');
    notification.className = 'sp-alert sp-alert-success copy-notification';
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideInRight 0.3s ease-out; cursor:pointer;';
    notification.innerHTML = `<strong><i class="fas fa-check-circle" style="margin-right:0.5rem;"></i><?php echo t('api_key_copied'); ?></strong>`;
    notification.onclick = function() { this.remove(); };
    document.body.appendChild(notification);
    // Auto-remove after 3 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 300);
        }
    }, 3000);
}

function toggleApiKeyVisibility() {
    const apiKeyField = document.getElementById('api-key-field');
    const visibilityIcon = document.getElementById('visibility-icon');
    const copyBtn = document.getElementById('copy-api-key-btn');
    if (apiKeyField.type === 'password') {
        Swal.fire({
            title: <?php echo json_encode(t('sensitive_info_title')); ?>,
            text: <?php echo json_encode(t('sensitive_info_text')); ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: <?php echo json_encode(t('show_api_key')); ?>,
            cancelButtonText: <?php echo json_encode(t('cancel')); ?>
        }).then((result) => {
            if (result.isConfirmed) {
                apiKeyField.type = 'text';
                visibilityIcon.classList.remove('fa-eye');
                visibilityIcon.classList.add('fa-eye-slash');
            }
        });
    } else {
        apiKeyField.type = 'password';
        visibilityIcon.classList.remove('fa-eye-slash');
        visibilityIcon.classList.add('fa-eye');
    }
}

// Show processing overlay when form is submitted
function showProcessingOverlay(message) {
    message = message || '<?php echo t('processing'); ?>';
    const overlay = document.createElement('div');
    overlay.id = 'processing-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(3px);
    `;
    overlay.innerHTML = `
        <div style="
            background: white;
            padding: 2rem 3rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        ">
            <div class="spinner" style="
                border: 4px solid #f3f3f3;
                border-top: 4px solid #3273dc;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 0 auto 1rem;
            "></div>
            <p style="font-size: 1.2rem; font-weight: 600; color: #363636; margin: 0;">${message}</p>
        </div>
    `;
    // Add spinner animation
    const style = document.createElement('style');
    style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
    document.head.appendChild(style);
    document.body.appendChild(overlay);
}

document.addEventListener('DOMContentLoaded', function() {
    loadProfileList();
    // Add processing indicator to forms that trigger page reload
    const formsWithReload = [
        { id: 'timezone-form', message: '<?php echo t('processing'); ?>...' },
        { id: 'weather-form', message: '<?php echo t('processing'); ?>...' },
        { id: 'language-form', message: '<?php echo t('processing'); ?>...' },
        { id: 'technical-mode-form', message: '<?php echo t('processing'); ?>...' },
        { id: 'heartrate-form', message: '<?php echo t('processing'); ?>...' },
        { id: 'custom-bot-form', message: '<?php echo t('processing'); ?>...' }
    ];
    formsWithReload.push({ id: 'app-password-form', message: '<?php echo t('processing'); ?>...' });
    formsWithReload.forEach(formConfig => {
        const form = document.getElementById(formConfig.id);
        if (form) {
            form.addEventListener('submit', function(e) {
                // Don't prevent default - let the form submit normally
                showProcessingOverlay(formConfig.message);
            });
        }
    });
    const apiKeyField = document.getElementById('api-key-field');
    if (apiKeyField) {
        apiKeyField.type = 'password';
    }

    const regenBtn = document.getElementById('regenerate-api-key-btn');
    if (regenBtn) {
        regenBtn.addEventListener('click', function(e) {
            Swal.fire({
                title: <?php echo json_encode(t('regenerate_api_key_title')); ?>,
                text: <?php echo json_encode(t('regenerate_api_key_text')); ?>,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: <?php echo json_encode(t('yes_regenerate')); ?>,
                cancelButtonText: <?php echo json_encode(t('cancel')); ?>
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('regenerate-api-key-form').submit();
                }
            });
        });
    }

    // App password - remove confirmation
    const clearAppPasswordBtn = document.getElementById('clear-app-password-btn');
    if (clearAppPasswordBtn) {
        clearAppPasswordBtn.addEventListener('click', function() {
            Swal.fire({
                title: <?php echo json_encode(t('profile_remove_app_password_title')); ?>,
                text: <?php echo json_encode(t('profile_remove_app_password_text')); ?>,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: <?php echo json_encode(t('profile_yes_remove_it')); ?>,
                cancelButtonText: <?php echo json_encode(t('cancel')); ?>,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    showProcessingOverlay(<?php echo json_encode(t('profile_removing_app_password')); ?>);
                    document.getElementById('clear-app-password-form').submit();
                }
            });
        });
    }

    // Weather location validation
    const weatherInput = document.getElementById('weather-location-input');
    const statusIcon = document.getElementById('weather-location-status');
    const helpText = document.getElementById('weather-location-help');
    const apiKey = <?php echo json_encode($user['api_key'] ?? ''); ?>;
    let weatherTimeout = null;
    // Helper to get cookie value by name
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }
    // Helper to set cookie
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + (days*24*60*60*1000));
        document.cookie = name + "=" + encodeURIComponent(value) + ";expires=" + d.toUTCString() + ";path=/";
    }
    // Check if user has accepted cookies
    function hasCookieConsent() {
        return getCookie('cookie_consent') === 'accepted';
    }
    function validateWeatherLocation() {
        const location = weatherInput.value.trim().replace(/\s+/g, ',');
        statusIcon.style.display = 'none';
        helpText.textContent = <?php echo json_encode(t('weather_location_input_help')); ?>;
        if (!location) return;
        // If user accepted cookies, check for cached validation
        const cached = hasCookieConsent() ? getCookie('weather_location_valid') : null;
        if (cached) {
            try {
                const cachedObj = JSON.parse(cached);
                if (cachedObj.location === location) {
                    // Use cached result
                    if (cachedObj.valid) {
                        statusIcon.innerHTML = '<i class="fas fa-check" style="color:var(--green);"></i>';
                        helpText.textContent = cachedObj.message || <?php echo json_encode(t('location_is_valid')); ?>;
                    } else {
                        statusIcon.innerHTML = '<i class="fas fa-times" style="color:var(--red);"></i>';
                        helpText.textContent = cachedObj.message || <?php echo json_encode(t('location_not_found')); ?>;
                    }
                    statusIcon.style.display = '';
                    return;
                }
            } catch (e) { /* ignore parse errors */ }
        }
        statusIcon.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        statusIcon.style.display = '';
        fetch(`https://api.botofthespecter.com/v2/weather/location?location=${encodeURIComponent(location)}`, {
            headers: { 'X-API-KEY': apiKey }
        })
            .then(res => {
                return res.json().then(data => {
                    let valid = false, msg = "";
                    if (res.ok && data && data.message) {
                        statusIcon.innerHTML = '<i class="fas fa-check" style="color:var(--green);"></i>';
                        helpText.textContent = data.message;
                        valid = true;
                        msg = data.message;
                    } else if (data && data.detail && data.detail.includes("not found")) {
                        statusIcon.innerHTML = '<i class="fas fa-times" style="color:var(--red);"></i>';
                        helpText.textContent = <?php echo json_encode(t('weather_location_not_found')); ?>;
                        valid = false;
                        msg = <?php echo json_encode(t('weather_location_not_found')); ?>;
                    } else {
                        statusIcon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>';
                        helpText.textContent = <?php echo json_encode(t('weather_location_could_not_validate')); ?>;
                        valid = false;
                        msg = <?php echo json_encode(t('weather_location_could_not_validate')); ?>;
                    }
                    statusIcon.style.display = '';
                    if (hasCookieConsent()) {
                        setCookie('weather_location_valid', JSON.stringify({
                            location: location,
                            valid: valid,
                            message: msg
                        }), 7);
                    }
                });
            })
            .catch(() => {
                statusIcon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>';
                helpText.textContent = <?php echo json_encode(t('weather_location_could_not_validate')); ?>;
                statusIcon.style.display = '';
            });
    }
    if (weatherInput) {
        weatherInput.addEventListener('input', function() {
            clearTimeout(weatherTimeout);
            weatherTimeout = setTimeout(validateWeatherLocation, 700);
        });
        // Validate on page load if value exists
        if (weatherInput.value.trim()) {
            validateWeatherLocation();
        }
    }

    // Cookie Consent Button
    const declineBtn = document.getElementById('decline-cookies-btn');
    if (declineBtn) {
        declineBtn.addEventListener('click', function() {
            // Set cookie_consent to declined
            document.cookie = "cookie_consent=declined; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/";
            // Remove specific cookies
            ["weather_location_valid", "selectedBot"].forEach(function(name) {
                document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
            });
            location.reload();
        });
    }
    const acceptBtn = document.getElementById('accept-cookies-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            // Set cookie_consent to accepted
            document.cookie = "cookie_consent=accepted; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/";
            location.reload();
        });
    }
});

// Disconnect functions using SweetAlert2
function disconnectTwitch() {
    Swal.fire({
        title: <?php echo json_encode(t('confirm_disconnect_twitch_title')); ?>,
        text: <?php echo json_encode(t('confirm_disconnect_twitch_text')); ?>,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('yes_logout')); ?>,
        cancelButtonText: <?php echo json_encode(t('cancel')); ?>,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'disconnect_twitch';
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function disconnectDiscord() {
    Swal.fire({
        title: <?php echo json_encode(t('confirm_disconnect_discord_title')); ?>,
        text: <?php echo json_encode(t('confirm_disconnect_discord_text')); ?>,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('yes_disconnect')); ?>,
        cancelButtonText: <?php echo json_encode(t('cancel')); ?>,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'disconnect_discord';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function disconnectSpotify() {
    Swal.fire({
        title: <?php echo json_encode(t('confirm_disconnect_spotify_title')); ?>,
        text: <?php echo json_encode(t('confirm_disconnect_spotify_text')); ?>,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('yes_disconnect')); ?>,
        cancelButtonText: <?php echo json_encode(t('cancel')); ?>,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'disconnect_spotify';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function disconnectStreamelements() {
    Swal.fire({
        title: <?php echo json_encode(t('confirm_disconnect_streamelements_title')); ?>,
        text: <?php echo json_encode(t('confirm_disconnect_streamelements_text')); ?>,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('yes_disconnect')); ?>,
        cancelButtonText: <?php echo json_encode(t('cancel')); ?>,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'disconnect_streamelements';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function disconnectStreamlabs() {
    Swal.fire({
        title: <?php echo json_encode(t('confirm_disconnect_streamlabs_title')); ?>,
        text: <?php echo json_encode(t('confirm_disconnect_streamlabs_text')); ?>,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('yes_disconnect')); ?>,
        cancelButtonText: <?php echo json_encode(t('cancel')); ?>,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'disconnect_streamlabs';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function exportProfileData() {
    const btn = document.getElementById('export-data-profile-btn');
    if (!btn) return;
    const email = btn.getAttribute('data-user-email') || '';
    const uname = btn.getAttribute('data-user-username') || '';
    Swal.fire({
        title: <?php echo json_encode(t('profile_export_request_title')); ?>,
        html: <?php echo json_encode(t('profile_export_duration_line')); ?> +
              <?php echo json_encode(t('profile_export_email_prefix')); ?> + (email || <?php echo json_encode(t('profile_export_email_fallback')); ?>) + <?php echo json_encode(t('profile_export_email_suffix')); ?> +
              <?php echo json_encode(t('profile_export_notify_line')); ?>,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: <?php echo json_encode(t('profile_export_request_btn')); ?>,
        cancelButtonText: <?php echo json_encode(t('cancel')); ?>
    }).then((result)=>{
        if (!result.isConfirmed) return;
        $.post('admin/export_user_data.php', { username: uname }, function(resp){
            let data = {};
            try { data = typeof resp === 'object' ? resp : JSON.parse(resp); } catch(e){}
            if (data && data.success) {
                Swal.fire(<?php echo json_encode(t('profile_export_queued_title')); ?>, <?php echo json_encode(t('profile_export_queued_text')); ?>, 'success');
            } else {
                Swal.fire(<?php echo json_encode(t('profile_export_error_title')); ?>, data.msg || <?php echo json_encode(t('profile_export_error_start')); ?>, 'error');
            }
        }).fail(function(){
            Swal.fire(<?php echo json_encode(t('profile_export_error_title')); ?>, <?php echo json_encode(t('profile_export_error_contact')); ?>, 'error');
        });
    });
}
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
