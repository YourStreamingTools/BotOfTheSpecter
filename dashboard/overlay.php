<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
require_once "db_connect.php";
$status = "";

// Check if auth parameter is provided and not empty
if (isset($_GET['auth']) && !empty($_GET['auth'])) {
    $api_key = $_GET['auth'];

    // Prepare the SQL statement to prevent SQL injection
    if ($stmt = $conn->prepare("SELECT username, access_token FROM users WHERE api_key = ?")) {
        $stmt->bind_param("s", $api_key);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $username = $user['username'];
            $authToken = $user['access_token'];
            if ($username && $authToken) {
                include 'sqlite.php';
                $decApiUrl = "https://decapi.me/twitch/game/" . $username;
                $currentGame = trim(file_get_contents($decApiUrl));
                $currentGameDeathCount = 0;
                foreach ($gameDeaths as $gameDeath) {
                    if (strcasecmp($gameDeath['game_name'], $currentGameName) == 0) {
                        $currentGameDeathCount = $gameDeath['death_count'];
                        break;
                    }
                }
                if ($currentGameDeathCount > 0) {
                    $status = "<div>Current Game Death Count: " . htmlspecialchars($currentGameName) . ": " . htmlspecialchars($currentGameDeathCount) . "</div>"
                } else {
                    $status = "<div>Current Game: " . htmlspecialchars($currentGameName) . " has no recorded deaths.</div>";
                }
            } else {
                $status = "No username or authentication token found.";
            }
        } else {
            $status = "No user found for the provided API key.";
        }
        
        $stmt->close();
    } else {
        $status = "Failed to prepare the statement.";
    }
} else {
    $status = "API key not provided.";
}
$buildStatus = "<h1>" . htmlspecialchars($status) . "</h1>";
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