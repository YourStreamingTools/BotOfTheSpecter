<?php
// support/index.php
// ----------------------------------------------------------------
// Public documentation landing page.
// Built-in tabs: Commands (API), FAQ, Troubleshooting.
// Additional guide content will be added as static PHP sections.
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
            activateTab(stored || 'commands');
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
