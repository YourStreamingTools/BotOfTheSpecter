<?php
// Start the session
session_start();

// Database connection & Twitch API credentials
require '/var/www/specterbotapp/database.php';
require_once "/var/www/config/twitch.php";
$redirectURI = 'https://specterbot.app/index.php';

// Twitch OAuth API URLs
$tokenURL = 'https://id.twitch.tv/oauth2/token';
$authUrl = 'https://id.twitch.tv/oauth2/authorize';

$userDatabaseExists = "";
function userDatabaseExists($username) {
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

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    // Exchange the authorization code for an access token and refresh token
    $postData = array(
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectURI
    );
    $curl = curl_init($tokenURL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    if ($response === false) {
        // Handle cURL error
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        // Handle non-successful HTTP response
        echo 'HTTP error: ' . $httpCode;
        exit;
    }
    curl_close($curl);
    // Extract the access token from the response
    $responseData = json_decode($response, true);
    $accessToken = $responseData['access_token'];
    $_SESSION['access_token'] = $accessToken;
    // Fetch the user's Twitch username
    $userInfoURL = 'https://api.twitch.tv/helix/users';
    $curl = curl_init($userInfoURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-ID: ' . $clientID
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $userInfoResponse = curl_exec($curl);
    if ($userInfoResponse === false) {
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        echo 'HTTP error: ' . $httpCode;
        exit;
    }
    curl_close($curl);
    $userInfo = json_decode($userInfoResponse, true);
    if (isset($userInfo['data']) && count($userInfo['data']) > 0) {
        $twitchUsername = $userInfo['data'][0]['login'];
        $_SESSION['twitch_username'] = $twitchUsername;
        $userFolder = '/var/www/specterbotapp/' . $twitchUsername;
        if (!userDatabaseExists($twitchUsername)) {
            $userDatabaseExists = "User database does not exist. Please use the bot to create your database first.";
        }
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }
        header('Location: ' . strtok($redirectURI, '?'));
        exit;
    } else {
        $twitchUsername = 'guest_user';
        $userDatabaseExists = "User is not signed in";
    }
} else {
    $twitchUsername = isset($_SESSION['twitch_username']) ? $_SESSION['twitch_username'] : 'guest_user';
    $userDatabaseExists = userDatabaseExists($twitchUsername) ? "User database exists" : "User database does not exist";
}

$loginURL = $authUrl . '?client_id=' . $clientID . '&redirect_uri=' . urlencode($redirectURI) . '&response_type=code&scope=user:read:email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to SpecterBot Custom API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.0/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description" content="BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics." />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <link rel="stylesheet" href="css/custom.css">
</head>
<body class="dark-mode">
    <header>
        <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
            <div class="navbar-brand">
                <a class="navbar-item" href="../"><img src="logo.png" alt="BotOfTheSpecter Logo"></a>
                <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasicExample">
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
                                <a href="<?php echo filter_var($loginURL, FILTER_SANITIZE_URL); ?>" class="button is-primary">Login with Twitch</a>
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
                Use your personalized subdomain at <code><?php echo $twitchUsername; ?>.specterbot.app</code> to interact with your custom endpoints.<br>
                <?php if ($twitchUsername === 'guest_user'): ?>Please note that you need to sign in to verify your database connection with SpecterBot.<?php endif; ?>
            </p>
            <div class="box" id="features">
                <h3 class="title is-4">Key Features</h3>
                <ul>
                    <li>Custom subdomains for users</li>
                    <li>Direct Access to your own database that Specter uses.</li>
                    <li>You can use <code>database.php</code> in your PHP files to auto-connect to your database.
                        <br>Example:<pre><code>require '/var/www/specterbotapp/database.php';</code></pre>
                    </li>
                </ul>
            </div>
            <?php if (isset($_SESSION['access_token'])): ?>
            <div class="box columns is-desktop is-multiline" id="file-uploads">
                <div class="column is-3">
                    <div id="specterbot-upload" style="position: relative;">
                        <h1 class="title is-4">Upload Your Files:</h1>
                            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                                <label for="filesToUpload" class="drag-area" id="drag-area">
                                    <span>Drag & Drop files here</span>
                                    <input class="is-hidden" type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".php">
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
                            if (!empty($name) && pathinfo($name, PATHINFO_EXTENSION) === 'php') {
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
                    function formatFileName($fileName) { return basename($fileName, '.php'); }
                    if (!empty($userFiles)) : ?>
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
                                        <td style="vertical-align: middle;"><i class="fas fa-copy copy-link" data-link="https://<?php echo $twitchUsername; ?>.specterbot.app/<?php echo htmlspecialchars($file); ?>" style="cursor: pointer;"></i></td>
                                        <td style="text-align: left; vertical-align: middle;"><?php echo htmlspecialchars(formatFileName($file)); ?></td>
                                        <td style="text-align: left; vertical-align: middle;"><a href="https://<?php echo $twitchUsername; ?>.specterbot.app/<?php echo htmlspecialchars($file); ?>" target="_blank">https://<?php echo $twitchUsername; ?>.specterbot.app/<?php echo htmlspecialchars($file); ?></a></td>
                                        <td style="vertical-align: middle;"><button type="button" class="delete-single button is-danger" data-file="<?php echo htmlspecialchars($file); ?>">Delete</button></td>
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
        fileInput.files = files;
        fileList.innerHTML = '';
        Array.from(files).forEach(file => {
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
        fileList.innerHTML = '';
        Array.from(files).forEach(file => {
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
</body>
</html>