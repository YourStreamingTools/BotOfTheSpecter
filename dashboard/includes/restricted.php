<?php
// Ensure translations are available even when this partial is included by a
// page that did not load i18n (e.g. admin_access.php). The guard prevents
// double-loading when the including page (e.g. login.php) already defined t().
if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    $i18nPath = __DIR__ . '/../lang/i18n.php';
    if (file_exists($i18nPath)) {
        include_once $i18nPath;
    }
    if (!function_exists('t')) {
        function t($key, $replacements = [])
        {
            return $key;
        }
    }
}

$accessMode = isset($accessMode) ? (string) $accessMode : 'restricted';
$isAccessDenied = ($accessMode === 'denied');
$isMemorial = ($accessMode === 'memorial');

$restrictedDetails = [
    t('restricted_detail_1'),
    t('restricted_detail_2'),
    t('restricted_detail_3'),
    t('restricted_detail_4'),
    t('restricted_detail_5'),
    t('restricted_detail_6')
];

if (!isset($info) || trim((string) $info) === '') {
    if ($isMemorial) {
        $info = t('restricted_info_memorial');
    } elseif ($isAccessDenied) {
        $info = t('restricted_info_denied');
    } else {
        $info = t('restricted_info_default');
    }
}

if ($isMemorial) {
    $pageTitle = t('restricted_page_title_memorial');
    $headingTitle = t('restricted_heading_memorial');
} elseif ($isAccessDenied) {
    $pageTitle = t('restricted_page_title_denied');
    $headingTitle = t('restricted_heading_denied');
} else {
    $pageTitle = t('restricted_page_title_default');
    $headingTitle = t('restricted_heading_default');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Theme bootstrap: apply saved/OS theme before stylesheets paint (avoids flash) -->
    <script>
        (function () {
            try {
                var t = localStorage.getItem('sp-theme');
                if (t !== 'light' && t !== 'dark') {
                    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
                }
                document.documentElement.setAttribute('data-theme', t);
                document.documentElement.className = (t === 'light' ? 'light-theme' : 'dark-theme');
            } catch (e) {}
        })();
    </script>
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="css/dashboard.css">
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
            max-width: 1100px;
            width: 100%;
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
            max-width: 100%;
            padding-left: 1.25rem;
            list-style-position: outside;
            line-height: 1.55;
        }
        .restricted-details li {
            margin-bottom: 0.7rem;
            padding-left: 0.2rem;
        }
        .restricted-details li:last-child {
            margin-bottom: 0;
        }
        .restricted-details li::marker {
            color: #b5b5b5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <header class="card-header">
                <p class="card-header-title">
                    <span class="icon mr-2">
                        <?php if ($isMemorial): ?>
                            <i class="fas fa-dove" style="color:#9b59b6;"></i>
                        <?php else: ?>
                            <i class="fas fa-exclamation-triangle has-text-danger"></i>
                        <?php endif; ?>
                    </span>
                    <?php echo htmlspecialchars($headingTitle, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </header>
            <div class="card-content">
                <div class="content has-text-centered">
                    <img src="https://cdn.botofthespecter.com/logo.png" alt="<?php echo htmlspecialchars(t('restricted_logo_alt'), ENT_QUOTES, 'UTF-8'); ?>" width="100" class="mb-4">
                    <p class="mb-4"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if (!$isAccessDenied && !$isMemorial): ?>
                        <ul class="restricted-details">
                            <?php foreach ($restrictedDetails as $detail): ?>
                                <li><?php echo htmlspecialchars($detail, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="buttons is-centered">
                        <?php if (!$isAccessDenied && !$isMemorial): ?>
                            <a href="mailto:support@botofthespecter.com" class="button is-link">
                                <span class="icon">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <span><?php echo htmlspecialchars(t('restricted_btn_contact_support'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endif; ?>
                        <a href="https://botofthespecter.com" class="button is-light">
                            <span class="icon">
                                <i class="fas fa-home"></i>
                            </span>
                            <span><?php echo htmlspecialchars(t('restricted_btn_return_home'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>