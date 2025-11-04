<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';
$pageTitle = t('admin_user_management_title');
ob_start();

function getTwitchSubTier($twitch_user_id) {
    global $clientID;
    $accessToken = $_SESSION['access_token'];
    if (empty($twitch_user_id)) {
        return null;
    }
    $broadcaster_id = "140296994";
    $url = "https://api.twitch.tv/helix/subscriptions?broadcaster_id={$broadcaster_id}&user_id={$twitch_user_id}";
    $headers = [ "Client-ID: $clientID", "Authorization: Bearer $accessToken" ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }
    $data = json_decode($response, true);
    curl_close($ch);
    // Check if we have subscription data in the response
    if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
        return $data['data'][0]['tier'];
    }
    return null;
}

function mask_email($email) {
    if (!$email) return '';
    $atPos = strpos($email, '@');
    if ($atPos === false) return str_repeat('•', strlen($email));
    return str_repeat('•', $atPos) . substr($email, $atPos);
}

function mask_api_key($api_key) {
    if (!$api_key) return '';
    return str_repeat('•', strlen($api_key));
}

// Fetch users from database
$users = [];
$stmt = $conn->prepare("SELECT * FROM users");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['twitch_user_id'] = (!empty($row['twitch_user_id'])) ? $row['twitch_user_id'] : null;
    $users[] = $row;
}
$stmt->close();

// Fetch restricted users into an array for quick lookup
$restricted_users = [];
$restricted_result = $conn->query("SELECT username, twitch_user_id FROM restricted_users");
while ($row = $restricted_result->fetch_assoc()) {
    $restricted_users[$row['username']] = true;
    if ($row['twitch_user_id']) {
        $restricted_users[$row['twitch_user_id']] = true;
    }
}

// Handle AJAX delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['delete_user_id']);
    $delete_db = isset($_POST['delete_db']) ? $_POST['delete_db'] : null;
    $response = ['success' => false, 'msg' => ''];
    // Get username from POST if provided (sent from JS before user row is deleted)
    $username = isset($_POST['username']) ? $_POST['username'] : null;
    // If not provided, fallback to DB lookup (for safety)
    if (!$username) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($username);
        $stmt->fetch();
        $stmt->close();
    }
    if (!$username) {
        $response['msg'] = 'User not found.';
        echo json_encode($response);
        exit;
    }
    if ($delete_db === '1') {
        // Drop the user's database
        $db_name = $username;
        require_once "/var/www/config/database.php";
        $mysqli = new mysqli($db_servername, $db_username, $db_password);
        if ($mysqli->connect_errno) {
            $response['msg'] = 'Failed to connect to MySQL for DB drop.';
            echo json_encode($response);
            exit;
        }
        if ($mysqli->query("DROP DATABASE `" . $mysqli->real_escape_string($db_name) . "`")) {
            $response['success'] = true;
            $response['msg'] = 'User database deleted.';
        } else {
            $response['msg'] = 'Could not delete user database.';
        }
        $mysqli->close();
        echo json_encode($response);
        exit;
    } else {
        // Delete user from users table
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['username'] = $username;
        } else {
            $response['msg'] = 'Could not delete user.';
        }
        $stmt->close();
        echo json_encode($response);
        exit;
    }
}

// Handle AJAX restrict/unrestrict requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restrict_action'])) {
    $action = $_POST['restrict_action'];
    $username = $_POST['username'] ?? '';
    $twitch_user_id = $_POST['twitch_user_id'] ?? '';
    $response = ['success' => false, 'msg' => ''];
    if ($action === 'restrict') {
        $stmt = $conn->prepare("INSERT IGNORE INTO restricted_users (username, twitch_user_id) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $twitch_user_id);
        $response['success'] = $stmt->execute();
        $stmt->close();
        if (!$response['success']) $response['msg'] = 'Could not restrict user.';
    } elseif ($action === 'unrestrict') {
        $stmt = $conn->prepare("DELETE FROM restricted_users WHERE username = ? OR twitch_user_id = ?");
        $stmt->bind_param("ss", $username, $twitch_user_id);
        $response['success'] = $stmt->execute();
        $stmt->close();
        if (!$response['success']) $response['msg'] = 'Could not unrestrict user.';
    }
    echo json_encode($response);
    exit;
}
?>
<div class="box">
    <div class="level mb-4">
        <div class="level-left">
            <h1 class="title is-4 mb-0"><span class="icon"><i class="fas fa-users-cog"></i></span> User Management</h1>
        </div>
        <div class="level-right">
            <form class="field has-addons" onsubmit="event.preventDefault(); filterUsers();">
                <p class="control has-icons-left">
                    <input class="input is-rounded" type="text" placeholder="Search users..." id="user-search" autocomplete="off">
                    <span class="icon is-left">
                        <i class="fas fa-search"></i>
                    </span>
                </p>
            </form>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth">
            <thead>
                <tr>
                    <th class="has-text-centered">ID</th>
                    <th class="has-text-centered">User</th>
                    <th class="has-text-centered">Admin</th>
                    <th class="has-text-centered">Beta Access</th>
                    <th class="has-text-centered">Premium Access</th>
                    <th class="has-text-centered">Signup Date</th>
                    <th class="has-text-centered">Last Login</th>
                    <th class="has-text-centered">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                function format_pretty_date($dateStr) {
                    if (!$dateStr) return '-';
                    $dt = new DateTime($dateStr);
                    $day = (int)$dt->format('j');
                    if ($day >= 11 && $day <= 13) {
                        $suffix = 'th';
                    } else {
                        switch ($day % 10) {
                            case 1: $suffix = 'st'; break;
                            case 2: $suffix = 'nd'; break;
                            case 3: $suffix = 'rd'; break;
                            default: $suffix = 'th';
                        }
                    }
                    return $day . $suffix . ' ' . $dt->format('M Y g:ia');
                }
                foreach ($users as $user):
                    $is_restricted =
                        (isset($user['username']) && isset($restricted_users[$user['username']]))
                        || (isset($user['twitch_user_id']) && isset($restricted_users[$user['twitch_user_id']]));
                ?>
                <tr<?php if ($is_restricted) echo ' style="text-decoration: line-through; opacity: 0.6;"'; ?>>
                    <td class="has-text-centered" style="vertical-align: middle;"><?php echo htmlspecialchars($user['id']); ?></td>
                    <td style="vertical-align: middle;">
                        <figure class="image is-32x32 is-inline-block mr-2" style="vertical-align:middle;">
                            <img class="is-rounded" src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" onerror="this.src='https://cdn.botofthespecter.com/logo.png';">
                        </figure>
                        <span style="vertical-align:middle;"><?php echo htmlspecialchars($user['username']); ?></span>
                    </td>
                    <td class="has-text-centered" style="vertical-align: middle;">
                        <?php if ($user['is_admin']): ?>
                            <span class="tag is-success">True</span>
                        <?php else: ?>
                            <span class="tag is-danger">False</span>
                        <?php endif; ?>
                    </td>
                    <td class="has-text-centered" style="vertical-align: middle;">
                        <?php if ($user['beta_access']): ?>
                            <span class="tag is-success">True</span>
                        <?php else: ?>
                            <span class="tag is-danger">False</span>
                        <?php endif; ?>
                    </td>
                    <td class="has-text-centered" style="vertical-align: middle;">
                        <?php
                        if (!empty($user['twitch_user_id'])) {
                            $tier = getTwitchSubTier($user['twitch_user_id']);
                            if ($tier === "1000") {
                                echo '<span class="tag is-warning">Tier 1</span>';
                            } elseif ($tier === "2000") {
                                echo '<span class="tag is-link">Tier 2</span>';
                            } elseif ($tier === "3000") {
                                echo '<span class="tag is-danger">Tier 3</span>';
                            } else {
                                echo '<span class="tag is-info">None</span>';
                            }
                        } else {
                            echo '<span class="tag is-info">None</span>';
                        }
                        ?>
                    </td>
                    <td class="has-text-centered" style="vertical-align: middle;"><?php echo format_pretty_date($user['signup_date']); ?></td>
                    <td class="has-text-centered" style="vertical-align: middle;"><?php echo format_pretty_date($user['last_login']); ?></td>
                    <td class="has-text-centered" style="vertical-align: middle;">
                        <button class="button is-small is-light" onclick="showSensitiveModal(<?php echo $user['id']; ?>)">
                            <span class="icon"><i class="fas fa-eye"></i></span>
                        </button>
                        <button class="button is-small is-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">
                            <span class="icon"><i class="fas fa-trash"></i></span>
                        </button>
                        <?php if ($is_restricted): ?>
                            <button class="button is-small is-warning" onclick="toggleRestrictUser('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', false)">
                                <span class="icon"><i class="fas fa-user-lock"></i></span>
                                <span>Unrestrict</span>
                            </button>
                        <?php else: ?>
                            <button class="button is-small is-dark" onclick="toggleRestrictUser('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', true)">
                                <span class="icon"><i class="fas fa-user-lock"></i></span>
                                <span>Restrict</span>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Sensitive Info Modal -->
<div class="modal" id="sensitive-modal">
    <div class="modal-background" onclick="closeSensitiveModal()"></div>
    <div class="modal-card" style="max-width: 800px;">
        <header class="modal-card-head">
            <p class="modal-card-title"><span class="icon"><i class="fas fa-user-secret"></i></span> User Details</p>
            <button class="delete" aria-label="close" onclick="closeSensitiveModal()"></button>
        </header>
        <section class="modal-card-body" id="sensitive-modal-content">
            <!-- Populated by JS -->
        </section>
        <footer class="modal-card-foot" style="justify-content: flex-end;">
            <button class="button" onclick="closeSensitiveModal()">Close</button>
        </footer>
    </div>
</div>

<script>
const usersData = <?php echo json_encode($users); ?>;

function maskEmail(email) {
    if (!email) return '';
    const atPos = email.indexOf('@');
    if (atPos === -1) return '•'.repeat(email.length);
    return '•'.repeat(atPos) + email.substring(atPos);
}
function maskApiKey(api) {
    if (!api) return '';
    return '•'.repeat(api.length);
}

function showSensitiveModal(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    function pretty(dateStr) {
        if (!dateStr) return '-';
        const dt = new Date(dateStr);
        if (isNaN(dt)) return '-';
        const day = dt.getDate();
        let suffix = 'th';
        if (day < 11 || day > 13) {
            switch (day % 10) {
                case 1: suffix = 'st'; break;
                case 2: suffix = 'nd'; break;
                case 3: suffix = 'rd'; break;
            }
        }
        return `${day}${suffix} ${dt.toLocaleString('en-US', { month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}`;
    }
    let html = `
    <div class="box">
        <div class="columns is-vcentered mb-2">
            <div class="column is-narrow">
                <figure class="image is-48x48">
                    <img class="is-rounded" src="${user.profile_image ? user.profile_image : 'https://cdn.botofthespecter.com/logo.png'}" alt="Profile" onerror="this.src='https://cdn.botofthespecter.com/logo.png';">
                </figure>
            </div>
            <div class="column">
                <p class="title is-5 mb-1">${user.twitch_display_name ? user.twitch_display_name : ''}</p>
                <p class="subtitle is-7 mb-0" style="vertical-align: middle;">Twitch ID: <span class="has-text-danger">${user.twitch_user_id}</span></p>
            </div>
        </div>
        <hr class="my-2">
        <div class="content">
            <table class="table is-fullwidth is-bordered is-narrow">
                <tbody>
                    <tr>
                        <th>Admin</th>
                        <td>${user.is_admin ? '<span class="tag is-success">True</span>' : '<span class="tag is-danger">False</span>'}</td>
                    </tr>
                    <tr>
                        <th>Beta Access</th>
                        <td>${user.beta_access ? '<span class="tag is-success">True</span>' : '<span class="tag is-danger">False</span>'}</td>
                    </tr>
                    <tr>
                        <th>Premium Access</th>
                        <td>
                            ${
                                user.twitch_user_id
                                ? (() => {
                                    let tier = '';
                                    if (user.twitch_sub_tier === "1000") return '<span class="tag is-warning">Tier 1</span>';
                                    if (user.twitch_sub_tier === "2000") return '<span class="tag is-link">Tier 2</span>';
                                    if (user.twitch_sub_tier === "3000") return '<span class="tag is-danger">Tier 3</span>';
                                    return '<span class="tag is-info">None</span>';
                                })()
                                : '<span class="tag is-info">None</span>'
                            }
                        </td>
                    </tr>
                    <tr>
                        <th>Signup Date</th>
                        <td>${pretty(user.signup_date)}</td>
                    </tr>
                    <tr>
                        <th>Last Login</th>
                        <td>${pretty(user.last_login)}</td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td>
                            ${user.email ? `
                                <span id="modal-email-masked">${maskEmail(user.email)}</span>
                                <span id="modal-email-unmasked" class="is-hidden">${user.email}</span>
                                <button class="button is-small is-light ml-2" id="modal-email-eye" onclick="toggleModalInfo('email', true)" style="vertical-align: middle;">
                                    <span class="icon"><i class="fas fa-eye"></i></span>
                                </button>
                                <button class="button is-small is-light ml-2 is-hidden" id="modal-email-eye-slash" onclick="toggleModalInfo('email', false)" style="vertical-align: middle;">
                                    <span class="icon"><i class="fas fa-eye-slash"></i></span>
                                </button>
                            ` : '<span class="has-text-grey-light">None</span>'}
                        </td>
                    </tr>
                    <tr>
                        <th>API Key</th>
                        <td>
                            <span id="modal-api-masked">${maskApiKey(user.api_key)}</span>
                            <span id="modal-api-unmasked" class="is-hidden">${user.api_key}</span>
                            <button class="button is-small is-light ml-2" id="modal-api-eye" onclick="toggleModalInfo('api', true)" style="vertical-align: middle;">
                                <span class="icon"><i class="fas fa-eye"></i></span>
                            </button>
                            <button class="button is-small is-light ml-2 is-hidden" id="modal-api-eye-slash" onclick="toggleModalInfo('api', false)" style="vertical-align: middle;">
                                <span class="icon"><i class="fas fa-eye-slash"></i></span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    `;

    document.getElementById('sensitive-modal-content').innerHTML = html;
    document.getElementById('sensitive-modal').classList.add('is-active');
}

function closeSensitiveModal() {
    document.getElementById('sensitive-modal').classList.remove('is-active');
}

function toggleModalInfo(type, reveal) {
    let label = type === 'email' ? 'Email' : 'API Key';
    if (reveal) {
        Swal.fire({
            title: `Reveal ${label}?`,
            text: `Are you sure you want to view this user's ${label.toLowerCase()}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Reveal',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                if (type === 'email') {
                    document.getElementById('modal-email-masked').classList.add('is-hidden');
                    document.getElementById('modal-email-unmasked').classList.remove('is-hidden');
                    document.getElementById('modal-email-eye').classList.add('is-hidden');
                    document.getElementById('modal-email-eye-slash').classList.remove('is-hidden');
                } else if (type === 'api') {
                    document.getElementById('modal-api-masked').classList.add('is-hidden');
                    document.getElementById('modal-api-unmasked').classList.remove('is-hidden');
                    document.getElementById('modal-api-eye').classList.add('is-hidden');
                    document.getElementById('modal-api-eye-slash').classList.remove('is-hidden');
                }
            }
        });
    } else {
        if (type === 'email') {
            document.getElementById('modal-email-masked').classList.remove('is-hidden');
            document.getElementById('modal-email-unmasked').classList.add('is-hidden');
            document.getElementById('modal-email-eye').classList.remove('is-hidden');
            document.getElementById('modal-email-eye-slash').classList.add('is-hidden');
        } else if (type === 'api') {
            document.getElementById('modal-api-masked').classList.remove('is-hidden');
            document.getElementById('modal-api-unmasked').classList.add('is-hidden');
            document.getElementById('modal-api-eye').classList.remove('is-hidden');
            document.getElementById('modal-api-eye-slash').classList.add('is-hidden');
        }
    }
}

function filterUsers() {
    const input = document.getElementById('user-search').value.toLowerCase();
    const table = document.querySelector('.table tbody');
    const rows = table.getElementsByTagName('tr');
    for (let row of rows) {
        const usernameCell = row.cells[1];
        // Extract username text (skip image)
        let username = '';
        if (usernameCell) {
            const spans = usernameCell.getElementsByTagName('span');
            if (spans.length > 0) {
                username = spans[0].textContent.toLowerCase();
            }
        }
        if (username.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}

function deleteUser(userId) {
    const user = usersData.find(u => u.id == userId);
    if (!user) return;
    Swal.fire({
        title: 'Delete User?',
        html: `Are you sure you want to delete <b>${user.username}</b> from the users table?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            // Ask about DB deletion BEFORE deleting user
            Swal.fire({
                title: 'Delete User Database?',
                html: `Do you also want to delete the database <b>${user.username}</b>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No'
            }).then((dbResult) => {
                const deleteDb = dbResult.isConfirmed;
                // Final confirmation
                Swal.fire({
                    title: 'Are you absolutely sure?',
                    text: deleteDb
                        ? `This will permanently delete the user and the database "${user.username}". This action cannot be undone.`
                        : `This will permanently delete the user "${user.username}". The database will NOT be deleted.`,
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete',
                    cancelButtonText: 'Cancel'
                }).then((finalResult) => {
                    if (finalResult.isConfirmed) {
                        // Always delete user first
                        $.post('', { delete_user_id: userId, username: user.username }, function(resp) {
                            let data = {};
                            try { data = JSON.parse(resp); } catch {}
                            if (data.success) {
                                if (deleteDb) {
                                    // Now delete DB
                                    $.post('', { delete_user_id: userId, delete_db: 1, username: user.username }, function(dbResp) {
                                        let dbData = {};
                                        try { dbData = JSON.parse(dbResp); } catch {}
                                        if (dbData.success) {
                                            Swal.fire('Deleted!', 'User and database deleted.', 'success').then(() => location.reload());
                                        } else {
                                            Swal.fire(
                                                'User deleted!',
                                                'User was deleted, but the database could not be deleted.<br>' +
                                                (dbData.msg ? `Reason: ${dbData.msg}` : 'Unknown error.'),
                                                'warning'
                                            ).then(() => location.reload());
                                        }
                                    });
                                } else {
                                    Swal.fire('Deleted!', 'User deleted. Database was not deleted.', 'success').then(() => location.reload());
                                }
                            } else {
                                Swal.fire('Error', data.msg || 'Could not delete user.', 'error');
                            }
                        });
                    }
                });
            });
        }
    });
}

function toggleRestrictUser(username, twitch_user_id, restrict) {
    const action = restrict ? 'restrict' : 'unrestrict';
    const actionText = restrict ? 'restrict' : 'remove restriction for';
    const confirmText = restrict ? 'Restrict' : 'Unrestrict';
    Swal.fire({
        title: restrict ? 'Restrict User?' : 'Remove Restriction?',
        html: `Are you sure you want to ${actionText} <b>${username}</b>?`,
        icon: restrict ? 'warning' : 'info',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('', {
                restrict_action: action,
                username: username,
                twitch_user_id: twitch_user_id
            }, function(resp) {
                let data = {};
                try { data = JSON.parse(resp); } catch {}
                if (data.success) {
                    Swal.fire('Success', restrict ? 'User restricted.' : 'Restriction removed.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.msg || 'Could not update restriction.', 'error');
                }
            });
        }
    });
}

document.getElementById('user-search').addEventListener('keyup', function(e) {
    filterUsers();
});
</script>
<?php
$content = ob_get_clean();
include "admin_layout.php";
?>
