<?php
// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars(trim($input));
}

// PAGE TITLE
$title = "User Commands";
$commands = [];

// Check if a user is specified in the URL parameter
if (isset($_GET['user'])) {
    // Get username from URL
    $username = sanitize_input($_GET['user']);
    
    // Path to SQLite database
    $db_path = "/var/www/bot/commands/{$username}.db";

    try {
        // Check if database file exists
        if (!file_exists($db_path)) {
            throw new Exception("User '$username' does not use our system.");
        }
        
        // Update Title for the Username
        $title = "User Commands: $username";

        // Connect to SQLite database
        $db = new SQLite3($db_path);

        // Check if custom_commands table exists
        $check_table_query = "SELECT name FROM sqlite_master WHERE type='table' AND name='custom_commands'";
        $table_result = $db->querySingle($check_table_query);
        if ($table_result === false) {
            throw new Exception("No custom commands found for user '$username'.");
        }

        // Query commands for the user
        $query = "SELECT command FROM custom_commands";
        $result = $db->query($query);

        // Fetch commands into an array
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $commands[] = $row;
        }

        // Close database connection
        $db->close();
    } catch (Exception $e) {
        $buildResults = "<p>Error: " . $e->getMessage() . "</p>";
    }
} else {
    // If user is not specified in URL, provide a search form
    $buildResults = "<h2>Search for User Commands:</h2>"; 
    $buildResults .= "<form method='get' action='{$_SERVER['PHP_SELF']}'>"; 
    $buildResults .= "<label for='user_search'>Enter username:</label>"; 
    $buildResults .= "<input type='text' id='user_search' name='user'>"; 
    $buildResults .= "<input type='submit' value='Search'>"; 
    $buildResults .= "</form>"; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - <?php echo $title; ?></title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" type="text/css" href="https://botofthespecter.com/style.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description" content="BotOfTheSpecter is an advanced Twitch bot designed to enhance your streaming experience, offering a suite of tools for community interaction, channel management, and analytics." />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <style>
        .bot-table {
            border-style: solid;
            border-color: #ffffff;
            background-color: #111111;
            border-width: 1px;
            width: 100%;
        }

        .bot-table td, 
        .bot-table tr,
        .bot-table th {
            color: #ffffff;
            border-style: solid;
            border-color: #ffffff;
            background-color: #111111;
            border-width: 1px;
        }
    </style>
</head>
<body>
    <header>
        <h1>BotOfTheSpecter</h1>
        <p>&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.</p>
    </header>
    <div class="container">
        <div class="medium-6 column">
            <?php echo $buildResults; ?>
            <?php if (!empty($commands)): ?>
                <table class="bot-table">
                    <thead>
                        <tr>
                            <th>Command</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commands as $command): ?>
                            <tr>
                                <td>!<?php echo $command['command']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>