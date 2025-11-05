<?php
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">First Time Setup</a></li>
    </ul>
</nav>

<h1 class="title is-2 has-text-light">First Time Setup Guide</h1>
<p class="subtitle has-text-light">Get BotOfTheSpecter up and running on your Twitch channel in just a few minutes</p>

<div class="content has-text-light">
    <div class="notification is-info has-background-dark">
        <h3 class="title is-4 has-text-light">
            <span class="icon">
                <i class="fas fa-info-circle"></i>
            </span>
            What is BotOfTheSpecter?
        </h3>
        <p class="has-text-light">BotOfTheSpecter is a <span class="has-text-weight-bold">cloud-based Twitch chat bot</span> that runs entirely on our servers. You don't need to install any software, run servers, or manage technical infrastructure. Just connect your Twitch account and start using the bot immediately!</p>
    </div>

    <h2 class="title is-3 has-text-light" id="getting-started">
        <span class="icon">
            <i class="fas fa-rocket"></i>
        </span>
        Step 1: Access the Dashboard
    </h2>
    <div class="box has-background-dark has-text-light">
        <p>Go to the BotOfTheSpecter dashboard:</p>
        <div style="margin: 2rem 0;">
            <a href="https://dashboard.botofthespecter.com" target="_blank" class="button is-medium is-primary">
                <span class="icon">
                    <i class="fas fa-external-link-alt"></i>
                </span>
                <span>Open Dashboard</span>
            </a>
        </div>
        <p>Or visit: <code>https://dashboard.botofthespecter.com</code></p>
    </div>

    <h2 class="title is-3 has-text-light" id="login">
        <span class="icon">
            <i class="fab fa-twitch"></i>
        </span>
        Step 2: Connect Your Twitch Account
    </h2>
    <div class="box has-background-dark has-text-light">
        <ol>
            <li>Click the <span class="has-text-weight-bold">"Login with Twitch"</span> button on the dashboard</li>
            <li>You'll be redirected to Twitch's authorization page</li>
            <li>Review the permissions and click <span class="has-text-weight-bold">"Authorize"</span></li>
            <li>You'll be redirected back to the dashboard, now logged in</li>
        </ol>
        <div class="notification is-warning has-background-dark has-text-light">
            <span class="has-text-weight-bold">Permissions:</span> When you authorize BotOfTheSpecter, you'll be asked to grant the following permissions:
            <div style="margin-top: 1rem; max-height: 300px; overflow-y: auto; background: #1a1a1a; padding: 1rem; border-radius: 4px; border: 1px solid #4a4a4a;">
                <ul style="font-size: 0.9em; line-height: 1.4;">
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
                    <li>Get a list of all users on your block list</li>
                    <li>Add and remove users from your block list</li>
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
                    <li>Read non-private blocked terms in channels where you have the moderator role</li>
                    <li>Read chat settings in channels where you have the moderator role</li>
                    <li>Read the list of moderators in channels where you have the moderator role</li>
                    <li>Read the list of bans or unbans in channels where you have the moderator role and in Shared Chat sessions where you are a moderator for a channel in the Shared Chat</li>
                    <li>Read deleted chat messages in channels where you have the moderator role and in Shared Chat sessions where you are a moderator for a channel in the Shared Chat</li>
                    <li>Read warnings in channels where you have the moderator role</li>
                    <li>Join chat as your user and appear as a bot</li>
                    <li>Manage AutoMod in channels where you have the moderator role</li>
                    <li>Read charity campaign details and user donations on your channel</li>
                    <li>Read your email address and email verification status</li>
                    <li>Read chat messages and appear in chat as your user</li>
                    <li>Write chat messages as your user</li>
                    <li>View live Stream Chat and Rooms messages</li>
                    <li>View your channel's moderation data including Moderators, Bans, Timeouts and Automod settings</li>
                    <li>Read the list of followers in channels where you are a moderator</li>
                    <li>Read the list of chatters in channels where you have the moderator role</li>
                    <li>View your channel's Bits information</li>
                    <li>Run ads and manage the ads schedule on your channel</li>
                    <li>Read the ads schedule and details on your channel</li>
                    <li>Ban or unban users in channels where you have the moderator role</li>
                    <li>Read shoutouts in channels where you have the moderator role</li>
                    <li>Manage shoutouts in channels where you have the moderator role</li>
                </ul>
            </div>
            <p class="has-text-light" style="margin-top: 1rem; font-size: 0.9em;">
                <span class="has-text-weight-bold">Why so many permissions?</span><br>
                    BotOfTheSpecter is a comprehensive bot that handles moderation, entertainment, analytics, and channel management.<br>
                    Each permission enables specific features that make your streaming experience better.
            </p>
        </div>
    </div>

    <h2 class="title is-3 has-text-light" id="permissions">
        <span class="icon">
            <i class="fas fa-shield-alt"></i>
        </span>
        Step 3: Set Up Bot Permissions
    </h2>

    <div class="box has-background-dark has-text-light">
        <p>The bot needs to be a moderator in your channel to function properly. Here's how to add it:</p>
        <ol>
            <li>Go to your <a href="https://dashboard.twitch.tv" target="_blank">Twitch Dashboard</a></li>
            <li>On the left panel, expand the <span class="has-text-weight-bold">Community</span> menu</li>
            <li>Click <span class="has-text-weight-bold">Roles Manager</span></li>
            <li>Click <span class="has-text-weight-bold">Add New</span></li>
            <li>In the search bar, enter: <code>BotOfTheSpecter</code></li>
            <li>Select the bot user and check the <span class="has-text-weight-bold">Moderator</span> permission</li>
            <li><em>Optional:</em> Also check the <span class="has-text-weight-bold">Editor</span> role to enable VOD video access</li>
        </ol>
        <div class="notification is-info has-background-dark has-text-light" style="margin-top: 1rem;">
            <span class="has-text-weight-bold">Why is this needed?</span> As a moderator, the bot can:
            <ul style="margin-top: 0.5rem;">
                <li>Delete inappropriate messages</li>
                <li>Timeout or ban users when necessary</li>
                <li>Respond to commands in chat</li>
                <li>Manage channel point redemptions</li>
            </ul>
            <p style="margin-top: 0.5rem;"><span class="has-text-weight-bold">Editor role benefits:</span> Allows the bot to access VODs and video content for video-related commands.</p>
        </div>
    </div>

    <h2 class="title is-3 has-text-light" id="channel-setup">
        <span class="icon">
            <i class="fas fa-cogs"></i>
        </span>
        Step 4: Return to BotOfTheSpecter Dashboard & Configure
    </h2>
    <div class="box has-background-dark has-text-light">
        <p>Now that the bot has moderator permissions on Twitch, return to the BotOfTheSpecter dashboard to configure and enable the bot:</p>
        <div style="margin: 1rem 0;">
            <a href="https://dashboard.botofthespecter.com" target="_blank" class="button is-primary">
                <span class="icon">
                    <i class="fas fa-external-link-alt"></i>
                </span>
                <span>Return to Dashboard</span>
            </a>
        </div>
    </div>
    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5 has-text-light">Basic Settings</h4>
        <p>Once back in the dashboard, configure your bot:</p>
        <ul>
            <li><span class="has-text-weight-bold">Bot Status:</span> The website will detect when you've modded the bot. If you still see a warning, log out and log back in to refresh your permissions. Click the <span class="has-text-weight-bold">START</span> button to run the bot and <span class="has-text-weight-bold">WAIT</span> for the system to switch your bot on.</li>
            <li><span class="has-text-weight-bold">Channel Information:</span> Set up your channel preferences on the <a href="https://dashboard.botofthespecter.com/profile.php" target="_blank">Profile page</a>. Here you can configure:
                <ul style="margin-top: 0.5rem; margin-left: 1rem;">
                    <li>Technical terms and advanced options toggle for bot configuration</li>
                    <li>Dashboard language (English, French, or German)</li>
                    <li>Your Time Zone and Weather Location</li>
                    <li>HypeRate.io integration for heart rate display in chat - <a href="https://www.hyperate.io/" target="_blank">get your code here</a></li>
                    <li>External connections for Discord, Spotify, and StreamElements</li>
                </ul>
            </li>
            <li><span class="has-text-weight-bold">Command Prefix:</span> The command prefix is set to exclamation point <code>!</code> and cannot be changed</li>
        </ul>
        <div class="notification is-info has-background-dark has-text-light" style="margin-top: 1rem;">
            <span class="has-text-weight-bold">Control Your Bot:</span><br>
                BotOfTheSpecter is designed with control in mind - <span class="has-text-weight-bold">you run the bot, you stop the bot</span>.<br>
                If you no longer wish to use the bot, simply <span class="has-text-weight-bold">STOP Specter</span> with the STOP button. It's that simple.<br>
                We have purposely built Specter this way - no company or system should tell you how to run your own stream, and we don't want to do that.<br>
                It's your stream, you are choosing to use Specter and we can't thank you enough for choosing us.<br>
                We know you have a huge choice of bots out there, but a heads up: BotOfTheSpecter gets its name from "BOTS" - it's a bot to replace them all.<br>
                As our developer has always said, <code>"I built Specter so I'm not running 4 different chat bots on my own stream, now I just run one, that's Specter."</code>
        </div>
    </div>

    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5 has-text-light">Moderation Settings</h4>
        <p>Configure how the bot helps moderate your chat on the <a href="https://dashboard.botofthespecter.com/modules.php" target="_blank">Modules page</a>:</p>
        <ul>
            <li><span class="has-text-weight-bold">Joke Blacklist:</span> Set up joke categories to blacklist from the <code>!joke</code> command</li>
            <li><span class="has-text-weight-bold">Chat Protection:</span> Enable/disable URL blocking in chat
                <ul style="margin-top: 0.5rem; margin-left: 1rem;">
                    <li>When enabled, you can whitelist specific links to allow them</li>
                    <li>When disabled, you can still blacklist links that will ALWAYS be removed from chat</li>
                </ul>
            </li>
        </ul>
    </div>

    <h2 class="title is-3 has-text-light" id="bot-points">
        <span class="icon">
            <i class="fas fa-coins"></i>
        </span>
        Step 5: Set Up Bot Points
    </h2>
    <div class="box has-background-dark has-text-light">
        <p>
            Configure the internal point system that rewards your viewers for engagement.<br>
            This system is enabled by default and cannot be turned off at the time of writing this help document (feature coming soon):
        </p>
        <ul>
            <li><span class="has-text-weight-bold">Point Name:</span> Set a custom name for your points (e.g., "Coins", "Tokens", "Credits")</li>
            <li><span class="has-text-weight-bold">Earning Rates:</span> Configure how many points users earn for:
                <ul style="margin-top: 0.5rem; margin-left: 1rem;">
                    <li>Each chat message sent</li>
                    <li>Following your channel</li>
                    <li>Subscribing to your channel</li>
                    <li>Each cheered message</li>
                    <li>Each viewer in a raid</li>
                </ul>
            </li>
            <li><span class="has-text-weight-bold">Subscriber Multipliers:</span> Set up bonus multipliers for subscribers (e.g., 2x points for subscribers)</li>
        </ul>
        <div class="notification is-info has-background-dark has-text-light" style="margin-top: 1rem;">
            <span class="has-text-weight-bold">Why set this up?</span><br>
                Bot Points encourage viewer engagement and create a fun, gamified experience in your community.<br>
                Points can be redeemed for custom rewards, shoutouts, or special privileges. (Features coming soon)
        </div>
    </div>

    <h2 class="title is-3 has-text-light" id="customization">
        <span class="icon">
            <i class="fas fa-paint-brush"></i>
        </span>
        Step 6: Customize Your Bot (Optional)
    </h2>
    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5 has-text-light">Custom Commands</h4>
        <p>We recommend that you set up some custom commands that users can use, for example your social links, <code>!discord</code>, <code>!youtube</code>, <code>!instagram</code>, etc.</p>
        <p>They can be created on our <a href="https://dashboard.botofthespecter.com/custom_commands.php" target="_blank">Custom Commands page</a> on the Specter Dashboard.</p>
        <div class="notification is-info has-background-dark has-text-light" style="margin-top: 1rem;">
            <span class="has-text-weight-bold">Want to level up your commands?</span><br>
            Explore <a href="https://help.botofthespecter.com/custom_command_variables.php" target="_blank">Custom Variables</a> to add dynamic features and personalize your command responses.<br>
            <em>Note: Custom Variables only work in the response part of your command.</em>
        </div>
    </div>
    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5 has-text-light">Auto Messages</h4>
        <p>We also recommend you set up some auto messages that the bot will post on a timer and after a set amount of messages - you can pick these settings.</p>
        <p>They can be created on our <a href="https://dashboard.botofthespecter.com/timed_messages.php" target="_blank">Timed Messages page</a> on the Specter Dashboard.</p>
        <div class="notification is-info has-background-dark has-text-light" style="margin-top: 1rem;">
            <span class="has-text-weight-bold">Info:</span> Timed messages are sent automatically by the bot at set intervals and chat activity, but only while your channel is online.<br>
            The bot posts timed messages in three ways:
            <ol style="margin-top: 0.5rem;">
                <li>After a set time interval</li>
                <li>After a certain number of chat messages (line triggers)</li>
                <li>After a certain number of chat messages with a time delay</li>
            </ol>
            All three methods work together to ensure your chat never misses these messages.
        </div>
    </div>

    <h2 class="title is-3 has-text-light" id="troubleshooting">
        <span class="icon">
            <i class="fas fa-wrench"></i>
        </span>
        Troubleshooting
    </h2>
    <div class="columns is-multiline">
        <div class="column is-6">
            <div class="box has-background-dark has-text-light">
                <h4 class="title is-5 has-text-light">Bot Not Appearing in Chat</h4>
                <ul>
                    <li>Check that the bot is turned on in the dashboard</li>
                    <li>Verify the bot is added as a moderator</li>
                    <li>Try refreshing the dashboard and re-starting the bot</li>
                    <li>Check the bot status indicator in the dashboard ONLINE/OFFLINE</li>
                </ul>
            </div>
        </div>
        <div class="column is-6">
            <div class="box has-background-dark has-text-light">
                <h4 class="title is-5 has-text-light">Commands Not Working</h4>
                <ul>
                    <li>Ensure you're using the correct command prefix, the bot uses <code>!</code></li>
                    <li>Check if the command is enabled in settings</li>
                    <li>Verify the user has permission to use it</li>
                    <li>Some commands require premium features, check if this is the case</li>
                </ul>
            </div>
        </div>
        <div class="column is-6">
            <div class="box has-background-dark has-text-light">
                <h4 class="title is-5 has-text-light">Login Issues</h4>
                <ul>
                    <li>Try logging out and back in</li>
                    <li>Clear your browser cache and cookies</li>
                    <li>Make sure you're using a supported browser, all modern browsers are supported</li>
                    <li>Check if Twitch is experiencing issues <a href="https://status.twitch.tv/" target="_blank">here</a> as we may be affected by their downtime</li>
                </ul>
            </div>
        </div>
        <div class="column is-6">
            <div class="box has-background-dark has-text-light">
                <h4 class="title is-5 has-text-light">Permission Errors</h4>
                <ul>
                    <li>Ensure the bot is a moderator in your channel</li>
                    <li>Check that you have broadcaster permissions, only the broadcaster can start and stop the bot, our moderator dashboard does not have this capability</li>
                    <li>Some features require VIP or subscriber status if you've enabled them, check to make sure the user has the appropriate role</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="notification is-success has-background-dark has-text-light">
        <h3 class="title is-4 has-text-light">
            <span class="icon">
                <i class="fas fa-check-circle"></i>
            </span>
            Setup Complete!
        </h3>
        <p>Congratulations! Your Specter is now set up and running. Once started, the bot automatically joins your channel and remains available 24/7.</p>
        <p style="margin-top: 1rem;"><span class="has-text-weight-bold">Next Steps:</span></p>
        <ul>
            <li>Explore the dashboard to discover all available features</li>
            <li>Customize commands and settings to match your stream style</li>
            <li>Check out the help documentation for advanced features</li>
            <li>Join our Discord for community support and tips</li>
        </ul>
    </div>

    <h2 class="title is-3 has-text-light">Premium Features</h2>
    <div class="box has-background-dark has-text-light">
        <p>Some advanced features require a premium subscription:</p>
        <ul>
            <li><span class="has-text-weight-bold">AI Chat:</span> Have conversations with an AI in your chat</li>
            <li><span class="has-text-weight-bold">Advanced Music:</span> Use <code>!song</code> without connecting Spotify</li>
            <li><span class="has-text-weight-bold">Shared Bot Name (BotOfTheSpecter):</span> The default shared bot username used across the platform.</li>
            <li><span class="has-text-weight-bold">Custom Bot Name (Your Custom Bot Name, Experimental/Coming Soon):</span> Use your own bot username instead of "BotOfTheSpecter". This feature is experimental & is coming soon.</li>
        </ul>
        <p style="margin-top: 1rem;">Support the developer on Twitch to unlock these features!</p>
        <div style="margin-top: 1rem;">
            <a href="https://twitch.tv/gfaUnDead" target="_blank" class="button is-success">
                <span class="icon">
                    <i class="fab fa-twitch"></i>
                </span>
                <span>Support on Twitch</span>
            </a>
        </div>
    </div>

    <h2 class="title is-3 has-text-light">Need Help?</h2>
    <p>If you encounter issues during setup, don't hesitate to reach out:</p>
    <div class="columns is-multiline is-flex">
        <div class="column is-4">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-primary">
                        <i class="fab fa-discord fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Discord Support</h3>
                    <p>Join our community Discord for real-time help and support.</p>
                    <a href="https://discord.com/invite/ANwEkpauHJ" class="button is-primary is-small" target="_blank">
                        <span class="icon">
                            <i class="fab fa-discord"></i>
                        </span>
                        <span>Join Discord</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="column is-4">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-info">
                        <i class="fas fa-envelope fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Email Support</h3>
                    <p>Send us a detailed message about your issue.</p>
                    <a href="mailto:questions@botofthespecter.com" class="button is-info is-small">
                        <span class="icon">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <span>Email Us</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="column is-4">
            <div class="card has-background-dark has-shadow is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light has-text-centered">
                    <span class="icon is-large has-text-success">
                        <i class="fab fa-twitch fa-3x"></i>
                    </span>
                    <h3 class="title is-5 has-text-light">Live Support</h3>
                    <p>Catch us live on Twitch for immediate assistance.</p>
                    <a href="https://twitch.tv/gfaUnDead" class="button is-success is-small" target="_blank">
                        <span class="icon">
                            <i class="fab fa-twitch"></i>
                        </span>
                        <span>Watch Live</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'First Time Setup';
include 'layout.php';
?>
