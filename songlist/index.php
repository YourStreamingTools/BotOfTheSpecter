<?php
session_start();
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Song List</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/1.0.0/css/bulma.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/script.js"></script>
</head>
<body>
    <section class="section">
        <div class="container">
            <div class="columns">
                <div class="column is-one-quarter">
                    <aside class="menu">
                        <p class="menu-label">Menu</p>
                        <ul class="menu-list">
                            <li><a onclick="loadContent('songs')">Songs</a></li>
                            <li><a onclick="loadContent('add_song')">Add Song</a></li>
                        </ul>
                    </aside>
                </div>
                <div class="column" id="content">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </section>
</body>
</html>
