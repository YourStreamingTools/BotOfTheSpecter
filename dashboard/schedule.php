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
    if ($ajax === 'segment_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $broadcasterId = $_SESSION['twitchUserId'] ?? null;
        if (empty($broadcasterId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Broadcaster ID not available. Please re-login.']);
            exit();
        }
        $op = trim($_POST['action'] ?? '');
        if ($op !== 'cancel_segment') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported segment action for AJAX request.']);
            exit();
        }
        $segId = trim($_POST['segment_id'] ?? '');
        $cancelRaw = strtolower(trim((string)($_POST['cancel_state'] ?? '1')));
        $cancelState = in_array($cancelRaw, ['1', 'true', 'yes', 'on'], true);
        if ($segId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Segment ID is required to cancel.']);
            exit();
        }
        $cancelQuery = [
            'broadcaster_id' => $broadcasterId,
            'id' => $segId
        ];
        $urlCancel = 'https://api.twitch.tv/helix/schedule/segment?' . http_build_query($cancelQuery);
        $chCancel = curl_init($urlCancel);
        curl_setopt($chCancel, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($chCancel, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chCancel, CURLOPT_TIMEOUT, 10);
        curl_setopt($chCancel, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($chCancel, CURLOPT_HTTPHEADER, [
            'Client-ID: ' . $clientID,
            'Authorization: Bearer ' . ($_SESSION['access_token'] ?? ''),
            'Content-Type: application/json'
        ]);
        curl_setopt($chCancel, CURLOPT_POSTFIELDS, json_encode([
            'is_canceled' => $cancelState
        ]));
        $respCancel = curl_exec($chCancel);
        $codeCancel = curl_getinfo($chCancel, CURLINFO_HTTP_CODE);
        $errCancel = curl_error($chCancel);
        curl_close($chCancel);
        $responseCanceledState = $cancelState;
        if (is_string($respCancel) && $respCancel !== '') {
            $respJson = json_decode($respCancel, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($respJson)) {
                $respSeg = null;
                if (isset($respJson['data']['segments'][0]) && is_array($respJson['data']['segments'][0])) {
                    $respSeg = $respJson['data']['segments'][0];
                } elseif (isset($respJson['data']['segment']) && is_array($respJson['data']['segment'])) {
                    $respSeg = $respJson['data']['segment'];
                } elseif (isset($respJson['data'][0]) && is_array($respJson['data'][0])) {
                    $respSeg = $respJson['data'][0];
                }
                if (is_array($respSeg)) {
                    if (array_key_exists('is_canceled', $respSeg)) {
                        $responseCanceledState = !empty($respSeg['is_canceled']);
                    } elseif (array_key_exists('canceled_until', $respSeg)) {
                        $responseCanceledState = !empty($respSeg['canceled_until']);
                    }
                }
            }
        }
        $respCancelSafe = '';
        if (is_string($respCancel) && $respCancel !== '') {
            $respCancelSafe = preg_replace('/("(access_token|refresh_token|authorization|client_secret|auth_code|code)"\s*:\s*")[^"]*(")/i', '$1[REDACTED]$3', $respCancel);
            $respCancelSafe = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/=]+/i', 'Bearer [REDACTED]', $respCancelSafe);
            if (strlen($respCancelSafe) > 300) {
                $respCancelSafe = substr($respCancelSafe, 0, 300) . '...';
            }
        }
        $debugMeta = [
            'request' => [
                'method' => 'PATCH',
                'endpoint' => '/helix/schedule/segment',
                'segment_id' => $segId,
                'body' => ['is_canceled' => $cancelState]
            ],
            'response' => [
                'http_code' => $codeCancel,
                'curl_error' => $errCancel,
                'body_preview' => $respCancelSafe
            ]
        ];
        if ($codeCancel === 200) {
            echo json_encode([
                'ok' => true,
                'action' => 'cancel_segment',
                'segment_id' => $segId,
                'is_canceled' => $responseCanceledState,
                'requested_is_canceled' => $cancelState,
                'message' => $responseCanceledState ? 'Segment canceled.' : 'Segment uncanceled.',
                'debug' => $debugMeta
            ]);
            exit();
        }
        http_response_code($codeCancel > 0 ? $codeCancel : 500);
        echo json_encode([
            'ok' => false,
            'error' => 'Twitch API returned HTTP ' . $codeCancel . '. ' . htmlspecialchars($respCancel ?: $errCancel),
            'debug' => $debugMeta
        ]);
        exit();
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
        } elseif ($action === 'save') {
            // Save/update vacation using entered date range
            $startLocal = trim($_POST['vacation_start'] ?? '');
            $endLocal = trim($_POST['vacation_end'] ?? '');
            $tzPost = $timezone;
            if ($startLocal === '' || $endLocal === '') {
                $error = 'Start and end are required to start vacation.';
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
        }
        // Process create/update/delete actions submitted from the page for schedule segments
        $op = $_POST['action'] ?? '';
        if (empty($error) && $op === 'create_segment') {
            $sStart = trim($_POST['segment_start'] ?? '');
            $sEnd = trim($_POST['segment_end'] ?? '');
            $sTz = $timezone;
            $sRec = !empty($_POST['segment_recurring']) ? true : false;
            $sCat = trim($_POST['segment_category_id'] ?? '');
            $sTitle = trim($_POST['segment_title'] ?? '');
            if ($sStart === '' || $sEnd === '') {
                $error = 'Start and end times are required to create a segment.';
            } elseif (strlen($sTitle) > 140) {
                $error = 'Title cannot exceed 140 characters.';
            } else {
                try {
                    $dtStart = new DateTime($sStart, new DateTimeZone($sTz));
                    $dtEnd = new DateTime($sEnd, new DateTimeZone($sTz));
                    $durationMins = (int)floor(($dtEnd->getTimestamp() - $dtStart->getTimestamp()) / 60);
                    if ($durationMins < 30 || $durationMins > 1380) {
                        $error = 'Duration must be between 30 minutes and 23 hours (1380 minutes).';
                    }
                    if (empty($error)) {
                        $dtStart->setTimezone(new DateTimeZone('UTC'));
                        $body = [
                            'start_time' => $dtStart->format('Y-m-d\TH:i:s\Z'),
                            'timezone' => $sTz,
                            'duration' => (string)$durationMins,
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
                    }
                } catch (Exception $e) {
                    $error = 'Invalid start or end time for new segment.';
                }
            }
        }
        if (empty($error) && $op === 'update_segment') {
            $segId = trim($_POST['segment_id'] ?? '');
            if ($segId === '') {
                $error = 'Segment ID is required to update.';
            } else {
                $payload = [];
                $startRaw = trim($_POST['segment_start'] ?? '');
                $endRaw = trim($_POST['segment_end'] ?? '');
                if ($startRaw !== '' && $endRaw !== '') {
                    try {
                        $dtStart = new DateTime($startRaw, new DateTimeZone($timezone));
                        $dtEnd = new DateTime($endRaw, new DateTimeZone($timezone));
                        $durationMins = (int)floor(($dtEnd->getTimestamp() - $dtStart->getTimestamp()) / 60);
                        if ($durationMins < 30 || $durationMins > 1380) {
                            $error = 'Duration must be between 30 minutes and 23 hours (1380 minutes).';
                        } else {
                            $dtStart->setTimezone(new DateTimeZone('UTC'));
                            $payload['start_time'] = $dtStart->format('Y-m-d\TH:i:s\Z');
                            $payload['duration'] = (string)$durationMins;
                            $payload['timezone'] = $timezone;
                        }
                    } catch (Exception $e) {
                        $error = 'Invalid segment start or end time.';
                    }
                } elseif ($startRaw !== '' || $endRaw !== '') {
                    $error = 'Please provide both start and end times.';
                }
                if (isset($_POST['segment_title'])) {
                    $t = trim($_POST['segment_title']);
                    if (strlen($t) > 140) $error = 'Title cannot exceed 140 characters.';
                    else $payload['title'] = mb_substr($t,0,140);
                }
                if (!empty($_POST['segment_category_id'])) $payload['category_id'] = trim($_POST['segment_category_id']);
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
        if (empty($error) && $op === 'cancel_segment') {
            $segId = trim($_POST['segment_id'] ?? '');
            $cancelRaw = strtolower(trim((string)($_POST['cancel_state'] ?? '1')));
            $cancelState = in_array($cancelRaw, ['1', 'true', 'yes', 'on'], true);
            if ($segId === '') {
                $error = 'Segment ID is required to cancel.';
            } else {
                $cancelQuery = [
                    'broadcaster_id' => $broadcasterId,
                    'id' => $segId
                ];
                $urlCancel = 'https://api.twitch.tv/helix/schedule/segment?' . http_build_query($cancelQuery);
                $chCancel = curl_init($urlCancel);
                curl_setopt($chCancel, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($chCancel, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chCancel, CURLOPT_TIMEOUT, 10);
                curl_setopt($chCancel, CURLOPT_SSL_VERIFYPEER, true);
                $headersCancel = [
                    'Client-ID: ' . $clientID,
                    'Authorization: Bearer ' . $_SESSION['access_token'],
                    'Content-Type: application/json'
                ];
                curl_setopt($chCancel, CURLOPT_HTTPHEADER, $headersCancel);
                curl_setopt($chCancel, CURLOPT_POSTFIELDS, json_encode([
                    'is_canceled' => $cancelState
                ]));
                $respCancel = curl_exec($chCancel);
                $codeCancel = curl_getinfo($chCancel, CURLINFO_HTTP_CODE);
                $errCancel = curl_error($chCancel);
                curl_close($chCancel);
                if ($codeCancel === 200) {
                    $success = $cancelState ? 'Segment canceled.' : 'Segment uncanceled.';
                } else {
                    $error = 'Twitch API returned HTTP ' . $codeCancel . '. ' . htmlspecialchars($respCancel ?: $errCancel);
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
if (!isset($error)) {
    $error = null;
}
function fetch_twitch_schedule($broadcasterId, $clientID, $accessToken, $first = 25, $maxPages = 5) {
    $allSegments = [];
    $scheduleData = ['segments' => [], 'vacation' => null];
    $cursor = null;
    $page = 0;

    while (true) {
        $query = [
            'broadcaster_id' => $broadcasterId,
            'first' => $first
        ];
        if (!empty($cursor)) {
            $query['after'] = $cursor;
        }
        $url = 'https://api.twitch.tv/helix/schedule?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Client-ID: ' . $clientID,
            'Authorization: Bearer ' . $accessToken
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [null, 'Twitch API request failed: ' . htmlspecialchars($curlErr)];
        }
        if ($httpCode !== 200) {
            if ($httpCode === 401) {
                return [null, 'Unauthorized. Your Twitch session may need re-linking.'];
            }
            if ($httpCode === 404) {
                return [null, 'No schedule found for this broadcaster.'];
            }
            return [null, 'Twitch API returned HTTP ' . $httpCode . '.'];
        }

        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data'])) {
            return [null, 'Unable to parse Twitch response.'];
        }

        $pageData = $data['data'];
        if ($page === 0) {
            $scheduleData = $pageData;
        }
        if (isset($pageData['vacation'])) {
            $scheduleData['vacation'] = $pageData['vacation'];
        }
        $pageSegments = (isset($pageData['segments']) && is_array($pageData['segments'])) ? $pageData['segments'] : [];
        if (!empty($pageSegments)) {
            $allSegments = array_merge($allSegments, $pageSegments);
        }

        $cursor = null;
        if (isset($data['pagination']['cursor']) && is_string($data['pagination']['cursor']) && $data['pagination']['cursor'] !== '') {
            $cursor = $data['pagination']['cursor'];
        }

        $page++;
        if (empty($cursor) || $page >= $maxPages) {
            break;
        }
    }

    $scheduleData['segments'] = $allSegments;
    return [$scheduleData, null];
}
$broadcasterId = $_SESSION['twitchUserId'] ?? null;
if (empty($broadcasterId)) {
    $error = 'Broadcaster ID not available. Please re-login.';
} else {
    list($schedule, $fetchError) = fetch_twitch_schedule($broadcasterId, $clientID, $_SESSION['access_token'], 25, 5);
    if (!empty($fetchError)) {
        $error = $fetchError;
    }
    // Auto-disable vacation if Twitch still shows it active after end time has passed.
    if (empty($error) && !empty($schedule['vacation']['end_time'])) {
        try {
            $vacEndUtc = new DateTime($schedule['vacation']['end_time'], new DateTimeZone('UTC'));
            $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
            if ($nowUtc > $vacEndUtc) {
                $autoCancelUrl = 'https://api.twitch.tv/helix/schedule/settings?' . http_build_query([
                    'broadcaster_id' => $broadcasterId,
                    'is_vacation_enabled' => 'false'
                ]);
                $chAuto = curl_init($autoCancelUrl);
                curl_setopt($chAuto, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($chAuto, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chAuto, CURLOPT_TIMEOUT, 10);
                curl_setopt($chAuto, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($chAuto, CURLOPT_HTTPHEADER, [
                    'Client-ID: ' . $clientID,
                    'Authorization: Bearer ' . $_SESSION['access_token']
                ]);
                $autoResp = curl_exec($chAuto);
                $autoCode = curl_getinfo($chAuto, CURLINFO_HTTP_CODE);
                $autoErr = curl_error($chAuto);
                curl_close($chAuto);
                if ($autoCode === 204) {
                    $successMsg = 'Vacation auto-ended because the configured end date/time has passed.';
                    if (empty($success)) $success = $successMsg;
                    else $success .= ' ' . $successMsg;
                    // Refresh schedule data after auto-cancel so UI reflects current state.
                    list($refreshSchedule, $refreshError) = fetch_twitch_schedule($broadcasterId, $clientID, $_SESSION['access_token'], 25, 5);
                    if (empty($refreshError) && !empty($refreshSchedule)) {
                        $schedule = $refreshSchedule;
                    }
                } elseif (empty($error)) {
                    $error = 'Auto-cancel of expired vacation failed (HTTP ' . $autoCode . '). ' . htmlspecialchars($autoResp ?: $autoErr);
                }
            }
        } catch (Exception $e) {
            // Ignore invalid vacation timestamps; standard rendering/validation will continue.
        }
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
function segment_duration_minutes($startRfc3339, $endRfc3339) {
    if (empty($startRfc3339) || empty($endRfc3339)) return null;
    try {
        $start = new DateTime($startRfc3339, new DateTimeZone('UTC'));
        $end = new DateTime($endRfc3339, new DateTimeZone('UTC'));
        return (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
    } catch (Exception $e) {
        return null;
    }
}
function fmt_duration_human($minutes) {
    if (!is_numeric($minutes) || (int)$minutes <= 0) return '—';
    $totalMinutes = (int)$minutes;
    $hours = intdiv($totalMinutes, 60);
    $mins = $totalMinutes % 60;
    if ($hours > 0 && $mins > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $mins . 'm';
}
$segments = (isset($schedule['segments']) && is_array($schedule['segments'])) ? $schedule['segments'] : [];
$segmentsByDay = [];
$orderedDayKeys = [];
$selectedDayKeys = [];
$initialDayKeySet = [];
$dayOrderMap = [];
$hasMoreDays = false;
$loadMoreDayBatchSize = 6;
$initialLoadMoreEvents = 0;
$nextSevenSummary = [
    'total' => 0,
    'recurring' => 0,
    'canceled' => 0,
    'vacation' => !empty($schedule['vacation'])
];
try {
    $tzObj = new DateTimeZone($timezone);
} catch (Exception $e) {
    $tzObj = new DateTimeZone('UTC');
}
$nowLocal = new DateTime('now', $tzObj);
$todayKey = $nowLocal->format('Y-m-d');
$tomorrowLocal = clone $nowLocal;
$tomorrowLocal->modify('+1 day');
$tomorrowKey = $tomorrowLocal->format('Y-m-d');
foreach ($segments as $seg) {
    try {
        $startLocal = new DateTime($seg['start_time'] ?? '', new DateTimeZone('UTC'));
        $startLocal->setTimezone($tzObj);
    } catch (Exception $e) {
        continue;
    }
    $dayKey = $startLocal->format('Y-m-d');
    if ($dayKey === $todayKey) {
        $dayLabel = 'Today';
    } elseif ($dayKey === $tomorrowKey) {
        $dayLabel = 'Tomorrow';
    } else {
        $dayLabel = $startLocal->format('l, j M Y');
    }
    if (!isset($segmentsByDay[$dayKey])) {
        $segmentsByDay[$dayKey] = [
            'label' => $dayLabel,
            'sort_ts' => $startLocal->getTimestamp(),
            'segments' => []
        ];
    }
    $segmentsByDay[$dayKey]['segments'][] = $seg;
}
if (!empty($segmentsByDay)) {
    uasort($segmentsByDay, function ($a, $b) {
        return ($a['sort_ts'] ?? 0) <=> ($b['sort_ts'] ?? 0);
    });
    $orderedDayKeys = array_keys($segmentsByDay);
    if (isset($segmentsByDay[$todayKey])) {
        $selectedDayKeys[] = $todayKey;
    }
    if (isset($segmentsByDay[$tomorrowKey]) && !in_array($tomorrowKey, $selectedDayKeys, true)) {
        $selectedDayKeys[] = $tomorrowKey;
    }
    $postTomorrowCount = 0;
    foreach ($orderedDayKeys as $dayKey) {
        if ($postTomorrowCount >= 7) {
            break;
        }
        if (in_array($dayKey, $selectedDayKeys, true)) {
            continue;
        }
        if ($dayKey <= $tomorrowKey) {
            continue;
        }
        $selectedDayKeys[] = $dayKey;
        $postTomorrowCount++;
    }
    $initialDayKeySet = !empty($selectedDayKeys) ? array_fill_keys($selectedDayKeys, true) : [];
    $dayOrderMap = array_flip($orderedDayKeys);
    $hasMoreDays = count($orderedDayKeys) > count($selectedDayKeys);
    if ($hasMoreDays) {
        $remainingDayKeys = [];
        foreach ($orderedDayKeys as $dayKey) {
            if (!isset($initialDayKeySet[$dayKey])) {
                $remainingDayKeys[] = $dayKey;
            }
        }
        $nextLoadDayKeys = array_slice($remainingDayKeys, 0, $loadMoreDayBatchSize);
        foreach ($nextLoadDayKeys as $dayKey) {
            $initialLoadMoreEvents += count($segmentsByDay[$dayKey]['segments'] ?? []);
        }
    }
}

$nextSevenSummary['total'] = 0;
$nextSevenSummary['recurring'] = 0;
$nextSevenSummary['canceled'] = 0;
foreach ($segmentsByDay as $dayKey => $dayData) {
    if (!empty($initialDayKeySet) && !isset($initialDayKeySet[$dayKey])) {
        continue;
    }
    foreach (($dayData['segments'] ?? []) as $seg) {
        $nextSevenSummary['total']++;
        if (!empty($seg['is_recurring'])) $nextSevenSummary['recurring']++;
        if (!empty($seg['is_canceled']) || !empty($seg['canceled_until'])) $nextSevenSummary['canceled']++;
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
        <!-- Vacation / Schedule settings + Add segment -->
        <div class="box has-background-darker mb-4">
            <form method="post" class="columns is-vcentered is-multiline">
                <div class="column is-12">
                    <label class="label has-text-white">Vacation / Off dates</label>
                    <div class="field is-grouped is-align-items-center">
                        <div class="control">
                            <input class="input" type="datetime-local" name="vacation_start" value="<?php echo isset($schedule['vacation']['start_time']) ? date('Y-m-d\TH:i', (new DateTime($schedule['vacation']['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : ''; ?>" />
                        </div>
                        <div class="control">
                            <input class="input" type="datetime-local" name="vacation_end" value="<?php echo isset($schedule['vacation']['end_time']) ? date('Y-m-d\TH:i', (new DateTime($schedule['vacation']['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : ''; ?>" />
                        </div>
                        <div class="control">
                            <button class="button is-link" type="submit" name="action" value="save">Start Vacation</button>
                        </div>
                        <?php if (!empty($schedule['vacation'])): ?>
                        <div class="control">
                            <button class="button is-danger" type="submit" name="action" value="clear">Cancel Vacation</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <p class="help has-text-grey-light">Times are shown in your profile timezone (<?php echo htmlspecialchars($timezone); ?>). If this is wrong, update your timezone in your profile settings.</p>
                </div>
            </form>
            <hr style="border:0; border-top:2px solid #5a5a5a; margin:1.25rem 0 1.25rem 0; opacity:1;">
            <form method="post" class="columns is-vcentered is-multiline" id="createSegmentForm">
                <div class="column is-12">
                    <label class="label has-text-white">Add schedule segment</label>
                    <div class="field is-grouped is-align-items-center">
                        <div class="control">
                            <input class="input" type="datetime-local" name="segment_start" id="create_segment_start" placeholder="Start (local)" required />
                        </div>
                        <div class="control">
                            <input class="input" type="datetime-local" name="segment_end" id="create_segment_end" placeholder="End (local)" required />
                            <input type="hidden" name="segment_duration" id="create_segment_duration" value="" />
                        </div>
                        <div class="control schedule-duration-display">
                            <span class="tag is-dark" id="create_segment_duration_preview">Duration: —</span>
                        </div>
                    </div>
                    <div class="field is-grouped is-align-items-center mt-2">
                        <div class="control" style="min-width:260px; position:relative;">
                            <input class="input" type="text" id="segment_category_search" placeholder="Search category (name or id) — type to search" autocomplete="off" />
                            <input type="hidden" name="segment_category_id" id="segment_category_id" />
                            <div id="segment_category_suggestions" style="display:none; position:absolute; z-index:50; width:100%; background:var(--card-bg); border:1px solid #333; border-radius:4px; margin-top:0.25rem; max-height:200px; overflow:auto;"></div>
                        </div>
                        <div class="control" style="min-width:220px;">
                            <input class="input" type="text" name="segment_title" maxlength="140" placeholder="Title (optional)" />
                        </div>
                        <div class="control">
                            <label class="checkbox"><input type="checkbox" name="segment_recurring" value="1"> Recurring</label>
                        </div>
                        <div class="control">
                            <button class="button is-primary" type="submit" name="action" value="create_segment" id="create_segment_btn">Create</button>
                        </div>
                    </div>
                    <p class="help has-text-grey-light" id="create_segment_duration_help">Duration must be between 30 minutes and 23 hours (1380 minutes). Non-recurring segments may be restricted to partners/affiliates.</p>
                </div>
            </form>
        </div>
        <?php if (empty($segmentsByDay)): ?>
            <div class="box has-background-dark">
                <div class="content has-text-centered">
                    <p class="title is-5 has-text-white">No scheduled segments</p>
                    <p class="has-text-grey-light">You don't have any scheduled stream segments on Twitch. Use the Twitch Creator Dashboard to add schedule entries.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="box has-background-darker mb-4 schedule-summary-box">
                <p class="title is-6 has-text-white mb-3">Stream Summary</p>
                <div class="columns is-mobile is-multiline mb-0">
                    <div class="column is-6-mobile is-3-tablet">
                        <div class="schedule-summary-item">
                            <span class="has-text-grey-light">Streams</span>
                            <strong class="has-text-white" id="scheduleSummaryStreams"><?php echo (int)$nextSevenSummary['total']; ?></strong>
                        </div>
                    </div>
                    <div class="column is-6-mobile is-3-tablet">
                        <div class="schedule-summary-item">
                            <span class="has-text-grey-light">Recurring</span>
                            <strong class="has-text-info" id="scheduleSummaryRecurring"><?php echo (int)$nextSevenSummary['recurring']; ?></strong>
                        </div>
                    </div>
                    <div class="column is-6-mobile is-3-tablet">
                        <div class="schedule-summary-item">
                            <span class="has-text-grey-light">Canceled</span>
                            <strong class="has-text-danger" id="scheduleSummaryCanceled"><?php echo (int)$nextSevenSummary['canceled']; ?></strong>
                        </div>
                    </div>
                    <div class="column is-6-mobile is-3-tablet">
                        <div class="schedule-summary-item">
                            <span class="has-text-grey-light">Vacation</span>
                            <strong class="<?php echo !empty($nextSevenSummary['vacation']) ? 'has-text-warning' : 'has-text-success'; ?>"><?php echo !empty($nextSevenSummary['vacation']) ? 'Active' : 'Off'; ?></strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="columns is-multiline schedule-day-columns">
            <?php foreach ($segmentsByDay as $dayKey => $dayData): ?>
                <?php
                    $isDayInitiallyVisible = empty($initialDayKeySet) || isset($initialDayKeySet[$dayKey]);
                    $dayOrderIndex = isset($dayOrderMap[$dayKey]) ? (int)$dayOrderMap[$dayKey] : 0;
                ?>
                <?php foreach ($dayData['segments'] as $segIndex => $seg):
                            $start = fmt_dt($seg['start_time'] ?? null, $timezone);
                            $end = fmt_dt($seg['end_time'] ?? null, $timezone);
                            $category = $seg['category']['name'] ?? null;
                            $isRecurring = !empty($seg['is_recurring']) ? true : false;
                            $canceled = !empty($seg['is_canceled']) || !empty($seg['canceled_until']);
                            $durationMins = segment_duration_minutes($seg['start_time'] ?? null, $seg['end_time'] ?? null);
                            $startLocalValue = isset($seg['start_time']) ? date('Y-m-d\TH:i', (new DateTime($seg['start_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : '';
                            $endLocalValue = isset($seg['end_time']) ? date('Y-m-d\TH:i', (new DateTime($seg['end_time'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->getTimestamp()) : '';
                            $startDateText = '—';
                            $startTimeText = '—';
                            $endTimeText = '—';
                            $endDateText = '';
                            try {
                                $startDtLocal = new DateTime($seg['start_time'] ?? '', new DateTimeZone('UTC'));
                                $startDtLocal->setTimezone(new DateTimeZone($timezone));
                                $endDtLocal = new DateTime($seg['end_time'] ?? '', new DateTimeZone('UTC'));
                                $endDtLocal->setTimezone(new DateTimeZone($timezone));
                                $startDateText = $startDtLocal->format('D, j M Y');
                                $startTimeText = $startDtLocal->format('g:ia');
                                $endTimeText = $endDtLocal->format('g:ia');
                                if ($startDtLocal->format('Y-m-d') !== $endDtLocal->format('Y-m-d')) {
                                    $endDateText = $endDtLocal->format('D, j M Y');
                                }
                            } catch (Exception $e) {
                                // Keep fallback placeholders above.
                            }
                            ?>
                <div class="column is-12-mobile is-6-tablet is-4-desktop<?php echo $isDayInitiallyVisible ? '' : ' schedule-day-hidden'; ?>" data-day-key="<?php echo htmlspecialchars($dayKey); ?>" data-day-order="<?php echo $dayOrderIndex; ?>" data-segment-recurring="<?php echo $isRecurring ? '1' : '0'; ?>" data-segment-canceled="<?php echo $canceled ? '1' : '0'; ?>"<?php echo $isDayInitiallyVisible ? '' : ' style="display:none;"'; ?>>
                    <div class="schedule-day-group mb-5">
                        <?php if ($segIndex === 0): ?>
                        <h2 class="title is-5 has-text-white mb-3"><?php echo htmlspecialchars($dayData['label']); ?></h2>
                        <?php else: ?>
                        <h2 class="title is-5 has-text-white mb-3" style="visibility:hidden;" aria-hidden="true">&nbsp;</h2>
                        <?php endif; ?>
                        <div class="card schedule-segment-card<?php echo $canceled ? ' schedule-segment-card-canceled' : ''; ?>">
                            <header class="card-header">
                                <p class="card-header-title">
                                    <?php echo htmlspecialchars($seg['title'] ?: 'Untitled'); ?>
                                </p>
                                <span class="schedule-card-tags has-text-grey-light" aria-hidden="true">
                                    <?php if ($isRecurring): ?>
                                        <span class="tag is-small is-info">Recurring</span>
                                    <?php endif; ?>
                                    <?php if ($canceled): ?>
                                        <span class="tag is-small is-danger" data-role="canceled-tag">Canceled</span>
                                    <?php endif; ?>
                                </span>
                            </header>
                            <div class="card-content">
                                <div class="content">
                                    <p class="mb-1"><strong>Start Date:</strong> <?php echo htmlspecialchars($startDateText); ?></p>
                                    <p class="mb-1"><strong>Start Time:</strong> <?php echo htmlspecialchars($startTimeText); ?></p>
                                    <p class="mb-1"><strong>End Time:</strong> <?php echo htmlspecialchars($endTimeText); ?></p>
                                    <?php if ($endDateText !== ''): ?>
                                        <p class="mb-1"><strong>End Date:</strong> <?php echo htmlspecialchars($endDateText); ?></p>
                                    <?php endif; ?>
                                    <p class="mb-2"><strong>Duration</strong><br><?php echo htmlspecialchars(fmt_duration_human($durationMins)); ?></p>
                                    <p class="mb-2"><strong>Category</strong><br><?php echo $category ? htmlspecialchars($category) : '<em>Not specified</em>'; ?></p>
                                </div>
                            </div>
                            <div class="card-content has-background-darker">
                                <form method="post" class="columns is-multiline segment-edit-form" data-is-recurring="<?php echo $isRecurring ? '1' : '0'; ?>">
                                    <input type="hidden" name="segment_id" value="<?php echo htmlspecialchars($seg['id']); ?>" />
                                    <div class="column is-12">
                                        <div class="field is-grouped is-align-items-center is-flex-wrap-wrap">
                                            <div class="control">
                                                <input class="input segment-start-input" type="datetime-local" name="segment_start" value="<?php echo $startLocalValue; ?>" />
                                            </div>
                                            <div class="control">
                                                <input class="input segment-end-input" type="datetime-local" name="segment_end" value="<?php echo $endLocalValue; ?>" />
                                                <input type="hidden" name="segment_duration" class="segment-duration-hidden" value="<?php echo ($durationMins !== null) ? (int)$durationMins : ''; ?>" />
                                            </div>
                                            <div class="control schedule-duration-display">
                                                <span class="tag is-dark segment-duration-preview">Duration: <?php echo htmlspecialchars(fmt_duration_human($durationMins)); ?></span>
                                            </div>
                                            <div class="control" style="min-width:220px; position:relative;">
                                                <input class="input segment-category-search" type="text" placeholder="Search category..." value="<?php echo htmlspecialchars($seg['category']['name'] ?? ''); ?>" data-current-id="<?php echo htmlspecialchars($seg['category']['id'] ?? ''); ?>" autocomplete="off" />
                                                <input type="hidden" name="segment_category_id" class="segment-category-id" value="<?php echo htmlspecialchars($seg['category']['id'] ?? ''); ?>" />
                                                <div class="dropdown suggestions" style="display:none; position:absolute; z-index:50; width:100%; background:var(--card-bg); border:1px solid #333; border-radius:4px; margin-top:0.25rem; max-height:200px; overflow:auto;"></div>
                                            </div>
                                            <div class="control">
                                                <input class="input" type="text" name="segment_title" maxlength="140" value="<?php echo htmlspecialchars($seg['title'] ?? ''); ?>" placeholder="Title" />
                                            </div>
                                            <div class="control">
                                                <button class="button is-link segment-update-btn" type="submit" name="action" value="update_segment">Update</button>
                                            </div>
                                            <div class="control">
                                                <button class="button <?php echo $canceled ? 'is-warning' : 'is-danger'; ?>" type="submit" name="action" value="cancel_segment">
                                                    <?php echo $canceled ? 'Uncancel' : 'Cancel Stream'; ?>
                                                </button>
                                                <input type="hidden" name="cancel_state" value="<?php echo $canceled ? '0' : '1'; ?>" />
                                            </div>
                                            <div class="control">
                                                <button class="button is-danger is-light" type="submit" name="action" value="delete_segment" data-is-recurring="<?php echo $isRecurring ? '1' : '0'; ?>">Delete</button>
                                            </div>
                                        </div>
                                        <p class="help has-text-grey-light segment-duration-help">Duration must be between 30 minutes and 23 hours (1380 minutes).</p>
                                        <p class="help has-text-grey-light">"Cancel Stream" only cancels this segment. For multiple streams in a row, use "Vacation / Off dates" above.</p>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </div>
            <?php if ($hasMoreDays): ?>
                <div class="has-text-centered mt-4">
                    <?php $initialLoadMoreLabel = ($initialLoadMoreEvents === 1) ? 'Load 1 More' : ('Load ' . (int)$initialLoadMoreEvents . ' More'); ?>
                    <button type="button" class="button is-link is-light" id="loadMoreDaysBtn" data-day-batch-size="<?php echo (int)$loadMoreDayBatchSize; ?>"><?php echo htmlspecialchars($initialLoadMoreLabel); ?></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($schedule['vacation'])): ?>
                <div class="box mt-4 has-background-dark">
                    <p class="title is-6 has-text-white">Vacation / Off dates</p>
                    <p class="has-text-grey-light">From <?php echo fmt_dt($schedule['vacation']['start_time'] ?? null, $timezone); ?> to <?php echo fmt_dt($schedule['vacation']['end_time'] ?? null, $timezone); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
// Schedule page: category search + lookup (debounced to avoid Twitch rate limits)
(function(){
    // default debounce set to 1s for Twitch lookups
    const debounce = (fn, ms = 1000) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; };
    const MIN_DURATION = 30;
    const MAX_DURATION = 1380;
    function showToastError(msg){
        Toastify({ text: msg, duration: 3000, gravity: 'bottom', position: 'right', style: { background: '#ff4d4f', color: '#fff' } }).showToast();
    }
    function minutesBetween(startValue, endValue) {
        if (!startValue || !endValue) return null;
        const start = new Date(startValue);
        const end = new Date(endValue);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;
        return Math.floor((end.getTime() - start.getTime()) / 60000);
    }
    function formatDurationHuman(mins) {
        if (mins === null || mins <= 0) return '—';
        const hours = Math.floor(mins / 60);
        const minutes = mins % 60;
        if (hours > 0 && minutes > 0) return `${hours}h ${minutes}m`;
        if (hours > 0) return `${hours}h`;
        return `${minutes}m`;
    }
    function applyDurationState(startInput, endInput, hiddenInput, previewEl, helpEl, actionButton) {
        const mins = minutesBetween(startInput ? startInput.value : '', endInput ? endInput.value : '');
        let valid = false;
        let helpText = 'Duration must be between 30 minutes and 23 hours (1380 minutes).';
        if (mins === null) {
            if (hiddenInput) hiddenInput.value = '';
            if (previewEl) previewEl.textContent = 'Duration: —';
            if (actionButton) actionButton.disabled = true;
            if (endInput) endInput.classList.remove('is-danger');
            if (helpEl) helpEl.textContent = helpText;
            return false;
        }
        if (previewEl) previewEl.textContent = 'Duration: ' + formatDurationHuman(mins);
        if (mins < MIN_DURATION) {
            helpText = 'Duration is too short. Minimum is 30 minutes.';
        } else if (mins > MAX_DURATION) {
            helpText = 'Duration is too long. Maximum is 23 hours (1380 minutes).';
        } else {
            valid = true;
        }
        if (valid) {
            if (hiddenInput) hiddenInput.value = String(mins);
            if (endInput) endInput.classList.remove('is-danger');
            if (actionButton) actionButton.disabled = false;
        } else {
            if (hiddenInput) hiddenInput.value = '';
            if (endInput) endInput.classList.add('is-danger');
            if (actionButton) actionButton.disabled = true;
        }
        if (helpEl) helpEl.textContent = helpText;
        return valid;
    }
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
                if (hidden) hidden.value = it.id || '';
                if (visible) visible.value = it.name || '';
                // visual confirmation
                Toastify({ text: 'Category set: ' + (it.name || '') + ' (id:' + (it.id||'') + ')', duration: 2200, gravity: 'bottom', position: 'right', style: { background: '#22c55e', color: '#fff' } }).showToast();
                // mark resolved for client-side validation
                if (visible) visible.dataset.categoryResolved = '1';
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
    const refreshSummaryCounts = () => {
        const streamsEl = document.getElementById('scheduleSummaryStreams');
        const recurringEl = document.getElementById('scheduleSummaryRecurring');
        const canceledEl = document.getElementById('scheduleSummaryCanceled');
        if (!streamsEl && !recurringEl && !canceledEl) return;
        const visibleColumns = Array.from(document.querySelectorAll('.schedule-day-columns > .column')).filter((col) => {
            const hiddenByClass = col.classList.contains('schedule-day-hidden');
            const hiddenByStyle = col.style && col.style.display === 'none';
            return !hiddenByClass && !hiddenByStyle;
        });
        let total = 0;
        let recurring = 0;
        let canceled = 0;
        visibleColumns.forEach((col) => {
            total += 1;
            if (String(col.getAttribute('data-segment-recurring') || '0') === '1') recurring += 1;
            if (String(col.getAttribute('data-segment-canceled') || '0') === '1') canceled += 1;
        });
        if (streamsEl) streamsEl.textContent = String(total);
        if (recurringEl) recurringEl.textContent = String(recurring);
        if (canceledEl) canceledEl.textContent = String(canceled);
    };
    // Create form duration calculation
    const createStart = document.getElementById('create_segment_start');
    const createEnd = document.getElementById('create_segment_end');
    const createDuration = document.getElementById('create_segment_duration');
    const createDurationPreview = document.getElementById('create_segment_duration_preview');
    const createDurationHelp = document.getElementById('create_segment_duration_help');
    const createButton = document.getElementById('create_segment_btn');
    if (createStart && createEnd) {
        const syncCreateDuration = () => applyDurationState(createStart, createEnd, createDuration, createDurationPreview, createDurationHelp, createButton);
        createStart.addEventListener('input', syncCreateDuration);
        createEnd.addEventListener('input', syncCreateDuration);
        syncCreateDuration();
    }
    // Create-segment search box
    const createSearch = document.getElementById('segment_category_search');
    const createHidden = document.getElementById('segment_category_id');
    const createSug = document.getElementById('segment_category_suggestions');
    if (createSearch) {
        createSearch.addEventListener('input', debounce(async (ev) => {
            const q = ev.target.value.trim();
            // clear resolved flag whenever user types
            ev.target.dataset.categoryResolved = '';
            // if user typed only digits, try lookup by id
            if (/^\d+$/.test(q)) {
                const g = await getGameName(q);
                if (g) {
                    createHidden.value = g.id || '';
                    ev.target.dataset.categoryResolved = '1';
                    Toastify({ text: 'Category resolved: ' + (g.name||''), duration: 1800, gravity: 'bottom', position: 'right', style: { background: '#22c55e', color: '#fff' } }).showToast();
                    return renderSuggestions(createSug, [g]);
                }
            }
            const items = await searchCategories(q);
            renderSuggestions(createSug, items);
        }, 1000));
        // clear hidden id when user edits the visible search
        createSearch.addEventListener('change', (e) => {
            if (createHidden && createHidden.value && createSearch.value.trim() === '') {
                createHidden.value = '';
                createSearch.dataset.categoryResolved = '';
                Toastify({ text: 'Category cleared', duration: 1400, gravity: 'bottom', position: 'right', style: { background: '#3b82f6', color: '#fff' } }).showToast();
            }
        });
        document.addEventListener('click', (ev) => { if (!createSearch.contains(ev.target) && !createSug.contains(ev.target)) createSug.style.display = 'none'; });
        // validate create form before submit
        const createForm = createSearch.closest('form');
        if (createForm) createForm.addEventListener('submit', (ev) => {
            const submitAction = ev.submitter && ev.submitter.value ? ev.submitter.value : '';
            if (submitAction !== 'create_segment') return true;
            const visible = createSearch;
            const hidden = createHidden;
            if (visible && visible.value.trim() !== '' && (!hidden || hidden.value.trim() === '')) {
                ev.preventDefault();
                showToastError('Please select a valid category from the suggestions or clear the category field.');
                visible.focus();
                return false;
            }
            const isDurationValid = applyDurationState(createStart, createEnd, createDuration, createDurationPreview, createDurationHelp, createButton);
            if (!isDurationValid) {
                ev.preventDefault();
                showToastError('Please set an end time that gives a duration between 30 minutes and 23 hours.');
                if (createEnd) createEnd.focus();
                return false;
            }
        });
    }
    // Per-segment search boxes
    document.querySelectorAll('.segment-category-search').forEach(function(input){
        const root = input.closest('.control');
        const hidden = root.querySelector('.segment-category-id');
        const sug = root.querySelector('.suggestions');
        // if input has data-current-id, try to resolve name (in case only id saved)
        const currentId = input.getAttribute('data-current-id');
        if (currentId && !input.value) {
            getGameName(currentId).then(g => { if (g) { input.value = g.name || ''; if (hidden) hidden.value = g.id || ''; input.dataset.categoryResolved = '1'; } });
        }
        const doSearch = debounce(async (ev) => {
            const q = ev.target.value.trim();
            // clear resolved flag whenever user types
            ev.target.dataset.categoryResolved = '';
            if (/^\d+$/.test(q)) {
                const g = await getGameName(q);
                if (g) { if (hidden) hidden.value = g.id || ''; ev.target.dataset.categoryResolved = '1'; Toastify({ text: 'Category resolved: ' + (g.name||''), duration: 1600, gravity: 'bottom', position: 'right', style: { background: '#22c55e', color: '#fff' } }).showToast(); return renderSuggestions(sug, [g]); }
            }
            const items = await searchCategories(q);
            renderSuggestions(sug, items);
        }, 1000);
        input.addEventListener('input', doSearch);
        input.addEventListener('change', () => {
            if (hidden && hidden.value && input.value.trim() === '') {
                hidden.value = '';
                input.dataset.categoryResolved = '';
                Toastify({ text: 'Category cleared', duration: 1400, gravity: 'bottom', position: 'right', style: { background: '#3b82f6', color: '#fff' } }).showToast();
            }
        });
        document.addEventListener('click', (ev) => { if (!root.contains(ev.target)) sug.style.display = 'none'; });
    });
    // Per-segment duration calculation and submit validation
    document.querySelectorAll('.segment-edit-form').forEach(function(f){
        const startInput = f.querySelector('.segment-start-input');
        const endInput = f.querySelector('.segment-end-input');
        const durationHidden = f.querySelector('.segment-duration-hidden');
        const durationPreview = f.querySelector('.segment-duration-preview');
        const durationHelp = f.querySelector('.segment-duration-help');
        const updateBtn = f.querySelector('.segment-update-btn');
        const syncSegmentDuration = () => applyDurationState(startInput, endInput, durationHidden, durationPreview, durationHelp, updateBtn);
        if (startInput && endInput) {
            startInput.addEventListener('input', syncSegmentDuration);
            endInput.addEventListener('input', syncSegmentDuration);
            syncSegmentDuration();
        }
        f.addEventListener('submit', async function(ev){
            const submitAction = ev.submitter && ev.submitter.value ? ev.submitter.value : '';
            if (submitAction === 'cancel_segment') {
                ev.preventDefault();
                const submitBtn = ev.submitter;
                if (!submitBtn) return false;
                const isRecurringSegment = f.getAttribute('data-is-recurring') === '1';
                const cancelStateInput = f.querySelector('input[name="cancel_state"]');
                const wantsCancel = !!(cancelStateInput && String(cancelStateInput.value || '').trim() === '1');
                if (isRecurringSegment && wantsCancel) {
                    const startInputForNotice = f.querySelector('.segment-start-input');
                    const targetStart = startInputForNotice ? new Date(startInputForNotice.value) : null;
                    const msInDay = 24 * 60 * 60 * 1000;
                    const today = new Date();
                    const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
                    const targetDayStart = targetStart && !Number.isNaN(targetStart.getTime())
                        ? new Date(targetStart.getFullYear(), targetStart.getMonth(), targetStart.getDate())
                        : null;
                    const daysAfterToday = targetDayStart ? Math.floor((targetDayStart.getTime() - todayStart.getTime()) / msInDay) : -1;
                    const isWayFuture = daysAfterToday >= 7;
                    if (isWayFuture) {
                        const recurringNote = "Sorry, you can't cancel a recurring stream this far in the future. Twitch only allows recurring stream canceling within a 7-day window including today.";
                        if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
                            await Swal.fire({
                                title: 'Cannot cancel this recurring stream yet',
                                text: recurringNote,
                                icon: 'warning',
                                confirmButtonText: 'OK'
                            });
                        } else {
                            window.alert(recurringNote);
                        }
                        return false;
                    }
                }
                const card = f.closest('.schedule-segment-card');
                const tagContainer = card ? card.querySelector('.schedule-card-tags') : null;
                const wasCanceled = !!(card && card.classList.contains('schedule-segment-card-canceled'));
                submitBtn.disabled = true;
                try {
                    const formData = new FormData(f);
                    formData.set('action', 'cancel_segment');
                    const res = await fetch('schedule.php?ajax=segment_action', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });
                    let payload = null;
                    try {
                        payload = await res.json();
                    } catch (parseErr) {
                        payload = null;
                    }
                    if (payload && payload.debug) {
                        console.log('[Schedule PATCH debug]', payload.debug);
                    }
                    if (!res.ok || !payload || !payload.ok) {
                        if (payload && payload.debug) {
                            console.error('[Schedule PATCH failed]', payload.debug);
                        }
                        const msg = (payload && payload.error) ? payload.error : 'Unable to update stream cancel state right now.';
                        throw new Error(msg);
                    }
                    const isCanceled = !!payload.is_canceled;
                    if (card) {
                        card.classList.toggle('schedule-segment-card-canceled', isCanceled);
                    }
                    const segmentColumn = f.closest('.schedule-day-columns > .column');
                    if (segmentColumn) {
                        segmentColumn.setAttribute('data-segment-canceled', isCanceled ? '1' : '0');
                    }
                    if (cancelStateInput) cancelStateInput.value = isCanceled ? '0' : '1';
                    submitBtn.textContent = isCanceled ? 'Uncancel' : 'Cancel Stream';
                    submitBtn.classList.toggle('is-warning', isCanceled);
                    submitBtn.classList.toggle('is-danger', !isCanceled);
                    if (tagContainer) {
                        let canceledTag = tagContainer.querySelector('[data-role="canceled-tag"]');
                        if (isCanceled && !canceledTag) {
                            canceledTag = document.createElement('span');
                            canceledTag.className = 'tag is-small is-danger';
                            canceledTag.setAttribute('data-role', 'canceled-tag');
                            canceledTag.textContent = 'Canceled';
                            tagContainer.appendChild(canceledTag);
                        } else if (!isCanceled && canceledTag) {
                            canceledTag.remove();
                        }
                    }
                    refreshSummaryCounts();
                    Toastify({ text: payload.message || (isCanceled ? 'Segment canceled.' : 'Segment uncanceled.'), duration: 2200, gravity: 'bottom', position: 'right', style: { background: '#22c55e', color: '#fff' } }).showToast();
                } catch (err) {
                    showToastError(err && err.message ? err.message : 'Unable to update stream cancel state right now.');
                } finally {
                    submitBtn.disabled = false;
                }
                return false;
            }
            if (submitAction === 'delete_segment') {
                if (f.dataset.deleteConfirmed === '1') {
                    f.dataset.deleteConfirmed = '';
                    return true;
                }
                ev.preventDefault();
                let confirmed = false;
                const isRecurringDelete = !!(ev.submitter && ev.submitter.dataset && ev.submitter.dataset.isRecurring === '1');
                const deleteTitle = isRecurringDelete ? 'Delete recurring stream?' : 'Delete this stream?';
                const deleteText = isRecurringDelete
                    ? 'Deleting a recurring stream will remove all future events as well. If you only need to remove this single one, please consider marking it as canceled instead.'
                    : 'This cannot be undone.';
                if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
                    const result = await Swal.fire({
                        title: deleteTitle,
                        text: deleteText,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        reverseButtons: true
                    });
                    confirmed = !!(result && result.isConfirmed);
                } else {
                    confirmed = window.confirm(deleteTitle + ' ' + deleteText);
                }
                if (confirmed) {
                    f.dataset.deleteConfirmed = '1';
                    if (ev.submitter && typeof f.requestSubmit === 'function') {
                        f.requestSubmit(ev.submitter);
                    } else {
                        f.submit();
                    }
                }
                return false;
            }
            if (submitAction !== 'update_segment') return true;
            const visible = f.querySelector('.segment-category-search');
            const hidden = f.querySelector('.segment-category-id');
            if (visible && visible.value.trim() !== '' && (!hidden || hidden.value.trim() === '')) {
                ev.preventDefault();
                showToastError('Please select a valid category from suggestions or clear the category field.');
                visible.focus();
                return false;
            }
            const isDurationValid = applyDurationState(startInput, endInput, durationHidden, durationPreview, durationHelp, updateBtn);
            if (!isDurationValid) {
                ev.preventDefault();
                showToastError('Please set an end time that gives a duration between 30 minutes and 23 hours.');
                if (endInput) endInput.focus();
                return false;
            }
        });
    });
    const loadMoreDaysBtn = document.getElementById('loadMoreDaysBtn');
    if (loadMoreDaysBtn) {
        const dayBatchSize = Number(loadMoreDaysBtn.getAttribute('data-day-batch-size')) || 6;
        const getHiddenColumns = () => Array.from(document.querySelectorAll('.schedule-day-columns .schedule-day-hidden'));
        const getHiddenDayOrders = () => {
            const hiddenColumns = getHiddenColumns();
            const orderSet = new Set();
            hiddenColumns.forEach((col) => {
                const order = Number(col.getAttribute('data-day-order'));
                if (!Number.isNaN(order)) orderSet.add(order);
            });
            return Array.from(orderSet).sort((a, b) => a - b);
        };
        const getNextLoadEventCount = () => {
            const nextOrders = getHiddenDayOrders().slice(0, dayBatchSize);
            let count = 0;
            nextOrders.forEach((order) => {
                count += document.querySelectorAll('.schedule-day-columns .schedule-day-hidden[data-day-order="' + order + '"]').length;
            });
            return count;
        };
        const updateButtonState = () => {
            const hiddenColumns = getHiddenColumns();
            if (hiddenColumns.length === 0) {
                loadMoreDaysBtn.style.display = 'none';
                return;
            }
            const nextEventCount = getNextLoadEventCount();
            const labelCount = nextEventCount > 0 ? nextEventCount : hiddenColumns.length;
            loadMoreDaysBtn.textContent = labelCount === 1 ? 'Load 1 More' : ('Load ' + labelCount + ' More');
        };
        loadMoreDaysBtn.addEventListener('click', () => {
            const nextOrders = getHiddenDayOrders().slice(0, dayBatchSize);
            if (nextOrders.length === 0) {
                loadMoreDaysBtn.style.display = 'none';
                return;
            }
            nextOrders.forEach((order) => {
                const dayColumns = document.querySelectorAll('.schedule-day-columns .schedule-day-hidden[data-day-order="' + order + '"]');
                dayColumns.forEach((col) => {
                    col.style.display = '';
                    col.classList.remove('schedule-day-hidden');
                });
            });
            refreshSummaryCounts();
            updateButtonState();
        });
        updateButtonState();
    }
    refreshSummaryCounts();
})();
</script>
<?php
$content = ob_get_clean();
include 'layout.php';
?>