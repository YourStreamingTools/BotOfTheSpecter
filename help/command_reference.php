<?php
// Load command data from JSON file
$jsonText = file_get_contents(__DIR__ . '/../api/builtin_commands.json');
$cmdData = json_decode($jsonText, true)['commands'];
// Parse command data for descriptions
$commands = [];
foreach ($cmdData as $cmdKey => $cmdInfo) {
    if (is_array($cmdInfo)) {
        $commands[$cmdKey] = [
            'description' => $cmdInfo['description'] ?? 'No description available',
            'usage' => $cmdInfo['usage'] ?? '!' . $cmdKey
        ];
    } else {
        // Backwards compatibility for old string format
        $commands[$cmdKey] = [
            'description' => $cmdInfo,
            'usage' => '!' . $cmdKey
        ];
    }
}
// Sort commands alphabetically
ksort($commands);

// Start output buffering
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Command Reference</a></li>
    </ul>
</nav>
<h1 class="title is-2 has-text-light">Command Reference</h1>
<p class="subtitle has-text-light">Complete list of available commands for BotOfTheSpecter</p>
<div class="content has-text-light">
    <p>All commands are prefixed with <code>!</code> (e.g., <code>!help</code>). Some commands require moderator permissions.</p>
    <h2 class="title is-4 has-text-light">Built-in Commands</h2>
    <table class="table is-fullwidth has-background-dark has-text-light">
        <thead>
            <tr>
                <th class="has-text-light">Command</th>
                <th class="has-text-light">Description</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($commands as $cmdName => $cmdInfo): ?>
            <tr>
                <td><code>!<?php echo htmlspecialchars($cmdName); ?></code></td>
                <td><?php echo htmlspecialchars($cmdInfo['description']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h2 class="title is-4 has-text-light">Custom Commands</h2>
    <p>You can create custom commands through the dashboard. These are user-defined and can vary per channel.</p>
    <div class="notification is-info has-background-dark">
        <p class="has-text-light">For a complete and up-to-date list of commands, use <code>!commands</code> in your channel or check the dashboard.</p>
    </div>
    <h2 class="title is-4 has-text-light">Additional Resources</h2>
    <ul>
        <li><a href="index.php" class="has-text-link">Back to Help Home</a></li>
        <li><a href="troubleshooting.php" class="has-text-link">Troubleshooting Guide</a></li>
        <li><a href="https://api.botofthespecter.com/docs" class="has-text-link" target="_blank">API Documentation</a></li>
    </ul>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Command Reference';
include 'layout.php';
?>
