<?php
const PET_TEMPLATE_CDN_BASE = 'https://cdn.botofthespecter.com/pet-templates/';
const PET_TEMPLATE_FILE_PREFIX = 'template:';

function pet_template_frame_defaults() {
    return [
        'frame_width' => 128,
        'frame_height' => 128,
        'frame_count' => 30,
        'fps' => 15,
        'loop' => 1,
    ];
}

function pet_template_anims($overrides = []) {
    $frame = pet_template_frame_defaults();
    $anims = [];
    foreach (['idle', 'happy', 'hype', 'sad', 'sleep', 'eat', 'gift'] as $name) {
        $extra = isset($overrides[$name]) && is_array($overrides[$name]) ? $overrides[$name] : [];
        $anims[$name] = array_merge($frame, ['file' => $name . '.png'], $extra);
    }
    return $anims;
}

function pet_template_extra_anims($names) {
    $frame = pet_template_frame_defaults();
    $anims = [];
    foreach ($names as $name) {
        $anims[$name] = array_merge($frame, ['file' => $name . '.png']);
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
        'cat' => [
            'id' => 'cat',
            'name_key' => 'pet_template_cat_name',
            'desc_key' => 'pet_template_cat_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'dog' => [
            'id' => 'dog',
            'name_key' => 'pet_template_dog_name',
            'desc_key' => 'pet_template_dog_desc',
            'preview' => 'idle.png',
            'animations' => array_merge(pet_template_anims(), pet_template_extra_anims(['bark', 'bite'])),
        ],
        'bat' => [
            'id' => 'bat',
            'name_key' => 'pet_template_bat_name',
            'desc_key' => 'pet_template_bat_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'alien' => [
            'id' => 'alien',
            'name_key' => 'pet_template_alien_name',
            'desc_key' => 'pet_template_alien_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'squirrel' => [
            'id' => 'squirrel',
            'name_key' => 'pet_template_squirrel_name',
            'desc_key' => 'pet_template_squirrel_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'chicken' => [
            'id' => 'chicken',
            'name_key' => 'pet_template_chicken_name',
            'desc_key' => 'pet_template_chicken_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'cow' => [
            'id' => 'cow',
            'name_key' => 'pet_template_cow_name',
            'desc_key' => 'pet_template_cow_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'duck' => [
            'id' => 'duck',
            'name_key' => 'pet_template_duck_name',
            'desc_key' => 'pet_template_duck_desc',
            'preview' => 'idle.png',
            'animations' => pet_template_anims(),
        ],
        'bunny' => [
            'id' => 'bunny',
            'name_key' => 'pet_template_bunny_name',
            'desc_key' => 'pet_template_bunny_desc',
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
    return PET_TEMPLATE_CDN_BASE . rawurlencode($packId) . '/' . rawurlencode($file) . '?v=20260827';
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

function pet_sprite_kind($filename) {
    if (pet_is_template_sprite($filename)) {
        return 'sheet';
    }
    $ext = strtolower((string) pathinfo(basename((string) $filename), PATHINFO_EXTENSION));
    return $ext === 'gif' ? 'gif' : 'sheet';
}

/**
 * Read GIF frame delays (hundredths of a second). Used so a GIF action
 * plays for one cycle, then the overlay can return to idle.
 */
function pet_gif_timing($path) {
    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) < 14) {
        return null;
    }
    $sig = substr($raw, 0, 6);
    if ($sig !== 'GIF87a' && $sig !== 'GIF89a') {
        return null;
    }
    $len = strlen($raw);
    $packed = ord($raw[10]);
    $pos = 13;
    if ($packed & 0x80) {
        $pos += 3 * (1 << (($packed & 0x07) + 1));
    }
    $delays = [];
    $guard = 0;
    while ($pos < $len && $guard < 20000) {
        $guard++;
        $b = ord($raw[$pos]);
        if ($b === 0x3B) {
            break;
        }
        if ($b === 0x21) {
            if ($pos + 2 >= $len) {
                break;
            }
            $label = ord($raw[$pos + 1]);
            if ($label === 0xF9 && $pos + 7 < $len && ord($raw[$pos + 2]) === 0x04) {
                $delay = unpack('v', substr($raw, $pos + 4, 2));
                $delays[] = is_array($delay) ? (int) $delay[1] : 0;
                $pos += 8;
                continue;
            }
            $pos += 2;
            while ($pos < $len) {
                $sz = ord($raw[$pos]);
                $pos += 1;
                if ($sz === 0) {
                    break;
                }
                $pos += $sz;
            }
            continue;
        }
        if ($b === 0x2C) {
            if ($pos + 10 >= $len) {
                break;
            }
            $imgPacked = ord($raw[$pos + 9]);
            $pos += 10;
            if ($imgPacked & 0x80) {
                $pos += 3 * (1 << (($imgPacked & 0x07) + 1));
            }
            if ($pos >= $len) {
                break;
            }
            $pos += 1;
            while ($pos < $len) {
                $sz = ord($raw[$pos]);
                $pos += 1;
                if ($sz === 0) {
                    break;
                }
                $pos += $sz;
            }
            continue;
        }
        break;
    }
    $count = count($delays);
    if ($count < 1) {
        $count = 1;
        $ms = 1000;
    } else {
        $ms = 0;
        foreach ($delays as $d) {
            $hundredths = ((int) $d) <= 1 ? 10 : (int) $d;
            $ms += $hundredths * 10;
        }
        if ($ms < 80) {
            $ms = 80;
        }
    }
    $fps = (int) max(1, min(60, (int) round($count * 1000 / $ms)));
    return [
        'frame_count' => $count,
        'duration_ms' => $ms,
        'fps' => $fps,
    ];
}
