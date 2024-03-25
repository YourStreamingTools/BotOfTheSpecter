<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars($input);
}

$status = "";

// Check if auth parameter is provided and not empty
if (isset($_GET['auth']) && !empty($_GET['auth'])) {
    $api_key = $_GET['auth'];
    require_once "db_connect.php";
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
                $db = new PDO("sqlite:/var/www/bot/commands/{$username}.db");
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                // Fetch game-specific deaths
                $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths");
                $gameDeaths = $getGameDeaths->fetchAll(PDO::FETCH_ASSOC);

                $decApiUrl = "https://decapi.me/twitch/game/" . urlencode($username);
                $currentGame = trim(file_get_contents($decApiUrl));
                $currentGameDeathCount = 0;
                foreach ($gameDeaths as $gameDeath) {
                    if (strcasecmp($gameDeath['game_name'], $currentGame) == 0) {
                        $currentGameDeathCount = $gameDeath['death_count'];
                        break;
                    }
                }
                $showName = isset($_GET['name']) && $_GET['name'] === '0' ? false : true;
                if (!$showName) {
                    // If 'name' parameter is set to '0', display only the death count
                    $status = sanitize_input($currentGameDeathCount);
                } else {
                    // Display game name along with the death count
                    $status = "Current Game Death Count for " . sanitize_input($currentGame) . ": " . sanitize_input($currentGameDeathCount);
                }
            } else {
                $status = "I'm sorry, there was a problem accessing your data. Please try again later.";
            }
        } else {
            $status = "I'm sorry, we couldn't find your data in our system. Please make sure you're using the correct API key.";
        }
        $stmt->close();
    } else {
        $status = "I'm sorry, there was an issue connecting to our system. Please try again later.";
    }
} else {
    $status = "I'm sorry, we can't display your data without your API key. You can find your API Key on your <a href='https://dashboard.botofthespecter.com/profile.php'>profile page</a>.";
}
$buildStatus = "<h2>" . $status . "</h2>";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Overlay</title>
<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
<meta http-equiv='refresh' content='10'>
</head>
<body>
<?php echo $buildStatus; ?>

</body>
</html>