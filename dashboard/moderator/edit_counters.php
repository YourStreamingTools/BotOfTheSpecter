<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
$pageTitle = t('edit_counters_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

require_once '/var/www/config/database.php';
$dbname = $_SESSION['editing_username'];
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Fetch usernames from the user_typos table
$usernames = [];
if ($result = $db->query("SELECT username FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $usernames[] = $row['username'];
    }
    $result->free();
} else {
    $status = "Error fetching usernames: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch commands from the custom_counts table
$commands = [];
if ($result = $db->query("SELECT command FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commands[] = $row['command'];
    }
    $result->free();
} else {
    $status = "Error fetching commands: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch games from the deaths table
$games = [];
if ($result = $db->query("SELECT game_name FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $games[] = $row['game_name'];
    }
    $result->free();
} else {
    $status = "Error fetching games: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch total deaths
$totalDeaths = 0;
if ($result = $db->query("SELECT death_count FROM total_deaths LIMIT 1")) {
    if ($row = $result->fetch_assoc()) {
        $totalDeaths = $row['death_count'];
    }
    $result->free();
}

// Fetch hugs from the hug_counts table
$hugUsers = [];
if ($result = $db->query("SELECT username FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugUsers[] = $row['username'];
    }
    $result->free();
} else {
    $status = "Error fetching hug users: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch kisses from the kiss_counts table
$kissUsers = [];
if ($result = $db->query("SELECT username FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissUsers[] = $row['username'];
    }
    $result->free();
} else {
    $status = "Error fetching kiss users: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch high-fives from the highfive_counts table
$highfiveUsers = [];
if ($result = $db->query("SELECT username FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveUsers[] = $row['username'];
    }
    $result->free();
} else {
    $status = "Error fetching high-five users: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch user counts from the user_counts table
$userCountCommands = [];
$userCountUsersByCommand = [];
if ($result = $db->query("SELECT command, user FROM user_counts")) {
    while ($row = $result->fetch_assoc()) {
        $cmd = $row['command'];
        $user = $row['user'];
        if (!in_array($cmd, $userCountCommands, true)) {
            $userCountCommands[] = $cmd;
        }
        if (!isset($userCountUsersByCommand[$cmd])) {
            $userCountUsersByCommand[$cmd] = [];
        }
        $userCountUsersByCommand[$cmd][] = $user;
    }
    $result->free();
}

// Fetch reward counts and reward titles
$rewardCountsData = [];
$rewardIds = [];
$rewardUsersById = [];
$rewardTitles = [];
if ($result = $db->query("SELECT rc.reward_id, rc.user, rc.count, cpr.reward_title FROM reward_counts rc LEFT JOIN channel_point_rewards cpr ON rc.reward_id = cpr.reward_id")) {
    while ($row = $result->fetch_assoc()) {
        $rewardCountsData[] = $row;
        $rid = $row['reward_id'];
        $user = $row['user'];
        $rewardTitles[$rid] = $row['reward_title'];
        if (!in_array($rid, $rewardIds, true)) {
            $rewardIds[] = $rid;
        }
        if (!isset($rewardUsersById[$rid])) {
            $rewardUsersById[$rid] = [];
        }
        if (!in_array($user, $rewardUsersById[$rid], true)) {
            $rewardUsersById[$rid][] = $user;
        }
    }
    $result->free();
} else {
    $status = "Error fetching reward counts: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch initial data for all counters
$typoData = [];
$commandData = [];
$deathData = [];
$hugData = [];
$kissData = [];
$highfiveData = [];
$userCountData = [];

if ($result = $db->query("SELECT username, typo_count FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $typoData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching typo data: " . $db->error;
    $notification_status = "is-danger";
}

if ($result = $db->query("SELECT command, count FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commandData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching data: " . $db->error;
    $notification_status = "is-danger";
}

if ($result = $db->query("SELECT game_name, death_count FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $deathData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching death data: " . $db->error;
    $notification_status = "is-danger";
}

if ($result = $db->query("SELECT username, hug_count FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching hug data: " . $db->error;
    $notification_status = "is-danger";
}

if ($result = $db->query("SELECT username, kiss_count FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching kiss data: " . $db->error;
    $notification_status = "is-danger";
}

if ($result = $db->query("SELECT username, highfive_count FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching high-five data: " . $db->error;
    $notification_status = "is-danger";
}

if ($result = $db->query("SELECT command, user, count FROM user_counts")) {
    while ($row = $result->fetch_assoc()) {
        $userCountData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching user count data: " . $db->error;
    $notification_status = "is-danger";
}

// Prepare JS objects for reward counts and titles
$rewardCountsJs = [];
foreach ($rewardCountsData as $row) {
    $rewardCountsJs[$row['reward_id']][$row['user']] = $row['count'];
}
$rewardTitlesJs = $rewardTitles;

// Handling form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'update':
            $formUsername = $_POST['typo-username'] ?? '';
            $typoCount = $_POST['typo_count'] ?? '';
            $formCommand = $_POST['command'] ?? '';
            $commandCount = $_POST['command_count'] ?? '';
            $formGame = $_POST['death-game'] ?? '';
            $deathCount = $_POST['death_count'] ?? '';
            $formHugUser = $_POST['hug-username'] ?? '';
            $hugCount = $_POST['hug_count'] ?? '';
            $formKissUser = $_POST['kiss-username'] ?? '';
            $kissCount = $_POST['kiss_count'] ?? '';
            $formHighfiveUser = $_POST['highfive-username'] ?? '';
            $highfiveCount = $_POST['highfive_count'] ?? '';
            $formUserCountCommand = $_POST['usercount-command'] ?? '';
            $formUserCountUser = $_POST['usercount-user'] ?? '';
            $userCountValue = $_POST['usercount_count'] ?? '';
            // Update typo count
            if ($formUsername && is_numeric($typoCount)) {
                $stmt = $db->prepare("UPDATE user_typos SET typo_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $typoCount, $formUsername);
                    if ($stmt->execute()) {
                        $status = "Typo count updated successfully for user {$formUsername}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update command count
            if ($formCommand && is_numeric($commandCount)) {
                $stmt = $db->prepare("UPDATE custom_counts SET count = ? WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $commandCount, $formCommand);
                    if ($stmt->execute()) {
                        $status = "Count updated successfully for the command {$formCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update death count and total deaths
            if ($formGame && is_numeric($deathCount)) {
                // Get the old count for this game
                $oldDeathCount = 0;
                $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formGame);
                    $stmt->execute();
                    $stmt->bind_result($oldDeathCount);
                    $stmt->fetch();
                    $stmt->close();
                }
                $diff = $deathCount - $oldDeathCount;
                if ($diff !== 0) {
                    $stmt = $db->prepare("UPDATE game_deaths SET death_count = ? WHERE game_name = ?");
                    if ($stmt) {
                        $stmt->bind_param('is', $deathCount, $formGame);
                        if ($stmt->execute()) {
                            $stmt2 = $db->prepare("UPDATE total_deaths SET death_count = death_count + ? LIMIT 1");
                            if ($stmt2) {
                                $stmt2->bind_param('i', $diff);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                            $status = "Death count updated successfully for game {$formGame}.";
                            $notification_status = "is-success";
                        } else {
                            $status = "Error: " . $stmt->error;
                            $notification_status = "is-danger";
                        }
                        $stmt->close();
                    } else {
                        $status = "Error: " . $db->error;
                        $notification_status = "is-danger";
                    }
                } else {
                    $status = "No change in death count for game {$formGame}.";
                    $notification_status = "is-info";
                }
            }
            // Update hug count
            if ($formHugUser && is_numeric($hugCount)) {
                $stmt = $db->prepare("UPDATE hug_counts SET hug_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $hugCount, $formHugUser);
                    if ($stmt->execute()) {
                        $status = "Hug count updated successfully for user {$formHugUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update kiss count
            if ($formKissUser && is_numeric($kissCount)) {
                $stmt = $db->prepare("UPDATE kiss_counts SET kiss_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $kissCount, $formKissUser);
                    if ($stmt->execute()) {
                        $status = "Kiss count updated successfully for user {$formKissUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update high-five count
            if ($formHighfiveUser && is_numeric($highfiveCount)) {
                $stmt = $db->prepare("UPDATE highfive_counts SET highfive_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $highfiveCount, $formHighfiveUser);
                    if ($stmt->execute()) {
                        $status = "High-five count updated successfully for user {$formHighfiveUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update user count
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE user_counts SET count = ? WHERE command = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $status = "User count updated successfully for user {$formUserCountUser} and command {$formUserCountCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update reward count
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE reward_counts SET count = ? WHERE reward_id = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $status = "Reward count updated successfully for user {$formUserCountUser} and reward {$formUserCountCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        case 'remove':
            $formUsername = $_POST['typo-username-remove'] ?? '';
            $formCommandRemove = $_POST['command-remove'] ?? '';
            $formGameRemove = $_POST['death-game-remove'] ?? '';
            $formHugUserRemove = $_POST['hug-username-remove'] ?? '';
            $formKissUserRemove = $_POST['kiss-username-remove'] ?? '';
            $formHighfiveUserRemove = $_POST['highfive-username-remove'] ?? '';
            $formUserCountCommandRemove = $_POST['usercount-command-remove'] ?? '';
            $formUserCountUserRemove = $_POST['usercount-user-remove'] ?? '';
            $formRewardCountRemove = $_POST['rewardcount-reward-remove'] ?? '';
            // Remove typo record
            if ($formUsername) {
                $stmt = $db->prepare("DELETE FROM user_typos WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formUsername);
                    if ($stmt->execute()) {
                        $status = "Typo record for user '$formUsername' has been removed.";
                        $notification_status = "is-success";
                    } else {
                        $status = 'Error: ' . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
                // Remove custom counter record
            } elseif ($formCommandRemove) {
                $stmt = $db->prepare("DELETE FROM custom_counts WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formCommandRemove);
                    if ($stmt->execute()) {
                        $status = "Custom counter for command '$formCommandRemove' has been removed.";
                        $notification_status = "is-success";
                    } else {
                        $status = 'Error: ' . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            } elseif ($formGameRemove) {
                // Get the current death count for this game
                $gameDeathCount = 0;
                $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formGameRemove);
                    $stmt->execute();
                    $stmt->bind_result($gameDeathCount);
                    $stmt->fetch();
                    $stmt->close();
                }
                // Subtract from total deaths
                if ($gameDeathCount > 0) {
                    $stmt = $db->prepare("UPDATE total_deaths SET death_count = death_count - ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('i', $gameDeathCount);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                // Remove the game death counter
                $stmt = $db->prepare("DELETE FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formGameRemove);
                    if ($stmt->execute()) {
                        $status = "Death counter for game '{$formGameRemove}' has been removed and total deaths updated.";
                        $notification_status = "is-success";
                    } else {
                        $status = 'Error: ' . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            } elseif ($formHugUserRemove) {
                // Remove hug record
                if ($formHugUserRemove) {
                    $stmt = $db->prepare("DELETE FROM hug_counts WHERE username = ?");
                    if ($stmt) {
                        $stmt->bind_param('s', $formHugUserRemove);
                        if ($stmt->execute()) {
                            $status = "Hug record for user '$formHugUserRemove' has been removed.";
                            $notification_status = "is-success";
                        } else {
                            $status = 'Error: ' . $stmt->error;
                            $notification_status = "is-danger";
                        }
                        $stmt->close();
                    } else {
                        $status = "Error: " . $db->error;
                        $notification_status = "is-danger";
                    }
                }
            } elseif ($formKissUserRemove) {
                // Remove kiss record
                if ($formKissUserRemove) {
                    $stmt = $db->prepare("DELETE FROM kiss_counts WHERE username = ?");
                    if ($stmt) {
                        $stmt->bind_param('s', $formKissUserRemove);
                        if ($stmt->execute()) {
                            $status = "Kiss record for user '$formKissUserRemove' has been removed.";
                            $notification_status = "is-success";
                        } else {
                            $status = 'Error: ' . $stmt->error;
                            $notification_status = "is-danger";
                        }
                        $stmt->close();
                    } else {
                        $status = "Error: " . $db->error;
                        $notification_status = "is-danger";
                    }
                }
            } elseif ($formHighfiveUserRemove) {
                // Remove high-five record
                if ($formHighfiveUserRemove) {
                    $stmt = $db->prepare("DELETE FROM highfive_counts WHERE username = ?");
                    if ($stmt) {
                        $stmt->bind_param('s', $formHighfiveUserRemove);
                        if ($stmt->execute()) {
                            $status = "High-five record for user '$formHighfiveUserRemove' has been removed.";
                            $notification_status = "is-success";
                        } else {
                            $status = 'Error: ' . $stmt->error;
                            $notification_status = "is-danger";
                        }
                        $stmt->close();
                    } else {
                        $status = "Error: " . $db->error;
                        $notification_status = "is-danger";
                    }
                }
            } elseif ($formUserCountCommandRemove && $formUserCountUserRemove) {
                // Remove user count record
                $stmt = $db->prepare("DELETE FROM user_counts WHERE command = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('ss', $formUserCountCommandRemove, $formUserCountUserRemove);
                    if ($stmt->execute()) {
                        $status = "User count for user '$formUserCountUserRemove' and command '$formUserCountCommandRemove' has been removed.";
                        $notification_status = "is-success";
                    } else {
                        $status = 'Error: ' . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            } elseif ($formRewardCountRemove) {
                // Remove reward count record
                $stmt = $db->prepare("DELETE FROM reward_counts WHERE reward_id = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('ss', $formRewardCountRemove, $formUserCountUserRemove);
                    if ($stmt->execute()) {
                        $status = "Reward count for user '$formUserCountUserRemove' and reward '$formRewardCountRemove' has been removed.";
                        $notification_status = "is-success";
                    } else {
                        $status = 'Error: ' . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            } else {
                $status = "Invalid input.";
                $notification_status = "is-danger";
            }
            break;
        default:
            $status = "Invalid action.";
            $notification_status = "is-danger";
            break;
    }
}

// Fetch usernames and their current typo counts
$typoData = [];
if ($result = $db->query("SELECT username, typo_count FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $typoData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching typo data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch command counts
$commandData = [];
if ($result = $db->query("SELECT command, count FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commandData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch games and their current death counts
$deathData = [];
if ($result = $db->query("SELECT game_name, death_count FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $deathData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching death data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch hugs and their current counts
$hugData = [];
if ($result = $db->query("SELECT username, hug_count FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching hug data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch kisses and their current counts
$kissData = [];
if ($result = $db->query("SELECT username, kiss_count FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching kiss data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch high-fives and their current counts
$highfiveData = [];
if ($result = $db->query("SELECT username, highfive_count FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching high-five data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch user counts and their current values
$userCountData = [];
if ($result = $db->query("SELECT command, user, count FROM user_counts")) {
    while ($row = $result->fetch_assoc()) {
        $userCountData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching user count data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch quotes from the quotes table (with id for edit/remove)
$quotesData = [];
if ($result = $db->query("SELECT id, quote, added FROM quotes ORDER BY added DESC")) {
    while ($row = $result->fetch_assoc()) {
        $quotesData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching quotes: " . $db->error;
    $notification_status = "is-danger";
}

// Handling form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'update':
            $formUsername = $_POST['typo-username'] ?? '';
            $typoCount = $_POST['typo_count'] ?? '';
            $formCommand = $_POST['command'] ?? '';
            $commandCount = $_POST['command_count'] ?? '';
            $formGame = $_POST['death-game'] ?? '';
            $deathCount = $_POST['death_count'] ?? '';
            $formHugUser = $_POST['hug-username'] ?? '';
            $hugCount = $_POST['hug_count'] ?? '';
            $formKissUser = $_POST['kiss-username'] ?? '';
            $kissCount = $_POST['kiss_count'] ?? '';
            $formHighfiveUser = $_POST['highfive-username'] ?? '';
            $highfiveCount = $_POST['highfive_count'] ?? '';
            $formUserCountCommand = $_POST['usercount-command'] ?? '';
            $formUserCountUser = $_POST['usercount-user'] ?? '';
            $userCountValue = $_POST['usercount_count'] ?? '';
            // Update typo count
            if ($formUsername && is_numeric($typoCount)) {
                $stmt = $db->prepare("UPDATE user_typos SET typo_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $typoCount, $formUsername);
                    if ($stmt->execute()) {
                        $status = "Typo count updated successfully for user {$formUsername}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update command count
            if ($formCommand && is_numeric($commandCount)) {
                $stmt = $db->prepare("UPDATE custom_counts SET count = ? WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $commandCount, $formCommand);
                    if ($stmt->execute()) {
                        $status = "Count updated successfully for the command {$formCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update death count and total deaths
            if ($formGame && is_numeric($deathCount)) {
                // Get the old count for this game
                $oldDeathCount = 0;
                $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formGame);
                    $stmt->execute();
                    $stmt->bind_result($oldDeathCount);
                    $stmt->fetch();
                    $stmt->close();
                }
                $diff = $deathCount - $oldDeathCount;
                if ($diff !== 0) {
                    $stmt = $db->prepare("UPDATE game_deaths SET death_count = ? WHERE game_name = ?");
                    if ($stmt) {
                        $stmt->bind_param('is', $deathCount, $formGame);
                        if ($stmt->execute()) {
                            $stmt2 = $db->prepare("UPDATE total_deaths SET death_count = death_count + ? LIMIT 1");
                            if ($stmt2) {
                                $stmt2->bind_param('i', $diff);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                            $status = "Death count updated successfully for game {$formGame}.";
                            $notification_status = "is-success";
                        } else {
                            $status = "Error: " . $stmt->error;
                            $notification_status = "is-danger";
                        }
                        $stmt->close();
                    } else {
                        $status = "Error: " . $db->error;
                        $notification_status = "is-danger";
                    }
                } else {
                    $status = "No change in death count for game {$formGame}.";
                    $notification_status = "is-info";
                }
            }
            // Update hug count
            if ($formHugUser && is_numeric($hugCount)) {
                $stmt = $db->prepare("UPDATE hug_counts SET hug_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $hugCount, $formHugUser);
                    if ($stmt->execute()) {
                        $status = "Hug count updated successfully for user {$formHugUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update kiss count
            if ($formKissUser && is_numeric($kissCount)) {
                $stmt = $db->prepare("UPDATE kiss_counts SET kiss_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $kissCount, $formKissUser);
                    if ($stmt->execute()) {
                        $status = "Kiss count updated successfully for user {$formKissUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update high-five count
            if ($formHighfiveUser && is_numeric($highfiveCount)) {
                $stmt = $db->prepare("UPDATE highfive_counts SET highfive_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $highfiveCount, $formHighfiveUser);
                    if ($stmt->execute()) {
                        $status = "High-five count updated successfully for user {$formHighfiveUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update user count
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE user_counts SET count = ? WHERE command = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $status = "User count updated successfully for user {$formUserCountUser} and command {$formUserCountCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update reward count
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE reward_counts SET count = ? WHERE reward_id = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $status = "Reward count updated successfully for user {$formUserCountUser} and reward {$formUserCountCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        case 'remove_quote':
            $quoteId = $_POST['quote-id-remove'] ?? '';
            if ($quoteId) {
                $stmt = $db->prepare("DELETE FROM quotes WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $quoteId);
                    if ($stmt->execute()) {
                        $status = "Quote removed successfully.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        case 'update_quote':
            $quoteId = $_POST['quote-id'] ?? '';
            $quoteText = $_POST['quote-text'] ?? '';
            $quoteAdded = $_POST['quote-added'] ?? '';
            if ($quoteId && $quoteText !== '' && $quoteAdded !== '') {
                $stmt = $db->prepare("UPDATE quotes SET quote = ?, added = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssi', $quoteText, $quoteAdded, $quoteId);
                    if ($stmt->execute()) {
                        $status = "Quote updated successfully.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        default:
            $status = "Invalid action.";
            $notification_status = "is-danger";
            break;
    }
}

// Fetch usernames and their current typo counts
$typoData = [];
if ($result = $db->query("SELECT username, typo_count FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $typoData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching typo data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch command counts
$commandData = [];
if ($result = $db->query("SELECT command, count FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commandData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch games and their current death counts
$deathData = [];
if ($result = $db->query("SELECT game_name, death_count FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $deathData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching death data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch hugs and their current counts
$hugData = [];
if ($result = $db->query("SELECT username, hug_count FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching hug data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch kisses and their current counts
$kissData = [];
if ($result = $db->query("SELECT username, kiss_count FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching kiss data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch high-fives and their current counts
$highfiveData = [];
if ($result = $db->query("SELECT username, highfive_count FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching high-five data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch user counts and their current values
$userCountData = [];
if ($result = $db->query("SELECT command, user, count FROM user_counts")) {
    while ($row = $result->fetch_assoc()) {
        $userCountData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching user count data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch quotes from the quotes table
$quotesData = [];
if ($result = $db->query("SELECT id, quote, added FROM quotes ORDER BY added DESC")) {
    while ($row = $result->fetch_assoc()) {
        $quotesData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching quotes: " . $db->error;
    $notification_status = "is-danger";
}

// Handling form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'update':
            $formUsername = $_POST['typo-username'] ?? '';
            $typoCount = $_POST['typo_count'] ?? '';
            $formCommand = $_POST['command'] ?? '';
            $commandCount = $_POST['command_count'] ?? '';
            $formGame = $_POST['death-game'] ?? '';
            $deathCount = $_POST['death_count'] ?? '';
            $formHugUser = $_POST['hug-username'] ?? '';
            $hugCount = $_POST['hug_count'] ?? '';
            $formKissUser = $_POST['kiss-username'] ?? '';
            $kissCount = $_POST['kiss_count'] ?? '';
            $formHighfiveUser = $_POST['highfive-username'] ?? '';
            $highfiveCount = $_POST['highfive_count'] ?? '';
            $formUserCountCommand = $_POST['usercount-command'] ?? '';
            $formUserCountUser = $_POST['usercount-user'] ?? '';
            $userCountValue = $_POST['usercount_count'] ?? '';
            // Update typo count
            if ($formUsername && is_numeric($typoCount)) {
                $stmt = $db->prepare("UPDATE user_typos SET typo_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $typoCount, $formUsername);
                    if ($stmt->execute()) {
                        $status = "Typo count updated successfully for user {$formUsername}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update command count
            if ($formCommand && is_numeric($commandCount)) {
                $stmt = $db->prepare("UPDATE custom_counts SET count = ? WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $commandCount, $formCommand);
                    if ($stmt->execute()) {
                        $status = "Count updated successfully for the command {$formCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update death count and total deaths
            if ($formGame && is_numeric($deathCount)) {
                // Get the old count for this game
                $oldDeathCount = 0;
                $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $formGame);
                    $stmt->execute();
                    $stmt->bind_result($oldDeathCount);
                    $stmt->fetch();
                    $stmt->close();
                }
                $diff = $deathCount - $oldDeathCount;
                if ($diff !== 0) {
                    $stmt = $db->prepare("UPDATE game_deaths SET death_count = ? WHERE game_name = ?");
                    if ($stmt) {
                        $stmt->bind_param('is', $deathCount, $formGame);
                        if ($stmt->execute()) {
                            $stmt2 = $db->prepare("UPDATE total_deaths SET death_count = death_count + ? LIMIT 1");
                            if ($stmt2) {
                                $stmt2->bind_param('i', $diff);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                            $status = "Death count updated successfully for game {$formGame}.";
                            $notification_status = "is-success";
                        } else {
                            $status = "Error: " . $stmt->error;
                            $notification_status = "is-danger";
                        }
                        $stmt->close();
                    } else {
                        $status = "Error: " . $db->error;
                        $notification_status = "is-danger";
                    }
                } else {
                    $status = "No change in death count for game {$formGame}.";
                    $notification_status = "is-info";
                }
            }
            // Update hug count
            if ($formHugUser && is_numeric($hugCount)) {
                $stmt = $db->prepare("UPDATE hug_counts SET hug_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $hugCount, $formHugUser);
                    if ($stmt->execute()) {
                        $status = "Hug count updated successfully for user {$formHugUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update kiss count
            if ($formKissUser && is_numeric($kissCount)) {
                $stmt = $db->prepare("UPDATE kiss_counts SET kiss_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $kissCount, $formKissUser);
                    if ($stmt->execute()) {
                        $status = "Kiss count updated successfully for user {$formKissUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update high-five count
            if ($formHighfiveUser && is_numeric($highfiveCount)) {
                $stmt = $db->prepare("UPDATE highfive_counts SET highfive_count = ? WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $highfiveCount, $formHighfiveUser);
                    if ($stmt->execute()) {
                        $status = "High-five count updated successfully for user {$formHighfiveUser}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update user count
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE user_counts SET count = ? WHERE command = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $status = "User count updated successfully for user {$formUserCountUser} and command {$formUserCountCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            // Update reward count
            if ($formUserCountCommand && $formUserCountUser && is_numeric($userCountValue)) {
                $stmt = $db->prepare("UPDATE reward_counts SET count = ? WHERE reward_id = ? AND user = ?");
                if ($stmt) {
                    $stmt->bind_param('iss', $userCountValue, $formUserCountCommand, $formUserCountUser);
                    if ($stmt->execute()) {
                        $status = "Reward count updated successfully for user {$formUserCountUser} and reward {$formUserCountCommand}.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        case 'remove_quote':
            $quoteId = $_POST['quote-id-remove'] ?? '';
            if ($quoteId) {
                $stmt = $db->prepare("DELETE FROM quotes WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $quoteId);
                    if ($stmt->execute()) {
                        $status = "Quote removed successfully.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        case 'update_quote':
            $quoteId = $_POST['quote-id'] ?? '';
            $quoteText = $_POST['quote-text'] ?? '';
            $quoteAdded = $_POST['quote-added'] ?? '';
            if ($quoteId && $quoteText !== '' && $quoteAdded !== '') {
                $stmt = $db->prepare("UPDATE quotes SET quote = ?, added = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssi', $quoteText, $quoteAdded, $quoteId);
                    if ($stmt->execute()) {
                        $status = "Quote updated successfully.";
                        $notification_status = "is-success";
                    } else {
                        $status = "Error: " . $stmt->error;
                        $notification_status = "is-danger";
                    }
                    $stmt->close();
                } else {
                    $status = "Error: " . $db->error;
                    $notification_status = "is-danger";
                }
            }
            break;
        default:
            $status = "Invalid action.";
            $notification_status = "is-danger";
            break;
    }
}

// Fetch usernames and their current typo counts
$typoData = [];
if ($result = $db->query("SELECT username, typo_count FROM user_typos")) {
    while ($row = $result->fetch_assoc()) {
        $typoData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching typo data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch command counts
$commandData = [];
if ($result = $db->query("SELECT command, count FROM custom_counts")) {
    while ($row = $result->fetch_assoc()) {
        $commandData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch games and their current death counts
$deathData = [];
if ($result = $db->query("SELECT game_name, death_count FROM game_deaths")) {
    while ($row = $result->fetch_assoc()) {
        $deathData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching death data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch hugs and their current counts
$hugData = [];
if ($result = $db->query("SELECT username, hug_count FROM hug_counts")) {
    while ($row = $result->fetch_assoc()) {
        $hugData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching hug data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch kisses and their current counts
$kissData = [];
if ($result = $db->query("SELECT username, kiss_count FROM kiss_counts")) {
    while ($row = $result->fetch_assoc()) {
        $kissData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching kiss data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch high-fives and their current counts
$highfiveData = [];
if ($result = $db->query("SELECT username, highfive_count FROM highfive_counts")) {
    while ($row = $result->fetch_assoc()) {
        $highfiveData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching high-five data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch user counts and their current values
$userCountData = [];
if ($result = $db->query("SELECT command, user, count FROM user_counts")) {
    while ($row = $result->fetch_assoc()) {
        $userCountData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching user count data: " . $db->error;
    $notification_status = "is-danger";
}

// Fetch quotes from the quotes table
$quotesData = [];
if ($result = $db->query("SELECT id, quote, added FROM quotes ORDER BY added DESC")) {
    while ($row = $result->fetch_assoc()) {
        $quotesData[] = $row;
    }
    $result->free();
} else {
    $status = "Error fetching quotes: " . $db->error;
    $notification_status = "is-danger";
}

// Check for AJAX requests
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'get_typo_count' && isset($_GET['username'])) {
        $requestedUsername = $_GET['username'];
        $stmt = $db->prepare("SELECT typo_count FROM user_typos WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param('s', $requestedUsername);
            $stmt->execute();
            $stmt->bind_result($typo_count);
            if ($stmt->fetch()) {
                echo $typo_count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "Error: " . $db->error;
        }
    } elseif ($_GET['action'] == 'get_command_count' && isset($_GET['command'])) {
        $requestedCommand = $_GET['command'];
        $stmt = $db->prepare("SELECT count FROM custom_counts WHERE command = ?");
        if ($stmt) {
            $stmt->bind_param('s', $requestedCommand);
            $stmt->execute();
            $stmt->bind_result($count);
            if ($stmt->fetch()) {
                echo $count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "Error: " . $db->error;
        }
    } elseif ($_GET['action'] == 'get_death_count' && isset($_GET['game'])) {
        $requestedGame = $_GET['game'];
        $stmt = $db->prepare("SELECT death_count FROM game_deaths WHERE game_name = ?");
        if ($stmt) {
            $stmt->bind_param('s', $requestedGame);
            $stmt->execute();
            $stmt->bind_result($death_count);
            if ($stmt->fetch()) {
                echo $death_count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "Error: " . $db->error;
        }
    } elseif ($_GET['action'] == 'get_hug_count' && isset($_GET['username'])) {
        $requestedUsername = $_GET['username'];
        $stmt = $db->prepare("SELECT hug_count FROM hug_counts WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param('s', $requestedUsername);
            $stmt->execute();
            $stmt->bind_result($hug_count);
            if ($stmt->fetch()) {
                echo $hug_count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "Error: " . $db->error;
        }
    } elseif ($_GET['action'] == 'get_kiss_count' && isset($_GET['username'])) {
        $requestedUsername = $_GET['username'];
        $stmt = $db->prepare("SELECT kiss_count FROM kiss_counts WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param('s', $requestedUsername);
            $stmt->execute();
            $stmt->bind_result($kiss_count);
            if ($stmt->fetch()) {
                echo $kiss_count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "Error: " . $db->error;
        }
    } elseif ($_GET['action'] == 'get_highfive_count' && isset($_GET['username'])) {
        $requestedUsername = $_GET['username'];
        $stmt = $db->prepare("SELECT highfive_count FROM highfive_counts WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param('s', $requestedUsername);
            $stmt->execute();
            $stmt->bind_result($highfive_count);
            if ($stmt->fetch()) {
                echo $highfive_count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "Error: " . $db->error;
        }
    } elseif ($_GET['action'] == 'get_usercount' && isset($_GET['command']) && isset($_GET['user'])) {
        $cmd = $_GET['command'];
        $user = $_GET['user'];
        $stmt = $db->prepare("SELECT count FROM user_counts WHERE command = ? AND user = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $cmd, $user);
            $stmt->execute();
            $stmt->bind_result($count);
            if ($stmt->fetch()) {
                echo $count;
            } else {
                echo "0";
            }
            $stmt->close();
        } else {
            echo "0";
        }
        exit;
    } elseif ($_GET['action'] == 'get_usercount_users' && isset($_GET['command'])) {
        $cmd = $_GET['command'];
        $stmt = $db->prepare("SELECT user FROM user_counts WHERE command = ?");
        $users = [];
        if ($stmt) {
            $stmt->bind_param('s', $cmd);
            $stmt->execute();
            $stmt->bind_result($user);
            while ($stmt->fetch()) {
                $users[] = $user;
            }
            $stmt->close();
        }
        header('Content-Type: application/json');
        echo json_encode($users);
        exit;
    }
    exit;
}

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';
$defaultEditTab = 'typos';
if ($cookieConsent && isset($_COOKIE['preferred_edit_tab'])) {
    $defaultEditTab = $_COOKIE['preferred_edit_tab'];
}

// Prepare a JavaScript object with Typo Counts & Command Counts for each user
$commandCountsJs = json_encode(array_column($commandData, 'count', 'command'));
$typoCountsJs = json_encode(array_column($typoData, 'typo_count', 'username'));

// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white mt-6"
            style="border-radius: 14px; box-shadow: 0 4px 24px #000a; margin-top: 3rem;">
            <header class="card-header" style="border-bottom: 1px solid #23272f;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-edit"></i></span>
                    <?php echo t('edit_counters_title'); ?>
                </span>
            </header>
            <div class="card-content">
                <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
                    <div class="notification <?php echo $notification_status; ?>"><?php echo $status; ?></div>
                <?php endif; ?>
                <!-- Tab Buttons -->
                <div class="buttons is-centered mb-4">
                    <button class="button is-info" data-type="typos"
                        onclick="showTab('typos')"><?php echo t('edit_counters_edit_user_typos'); ?></button>
                    <button class="button is-info" data-type="customCounts"
                        onclick="showTab('customCounts')"><?php echo t('counters_custom_counts'); ?></button>
                    <button class="button is-info" data-type="deaths"
                        onclick="showTab('deaths')"><?php echo t('counters_deaths'); ?></button>
                    <button class="button is-info" data-type="hugs"
                        onclick="showTab('hugs')"><?php echo t('counters_hugs'); ?></button>
                    <button class="button is-info" data-type="kisses"
                        onclick="showTab('kisses')"><?php echo t('counters_kisses'); ?></button>
                    <button class="button is-info" data-type="highfives"
                        onclick="showTab('highfives')"><?php echo t('counters_highfives'); ?></button>
                    <button class="button is-info" data-type="userCounts"
                        onclick="showTab('userCounts')"><?php echo t('counters_user_counts'); ?></button>
                    <button class="button is-info" data-type="rewardCounts"
                        onclick="showTab('rewardCounts')"><?php echo t('counters_reward_counts'); ?></button>
                    <button class="button is-info" data-type="quotes"
                        onclick="showTab('quotes')"><?php echo t('counters_quotes'); ?></button>
                </div>
                <!-- Tab Contents -->
                <div id="tab-typos" class="tab-content">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit User Typos -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_user_typos'); ?></h4>
                                <form action="" method="post" id="typo-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="typo-username"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="typo-username" name="typo-username" required
                                                    onchange="updateCurrentCount('typo', this.value); enableButton('typo-username','typo-edit-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($usernames as $typo_name): ?>
                                                        <option title="<?php echo htmlspecialchars($typo_name); ?>"
                                                            value="<?php echo htmlspecialchars($typo_name); ?>">
                                                            <?php echo htmlspecialchars($typo_name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="typo_count"><?php echo t('edit_counters_new_typo_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="typo_count" name="typo_count"
                                                value="" required min="0">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="typo-edit-btn"
                                                disabled><?php echo t('edit_counters_update_typo_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove User Typo Record -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_user_typo'); ?></h4>
                                <form action="" method="post" id="typo-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="typo">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="typo-username-remove"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="typo-username-remove" name="typo-username-remove" required
                                                    onchange="enableButton('typo-username-remove','typo-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($usernames as $typo_name): ?>
                                                        <option title="<?php echo htmlspecialchars($typo_name); ?>"
                                                            value="<?php echo htmlspecialchars($typo_name); ?>">
                                                            <?php echo htmlspecialchars($typo_name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="typo-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_typo_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-customCounts" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit Custom Counter -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_custom_counter'); ?></h4>
                                <form action="" method="post" id="command-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="command"><?php echo t('edit_counters_command_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="command" name="command" required
                                                    onchange="updateCurrentCount('command', this.value); enableButton('command','command-edit-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_command'); ?>
                                                    </option>
                                                    <?php foreach ($commands as $command): ?>
                                                        <option title="<?php echo htmlspecialchars($command); ?>"
                                                            value="<?php echo htmlspecialchars($command); ?>">
                                                            <?php echo htmlspecialchars($command); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="command_count"><?php echo t('edit_counters_new_command_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="command_count" name="command_count"
                                                value="" min="0" required>
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="command-edit-btn"
                                                disabled><?php echo t('edit_counters_update_command_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove Custom Counter -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_custom_counter'); ?></h4>
                                <form action="" method="post" id="command-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="command">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="command-remove"><?php echo t('edit_counters_command_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="command-remove" name="command-remove" required
                                                    onchange="enableButton('command-remove','command-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_command'); ?>
                                                    </option>
                                                    <?php foreach ($commands as $command): ?>
                                                        <option title="<?php echo htmlspecialchars($command); ?>"
                                                            value="<?php echo htmlspecialchars($command); ?>">
                                                            <?php echo htmlspecialchars($command); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="command-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_command_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-deaths" class="tab-content" style="display:none;">
                    <!-- Show total deaths -->
                    <div class="mb-4">
                        <div class="notification is-info has-text-centered" style="font-size:1.2em;">
                            <strong><?php echo t('edit_counters_total_deaths'); ?>:</strong>
                            <span class="has-text-weight-bold has-text-danger"><?php echo (int) $totalDeaths; ?></span>
                        </div>
                    </div>
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit Game Death Count -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_game_deaths'); ?></h4>
                                <form action="" method="post" id="death-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="death-game"><?php echo t('edit_counters_game_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="death-game" name="death-game" required
                                                    onchange="updateCurrentCount('death', this.value); enableButton('death-game','death-edit-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_game'); ?>
                                                    </option>
                                                    <?php foreach ($games as $game): ?>
                                                        <option title="<?php echo htmlspecialchars($game); ?>"
                                                            value="<?php echo htmlspecialchars($game); ?>">
                                                            <?php echo htmlspecialchars($game); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="death_count"><?php echo t('edit_counters_new_death_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="death_count" name="death_count"
                                                value="" required min="0">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="death-edit-btn"
                                                disabled><?php echo t('edit_counters_update_death_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove Game Death Counter -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_game_death_counter'); ?></h4>
                                <form action="" method="post" id="death-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="death">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="death-game-remove"><?php echo t('edit_counters_game_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="death-game-remove" name="death-game-remove" required
                                                    onchange="enableButton('death-game-remove','death-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_game'); ?>
                                                    </option>
                                                    <?php foreach ($games as $game): ?>
                                                        <option title="<?php echo htmlspecialchars($game); ?>"
                                                            value="<?php echo htmlspecialchars($game); ?>">
                                                            <?php echo htmlspecialchars($game); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="death-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_game_death_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-hugs" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit Hug Count -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_user_hugs'); ?></h4>
                                <form action="" method="post" id="hug-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="hug-username"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="hug-username" name="hug-username" required
                                                    onchange="updateCurrentCount('hug', this.value); enableButton('hug-username','hug-edit-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($hugUsers as $hugUser): ?>
                                                        <option title="<?php echo htmlspecialchars($hugUser); ?>"
                                                            value="<?php echo htmlspecialchars($hugUser); ?>">
                                                            <?php echo htmlspecialchars($hugUser); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="hug_count"><?php echo t('edit_counters_new_hug_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="hug_count" name="hug_count" value=""
                                                required min="0">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="hug-edit-btn"
                                                disabled><?php echo t('edit_counters_update_hug_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove Hug Record -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_user_hug'); ?></h4>
                                <form action="" method="post" id="hug-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="hug">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="hug-username-remove"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="hug-username-remove" name="hug-username-remove" required
                                                    onchange="enableButton('hug-username-remove','hug-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($hugUsers as $hugUser): ?>
                                                        <option title="<?php echo htmlspecialchars($hugUser); ?>"
                                                            value="<?php echo htmlspecialchars($hugUser); ?>">
                                                            <?php echo htmlspecialchars($hugUser); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="hug-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_hug_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-kisses" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit Kiss Count -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_user_kisses'); ?></h4>
                                <form action="" method="post" id="kiss-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="kiss-username"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="kiss-username" name="kiss-username" required
                                                    onchange="updateCurrentCount('kiss', this.value); enableButton('kiss-username','kiss-edit-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($kissUsers as $kissUser): ?>
                                                        <option title="<?php echo htmlspecialchars($kissUser); ?>"
                                                            value="<?php echo htmlspecialchars($kissUser); ?>">
                                                            <?php echo htmlspecialchars($kissUser); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="kiss_count"><?php echo t('edit_counters_new_kiss_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="kiss_count" name="kiss_count"
                                                value="" required min="0">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="kiss-edit-btn"
                                                disabled><?php echo t('edit_counters_update_kiss_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove Kiss Record -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_user_kiss'); ?></h4>
                                <form action="" method="post" id="kiss-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="kiss">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="kiss-username-remove"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="kiss-username-remove" name="kiss-username-remove" required
                                                    onchange="enableButton('kiss-username-remove','kiss-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($kissUsers as $kissUser): ?>
                                                        <option title="<?php echo htmlspecialchars($kissUser); ?>"
                                                            value="<?php echo htmlspecialchars($kissUser); ?>">
                                                            <?php echo htmlspecialchars($kissUser); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="kiss-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_kiss_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-highfives" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit High-Five Count -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_user_highfives'); ?></h4>
                                <form action="" method="post" id="highfive-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="highfive-username"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="highfive-username" name="highfive-username" required
                                                    onchange="updateCurrentCount('highfive', this.value); enableButton('highfive-username','highfive-edit-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($highfiveUsers as $highfiveUser): ?>
                                                        <option title="<?php echo htmlspecialchars($highfiveUser); ?>"
                                                            value="<?php echo htmlspecialchars($highfiveUser); ?>">
                                                            <?php echo htmlspecialchars($highfiveUser); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="highfive_count"><?php echo t('edit_counters_new_highfive_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="highfive_count" name="highfive_count"
                                                value="" required min="0">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="highfive-edit-btn"
                                                disabled><?php echo t('edit_counters_update_highfive_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove High-Five Record -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_user_highfive'); ?></h4>
                                <form action="" method="post" id="highfive-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="highfive">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="highfive-username-remove"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="highfive-username-remove" name="highfive-username-remove"
                                                    required
                                                    onchange="enableButton('highfive-username-remove','highfive-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <?php foreach ($highfiveUsers as $highfiveUser): ?>
                                                        <option title="<?php echo htmlspecialchars($highfiveUser); ?>"
                                                            value="<?php echo htmlspecialchars($highfiveUser); ?>">
                                                            <?php echo htmlspecialchars($highfiveUser); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="highfive-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_highfive_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-userCounts" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit User Count -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_user_counts'); ?></h4>
                                <form action="" method="post" id="usercount-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="usercount-command"><?php echo t('edit_counters_command_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="usercount-command" name="usercount-command" required
                                                    onchange="populateUserCountUsers(); enableUserCountEditBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_command'); ?>
                                                    </option>
                                                    <?php foreach ($userCountCommands as $command): ?>
                                                        <option title="<?php echo htmlspecialchars($command); ?>"
                                                            value="<?php echo htmlspecialchars($command); ?>">
                                                            <?php echo htmlspecialchars($command); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="usercount-user"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="usercount-user" name="usercount-user" required
                                                    onchange="enableUserCountEditBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                    <!-- Options will be populated by JS -->
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="usercount_count"><?php echo t('edit_counters_new_usercount_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="usercount_count"
                                                name="usercount_count" value="" required min="0"
                                                oninput="enableUserCountEditBtn();">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="usercount-edit-btn"
                                                disabled><?php echo t('edit_counters_update_usercount_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove User Count Record -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_user_usercount'); ?></h4>
                                <form action="" method="post" id="usercount-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="usercount">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="usercount-command-remove"><?php echo t('edit_counters_command_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="usercount-command-remove" name="usercount-command-remove"
                                                    required
                                                    onchange="populateUserCountUsersRemove(); enableUserCountRemoveBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_command'); ?>
                                                    </option>
                                                    <?php foreach ($userCountCommands as $command): ?>
                                                        <option title="<?php echo htmlspecialchars($command); ?>"
                                                            value="<?php echo htmlspecialchars($command); ?>">
                                                            <?php echo htmlspecialchars($command); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="usercount-user-remove"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="usercount-user-remove" name="usercount-user-remove" required
                                                    onchange="enableUserCountRemoveBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="usercount-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_usercount_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-rewardCounts" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline">
                        <!-- Edit Reward Count -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_reward_counts'); ?></h4>
                                <form action="" method="post" id="rewardcount-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update">
                                    <div class="field">
                                        <label class="label"
                                            for="rewardcount-reward"><?php echo t('edit_counters_reward_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="rewardcount-reward" name="rewardcount-reward" required
                                                    onchange="populateRewardCountUsers(); enableRewardCountEditBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_reward'); ?>
                                                    </option>
                                                    <?php foreach ($rewardIds as $rid): ?>
                                                        <option value="<?php echo htmlspecialchars($rid); ?>"
                                                            title="<?php echo htmlspecialchars($rewardTitles[$rid] ?? $rid); ?>">
                                                            <?php echo htmlspecialchars($rewardTitles[$rid] ?? $rid); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="rewardcount-user"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="rewardcount-user" name="rewardcount-user" required
                                                    onchange="enableRewardCountEditBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="rewardcount_count"><?php echo t('edit_counters_new_rewardcount_count'); ?></label>
                                        <div class="control">
                                            <input class="input" type="number" id="rewardcount_count"
                                                name="rewardcount_count" value="" required min="0">
                                        </div>
                                    </div>
                                    <div class="is-flex-grow-1"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="rewardcount-edit-btn"
                                                disabled><?php echo t('edit_counters_update_rewardcount_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove Reward Count Record -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_rewardcount'); ?></h4>
                                <form action="" method="post" id="rewardcount-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="rewardcount">
                                    <input type="hidden" name="action" value="remove">
                                    <div class="field">
                                        <label class="label"
                                            for="rewardcount-reward-remove"><?php echo t('edit_counters_reward_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="rewardcount-reward-remove" name="rewardcount-reward-remove"
                                                    required
                                                    onchange="populateRewardCountUsersRemove(); enableRewardCountRemoveBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_reward'); ?>
                                                    </option>
                                                    <?php foreach ($rewardIds as $rid): ?>
                                                        <option value="<?php echo htmlspecialchars($rid); ?>"
                                                            title="<?php echo htmlspecialchars($rewardTitles[$rid] ?? $rid); ?>">
                                                            <?php echo htmlspecialchars($rewardTitles[$rid] ?? $rid); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="rewardcount-user-remove"><?php echo t('edit_counters_username_label'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="rewardcount-user-remove" name="rewardcount-user-remove"
                                                    required onchange="enableRewardCountRemoveBtn();">
                                                    <option value=""><?php echo t('edit_counters_select_user'); ?>
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex-grow:1"></div>
                                    <div style="height: 88px;"></div>
                                    <div class="field is-grouped is-grouped-right mt-4">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="rewardcount-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_rewardcount_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="tab-quotes" class="tab-content" style="display:none;">
                    <div class="columns is-desktop is-multiline is-flex">
                        <!-- Edit Quote -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-flex-grow-1">
                                <h4 class="title is-5"><?php echo t('edit_counters_edit_quote'); ?></h4>
                                <form action="" method="post" id="quote-edit-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1">
                                    <input type="hidden" name="action" value="update_quote">
                                    <div class="field">
                                        <label class="label"
                                            for="quote-id"><?php echo t('edit_counters_select_quote'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="quote-id" name="quote-id" required
                                                    onchange="populateQuoteEdit();">
                                                    <option value=""><?php echo t('edit_counters_select_quote'); ?>
                                                    </option>
                                                    <?php foreach ($quotesData as $q): ?>
                                                        <option value="<?php echo (int) $q['id']; ?>"
                                                            data-quote="<?php echo htmlspecialchars($q['quote']); ?>"
                                                            data-added="<?php echo htmlspecialchars($q['added']); ?>">
                                                            <?php echo htmlspecialchars($q['quote']); ?>
                                                            (<?php echo date('Y-m-d H:i:s', strtotime($q['added'])); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="quote-text"><?php echo t('edit_counters_edit_quote_text'); ?></label>
                                        <div class="control">
                                            <textarea class="textarea" id="quote-text" name="quote-text" required
                                                rows="3"></textarea>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"
                                            for="quote-added"><?php echo t('edit_counters_edit_quote_added'); ?></label>
                                        <div class="control">
                                            <input class="input" type="datetime-local" id="quote-added"
                                                name="quote-added" required>
                                        </div>
                                        <p class="help"><?php echo t('edit_counters_edit_quote_added_help'); ?></p>
                                    </div>
                                    <div
                                        class="field is-grouped is-grouped-right mt-4 is-flex-grow-1 is-align-items-end">
                                        <div class="control">
                                            <button type="submit" class="button is-primary is-fullwidth"
                                                id="quote-edit-btn"
                                                disabled><?php echo t('edit_counters_update_quote_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <!-- Remove Quote -->
                        <div class="column is-6 is-flex is-flex-direction-column is-fullheight">
                            <div class="box has-background-dark is-flex is-flex-direction-column is-flex-grow-1">
                                <h4 class="title is-5"><?php echo t('edit_counters_remove_quote'); ?></h4>
                                <form action="" method="post" id="quote-remove-form"
                                    class="is-flex is-flex-direction-column is-flex-grow-1" data-type="quote">
                                    <input type="hidden" name="action" value="remove_quote">
                                    <div class="field">
                                        <label class="label"
                                            for="quote-id-remove"><?php echo t('edit_counters_select_quote'); ?></label>
                                        <div class="control">
                                            <div class="select is-fullwidth is-clipped">
                                                <select id="quote-id-remove" name="quote-id-remove" required
                                                    onchange="populateQuotePreview(); enableButton('quote-id-remove','quote-remove-btn');">
                                                    <option value=""><?php echo t('edit_counters_select_quote'); ?>
                                                    </option>
                                                    <?php foreach ($quotesData as $q): ?>
                                                        <option value="<?php echo (int) $q['id']; ?>"
                                                            data-quote="<?php echo htmlspecialchars($q['quote']); ?>">
                                                            <?php echo htmlspecialchars($q['quote']); ?>
                                                            (<?php echo date('Y-m-d H:i:s', strtotime($q['added'])); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="label"><?php echo t('edit_counters_quote_preview'); ?></label>
                                        <div class="control">
                                            <textarea class="textarea" id="quote-preview" readonly rows="7"
                                                style="background-color: #2b2b2b; color: #fff;"></textarea>
                                        </div>
                                    </div>
                                    <div
                                        class="field is-grouped is-grouped-right mt-4 is-flex-grow-1 is-align-items-end">
                                        <div class="control">
                                            <button type="submit" class="button is-danger is-fullwidth"
                                                id="quote-remove-btn"
                                                disabled><?php echo t('edit_counters_remove_quote_btn'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
    // Set a cookie
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        const expires = "expires=" + d.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/";
    }

    function populateQuotePreview() {
        const select = document.getElementById('quote-id-remove');
        const textarea = document.getElementById('quote-preview');
        const selectedOption = select.options[select.selectedIndex];
        textarea.value = selectedOption.dataset.quote || '';
    }

    // Tab system like counters.php
    function showTab(type) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => tab.style.display = 'none');
        // Show selected tab
        var tab = document.getElementById('tab-' + type);
        if (tab) tab.style.display = '';
        // Update button states
        document.querySelectorAll('.buttons .button').forEach(button => {
            if (button.getAttribute('data-type') === type) {
                button.classList.remove('is-info');
                button.classList.add('is-primary');
            } else {
                button.classList.remove('is-primary');
                button.classList.add('is-info');
            }
        });
        // Store the user's preferred edit tab in a cookie if consent is given
        if (<?php echo $cookieConsent ? 'true' : 'false'; ?>) {
            setCookie('preferred_edit_tab', type, 30); // Store for 30 days
        }
    }

    // On page load, show the preferred tab (from cookie) or default to 'typos'
    document.addEventListener('DOMContentLoaded', function () {
        var defaultTab = <?php echo json_encode($defaultEditTab); ?>;
        showTab(defaultTab);
        enableButton('typo-username', 'typo-edit-btn');
        enableButton('typo-username-remove', 'typo-remove-btn');
        enableButton('command', 'command-edit-btn');
        enableButton('command-remove', 'command-remove-btn');
        enableButton('death-game', 'death-edit-btn');
        enableButton('death-game-remove', 'death-remove-btn');
        enableButton('hug-username', 'hug-edit-btn');
        enableButton('hug-username-remove', 'hug-remove-btn');
        enableButton('kiss-username', 'kiss-edit-btn');
        enableButton('kiss-username-remove', 'kiss-remove-btn');
        enableButton('highfive-username', 'highfive-edit-btn');
        enableButton('highfive-username-remove', 'highfive-remove-btn');
        enableButton('usercount-command', 'usercount-edit-btn');
        enableButton('usercount-user', 'usercount-edit-btn');
        enableButton('usercount-command-remove', 'usercount-remove-btn');
        enableButton('usercount-user-remove', 'usercount-remove-btn');
        enableButton('rewardcount-reward', 'rewardcount-edit-btn');
        enableButton('rewardcount-user', 'rewardcount-edit-btn');
        enableButton('rewardcount-reward-remove', 'rewardcount-remove-btn');
        enableButton('rewardcount-user-remove', 'rewardcount-remove-btn');
    });

    // SweetAlert2 for Remove User Typo Record
    document.addEventListener('DOMContentLoaded', function () {
        // Map type to context-aware removal message
        const removalMessages = {
            typo: "<?php echo t('edit_counters_swal_html_typo'); ?>",
            command: "<?php echo t('edit_counters_swal_html_command'); ?>",
            death: "<?php echo t('edit_counters_swal_html_death'); ?>",
            hug: "<?php echo t('edit_counters_swal_html_hug'); ?>",
            kiss: "<?php echo t('edit_counters_swal_html_kiss'); ?>",
            highfive: "<?php echo t('edit_counters_swal_html_highfive'); ?>",
            usercount: "<?php echo t('edit_counters_swal_html_usercount'); ?>",
            rewardcount: "<?php echo t('edit_counters_swal_html_rewardcount'); ?>"
        };

        function getRemovalMessage(type, value, command) {
            let msg = removalMessages[type] || removalMessages['typo'];
            // For usercount and rewardcount, we want to show both user and command
            if (type === 'usercount' || type === 'rewardcount') {
                msg = msg.replace(':username', '<span class="has-text-danger">' + value + ' (' + command + ')</span>');
            } else {
                msg = msg.replace(':username', '<span class="has-text-danger">' + value + '</span>');
            }
            return msg;
        }

        // Helper to wire up SweetAlert for a form
        function wireRemoveForm(formId, selectId, type, getExtra) {
            var form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var select = document.getElementById(selectId);
                    var value = select ? select.value : '';
                    if (!value) return;
                    var extra = getExtra ? getExtra() : undefined;
                    Swal.fire({
                        title: '<?php echo t('edit_counters_swal_title'); ?>',
                        html: getRemovalMessage(type, value, extra),
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: '<?php echo t('edit_counters_swal_confirm'); ?>',
                        cancelButtonText: '<?php echo t('edit_counters_swal_cancel'); ?>',
                        focusCancel: true,
                        customClass: {
                            confirmButton: 'button is-danger',
                            cancelButton: 'button is-light'
                        },
                        buttonsStyling: false
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            }
        }

        wireRemoveForm('typo-remove-form', 'typo-username-remove', 'typo');
        wireRemoveForm('command-remove-form', 'command-remove', 'command');
        wireRemoveForm('death-remove-form', 'death-game-remove', 'death');
        wireRemoveForm('hug-remove-form', 'hug-username-remove', 'hug');
        wireRemoveForm('kiss-remove-form', 'kiss-username-remove', 'kiss');
        wireRemoveForm('highfive-remove-form', 'highfive-username-remove', 'highfive');
        wireRemoveForm('usercount-remove-form', 'usercount-user-remove', 'usercount', function () {
            return document.getElementById('usercount-command-remove').value;
        });
        wireRemoveForm('rewardcount-remove-form', 'rewardcount-reward-remove', 'rewardcount', function () {
            return document.getElementById('rewardcount-user-remove').value;
        });
    });

    // Data from PHP for current counts
    const typoCounts = <?php echo $typoCountsJs; ?>;
    const commandCounts = <?php echo $commandCountsJs; ?>;
    const deathCounts = <?php echo json_encode(array_column($deathData, 'death_count', 'game_name')); ?>;
    const hugCounts = <?php echo json_encode(array_column($hugData, 'hug_count', 'username')); ?>;
    const kissCounts = <?php echo json_encode(array_column($kissData, 'kiss_count', 'username')); ?>;
    const highfiveCounts = <?php echo json_encode(array_column($highfiveData, 'highfive_count', 'username')); ?>;
    const userCountCounts = <?php
    $userCountArr = [];
    foreach ($userCountData as $row) {
        $userCountArr[$row['command']][$row['user']] = $row['count'];
    }
    echo json_encode($userCountArr);
    ?>;
    const rewardCounts = <?php echo json_encode($rewardCountsJs); ?>;
    const rewardTitles = <?php echo json_encode($rewardTitlesJs); ?>;

    // Populate user dropdown for reward counts (edit)
    function populateRewardCountUsers() {
        var rid = document.getElementById('rewardcount-reward').value;
        var userSelect = document.getElementById('rewardcount-user');
        userSelect.innerHTML = '<option value=""><?php echo t('edit_counters_select_user'); ?></option>';
        if (rid && rewardCounts[rid]) {
            Object.keys(rewardCounts[rid]).forEach(function (user) {
                var opt = document.createElement('option');
                opt.value = user;
                opt.textContent = user;
                userSelect.appendChild(opt);
            });
        }
        document.getElementById('rewardcount_count').value = '';
        enableRewardCountEditBtn();
    }

    // Populate user dropdown for reward counts (remove)
    function populateRewardCountUsersRemove() {
        var rid = document.getElementById('rewardcount-reward-remove').value;
        var userSelect = document.getElementById('rewardcount-user-remove');
        userSelect.innerHTML = '<option value=""><?php echo t('edit_counters_select_user'); ?></option>';
        if (rid && rewardCounts[rid]) {
            Object.keys(rewardCounts[rid]).forEach(function (user) {
                var opt = document.createElement('option');
                opt.value = user;
                opt.textContent = user;
                userSelect.appendChild(opt);
            });
        }
        enableRewardCountRemoveBtn();
    }

    // Enable/disable edit button for reward counts
    function enableRewardCountEditBtn() {
        var rid = document.getElementById('rewardcount-reward').value;
        var user = document.getElementById('rewardcount-user').value;
        var btn = document.getElementById('rewardcount-edit-btn');
        btn.disabled = !(rid && user);
        updateCurrentCount('rewardcount');
    }

    // Enable/disable remove button for reward counts
    function enableRewardCountRemoveBtn() {
        var rid = document.getElementById('rewardcount-reward-remove').value;
        var user = document.getElementById('rewardcount-user-remove').value;
        var btn = document.getElementById('rewardcount-remove-btn');
        btn.disabled = !(rid && user);
    }

    // Update input field for reward count
    function updateCurrentCount(type, value) {
        switch (type) {
            case 'typo':
                document.getElementById('typo_count').value = (value && typoCounts[value] !== undefined) ? typoCounts[value] : '';
                break;
            case 'command':
                document.getElementById('command_count').value = (value && commandCounts[value] !== undefined) ? commandCounts[value] : '';
                break;
            case 'death':
                document.getElementById('death_count').value = (value && deathCounts[value] !== undefined) ? deathCounts[value] : '';
                break;
            case 'hug':
                document.getElementById('hug_count').value = (value && hugCounts[value] !== undefined) ? hugCounts[value] : '';
                break;
            case 'kiss':
                document.getElementById('kiss_count').value = (value && kissCounts[value] !== undefined) ? kissCounts[value] : '';
                break;
            case 'highfive':
                document.getElementById('highfive_count').value = (value && highfiveCounts[value] !== undefined) ? highfiveCounts[value] : '';
                break;
            case 'usercount':
                var cmd = document.getElementById('usercount-command').value;
                var user = document.getElementById('usercount-user').value;
                if (cmd && user && userCountCounts[cmd] && userCountCounts[cmd][user] !== undefined) {
                    document.getElementById('usercount_count').value = userCountCounts[cmd][user];
                } else {
                    document.getElementById('usercount_count').value = '';
                }
                break;
            case 'rewardcount':
                var rid = document.getElementById('rewardcount-reward').value;
                var user = document.getElementById('rewardcount-user').value;
                if (rid && user && rewardCounts[rid] && rewardCounts[rid][user] !== undefined) {
                    document.getElementById('rewardcount_count').value = rewardCounts[rid][user];
                } else {
                    document.getElementById('rewardcount_count').value = '';
                }
                break;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Wire up user count dropdowns
        var usercountCmd = document.getElementById('usercount-command');
        if (usercountCmd) {
            usercountCmd.addEventListener('change', function () {
                populateUserCountUsers();
            });
        }
        var usercountCmdRemove = document.getElementById('usercount-command-remove');
        if (usercountCmdRemove) {
            usercountCmdRemove.addEventListener('change', function () {
                populateUserCountUsersRemove();
            });
        }
        // Also wire up user dropdowns to enable/disable buttons
        var usercountUser = document.getElementById('usercount-user');
        if (usercountUser) {
            usercountUser.addEventListener('change', function () {
                enableUserCountEditBtn();
            });
        }
        var usercountUserRemove = document.getElementById('usercount-user-remove');
        if (usercountUserRemove) {
            usercountUserRemove.addEventListener('change', function () {
                enableUserCountRemoveBtn();
            });
        }
        // Wire up reward count dropdowns
        var rewardcountReward = document.getElementById('rewardcount-reward');
        if (rewardcountReward) {
            rewardcountReward.addEventListener('change', function () {
                populateRewardCountUsers();
            });
        }
        var rewardcountRewardRemove = document.getElementById('rewardcount-reward-remove');
        if (rewardcountRewardRemove) {
            rewardcountRewardRemove.addEventListener('change', function () {
                populateRewardCountUsersRemove();
            });
        }
        var rewardcountUser = document.getElementById('rewardcount-user');
        if (rewardcountUser) {
            rewardcountUser.addEventListener('change', function () {
                enableRewardCountEditBtn();
            });
        }
        var rewardcountUserRemove = document.getElementById('rewardcount-user-remove');
        if (rewardcountUserRemove) {
            rewardcountUserRemove.addEventListener('change', function () {
                enableRewardCountRemoveBtn();
            });
        }
        // Wire up quote edit form
        var quoteEditSelect = document.getElementById('quote-id');
        if (quoteEditSelect) {
            quoteEditSelect.addEventListener('change', function () {
                populateQuoteEdit();
            });
        }
        // Wire up quote remove form
        var quoteRemoveSelect = document.getElementById('quote-id-remove');
        var quoteRemoveBtn = document.getElementById('quote-remove-btn');
        if (quoteRemoveSelect && quoteRemoveBtn) {
            quoteRemoveSelect.addEventListener('change', function () {
                quoteRemoveBtn.disabled = !quoteRemoveSelect.value;
            });
        }
    });

    // Populate quote textarea and date/time when selecting a quote to edit
    function populateQuoteEdit() {
        var select = document.getElementById('quote-id');
        var btn = document.getElementById('quote-edit-btn');
        var textarea = document.getElementById('quote-text');
        var addedInput = document.getElementById('quote-added');
        if (select && textarea && addedInput) {
            var selected = select.options[select.selectedIndex];
            textarea.value = selected && selected.value ? selected.getAttribute('data-quote') : '';
            // Convert "YYYY-MM-DD HH:MM:SS" to "YYYY-MM-DDTHH:MM:SS" for datetime-local
            var raw = selected && selected.value ? selected.getAttribute('data-added') : '';
            if (raw) {
                // If the value is "YYYY-MM-DD HH:MM:SS", convert to "YYYY-MM-DDTHH:MM:SS"
                var dt = raw.replace(' ', 'T').slice(0, 19);
                addedInput.value = dt;
            } else {
                addedInput.value = '';
            }
            btn.disabled = !select.value;
        }
    }
</script>
<?php
$scripts = ob_get_clean();
// Render layout
include "mod_layout.php";
?>