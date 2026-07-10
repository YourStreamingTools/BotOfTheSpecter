<?php
include '/var/www/config/database.php';
$primary_db_name = 'website';

$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'] ?? '';

if (empty($api_key)) {
    die("No code provided in the URL.");
}

$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param("s", $api_key);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'] ?? '';

$socials = [];

if ($username) {
    try {
        $db = new PDO("mysql:host=$db_servername;dbname=$username", $db_username, $db_password);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if table exists first (just in case they haven't visited dashboard yet)
        $tableExists = $db->query("SHOW TABLES LIKE 'user_socials'")->rowCount() > 0;
        
        if ($tableExists) {
            $stmt = $db->prepare("SELECT platform, handle FROM user_socials WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
            $stmt->execute();
            $socials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Social Roller DB Error: " . $e->getMessage());
    }
} else {
    die("Invalid code provided.");
}

// Map platforms to Simple-Icons names and brand colors
$platformInfo = [
    'twitch'    => ['icon' => 'twitch', 'color' => '#9146FF'],
    'twitter'   => ['icon' => 'twitter', 'color' => '#1DA1F2'], // Can also use 'x' for X
    'youtube'   => ['icon' => 'youtube', 'color' => '#FF0000'],
    'instagram' => ['icon' => 'instagram', 'color' => '#E4405F'],
    'tiktok'    => ['icon' => 'tiktok', 'color' => '#000000'],
    'discord'   => ['icon' => 'discord', 'color' => '#5865F2'],
    'facebook'  => ['icon' => 'facebook', 'color' => '#1877F2'],
    'reddit'    => ['icon' => 'reddit', 'color' => '#FF4500'],
    'linkedin'  => ['icon' => 'linkedin', 'color' => '#0A66C2'],
    'snapchat'  => ['icon' => 'snapchat', 'color' => '#FFFC00'],
    'pinterest' => ['icon' => 'pinterest', 'color' => '#BD081C'],
    'threads'   => ['icon' => 'threads', 'color' => '#000000'],
    'bluesky'   => ['icon' => 'bluesky', 'color' => '#0285FF'],
    'mastodon'  => ['icon' => 'mastodon', 'color' => '#6364FF'],
    'kick'      => ['icon' => 'kick', 'color' => '#53FC18'],
    'github'    => ['icon' => 'github', 'color' => '#181717'],
    'spotify'   => ['icon' => 'spotify', 'color' => '#1DB954'],
    'steam'     => ['icon' => 'steam', 'color' => '#000000'],
    'patreon'   => ['icon' => 'patreon', 'color' => '#FF424D'],
    'kofi'      => ['icon' => 'kofi', 'color' => '#FF5E5B'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Social Overlay Roller</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/@iconfu/svg-inject@1.2.3/dist/svg-inject.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const items = document.querySelectorAll('.social-roller-item');
            if (items.length === 0) return;
            
            let currentIndex = 0;
            const displayDuration = 7000; // 7 seconds
            const transitionDuration = 1000; // Time it takes to animate out
            
            function showNextItem() {
                // Hide current
                const currentItem = items[currentIndex];
                currentItem.classList.remove('active');
                currentItem.classList.add('leaving');
                
                setTimeout(() => {
                    currentItem.classList.remove('leaving');
                    
                    // Show next
                    currentIndex = (currentIndex + 1) % items.length;
                    items[currentIndex].classList.add('active');
                    
                }, transitionDuration);
            }

            // Show first item immediately
            items[0].classList.add('active');
            
            // Start rotation if there's more than 1
            if (items.length > 1) {
                setInterval(showNextItem, displayDuration + transitionDuration);
            }
        });
    </script>
</head>
<body>
    <div id="socialRollerContainer" class="social-roller-container">
        <?php if (empty($socials)): ?>
            <!-- No active socials. Hide overlay. -->
        <?php else: ?>
            <?php foreach ($socials as $social): 
                $platform = strtolower($social['platform']);
                $handle = htmlspecialchars($social['handle']);
                $info = $platformInfo[$platform] ?? ['icon' => $platform, 'color' => '#FFFFFF'];
                $iconUrl = "https://cdn.jsdelivr.net/npm/simple-icons@v6/icons/{$info['icon']}.svg";
            ?>
            <div class="social-roller-item">
                <div class="social-roller-icon-wrapper" style="background-color: <?= $info['color'] ?>;">
                    <img src="<?= $iconUrl ?>" alt="<?= $platform ?>" class="social-roller-icon" onload="SVGInject(this)">
                </div>
                <div class="social-roller-handle-wrapper">
                    <span class="social-roller-handle"><?= $handle ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SVGInject to inline SVG icons so we can style them white if needed, but since we use background color, we can just use CSS filter or normal images. -->
    <script>
        // Simple way to make SVG white: use CSS filter in index.css
        // .social-roller-icon { filter: brightness(0) invert(1); }
    </script>
</body>
</html>
