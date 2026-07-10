<?php
// support/index.php
// ----------------------------------------------------------------
// Public documentation landing page.
// Static tabs: Setup, Commands (API), FAQ, Troubleshooting.
// Additional guide content is added as static PHP sections.
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
support_session_start();

// Load built-in commands from the API
$ch = curl_init('https://api.botofthespecter.com/commands/info');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_FAILONERROR    => true,
]);
$commandsJson = curl_exec($ch);
$cmdData  = $commandsJson ? (json_decode($commandsJson, true)['commands'] ?? []) : [];
$commands = [];
foreach ($cmdData as $k => $v) {
    $commands[$k] = is_array($v) ? $v : ['description' => (string)$v];
}
ksort($commands);

$pageTitle       = 'Documentation';
$topbarTitle     = 'BotOfTheSpecter Documentation';
$pageDescription = 'Complete documentation for BotOfTheSpecter — setup guides, command reference, integrations, and support tickets.';
$extraHead       = '<script>document.addEventListener("DOMContentLoaded",function(){var w=document.getElementById("sp-search-wrap");if(w)w.style.display="block";});</script>';

ob_start();
?>
<!-- ===== HERO ===== -->
<div class="sp-hero" style="text-align:center;padding:1.5rem 1rem 1.25rem;border-bottom:1px solid var(--border);margin-bottom:1.5rem;">
    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter"
         style="width:72px;height:72px;border-radius:50%;margin:0 auto 1rem;border:2px solid var(--border);display:block;">
    <h1 style="font-size:1.75rem;font-weight:800;margin-bottom:0.5rem;">BotOfTheSpecter Documentation</h1>
    <p style="color:var(--text-secondary);max-width:560px;margin:0 auto 1.5rem;">
        Everything you need to set up, configure, and get the most from your streaming bot.
    </p>
    <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;">
        <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary">
            <i class="fa-brands fa-github"></i> View on GitHub
        </a>
        <?php if (empty($_SESSION['access_token'])): ?>
        <a href="/login.php" class="sp-btn sp-btn-primary">
            <i class="fa-brands fa-twitch"></i> Log in to Submit a Ticket
        </a>
        <?php else: ?>
        <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary">
            <i class="fa-solid fa-ticket"></i> Submit a Support Ticket
        </a>
        <?php endif; ?>
    </div>
</div>
<!-- ===== QUICK LINKS GRID ===== -->
<div class="sp-doc-grid sp-mb-3">
    <a href="#" class="sp-doc-card" data-goto="setup">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-rocket"></i></div>
        <div class="sp-doc-card-title">First Time Setup</div>
        <div class="sp-doc-card-desc">Get the bot running on your channel.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="commands">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-terminal"></i></div>
        <div class="sp-doc-card-title">Command Reference</div>
        <div class="sp-doc-card-desc">All built-in bot commands.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="faq">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-circle-question"></i></div>
        <div class="sp-doc-card-title">FAQ</div>
        <div class="sp-doc-card-desc">Frequently asked questions.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="troubleshooting">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-wrench"></i></div>
        <div class="sp-doc-card-title">Troubleshooting</div>
        <div class="sp-doc-card-desc">Common issues and solutions.</div>
    </a>
</div>
<!-- ===================================================================
     TAB: FIRST TIME SETUP
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="setup">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">First Time Setup Guide</h1>
            <p style="margin:0;color:var(--text-secondary);">Connect Twitch, mod the bot, and configure the essentials.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="setup" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What is BotOfTheSpecter?</h2>
    <p>
        BotOfTheSpecter is a <strong>cloud-based Twitch chat bot</strong> that runs entirely on our servers.
        You don't need to install any software, run servers, or manage technical infrastructure.
        Just connect your Twitch account and start using the bot immediately!
    </p>

    <hr class="sp-divider">

    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Access the Dashboard</h4>
            <p>Go to the BotOfTheSpecter dashboard:</p>
            <p style="margin:1rem 0;">
                <a href="https://dashboard.botofthespecter.com" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Dashboard
                </a>
            </p>
            <p>Or visit: <code>https://dashboard.botofthespecter.com</code></p>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Connect Your Twitch Account</h4>
            <ol>
                <li>Click the <strong>Login with Twitch</strong> button on the dashboard</li>
                <li>You'll be redirected to Twitch's authorization page</li>
                <li>Review the permissions and click <strong>Authorize</strong></li>
                <li>You'll be redirected back to the dashboard, now logged in</li>
            </ol>
            <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Permissions:</strong> When you authorize BotOfTheSpecter, you'll be asked to grant a number of permissions.
                    These cover moderation, channel management, chat, subscriptions, analytics, and more.
                    Each permission enables specific features that make your streaming experience better.
                    <details style="margin-top:0.75rem;">
                        <summary style="cursor:pointer;color:var(--accent);">View full permissions list</summary>
                        <ul style="margin-top:0.75rem;font-size:0.875rem;max-height:280px;overflow-y:auto;padding-right:0.5rem;">
                            <li>Delete chat messages in channels where you have the moderator role</li>
                            <li>Read unban requests in channels where you have the moderator role</li>
                            <li>Perform moderation actions in a channel</li>
                            <li>Grant or remove the moderator role from users in your channel</li>
                            <li>Edit your channel's broadcast configuration including extension activations</li>
                            <li>Manage Channel Points custom rewards and their redemptions on your channel</li>
                            <li>Manage your channel's broadcast configuration, including updating channel configuration and managing stream markers and stream tags</li>
                            <li>Read your list of follows</li>
                            <li>Send live Stream Chat and Rooms messages</li>
                            <li>Read the list of VIPs in your channel</li>
                            <li>Grant or remove the VIP role from users in your channel</li>
                            <li>Get the details of your subscription to a channel</li>
                            <li>Send announcements in channels where you have the moderator role</li>
                            <li>Get a list of all users on your block list / Add and remove users from your block list</li>
                            <li>Get your Twitch user ID, username, profile image, profile update date, email address, and email verification status</li>
                            <li>Manage your channel's polls</li>
                            <li>Read chat messages from suspicious users and see users flagged as suspicious in channels where you have the moderator role</li>
                            <li>Read your channel's Hype Train data</li>
                            <li>View Channel Points rewards and their redemptions on your channel</li>
                            <li>Get a list of all subscribers to your channel and check if a user is subscribed to your channel</li>
                            <li>Manage your channel's schedule, including adding, updating, and deleting segments</li>
                            <li>Create clips from a broadcast or video</li>
                            <li>Join your channel's chat as a bot user</li>
                            <li>Read the list of channels you have moderator privileges in</li>
                            <li>Read non-private blocked terms / chat settings / moderators / bans / deleted messages / warnings in channels where you have the moderator role</li>
                            <li>Join chat as your user and appear as a bot</li>
                            <li>Manage AutoMod in channels where you have the moderator role</li>
                            <li>Read charity campaign details and user donations on your channel</li>
                            <li>Read chat messages and appear in chat / write chat messages as your user</li>
                            <li>View your channel's moderation data including Moderators, Bans, Timeouts and Automod settings</li>
                            <li>Read the list of followers / chatters in channels where you are a moderator</li>
                            <li>View your channel's Bits information</li>
                            <li>Run ads and manage / read the ads schedule on your channel</li>
                            <li>Ban or unban users / manage shoutouts in channels where you have the moderator role</li>
                        </ul>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Set Up Bot Permissions</h4>
            <p>The bot needs to be a moderator in your channel to function properly. There are two ways to do this:</p>

            <h4><i class="fa-solid fa-star" style="color:var(--accent);"></i> Option 1 (Recommended): Use the dashboard button</h4>
            <p>When you're logged into the dashboard and the bot is not modded, you'll see this warning:</p>
            <p><code>The bot is not a moderator on your channel. Please make the bot a moderator to start it.</code></p>
            <p>Click the <strong>Make Mod</strong> button and follow the prompt.</p>

            <h4 style="margin-top:1rem;">Option 2: Add the role manually on Twitch</h4>
            <ol>
                <li>Go to your <a href="https://dashboard.twitch.tv" target="_blank" rel="noopener">Twitch Dashboard</a></li>
                <li>On the left panel, expand the <strong>Community</strong> menu</li>
                <li>Click <strong>Roles Manager</strong></li>
                <li>Click <strong>Add New</strong></li>
                <li>In the search bar, enter: <code>BotOfTheSpecter</code></li>
                <li>Select the bot user and check the <strong>Moderator</strong> permission</li>
                <li><em>Optional:</em> Also check the <strong>Editor</strong> role to enable VOD video access</li>
            </ol>

            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <strong>Why is this needed?</strong> As a moderator, the bot can:
                    <ul style="margin-top:0.5rem;">
                        <li>Delete inappropriate messages</li>
                        <li>Timeout or ban users when necessary</li>
                        <li>Respond to commands in chat</li>
                        <li>Manage channel point redemptions</li>
                    </ul>
                    <p style="margin-top:0.5rem;margin-bottom:0;"><strong>Editor role benefits:</strong> Allows the bot to access VODs and video content for video-related commands.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure the Dashboard</h4>
            <p>Now that the bot has moderator permissions, continue configuration in the dashboard:</p>
            <ul>
                <li>If you used <strong>Option 1 (Make Mod)</strong> in Step 3, you're already in the right place.</li>
                <li>If you used <strong>Option 2 (manual Twitch Roles Manager)</strong>, return to the dashboard and refresh or log out and back in so permissions update.</li>
            </ul>
            <p style="margin:1rem 0;">
                <a href="https://dashboard.botofthespecter.com" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Dashboard
                </a>
            </p>

            <h4>Basic Settings</h4>
            <ul>
                <li><strong>Bot Status:</strong> After the bot is modded, the dashboard detects it automatically. Click <strong>START</strong> to run the bot and wait for the status to update.</li>
                <li><strong>Channel Information:</strong> Set up your preferences on the <a href="https://dashboard.botofthespecter.com/profile.php" target="_blank" rel="noopener">Profile page</a>:
                    <ul style="margin-top:0.5rem;">
                        <li>Technical/advanced options toggle</li>
                        <li>Dashboard language (English, French, or German)</li>
                        <li>Your Time Zone and Weather Location</li>
                        <li>HypeRate.io integration for heart rate display in chat</li>
                        <li>External connections for Discord, Spotify, and StreamElements</li>
                    </ul>
                </li>
                <li><strong>Command Prefix:</strong> Commands use <code>!</code> — this cannot be changed.</li>
            </ul>

            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-gamepad"></i>
                <div>
                    <strong>Control Your Bot</strong><br>
                    BotOfTheSpecter is designed with control in mind — <strong>you run the bot, you stop the bot</strong>.
                    If you no longer wish to use it, simply click <strong>STOP</strong>. It's that simple.<br><br>
                    <em style="display:block;padding-left:0.75rem;border-left:3px solid var(--accent);margin-top:0.5rem;">
                        "I built Specter so I'm not running 4 different chat bots on my own stream, now I just run one, that's Specter." — Developer
                    </em>
                </div>
            </div>

            <h4 style="margin-top:1.25rem;">Moderation Settings</h4>
            <p>Configure moderation on the <a href="https://dashboard.botofthespecter.com/modules.php" target="_blank" rel="noopener">Modules page</a>:</p>
            <ul>
                <li><strong>Joke Blacklist:</strong> Set up joke categories to blacklist from the <code>!joke</code> command</li>
                <li><strong>Chat Protection:</strong> Enable/disable URL blocking in chat
                    <ul style="margin-top:0.5rem;">
                        <li>When enabled, you can whitelist specific links to allow them</li>
                        <li>When disabled, you can still blacklist links that will always be removed from chat</li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">5</div>
        <div class="sp-step-body">
            <h4>Set Up Bot Points</h4>
            <p>Bot Points are your built-in loyalty system. Viewers earn points as they chat and interact, and you can tune how rewards are given. This feature is enabled by default.</p>
            <ul>
                <li><strong>Point Name:</strong> Choose a custom name for your points (e.g. Coins, Tokens, Credits)</li>
                <li><strong>Earning Rates:</strong> Configure how many points users earn for:
                    <ul style="margin-top:0.5rem;">
                        <li>Each chat message sent</li>
                        <li>Following your channel</li>
                        <li>Subscribing to your channel</li>
                        <li>Each cheered message</li>
                        <li>Each viewer in a raid</li>
                    </ul>
                </li>
                <li><strong>Subscriber Multipliers:</strong> Add bonus multipliers for subscribers (e.g. 2x points)</li>
            </ul>

            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <strong>Why set this up?</strong> Bot Points help encourage chat activity and make your community feel more interactive.
                    As more point-based features roll out, you'll be able to use them for custom rewards, shoutouts, and other perks.
                </div>
            </div>

            <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
                <i class="fa-solid fa-code"></i>
                <div>
                    <strong>API Integrations (Credit / Debit):</strong> Manage user points via the API for custom integrations.<br>
                    <ul style="margin-top:0.5rem;">
                        <li><strong>CREDIT</strong> (adds points): <code>https://api.botofthespecter.com/user-points/credit?api_key=1234&amp;username=test&amp;amount=1</code></li>
                        <li><strong>DEBIT</strong> (removes points): <code>https://api.botofthespecter.com/user-points/debit?api_key=1234&amp;username=test&amp;amount=1&amp;allow_negative=false</code></li>
                    </ul>
                    <p style="margin-top:0.5rem;margin-bottom:0;">
                        API Docs:
                        <a href="https://api.botofthespecter.com/docs#/Commands/credit_user_points" target="_blank" rel="noopener">credit_user_points</a>
                        |
                        <a href="https://api.botofthespecter.com/docs#/Commands/debit_user_points" target="_blank" rel="noopener">debit_user_points</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">6</div>
        <div class="sp-step-body">
            <h4>Customize Your Bot</h4>
            <h4>Custom Commands</h4>
            <p>Custom commands are one of the quickest ways to make your bot feel like part of your community. Start with simple commands like <code>!discord</code>, <code>!youtube</code>, <code>!instagram</code>, and <code>!business</code>.</p>
            <p>Create them on the <a href="https://dashboard.botofthespecter.com/custom_commands.php" target="_blank" rel="noopener">Custom Commands page</a>.</p>
            <ul>
                <li><strong>Community links:</strong> Discord, social accounts, merch, and support links</li>
                <li><strong>Channel info:</strong> Stream schedule, PC specs, and FAQs</li>
                <li><strong>Utility commands:</strong> Rules reminder, business email, event announcements</li>
            </ul>
            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <strong>Want to level up your commands?</strong> Use <strong>Custom Variables</strong> to add dynamic, personalized responses.
                    Note: Custom Variables only work in the response part of your command.
                </div>
            </div>

            <h4 style="margin-top:1.5rem;">Auto Messages</h4>
            <p>Auto messages keep important information visible without needing a moderator to post manually. Create them on the <a href="https://dashboard.botofthespecter.com/timed_messages.php" target="_blank" rel="noopener">Timed Messages page</a>.</p>
            <ul>
                <li><strong>Welcome and rules:</strong> Friendly reminders about chat rules and stream expectations</li>
                <li><strong>Useful links:</strong> Discord invite, social links, donation links, and command list</li>
                <li><strong>Stream engagement:</strong> Follow reminder, schedule updates, and community events</li>
            </ul>
            <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Timed messages are sent automatically while your channel is <strong>online</strong>, in three ways:
                    <ol style="margin-top:0.5rem;">
                        <li>After a set time interval</li>
                        <li>After a certain number of chat messages (line triggers)</li>
                        <li>After a certain number of chat messages combined with a time delay</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-comment-slash"></i> Bot Not Appearing in Chat</div>
            <div class="sp-card-body">
                <ul>
                    <li>Check that the bot is turned on in the dashboard</li>
                    <li>Verify the bot is added as a moderator</li>
                    <li>Try refreshing the dashboard and re-starting the bot</li>
                    <li>Check the bot status indicator — ONLINE/OFFLINE</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-terminal"></i> Commands Not Working</div>
            <div class="sp-card-body">
                <ul>
                    <li>Ensure you're using the correct prefix — the bot uses <code>!</code></li>
                    <li>Check if the command is enabled in settings</li>
                    <li>Verify the user has permission to use it</li>
                    <li>Some commands require premium features</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-right-to-bracket"></i> Login Issues</div>
            <div class="sp-card-body">
                <ul>
                    <li>Try logging out and back in</li>
                    <li>Clear your browser cache and cookies</li>
                    <li>All modern browsers are supported</li>
                    <li>Check if Twitch is experiencing issues at <a href="https://status.twitch.tv/" target="_blank" rel="noopener">status.twitch.tv</a></li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-shield-halved"></i> Permission Errors</div>
            <div class="sp-card-body">
                <ul>
                    <li>Ensure the bot is a moderator in your channel</li>
                    <li>Only the broadcaster can start and stop the bot — the moderator dashboard does not have this capability</li>
                    <li>Some features require VIP or subscriber status — check the user has the appropriate role</li>
                </ul>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Setup Complete!</h2>
    <div class="sp-alert sp-alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <div>
            <strong>Congratulations!</strong> Your Specter is now set up and running. Once started, the bot automatically joins your channel and remains available 24/7.<br><br>
            <strong>Next Steps:</strong>
            <ul style="margin-top:0.5rem;">
                <li>Explore the dashboard to discover all available features</li>
                <li>Customize commands and settings to match your stream style</li>
                <li>Check out the documentation for advanced features</li>
                <li>Join our Discord for community support and tips</li>
            </ul>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Premium Features</h2>
    <p>Some advanced features require a premium subscription:</p>
    <ul>
        <li><strong>AI Chat:</strong> Have conversations with an AI in your chat</li>
        <li><strong>Advanced Music:</strong> Use <code>!song</code> without connecting Spotify</li>
        <li><strong>Shared Bot Name (BotOfTheSpecter):</strong> The default shared bot username used across the platform</li>
        <li><strong>Custom Bot Name (Experimental/Coming Soon):</strong> Use your own bot username instead of BotOfTheSpecter</li>
    </ul>
    <p style="margin-top:1rem;">Support the developer on Twitch to unlock these features!</p>
    <p>
        <a href="https://twitch.tv/gfaUnDead" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
            <i class="fa-brands fa-twitch"></i> Support on Twitch
        </a>
    </p>

    <hr class="sp-divider">

    <h2>Need Help?</h2>
    <p>If you encounter issues during setup, don't hesitate to reach out:</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card" style="text-align:center;">
            <div class="sp-card-body">
                <i class="fa-brands fa-discord" style="font-size:2rem;color:var(--accent);margin-bottom:0.75rem;display:block;"></i>
                <h4 style="margin-bottom:0.5rem;">Discord Support</h4>
                <p>Join our community Discord for real-time help and support.</p>
                <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener" class="sp-btn sp-btn-primary sp-btn-sm">
                    <i class="fa-brands fa-discord"></i> Join Discord
                </a>
            </div>
        </div>
        <div class="sp-card" style="text-align:center;">
            <div class="sp-card-body">
                <i class="fa-solid fa-envelope" style="font-size:2rem;color:var(--blue);margin-bottom:0.75rem;display:block;"></i>
                <h4 style="margin-bottom:0.5rem;">Email Support</h4>
                <p>Send us a detailed message about your issue.</p>
                <a href="mailto:questions@botofthespecter.com" class="sp-btn sp-btn-secondary sp-btn-sm">
                    <i class="fa-solid fa-envelope"></i> Email Us
                </a>
            </div>
        </div>
        <div class="sp-card" style="text-align:center;">
            <div class="sp-card-body">
                <i class="fa-brands fa-twitch" style="font-size:2rem;color:var(--green);margin-bottom:0.75rem;display:block;"></i>
                <h4 style="margin-bottom:0.5rem;">Live Support</h4>
                <p>Catch us live on Twitch for immediate assistance.</p>
                <a href="https://twitch.tv/gfaUnDead" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary sp-btn-sm">
                    <i class="fa-brands fa-twitch"></i> Watch Live
                </a>
            </div>
        </div>
    </div>
</div>
<!-- ===================================================================
     TAB: COMMANDS (from API)
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="commands">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Command Reference</h1>
            <p style="margin:0;color:var(--text-secondary);">All commands use the <code>!</code> prefix. Some require moderator or broadcaster permissions.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="commands" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>
    <?php if (!empty($commands)): ?>
    <div class="sp-table-wrap">
        <table class="sp-table sp-table-no-hover">
            <thead>
                <tr>
                    <th style="width:18%;">Command</th>
                    <th style="width:35%;">Description</th>
                    <th>Syntax</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commands as $name => $info):
                    $aliases    = $info['aliases']     ?? [];
                    $syntaxRaw  = $info['syntax']      ?? null;
                    $isMod      = ($info['force_level'] ?? '') === 'mod';
                    $syntaxList = is_array($syntaxRaw) ? $syntaxRaw : ($syntaxRaw !== null ? [$syntaxRaw] : []);
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                            <code>!<?php echo htmlspecialchars($name); ?></code>
                            <?php if ($isMod): ?>
                            <span class="sp-badge sp-badge-muted" title="Requires moderator or broadcaster">Mod</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($aliases)): ?>
                        <div style="margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.3rem;">
                            <?php foreach ($aliases as $alias): ?>
                            <code style="font-size:0.78rem;color:var(--text-secondary);">!<?php echo htmlspecialchars($alias); ?></code>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($info['description'] ?? 'No description available'); ?></td>
                    <td>
                        <?php if (!empty($syntaxList)): ?>
                        <div class="sp-cmd-examples">
                            <?php foreach ($syntaxList as $example): ?>
                            <span class="sp-cmd-example"><?php echo htmlspecialchars($example); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="sp-alert sp-alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Command list unavailable — could not reach the commands API.</span>
    </div>
    <?php endif; ?>
    <div class="sp-alert sp-alert-info sp-mt-2">
        <i class="fa-solid fa-circle-info"></i>
        <span>Type <code>!commands</code> in your Twitch chat to see all active commands, including custom ones.</span>
    </div>
</div>
<!-- ===================================================================
     TAB: FAQ
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="faq">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Frequently Asked Questions</h1>
            <p style="margin:0;color:var(--text-secondary);">Common questions about BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="faq" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up the bot for the first time?</div>
        <div class="sp-faq-a">Follow the <a href="#" data-goto="setup">First Time Setup</a> guide — connect Twitch, mod the bot, start it from the dashboard, then configure points and custom commands.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What built-in commands are there for the bot?</div>
        <div class="sp-faq-a">BotOfTheSpecter comes with many built-in commands for moderation, entertainment, and utility. See the <a href="#" data-goto="commands">Command Reference</a> tab for the full list.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up audio monitoring in OBS?</div>
        <div class="sp-faq-a">Go to <strong>OBS → Settings → Audio → Monitoring Device</strong> and select your headset or speakers. Add your overlay as a Browser Source, enable <em>Control audio via OBS</em>, then set Audio Monitoring to <strong>Monitor and Output</strong> in Advanced Audio Properties.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">I'm having trouble with the bot. What should I do?</div>
        <div class="sp-faq-a">Start with the <a href="#" data-goto="troubleshooting">Troubleshooting</a> tab which covers the most common problems. If you're still stuck, <a href="/tickets.php?action=new">submit a support ticket</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I use custom variables in commands?</div>
        <div class="sp-faq-a">Custom commands and timed messages support dynamic variables that are replaced at runtime. Full variable reference docs will be expanded here as guides are rebuilt.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do Twitch Channel Points work with the bot?</div>
        <div class="sp-faq-a">BotOfTheSpecter integrates with Twitch Channel Points. Set up and customise channel point rewards and responses from the <strong>Channel Points</strong> section in your dashboard.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Can I request a new built-in command?</div>
        <div class="sp-faq-a">Yes! We're always looking for new commands to add. Let us know on our dev streams, via <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord</a>, or email <a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Where can I get more help?</div>
        <div class="sp-faq-a">Join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord server</a>, watch the developer stream at <a href="https://twitch.tv/gfaundead" target="_blank" rel="noopener">twitch.tv/gfaundead</a>, or <a href="/tickets.php?action=new">submit a support ticket</a>.</div>
    </div>
</div>

<!-- ===================================================================
     TAB: TROUBLESHOOTING
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="troubleshooting">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Troubleshooting Guide</h1>
            <p style="margin:0;color:var(--text-secondary);">Common issues and solutions for BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="troubleshooting" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Bot Not Connecting</h2>
    <p>If your bot isn't connecting to Twitch:</p>
    <ul>
        <li>Try stopping and starting the bot from the dashboard under <strong>Bot Control</strong>.</li>
    </ul>

    <h2>Commands Not Working</h2>
    <p>If commands aren't responding:</p>
    <ul>
        <li>Check that the command is enabled in the dashboard, or use <code>!enablecommand commandname</code> in chat.</li>
        <li>Commands always use the <code>!</code> prefix — verify the bot has <strong>Moderator</strong> permissions in your channel.</li>
        <li>Double-check the spelling of the command name both in chat and in the dashboard.</li>
    </ul>

    <h2>Sound Alerts / TTS / Walk-ons — Audio Issues</h2>
    <p>All Specter audio goes through the audio overlays. Make sure you're running the correct overlay, or use the <strong>All Audio</strong> overlay:</p>
    <p><code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code></p>
    <ul>
        <li>Check audio device settings in OBS.</li>
        <li>Ensure the OBS Browser Source volume is audible and <a href="#" data-goto="faq">audio monitoring is configured correctly</a>.</li>
        <li>If you hear an echo, set Audio Monitoring to <strong>Monitor Only (mute output)</strong> and test again — everyone's audio setup differs.</li>
        <li>Confirm you've entered the correct API key, found on your <strong>Specter Profile</strong> page in the dashboard.</li>
    </ul>

    <h2>Still Stuck?</h2>
    <p>If none of the above resolves your issue, <a href="/tickets.php?action=new">submit a support ticket</a> and include:</p>
    <ul>
        <li>A description of what you expected to happen vs. what actually happened.</li>
        <li>Any error messages you see (screenshots are helpful).</li>
        <li>Your Twitch username and approximate time the issue occurred.</li>
    </ul>
    <div class="sp-alert sp-alert-info sp-mt-2">
        <i class="fa-solid fa-circle-info"></i>
        <span>You can also check the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" rel="noopener">GitHub Issues</a> page or join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord server</a> for community support.</span>
    </div>
</div>
<?php
$content = ob_get_clean();
// Wire quick-link cards and inline data-goto links to tabs
$extraScripts = <<<'JS'
<script>
(function () {
    var panels, cards;

    function activateTab(id) {
        panels.forEach(function (p) {
            p.classList.toggle('active', p.dataset.panel === id);
        });
        cards.forEach(function (c) {
            c.classList.toggle('active', c.dataset.goto === id);
        });
        try { sessionStorage.setItem('sp_active_tab', id); } catch (e) {}
        try {
            var newHash = '#' + id;
            if (window.location.hash !== newHash) {
                history.replaceState(null, '', newHash);
            }
        } catch (e) {}
    }

    function resolveHash(hash) {
        if (!hash) return null;
        for (var i = 0; i < panels.length; i++) {
            if (panels[i].dataset.panel === hash) {
                return { tabId: hash };
            }
        }
        return null;
    }

    function applyHash(hash) {
        var resolved = resolveHash(hash);
        if (!resolved) return false;
        activateTab(resolved.tabId);
        return true;
    }

    document.addEventListener('DOMContentLoaded', function () {
        panels = document.querySelectorAll('.sp-tab-panel[data-panel]');
        cards  = document.querySelectorAll('.sp-doc-card[data-goto]');
        cards.forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                activateTab(card.dataset.goto);
                var first = document.querySelector('.sp-tab-panel.active');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        document.querySelectorAll('a[data-goto]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                activateTab(a.dataset.goto);
            });
        });
        var initHash = window.location.hash.replace('#', '');
        var stored   = '';
        try { stored = sessionStorage.getItem('sp_active_tab') || ''; } catch (e) {}
        if (!applyHash(initHash)) {
            activateTab(stored || 'setup');
        }
    });

    window.addEventListener('hashchange', function () {
        var hash = window.location.hash.replace('#', '');
        applyHash(hash);
    });
}());
</script>
JS;
include __DIR__ . '/layout.php';
?>
