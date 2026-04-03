<?php
// Start the session
session_start();

// Database connection
require '/var/www/specterbotapp/database.php';
$redirectURI = 'https://specterbot.app/index.php';

$userDatabaseExists = "";
function userDatabaseExists($username)
{
    global $conn;
    $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    if (!$stmt) {
        die('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $authData = null;
    $cfg = require_once "/var/www/config/main.php";
    $apiKey = isset($cfg['streamersconnect_api_key']) ? $cfg['streamersconnect_api_key'] : '';
    // Try signed payload verification via StreamersConnect verify endpoint
    if (isset($_GET['auth_data_sig']) && $apiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) {
                $authData = $res['payload'];
            }
        }
    }
    // Next, try exchanging server_token
    if (!$authData && isset($_GET['server_token']) && $apiKey) {
        $token = $_GET['server_token'];
        $ch = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $token]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) {
                $authData = $res['payload'];
            }
        }
    }
    // Fallback to legacy base64 auth_data
    if (!$authData && isset($_GET['auth_data'])) {
        $authDataEncoded = $_GET['auth_data'];
        $authDataJson = base64_decode($authDataEncoded);
        $authData = json_decode($authDataJson, true);
    }
    if ($authData && isset($authData['success']) && $authData['success'] === true) {
        $accessToken = $authData['access_token'];
        $refreshToken = $authData['refresh_token'];
        $twitchUsername = $authData['user']['login'] ?? 'guest_user';
        $displayName = $authData['user']['display_name'] ?? null;
        $email = $authData['user']['email'] ?? null;
        $_SESSION['access_token'] = $accessToken;
        $_SESSION['refresh_token'] = $refreshToken;
        $_SESSION['twitch_username'] = $twitchUsername;
        $_SESSION['display_name'] = $displayName;
        $_SESSION['email'] = $email;
        $userFolder = '/var/www/specterbotapp/' . $twitchUsername;
        if (!userDatabaseExists($twitchUsername)) {
            $userDatabaseExists = "User database does not exist. Please use the bot to create your database first.";
        }
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }
        // Regenerate session id to prevent fixation
        session_regenerate_id(true);
        header('Location: ' . strtok($redirectURI, '?'));
        exit;
    } else {
        $twitchUsername = 'guest_user';
        $userDatabaseExists = "Authentication failed. Please try again.";
    }
} else {
    $twitchUsername = isset($_SESSION['twitch_username']) ? $_SESSION['twitch_username'] : 'guest_user';
    $userDatabaseExists = userDatabaseExists($twitchUsername) ? "User database exists" : "User database does not exist";
}

$loginURL = 'https://streamersconnect.com/?service=twitch&login=specterbot.app&scopes=user:read:email&return_url=' . urlencode($redirectURI);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to SpecterBot Custom API</title>
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/themes/prism-tomorrow.min.css">
    <link rel="icon" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description"
        content="BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics." />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <link rel="stylesheet" href="css/custom.css?v=<?php echo filemtime(__DIR__ . '/css/custom.css'); ?>">
</head>

<body class="dark-mode">
    <header>
        <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
            <div class="navbar-brand">
                <a class="navbar-item" href="../"><img src="logo.png" alt="BotOfTheSpecter Logo"></a>
                <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false"
                    data-target="navbarBasicExample">
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </a>
            </div>
            <div id="navbarBasicExample" class="navbar-menu">
                <div class="navbar-start">
                    <div class="navbar-item">
                        <h1 class="title">BotOfTheSpecter</h1>
                    </div>
                    <div class="navbar-item">
                        <h2 class="subtitle">Your gateway to building custom integrations with SpecterBot.</h2>
                    </div>
                </div>
                <div class="navbar-end">
                    <div class="navbar-item">
                        <div class="buttons">
                            <?php if (!isset($_SESSION['access_token'])): ?>
                                <a href="<?php echo filter_var($loginURL, FILTER_SANITIZE_URL); ?>"
                                    class="button is-primary">Login with Twitch</a>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['access_token'])): ?>
                                <a href="logout.php" class="button is-danger">Logout</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    </header>
    <section class="section">
        <div class="container">
            <h2 class="title">Getting Started</h2>
            <p class="subtitle">
                Welcome to the SpecterBot Custom API!<br>
                This platform allows developers to integrate seamlessly with our service.<br>
                Use your personalized subdomain at <code><?php echo $twitchUsername; ?>.specterbot.app</code> to
                interact with your custom endpoints.<br>
                <?php if ($twitchUsername === 'guest_user'): ?>Please note that you need to sign in to verify your
                    database connection with SpecterBot.<?php endif; ?>
            </p>
            <div class="box" id="features">
                <h3 class="title is-4">Key Features</h3>
                <ul>
                    <li>Custom subdomains for users</li>
                    <li>Direct Access to your own database that Specter uses.</li>
                    <li>You can use <code>database.php</code> in your PHP files to auto-connect to your database.
                        <br>Example:
                        <pre><code class="language-php">&lt;?php
require '/var/www/specterbotapp/database.php';</code></pre>
                    </li>
                </ul>
            </div>
            <div class="box" id="features-websocket">
                <h3 class="title is-4">WebSocket Connection</h3>
                <p>Connect to the BotOfTheSpecter WebSocket server to receive real-time events such as channel point redemptions, subscriptions, and more.</p>
                <button class="button is-info" id="open-websocket-docs">View WebSocket Documentation</button>
            </div>
            <!-- WebSocket Docs Modal -->
            <div class="modal" id="websocket-docs-modal">
                <div class="modal-background" id="websocket-modal-bg"></div>
                <div class="modal-card" style="width: 90%; max-width: 860px;">
                    <header class="modal-card-head">
                        <p class="modal-card-title">WebSocket Connection</p>
                        <button class="delete" aria-label="close" id="close-websocket-docs"></button>
                    </header>
                    <section class="modal-card-body">
                        <p>Your API code can be found in your <strong>BotOfTheSpecter Dashboard</strong>. Never paste it directly into your overlay file &mdash; store it securely in a separate config file instead.</p>
                        <br>
                        <p><strong>1. Create a secure config file for your API code:</strong></p>
                        <p>Use a random filename so it cannot be easily guessed (e.g. <code>x9k2m7p_config.php</code>). This file should <em>never</em> be your main overlay file.</p>
                        <p>Also choose a unique random string for the guard constant &mdash; something hard to guess. Generate one at <a href="https://www.uuidgenerator.net/" target="_blank">uuidgenerator.net</a> or just mash your keyboard. Both your config file and overlay file must use the <strong>same</strong> constant name.</p>
                        <pre><code class="language-php">&lt;?php
if (!defined('j4iDSiaiuF3V')) {  // replace j4iDSiaiuF3V with your own unique string
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

define('BOTOFTHE_SPECTER_CODE', 'YOUR_ACTUAL_API_CODE_HERE');
?&gt;</code></pre>
                        <p>The guard at the top ensures this file cannot be accessed directly in a browser &mdash; it can only be loaded by your own overlay file.</p>
                        <br>
                        <p><strong>2. Load your config at the top of your overlay file:</strong></p>
                        <pre><code class="language-php">&lt;?php
define('j4iDSiaiuF3V', true);            // must match the constant name in your config file
require_once __DIR__ . '/x9k2m7p_config.php';  // use your actual random filename here

$apiCode = BOTOFTHE_SPECTER_CODE;
?&gt;</code></pre>
                        <br>
                        <p><strong>3. Include the Socket.IO client library and pass your code into JavaScript:</strong></p>
                        <pre><code class="language-php">&lt;script src="https://cdn.socket.io/4.8.3/socket.io.min.js"&gt;&lt;/script&gt;
&lt;script&gt;
    const code = '&lt;?php echo htmlspecialchars($apiCode, ENT_QUOTES); ?&gt;';
&lt;/script&gt;</code></pre>
                        <br>
                        <p><strong>4. Connect and register your session:</strong></p>
                        <p>Once connected, emit a <code>REGISTER</code> event with three required fields:</p>
                        <ul>
                            <li><code>code</code> &mdash; Your BotOfTheSpecter API code, securely loaded from your config file as shown above.</li>
                            <li><code>channel</code> &mdash; The channel type. Use <code>'Custom Overlay'</code> for custom integrations built here.</li>
                            <li><code>name</code> &mdash; A unique, descriptive name for this specific overlay or integration (e.g. <code>'Loyalty Card'</code>, <code>'My Alert Box'</code>). Each integration you build should use a different name.</li>
                        </ul>
                        <pre><code class="language-javascript">const socket = io('wss://websocket.botofthespecter.com', {
    reconnection: false
});

socket.on('connect', () => {
    socket.emit('REGISTER', {
        code: code,
        channel: 'Custom Overlay',
        name: 'My Custom Integration'
    });
});</code></pre>
                        <br>
                        <p><strong>5. Listen for server confirmation and events:</strong></p>
                        <pre><code class="language-javascript">socket.on('WELCOME', (data) => {
    console.log('Server welcome:', data);
});

socket.on('SUCCESS', (data) => {
    console.log('Registration successful:', data);
});

socket.on('disconnect', () => {
    // Implement your own reconnect logic here
    setTimeout(() => connectWebSocket(), 5000);
});</code></pre>
                    </section>
                    <footer class="modal-card-foot">
                        <button class="button" id="close-websocket-docs-footer">Close</button>
                    </footer>
                </div>
            </div>
            <?php if (isset($_SESSION['access_token'])): ?>
                <div class="box columns is-desktop is-multiline" id="file-uploads">
                    <div class="column is-3">
                        <div id="specterbot-upload" style="position: relative;">
                            <h1 class="title is-4">Upload Your Files:</h1>
                            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                                <label for="filesToUpload" class="drag-area" id="drag-area">
                                    <span>Drag & Drop files here</span>
                                    <input class="is-hidden" type="file" name="filesToUpload[]" id="filesToUpload" multiple
                                        accept=".php">
                                </label>
                                <br>
                                <div id="file-list"></div>
                                <input type="submit" value="Upload Files" name="submit" class="button is-primary">
                            </form>
                            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                        </div>
                        <?php
                        $userFolder = '/var/www/specterbotapp/' . $twitchUsername;
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['filesToUpload'])) {
                            $uploadedFiles = $_FILES['filesToUpload'];
                            foreach ($uploadedFiles['name'] as $key => $name) {
                                if (!empty($name) && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php') {
                                    if (basename($name, '.php') === 'index') {
                                        echo '<p class="has-text-danger">Error: "index" cannot be used as a file name.</p>';
                                        continue;
                                    }
                                    $targetDir = $userFolder . '/';
                                    $targetFile = $targetDir . basename($name);
                                    if (move_uploaded_file($uploadedFiles['tmp_name'][$key], $targetFile)) {
                                        echo '<p class="has-text-success">File uploaded successfully: ' . htmlspecialchars($name) . '</p>';
                                    } else {
                                        echo '<p class="has-text-danger">Error uploading file: ' . htmlspecialchars($name) . '</p>';
                                    }
                                } else {
                                    echo '<p class="has-text-warning">Only .php files are allowed.</p>';
                                }
                            }
                        }
                        ?>
                    </div>
                    <div class="column is-7">
                        <?php
                        $userFiles = array_diff(scandir($userFolder), array('.', '..'));
                        function formatFileName($fileName)
                        {
                            return basename($fileName, '.php');
                        }
                        if (!empty($userFiles)): ?>
                            <h1 class="title is-4">Your custom API Files:</h1>
                            <form action="" method="POST" id="deleteForm">
                                <table class="table is-striped" style="width: 100%; text-align: center;">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <th>File Name</th>
                                            <th>Link</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userFiles as $file): ?>
                                            <?php if (pathinfo($file, PATHINFO_EXTENSION) === 'php'): ?>
                                                <tr>
                                                    <td style="vertical-align: middle;"><i class="fas fa-copy copy-link"
                                                            data-link="https://<?php echo $twitchUsername; ?>.specterbot.app/<?php echo htmlspecialchars($file); ?>"
                                                            style="cursor: pointer;"></i></td>
                                                    <td style="text-align: left; vertical-align: middle;">
                                                        <?php echo htmlspecialchars(formatFileName($file)); ?>
                                                    </td>
                                                    <td style="text-align: left; vertical-align: middle;"><a
                                                            href="https://<?php echo $twitchUsername; ?>.specterbot.app/<?php echo htmlspecialchars($file); ?>"
                                                            target="_blank">https://<?php echo $twitchUsername; ?>.specterbot.app/<?php echo htmlspecialchars($file); ?></a>
                                                    </td>
                                                    <td style="vertical-align: middle;"><button type="button"
                                                            class="delete-single button is-danger"
                                                            data-file="<?php echo htmlspecialchars($file); ?>">Delete</button></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </form>
                        <?php else: ?>
                            <h1 class="title is-4">A list of files will appear here.</h1>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <br>
    <footer class="footer">
        <div class="content has-text-centered">
            <p>&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.</p>
        </div>
    </footer>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Get all "navbar-burger" elements
            const $navbarBurgers = Array.prototype.slice.call(document.querySelectorAll('.navbar-burger'), 0);
            // Check if there are any navbar burgers
            if ($navbarBurgers.length > 0) {
                // Add a click event on each of them
                $navbarBurgers.forEach(el => {
                    el.addEventListener('click', () => {
                        // Get the target from the "data-target" attribute
                        const target = el.dataset.target;
                        const $target = document.getElementById(target);
                        // Toggle the "is-active" class on both the "navbar-burger" and the "navbar-menu"
                        el.classList.toggle('is-active');
                        $target.classList.toggle('is-active');
                    });
                });
            }
        });
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.copy-link').forEach(button => {
                button.addEventListener('click', function () {
                    const link = this.getAttribute('data-link');
                    navigator.clipboard.writeText(link).then(() => {
                        alert('Link copied to clipboard: ' + link);
                    }).catch(err => {
                        console.error('Failed to copy link: ', err);
                    });
                });
            });
        });
        document.addEventListener("DOMContentLoaded", function () {
            let dropArea = document.getElementById('drag-area');
            let fileInput = document.getElementById('filesToUpload');
            let fileList = document.getElementById('file-list');
            if (!dropArea || !fileInput || !fileList) {
                return;
            }
            dropArea.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropArea.classList.add('dragging');
            });
            dropArea.addEventListener('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropArea.classList.remove('dragging');
            });
            dropArea.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropArea.classList.remove('dragging');
                let files = e.dataTransfer.files;
                let dt = new DataTransfer();
                let rejected = 0;
                Array.from(files).forEach(file => {
                    if (file.name.toLowerCase().endsWith('.php')) {
                        dt.items.add(file);
                    } else {
                        rejected++;
                    }
                });
                if (rejected > 0) {
                    alert(rejected + ' file(s) were rejected. Only .php files are allowed.');
                }
                fileInput.files = dt.files;
                fileList.innerHTML = '';
                Array.from(dt.files).forEach(file => {
                    let div = document.createElement('div');
                    div.textContent = file.name;
                    fileList.appendChild(div);
                });
            });
            dropArea.addEventListener('click', function () {
                fileInput.click();
            });
            fileInput.addEventListener('change', function () {
                let files = fileInput.files;
                let dt = new DataTransfer();
                let rejected = 0;
                Array.from(files).forEach(file => {
                    if (file.name.toLowerCase().endsWith('.php')) {
                        dt.items.add(file);
                    } else {
                        rejected++;
                    }
                });
                if (rejected > 0) {
                    alert(rejected + ' file(s) were rejected. Only .php files are allowed.');
                    fileInput.files = dt.files;
                }
                fileList.innerHTML = '';
                Array.from(fileInput.files).forEach(file => {
                    let div = document.createElement('div');
                    div.textContent = file.name;
                    fileList.appendChild(div);
                });
            });
            fileInput.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            document.addEventListener('click', function (e) {
                if (e.target !== fileInput && e.target !== dropArea) {
                    fileInput.blur();
                }
            });
        });
    </script>
    <script>
        const userDatabaseStatus = "<?php echo $userDatabaseExists; ?>";
        if (userDatabaseStatus === "User database does not exist. Please use the bot to create your database first.") {
            alert("User database does not exist. Please use the bot to create your database first.");
        }
    </script>
    <script>console.log('Welcome to SpecterBot Custom API!');</script>
    <script>console.log('Connection status: <?php echo $connection; ?>');</script>
    <script>console.log('Your Twitch username is: <?php echo $twitchUsername; ?>');</script>
    <script>console.log('User database status: <?php echo $userDatabaseExists; ?>');</script>
    <script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/prism.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-markup-templating.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-php.min.js"></script>
    <script>
        (function () {
            const modal = document.getElementById('websocket-docs-modal');
            let highlighted = false;
            function openModal() {
                modal.classList.add('is-active');
                if (!highlighted) {
                    Prism.highlightAllUnder(modal);
                    highlighted = true;
                }
            }
            function closeModal() {
                modal.classList.remove('is-active');
            }
            document.getElementById('open-websocket-docs').addEventListener('click', openModal);
            document.getElementById('close-websocket-docs').addEventListener('click', closeModal);
            document.getElementById('close-websocket-docs-footer').addEventListener('click', closeModal);
            document.getElementById('websocket-modal-bg').addEventListener('click', closeModal);
        })();
    </script>
</body>

</html>