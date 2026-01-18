<?php
$openai_api_key = "";
if (!isset($openai_config) || !is_array($openai_config)) {
    $openai_config = [
        'admin_key' => $openai_api_key,
        'start_time' => 'today',
        'limit' => 1,
    ];
}

return $openai_config;