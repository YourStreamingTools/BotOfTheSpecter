<?php
require_once __DIR__ . '/../includes/session.php';
support_session_start();

$normal = [
    'alloy'   => 'Clear, crisp, and professional',
    'ash'     => 'Warm and friendly',
    'ballad'  => 'Melodic and expressive',
    'coral'   => 'Energetic and bright',
    'echo'    => 'Deep and resonant',
    'fable'   => 'Storyteller voice',
    'nova'    => 'Fast-paced and dynamic',
    'onyx'    => 'Smooth and sophisticated',
    'sage'    => 'Thoughtful and calm',
    'shimmer' => 'Gentle and uplifting',
    'verse'   => 'Bright and conversational',
];

$expressive = [];
$paths = [
    '/var/www/cdn/help/tts/expressive/voices.json',
];
foreach ($paths as $path) {
    if (is_file($path)) {
        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data)) {
            $expressive = $data;
            break;
        }
    }
}
if (!$expressive) {
    $cdn = @file_get_contents('https://cdn.botofthespecter.com/help/tts/expressive/voices.json');
    if (is_string($cdn) && $cdn !== '') {
        $data = json_decode($cdn, true);
        if (is_array($data)) {
            $expressive = $data;
        }
    }
}

json_out(['ok' => true, 'normal' => $normal, 'expressive' => $expressive]);
