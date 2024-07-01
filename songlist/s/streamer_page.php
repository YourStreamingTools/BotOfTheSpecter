<?php
if (isset($_GET['username'])) {
    $username = $_GET['username'];
    $userApiUrl = "../../songlistapi/check_streamer.php?username=" . $username;
    $userApiResponse = file_get_contents($userApiUrl);
    $userApiData = json_decode($userApiResponse, true);

    if ($userApiData['status'] === 'success') {
        $user = $userApiData['user'];
    } else {
        echo "<h1>Error: " . $userApiData['message'] . "</h1>";
        exit;
    }
} else {
    echo "<h1>Error: Username not provided.</h1>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Song List for <?php echo htmlspecialchars($user['twitch_display_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/1.0.0/css/bulma.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/script.js"></script>
</head>
<body>
    <section class="section">
        <div class="container">
            <h1 class="title">Song List for <?php echo htmlspecialchars($user['twitch_display_name']); ?></h1>
            <div id="content">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </section>
    <script>
        $(document).ready(function() {
            loadContent('songs');
        });
    </script>
</body>
</html>