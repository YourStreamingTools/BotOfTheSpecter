<?php
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Troubleshooting Guide</a></li>
    </ul>
</nav>
<section class="section">
    <div class="container">
        <h1 class="title is-2 has-text-light">Troubleshooting Guide</h1>
        <p class="subtitle has-text-light">Common issues and solutions for BotOfTheSpecter</p>
        <div class="content has-text-light">
            <h2 class="title is-4 has-text-light">Bot Not Connecting</h2>
            <p>If your bot isn't connecting to Twitch:</p>
            <ul>
                <li>Try stopping and starting the bot from the dashboard</li>
            </ul>
            <h2 class="title is-4 has-text-light">Commands Not Working</h2>
            <p>If commands aren't responding:</p>
            <ul>
                <li>Check to make sure the commands are enabled, via the dashboard or you can use the <code>!enablecommand</code> command followed by the name of the command to ensure it's enabled</li>
                <li>Syntax is always an exclamation point "!", verify that the bot has mod permissions in your channel</li>
                <li>Make sure you're typing the command name correctly and that the command setup on the dashboard was spelt correctly</li>
            </ul>
            <h2 class="title is-4 has-text-light">Sound Alerts/TTS/Walk ons, etc Audio Issues</h2>
            <p>All of Specter audio goes via the audio overlays, please ensure that you're running the correct overlay for the system or by using the "All Audio" overlay <code>https://overlay.botofthespecter.com/alert.php?code=API_KEY_HERE</code></p>
            <ul>
                <li>Check audio device settings</li>
                <li>Make sure your volume level on OBS is set to a hearable volume and that you've correctly setup overlay <a href="obs_audio_monitoring.php">audio monitoring in OBS</a></li>
                <li>If you or your stream hears an echo on the sound alert, set Audio Monitoring to <strong>"Monitor Only (mute output)"</strong> and try again. Everyone's audio/sound setup is different, so please try changing that setting first.</li>
                <li>Make sure that you have entered the correct API key. Your API key can be found on the Specter Profile page on the dashboard</li>
            </ul>
            <h2 class="title is-4 has-text-light">Getting Help</h2>
            <p>If you can't resolve the issue:</p>
            <ul>
                <li>Check the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" class="has-text-link">GitHub Issues</a> page</li>
                <li>Join our <a href="https://discord.com/invite/ANwEkpauHJ" class="has-text-link">Discord Server</a> for support</li>
                <li>Review the <a href="https://api.botofthespecter.com/docs" class="has-text-link">API Documentation</a></li>
            </ul>
        </div>
    </div>
</section>
<?php
$content = ob_get_clean();
$pageTitle = 'Troubleshooting Guide';
include 'layout.php';
?>