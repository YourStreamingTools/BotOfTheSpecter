<?php
// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// PAGE TITLE
$title = "Members";
$commands = [];
$typos = [];
$lurkers = [];
$totalDeaths = [];
$gameDeaths = [];
$totalHugs = 0;
$hugCounts = [];
$totalKisses = 0;
$kissCounts = [];
$customCounts = [];

require_once "db_connect.php";

// Get the username from the URL path
$requestUri = $_SERVER['REQUEST_URI'];
$segments = explode('/', trim($requestUri, '/'));
$username = isset($segments[1]) ? sanitize_input($segments[1]) : null;

if ($username) {
    try {
        // Connect to the MySQL database
        $db = new PDO("mysql:host=sql.botofthespecter.com;dbname={$username}", "specter", "Rg8sJ2h3FyL9");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Update Title for the Username
        $title = "Member: $username";
        // Fetch custom commands
        $query = "SELECT command FROM custom_commands";
        $result = $db->query($query);
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $commands[] = $row;
        }
        // Fetch additional data
        // Typos
        $getTypos = $db->query("SELECT * FROM user_typos ORDER BY typo_count DESC");
        $typos = $getTypos->fetchAll(PDO::FETCH_ASSOC);
        // Lurkers
        $getLurkers = $db->query("SELECT username FROM lurkers ORDER BY id DESC");
        $lurkers = $getLurkers->fetchAll(PDO::FETCH_ASSOC);
        // Hugs
        $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
        $totalHugs = $getTotalHugs->fetch(PDO::FETCH_ASSOC)['total_hug_count'];
        $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC");
        $hugCounts = $getHugCounts->fetchAll(PDO::FETCH_ASSOC);
        // Kisses
        $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
        $totalKisses = $getTotalKisses->fetch(PDO::FETCH_ASSOC)['total_kiss_count'];
        $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC");
        $kissCounts = $getKissCounts->fetchAll(PDO::FETCH_ASSOC);
        // Custom Command Counts
        $getCustomCounts = $db->query("SELECT command, count FROM custom_counts ORDER BY count DESC");
        $customCounts = $getCustomCounts->fetchAll(PDO::FETCH_ASSOC);
        // Close database connection
        $db = null;
    } catch (PDOException $e) {
        $buildResults = "<p>Error: " . $e->getMessage() . "</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="custom.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description" content="BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics." />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
</head>
<body>
<section class="hero is-primary">
    <div class="hero-body">
        <p class="title">
            <img src="https://cdn.botofthespecter.com/logo.png" width="75px" height="75px" alt="BotOfTheSpecter Logo Image">
            BotOfTheSpecter
        </p>
    </div>
</section>
<div class="container">
    <div class="columns is-centered">
        <div class="column is-three-quarters">
            <?php if ($username): ?>
                <div class="notification is-info">
                    <?php echo $buildResults; ?>
                </div>
                <div class="buttons">
                    <button class="button is-link" data-target="#commands-modal" aria-haspopup="true">Commands</button>
                    <button class="button is-link" data-target="#custom-command-modal" aria-haspopup="true">Custom Command Count</button>
                    <button class="button is-link" data-target="#lurkers-modal" aria-haspopup="true">Lurkers</button>
                    <button class="button is-link" data-target="#typos-modal" aria-haspopup="true">Typos</button>
                    <button class="button is-link" data-target="#deaths-modal" aria-haspopup="true">Deaths</button>
                    <button class="button is-link" data-target="#hugs-modal" aria-haspopup="true">Hugs</button>
                    <button class="button is-link" data-target="#kisses-modal" aria-haspopup="true">Kisses</button>
                </div>
                <!-- Commands Modal -->
                <div id="commands-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Commands</h2>
                            <ul>
                                <?php foreach ($commands as $command): ?>
                                    <li><?php echo htmlspecialchars($command['command']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Custom Command Count Modal -->
                <div id="custom-command-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Custom Command Count</h2>
                            <ul>
                                <?php foreach ($customCounts as $custom): ?>
                                    <li><?php echo htmlspecialchars($custom['command']); ?>: <?php echo htmlspecialchars($custom['count']); ?> uses</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Lurkers Modal -->
                <div id="lurkers-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Lurkers</h2>
                            <ul>
                                <?php foreach ($lurkers as $lurker): ?>
                                    <li><?php echo htmlspecialchars($lurker['username']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Typos Modal -->
                <div id="typos-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Typos</h2>
                            <ul>
                                <?php foreach ($typos as $typo): ?>
                                    <li><?php echo htmlspecialchars($typo['username']); ?>: <?php echo htmlspecialchars($typo['typo_count']); ?> typos</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Deaths Modal -->
                <div id="deaths-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Deaths</h2>
                            <p>Total Deaths: <?php echo htmlspecialchars($totalDeaths['death_count']); ?></p>
                            <ul>
                                <?php foreach ($gameDeaths as $gameDeath): ?>
                                    <li><?php echo htmlspecialchars($gameDeath['game_name']); ?>: <?php echo htmlspecialchars($gameDeath['death_count']); ?> deaths</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Hugs Modal -->
                <div id="hugs-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Hugs</h2>
                            <p>Total Hugs: <?php echo htmlspecialchars($totalHugs); ?></p>
                            <ul>
                                <?php foreach ($hugCounts as $hug): ?>
                                    <li><?php echo htmlspecialchars($hug['username']); ?>: <?php echo htmlspecialchars($hug['hug_count']); ?> hugs</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
                <!-- Kisses Modal -->
                <div id="kisses-modal" class="modal">
                    <div class="modal-background"></div>
                    <div class="modal-content">
                        <div class="box">
                            <h2 class="title">Kisses</h2>
                            <p>Total Kisses: <?php echo htmlspecialchars($totalKisses); ?></p>
                            <ul>
                                <?php foreach ($kissCounts as $kiss): ?>
                                    <li><?php echo htmlspecialchars($kiss['username']); ?>: <?php echo htmlspecialchars($kiss['kiss_count']); ?> kisses</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button class="modal-close is-large" aria-label="close"></button>
                </div>
            <?php else: ?>
                <br>
                <div class="box">
                    <h2 class="title">Enter the Twitch Username:</h2>
                    <form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="field is-grouped">
                        <div class="control is-expanded">
                            <input type="text" id="user_search" name="user" class="input" placeholder="Enter username">
                        </div>
                        <div class="control">
                            <input type="submit" value="Search" class="button is-link">
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<footer class="footer">
    <div class="content has-text-centered">
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.
    </div>
</footer>

<script>
// Script to handle modal open and close
document.querySelectorAll('.button').forEach(button => {
    button.addEventListener('click', () => {
        const target = button.dataset.target;
        const modal = document.querySelector(target);
        modal.classList.add('is-active');
    });
});
document.querySelectorAll('.modal-close, .modal-background').forEach(close => {
    close.addEventListener('click', () => {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('is-active');
        });
    });
});
</script>
</body>
</html>