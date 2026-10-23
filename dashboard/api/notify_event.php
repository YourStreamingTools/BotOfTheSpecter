<?php 
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();

require_once '/var/www/lib/require_auth.php';

require_once '/var/www/config/database.php';

// Prepare a response array
$response = ['success' => false, 'message' => '', 'output' => ''];

// Pages that should not expose the API key in their JS (e.g. Specter Modules)
// leave api_key out; use the signed-in channel's key, same-origin callers only.
if (isset($_POST['event']) && !isset($_POST['api_key']) && !empty($_SESSION['api_key'])) {
    require_once __DIR__ . '/../includes/write_guard.php';
    if (dashboard_same_origin_ok()) {
        $_POST['api_key'] = (string) $_SESSION['api_key'];
    }
}

try {
    // Get the event type and api_key from the request
    if (isset($_POST['event']) && isset($_POST['api_key'])) {
        $event = $_POST['event'];
        $api_key = $_POST['api_key'];
        // Initialize curl
        $ch = curl_init();
        // Base URL for the WebSocket notification
        // V2: the key goes in the X-API-KEY header, never the URL.
        $url = "https://websocket.botofthespecter.com/notify?event=" . urlencode($event);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . str_replace(["\r", "\n"], '', $api_key)]);
        // Add event-specific parameters
        $params = [];
        if ($event === "WALKON" && isset($_POST['channel'], $_POST['user'])) {
            $params['channel'] = $_POST['channel'];
            $params['user'] = $_POST['user'];
        } elseif ($event === "DEATHS" && isset($_POST['death'], $_POST['game'])) {
            $params['death-text'] = $_POST['death'];
            $params['game'] = $_POST['game'];
        } elseif (in_array($event, ["STREAM_ONLINE", "STREAM_OFFLINE", "OVERLAY_REFRESH"])) {
            // No additional parameters needed (OVERLAY_REFRESH = full browser-source reload)
        } elseif ($event === "WEATHER" && isset($_POST['weather'])) {
            $params['location'] = $_POST['weather'];
        } elseif ($event === "TWITCH_FOLLOW" && isset($_POST['user'])) {
            $params['twitch-username'] = $_POST['user'];
        } elseif ($event === "TWITCH_CHEER" && isset($_POST['user'], $_POST['cheer_amount'])) {
            $params['twitch-username'] = $_POST['user'];
            $params['twitch-cheer-amount'] = $_POST['cheer_amount'];
        } elseif ($event === "TWITCH_SUB" && isset($_POST['user'], $_POST['sub_tier'], $_POST['sub_months'])) {
            $params['twitch-username'] = $_POST['user'];
            $params['twitch-tier'] = $_POST['sub_tier'];
            $params['twitch-sub-months'] = $_POST['sub_months'];
        } elseif ($event === "TWITCH_GIFT_SUB" && isset($_POST['user'])) {
            $params['twitch-username'] = $_POST['user'];
            $params['twitch-gift-count'] = max(1, intval($_POST['gift_count'] ?? 1));
            $params['twitch-tier'] = $_POST['sub_tier'] ?? '1';
        } elseif ($event === "TWITCH_CHANNELPOINTS" && isset($_POST['user'])) {
            $params['twitch-username'] = $_POST['user'];
            $params['reward_id'] = $_POST['reward_id'] ?? '';
            $params['reward_title'] = $_POST['reward_title'] ?? '';
            $params['cost'] = $_POST['cost'] ?? '';
            $params['message'] = $_POST['message'] ?? '';
        } elseif ($event === "WEATHER_DATA") {
            // Sample card for the Alerts page live test, in the shape api.py sends.
            $params['weather_data'] = json_encode([
                'status' => 'Partly cloudy',
                'temperature' => '22°C | 72°F',
                'wind' => '10 kph | 6 mph NE',
                'humidity' => 'Humidity: 60%',
                'icon' => 'https://openweathermap.org/img/wn/02d@2x.png',
                'location' => 'Sydney',
            ]);
        } elseif ($event === "TWITCH_RAID" && isset($_POST['user'], $_POST['raid_viewers'])) {
            $params['twitch-username'] = $_POST['user'];
            $params['twitch-raid'] = $_POST['raid_viewers'];
        } elseif ($event === "TWITCH_HYPE_TRAIN" && isset($_POST['level'])) {
            $params['twitch-hype-level'] = intval($_POST['level']);
            if (isset($_POST['user'])) $params['twitch-username'] = $_POST['user'];
        } elseif ($event === "FOURTHWALL") {
            // Mirror Fourthwall webhook envelope: { type, data: { ...per-type fields } }
            $fwType   = $_POST['fourthwall_type'] ?? 'DONATION';
            $username = $_POST['user']     ?? 'TestUser';
            $amount   = $_POST['amount']   ?? '5.00';
            $currency = $_POST['currency'] ?? 'USD';
            $itemName = $_POST['item']     ?? 'Test Item';
            $message  = $_POST['message']  ?? '';
            $eventData = [];
            if ($fwType === 'SUBSCRIPTION_PURCHASED') {
                $eventData['nickname'] = $username;
                $eventData['subscription'] = [
                    'variant' => [
                        'interval' => $_POST['interval'] ?? 'monthly',
                        'amount'   => ['value' => $amount, 'currency' => $currency],
                    ],
                ];
            } else {
                $eventData['username'] = $username;
                $eventData['amounts']  = ['total' => ['value' => $amount, 'currency' => $currency]];
                if ($fwType === 'ORDER_PLACED') {
                    $eventData['offers'] = [['name' => $itemName, 'variant' => ['quantity' => 1]]];
                } elseif ($fwType === 'GIVEAWAY_PURCHASED') {
                    $eventData['offer'] = ['name' => $itemName];
                } elseif ($fwType === 'DONATION' && $message !== '') {
                    $eventData['message'] = $message;
                }
            }
            $params['data'] = json_encode(['type' => $fwType, 'data' => $eventData]);
        } elseif ($event === "PATREON") {
            // Patreon webhooks are JSON:API shaped. Build the same envelope so
            // the overlay's classifyEvent + tolerant parser fire correctly.
            $patreonType = $_POST['patreon_type'] ?? 'pledge';
            $cents       = isset($_POST['amount']) ? (int) round(floatval($_POST['amount']) * 100) : 500;
            $lifetimeCt  = isset($_POST['lifetime']) ? (int) round(floatval($_POST['lifetime']) * 100) : null;
            $patronStatus = $patreonType === 'cancelled' ? 'former_patron' : 'active_patron';
            $attrs = [
                'full_name'                       => $_POST['user'] ?? 'TestUser',
                'patron_status'                   => $patronStatus,
                'currency_code'                   => $_POST['currency'] ?? 'USD',
                'currently_entitled_amount_cents' => $cents,
            ];
            if ($lifetimeCt !== null) {
                $attrs['campaign_lifetime_support_cents'] = $lifetimeCt;
            }
            // Mark as an update (vs first pledge) via last_charge_status + lifetime
            if ($patreonType === 'update') {
                $attrs['last_charge_status'] = 'Paid';
                if (!isset($attrs['campaign_lifetime_support_cents'])) {
                    $attrs['campaign_lifetime_support_cents'] = $cents * 4;
                }
            }
            $envelope = [
                'data' => [
                    'type'       => 'member',
                    'attributes' => $attrs,
                ],
            ];
            if (!empty($_POST['tier_name'])) {
                $envelope['included'] = [
                    [
                        'type'       => 'tier',
                        'attributes' => ['title' => $_POST['tier_name']],
                    ],
                ];
            }
            $params['data'] = json_encode($envelope);
        } elseif ($event === "KOFI") {
            $kofiPayload = [
                'type'      => $_POST['kofi_type'] ?? 'Donation',
                'from_name' => $_POST['user']      ?? 'TestUser',
                'amount'    => $_POST['amount']    ?? '5.00',
                'currency'  => $_POST['currency']  ?? 'USD',
            ];
            if (isset($_POST['message']))   $kofiPayload['message']   = $_POST['message'];
            if (isset($_POST['tier_name'])) $kofiPayload['tier_name'] = $_POST['tier_name'];
            if (($_POST['kofi_type'] ?? '') === 'Subscription') {
                $kofiPayload['is_subscription_payment']       = true;
                $kofiPayload['is_first_subscription_payment'] = true;
            }
            $params['data'] = json_encode($kofiPayload);
        } elseif ($event === "STREAM_BINGO_STARTED") {
            if (isset($_POST['is_sub_only']))  $params['is_sub_only']  = intval($_POST['is_sub_only']);
            if (isset($_POST['events_count'])) $params['events_count'] = intval($_POST['events_count']);
        } elseif ($event === "STREAM_BINGO_ENDED") {
            // no extras
        } elseif ($event === "STREAM_BINGO_EVENT_CALLED") {
            if (isset($_POST['display_number'])) $params['display_number'] = intval($_POST['display_number']);
            if (isset($_POST['event_name']))     $params['event_name']     = $_POST['event_name'];
            if (isset($_POST['event_id']))       $params['event_id']       = $_POST['event_id'];
        } elseif ($event === "STREAM_BINGO_WINNER") {
            if (isset($_POST['player_name'])) $params['player_name'] = $_POST['player_name'];
            if (isset($_POST['rank']))        $params['rank']        = intval($_POST['rank']);
            if (isset($_POST['rank_text']))   $params['rank_text']   = $_POST['rank_text'];
        } elseif ($event === "TWITCH_CHARITY" && isset($_POST['user'], $_POST['amount'])) {
            $params['twitch-username']      = $_POST['user'];
            $params['twitch-charity-amount']= $_POST['amount'];
            // amount_value is the numeric form used by condition matching.
            // For the live test we extract the leading number from "100.00 USD".
            if (isset($_POST['amount_value'])) {
                $params['twitch-charity-value'] = floatval($_POST['amount_value']);
            } else {
                preg_match('/[\d.]+/', $_POST['amount'], $m);
                $params['twitch-charity-value'] = isset($m[0]) ? floatval($m[0]) : 0;
            }
            if (isset($_POST['charity_name'])) $params['twitch-charity-name'] = $_POST['charity_name'];
        } elseif ($event === "TWITCH_AD_BREAK") {
            $duration = isset($_POST['duration_seconds']) ? intval($_POST['duration_seconds']) : 15;
            if ($duration < 1) $duration = 15;
            if ($duration > 180) $duration = 180;
            $params['duration_seconds'] = $duration;
            $params['started_at'] = time();
            $params['ends_at'] = time() + $duration;
        } elseif ($event === "TWITCH_AD_BREAK_END") {
            // Hide the ad card. No extra fields.
        } elseif ($event === "DISCORD_JOIN") {
            $params['member'] = $_POST['member'] ?? 'TestUser';
        } elseif ($event === "WATCH_STREAK") {
            $params['username'] = $_POST['username'] ?? ($_POST['user'] ?? 'TestUser');
            $params['streak'] = isset($_POST['streak']) ? intval($_POST['streak']) : 7;
        } elseif ($event === "TWITCH_GOAL_BEGIN" || $event === "TWITCH_GOAL_END") {
            $params['description'] = $_POST['description'] ?? 'Follower goal';
            $params['current_amount'] = $_POST['current_amount'] ?? '10';
            $params['target_amount'] = $_POST['target_amount'] ?? '100';
            $params['goal_type'] = $_POST['goal_type'] ?? 'follower';
        } elseif ($event === "TTS" && isset($_POST['text'])) {
            $params['text'] = $_POST['text'];
            // Optional saved voice, so a test sounds like the streamer's real TTS.
            foreach (['voice', 'language', 'style', 'expressive_voice'] as $ttsField) {
                if (!empty($_POST[$ttsField])) $params[$ttsField] = $_POST[$ttsField];
            }
        } elseif ($event === "SUBATHON_ADD_TIME" && isset($_POST['added_minutes'])) {
            $mins = intval($_POST['added_minutes']);
            if ($mins < 1) $mins = 1;
            if ($mins > 10080) $mins = 10080;
            $params['added_minutes'] = $mins;
        } elseif (in_array($event, ["SUBATHON_START", "SUBATHON_STOP", "SUBATHON_PAUSE", "SUBATHON_RESUME", "SUBATHON_ADD_TIME"]) && isset($_POST['additional_data'])) {
            $additional_data = json_decode($_POST['additional_data'], true);
            if (is_array($additional_data)) {
                $params = array_merge($params, $additional_data);
            } else {
                $response['message'] = "Invalid additional_data format.";
                echo json_encode($response);
                exit();
            }
        } elseif ($event === "PET_SETTINGS_UPDATE") {
            // Overlay hot-reload of pet config/sprites (no extra parameters)
        } elseif ($event === "PET_REACT" && isset($_POST['animation'])) {
            $params['animation'] = $_POST['animation'];
            if (isset($_POST['bubble_text'])) $params['bubble_text'] = $_POST['bubble_text'];
            if (isset($_POST['personalized'])) $params['personalized'] = $_POST['personalized'];
        } elseif ($event === "PET_STATE") {
            foreach (['happiness', 'hunger', 'energy', 'level', 'xp', 'last_interaction_at', 'decay_happiness', 'decay_hunger', 'decay_energy', 'stream_online'] as $petField) {
                if (isset($_POST[$petField])) {
                    $params[$petField] = $_POST[$petField];
                }
            }
            if (isset($_POST['decay_rates'])) {
                $rates = $_POST['decay_rates'];
                $params['decay_rates'] = is_array($rates) ? json_encode($rates) : $rates;
            }
        } elseif (($event === "SOUND_ALERT" && isset($_POST['sound'], $_POST['channel_name']))
               || ($event === "VIDEO_ALERT" && isset($_POST['video'], $_POST['channel_name']))) {
            // Single migration check shared by sound/video test playback. Reads the
            // global flag (website.users.new_media) so this stays consistent with
            // the bot's own routing in beta.py / beta-v6.py.
            $channelName = $_POST['channel_name'];
            $isMigrated = false;
            $mnConn = new mysqli($db_servername, $db_username, $db_password, 'website');
            if (!$mnConn->connect_error) {
                if ($flagStmt = $mnConn->prepare("SELECT new_media FROM users WHERE username = ? LIMIT 1")) {
                    $flagStmt->bind_param('s', $channelName);
                    $flagStmt->execute();
                    $flagRes = $flagStmt->get_result();
                    if ($flagRow = $flagRes->fetch_assoc()) {
                        $isMigrated = !empty($flagRow['new_media']);
                    }
                    $flagStmt->close();
                }
                $mnConn->close();
            }
            if ($event === "SOUND_ALERT") {
                $soundBase = $isMigrated ? 'media.botofthespecter.com' : 'soundalerts.botofthespecter.com';
                // File names can contain spaces, # or ?, so each path segment is URL-encoded
                $params['sound'] = "https://$soundBase/" . $channelName . "/" . implode('/', array_map('rawurlencode', explode('/', (string) $_POST['sound'])));
            } else {
                $videoBase = $isMigrated ? 'media.botofthespecter.com' : 'videoalerts.botofthespecter.com';
                $params['video'] = "https://$videoBase/" . $channelName . "/" . implode('/', array_map('rawurlencode', explode('/', (string) $_POST['video'])));
            }
        } else {
            $response['message'] = "Event '$event' requires additional parameters or is not recognized.";
            echo json_encode($response);
            exit();
        }
        // Live tests from the Alerts page name the variant the overlay should play.
        if (isset($_POST['test_variant'])) {
            $params['test_variant'] = intval($_POST['test_variant']);
        }
        // Append parameters to the URL
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }
        // Set the URL
        curl_setopt($ch, CURLOPT_URL, $url);
        // Return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // Execute the curl session
        $output = curl_exec($ch);
        // Check for curl errors
        if ($output === false) {
            $response['message'] = 'Curl error: ' . curl_error($ch);
        } else {
            $response['success'] = true;
            $response['message'] = 'Event sent successfully.';
            $response['output'] = $output;
        }
        // Close the curl session
} else {
        $response['message'] = 'No event or api_key specified.';
    }
} catch (Exception $e) {
    $response['message'] = 'Exception: ' . $e->getMessage();
}

// Output the JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>

