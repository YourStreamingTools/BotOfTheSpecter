<?php
require_once dirname(__DIR__) . '/includes/session.php';
roadmap_session_start();

function extract_version_date(string $filePath): DateTime {
    $content = (string) file_get_contents($filePath);
    if (preg_match('/\((\d{4}-\d{2}-\d{2})\)/', $content, $matches)) {
        return new DateTime($matches[1]);
    }
    return new DateTime('@' . filemtime($filePath));
}

function parse_version_file(string $filePath): array {
    $content = (string) file_get_contents($filePath);
    $versionNumber = '';
    if (preg_match('/Version\s+([\d.]+)/', $content, $matches)) {
        $versionNumber = $matches[1];
    }
    $date = '';
    if (preg_match('/\((\d{4}-\d{2}-\d{2})\)/', $content, $matches)) {
        $date = $matches[1];
    }
    $summary = '';
    foreach (explode("\n", $content) as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '' && strpos($trimmed, '#') !== 0 && strpos($trimmed, '```') !== 0 && strpos($trimmed, '-') !== 0 && strpos($trimmed, '*') !== 0) {
            $summary = preg_replace('/\*\*(.*?)\*\*/', '$1', $trimmed);
            $summary = preg_replace('/\*(.*?)\*/', '$1', $summary);
            $summary = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $summary);
            break;
        }
    }
    return [
        'version' => $versionNumber,
        'date' => $date,
        'summary' => $summary,
        'markdown' => $content,
    ];
}

$wantVersion = trim((string) ($_GET['version'] ?? ''));
$docsPath = dirname(__DIR__, 2) . '/docs';
$versionFiles = [];
if (is_dir($docsPath)) {
    foreach (scandir($docsPath, SCANDIR_SORT_DESCENDING) as $file) {
        if (!preg_match('/^(\d+\.\d+(?:\.\d+)*)\.md$/', $file, $matches)) {
            continue;
        }
        $filePath = $docsPath . '/' . $file;
        $date = extract_version_date($filePath);
        $versionFiles[] = [
            'file' => $file,
            'path' => $filePath,
            'version' => $matches[1],
            'date_obj' => $date,
            'timestamp' => $date->getTimestamp(),
        ];
    }
}
usort($versionFiles, static fn($a, $b) => $b['timestamp'] - $a['timestamp']);

if ($wantVersion !== '') {
    foreach ($versionFiles as $vf) {
        if ($vf['version'] === $wantVersion) {
            $parsed = parse_version_file($vf['path']);
            json_out(['ok' => true, 'version' => $parsed]);
        }
    }
    json_out(['ok' => false, 'error' => 'Version not found'], 404);
}

$grouped = [];
foreach ($versionFiles as $vf) {
    $parsed = parse_version_file($vf['path']);
    unset($parsed['markdown']);
    $yearMonth = $vf['date_obj']->format('Y-m');
    if (!isset($grouped[$yearMonth])) {
        $grouped[$yearMonth] = [
            'key' => $yearMonth,
            'month' => $vf['date_obj']->format('F Y'),
            'versions' => [],
        ];
    }
    $grouped[$yearMonth]['versions'][] = $parsed;
}
krsort($grouped);

json_out(['ok' => true, 'groups' => array_values($grouped)]);
