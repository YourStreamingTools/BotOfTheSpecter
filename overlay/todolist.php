<!DOCTYPE html>
<html>
<head>
<title>OBS TASK LIST</title>
<link rel='icon' href='https://yourlistonline.yourcdnonline.com/img/logo.png' type='image/png' />
<link rel='apple-touch-icon' href='https://yourlistonline.yourcdnonline.com/img/logo.png'>
<script src='https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js'></script>
<?php
ob_start();
include '/var/www/config/database.php';
$primary_db_name = "website";
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
if ($conn->connect_error) {
    ob_end_clean();
    die("Connection to primary database failed: " . $conn->connect_error);
}

// Verify URL code using the primary database
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = $_GET['code'];
    // Check if API key is valid in the primary database
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE api_key = ?");
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if ($user) {
        $user_id = $user['id'];
        $username = $user['username'];
    } else {
        ob_end_clean();
        ?>
        </head>
        <body>
        Invalid API key.<br>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.
        <p>If you wish to define a working category, please add it like this: <strong>todolist.php?code=API_KEY&category=1</strong></br>
        (where ID 1 is called Default defined on the categories page.)</p>
        </body>
        </html>
        <?php
        exit;
    }
} else {
    ob_end_clean();
    ?>
    </head>
    <body>
    <p>Please provide your API key in the URL like this: <strong>todolist.php?code=API_KEY</strong></p>
    <p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>
    <p>If you wish to define a working category, please add it like this: <strong>todolist.php?code=API_KEY&category=1</strong></br>
    (where ID 1 is called Default defined on the categories page.)</p>
    </body>
    </html>
    <?php
    exit;
}

// Secondary database
$secondary_db_name = $username;

// Create a connection to the secondary database using MySQLi
$user_db = new mysqli($db_servername, $db_username, $db_password, $secondary_db_name);
if ($user_db->connect_error) {
    ob_end_clean();
    echo "Connection to secondary database failed: " . $user_db->connect_error;
    exit;
}

// Retrieve font, font_size, color, list, and shadow settings for the user from the secondary database
$stmt = $user_db->prepare("SELECT * FROM showobs LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();
$font = isset($settings['font']) ? $settings['font'] : null;
$color = isset($settings['color']) ? $settings['color'] : null;
$list = isset($settings['list']) ? $settings['list'] : null;
$shadow = isset($settings['shadow']) ? $settings['shadow'] : null;
$font_size = isset($settings['font_size']) ? $settings['font_size'] : null;
$listType = ($list === 'Numbered') ? 'ol' : 'ul';
$bold = isset($settings['bold']) ? $settings['bold'] : null;
$category_id = isset($_GET['category']) && !empty($_GET['category']) ? $_GET['category'] : "1";
// Validate category ID
$stmt = $user_db->prepare("SELECT category FROM categories WHERE id = ?");
$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();
$category_row = $result->fetch_assoc();
if ($category_row) {
    $category = $category_row['category'];
    $stmt = $user_db->prepare("SELECT * FROM todos WHERE category = ? ORDER BY id ASC");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $tasks_result = $stmt->get_result();
    $tasks = [];
    while ($row = $tasks_result->fetch_assoc()) {
        $tasks[] = $row;
    }
} else {
    ob_end_clean();
    ?>
    </head>
    <body>
    Invalid category ID.
    <br>ID 1 is called Default defined on the categories page, please review this page for a full list of IDs.</p>
    </body>
    </html>
    <?php
    exit;
}
?>
<meta http-equiv='refresh' content='10'>
<style>body { <?php
        if ($font) { echo "font-family: $font; "; }
        if ($color) { echo "color: $color;";
            if ($shadow && $shadow == 1) {
                if ($color === 'Black') { echo "text-shadow: 0px 0px 6px White;";
                } elseif ($color === 'White') { echo "text-shadow: 0px 0px 6px Black;";
                } else { echo "text-shadow: 0px 0px 6px Black;";
                }
            }
        }
        ?> }</style>
</head>
<body>
<h1><?php echo htmlspecialchars($category); ?> List:</h1>
<?php
echo "<$listType>";
foreach ($tasks as $task) {
    $task_id = $task['id'];
    $objective = $task['completed'] === 'Yes' ? '<s>' . htmlspecialchars($task['objective']) . '</s>' : htmlspecialchars($task['objective']);
    $taskStyle = $bold == 1 ? 'style="font-size: ' . $font_size . 'px;"><strong>' : 'style="font-size: ' . $font_size . 'px;">';
    echo "<li $taskStyle$objective</li>";
}
echo "</$listType>";
?>
</body>
</html>