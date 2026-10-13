<?php
// Shared page for overlays replaced by Specter Alerts (index.php). Plays nothing and opens no WebSocket.
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overlay retired</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body>
    <div id="overlayRetiredBanner" class="overlay-error-banner overlay-error-banner-warn">
        This overlay has been retired. Specter Alerts replaces it. Set it up on the Alerts page in the dashboard, then remove this browser source.
    </div>
    <script>
        console.warn('[Specter] This overlay is retired. Use Specter Alerts: https://overlay.botofthespecter.com/?code=YOUR_API_KEY');
        setTimeout(() => { document.getElementById('overlayRetiredBanner').style.display = 'none'; }, 30000);
    </script>
</body>
</html>
