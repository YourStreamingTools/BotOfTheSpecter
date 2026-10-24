<?php
// GET: every pattern, as the old page listed them.
$spam_patterns = [];
$result = $spam_conn->query("SELECT id, spam_pattern FROM spam_patterns ORDER BY id");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $spam_patterns[] = ['id' => (int) $row['id'], 'spam_pattern' => $row['spam_pattern']];
    }
    $result->free();
}
ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'patterns' => $spam_patterns], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
exit;
