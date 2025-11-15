<?php
session_start();

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

function sse_send($data, $event = null)
{
    if ($event) {
        echo "event: {$event}\n";
    }
    $lines = preg_split("/\r\n|\n|\r/", rtrim($data, "\n"));
    foreach ($lines as $line) {
        $line = str_replace("</", "<\\/", $line);
        echo "data: {$line}\n";
    }
    echo "\n";
    @ob_flush();
    @flush();
}

$accessToken = $_SESSION['access_token'] ?? null;
$username = $_SESSION['username'] ?? null;
$twitch_id = $_SESSION['twitchUserId'] ?? null;

if (!$accessToken || !$username || !$twitch_id) {
    sse_send('Unauthorized.', 'error');
    sse_send(json_encode(['success' => false]), 'done');
    exit;
}

$command = sprintf(
    'cd /home/botofthespecter && python3 sync-channel-rewards.py -channel %s -channelid %s -token %s',
    escapeshellarg($username),
    escapeshellarg($twitch_id),
    escapeshellarg($accessToken)
);

$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptors, $pipes, '/home/botofthespecter');
if (!is_resource($process)) {
    sse_send('Failed to start the sync script.', 'error');
    sse_send(json_encode(['success' => false]), 'done');
    exit;
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$read = [$pipes[1], $pipes[2]];

while (true) {
    $r = $read;
    $w = null;
    $e = null;
    $count = @stream_select($r, $w, $e, 1, 0);
    if ($count === false) {
        break;
    }
    if ($count > 0) {
        foreach ($r as $stream) {
            $chunk = stream_get_contents($stream);
            if ($chunk !== false && strlen($chunk) > 0) {
                sse_send($chunk);
            }
        }
    }
    if (feof($pipes[1]) && feof($pipes[2])) {
        break;
    }
}

$status = proc_close($process);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}

sse_send(json_encode(['success' => $status === 0, 'exit_code' => $status]), 'done');
exit;
?>