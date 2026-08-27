<?php
require_once '/var/www/lib/session_bootstrap.php';
$bots_logged_in   = !empty($_SESSION['access_token']);
$bots_display_name = $bots_logged_in
    ? ($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Account')
    : null;

ob_start();
?>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"BotOfTheSpecter","description":"BotOfTheSpecter is a Twitch-first streaming platform with a Discord extension, OBS overlays, dashboard tools, and optional integrations for music, tips, and more.","url":"https://botofthespecter.com/"}</script>
<?php
$extraScripts = ob_get_clean();

ob_start();
$pageTitle       = "BotOfTheSpecter";
$pageDescription = "BotOfTheSpecter is a Twitch-first streaming platform with a Discord extension, OBS overlays, dashboard tools, and optional integrations for music, tips, and more.";
?>
<!-- Hero -->
<section class="hs-hero">
    <div class="hs-hero-eyebrow"><i class="fa-brands fa-twitch"></i> Twitch-first streaming tools</div>
    <h1 class="hs-hero-title">BotOfTheSpecter</h1>
    <p class="hs-hero-tagline">The Twitch chat bot built to replace them all&mdash;with a dashboard, overlays, and a Discord extension.</p>
    <div class="hs-hero-ctas">
        <?php if ($bots_logged_in): ?>
            <a href="/profile.php" class="hs-btn hs-btn-primary">
                <i class="fa-solid fa-user"></i> View Profile (<?php echo htmlspecialchars($bots_display_name); ?>)
            </a>
            <a href="https://dashboard.botofthespecter.com/dashboard.php" class="hs-btn hs-btn-ghost">
                <i class="fa-solid fa-gauge-high"></i> Open Dashboard
            </a>
            <a href="/logout.php" class="hs-btn hs-btn-ghost">
                <i class="fa-solid fa-right-from-bracket"></i> Log out
            </a>
        <?php else: ?>
            <a href="/login.php" class="hs-btn hs-btn-primary">
                <i class="fa-brands fa-twitch"></i> Login with Twitch
            </a>
            <a href="https://dashboard.botofthespecter.com/dashboard.php" class="hs-btn hs-btn-ghost">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="https://discord.com/invite/ANwEkpauHJ" class="hs-btn hs-btn-ghost" target="_blank" rel="noopener">
                <i class="fa-brands fa-discord"></i> Join Discord
            </a>
        <?php endif; ?>
    </div>
</section>

<!-- Info notice -->
<div class="hs-notice">
    <strong>BotOfTheSpecter is a Twitch-first bot platform from YourStreamingTools.</strong><br>
    Sign in with Twitch to configure your channel in the dashboard, run the chat bot, and drive OBS overlays in real time.<br>
    The <u>Discord bot is not standalone</u>&mdash;it&rsquo;s built to connect your Twitch activity to Discord. If you only want a Discord bot, this isn&rsquo;t the right tool.<br>
    Optional add-ons include Spotify, tip platforms, a Kick companion bot, multi-region stream tools, and Premium perks via a Twitch subscription to the developer.
</div>

<!-- Feature grid -->
<div class="hs-feature-grid">
    <div class="hs-card">
        <h2 class="hs-card-title"><i class="fa-brands fa-twitch"></i> Twitch Bot</h2>
        <ul>
            <li><strong>Commands:</strong> Built-in and custom chat commands with permissions and channel controls.</li>
            <li><strong>Channel points:</strong> Automate responses and actions for reward redemptions.</li>
            <li><strong>Community tools:</strong> Points, watch time, counters, lurkers, and other engagement features.</li>
            <li><strong>Alerts:</strong> Follows, subs, sound/video/walk-on alerts, and tip events from connected platforms.</li>
            <li><strong>Music:</strong> Spotify integration for now-playing and song-related commands.</li>
            <li><strong>AI (Premium):</strong> Optional AI chat features for higher Twitch subscription tiers.</li>
        </ul>
    </div>
    <div class="hs-card">
        <h2 class="hs-card-title"><i class="fa-brands fa-discord"></i> Discord Extension</h2>
        <ul>
            <li>Link your Discord server to your Twitch channel.</li>
            <li>Post Twitch activity and stream-related announcements to Discord.</li>
            <li>Relay tip and event alerts configured through the dashboard.</li>
            <li>Optional FreeStuff game announcement channel settings.</li>
            <li>Discord&ndash;Twitch user linking for connected community tools.</li>
            <li>Requires a Twitch-linked setup&mdash;not a standalone Discord bot product.</li>
        </ul>
    </div>
    <div class="hs-card">
        <h2 class="hs-card-title"><i class="fa-solid fa-layer-group"></i> Dashboard, Overlays &amp; More</h2>
        <ul>
            <li><strong>Dashboard:</strong> Configure the bot, media, alerts, and integrations in one place.</li>
            <li><strong>OBS overlays:</strong> Browser sources for chat, music, TTS, deaths, weather, tips, and more.</li>
            <li><strong>Integrations:</strong> Discord, Spotify, StreamElements, Streamlabs, Patreon, Ko-fi, Fourthwall, HypeRate.</li>
            <li><strong>Kick companion:</strong> Optional Kick bot and webhook support for multi-platform streamers.</li>
            <li><strong>Stream tools:</strong> Optional multi-region ingest, recording, and multi-destination forward settings.</li>
            <li><strong>API &amp; real-time events:</strong> API keys and WebSocket-powered live overlays.</li>
        </ul>
    </div>
</div>

<!-- Text sections -->
<div class="hs-section">
    <h2><i class="fa-solid fa-users"></i> Built for streamers first</h2>
    <p>BotOfTheSpecter is aimed at Twitch streamers who want chat commands, moderation helpers, community tools, and live overlays&mdash;with Discord as a companion channel, not a separate product. If your priority is running a strong Twitch channel and optionally connecting Discord, tips, music, or Kick, this is the stack we build for.</p>
</div>

<div class="hs-section">
    <h2><i class="fa-solid fa-gauge-high"></i> One dashboard for the channel</h2>
    <p><strong>Easy-to-use Dashboard:</strong> Manage bot settings, media uploads, alerts, overlays, and connected services without juggling half a dozen tools.</p>
    <p><strong>Real-time overlays:</strong> Load browser sources in OBS and receive live events over our WebSocket service while you stream.</p>
    <p><strong>Open source:</strong> Prefer to inspect or contribute? The project is open source on GitHub.</p>
</div>

<div class="hs-section">
    <h2><i class="fa-solid fa-star"></i> Free core features + optional Premium</h2>
    <p>Most of the bot is free to use. Optional <strong>Premium</strong> perks (for example higher media storage, beta bot access, and higher-tier AI features) are unlocked by subscribing on Twitch to the bot developer <strong>gfaUnDead</strong>. Billing for Premium is handled by Twitch, not by a separate card checkout on this site.</p>
    <p>See plans and details in the <a href="https://dashboard.botofthespecter.com/premium.php">dashboard Premium page</a> after you log in, or subscribe at <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" rel="noopener">twitch.tv/subs/gfaundead</a>.</p>
</div>

<div class="hs-section">
    <h2><i class="fa-solid fa-heart"></i> Join the community</h2>
    <p>Connect with other streamers, get help, and follow development on our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener"><i class="fa-brands fa-discord"></i> public Discord server</a>.</p>
    <p>Need tickets or docs? Visit the <a href="https://support.botofthespecter.com" target="_blank" rel="noopener"><i class="fa-solid fa-ticket"></i> support portal</a>.</p>
    <p>Want to contribute or see how it works? Check out our <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub page</a>. Happy streaming!</p>
</div>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>
