<?php
function load_translations($lang = 'en') {
    $base = include __DIR__ . "/en.php";
    if ($lang === 'en') return $base;
    $file = __DIR__ . "/$lang.php";
    if (file_exists($file)) {
        $custom = include $file;
        return array_merge($base, $custom);
    }
    return $base;
}

$translations = load_translations(strtolower($userLanguage ?? 'en'));

function t($key) {
    global $translations;
    return $translations[$key] ?? $key;
}
