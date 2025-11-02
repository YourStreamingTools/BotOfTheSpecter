<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = 'Twitch App Access Tokens';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';

// Handle AJAX request for token generation BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_token'])) {
    header('Content-Type: application/json');
    $clientID = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
    $clientSecret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';
    // Fall back to config values if POST values are empty
    if (empty($clientID) && isset($GLOBALS['clientID']) && !empty($GLOBALS['clientID'])) {
        $clientID = $GLOBALS['clientID'];
    }
    if (empty($clientSecret) && isset($GLOBALS['clientSecret']) && !empty($GLOBALS['clientSecret'])) {
        $clientSecret = $GLOBALS['clientSecret'];
    }
    if (empty($clientID) || empty($clientSecret)) {
        echo json_encode(['success' => false, 'error' => 'Client ID and Client Secret are required. Please configure them in your config file or enter them manually.']);
        exit;
    }
    $url = 'https://id.twitch.tv/oauth2/token';
    $data = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['access_token'])) {
            echo json_encode([
                'success' => true,
                'access_token' => $result['access_token'],
                'expires_in' => $result['expires_in'] ?? 0,
                'token_type' => $result['token_type'] ?? 'bearer'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid response from Twitch API.']);
        }
    } else {
        $error = json_decode($response, true);
        $errorMsg = isset($error['message']) ? $error['message'] : 'Failed to generate token.';
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit;
}

// Handle AJAX request for token validation BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_token'])) {
    header('Content-Type: application/json');
    $token = isset($_POST['access_token']) ? trim($_POST['access_token']) : '';
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Access token is required.']);
        exit;
    }
    $url = 'https://id.twitch.tv/oauth2/validate';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: OAuth ' . $token
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        echo json_encode([
            'success' => true,
            'validation' => $result
        ]);
    } else {
        $error = json_decode($response, true);
        $errorMsg = isset($error['message']) ? $error['message'] : 'Failed to validate token.';
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit;
}

// Handle AJAX request for token renewal BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_token'])) {
    header('Content-Type: application/json');
    $userId = isset($_POST['twitch_user_id']) ? trim($_POST['twitch_user_id']) : '';
    if (empty($userId)) {
        echo json_encode(['success' => false, 'error' => 'User ID is required.']);
        exit;
    }
    $clientID = $GLOBALS['clientID'] ?? '';
    $clientSecret = $GLOBALS['clientSecret'] ?? '';
    if (empty($clientID) || empty($clientSecret)) {
        echo json_encode(['success' => false, 'error' => 'Client credentials not configured.']);
        exit;
    }
    $url = 'https://id.twitch.tv/oauth2/token';
    $data = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['access_token'])) {
            // Update database
            $stmt = $conn->prepare("UPDATE twitch_bot_access SET twitch_access_token = ? WHERE twitch_user_id = ?");
            $stmt->bind_param("ss", $result['access_token'], $userId);
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'new_token' => $result['access_token'],
                    'expires_in' => $result['expires_in'] ?? 0
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update database.']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid response from Twitch API.']);
        }
    } else {
        $error = json_decode($response, true);
        $errorMsg = isset($error['message']) ? $error['message'] : 'Failed to renew token.';
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit;
}

// Handle AJAX request to renew a custom bot's user token using its refresh_token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_custom'])) {
    header('Content-Type: application/json');
    $botChannelId = isset($_POST['bot_channel_id']) ? trim($_POST['bot_channel_id']) : '';
    if (empty($botChannelId)) {
        echo json_encode(['success' => false, 'error' => 'bot_channel_id is required']);
        exit;
    }
    // Load client credentials from config
    $clientID = $GLOBALS['clientID'] ?? '';
    $clientSecret = $GLOBALS['clientSecret'] ?? '';
    if (empty($clientID) || empty($clientSecret)) {
        echo json_encode(['success' => false, 'error' => 'Client credentials not configured.']);
        exit;
    }
    // Find the custom bot row
    $stmt = $conn->prepare("SELECT id, refresh_token FROM custom_bots WHERE bot_channel_id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('s', $botChannelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Custom bot not found']);
        exit;
    }
    $refreshToken = $row['refresh_token'] ?? '';
    if (empty($refreshToken)) {
        echo json_encode(['success' => false, 'error' => 'No refresh token available for this custom bot']);
        exit;
    }
    // Call Twitch token endpoint to refresh
    $url = 'https://id.twitch.tv/oauth2/token';
    $data = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $clientID,
        'client_secret' => $clientSecret
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 200) {
        $errMsg = $response ? (json_decode($response, true)['message'] ?? $response) : $err;
        echo json_encode(['success' => false, 'error' => 'Failed to refresh token: ' . $errMsg]);
        exit;
    }
    $result = json_decode($response, true);
    if (!isset($result['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid response from Twitch']);
        exit;
    }
    $newAccess = $result['access_token'];
    $newRefresh = $result['refresh_token'] ?? $refreshToken;
    $newExpiresIn = $result['expires_in'] ?? null;
    $newExpiresAt = $newExpiresIn ? date('Y-m-d H:i:s', time() + intval($newExpiresIn)) : null;
    // Persist into custom_bots
    $upd = $conn->prepare("UPDATE custom_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ? LIMIT 1");
    if (!$upd) {
        echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $conn->error]);
        exit;
    }
    $upd->bind_param('ssss', $newAccess, $newExpiresAt, $newRefresh, $botChannelId);
    if (!$upd->execute()) {
        $err = $upd->error;
        $upd->close();
        echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $err]);
        exit;
    }
    $upd->close();
    echo json_encode(['success' => true, 'new_token' => $newAccess, 'expires_at' => $newExpiresAt]);
    exit;
}

ob_start();
?>
<div class="box">
    <h1 class="title is-4"><span class="icon"><i class="fab fa-twitch"></i></span> Twitch App Access Tokens</h1>
    <p class="mb-4">Generate App Access Tokens for Twitch API usage, such as for chatbot badge display.</p>
    <div class="field">
        <div class="control">
            <button class="button is-info is-light" id="learn-more-btn">
                <span class="icon"><i class="fas fa-info-circle"></i></span>
                <span>What is an App Access Token?</span>
            </button>
        </div>
    </div>
    <div class="box">
        <h3 class="title is-5">Enter Twitch Application Credentials</h3>
        <p class="mb-4">Fields are pre-populated with your configured credentials. You can modify them if needed.</p>
        <div class="field">
            <label class="label">Client ID</label>
            <div class="control">
                <input class="input" type="text" id="client-id" placeholder="Enter your Twitch Client ID" value="<?php echo htmlspecialchars($clientID ?? ''); ?>" required>
            </div>
            <p class="help">Found in your Twitch Developer Console application settings</p>
        </div>
        <div class="field">
            <label class="label">Client Secret</label>
            <div class="control">
                <input class="input" type="password" id="client-secret" placeholder="Enter your Twitch Client Secret" value="<?php echo htmlspecialchars($clientSecret ?? ''); ?>" required>
            </div>
            <p class="help">Keep this secret! Found in your Twitch Developer Console application settings</p>
        </div>
        <div class="field">
            <div class="control">
                <button class="button is-primary" id="generate-token-btn">
                    <span class="icon"><i class="fas fa-key"></i></span>
                    <span>Generate App Access Token</span>
                </button>
            </div>
        </div>
        <div class="notification is-info is-light">
            <h4 class="title is-6">ℹ️ Redirect URI Setup</h4>
            <p><strong>Current Status:</strong> Your system has redirect URIs configured for user authentication flows.</p>
            <p><strong>For App Access Tokens:</strong> No additional redirect setup needed - you're good to go!</p>
            <p><strong>For User Authentication:</strong> Make sure these URIs are added to your Twitch Developer Console application settings.</p>
        </div>
    </div>
    <div id="token-result" class="notification is-hidden">
        <div id="token-content"></div>
    </div>
</div>
<!-- Modal for App Access Token Information -->
<div class="modal" id="info-modal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title">What is an App Access Token?</p>
            <button class="delete" aria-label="close" id="close-modal"></button>
        </header>
        <section class="modal-card-body">
            <div class="content">
                <h2>How to Get a Twitch App Access Token</h2>
                <p>To obtain an App Access Token, you need:</p>
                <ol>
                    <li>A registered Twitch application in the <a href="https://dev.twitch.tv/console/apps" target="_blank">Twitch Developer Console</a></li>
                    <li>Your application's Client ID and Client Secret</li>
                    <li>Use the OAuth endpoint to request the token</li>
                </ol>
                <p>The token is generated using the Client Credentials flow and is not tied to a specific user.</p>
                <h3>Redirect URI Configuration</h3>
                <p><strong>For App Access Tokens (Client Credentials):</strong> No redirect URI is required in the Twitch Developer Console.</p>
                <p><strong>For User Access Tokens (Authorization Code):</strong> You need to configure redirect URIs in your Twitch application settings.</p>
                <p><strong>Current Configuration:</strong></p>
                <ul>
                    <li>Production: <code><?php echo htmlspecialchars($redirectURI ?? 'Not configured'); ?></code></li>
                    <li>Beta: <code><?php echo htmlspecialchars($betaRedirectURI ?? 'Not configured'); ?></code></li>
                </ul>
                <h3>Requirements for Chat Bot Badge</h3>
                <p>For a chatbot to display the Chat Bot Badge:</p>
                <ul>
                    <li>Use the Send Chat Message API</li>
                    <li>Use an App Access Token</li>
                    <li>Have the <code>channel:bot</code> scope authorized by the broadcaster or moderator status</li>
                    <li>The chatbot's user account is not the channel's broadcaster</li>
                </ul>
            </div>
        </section>
        <footer class="modal-card-foot">
            <button class="button is-success" id="close-modal-footer">Got it!</button>
        </footer>
    </div>
</div>
<div class="box">
    <h3 class="title is-5">Validate Access Token</h3>
    <p class="mb-4">Enter an access token to validate its status and details.</p>
    <div class="field">
        <label class="label">Access Token</label>
        <div class="control">
            <input class="input" type="password" id="validate-token" placeholder="Enter access token to validate" required>
        </div>
        <p class="help">The token will be validated against Twitch's API</p>
    </div>
    <div class="field">
        <div class="control">
            <button class="button is-info" id="validate-token-btn">
                <span class="icon"><i class="fas fa-check"></i></span>
                <span>Validate Token</span>
            </button>
        </div>
    </div>
</div>
<div id="validation-result" class="notification is-hidden">
    <div id="validation-content"></div>
</div>
<div class="box">
    <h3 class="title is-5">Twitch Chat Token</h3>
    <p class="mb-4">Status of the configured Twitch Chat OAuth token.</p>
    <p><strong>Status:</strong> <span id="chat-status">Checking...</span></p>
    <p><strong>Expires In:</strong> <span id="chat-expiry">-</span></p>
    <div class="field">
        <div class="control">
            <button class="button is-info" id="validate-chat-btn">
                <span class="icon"><i class="fas fa-check"></i></span>
                <span>Validate Chat Token</span>
            </button>
            <button class="button is-warning" id="renew-chat-btn" style="margin-left:10px;">
                <span class="icon"><i class="fas fa-sync-alt"></i></span>
                <span>Renew Chat Token</span>
            </button>
        </div>
    </div>
</div>
<div id="chat-token-result" class="notification is-hidden" style="margin-top:10px;">
    <div id="chat-token-content"></div>
</div>
<div class="box">
    <h3 class="title is-5">View Existing Tokens</h3>
    <p class="mb-4">List of all stored Twitch App Access Tokens with their associated users.</p>
    <div class="field">
        <div class="control">
            <button class="button is-info" id="validate-all-btn">
                <span class="icon"><i class="fas fa-check-circle"></i></span>
                <span>Validate All Tokens</span>
            </button>
            <button class="button is-danger is-disabled" id="renew-invalid-btn" disabled style="margin-left: 10px;">
                <span class="icon"><i class="fas fa-refresh"></i></span>
                <span>Renew Invalid Tokens</span>
            </button>
        </div>
    </div>
    <table class="table is-fullwidth">
        <thead>
            <tr>
                <th>Username</th>
                <th>Status</th>
                <th>Expires In</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="tokens-table-body">
            <?php
            $sql = "SELECT tba.twitch_user_id, tba.twitch_access_token, u.username FROM twitch_bot_access tba JOIN users u ON tba.twitch_user_id = u.twitch_user_id";
            $result = $conn->query($sql);
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $userId = $row['twitch_user_id'];
                    $token = $row['twitch_access_token'];
                    $username = htmlspecialchars($row['username']);
                    $tokenId = md5($token); // Use hash for unique ID
                    echo "<tr id='row-$tokenId' data-token='$token' data-user-id='$userId'>";
                    echo "<td>$username</td>";
                    echo "<td id='status-$tokenId'>Not Validated</td>";
                    echo "<td id='expiry-$tokenId'>-</td>";
                    echo "<td><button class='button is-small is-info' onclick='validateToken(\"$token\", \"$tokenId\")'>Validate</button> <button class='button is-small is-warning' onclick='renewToken(\"$userId\", \"$tokenId\")'>Renew</button></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4'>No tokens found.</td></tr>";
            }
            ?>
        </tbody>
    </table>
</div>
<div class="box">
    <h3 class="title is-5">Custom Bot Tokens</h3>
    <p class="mb-4">List of stored custom bot tokens with their associated bot accounts.</p>
    <table class="table is-fullwidth">
        <thead>
            <tr>
                <th>Bot Username</th>
                <th>Bot Channel ID</th>
                <th>Status</th>
                <th>Expires At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="custom-tokens-table-body">
            <?php
            $sqlc = "SELECT channel_id, bot_username, bot_channel_id, access_token, token_expires FROM custom_bots";
            $resc = $conn->query($sqlc);
            if ($resc && $resc->num_rows > 0) {
                while ($crow = $resc->fetch_assoc()) {
                    $botUsername = htmlspecialchars($crow['bot_username'] ?? '');
                    $botChannelId = htmlspecialchars($crow['bot_channel_id'] ?? '');
                    $accessToken = $crow['access_token'] ?? '';
                    $expiresAt = $crow['token_expires'] ?? '-';
                    $tokenId = md5(($botChannelId ?: $botUsername) . ($accessToken ?? ''));
                    echo "<tr id='custom-row-$tokenId' data-token='" . htmlspecialchars($accessToken) . "' data-bot-channel-id='" . htmlspecialchars($botChannelId) . "'>";
                    echo "<td>$botUsername</td>";
                    echo "<td>$botChannelId</td>";
                    echo "<td id='status-custom-$tokenId'>Not Validated</td>";
                    echo "<td id='expiry-custom-$tokenId'>" . htmlspecialchars($expiresAt) . "</td>";
                    echo "<td><button class='button is-small is-info' onclick='validateCustomToken(" . json_encode($accessToken) . ", \"$tokenId\")'>Validate</button> <button class='button is-small is-warning' onclick='renewCustomToken(\"$botChannelId\", \"$tokenId\")'>Renew</button></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='5'>No custom bots found.</td></tr>";
            }
            ?>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const generateBtn = document.getElementById('generate-token-btn');
    const tokenResult = document.getElementById('token-result');
    const tokenContent = document.getElementById('token-content');
    const validateBtn = document.getElementById('validate-token-btn');
    const validationResult = document.getElementById('validation-result');
    const validationContent = document.getElementById('validation-content');
    const learnMoreBtn = document.getElementById('learn-more-btn');
    const infoModal = document.getElementById('info-modal');
    const closeModal = document.getElementById('close-modal');
    const closeModalFooter = document.getElementById('close-modal-footer');
    const validateAllBtn = document.getElementById('validate-all-btn');
    const renewInvalidBtn = document.getElementById('renew-invalid-btn');
    const validateChatBtn = document.getElementById('validate-chat-btn');
    const renewChatBtn = document.getElementById('renew-chat-btn');
    let invalidTokens = [];
    let chatToken = "<?php echo addslashes($oauth); ?>";
    // Modal functionality
    learnMoreBtn.addEventListener('click', function() {
        infoModal.classList.add('is-active');
    });
    closeModal.addEventListener('click', function() {
        infoModal.classList.remove('is-active');
    });
    closeModalFooter.addEventListener('click', function() {
        infoModal.classList.remove('is-active');
    });
    // Close modal when clicking background
    infoModal.addEventListener('click', function(event) {
        if (event.target === infoModal) {
            infoModal.classList.remove('is-active');
        }
    });
    // Validate all tokens
    validateAllBtn.addEventListener('click', function() {
        const rows = document.querySelectorAll('#tokens-table-body tr[data-token]');
        invalidTokens = [];
        renewInvalidBtn.disabled = true;
        renewInvalidBtn.classList.add('is-disabled');
        const promises = Array.from(rows).map(row => {
            const token = row.getAttribute('data-token');
            const tokenId = row.id.replace('row-', '');
            return validateToken(token, tokenId);
        });
        Promise.all(promises).then(() => {
            rows.forEach(row => {
                const tokenId = row.id.replace('row-', '');
                const status = document.getElementById(`status-${tokenId}`).textContent;
                if (status === 'Invalid') {
                    invalidTokens.push(row.getAttribute('data-user-id'));
                }
            });
            if (invalidTokens.length > 0) {
                renewInvalidBtn.disabled = false;
                renewInvalidBtn.classList.remove('is-disabled');
            }
        });
    });
    // Renew invalid tokens
    renewInvalidBtn.addEventListener('click', function() {
        invalidTokens.forEach(userId => {
            const row = document.querySelector(`tr[data-user-id="${userId}"]`);
            const tokenId = row.id.replace('row-', '');
            renewToken(userId, tokenId);
        });
        invalidTokens = [];
        renewInvalidBtn.disabled = true;
        renewInvalidBtn.classList.add('is-disabled');
    });
    // Validate chat token
    validateChatBtn.addEventListener('click', function() {
        validateChatToken(chatToken);
    });
    // Auto-validate chat token on page load
    if (chatToken) {
        validateChatToken(chatToken);
    }
    // Renew chat token
    if (renewChatBtn) {
        renewChatBtn.addEventListener('click', function() {
            // Prefill with configured chat user id if available
            const prefillUser = "<?php echo addslashes($twitch_chat_user_id ?? $twitch_user_id ?? ''); ?>";
            const userId = window.prompt('Enter the Twitch user id to renew the chat token for:', prefillUser);
            if (!userId) return;
            renewChatToken(userId);
        });
    }
    generateBtn.addEventListener('click', async function() {
        const clientId = document.getElementById('client-id').value.trim();
        const clientSecret = document.getElementById('client-secret').value.trim();
        if (!clientId || !clientSecret) {
            Swal.fire({
                title: 'Missing Credentials',
                text: 'Please enter both Client ID and Client Secret, or ensure they are configured in your config file.',
                icon: 'warning'
            });
            return;
        }
        generateBtn.classList.add('is-loading');
        generateBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('generate_token', '1');
            formData.append('client_id', clientId);
            formData.append('client_secret', clientSecret);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                tokenContent.innerHTML = `
                    <h4 class="title is-5">Token Generated Successfully</h4>
                    <div class="field">
                        <label class="label">Access Token</label>
                        <div class="control">
                            <input class="input" type="text" value="${data.access_token}" readonly id="token-input">
                        </div>
                        <p class="help">Expires in: ${data.expires_in} seconds (${Math.floor(data.expires_in / 3600)} hours)</p>
                    </div>
                    <div class="field">
                        <div class="control">
                            <button class="button is-small" onclick="copyToken()">
                                <span class="icon"><i class="fas fa-copy"></i></span>
                                <span>Copy Token</span>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <button class="button is-small is-info" onclick="generateAuthLink()">
                                <span class="icon"><i class="fas fa-link"></i></span>
                                <span>Generate Auth Link</span>
                            </button>
                        </div>
                    </div>
                `;
                tokenResult.classList.remove('is-hidden');
                tokenResult.classList.add('is-success');
                tokenResult.classList.remove('is-danger');
            } else {
                tokenContent.innerHTML = `<p class="has-text-danger">${data.error}</p>`;
                tokenResult.classList.remove('is-hidden');
                tokenResult.classList.remove('is-success');
                tokenResult.classList.add('is-danger');
            }
        } catch (error) {
            tokenContent.innerHTML = '<p class="has-text-danger">An error occurred while generating the token.</p>';
            tokenResult.classList.remove('is-hidden');
            tokenResult.classList.remove('is-success');
            tokenResult.classList.add('is-danger');
        }
        generateBtn.classList.remove('is-loading');
        generateBtn.disabled = false;
    });
    validateBtn.addEventListener('click', async function() {
        const token = document.getElementById('validate-token').value.trim();
        if (!token) {
            Swal.fire({
                title: 'Missing Token',
                text: 'Please enter an access token to validate.',
                icon: 'warning'
            });
            return;
        }
        validateBtn.classList.add('is-loading');
        validateBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('validate_token', '1');
            formData.append('access_token', token);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                const val = data.validation;
                const expiresIn = val.expires_in || 0;
                const now = new Date();
                const expiryDate = new Date(now.getTime() + expiresIn * 1000);
                // Calculate time components
                let remaining = expiresIn;
                const months = Math.floor(remaining / (30 * 24 * 3600));
                remaining %= (30 * 24 * 3600);
                const days = Math.floor(remaining / (24 * 3600));
                remaining %= (24 * 3600);
                const hours = Math.floor(remaining / 3600);
                remaining %= 3600;
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                // Build time string, only including non-zero units
                let timeParts = [];
                if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
                if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
                if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
                if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
                if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
                const timeString = timeParts.join(', ') || '0 seconds';
                const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
                validationContent.innerHTML = `
                    <h4 class="title is-5">Token Validated Successfully</h4>
                    <p><strong>Expires In:</strong> ${timeString}</p>
                    <p><strong>Expiration Date:</strong> ${expiryDate.toLocaleString('en-AU', dateOptions)}</p>
                `;
                validationResult.classList.remove('is-hidden');
                validationResult.classList.add('is-success');
                validationResult.classList.remove('is-danger');
            } else {
                validationContent.innerHTML = `<p class="has-text-danger">${data.error}</p>`;
                validationResult.classList.remove('is-hidden');
                validationResult.classList.remove('is-success');
                validationResult.classList.add('is-danger');
            }
        } catch (error) {
            validationContent.innerHTML = '<p class="has-text-danger">An error occurred while validating the token.</p>';
            validationResult.classList.remove('is-hidden');
            validationResult.classList.remove('is-success');
            validationResult.classList.add('is-danger');
        }
        validateBtn.classList.remove('is-loading');
        validateBtn.disabled = false;
    });
});

function validateChatToken(token) {
    const statusCell = document.getElementById('chat-status');
    const expiryCell = document.getElementById('chat-expiry');
    const button = document.getElementById('validate-chat-btn');
    statusCell.textContent = 'Validating...';
    button.disabled = true;
    button.classList.add('is-loading');
    const formData = new FormData();
    formData.append('validate_token', '1');
    formData.append('access_token', token);
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const val = data.validation;
            const expiresIn = val.expires_in || 0;
            const now = new Date();
            const expiryDate = new Date(now.getTime() + expiresIn * 1000);
            // Calculate time components
            let remaining = expiresIn;
            const months = Math.floor(remaining / (30 * 24 * 3600));
            remaining %= (30 * 24 * 3600);
            const days = Math.floor(remaining / (24 * 3600));
            remaining %= (24 * 3600);
            const hours = Math.floor(remaining / 3600);
            remaining %= 3600;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            // Build time string
            let timeParts = [];
            if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
            if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
            if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
            if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
            if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
            const timeString = timeParts.join(', ') || '0 seconds';
            statusCell.textContent = 'Valid';
            statusCell.className = 'has-text-success';
            expiryCell.textContent = timeString;
        } else {
            statusCell.textContent = 'Invalid';
            statusCell.className = 'has-text-danger';
            expiryCell.textContent = '-';
        }
    })
    .catch(error => {
        statusCell.textContent = 'Error';
        statusCell.className = 'has-text-danger';
        expiryCell.textContent = '-';
    })
    .finally(() => {
        button.disabled = false;
        button.classList.remove('is-loading');
    });
}

function validateToken(token, tokenId) {
    const statusCell = document.getElementById(`status-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-${tokenId}`);
    const button = document.querySelector(`#row-${tokenId} button:first-child`);
    statusCell.textContent = 'Validating...';
    button.disabled = true;
    button.classList.add('is-loading');
    const formData = new FormData();
    formData.append('validate_token', '1');
    formData.append('access_token', token);
    return fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const val = data.validation;
            const expiresIn = val.expires_in || 0;
            const now = new Date();
            const expiryDate = new Date(now.getTime() + expiresIn * 1000);
            // Calculate time components
            let remaining = expiresIn;
            const months = Math.floor(remaining / (30 * 24 * 3600));
            remaining %= (30 * 24 * 3600);
            const days = Math.floor(remaining / (24 * 3600));
            remaining %= (24 * 3600);
            const hours = Math.floor(remaining / 3600);
            remaining %= 3600;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            // Build time string
            let timeParts = [];
            if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
            if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
            if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
            if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
            if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
            const timeString = timeParts.join(', ') || '0 seconds';
            statusCell.textContent = 'Valid';
            statusCell.className = 'has-text-success';
            expiryCell.textContent = timeString;
        } else {
            statusCell.textContent = 'Invalid';
            statusCell.className = 'has-text-danger';
            expiryCell.textContent = '-';
        }
        return data;
    })
    .catch(error => {
        statusCell.textContent = 'Error';
        statusCell.className = 'has-text-danger';
        expiryCell.textContent = '-';
        return { success: false };
    })
    .finally(() => {
        button.disabled = false;
        button.classList.remove('is-loading');
    });
}

function renewToken(userId, tokenId) {
    const statusCell = document.getElementById(`status-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-${tokenId}`);
    const row = document.getElementById(`row-${tokenId}`);
    const buttons = row.querySelectorAll('button');
    buttons.forEach(btn => {
        btn.disabled = true;
        btn.classList.add('is-loading');
    });
    statusCell.textContent = 'Renewing...';
    const formData = new FormData();
    formData.append('renew_token', '1');
    formData.append('twitch_user_id', userId);
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the row's data-token
            row.setAttribute('data-token', data.new_token);
            statusCell.textContent = 'Renewed';
            statusCell.className = 'has-text-warning';
            expiryCell.textContent = '-';
            // Optionally, auto-validate
            setTimeout(() => validateToken(data.new_token, tokenId), 500);
        } else {
            statusCell.textContent = 'Renew Failed';
            statusCell.className = 'has-text-danger';
        }
    })
    .catch(error => {
        statusCell.textContent = 'Error';
        statusCell.className = 'has-text-danger';
    })
    .finally(() => {
        buttons.forEach(btn => {
            btn.disabled = false;
            btn.classList.remove('is-loading');
        });
    });
}

function renewChatToken(userId) {
    const resultBox = document.getElementById('chat-token-result');
    const resultContent = document.getElementById('chat-token-content');
    const validateBtn = document.getElementById('validate-chat-btn');
    const renewBtn = document.getElementById('renew-chat-btn');
    if (renewBtn) { renewBtn.disabled = true; renewBtn.classList.add('is-loading'); }
    if (validateBtn) { validateBtn.disabled = true; }
    resultContent.innerHTML = '<p>Renewing token...</p>';
    resultBox.classList.remove('is-hidden');
    const formData = new FormData();
    formData.append('renew_token', '1');
    formData.append('twitch_user_id', userId);
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.new_token) {
                const newToken = data.new_token;
                // If token unchanged, show brief message and hide token field
                if (newToken === chatToken) {
                    resultContent.innerHTML = '<p class="has-text-info">Token renewed but is unchanged from the currently configured chat token.</p>';
                } else {
                    chatToken = newToken; // update for future validation
                    // Show masked input with eye toggle and copy
                    resultContent.innerHTML = `
                        <h4 class="title is-6">Chat Token Renewed</h4>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input" type="password" id="chat-token-input" value="${newToken}" readonly>
                            </div>
                            <div class="control">
                                <button class="button" id="toggle-chat-eye" title="Show/Hide Token"><span class="icon"><i class="fas fa-eye"></i></span></button>
                            </div>
                            <div class="control">
                                <button class="button is-small" id="copy-chat-token"><span class="icon"><i class="fas fa-copy"></i></span></button>
                            </div>
                        </div>
                        <p class="help">The token is masked by default. Click the eye to reveal.</p>
                    `;
                    // attach handlers
                    document.getElementById('toggle-chat-eye').addEventListener('click', function() {
                        toggleChatTokenVisibility('chat-token-input', this.querySelector('i'));
                    });
                    document.getElementById('copy-chat-token').addEventListener('click', function() {
                        copyChatToken('chat-token-input');
                    });
                }
            } else {
                const err = data.error || 'Failed to renew chat token.';
                resultContent.innerHTML = `<p class="has-text-danger">${err}</p>`;
            }
        })
        .catch(() => {
            resultContent.innerHTML = '<p class="has-text-danger">An error occurred while renewing the chat token.</p>';
        })
        .finally(() => {
            if (renewBtn) { renewBtn.disabled = false; renewBtn.classList.remove('is-loading'); }
            if (validateBtn) { validateBtn.disabled = false; }
        });
}

function validateCustomToken(token, tokenId) {
    const statusCell = document.getElementById(`status-custom-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-custom-${tokenId}`);
    const row = document.getElementById(`custom-row-${tokenId}`);
    statusCell.textContent = 'Validating...';
    // Disable the validate button in this row
    const btn = row.querySelector('button');
    if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
    const formData = new FormData();
    formData.append('validate_token', '1');
    formData.append('access_token', token);
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const val = data.validation;
                const expiresIn = val.expires_in || 0;
                let remaining = expiresIn;
                const months = Math.floor(remaining / (30 * 24 * 3600));
                remaining %= (30 * 24 * 3600);
                const days = Math.floor(remaining / (24 * 3600));
                remaining %= (24 * 3600);
                const hours = Math.floor(remaining / 3600);
                remaining %= 3600;
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                let timeParts = [];
                if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
                if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
                if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
                if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
                if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
                const timeString = timeParts.join(', ') || '0 seconds';
                statusCell.textContent = 'Valid';
                statusCell.className = 'has-text-success';
                expiryCell.textContent = timeString;
            } else {
                statusCell.textContent = 'Invalid';
                statusCell.className = 'has-text-danger';
                expiryCell.textContent = '-';
            }
        })
        .catch(() => {
            statusCell.textContent = 'Error';
            statusCell.className = 'has-text-danger';
            expiryCell.textContent = '-';
        })
        .finally(() => {
            if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
        });
}

function renewCustomToken(botChannelId, tokenId) {
    const row = document.getElementById(`custom-row-${tokenId}`);
    const buttons = row.querySelectorAll('button');
    buttons.forEach(btn => { btn.disabled = true; btn.classList.add('is-loading'); });
    const statusCell = document.getElementById(`status-custom-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-custom-${tokenId}`);
    statusCell.textContent = 'Renewing...';
    const formData = new FormData();
    formData.append('renew_custom', '1');
    formData.append('bot_channel_id', botChannelId);
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const newToken = data.new_token || data.new_token || '';
                const expiresAt = data.expires_at || data.expires_at || '';
                // Update row data-token and expiry display
                row.setAttribute('data-token', newToken);
                expiryCell.textContent = expiresAt || '-';
                statusCell.textContent = 'Renewed';
                statusCell.className = 'has-text-warning';
                // Optionally auto-validate
                setTimeout(() => validateCustomToken(newToken, tokenId), 500);
            } else {
                statusCell.textContent = 'Renew Failed';
                statusCell.className = 'has-text-danger';
            }
        })
        .catch(() => {
            statusCell.textContent = 'Error';
            statusCell.className = 'has-text-danger';
        })
        .finally(() => {
            buttons.forEach(btn => { btn.disabled = false; btn.classList.remove('is-loading'); });
        });
}

function toggleChatTokenVisibility(inputId, iconEl) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (iconEl) { iconEl.classList.remove('fa-eye'); iconEl.classList.add('fa-eye-slash'); }
    } else {
        input.type = 'password';
        if (iconEl) { iconEl.classList.remove('fa-eye-slash'); iconEl.classList.add('fa-eye'); }
    }
}

function copyChatToken(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    try {
        // Use clipboard api when available
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(() => {
                Swal.fire({ title: 'Copied!', text: 'Chat token copied to clipboard', icon: 'success', timer: 1500, showConfirmButton: false });
            });
        } else {
            input.select();
            document.execCommand('copy');
            Swal.fire({ title: 'Copied!', text: 'Chat token copied to clipboard', icon: 'success', timer: 1500, showConfirmButton: false });
        }
    } catch (e) {
        Swal.fire({ title: 'Copy failed', text: 'Unable to copy token', icon: 'error' });
    }
}

function copyToken() {
    const tokenInput = document.getElementById('token-input');
    tokenInput.select();
    document.execCommand('copy');
    // Optional: Show a brief success message
    Swal.fire({
        title: 'Copied!',
        text: 'Token copied to clipboard',
        icon: 'success',
        timer: 1500,
        showConfirmButton: false
    });
}

function generateAuthLink() {
    const clientId = document.getElementById('client-id').value.trim();
    const clientSecret = document.getElementById('client-secret').value.trim();
    if (!clientId || !clientSecret) {
        Swal.fire({
            title: 'Missing Credentials',
            text: 'Please enter both Client ID and Client Secret.',
            icon: 'warning'
        });
        return;
    }
    const authUrl = `https://id.twitch.tv/oauth2/token?client_id=${encodeURIComponent(clientId)}&client_secret=${encodeURIComponent(clientSecret)}&grant_type=client_credentials`;
    // Copy to clipboard
    navigator.clipboard.writeText(authUrl).then(() => {
        Swal.fire({
            title: 'Auth Link Generated!',
            html: `The authorization URL has been copied to your clipboard:<br><br><code>${authUrl}</code>`,
            icon: 'success'
        });
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = authUrl;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        Swal.fire({
            title: 'Auth Link Generated!',
            html: `The authorization URL has been copied to your clipboard:<br><br><code>${authUrl}</code>`,
            icon: 'success'
        });
    });
}
</script>
<?php
$scripts = ob_get_clean();
include "admin_layout.php";
?>