<?php ob_start(); ?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li><a href="command_reference.php" class="has-text-light">Command Reference</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Custom Command Variables</a></li>
    </ul>
</nav>
<h1 class="title has-text-light">Custom Command Variables</h1>
<p class="subtitle has-text-light">A comprehensive guide to using variables in your custom commands.</p>
<div class="notification is-darker has-text-light" style="margin-bottom: 2rem;">
    <span class="has-text-weight-bold">Note:</span> Variables colored in <span style="color: #c813e0ff;">purple</span> are for the beta bot only and are currently in testing.
</div>
<div class="columns is-desktop is-multiline">
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(customapi.URL)</span><br>
                Fetches data from a custom API endpoint. Replace URL with your API endpoint. Use <code>json.URL</code> to parse the response as JSON, otherwise returns raw text.<br>
                <span class="has-text-weight-bold">Examples:</span><br>
                <code>(customapi.https://api.example.com/data)</code> - Raw response<br>
                <code>(customapi.json.https://api.example.com/data)</code> - JSON parsed<br>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>API Response: {"status": "success"}</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(count)</span><br>
                Displays the number of times this command has been used.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(count)</code><br>
                <span class="has-text-weight-bold">In Chat:</span><br>
                <code>This command has been used 42 times.</code><br><br>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(daysuntil.DATE)</span><br>
                Calculates the number of days until a specific date. Format: YYYY-MM-DD.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(daysuntil.2024-12-25)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>There are 42 days until Christmas.</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(command.COMMAND)</span><br>
                Executes another custom command. Replace COMMAND with the command name.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(command.othercommand)</code>
                <br><span class="has-text-weight-bold">In Chat:</span>
                <br>Response from othercommand
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(user)</span> | <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(author)</span><br>
                Displays the username of the person who triggered the command.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(user)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Hello, streamername!</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(random.percent)</span><br>
                Generates a random percentage between 0% and 100%. Use <span style="color: #3273dc;">(random.percent.LOWER-UPPER)</span> for custom range.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(random.percent)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Your random percentage is 73%.</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(random.number)</span><br>
                Generates a random number between 0 and 100. Use <span style="color: #3273dc;">(random.number.LOWER-UPPER)</span> for custom range.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(random.number)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Your random number is 42.</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(random.pick.*)</span><br>
                Randomly selects one option from a list. Separate options with a period (.).<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(random.pick.Option1.Option2.Option3)</code><br>
                <span class="has-text-weight-bold">In Chat:</span><br>
                <code>I randomly picked: Option2</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(math.*)</span><br>
                Performs mathematical calculations. Supports +, -, *, /, and parentheses.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(math.5+3*2)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>The result is 16.</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(usercount)</span><br>
                Displays the total number of unique users who have used this command.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(usercount)</code><br>
                <span class="has-text-weight-bold">In Chat:</span><br>
                <code>This command has been used by 15 unique users.</code><br><br>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(timeuntil.DATE-TIME)</span><br>
                Calculates the time remaining until a specific date and time. Format: YYYY-MM-DD HH:MM.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(timeuntil.2024-12-25 00:00)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>There are 42 days, 12 hours, 30 minutes until Christmas.</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #3273dc;">(game)</span><br>
                Displays the current game/category being streamed.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(game)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Currently playing: Just Chatting</code>
            </div>
        </div>
    </div>
    <div class="column is-4">
        <div class="card has-background-dark has-shadow mb-4">
            <div class="card-content has-background-dark has-text-light" style="height: 250px; word-break: break-word;">
                <span class="has-text-weight-bold variable-title" style="color: #c813e0ff;">(call.COMMAND)</span><br>
                Calls an internal command as an alias. Replace COMMAND with the internal command name.<br>
                <span class="has-text-weight-bold">Example:</span><br>
                <code>(call.ping)</code>
                <br><span class="has-text-weight-bold">In Chat:</span><br>
                <code>Pong!</code>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Custom Command Variables';
include 'layout.php';
?>
