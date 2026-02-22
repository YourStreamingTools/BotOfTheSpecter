<?php
session_start();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = 'EventSub Connections';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../userdata.php';

function fetchUserEventSubSummary($accessToken, $clientID) {
	$ch = curl_init('https://api.twitch.tv/helix/eventsub/subscriptions');
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
			'error' => 'Invalid JSON from Twitch'
		];
	}
	$subscriptions = $payload['data'] ?? [];
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
		'total' => $payload['total'] ?? count($subscriptions),
		'total_cost' => $payload['total_cost'] ?? 0,
		'max_cost' => $payload['max_total_cost'] ?? 0,
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
			'error' => 'Failed to load users with Twitch tokens.',
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
<div class="box">
	<div class="level">
		<div class="level-left">
			<div class="level-item">
				<div>
					<h1 class="title is-4"><span class="icon"><i class="fas fa-bell"></i></span> EventSub Connections</h1>
					<p class="mb-4">Admin overview of Twitch EventSub connections for all users with stored bot tokens.</p>
				</div>
			</div>
		</div>
		<div class="level-right">
			<div class="level-item">
				<button class="button is-primary" id="refresh-eventsub-btn" onclick="refreshEventSubData()">
					<span class="icon"><i class="fas fa-sync-alt"></i></span>
					<span>Refresh</span>
				</button>
			</div>
		</div>
	</div>
	<div class="notification is-info is-light" id="last-updated">
		<small>Loading EventSub data...</small>
	</div>
	<div class="columns is-multiline mb-4">
		<div class="column is-3-desktop is-6-tablet">
			<div class="box has-background-primary has-text-white">
				<p class="heading has-text-white">Users Scanned</p>
				<p class="title is-4 has-text-white" id="stat-users-scanned">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box has-background-success has-text-white">
				<p class="heading has-text-white">Healthy Users</p>
				<p class="title is-4 has-text-white" id="stat-healthy-users">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box has-background-warning has-text-black">
				<p class="heading has-text-black">Users With Active WS</p>
				<p class="title is-4 has-text-black" id="stat-users-active-ws">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box has-background-danger has-text-white">
				<p class="heading has-text-white">Users With Errors</p>
				<p class="title is-4 has-text-white" id="stat-users-errors">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box">
				<p class="heading">Enabled WS Connections</p>
				<p class="title is-5" id="stat-enabled-connections">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box">
				<p class="heading">Enabled WS Subs</p>
				<p class="title is-5" id="stat-enabled-ws-subs">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box">
				<p class="heading">Disabled WS Subs</p>
				<p class="title is-5" id="stat-disabled-ws-subs">0</p>
			</div>
		</div>
		<div class="column is-3-desktop is-6-tablet">
			<div class="box">
				<p class="heading">Webhook Subs</p>
				<p class="title is-5" id="stat-webhook-subs">0</p>
			</div>
		</div>
	</div>
</div>
<div class="box">
	<h2 class="title is-5">Per-user Twitch Connection Status</h2>
	<div class="table-container">
		<table class="table is-fullwidth is-hoverable is-striped">
			<thead>
				<tr>
					<th>User</th>
					<th>Twitch ID</th>
					<th>Status</th>
					<th>Connections</th>
					<th>Enabled WS</th>
					<th>Disabled WS</th>
					<th>Webhook</th>
					<th>Cost</th>
					<th>Error</th>
				</tr>
			</thead>
			<tbody id="eventsub-users-body">
				<tr>
					<td colspan="9" class="has-text-centered has-text-grey">Loading...</td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
<script>
const initialEventSubData = <?php echo json_encode($initialData, JSON_UNESCAPED_SLASHES); ?>;
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = (text ?? '').toString();
	return div.innerHTML;
}

function formatTimestamp(isoString) {
	if (!isoString) return 'Unknown';
	const date = new Date(isoString);
	if (isNaN(date.getTime())) return 'Unknown';
	return date.toLocaleString();
}

function statusTag(user) {
	if (!user.ok) {
		return '<span class="tag is-danger">Error</span>';
	}
	if ((user.enabled_connections || 0) >= 3) {
		return '<span class="tag is-warning">High Usage</span>';
	}
	if ((user.enabled_connections || 0) > 0) {
		return '<span class="tag is-success">Connected</span>';
	}
	return '<span class="tag is-info">No Active Connections</span>';
}

function renderEventSubData(data) {
	const body = document.getElementById('eventsub-users-body');
	const updated = document.getElementById('last-updated');
	if (!data || !data.success) {
		if (updated) {
			updated.innerHTML = '<small class="has-text-danger">Failed to load data.</small>';
		}
		body.innerHTML = '<tr><td colspan="9" class="has-text-centered has-text-danger">Failed to load EventSub data.</td></tr>';
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
		updated.innerHTML = `<small>Last Updated: ${escapeHtml(formatTimestamp(data.generated_at))}</small>`;
	}
	const users = Array.isArray(data.users) ? data.users : [];
	if (users.length === 0) {
		body.innerHTML = '<tr><td colspan="9" class="has-text-centered has-text-grey">No users found.</td></tr>';
		return;
	}
	const rows = users.map(user => {
		const displayName = user.display_name || user.username || 'Unknown';
		const username = user.username ? `@${user.username}` : '';
		const errorText = user.ok ? '-' : (user.error || `HTTP ${user.http_code || 0}`);
		const connectionText = `${user.enabled_connections || 0}` + ((user.disabled_connections || 0) > 0 ? ` (+${user.disabled_connections} disabled)` : '');
		const costText = user.ok ? `${user.total_cost || 0}/${user.max_cost || 0}` : '-';
		return `
			<tr>
				<td>
					<strong>${escapeHtml(displayName)}</strong><br>
					<small class="has-text-grey">${escapeHtml(username)}</small>
				</td>
				<td><code>${escapeHtml(user.twitch_user_id || '')}</code></td>
				<td>${statusTag(user)}</td>
				<td>${escapeHtml(connectionText)}</td>
				<td>${escapeHtml(user.enabled_ws_subs || 0)}</td>
				<td>${escapeHtml(user.disabled_ws_subs || 0)}</td>
				<td>${escapeHtml(user.webhook_subs || 0)}</td>
				<td>${escapeHtml(costText)}</td>
				<td><small class="has-text-grey">${escapeHtml(errorText)}</small></td>
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
		button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Refreshing...</span>';
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