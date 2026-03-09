<?php
// support/index.php
// ----------------------------------------------------------------
// Public landing page — all documentation on a single tabbed page.
// No auth required to view docs.
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
support_session_start();

// Load command data (same source as help/command_reference.php)
$commandsJson = @file_get_contents(__DIR__ . '/../api/builtin_commands.json');
$cmdData = $commandsJson ? (json_decode($commandsJson, true)['commands'] ?? []) : [];
$commands = [];
foreach ($cmdData as $k => $v) {
    $commands[$k] = is_array($v)
        ? ['description' => $v['description'] ?? 'No description available', 'usage' => $v['usage'] ?? '!' . $k]
        : ['description' => (string)$v, 'usage' => '!' . $k];
}
ksort($commands);

$pageTitle    = 'Documentation & Support';
$topbarTitle  = 'BotOfTheSpecter Documentation';
$pageDescription = 'Complete documentation for BotOfTheSpecter — setup guides, command reference, custom variables, integrations, and support tickets.';

// Show the search bar in the topbar when on this page
$extraHead = '<script>document.addEventListener("DOMContentLoaded",function(){var w=document.getElementById("sp-search-wrap");if(w)w.style.display="block";});</script>';

ob_start();
?>
<!-- ===== HERO ===== -->
<div style="text-align:center;padding:2.5rem 1rem 2rem;border-bottom:1px solid var(--border);margin-bottom:2rem;">
    <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" style="width:72px;height:72px;border-radius:50%;margin:0 auto 1rem;border:2px solid var(--border);">
    <h1 style="font-size:1.75rem;font-weight:800;margin-bottom:0.5rem;">BotOfTheSpecter Documentation</h1>
    <p style="color:var(--text-secondary);max-width:560px;margin:0 auto 1.5rem;">Everything you need to set up, configure, and get the most out of your streaming bot.</p>
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
        <div class="sp-doc-card-desc">Get your bot connected and running in minutes.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="commands">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-terminal"></i></div>
        <div class="sp-doc-card-title">Command Reference</div>
        <div class="sp-doc-card-desc">Full list of all built-in bot commands.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="variables">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-code"></i></div>
        <div class="sp-doc-card-title">Custom Variables</div>
        <div class="sp-doc-card-desc">Dynamic variables for commands and messages.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="faq">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-circle-question"></i></div>
        <div class="sp-doc-card-title">FAQ</div>
        <div class="sp-doc-card-desc">Answers to the most common questions.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="troubleshooting">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-wrench"></i></div>
        <div class="sp-doc-card-title">Troubleshooting</div>
        <div class="sp-doc-card-desc">Fix common issues and errors.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="integrations">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-plug"></i></div>
        <div class="sp-doc-card-title">Integrations</div>
        <div class="sp-doc-card-desc">Spotify, TTS, Channel Points, and more.</div>
    </a>
    <a href="#" class="sp-doc-card" data-goto="advanced">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-flask"></i></div>
        <div class="sp-doc-card-title">Advanced</div>
        <div class="sp-doc-card-desc">Self-hosting, Custom API, OBS, and module variables.</div>
    </a>
    <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener" class="sp-doc-card">
        <div class="sp-doc-card-icon"><i class="fa-solid fa-book"></i></div>
        <div class="sp-doc-card-title">API Docs <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.65rem;opacity:0.5;"></i></div>
        <div class="sp-doc-card-desc">REST API reference for developers.</div>
    </a>
</div>
<hr class="sp-divider">
<!-- ===== TABS ===== -->
<div class="sp-tabs" id="sp-doc-tabs">
    <button class="sp-tab" data-tab="setup">           <i class="fa-solid fa-rocket"></i> Setup</button>
    <button class="sp-tab" data-tab="commands">        <i class="fa-solid fa-terminal"></i> Commands</button>
    <button class="sp-tab" data-tab="variables">       <i class="fa-solid fa-code"></i> Variables</button>
    <button class="sp-tab" data-tab="faq">             <i class="fa-solid fa-circle-question"></i> FAQ</button>
    <button class="sp-tab" data-tab="troubleshooting"> <i class="fa-solid fa-wrench"></i> Troubleshooting</button>
    <button class="sp-tab" data-tab="integrations">    <i class="fa-solid fa-plug"></i> Integrations</button>
    <button class="sp-tab" data-tab="advanced">        <i class="fa-solid fa-flask"></i> Advanced</button>
</div>
<!-- ===================================================================
     TAB: SETUP
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="setup">
    <h1>First Time Setup</h1>
    <p>Follow these steps to get BotOfTheSpecter up and running on your Twitch channel.</p>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Access the Dashboard</h4>
            <p>Visit <a href="https://dashboard.botofthespecter.com" target="_blank" rel="noopener">dashboard.botofthespecter.com</a> and click <strong>Log in with Twitch</strong>. Authorise the requested permissions — these are needed for the bot to read chat, manage moderation, and access channel data.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Connect Your Twitch Account</h4>
            <p>Once logged in you will be taken to the dashboard. The bot needs to be authorised as a <em>Twitch Bot</em> in your channel — click <strong>Bot Control → Authorise Bot</strong> and follow the on-screen steps.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Set Bot Permissions</h4>
            <p>In your Twitch channel settings (or via the bot's mod command), give <code>BotOfTheSpecter</code> <strong>Moderator</strong> status so it can timeout, ban, and manage chat. Without this, moderation commands will fail.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Configure the Bot</h4>
            <p>Head to <strong>Settings → Bot Settings</strong> in the dashboard to customise the bot prefix (default <code>!</code>), enable or disable built-in commands, set up timed messages, manage channel points responses, and more.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">5</div>
        <div class="sp-step-body">
            <h4>Start the Bot</h4>
            <p>Go to <strong>Bot Control</strong> and click <strong>Start Bot</strong>. The status indicator should turn green. BotOfTheSpecter will join your channel within a few seconds and post a join message if configured.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">6</div>
        <div class="sp-step-body">
            <h4>Verify It's Working</h4>
            <p>Type <code>!ping</code> in your Twitch chat. The bot should respond with a pong message. If it doesn't, check the <a href="#" data-goto="troubleshooting">Troubleshooting</a> guide.</p>
        </div>
    </div>
    <h2>Enabling Built-in Commands</h2>
    <p>Navigate to <strong>Commands → Built-in Commands</strong> in the dashboard. Each command can be individually enabled or disabled. Hover over a command for a description, or see the <a href="#" data-goto="commands">Command Reference</a> tab.</p>
    <h2>Setting Up Bot Points</h2>
    <p>Bot Points is BotOfTheSpecter's built-in loyalty/currency system. Enable it in <strong>Settings → Bot Points</strong>. Configure the earn rate, point name, and enable point-gated commands.</p>
    <h2>Need Help?</h2>
    <p>If you run into any issues not covered here, check the <a href="#" data-goto="troubleshooting">Troubleshooting</a> tab or <a href="/tickets.php?action=new">submit a support ticket</a>.</p>
</div>
<!-- ===================================================================
     TAB: COMMANDS
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="commands">
    <h1>Command Reference</h1>
    <p>All commands are prefixed with <code>!</code>. Some require moderator or broadcaster permissions.</p>
    <?php if (!empty($commands)): ?>
    <div class="sp-table-wrap sp-mt-2">
        <table class="sp-table">
            <thead>
                <tr>
                    <th>Command</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commands as $name => $info): ?>
                <tr>
                    <td><code>!<?php echo htmlspecialchars($name); ?></code></td>
                    <td><?php echo htmlspecialchars($info['description']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="sp-alert sp-alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Command list unavailable — the commands JSON could not be loaded.</span>
    </div>
    <?php endif; ?>
    <h2 style="margin-top:2rem;">Custom Commands</h2>
    <p>Beyond built-in commands, you can create unlimited custom commands through the dashboard under <strong>Commands → Custom Commands</strong>. Custom commands support dynamic <a href="#" data-goto="variables">variables</a> and can call external APIs.</p>
    <div class="sp-alert sp-alert-info sp-mt-2">
        <i class="fa-solid fa-circle-info"></i>
        <span>To see all active commands in your channel (including customs), type <code>!commands</code> in Twitch chat.</span>
    </div>
</div>
<!-- ===================================================================
     TAB: VARIABLES
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="variables">
    <h1>Custom Variables</h1>
    <p>Custom commands and timed messages support dynamic variables that are replaced at runtime. Use them to make commands interactive and data-driven.</p>
    <h2>User &amp; Channel Variables</h2>
    <table class="sp-var-table">
        <thead><tr><th>Variable</th><th>Description</th><th>Example output</th></tr></thead>
        <tbody>
            <tr><td><code>(user)</code></td><td>Twitch username of the person who triggered the command</td><td><code>gfaundead</code></td></tr>
            <tr><td><code>(author)</code></td><td>Alias for <code>(user)</code></td><td><code>gfaundead</code></td></tr>
            <tr><td><code>(usercount)</code></td><td>Number of times the triggering user has used this command</td><td><code>5</code></td></tr>
            <tr><td><code>(game)</code></td><td>Current stream game/category</td><td><code>Minecraft</code></td></tr>
        </tbody>
    </table>
    <h2>API Variables</h2>
    <table class="sp-var-table">
        <thead><tr><th>Variable</th><th>Description</th><th>Example</th></tr></thead>
        <tbody>
            <tr><td><code>(customapi.URL)</code></td><td>Makes a GET request to URL and inserts the plain-text response</td><td><code>(customapi.https://example.com/api)</code></td></tr>
            <tr><td><code>(json.URL.key)</code></td><td>Makes a GET request to URL (JSON response) and extracts a key</td><td><code>(json.https://api.example.com/data.value)</code></td></tr>
        </tbody>
    </table>
    <h2>Counter &amp; Math Variables</h2>
    <table class="sp-var-table">
        <thead><tr><th>Variable</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>(count)</code></td><td>Auto-incrementing counter per command</td></tr>
            <tr><td><code>(math.EXPRESSION)</code></td><td>Evaluates a mathematical expression, e.g. <code>(math.2+2)</code> → <code>4</code></td></tr>
            <tr><td><code>(random.percent)</code></td><td>Random integer 0–100</td></tr>
            <tr><td><code>(random.number)</code></td><td>Random integer 1–1000</td></tr>
            <tr><td><code>(random.pick.a/b/c)</code></td><td>Picks one item from a slash-separated list</td></tr>
            <tr><td><code>(random.pick.list.COMMAND)</code></td><td>Picks one item from the response list of another command</td></tr>
        </tbody>
    </table>
    <h2>Date &amp; Time Variables</h2>
    <table class="sp-var-table">
        <thead><tr><th>Variable</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>(daysuntil.YYYY-MM-DD)</code></td><td>Days remaining until the given date</td></tr>
            <tr><td><code>(timeuntil.YYYY-MM-DD HH:MM:SS)</code></td><td>Human-readable countdown to a date/time</td></tr>
        </tbody>
    </table>
    <h2>Command Chaining</h2>
    <table class="sp-var-table">
        <thead><tr><th>Variable</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>(command.COMMAND)</code></td><td>Inserts the response of another command inline</td></tr>
            <tr><td><code>(call.COMMAND)</code></td><td>Executes another command as if run by the same user</td></tr>
        </tbody>
    </table>
    <h2>Module Variables (Events)</h2>
    <p>The following variables are available in event response messages (welcome messages, alerts, etc.):</p>
    <table class="sp-var-table">
        <thead><tr><th>Context</th><th>Variable</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td>All</td><td><code>(user)</code></td><td>Username triggering the event</td></tr>
            <tr><td>All</td><td><code>(shoutout)</code></td><td>Auto-generated shoutout message</td></tr>
            <tr><td>Welcome</td><td><code>(count)</code></td><td>How many times this user has been welcomed</td></tr>
            <tr><td>Ad Notice</td><td><code>(time)</code></td><td>Time until ad in seconds</td></tr>
            <tr><td>Ad Notice</td><td><code>(duration)</code></td><td>Ad duration in seconds</td></tr>
            <tr><td>Bits/Cheer</td><td><code>(bits)</code></td><td>Bits cheered this event</td></tr>
            <tr><td>Bits/Cheer</td><td><code>(total-bits)</code></td><td>Total cumulative bits from this user</td></tr>
            <tr><td>Raid</td><td><code>(viewers)</code></td><td>Number of raiders</td></tr>
            <tr><td>Subscription</td><td><code>(tier)</code></td><td>Sub tier (1000/2000/3000)</td></tr>
            <tr><td>Subscription</td><td><code>(months)</code></td><td>Total months subscribed</td></tr>
            <tr><td>Gift Sub</td><td><code>(count)</code></td><td>Number of subs gifted</td></tr>
            <tr><td>Gift Sub</td><td><code>(total-gifted)</code></td><td>Total subs gifted by this user</td></tr>
            <tr><td>Gift Sub</td><td><code>(gifter)</code></td><td>Username of the gifter</td></tr>
            <tr><td>Hype Train</td><td><code>(level)</code></td><td>Hype train level reached</td></tr>
        </tbody>
    </table>
</div>
<!-- ===================================================================
     TAB: FAQ
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="faq">
    <h1>Frequently Asked Questions</h1>
    <p>Common questions about BotOfTheSpecter.</p>
    <div class="sp-faq-item">
        <div class="sp-faq-q">What built-in commands are available? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">BotOfTheSpecter includes commands for moderation, entertainment, and utilities. See the full list on the <a href="#" data-goto="commands">Command Reference</a> tab, or type <code>!commands</code> in your channel.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I set up audio monitoring in OBS? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">Go to the <a href="#" data-goto="advanced">Advanced</a> tab and find the OBS Audio Monitoring guide. It walks through Settings → Audio → Monitoring Device and enabling Monitor and Output on the bot browser source.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">The bot isn't responding. What do I do? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">Check the <a href="#" data-goto="troubleshooting">Troubleshooting</a> tab first. The most common causes are: the bot isn't started (check Bot Control), the bot isn't a mod in your channel, or the built-in command is disabled in the dashboard.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do I use custom variables in my commands? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">See the <a href="#" data-goto="variables">Variables</a> tab for a full reference. Variables like <code>(user)</code>, <code>(count)</code>, and <code>(customapi.URL)</code> can be embedded directly in your command response text in the dashboard.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">How do Twitch Channel Points work with the bot? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">BotOfTheSpecter integrates with Twitch Channel Points via the dashboard under <strong>Settings → Channel Rewards</strong>. You can sync rewards and configure custom responses using the Channel Points variables. See the <a href="#" data-goto="integrations">Integrations</a> tab for more.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Can I make my commands call an external API? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">Yes — use the <code>(customapi.URL)</code> variable in any command response. It makes a GET request and inserts the plain-text response. For JSON APIs use <code>(json.URL.key)</code>. See the <a href="#" data-goto="variables">Variables</a> tab.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Can you add a command or feature I have an idea for? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">Absolutely — we're always looking for community ideas. Reach out on our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Discord server</a>, during our dev streams at <a href="https://twitch.tv/gfaundead" target="_blank" rel="noopener">twitch.tv/gfaundead</a>, or <a href="/tickets.php?action=new">submit a support ticket</a>.</div>
    </div>
    <div class="sp-faq-item">
        <div class="sp-faq-q">Is the bot free to use? <i class="fa-solid fa-chevron-down"></i></div>
        <div class="sp-faq-a">Yes, the core bot is completely free. Premium tiers (Tier 1/2/3) are available via Twitch subscription and unlock additional storage, priority support, and exclusive features. See the <a href="https://dashboard.botofthespecter.com" target="_blank" rel="noopener">Dashboard</a> for pricing.</div>
    </div>
    <hr class="sp-divider">
    <h2>Have a Question?</h2>
    <p>Can't find your answer above?</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem;">
        <div class="sp-card" style="padding:1.25rem;text-align:center;">
            <div style="font-size:1.5rem;margin-bottom:0.5rem;color:var(--accent-hover);"><i class="fa-brands fa-twitch"></i></div>
            <strong>Live on Stream</strong>
            <p style="font-size:0.82rem;color:var(--text-secondary);margin-top:0.4rem;">Ask during dev streams: <a href="https://twitch.tv/gfaundead" target="_blank" rel="noopener">twitch.tv/gfaundead</a></p>
        </div>
        <div class="sp-card" style="padding:1.25rem;text-align:center;">
            <div style="font-size:1.5rem;margin-bottom:0.5rem;color:#5865F2;"><i class="fa-brands fa-discord"></i></div>
            <strong>Discord</strong>
            <p style="font-size:0.82rem;color:var(--text-secondary);margin-top:0.4rem;"><a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">Join our server</a></p>
        </div>
        <div class="sp-card" style="padding:1.25rem;text-align:center;">
            <div style="font-size:1.5rem;margin-bottom:0.5rem;color:var(--green);"><i class="fa-solid fa-envelope"></i></div>
            <strong>Email</strong>
            <p style="font-size:0.82rem;color:var(--text-secondary);margin-top:0.4rem;"><a href="mailto:questions@botofthespecter.com">questions@botofthespecter.com</a></p>
        </div>
        <div class="sp-card" style="padding:1.25rem;text-align:center;">
            <div style="font-size:1.5rem;margin-bottom:0.5rem;color:var(--blue);"><i class="fa-solid fa-ticket"></i></div>
            <strong>Support Ticket</strong>
            <p style="font-size:0.82rem;color:var(--text-secondary);margin-top:0.4rem;"><a href="/tickets.php?action=new">Open a ticket</a></p>
        </div>
    </div>
</div>
<!-- ===================================================================
     TAB: TROUBLESHOOTING
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="troubleshooting">
    <h1>Troubleshooting Guide</h1>
    <p>Solutions to the most common issues with BotOfTheSpecter.</p>
    <h2>Bot Not Responding to Commands</h2>
    <ul>
        <li><strong>Is the bot running?</strong> Go to <strong>Bot Control</strong> in the dashboard and check the status indicator. Click <strong>Start Bot</strong> if stopped.</li>
        <li><strong>Is the bot a moderator?</strong> Without moderator status, some commands may be silently ignored by Twitch. Run <code>/mod BotOfTheSpecter</code> in your channel.</li>
        <li><strong>Is the command enabled?</strong> Check <strong>Commands → Built-in Commands</strong> to confirm the command is set to <em>Enabled</em>.</li>
        <li><strong>Are you using the right prefix?</strong> The default prefix is <code>!</code>. Check your dashboard settings.</li>
    </ul>
    <h2>Bot Joined but No Join Message</h2>
    <p>The join message is optional and disabled by default. Enable it in <strong>Settings → Bot Settings → Join Message</strong>.</p>
    <h2>Twitch Channel Points Not Working</h2>
    <ul>
        <li>Make sure you have synced your rewards via <strong>Settings → Channel Rewards → Sync Rewards</strong>.</li>
        <li>The bot needs <em>channel:manage:redemptions</em> scope — re-authorise via <strong>Bot Control → Authorise Bot</strong> if you think scopes are missing.</li>
        <li>Check that the reward's response message uses supported <a href="#" data-goto="variables">variables</a>.</li>
    </ul>
    <h2>TTS Not Playing</h2>
    <ul>
        <li>TTS requires a browser source in OBS pointed at your overlay URL. See the <a href="#" data-goto="integrations">Integrations → TTS Setup</a> section.</li>
        <li>Make sure <em>Monitor and Output</em> is selected in OBS Advanced Audio Properties for the browser source.</li>
        <li>Check the audio monitoring device is set to your headset/speakers in OBS <strong>Settings → Audio → Monitoring Device</strong>.</li>
    </ul>
    <h2>Spotify Integration Not Working</h2>
    <ul>
        <li>Ensure you have created a Spotify Developer App and entered the correct Client ID, Client Secret, and Redirect URI in the dashboard.</li>
        <li>The Redirect URI in Spotify must exactly match what's configured. See <a href="#" data-goto="integrations">Integrations → Spotify Setup</a>.</li>
        <li>Re-authorise Spotify in <strong>Integrations → Spotify</strong> after any credential change.</li>
    </ul>
    <h2>Custom Commands Not Working</h2>
    <ul>
        <li>Ensure the command name doesn't conflict with a built-in command.</li>
        <li>Double-check variables — they are case-sensitive and must use correct syntax (e.g. <code>(user)</code> not <code>(User)</code>).</li>
        <li>If using <code>(customapi.URL)</code> ensure the URL is publicly accessible and returns plain text or valid JSON.</li>
    </ul>
    <h2>Dashboard Login Issues</h2>
    <ul>
        <li>Clear your browser cookies/cache for <code>dashboard.botofthespecter.com</code> and try again.</li>
        <li>If you see a banned/restricted error, contact <a href="mailto:support@botofthespecter.com">support@botofthespecter.com</a>.</li>
        <li>Make sure pop-ups are allowed for the Twitch login page.</li>
    </ul>
    <h2>Still Stuck?</h2>
    <p>If none of the above resolves your issue, <a href="/tickets.php?action=new">submit a support ticket</a> and include:</p>
    <ul>
        <li>Your Twitch username</li>
        <li>A clear description of the problem</li>
        <li>What you have already tried</li>
        <li>Any error messages shown in the dashboard</li>
    </ul>
</div>
<!-- ===================================================================
     TAB: INTEGRATIONS
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="integrations">
    <h1>Integrations</h1>
    <!-- Spotify -->
    <h2><i class="fa-brands fa-spotify" style="color:#1DB954;"></i> Spotify Setup</h2>
    <p>BotOfTheSpecter can display your current Spotify track in chat. To enable this you need a Spotify Developer App.</p>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Create a Spotify Developer Account</h4>
            <p>Visit <a href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener">developer.spotify.com/dashboard</a> and log in with your Spotify account.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Create an App</h4>
            <p>Click <strong>Create App</strong>. Give it a name and description. Under <em>Redirect URIs</em> add: <code>https://dashboard.botofthespecter.com/spotify_callback.php</code>. Select <em>Web API</em> as the API type and save.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Copy Your Credentials</h4>
            <p>In your app's settings, copy the <strong>Client ID</strong> and <strong>Client Secret</strong>.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Enter Credentials in the Dashboard</h4>
            <p>Go to <strong>Integrations → Spotify</strong> in the dashboard. Paste your Client ID and Client Secret, then click <strong>Connect Spotify</strong> to authorise.</p>
        </div>
    </div>
    <h3>Troubleshooting Spotify</h3>
    <ul>
        <li>The Redirect URI must match exactly — including trailing slashes.</li>
        <li>If the <code>!song</code> command returns nothing, make sure Spotify is actively playing on your account.</li>
        <li>Free Spotify accounts may have API limitations.</li>
    </ul>
    <hr class="sp-divider">
    <!-- TTS -->
    <h2><i class="fa-solid fa-microphone" style="color:var(--accent-hover);"></i> Text-to-Speech (TTS) Setup</h2>
    <p>BotOfTheSpecter's TTS converts viewer messages into speech using AI voices. It plays through your OBS browser source overlay.</p>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Enable TTS in Dashboard</h4>
            <p>Go to <strong>Settings → TTS Settings</strong> and enable TTS. Select your preferred voice from the available options (Alloy, Ash, Ballad, Coral, Echo, Fable, Nova, Onyx, Sage, Shimmer, or Verse).</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Add the Browser Source in OBS</h4>
            <p>Add a Browser Source in OBS with your TTS overlay URL (found in <strong>Settings → Overlays → TTS</strong>). Set width/height to match your canvas.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Enable Audio Monitoring</h4>
            <p>In OBS, right-click the browser source → <strong>Properties</strong> → enable <strong>Control audio via OBS</strong>. Then open <strong>Audio Mixer</strong>, click the gear on the source → <strong>Advanced Audio Properties</strong> → set <em>Audio Monitoring</em> to <strong>Monitor and Output</strong>.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Set Monitoring Device</h4>
            <p>In OBS <strong>Settings → Audio</strong>, set <em>Monitoring Device</em> to your headset or speakers so you can hear TTS.</p>
        </div>
    </div>
    <h3>Channel Points TTS</h3>
    <p>You can gate TTS behind a Channel Point redemption. Create a reward in <strong>Settings → Channel Rewards</strong> and set the response to include the <code>(tts.message)</code> or <code>(tts)</code> variable.</p>
    <hr class="sp-divider">
    <!-- Channel Points -->
    <h2><i class="fa-brands fa-twitch" style="color:#9146FF;"></i> Twitch Channel Points</h2>
    <p>BotOfTheSpecter can respond to Channel Point redemptions automatically.</p>
    <h3>Setting Up</h3>
    <ul>
        <li>Go to <strong>Settings → Channel Rewards</strong> in the dashboard.</li>
        <li>Click <strong>Sync Rewards</strong> to pull your current Twitch rewards into the bot.</li>
        <li>For each reward, set a <em>Response Message</em> that the bot will post when someone redeems it.</li>
    </ul>
    <h3>Available Variables for Channel Points</h3>
    <table class="sp-var-table">
        <thead><tr><th>Variable</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>(user)</code></td><td>Username of the person who redeemed</td></tr>
            <tr><td><code>(usercount)</code></td><td>How many times this user has redeemed this reward</td></tr>
            <tr><td><code>(userstreak)</code></td><td>Consecutive redemption streak</td></tr>
            <tr><td><code>(track)</code></td><td>Current Spotify track (if Spotify integration active)</td></tr>
            <tr><td><code>(tts)</code></td><td>Plays TTS for the redemption input</td></tr>
            <tr><td><code>(tts.message)</code></td><td>Same as <code>(tts)</code> — reads the redemption message aloud</td></tr>
            <tr><td><code>(lotto)</code></td><td>Runs the lotto/raffle for this user</td></tr>
            <tr><td><code>(fortune)</code></td><td>Returns a random fortune message</td></tr>
            <tr><td><code>(vip)</code></td><td>Grants VIP status to the redeeming user</td></tr>
            <tr><td><code>(vip.today)</code></td><td>Grants temporary VIP for the current day</td></tr>
            <tr><td><code>(customapi.URL)</code></td><td>Calls an external URL and returns its response</td></tr>
            <tr><td><code>(json.URL.key)</code></td><td>Reads a key from a JSON API response</td></tr>
        </tbody>
    </table>
</div>
<!-- ===================================================================
     TAB: ADVANCED
=================================================================== -->
<div class="sp-tab-panel sp-doc-content" data-panel="advanced">
    <h1>Advanced</h1>
    <!-- Self-hosting -->
    <h2><i class="fa-solid fa-server"></i> Running It Yourself</h2>
    <p>BotOfTheSpecter is open-source and can be self-hosted. You'll need a multi-server setup with minimum 4 servers (5 recommended).</p>
    <h3>Server Architecture</h3>
    <ul>
        <li><strong>Web Server</strong> — Apache2/Nginx serving the PHP dashboard and API</li>
        <li><strong>Bot Server</strong> — Runs the Python bot processes (stable, beta, v6)</li>
        <li><strong>Database Server</strong> — MySQL for all data storage</li>
        <li><strong>CDN / Storage Server</strong> — Static assets, sound files, overlays</li>
        <li><strong>WebSocket Server</strong> (recommended) — Socket.IO for real-time overlay updates</li>
    </ul>
    <h3>Prerequisites</h3>
    <ul>
        <li>Ubuntu 22.04 LTS or later (recommended)</li>
        <li>Python 3.10+</li>
        <li>PHP 8.0+ with <code>mysqli</code>, <code>curl</code>, <code>mbstring</code></li>
        <li>MySQL 8.0+</li>
        <li>Apache2 with <code>mod_rewrite</code> enabled</li>
        <li>A registered Twitch Developer App</li>
    </ul>
    <h3>Quick Start</h3>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Clone the Repository</h4>
            <p><code>git clone https://github.com/YourStreamingTools/BotOfTheSpecter.git</code></p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Set Up Config Files</h4>
            <p>Fill in all <code>/var/www/config/*.php</code> files with your Twitch app credentials, database credentials, and API keys.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Set Up Python Environment</h4>
            <p>On the bot server: <code>cd bot &amp;&amp; python3 -m venv venv &amp;&amp; source venv/bin/activate &amp;&amp; pip install -r requirements.txt</code></p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">4</div>
        <div class="sp-step-body">
            <h4>Import Database Schema</h4>
            <p>Run the migration scripts from the repository's <code>sql/</code> folder to create the <code>website</code> database and initial tables.</p>
        </div>
    </div>
    <p style="margin-top:1rem;">For full self-hosting documentation see the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener">GitHub repository</a>.</p>
    <hr class="sp-divider">
    <!-- Custom API -->
    <h2><i class="fa-solid fa-satellite-dish"></i> Custom API</h2>
    <p>BotOfTheSpecter provides a REST API at <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener">api.botofthespecter.com</a> that you can use to build integrations.</p>
    <h3>Authentication</h3>
    <p>All API requests require your API Key (found in <strong>Dashboard → Profile → API Key</strong>) passed as a header:</p>
    <pre><code>X-API-KEY: your_api_key_here</code></pre>
    <h3>Endpoint Groups</h3>
    <table class="sp-var-table">
        <thead><tr><th>Group</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td>Public</td><td>Read-only endpoints that don't require auth (e.g. version info)</td></tr>
            <tr><td>Commands</td><td>List, create, update, delete custom commands</td></tr>
            <tr><td>User Points</td><td>Read and modify viewer bot point balances</td></tr>
            <tr><td>User Account</td><td>Account info and settings</td></tr>
            <tr><td>Webhooks</td><td>Register URLs to receive event notifications</td></tr>
            <tr><td>WebSocket Triggers</td><td>Send events to the WebSocket server for overlays</td></tr>
        </tbody>
    </table>
    <p>Full interactive documentation: <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener">api.botofthespecter.com/docs</a></p>
    <hr class="sp-divider">
    <!-- OBS Audio Monitoring -->
    <h2><i class="fa-solid fa-headphones"></i> OBS Audio Monitoring</h2>
    <p>To hear bot sounds (TTS, alerts, walk-ons) through your headset while streaming:</p>
    <div class="sp-step">
        <div class="sp-step-num">1</div>
        <div class="sp-step-body">
            <h4>Set Monitoring Device</h4>
            <p>In OBS, go to <strong>Settings → Audio → Monitoring Device</strong> and select your headset or speakers.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">2</div>
        <div class="sp-step-body">
            <h4>Add a Browser Source</h4>
            <p>Add a Browser Source with your overlay URL. Enable <strong>Control audio via OBS</strong> in the source properties.</p>
        </div>
    </div>
    <div class="sp-step">
        <div class="sp-step-num">3</div>
        <div class="sp-step-body">
            <h4>Set to Monitor and Output</h4>
            <p>In the OBS Audio Mixer, click the gear icon on the browser source → <strong>Advanced Audio Properties</strong> → set <em>Audio Monitoring</em> to <strong>Monitor and Output</strong>.</p>
        </div>
    </div>
    <hr class="sp-divider">
    <!-- Module Variables -->
    <h2><i class="fa-solid fa-puzzle-piece"></i> Module Variables Reference</h2>
    <p>Module variables are used in event alert messages configured in the dashboard under <strong>Settings → Alerts</strong>.</p>
    <table class="sp-var-table">
        <thead><tr><th>Module</th><th>Variable</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td>All</td><td><code>(user)</code></td><td>Username of the person triggering the event</td></tr>
            <tr><td>All</td><td><code>(shoutout)</code></td><td>Auto-generated shoutout text</td></tr>
            <tr><td>Welcome</td><td><code>(count)</code></td><td>Number of times this user has been welcomed</td></tr>
            <tr><td>Ad Notice</td><td><code>(time)</code></td><td>Seconds until ad break</td></tr>
            <tr><td>Ad Notice</td><td><code>(duration)</code></td><td>Ad duration in seconds</td></tr>
            <tr><td>Bits / Cheer</td><td><code>(bits)</code></td><td>Bits cheered in this event</td></tr>
            <tr><td>Bits / Cheer</td><td><code>(total-bits)</code></td><td>Total cumulative bits from this user</td></tr>
            <tr><td>Raid</td><td><code>(viewers)</code></td><td>Number of raiders</td></tr>
            <tr><td>Sub</td><td><code>(tier)</code></td><td>Sub tier (1000 / 2000 / 3000)</td></tr>
            <tr><td>Sub</td><td><code>(months)</code></td><td>Total months subscribed</td></tr>
            <tr><td>Gift Sub</td><td><code>(count)</code></td><td>Subs gifted in this event</td></tr>
            <tr><td>Gift Sub</td><td><code>(total-gifted)</code></td><td>All-time subs gifted by this user</td></tr>
            <tr><td>Gift Sub</td><td><code>(gifter)</code></td><td>Username of the gifter</td></tr>
            <tr><td>Hype Train</td><td><code>(level)</code></td><td>Hype Train level reached</td></tr>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();

// Inline JS to wire quick-link cards to their tabs, and expose search in topbar
$extraScripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Quick-link cards → activate corresponding tab
    document.querySelectorAll('.sp-doc-card[data-goto]').forEach(function (card) {
        card.addEventListener('click', function (e) {
            e.preventDefault();
            var id = card.dataset.goto;
            // Trigger the matching tab button
            var btn = document.querySelector('.sp-tab[data-tab="' + id + '"]');
            if (btn) btn.click();
            // Scroll to tabs
            var tabs = document.getElementById('sp-doc-tabs');
            if (tabs) tabs.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    // Inline data-goto links inside doc content
    document.querySelectorAll('a[data-goto]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var id = a.dataset.goto;
            var btn = document.querySelector('.sp-tab[data-tab="' + id + '"]');
            if (btn) btn.click();
            var tabs = document.getElementById('sp-doc-tabs');
            if (tabs) tabs.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
});
</script>
JS;

include __DIR__ . '/layout.php';
?>
