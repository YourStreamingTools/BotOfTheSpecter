<?php
// GET: every user's Discord config (no tokens: is_linked only) plus the page strings.
$discord_conn->close();
header('Content-Type: application/json');
echo json_encode(['success' => true, 'configs' => array_values($discordConfigData), 'L' => badm_lang('admin_discord_overview_')], JSON_UNESCAPED_UNICODE);
exit;
