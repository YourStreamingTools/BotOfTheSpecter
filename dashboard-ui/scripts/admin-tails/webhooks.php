<?php
// GET: the table the old page rendered. Secrets never leave the server; only
// the last 4 characters, as the old table showed them.
$webhooks = [];
$baseCols = "id, slug, name, service, event_name, scope, target_username, verify_mode, secret, secret_header, enabled, last_received_at, received_count";
$result = false;
foreach ([$baseCols . ", last_attempt_at, last_status, last_error", $baseCols] as $cols) {
    try { $result = $conn->query("SELECT $cols FROM custom_webhooks ORDER BY name"); } catch (Throwable $e) { $result = false; }
    if ($result) break;
}
$adminKeys = wh_load_admin_keys($conn);
if ($result) {
    while ($wh = $result->fetch_assoc()) {
        $state = wh_key_state($wh, $adminKeys);
        $webhooks[] = [
            'id' => (int) $wh['id'], 'slug' => $wh['slug'], 'name' => $wh['name'], 'service' => $wh['service'],
            'event_name' => $wh['event_name'], 'scope' => $wh['scope'], 'target_username' => $wh['target_username'] ?? '',
            'verify_mode' => $wh['verify_mode'], 'secret_last4' => ($wh['verify_mode'] !== 'none' && !empty($wh['secret'])) ? substr($wh['secret'], -4) : '',
            'secret_header' => $wh['secret_header'], 'enabled' => (int) $wh['enabled'],
            'received_count' => (int) $wh['received_count'], 'last_received_at' => $wh['last_received_at'],
            'url' => $apiBase . '/webhook/' . $wh['slug'],
            'key_state' => $state, 'key_label' => $state ? t('admin_webhooks_key_' . $state) : null,
            'key_help' => $state ? t('admin_webhooks_key_' . $state . '_help', [$wh['service']]) : null,
            'last_status' => $wh['last_status'] ?? null, 'last_status_label' => !empty($wh['last_status']) ? wh_status_label($wh['last_status']) : null,
            'last_error' => $wh['last_error'] ?? null, 'last_attempt_at' => $wh['last_attempt_at'] ?? null,
        ];
    }
    $result->free();
}
wh_json(['success' => true, 'webhooks' => $webhooks, 'discord_log_event' => $discordLogEvent]);
