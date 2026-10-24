<?php
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json');
$badmSites = [];
foreach ($sites as $s) {
    $hosts = [];
    foreach ($s['hosts'] as $h) { $hosts[] = ['h' => $h, 'kind' => caddy_host_kind($h)]; }
    $target = '';
    if ($s['type'] === 'redirect' && !empty($s['target'])) { $target = trim(preg_replace('/\{[^}]*\}/', '', $s['target'])); }
    $badmSites[] = ['hosts' => $hosts, 'type' => $s['type'], 'listen' => $s['listen'], 'target' => $target];
}
echo json_encode([
    'success' => true,
    'is_super' => $isSuperAdmin,
    'ssh_ready' => !empty($web_ssh_host),
    'up' => $caddyUp,
    'sites' => $badmSites,
    'tls' => ['acme_email' => (string) ($tls['acme_email'] ?? '-'), 'dns_provider' => (string) ($tls['dns_provider'] ?? '-'), 'policies' => (int) ($tls['policies'] ?? 0)],
    'upstreams' => $upstreams,
    'config' => (string) $redactedConfigJson,
    'L' => badm_lang('caddy_'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
