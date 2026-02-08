<?php
session_start();

require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Handle StreamersConnect auth_data callback (supports signed payloads and server tokens)
if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
	$decoded = null;
	// Load main config which contains the StreamersConnect API key
	$cfg = require_once "/var/www/config/main.php";
	$apiKey = isset($cfg['streamersconnect_api_key']) ? $cfg['streamersconnect_api_key'] : '';
	// Prefer server-side verification of signed payloads
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
			if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
		}
	}
	// Next, try exchanging a short-lived server token
	if (!$decoded && isset($_GET['server_token']) && $apiKey) {
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
			if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
		}
	}
	// Fallback to legacy base64 auth_data if nothing else worked
	if (!$decoded && isset($_GET['auth_data'])) {
		$decoded = json_decode(base64_decode($_GET['auth_data']), true);
	}
	if (!is_array($decoded) || empty($decoded['success'])) {
		$message = 'Authentication failed or was cancelled.';
	} elseif (isset($decoded['service']) && $decoded['service'] === 'twitch') {
		$user = $decoded['user'] ?? [];
		// Set session values similar to other login handlers
		$_SESSION['twitch_user_id'] = $user['id'] ?? null;
		$_SESSION['twitch_display_name'] = $user['display_name'] ?? ($user['global_name'] ?? ($user['login'] ?? $user['username'] ?? null));
		$_SESSION['twitch_username'] = $user['login'] ?? $user['username'] ?? null;
		$_SESSION['profile_image'] = $user['profile_image_url'] ?? null;
		$_SESSION['user_email'] = $user['email'] ?? null;
		$_SESSION['access_token'] = $decoded['access_token'] ?? null;
		$_SESSION['refresh_token'] = $decoded['refresh_token'] ?? null;
		// Redirect to clean URL
		header('Location: feedback.php');
		exit;
	}
}

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
			$is_bug_report = isset($_POST['is_bug_report']) ? 1 : 0;
			$bug_category = $is_bug_report ? trim($_POST['bug_category'] ?? '') : null;
			$severity = $is_bug_report ? trim($_POST['severity'] ?? '') : null;
			$steps_to_reproduce = $is_bug_report ? trim($_POST['steps_to_reproduce'] ?? '') : null;
			$expected_behavior = $is_bug_report ? trim($_POST['expected_behavior'] ?? '') : null;
			$actual_behavior = $is_bug_report ? trim($_POST['actual_behavior'] ?? '') : null;
			$browser_info = $is_bug_report ? trim($_POST['browser_info'] ?? '') : null;
			$error_message = $is_bug_report ? trim($_POST['error_message'] ?? '') : null;
			$create_sql = "CREATE TABLE IF NOT EXISTS feedback (id INT AUTO_INCREMENT PRIMARY KEY,twitch_user_id VARCHAR(64),display_name VARCHAR(255),message TEXT,is_bug_report TINYINT(1) DEFAULT 0,bug_category VARCHAR(100),severity VARCHAR(50),steps_to_reproduce TEXT,expected_behavior TEXT,actual_behavior TEXT,browser_info VARCHAR(500),error_message TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
			$conn->query($create_sql);
			$stmt = $conn->prepare("INSERT INTO feedback (twitch_user_id, display_name, message, is_bug_report, bug_category, severity, steps_to_reproduce, expected_behavior, actual_behavior, browser_info, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			if ($stmt) {
				$stmt->bind_param('sssississss', $twitch_user_id, $display_name, $feedback_text, $is_bug_report, $bug_category, $severity, $steps_to_reproduce, $expected_behavior, $actual_behavior, $browser_info, $error_message);
				if ($stmt->execute()) {
					$message = $is_bug_report ? 'Thanks — your bug report has been recorded.' : 'Thanks — your feedback has been recorded.';
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

// Build StreamersConnect authorize URL for centralized OAuth
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$originDomain = $_SERVER['HTTP_HOST'];
$returnUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$streamersconnectBase = 'https://streamersconnect.com/';
$scopes = 'user:read:email';
$authorize_url = $streamersconnectBase . '?' . http_build_query([
	'service' => 'twitch',
	'login' => $originDomain,
	'scopes' => $scopes,
	'return_url' => $returnUrl
]);
ob_start();
?>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"BotOfTheSpecter - Feedback","description":"Send feedback or bug reports for BotOfTheSpecter.","url":"https://botofthespecter.com/feedback.php"}</script>
<?php
// Page scripts moved into the body to match other pages (keeps head smaller).

$pageTitle = "BotOfTheSpecter — Feedback";
$pageDescription = "Send feedback or bug reports for BotOfTheSpecter.";

ob_start();
?>
<main class="box is-fullwidth content" role="main" aria-labelledby="feedback-heading">
	<h1 id="feedback-heading" class="title">Feedback for BotOfTheSpecter</h1>
	<p class="subtitle">We welcome feedback and bug reports — your input helps us improve.</p>
	<br />
	<?php
	// choose a Bulma notification class for message display
	$message_class = '';
	if ($message !== '') {
		$message_class = (strpos($message, 'Thanks') === 0 || stripos($message, 'recorded') !== false) ? 'is-success' : 'is-danger';
	}
	?>
	<?php if ($message): ?>
		<div class="notification <?= h($message_class) ?> is-light">
			<button class="delete"></button>
			<?= h($message) ?>
		</div>
	<?php endif; ?>
	<div class="columns is-variable is-6">
		<div class="column is-two-thirds">
			<div class="card feedback-card">
				<div class="card-content">
					<?php if (!empty($_SESSION['twitch_user_id'])): ?>
						<p class="mb-4">Signed in as <strong><?= h($_SESSION['twitch_display_name'] ?? $_SESSION['twitch_user_id']) ?></strong> — <a href="?logout=1" class="button is-light is-small">Sign out</a></p>
						<form method="post" action="feedback.php" id="feedbackForm" class="feedback-form">
							<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
							<input type="hidden" name="action" value="submit_feedback">
							<input type="hidden" name="browser_info" id="browser_info" value="">
							<div class="field">
								<label class="checkbox">
									<input type="checkbox" id="is_bug_report" name="is_bug_report">
									Is this a bug report?
								</label>
							</div>
							<div class="field" id="general_feedback_section">
								<label class="label" for="feedback_text">Do you have any feedback about "BotOfTheSpecter"?</label>
								<div class="control">
									<textarea id="feedback_text" name="feedback_text" class="textarea" rows="5" placeholder="Tell us what's working, what's not, or what you'd like to see..."></textarea>
								</div>
							</div>
							<div id="bug_report_fields" style="display: none;">
								<!-- ...bug report fields unchanged... -->
								<div class="field">
									<label class="label" for="bug_category">Category <span class="has-text-danger">*</span></label>
									<div class="control">
										<div class="select is-fullwidth">
											<select id="bug_category" name="bug_category">
												<option value="">Select a category...</option>
												<option value="dashboard">Dashboard/Overlays</option>
												<option value="websocket">WebSocket</option>
												<option value="bot">Twitch Bot</option>
												<option value="discord">Discord Bot</option>
												<option value="api">API</option>
												<option value="other">Other</option>
											</select>
										</div>
									</div>
								</div>
								<div class="field">
									<label class="label" for="severity">Severity <span class="has-text-danger">*</span></label>
									<div class="control">
										<div class="select is-fullwidth">
											<select id="severity" name="severity">
												<option value="">Select severity...</option>
												<option value="low">Low - Minor inconvenience</option>
												<option value="medium">Medium - Feature not working as expected</option>
												<option value="high">High - Major feature broken</option>
												<option value="critical">Critical - System unusable</option>
											</select>
										</div>
									</div>
								</div>
								<div class="field">
									<label class="label" for="steps_to_reproduce">Steps to Reproduce <span class="has-text-danger">*</span></label>
									<div class="control">
										<textarea id="steps_to_reproduce" name="steps_to_reproduce" class="textarea" rows="4" placeholder="1. Go to...&#10;2. Click on...&#10;3. See error"></textarea>
									</div>
								</div>
								<div class="field">
									<label class="label" for="expected_behavior">Expected Behavior <span class="has-text-danger">*</span></label>
									<div class="control">
										<textarea id="expected_behavior" name="expected_behavior" class="textarea" rows="3" placeholder="What should have happened?"></textarea>
									</div>
								</div>
								<div class="field">
									<label class="label" for="actual_behavior">Actual Behavior <span class="has-text-danger">*</span></label>
									<div class="control">
										<textarea id="actual_behavior" name="actual_behavior" class="textarea" rows="3" placeholder="What actually happened?"></textarea>
									</div>
								</div>
								<div class="field">
									<label class="label" for="error_message">Error Message (if any)</label>
									<div class="control">
										<textarea id="error_message" name="error_message" class="textarea" rows="3" placeholder="Paste any error messages here"></textarea>
									</div>
								</div>
								<div class="field" id="browser_info_display" style="display: none;">
									<label class="label">Browser Information</label>
									<div class="field">
										<label class="checkbox">
											<input type="checkbox" id="different_device" name="different_device">
											I'm reporting this from a different device/browser
										</label>
									</div>
									<div class="control">
										<textarea id="browser_info_text" class="textarea" rows="2" readonly style="background-color: #2b2b2b; cursor: not-allowed;" placeholder="Browser info will be auto-detected..."></textarea>
									</div>
									<p class="help" id="browser_info_help">This information helps us diagnose browser-specific issues</p>
								</div>
							</div>
							<div class="field is-grouped mt-4">
								<div class="control">
									<button class="button is-primary" type="submit" id="submitBtn">Send feedback</button>
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
		<div class="column is-one-third">
			<div class="box feedback-sidebar">
				<h3 class="title is-5">Need help?</h3>
				<p>Join our <a class="has-text-info" href="https://discord.com/invite/ANwEkpauHJ" target="_blank">Public Discord Server</a> for live support and discussions.</p>
				<hr>
				<p><strong>Privacy:</strong> Your Twitch info is used only to record feedback submitters. See our <a class="has-text-info" href="privacy-policy.php">Privacy Policy</a>.</p>
				<p><strong>Terms:</strong> See our <a class="has-text-info" href="terms-of-service.php">Terms of Service</a>.</p>
			</div>
		</div>
	</div>
</main>
<?php
$pageContent = ob_get_clean();

ob_start();
?>
<script>
/* Feedback page script — moved to body for consistency with other pages */
document.addEventListener('DOMContentLoaded', function() {
	const bugReportCheckbox = document.getElementById('is_bug_report');
	const bugReportFields = document.getElementById('bug_report_fields');
	const generalFeedbackSection = document.getElementById('general_feedback_section');
	const feedbackTextLabel = generalFeedbackSection ? generalFeedbackSection.querySelector('label') : null;
	const submitBtn = document.getElementById('submitBtn');
	const form = document.getElementById('feedbackForm');
	const browserInfoInput = document.getElementById('browser_info');
	let bugCategorySelect = null;

	function updateBrowserInfo() {
		if (!bugCategorySelect) bugCategorySelect = document.getElementById('bug_category');
		const browserInfoDisplay = document.getElementById('browser_info_display');
		const browserInfoTextArea = document.getElementById('browser_info_text');
		const differentDeviceCheckbox = document.getElementById('different_device');
		if (bugReportCheckbox && bugReportCheckbox.checked && bugCategorySelect && bugCategorySelect.value === 'dashboard') {
			if (browserInfoDisplay) browserInfoDisplay.style.display = 'block';
			if (!differentDeviceCheckbox || !differentDeviceCheckbox.checked) {
				const browserInfo = `${navigator.userAgent} | Screen: ${screen.width}x${screen.height}`;
				if (browserInfoInput) browserInfoInput.value = browserInfo;
				if (browserInfoTextArea) browserInfoTextArea.value = browserInfo;
			}
		} else {
			if (browserInfoInput) browserInfoInput.value = '';
			if (browserInfoDisplay) browserInfoDisplay.style.display = 'none';
			if (differentDeviceCheckbox) differentDeviceCheckbox.checked = false;
		}
	}

	document.addEventListener('change', function(e) {
		if (e.target && e.target.id === 'bug_category') {
			bugCategorySelect = e.target; updateBrowserInfo();
		}
		if (e.target && e.target.id === 'different_device') {
			const browserInfoTextArea = document.getElementById('browser_info_text');
			const browserInfoHelp = document.getElementById('browser_info_help');
			if (e.target.checked) {
				if (browserInfoTextArea) {
					browserInfoTextArea.removeAttribute('readonly');
					browserInfoTextArea.style.backgroundColor = '';
					browserInfoTextArea.style.cursor = '';
					browserInfoTextArea.value = '';
					browserInfoTextArea.placeholder = 'Please enter the browser and device info where the bug occurred...';
				}
				if (browserInfoHelp) browserInfoHelp.textContent = 'Enter the browser name, version, OS, and any other relevant details';
			} else {
				if (browserInfoTextArea) {
					browserInfoTextArea.setAttribute('readonly', true);
					browserInfoTextArea.style.backgroundColor = '#2b2b2b';
					browserInfoTextArea.style.cursor = 'not-allowed';
					browserInfoTextArea.placeholder = 'Browser info will be auto-detected...';
				}
				if (browserInfoHelp) browserInfoHelp.textContent = 'This information helps us diagnose browser-specific issues';
				updateBrowserInfo();
			}
		}
	});

	if (bugReportCheckbox) bugReportCheckbox.addEventListener('change', function() {
		if (this.checked) {
			if (bugReportFields) bugReportFields.style.display = 'block';
			if (feedbackTextLabel) feedbackTextLabel.innerHTML = 'Bug Summary <span class="has-text-danger">*</span>';
			if (document.getElementById('feedback_text')) document.getElementById('feedback_text').placeholder = 'Brief description of the bug...';
			if (submitBtn) submitBtn.textContent = 'Submit Bug Report';
			updateBrowserInfo();
		} else {
			if (bugReportFields) bugReportFields.style.display = 'none';
			if (feedbackTextLabel) feedbackTextLabel.textContent = 'Do you have any feedback about "BotOfTheSpecter"?';
			if (document.getElementById('feedback_text')) document.getElementById('feedback_text').placeholder = "Tell us what's working, what's not, or what you'd like to see...";
			if (submitBtn) submitBtn.textContent = 'Send feedback';
			if (browserInfoInput) browserInfoInput.value = '';
		}
	});

	if (form) form.addEventListener('submit', function(e) {
		const isBugReport = bugReportCheckbox && bugReportCheckbox.checked;
		const feedbackText = document.getElementById('feedback_text') ? document.getElementById('feedback_text').value.trim() : '';
		if (feedbackText === '') { e.preventDefault(); alert(isBugReport ? 'Please provide a bug summary.' : 'Please enter some feedback.'); return; }
		if (isBugReport) {
			const category = document.getElementById('bug_category') ? document.getElementById('bug_category').value : '';
			const severity = document.getElementById('severity') ? document.getElementById('severity').value : '';
			const steps = document.getElementById('steps_to_reproduce') ? document.getElementById('steps_to_reproduce').value.trim() : '';
			const expected = document.getElementById('expected_behavior') ? document.getElementById('expected_behavior').value.trim() : '';
			const actual = document.getElementById('actual_behavior') ? document.getElementById('actual_behavior').value.trim() : '';
			if (!category || !severity || !steps || !expected || !actual) { e.preventDefault(); alert('Please fill in all required bug report fields.'); return; }
			if (category === 'dashboard') {
				const differentDevice = document.getElementById('different_device');
				const browserInfoText = document.getElementById('browser_info_text');
				if (differentDevice && differentDevice.checked && browserInfoText) browserInfoInput.value = browserInfoText.value;
			}
		}
	});
	const deleteButtons = document.querySelectorAll('.notification .delete');
	deleteButtons.forEach(button => { button.addEventListener('click', function() { this.parentElement.style.display = 'none'; }); });
});
</script>

<?php
$customPageScript = ob_get_clean();
include 'layout.php';
?>