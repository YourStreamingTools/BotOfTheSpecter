<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Function to sanitize input
function sanitize_input($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

$status = "";

// Database credentials
include __DIR__ . '/../config/database.php';
$maindb = 'website';

function build_event_section($user_db, $event, $section_name, $clean_data = false) {
    $section_html = "<h2 class='subtitle has-text-white'>$section_name</h2><ul class='content has-text-white'>";
    if ($stmt = $user_db->prepare("SELECT username, event, data FROM stream_credits WHERE event = ?")) {
        $stmt->bind_param("s", $event);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data = sanitize_input($row['data']);
                if ($clean_data) {
                    $section_html .= "<li>" . sanitize_input($row['username']) . "</li>";
                } else {
                    $section_html .= "<li>" . sanitize_input($row['username']) . " - " . $data . "</li>";
                }
            }
        }
        $stmt->close();
    }
    $section_html .= "</ul>";
    return $section_html;
}

function build_chatters_section($user_db) {
    $section_html = "<h2 class='subtitle has-text-white'>Chatters</h2><ul class='content has-text-white'>";
    if ($stmt = $user_db->prepare("SELECT username FROM seen_today")) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $section_html .= "<li>" . sanitize_input($row['username']) . "</li>";
            }
        }
        $stmt->close();
    }
    $section_html .= "</ul>";
    return $section_html;
}

function build_event_column($user_db, $event, $section_name, $clean_data = false) {
    $column_html = "<div class='column has-text-centered'>";
    $column_html .= "<h2 class='subtitle has-text-white'>$section_name</h2>";
    $column_html .= "<div class='scroll-area'><ul class='content has-text-white'>";
    if ($clean_data) {
        // For followers, only get distinct usernames
        if ($stmt = $user_db->prepare("SELECT DISTINCT username FROM stream_credits WHERE event = ? ORDER BY username ASC")) {
            $stmt->bind_param("s", $event);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $column_html .= "<li>" . sanitize_input($row['username']) . "</li>";
                }
            }
            $stmt->close();
        }
    } else {
        // For raids, bits, subscriptions - get username and data, group by username to avoid duplicates
        if ($stmt = $user_db->prepare("SELECT username, MAX(data) as data FROM stream_credits WHERE event = ? GROUP BY username ORDER BY username ASC")) {
            $stmt->bind_param("s", $event);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $data = sanitize_input($row['data']);
                    $column_html .= "<li>" . sanitize_input($row['username']) . " - " . $data . "</li>";
                }
            }
            $stmt->close();
        }
    }
    $column_html .= "</ul></div>";
    $column_html .= "</div>";
    return $column_html;
}

function build_chatters_column($user_db) {
    $column_html = "<div class='column has-text-centered'>";
    $column_html .= "<h2 class='subtitle has-text-white'>Chatters</h2>";
    $column_html .= "<div class='scroll-area'><ul class='content has-text-white'>";
    if ($stmt = $user_db->prepare("SELECT DISTINCT username FROM seen_today ORDER BY username ASC")) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $column_html .= "<li>" . sanitize_input($row['username']) . "</li>";
            }
        }
        $stmt->close();
    }
    $column_html .= "</ul></div>";
    $column_html .= "</div>";
    return $column_html;
}

// Check if code parameter is provided and not empty
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = sanitize_input($_GET['code']);
    // Connect to the main database
    $conn = new mysqli($db_servername, $db_username, $db_password, $maindb);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    // Prepare the SQL statement to prevent SQL injection
    if ($stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?")) {
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $username = $user['username'];
            if ($username) {
                // Connect to the user's database
                $user_db = new mysqli($db_servername, $db_username, $db_password, $username);
                // Check connection
                if ($user_db->connect_error) {
                    die("Connection failed: " . $user_db->connect_error);
                }
                // Only build the scrolling credits section
                $scrolling_credits = "<section class='scrolling-credits'>";
                $scrolling_credits .= "<div class='columns is-vcentered is-centered is-flex is-flex-direction-row no-wrap-columns'>";
                $scrolling_credits .= build_event_column($user_db, 'raid', 'Raiders');
                $scrolling_credits .= build_event_column($user_db, 'bits', 'Cheers');
                $scrolling_credits .= build_event_column($user_db, 'subscriptions', 'Subscriptions');
                $scrolling_credits .= build_event_column($user_db, 'follow', 'Followers', true);
                $scrolling_credits .= build_chatters_column($user_db);
                $scrolling_credits .= "</div>";
                $scrolling_credits .= "</section>";
                $status = $scrolling_credits;
                $user_db->close();
            } else {
                $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, there was a problem accessing your data. Please try again later.</h2></div></section>";
            }
        } else {
            $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, we couldn't find your data in our system. Please make sure you're using the correct API key.</h2></div></section>";
        }
        $stmt->close();
    } else {
        $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, there was an issue connecting to our system. Please try again later.</h2></div></section>";
    }
    $conn->close();
} else {
    $status = "<section class='section'><div class='container'><h2 class='subtitle has-text-white'>I'm sorry, we can't display your data without your API key. You can find your API Key on your <a class='has-text-link' href='https://dashboard.botofthespecter.com/profile.php'>profile page</a>.</h2></div></section>";
}
$buildStatus = $status;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credits Overlay</title>
<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bulma/1.0.4/css/bulma.min.css">
<style>
body {
    background: transparent !important;
}
.container.is-fluid {
    background: rgba(24, 26, 27, 0.85);
    border-radius: 12px;
    padding: 1.5rem;
    color: #FFFFFF !important;
}
.title,
.subtitle,
.section h2,
.section ul li,
.content,
ul.content,
li {
    color: #FFFFFF !important;
}
/* Center the title and ul */
.title {
    text-align: center;
}
ul.content {
    text-align: center;
    list-style-type: none;
}
.content {
    list-style-type: none;
}
.title, .subtitle {
    font-weight: bold !important;
}
.container.is-fluid .content,
.container.is-fluid .content *,
.scrolling-credits .content,
.scrolling-credits .content * {
    color: #FFFFFF !important;
}
.container.is-fluid h2,
.container.is-fluid h1 {
    color: #FFFFFF !important;
}
a, a:visited, a:active {
    color: #FFFFFF !important;
    text-decoration: underline;
}
.scrolling-credits {
    width: 100%;
    overflow: hidden;
    display: flex;
    justify-content: center;
    z-index: 10;
    background: rgba(24, 26, 27, 0.85);
    color: #FFFFFF !important;
    margin-top: 2rem;
    margin-bottom: 2rem;
}
.scrolling-credits .columns {
    width: 100%;
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    overflow-x: auto;
    gap: 1.5rem;
}
.no-wrap-columns {
    flex-wrap: nowrap !important;
}
.scrolling-credits .column {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 200px;
    flex: 1 1 0;
    max-width: 100vw;
    position: relative;
}
.scrolling-credits .subtitle {
    margin-bottom: 1rem;
    color: #FFFFFF !important;
    z-index: 2;
    font-weight: bold !important;
}
.scrolling-credits .scroll-area {
    position: relative;
    width: 100%;
    flex: 1 1 auto;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    overflow: hidden;
    min-height: 300px;
}
.scrolling-credits ul {
    list-style-type: none;
    padding: 0;
    margin: 0;
    width: 100%;
    position: relative;
    will-change: transform;
    color: #FFFFFF !important;
}
.scrolling-credits li {
    font-size: 1.5em;
    margin: 5px 0;
    color: #FFFFFF !important;
    text-align: center;
}
@keyframes scroll-up {
    0% {
        transform: translateY(100%);
    }
    100% {
        transform: translateY(-100%);
    }
}
.section .container > h2 {
    text-align: center;
    margin-left: auto;
    margin-right: auto;
}
.centered-status {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 80vh;
    text-align: center;
}
</style>
</head>
<body class="has-text-white">
    <div class="container is-fluid" style="margin-top:2rem;">
        <section class="section">
            <div class="container">
                <h1 class="title">Stream Ending</h1>
                <h2>Thank you for your support!</h2>
                <h2>Special Thanks To:</h2>
                <ul class="content">
                    <li>All the lurkers!</li>
                </ul>
                <?php
                if (preg_match('/<section class=\'scrolling-credits\'>.*?<\/section>/s', $buildStatus, $matches)) {
                    echo $matches[0];
                } else {
                    echo str_replace('<section class=\'section\'>', '<section class="section centered-status">', $buildStatus);
                }
                ?>
            </div>
        </section>
    </div>
    <script>
    // Scroll each column's list independently, always centered, never above the title
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.scrolling-credits .scroll-area').forEach(function(area) {
            const ul = area.querySelector('ul');
            if (!ul) return;
            // Check if list has content
            const listItems = ul.querySelectorAll('li');
            if (listItems.length === 0) return;
            // Make sure list is visible initially
            ul.style.position = 'relative';
            ul.style.transform = 'translateY(0)';
            // Check initial height
            let initialHeight = ul.scrollHeight;
            let areaHeight = area.clientHeight;
            // Only duplicate and animate if list is tall enough (needs more than area height to scroll)
            if (initialHeight > areaHeight && listItems.length > 3) {
                // Duplicate the list for seamless looping
                ul.innerHTML += ul.innerHTML;
                let listHeight = ul.scrollHeight / 2;
                ul.style.position = 'absolute';
                let duration = Math.max(20, listHeight / 40 * 5); // 5s per 40px, min 20s
                let start = null;
                function animateScroll(ts) {
                    if (!start) start = ts;
                    let elapsed = (ts - start) / 1000;
                    let progress = (elapsed % duration) / duration;
                    let translateY = (1 - progress) * listHeight;
                    ul.style.transform = `translateY(${translateY}px)`;
                    requestAnimationFrame(animateScroll);
                }
                requestAnimationFrame(animateScroll);
            }
            // Set scroll area height
            area.style.display = 'flex';
            area.style.flexDirection = 'column';
            area.style.justifyContent = 'flex-start';
            area.style.alignItems = 'center';
            area.style.height = `calc(100% - ${area.previousElementSibling ? area.previousElementSibling.offsetHeight : 0}px)`;
        });
    });
    </script>
</body>
</html>