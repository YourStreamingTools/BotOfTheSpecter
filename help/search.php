<?php
// search.php
$query = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';

$isAjax = isset($_GET['ajax']);

// Rebuild search index on every search
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

$index = [];

foreach ($pages as $page) {
    if (!file_exists($page)) continue;
    ob_start();
    include $page;
    $full_html = ob_get_clean();
    // Extract the main content
    preg_match('/<main class="section">(.*?)<\/main>/s', $full_html, $matches);
    $content_html = $matches[1] ?? '';
    $text = strip_tags($content_html);
    $index[$page] = $text;
}

file_put_contents('search_index.json', json_encode($index));

$indexFile = 'search_index.json';
$results = [];

if (file_exists($indexFile) && !empty($query)) {
    $index = json_decode(file_get_contents($indexFile), true);
    foreach ($index as $page => $text) {
        if (strpos(strtolower($text), $query) !== false) {
            $results[$page] = $text;
        }
    }
}

if ($isAjax) {
    // Return JSON for AJAX requests
    header('Content-Type: application/json');
    $jsonResults = [];
    foreach ($results as $page => $text) {
        $pos = stripos($text, $query);
        $start = max(0, $pos - 50);
        $snippet = substr($text, $start, 100);
        if ($start > 0) $snippet = '...' . $snippet;
        if (strlen($text) > $start + 100) $snippet .= '...';
        $jsonResults[] = [
            'page' => $page,
            'title' => ucfirst(str_replace('_', ' ', basename($page, '.php'))),
            'snippet' => htmlspecialchars($snippet)
        ];
    }
    echo json_encode($jsonResults);
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
<p class="subtitle has-text-light">Results for: "<?php echo htmlspecialchars($_GET['q']); ?>"</p>
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
                <p><?php
                    $pos = stripos($text, $query);
                    $start = max(0, $pos - 100);
                    $snippet = substr($text, $start, 200);
                    if ($start > 0) $snippet = '...' . $snippet;
                    if (strlen($text) > $start + 200) $snippet .= '...';
                    echo htmlspecialchars($snippet);
                ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Search Results';
include 'layout.php';
?>