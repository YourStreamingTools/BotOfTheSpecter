<?php
// support/layout.php
// ----------------------------------------------------------------
// Shared layout shell for all support portal pages.
// Callers set: $pageTitle (string), $content (HTML string from ob_*)
// Optional: $pageDescription, $bodyClass, $topbarTitle
// ----------------------------------------------------------------

if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        return bin2hex(random_bytes(4));
    }
}

$config           = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'] ?? '4.1.0';

if (!isset($pageTitle))       $pageTitle       = 'Support';
if (!isset($pageDescription)) $pageDescription = 'BotOfTheSpecter Support Portal — documentation, guides, and support tickets for your streaming bot.';
if (!isset($topbarTitle))     $topbarTitle     = $pageTitle;

// Session state (layout calls session_start via helpers if available)
if (!function_exists('is_staff')) {
    function is_staff(): bool { return false; }
}

$isLoggedIn = !empty($_SESSION['access_token']);
$displayName = htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? '', ENT_QUOTES);
$profileImage = htmlspecialchars($_SESSION['profile_image'] ?? '', ENT_QUOTES);
$userIsStaff  = is_staff();

$v = uuidv4();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> — BotOfTheSpecter Support</title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <!-- Open Graph -->
    <meta property="og:title"       content="<?php echo htmlspecialchars($pageTitle); ?> — BotOfTheSpecter Support">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <meta property="og:image"       content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <meta property="og:type"        content="website">
    <!-- Twitter card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:site"        content="@Tools4Streaming">
    <meta name="twitter:title"       content="<?php echo htmlspecialchars($pageTitle); ?> — BotOfTheSpecter Support">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <meta name="twitter:image"       content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <!-- Favicon -->
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- Font Awesome (self-hosted CDN) -->
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <!-- Custom stylesheet -->
    <link rel="stylesheet" href="/css/style.css?v=<?php echo $v; ?>">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body>
<!-- Mobile sidebar overlay -->
<div id="sp-sidebar-overlay" class="sp-sidebar-overlay"></div>
<!-- ===== LAYOUT ===== -->
<div class="sp-layout">
    <!-- ===== SIDEBAR ===== -->
    <aside id="sp-sidebar" class="sp-sidebar">
        <!-- Brand -->
        <div class="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter">
            <div class="sp-brand-text">
                <span class="sp-brand-title">BotOfTheSpecter</span>
                <span class="sp-brand-sub">Support Portal</span>
            </div>
        </div>
        <!-- Nav -->
        <nav class="sp-nav">
            <!-- Documentation section (dynamic from DB) -->
            <?php
            $_navSections = [];
            if (function_exists('support_db')) {
                try {
                    $_navDb  = support_db();
                    $_navRes = $_navDb->query('SELECT section_key, section_label, section_icon FROM support_doc_sections ORDER BY section_order ASC, section_label ASC');
                    if ($_navRes) $_navSections = $_navRes->fetch_all(MYSQLI_ASSOC);
                } catch (Exception $e) { /* ignore — DB may not exist yet */ }
            }
            ?>
            <div class="sp-nav-section">
                <div class="sp-nav-label">Documentation</div>
                <a href="/index.php" class="sp-nav-link"><i class="fa-solid fa-house"></i> Home</a>
                <a href="/index.php#commands" class="sp-nav-link"><i class="fa-solid fa-terminal"></i> Command Reference</a>
                <a href="/index.php#faq" class="sp-nav-link"><i class="fa-solid fa-circle-question"></i> FAQ</a>
                <a href="/index.php#troubleshooting" class="sp-nav-link"><i class="fa-solid fa-wrench"></i> Troubleshooting</a>
                <?php foreach ($_navSections as $_ns): ?>
                <a href="/index.php#<?php echo urlencode($_ns['section_key']); ?>" class="sp-nav-link">
                    <i class="<?php echo htmlspecialchars($_ns['section_icon']); ?>"></i>
                    <?php echo htmlspecialchars($_ns['section_label']); ?>
                </a>
                <?php endforeach; ?>
                <?php if ($userIsStaff): ?>
                <a href="/docs.php" class="sp-nav-link sp-nav-link-staff">
                    <i class="fa-solid fa-pen-to-square"></i> Manage Docs
                    <span class="sp-badge sp-badge-accent" style="margin-left:auto;font-size:0.65rem;">Staff</span>
                </a>
                <?php endif; ?>
            </div>
            <!-- External links -->
            <div class="sp-nav-section">
                <div class="sp-nav-label">Resources</div>
                <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-book"></i> API Docs <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
                <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-brands fa-github"></i> GitHub <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
                <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-gauge"></i> Dashboard <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
            </div>
            <!-- Support Tickets section -->
            <div class="sp-nav-section">
                <div class="sp-nav-label">Support Tickets</div>
                <?php if ($isLoggedIn): ?>
                    <a href="/tickets.php"               class="sp-nav-link"><i class="fa-solid fa-ticket"></i> My Tickets</a>
                    <a href="/tickets.php?action=new"    class="sp-nav-link"><i class="fa-solid fa-plus"></i> Submit a Ticket</a>
                    <?php if ($userIsStaff): ?>
                        <a href="/tickets.php?view=staff" class="sp-nav-link"><i class="fa-solid fa-headset"></i> Staff Queue</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="/login.php"                  class="sp-nav-link"><i class="fa-solid fa-right-to-bracket"></i> Log in to Submit</a>
                <?php endif; ?>
            </div>
        </nav>
        <!-- Sidebar footer -->
        <div class="sp-sidebar-footer">
            <?php if ($isLoggedIn): ?>
                <div class="sp-user-block">
                    <?php if ($profileImage): ?>
                        <img src="<?php echo $profileImage; ?>" alt="<?php echo $displayName; ?>" class="sp-user-avatar">
                    <?php else: ?>
                        <div class="sp-user-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div style="min-width:0;">
                        <div class="sp-user-name"><?php echo $displayName; ?></div>
                        <div class="sp-user-role"><?php echo $userIsStaff ? 'Staff' : 'User'; ?></div>
                    </div>
                </div>
                <a href="/logout.php" class="sp-nav-link sp-text-small">
                    <i class="fa-solid fa-right-from-bracket"></i> Log Out
                </a>
            <?php else: ?>
                <a href="/login.php" class="sp-btn sp-btn-primary" style="width:100%;justify-content:center;">
                    <i class="fa-solid fa-right-to-bracket"></i> Log In
                </a>
            <?php endif; ?>
        </div>
    </aside>
    <!-- /SIDEBAR -->
    <!-- ===== MAIN ===== -->
    <div class="sp-main">
        <!-- Topbar -->
        <header class="sp-topbar">
            <button id="sp-hamburger" class="sp-hamburger" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <span class="sp-topbar-title"><?php echo htmlspecialchars($topbarTitle); ?></span>
            <div class="sp-topbar-actions">
                <!-- Search (only shown on index/docs pages) -->
                <div class="sp-search-wrap" id="sp-search-wrap" style="display:none;">
                    <i class="fa-solid fa-magnifying-glass sp-search-icon"></i>
                    <input
                        type="text"
                        id="sp-search-input"
                        class="sp-search-input"
                        placeholder="Search docs…"
                        autocomplete="off"
                        spellcheck="false"
                        aria-label="Search documentation"
                    >
                    <div id="sp-search-results"></div>
                </div>
                <?php if ($isLoggedIn && !$userIsStaff): ?>
                    <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary sp-btn-sm">
                        <i class="fa-solid fa-plus"></i> New Ticket
                    </a>
                <?php elseif (!$isLoggedIn): ?>
                    <a href="/login.php" class="sp-btn sp-btn-secondary sp-btn-sm">
                        <i class="fa-brands fa-twitch"></i> Log In
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <!-- Page content -->
        <main class="sp-content">
            <?php echo $content; ?>
        </main>
        <!-- Footer -->
        <footer class="sp-footer">
            &copy; 2023&ndash;<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            BotOfTheSpecter is operated under the business name &quot;YourStreamingTools&quot;, registered in Australia (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).<br>
            Not affiliated with Twitch Interactive, Inc., Discord Inc., Spotify AB, or StreamElements Inc.<br>
            All trademarks are the property of their respective owners.<br>
            <span style="color:var(--text-muted);font-size:0.72rem;">Portal v<?php echo htmlspecialchars($dashboardVersion); ?></span>
        </footer>
    </div>
    <!-- /MAIN -->
</div>
<!-- /LAYOUT -->
<!-- Scripts -->
<script src="/js/app.js?v=<?php echo $v; ?>" defer></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>