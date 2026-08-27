<?php
// support/api/ai_docs.php
// ----------------------------------------------------------------
// AJAX endpoint: Claude AI assistant for the docs CMS editor.
// Accepts the current section HTML + a user prompt, sends all
// existing doc-block content as context so the AI understands
// the site's HTML/CSS patterns, then returns generated HTML.
//
// POST JSON body:
//   prompt        (string)  — what the user wants the AI to do
//   current_html  (string)  — current textarea content (may be empty)
//   section_key   (string)  — the section this block belongs to
//   doc_id        (int)     — 0 for new blocks
//
// Response JSON:
//   { "ok": true,  "html": "..." }
//   { "ok": false, "error": "..." }
// ----------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session.php';
support_session_start();

// Staff-only
if (!is_staff()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Parse JSON body
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// CSRF check (sent via X-CSRF-Token header)
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch.']);
    exit;
}

$prompt      = trim($body['prompt']       ?? '');
$currentHtml = trim($body['current_html'] ?? '');
$sectionKey  = trim($body['section_key']  ?? '');
$docId       = (int)($body['doc_id']      ?? 0);

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A prompt is required.']);
    exit;
}

// ----------------------------------------------------------------
// Load Claude API config
// ----------------------------------------------------------------
require_once '/var/www/config/ai.php';
if (empty($claude_api_key)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Claude API key is not configured.']);
    exit;
}

// ----------------------------------------------------------------
// Gather ALL existing doc blocks for context
// ----------------------------------------------------------------
$db = support_db();

$allDocs   = [];
$secLabels = [];

$secRes = $db->query('SELECT section_key, section_label FROM support_doc_sections ORDER BY section_order ASC');
if ($secRes) {
    while ($r = $secRes->fetch_assoc()) {
        $secLabels[$r['section_key']] = $r['section_label'];
    }
}

$docRes = $db->query('SELECT id, section_key, title, content, doc_order FROM support_docs ORDER BY section_key ASC, doc_order ASC');
if ($docRes) {
    while ($r = $docRes->fetch_assoc()) {
        $allDocs[] = $r;
    }
}

// Build a context string of all existing sections + their HTML
$contextParts = [];
foreach ($secLabels as $key => $label) {
    $blocks = array_filter($allDocs, fn($d) => $d['section_key'] === $key);
    if (empty($blocks)) {
        $contextParts[] = "=== Section: {$label} (key: {$key}) ===\n(no content yet)";
        continue;
    }
    $sectionContent = "=== Section: {$label} (key: {$key}) ===\n";
    foreach ($blocks as $b) {
        $titleLabel = $b['title'] ?: '(untitled block)';
        $sectionContent .= "--- Block #{$b['id']}: {$titleLabel} (order: {$b['doc_order']}) ---\n";
        $sectionContent .= $b['content'] . "\n\n";
    }
    $contextParts[] = $sectionContent;
}
$allDocsContext = implode("\n\n", $contextParts);

// ----------------------------------------------------------------
// Build the prompt for Claude
// ----------------------------------------------------------------
$systemPrompt = <<<'SYSTEM'
You are an HTML content assistant for a documentation CMS. Your job is to write or edit HTML content blocks for a support documentation site.

IMPORTANT RULES:
1. Output ONLY raw HTML — no markdown, no code fences, no explanations before or after the HTML.
2. Use the same HTML tags, CSS classes, and patterns you see in the existing content blocks provided as context.
3. Common patterns used on this site:
   - Headings: <h2>, <h3>, <h4>
   - Paragraphs: <p>
   - Lists: <ul> with <li> items
   - Inline code: <code>...</code>
   - Alerts: <div class="sp-alert sp-alert-info|success|warning|danger"><i class="fa-solid fa-circle-info"></i><span>...</span></div>
   - Steps: <div class="sp-step"><div class="sp-step-num">1</div><div class="sp-step-body"><h4>Title</h4><p>Description</p></div></div>
   - Tables: <table class="sp-var-table"><thead>...</thead><tbody>...</tbody></table>
   - Dividers: <hr class="sp-divider">
   - Links: <a href="#" data-goto="section">text</a> for internal section links
   - Font Awesome icons: <i class="fa-solid fa-icon-name"></i>
4. Keep the content professional, clear, and helpful for end users.
5. If the user asks you to edit existing content, return the FULL updated HTML (not just the changed part).
6. If the user asks you to create new content, generate complete HTML ready to paste into the editor.
7. Match the writing style, tone, and formatting conventions of the existing documentation.
SYSTEM;

// Build the user message
$userMessage = "";

if ($allDocsContext !== '') {
    $userMessage .= "Here is all the existing documentation content on the site, so you understand the HTML patterns, CSS classes, and writing style used:\n\n";
    $userMessage .= $allDocsContext . "\n\n";
    $userMessage .= "---\n\n";
}

if ($currentHtml !== '') {
    $userMessage .= "Here is the CURRENT content of the block I'm editing:\n\n";
    $userMessage .= $currentHtml . "\n\n";
    $userMessage .= "---\n\n";
} else {
    $userMessage .= "This is a NEW block with no existing content.\n\n";
}

if ($sectionKey !== '' && isset($secLabels[$sectionKey])) {
    $userMessage .= "This block belongs to the section: \"{$secLabels[$sectionKey]}\" (key: {$sectionKey}).\n\n";
}

$userMessage .= "My request: " . $prompt;

// ----------------------------------------------------------------
// Call Claude API
// ----------------------------------------------------------------
$model = $claude_model ?? 'claude-sonnet-4-20250514';

$payload = json_encode([
    'model'      => $model,
    'max_tokens' => 4096,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $userMessage],
    ],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $claude_api_key,
        'anthropic-version: 2023-06-01',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Failed to reach Claude API: ' . $curlErr]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !is_array($data)) {
    $apiError = $data['error']['message'] ?? $data['error']['type'] ?? 'Unknown API error (HTTP ' . $httpCode . ')';
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Claude API error: ' . $apiError]);
    exit;
}

// Extract text from the response
$html = '';
if (!empty($data['content'])) {
    foreach ($data['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $html .= $block['text'];
        }
    }
}

// Strip markdown code fences if the model wrapped the output
$html = preg_replace('/^```html?\s*\n?/i', '', $html);
$html = preg_replace('/\n?```\s*$/', '', $html);
$html = trim($html);

if ($html === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Claude returned an empty response.']);
    exit;
}

echo json_encode(['ok' => true, 'html' => $html]);
