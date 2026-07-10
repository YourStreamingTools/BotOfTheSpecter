<?php
// support/index.php
// ----------------------------------------------------------------
// Public documentation landing page.
// Static tabs: Setup, Features, Commands (API), FAQ, Troubleshooting.
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
    <a href="#" class="sp-doc-card" data-goto="features">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-star"></i></div>
        <div class="sp-doc-card-title">Main Features</div>
        <div class="sp-doc-card-desc">Commands, games, events, tracking, and integrations.</div>
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
     TAB: MAIN FEATURES
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="features">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Main Features</h1>
            <p style="margin:0;color:var(--text-secondary);">Chat tools, games, events, tracking, and third-party integrations.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="features" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Chat Protection &amp; Custom Commands</h2>

    <h3>Link Protection</h3>
    <p>URL blocking is disabled by default. You must enable it before the bot will remove links posted by non-moderators. Moderators &amp; Broadcasters are always exempt.</p>
    <ul>
        <li>When enabled, any viewer who posts a link will have their message deleted.</li>
        <li>Use <code>!permit @username</code> to give a viewer a 60-second window to post one link.</li>
        <li>Toggle URL blocking from <strong>Integrations → Specter Modules → Chat Protection</strong> in the dashboard.</li>
    </ul>

    <h3>Custom Commands</h3>
    <p>Create unlimited custom commands from the dashboard or directly in chat.</p>
    <ul>
        <li><code>!addcommand !name response</code> — creates a new command.</li>
        <li><code>!editcommand !name new response</code> — updates an existing command.</li>
        <li><code>!removecommand !name</code> — deletes a command.</li>
    </ul>
    <p>Responses support the full <a href="#" data-goto="variables">variables system</a> — including <code>(user)</code>, <code>(count)</code>, <code>(customapi.URL)</code>, math expressions, and more.</p>

    <h3>Timed Messages</h3>
    <p>Schedule messages to post automatically at a set interval. Manage them under <strong>Commands → Timed Messages</strong>.</p>
    <ul>
        <li>Set the interval in minutes and a minimum chat-line threshold so messages only fire when chat is active.</li>
        <li>Multiple timed messages can run simultaneously.</li>
        <li>Supports the same dynamic variables as custom commands.</li>
    </ul>

    <h3>Bot Modes</h3>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Mode</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><strong>Standard</strong></td><td>The main BotOfTheSpecter account joins your channel.</td></tr>
                <tr><td><strong>Custom Bot</strong></td><td>Use your own Twitch bot account — the bot acts with your bot's identity.</td></tr>
                <tr><td><strong>Self Mode</strong></td><td>The broadcaster's own account is used as the bot.</td></tr>
            </tbody>
        </table>
    </div>

    <hr class="sp-divider">

    <h2>Games &amp; Fun</h2>

    <h3>Points-Based Games</h3>
    <p>These games consume or award bot points. The points system must be enabled in <strong>Settings → Bot Points</strong>.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Command</th><th>Description</th></tr></thead>
            <tbody>
                <tr>
                    <td><code>!gamble &lt;type&gt; [choice] [amount]</code></td>
                    <td>Wagers bot points (defaults to 100 if omitted). Types: <code>coinflip</code> (50% to win double), <code>blackjack</code> (random 1-21, only 21 wins double), <code>roulette</code> (choose red/black for double or lose).<br><strong>Examples:</strong> <code>!gamble coinflip 100</code> | <code>!gamble roulette red 100</code></td>
                </tr>
                <tr>
                    <td><code>!slots</code></td>
                    <td>Spins a slot machine with guaranteed payouts. Symbols have values: =10, =15, =20, =25, =30, =35, ⭐=50. Winning spins pay out triple the symbol values; losing spins deduct the sum of symbol values.</td>
                </tr>
                <tr>
                    <td><code>!roulette</code></td>
                    <td>Russian roulette — survive or get shot. If shot, deducts 100 points as hospital penalty. No chat timeout applied.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3>Social &amp; Party Commands</h3>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Command</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>!rps &lt;rock|paper|scissors&gt;</code></td><td>Play rock-paper-scissors against the bot. Announces win/tie/lose with no points exchanged.</td></tr>
                <tr><td><code>!story &lt;5 words&gt;</code></td><td>AI generates a short story (3-5 sentences) seeded from your five words.<br><strong>Example:</strong> <code>!story dragon castle brave knight magic</code></td></tr>
                <tr><td><code>!joke</code></td><td>Fetches a random joke with category filtering based on your blacklist settings.</td></tr>
                <tr><td><code>!kill @user</code></td><td>Playfully "kills" a viewer or yourself with randomized messages from external API templates.</td></tr>
                <tr><td><code>!hug @user</code></td><td>Sends a virtual hug, increments hug counter, and announces the updated total. Self-targeting blocked.</td></tr>
                <tr><td><code>!highfive @user</code></td><td>High-fives a viewer, increments counter, and announces the total. Self-targeting blocked.</td></tr>
                <tr><td><code>!kiss @user</code></td><td>Sends a kiss, increments kiss counter, and announces the total. Self-targeting blocked.</td></tr>
                <tr><td><code>!puzzles</code></td><td>Reports the number of Tanggle puzzles completed by the channel.</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Raffle System</h3>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Command</th><th>Who</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>!createraffle &lt;name&gt; &lt;prize&gt; &lt;winners&gt; [weighted]</code></td><td>Moderator</td><td>Creates a scheduled raffle with specified name, prize, and number of winners. Optional weighted mode applies multipliers based on subscriber tier or VIP status.</td></tr>
                <tr><td><code>!startraffle [raffle_id]</code></td><td>Moderator</td><td>Starts a scheduled raffle. If no ID specified, starts the oldest scheduled raffle.</td></tr>
                <tr><td><code>!joinraffle</code></td><td>Viewer</td><td>Enters the active raffle. Respects settings for subscriber-only, follower-only, and moderator exclusions. Weighted raffles apply multipliers automatically.</td></tr>
                <tr><td><code>!leaveraffle</code></td><td>Viewer</td><td>Removes the viewer from the current raffle.</td></tr>
                <tr><td><code>!stopraffle</code></td><td>Moderator</td><td>Ends the running raffle without drawing winners.</td></tr>
                <tr><td><code>!drawraffle [raffle_id]</code></td><td>Moderator</td><td>Performs weighted random selection to pick winners, announces results in chat, and triggers overlay alerts.</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Lotto System</h3>
    <p>A Channel Points-based lotto system where viewers redeem Channel Point rewards to get their lotto numbers. Winning numbers are automatically generated when the stream goes live, and moderators draw prizes across multiple divisions.</p>
    <h4>Setup</h4>
    <ol>
        <li>Create a Channel Point reward on Twitch for lotto entry.</li>
        <li>In the Specter dashboard, sync your Channel Point rewards.</li>
        <li>Edit the lotto reward and add <code>(lotto)</code> to the custom message — this generates the viewer's lotto numbers.</li>
        <li>Viewers redeem the reward to receive their numbers and enter the draw.</li>
    </ol>
    <h4>Commands</h4>
    <ul>
        <li><code>!drawlotto</code> (Moderator) — compares entries to winning numbers and awards points by division: Division 1 (100,000), Division 2 (50,000), Division 3 (10,000), Division 4 (5,000), Division 5 (1,000), Division 6 (500).</li>
    </ul>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Note:</strong> Winning lotto numbers are automatically generated when your stream goes online. The <code>(lotto)</code> variable in Channel Point reward messages automatically generates and displays the viewer's lotto numbers when they redeem the reward.
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Events &amp; Alerts</h2>
    <p>BotOfTheSpecter reacts to Twitch events automatically. All response messages are customisable from <strong>Settings → Alerts</strong> in the dashboard and support the <a href="#" data-goto="variables">variables system</a>.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Event</th><th>What happens</th></tr></thead>
            <tbody>
                <tr><td><strong>New Follow</strong></td><td>Posts a customisable thank-you message and triggers an overlay alert.</td></tr>
                <tr><td><strong>Subscription</strong></td><td>Posts a sub message. Supports <code>(tier)</code> and <code>(months)</code> variables.</td></tr>
                <tr><td><strong>Re-subscription</strong></td><td>Handles resub messages with streak and total month data.</td></tr>
                <tr><td><strong>Gift Subscriptions</strong></td><td>Single and bulk gifts supported. Supports <code>(count)</code>, <code>(total-gifted)</code>, and <code>(gifter)</code>.</td></tr>
                <tr><td><strong>Bits / Cheer</strong></td><td>Thanks the viewer. Supports <code>(bits)</code> and <code>(total-bits)</code>.</td></tr>
                <tr><td><strong>Raid</strong></td><td>Welcomes the raider, triggers an automatic Twitch shoutout. Supports <code>(viewers)</code>.</td></tr>
                <tr><td><strong>Channel Points Redemption</strong></td><td>Executes a custom response per reward — supports TTS, raffle, fortune, VIP grants, and API calls.</td></tr>
                <tr><td><strong>Hype Train</strong></td><td>Announces start, level-ups, and completion. Supports <code>(level)</code>.</td></tr>
                <tr><td><strong>Ad Break</strong></td><td>Sends an upcoming ad warning, activates an AI chat companion during the break, then resumes normal mode.</td></tr>
                <tr><td><strong>Poll / Prediction</strong></td><td>Announces progress, results, and outcomes in chat.</td></tr>
                <tr><td><strong>Shoutout Received</strong></td><td>Announces when another streamer gives the channel a Twitch shoutout.</td></tr>
                <tr><td><strong>Stream Online / Offline</strong></td><td>Starts/stops timed messages, watch-time tracking, and logs stream session data.</td></tr>
                <tr><td><strong>Chat Join / Welcome</strong></td><td>Welcomes returning and new viewers by name (bots are ignored automatically).</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Overlay Alerts</h3>
    <p>Events are forwarded to the WebSocket server and displayed through OBS browser source overlays at:<br><code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code></p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Alert Type</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><strong>Sound Alerts</strong></td><td>Custom audio clips triggered by chat commands or Channel Points.</td></tr>
                <tr><td><strong>Walk-ons</strong></td><td>Personalised audio/video that plays when a specific viewer joins chat.</td></tr>
                <tr><td><strong>Text-to-Speech (TTS)</strong></td><td>AI voice reads viewer messages aloud through the OBS browser source.</td></tr>
                <tr><td><strong>Video Alerts</strong></td><td>Custom video clips triggered by events or commands.</td></tr>
            </tbody>
        </table>
    </div>

    <hr class="sp-divider">

    <h2>Tracking &amp; Stats</h2>

    <h3>Bot Points</h3>
    <p>A built-in loyalty point system earned by watching and participating in the stream. Configure the name, earn rate, and icon under <strong>Settings → Bot Points</strong>.</p>
    <ul>
        <li><code>!points</code> — check your balance.</li>
        <li><code>!addpoints</code> / <code>!removepoints</code> — moderator manual adjustment.</li>
        <li>Spent playing <code>!gamble</code>, <code>!slots</code>, and <code>!roulette</code>.</li>
    </ul>

    <h3>Watch Time</h3>
    <p>Tracks total viewing time per viewer across all streams. Viewers check their own with <code>!watchtime</code>.</p>

    <h3>Lurk Tracking</h3>
    <ul>
        <li><code>!lurk</code> — starts tracking and announces the viewer is lurking.</li>
        <li><code>!unlurk</code> — ends tracking and announces the viewer is back.</li>
        <li><code>!lurking</code> — shows how long the user has been lurking this session.</li>
        <li><code>!lurklead</code> — shows who has the most accumulated all-time lurk time.</li>
        <li><code>!userslurking</code> — shows how many viewers are currently lurking.</li>
    </ul>

    <h3>Death Counter</h3>
    <p>Tracks in-game deaths for the current session.</p>
    <ul>
        <li><code>!deaths</code> — shows the count (anyone).</li>
        <li><code>!deathadd</code> / <code>!death+</code> — adds to the counter (moderator).</li>
        <li><code>!deathremove</code> / <code>!death-</code> — removes from the counter (moderator).</li>
    </ul>

    <h3>Typo Counter</h3>
    <ul>
        <li><code>!typo @user</code> — records a typo for a user (moderator).</li>
        <li><code>!typos [@user]</code> — shows the typo count.</li>
        <li><code>!edittypos @user &lt;n&gt;</code> — sets the count to a specific number (moderator).</li>
        <li><code>!removetypos @user</code> — decrements or resets (moderator).</li>
    </ul>

    <h3>Quotes</h3>
    <ul>
        <li><code>!quote [number]</code> — shows a random quote or one by number.</li>
        <li><code>!quoteadd &lt;text&gt;</code> — saves a new quote (moderator).</li>
        <li><code>!removequote &lt;number&gt;</code> — deletes a quote (moderator).</li>
    </ul>

    <h3>Bits Tracking</h3>
    <ul>
        <li><code>!mybits</code> — shows the user's total all-time bits cheered in the channel.</li>
        <li><code>!cheerleader</code> — shows the all-time top bit cheerer.</li>
    </ul>

    <h3>Follow Age</h3>
    <p><code>!followage [@user]</code> queries the Twitch API to show exactly how long a viewer has been following the channel.</p>

    <h3>Subathon Timer</h3>
    <p>A countdown timer that extends with subs, bits, and Channel Points redemptions to drive engagement during subathon events. Check remaining time with <code>!subathon</code>. Configure time additions per sub tier from the dashboard.</p>

    <hr class="sp-divider">

    <h2>Integrations</h2>
    <p>BotOfTheSpecter connects to a wide range of third-party services to enhance your stream. All integrations are configured from <strong>Integrations</strong> in the dashboard.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead><tr><th>Service</th><th>What the bot does</th></tr></thead>
            <tbody>
                <tr><td><strong><i class="fa-brands fa-spotify" style="color:#1DB954;"></i> Spotify</strong></td><td>Displays the current track (<code>!song</code>), accepts viewer song requests (<code>!sr</code>), skips tracks (<code>!skipsong</code>), and shows the queue (<code>!songqueue</code>).</td></tr>
                <tr><td><strong><i class="fa-brands fa-steam"></i> Steam</strong></td><td>Looks up Steam games by name via the Steam API — shows store descriptions, prices, and app IDs (<code>!steam</code>).</td></tr>
                <tr><td><strong><i class="fa-solid fa-video"></i> OBS WebSocket</strong></td><td>Controls OBS scenes and sources directly from chat via the <code>!obs</code> moderator command.</td></tr>
                <tr><td><strong><i class="fa-solid fa-heart-pulse" style="color:#e74c3c;"></i> HypeRate</strong></td><td>Connects via WebSocket to show the streamer's live BPM in chat with <code>!heartrate</code>.</td></tr>
                <tr><td><strong><i class="fa-solid fa-bolt" style="color:#f5a623;"></i> StreamElements</strong></td><td>Receives tip and merch alert events in real time via Socket.IO and forwards them to overlays.</td></tr>
                <tr><td><strong><i class="fa-solid fa-circle-dollar-to-slot" style="color:#31c3a2;"></i> StreamLabs</strong></td><td>Receives donation and alert events and broadcasts them to the WebSocket overlay server.</td></tr>
                <tr><td><strong><i class="fa-solid fa-robot" style="color:#3ecf8e;"></i> OpenAI (GPT)</strong></td><td>Powers AI responses in the home channel, generates AI stories (<code>!story</code>), and runs an AI chat companion during ad breaks with persistent chat history.</td></tr>
                <tr><td><strong><i class="fa-solid fa-cloud-sun" style="color:#4aa3f0;"></i> OpenWeatherMap</strong></td><td>Fetches live weather for any location via <code>!weather &lt;city&gt;</code>.</td></tr>
                <tr><td><strong><i class="fa-solid fa-user-tag"></i> Pronouns (alejo.io)</strong></td><td>Looks up and caches viewer-set pronouns, using them naturally when the bot mentions a viewer by name.</td></tr>
                <tr><td><strong><i class="fa-brands fa-discord" style="color:#5865F2;"></i> Discord</strong></td><td>A companion Discord bot handles stream announcements, reaction roles, support tickets, voice music playback, and Twitch account linking.</td></tr>
                <tr><td><strong><i class="fa-solid fa-microphone"></i> Text-to-Speech (TTS)</strong></td><td>Converts viewer messages to speech using AI voices (Alloy, Ash, Ballad, Coral, Echo, Fable, Nova, Onyx, Sage, Shimmer, Verse) through an OBS browser source overlay.</td></tr>
                <tr><td><strong><i class="fa-solid fa-ruler-combined" style="color:#f0a500;"></i> Unit Conversion</strong></td><td>Powered by the Pint unit library — <code>!convert</code> handles length, weight, temperature, volume, speed, and more.</td></tr>
            </tbody>
        </table>
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
        <div class="sp-faq-q">What main features does the bot include?</div>
        <div class="sp-faq-a">See the <a href="#" data-goto="features">Main Features</a> guide for chat protection, custom commands, games, events, tracking, and integrations.</div>
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
