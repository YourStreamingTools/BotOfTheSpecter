<?php
require_once __DIR__ . '/../includes/session.php';
support_session_start();

$ch = curl_init('https://api.botofthespecter.com/commands/info');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_FAILONERROR    => true,
]);
$commandsJson = curl_exec($ch);
curl_close($ch);
$cmdData = $commandsJson ? (json_decode($commandsJson, true)['commands'] ?? []) : [];
$commands = [];
foreach ($cmdData as $k => $v) {
    $commands[$k] = is_array($v) ? $v : ['description' => (string) $v];
}
ksort($commands);

json_out(['ok' => true, 'commands' => $commands]);
