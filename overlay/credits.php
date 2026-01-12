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
    // Put the title inside the scroll area so the title scrolls with the list
    $column_html .= "<div class='scroll-area'>";
    // Wrap title+list together so they can be animated as a single block
    $column_html .= "<div class='scroll-wrap'>";
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
        // For raids, bits, subscriptions - get username and data, group by username to avoid duplicates
        if ($stmt = $user_db->prepare("SELECT username, MAX(data) as data FROM stream_credits WHERE event = ? GROUP BY username ORDER BY username ASC")) {
            $stmt->bind_param("s", $event);
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
    // Add placeholder message if no data
    if (!$has_data) {
        $column_html .= "<li>No " . $section_name . " today</li>";
    }
    $column_html .= "</ul></div></div>";
    $column_html .= "</div>";
    return $column_html;
}

function build_chatters_column($user_db) {
    $column_html = "<div class='column has-text-centered'>";
    // Put the title inside the scroll area so the title scrolls with the list
    $column_html .= "<div class='scroll-area'>";
    // Wrap title+list together so they can be animated as a single block
    $column_html .= "<div class='scroll-wrap'>";
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
    // Add placeholder message if no data
    if (!$has_data) {
        $column_html .= "<li>No Chatters today</li>";
    }
    $column_html .= "</ul></div></div>";
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
body, html {
    background: transparent !important;
    /* Always hide native page scrollbars; we animate the content instead */
    overflow: hidden;
}

/* Hide native scrollbars globally */
html, body {
    scrollbar-width: none; /* Firefox */
}
html::-webkit-scrollbar, body::-webkit-scrollbar {
    display: none; /* Safari and Chrome */
}
.container.is-fluid {
    /*background: rgba(58, 60, 61, 0.8); */
    border-radius: 12px;
    padding: 12px;
    padding-bottom: 28px;
    color: #FFFFFF !important;
    position: relative;
    overflow: hidden;
    margin-bottom: 12px;
    outline: 1px solid #ffffff04;
    box-sizing: border-box;
    max-height: calc(100vh - 24px);
}
/* Paint a dedicated rounded background layer to avoid rendering artifacts */
.container.is-fluid::before {
    content: '';
    position: absolute;
    inset: 0px;
    /* background-color: rgba(58, 60, 61, 0.8); */
    border-radius: 12px;
    pointer-events: none;
    z-index: 0;
}
.container.is-fluid > .section,
.container.is-fluid > .section .container {
    position: relative;
    z-index: 1; /* ensure content sits above the bg layer */
}
/* Ensure background respects border-radius and is clipped */
.container.is-fluid {
    -webkit-background-clip: padding-box;
    background-clip: padding-box;
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
/* Reduce default Bulma section/container spacing inside this overlay */
.container.is-fluid > .section {
    padding-top: 6px;
    padding-bottom: 6px;
    margin: 0;
}
.container.is-fluid > .section .container {
    padding: 0;
    margin: 0;
}
.container.is-fluid .title {
    margin-top: 2px;
    margin-bottom: 6px;
    padding-top: 0;
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
    color: #FFFFFF !important;
    margin-top: 8px;
    margin-bottom: 8px;
    padding-bottom: 16px;
}

/* Ensure the generated page scroll container leaves room at the bottom for rounded corners */
.page-scroll-wrap-container {
    box-sizing: border-box;
    padding-bottom: 16px;
    background: transparent;
    border-radius: 12px;
    overflow: hidden;
}
/* inner scrolling structure should be transparent and not paint a square over rounded corners */
.page-scroll-inner,
.page-panel,
.page-scroll-wrap,
.page-scroll-gap {
    background: transparent !important;
}
.page-scroll-inner {
    padding-bottom: 16px;
}

/* Force any nested Bulma/container elements inside the cloned panels to be transparent
   and not contribute a background box that covers the rounded corners. */
.page-scroll-wrap-container .container,
.page-scroll-wrap-container .section,
.page-scroll-wrap-container .columns,
.page-scroll-wrap-container .column,
.page-scroll-wrap-container .scrolling-credits,
.page-scroll-wrap-container ul,
.page-scroll-wrap-container li,
.page-scroll-wrap-container h1,
.page-scroll-wrap-container h2 {
    background: transparent !important;
    box-shadow: none !important;
    border-radius: inherit !important;
}

/* Extra bottom gap inside each panel so content never reaches the rounded corner */
.page-scroll-wrap-container .page-panel {
    padding-bottom: 28px;
    box-sizing: border-box;
}

/* Aggressive safeguard: ensure nothing inside the cloned panels paints an opaque background */
.page-scroll-wrap-container, .page-scroll-wrap-container * {
    background-color: transparent !important;
    background-image: none !important;
    box-shadow: none !important;
    border: none !important;
}

/* If any cloned element has inline background styles, make sure its computed background is cleared */
.page-scroll-wrap-container [style] {
    background-color: transparent !important;
    background-image: none !important;
}
.scrolling-credits .columns {
    width: 100%;
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    /* Prevent native scrollbars while keeping layout intact */
    overflow-x: hidden;
    gap: 24px;
    -ms-overflow-style: none; /* IE/Edge */
    scrollbar-width: none; /* Firefox */
}
.scrolling-credits .columns::-webkit-scrollbar {
    display: none; /* Safari and Chrome */
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
/* Responsive design for smaller screens */
@media (max-width: 1400px) {
    .scrolling-credits .columns {
        flex-direction: column !important;
        flex-wrap: wrap !important;
        overflow-x: visible;
        gap: 32px;
    }
    .scrolling-credits .column {
        min-width: 100%;
        width: 100%;
        max-width: 100%;
    }
    .scrolling-credits .scroll-area {
        min-height: auto;
    }
}
.scrolling-credits .subtitle {
    margin-bottom: 16px;
    color: #FFFFFF !important;
    z-index: 2;
    font-weight: bold !important;
}
.scrolling-credits .scroll-area {
    position: relative;
    width: 100%;
    flex: 0 1 auto;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    /* Hide native scrollbars and allow JS-driven auto-scroll when needed */
    overflow: hidden;
    max-height: 60vh;
    min-height: 96px;
}
.scrolling-credits .scroll-wrap {
    /* This is the element we will animate (contains title + list) */
    position: relative;
    width: 100%;
}
.scrolling-credits .scroll-wrap > h2 {
    margin-bottom: 16px;
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
    font-size: 24px;
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
            // Animate the title+list block (.scroll-wrap) if its content is taller than the visible area
            const wrap = area.querySelector('.scroll-wrap');
            if (!wrap) return;
            // Ensure initial state
            wrap.style.transform = 'translateY(0)';
            wrap.style.willChange = 'transform';
            let contentHeight = wrap.scrollHeight;
            let visibleHeight = area.clientHeight;
            // If content is taller than the visible area, create a seamless loop and animate
            if (contentHeight > visibleHeight + 10 && listItems.length > 0) {
                // Avoid double-duplicating on re-run
                if (!wrap.dataset.duplicated) {
                    wrap.innerHTML += wrap.innerHTML;
                    wrap.dataset.duplicated = '1';
                }
                let singleHeight = wrap.scrollHeight / 2;
                wrap.style.position = 'absolute';
                // Duration proportional to height (pixels -> seconds), tweak as needed
                let duration = Math.max(2, singleHeight / 50); // base speed
                let start = null;
                function animateWrap(ts) {
                    if (!start) start = ts;
                    let elapsed = (ts - start) / 1000;
                    let progress = (elapsed % duration) / duration;
                    let translateY = -progress * singleHeight;
                    wrap.style.transform = `translateY(${translateY}px)`;
                    requestAnimationFrame(animateWrap);
                }
                requestAnimationFrame(animateWrap);
            }
            // Set scroll area height
            area.style.display = 'flex';
            area.style.flexDirection = 'column';
            area.style.justifyContent = 'flex-start';
            area.style.alignItems = 'center';
        });
        // PAGE-LEVEL auto-scroll for the entire credits section
        (function() {
            const main = document.querySelector('.container.is-fluid');
            if (!main) return;
            const credits = main.querySelector('.scrolling-credits');
            if (!credits) return;
            const viewH = window.innerHeight;
            // If credits content is not taller than the viewport, no page-level scroll needed
            if (credits.scrollHeight <= viewH - 100) return;
            // If we haven't created the page scroll container, create one that contains two stacked copies
            if (!main.querySelector('.page-scroll-wrap-container')) {
                const wrapper = document.createElement('div');
                wrapper.className = 'page-scroll-wrap-container';
                wrapper.style.position = 'relative';
                wrapper.style.overflow = 'hidden';
                // Two panels stacked inside an inner container that we will animate.
                // Each panel contains the credits + a small gap so the loop has breathing room.
                const gapPx = 24; // space between end and start in pixels
                const panelA = '<div class="page-panel"><div class="page-scroll-wrap">' + credits.outerHTML + '</div><div class="page-scroll-gap" style="height:' + gapPx + 'px"></div></div>';
                const panelB = '<div class="page-panel"><div class="page-scroll-wrap">' + credits.outerHTML + '</div><div class="page-scroll-gap" style="height:' + gapPx + 'px"></div></div>';
                wrapper.innerHTML = '<div class="page-scroll-inner">' + panelA + panelB + '</div>';
                credits.parentNode.replaceChild(wrapper, credits);
            }
            const pageWrapper = main.querySelector('.page-scroll-wrap-container');
            if (!pageWrapper) return;
            const pageInner = pageWrapper.querySelector('.page-scroll-inner');
            const firstPanel = pageInner ? pageInner.querySelector('.page-panel') : null;
            if (!pageInner || !firstPanel) return;
            // Measure the content height (credits) and compute total panel height including gap
            const contentBlock = firstPanel.querySelector('.page-scroll-wrap');
            const gapBlock = firstPanel.querySelector('.page-scroll-gap');
            const contentH = Math.max(1, Math.round(contentBlock.scrollHeight));
            const gapH = gapBlock ? Math.max(0, parseInt(gapBlock.style.height || '24', 10)) : 24;
            const totalH = contentH + gapH;
            // Set the visible area to the content height so header-aligned region stays correct
            pageWrapper.style.height = contentH + 'px';
            pageWrapper.style.overflow = 'hidden';
            pageInner.style.willChange = 'transform';
            pageInner.style.height = (totalH * 2) + 'px';
            // Ensure each panel has consistent sizing
            Array.from(pageInner.querySelectorAll('.page-panel')).forEach(function(ch) {
                ch.style.height = totalH + 'px';
                ch.style.overflow = 'hidden';
                ch.style.margin = '0';
                ch.style.padding = '0';
                const innerWrap = ch.querySelector('.page-scroll-wrap');
                if (innerWrap) innerWrap.style.height = contentH + 'px';
                const innerGap = ch.querySelector('.page-scroll-gap');
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
    });
    </script>
</body>
</html>