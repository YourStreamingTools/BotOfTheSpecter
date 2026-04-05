<?php
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
    $column_html .= "<ul class='content has-text-white'>";
    $has_data = false;
    if ($clean_data) {
        // For followers, only get distinct usernames
        if ($stmt = $user_db->prepare("SELECT DISTINCT username FROM stream_credits WHERE event = ? ORDER BY username ASC")) {
            $stmt->bind_param("s", $event);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $has_data = true;
                while ($row = $result->fetch_assoc()) {
                    $column_html .= "<li>" . sanitize_input($row['username']) . "</li>";
                }
            }
            $stmt->close();
        }
    } else {
        // For raids, bits, subscriptions - get the latest row per username to avoid duplicates
        if ($stmt = $user_db->prepare("SELECT sc.username, sc.data FROM stream_credits sc INNER JOIN (SELECT username, MAX(id) AS max_id FROM stream_credits WHERE event = ? GROUP BY username) latest ON sc.username = latest.username AND sc.id = latest.max_id WHERE sc.event = ? ORDER BY sc.username ASC")) {
            $stmt->bind_param("ss", $event, $event);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $has_data = true;
                while ($row = $result->fetch_assoc()) {
                    $data = sanitize_input($row['data']);
                    $column_html .= "<li>" . sanitize_input($row['username']) . " - " . $data . "</li>";
                }
            }
            $stmt->close();
        }
    }
    if (!$has_data) {
        return '';
    }
    $column_html .= "</ul>";
    $column_html .= "</div>";
    return $column_html;
}

function build_chatters_column($user_db) {
    $column_html = "<div class='column has-text-centered'>";
    $column_html .= "<h2 class='subtitle has-text-white'>Chatters</h2>";
    $column_html .= "<ul class='content has-text-white'>";
    $has_data = false;
    if ($stmt = $user_db->prepare("SELECT DISTINCT username FROM seen_today ORDER BY username ASC")) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $has_data = true;
            while ($row = $result->fetch_assoc()) {
                $column_html .= "<li>" . sanitize_input($row['username']) . "</li>";
            }
        }
        $stmt->close();
    }
    if (!$has_data) {
        return '';
    }
    $column_html .= "</ul>";
    $column_html .= "</div>";
    return $column_html;
}

// Check if code parameter is provided and not empty
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = trim($_GET['code']);
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
                $columns = '';
                $columns .= build_event_column($user_db, 'raid', 'Raiders');
                $columns .= build_event_column($user_db, 'bits', 'Cheers');
                $columns .= build_event_column($user_db, 'subscriptions', 'Subscriptions');
                $columns .= build_event_column($user_db, 'watch_streak', 'Watch Streaks');
                $columns .= build_event_column($user_db, 'follow', 'Followers', true);
                $columns .= build_chatters_column($user_db);
                if ($columns !== '') {
                    $status = "<section class='credits-overlay-page-scrolling'><div class='columns is-vcentered is-centered is-flex is-flex-direction-row credits-overlay-page-no-wrap'>" . $columns . "</div></section>";
                } else {
                    $status = '';
                }
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
<link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body class="has-text-white credits-overlay-page">
    <div class="container is-fluid" style="margin-top:8px;">
        <section class="section">
            <div class="container">
                <h1 class="title">Stream Ending</h1>
                <h2>Thank you for your support!</h2>
                <h2>Special Thanks To:</h2>
                <ul class="content">
                    <li>All the lurkers!</li>
                </ul>
                <?php
                if (preg_match('/<section class=\'credits-overlay-page-scrolling\'>.*?<\/section>/s', $buildStatus, $matches)) {
                    echo $matches[0];
                } else {
                    echo str_replace('<section class=\'section\'>', '<section class="section credits-overlay-page-centered-status">', $buildStatus);
                }
                ?>
            </div>
        </section>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // PAGE-LEVEL auto-scroll for the entire credits section
    // Deferred into rAF so layout is fully calculated before measuring heights
    requestAnimationFrame(function() {
    (function() {
        const main = document.querySelector('.container.is-fluid');
        if (!main) return;
        const credits = main.querySelector('.credits-overlay-page-scrolling');
        if (!credits) return;
        // Only scroll if the credits list actually overflows below the visible area
        const rect = credits.getBoundingClientRect();
        const availableH = window.innerHeight - rect.top;
        if (credits.scrollHeight <= availableH) return;
        // If we haven't created the page scroll container, create one that contains two stacked copies
        if (!main.querySelector('.credits-overlay-page-scroll-container')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'credits-overlay-page-scroll-container';
            wrapper.style.position = 'relative';
            wrapper.style.overflow = 'hidden';
            // Two panels stacked inside an inner container that we will animate.
            // Each panel contains the credits + a small gap so the loop has breathing room.
            const gapPx = 24; // space between end and start in pixels
            const panelA = '<div class="credits-overlay-page-panel"><div class="credits-overlay-page-scroll-wrap">' + credits.outerHTML + '</div><div class="credits-overlay-page-scroll-gap" style="height:' + gapPx + 'px"></div></div>';
            const panelB = '<div class="credits-overlay-page-panel"><div class="credits-overlay-page-scroll-wrap">' + credits.outerHTML + '</div><div class="credits-overlay-page-scroll-gap" style="height:' + gapPx + 'px"></div></div>';
            wrapper.innerHTML = '<div class="credits-overlay-page-scroll-inner">' + panelA + panelB + '</div>';
            credits.parentNode.replaceChild(wrapper, credits);
        }
        const pageWrapper = main.querySelector('.credits-overlay-page-scroll-container');
        if (!pageWrapper) return;
        const pageInner = pageWrapper.querySelector('.credits-overlay-page-scroll-inner');
        const firstPanel = pageInner ? pageInner.querySelector('.credits-overlay-page-panel') : null;
        if (!pageInner || !firstPanel) return;
        // Measure the content height (credits) and compute total panel height including gap
        const contentBlock = firstPanel.querySelector('.credits-overlay-page-scroll-wrap');
        const gapBlock = firstPanel.querySelector('.credits-overlay-page-scroll-gap');
        const contentH = Math.max(1, Math.round(contentBlock.scrollHeight));
        const gapH = gapBlock ? Math.max(0, parseInt(gapBlock.style.height || '24', 10)) : 24;
        const totalH = contentH + gapH;
        // Set the visible area to the content height so header-aligned region stays correct
        pageWrapper.style.height = contentH + 'px';
        pageWrapper.style.overflow = 'hidden';
        pageInner.style.willChange = 'transform';
        pageInner.style.height = (totalH * 2) + 'px';
        // Ensure each panel has consistent sizing
        Array.from(pageInner.querySelectorAll('.credits-overlay-page-panel')).forEach(function(ch) {
            ch.style.height = totalH + 'px';
            ch.style.overflow = 'hidden';
            ch.style.margin = '0';
            ch.style.padding = '0';
            const innerWrap = ch.querySelector('.credits-overlay-page-scroll-wrap');
            if (innerWrap) innerWrap.style.height = contentH + 'px';
            const innerGap = ch.querySelector('.credits-overlay-page-scroll-gap');
            if (innerGap) innerGap.style.height = gapH + 'px';
        });
        // Animate the inner container vertically by totalH and wrap smoothly
        const duration = Math.max(2, totalH / 50);
        const speed = totalH / duration;
        let last = null;
        let offset = 0;
        function animatePage(ts) {
            if (last == null) last = ts;
            const delta = (ts - last) / 1000;
            last = ts;
            offset += speed * delta;
            if (offset >= totalH) offset -= totalH;
            pageInner.style.transform = `translateY(${-offset}px)`;
            requestAnimationFrame(animatePage);
        }
        requestAnimationFrame(animatePage);
    })();
    }); // end rAF
});
</script>
</body>
</html>