<?php
if (!isset($pageTitle))     $pageTitle     = 'Members Portal';
if (!isset($activeNav))     $activeNav     = '';
if (!isset($extraHead))     $extraHead     = '';
if (!isset($topbarActions)) $topbarActions = '';
if (!isset($extraScripts))  $extraScripts  = '';

$_cssV = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();
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
    <title>BotOfTheSpecter &mdash; <?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="BotOfTheSpecter Members Portal — view channel data, commands, stats and more.">
    <meta property="og:title" content="BotOfTheSpecter &mdash; <?php echo htmlspecialchars($pageTitle); ?>">
    <meta property="og:description" content="BotOfTheSpecter Members Portal — view channel data, commands, stats and more.">
    <meta property="og:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@Tools4Streaming">
    <meta name="twitter:title" content="BotOfTheSpecter &mdash; <?php echo htmlspecialchars($pageTitle); ?>">
    <meta name="twitter:description" content="BotOfTheSpecter Members Portal — view channel data, commands, stats and more.">
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="/style.css?v=<?php echo $_cssV; ?>">
    <?php if ($extraHead) echo $extraHead; ?>
</head>
<body>
<div id="sp-sidebar-overlay" class="sp-sidebar-overlay"></div>
<div class="sp-layout">
    <!-- SIDEBAR -->
    <aside id="sp-sidebar" class="sp-sidebar">
        <div class="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter">
            <div class="sp-brand-text">
                <span class="sp-brand-title">BotOfTheSpecter</span>
                <span class="sp-brand-sub">Members Portal</span>
            </div>
        </div>
        <nav class="sp-nav">
            <div class="sp-nav-section">
                <div class="sp-nav-label">Navigation</div>
                <a href="/" class="sp-nav-link<?php echo $activeNav === 'search' ? ' active' : ''; ?>">
                    <i class="fa-solid fa-magnifying-glass"></i> Search Channels
                </a>
                <a href="/freegames.php" class="sp-nav-link<?php echo $activeNav === 'freegames' ? ' active' : ''; ?>">
                    <i class="fa-solid fa-gamepad"></i> Free Games
                </a>
            </div>
            <div class="sp-nav-section">
                <div class="sp-nav-label">Resources</div>
                <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-gauge"></i> Dashboard <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
                <a href="https://support.botofthespecter.com" target="_blank" rel="noopener" class="sp-nav-link">
                    <i class="fa-solid fa-circle-question"></i> Support <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;margin-left:auto;"></i>
                </a>
            </div>
        </nav>
        <div class="sp-sidebar-footer">
            <div class="sp-user-block">
                <?php if (!empty($_SESSION['profile_image_url'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['profile_image_url'], ENT_QUOTES); ?>"
                         alt="<?php echo htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES); ?>"
                         class="sp-user-avatar">
                <?php else: ?>
                    <div class="sp-user-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div style="min-width:0;">
                    <div class="sp-user-name"><?php echo htmlspecialchars($_SESSION['display_name'] ?? ''); ?></div>
                    <div class="sp-user-role">Member</div>
                </div>
            </div>
            <a href="/logout.php" class="sp-nav-link sp-text-small">
                <i class="fa-solid fa-right-from-bracket"></i> Log Out
            </a>
        </div>
    </aside>
    <!-- MAIN -->
    <div class="sp-main">
        <header class="sp-topbar">
            <button id="sp-hamburger" class="sp-hamburger" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <span class="sp-topbar-title"><?php echo htmlspecialchars($pageTitle); ?></span>
            <div class="sp-topbar-actions">
                <button class="sp-theme-toggle" id="spThemeToggle" type="button" aria-label="Toggle light or dark theme" title="Toggle theme">
                    <i class="fa-solid fa-moon"></i>
                </button>
                <?php if ($topbarActions) echo $topbarActions; ?>
            </div>
        </header>
        <main class="sp-content">
            <?php echo $content; ?>
        </main>
        <footer class="sp-footer">
            &copy; 2023&ndash;<?php echo date('Y'); ?> BotOfTheSpecter &mdash; All rights reserved.
        </footer>
    </div>
    <!-- /MAIN -->
</div>
<!-- /LAYOUT -->
<script>
    (function () {
        const overlay = document.getElementById('sp-sidebar-overlay');
        const sidebar = document.getElementById('sp-sidebar');
        const hamburger = document.getElementById('sp-hamburger');
        if (!hamburger) return;
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('visible'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('visible'); }
        hamburger.addEventListener('click', openSidebar);
        overlay.addEventListener('click', closeSidebar);
    })();
</script>
<script>
    // Light/dark theme toggle (topbar). The <head> bootstrap sets the initial theme.
    (function () {
        var btn = document.getElementById('spThemeToggle');
        function current() { return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
        function syncIcon(theme) {
            if (!btn) return;
            var icon = btn.querySelector('i');
            if (icon) icon.className = (theme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon');
        }
        function apply(theme, persist) {
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.className = (theme === 'light' ? 'light-theme' : 'dark-theme');
            if (persist) { try { localStorage.setItem('sp-theme', theme); } catch (e) {} }
            syncIcon(theme);
        }
        syncIcon(current());
        if (btn) btn.addEventListener('click', function () { apply(current() === 'light' ? 'dark' : 'light', true); });
        window.addEventListener('storage', function (e) {
            if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) { apply(e.newValue, false); }
        });
    })();
</script>
<?php if ($extraScripts) echo $extraScripts; ?>
</body>
</html>