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
    <h3 class="title is-5">View Existing Tokens</h3>
    <p class="mb-4">List of all stored Twitch App Access Tokens with their associated users.</p>
    <div class="field">
        <div class="control">
            <button class="button is-info" id="validate-all-btn">
                <span class="icon"><i class="fas fa-check-circle"></i></span>
                <span>Validate All Tokens</span>
            </button>
            <button class="button is-danger" id="renew-invalid-btn" style="display:none; margin-left: 10px;">
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
    let invalidTokens = [];
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
        renewInvalidBtn.style.display = 'none';
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
                renewInvalidBtn.style.display = 'inline-block';
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
        renewInvalidBtn.style.display = 'none';
    });
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