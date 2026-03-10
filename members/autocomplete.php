<?php
session_start();

// Must be logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) < 1) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

include '/var/www/config/database.php';

$results = [];
try {
    $conn = new mysqli($db_servername, $db_username, $db_password, 'website');
    if ($conn->connect_error) {
        throw new Exception("Connection failed");
    }
    $like = '%' . $conn->real_escape_string($q) . '%';
    $stmt = $conn->prepare(
        "SELECT u.username, u.twitch_display_name, u.profile_image
         FROM users u
         LEFT JOIN restricted_users r ON u.username = r.username
         WHERE (u.username LIKE ? OR u.twitch_display_name LIKE ?)
           AND u.is_deceased = 0
           AND r.username IS NULL
         ORDER BY
           CASE WHEN u.username LIKE ? THEN 0 ELSE 1 END,
           u.username ASC
         LIMIT 10"
    );
    $likeStart = $q . '%';
    $stmt->bind_param('sss', $like, $like, $likeStart);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = [
            'username'     => $row['username'],
            'display_name' => $row['twitch_display_name'] ?: $row['username'],
            'avatar'       => $row['profile_image'] ?: '',
        ];
    }
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    // Return empty on error
}

header('Content-Type: application/json');
echo json_encode($results);

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) < 1) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

include '/var/www/config/database.php';

// System databases to exclude
$systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', 'website'];

$results = [];
try {
    $conn = new mysqli($db_servername, $db_username, $db_password);
    if ($conn->connect_error) {
        throw new Exception("Connection failed");
    }
    // Find all databases matching the query
    $like = $conn->real_escape_string($q) . '%';
    $res = $conn->query("SHOW DATABASES LIKE '" . $conn->real_escape_string($q) . "%'");
    $matchedDbs = [];
    if ($res) {
        while ($row = $res->fetch_row()) {
            $dbName = $row[0];
            if (!in_array(strtolower($dbName), $systemDbs)) {
                $matchedDbs[] = $dbName;
            }
        }
    }
    if (!empty($matchedDbs)) {
        // Fetch restricted and deceased users to exclude
        $excluded = [];
        $websiteConn = new mysqli($db_servername, $db_username, $db_password, 'website');
        $profileMap = [];
        if (!$websiteConn->connect_error) {
            // Get restricted usernames
            $rRes = $websiteConn->query("SELECT username FROM restricted_users");
            if ($rRes) {
                while ($r = $rRes->fetch_row()) {
                    $excluded[] = strtolower($r[0]);
                }
            }
            // Get profile data for matched dbs
            $inList = implode(',', array_map(fn($d) => "'" . $websiteConn->real_escape_string($d) . "'", $matchedDbs));
            $pRes = $websiteConn->query(
                "SELECT username, twitch_display_name, profile_image, is_deceased
                 FROM users WHERE username IN ($inList)"
            );
            if ($pRes) {
                while ($p = $pRes->fetch_assoc()) {
                    $profileMap[strtolower($p['username'])] = $p;
                }
            }
            $websiteConn->close();
        }
        foreach ($matchedDbs as $dbName) {
            $key = strtolower($dbName);
            // Skip restricted or deceased
            if (in_array($key, $excluded)) continue;
            $profile = $profileMap[$key] ?? null;
            if ($profile && (int)$profile['is_deceased'] === 1) continue;
            $results[] = [
                'username'     => $dbName,
                'display_name' => ($profile && $profile['twitch_display_name']) ? $profile['twitch_display_name'] : $dbName,
                'avatar'       => ($profile && $profile['profile_image']) ? $profile['profile_image'] : '',
            ];
        }
        // Sort: starts-with first, then alpha
        usort($results, function ($a, $b) use ($q) {
            $aStarts = stripos($a['username'], $q) === 0 ? 0 : 1;
            $bStarts = stripos($b['username'], $q) === 0 ? 0 : 1;
            if ($aStarts !== $bStarts) return $aStarts - $bStarts;
            return strcasecmp($a['username'], $b['username']);
        });
        $results = array_slice($results, 0, 10);
    }
    $conn->close();
} catch (Exception $e) {
    // Return empty on error
}

header('Content-Type: application/json');
echo json_encode(array_values($results));
