<?php
session_start();

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$isLoggedIn = isset($_SESSION['access_token']) && isset($_SESSION['api_key']);

$pageTitle = 'Discord Twitch Link';
$statusClass = 'is-danger';
$statusTitle = 'Link Failed';
$statusMessage = 'Invalid request.';

ob_start();

if ($token === '') {
    $statusMessage = 'The link token is missing. Please run !linktwitch again in Discord.';
} elseif (!$isLoggedIn) {
    $statusClass = 'is-warning';
    $statusTitle = 'Login Required';
    $statusMessage = 'Please log in to your BotOfTheSpecter account (Twitch), then return to this page using the Discord DM link.';
} else {
    $apiKey = (string)$_SESSION['api_key'];
    $endpoint = 'https://api.botofthespecter.com/discord/twitch-link/confirm?' . http_build_query([
        'api_key' => $apiKey,
        'token' => $token,
    ]);
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        $statusMessage = 'Unable to reach the API right now. Please try again in a moment. ' . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8');
    } else {
        $json = json_decode($response, true);
        if ($httpCode === 200 && is_array($json) && !empty($json['success'])) {
            $statusClass = 'is-success';
            $statusTitle = 'Link Complete';
            $linkedName = htmlspecialchars((string)($json['twitch_username'] ?? 'your Twitch account'), ENT_QUOTES, 'UTF-8');
            $statusMessage = 'Your Discord user is now linked to Twitch account: <strong>' . $linkedName . '</strong>.';
        } else {
            $detail = 'Unknown error.';
            if (is_array($json) && isset($json['detail'])) {
                $detail = (string)$json['detail'];
            }
            $statusMessage = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        }
    }
}
?>
<main class="is-fullwidth content" role="main" aria-labelledby="discord-twitch-link-heading">
    <div class="columns is-centered mt-5">
        <div class="column is-10-tablet is-8-desktop is-7-widescreen">
            <div class="box has-background-dark has-text-light">
                <h1 id="discord-twitch-link-heading" class="title has-text-light"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                <article class="message <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="message-header">
                        <p><?php echo htmlspecialchars($statusTitle, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="message-body">
                        <?php echo $statusMessage; ?>
                    </div>
                </article>
            </div>
        </div>
    </div>
</main>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>