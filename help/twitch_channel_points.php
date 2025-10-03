<?php
ob_start();
?>
<h1 class="title has-text-light">Twitch Channel Points</h1>
<p class="subtitle has-text-light">Learn how to use Twitch Channel Points with BotOfTheSpecter for enhanced viewer engagement.</p>

<div class="columns is-multiline">
    <div class="column is-12">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-info-circle"></i>
                    </span>
                    What are Twitch Channel Points?
                </h2>
                <p>
                    Twitch Channel Points are a loyalty system that allows streamers to reward their viewers for watching, following, subscribing, and participating in the stream.
                    Viewers earn points over time and can redeem them for various rewards that you create. BotOfTheSpecter integrates seamlessly with Twitch's Channel Points system,
                    allowing you to automate responses and create custom experiences when viewers redeem rewards.
                </p>
                <div class="notification is-info mt-4">
                    <strong>Note:</strong> Channel Points are managed entirely through Twitch's dashboard. BotOfTheSpecter enhances this system by allowing you to set custom messages and automate responses to redemptions.
                </div>
            </div>
        </div>
    </div>

    <div class="columns is-flex is-full">
        <div class="column is-6">
            <div class="card has-background-dark has-shadow mb-4 is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light">
                    <h3 class="title is-5 has-text-light">
                        <span class="icon">
                            <i class="fas fa-cog"></i>
                        </span>
                        Setting Up Channel Points
                    </h3>
                    <ol class="ml-5">
                        <li>Go to your <a href="https://dashboard.twitch.tv/" target="_blank" class="has-text-link">Twitch Dashboard</a></li>
                        <li>Navigate to the "Channel Points" section</li>
                        <li>Create custom rewards with titles, costs, and descriptions</li>
                        <li>Enable the rewards you want to use</li>
                        <li>Use BotOfTheSpecter to sync and customize responses</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="column is-6">
            <div class="card has-background-dark has-shadow mb-4 is-flex" style="height: 100%;">
                <div class="card-content has-background-dark has-text-light">
                    <h3 class="title is-5 has-text-light">
                        <span class="icon">
                            <i class="fas fa-sync-alt"></i>
                        </span>
                        Syncing Rewards in Specter
                    </h3>
                    <p>
                        To use Channel Points with BotOfTheSpecter, you need to sync your rewards from Twitch. This allows the bot to recognize redemptions and respond accordingly.
                    </p>
                    <div class="content">
                        <ol>
                            <li>Log into your BotOfTheSpecter dashboard</li>
                            <li>Go to the Channel Rewards page</li>
                            <li>Click the "Sync Rewards" button</li>
                            <li>Wait for the sync to complete</li>
                            <li>Your rewards will appear in the table below</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="column is-12">
        <div class="notification is-warning">
            <strong>Tip:</strong> Sync your rewards whenever you add, modify, or remove rewards on Twitch to keep everything up to date.
        </div>
    </div>

    <div class="column is-12">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-edit"></i>
                    </span>
                    Customizing Reward Responses
                </h2>
                <p>
                    Once your rewards are synced, you can customize the bot's response for each redemption. This allows you to create personalized experiences for your viewers.
                </p>
                <div class="columns mt-4">
                    <div class="column is-6">
                        <h4 class="title is-6 has-text-light">How to Customize:</h4>
                        <ol class="ml-5">
                            <li>Find the reward in the Channel Rewards table</li>
                            <li>Click the "Edit" button next to the reward</li>
                            <li>Enter your custom message in the text area</li>
                            <li>Click "Save" to apply the changes</li>
                        </ol>
                    </div>
                    <div class="column is-6">
                        <h4 class="title is-6 has-text-light">Message Variables:</h4>
                        <p>You can use variables in your custom messages:</p>
                        <ul>
                            <li><code>(user)</code> - Tags the user who redeemed the reward.</li>
                            <li><code>(usercount)</code> - Shows how many times the user has redeemed the reward.</li>
                            <li><code>(userstreak)</code> - Shows how many times in a row the user has redeemed the reward.</li>
                            <li><code>(track)</code> - Tracks how many times the reward has been used, this is an internal count and does not get posted to chat.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="column is-12">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light">
                <h2 class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-lightbulb"></i>
                    </span>
                    Best Practices
                </h2>
                <div class="columns">
                    <div class="column is-4">
                        <h4 class="title is-6 has-text-light">Reward Design</h4>
                        <ul>
                            <li>Set appropriate point costs based on value</li>
                            <li>Use clear, descriptive titles</li>
                            <li>Include cooldowns for high-value rewards</li>
                            <li>Limit redemptions per stream/user if needed</li>
                        </ul>
                    </div>
                    <div class="column is-4">
                        <h4 class="title is-6 has-text-light">Engagement Tips</h4>
                        <ul>
                            <li>Announce rewards during stream</li>
                            <li>Create themed reward sets</li>
                            <li>Rotate rewards to keep things fresh</li>
                            <li>Monitor redemption patterns</li>
                        </ul>
                    </div>
                    <div class="column is-4">
                        <h4 class="title is-6 has-text-light">Bot Integration</h4>
                        <ul>
                            <li>Keep custom messages fun and engaging</li>
                            <li>Use variables to personalize responses</li>
                            <li>Test rewards before going live</li>
                            <li>Regularly sync rewards from Twitch</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="column is-12">
        <div class="card has-background-dark has-shadow">
            <div class="card-content has-background-dark has-text-light">
                <h2 class="title is-4 has-text-light">
                    <span class="icon">
                        <i class="fas fa-question-circle"></i>
                    </span>
                    Troubleshooting
                </h2>
                <div class="content">
                    <h4 class="title is-6 has-text-light">Common Issues:</h4>
                    <div class="columns is-flex">
                        <div class="column is-6">
                            <div class="card has-background-dark has-shadow mb-4 is-flex" style="height: 100%;">
                                <div class="card-content has-background-dark has-text-light">
                                    <h5 class="title is-6 has-text-light">
                                        <span class="icon">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </span>
                                        Rewards not appearing after sync
                                    </h5>
                                    <p>Make sure the rewards are enabled on Twitch and try syncing again from the dashboard.</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6">
                            <div class="card has-background-dark has-shadow mb-4 is-flex" style="height: 100%;">
                                <div class="card-content has-background-dark has-text-light">
                                    <h5 class="title is-6 has-text-light">
                                        <span class="icon">
                                            <i class="fas fa-comment-slash"></i>
                                        </span>
                                        Custom messages not working
                                    </h5>
                                    <p>Ensure you've saved the custom message and that the bot has the necessary mod permissions on your channel.<br>
                                    Check the bot's logs for any errors and report them on GitHub or the Discord Server.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="card has-background-dark has-shadow">
                            <div class="card-content has-background-dark has-text-light">
                                <h5 class="title is-6 has-text-light">
                                    <span class="icon">
                                        <i class="fas fa-robot"></i>
                                    </span>
                                    Redemptions not triggering responses
                                </h5>
                                <p>Verify that the reward is synced and that the bot is running. Make sure the reward title matches exactly between Twitch and Specter.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="notification is-info">
                    <strong>Need more help?</strong> Check the <a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" class="has-text-link">GitHub Issues</a> or join our <a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" class="has-text-link">Discord Server</a> for support.
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Twitch Channel Points';
include 'layout.php';
?>