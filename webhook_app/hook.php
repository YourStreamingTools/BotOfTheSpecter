<?php
// Define file paths
$WEBHOOK_LOG = "/var/www/logs/webhook.txt";
$EVENTSUB_LOG = "/var/www/logs/eventsub.txt";
$SECRET = ""; // CHANGE TO MAKE THIS WORK

// Retrieve headers and body from the incoming request
$headers = getallheaders();
$body = file_get_contents('php://input');

// Log incoming webhook data
webhook_log($headers, $body);

// Verify the authenticity of the request
if(!verify_request($headers, $body)){
    http_response_code(400);
    die();
}

// Determine the type of Twitch EventSub notification and process accordingly
$msg_type = strtolower($headers["Twitch-Eventsub-Message-Type"]);
$body = json_decode($body);

switch($msg_type){
    case "webhook_callback_verification":
        challenge_response($headers, $body);
        break;
    case "notification":
        // Extract broadcaster username from the body if available
        $user = $body->event->broadcaster_user_name ?? null;
        process_notification($headers, $body, $user);
        break;
    case "revocation":
        process_revocation($headers, $body);
        break;
    default:
        http_response_code(400);
        die();
}

// Process incoming EventSub notification
function process_notification($headers, $body, $user){
    switch($body->subscription->type){
        case "channel.follow":
            // Handle channel follow notification
            $user_id = $body->event->user_id; // Follower's user ID
            $user_name = $body->event->user_name; // Follower's user name
        
            // Path to the SQLite database
            $SQLite = "/var/www/bot/commands/$user.db";
        
            // Attempt to connect to the SQLite database
            try {
                $db = new SQLite3($SQLite);
            } catch (Exception $e) {
                eventsub_log("Error Connecting to DB: " . $e->getMessage());
                die();
            }
        
            // Prepare the SQL statement for adding a follower
            $stmt = $db->prepare('INSERT INTO followers_data (user_id, user_name) VALUES (:user_id, :user_name)');
            if ($stmt) {
                // Bind the parameters to the query
                $stmt->bindValue(':user_id', $user_id, SQLITE3_TEXT);
                $stmt->bindValue(':user_name', $user_name, SQLITE3_TEXT);
        
                // Execute the query and check if it was successful
                $result = $stmt->execute();
                if ($result) {
                    eventsub_log("Follower added successfully with user ID: $user_id and user name: $user_name");
                } else {
                    eventsub_log("Error adding follower to the database");
                }
            } else {
                eventsub_log("Error preparing statement for inserting follower");
            }
            break;
        case "channel.raid":
            // Handle channel raid notification
            $raider_name = $body->event->from_broadcaster_user_name; // Raider's name
            $viewers = $body->event->viewers; // Number of viewers

            // Path to the SQLite database
            $SQLite = "/var/www/bot/commands/$user.db";

            // Attempt to connect to the SQLite database
            try {
                $db = new SQLite3($SQLite);
            } catch (Exception $e) {
                eventsub_log("Error Connecting to DB: " . $e->getMessage());
                die();
            }

            // Prepare the SQL statement for adding a raid
            $stmt = $db->prepare('INSERT INTO raid_data (raider_name, viewers) VALUES (:raider_name, :viewers)');
            if ($stmt) {
                // Bind the parameters to the query
                $stmt->bindValue(':raider_name', $raider_name, SQLITE3_TEXT);
                $stmt->bindValue(':viewers', $viewers, SQLITE3_INTEGER);
        
                // Execute the query and check if it was successful
                $result = $stmt->execute();
                if ($result) {
                    eventsub_log("Raid added successfully with raider: $raider_name and viewers: $viewers");
                } else {
                    eventsub_log("Error adding raid to the database");
                }
            } else {
                eventsub_log("Error preparing statement for inserting raid");
            }
            break;
        case "channel.cheer":
            // Handle channel cheer notification
            $user_id = $body->event->user_id; // Cheering user's ID
            $user_name = $body->event->user_name; // Cheering user's name
            $bits = $body->event->bits; // Number of bits cheered

            // Path to the SQLite database
            $SQLite = "/var/www/bot/commands/$user.db";

            // Attempt to connect to the SQLite database
            try {
                $db = new SQLite3($SQLite);
            } catch (Exception $e) {
                eventsub_log("Error Connecting to DB: " . $e->getMessage());
                die();
            }

            // Prepare the SQL statement for adding cheer data
            $stmt = $db->prepare('INSERT INTO bits_data (user_id, user_name, bits) VALUES (:user_id, :user_name, :bits)');
            if ($stmt) {
                // Bind the parameters to the query
                $stmt->bindValue(':user_id', $user_id, SQLITE3_TEXT);
                $stmt->bindValue(':user_name', $user_name, SQLITE3_TEXT);
                $stmt->bindValue(':bits', $bits, SQLITE3_INTEGER);
        
                // Execute the query and check if it was successful
                $result = $stmt->execute();
                if ($result) {
                    eventsub_log("Cheer added successfully with user ID: $user_id, user name: $user_name, and bits: $bits");
                } else {
                    eventsub_log("Error adding cheer to the database");
                }
            } else {
                eventsub_log("Error preparing statement for inserting cheer");
            }
            break;
        case "channel.subscribe":
            // Handle channel subscribe notification
            $user_id = $body->event->user_id; // Subscriber's user ID
            $user_name = $body->event->user_name; // Subscriber's name
            $sub_plan = $body->event->tier; // Subscription tier/plan
            $months = 1; // Placeholder value for their first sub to the channel, being their first month.

            // Path to the SQLite database
            $SQLite = "/var/www/bot/commands/$user.db";

            // Attempt to connect to the SQLite database
            try {
                $db = new SQLite3($SQLite);
            } catch (Exception $e) {
                eventsub_log("Error Connecting to DB: " . $e->getMessage());
                die();
            }

            // Prepare the SQL statement for adding subscription data
            $stmt = $db->prepare('INSERT INTO subscription_data (user_id, user_name, sub_plan, months) VALUES (:user_id, :user_name, :sub_plan, :months)');
            if ($stmt) {
                // Bind the parameters to the query
                $stmt->bindValue(':user_id', $user_id, SQLITE3_TEXT);
                $stmt->bindValue(':user_name', $user_name, SQLITE3_TEXT);
                $stmt->bindValue(':sub_plan', $sub_plan, SQLITE3_TEXT);
                $stmt->bindValue(':months', $months, SQLITE3_INTEGER);
        
                // Execute the query and check if it was successful
                $result = $stmt->execute();
                if ($result) {
                    eventsub_log("Subscription added successfully with user ID: $user_id, user name: $user_name, subscription plan: $sub_plan, and months: $months");
                } else {
                    eventsub_log("Error adding subscription to the database");
                }
            } else {
                eventsub_log("Error preparing statement for inserting subscription");
            }
            break;            
        case "stream.online":
            // Handle stream online notification
            // Extract relevant data from the notification
            $user = $body->event->broadcaster_user_name;
            $time = $body->event->started_at;
            eventsub_log("Type: Stream Online [$user] at $time");
            die();
            break;
        case "stream.offline":
            // Handle stream offline notification
            // Extract relevant data from the notification
            $user = $body->event->broadcaster_user_name;
            eventsub_log("Type: Stream Offline [$user]");
            die();
            break;
    }
    die();
}

// Process revocation notification
function process_revocation($headers, $body){
    // Extract relevant information from the revocation body
    $id = $body->subscription->id;
    $status = $body->subscription->status;
    $type = $body->subscription->type;
    
    // Log the revocation event
    eventsub_log("Subscription Revoked ($id) - Type: $type - Reason: $status");
    
    // Handle different revocation scenarios
    switch($status){
        case "user_removed":
            // Handle user removal revocation
            handle_user_removed();
            break;
        case "authorization_revoked":
            // Handle authorization revocation
            handle_authorization_revoked();
            break;
        case "notification_failures_exceeded":
            // Handle notification failures exceeded
            handle_notification_failures_exceeded();
            break;
        case "version_removed":
            // Handle version removed
            handle_version_removed();
            break;
        default:
            // Handle unknown revocation reason
            handle_unknown_reason();
    }
    
    // Stop further execution after handling revocation
    die();
}

// Custom handling functions for each revocation scenario
function handle_user_removed() {
    // Add code for handling user removal revocation
    eventsub_log("User has been removed from the platform. Subscription revoked.");
}

function handle_authorization_revoked() {
    // Add code for handling authorization revocation
    eventsub_log("Authorization revoked for the user. Subscription revoked.");
}

function handle_notification_failures_exceeded() {
    // Add code for handling notification failures exceeded
    eventsub_log("Notification failures exceeded for the subscription. Subscription revoked.");
}

function handle_version_removed() {
    // Add code for handling version removed
    eventsub_log("API version used by the application is no longer supported. Subscription revoked.");
}

function handle_unknown_reason() {
    // Add code for handling unknown revocation reasons
    eventsub_log("Unknown revocation reason received. Subscription revoked.");
}

// Function to log events
function eventsub_log($msg){
    global $EVENTSUB_LOG;
    $msg .= "\n";
    $file = fopen($EVENTSUB_LOG, "a");
    fwrite($file, $msg);
    fclose($file);
}

// Verify the authenticity of the incoming request
function verify_request($headers, $body){
    global $SECRET;
    $hmac_data = $headers["Twitch-Eventsub-Message-Id"];
    $hmac_data .= $headers["Twitch-Eventsub-Message-Timestamp"];
    $hmac_data .= $body;
    $hmac = "sha256=".hash_hmac("sha256", $hmac_data, $SECRET);
    if($hmac == $headers["Twitch-Eventsub-Message-Signature"]){
        return TRUE;
    }
    return FALSE;
}

// Respond to webhook verification challenge
function challenge_response($headers, $body){
    header("Content-Type: text/plain");
    die($body->challenge);
}

// Log EventSub messages
function eventsub_log($msg){
    global $EVENTSUB_LOG;
    $msg .= "\n";
    $file = fopen($EVENTSUB_LOG, "a");
    fwrite($file, $msg);
    fclose($file);
}

// Log incoming webhook data
function webhook_log($headers, $body){
    global $WEBHOOK_LOG;
    $file = fopen($WEBHOOK_LOG, "a");
    $header_names = array_keys($headers);
    
    foreach($header_names as $name){
        fwrite($file, $name . ": " . $headers[$name] . "\n");
    }

    fwrite($file, "\n");
    fwrite($file, $body."\n");
    fclose($file);
}
?>