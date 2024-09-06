<?php
// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// PAGE TITLE
$title = "Members";
$commands = [];
$builtCommands = [];

// Connect to database
require_once "db_connect.php";

// Query to fetch commands from the database
$fetchCommandsSql = "SELECT * FROM commands";
$result = $conn->query($fetchCommandsSql);
$builtCommands = array();
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $builtCommands[] = $row;
    }
}

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
        $title = "User Commands: $username";
        // Check if custom_commands table exists
        $check_table_query = "SHOW TABLES LIKE 'custom_commands'";
        $stmt = $db->prepare($check_table_query);
        $stmt->execute();
        $table_exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$table_exists) {
            throw new Exception("No custom commands found for user '$username'.");
        }
        // Query commands for the user
        $query = "SELECT command FROM custom_commands";
        $result = $db->query($query);
        // Fetch commands into an array
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $commands[] = $row;
        }
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
            BotOfTheSpecter
        </p>
    </div>
</section>
<div class="content-wrapper">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-three-quarters">
                <?php if ($username): ?>
                    <div class="notification is-info">
                        <?php echo $buildResults; ?>
                    </div>
                    <?php if (!empty($commands)): ?>
                        <table class="table is-fullwidth is-striped">
                            <thead>
                                <tr>
                                    <th>Built-in Commands</th>
                                    <th>Custom Commands</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $maxRows = max(count($builtCommands), count($commands)); ?>
                                <?php for ($i = 0; $i < $maxRows; $i++): ?>
                                    <tr>
                                        <td><?php echo isset($builtCommands[$i]) ? '!' . htmlspecialchars($builtCommands[$i]['command_name']) : ''; ?></td>
                                        <td><?php echo isset($commands[$i]) ? '!' . htmlspecialchars($commands[$i]['command']) : ''; ?></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="notification is-warning">
                            <p>No commands found.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="box">
                        <h2 class="title">Search for User Commands:</h2>
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
</div>
<footer class="footer">
    <div class="content has-text-centered">
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.
    </div>
</footer>
</body>
</html>