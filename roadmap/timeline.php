<?php
// Timeline view using changelog versions
date_default_timezone_set('Australia/Sydney');
session_start();

$markdownParsers = [
    'https://cdn.botofthespecter.com/css/Markdown/php-markdown-2.0.0/Michelf/MarkdownExtra.inc.php',
    'https://cdn.botofthespecter.com/css/Markdown/php-markdown-2.0.0/Michelf/Markdown.inc.php',
    'https://cdn.botofthespecter.com/css/Markdown/php-markdown-2.0.0/Michelf/MarkdownInterface.inc.php',
];

foreach ($markdownParsers as $parserPath) {
    if (class_exists('Michelf\\MarkdownExtra') || class_exists('Michelf\\Markdown')) {
        break;
    }
    @include_once $parserPath;
}

// Set page metadata
$pageTitle = 'Timeline';

// Helper function to extract date from version file
function extractVersionDate($filePath) {
    $content = file_get_contents($filePath);
    // Try to extract date from common patterns
    if (preg_match('/\((\d{4}-\d{2}-\d{2})\)/', $content, $matches)) {
        return new DateTime($matches[1]);
    }
    // Fallback to file modification time
    $mtime = filemtime($filePath);
    return new DateTime('@' . $mtime);
}

// Helper function to extract version number and summary from markdown
function parseVersionFile($filePath) {
    $content = file_get_contents($filePath);
    // Extract version number
    $versionNumber = '';
    if (preg_match('/Version\s+([\d.]+)/', $content, $matches)) {
        $versionNumber = $matches[1];
    }
    // Extract date
    $date = '';
    if (preg_match('/\((\d{4}-\d{2}-\d{2})\)/', $content, $matches)) {
        $date = $matches[1];
    }
    // Extract introduction/summary (first meaningful paragraph or line)
    $summary = '';
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Skip headers, empty lines, code blocks, and list items
        if (!empty($trimmed) && strpos($trimmed, '#') !== 0 && strpos($trimmed, '```') !== 0 && strpos($trimmed, '-') !== 0 && strpos($trimmed, '*') !== 0) {
            // Clean up markdown bold/italic/links
            $summary = preg_replace('/\*\*(.*?)\*\*/', '$1', $trimmed);
            $summary = preg_replace('/\*(.*?)\*/', '$1', $summary);
            $summary = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $summary);
            break;
        }
    }
    // Extract all sections including nested subsections
    $sections = [];
    $currentSection = '';
    $currentSubsection = '';
    $sectionItems = [];
    foreach ($lines as $line) {
        // Match level 2 headers (##)
        if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
            if ($currentSection && !empty($sectionItems)) {
                if ($currentSubsection) {
                    $sections[$currentSection][$currentSubsection] = $sectionItems;
                } else {
                    $sections[$currentSection] = $sectionItems;
                }
            }
            $currentSection = trim($matches[1]);
            $currentSubsection = '';
            $sectionItems = [];
        }
        // Match level 3 headers (###) as subsections
        elseif (preg_match('/^###\s+(.+)$/', $line, $matches)) {
            if ($currentSubsection && !empty($sectionItems)) {
                $sections[$currentSection][$currentSubsection] = $sectionItems;
                $sectionItems = [];
            }
            $currentSubsection = trim($matches[1]);
        }
        // Match bullet points (with * or -)
        elseif ($currentSection && preg_match('/^\s*[\*\-]\s+(.+)$/', $line, $matches)) {
            $item = trim($matches[1]);
            // Remove markdown links but keep text
            $item = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $item);
            $sectionItems[] = $item;
        }
    }
    // Don't forget the last section
    if ($currentSection && !empty($sectionItems)) {
        if ($currentSubsection) {
            $sections[$currentSection][$currentSubsection] = $sectionItems;
        } else {
            $sections[$currentSection] = $sectionItems;
        }
    }
    $renderedHtml = '';
    if (class_exists('Michelf\\MarkdownExtra')) {
        $renderedHtml = Michelf\MarkdownExtra::defaultTransform($content);
    } elseif (class_exists('Michelf\\Markdown')) {
        $renderedHtml = Michelf\Markdown::defaultTransform($content);
    } else {
        $renderedHtml = '';
    }
    return [
        'version' => $versionNumber,
        'date' => $date,
        'summary' => $summary,
        'sections' => $sections,
        'html' => $renderedHtml,
        'markdown' => $content
    ];
}

// Load all version files from docs folder
$docsPath = dirname(__FILE__) . '/../docs';
$versionFiles = [];

if (is_dir($docsPath)) {
    $files = scandir($docsPath, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        // accept versions with two or more dot-separated numeric parts (1.2, 1.2.3, 1.2.3.4, ...)
        if (preg_match('/^(\d+\.\d+(?:\.\d+)*)\.md$/', $file, $matches)) {
            // compute path, version and date before using them
            $filePath = $docsPath . '/' . $file;
            $versionNum = $matches[1];
            $date = extractVersionDate($filePath);
            $versionFiles[] = array(
                'file' => $file,
                'path' => $filePath,
                'version' => $versionNum,
                'date' => $date,
                'timestamp' => $date->getTimestamp()
            );
        }
    }
}

// Sort by date (newest first)
usort($versionFiles, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

// Group by month/year
$groupedVersions = [];
foreach ($versionFiles as $versionFile) {
    $yearMonth = $versionFile['date']->format('Y-m');
    $monthName = $versionFile['date']->format('F Y');
    if (!isset($groupedVersions[$yearMonth])) {
        $groupedVersions[$yearMonth] = [
            'month' => $monthName,
            'versions' => []
        ];
    }
    $parsed = parseVersionFile($versionFile['path']);
    $groupedVersions[$yearMonth]['versions'][] = array_merge($versionFile, $parsed);
}

// Sort grouped versions by month (newest first)
krsort($groupedVersions);

// Set page content
$pageContent = '';

ob_start();
?>
<div class="content">
    <div style="margin-bottom: 2rem;">
        <h1 class="title">Development Timeline</h1>
        <p class="subtitle">Track the evolution of BotOfTheSpecter through version releases</p>
    </div>
    <!-- Timeline with alternating left/right items -->
    <div class="timeline-container">
        <div class="timeline-center-line"></div>
        <?php 
        foreach ($groupedVersions as $yearMonth => $monthData): 
        ?>
            <div class="timeline-section">
                <h2 class="title is-4 timeline-month-header">
                    <?php echo htmlspecialchars($monthData['month']); ?>
                </h2>
                <!-- Timeline items -->
                <?php 
                foreach ($monthData['versions'] as $idx => $version): 
                    $isLeft = $idx % 2 === 0;
                    $alignment = $isLeft ? 'left' : 'right';
                ?>
                    <div class="timeline-item timeline-item-<?php echo $alignment; ?>">
                        <div class="timeline-card">
                            <!-- Timeline indicator -->
                            <div class="timeline-indicator">
                                <div class="timeline-dot"></div>
                            </div>
                            <div class="timeline-content">
                                    <small class="timeline-date">
                                        <?php echo htmlspecialchars($version['date']); ?>
                                    </small>
                                    <h3 class="title is-5 timeline-title">
                                        Version <?php echo htmlspecialchars($version['version']); ?>
                                    </h3>
                                    <?php if (!empty($version['summary'])): ?>
                                        <p class="timeline-event-description" style="margin-bottom: 1rem; font-size: 0.9rem;">
                                            <?php echo htmlspecialchars(substr($version['summary'], 0, 150)); ?>
                                        </p>
                                    <?php endif; ?>
                                    <button class="button is-small is-info is-light"
                                        data-version="<?php echo htmlspecialchars((string) $version['version'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-html-b64="<?php echo htmlspecialchars(base64_encode((string) ($version['html'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-markdown-b64="<?php echo htmlspecialchars(base64_encode((string) ($version['markdown'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openVersionModalFromButton(this)"
                                        style="margin-top: 1rem;">
                                        <span class="icon is-small"><i class="fas fa-file-alt"></i></span>
                                        <span>View Notes</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (empty($groupedVersions)): ?>
            <div class="timeline-no-events">
                <p class="has-text-grey-light"><i class="fas fa-calendar-times timeline-no-events-icon"></i>No version releases found</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$pageContent = ob_get_clean();

// Include layout
require_once "layout.php";
?>