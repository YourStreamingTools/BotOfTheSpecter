<?php
// Server-side checks for the Discord API helpers. A request may only touch the
// Discord server the signed-in user saved (discord_users.guild_id), sends also
// re-check that the user still owns that server, and every channel a message is
// sent to must belong to it.

if (!function_exists('discord_guard_fail')) {
function discord_guard_fail(string $message, int $code = 403): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message, 'error' => $message]);
    exit();
}

// The user's discord_users row (guild_id and access_token), cached per request.
function discord_guard_row(int $user_id): array
{
    static $rows = [];
    if (isset($rows[$user_id])) return $rows[$user_id];
    include '/var/www/config/database.php';
    $c = @new mysqli($db_servername, $db_username, $db_password, 'website');
    if ($c->connect_error) discord_guard_fail('Discord settings are unavailable right now.', 503);
    $st = $c->prepare('SELECT guild_id, access_token FROM discord_users WHERE user_id = ?');
    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    $c->close();
    return $rows[$user_id] = $row;
}

// Stops the request unless $server_id is the user's saved Discord server.
function discord_guard_saved_guild(int $user_id, $server_id): string
{
    $server_id = trim((string) $server_id);
    $saved = trim((string) (discord_guard_row($user_id)['guild_id'] ?? ''));
    if ($user_id <= 0 || !preg_match('/^\d{15,22}$/', $server_id) || $saved === '' || !hash_equals($saved, $server_id)) {
        discord_guard_fail('You can only change the Discord server saved on your account.');
    }
    return $server_id;
}

function discord_guard_get(string $url, string $auth)
{
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Authorization: $auth\r\n", 'timeout' => 10, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

// Stops the request unless the user still owns the server (same rule as the
// server picker on discordbot.php: owner only).
function discord_guard_owner(int $user_id, string $server_id): void
{
    $token = (string) (discord_guard_row($user_id)['access_token'] ?? '');
    if ($token === '') discord_guard_fail('Link your Discord account first.');
    $guilds = discord_guard_get('https://discord.com/api/v10/users/@me/guilds', 'Bearer ' . $token);
    if ($guilds === null || !array_is_list($guilds)) discord_guard_fail('Could not check your Discord server right now. Please try again.', 502);
    foreach ($guilds as $g) {
        if ((string) ($g['id'] ?? '') === $server_id && ($g['owner'] ?? false) === true) return;
    }
    discord_guard_fail('You can only send to a Discord server you own.');
}

// Stops the request unless $channel_id is a channel in $server_id.
function discord_guard_channel(string $server_id, $channel_id): string
{
    $channel_id = trim((string) $channel_id);
    if (!preg_match('/^\d{15,22}$/', $channel_id)) discord_guard_fail('That channel ID is not valid.', 400);
    static $channels = [];
    if (!isset($channels[$server_id])) {
        include '/var/www/config/discord.php';
        if (empty($bot_token)) discord_guard_fail('Could not check your Discord server right now. Please try again.', 502);
        $list = discord_guard_get("https://discord.com/api/v10/guilds/$server_id/channels", 'Bot ' . $bot_token);
        if ($list === null || !array_is_list($list)) discord_guard_fail('Could not check your Discord server right now. Please try again.', 502);
        $channels[$server_id] = array_map(fn($c) => (string) ($c['id'] ?? ''), $list);
    }
    if (!in_array($channel_id, $channels[$server_id], true)) discord_guard_fail('That channel is not in your Discord server.', 400);
    return $channel_id;
}
}
