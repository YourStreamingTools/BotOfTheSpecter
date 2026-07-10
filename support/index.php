<?php
// support/index.php
// ----------------------------------------------------------------
// Public documentation landing page.
// Static tabs: Setup, Features, Spotify, TTS, OBS Audio, Variables, Module Variables, Channel Points, Custom API, Run Yourself, Commands, FAQ, Troubleshooting.
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
    <a href="#" class="sp-doc-card" data-goto="spotify">
        <div class="sp-doc-card-icon"><i class="fa-brands fa-spotify"></i></div>
        <div class="sp-doc-card-title">Spotify Setup</div>
        <div class="sp-doc-card-desc">Create your own Spotify app and link it.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="tts">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-microphone"></i></div>
        <div class="sp-doc-card-title">Text-to-Speech</div>
        <div class="sp-doc-card-desc">Voices, Channel Points TTS, and setup tips.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="obs-audio">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-headphones"></i></div>
        <div class="sp-doc-card-title">OBS Audio Monitoring</div>
        <div class="sp-doc-card-desc">Hear overlay alerts, TTS, and walk-ons in OBS.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="variables">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-code"></i></div>
        <div class="sp-doc-card-title">Custom Variables</div>
        <div class="sp-doc-card-desc">Dynamic tokens for commands, timers, and rewards.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="module-variables">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-puzzle-piece"></i></div>
        <div class="sp-doc-card-title">Module Variables</div>
        <div class="sp-doc-card-desc">Event tokens for welcomes, ads, and chat alerts.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="twitch-channel-points">
        <div class="sp-doc-card-icon"><i class="fa-brands fa-twitch"></i></div>
        <div class="sp-doc-card-title">Channel Points</div>
        <div class="sp-doc-card-desc">Sync rewards and automate redemption responses.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="api">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-satellite-dish"></i></div>
        <div class="sp-doc-card-title">Custom API</div>
        <div class="sp-doc-card-desc">Auth, endpoints, and code samples for integrations.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="run-yourself">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-server"></i></div>
        <div class="sp-doc-card-title">Run Yourself</div>
        <div class="sp-doc-card-desc">Self-host Specter on your own servers.</div>
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
     TAB: SPOTIFY SETUP
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="spotify">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Setting Up Your Own Spotify Application</h1>
            <p style="margin:0;color:var(--text-secondary);">Create a personal Spotify Developer app and link it to BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="spotify" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Important: Spotify Integration Changes (Effective March 9, 2026)</strong><br>
            We apologise for the inconvenience. Due to Spotify's updated Developer Policy, our platform Spotify client is no longer able to accept new users — Development Mode apps are now capped at 5 authorized users. If you were previously linked via our platform account and need to reconnect, your slot is still reserved. For new users, you will need to create your own Spotify app to use Spotify integration — it takes only a few minutes and will be solely used for your channel. Note: your Spotify developer account must have Spotify Premium to use Development Mode.
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Create a Spotify Developer Account</h4>
            <ol>
                <li>Go to the <a href="https://developer.spotify.com/" target="_blank" rel="noopener">Spotify Developer Dashboard</a>.</li>
                <li>Log in with your Spotify account (or create one if you don't have it).</li>
                <li>Accept the terms and conditions.</li>
            </ol>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Create Your Spotify Application</h4>
            <ol>
                <li>Click on <strong>Create app</strong>.</li>
                <li>Fill in the application details:
                    <ul>
                        <li><strong>App name:</strong> <code>Specter-[Your Username]</code> (e.g., <code>Specter-JohnDoe</code>)</li>
                        <li><strong>App description:</strong> Twitch bot integration for Spotify</li>
                        <li><strong>Website:</strong> <code>https://dashboard.botofthespecter.com</code></li>
                        <li><strong>Redirect URI:</strong> <code>https://dashboard.botofthespecter.com/spotifylink.php</code></li>
                    </ul>
                </li>
                <li>Check the box for <strong>Web API</strong> under "Which API/SDKs are you planning to use?"</li>
                <li>Check the agreement boxes and click <strong>Save</strong>.</li>
            </ol>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Get Your App Credentials</h4>
            <ol>
                <li>In your app dashboard, you'll see your <strong>Client ID</strong> displayed.</li>
                <li>Copy the <strong>Client ID</strong> (a 32-character string).</li>
                <li>Click <strong>View client secret</strong> to reveal and copy the <strong>Client Secret</strong>.</li>
            </ol>
            <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Keep your Client Secret secure</strong> — never share it publicly or commit it to version control.
                </div>
            </div>
            <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
                <i class="fa-solid fa-shield-halved"></i>
                <div>
                    <strong>Security Note:</strong> Your credentials are stored securely in our encrypted database and are only used for your bot's Spotify integration.
                </div>
            </div>
        </div>
    </div>

    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure BotOfTheSpecter</h4>
            <ol>
                <li>Go to your <a href="https://dashboard.botofthespecter.com/spotifylink.php" target="_blank" rel="noopener">Spotify Link page</a>.</li>
                <li>Check the <strong>Enable Own Client</strong> box.</li>
                <li>Enter your <strong>Client ID</strong> and <strong>Client Secret</strong> in the fields that appear.</li>
                <li>Click <strong>Save Credentials</strong>.</li>
                <li>Click the <strong>Link Spotify Account</strong> button to authorize with your new app.</li>
            </ol>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting Common Issues</h2>
    <ul>
        <li>
            <strong>Redirect URI mismatch:</strong> Ensure the Redirect URI in your Spotify app matches exactly:<br>
            <code>https://dashboard.botofthespecter.com/spotifylink.php</code>
        </li>
        <li>
            <strong>Permissions:</strong> The required scopes (<code>user-read-playback-state</code>, <code>user-modify-playback-state</code>, <code>user-read-currently-playing</code>) are automatically requested during authorization.
        </li>
        <li>
            <strong>Rate limits:</strong> Spotify has rate limits — if you exceed them, wait a moment before trying again.
        </li>
        <li>
            <strong>Authorization fails:</strong> Double-check that your Client ID and Client Secret are correct and that the Redirect URI matches exactly.
        </li>
    </ul>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            Using your own Spotify app gives you more control and potentially higher rate limits, but requires you to manage the app yourself.
        </div>
    </div>
</div>
<!-- ===================================================================
     TAB: TEXT-TO-SPEECH (TTS)
=================================================================== -->
<?php
$ttsVoices = [
    'alloy'   => 'Clear, crisp, and professional',
    'ash'     => 'Warm and friendly',
    'ballad'  => 'Melodic and expressive',
    'coral'   => 'Energetic and bright',
    'echo'    => 'Deep and resonant',
    'fable'   => 'Storyteller voice',
    'nova'    => 'Fast-paced and dynamic',
    'onyx'    => 'Smooth and sophisticated',
    'sage'    => 'Thoughtful and calm',
    'shimmer' => 'Gentle and uplifting',
    'verse'   => 'Rhythmic and poetic',
];
?>
<div class="sp-tab-panel sp-doc-content" data-panel="tts">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Text-to-Speech (TTS) Module</h1>
            <p style="margin:0;color:var(--text-secondary);">Read chat and Channel Point messages aloud through your OBS overlay.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="tts" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What is TTS &amp; How to Set It Up</h2>
    <p>The Text-to-Speech (TTS) module allows BotOfTheSpecter to read messages aloud in your stream. You can customize which voice is used, and the TTS will play through your audio overlay. This is perfect for announcements, alerts, and enhancing viewer engagement.</p>

    <h3>Setting Up TTS</h3>
    <ol>
        <li>Navigate to the <strong>TTS Settings</strong> section in the BotOfTheSpecter dashboard.</li>
        <li>Choose your preferred voice from the available options (see the Available Voices section below).</li>
        <li>Set up your audio overlay to hear TTS output — see the <a href="#" data-goto="obs-audio">OBS Audio Monitoring</a> guide.</li>
        <li>Test your setup with a sample message.</li>
    </ol>

    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            All TTS audio is played through your configured audio overlay. Make sure you have the correct overlay URL in your OBS browser source and audio monitoring enabled.
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Using TTS with Channel Points</h3>
    <p>TTS is triggered through Twitch Channel Points redemptions. Viewers can redeem a Channel Point reward to have a message read aloud using the voice you've selected in TTS settings.</p>

    <hr class="sp-divider">

    <h2>Available Voices</h2>
    <p>Click the play button next to each voice to hear a sample:</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-top:1rem;">
        <?php foreach ($ttsVoices as $voiceKey => $voiceDesc):
            $voiceLabel = ucfirst($voiceKey);
        ?>
        <div class="sp-card">
            <div class="sp-card-header"><?php echo htmlspecialchars($voiceLabel); ?></div>
            <div class="sp-card-body">
                <p style="color:var(--text-secondary);font-size:0.9rem;"><?php echo htmlspecialchars($voiceDesc); ?></p>
                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm voice-play-button" style="margin-top:0.75rem;"
                        data-voice="<?php echo htmlspecialchars($voiceKey); ?>">
                    <i class="fa-solid fa-play"></i> Play Sample
                </button>
                <audio id="audio-<?php echo htmlspecialchars($voiceKey); ?>" preload="none" style="display:none;">
                    <source src="https://cdn.botofthespecter.com/help/tts/<?php echo htmlspecialchars($voiceKey); ?>_sample.mp3" type="audio/mpeg">
                    <source src="https://cdn.botofthespecter.com/help/tts/<?php echo htmlspecialchars($voiceKey); ?>_sample.wav" type="audio/wav">
                </audio>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting TTS</h2>
    <ul>
        <li><strong>No audio output:</strong> Verify that your audio overlay is correctly configured in OBS and that audio monitoring is enabled. See the <a href="#" data-goto="obs-audio">OBS Audio Monitoring</a> guide.</li>
        <li><strong>Wrong voice playing:</strong> Check that you've saved the correct voice in TTS settings on the dashboard.</li>
        <li><strong>Audio too quiet or too loud:</strong> Adjust the volume slider on the audio overlay source in OBS.</li>
        <li><strong>TTS not responding:</strong> Ensure the TTS module is enabled on the dashboard and the bot has proper channel permissions.</li>
    </ul>
</div>
<script>
(function () {
    var currentVoice = null;
    var panel = document.querySelector('.sp-tab-panel[data-panel="tts"]');
    if (!panel) return;

    panel.querySelectorAll('.voice-play-button[data-voice]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var voiceName = btn.getAttribute('data-voice');
            var audio = document.getElementById('audio-' + voiceName);
            if (!audio) return;

            if (currentVoice === voiceName && !audio.paused) {
                audio.pause();
                audio.currentTime = 0;
                btn.innerHTML = '<i class="fa-solid fa-play"></i> Play Sample';
                currentVoice = null;
                return;
            }

            panel.querySelectorAll('audio').forEach(function (a) {
                a.pause();
                a.currentTime = 0;
            });
            panel.querySelectorAll('.voice-play-button').forEach(function (b) {
                b.innerHTML = '<i class="fa-solid fa-play"></i> Play Sample';
            });

            audio.play().catch(function () {
                alert('Could not play audio sample. The file may not be available.');
            });
            btn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop';
            currentVoice = voiceName;
            audio.onended = function () {
                btn.innerHTML = '<i class="fa-solid fa-play"></i> Play Sample';
                currentVoice = null;
            };
        });
    });
})();
</script>
<!-- ===================================================================
     TAB: OBS AUDIO MONITORING
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="obs-audio">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">OBS Audio Monitoring Setup</h1>
            <p style="margin:0;color:var(--text-secondary);">Hear overlay alerts, TTS, and walk-ons through OBS during your stream.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="obs-audio" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Why Audio Monitoring?</h2>
    <p>Audio monitoring lets you hear audio from your overlays — sound alerts, TTS, and walk-ons — directly through OBS, ensuring they play correctly during your stream.</p>
    <div class="sp-alert sp-alert-info" style="margin:1rem 0;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Before you begin:</strong> Have your overlay URL ready from your Specter Profile page. The format is:<br>
            <code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code>
        </div>
    </div>

    <h2>Part 1: Configure OBS Audio Settings</h2>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Open OBS Studio</h4>
            <p>Launch OBS on your computer.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Go to Settings</h4>
            <p>Click the <strong>Settings</strong> button in the bottom-right corner of the OBS window.</p>
            <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Settings_Button.png" alt="OBS Settings Button" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Select the Audio Tab</h4>
            <p>In the Settings window, click the <strong>Audio</strong> tab.</p>
            <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Access_Audio_Settings.png" alt="Access Audio Settings in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure Monitoring Device</h4>
            <p>Under <strong>Monitoring Device</strong>, select your desired audio output (e.g., headphones or speakers). Choose <em>Default</em> or your primary device.</p>
            <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring/Configure_Monitoring_Device.png" alt="Configure Monitoring Device in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Part 2: Add the Overlay Browser Source</h2>
    <div class="sp-step">
        <div class="sp-step-num">5</div>
        <div class="sp-step-body">
            <h4>Add a Browser Source</h4>
            <ol style="margin-top:0.75rem;padding-left:1.25rem;">
                <li>In the <strong>Sources</strong> panel, click <strong>+</strong> and select <strong>Browser</strong>.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source.png" alt="Add New Source in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;"><br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Browser.png" alt="Select Browser Source in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Select <strong>Create new</strong> and give it a name (e.g., <em>Specter Overlay</em>). Ensure <strong>Make source visible</strong> is checked and click <strong>OK</strong>.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Browser_Name_Setting.png" alt="Create New Browser Source in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">In the Properties window, paste your overlay URL into the <strong>URL</strong> field:<br>
                    <code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code><br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Add_New_Source_Broswer_Properties_Window.png" alt="Browser Source Properties Window in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Check <strong>Control audio via OBS</strong>, clear any text in <strong>Custom CSS</strong>, then click <strong>OK</strong>.</li>
            </ol>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">6</div>
        <div class="sp-step-body">
            <h4>Configure Audio Monitoring for the Browser Source</h4>
            <ol style="margin-top:0.75rem;padding-left:1.25rem;">
                <li>The browser source will appear in the <strong>Audio Mixer</strong> at the bottom of OBS.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Broswer_Source_Audio_Mixer.png" alt="Browser Source in OBS Audio Mixer" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Click the <strong>⋯</strong> (three dots) next to the speaker icon for the browser source.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Broswer_Source_Audio_Mixer_Advanced_Audio_Properties.png" alt="Advanced Audio Properties Menu in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Click <strong>Advanced Audio Properties</strong>.</li>
                <li style="margin-top:0.75rem;">Set the <strong>Audio Monitoring</strong> dropdown to <strong>Monitor and Output</strong>.<br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Advanced_Audio_Properties_Window.png" alt="Advanced Audio Properties Window in OBS" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;"><br>
                    <img src="https://cdn.botofthespecter.com/help/OBS_Audio_Monitoring_and_Output/Advanced_Audio_Properties_Window_Saved.png" alt="Monitor and Output Selected" style="max-width:100%;height:auto;margin-top:0.5rem;border-radius:4px;">
                </li>
                <li style="margin-top:0.75rem;">Click <strong>Close</strong>. Your overlay audio is now configured.</li>
            </ol>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Hearing an echo on sound alerts?</strong> Set Audio Monitoring to <strong>Monitor Only (mute output)</strong> instead of "Monitor and Output". Everyone's audio/sound setup is different — try this first before anything else.
        </div>
    </div>
    <div style="display:grid;gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header">No audio heard at all</div>
            <div class="sp-card-body">Check that your monitoring device is correctly selected in OBS <strong>Settings → Audio → Monitoring Device</strong>.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Echo on stream or sound alerts</div>
            <div class="sp-card-body">In Advanced Audio Properties for the browser source, change <strong>Audio Monitoring</strong> to <strong>Monitor Only (mute output)</strong>.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Overlay URL not working</div>
            <div class="sp-card-body">Make sure the URL contains the correct API key from your Specter Profile page:<br><code>https://overlay.botofthespecter.com/alert.php?code=YOUR_API_KEY</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Browser source not monitoring audio</div>
            <div class="sp-card-body">Confirm that <strong>Control audio via OBS</strong> is checked in the browser source Properties, and that Advanced Audio Properties is set to Monitor and Output.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header">Source appears muted</div>
            <div class="sp-card-body">Check the OBS Audio Mixer for the browser source and confirm the speaker icon is not muted.</div>
        </div>
    </div>
</div>
<!-- ===================================================================
     TAB: CUSTOM VARIABLES
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="variables">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Custom Variables</h1>
            <p style="margin:0;color:var(--text-secondary);">Dynamic tokens for custom commands, timed messages, and channel point rewards.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="variables" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Custom Variables Reference</h2>
    <div class="sp-alert sp-alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            This is the central reference for variables used in <strong>custom commands, timed messages, and channel point rewards</strong>.
            Most variables work across all three systems unless noted otherwise.
            Variables marked <span style="color:#c813e0;font-weight:600;">purple</span> are beta-only and currently in testing.
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin-top:1.25rem;">

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(count)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Increments and displays the number of times this command has been used.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>This command has been used (count) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>This command has been used 42 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(usercount)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Displays how many times <em>this specific user</em> has used this command.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) has used this command (usercount) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername has used this command 15 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code> / <code style="color:#3273dc;">(author)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;"><code>(user)</code> displays the username of the person who triggered the command, or the @mentioned user if one was provided. <code>(author)</code> always refers to the person who typed the command, regardless of any @mention.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Hey (user), welcome to the stream! (author) says hi!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Hey @someone, welcome to the stream! streamername says hi!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(game)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Displays the current game/category being streamed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>We're currently playing (game)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>We're currently playing Just Chatting!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(daysuntil.DATE)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Calculates the number of days until a specific date. Format: <code>YYYY-MM-DD</code>. Automatically rolls over to the next year if the date has already passed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Only (daysuntil.2026-12-25) days until Christmas!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Only 42 days until Christmas!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(timeuntil.DATE)</code> / <code style="color:#3273dc;">(timeuntil.DATE-HH-MM)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Calculates the time remaining until a specific date or date and time. Use <code>YYYY-MM-DD</code> for date-only, or <code>YYYY-MM-DD-HH-MM</code> to include a specific time.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(timeuntil.2026-12-25)</code><br>
                    <code>(timeuntil.2026-06-15-18-00)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>The event starts in 42 days, 12 hours, 30 minutes!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(math.expression)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Evaluates a math expression. Supports <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>, and parentheses.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>2 + 2 = (math.2+2)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>2 + 2 = 4</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(random.percent)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Generates a random percentage between 0% and 100%. Use <code>(random.percent.X-Y)</code> for a custom range.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(user) is (random.percent) cool today!</code><br>
                    <code>Your luck today is (random.percent.50-100)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername is 73% cool today!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(random.number)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Generates a random number between 0 and 100. Use <code>(random.number.X-Y)</code> for a custom range.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>Your roll: (random.number)</code><br>
                    <code>You dealt (random.number.1-20) damage!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>You dealt 14 damage!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(random.pick.item1.item2.item3)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Randomly selects one option from a dot-separated inline list.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) should play (random.pick.Minecraft.Fortnite.Valorant) next!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername should play Minecraft next!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(command.name)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">References another custom command and sends its response as an additional message.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Here's some info: (command.socials)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> Response from the <code>socials</code> command</p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(customapi.URL)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Fetches a URL and inserts the plain text response. Use <code>(customapi.json.URL)</code> to fetch JSON into temporary context (silent — does not print to chat) for use with <code>(json.*)</code> variables.</p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong><br>
                    <code>(customapi.https://api.example.com/joke)</code> — raw response<br>
                    <code>(customapi.json.https://api.example.com/data)</code> — JSON context<br>
                    <code>(customapi.https://yourapi.com/user.php?user=(user))</code> — with variable in URL</p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(call.commandname)</code></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Calls and executes a built-in bot command by name.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(call.shoutout)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> Output from the built-in <code>shoutout</code> command</p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(arg)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">The argument the user passed after the command name. Empty string if no argument was given.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(author) gives (arg) a big hug!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat</strong> (user types <code>!hug @someone</code>): <code>streamername gives @someone a big hug!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(pronouns)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Displays the user's full pronoun set, fetched from <a href="https://pronouns.alejo.io" target="_blank" rel="noopener">pronouns.alejo.io</a>. Defaults to <code>they/them</code> if not set.</p>
                <p style="margin-top:0.5rem;">Use <code style="color:#c813e0;">(pronouns.they)</code> for just the subject pronoun (e.g. <code>she</code>, <code>he</code>, <code>they</code>) and <code style="color:#c813e0;">(pronouns.them)</code> for just the object pronoun (e.g. <code>her</code>, <code>him</code>, <code>them</code>).</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) and (pronouns) are here! We hope (pronouns.they) enjoy the stream! Give (pronouns.them) a warm welcome!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername and she/her are here! We hope she enjoy the stream! Give her a warm welcome!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(random.pick)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Picks a random item from the pre-configured options list stored in the database for that command. No inline items needed.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Today's winner is (random.pick)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Today's winner is Option2!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(random.pick.list.commandname)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge">Custom Commands, Timed Messages &amp; Channel Points</span>
                <p style="margin-top:0.5rem;">Picks a random item from the options list stored for a <em>different</em> named command. Useful for sharing a single list across multiple commands or rewards.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>The chosen game is (random.pick.list.gamelist)!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>The chosen game is Minecraft!</code></p>
            </div>
        </div>

    </div>

    <hr class="sp-divider">

    <h2>Channel Point Reward Variables</h2>
    <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Tip:</strong> All shared variables from the section above — including <code>(count)</code>, <code>(user)</code>, <code>(author)</code>, <code>(game)</code>, <code>(random.*)</code>, <code>(math.*)</code>, <code>(customapi.*)</code>, <code>(json.*)</code>, <code>(if.*)</code>, <code>(daysuntil.*)</code>, <code>(timeuntil.*)</code>, <code>(pronouns)</code>, <code>(command.*)</code>, <code>(call.*)</code>, and <code>(arg)</code> — also work in channel point reward messages.
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(message)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The text input the user provided when redeeming the reward.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) says: (message)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername says: hello world</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(usercount)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Displays how many times this specific user has redeemed <em>this reward</em>. Uses a separate counter from the command version of <code>(usercount)</code>.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) has redeemed this reward (usercount) times!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername has redeemed this reward 5 times!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(userstreak)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">The current consecutive redemption streak for this user. Resets to 1 when a different user redeems the reward.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) is on a (userstreak) streak!</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername is on a 3 streak!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(track)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Silently increments the reward's usage counter. Does not display anything in chat.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Thanks for redeeming! (track)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Thanks for redeeming!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(tts)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Triggers text-to-speech using the user's input text. Does not display anything in chat itself.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) triggered TTS! (tts)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername triggered TTS!</code> <em>(user's input is sent to TTS)</em></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(tts.message)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">After all variables are processed, sends the final composed message to both chat <em>and</em> text-to-speech simultaneously.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) says: (message) (tts.message)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername says: hello world</code> <em>(also sent to TTS)</em></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(lotto)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Generates a set of lottery numbers for the redeeming user.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user), your lucky numbers are: (lotto)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername, your lucky numbers are: 7, 14, 22, 35, 42</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(fortune)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Tells the redeeming user's fortune. The user's name is automatically prepended if not already present in the message.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(fortune)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername, you will find great success today</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(vip)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Grants the redeeming user VIP status via the Twitch API. Does not output any text itself.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Congrats (user), you are now a VIP! (vip)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>Congrats streamername, you are now a VIP!</code></p>
            </div>
        </div>

        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(vip.today)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <span class="sp-badge" style="background:#fdf4ff;color:#7e22ce;">Channel Points Only</span>
                <p style="margin-top:0.5rem;">Same as <code style="color:#c813e0;">(vip)</code>, but also records the user so that VIP status is automatically removed when the stream ends.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>(user) is VIP for today's stream! (vip.today)</code></p>
                <p style="margin-top:0.5rem;"><strong>In chat:</strong> <code>streamername is VIP for today's stream!</code></p>
            </div>
        </div>

    </div>
</div>
<!-- ===================================================================
     TAB: MODULE VARIABLES
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="module-variables">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Module Variables</h1>
            <p style="margin:0;color:var(--text-secondary);">Event-specific tokens for welcome messages, ad notices, and Twitch chat alerts.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="module-variables" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>Overview &amp; General Variables</h2>
    <p>These variables are event-specific and are available for use in <strong>Welcome Messages</strong>, <strong>Ad Notices</strong>, and <strong>Twitch Chat Alerts</strong>. They are separate from the custom command/timed message variables.</p>
    <p>In addition to the event-specific variables listed here, <strong>all shared variables from the <a href="#" data-goto="variables">Custom Variables Reference</a> page</strong> also work in these alerts — including <code>(count)</code>, <code>(random.*)</code>, <code>(math.*)</code>, <code>(game)</code>, <code>(customapi.*)</code>, <code>(json.*)</code>, <code>(if.*)</code>, <code>(daysuntil.*)</code>, <code>(timeuntil.*)</code>, <code>(command.*)</code>, and <code>(call.*)</code>. This is because all alert messages go through the same variable processing system as custom commands.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Note:</strong> You're not limited to only the variables listed on this page! Any variable that works in a custom command will also work in your alert messages. See the <a href="#" data-goto="variables">Custom Variables Reference</a> for the full list of shared variables.
        </div>
    </div>
    <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
        <i class="fa-solid fa-lightbulb"></i>
        <div>
            <strong>Pro Tip:</strong> You can combine multiple variables in a single message for more dynamic alerts!<br>
            <strong>Example:</strong> <code>Thank you (user) for (bits) bits! You've given a total of (total-bits) bits to the channel!</code><br>
            <strong>In chat:</strong> <code>Thank you BotOfTheSpecter for 100 bits! You've given a total of 5,000 bits to the channel!</code>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">General Variables <span style="font-weight:400;font-size:0.875rem;color:var(--text-secondary);">(available across multiple modules)</span></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:0.75rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">
                <p>The username of the person who triggered the event (follower, subscriber, raider, etc.).</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Thank you (user) for following!</code></p>
                <p><strong>In chat:</strong> <code>Thank you BotOfTheSpecter for following!</code></p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(shoutout)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <p>Triggers a shoutout for the user. The shoutout info is sent as a separate message after your alert.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Welcome (user)! (shoutout)</code></p>
                <p><strong>In chat:</strong><br>
                    <code>Welcome BotOfTheSpecter!</code><br>
                    <code>Check out their channel at twitch.tv/BotOfTheSpecter - They were last playing Software and Game Development!</code>
                </p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(pronouns)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <p>The pronouns of the user who triggered the event, fetched from <a href="https://pronouns.alejo.io" target="_blank" rel="noopener">pronouns.alejo.io</a>. Falls back to <code>they/them</code> if the user has not set their pronouns.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Their pronouns are (pronouns).</code></p>
                <p><strong>In chat:</strong> <code>Their pronouns are she/her.</code></p>
                <p style="margin-top:0.5rem;">You can also use <code style="color:#c813e0;">(pronouns.they)</code> for just the subject (e.g. <code>she</code>, <code>he</code>, <code>they</code>) and <code style="color:#c813e0;">(pronouns.them)</code> for just the object (e.g. <code>her</code>, <code>him</code>, <code>them</code>).</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>We hope (pronouns.they) enjoys the channel! Give (pronouns.them) a warm welcome!</code></p>
                <p><strong>In chat:</strong> <code>We hope she enjoys the channel! Give her a warm welcome!</code></p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(arg)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <p>The argument typed by the user after the command name. Empty string if no argument was provided.</p>
                <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>You said: (arg)</code></p>
                <p><strong>In chat:</strong> <code>You said: hello</code></p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(if.CONDITION|TRUE|FALSE)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">
                <p>Evaluates a condition and returns one of two values. All other variables are resolved first. Supported operators: <code>=</code> <code>!=</code> <code>&lt;</code> <code>&gt;</code> <code>&lt;=</code> <code>&gt;=</code> <code>contains</code> <code>startswith</code> <code>endswith</code></p>
                <p style="margin-top:0.5rem;"><strong>Examples:</strong></p>
                <p><code>(customapi.json.https://your-api.com/data)(if.(json.username) = (user)|You're authorised|You're not authorised)</code></p>
                <p><code>(if.(arg) = start|The timer has started!|Say start to begin)</code></p>
                <p><code>(if.(user) = gfaundead|Welcome boss!|Hello (user)!)</code></p>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-hand-wave"></i> Welcome Messages</div>
            <div class="sp-card-body">
                <p style="color:var(--text-secondary);font-size:0.875rem;">No unique variables — all <strong>General Variables</strong> above (including <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, <code style="color:#c813e0;">(pronouns.them)</code>) are available in welcome messages.</p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-rectangle-ad"></i> Ad Notices</div>
            <div class="sp-card-body">
                <div style="margin-bottom:0.75rem;">
                    <div class="sp-card-header" style="padding-left:0;"><code style="color:#3273dc;">(minutes)</code></div>
                    <p style="margin-top:0.35rem;">Shows how many minutes until an upcoming ad break starts. Used in the upcoming ad notification message.</p>
                    <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Heads up! An ad break is coming up in (minutes) minutes!</code></p>
                    <p><strong>In chat:</strong> <code>Heads up! An ad break is coming up in 5 minutes!</code></p>
                </div>
                <div>
                    <div class="sp-card-header" style="padding-left:0;"><code style="color:#3273dc;">(duration)</code></div>
                    <p style="margin-top:0.35rem;">Shows the length of the ad break, formatted as a human-readable string.</p>
                    <p style="margin-top:0.5rem;"><strong>Example:</strong> <code>Ad break will last (duration).</code></p>
                    <p><strong>In chat:</strong> <code>Ad break will last 1 minute 30 seconds.</code></p>
                </div>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h3>Follower Alert</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the new follower.<br><strong>Example:</strong> <code>Thank you (user) for following!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> — see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Bits &amp; Cheers</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the person who cheered bits.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(bits)</code></div>
            <div class="sp-card-body">The number of bits cheered in this event.<br><strong>Example:</strong> <code>Thank you for (bits) bits!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(total-bits)</code></div>
            <div class="sp-card-body">The total bits this user has given to the channel.<br><strong>Example:</strong> <code>You've given (total-bits) bits total!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> — see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Raid</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the raider.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(viewers)</code></div>
            <div class="sp-card-body">The number of viewers who joined with the raid.<br><strong>Example:</strong> <code>(user) raided with (viewers) viewers!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> — see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Hype Train</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(level)</code></div>
            <div class="sp-card-body">The current or final level of the hype train.<br><strong>Example:</strong> <code>Hype train is at level (level)!</code></div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Standard Subscriptions</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the subscriber.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(tier)</code></div>
            <div class="sp-card-body">The subscription tier (Tier 1, Tier 2, Tier 3, or Prime).<br><strong>Example:</strong> <code>You are now a (tier) subscriber!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(months)</code></div>
            <div class="sp-card-body">The cumulative number of months the user has been subscribed.<br><strong>Example:</strong> <code>Subscribed for (months) months!</code></div>
        </div>
        <div class="sp-card" style="border-color:#c813e0;">
            <div class="sp-card-header" style="color:#c813e0;">Also available <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body" style="font-size:0.875rem;">Supports <code style="color:#c813e0;">(shoutout)</code>, <code style="color:#c813e0;">(pronouns)</code>, <code style="color:#c813e0;">(pronouns.they)</code>, and <code style="color:#c813e0;">(pronouns.them)</code> — see General Variables above.</div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Gift Subscriptions</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(user)</code></div>
            <div class="sp-card-body">The username of the gifter.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(count)</code></div>
            <div class="sp-card-body">The number of gift subscriptions given in this event.<br><strong>Example:</strong> <code>Gifted (count) subscriptions!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#3273dc;">(total-gifted)</code></div>
            <div class="sp-card-body">The total gift subscriptions this user has given to the channel.<br><strong>Example:</strong> <code>You've gifted (total-gifted) subs total!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(gifter)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The username of the original gifter (for pay-it-forward events).<br><strong>Example:</strong> <code>Thank you (user) for paying it forward! They received a gift from (gifter).</code></div>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Subscription Upgrade <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.5rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(user)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The username of the person who upgraded their subscription.<br><strong>Example:</strong> <code>Thank you (user) for upgrading to a paid subscription!</code></div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code style="color:#c813e0;">(tier)</code> <span class="sp-badge" style="background:#c813e0;color:#fff;margin-left:0.4rem;">Beta</span></div>
            <div class="sp-card-body">The tier they upgraded to (Tier 1, Tier 2, or Tier 3).<br><strong>Example:</strong> <code>Thank you for upgrading to a (tier) subscription!</code></div>
        </div>
    </div>
</div>
<!-- ===================================================================
     TAB: TWITCH CHANNEL POINTS
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="twitch-channel-points">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Twitch Channel Points</h1>
            <p style="margin:0;color:var(--text-secondary);">Sync rewards and automate redemption responses with BotOfTheSpecter.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="twitch-channel-points" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What are Twitch Channel Points?</h2>
    <p>Twitch Channel Points are a loyalty system that allows streamers to reward their viewers for watching, following, subscribing, and participating in the stream. Viewers earn points over time and can redeem them for various rewards that you create.</p>
    <p>BotOfTheSpecter integrates seamlessly with Twitch's Channel Points system, allowing you to automate responses and create custom experiences when viewers redeem rewards.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            Channel Points are managed through Twitch's dashboard and are available for <strong>Affiliate/Partner</strong> channels. BotOfTheSpecter enhances this system by syncing your rewards and automating responses when redemptions happen.
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Setting Up &amp; Syncing Rewards</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-gear"></i> Setting Up Channel Points on Twitch</div>
            <div class="sp-card-body">
                <ol style="margin:0;padding-left:1.25rem;">
                    <li>Go to your <a href="https://dashboard.twitch.tv/" target="_blank" rel="noopener">Twitch Dashboard</a>.</li>
                    <li>Navigate to the <strong>Channel Points</strong> section.</li>
                    <li>Create custom rewards with titles, costs, and descriptions.</li>
                    <li>Enable the rewards you want to use (Affiliate/Partner required).</li>
                    <li>Use BotOfTheSpecter to sync and customize responses.</li>
                </ol>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-arrows-rotate"></i> Syncing Rewards in Specter</div>
            <div class="sp-card-body">
                <p>To use Channel Points with BotOfTheSpecter, sync your rewards from Twitch. This updates reward IDs, titles, and costs so the bot can recognise redemptions and trigger your configured actions.</p>
                <ol style="margin:0.75rem 0 0;padding-left:1.25rem;">
                    <li>Log into your BotOfTheSpecter dashboard.</li>
                    <li>Go to the <strong>Channel Rewards</strong> page.</li>
                    <li>Click the <strong>Sync Rewards</strong> button.</li>
                    <li>Wait for the sync to complete.</li>
                    <li>Your rewards will appear in the table.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>Sync your rewards whenever you add, modify, or remove rewards on Twitch to keep everything up to date.</div>
    </div>

    <hr class="sp-divider">

    <h2>Customizing Reward Responses</h2>
    <p>Once your rewards are synced, you can customize the bot's response for each redemption. This allows you to create personalized experiences for your viewers.</p>
    <h3>How to Customize</h3>
    <ol>
        <li>Find the reward in the Channel Rewards table.</li>
        <li>Click the <strong>Edit</strong> button next to the reward.</li>
        <li>Enter your custom message in the text area (up to 255 characters).</li>
        <li>Click <strong>Save</strong> to apply the changes.</li>
    </ol>
    <h3>Message Variables</h3>
    <p>You can use the following variables in your custom reward messages. For the full shared-variable list, see the <a href="#" data-goto="variables">Custom Variables</a> guide.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.75rem;margin-top:0.75rem;">
        <div class="sp-card">
            <div class="sp-card-header"><code>(user)</code></div>
            <div class="sp-card-body">Tags the user who redeemed the reward.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(usercount)</code></div>
            <div class="sp-card-body">Shows how many times the user has redeemed the reward.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(userstreak)</code></div>
            <div class="sp-card-body">Shows how many times <em>in a row</em> the user has redeemed the reward.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(track)</code></div>
            <div class="sp-card-body">Increments internal reward usage tracking. Does <strong>not</strong> post any text to chat.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(tts)</code></div>
            <div class="sp-card-body">Sends the redemption user input to TTS (if present). See also the <a href="#" data-goto="tts">TTS guide</a>.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(tts.message)</code></div>
            <div class="sp-card-body">Sends your final custom message to both chat and TTS.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(lotto)</code></div>
            <div class="sp-card-body">Generates the user's lotto numbers.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(fortune)</code></div>
            <div class="sp-card-body">Inserts a random fortune response.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(vip)</code></div>
            <div class="sp-card-body">Attempts to grant the redeemer VIP status via Twitch.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(vip.today)</code></div>
            <div class="sp-card-body">Grants temporary VIP intended for current stream use.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(customapi.URL)</code></div>
            <div class="sp-card-body">Fetches data from a custom API endpoint and prints the raw response.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><code>(customapi.json.URL)</code> + <code>(json.*)</code></div>
            <div class="sp-card-body">Fetches JSON silently and inserts a specific field from the response.</div>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <code>(fortune)</code>, <code>(lotto)</code>, and <code>(tts)</code> are variable-based triggers. You can place them in <strong>any</strong> reward message instead of relying on a specific reward title.
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Best Practices</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-star"></i> Reward Design</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Set appropriate point costs based on value</li>
                    <li>Use clear, descriptive titles</li>
                    <li>Include cooldowns for high-value rewards</li>
                    <li>Limit redemptions per stream/user if needed</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-users"></i> Engagement Tips</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Announce rewards during stream</li>
                    <li>Create themed reward sets</li>
                    <li>Rotate rewards to keep things fresh</li>
                    <li>Monitor redemption patterns</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-robot"></i> Bot Integration</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Keep custom messages fun and engaging</li>
                    <li>Use variables to personalise responses</li>
                    <li>Use the Manage option to convert rewards to Specter-managed when needed</li>
                    <li>Map rewards to sounds/videos for overlay alerts if needed</li>
                    <li>Test rewards before going live</li>
                    <li>Regularly sync rewards from Twitch</li>
                </ul>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-triangle-exclamation"></i> Rewards not appearing after sync</div>
            <div class="sp-card-body">Make sure the rewards are <strong>enabled</strong> on Twitch and try syncing again from the dashboard.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-comment-slash"></i> Custom messages not working</div>
            <div class="sp-card-body">
                <p>Ensure you've saved the custom message and that the bot has mod permissions on your channel.</p>
                <p style="margin-top:0.5rem;">If you use <code>(customapi.json...)</code>, make sure your <code>(json.path.to.value)</code> matches the API response structure.</p>
                <p style="margin-top:0.5rem;">Check the bot's logs for errors and report them on GitHub or Discord.</p>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-robot"></i> Redemptions not triggering responses</div>
            <div class="sp-card-body">Verify that the reward is synced, your channel is Affiliate/Partner, and the bot is running. Make sure the correct reward was redeemed and your response is configured for that reward ID in Specter.</div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-clock-rotate-left"></i> Redemption history is empty</div>
            <div class="sp-card-body">Recent redemption history only loads for <strong>Specter-managed</strong> rewards. If a reward is Twitch-only, convert it using the <strong>Manage</strong> button in Channel Rewards first.</div>
        </div>
    </div>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Need more help?</strong> Check the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" rel="noopener">GitHub Issues</a> or join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord Server</a> for support.
        </div>
    </div>
</div>
<!-- ===================================================================
     TAB: CUSTOM API
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="api">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Custom API Documentation</h1>
            <p style="margin:0;color:var(--text-secondary);">Programmatic access for integrations, overlays, and external tools.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="api" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>API Overview &amp; Authentication</h2>
    <p>The BotOfTheSpecter API enables programmatic access to various bot features, allowing developers to build custom integrations, extensions, and applications that interact with the bot's functionality.</p>
    <p>All authenticated API requests require your unique API key. This key is essential for BotOfTheSpecter integrations, including API access, WebSocket server connections, and third-party platform integrations.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>v2 Authentication Update:</strong> Authenticated <code>/v2/</code> endpoints support sending your key in the <code>X-API-KEY</code> request header. This is the recommended approach for better security. Legacy endpoints still support <code>?api_key=YOUR_API_KEY</code> where applicable.<br>
            Full v2 docs: <a href="https://api.botofthespecter.com/v2/docs" target="_blank" rel="noopener">https://api.botofthespecter.com/v2/docs</a>
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">Obtaining Your API Key</h3>
    <ol>
        <li>Log in to the <a href="https://dashboard.botofthespecter.com/" target="_blank" rel="noopener">BotOfTheSpecter Dashboard</a>.</li>
        <li>Navigate to <strong>Dashboard → Profile</strong>.</li>
        <li>Locate your API key in the <strong>API Access</strong> section of the Profile page.</li>
    </ol>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Keep your API key secure.</strong> Do not share it publicly or include it in client-side code. Your API key provides full access to your BotOfTheSpecter account.
        </div>
    </div>

    <h3 style="margin-top:1.25rem;">API Key Regeneration</h3>
    <p>If you believe your API key has been compromised:</p>
    <ol>
        <li>Go to <strong>Dashboard → Profile</strong>.</li>
        <li>Click the regenerate button in the API Key section.</li>
        <li><strong>Important:</strong> Regenerating your key requires a full restart of all BotOfTheSpecter components (Twitch Chat Bot &amp; Overlays). Restart them via the dashboard after regenerating.</li>
    </ol>

    <hr class="sp-divider">

    <h2>Endpoint Quick Reference</h2>
    <p>BotOfTheSpecter's API provides several endpoint groups. Some are public; others require a user API key or admin key. For <code>/v2/</code> endpoints, prefer the <code>X-API-KEY</code> header.</p>
    <div class="sp-card" style="margin-top:1rem;">
        <div class="sp-card-header">Authenticated Endpoint Highlights (v2)</div>
        <div class="sp-card-body">
            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/account</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/bot/status</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/checkkey</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/streamonline</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/quotes</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/fortune</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/kill</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/joke</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/weather</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/sound-alerts</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/custom-commands</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/user-points</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-points/credit</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-points/debit</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">GET /v2/user-commands/get</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-commands/add</span>
                <span class="sp-badge" style="background:#6610f2;color:white;">POST /v2/user-commands/remove</span>
                <span style="width:100%;margin:16px 0 8px 0;font-size:0.95rem;color:#0d6efd;font-weight:700;">
                    EVENTS &amp; WebSocket Triggers
                </span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/tts</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/walkon</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/deaths</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/sound_alert</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/custom_command</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/stream_online</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/stream_offline</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">GET /v2/websocket/raffle_winner</span>
                <span class="sp-badge" style="background:#0d6efd;color:white;">POST /v2/SEND_OBS_EVENT</span>
                <span style="width:100%;margin:16px 0 8px 0;font-size:0.95rem;color:#888;font-weight:700;">
                    Webhooks
                </span>
                <span class="sp-badge" style="background:#6c757d;color:white;">POST /patreon</span>
                <span class="sp-badge" style="background:#6c757d;color:white;">POST /kofi</span>
                <span class="sp-badge" style="background:#6c757d;color:white;">POST /fourthwall</span>
                <span style="width:100%;margin:16px 0 8px 0;font-size:0.95rem;color:#5a3e00;font-weight:700;">
                    Admin Only
                </span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">POST /freestuff</span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">POST /github</span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">GET /v2/authorizedusers</span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">GET /v2/discord/linked</span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">GET /v2/discord/twitch-link</span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">POST /v2/discord/twitch-link/request</span>
                <span class="sp-badge" style="background:#5a3e00;color:#ffd080;">POST /v2/discord/twitch-link/unlink</span>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Endpoint Reference: Public &amp; Commands</h2>

    <h3>Public (no authentication required)</h3>
    <ul>
        <li><code>GET /freestuff/games</code> — Get recent free games</li>
        <li><code>GET /freestuff/latest</code> — Get the most recent free game</li>
        <li><code>GET /versions</code> — Get the current bot versions</li>
        <li><code>GET /commands/info</code> — Get builtin commands information</li>
        <li><code>GET /heartbeat/websocket</code> — Get the heartbeat status of the websocket server</li>
        <li><code>GET /heartbeat/api</code> — Get the heartbeat status of the API server</li>
        <li><code>GET /heartbeat/database</code> — Get the heartbeat status of the database server</li>
        <li><code>GET /system/uptime</code> — Get API process uptime</li>
        <li><code>GET /chat-instructions</code> — Get AI chat instructions</li>
        <li><code>GET /api/song</code> — Get the remaining song requests</li>
        <li><code>GET /api/exchangerate</code> — Get the remaining exchangerate requests</li>
        <li><code>GET /api/weather</code> — Get the remaining weather API requests</li>
        <li><code>GET /api/steamapplist</code> — Get Steam app list mapping</li>
    </ul>

    <h3>Webhooks (require API key)</h3>
    <ul>
        <li><code>POST /fourthwall</code> — Receive and process FOURTHWALL Webhook Requests</li>
        <li><code>POST /kofi</code> — Receive and process KOFI Webhook Requests</li>
        <li><code>POST /patreon</code> — Receive and process Patreon Webhook Requests</li>
    </ul>

    <h3>Commands (requires user API key)</h3>
    <p>Admins can query any user's data with the <code>channel</code> parameter.</p>
    <ul>
        <li><code>GET /v2/quotes</code> — Get a random quote</li>
        <li><code>GET /v2/fortune</code> — Get a random fortune</li>
        <li><code>GET /v2/kill</code> — Retrieve the Kill Command Responses</li>
        <li><code>GET /v2/joke</code> — Get a random joke</li>
        <li><code>GET /v2/sound-alerts</code> — Get list of sound alerts for user</li>
        <li><code>GET /v2/custom-commands</code> — Get list of custom commands for your account</li>
        <li><code>GET /v2/user-commands/get</code> — Get list of user managed commands</li>
        <li><code>POST /v2/user-commands/add</code> — Add a user managed command</li>
        <li><code>POST /v2/user-commands/remove</code> — Remove a user managed command</li>
        <li><code>GET /v2/weather</code> — Get weather data and trigger WebSocket weather event</li>
        <li><code>GET /v2/user-points</code> — Get user points</li>
        <li><code>POST /v2/user-points/credit</code> — Credit points to a user</li>
        <li><code>POST /v2/user-points/debit</code> — Debit points from a user</li>
    </ul>

    <h3>User Account (requires user API key)</h3>
    <p>Admins can query any user's data with the <code>channel</code> parameter.</p>
    <ul>
        <li><code>GET /v2/account</code> — Get account information</li>
        <li><code>GET /v2/checkkey</code> — Check if the API key is valid</li>
        <li><code>GET /v2/streamonline</code> — Check if the stream is online</li>
        <li><code>POST /v2/discord/twitch-link/confirm</code> — Confirm Discord to Twitch link using one-time token</li>
        <li><code>GET /v2/bot/status</code> — Get chat bot status</li>
    </ul>

    <h3>WebSocket Triggers (requires user API key)</h3>
    <p>Endpoints that trigger real-time events via WebSocket to the bot and overlays.</p>
    <ul>
        <li><code>GET /v2/websocket/tts</code> — Trigger TTS via API</li>
        <li><code>GET /v2/websocket/walkon</code> — Trigger Walkon via API</li>
        <li><code>GET /v2/websocket/deaths</code> — Trigger Deaths via API</li>
        <li><code>GET /v2/websocket/sound_alert</code> — Trigger Sound Alert via API</li>
        <li><code>GET /v2/websocket/custom_command</code> — Trigger Custom Command via API</li>
        <li><code>GET /v2/websocket/stream_online</code> — Trigger Stream Online via API</li>
        <li><code>GET /v2/websocket/raffle_winner</code> — Trigger Raffle Winner via API</li>
        <li><code>GET /v2/websocket/stream_offline</code> — Trigger Stream Offline via API</li>
        <li><code>POST /v2/SEND_OBS_EVENT</code> — Pass OBS events to the websocket server</li>
    </ul>

    <h3>Admin Only (requires admin API key)</h3>
    <p>Administrative endpoints that require admin API key. Service-specific admin keys are restricted to their designated service.</p>
    <ul>
        <li><code>POST /freestuff</code> — Receive and process FreeStuff Webhook Requests</li>
        <li><code>POST /github</code> — Receive and process GitHub Webhook Requests</li>
        <li><code>GET /v2/authorizedusers</code> — Get a list of authorized users for full beta access to the entire Specter Ecosystem</li>
        <li><code>GET /v2/discord/linked</code> — Check if Discord user is linked</li>
        <li><code>GET /v2/discord/twitch-link</code> — Get Discord to Twitch link</li>
        <li><code>POST /v2/discord/twitch-link/request</code> — Create one-time Twitch link token for a Discord user</li>
        <li><code>POST /v2/discord/twitch-link/unlink</code> — Unlink Discord user from Twitch account</li>
    </ul>

    <hr class="sp-divider">

    <h2>Using the API</h2>
    <p>For <code>/v2/</code> endpoints, send your API key in the <code>X-API-KEY</code> header. Legacy endpoints can still use a URL query parameter where supported.</p>
    <p>Do not expose the key in public client-side code — treat it like a secret and rotate it if you suspect compromise.</p>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Recommended for /v2/:</strong> <code>X-API-KEY: YOUR_API_KEY</code>
        </div>
    </div>

    <div class="sp-card" style="margin-top:1rem;" id="api-code-examples">
        <div class="sp-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <span>Code Examples</span>
            <label style="font-weight:normal;font-size:0.875rem;display:flex;align-items:center;gap:0.4rem;">
                Language:
                <select id="apiExampleLang" class="sp-select" style="min-width:10rem;">
                    <option value="curl">curl</option>
                    <option value="javascript">JavaScript (fetch)</option>
                    <option value="python">Python (requests)</option>
                    <option value="php">PHP (curl)</option>
                    <option value="java">Java (HttpClient)</option>
                </select>
            </label>
        </div>
        <div class="sp-card-body">
            <pre class="api-sample" data-lang="curl" style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>curl -H "X-API-KEY: YOUR_API_KEY" "https://api.botofthespecter.com/v2/account"</code></pre>
            <pre class="api-sample" data-lang="javascript" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>fetch('https://api.botofthespecter.com/v2/account', {
  headers: {
    'X-API-KEY': 'YOUR_API_KEY'
  }
})
.then(r =&gt; r.json())
.then(console.log);</code></pre>
            <pre class="api-sample" data-lang="python" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>import requests

resp = requests.get(
    'https://api.botofthespecter.com/v2/account',
    headers={'X-API-KEY': 'YOUR_API_KEY'}
)
print(resp.json())</code></pre>
            <pre class="api-sample" data-lang="php" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.botofthespecter.com/v2/account');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: YOUR_API_KEY']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
echo $response;</code></pre>
            <pre class="api-sample" data-lang="java" style="display:none;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>// Java 11+ HttpClient
HttpClient client = HttpClient.newHttpClient();
HttpRequest request = HttpRequest.newBuilder()
    .uri(URI.create("https://api.botofthespecter.com/v2/account"))
    .header("X-API-KEY", "YOUR_API_KEY")
    .GET()
    .build();
HttpResponse&lt;String&gt; resp = client.send(request, HttpResponse.BodyHandlers.ofString());
System.out.println(resp.body());</code></pre>
            <p style="margin-top:0.75rem;font-size:0.9rem;margin-bottom:0;">Replace <code>YOUR_API_KEY</code> with the key from your dashboard. For <code>/v2/</code> routes, always send it via the <code>X-API-KEY</code> header and avoid passing keys in URLs.</p>
        </div>
    </div>

    <div class="sp-alert sp-alert-info" style="margin-top:1.25rem;">
        <i class="fa-solid fa-book"></i>
        <div>
            Interactive OpenAPI docs:
            <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener">api.botofthespecter.com/docs</a>
            ·
            <a href="https://api.botofthespecter.com/v2/docs" target="_blank" rel="noopener">v2 docs</a>
        </div>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('apiExampleLang');
    if (!sel) return;
    var root = document.getElementById('api-code-examples') || document;
    var blocks = Array.prototype.slice.call(root.querySelectorAll('.api-sample'));
    function show(v) {
        blocks.forEach(function (b) {
            b.style.display = b.dataset.lang === v ? 'block' : 'none';
        });
    }
    sel.addEventListener('change', function (e) { show(e.target.value); });
    show(sel.value);
})();
</script>
<!-- ===================================================================
     TAB: RUN YOURSELF (self-hosting)
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="run-yourself">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h1 style="margin:0 0 0.25rem;">Run BotOfTheSpecter Yourself</h1>
            <p style="margin:0;color:var(--text-secondary);">Self-host SpecterSystems on your own Linux servers.</p>
        </div>
        <button type="button" class="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link"
                data-copy-id="run-yourself" title="Copy link to this section">
            <i class="fa-solid fa-link"></i> Copy link
        </button>
    </div>

    <h2>What is Self-Hosting?</h2>
    <div class="sp-alert sp-alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Complete Freedom &amp; Control</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">To run the source code of BotOfTheSpecter on your own set of servers and not use our hosted system, you'll have complete freedom to host it yourself with more control over your data. BotOfTheSpecter runs on a full headless Linux server architecture.</p>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Advanced Setup Required</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">Running SpecterSystems yourself requires technical knowledge of server administration, Python, PHP, and Linux. This is recommended for experienced developers and system administrators only.</p>
        </div>
    </div>
    <div class="sp-alert sp-alert-warning" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Self-Hosting Note</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">If you're interested in running BotOfTheSpecter on your own servers, please be aware that the self-hosting documentation may not always reflect the latest changes. Self-hosting is recommended for experienced developers who are comfortable troubleshooting issues independently. While we're happy to help with our hosted service, our support team focuses primarily on the cloud-hosted version and may not be able to assist with self-hosting setup or issues.</p>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Server Architecture</h2>
    <p>The minimum setup required to run SpecterSystems consists of <strong>4 servers</strong> running on a headless Linux architecture. A 5-server setup is recommended for production deployments.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #3273dc;">Server 1: Web / Dashboard</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 1+ core</li>
                    <li><strong>RAM:</strong> 1 GB minimum</li>
                    <li><strong>Service:</strong> PHP / Caddy Dashboard</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #48c774;">Server 2: API</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 1+ core</li>
                    <li><strong>RAM:</strong> 1 GB minimum</li>
                    <li><strong>Service:</strong> FastAPI server</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #ffdd57;">Server 3: WebSocket</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 1+ core</li>
                    <li><strong>RAM:</strong> 1 GB minimum</li>
                    <li><strong>Service:</strong> Python SocketIO server</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header" style="border-left:4px solid #f14668;">Server 4: Database</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                    <li><strong>CPU:</strong> 2+ cores</li>
                    <li><strong>RAM:</strong> 4 GB minimum</li>
                    <li><strong>Service:</strong> MySQL</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="sp-card" style="margin-top:1rem;">
        <div class="sp-card-header" style="border-left:4px solid #b56edb;">
            Server 5: Bot <span class="sp-badge" style="background:#b56edb;color:#fff;margin-left:0.5rem;">Recommended</span>
        </div>
        <div class="sp-card-body">
            <p>For production with improved reliability and scalability. This is how SpecterSystems currently runs.</p>
            <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
                <li><strong>OS:</strong> Ubuntu 24.04 LTS+</li>
                <li><strong>CPU:</strong> 2+ cores</li>
                <li><strong>RAM:</strong> 4 GB minimum</li>
                <li><strong>Service:</strong> Python bot process</li>
            </ul>
            <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;font-size:0.9rem;">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    The 2+ cores / 4 GB RAM spec is for running many bots for multiple users. If you're only running a <strong>single bot</strong> for personal use, 1 core and 1 GB RAM is sufficient.
                </div>
            </div>
        </div>
    </div>
    <h3 style="margin-top:1.25rem;">Common Software Requirements (All Servers)</h3>
    <ul>
        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS or newer)</li>
        <li><strong>Python:</strong> 3.8+ (Bot, API, and WebSocket servers)</li>
        <li><strong>PHP:</strong> 8.0+ (Web/Dashboard server)</li>
        <li><strong>Caddy</strong> (Web/Dashboard server)</li>
        <li><strong>MySQL</strong> (Database server)</li>
        <li><strong>Git:</strong> For version control</li>
    </ul>
    <h3>Network &amp; Services</h3>
    <ul>
        <li>Twitch API credentials (OAuth tokens)</li>
        <li>Discord bot token <em>(optional)</em></li>
        <li>Spotify API credentials <em>(optional)</em></li>
        <li>OpenWeatherMap API key <em>(optional)</em></li>
        <li>SSL/TLS certificates for secure communication</li>
        <li>Firewall configured for internal communication</li>
    </ul>

    <hr class="sp-divider">

    <h2>Recommended Hosting: Linode</h2>
    <div class="sp-alert sp-alert-info">
        <i class="fa-solid fa-cloud"></i>
        <div>
            <strong>We recommend running SpecterSystems on Linode.</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">Our systems have been fully tested and optimized to work seamlessly on Linode's infrastructure.</p>
        </div>
    </div>
    <p style="margin-top:1rem;"><strong>Get $100 in free credit:</strong> Use our referral link to receive <strong>$100 of Linode credit</strong> to use within 60 days once you've entered a valid payment method to your Linode account.</p>
    <p style="margin-top:1rem;">
        <a href="https://www.linode.com/lp/refer/?r=210010495bf7dc151d31289c7bc399f8933f79e3" target="_blank" rel="noopener" class="sp-btn sp-btn-primary">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Get $100 Linode Credit
        </a>
    </p>

    <hr class="sp-divider">

    <h2>Prerequisites (All Servers)</h2>
    <p>Before deploying to individual servers, ensure each Linux server has the following installed:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:1rem;"><code># Update system packages (All Servers)
sudo apt update &amp;&amp; sudo apt upgrade -y

# Install common dependencies (All Servers)
sudo apt install -y curl wget git build-essential openssl ssl-cert

# Create botofthespecter user (All Servers)
sudo useradd -m -s /bin/bash botofthespecter
sudo usermod -aG sudo botofthespecter

# For Servers 1, 2, 3, 5 - Install Python and pip
sudo apt install -y python3 python3-pip python3-venv

# For Server 1 Only - Install PHP and Caddy
sudo apt install -y php php-cli php-fpm php-curl php-json php-mysql php-ssh2 caddy

# For Server 4 Only - Install MySQL
sudo apt install -y mysql-server</code></pre>

    <hr class="sp-divider">

    <h2>Step 1: Clone the Repository (Servers 1, 2, 3, 5)</h2>
    <p>Clone the BotOfTheSpecter repository to a temporary directory on each server (except Server 4 — Database):</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:1rem;"><code>cd /tmp
git clone https://github.com/YourStreamingTools/BotOfTheSpecter.git botofthespecter-temp
cd botofthespecter-temp</code></pre>
    <p style="margin-top:1rem;">Then move the appropriate files to their destinations based on your server type:</p>

    <h3>For Server 1 (Web/Dashboard):</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;"><code>sudo rm -rf /var/www/html
sudo cp -r /tmp/botofthespecter-temp/dashboard /var/www/
sudo cp -r /tmp/botofthespecter-temp/home /var/www/
sudo cp -r /tmp/botofthespecter-temp/html /var/www/
sudo cp -r /tmp/botofthespecter-temp/overlay /var/www/
sudo cp -r /tmp/botofthespecter-temp/roadmap /var/www/
sudo cp -r /tmp/botofthespecter-temp/tts /var/www/
sudo cp -r /tmp/botofthespecter-temp/walkons /var/www/
sudo cp -r /tmp/botofthespecter-temp/videoalerts /var/www/
sudo cp -r /tmp/botofthespecter-temp/soundalerts /var/www/
sudo cp -r /tmp/botofthespecter-temp/config /var/www/
sudo cp -r /tmp/botofthespecter-temp/cdn /var/www/
sudo chown -R www-data:www-data /var/www</code></pre>

    <h3 style="margin-top:1rem;">For Server 2 (API):</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;"><code>sudo cp -r /tmp/botofthespecter-temp/api /home/botofthespecter/
sudo chown -R botofthespecter:botofthespecter /home/botofthespecter</code></pre>

    <h3 style="margin-top:1rem;">For Server 3 (WebSocket):</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;"><code>sudo cp -r /tmp/botofthespecter-temp/websocket /home/botofthespecter/
sudo chown -R botofthespecter:botofthespecter /home/botofthespecter</code></pre>

    <h3 style="margin-top:1rem;">For Server 5 (Bot):</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;"><code>sudo cp -r /tmp/botofthespecter-temp/bot /home/botofthespecter/
sudo chown -R botofthespecter:botofthespecter /home/botofthespecter</code></pre>

    <h3 style="margin-top:1rem;">Clean up temporary directory (All Servers):</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;"><code>rm -rf /tmp/botofthespecter-temp</code></pre>

    <hr class="sp-divider">

    <h2>Step 2: Configure Database Server (Server 4 Only)</h2>
    <p>Server 4 does not require application files from the repository — it only needs MySQL installed and configured. Specter uses <strong>two database scopes</strong>:</p>
    <ol>
        <li><strong>Central / system databases</strong> — you create these once by hand (<code>website</code>, <code>spam_pattern</code>, optional <code>roadmap</code> / <code>specterdiscordbot</code>).</li>
        <li><strong>Per-user databases</strong> — one MySQL database <strong>per Twitch username</strong> (DB name = username). These are <strong>never</strong> created by hand; the dashboard creates them on first login.</li>
    </ol>

    <h3>Central databases (create manually)</h3>
    <ul>
        <li><strong>spam_pattern</strong> — global spam phrases for auto-ban (table: <code>spam_patterns</code>)</li>
        <li><strong>website</strong> — accounts, OAuth tokens, API keys, admin keys, system tables</li>
        <li><strong>specterdiscordbot</strong> — Discord bot state <em>(optional)</em></li>
        <li><strong>roadmap</strong> — roadmap site <em>(optional)</em></li>
    </ul>
    <p>Minimal bootstrap SQL for the core DBs (expand as needed; full <code>website</code> tables also grow via <code>migrations/website/</code>):</p>
    <details style="margin-top:1rem;">
        <summary style="cursor:pointer;padding:0.75rem 1rem;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);font-weight:600;">View central database bootstrap SQL (click to expand)</summary>
        <pre style="background:var(--bg-surface);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);padding:1rem;overflow-x:auto;margin:0;"><code>sudo mysql -u root -p

-- spam_pattern (DB name) + spam_patterns (table)
CREATE DATABASE IF NOT EXISTS spam_pattern;
USE spam_pattern;
CREATE TABLE IF NOT EXISTS spam_patterns (
    id INT NOT NULL AUTO_INCREMENT,
    spam_pattern TEXT NOT NULL,
    PRIMARY KEY (id)
);

-- roadmap (optional)
CREATE DATABASE IF NOT EXISTS roadmap;
USE roadmap;
CREATE TABLE IF NOT EXISTS roadmap_items (
    id INT NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    category ENUM('REQUESTS','IN PROGRESS','BETA TESTING','COMPLETED','REJECTED') NOT NULL DEFAULT 'REQUESTS',
    subcategory ENUM('TWITCH BOT','DISCORD BOT','WEBSOCKET SERVER','API SERVER','WEBSITE','OTHER') NOT NULL,
    priority ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    website_type ENUM('DASHBOARD','OVERLAYS') DEFAULT NULL,
    completed_date DATE DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
CREATE TABLE IF NOT EXISTS roadmap_comments (
    id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    username VARCHAR(255) NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT roadmap_comments_ibfk_1 FOREIGN KEY (item_id) REFERENCES roadmap_items (id) ON DELETE CASCADE
);

-- specterdiscordbot (optional — full schema in repo)
CREATE DATABASE IF NOT EXISTS specterdiscordbot;

-- website (accounts + system tables; users row is the minimum for login)
CREATE DATABASE IF NOT EXISTS website;
USE website;
CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    twitch_display_name VARCHAR(50) DEFAULT NULL,
    twitch_user_id VARCHAR(255) NOT NULL,
    access_token VARCHAR(255) DEFAULT NULL,
    refresh_token VARCHAR(255) DEFAULT NULL,
    api_key VARCHAR(32) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    beta_access TINYINT(1) NOT NULL DEFAULT 0,
    is_technical TINYINT(1) NOT NULL DEFAULT 0,
    signup_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    profile_image VARCHAR(255) NOT NULL DEFAULT 'https://cdn.botofthespecter.com/noimage.png',
    email VARCHAR(255) DEFAULT NULL,
    app_password VARCHAR(50) DEFAULT NULL,
    language VARCHAR(5) NOT NULL DEFAULT 'EN',
    PRIMARY KEY (id),
    UNIQUE KEY username (username),
    UNIQUE KEY api_key (api_key),
    KEY idx_twitch_user_id (twitch_user_id)
);</code></pre>
    </details>

    <p style="margin-top:1rem;">Then create your MySQL application user (must be able to create databases — per-user DBs need <code>CREATE</code>):</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>CREATE USER 'your_username'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON *.* TO 'your_username'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;</code></pre>

    <div class="sp-alert sp-alert-danger" style="margin-top:1rem;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>DO NOT manually create per-user databases or their tables.</strong>
            <p style="margin-top:0.5rem;margin-bottom:0;">On first dashboard login for a channel, <code>dashboard/includes/usr_database.php</code> creates a MySQL database named after the Twitch username (e.g. <code>gfaundead</code>), creates ~100 tables if missing, migrates columns, and seeds default settings. Manual creation will drift from the live schema.</p>
        </div>
    </div>

    <h3 style="margin-top:1.5rem;">Per-user databases (auto-created)</h3>
    <p>Source of truth: <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/blob/main/dashboard/includes/usr_database.php" target="_blank" rel="noopener"><code>dashboard/includes/usr_database.php</code></a>.</p>
    <ul>
        <li><strong>Database name</strong> = Twitch login / session username (letters, numbers, underscore only; max 64 chars).</li>
        <li><strong>When</strong> — first successful dashboard session for that user (and again on later loads to create any missing tables / columns).</li>
        <li><strong>What runs</strong> — <code>CREATE DATABASE</code> if the schema does not exist, then <code>CREATE TABLE IF NOT EXISTS</code> for every table below, then column checks, then default seed rows.</li>
    </ul>

    <h4>Tables created in each user database</h4>
    <p>Grouped for readability (names match the code):</p>
    <div class="sp-table-wrap" style="margin-top:0.75rem;">
        <table class="sp-table">
            <thead>
                <tr><th style="width:28%;">Area</th><th>Tables</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Commands</strong></td>
                    <td><code>custom_commands</code>, <code>custom_user_commands</code>, <code>builtin_commands</code>, <code>command_options</code>, <code>custom_command_random_pick_options</code>, <code>timed_messages</code>, <code>custom_counts</code>, <code>user_counts</code></td>
                </tr>
                <tr>
                    <td><strong>Points &amp; store</strong></td>
                    <td><code>bot_points</code>, <code>bot_settings</code>, <code>point_store_settings</code>, <code>point_store_items</code>, <code>point_store_purchases</code></td>
                </tr>
                <tr>
                    <td><strong>Channel points / rewards</strong></td>
                    <td><code>channel_point_rewards</code>, <code>reward_counts</code>, <code>reward_streaks</code>, <code>stored_redeems</code></td>
                </tr>
                <tr>
                    <td><strong>Social counters</strong></td>
                    <td><code>user_typos</code>, <code>lurk_times</code>, <code>hug_counts</code>, <code>highfive_counts</code>, <code>kiss_counts</code></td>
                </tr>
                <tr>
                    <td><strong>Deaths</strong></td>
                    <td><code>total_deaths</code>, <code>per_stream_deaths</code>, <code>game_deaths</code>, <code>game_deaths_settings</code></td>
                </tr>
                <tr>
                    <td><strong>Events / analytics</strong></td>
                    <td><code>bits_data</code>, <code>subscription_data</code>, <code>followers_data</code>, <code>raid_data</code>, <code>analytic_raids</code>, <code>analytic_stream_watch_streak</code>, <code>message_counts</code>, <code>watch_time</code>, <code>watch_time_excluded_users</code>, <code>shoutout_history</code>, <code>stream_session_stats</code>, <code>song_request_analytics</code></td>
                </tr>
                <tr>
                    <td><strong>Chat &amp; presence</strong></td>
                    <td><code>chat_history</code>, <code>seen_users</code>, <code>seen_today</code>, <code>everyone</code>, <code>groups</code></td>
                </tr>
                <tr>
                    <td><strong>Protection</strong></td>
                    <td><code>protection</code>, <code>link_whitelist</code>, <code>link_blacklisting</code>, <code>blocked_terms</code>, <code>word_replace_ignored_users</code>, <code>word_replace_ignored_words</code>, <code>joke_settings</code></td>
                </tr>
                <tr>
                    <td><strong>Alerts &amp; media</strong></td>
                    <td><code>twitch_alerts</code>, <code>twitch_alert_category_settings</code>, <code>twitch_chat_alerts</code>, <code>sound_alerts</code>, <code>twitch_sound_alerts</code>, <code>video_alerts</code>, <code>walkons</code>, <code>tts_settings</code>, <code>stream_credits</code>, <code>credits_overlay_settings</code></td>
                </tr>
                <tr>
                    <td><strong>Overlays / prefs</strong></td>
                    <td><code>profile</code>, <code>streamer_preferences</code>, <code>streaming_settings</code>, <code>ad_notice_settings</code>, <code>closed_captions_settings</code>, <code>closed_captions_corrections</code>, <code>avatar_settings</code>, <code>working_study_overlay_settings</code>, <code>maker_overlay_settings</code></td>
                </tr>
                <tr>
                    <td><strong>Raffles / lotto / VIP</strong></td>
                    <td><code>raffles</code>, <code>raffle_entries</code>, <code>raffle_winners</code>, <code>stream_lotto</code>, <code>stream_lotto_winning_numbers</code>, <code>vip_today</code></td>
                </tr>
                <tr>
                    <td><strong>Subathon / stream</strong></td>
                    <td><code>subathon_settings</code>, <code>subathon</code>, <code>stream_status</code>, <code>active_timers</code>, <code>poll_results</code>, <code>auto_record_settings</code>, <code>stream_forward_settings</code>, <code>eventsub_sessions</code></td>
                </tr>
                <tr>
                    <td><strong>Quotes / tips / categories</strong></td>
                    <td><code>quotes</code>, <code>quote_category</code>, <code>tipping_settings</code>, <code>tipping</code>, <code>categories</code></td>
                </tr>
                <tr>
                    <td><strong>Tasks / pomodoro / makers</strong></td>
                    <td><code>todos</code>, <code>showobs</code>, <code>streamer_tasks</code>, <code>user_tasks</code>, <code>task_reward_log</code>, <code>task_settings</code>, <code>user_active_project</code>, <code>user_projects</code>, <code>user_pomos</code>, <code>maker_projects</code>, <code>maker_project_images</code></td>
                </tr>
                <tr>
                    <td><strong>Bingo / Tanggle</strong></td>
                    <td><code>bingo_games</code>, <code>bingo_winners</code>, <code>bingo_players</code>, <code>tanggle_room_completions</code>, <code>tanggle_puzzle_stats</code></td>
                </tr>
                <tr>
                    <td><strong>Media queue</strong></td>
                    <td><code>media_queue</code>, <code>media_request_settings</code>, <code>media_banlist</code></td>
                </tr>
                <tr>
                    <td><strong>Other</strong></td>
                    <td><code>member_streams</code>, <code>automated_shoutout_settings</code>, <code>automated_shoutout_tracking</code></td>
                </tr>
            </tbody>
        </table>
    </div>

    <h4 style="margin-top:1.25rem;">Default seed data (first creation)</h4>
    <p><code>usr_database.php</code> also inserts defaults when tables are empty, including:</p>
    <ul>
        <li><strong>groups</strong> — Moderators, VIPs, Subscribers, Bots</li>
        <li><strong>categories</strong> — Default</li>
        <li><strong>bot_settings</strong> — point name <code>Points</code>, chat/follow/sub/cheer/raid amounts, 2× sub multiplier, excluded users include <code>botofthespecter</code> and the channel username</li>
        <li><strong>point_store_settings</strong>, <strong>subathon_settings</strong>, <strong>protection</strong>, <strong>joke_settings</strong>, <strong>watch_time_excluded_users</strong>, <strong>stream_status</strong></li>
        <li><strong>ad_notice_settings</strong> — default ad start/end/upcoming messages with variables like <code>(duration)</code> / <code>(minutes)</code></li>
        <li><strong>streamer_preferences</strong> — welcome message defaults, music source</li>
        <li><strong>twitch_chat_alerts</strong> — gift/prime upgrade, pay-it-forward, watch streak templates</li>
        <li><strong>task_settings</strong>, <strong>credits_overlay_settings</strong>, <strong>closed_captions_settings</strong>, <strong>avatar_settings</strong>, <strong>working_study_overlay_settings</strong>, <strong>automated_shoutout_settings</strong>, <strong>tanggle_puzzle_stats</strong>, <strong>showobs</strong></li>
    </ul>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            Column definitions change over time. On each dashboard load, <code>usr_database.php</code> compares live columns to the CREATE TABLE definitions and <code>ALTER TABLE … ADD COLUMN</code> for missing ones. Always deploy the latest <code>usr_database.php</code> with the dashboard — do not copy an old SQL dump as the permanent user schema.
        </div>
    </div>

    <p style="margin-top:1rem;">Finally, configure MySQL to accept connections from other servers by editing <code>/etc/mysql/mysql.conf.d/mysqld.cnf</code>:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>bind-address = 0.0.0.0</code></pre>

    <hr class="sp-divider">

    <h2>Step 3: Set Up Python Environment (Servers 2, 3 &amp; 5)</h2>
    <p>All application servers share the same repository path: <code>/home/botofthespecter</code>. Create the virtual environment in that directory and use the venv's pip/python directly so commands are deterministic and work the same on every server.</p>
    <p><strong>Recommended venv location:</strong> <code>/home/botofthespecter/botofthespecter</code></p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:1rem;"><code># create the venv (run as the botofthespecter user)
python3 -m venv botofthespecter
# install all required packages
/home/botofthespecter/botofthespecter/bin/pip install -r /home/botofthespecter/requirements.txt</code></pre>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Production notes:</strong>
            <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
                <li>Reference the virtualenv executables directly in systemd unit files. Example: <code>ExecStart=/home/botofthespecter/botofthespecter/bin/python /home/botofthespecter/api/api.py</code></li>
                <li>Always run the venv creation and package installs as the <code>botofthespecter</code> user to ensure correct file ownership.</li>
            </ul>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Step 4: Configure Environment Variables (All Servers)</h2>
    <p>Create a <code>.env</code> file in <code>/home/botofthespecter</code> with your configuration. Replace the placeholders with your actual values:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:1rem;"><code># SQL Data
SQL_HOST=
SQL_USER=
SQL_PASSWORD=
SQL_PORT=
# API STUFF
SHAZAM_API=
WEATHER_API=
STEAM_API=
OPENAI_KEY=
OPENAI_VECTOR_ID=
STREAMELEMENTS_CLIENT_ID=
STREAMELEMENTS_SECRET_KEY=
HYPERATE_API_KEY=
# Twitch Bot
OAUTH_TOKEN=oauth:
TWITCH_OAUTH_API_TOKEN=
TWITCH_OAUTH_API_CLIENT_ID=
CLIENT_ID=
CLIENT_SECRET=
TWITCH_GQL=
TIMEZONE_API=
EXCHANGE_RATE_API=
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
BOT_ID=
# Discord Bot
DISCORD_TOKEN=
DISCORD_PUBLIC_KEY=
API_KEY=
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
# Guided Bot
GUIDED_BOT_USER_ID=
GUIDED_BOT_TOKEN=
# ADMINS
ADMIN_KEY=
# BACKUP SYSTEM
USE_BACKUP_SYSTEM=False
BACKUP_CLIENT_ID=
BACKUP_SECRET_KEY=
# SSH Settings
SSH_USERNAME=
SSH_PASSWORD=
API-HOST=
WEBSOCKET-HOST=
BOT-SRV-HOST=
SQL-HOST=
WEB-HOST=
BILLING-HOST=
STREAM-AU-EAST-1-HOST=
STREAM-US-EAST-1-HOST=
STREAM-US-WEST-1-HOST=
# SMTP Email Settings
SMTP_HOST=
SMTP_PORT=465
SMTP_FROM_NAME=
SMTP_USERNAME=
SMTP_PASSWORD=
# S3 Bucket Settings for Exports Only
S3_ENDPOINT_HOSTNAME=
S3_CUSTOM_DOMAIN=
S3_BUCKET_NAME=
S3_ACCESS_KEY=
S3_SECRET_KEY=
S3_ALWAYS_UPLOAD=True</code></pre>
    <h3 style="margin-top:1rem;">Required Variables</h3>
    <ul>
        <li><strong>SQL_*</strong> — Database connection details (must match Server 4 config)</li>
        <li><strong>CLIENT_ID &amp; CLIENT_SECRET</strong> — Your Twitch application credentials</li>
        <li><strong>OAUTH_TOKEN</strong> — Bot account OAuth token</li>
        <li><strong>API_KEY</strong> — Generate a secure random key for internal service authentication</li>
    </ul>
    <h3>Optional Variables</h3>
    <ul>
        <li><strong>WEATHER_API</strong> — For weather commands (OpenWeatherMap)</li>
        <li><strong>SPOTIFY_*</strong> — For Spotify integration</li>
        <li><strong>DISCORD_*</strong> — For Discord bot functionality</li>
        <li><strong>OPENAI_KEY</strong> — For AI features</li>
        <li><strong>S3_*</strong> — For user data exports to object storage</li>
        <li><strong>SMTP_*</strong> — For email notifications</li>
    </ul>
    <h3>Server Host Variables</h3>
    <ul>
        <li><strong>API-HOST, WEBSOCKET-HOST, etc.</strong> — Set these to the IP addresses or hostnames of your respective servers for inter-server communication</li>
    </ul>

    <hr class="sp-divider">

    <h2>Step 5: Verify WebSocket Dependencies (Server 3)</h2>
    <p>Install Python dependencies for the WebSocket server:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:1rem;"><code>cd /home/botofthespecter
source /home/botofthespecter/botofthespecter/bin/activate
/home/botofthespecter/botofthespecter/bin/pip install -r /home/botofthespecter/requirements.txt</code></pre>

    <hr class="sp-divider">

    <h2>Step 6: Set Up Web Server (Server 1)</h2>
    <p>Configure Caddy to serve the PHP dashboard and static assets. Caddy auto-issues and auto-renews Let's Encrypt certificates, so you do not need a separate ACME client:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:1rem;"><code>sudo apt install -y caddy
# Install the Caddyfile from the repo (the repo ships a ready-made one under web/Caddyfile)
sudo cp /home/botofthespecter/web/Caddyfile /etc/caddy/Caddyfile
sudo caddy validate --config /etc/caddy/Caddyfile</code></pre>
    <p style="margin-top:1rem;">The shipped <code>web/Caddyfile</code> expects each surface on its own docroot under <code>/var/www/</code> and talks to PHP over the FPM socket. Update the hostnames in <code>/etc/caddy/Caddyfile</code> to your own domain, then add a Cloudflare DNS API token to <code>/etc/caddy/caddy.env</code> if you need wildcard certificates:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code># /etc/caddy/caddy.env  (systemd drop-in; Caddy reads it via {env.X})
CF_API_TOKEN=your_cloudflare_dns_api_token
STORAGE_HOST=your-object-storage-host
STORAGE_PREFIX=your-bucket-prefix</code></pre>
    <p style="margin-top:1rem;">You must serve the dashboard and related assets under your domain. Recommended subdomains to configure:</p>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>example.com
dashboard.example.com
overlay.example.com
videoalert.example.com
soundalert.example.com
tts.example.com</code></pre>
    <div class="sp-alert sp-alert-info" style="margin-top:1rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>Caddy auto-issues Let's Encrypt certificates via HTTP-01 (apex + standard subdomains) and via Cloudflare DNS-01 (wildcards). Ensure port 80 and 443 are reachable from the public internet so issuance succeeds.</div>
    </div>

    <hr class="sp-divider">

    <h2>Running the Services</h2>

    <h3>Server 1: Start the Web/Dashboard Server</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>sudo systemctl enable caddy
sudo systemctl start caddy
sudo systemctl status caddy
# After editing /etc/caddy/caddy.env you must restart (env is read once at process start);
# a plain reload will not pick up the new CF_API_TOKEN.
sudo systemctl restart caddy</code></pre>

    <h3 style="margin-top:1rem;">Server 2: Start the API Server</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>cd /home/botofthespecter
source /home/botofthespecter/botofthespecter/bin/activate
# Run with TLS (replace cert paths with your domain)
python -m uvicorn api.api:app --host 0.0.0.0 --port 443 \
  --ssl-keyfile=/etc/letsencrypt/live/api.example.com/privkey.pem \
  --ssl-certfile=/etc/letsencrypt/live/api.example.com/fullchain.pem</code></pre>
    <div class="sp-alert sp-alert-info" style="margin-top:0.75rem;">
        <i class="fa-solid fa-circle-info"></i>
        <div>TLS is required for the API server. For production, create a systemd service unit so the API starts automatically on boot.</div>
    </div>

    <h3 style="margin-top:1rem;">Server 3: Start the WebSocket Server</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>cd /home/botofthespecter
source /home/botofthespecter/botofthespecter/bin/activate
python /home/botofthespecter/server.py</code></pre>

    <h3 style="margin-top:1rem;">Server 4: Start the Database</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>sudo systemctl enable mysql
sudo systemctl start mysql
sudo systemctl status mysql</code></pre>

    <h3 style="margin-top:1rem;">Server 5: Bot Service</h3>
    <p>The bot is controlled and started from the dashboard (Server 1). No manual startup is required on Server 5 — it is ready once the Python environment and <code>.env</code> configuration are complete.</p>

    <hr class="sp-divider">

    <h2>Inter-Server Networking</h2>
    <ul>
        <li><strong>Internal Network:</strong> Use private IP addresses for inter-server communication</li>
        <li><strong>DNS/Hostnames:</strong> Set up DNS or <code>/etc/hosts</code> entries for server-to-server connections</li>
        <li><strong>Firewall Rules:</strong> Only allow necessary ports between servers</li>
        <li><strong>SSL/TLS:</strong> Encrypt communication between services</li>
    </ul>
    <h3>Firewall Configuration Example</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code># Server 1 (Web) - Allow HTTP/HTTPS and communication with other services
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow from 10.10.10.2:8001  # API Server
sudo ufw allow from 10.10.10.3:8000  # WebSocket Server
sudo ufw allow from 10.10.10.4:3306  # Database Server

# Server 2 (API) - Allow inbound from Web and Bot servers
sudo ufw allow from 10.10.10.1:any   # Web Server
sudo ufw allow from 10.10.10.5:any   # Bot Server

# Server 3 (WebSocket) - Allow inbound from Web and Bot servers
sudo ufw allow from 10.10.10.1:any   # Web Server
sudo ufw allow from 10.10.10.5:any   # Bot Server

# Server 4 (Database) - Allow inbound from all services
sudo ufw allow from 10.10.10.1:any   # Web Server
sudo ufw allow from 10.10.10.2:any   # API Server
sudo ufw allow from 10.10.10.5:any   # Bot Server

# Server 5 (Bot) - Allow outbound to API, WebSocket, and Database
sudo ufw allow to 10.10.10.2:8001    # API Server
sudo ufw allow to 10.10.10.3:8000    # WebSocket Server
sudo ufw allow to 10.10.10.4:3306    # Database Server</code></pre>

    <hr class="sp-divider">

    <h2>Security Considerations</h2>
    <ul>
        <li><strong>HTTPS/SSL:</strong> Always use SSL certificates for all services — Let's Encrypt is free</li>
        <li><strong>Firewall:</strong> Restrict database access to only the servers that need it</li>
        <li><strong>Environment Variables:</strong> Never commit <code>.env</code> files to version control</li>
        <li><strong>Database Backups:</strong> Set up automated daily backups</li>
        <li><strong>Updates:</strong> Keep dependencies updated to patch security vulnerabilities</li>
        <li><strong>Monitoring:</strong> Monitor system resources and bot logs for issues</li>
    </ul>

    <hr class="sp-divider">

    <h2>Troubleshooting</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-brands fa-twitch"></i> Bot Not Connecting to Twitch</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify your OAuth token is valid and not expired</li>
                    <li>Check that your Twitch Client ID and Secret are correct</li>
                    <li>Ensure the bot account has the proper channel permissions</li>
                    <li>Review logs in <code>bot/logs/</code> for error messages</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-database"></i> Database Connection Errors</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify MySQL is running on Server 4</li>
                    <li>Check credentials in your <code>.env</code> file</li>
                    <li>Ensure the user has proper database permissions</li>
                    <li>Test: <code>mysql -u botuser -p -h &lt;db-host&gt;</code></li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-server"></i> API Server Not Responding</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify FastAPI/Uvicorn is running</li>
                    <li>Check that port 443 is not in use by another service</li>
                    <li>Review API logs for startup errors</li>
                    <li>Ensure all Python dependencies are installed</li>
                </ul>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-plug"></i> WebSocket Connection Issues</div>
            <div class="sp-card-body">
                <ul style="margin:0;padding-left:1.25rem;">
                    <li>Verify WebSocket server is running on port 8000</li>
                    <li>Check firewall rules allow WebSocket connections</li>
                    <li>Ensure the WebSocket URL is correctly configured in clients</li>
                    <li>Review WebSocket server logs for errors</li>
                </ul>
            </div>
        </div>
    </div>

    <hr class="sp-divider">

    <h2>Maintenance</h2>
    <h3>Regular Tasks</h3>
    <ul>
        <li><strong>Daily:</strong> Check logs for errors and unusual activity</li>
        <li><strong>Weekly:</strong> Verify all services are running and responsive</li>
        <li><strong>Monthly:</strong> Update dependencies and apply security patches</li>
        <li><strong>Quarterly:</strong> Review and optimize database performance</li>
    </ul>
    <h3>Updating BotOfTheSpecter</h3>
    <pre style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;overflow-x:auto;margin-top:0.5rem;"><code>git pull origin main
pip install -r bot/requirements.txt --upgrade
pip install -r api/requirements.txt --upgrade</code></pre>

    <hr class="sp-divider">

    <h2>Need Help?</h2>
    <p>If you encounter issues while self-hosting BotOfTheSpecter:</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-brands fa-github"></i> GitHub Issues</div>
            <div class="sp-card-body">
                <p>Report bugs or browse existing issues on GitHub.</p>
                <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary sp-btn-sm">Open GitHub Issues</a>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-brands fa-discord"></i> Discord Community</div>
            <div class="sp-card-body">
                <p>Join our community for help and discussion.</p>
                <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener" class="sp-btn sp-btn-secondary sp-btn-sm">Join Discord</a>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-card-header"><i class="fa-solid fa-ticket"></i> Support Ticket</div>
            <div class="sp-card-body">
                <p>Open a ticket if you need direct assistance.</p>
                <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary sp-btn-sm">Open a Ticket</a>
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
        <div class="sp-faq-q">What main features does the bot include?</div>
        <div class="sp-faq-a">See the <a href="#" data-goto="features">Main Features</a> guide for chat protection, custom commands, games, events, tracking, and integrations.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up Spotify with the bot?</div>
        <div class="sp-faq-a">New users need their own Spotify Developer app (platform client is capped). Follow the <a href="#" data-goto="spotify">Spotify Setup</a> guide to create an app, enter credentials, and link your account.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up Text-to-Speech (TTS)?</div>
        <div class="sp-faq-a">Pick a voice in dashboard TTS settings, add your audio overlay in OBS with monitoring enabled, and trigger TTS via Channel Points. Full voice samples and tips are in the <a href="#" data-goto="tts">Text-to-Speech</a> guide.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What built-in commands are there for the bot?</div>
        <div class="sp-faq-a">BotOfTheSpecter comes with many built-in commands for moderation, entertainment, and utility. See the <a href="#" data-goto="commands">Command Reference</a> tab for the full list.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up audio monitoring in OBS?</div>
        <div class="sp-faq-a">Follow the step-by-step <a href="#" data-goto="obs-audio">OBS Audio Monitoring</a> guide: set your monitoring device, add the Specter overlay browser source with <em>Control audio via OBS</em>, then set Audio Monitoring to <strong>Monitor and Output</strong>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">I'm having trouble with the bot. What should I do?</div>
        <div class="sp-faq-a">Start with the <a href="#" data-goto="troubleshooting">Troubleshooting</a> tab which covers the most common problems. If you're still stuck, <a href="/tickets.php?action=new">submit a support ticket</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I use custom variables in commands?</div>
        <div class="sp-faq-a">Custom commands, timed messages, and channel point rewards support dynamic variables like <code>(user)</code>, <code>(count)</code>, and <code>(customapi.URL)</code>. See the full list in the <a href="#" data-goto="variables">Custom Variables</a> guide.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What variables work in welcome messages and event alerts?</div>
        <div class="sp-faq-a">Event alerts use module-specific tokens (bits, raids, subs, ad notices, etc.) plus the shared custom-variable system. See <a href="#" data-goto="module-variables">Module Variables</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do Twitch Channel Points work with the bot?</div>
        <div class="sp-faq-a">Sync rewards from the dashboard Channel Rewards page, then set custom redemption messages with variables. Full walkthrough: <a href="#" data-goto="twitch-channel-points">Channel Points</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I use the BotOfTheSpecter API?</div>
        <div class="sp-faq-a">Get your API key from Dashboard → Profile, send it as <code>X-API-KEY</code> on <code>/v2/</code> routes, and see the <a href="#" data-goto="api">Custom API</a> guide for endpoints and code samples. Full OpenAPI: <a href="https://api.botofthespecter.com/v2/docs" target="_blank" rel="noopener">api.botofthespecter.com/v2/docs</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Can I self-host BotOfTheSpecter?</div>
        <div class="sp-faq-a">Yes — advanced users can run Specter on their own Linux servers. See the <a href="#" data-goto="run-yourself">Run Yourself</a> guide. Support focuses on the hosted service; self-hosting requires independent troubleshooting.</div>
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
        <li>Ensure the OBS Browser Source volume is audible and <a href="#" data-goto="obs-audio">audio monitoring is configured correctly</a>.</li>
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
