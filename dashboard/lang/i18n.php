<?php
function load_translations($lang = 'en')
{
    $base = include __DIR__ . "/en.php";
    if ($lang === 'en')
        return $base;
    $file = __DIR__ . "/$lang.php";
    if (file_exists($file)) {
        $custom = include $file;
        return array_merge($base, $custom);
    }
    return $base;
}

$translations = load_translations(strtolower($userLanguage ?? 'en'));

function t($key, $replacements = [])
{
    global $translations;
    $text = $translations[$key] ?? $key;
    if (empty($replacements)) {
        return $text;
    }
    // Check if replacements are for sprintf (indexed array)
    if (array_keys($replacements) === range(0, count($replacements) - 1)) {
        return sprintf($text, ...$replacements);
    }
    // Named placeholders (associative array)
    $replacePairs = [];
    foreach ($replacements as $key => $value) {
        $replacePairs[':' . $key] = $value;
    }
    return strtr($text, $replacePairs);
}
