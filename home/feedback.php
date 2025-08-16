<?php
session_start();

require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$logout = isset($_GET['logout']);
if ($logout) {
	// clear all session data and destroy the session so the user is fully logged out
	$_SESSION = [];
	// expire the session cookie if used
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000,
			$params['path'], $params['domain'],
			$params['secure'], $params['httponly']
		);
	}
	session_destroy();
	// Redirect back to the feedback page (new session will be started on reload)
	header('Location: feedback.php');
	exit;
}
// CSRF protection
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$method = $_SERVER['REQUEST_METHOD'];
$message = '';

// Handle feedback POST
if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_feedback') {
	// require login
	if (empty($_SESSION['twitch_user_id'])) {
		$message = 'You must be signed in with Twitch to submit feedback.';
	} elseif (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
		$message = 'Invalid CSRF token.';
	} else {
		$twitch_user_id = $_SESSION['twitch_user_id'];
		$display_name = $_SESSION['twitch_display_name'] ?? '';
		$feedback_text = trim($_POST['feedback_text'] ?? '');
		if ($feedback_text === '') {
			$message = 'Please enter some feedback before submitting.';
		} else {
			// Ensure feedback table exists
			$create_sql = "CREATE TABLE IF NOT EXISTS feedback (id INT AUTO_INCREMENT PRIMARY KEY,twitch_user_id VARCHAR(64),display_name VARCHAR(255),message TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
			$conn->query($create_sql);
			$stmt = $conn->prepare("INSERT INTO feedback (twitch_user_id, display_name, message) VALUES (?, ?, ?)");
			if ($stmt) {
				$stmt->bind_param('sss', $twitch_user_id, $display_name, $feedback_text);
				if ($stmt->execute()) {
					$message = 'Thanks — your feedback has been recorded.';
				} else {
					$message = 'Failed to save feedback (db error).';
				}
				$stmt->close();
			} else {
				$message = 'Failed to save feedback (db prepare failed).';
			}
		}
	}
}

// Build Twitch authorize URL when needed
$redirect_uri = 'https://botofthespecter.com/oauth_callback.php';
$authorize_url = '';
$state = bin2hex(random_bytes(8));
$_SESSION['oauth_state'] = $state;
$scopes = urlencode('user:read:email');
$authorize_url = "https://id.twitch.tv/oauth2/authorize?response_type=code&client_id=" . urlencode($clientID) . "&redirect_uri=" . urlencode($redirect_uri) . "&scope={$scopes}&state=" . urlencode($state) . "&force_verify=true";


?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>BotOfTheSpecter — Feedback</title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
    <script src="navbar.js" defer></script>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter — Feedback" />
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>" />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
</head>
<body>
	<section class="section">
		<div class="container">
			<h1 class="title">Feedback for BotOfTheSpecter</h1>
			<?php
			// choose a Bulma notification class for message display
			$message_class = '';
			if ($message !== '') {
				$message_class = (strpos($message, 'Thanks') === 0 || stripos($message, 'recorded') !== false) ? 'is-success' : 'is-danger';
			}
			?>
			<div class="columns is-centered">
				<div class="column is-half">
					<div class="card">
						<div class="card-content">
							<?php if ($message): ?>
								<div class="notification <?= h($message_class) ?>">
									<button class="delete"></button>
									<?= h($message) ?>
								</div>
							<?php endif; ?>
							<?php if (!empty($_SESSION['twitch_user_id'])): ?>
								<p class="mb-4">Signed in as <strong><?= h($_SESSION['twitch_display_name'] ?? $_SESSION['twitch_user_id']) ?></strong> — <a href="?logout=1" class="button is-light is-small">Sign out</a></p>
								<form method="post" action="feedback.php">
									<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
									<input type="hidden" name="action" value="submit_feedback">
									<div class="field">
										<label class="label" for="feedback_text">Do you have any feedback about "BotOfTheSpecter"?</label>
										<div class="control">
											<textarea id="feedback_text" name="feedback_text" class="textarea" rows="5" placeholder="Tell us what's working, what's not, or what you'd like to see..."></textarea>
										</div>
									</div>
									<div class="field is-grouped">
										<div class="control">
											<button class="button is-primary" type="submit">Send feedback</button>
										</div>
									</div>
								</form>
							<?php else: ?>
								<p class="mb-4">Please sign in with your Twitch account to submit feedback. We only use your Twitch ID/display name to record who submitted feedback.</p>
								<a class="button is-link" href="<?= h($authorize_url) ?>">Sign in with Twitch</a>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>
</body>
</html>
