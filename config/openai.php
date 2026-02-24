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
        'pricing_per_million' => [
            'gpt-5' => ['input' => 1.25, 'output' => 10.00],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
            'gpt-5-chat-latest' => ['input' => 1.25, 'output' => 10.00],
            'gpt-5-codex' => ['input' => 1.25, 'output' => 10.00],
            'gpt-5-codex-mini' => ['input' => 0.25, 'output' => 2.00],
            'gpt-5-codex-nano' => ['input' => 0.05, 'output' => 0.40],
            'gpt-5-codex-chat-latest' => ['input' => 1.25, 'output' => 10.00],
            'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
            'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
            'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            'gpt-4o-mini-tts' => ['input' => 0.60, 'output' => 12.00],
            'gpt-4o-transcribe' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-transcribe-diarize' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini-transcribe' => ['input' => 1.25, 'output' => 5.00],
            'o1' => ['input' => 15.00, 'output' => 60.00],
            'o1-pro' => ['input' => 150.00, 'output' => 600.00],
            'o3' => ['input' => 2.00, 'output' => 8.00],
            'o3-pro' => ['input' => 20.00, 'output' => 80.00],
            'o4-mini' => ['input' => 1.10, 'output' => 4.40],
            'default' => ['input' => 2.50, 'output' => 10.00],
        ],
    ];
}

return $openai_config;