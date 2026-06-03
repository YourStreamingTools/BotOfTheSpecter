<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_eventsub_page_title');
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../includes/userdata.php';
session_write_close();

function fetchUserEventSubSummary($accessToken, $clientID) {
	$allSubscriptions = [];
	$after = null;
	$total = 0;
	$totalCost = 0;
	$maxCost = 0;
	do {
		$url = 'https://api.twitch.tv/helix/eventsub/subscriptions?first=100';
		if (!empty($after)) {
			$url .= '&after=' . urlencode($after);
		}
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $accessToken,
			'Client-Id: ' . $clientID
		]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 8);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		if (!empty($curlError)) {
			return [
				'ok' => false,
				'http_code' => 0,
				'error' => 'cURL error: ' . $curlError
			];
		}
		if ($httpCode !== 200 || !$response) {
			$errMsg = 'HTTP ' . $httpCode;
			if (!empty($response)) {
				$decodedErr = json_decode($response, true);
				if (is_array($decodedErr) && !empty($decodedErr['message'])) {
					$errMsg .= ' - ' . $decodedErr['message'];
				}
			}
			return [
				'ok' => false,
				'http_code' => $httpCode,
				'error' => $errMsg
			];
		}
		$payload = json_decode($response, true);
		if (!is_array($payload)) {
			return [
				'ok' => false,
				'http_code' => $httpCode,
				'error' => t('admin_eventsub_err_invalid_json')
			];
		}
		if (isset($payload['total'])) {
			$total = (int) $payload['total'];
		}
		if (isset($payload['total_cost'])) {
			$totalCost = (int) $payload['total_cost'];
		}
		if (isset($payload['max_total_cost'])) {
			$maxCost = (int) $payload['max_total_cost'];
		}
		$pageSubscriptions = $payload['data'] ?? [];
		if (is_array($pageSubscriptions) && !empty($pageSubscriptions)) {
			$allSubscriptions = array_merge($allSubscriptions, $pageSubscriptions);
		}
		$after = $payload['pagination']['cursor'] ?? null;
	} while (!empty($after));
	$subscriptions = $allSubscriptions;
	$enabledWs = 0;
	$disabledWs = 0;
	$webhookSubs = 0;
	$enabledSessionIds = [];
	$disabledSessionIds = [];
	foreach ($subscriptions as $sub) {
		$transport = $sub['transport']['method'] ?? '';
		$status = $sub['status'] ?? '';
		if ($transport === 'websocket') {
			$sessionId = $sub['transport']['session_id'] ?? '';
			if ($status === 'enabled') {
				$enabledWs++;
				if (!empty($sessionId)) {
					$enabledSessionIds[$sessionId] = true;
				}
			} else {
				$disabledWs++;
				if (!empty($sessionId)) {
					$disabledSessionIds[$sessionId] = true;
				}
			}
		} else {
			$webhookSubs++;
		}
	}
	return [
		'ok' => true,
		'http_code' => 200,
		'error' => null,
		'total' => $total > 0 ? $total : count($subscriptions),
		'total_cost' => $totalCost,
		'max_cost' => $maxCost,
		'enabled_ws_subs' => $enabledWs,
		'disabled_ws_subs' => $disabledWs,
		'webhook_subs' => $webhookSubs,
		'enabled_connections' => count($enabledSessionIds),
		'disabled_connections' => count($disabledSessionIds)
	];
}

function fetchAllEventSubConnections($conn, $clientID) {
	$users = [];
	$query = "SELECT u.id, u.username, u.twitch_display_name, u.twitch_user_id, tba.twitch_access_token
			  FROM users u
			  LEFT JOIN twitch_bot_access tba ON u.twitch_user_id = tba.twitch_user_id
			  ORDER BY u.username ASC";
	$result = $conn->query($query);
	if (!$result) {
		return [
			'success' => false,
			'error' => t('admin_eventsub_err_load_users'),
			'generated_at' => date('c')
		];
	}
	$summary = [
		'users_scanned' => 0,
		'healthy_users' => 0,
		'users_with_errors' => 0,
		'users_with_active_ws' => 0,
		'total_enabled_connections' => 0,
		'total_enabled_ws_subs' => 0,
		'total_disabled_ws_subs' => 0,
		'total_webhook_subs' => 0
	];
	while ($row = $result->fetch_assoc()) {
		$summary['users_scanned']++;
		$token = trim((string)($row['twitch_access_token'] ?? ''));
		if ($token === '') {
			$scan = [
				'ok' => true,
				'http_code' => 200,
				'error' => null,
				'total' => 0,
				'total_cost' => 0,
				'max_cost' => 0,
				'enabled_ws_subs' => 0,
				'disabled_ws_subs' => 0,
				'webhook_subs' => 0,
				'enabled_connections' => 0,
				'disabled_connections' => 0
			];
		} else {
			$scan = fetchUserEventSubSummary($token, $clientID);
		}
		$item = [
			'id' => (int) $row['id'],
			'username' => $row['username'] ?? '',
			'display_name' => $row['twitch_display_name'] ?? '',
			'twitch_user_id' => $row['twitch_user_id'] ?? '',
			'ok' => $scan['ok'],
			'http_code' => $scan['http_code'] ?? 0,
			'error' => $scan['error'] ?? null,
			'total' => $scan['total'] ?? 0,
			'total_cost' => $scan['total_cost'] ?? 0,
			'max_cost' => $scan['max_cost'] ?? 0,
			'enabled_ws_subs' => $scan['enabled_ws_subs'] ?? 0,
			'disabled_ws_subs' => $scan['disabled_ws_subs'] ?? 0,
			'webhook_subs' => $scan['webhook_subs'] ?? 0,
			'enabled_connections' => $scan['enabled_connections'] ?? 0,
			'disabled_connections' => $scan['disabled_connections'] ?? 0
		];
		if ($scan['ok']) {
			$summary['healthy_users']++;
			if (($item['enabled_connections'] ?? 0) > 0) {
				$summary['users_with_active_ws']++;
			}
			$summary['total_enabled_connections'] += (int) $item['enabled_connections'];
			$summary['total_enabled_ws_subs'] += (int) $item['enabled_ws_subs'];
			$summary['total_disabled_ws_subs'] += (int) $item['disabled_ws_subs'];
			$summary['total_webhook_subs'] += (int) $item['webhook_subs'];
		} else {
			$summary['users_with_errors']++;
		}
		$users[] = $item;
	}
	usort($users, function ($a, $b) {
		if ($a['ok'] !== $b['ok']) {
			return $a['ok'] ? -1 : 1;
		}
		if (($a['enabled_connections'] ?? 0) !== ($b['enabled_connections'] ?? 0)) {
			return ($b['enabled_connections'] ?? 0) <=> ($a['enabled_connections'] ?? 0);
		}
		return ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
	});
	return [
		'success' => true,
		'generated_at' => date('c'),
		'summary' => $summary,
		'users' => $users
	];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh_data'])) {
	header('Content-Type: application/json');
	echo json_encode(fetchAllEventSubConnections($conn, $clientID));
	exit;
}

$initialData = fetchAllEventSubConnections($conn, $clientID);

ob_start();
?>
<div class="sp-card">
	<div class="sp-card-header">
		<h1 class="sp-card-title"><span class="icon"><i class="fas fa-bell"></i></span> <?php echo t('admin_eventsub_page_title'); ?></h1>
		<button class="sp-btn sp-btn-primary" id="refresh-eventsub-btn" onclick="refreshEventSubData()">
			<span class="icon"><i class="fas fa-sync-alt"></i></span>
			<span><?php echo t('admin_eventsub_refresh'); ?></span>
		</button>
	</div>
	<div class="sp-card-body">
	<p style="margin-bottom:1rem;"><?php echo t('admin_eventsub_description'); ?></p>
	<div class="sp-alert sp-alert-info" id="last-updated">
		<small><?php echo t('admin_eventsub_loading'); ?></small>
	</div>
	<div class="stats-grid">
		<div class="stat-card accent-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_users_scanned'); ?></p>
			<p class="stat-value" id="stat-users-scanned">0</p>
		</div>
		<div class="stat-card success-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_healthy_users'); ?></p>
			<p class="stat-value" id="stat-healthy-users">0</p>
		</div>
		<div class="stat-card warning-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_users_active_ws'); ?></p>
			<p class="stat-value" id="stat-users-active-ws">0</p>
		</div>
		<div class="stat-card danger-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_users_errors'); ?></p>
			<p class="stat-value" id="stat-users-errors">0</p>
		</div>
		<div class="stat-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_enabled_connections'); ?></p>
			<p class="stat-value" id="stat-enabled-connections">0</p>
		</div>
		<div class="stat-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_enabled_ws_subs'); ?></p>
			<p class="stat-value" id="stat-enabled-ws-subs">0</p>
		</div>
		<div class="stat-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_disabled_ws_subs'); ?></p>
			<p class="stat-value" id="stat-disabled-ws-subs">0</p>
		</div>
		<div class="stat-card">
			<p class="stat-label"><?php echo t('admin_eventsub_stat_webhook_subs'); ?></p>
			<p class="stat-value" id="stat-webhook-subs">0</p>
		</div>
	</div>
	</div><!-- /sp-card-body --></div><!-- /sp-card -->
<div class="sp-card">
	<div class="sp-card-header">
		<h2 class="sp-card-title"><?php echo t('admin_eventsub_table_heading'); ?></h2>
	</div>
	<div class="sp-card-body">
	<div class="sp-table-wrap">
		<table class="sp-table">
			<thead>
				<tr>
					<th><?php echo t('admin_eventsub_th_user'); ?></th>
					<th><?php echo t('admin_eventsub_th_twitch_id'); ?></th>
					<th><?php echo t('admin_eventsub_th_status'); ?></th>
					<th><?php echo t('admin_eventsub_th_connections'); ?></th>
					<th><?php echo t('admin_eventsub_th_enabled_ws'); ?></th>
					<th><?php echo t('admin_eventsub_th_disabled_ws'); ?></th>
					<th><?php echo t('admin_eventsub_th_webhook'); ?></th>
					<th><?php echo t('admin_eventsub_th_cost'); ?></th>
					<th><?php echo t('admin_eventsub_th_error'); ?></th>
				</tr>
			</thead>
			<tbody id="eventsub-users-body">
				<tr>
					<td colspan="9" class="sp-text-muted" style="text-align:center;"><?php echo t('admin_eventsub_loading_short'); ?></td>
				</tr>
			</tbody>
		</table>
	</div><!-- /sp-table-wrap -->
	</div><!-- /sp-card-body -->
</div><!-- /sp-card -->
<script>
const initialEventSubData = <?php echo json_encode($initialData, JSON_UNESCAPED_SLASHES); ?>;
const ESUB_I18N = {
	unknown: <?php echo json_encode(t('admin_eventsub_js_unknown')); ?>,
	statusError: <?php echo json_encode(t('admin_eventsub_js_status_error')); ?>,
	statusHighUsage: <?php echo json_encode(t('admin_eventsub_js_status_high_usage')); ?>,
	statusConnected: <?php echo json_encode(t('admin_eventsub_js_status_connected')); ?>,
	statusNoActive: <?php echo json_encode(t('admin_eventsub_js_status_no_active')); ?>,
	failedToLoad: <?php echo json_encode(t('admin_eventsub_js_failed_to_load')); ?>,
	failedToLoadData: <?php echo json_encode(t('admin_eventsub_js_failed_to_load_data')); ?>,
	noUsersFound: <?php echo json_encode(t('admin_eventsub_js_no_users_found')); ?>,
	lastUpdated: <?php echo json_encode(t('admin_eventsub_js_last_updated')); ?>,
	disabledSuffix: <?php echo json_encode(t('admin_eventsub_js_disabled_suffix')); ?>,
	refreshing: <?php echo json_encode(t('admin_eventsub_js_refreshing')); ?>
};
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = (text ?? '').toString();
	return div.innerHTML;
}

function formatTimestamp(isoString) {
	if (!isoString) return ESUB_I18N.unknown;
	const date = new Date(isoString);
	if (isNaN(date.getTime())) return ESUB_I18N.unknown;
	return date.toLocaleString();
}

function statusTag(user) {
	if (!user.ok) {
		return '<span class="sp-badge sp-badge-red">' + escapeHtml(ESUB_I18N.statusError) + '</span>';
	}
	if ((user.enabled_connections || 0) >= 3) {
		return '<span class="sp-badge sp-badge-amber">' + escapeHtml(ESUB_I18N.statusHighUsage) + '</span>';
	}
	if ((user.enabled_connections || 0) > 0) {
		return '<span class="sp-badge sp-badge-green">' + escapeHtml(ESUB_I18N.statusConnected) + '</span>';
	}
	return '<span class="sp-badge sp-badge-blue">' + escapeHtml(ESUB_I18N.statusNoActive) + '</span>';
}

function renderEventSubData(data) {
	const body = document.getElementById('eventsub-users-body');
	const updated = document.getElementById('last-updated');
	if (!data || !data.success) {
		if (updated) {
			updated.innerHTML = '<small class="sp-text-danger">' + escapeHtml(ESUB_I18N.failedToLoad) + '</small>';
		}
		body.innerHTML = '<tr><td colspan="9" class="sp-text-danger" style="text-align:center;">' + escapeHtml(ESUB_I18N.failedToLoadData) + '</td></tr>';
		return;
	}
	const s = data.summary || {};
	document.getElementById('stat-users-scanned').textContent = s.users_scanned || 0;
	document.getElementById('stat-healthy-users').textContent = s.healthy_users || 0;
	document.getElementById('stat-users-active-ws').textContent = s.users_with_active_ws || 0;
	document.getElementById('stat-users-errors').textContent = s.users_with_errors || 0;
	document.getElementById('stat-enabled-connections').textContent = s.total_enabled_connections || 0;
	document.getElementById('stat-enabled-ws-subs').textContent = s.total_enabled_ws_subs || 0;
	document.getElementById('stat-disabled-ws-subs').textContent = s.total_disabled_ws_subs || 0;
	document.getElementById('stat-webhook-subs').textContent = s.total_webhook_subs || 0;
	if (updated) {
		updated.innerHTML = `<small>${escapeHtml(ESUB_I18N.lastUpdated)} ${escapeHtml(formatTimestamp(data.generated_at))}</small>`;
	}
	const users = Array.isArray(data.users) ? data.users : [];
	if (users.length === 0) {
		body.innerHTML = '<tr><td colspan="9" class="sp-text-muted" style="text-align:center;">' + escapeHtml(ESUB_I18N.noUsersFound) + '</td></tr>';
		return;
	}
	const rows = users.map(user => {
		const displayName = user.display_name || user.username || ESUB_I18N.unknown;
		const username = user.username ? `@${user.username}` : '';
		const errorText = user.ok ? '-' : (user.error || `HTTP ${user.http_code || 0}`);
		const connectionText = `${user.enabled_connections || 0}` + ((user.disabled_connections || 0) > 0 ? ` ${ESUB_I18N.disabledSuffix.replace('%d', user.disabled_connections)}` : '');
		const costText = user.ok ? `${user.total_cost || 0}/${user.max_cost || 0}` : '-';
		return `
			<tr>
				<td>
					<strong>${escapeHtml(displayName)}</strong><br>
					<small class="sp-text-muted">${escapeHtml(username)}</small>
				</td>
				<td><code>${escapeHtml(user.twitch_user_id || '')}</code></td>
				<td>${statusTag(user)}</td>
				<td>${escapeHtml(connectionText)}</td>
				<td>${escapeHtml(user.enabled_ws_subs || 0)}</td>
				<td>${escapeHtml(user.disabled_ws_subs || 0)}</td>
				<td>${escapeHtml(user.webhook_subs || 0)}</td>
				<td>${escapeHtml(costText)}</td>
				<td><small class="sp-text-muted">${escapeHtml(errorText)}</small></td>
			</tr>
		`;
	});
	body.innerHTML = rows.join('');
}

async function refreshEventSubData() {
	const button = document.getElementById('refresh-eventsub-btn');
	const original = button ? button.innerHTML : null;
	if (button) {
		button.disabled = true;
		button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + escapeHtml(ESUB_I18N.refreshing) + '</span>';
	}
	try {
		const response = await fetch('?refresh_data=1');
		const result = await response.json();
		renderEventSubData(result);
	} catch (error) {
		console.error('Failed to refresh EventSub data:', error);
		renderEventSubData({ success: false });
	} finally {
		if (button && original !== null) {
			button.disabled = false;
			button.innerHTML = original;
		}
	}
}

document.addEventListener('DOMContentLoaded', function () {
	renderEventSubData(initialEventSubData);
});
</script>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>