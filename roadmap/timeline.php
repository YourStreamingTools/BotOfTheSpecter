<?php
// Timeline view using changelog versions
date_default_timezone_set('Australia/Sydney');
session_start();
session_write_close();

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
    return [
        'version' => $versionNumber,
        'date' => $date,
        'summary' => $summary,
        'sections' => $sections,
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
<!-- Page header -->
<div class="sp-page-header">
    <div>
        <h1 class="sp-page-title">Development Timeline</h1>
        <p class="sp-page-subtitle">Track the evolution of BotOfTheSpecter through version releases</p>
    </div>
</div>

<!-- Timeline -->
<div class="rm-timeline">
    <div class="rm-timeline-line"></div>
    <?php foreach ($groupedVersions as $yearMonth => $monthData): ?>
        <div class="rm-timeline-section">
            <h2 class="rm-timeline-month"><?php echo htmlspecialchars($monthData['month']); ?></h2>
            <?php foreach ($monthData['versions'] as $idx => $version):
                $side = ($idx % 2 === 0) ? 'left' : 'right'; ?>
                <div class="rm-timeline-item rm-tl-<?php echo $side; ?>">
                    <div class="rm-timeline-dot"></div>
                    <div class="rm-timeline-card">
                        <small class="rm-timeline-date"><?php echo htmlspecialchars($version['date']); ?></small>
                        <h3 class="rm-timeline-title">Version <?php echo htmlspecialchars($version['version']); ?></h3>
                        <?php if (!empty($version['summary'])): ?>
                            <p class="rm-timeline-desc"><?php echo htmlspecialchars(substr($version['summary'], 0, 150)); ?></p>
                        <?php endif; ?>
                        <button class="sp-btn sp-btn-info sp-btn-sm"
                            data-version="<?php echo htmlspecialchars((string)$version['version'],ENT_QUOTES,'UTF-8'); ?>"
                            data-markdown-b64="<?php echo htmlspecialchars(base64_encode((string)($version['markdown']??'')),ENT_QUOTES,'UTF-8'); ?>"
                            onclick="openVersionModalFromButton(this)">
                            <i class="fa-solid fa-file-lines"></i> View Notes
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if (empty($groupedVersions)): ?>
        <div class="rm-timeline-empty">
            <i class="fa-solid fa-calendar-xmark"></i> No version releases found
        </div>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();

// Include layout
require_once "layout.php";
?>