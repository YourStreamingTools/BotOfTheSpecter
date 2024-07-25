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
                    if ($credits_result->num_rows > 0) {
                        $credits_list = "<ul>";
                        while ($row = $credits_result->fetch_assoc()) {
                            $credits_list .= "<li>" . sanitize_input($row['username']) . " - " . sanitize_input($row['event']) . " - " . sanitize_input($row['data']) . "</li>";
                        }
                        $credits_list .= "</ul>";
                        $status = $credits_list;
                    } else {
                        $status = "<h2>No stream credits found.</h2>";
                    }
                    
                    $stmtt->close();
                } else {
                    $status = "<h2>Error preparing statement to retrieve stream credits.</h2>";
                }
                $user_db->close();
            } else {
                $status = "<h2>I'm sorry, there was a problem accessing your data. Please try again later.</h2>";
            }
        } else {
            $status = "<h2>I'm sorry, we couldn't find your data in our system. Please make sure you're using the correct API key.</h2>";
        }
        $stmt->close();
    } else {
        $status = "<h2>I'm sorry, there was an issue connecting to our system. Please try again later.</h2>";
    }
    $conn->close();
} else {
    $status = "<h2>I'm sorry, we can't display your data without your API key. You can find your API Key on your <a href='https://dashboard.botofthespecter.com/profile.php'>profile page</a>.</h2>";
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
</head>
<body>
<?php echo $buildStatus; ?>
</body>
</html>