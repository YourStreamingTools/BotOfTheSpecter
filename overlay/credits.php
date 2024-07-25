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
$sqlusername = ''; // CHANGE TO MAKE THIS WORK
$sqlpassword = ''; // CHANGE TO MAKE THIS WORK
$dbhost = 'sql.botofthespecter.com';
$maindb = 'website';

// Check if code parameter is provided and not empty
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = sanitize_input($_GET['code']);
    // Connect to the main database
    $conn = new mysqli($dbhost, $sqlusername, $sqlpassword, $maindb);
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
                $user_db = new mysqli($dbhost, $sqlusername, $sqlpassword, $username);
                // Check connection
                if ($user_db->connect_error) {
                    die("Connection failed: " . $user_db->connect_error);
                }
                if ($stmtt = $user_db->prepare("SELECT * FROM stream_credits")) {
                    $stmtt->execute();
                    // Fetch results and build the credits list
                    $credits_result = $stmtt->get_result();
                    $credits_list = "<section class='section'>";
                    $credits_list .= "<div class='container'>";
                    $credits_list .= "<h1 class='title has-text-white'>Stream Ending</h1>";
                    $credits_list .= "<h2 class='subtitle has-text-white'>Thank you for your support!</h2>";
                    $credits_list .= "<h2 class='subtitle has-text-white'>Special Thanks To:</h2>";
                    $credits_list .= "<ul class='content has-text-white'>";

                    if ($credits_result->num_rows > 0) {
                        while ($row = $credits_result->fetch_assoc()) {
                            $credits_list .= "<li>" . sanitize_input($row['username']) . " - " . sanitize_input($row['event']) . " - " . sanitize_input($row['data']) . "</li>";
                        }
                    }

                    // Always thank the lurkers
                    $credits_list .= "<li>Thank you to all the lurkers!</li>";

                    $credits_list .= "</ul>";
                    $credits_list .= "</div>";
                    $credits_list .= "</section>";
                    $status = $credits_list;

                    $stmtt->close();
                } else {
                    $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>Error preparing statement to retrieve stream credits.</h2></div></section>";
                }
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
</style>
</head>
<body>
<?php echo $buildStatus; ?>
</body>
</html>