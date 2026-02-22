<?php
$accessMode = isset($accessMode) ? (string) $accessMode : 'restricted';
$isAccessDenied = ($accessMode === 'denied');

$restrictedDetails = [
    'This account restriction is applied when security, abuse-prevention, or platform policy checks detect behavior that puts services, users, or integrations at risk.',
    'While restricted, you may be blocked from dashboard pages, bot command controls, token or integration management, and other account-level actions.',
    'Restrictions can be temporary or permanent depending on severity, frequency, and prior account history.',
    'Repeated login attempts, API misuse, automation abuse, or attempts to bypass limits may delay or prevent restoration of access.',
    'Do not create alternate accounts to avoid this restriction. Circumvention attempts may result in broader enforcement actions.',
    'If you believe this is an error, contact support with your Twitch username, approximate time of your last successful login, and any relevant context so we can review your case quickly.'
];

if (!isset($info) || trim((string) $info) === '') {
    if ($isAccessDenied) {
        $info = 'Access denied.';
    } else {
        $info = "Your account has been restricted from accessing BotOfTheSpecter due to activity that violated our platform rules or acceptable use policies.";
    }
}

$pageTitle = $isAccessDenied ? 'Access Denied - BotOfTheSpecter' : 'Access Restricted - BotOfTheSpecter';
$headingTitle = $isAccessDenied ? 'Access Denied' : 'Access Restricted';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <!-- Bulma CSS 1.0.0 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.0/css/bulma.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <style>
        body {
            background-color: #121212;
            color: #f5f5f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .container {
            max-width: 600px;
            padding: 2rem;
        }
        .card {
            background-color: #1a1a1a;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
        }
        .card-header {
            background-color: #141414;
        }
        .card-header-title {
            color: #f5f5f5 !important;
        }
        .card-content {
            background-color: #1a1a1a;
        }
        .restricted-details {
            text-align: left;
            margin: 0 auto 1.25rem auto;
            max-width: 520px;
        }
        .restricted-details li {
            margin-bottom: 0.6rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <header class="card-header">
                <p class="card-header-title">
                    <span class="icon mr-2">
                        <i class="fas fa-exclamation-triangle has-text-danger"></i>
                    </span>
                    <?php echo htmlspecialchars($headingTitle, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </header>
            <div class="card-content">
                <div class="content has-text-centered">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" width="100" class="mb-4">
                    <p class="mb-4"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if (!$isAccessDenied): ?>
                        <ul class="restricted-details">
                            <?php foreach ($restrictedDetails as $detail): ?>
                                <li><?php echo htmlspecialchars($detail, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="buttons is-centered">
                        <?php if (!$isAccessDenied): ?>
                            <a href="mailto:support@botofthespecter.com" class="button is-link">
                                <span class="icon">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <span>Contact Support</span>
                            </a>
                        <?php endif; ?>
                        <a href="https://botofthespecter.com" class="button is-light">
                            <span class="icon">
                                <i class="fas fa-home"></i>
                            </span>
                            <span>Return to Homepage</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>