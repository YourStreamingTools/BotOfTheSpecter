<?php
session_start();
include 'database.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit;
}

// Fetch the logged-in user's Twitch username
$loggedInUsername = $_SESSION['twitch_username'];

// Check if the username parameter is provided in the URL
if (isset($_GET['username'])) {
    $username = $_GET['username'];

    // Fetch the streamer's information from the database
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if the streamer exists and the logged-in user is the streamer
    if ($user && $user['username'] === $loggedInUsername) {
        $streamerDisplayName = $user['twitch_display_name'];
    } else {
        echo "<h1>Error: Unauthorized access.</h1>";
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
    <title>YouTube Player for <?php echo htmlspecialchars($streamerDisplayName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/1.0.0/css/bulma.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <section class="section">
        <div class="container">
            <h1 class="title">YouTube Player for <?php echo htmlspecialchars($streamerDisplayName); ?></h1>
            <div id="video-container" class="content">
                <iframe id="youtube-player" width="560" height="315" src="" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>
            <div>
                <button id="next-song" class="button is-primary">Next Song</button>
            </div>
        </div>
    </section>

    <script>
        let playlist = [];
        let currentSongIndex = 0;

        function loadPlaylist() {
            const urlParams = new URLSearchParams(window.location.search);
            const username = urlParams.get('username');

            if (!username) {
                alert('Username not provided.');
                return;
            }

            $.ajax({
                url: `get_youtube_songs.php?username=${username}`,
                method: 'GET',
                success: function(data) {
                    const response = JSON.parse(data);
                    if (response.status === 'error') {
                        alert(response.message);
                    } else {
                        playlist = response;
                        playNextSong();
                    }
                },
                error: function() {
                    alert('Error loading playlist.');
                }
            });
        }

        function playNextSong() {
            if (currentSongIndex < playlist.length) {
                const videoId = playlist[currentSongIndex].youtube_id;
                $('#youtube-player').attr('src', `https://www.youtube.com/embed/${videoId}?autoplay=1`);
                currentSongIndex++;
            } else {
                alert('No more songs in the playlist.');
            }
        }

        $(document).ready(function() {
            loadPlaylist();

            $('#next-song').click(function() {
                playNextSong();
            });
        });
    </script>
</body>
</html>