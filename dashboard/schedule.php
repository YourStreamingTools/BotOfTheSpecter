<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Require login
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page setup
$pageTitle = 'Twitch Schedule';

// Includes used across dashboard pages
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';

// Get user's timezone from profile (fallback to UTC)
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// AJAX endpoints used by this page (category search / lookup)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_GET['ajax'];

    // Search categories by name (Helix: search/categories)
    if ($ajax === 'search_categories' && !empty($_GET['q'])) {
        $q = substr(trim($_GET['q']), 0, 200);
        $url = 'https://api.twitch.tv/helix/search/categories?query=' . urlencode($q) . '&first=10';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $headers = [
            'Client-ID: ' . $clientID,
            'Authorization: Bearer ' . ($_SESSION['access_token'] ?? '')
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            http_response_code(502);
            echo json_encode(['error' => 'Twitch request failed: ' . $err]);
            exit();
        } elseif ($code === 200) {
            $data = json_decode($resp, true);
            echo json_encode($data['data'] ?? []);
            exit();
        } else {
            http_response_code($code);
            echo json_encode(['error' => 'Twitch API returned ' . $code, 'body' => $resp]);
            exit();
        }
    }

    // Lookup game/category by ID (Helix: games)
    if ($ajax === 'get_game_name' && !empty($_GET['id'])) {
        $id = trim($_GET['id']);
        $url = 'https://api.twitch.tv/helix/games?id=' . urlencode($id);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $headers = [
            'Client-ID: ' . $clientID,
            'Authorization: Bearer ' . ($_SESSION['access_token'] ?? '')
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            http_response_code(502);
            echo json_encode(['error' => 'Twitch request failed: ' . $err]);
            exit();
        } elseif ($code === 200) {
            $data = json_decode($resp, true);
            $row = $data['data'][0] ?? null;
            if ($row) echo json_encode(['id' => $row['id'], 'name' => $row['name'], 'box_art_url' => $row['box_art_url'] ?? '']);
            else http_response_code(404);
            exit();
        } else {
            http_response_code($code);
            echo json_encode(['error' => 'Twitch API returned ' . $code, 'body' => $resp]);
            exit();
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid ajax request']);
    exit();
}

// Handle schedule settings updates (vacation) via Twitch Helix PATCH
$success = null; // used for UI feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['access_token'])) {
    $broadcasterId = $_SESSION['twitchUserId'] ?? null;
    if (empty($broadcasterId)) {
        $error = 'Broadcaster ID not available. Please re-login to update schedule settings.';
    } else {
        $action = $_POST['action'] ?? ($_POST['submit'] ?? 'save');
        $params = [];
        if ($action === 'clear') {
            // Cancel vacation
            $params = [
                'broadcaster_id' => $broadcasterId,
                'is_vacation_enabled' => 'false'
            ];
        } else {
            // Save/update
            $isVac = !empty($_POST['is_vacation_enabled']) ? true : false;
            if ($isVac) {
                $startLocal = trim($_POST['vacation_start'] ?? '');
                $endLocal = trim($_POST['vacation_end'] ?? '');
                $tzPost = trim($_POST['timezone'] ?? $timezone);
                if ($startLocal === '' || $endLocal === '' || $tzPost === '') {
                    $error = 'Start, end and timezone are required when enabling vacation.';
                } else {
                    try {
                        $dtStart = new DateTime($startLocal, new DateTimeZone($tzPost));
                        $dtEnd = new DateTime($endLocal, new DateTimeZone($tzPost));
                        if ($dtEnd <= $dtStart) {
                            $error = 'Vacation end must be after start.';
                        } else {
                            $dtStart->setTimezone(new DateTimeZone('UTC'));
                            $dtEnd->setTimezone(new DateTimeZone('UTC'));
                            $params = [
                                'broadcaster_id' => $broadcasterId,
                                'is_vacation_enabled' => 'true',
                                'vacation_start_time' => $dtStart->format('Y-m-d\TH:i:s\Z'),
                                'vacation_end_time' => $dtEnd->format('Y-m-d\TH:i:s\Z'),
                                'timezone' => $tzPost
                            ];
                        }
                    } catch (Exception $e) {
                        $error = 'Invalid date/time or timezone provided.';
                    }
                }
            } else {
                // Explicitly disable
                $params = [
                    'broadcaster_id' => $broadcasterId,
                    'is_vacation_enabled' => 'false'
                ];
            }
        }
        // Process create/update/delete actions submitted from the page for schedule segments
        $op = $_POST['action'] ?? '';
        if (empty($error) && $op === 'create_segment') {
            $sStart = trim($_POST['segment_start'] ?? '');
            $sTz = trim($_POST['segment_timezone'] ?? $timezone);
            $sDur = trim($_POST['segment_duration'] ?? '');
            $sRec = !empty($_POST['segment_recurring']) ? true : false;
            $sCat = trim($_POST['segment_category_id'] ?? '');
            $sTitle = trim($_POST['segment_title'] ?? '');
            if ($sStart === '' || $sTz === '' || $sDur === '') {
                $error = 'Start time, timezone and duration are required to create a segment.';
            } elseif (!is_numeric($sDur) || (int)$sDur < 30 || (int)$sDur > 1380) {
                $error = 'Duration must be a number between 30 and 1380 minutes.';
            } elseif (strlen($sTitle) > 140) {
                $error = 'Title cannot exceed 140 characters.';
            } else {
                try {
                    $dt = new DateTime($sStart, new DateTimeZone($sTz));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $body = [
                        'start_time' => $dt->format('Y-m-d\TH:i:s\Z'),
                        'timezone' => $sTz,
                        'duration' => (string)(int)$sDur,
                        'is_recurring' => $sRec ? true : false
                    ];
                    if ($sCat !== '') $body['category_id'] = $sCat;
                    if ($sTitle !== '') $body['title'] = mb_substr($sTitle, 0, 140);
                    $urlSeg = 'https://api.twitch.tv/helix/schedule/segment?broadcaster_id=' . urlencode($broadcasterId);
                    $chSeg = curl_init($urlSeg);
                    curl_setopt($chSeg, CURLOPT_POST, true);
                    curl_setopt($chSeg, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chSeg, CURLOPT_TIMEOUT, 10);
                    curl_setopt($chSeg, CURLOPT_SSL_VERIFYPEER, true);
                    $headersSeg = [
                        'Client-ID: ' . $clientID,
                        'Authorization: Bearer ' . $_SESSION['access_token'],
                        'Content-Type: application/json'
                    ];
                    curl_setopt($chSeg, CURLOPT_HTTPHEADER, $headersSeg);
                    curl_setopt($chSeg, CURLOPT_POSTFIELDS, json_encode($body));
                    $respSeg = curl_exec($chSeg);
                    $codeSeg = curl_getinfo($chSeg, CURLINFO_HTTP_CODE);
                    $errSeg = curl_error($chSeg);
                    curl_close($chSeg);
                    if ($codeSeg === 200) {
                        $success = 'Schedule segment created successfully.';
                    } else {
                        $error = 'Twitch API returned HTTP ' . $codeSeg . '. ' . htmlspecialchars($respSeg ?: $errSeg);
                    }
                } catch (Exception $e) {
                    $error = 'Invalid start time or timezone for new segment.';
                }
            }
        }
        if (empty($error) && $op === 'update_segment') {
            $segId = trim($_POST['segment_id'] ?? '');
            if ($segId === '') {
                $error = 'Segment ID is required to update.';
            } else {
                $payload = [];
                if (!empty($_POST['segment_start'])) {
                    try {
                        $dt = new DateTime(trim($_POST['segment_start']), new DateTimeZone(trim($_POST['segment_timezone'] ?? $timezone)));
                        $dt->setTimezone(new DateTimeZone('UTC'));
                        $payload['start_time'] = $dt->format('Y-m-d\TH:i:s\Z');
                    } catch (Exception $e) {
                        $error = 'Invalid segment start time.';
                    }
                }
                if (!empty($_POST['segment_duration'])) {
                    $d = trim($_POST['segment_duration']);
                    if (!is_numeric($d) || (int)$d < 30 || (int)$d > 1380) $error = 'Duration must be between 30 and 1380.';
                    else $payload['duration'] = (string)(int)$d;
                }
                if (isset($_POST['segment_title'])) {
                    $t = trim($_POST['segment_title']);
                    if (strlen($t) > 140) $error = 'Title cannot exceed 140 characters.';
                    else $payload['title'] = mb_substr($t,0,140);
                }
                if (!empty($_POST['segment_category_id'])) $payload['category_id'] = trim($_POST['segment_category_id']);
                if (!empty($_POST['segment_timezone'])) $payload['timezone'] = trim($_POST['segment_timezone']);
                if (isset($_POST['segment_canceled'])) $payload['is_canceled'] = ($_POST['segment_canceled'] ? true : false);
                if (empty($error) && empty($payload)) {
                    $error = 'No update fields provided for segment.';
                }
                if (empty($error)) {
                    $urlUpd = 'https://api.twitch.tv/helix/schedule/segment?broadcaster_id=' . urlencode($broadcasterId) . '&id=' . urlencode($segId);
                    $chUpd = curl_init($urlUpd);
                    curl_setopt($chUpd, CURLOPT_CUSTOMREQUEST, 'PATCH');
                    curl_setopt($chUpd, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chUpd, CURLOPT_TIMEOUT, 10);
                    curl_setopt($chUpd, CURLOPT_SSL_VERIFYPEER, true);
                    $headersUpd = [
                        'Client-ID: ' . $clientID,
                        'Authorization: Bearer ' . $_SESSION['access_token'],
                        'Content-Type: application/json'
                    ];
                    curl_setopt($chUpd, CURLOPT_HTTPHEADER, $headersUpd);
                    curl_setopt($chUpd, CURLOPT_POSTFIELDS, json_encode($payload));
                    $respUpd = curl_exec($chUpd);
                    $codeUpd = curl_getinfo($chUpd, CURLINFO_HTTP_CODE);
                    $errUpd = curl_error($chUpd);
                    curl_close($chUpd);
                    if ($codeUpd === 200) {
                        $success = 'Schedule segment updated successfully.';
                    } else {
                        $error = 'Twitch API returned HTTP ' . $codeUpd . '. ' . htmlspecialchars($respUpd ?: $errUpd);
                    }
                }
            }
        }
        if (empty($error) && $op === 'delete_segment') {
            $segId = trim($_POST['segment_id'] ?? '');
            if ($segId === '') {
                $error = 'Segment ID is required to delete.';
            } else {
                $urlDel = 'https://api.twitch.tv/helix/schedule/segment?broadcaster_id=' . urlencode($broadcasterId) . '&id=' . urlencode($segId);
                $chDel = curl_init($urlDel);
                curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chDel, CURLOPT_TIMEOUT, 10);
                curl_setopt($chDel, CURLOPT_SSL_VERIFYPEER, true);
                $headersDel = [
                    'Client-ID: ' . $clientID,
                    'Authorization: Bearer ' . $_SESSION['access_token']
                ];
                curl_setopt($chDel, CURLOPT_HTTPHEADER, $headersDel);
                $respDel = curl_exec($chDel);
                $codeDel = curl_getinfo($chDel, CURLINFO_HTTP_CODE);
                $errDel = curl_error($chDel);
                curl_close($chDel);
                if ($codeDel === 204) {
                    $success = 'Schedule segment deleted.';
                } else {
                    $error = 'Twitch API returned HTTP ' . $codeDel . '. ' . htmlspecialchars($respDel ?: $errDel);
                }
            }
        }
        // If params set and no validation error, call Twitch
        if (!empty($params) && empty($error)) {
            $url = 'https://api.twitch.tv/helix/schedule/settings?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $headers = [
                'Client-ID: ' . $clientID,
                'Authorization: Bearer ' . $_SESSION['access_token']
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($httpCode === 204) {
                $success = 'Schedule settings updated successfully.';
            } else {
                $body = $resp ?: '';
                $error = 'Twitch API returned HTTP ' . $httpCode . '. ' . htmlspecialchars($body ?: $curlErr);
            }
        }
    }
}

// Fetch schedule from Twitch Helix
$schedule = null;
$error = null;
$broadcasterId = $_SESSION['twitchUserId'] ?? null;
if (empty($broadcasterId)) {
    $error = 'Broadcaster ID not available. Please re-login.';
} else {
    $url = 'https://api.twitch.tv/helix/schedule?broadcaster_id=' . urlencode($broadcasterId) . '&first=25';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $headers = [
        'Client-ID: ' . $clientID,
        'Authorization: Bearer ' . $_SESSION['access_token']
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        $error = 'Twitch API request failed: ' . htmlspecialchars($curlErr);
    } elseif ($httpCode === 200) {
        $data = json_decode($resp, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['data'])) {
            $schedule = $data['data'];
        } else {
            $error = 'Unable to parse Twitch response.';
        }
    } elseif ($httpCode === 401) {
        $error = 'Unauthorized. Your Twitch session may need re-linking.';
    } elseif ($httpCode === 404) {
        $error = 'No schedule found for this broadcaster.';
    } else {
        $error = 'Twitch API returned HTTP ' . $httpCode . '.';
    }
}

// Helper: format RFC3339 -> localized date/time string
function fmt_dt($rfc3339, $tz, $format = 'D, j M Y - g:ia T') {
    if (empty($rfc3339)) return '—';
    try {
        $dt = new DateTime($rfc3339, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($format);
    } catch (Exception $e) {
        return htmlspecialchars($rfc3339);
    }
}

// Render page content with output buffering
ob_start();
?>
<div class="hero is-small has-background-dark">
    <div class="hero-body">
        <div class="container">
            <h1 class="title is-3 has-text-white"><i class="fas fa-calendar-days"></i> Twitch Schedule</h1>
            <p class="subtitle has-text-grey-light">Your official Twitch schedule.</p>
        </div>
    </div>
</div>
<section class="section">
    <div class="container">
        <?php if ($error): ?>
            <div class="notification is-danger is-light">
                <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                <strong>Notice:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="notification is-success is-light">
                <span class="icon"><i class="fas fa-check-circle"></i></span>
                <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <!-- Vacation / Schedule settings form -->
        <div class="box has-background-darker mb-4">
            <form method="post" class="columns is-vcentered is-multiline">
                <div class="column is-12">
                    <label class="label has-text-white">Vacation / Off dates</label>
                    <div class="field is-grouped is-align-items-center">
                        <div class="control">
                            <label class="checkbox">
                                <input type="checkbox" name="is_vacation_enabled" value="1" <?php echo (!empty($schedule['vacation'])) ? 'checked' : ''; ?> />
                                Enable vacation
                            </label>
                        </div>
                        <div class="control">
                            <input class="input" type="datetime-local" name="vacation_start" value="<?php echo isset($schedule['vacation']['start_time']) ? date('Y-m-d\TH:i', (new DateTime($schedule['vacation']['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : ''; ?>" />
                        </div>
                        <div class="control">
                            <input class="input" type="datetime-local" name="vacation_end" value="<?php echo isset($schedule['vacation']['end_time']) ? date('Y-m-d\TH:i', (new DateTime($schedule['vacation']['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : ''; ?>" />
                        </div>
                        <div class="control" style="max-width:220px;">
                            <div class="select is-fullwidth">
                                <select name="timezone">
                                    <?php foreach (DateTimeZone::listIdentifiers() as $tzid): ?>
                                        <option value="<?php echo htmlspecialchars($tzid); ?>" <?php echo ($tzid === $timezone) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tzid); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="control">
                            <button class="button is-link" type="submit" name="action" value="save">Save</button>
                        </div>
                        <div class="control">
                            <button class="button is-danger" type="submit" name="action" value="clear">Cancel vacation</button>
                        </div>
                    </div>
                    <p class="help has-text-grey-light">Times are shown/entered in your profile timezone (<?php echo htmlspecialchars($timezone); ?>).</p>
                </div>
            </form>
        </div>
        <!-- Create new schedule segment -->
        <div class="box has-background-darker mb-4">
            <form method="post" class="columns is-vcentered is-multiline">
                <div class="column is-12">
                    <label class="label has-text-white">Add schedule segment</label>
                    <div class="field is-grouped is-align-items-center">
                        <div class="control">
                            <input class="input" type="datetime-local" name="segment_start" placeholder="Start (local)" />
                        </div>
                        <div class="control">
                            <input class="input" type="text" name="segment_timezone" value="<?php echo htmlspecialchars($timezone); ?>" placeholder="Timezone (IANA)" />
                        </div>
                        <div class="control">
                            <input class="input" type="number" name="segment_duration" min="30" max="1380" placeholder="Duration (minutes)" />
                        </div>
                        <div class="control" style="min-width:260px; position:relative;">
                            <input class="input" type="text" id="segment_category_search" placeholder="Search category (name or id) — type to search" autocomplete="off" />
                            <input type="hidden" name="segment_category_id" id="segment_category_id" />
                            <div id="segment_category_suggestions" style="display:none; position:absolute; z-index:50; width:100%; background:var(--card-bg); border:1px solid #333; border-radius:4px; margin-top:0.25rem; max-height:200px; overflow:auto;"></div>
                            <p class="help has-text-grey-light"><span id="segment_category_name"></span></p>
                        </div>
                        <div class="control" style="min-width:220px;">
                            <input class="input" type="text" name="segment_title" maxlength="140" placeholder="Title (optional)" />
                        </div>
                        <div class="control">
                            <label class="checkbox"><input type="checkbox" name="segment_recurring" value="1"> Recurring</label>
                        </div>
                        <div class="control">
                            <button class="button is-primary" type="submit" name="action" value="create_segment">Create</button>
                        </div>
                    </div>
                    <p class="help has-text-grey-light">Duration must be between 30 and 1380 minutes. Non-recurring segments may be restricted to partners/affiliates.</p>
                </div>
            </form>
        </div>
        <?php if (empty($schedule) || empty($schedule['segments'])): ?>
            <div class="box has-background-dark">
                <div class="content has-text-centered">
                    <p class="title is-5 has-text-white">No scheduled segments</p>
                    <p class="has-text-grey-light">You don't have any scheduled stream segments on Twitch. Use the Twitch Creator Dashboard to add schedule entries.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="columns is-multiline">
                <?php foreach ($schedule['segments'] as $seg):
                    $start = fmt_dt($seg['start_time'] ?? null, $timezone);
                    $end = fmt_dt($seg['end_time'] ?? null, $timezone);
                    $category = $seg['category']['name'] ?? null;
                    $isRecurring = !empty($seg['is_recurring']) ? true : false;
                    $canceled = !empty($seg['canceled_until']);
                ?>
                <div class="column is-6-tablet is-4-desktop">
                    <div class="card">
                        <header class="card-header">
                            <p class="card-header-title">
                                <?php echo htmlspecialchars($seg['title'] ?: 'Untitled'); ?>
                            </p>
                            <span class="card-header-icon has-text-grey-light" aria-hidden="true">
                                <?php if ($isRecurring): ?>
                                    <span class="tag is-small is-info">Recurring</span>
                                <?php endif; ?>
                                <?php if ($canceled): ?>
                                    <span class="tag is-small is-danger">Canceled</span>
                                <?php endif; ?>
                            </span>
                        </header>
                        <div class="card-content">
                            <div class="content">
                                <p class="mb-2"><strong>When</strong><br><?php echo $start; ?> &ndash; <?php echo $end; ?></p>
                                <p class="mb-2"><strong>Category</strong><br><?php echo $category ? htmlspecialchars($category) : '<em>Not specified</em>'; ?></p>
                                <?php if (!empty($seg['is_recurring'])): ?>
                                    <p class="mb-0 has-text-grey">This segment is part of a recurring series.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Inline edit / delete form for this segment -->
                        <div class="card-content has-background-darker">
                            <form method="post" class="columns is-multiline">
                                <input type="hidden" name="segment_id" value="<?php echo htmlspecialchars($seg['id']); ?>" />
                                <div class="column is-12">
                                    <div class="field is-grouped is-align-items-center is-flex-wrap-wrap">
                                        <div class="control">
                                            <input class="input" type="datetime-local" name="segment_start" value="<?php echo isset($seg['start_time']) ? date('Y-m-d\TH:i', (new DateTime($seg['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : ''; ?>" />
                                        </div>
                                        <div class="control">
                                            <input class="input" type="number" name="segment_duration" min="30" max="1380" value="<?php echo (isset($seg['start_time']) && isset($seg['end_time'])) ? intval(((new DateTime($seg['end_time']))->getTimestamp() - (new DateTime($seg['start_time']))->getTimestamp())/60) : ''; ?>" placeholder="duration (minutes)" />
                                        </div>
                                        <div class="control">
                                            <input class="input" type="text" name="segment_title" maxlength="140" value="<?php echo htmlspecialchars($seg['title'] ?? ''); ?>" placeholder="Title" />
                                        </div>
                                        <div class="control" style="min-width:220px; position:relative;">
                                            <input class="input segment-category-search" type="text" placeholder="Search category..." value="<?php echo htmlspecialchars($seg['category']['name'] ?? ''); ?>" data-current-id="<?php echo htmlspecialchars($seg['category']['id'] ?? ''); ?>" autocomplete="off" />
                                            <input type="hidden" name="segment_category_id" class="segment-category-id" value="<?php echo htmlspecialchars($seg['category']['id'] ?? ''); ?>" />
                                            <div class="dropdown suggestions" style="display:none; position:absolute; z-index:50; width:100%; background:var(--card-bg); border:1px solid #333; border-radius:4px; margin-top:0.25rem; max-height:200px; overflow:auto;"></div>
                                            <p class="help has-text-grey-light"><span class="segment-category-name"><?php echo htmlspecialchars($seg['category']['name'] ?? ''); ?></span></p>
                                        </div>
                                        <div class="control">
                                            <input class="input" type="text" name="segment_timezone" value="<?php echo htmlspecialchars($seg['timezone'] ?? $timezone); ?>" placeholder="Timezone" />
                                        </div>
                                        <div class="control">
                                            <label class="checkbox"><input type="checkbox" name="segment_canceled" value="1" <?php echo !empty($seg['canceled_until']) ? 'checked' : ''; ?> /> Canceled</label>
                                        </div>
                                        <div class="control">
                                            <button class="button is-link" type="submit" name="action" value="update_segment">Update</button>
                                        </div>
                                        <div class="control">
                                            <button class="button is-danger" type="submit" name="action" value="delete_segment">Delete</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($schedule['vacation'])): ?>
                <div class="box mt-4 has-background-dark">
                    <p class="title is-6 has-text-white">Vacation / Off dates</p>
                    <p class="has-text-grey-light">From <?php echo fmt_dt($schedule['vacation']['start_time'] ?? null, $timezone); ?> to <?php echo fmt_dt($schedule['vacation']['end_time'] ?? null, $timezone); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
// Schedule page: category search + lookup (small, dependency-free)
(function(){
    const debounce = (fn, ms = 250) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; };

    function renderSuggestions(container, items) {
        container.innerHTML = '';
        if (!items || items.length === 0) { container.style.display = 'none'; return; }
        items.forEach(it => {
            const el = document.createElement('div');
            el.className = 'dropdown-item';
            el.style.padding = '0.5rem';
            el.style.cursor = 'pointer';
            el.innerHTML = '<strong>' + (it.name || '') + '</strong> <span style="float:right;opacity:0.8;">id:' + (it.id||'') + '</span>';
            el.addEventListener('click', () => {
                container.style.display = 'none';
                const root = container.closest('.control');
                const hidden = root.querySelector('input[type="hidden"]');
                const visible = root.querySelector('input[type="text"]');
                const nameEl = root.querySelector('.segment-category-name, #segment_category_name');
                if (hidden) hidden.value = it.id || '';
                if (visible) visible.value = it.name || '';
                if (nameEl) nameEl.textContent = it.name || '';
            });
            container.appendChild(el);
        });
        container.style.display = 'block';
    }

    async function searchCategories(q) {
        if (!q || q.trim().length === 0) return [];
        try {
            const res = await fetch('schedule.php?ajax=search_categories&q=' + encodeURIComponent(q));
            if (!res.ok) return [];
            return await res.json();
        } catch (e) { return []; }
    }

    async function getGameName(id) {
        if (!id) return null;
        try {
            const res = await fetch('schedule.php?ajax=get_game_name&id=' + encodeURIComponent(id));
            if (!res.ok) return null;
            return await res.json();
        } catch (e) { return null; }
    }

    // Create-segment search box
    const createSearch = document.getElementById('segment_category_search');
    const createHidden = document.getElementById('segment_category_id');
    const createSug = document.getElementById('segment_category_suggestions');
    const createName = document.getElementById('segment_category_name');
    if (createSearch) {
        createSearch.addEventListener('input', debounce(async (ev) => {
            const q = ev.target.value.trim();
            // if user typed only digits, try lookup by id
            if (/^\d+$/.test(q)) {
                const g = await getGameName(q);
                if (g) {
                    createHidden.value = g.id || '';
                    createName.textContent = g.name || '';
                    return renderSuggestions(createSug, [g]);
                }
            }
            const items = await searchCategories(q);
            renderSuggestions(createSug, items);
        }, 200));
        // clear hidden id when user edits the visible search
        createSearch.addEventListener('change', (e) => { if (createHidden && createHidden.value && createSearch.value.trim() === '') createHidden.value = ''; });
        document.addEventListener('click', (ev) => { if (!createSearch.contains(ev.target) && !createSug.contains(ev.target)) createSug.style.display = 'none'; });
    }

    // Per-segment search boxes
    document.querySelectorAll('.segment-category-search').forEach(function(input){
        const root = input.closest('.control');
        const hidden = root.querySelector('.segment-category-id');
        const sug = root.querySelector('.suggestions');
        const nameEl = root.querySelector('.segment-category-name');
        // if input has data-current-id, try to resolve name (in case only id saved)
        const currentId = input.getAttribute('data-current-id');
        if (currentId && !input.value) {
            getGameName(currentId).then(g => { if (g) { input.value = g.name || ''; if (hidden) hidden.value = g.id || ''; if (nameEl) nameEl.textContent = g.name || ''; } });
        }
        const doSearch = debounce(async (ev) => {
            const q = ev.target.value.trim();
            if (/^\d+$/.test(q)) {
                const g = await getGameName(q);
                if (g) { if (hidden) hidden.value = g.id || ''; if (nameEl) nameEl.textContent = g.name || ''; return renderSuggestions(sug, [g]); }
            }
            const items = await searchCategories(q);
            renderSuggestions(sug, items);
        }, 200);
        input.addEventListener('input', doSearch);
        input.addEventListener('change', () => { if (hidden && hidden.value && input.value.trim() === '') hidden.value = ''; });
        document.addEventListener('click', (ev) => { if (!root.contains(ev.target)) sug.style.display = 'none'; });
    });
})();
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>