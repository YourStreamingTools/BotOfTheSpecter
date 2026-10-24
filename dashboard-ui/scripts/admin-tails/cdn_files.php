<?php
// GET: store names (not the store config), write access and page strings.
ob_end_clean();
header('Content-Type: application/json');
$stores = [];
foreach ((is_array($megas4_stores ?? null) ? $megas4_stores : []) as $k => $info) {
    $stores[] = ['key' => (string) $k, 'name' => (string) ($info['name'] ?? $k)];
}
echo json_encode(['success' => true, 'is_super' => $isSuperAdmin, 'stores' => $stores, 'allowed_exts' => $allAllowedExts, 'L' => badm_lang('cdn_files_')], JSON_UNESCAPED_UNICODE);
exit;
