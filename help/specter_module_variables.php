<?php ob_start(); ?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li><a href="command_reference.php" class="has-text-light">Command Reference</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Module Variables</a></li>
    </ul>
</nav>
<h1 class="title has-text-light">Module Variables</h1>
<p class="subtitle has-text-light">Variables available for use in Welcome Messages, Ad Notices, and Twitch Chat Alerts.</p>
<div class="notification is-darker has-text-light" style="margin-bottom: 2rem;">
    <span class="has-text-weight-bold">Note:</span> Variables colored in <span style="color: #c813e0ff;">purple</span> are for the beta bot only and are currently in testing.
</div>
<div class="notification is-info has-text-dark mt-5">
    <p><strong>Pro Tip:</strong> You can combine multiple variables in a single message to create more dynamic and personalized alerts!</p>
    <p class="mt-2"><strong>Example:</strong>
    <code>Thank you (user) for (bits) bits! You've given a total of (total-bits) bits to the channel!</code>
    <br><br>In Chat, this would appear as:
    <code>Thank you BotOfTheSpecter for 100 bits! You've given a total of 5,000 bits to the channel!</code>
    </p>
</div>
<!-- General Variables -->
<h2 class="title is-4 has-text-light mt-5 mb-4">General Variables</h2>
<p class="subtitle is-6 has-text-light mb-4">These variables can be used across multiple modules.</p>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(user)</span><br>
                Displays the username of the person who triggered the event (follower, subscriber, raider, etc.).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for following!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for following!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 350px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(shoutout)</span> <span class="tag is-danger is-small">BETA ONLY</span><br>
                Triggers a shoutout for the user. The shoutout information is sent as a separate message after your alert.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Welcome (user)! (shoutout)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Welcome BotOfTheSpecter!</code><br><br>
                <code>Check out their channel at twitch.tv/BotOfTheSpecter - They were last playing Software and Game Development!</code>
            </div>
        </div>
    </div>
</div>
<!-- Welcome Message Variables -->
<h2 class="title is-4 has-text-light mt-5 mb-4">Welcome Message Variables</h2>
<p class="subtitle is-6 has-text-light mb-4">Variables available in the Welcome Messages module.</p>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(count)</span><br>
                Shows how many times the user has sent messages in chat (message count).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Welcome back (user)! You've sent (count) messages.</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Welcome back BotOfTheSpecter! You've sent 1,234 messages.</code>
            </div>
        </div>
    </div>
</div>
<!-- Ad Notice Variables -->
<h2 class="title is-4 has-text-light mt-5 mb-4">Ad Notice Variables</h2>
<p class="subtitle is-6 has-text-light mb-4">Variables available in the Ad Notices module.</p>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(time)</span><br>
                Displays when the ad will start or is starting.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Ad break starting at (time).</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Ad break starting at 3:45 PM.</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(duration)</span><br>
                Shows the length of the ad break in seconds.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Ad break will last (duration) seconds.</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Ad break will last 90 seconds.</code>
            </div>
        </div>
    </div>
</div>
<!-- Twitch Chat Alerts - Follower Variables -->
<h2 class="title is-4 has-text-light mt-5 mb-4">Twitch Chat Alerts Variables</h2>
<p class="subtitle is-6 has-text-light mb-4">Variables available in Twitch Chat Alerts for different events.</p>
<h3 class="title is-5 has-text-light mt-4 mb-3">Follower Alert Variables</h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(user)</span><br>
                The username of the new follower.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for following!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for following!</code>
            </div>
        </div>
    </div>
</div>
<h3 class="title is-5 has-text-light mt-4 mb-3">Bits & Cheers Variables</h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(user)</span><br>
                The username of the person who cheered bits.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for the bits!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for the bits!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(bits)</span><br>
                The number of bits cheered in this event.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you for (bits) bits!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you for 100 bits!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(total-bits)</span><br>
                The total number of bits this user has given to the channel.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>You've given (total-bits) bits total!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>You've given 5,000 bits total!</code>
            </div>
        </div>
    </div>
</div>
<h3 class="title is-5 has-text-light mt-4 mb-3">Raid Event Variables</h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(user)</span><br>
                The username of the raider.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Welcome raiders from (user)!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Welcome raiders from BotOfTheSpecter!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(viewers)</span><br>
                The number of viewers who joined with the raid.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(user) raided with (viewers) viewers!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>BotOfTheSpecter raided with 50 viewers!</code>
            </div>
        </div>
    </div>
</div>
<h3 class="title is-5 has-text-light mt-4 mb-3">Subscription Variables</h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(user)</span><br>
                The username of the subscriber or gifter.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for subscribing!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for subscribing!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(tier)</span><br>
                The subscription tier (Tier 1, Tier 2, Tier 3, or Prime).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>You are now a (tier) subscriber!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>You are now a Tier 1 subscriber!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(months)</span><br>
                The cumulative number of months the user has been subscribed.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Subscribed for (months) months!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Subscribed for 12 months!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(count)</span><br>
                The number of gift subscriptions given in this event.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Gifted (count) subscriptions!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Gifted 5 subscriptions!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(total-gifted)</span><br>
                The total number of gift subscriptions this user has given to the channel.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>You've gifted (total-gifted) subs total!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>You've gifted 50 subs total!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 300px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(gifter)</span> <span class="tag is-danger is-small">BETA ONLY</span><br>
                The username of the original gifter (for pay it forward events).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for paying it forward! They received a gift from (gifter).</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for paying it forward! They received a gift from gfaUnDead.</code>
            </div>
        </div>
    </div>
</div>
<h3 class="title is-5 has-text-light mt-4 mb-3">Subscription Upgrade Variables <span class="tag is-danger is-small">BETA ONLY</span></h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 300px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(user)</span><br>
                The username of the person who upgraded their subscription.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for upgrading to a paid subscription!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for upgrading to a paid subscription!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 300px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(tier)</span><br>
                The subscription tier they upgraded to (Tier 1, Tier 2, or Tier 3).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you for upgrading to a (tier) subscription!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you for upgrading to a Tier 1 subscription!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 300px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(gifter)</span> <span class="tag is-danger is-small">BETA ONLY</span><br>
                The username of the original gifter (for pay it forward events).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for paying it forward! They received a gift from (gifter).</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for paying it forward! They received a gift from gfaUnDead.</code>
            </div>
        </div>
    </div>
</div>
<h3 class="title is-5 has-text-light mt-4 mb-3">Subscription Upgrade Variables <span class="tag is-danger is-small">BETA ONLY</span></h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 300px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(user)</span><br>
                The username of the person who upgraded their subscription.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you (user) for upgrading to a paid subscription!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you BotOfTheSpecter for upgrading to a paid subscription!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 300px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(tier)</span><br>
                The subscription tier they upgraded to (Tier 1, Tier 2, or Tier 3).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Thank you for upgrading to a (tier) subscription!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Thank you for upgrading to a Tier 1 subscription!</code>
            </div>
        </div>
    </div>
</div>
<h3 class="title is-5 has-text-light mt-4 mb-3">Hype Train Variables</h3>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(level)</span><br>
                The current or final level of the hype train.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>Hype train is at level (level)!</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Hype train is at level 3!</code>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Module Variables - BotOfTheSpecter';
include 'layout.php';
?>