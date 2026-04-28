<?php
require_once '/var/www/lib/session_bootstrap.php';
$bots_logged_in   = !empty($_SESSION['access_token']);
$bots_display_name = $bots_logged_in
    ? ($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Account')
    : null;

ob_start();
?>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"BotOfTheSpecter","description":"BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.","url":"https://botofthespecter.com/"}</script>
<?php
$extraScripts = ob_get_clean();

ob_start();
$pageTitle       = "BotOfTheSpecter";
$pageDescription = "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.";
?>
<!-- Hero -->
<section class="hs-hero">
    <div class="hs-hero-eyebrow"><i class="fa-brands fa-twitch"></i> Twitch Bot &amp; Discord Extension</div>
    <h1 class="hs-hero-title">BotOfTheSpecter</h1>
    <p class="hs-hero-tagline">The Twitch Chat Bot built to replace them all!</p>
    <div class="hs-hero-ctas">
        <?php if ($bots_logged_in): ?>
            <a href="/profile.php" class="hs-btn hs-btn-primary">
                <i class="fa-solid fa-user"></i> View Profile (<?php echo htmlspecialchars($bots_display_name); ?>)
            </a>
            <a href="/logout.php" class="hs-btn hs-btn-ghost">
                <i class="fa-solid fa-right-from-bracket"></i> Log out
            </a>
        <?php else: ?>
            <a href="/login.php" class="hs-btn hs-btn-primary">
                <i class="fa-brands fa-twitch"></i> Login
            </a>
            <a href="https://discord.com/invite/ANwEkpauHJ" class="hs-btn hs-btn-ghost" target="_blank" rel="noopener">
                <i class="fa-brands fa-discord"></i> Join Discord
            </a>
        <?php endif; ?>
    </div>
</section>

<!-- Info notice -->
<div class="hs-notice">
    <strong>BotOfTheSpecter is a Twitch bot with a powerful Discord extension.</strong><br>
    It connects your Twitch activity, events, and community directly to your Discord server.<br>
    <u>The Discord bot is not standalone</u>&mdash;it&rsquo;s designed to work with your Twitch channel. If you only want a Discord bot, this isn&rsquo;t the right tool.<br>
    At YourStreamingTools, we&rsquo;re continually evolving and adding new features to deepen the connection between your Twitch and Discord communities.
</div>

<!-- Feature grid -->
<div class="hs-feature-grid">
    <div class="hs-card">
        <h2 class="hs-card-title"><i class="fa-brands fa-twitch"></i> Twitch Bot Features</h2>
        <ul>
            <li><strong>Custom Chat Commands:</strong> Create and manage custom commands to engage with your viewers. Plus, take advantage of our extensive, feature-packed built-in command list.</li>
            <li><strong>Channel Point Rewards:</strong> Set up and automate responses to channel point redemptions.</li>
            <li><strong>Real-time Analytics:</strong> Monitor your channel growth and viewer engagement.</li>
            <li><strong>Community Management:</strong> Tools for managing subscribers, VIPs, and moderating chat.</li>
            <li><strong>Automated Alerts:</strong> Customizable notifications for follows, subscriptions, and <span class="coming-soon" title="Coming soon!">donations</span>.</li>
            <li><strong>Stream Dashboard:</strong> All-in-one control center for your stream management.</li>
        </ul>
    </div>
    <div class="hs-card">
        <h2 class="hs-card-title"><i class="fa-brands fa-discord"></i> Discord Bot Features</h2>
        <ul>
            <li>Post Twitch activity and announcements to Discord.</li>
            <li><span class="coming-soon" title="Coming soon!">Auto-manage roles based on Twitch status.</span></li>
            <li><span class="coming-soon" title="Coming soon!">Custom welcome messages.</span></li>
            <li>Community tools linked to Twitch events.</li>
            <li><span class="coming-soon" title="Coming soon!">Moderation that works with your Twitch setup.</span></li>
            <li>Some basic Discord features work alone, but are limited without a linked Twitch channel.</li>
        </ul>
    </div>
</div>

<!-- Text sections -->
<div class="hs-section">
    <h2><i class="fa-solid fa-users"></i> Tailored for Streamers and Communities</h2>
    <p>BotOfTheSpecter is built for streamers who want the ultimate Twitch chat and moderation experience&mdash;with seamless Discord integration. Our system is designed to unify your Twitch and Discord communities, not to run as a standalone Discord bot. If you want the best for your Twitch channel and want to connect your Discord, this is the tool for you.</p>
</div>

<div class="hs-section">
    <h2><i class="fa-solid fa-bolt"></i> Simple and Effective Tools</h2>
    <p><strong>Easy-to-use Dashboard:</strong> Access all the tools you need to manage your Twitch stream with just a few clicks, whether you&rsquo;re monitoring real-time engagement or configuring bot settings.</p>
    <p><strong>Dedicated Features for Twitch:</strong> Enjoy tailored functionality for Twitch, ensuring you have the best tools to grow and manage your community effectively.</p>
</div>

<div class="hs-section">
    <h2><i class="fa-solid fa-heart"></i> Join the Community</h2>
    <p>Join the BotOfTheSpecter community and help shape the future of Twitch chat and moderation. Our focus is on building the best Twitch bot&mdash;with Discord integration for those who want to connect both platforms.</p>
    <p>Connect with other streamers and get support on our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener"><i class="fa-brands fa-discord"></i> Public Discord Server</a>.</p>
    <p>Want to contribute or see how it works? BotOfTheSpecter is fully open source&mdash;check out our <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub page</a>. Happy streaming&mdash;and enjoy a truly unified chat experience!</p>
</div>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>
