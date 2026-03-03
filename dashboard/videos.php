<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

if (!isset($_SESSION['access_token'])) {
	header('Location: login.php');
	exit();
}

$pageTitle = 'Videos';

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';

$accessToken = $_SESSION['access_token'] ?? '';
$defaultUserId = $_SESSION['twitchUserId'] ?? ($broadcasterID ?? '');

$videoModes = ['user_id', 'game_id', 'id'];
$clipModes = ['clips_broadcaster_id', 'clips_game_id', 'clips_id'];
$allowedModes = array_merge($videoModes, $clipModes);
$allowedTabs = ['videos', 'clips'];
$allowedPeriods = ['all', 'day', 'week', 'month'];
$allowedSort = ['time', 'trending', 'views'];
$allowedTypes = ['all', 'archive', 'highlight', 'upload'];

$requestedTab = isset($_GET['tab']) ? trim((string) $_GET['tab']) : '';
$requestedMode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';

$mode = in_array($requestedMode, $allowedModes, true) ? $requestedMode : 'user_id';
$tab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : (in_array($mode, $clipModes, true) ? 'clips' : 'videos');

if ($tab === 'videos' && !in_array($mode, $videoModes, true)) {
	$mode = 'user_id';
} elseif ($tab === 'clips' && !in_array($mode, $clipModes, true)) {
	$mode = 'clips_broadcaster_id';
}

$period = isset($_GET['period']) && in_array($_GET['period'], $allowedPeriods, true) ? $_GET['period'] : 'all';
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSort, true) ? $_GET['sort'] : 'time';
$type = isset($_GET['type']) && in_array($_GET['type'], $allowedTypes, true) ? $_GET['type'] : 'all';
$first = isset($_GET['first']) ? max(1, min(100, (int) $_GET['first'])) : 20;

$rawUserId = isset($_GET['user_id']) ? trim((string) $_GET['user_id']) : (string) $defaultUserId;
$rawGameId = isset($_GET['game_id']) ? trim((string) $_GET['game_id']) : '';
$rawVideoIds = isset($_GET['video_ids']) ? trim((string) $_GET['video_ids']) : '';
$rawBroadcasterId = isset($_GET['broadcaster_id']) ? trim((string) $_GET['broadcaster_id']) : (string) $defaultUserId;
$rawEditorId = isset($_GET['editor_id']) ? trim((string) $_GET['editor_id']) : (string) $defaultUserId;
$rawClipGameId = isset($_GET['clip_game_id']) ? trim((string) $_GET['clip_game_id']) : '';
$rawClipIds = isset($_GET['clip_ids']) ? trim((string) $_GET['clip_ids']) : '';
$rawStartedAt = isset($_GET['started_at']) ? trim((string) $_GET['started_at']) : '';
$rawEndedAt = isset($_GET['ended_at']) ? trim((string) $_GET['ended_at']) : '';
$rawIsFeatured = isset($_GET['is_featured']) ? strtolower(trim((string) $_GET['is_featured'])) : '';
$rawLanguage = isset($_GET['language']) ? trim((string) $_GET['language']) : '';
$afterCursor = isset($_GET['after']) ? trim((string) $_GET['after']) : '';
$beforeCursor = isset($_GET['before']) ? trim((string) $_GET['before']) : '';

if ($afterCursor !== '' && $beforeCursor !== '') {
	$beforeCursor = '';
}

if ($rawLanguage !== '') {
	$rawLanguage = strtolower($rawLanguage);
	if ($rawLanguage !== 'other' && !preg_match('/^[a-z]{2}$/', $rawLanguage)) {
		$rawLanguage = '';
	}
}

if (!in_array($rawIsFeatured, ['', 'true', 'false'], true)) {
	$rawIsFeatured = '';
}

if ($rawStartedAt !== '') {
	$startedAtUnix = strtotime($rawStartedAt);
	$rawStartedAt = $startedAtUnix !== false ? gmdate('c', $startedAtUnix) : '';
}

if ($rawEndedAt !== '') {
	$endedAtUnix = strtotime($rawEndedAt);
	$rawEndedAt = $endedAtUnix !== false ? gmdate('c', $endedAtUnix) : '';
}

function twitchApiRequest($method, $url, $accessToken, $clientID)
{
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Authorization: Bearer ' . $accessToken,
		'Client-Id: ' . $clientID,
	]);
	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);
	return [
		'http_code' => $httpCode,
		'body' => $response,
		'curl_error' => $curlError,
	];
}

function buildTwitchQuery(array $params)
{
	$parts = [];
	foreach ($params as $key => $value) {
		if (is_array($value)) {
			foreach ($value as $item) {
				$parts[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
			}
		} else {
			$parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
		}
	}
	return implode('&', $parts);
}

$flashSuccess = '';
$flashError = '';
$clipDownloadUrls = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_video') {
	$deleteVideoId = isset($_POST['video_id']) ? trim((string) $_POST['video_id']) : '';
	if ($deleteVideoId === '') {
		$flashError = 'Video ID is required.';
	} else {
		$deleteUrl = 'https://api.twitch.tv/helix/videos?' . buildTwitchQuery(['id' => [$deleteVideoId]]);
		$deleteResult = twitchApiRequest('DELETE', $deleteUrl, $accessToken, $clientID);
		$deleteBody = json_decode((string) $deleteResult['body'], true);
		if ($deleteResult['curl_error']) {
			$flashError = 'Delete failed: ' . $deleteResult['curl_error'];
		} elseif ($deleteResult['http_code'] === 200) {
			$deletedItems = isset($deleteBody['data']) && is_array($deleteBody['data']) ? $deleteBody['data'] : [];
			if (in_array($deleteVideoId, $deletedItems, true) || empty($deletedItems)) {
				$flashSuccess = 'Video deleted from Twitch.';
			} else {
				$flashSuccess = 'Delete request completed.';
			}
		} else {
			$apiMessage = '';
			if (is_array($deleteBody) && isset($deleteBody['message'])) {
				$apiMessage = (string) $deleteBody['message'];
			}
			$flashError = 'Delete failed (HTTP ' . (int) $deleteResult['http_code'] . ').' . ($apiMessage !== '' ? ' ' . $apiMessage : '');
		}
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_clip_download') {
	$downloadClipId = isset($_POST['clip_id']) ? trim((string) $_POST['clip_id']) : '';
	$downloadBroadcasterId = isset($_POST['download_broadcaster_id']) ? trim((string) $_POST['download_broadcaster_id']) : '';
	$downloadEditorId = isset($_POST['editor_id']) ? trim((string) $_POST['editor_id']) : $rawEditorId;

	if ($downloadClipId === '' || $downloadBroadcasterId === '' || $downloadEditorId === '') {
		$flashError = 'Clip download requires clip ID, broadcaster ID, and editor ID.';
	} else {
		$downloadUrl = 'https://api.twitch.tv/helix/clips/downloads?' . buildTwitchQuery([
			'editor_id' => $downloadEditorId,
			'broadcaster_id' => $downloadBroadcasterId,
			'clip_id' => [$downloadClipId],
		]);
		$downloadResult = twitchApiRequest('GET', $downloadUrl, $accessToken, $clientID);
		$downloadBody = json_decode((string) $downloadResult['body'], true);
		if ($downloadResult['curl_error']) {
			$flashError = 'Clip download lookup failed: ' . $downloadResult['curl_error'];
		} elseif ((int) $downloadResult['http_code'] === 200) {
			$downloadData = isset($downloadBody['data']) && is_array($downloadBody['data']) ? $downloadBody['data'] : [];
			if (!empty($downloadData) && isset($downloadData[0]['clip_id'])) {
				$clipIdKey = (string) $downloadData[0]['clip_id'];
				$clipDownloadUrls[$clipIdKey] = [
					'landscape_download_url' => isset($downloadData[0]['landscape_download_url']) ? (string) $downloadData[0]['landscape_download_url'] : '',
					'portrait_download_url' => isset($downloadData[0]['portrait_download_url']) ? (string) $downloadData[0]['portrait_download_url'] : '',
				];
				$hasAnyDownload = $clipDownloadUrls[$clipIdKey]['landscape_download_url'] !== '' || $clipDownloadUrls[$clipIdKey]['portrait_download_url'] !== '';
				$flashSuccess = $hasAnyDownload ? 'Clip download URLs loaded.' : 'Clip download lookup returned no downloadable URL yet.';
			} else {
				$flashError = 'Clip download lookup returned no data.';
			}
		} else {
			$apiMessage = is_array($downloadBody) && isset($downloadBody['message']) ? (string) $downloadBody['message'] : '';
			$flashError = 'Clip download lookup failed (HTTP ' . (int) $downloadResult['http_code'] . ').' . ($apiMessage !== '' ? ' ' . $apiMessage : '');
		}
	}
}

$queryForApi = [];
$currentModeLabel = '';
$apiEndpoint = 'videos';
$isClipsMode = false;

if ($mode === 'id') {
	$ids = preg_split('/[\s,]+/', $rawVideoIds, -1, PREG_SPLIT_NO_EMPTY);
	$ids = array_values(array_unique(array_slice($ids, 0, 100)));
	if (!empty($ids)) {
		$queryForApi['id'] = $ids;
		$currentModeLabel = 'Video ID';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'Enter at least one video ID.';
	}
} elseif ($mode === 'game_id') {
	if ($rawGameId !== '') {
		$queryForApi['game_id'] = $rawGameId;
		$queryForApi['period'] = $period;
		$queryForApi['sort'] = $sort;
		$queryForApi['type'] = $type;
		$queryForApi['first'] = $first;
		if ($rawLanguage !== '') {
			$queryForApi['language'] = $rawLanguage;
		}
		$currentModeLabel = 'Game/Category';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'Enter a game/category ID.';
	}
} elseif ($mode === 'clips_id') {
	$isClipsMode = true;
	$apiEndpoint = 'clips';
	$clipIds = preg_split('/[\s,]+/', $rawClipIds, -1, PREG_SPLIT_NO_EMPTY);
	$clipIds = array_values(array_unique(array_slice($clipIds, 0, 100)));
	if (!empty($clipIds)) {
		$queryForApi['id'] = $clipIds;
		$currentModeLabel = 'Clip ID';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'Enter at least one clip ID.';
	}
} elseif ($mode === 'clips_game_id') {
	$isClipsMode = true;
	$apiEndpoint = 'clips';
	if ($rawClipGameId !== '') {
		$queryForApi['game_id'] = $rawClipGameId;
		$queryForApi['first'] = $first;
		if ($rawStartedAt !== '') {
			$queryForApi['started_at'] = $rawStartedAt;
		}
		if ($rawEndedAt !== '') {
			$queryForApi['ended_at'] = $rawEndedAt;
		}
		if ($rawIsFeatured !== '') {
			$queryForApi['is_featured'] = $rawIsFeatured;
		}
		if ($afterCursor !== '') {
			$queryForApi['after'] = $afterCursor;
		} elseif ($beforeCursor !== '') {
			$queryForApi['before'] = $beforeCursor;
		}
		$currentModeLabel = 'Clip Game/Category';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'Enter a clip game/category ID.';
	}
} elseif ($mode === 'clips_broadcaster_id') {
	$isClipsMode = true;
	$apiEndpoint = 'clips';
	if ($rawBroadcasterId !== '') {
		$queryForApi['broadcaster_id'] = $rawBroadcasterId;
		$queryForApi['first'] = $first;
		if ($rawStartedAt !== '') {
			$queryForApi['started_at'] = $rawStartedAt;
		}
		if ($rawEndedAt !== '') {
			$queryForApi['ended_at'] = $rawEndedAt;
		}
		if ($rawIsFeatured !== '') {
			$queryForApi['is_featured'] = $rawIsFeatured;
		}
		if ($afterCursor !== '') {
			$queryForApi['after'] = $afterCursor;
		} elseif ($beforeCursor !== '') {
			$queryForApi['before'] = $beforeCursor;
		}
		$currentModeLabel = 'Clip Broadcaster';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'No Twitch broadcaster ID found for this account.';
	}
} else {
	if ($rawUserId !== '') {
		$queryForApi['user_id'] = $rawUserId;
		$queryForApi['period'] = $period;
		$queryForApi['sort'] = $sort;
		$queryForApi['type'] = $type;
		$queryForApi['first'] = $first;
		if ($afterCursor !== '') {
			$queryForApi['after'] = $afterCursor;
		} elseif ($beforeCursor !== '') {
			$queryForApi['before'] = $beforeCursor;
		}
		$currentModeLabel = 'Channel User';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'No Twitch user ID found for this account.';
	}
}

$videos = [];
$apiError = '';
$paginationCursor = '';
$httpCode = null;

if (empty($flashError) && !empty($queryForApi)) {
	$videosUrl = 'https://api.twitch.tv/helix/' . $apiEndpoint . '?' . buildTwitchQuery($queryForApi);
	$videosResult = twitchApiRequest('GET', $videosUrl, $accessToken, $clientID);
	$httpCode = (int) $videosResult['http_code'];
	$videosBody = json_decode((string) $videosResult['body'], true);
	if ($videosResult['curl_error']) {
		$apiError = 'Unable to connect to Twitch: ' . $videosResult['curl_error'];
	} elseif ($httpCode === 200) {
		$videos = isset($videosBody['data']) && is_array($videosBody['data']) ? $videosBody['data'] : [];
		$paginationCursor = isset($videosBody['pagination']['cursor']) ? (string) $videosBody['pagination']['cursor'] : '';
	} else {
		$apiMessage = is_array($videosBody) && isset($videosBody['message']) ? (string) $videosBody['message'] : '';
		$apiError = 'Twitch API error (HTTP ' . $httpCode . ')' . ($apiMessage !== '' ? ': ' . $apiMessage : '.');
	}
}

function formatVideoDuration($duration) {
	if (!is_string($duration) || $duration === '') {
		return 'Unknown';
	}
	return strtoupper($duration);
}

function formatVideoDate($timestamp) {
	if (!is_string($timestamp) || $timestamp === '') {
		return 'Unknown date';
	}
	$time = strtotime($timestamp);
	if ($time === false) {
		return htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8');
	}
	return date('j M Y, g:i A', $time);
}

function formatClipDuration($seconds) {
	if (!is_numeric($seconds)) {
		return 'Unknown';
	}
	return number_format((float) $seconds, 1) . 's';
}

$baseParams = [
	'tab' => $tab,
	'mode' => $mode,
	'user_id' => $rawUserId,
	'game_id' => $rawGameId,
	'video_ids' => $rawVideoIds,
	'broadcaster_id' => $rawBroadcasterId,
	'editor_id' => $rawEditorId,
	'clip_game_id' => $rawClipGameId,
	'clip_ids' => $rawClipIds,
	'started_at' => $rawStartedAt,
	'ended_at' => $rawEndedAt,
	'is_featured' => $rawIsFeatured,
	'period' => $period,
	'sort' => $sort,
	'type' => $type,
	'first' => $first,
	'language' => $rawLanguage,
];

$prevLink = '';
$nextLink = '';
$supportsCursorPaging = in_array($mode, ['user_id', 'clips_broadcaster_id', 'clips_game_id'], true);
if ($supportsCursorPaging) {
	if ($afterCursor !== '') {
		$prevParams = $baseParams;
		$prevParams['before'] = $afterCursor;
		unset($prevParams['after']);
		$prevLink = 'videos.php?' . http_build_query($prevParams);
	} elseif ($beforeCursor !== '') {
		$nextFromBeforeParams = $baseParams;
		$nextFromBeforeParams['after'] = $beforeCursor;
		unset($nextFromBeforeParams['before']);
		$nextLink = 'videos.php?' . http_build_query($nextFromBeforeParams);
	}
	if ($paginationCursor !== '') {
		if ($beforeCursor !== '') {
			$prevFromBeforeParams = $baseParams;
			$prevFromBeforeParams['before'] = $paginationCursor;
			unset($prevFromBeforeParams['after']);
			$prevLink = 'videos.php?' . http_build_query($prevFromBeforeParams);
		} else {
			$nextParams = $baseParams;
			$nextParams['after'] = $paginationCursor;
			unset($nextParams['before']);
			$nextLink = 'videos.php?' . http_build_query($nextParams);
		}
	}
}

$activeStateParams = $baseParams;
if ($afterCursor !== '') {
	$activeStateParams['after'] = $afterCursor;
}
if ($beforeCursor !== '') {
	$activeStateParams['before'] = $beforeCursor;
}

$postBackActionUrl = 'videos.php';
$postBackQuery = http_build_query($activeStateParams);
if ($postBackQuery !== '') {
	$postBackActionUrl .= '?' . $postBackQuery;
}

$videosTabLink = 'videos.php?' . http_build_query([
	'tab' => 'videos',
	'mode' => 'user_id',
	'user_id' => $rawUserId,
	'game_id' => $rawGameId,
	'video_ids' => $rawVideoIds,
	'period' => $period,
	'sort' => $sort,
	'type' => $type,
	'first' => $first,
	'language' => $rawLanguage,
]);

$clipsTabLink = 'videos.php?' . http_build_query([
	'tab' => 'clips',
	'mode' => 'clips_broadcaster_id',
	'broadcaster_id' => $rawBroadcasterId,
	'editor_id' => $rawEditorId,
	'clip_game_id' => $rawClipGameId,
	'clip_ids' => $rawClipIds,
	'started_at' => $rawStartedAt,
	'ended_at' => $rawEndedAt,
	'is_featured' => $rawIsFeatured,
	'first' => $first,
]);

ob_start();
?>
<div class="columns is-centered">
	<div class="column is-fullwidth">
		<div class="card has-background-dark has-text-white mb-5" style="border-radius:14px; box-shadow:0 4px 24px #000a;">
			<header class="card-header" style="border-bottom:1px solid #23272f;">
				<div class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
					<span class="icon mr-2"><i class="fas fa-photo-video"></i></span>
					Videos & Clips Analytics
				</div>
			</header>
			<div class="card-content">
				<div class="tabs is-boxed is-medium mb-4">
					<ul>
						<li class="<?php echo $tab === 'videos' ? 'is-active' : ''; ?>">
							<a href="<?php echo htmlspecialchars($videosTabLink, ENT_QUOTES, 'UTF-8'); ?>">
								<span class="icon is-small"><i class="fas fa-photo-video"></i></span>
								<span>Video Archive VODs</span>
							</a>
						</li>
						<li class="<?php echo $tab === 'clips' ? 'is-active' : ''; ?>">
							<a href="<?php echo htmlspecialchars($clipsTabLink, ENT_QUOTES, 'UTF-8'); ?>">
								<span class="icon is-small"><i class="fas fa-film"></i></span>
								<span>Clips</span>
							</a>
						</li>
					</ul>
				</div>
				<?php if ($flashSuccess !== ''): ?>
					<div class="notification is-success"><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
				<?php if ($flashError !== ''): ?>
					<div class="notification is-danger"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
				<?php if ($apiError !== ''): ?>
					<div class="notification is-danger"><?php echo htmlspecialchars($apiError, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
				<form method="get" class="box has-background-grey-darker" style="border:1px solid #2d3138;">
					<input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>">
					<div class="columns is-multiline">
						<div class="column is-12-mobile is-4-desktop">
							<label class="label has-text-white">Lookup Mode</label>
							<div class="select is-fullwidth">
								<select name="mode" id="videosModeSelect">
									<?php if ($tab === 'videos'): ?>
										<option value="user_id" <?php echo $mode === 'user_id' ? 'selected' : ''; ?>>By User ID</option>
										<option value="game_id" <?php echo $mode === 'game_id' ? 'selected' : ''; ?>>By Game/Category ID</option>
										<option value="id" <?php echo $mode === 'id' ? 'selected' : ''; ?>>By Video ID(s)</option>
									<?php else: ?>
										<option value="clips_broadcaster_id" <?php echo $mode === 'clips_broadcaster_id' ? 'selected' : ''; ?>>Clips by Broadcaster ID</option>
										<option value="clips_game_id" <?php echo $mode === 'clips_game_id' ? 'selected' : ''; ?>>Clips by Game/Category ID</option>
										<option value="clips_id" <?php echo $mode === 'clips_id' ? 'selected' : ''; ?>>Clips by Clip ID(s)</option>
									<?php endif; ?>
								</select>
							</div>
						</div>
						<div class="column is-12-mobile is-4-desktop js-mode-user">
							<label class="label has-text-white">User ID</label>
							<input class="input" type="text" name="user_id" value="<?php echo htmlspecialchars($rawUserId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Twitch user ID">
						</div>
						<div class="column is-12-mobile is-4-desktop js-mode-game">
							<label class="label has-text-white">Game/Category ID</label>
							<input class="input" type="text" name="game_id" value="<?php echo htmlspecialchars($rawGameId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Twitch game/category ID">
						</div>
						<div class="column is-12 js-mode-id">
							<label class="label has-text-white">Video IDs</label>
							<input class="input" type="text" name="video_ids" value="<?php echo htmlspecialchars($rawVideoIds, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Example: 335921245, 123456789">
						</div>
						<div class="column is-12-mobile is-4-desktop js-mode-clip-broadcaster">
							<label class="label has-text-white">Broadcaster ID</label>
							<input class="input" type="text" name="broadcaster_id" value="<?php echo htmlspecialchars($rawBroadcasterId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Twitch broadcaster ID">
						</div>
						<div class="column is-12-mobile is-4-desktop js-filter-clip-download">
							<label class="label has-text-white">Editor ID (for clip download)</label>
							<input class="input" type="text" name="editor_id" value="<?php echo htmlspecialchars($rawEditorId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Twitch editor user ID">
						</div>
						<div class="column is-12-mobile is-4-desktop js-mode-clip-game">
							<label class="label has-text-white">Clip Game/Category ID</label>
							<input class="input" type="text" name="clip_game_id" value="<?php echo htmlspecialchars($rawClipGameId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Twitch game/category ID">
						</div>
						<div class="column is-12 js-mode-clip-id">
							<label class="label has-text-white">Clip IDs</label>
							<input class="input" type="text" name="clip_ids" value="<?php echo htmlspecialchars($rawClipIds, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Example: Ag1234, Bx5678">
						</div>
						<div class="column is-12-mobile is-3-desktop js-filter-common">
							<label class="label has-text-white">Period</label>
							<div class="select is-fullwidth">
								<select name="period">
									<option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>All</option>
									<option value="day" <?php echo $period === 'day' ? 'selected' : ''; ?>>Day</option>
									<option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Week</option>
									<option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Month</option>
								</select>
							</div>
						</div>
						<div class="column is-12-mobile is-3-desktop js-filter-common">
							<label class="label has-text-white">Sort</label>
							<div class="select is-fullwidth">
								<select name="sort">
									<option value="time" <?php echo $sort === 'time' ? 'selected' : ''; ?>>Time</option>
									<option value="trending" <?php echo $sort === 'trending' ? 'selected' : ''; ?>>Trending</option>
									<option value="views" <?php echo $sort === 'views' ? 'selected' : ''; ?>>Views</option>
								</select>
							</div>
						</div>
						<div class="column is-12-mobile is-3-desktop js-filter-common">
							<label class="label has-text-white">Type</label>
							<div class="select is-fullwidth">
								<select name="type">
									<option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All</option>
									<option value="archive" <?php echo $type === 'archive' ? 'selected' : ''; ?>>Archive</option>
									<option value="highlight" <?php echo $type === 'highlight' ? 'selected' : ''; ?>>Highlight</option>
									<option value="upload" <?php echo $type === 'upload' ? 'selected' : ''; ?>>Upload</option>
								</select>
							</div>
						</div>
						<div class="column is-12-mobile is-3-desktop js-filter-page">
							<label class="label has-text-white">Results per page</label>
							<input class="input" type="number" name="first" min="1" max="100" value="<?php echo (int) $first; ?>">
						</div>
						<div class="column is-12-mobile is-4-desktop js-filter-game">
							<label class="label has-text-white">Language (optional)</label>
							<input class="input" type="text" name="language" value="<?php echo htmlspecialchars($rawLanguage, ENT_QUOTES, 'UTF-8'); ?>" placeholder="en, de, fr, other">
						</div>
						<div class="column is-12-mobile is-4-desktop js-filter-clip-broadcaster-game">
							<label class="label has-text-white">Started At (optional)</label>
							<input class="input" type="text" name="started_at" value="<?php echo htmlspecialchars($rawStartedAt, ENT_QUOTES, 'UTF-8'); ?>" placeholder="RFC3339 e.g. 2025-02-01T00:00:00Z">
						</div>
						<div class="column is-12-mobile is-4-desktop js-filter-clip-broadcaster-game">
							<label class="label has-text-white">Ended At (optional)</label>
							<input class="input" type="text" name="ended_at" value="<?php echo htmlspecialchars($rawEndedAt, ENT_QUOTES, 'UTF-8'); ?>" placeholder="RFC3339 e.g. 2025-02-08T00:00:00Z">
						</div>
						<div class="column is-12-mobile is-4-desktop js-filter-clip-broadcaster-game">
							<label class="label has-text-white">Featured Filter (optional)</label>
							<div class="select is-fullwidth">
								<select name="is_featured">
									<option value="" <?php echo $rawIsFeatured === '' ? 'selected' : ''; ?>>All Clips</option>
									<option value="true" <?php echo $rawIsFeatured === 'true' ? 'selected' : ''; ?>>Featured only</option>
									<option value="false" <?php echo $rawIsFeatured === 'false' ? 'selected' : ''; ?>>Not featured only</option>
								</select>
							</div>
						</div>
					</div>
					<div class="buttons mt-2">
						<button type="submit" class="button is-link is-medium"><i class="fas fa-search mr-2"></i>Load Results</button>
						<a class="button is-light is-medium" href="videos.php"><i class="fas fa-undo mr-2"></i>Reset</a>
					</div>
				</form>
				<div class="is-flex is-justify-content-space-between is-align-items-center mb-4" style="gap:1rem; flex-wrap:wrap;">
					<div>
						<p class="has-text-grey-light mb-1">Source: <?php echo htmlspecialchars($currentModeLabel !== '' ? $currentModeLabel : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
						<p class="has-text-white is-size-5">Found <?php echo count($videos); ?> <?php echo $isClipsMode ? 'clip' : 'video'; ?><?php echo count($videos) === 1 ? '' : 's'; ?></p>
					</div>
					<?php if ($supportsCursorPaging && ($prevLink !== '' || $nextLink !== '')): ?>
						<div class="buttons">
							<?php if ($prevLink !== ''): ?>
								<a class="button is-light" href="<?php echo htmlspecialchars($prevLink, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-chevron-left mr-2"></i>Previous</a>
							<?php endif; ?>
							<?php if ($nextLink !== ''): ?>
								<a class="button is-link is-light" href="<?php echo htmlspecialchars($nextLink, ENT_QUOTES, 'UTF-8'); ?>">Next<i class="fas fa-chevron-right ml-2"></i></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php if (empty($videos) && $apiError === '' && $flashError === '' && !empty($queryForApi)): ?>
					<div class="notification is-info">No <?php echo $isClipsMode ? 'clips' : 'videos'; ?> matched the current filters.</div>
				<?php endif; ?>
				<div class="columns is-multiline">
					<?php foreach ($videos as $video): ?>
						<?php
						$videoId = isset($video['id']) ? (string) $video['id'] : '';
						$videoTitle = isset($video['title']) ? (string) $video['title'] : ($isClipsMode ? 'Untitled Clip' : 'Untitled Video');
						$videoDescription = isset($video['description']) ? (string) $video['description'] : '';
						$thumbnail = isset($video['thumbnail_url']) ? (string) $video['thumbnail_url'] : '';
						if ($thumbnail !== '') {
							$thumbnail = str_replace(['%{width}', '%{height}'], ['320', '180'], $thumbnail);
						}
						$videoUrl = isset($video['url']) ? (string) $video['url'] : '';
						$videoViews = isset($video['view_count']) ? (int) $video['view_count'] : 0;
						$videoType = isset($video['type']) ? (string) $video['type'] : ($isClipsMode ? 'clip' : 'unknown');
						$videoLang = isset($video['language']) ? (string) $video['language'] : 'unknown';
						$videoDuration = $isClipsMode ? formatClipDuration($video['duration'] ?? null) : formatVideoDuration($video['duration'] ?? '');
						$videoDate = $isClipsMode ? formatVideoDate($video['created_at'] ?? '') : formatVideoDate($video['published_at'] ?? '');
						$clipCreator = isset($video['creator_name']) ? (string) $video['creator_name'] : '';
						$clipBroadcaster = isset($video['broadcaster_name']) ? (string) $video['broadcaster_name'] : '';
						$clipBroadcasterId = isset($video['broadcaster_id']) ? (string) $video['broadcaster_id'] : '';
						$clipFeatured = isset($video['is_featured']) ? (bool) $video['is_featured'] : false;
						$clipVodOffset = isset($video['vod_offset']) && $video['vod_offset'] !== null ? (int) $video['vod_offset'] : null;
						$clipDownloads = ($isClipsMode && $videoId !== '' && isset($clipDownloadUrls[$videoId]) && is_array($clipDownloadUrls[$videoId])) ? $clipDownloadUrls[$videoId] : [];
						$clipLandscapeDownload = isset($clipDownloads['landscape_download_url']) ? (string) $clipDownloads['landscape_download_url'] : '';
						$clipPortraitDownload = isset($clipDownloads['portrait_download_url']) ? (string) $clipDownloads['portrait_download_url'] : '';
						?>
						<div class="column is-12-mobile is-6-tablet is-4-desktop">
							<div class="card has-background-grey-darker has-text-white" style="height:100%; border:1px solid #2d3138;">
								<div class="card-image">
									<figure class="image is-16by9">
										<?php if ($thumbnail !== ''): ?>
											<img src="<?php echo htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8'); ?>" alt="Video thumbnail">
										<?php else: ?>
											<div class="has-background-dark is-flex is-align-items-center is-justify-content-center" style="height:100%; min-height:180px;">
												<span class="has-text-grey-light"><i class="fas fa-image mr-2"></i>No Thumbnail</span>
											</div>
										<?php endif; ?>
									</figure>
								</div>
								<div class="card-content">
									<p class="title is-6 has-text-white mb-2" style="min-height:3em; overflow:hidden;">
										<?php echo htmlspecialchars($videoTitle, ENT_QUOTES, 'UTF-8'); ?>
									</p>
									<?php if (!$isClipsMode): ?>
										<p class="is-size-7 has-text-grey-light mb-3" style="min-height:4.5em; overflow:hidden;">
											<?php echo htmlspecialchars($videoDescription !== '' ? $videoDescription : 'No description provided.', ENT_QUOTES, 'UTF-8'); ?>
										</p>
									<?php endif; ?>
									<div class="tags mb-3">
										<span class="tag is-info is-light"><?php echo htmlspecialchars($videoType, ENT_QUOTES, 'UTF-8'); ?></span>
										<span class="tag is-warning is-light"><?php echo htmlspecialchars($videoLang, ENT_QUOTES, 'UTF-8'); ?></span>
										<span class="tag is-primary is-light"><?php echo htmlspecialchars($videoDuration, ENT_QUOTES, 'UTF-8'); ?></span>
										<?php if ($isClipsMode): ?>
											<span class="tag is-success is-light"><?php echo $clipFeatured ? 'featured' : 'not featured'; ?></span>
										<?php endif; ?>
									</div>
									<?php if ($isClipsMode): ?>
										<p class="is-size-7 has-text-grey-light mb-1"><strong>Creator:</strong> <?php echo htmlspecialchars($clipCreator !== '' ? $clipCreator : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
										<p class="is-size-7 has-text-grey-light mb-1"><strong>Broadcaster:</strong> <?php echo htmlspecialchars($clipBroadcaster !== '' ? $clipBroadcaster : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
										<p class="is-size-7 has-text-grey-light mb-1"><strong>VOD Offset:</strong> <?php echo $clipVodOffset !== null ? number_format($clipVodOffset) . 's' : 'N/A'; ?></p>
									<?php endif; ?>
									<p class="is-size-7 has-text-grey-light mb-1"><strong>Views:</strong> <?php echo number_format($videoViews); ?></p>
									<p class="is-size-7 has-text-grey-light mb-4"><strong><?php echo $isClipsMode ? 'Created' : 'Published'; ?>:</strong> <?php echo htmlspecialchars($videoDate, ENT_QUOTES, 'UTF-8'); ?></p>
									<div class="buttons is-flex is-justify-content-space-between" style="gap:0.5rem; flex-wrap:wrap;">
										<?php if ($videoUrl !== ''): ?>
											<a class="button is-link is-small" href="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
												<i class="fas fa-external-link-alt mr-1"></i>Open
											</a>
										<?php else: ?>
											<button class="button is-light is-small" disabled>No URL</button>
										<?php endif; ?>
										<?php if (!$isClipsMode && $videoId !== ''): ?>
											<button
												type="button"
												class="button is-danger is-small delete-video-btn"
												data-video-id="<?php echo htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8'); ?>">
												<i class="fas fa-trash mr-1"></i>Delete
											</button>
										<?php endif; ?>
										<?php if ($isClipsMode && $videoId !== '' && $clipBroadcasterId !== ''): ?>
											<form method="post" action="<?php echo htmlspecialchars($postBackActionUrl, ENT_QUOTES, 'UTF-8'); ?>" class="is-inline-block">
												<input type="hidden" name="action" value="get_clip_download">
												<input type="hidden" name="clip_id" value="<?php echo htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8'); ?>">
												<input type="hidden" name="download_broadcaster_id" value="<?php echo htmlspecialchars($clipBroadcasterId, ENT_QUOTES, 'UTF-8'); ?>">
												<input type="hidden" name="editor_id" value="<?php echo htmlspecialchars($rawEditorId, ENT_QUOTES, 'UTF-8'); ?>">
												<button type="submit" class="button is-primary is-small">
													<i class="fas fa-download mr-1"></i>Get Download URLs
												</button>
											</form>
										<?php endif; ?>
									</div>
									<?php if ($isClipsMode && ($clipLandscapeDownload !== '' || $clipPortraitDownload !== '')): ?>
										<div class="buttons mt-2" style="gap:0.5rem; flex-wrap:wrap;">
											<?php if ($clipLandscapeDownload !== ''): ?>
												<a class="button is-success is-small is-light" href="<?php echo htmlspecialchars($clipLandscapeDownload, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" download>
													<i class="fas fa-download mr-1"></i>Landscape
												</a>
											<?php endif; ?>
											<?php if ($clipPortraitDownload !== ''): ?>
												<a class="button is-success is-small is-light" href="<?php echo htmlspecialchars($clipPortraitDownload, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" download>
													<i class="fas fa-download mr-1"></i>Portrait
												</a>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
</div>
<form id="deleteVideoForm" method="post" style="display:none;">
	<input type="hidden" name="action" value="delete_video">
	<input type="hidden" name="video_id" id="deleteVideoIdInput" value="">
</form>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
	const modeSelect = document.getElementById('videosModeSelect');
	const allGroups = document.querySelectorAll([
		'.js-mode-user',
		'.js-mode-game',
		'.js-mode-id',
		'.js-filter-common',
		'.js-filter-page',
		'.js-filter-game',
		'.js-mode-clip-broadcaster',
		'.js-mode-clip-game',
		'.js-mode-clip-id',
		'.js-filter-clip-download',
		'.js-filter-clip-broadcaster-game'
	].join(','));

	const modeMap = {
		user_id: ['.js-mode-user', '.js-filter-common', '.js-filter-page'],
		game_id: ['.js-mode-game', '.js-filter-common', '.js-filter-game', '.js-filter-page'],
		id: ['.js-mode-id'],
		clips_broadcaster_id: ['.js-mode-clip-broadcaster', '.js-filter-page', '.js-filter-clip-download', '.js-filter-clip-broadcaster-game'],
		clips_game_id: ['.js-mode-clip-game', '.js-filter-page', '.js-filter-clip-download', '.js-filter-clip-broadcaster-game'],
		clips_id: ['.js-mode-clip-id', '.js-filter-clip-download']
	};

	function toggleVisibility(elements, visible) {
		elements.forEach(function (el) {
			el.style.display = visible ? '' : 'none';
		});
	}

	function updateModeFields() {
		const mode = modeSelect ? modeSelect.value : 'user_id';
		toggleVisibility(allGroups, false);
		const selectors = modeMap[mode] || modeMap.user_id;
		selectors.forEach(function (selector) {
			toggleVisibility(document.querySelectorAll(selector), true);
		});
	}
	if (modeSelect) {
		updateModeFields();
		modeSelect.addEventListener('change', updateModeFields);
	}
	const deleteForm = document.getElementById('deleteVideoForm');
	const deleteVideoIdInput = document.getElementById('deleteVideoIdInput');
	document.querySelectorAll('.delete-video-btn').forEach(function (button) {
		button.addEventListener('click', function () {
			const videoId = button.getAttribute('data-video-id');
			if (!videoId || !deleteForm || !deleteVideoIdInput) {
				return;
			}
			Swal.fire({
				title: 'Delete Twitch Video?',
				text: 'This video will be deleted from Twitch and this action is irreversible.',
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#d33',
				cancelButtonColor: '#3085d6',
				confirmButtonText: 'Yes, delete video',
				cancelButtonText: 'Cancel',
				background: '#333',
				color: '#fff'
			}).then((result) => {
				if (result.isConfirmed) {
					deleteVideoIdInput.value = videoId;
					deleteForm.submit();
				}
			});
		});
	});
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>