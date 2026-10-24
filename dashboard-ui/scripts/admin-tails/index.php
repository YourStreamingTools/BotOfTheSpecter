<?php
// GET: the data the old page rendered server side (exchange rate quota, bot
// message counts, message templates) plus the page strings. The handlers above
// answer ?ajax=online_channels|validate_shoutout_user|bot_message_counts|ai_platform_stats
// and the POSTs (service control, token refresh scripts, quota check, send message, shoutout).
ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'exchangerate' => $exchangerateQuota,
    'bot_messages' => $botMessageStats,
    'message_systems' => $messageSystemNames,
    'templates' => $message_templates,
    'L' => badm_lang('admin_index_'),
    'youtube' => t('navbar_youtube'),
], JSON_UNESCAPED_UNICODE);
exit;
