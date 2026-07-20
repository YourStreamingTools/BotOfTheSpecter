<?php
ob_start();
include '/var/www/config/database.php';

function parse_overlay_category_ids($raw)
{
    if ($raw === null || $raw === '') {
        return [1];
    }

    $ids = [];
    foreach (explode(',', (string) $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $id = (int) $part;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}

$error_html = '';
$tasks = [];
$category = '';
$font = $color = $list = $shadow = $font_size = $listType = $bold = null;
$theme = isset($_GET['theme']) && $_GET['theme'] === 'true';

$primary_db_name = "website";
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
if ($conn->connect_error) {
    ob_end_clean();
    die("Connection to primary database failed: " . $conn->connect_error);
}

if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = $_GET['code'];
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE api_key = ?");
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if ($user) {
        $username = $user['username'];
    } else {
        $error_html = "Invalid API key.<br>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>."
            . "<p>If you wish to define a working category, please add it like this: <strong>todolist.php?code=API_KEY&category=1</strong></br>"
            . "Multiple categories are supported too: <strong>todolist.php?code=API_KEY&category=1,3</strong></br>"
            . "(where ID 1 is called Default defined on the categories page.)</p>";
    }
} else {
    $error_html = "<p>Please provide your API key in the URL like this: <strong>todolist.php?code=API_KEY</strong></p>"
        . "<p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>"
        . "<p>If you wish to define a working category, please add it like this: <strong>todolist.php?code=API_KEY&category=1</strong></br>"
        . "Multiple categories are supported too: <strong>todolist.php?code=API_KEY&category=1,3</strong></br>"
        . "(where ID 1 is called Default defined on the categories page.)</p>";
}
$conn->close();

if (!$error_html) {
    $secondary_db_name = $username;
    $user_db = new mysqli($db_servername, $db_username, $db_password, $secondary_db_name);
    if ($user_db->connect_error) {
        $error_html = "Connection to secondary database failed: " . htmlspecialchars($user_db->connect_error);
    }
}

if (!$error_html) {
    $stmt = $user_db->prepare("SELECT * FROM showobs LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    // Sanitize and validate OBS display settings
    $allowed_fonts = ['Arial','Arial Narrow','Verdana','Times New Roman','Courier New','Georgia'];
    $font_raw = isset($settings['font']) ? $settings['font'] : '';
    $font = in_array($font_raw, $allowed_fonts) ? $font_raw : null;
    $color_raw = isset($settings['color']) ? $settings['color'] : '';
    // Allow named colors or hex values
    if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color_raw)) {
        $color = $color_raw;
    } else {
        $named_colors = ['black'=>'black','white'=>'white','red'=>'red','blue'=>'blue'];
        $lower = strtolower($color_raw);
        $color = isset($named_colors[$lower]) ? $named_colors[$lower] : null;
    }
    $list = isset($settings['list']) ? $settings['list'] : null;
    $shadow = isset($settings['shadow']) && $settings['shadow'] == 1 ? true : false;
    $bold = isset($settings['bold']) && $settings['bold'] == 1 ? true : false;
    $show_completed = isset($settings['show_completed']) && $settings['show_completed'] == 1 ? true : false;
    // Normalize font size to an integer (pixels)
    $font_size_raw = isset($settings['font_size']) ? $settings['font_size'] : '';
    $font_size = intval(preg_replace('/\D/', '', $font_size_raw));
    if ($font_size <= 0) $font_size = 12;
    $listType = ($list === 'Numbered') ? 'ol' : 'ul';
    $category_ids = parse_overlay_category_ids($_GET['category'] ?? null);
    if (empty($category_ids)) {
        $error_html = "Invalid category ID.<br>Use one ID or comma-separated IDs, for example <strong>&category=1</strong> or <strong>&category=1,3</strong>.";
    } else {
        $placeholders = implode(',', array_fill(0, count($category_ids), '?'));
        $stmt = $user_db->prepare("SELECT id, category FROM categories WHERE id IN ($placeholders) ORDER BY id ASC");
        $types = str_repeat('i', count($category_ids));
        $stmt->bind_param($types, ...$category_ids);
        $stmt->execute();
        $category_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($category_rows) !== count($category_ids)) {
            $error_html = "Invalid category ID.<br>ID 1 is called Default defined on the categories page, please review this page for a full list of IDs.";
        } else {
            $category = implode(', ', array_column($category_rows, 'category'));
            if ($show_completed) {
                $stmt = $user_db->prepare("SELECT * FROM todos WHERE category IN ($placeholders) AND (private = 0 OR private IS NULL) ORDER BY id ASC");
            } else {
                $stmt = $user_db->prepare("SELECT * FROM todos WHERE category IN ($placeholders) AND (private = 0 OR private IS NULL) AND completed != 'Yes' ORDER BY id ASC");
            }
            $stmt->bind_param($types, ...$category_ids);
            $stmt->execute();
            $tasks_result = $stmt->get_result();
            while ($row = $tasks_result->fetch_assoc()) {
                $tasks[] = $row;
            }
        }
    }
}
if (isset($user_db) && $user_db instanceof mysqli) {
    $user_db->close();
}
ob_end_flush();
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv='refresh' content='10'>
    <title>OBS TASK LIST</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <style>
        body {
            background: transparent;
            <?php
            if ($font) { echo 'font-family: "' . htmlspecialchars($font) . '", sans-serif; '; }
            if ($color) {
                echo 'color: ' . htmlspecialchars($color) . ';';
                if ($shadow) {
                    if (strtolower($color) === 'black' || $color === '#000' || $color === '#000000') {
                        echo 'text-shadow: 0px 0px 6px white;';
                    } else {
                        echo 'text-shadow: 0px 0px 6px black;';
                    }
                }
            } elseif ($theme) {
                echo 'color: white;';
                if ($shadow) { echo 'text-shadow: 0px 0px 6px black;'; }
            }
            ?>
        }
    </style>
</head>
<body>
<?php if ($theme): ?><div class="todolist-overlay-page-theme-box"><?php endif; ?>
<?php if ($error_html): ?>
    <?php echo $error_html; ?>
<?php else: ?>
    <h1><?php echo htmlspecialchars($category); ?> List:</h1>
    <?php
    if (empty($tasks)) {
        echo '<p>No tasks in this category.</p>';
    } else {
        echo "<$listType>";
        foreach ($tasks as $task) {
            $text = htmlspecialchars($task['objective']);
            if ($task['completed'] === 'Yes') {
                $text = '<s>' . $text . '</s>';
            }
            $style = 'style="font-size: ' . intval($font_size) . 'px;"';
            if ($bold) {
                echo "<li $style><strong>$text</strong></li>";
            } else {
                echo "<li $style>$text</li>";
            }
        }
        echo "</$listType>";
    }
    ?>
<?php endif; ?>
<?php if ($theme): ?></div><?php endif; ?>
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
<script>
(function () {
    var params = new URLSearchParams(location.search);
    var code = params.get('code');
    if (!code) return;
    var socket;
    var reconnectAttempts = 0;
    function connectWS() {
        socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
        socket.on('connect', function () {
            reconnectAttempts = 0;
            socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Todo List' });
        });
        socket.on('OVERLAY_REFRESH', function (data) {
            var meta = document.createElement('meta');
            meta.setAttribute('http-equiv', 'refresh');
            meta.setAttribute('content', '0');
            document.head.appendChild(meta);
        });
        socket.on('disconnect', scheduleReconnect);
        socket.on('connect_error', scheduleReconnect);
    }
    function scheduleReconnect() {
        reconnectAttempts++;
        setTimeout(connectWS, Math.min(5000 * reconnectAttempts, 30000));
    }
    connectWS();
})();
</script>
</body>
</html>