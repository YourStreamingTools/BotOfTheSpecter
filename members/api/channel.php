<?php
require_once dirname(__DIR__) . '/includes/session.php';
members_require_login_json();

$channel = members_sanitize_channel($_GET['user'] ?? '');
if ($channel === null) {
    json_out(['ok' => false, 'error' => 'Invalid channel name.', 'status' => 'invalid'], 400);
}

$website = members_website_db();
$exists = members_db_exists($website, $channel);

$isDeceased = false;
$isRestricted = false;
$profileImage = null;
$displayName = $channel;

if ($exists) {
    $ustmt = $website->prepare('SELECT is_deceased, profile_image, twitch_display_name FROM users WHERE username = ? LIMIT 1');
    if ($ustmt) {
        $ustmt->bind_param('s', $channel);
        $ustmt->execute();
        $ustmt->bind_result($isDeceasedVal, $profileImageVal, $displayNameVal);
        if ($ustmt->fetch()) {
            $isDeceased = (int) $isDeceasedVal === 1;
            $profileImage = $profileImageVal ?: null;
            $displayName = $displayNameVal ?: $channel;
        }
        $ustmt->close();
    }
    if (!$isDeceased) {
        $rstmt = $website->prepare('SELECT 1 FROM restricted_users WHERE username = ? LIMIT 1');
        if ($rstmt) {
            $rstmt->bind_param('s', $channel);
            $rstmt->execute();
            $rstmt->store_result();
            $isRestricted = $rstmt->num_rows > 0;
            $rstmt->close();
        }
    }
}
$website->close();

$viewerDisplay = (string) ($_SESSION['display_name'] ?? $_SESSION['twitch_username'] ?? '');

if (!$exists) {
    session_write_close();
    json_out([
        'ok' => true,
        'channel' => $channel,
        'status' => 'not_found',
        'display_name' => $displayName,
        'profile_image' => $profileImage,
        'viewer_display_name' => $viewerDisplay,
    ]);
}

if ($isRestricted) {
    session_write_close();
    json_out([
        'ok' => true,
        'channel' => $channel,
        'status' => 'restricted',
        'display_name' => $displayName,
        'profile_image' => $profileImage,
        'viewer_display_name' => $viewerDisplay,
    ]);
}

if ($isDeceased) {
    $memorial = [
        'lurkers' => [],
        'typos' => [],
        'deaths' => [],
        'hugs' => [],
        'watchtime' => [],
    ];
    $db = members_user_db($channel);
    if ($db) {
        if (members_table_exists($db, 'lurk_times')) {
            $r = $db->query('SELECT user_id, start_time FROM lurk_times ORDER BY start_time ASC LIMIT 5');
            if ($r) {
                $rows = $r->fetch_all(MYSQLI_ASSOC);
                $ids = array_column($rows, 'user_id');
                $names = members_resolve_twitch_usernames($ids);
                foreach ($rows as &$row) {
                    $uid = (string) ($row['user_id'] ?? '');
                    $row['display_name'] = $names[$uid] ?? ('#' . $uid);
                }
                unset($row);
                $memorial['lurkers'] = $rows;
            }
        }
        if (members_table_exists($db, 'user_typos')) {
            $r = $db->query('SELECT username, typo_count FROM user_typos ORDER BY typo_count DESC LIMIT 5');
            if ($r) {
                $memorial['typos'] = $r->fetch_all(MYSQLI_ASSOC);
            }
        }
        if (members_table_exists($db, 'game_deaths')) {
            $r = $db->query('SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC LIMIT 5');
            if ($r) {
                $memorial['deaths'] = $r->fetch_all(MYSQLI_ASSOC);
            }
        }
        if (members_table_exists($db, 'hug_counts')) {
            $r = $db->query('SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC LIMIT 5');
            if ($r) {
                $memorial['hugs'] = $r->fetch_all(MYSQLI_ASSOC);
            }
        }
        if (members_table_exists($db, 'watch_time')) {
            $r = $db->query('SELECT username, total_watch_time_live FROM watch_time ORDER BY total_watch_time_live DESC LIMIT 5');
            if ($r) {
                $memorial['watchtime'] = $r->fetch_all(MYSQLI_ASSOC);
            }
        }
        $db->close();
    }
    session_write_close();
    json_out([
        'ok' => true,
        'channel' => $channel,
        'status' => 'deceased',
        'display_name' => $displayName,
        'profile_image' => $profileImage,
        'viewer_display_name' => $viewerDisplay,
        'memorial' => $memorial,
    ]);
}

$db = members_user_db($channel);
if (!$db) {
    json_out(['ok' => false, 'error' => 'Could not open channel data.'], 503);
}

function members_fetch_all(mysqli $db, string $table, string $sql): array
{
    if (!members_table_exists($db, $table)) {
        return [];
    }
    $r = $db->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

$commands = members_fetch_all($db, 'custom_commands', 'SELECT command, response, status, cooldown, permission FROM custom_commands');
foreach ($commands as &$cmd) {
    $cmd['response'] = members_sanitize_custom_vars($cmd['response'] ?? '');
}
unset($cmd);

$lurkers = members_fetch_all($db, 'lurk_times', 'SELECT user_id, start_time FROM lurk_times');
$lurkIds = array_column($lurkers, 'user_id');
$lurkNames = members_resolve_twitch_usernames($lurkIds);
foreach ($lurkers as &$row) {
    $uid = (string) ($row['user_id'] ?? '');
    $row['display_name'] = $lurkNames[$uid] ?? ($uid !== '' ? $uid : 'Unknown');
}
unset($row);

$payload = [
    'ok' => true,
    'channel' => $channel,
    'status' => 'ok',
    'display_name' => $displayName,
    'profile_image' => $profileImage,
    'viewer_display_name' => $viewerDisplay,
    'commands' => $commands,
    'lurkers' => $lurkers,
    'typos' => members_fetch_all($db, 'user_typos', 'SELECT username, typo_count FROM user_typos ORDER BY typo_count DESC'),
    'game_deaths' => members_fetch_all($db, 'game_deaths', 'SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC'),
    'hug_counts' => members_fetch_all($db, 'hug_counts', 'SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC'),
    'kiss_counts' => members_fetch_all($db, 'kiss_counts', 'SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC'),
    'highfive_counts' => members_fetch_all($db, 'highfive_counts', 'SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC'),
    'custom_counts' => members_fetch_all($db, 'custom_counts', 'SELECT command, count FROM custom_counts ORDER BY count DESC'),
    'user_counts' => members_fetch_all($db, 'user_counts', 'SELECT command, user, count FROM user_counts'),
    'reward_counts' => members_fetch_all(
        $db,
        'reward_counts',
        'SELECT rc.reward_id, rc.user, rc.count, c.reward_title
         FROM reward_counts AS rc
         LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id
         ORDER BY rc.count DESC'
    ),
    'watch_time' => members_fetch_all($db, 'watch_time', 'SELECT username, total_watch_time_live, total_watch_time_offline FROM watch_time'),
    'quotes' => members_fetch_all($db, 'quotes', 'SELECT id, quote FROM quotes ORDER BY id DESC'),
    'todos' => members_fetch_all($db, 'todos', 'SELECT id, objective, category, completed, created_at, updated_at FROM todos WHERE private = 0 ORDER BY id DESC'),
    'todo_categories' => members_fetch_all($db, 'categories', 'SELECT id, category FROM categories'),
];

$db->close();
session_write_close();
json_out($payload);
