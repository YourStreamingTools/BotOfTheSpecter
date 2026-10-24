<?php
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json');
$dbs = [];
foreach ($view as $db => $info) {
    $s = $info['status'];
    $dbs[] = ['db' => (string) $db, 'label' => (string) $info['label'], 'error' => $info['error'],
        'missing' => $s ? count($s['missing'] ?? []) : 0,
        'applied' => $s ? array_map(fn($m) => ['id' => (string) $m['id'], 'description' => (string) ($m['description'] ?? ''), 'applied_at' => $m['applied_at'] ?? '', 'applied_by' => $m['applied_by'] ?? '', 'drift' => !empty($m['drift'])], array_values($s['applied'])) : [],
        'pending' => $s ? array_map(fn($m) => ['id' => (string) $m['id'], 'description' => (string) ($m['description'] ?? ''), 'destructive' => !empty($m['destructive']), 'procedural' => !empty($m['procedural']),
            'sql' => !empty($m['procedural']) ? (string) ($m['preview'] ?: t('admin_migrations_procedural_note')) : implode(";\n", array_map('trim', (array) ($m['up'] ?? [])))], array_values($s['pending'])) : [],
    ];
}
echo json_encode(['success' => true, 'dbs' => $dbs, 'L' => badm_lang('admin_migrations_')], JSON_UNESCAPED_UNICODE);
exit;
