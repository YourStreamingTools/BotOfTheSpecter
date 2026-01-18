<?php
$openai_api_key = "";
$openai_organization_id = "";
$openai_project_id = "";
if (!isset($openai_config) || !is_array($openai_config)) {
    $openai_config = [
        'admin_key' => $openai_api_key,
        'organization_id' => $openai_organization_id,
        'project_id' => $openai_project_id,
        'start_time' => null,
        'limit' => 30,
    ];
}

return $openai_config;