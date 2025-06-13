<?php
// If we don't have an info message, set a default one
if (!isset($info)) {
    $info = "Your account has been restricted from accessing BotOfTheSpecter. If you believe this is a mistake, please contact support.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted - BotOfTheSpecter</title>
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
                    Access Restricted
                </p>
            </header>
            <div class="card-content">
                <div class="content has-text-centered">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" width="100" class="mb-4">
                    <p class="mb-4"><?php echo $info; ?></p>
                    <div class="buttons is-centered">
                        <a href="mailto:support@botofthespecter.com" class="button is-link">
                            <span class="icon">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <span>Contact Support</span>
                        </a>
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
