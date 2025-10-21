<?php
$pageTitle = "Spotify Setup Guide";
$pageDescription = "Step-by-step guide to create your own Spotify application for BotOfTheSpecter integration.";

ob_start();
?>
<div class="content">
    <h1 class="title has-text-white">Setting Up Your Own Spotify Application</h1>
    <p class="subtitle has-text-grey-light">Follow these steps to create a Spotify app and get your Client ID and Client Secret for use with BotOfTheSpecter.</p>
    <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px; border: 1px solid #363636;">
        <h2 class="title is-4 has-text-white">Step 1: Create a Spotify Developer Account</h2>
        <ol>
            <li>Go to the <a href="https://developer.spotify.com/" target="_blank" class="has-text-link">Spotify Developer Dashboard</a>.</li>
            <li>Log in with your Spotify account (or create one if you don't have it).</li>
            <li>Accept the terms and conditions.</li>
        </ol>
    </div>
    <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px; border: 1px solid #363636;">
        <h2 class="title is-4 has-text-white">Step 2: Create a New Application</h2>
        <ol>
            <li>Click on "Create an App" or "My Apps" > "Create an App".</li>
            <li>Fill in the application details:
                <ul>
                    <li><strong>App name:</strong> Specter-[Your Username] (e.g., Specter-JohnDoe)</li>
                    <li><strong>App description:</strong> Twitch bot integration for Spotify</li>
                    <li><strong>Redirect URI:</strong> <code>https://dashboard.botofthespecter.com/spotifylink.php</code></li>
                </ul>
            </li>
            <li>Check the boxes for the required APIs (Web API).</li>
            <li>Click "Create" or "Save".</li>
        </ol>
    </div>
    <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px; border: 1px solid #363636;">
        <h2 class="title is-4 has-text-white">Step 3: Get Your Credentials</h2>
        <ol>
            <li>In your app dashboard, go to the "Settings" or main app page.</li>
            <li>Copy the <strong>Client ID</strong> (a 32-character string).</li>
            <li>Click "Show Client Secret" to reveal and copy the <strong>Client Secret</strong> (another string).</li>
        </ol>
        <div class="notification is-warning is-light" style="border-radius: 8px;">
            <strong>Important:</strong> Keep your Client Secret secure and never share it publicly.
        </div>
        <div class="notification is-info is-light" style="border-radius: 8px; margin-top: 1rem;">
            <strong>Security Note:</strong> Your credentials are stored securely in our encrypted database and are only used for your bot's Spotify integration.
        </div>
    </div>
    <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px; border: 1px solid #363636;">
        <h2 class="title is-4 has-text-white">Step 4: Configure in BotOfTheSpecter Dashboard</h2>
        <ol>
            <li>Go back to your <a href="https://dashboard.botofthespecter.com/spotifylink.php" target="_blank" class="has-text-link">Spotify Link page</a>.</li>
            <li>Check the "Enable Own Client" box.</li>
            <li>Enter your Client ID and Client Secret in the fields that appear.</li>
            <li>Click "Save Credentials".</li>
            <li>Click the "Link Spotify Account" button to authorize with your new app.</li>
        </ol>
    </div>
    <div class="box has-background-grey-darker has-text-white" style="border-radius: 8px; border: 1px solid #363636;">
        <h2 class="title is-4 has-text-white">Troubleshooting</h2>
        <ul>
            <li><strong>Redirect URI mismatch:</strong> Ensure the Redirect URI in your Spotify app matches exactly: <code>https://dashboard.botofthespecter.com/spotifylink.php</code></li>
            <li><strong>Permissions:</strong> Make sure your app has the necessary scopes enabled (user-read-playback-state, user-modify-playback-state, user-read-currently-playing).</li>
            <li><strong>Rate limits:</strong> Spotify has rate limits; if you exceed them, wait before trying again.</li>
        </ul>
    </div>
    <div class="notification is-info is-light" style="border-radius: 8px;">
        <strong>Note:</strong> Using your own Spotify app gives you more control and potentially higher rate limits, but requires you to manage the app yourself.
    </div>
</div>
<?php
$content = ob_get_clean();
include "layout.php";
?>
