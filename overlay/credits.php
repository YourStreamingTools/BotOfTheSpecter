<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

$status = "";

// Database credentials
include '/var/www/config/database.php';
$maindb = 'website';

function build_event_section($user_db, $event, $section_name, $clean_data = false) {
    $section_html = "<h2 class='subtitle has-text-white'>$section_name</h2><ul class='content has-text-white'>";
    if ($stmt = $user_db->prepare("SELECT username, event, data FROM stream_credits WHERE event = ?")) {
        $stmt->bind_param("s", $event);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data = sanitize_input($row['data']);
                if ($clean_data) {
                    $section_html .= "<li>" . sanitize_input($row['username']) . "</li>";
                } else {
                    $section_html .= "<li>" . sanitize_input($row['username']) . " - " . $data . "</li>";
                }
            }
        }
        $stmt->close();
    }
    $section_html .= "</ul>";
    return $section_html;
}

function build_chatters_section($user_db) {
    $section_html = "<h2 class='subtitle has-text-white'>Chatters</h2><ul class='content has-text-white'>";
    if ($stmt = $user_db->prepare("SELECT username FROM seen_today")) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $section_html .= "<li>" . sanitize_input($row['username']) . "</li>";
            }
        }
        $stmt->close();
    }
    $section_html .= "</ul>";
    return $section_html;
}

// Check if code parameter is provided and not empty
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = sanitize_input($_GET['code']);
    // Connect to the main database
    $conn = new mysqli($db_servername, $db_username, $db_password, $maindb);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    // Prepare the SQL statement to prevent SQL injection
    if ($stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?")) {
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $username = $user['username'];
            if ($username) {
                // Connect to the user's database
                $user_db = new mysqli($db_servername, $db_username, $db_password, $username);
                // Check connection
                if ($user_db->connect_error) {
                    die("Connection failed: " . $user_db->connect_error);
                }
                $credits_list = "<section class='section'>";
                $credits_list .= "<div class='container'>";
                $credits_list .= "<h1 class='title has-text-white'>Stream Ending</h1>";
                $credits_list .= "<h2 class='subtitle has-text-white'>Thank you for your support!</h2>";
                $credits_list .= "<h2 class='subtitle has-text-white'>Special Thanks To:</h2>";
                $credits_list .= "<ul class='content has-text-white'>";
                $credits_list .= "<li>All the lurkers!</li>";
                $credits_list .= "</ul>";
                $credits_list .= "</div>";
                $credits_list .= "</section>";
                $credits_list .= "<section class='scrolling-credits'>";
                $credits_list .= build_event_section($user_db, 'raid', 'Raiders');
                $credits_list .= build_event_section($user_db, 'bits', 'Cheers');
                $credits_list .= build_event_section($user_db, 'subscriptions', 'Subscriptions');
                $credits_list .= build_event_section($user_db, 'follow', 'Followers', true);
                $credits_list .= build_chatters_section($user_db);
                $credits_list .= "</section>";
                $status = $credits_list;
                $user_db->close();
            } else {
                $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, there was a problem accessing your data. Please try again later.</h2></div></section>";
            }
        } else {
            $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, we couldn't find your data in our system. Please make sure you're using the correct API key.</h2></div></section>";
        }
        $stmt->close();
    } else {
        $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, there was an issue connecting to our system. Please try again later.</h2></div></section>";
    }
    $conn->close();
} else {
    $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, we can't display your data without your API key. You can find your API Key on your <a class='has-text-link' href='https://dashboard.botofthespecter.com/profile.php'>profile page</a>.</h2></div></section>";
}
$buildStatus = $status;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Overlay</title>
<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/1.0.0/css/bulma.min.css">
<style>
body {
    background-color: transparent;
    color: white;
}
.scrolling-credits {
    position: fixed;
    bottom: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    display: flex;
    justify-content: center;
    align-items: center;
}
.scrolling-credits ul {
    list-style-type: none;
    padding: 0;
    margin: 0;
}
.scrolling-credits li {
    font-size: 1.5em;
    margin: 5px 0;
}
@keyframes scroll {
    0% {
        transform: translateY(100%);
    }
    100% {
        transform: translateY(-100%);
    }
}
.scrolling-credits ul {
    animation: scroll 20s linear infinite;
}
</style>
</head>
<body>
<?php echo $buildStatus; ?>
</body>
</html>