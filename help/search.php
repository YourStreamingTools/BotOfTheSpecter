<?php
// search.php

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$queryLower = mb_strtolower($query, 'UTF-8');
$isAjax = isset($_GET['ajax']);

$pages = [
    'index.php',
    'command_reference.php',
    'custom_command_variables.php',
    'specter_module_variables.php',
    'faq.php',
    'markdown.php',
    'obs_audio_monitoring.php',
    'troubleshooting.php',
    'twitch_channel_points.php',
    'tts_setup.php',
    'setup.php',
    'run_yourself.php',
    'spotify_setup.php',
    'custom_api.php'
];

function buildSearchIndex(array $pages): array {
    $index = [];
    foreach ($pages as $page) {
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . $page;
        if (!is_file($fullPath)) {
            continue;
        }
        $fullHtml = (function (string $includePath): string {
            ob_start();
            include $includePath;
            return (string) ob_get_clean();
        })($fullPath);
        $contentHtml = '';
        if (preg_match('/<main[^>]*class=["\'][^"\']*\bsection\b[^"\']*["\'][^>]*>(.*?)<\/main>/si', $fullHtml, $matches)) {
            $contentHtml = $matches[1];
        }
        if ($contentHtml === '') {
            $contentHtml = $fullHtml;
        }
        $text = html_entity_decode(strip_tags($contentHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text ?? '');
        $index[$page] = trim((string) $text);
    }
    return $index;
}

function buildSnippet(string $text, string $queryLower, int $radius = 100): string {
    if ($text === '') {
        return '';
    }
    $textLower = mb_strtolower($text, 'UTF-8');
    $pos = mb_strpos($textLower, $queryLower, 0, 'UTF-8');
    if ($pos === false) {
        $snippet = mb_substr($text, 0, $radius, 'UTF-8');
        return mb_strlen($text, 'UTF-8') > $radius ? $snippet . '...' : $snippet;
    }
    $start = max(0, $pos - (int) floor($radius / 2));
    $snippet = mb_substr($text, $start, $radius, 'UTF-8');
    if ($start > 0) {
        $snippet = '...' . $snippet;
    }
    if (mb_strlen($text, 'UTF-8') > ($start + $radius)) {
        $snippet .= '...';
    }
    return $snippet;
}

$index = buildSearchIndex($pages);

// Best-effort cache write for debugging/inspection without affecting search behavior.
@file_put_contents(
    __DIR__ . DIRECTORY_SEPARATOR . 'search_index.json',
    json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT),
    LOCK_EX
);

$results = [];
if ($queryLower !== '') {
    foreach ($index as $page => $text) {
        if ($text === '') {
            continue;
        }
        if (mb_strpos(mb_strtolower($text, 'UTF-8'), $queryLower, 0, 'UTF-8') !== false) {
            $results[$page] = $text;
        }
    }
}

if ($isAjax) {
    // Return JSON for AJAX requests
    header('Content-Type: application/json');
    $jsonResults = [];
    foreach ($results as $page => $text) {
        $snippet = buildSnippet($text, $queryLower, 120);
        $jsonResults[] = [
            'page' => $page,
            'title' => ucfirst(str_replace('_', ' ', basename($page, '.php'))),
            'snippet' => htmlspecialchars($snippet)
        ];
    }
    echo json_encode($jsonResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Page display
if (empty($query)) {
    header('Location: index.php');
    exit;
}

ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Search</a></li>
    </ul>
</nav>
<h1 class="title has-text-light">Search Results</h1>
<p class="subtitle has-text-light">Results for: "<?php echo htmlspecialchars($query); ?>"</p>
<?php if (empty($results)): ?>
    <p class="has-text-light">No results found.</p>
<?php else: ?>
    <div class="content has-text-light">
        <?php foreach ($results as $page => $text): ?>
            <div class="box has-background-dark">
                <h2 class="title is-5 has-text-light">
                    <a href="<?php echo htmlspecialchars($page); ?>" class="has-text-light">
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', basename($page, '.php')))); ?>
                    </a>
                </h2>
                <p><?php echo htmlspecialchars(buildSnippet($text, $queryLower, 220)); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Search Results';
include 'layout.php';
?>