<?php
const PET_TEMPLATE_CDN_BASE = 'https://cdn.botofthespecter.com/pet-templates/';
const PET_TEMPLATE_FILE_PREFIX = 'template:';

function pet_template_anims($overrides = []) {
    $frame = [
        'frame_width' => 128,
        'frame_height' => 128,
        'frame_count' => 30,
        'fps' => 15,
        'loop' => 1,
    ];
    $anims = [];
    foreach (['idle', 'happy', 'hype', 'sad', 'sleep', 'eat'] as $name) {
        $extra = isset($overrides[$name]) && is_array($overrides[$name]) ? $overrides[$name] : [];
        $anims[$name] = array_merge($frame, ['file' => $name . '.png'], $extra);
    }
    return $anims;
}

function pet_template_catalog() {
    return [
        'specter' => [
            'id' => 'specter',
            'name_key' => 'pet_template_specter_name',
            'desc_key' => 'pet_template_specter_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'bot' => [
            'id' => 'bot',
            'name_key' => 'pet_template_bot_name',
            'desc_key' => 'pet_template_bot_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
    ];
}

function pet_template_get($packId) {
    $catalog = pet_template_catalog();
    return isset($catalog[$packId]) ? $catalog[$packId] : null;
}

function pet_is_template_sprite($filename) {
    return strncmp((string) $filename, PET_TEMPLATE_FILE_PREFIX, strlen(PET_TEMPLATE_FILE_PREFIX)) === 0;
}

function pet_template_token($packId, $file) {
    $packId = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $packId));
    $file = basename((string) $file);
    if ($packId === '' || $file === '') {
        return '';
    }
    return PET_TEMPLATE_FILE_PREFIX . $packId . '/' . $file;
}

function pet_template_cdn_url($packId, $file) {
    $packId = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $packId));
    $file = basename((string) $file);
    if ($packId === '' || $file === '') {
        return '';
    }
    return PET_TEMPLATE_CDN_BASE . rawurlencode($packId) . '/' . rawurlencode($file);
}

function pet_parse_template_sprite($filename) {
    $filename = (string) $filename;
    if (!pet_is_template_sprite($filename)) {
        return null;
    }
    $rest = substr($filename, strlen(PET_TEMPLATE_FILE_PREFIX));
    $parts = explode('/', $rest, 2);
    if (count($parts) !== 2) {
        return null;
    }
    $packId = preg_replace('/[^a-z0-9_-]/', '', strtolower($parts[0]));
    $file = basename($parts[1]);
    if ($packId === '' || $file === '') {
        return null;
    }
    return ['pack' => $packId, 'file' => $file];
}

function pet_template_spec_for_sprite($filename) {
    $parsed = pet_parse_template_sprite($filename);
    if (!$parsed) {
        return null;
    }
    $pack = pet_template_get($parsed['pack']);
    if (!$pack) {
        return null;
    }
    $animName = strtolower((string) pathinfo($parsed['file'], PATHINFO_FILENAME));
    return isset($pack['animations'][$animName]) ? $pack['animations'][$animName] : null;
}

function pet_resolve_sprite_url($username, $filename) {
    $parsed = pet_parse_template_sprite($filename);
    if ($parsed) {
        return pet_template_cdn_url($parsed['pack'], $parsed['file']);
    }
    $filename = basename((string) $filename);
    if ($filename === '' || strpos($filename, '..') !== false) {
        return '';
    }
    return 'https://media.botofthespecter.com/' . rawurlencode((string) $username) . '/pet/' . rawurlencode($filename);
}
