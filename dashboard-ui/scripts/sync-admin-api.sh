#!/usr/bin/env bash
# Mirror the live admin pages' PHP logic into public-admin/api/ for the beta
# admin panel. Each page's request handlers are copied verbatim up to the
# point where the old page starts rendering HTML; the bootstrap is swapped for
# _admin.php (session, is_admin re-check, Act As restore, audit) and paths are
# made absolute. A per-page tail from scripts/admin-tails/<page>.php then
# answers the React page's GET with the same data the old page rendered.
# Re-run after the live files change. Never writes to dashboard/admin/.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - "$@" <<'PY'
import re, sys, pathlib
SRC = pathlib.Path('../dashboard/admin')
OUT = pathlib.Path('public-admin/api')
TAILS = pathlib.Path('scripts/admin-tails')
# page -> exact text where the old page stops handling requests and starts
# rendering (everything from here on is replaced by the tail). A leading '+'
# means cut after the marker instead of before it.
CUT = {
    'known_bots': '// Fetch all known bots for display',
    'beta_programs': '// Fetch all programs',
    'feedback': '+    $stmt->close();\n    exit;\n}\n',
    'api_keys': '// Fetch all API keys',
    'webhooks': '// Fetch all webhooks for display.',
    'discordbot_overview': 'ob_start();\n?>\n<div class="sp-card">',
    'websocket_clients': '// Handle AJAX request for refreshing data',
    'logs': 'ob_start();\n?>\n<div class="sp-card">',
    'users': "include '../includes/userdata.php';",
    'export_user_data': "+echo json_encode(['success' => true, 'msg' => t('admin_export_user_data_export_started')]);\nexit();\n",
    'index': 'ob_start();\n?>\n<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>',
    'service_status': '+echo json_encode($result);\n',
    'start_bots': 'ob_start();\n?>\n<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>\n<div class="sp-card start-bots-page">',
    'twitch_tokens': 'ob_start();\n?>\n<div class="sp-card">\n    <div class="sp-card-body">\n    <h1',
    'cdn_files': 'ob_start();\n?>\n<div class="sp-card">\n    <div class="sp-card-header">\n        <h1 class="sp-card-title"><i class="fas fa-folder-open">',
    'migrations': 'ob_end_clean();\nob_start();\n?>\n<div class="sp-card">\n    <div class="sp-card-header"><h1 class="sp-card-title"><i class="fas fa-database">',
    'user_schema': 'ob_end_clean();\nob_start();\n?>\n<div class="sp-card">\n    <div class="sp-card-header">\n        <h1 class="sp-card-title"><i class="fas fa-database"></i> <?php echo t(\'admin_user_schema_page_title\'); ?></h1>',
    'caddy': 'ob_end_clean();\nob_start();\n?>\n<div class="sp-card">\n    <div class="sp-card-header"><h1 class="sp-card-title"><i class="fas fa-server"></i>',
    'terminal': 'ob_start();\n?>\n<div class="sp-card">\n    <div class="sp-card-header">\n        <h1 class="sp-card-title"><span class="icon"><i class="fas fa-terminal"></i></span>',
    # '*' = the whole file is request handling (SSE endpoints with no page of their own).
    'terminal_stream': '*',
    'stream_command': '*',
    'event_sub': '$initialData = fetchAllEventSubConnections',
    'spam_patterns': '$spam_patterns = [];\nif ($spam_conn) {',
}
# Per-page source edits applied after the cut (old, new); each must match once.
REPL = {
    'terminal': [
        ("    admin_access_deny(t('admin_terminal_super_admin_only'));",
         "    http_response_code(403);\n    header('Content-Type: application/json');\n    echo json_encode(['success' => false, 'error' => t('admin_terminal_super_admin_only')]);\n    exit;"),
    ],
    'twitch_tokens': [
        ("<?php\nob_start();",
         "<?php\n// beta: token values never leave the server. Every JSON reply is filtered: raw tokens are\n// replaced by their length. Only generate_token (a fresh app token the admin asked for) keeps it.\nfunction badm_tt_strip($b) {\n    if (isset($_POST['generate_token'])) { return $b; }\n    $j = json_decode($b, true);\n    if (!is_array($j)) { return $b; }\n    foreach (['access_token', 'refresh_token', 'renewed_token', 'new_token', 'token', 'twitch_access_token'] as $k) {\n        if (array_key_exists($k, $j)) { $j[$k . '_len'] = strlen((string) $j[$k]); unset($j[$k]); }\n    }\n    unset($j['cache']);\n    return json_encode($j);\n}\nob_start('badm_tt_strip');\nob_start();"),
    ],
    'users': [
        ("            'email' => $row['email'] ?? '',\n            'api_key' => $row['api_key'] ?? '',\n",
         "            // beta: values only via ?reveal (audited), see admin-tails/users.php\n            'email' => '',\n            'api_key' => '',\n            'has_email' => !empty($row['email']),\n            'email_domain' => (($at = strpos((string) ($row['email'] ?? ''), '@')) === false ? '' : substr((string) $row['email'], $at)),\n            'email_local_len' => (($at = strpos((string) ($row['email'] ?? ''), '@')) === false ? strlen((string) ($row['email'] ?? '')) : $at),\n            'api_key_len' => strlen((string) ($row['api_key'] ?? '')),\n"),
    ],
}
# Pages whose old file has no admin_access.php include: add the beta guard first.
GUARD = {'export_user_data'}
only = set(sys.argv[1:])
for page, marker in CUT.items():
    if only and page not in only:
        continue
    s = (SRC / f'{page}.php').read_text().lstrip('\ufeff')
    # A leading '+' keeps the marker text and cuts right after it.
    if marker == '*':
        s = re.sub(r'\?>\s*$', '', s)
    else:
        i = s.index(marker[1:]) + len(marker) - 1 if marker.startswith('+') else s.index(marker)
        s = s[:i]
    s = s.replace("require_once '/var/www/lib/session_bootstrap.php';\n", '')
    s = s.replace("require_once __DIR__ . '/admin_access.php';", "require_once __DIR__ . '/_admin.php';")
    s = s.replace("__DIR__ . '/../lang/i18n.php'", "'/var/www/dashboard/lang/i18n.php'")
    s = re.sub(r"(['\"])\.\./includes/", r"\1/var/www/dashboard/includes/", s)
    s = s.replace("__DIR__ . '/../includes/", "'/var/www/dashboard/includes/")
    s = s.replace('\u2014', '-')
    for old, new in REPL.get(page, []):
        assert s.count(old) == 1, (page, old[:60])
        s = s.replace(old, new)
    if page in GUARD:
        s = s.replace('<?php', "<?php\nrequire_once __DIR__ . '/_admin.php';", 1)
    tail = (TAILS / f'{page}.php').read_text()
    if tail.startswith('<?php'):
        tail = tail[5:].lstrip('\n')
    tail = (TAILS / 'lib' / 'lang.php').read_text() + tail
    head = f"<?php\n// GENERATED by scripts/sync-admin-api.sh from dashboard/admin/{page}.php. Do not edit.\n"
    s = head + s[len('<?php'):].lstrip('\n')
    (OUT / f'{page}.php').write_text(s + '// ---- beta admin tail (scripts/admin-tails) ----\n' + tail)
    print('synced', page)
PY
for f in public-admin/api/*.php; do php -l "$f" >/dev/null || exit 1; done
echo "lint ok"
