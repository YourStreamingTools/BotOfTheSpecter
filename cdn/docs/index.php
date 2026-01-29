<?php
$target = "https://botofthespecter.com";
$year = date("Y");
header("Refresh: 2; url=$target");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BotOfTheSpecter CDN</title>
<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
<link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
<style>
:root {
    --bg: #0b0d10;
    --card: #12151b;
    --text: #e6e8eb;
    --muted: #9aa4af;
    --accent: #5865f2;
}

* {
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    background: radial-gradient(circle at top, #141824, var(--bg));
    color: var(--text);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Ubuntu, sans-serif;
}

.wrapper {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.card {
    background: linear-gradient(180deg, #141824, var(--card));
    border-radius: 16px;
    padding: 40px 48px;
    max-width: 520px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
    text-align: center;
}

.logo {
    width: 96px;
    height: 96px;
    margin-bottom: 20px;
}

h1 {
    margin: 0 0 12px;
    font-size: 1.8rem;
    font-weight: 600;
}

p {
    margin: 0 0 20px;
    color: var(--muted);
    line-height: 1.6;
}

.spinner {
    font-size: 1.8rem;
    color: var(--accent);
    margin-bottom: 16px;
}

.footer {
    margin-top: 28px;
    font-size: .85rem;
    color: #7c8591;
}
</style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" class="logo">
        <h1>BotOfTheSpecter CDN</h1>
        <div class="spinner">
            <i class="fa-solid fa-circle-notch fa-spin"></i>
        </div>
        <p>
            You’ve reached the content delivery network.<br>
            Redirecting you to the main site…
        </p>
        <p>
            <a href="<?= $target ?>" style="color:var(--accent);text-decoration:none;">
                Click here if you are not redirected automatically
            </a>
        </p>
        <div class="footer">
            © 2023–<?= $year ?> BotOfTheSpecter
        </div>
    </div>
</div>
<script>
setTimeout(() => {
    window.location.href = "<?= $target ?>";
}, 2000);
</script>
</body>
</html>