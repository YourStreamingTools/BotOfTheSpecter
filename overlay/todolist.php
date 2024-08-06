<!DOCTYPE html>
<html>
<head>
<title>OBS TASK LIST</title>
<link rel='icon' href='https://yourlistonline.yourcdnonline.com/img/logo.png' type='image/png' />
<link rel='apple-touch-icon' href='https://yourlistonline.yourcdnonline.com/img/logo.png'>
<script src='https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js'></script>
<?php
// Database connection deatils
$db_servername = "sql.botofthespecter.com";
$db_username = ''; // CHANGE TO MAKE THIS WORK
$db_password = ''; // CHANGE TO MAKE THIS WORK

// Primary database
$primary_db_name = "website";

// Create a connection to the primary database
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);

// Check connection
if ($conn->connect_error) {
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
        echo "</head>";
        echo "<body>";
        echo "Invalid API key.<br>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.";
        echo "<p>If you wish to define a working category, please add it like this: <strong>todolist.php?code=API_KEY&category=1</strong></br>";
        echo "(where ID 1 is called Default defined on the categories page.)</p>";
        echo "</body>";
        echo "</html>";
        exit;
    }
} else {
    echo "</head>";
    echo "<body>";
    echo "<p>Please provide your API key in the URL like this: <strong>todolist.php?code=API_KEY</strong></p>";
    echo "<p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>";
    echo "<p>If you wish to define a working category, please add it like this: <strong>todolist.php?code=API_KEY&category=1</strong></br>";
    echo "(where ID 1 is called Default defined on the categories page.)</p>";
    echo "</body>";
    echo "</html>";
    exit;
}

// Secondary database
$secondary_db_name = $username;

try {
    // Create a connection to the secondary database
    $db = new PDO("mysql:host=$db_servername;dbname=$secondary_db_name", $db_username, $db_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Retrieve font, font_size, color, list, and shadow settings for the user from the secondary database
    $stmt = $db->prepare("SELECT * FROM showobs WHERE user_id = ?");
    $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    $font = isset($settings['font']) ? $settings['font'] : null;
    $color = isset($settings['color']) ? $settings['color'] : null;
    $list = isset($settings['list']) ? $settings['list'] : null;
    $shadow = isset($settings['shadow']) ? $settings['shadow'] : null;
    $font_size = isset($settings['font_size']) ? $settings['font_size'] : null;
    $listType = ($list === 'Numbered') ? 'ol' : 'ul';
    $bold = isset($settings['bold']) ? $settings['bold'] : null;

    $category_id = isset($_GET['category']) && !empty($_GET['category']) ? $_GET['category'] : "1";
    $stmt = $db->prepare("SELECT category FROM categories WHERE id = ? AND user_id = ?");
    $stmt->bindParam(1, $category_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $category = $result['category'];

        $stmt = $db->prepare("SELECT * FROM todos WHERE user_id = ? AND category = ? ORDER BY id ASC");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $category_id, PDO::PARAM_INT);
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        echo "</head>";
        echo "<body>";
        echo "Invalid category ID.";
        echo "<br>ID 1 is called Default defined on the categories page, please review this page for a full list of IDs.</p>";
        echo "</body>";
        echo "</html>";
        exit;
    }
} catch (PDOException $e) {
    echo "Connection to secondary database failed: " . $e->getMessage();
    exit;
}
?>
<meta http-equiv='refresh' content='10'>
<style>
    body {
        <?php
        if ($font) {
            echo "font-family: $font; ";
        }
        if ($color) {
            echo "color: $color;";
            if ($shadow && $shadow == 1) {
                if ($color === 'Black') {
                    echo "text-shadow: 0px 0px 6px White;";
                } elseif ($color === 'White') {
                    echo "text-shadow: 0px 0px 6px Black;";
                } else {
                    echo "text-shadow: 0px 0px 6px Black;";
                }
            }
        }
        ?> }
</style>
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