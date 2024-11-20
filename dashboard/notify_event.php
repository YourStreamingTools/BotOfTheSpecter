<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Prepare a response array
$response = ['success' => false, 'message' => '', 'output' => ''];

try {
    // Get the event type and api_key from the request
    if (isset($_POST['event']) && isset($_POST['api_key'])) {
        $event = $_POST['event'];
        $api_key = $_POST['api_key'];
        // Initialize curl
        $ch = curl_init();
        // Base URL for the WebSocket notification
        $url = "https://websocket.botofthespecter.com/notify?code=$api_key&event=$event";
        // Add event-specific parameters
        $params = [];
        if ($event === "WALKON" && isset($_POST['channel'], $_POST['user'])) {
            $params['channel'] = $_POST['channel'];
            $params['user'] = $_POST['user'];
        } elseif ($event === "DEATHS" && isset($_POST['death'], $_POST['game'])) {
            $params['death-text'] = $_POST['death'];
            $params['game'] = $_POST['game'];
        } elseif (in_array($event, ["STREAM_ONLINE", "STREAM_OFFLINE"])) {
            // No additional parameters needed
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
        } elseif ($event === "TWITCH_RAID" && isset($_POST['user'], $_POST['raid_viewers'])) {
            $params['twitch-username'] = $_POST['user'];
            $params['twitch-raid'] = $_POST['raid_viewers'];
        } elseif ($event === "TTS" && isset($_POST['text'])) {
            $params['text'] = $_POST['text'];
        } elseif (in_array($event, ["SUBATHON_START", "SUBATHON_STOP", "SUBATHON_PAUSE", "SUBATHON_RESUME", "SUBATHON_ADD_TIME"]) && isset($_POST['additional_data'])) {
            $additional_data = json_decode($_POST['additional_data'], true);
            if (is_array($additional_data)) {
                $params = array_merge($params, $additional_data);
            } else {
                $response['message'] = "Invalid additional_data format.";
                echo json_encode($response);
                exit();
            }
        } elseif ($event === "SOUND_ALERT" && isset($_POST['sound'], $_POST['channel_name'])) {
            $params['sound'] = "https://soundalerts.botofthespecter.com/" . $_POST['channel_name'] . "/" . $_POST['sound'];
        } else {
            $response['message'] = "Event '$event' requires additional parameters or is not recognized.";
            echo json_encode($response);
            exit();
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
        curl_close($ch);
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