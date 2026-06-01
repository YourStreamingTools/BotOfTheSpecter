<?php
$code = $_GET['code'] ?? '';
$theme = $_GET['theme'] ?? 'terminal';
if (!in_array($theme, ['terminal', 'pill', 'card', 'macwindow'], true)) {
    $theme = 'terminal';
}

// Terminal theme shows root@{username}; look it up from the api_key.
$username = '';
if ($code !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
    include '/var/www/config/database.php';
    $conn = @new mysqli($db_servername, $db_username, $db_password, 'website');
    if (!$conn->connect_error) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $username = $row['username'] ?? '';
            $stmt->close();
        }
    }
}
$host = $username !== '' ? $username : 'specter';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Spotify Now Playing</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body>
<?php if ($theme === 'terminal'): ?>
<div id="sp-root" class="spotify-overlay-page spotify-overlay-page-terminal" data-theme="terminal">
    <div class="spotify-overlay-page-term-bar">
        <span class="spotify-overlay-page-term-host">root@<?php echo htmlspecialchars($host); ?></span>
        <span class="spotify-overlay-page-term-btns"><i>_</i><i>&#9633;</i><i class="spotify-overlay-page-term-close">&#10005;</i></span>
    </div>
    <div class="spotify-overlay-page-term-body">
        <div class="spotify-overlay-page-term-cmd"><span class="spotify-overlay-page-term-prompt">&gt; ./specter</span> <span class="spotify-overlay-page-term-flag">--nowplaying</span></div>
        <div class="spotify-overlay-page-term-line"><span class="spotify-overlay-page-term-label">Title:</span>&nbsp;<span data-sp="title"></span></div>
        <div class="spotify-overlay-page-term-line"><span class="spotify-overlay-page-term-label">Artist:</span>&nbsp;<span data-sp="artist"></span></div>
        <div class="spotify-overlay-page-term-line"><span class="spotify-overlay-page-term-blocks" data-sp="blockbar"></span>&nbsp;<span data-sp="cur"></span>&nbsp;/&nbsp;<span data-sp="dur"></span></div>
    </div>
</div>
<?php elseif ($theme === 'pill'): ?>
<div id="sp-root" class="spotify-overlay-page spotify-overlay-page-pill" data-theme="pill">
    <img class="spotify-overlay-page-pill-art" data-sp="art" alt="">
    <span class="spotify-overlay-page-pill-text"><span class="spotify-overlay-page-pill-title" data-sp="title"></span> &bull; <span class="spotify-overlay-page-pill-artist" data-sp="artist"></span></span>
    <span class="spotify-overlay-page-pill-bar"><span class="spotify-overlay-page-pill-fill" data-sp="fill"></span></span>
</div>
<?php elseif ($theme === 'card'): ?>
<div id="sp-root" class="spotify-overlay-page spotify-overlay-page-card" data-theme="card">
    <img class="spotify-overlay-page-card-art" data-sp="art" alt="">
    <div class="spotify-overlay-page-card-info">
        <div class="spotify-overlay-page-card-title" data-sp="title"></div>
        <div class="spotify-overlay-page-card-artist" data-sp="artist"></div>
    </div>
    <div class="spotify-overlay-page-card-prog">
        <span data-sp="cur"></span>
        <span class="spotify-overlay-page-card-bar"><span class="spotify-overlay-page-card-fill" data-sp="fill"></span></span>
        <span data-sp="dur"></span>
    </div>
</div>
<?php else: ?>
<div id="sp-root" class="spotify-overlay-page spotify-overlay-page-macwindow" data-theme="macwindow">
    <div class="spotify-overlay-page-mac-bar">
        <span class="spotify-overlay-page-mac-dots"><i></i><i></i><i></i></span>
        <span class="spotify-overlay-page-mac-eq"><b></b><b></b><b></b><b></b><b></b></span>
    </div>
    <div class="spotify-overlay-page-mac-body">
        <img class="spotify-overlay-page-mac-art" data-sp="art" alt="">
        <div class="spotify-overlay-page-mac-info">
            <div class="spotify-overlay-page-mac-title" data-sp="title"></div>
            <div class="spotify-overlay-page-mac-artist" data-sp="artist"></div>
            <div class="spotify-overlay-page-mac-times"><span data-sp="cur"></span><span data-sp="dur"></span></div>
            <span class="spotify-overlay-page-mac-progress"><span class="spotify-overlay-page-mac-fill" data-sp="fill"></span></span>
        </div>
    </div>
</div>
<?php endif; ?>
<script>
(function () {
    const params = new URLSearchParams(location.search);
    const code = params.get('code');
    const root = document.getElementById('sp-root');
    if (!code || !root) return;
    const endpoint = 'spotify_nowplaying.php?code=' + encodeURIComponent(code);

    // Auto-scale to the OBS browser-source size: measure the overlay's natural
    // size, then CSS-zoom to fit. zoom re-renders the layout (not a bitmap
    // upscale), so it stays sharp at any source dimensions — no user setting.
    function fit() {
        root.style.zoom = '1';
        const w = root.offsetWidth, h = root.offsetHeight;
        if (w > 0 && h > 0) {
            root.style.zoom = Math.min(window.innerWidth / w, window.innerHeight / h);
        }
    }
    const POLL_MS = 4000;
    let progMs = 0, durMs = 0, isPlaying = false, shown = null;
    const fmt = (ms) => {
        const s = Math.max(0, Math.floor(ms / 1000));
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    };
    const setAll = (key, fn) => root.querySelectorAll('[data-sp="' + key + '"]').forEach(fn);
    function blocks(pct) {
        const N = 14, f = Math.round((pct / 100) * N);
        return '[' + '▓'.repeat(f) + '░'.repeat(N - f) + ']';
    }
    function paint() {
        const pct = durMs ? Math.min(100, (progMs / durMs) * 100) : 0;
        setAll('fill', el => el.style.width = pct + '%');
        setAll('blockbar', el => el.textContent = blocks(pct));
        setAll('cur', el => el.textContent = fmt(progMs));
        setAll('dur', el => el.textContent = fmt(durMs));
    }
    function show(on) {
        if (on === shown) return;
        shown = on;
        root.classList.toggle('spotify-overlay-page-show', on);
    }
    function render(d) {
        if (!d || !d.active || !d.title) { show(false); return; }
        setAll('title', el => el.textContent = d.title);
        setAll('artist', el => el.textContent = d.artist || '');
        setAll('art', el => { if (d.album_art) el.src = d.album_art; });
        progMs = d.progress_ms || 0;
        durMs = d.duration_ms || 0;
        isPlaying = !!d.is_playing;
        paint();
        show(true);
        fit();
    }
    async function poll() {
        try {
            const r = await fetch(endpoint, { cache: 'no-store', headers: { 'X-Specter-Overlay': '1' } });
            render(await r.json());
        } catch (e) { /* keep last rendered state on transient errors */ }
    }
    poll();
    setInterval(poll, POLL_MS);
    setInterval(() => {
        if (isPlaying && durMs) { progMs = Math.min(durMs, progMs + 1000); paint(); }
    }, 1000);
    window.addEventListener('resize', fit);
})();
</script>
</body>
</html>