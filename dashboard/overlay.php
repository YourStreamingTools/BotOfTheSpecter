<?php
require_once "db_connect.php";
$websocket_port = null;
$baseUrl = $_SERVER['HTTP_HOST'];

// Check if auth parameter is provided and not empty
if (isset($_GET['auth']) && !empty($_GET['auth'])) {
    $api_key = $_GET['auth'];

    // Check if API key is valid
    $stmt = $conn->prepare("SELECT * FROM users WHERE api_key = ?");
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        $user_id = $user['id'];
        $websocket_port = $user['websocket_port'];
        $wsUrl = "ws://" . $baseUrl . ":" . $websocket_port;
    } else {
        $status = "Nothing found";
    }
}
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
<div id="deathCounter"></div>
<script>
    <?php if ($websocket_port): ?>
    var ws = new WebSocket("<?php echo $wsUrl; ?>");
    ws.onmessage = function(event) {
        document.getElementById("deathCounter").textContent = "Deaths: " + event.data;
    };
    <?php else: ?>
    console.error("WebSocket port not found.");
    // Handle the scenario appropriately, perhaps by displaying an error message
    document.getElementById("deathCounter").textContent = "WebSocket connection error.";
    <?php endif; ?>
</script>
</body>
</html>