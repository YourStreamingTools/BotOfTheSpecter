<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = 'Discord Stream Tracking Overview';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

// Fetch all users
$users = [];
$result = $conn->query("SELECT username FROM users ORDER BY username ASC");
while ($row = $result->fetch_assoc()) {
    $users[] = $row['username'];
}

// Initialize data array
$trackingData = [];

foreach ($users as $username) {
    $userDbName = $username;
    try {
        $userConn = new mysqli($db_servername, $db_username, $db_password, $userDbName);
        if ($userConn->connect_error) {
            // Skip if database doesn't exist
            continue;
        }
        // Check if member_streams table exists
        $tableCheck = $userConn->query("SHOW TABLES LIKE 'member_streams'");
        if ($tableCheck->num_rows == 0) {
            $userConn->close();
            continue;
        }
        // Fetch tracked streams
        $streams = [];
        $stmt = $userConn->prepare("SELECT username, stream_url FROM member_streams ORDER BY username ASC");
        if ($stmt) {
            $stmt->execute();
            $resultStreams = $stmt->get_result();
            while ($row = $resultStreams->fetch_assoc()) {
                $streams[] = $row;
            }
            $stmt->close();
        }
        if (!empty($streams)) {
            // Remove duplicates based on username
            $uniqueStreams = [];
            foreach ($streams as $stream) {
                $uniqueStreams[$stream['username']] = $stream;
            }
            $trackingData[$username] = array_values($uniqueStreams);
        }
        $userConn->close();
    } catch (mysqli_sql_exception $e) {
        // Skip users whose database doesn't exist
        continue;
    }
}

ob_start();
?>

<div class="box">
    <h1 class="title is-4"><span class="icon"><i class="fab fa-discord"></i></span> Discord Stream Tracking Overview</h1>
    <p class="mb-4">This page shows which users have Discord stream tracking enabled, how many streams they're tracking, and the details of each tracked stream.</p>
    <?php if (empty($trackingData)): ?>
        <div class="notification is-info">
            <p>No users currently have Discord stream tracking configured.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-striped">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Streamer/Channel</th>
                        <th>Stream URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trackingData as $username => $streams): ?>
                        <?php foreach ($streams as $stream): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($username); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($stream['username']); ?>
                                </td>
                                <td>
                                    <?php if (!empty($stream['stream_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($stream['stream_url']); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo htmlspecialchars($stream['stream_url']); ?>
                                        </a>
                                    <?php else: ?>
                                        <em>No URL</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="level mt-4">
            <div class="level-left">
                <div class="level-item">
                    <p><strong>Total Users with Tracking:</strong> <?php echo count($trackingData); ?></p>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <p><strong>Total Tracked Streams:</strong> <?php echo array_sum(array_map('count', $trackingData)); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include "admin_layout.php";
?>