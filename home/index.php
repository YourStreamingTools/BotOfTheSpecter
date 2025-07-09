<?php
ob_start();
?>
<script type="application/ld+json">{"@context": "https://schema.org","@type": "WebPage","name": "BotOfTheSpecter","description": "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.","url": "https://botofthespecter.com/"}</script>
<?php
$extraScripts = ob_get_clean();

ob_start();
$pageTitle = "BotOfTheSpecter";
$pageDescription = "BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics.";
?>
<section class="has-text-centered mb-5">
    <h1 class="title is-2" style="font-weight:900; letter-spacing:0.01em;">BotOfTheSpecter</h1>
    <p class="subtitle is-4" style="font-weight:600; color: #FFD700;">The Twitch Chat Bot built to replace them all!</p>
</section>
<section class="notification is-dark is-light has-text-warning is-flex is-align-items-center is-justify-content-center mb-5 info-notice-bar" style="border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.18); font-size:1.1rem; width:100%; margin-left:0; margin-right:0; padding: 1.25rem 2rem; border: 1.5px solid #ffd700;">
    <span class="icon-text" style="width:100%;display:flex;align-items:center;justify-content:center;">
        <span class="has-text-centered has-text-white">
            <strong>BotOfTheSpecter is a Twitch bot with a powerful Discord extension.</strong><br>
            It connects your Twitch activity, events, and community directly to your Discord server.<br>
            <u>The Discord bot is not standalone</u>—it’s designed to work with your Twitch channel, if you only want a Discord bot, this isn’t the right tool.<br>
            At YourStreamingTools, we're continually evolving and adding new features to deepen the connection between your Twitch and Discord communities.
        </span>
    </span>
</section>
<div class="columns is-multiline is-variable is-6">
    <div class="column is-12">
        <div class="columns is-variable is-4 feature-row">
            <!-- Twitch Bot Section -->
            <div class="column is-6">
                <section class="box">
                    <h2 class="title is-4">Twitch Bot Features</h2>
                    <ul class="mb-3">
                        <li><strong>Custom Chat Commands:</strong> Create and manage custom commands to engage with your viewers. Plus, take advantage of our extensive, feature-packed built-in command list.</li>
                        <li><strong>Channel Point Rewards:</strong> Set up and automate responses to channel point redemptions</li>
                        <li><strong>Real-time Analytics:</strong> Monitor your channel growth and viewer engagement</li>
                        <li><strong>Community Management:</strong> Tools for managing subscribers, VIPs, and moderating chat</li>
                        <li><strong>Automated Alerts:</strong> Customizable notifications for follows, subscriptions, and <span class="coming-soon" title="Coming soon!">donations</span></li>
                        <li><strong>Stream Dashboard:</strong> All-in-one control center for your stream management</li>
                    </ul>
                </section>
            </div>
            <!-- Discord Bot Section -->
            <div class="column is-6">
                <section class="box">
                    <h2 class="title is-4">Discord Bot Features</h2>
                    <ul class="mb-3">
                        <li>Post Twitch activity and announcements to Discord</li>
                        <li><span class="coming-soon" title="Coming soon!">Auto-manage roles based on Twitch status</span></li>
                        <li><span class="coming-soon" title="Coming soon!">Custom welcome messages</span></li>
                        <li>Community tools linked to Twitch events</li>
                        <li><span class="coming-soon" title="Coming soon!">Moderation that works with your Twitch setup</span></li>
                        <li>Some basic Discord features work alone, but are limited</li>
                    </ul>
                </section>
            </div>
        </div>
        <section class="box mb-5">
            <h2 class="title is-4">Tailored for Streamers and Communities</h2>
            <p>BotOfTheSpecter is built for streamers who want the ultimate Twitch chat and moderation experience—with seamless Discord integration. Our system is designed to unify your Twitch and Discord communities, not to run as a standalone Discord bot. If you want the best for your Twitch channel and want to connect your Discord, this is the tool for you.</p>
        </section>
        <section class="box mb-5">
            <h2 class="title is-4">Simple and Effective Tools</h2>
            <p><strong>Easy-to-use Dashboard:</strong> Access all the tools you need to manage your Twitch stream with just a few clicks, whether you're monitoring real-time engagement or configuring bot settings.</p>
            <p><strong>Dedicated Features for Twitch:</strong> Enjoy tailored functionality for Twitch, ensuring you have the best tools to grow and manage your community effectively.</p>
        </section>
        <section class="box">
            <h2 class="title is-4">Join the Community</h2>
            <p>Join the BotOfTheSpecter community and help shape the future of Twitch chat and moderation. Our focus is on building the best Twitch bot—with Discord integration for those who want to connect both platforms. If you’re a streamer who wants to unify your Twitch and Discord communities, you’re in the right place.</p>
            <p>Connect with other streamers and get support on our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" class="has-text-info">Public Discord Server</a>.</p>
            <p>Want to contribute or see how it works? BotOfTheSpecter is fully open source—check out our <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" class="has-text-info">GitHub page</a>.</p>
            <p>Happy streaming—and enjoy a truly unified chat experience!</p>
        </section>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>