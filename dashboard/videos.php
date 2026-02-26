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

$allowedModes = ['user_id', 'game_id', 'id'];
$allowedPeriods = ['all', 'day', 'week', 'month'];
$allowedSort = ['time', 'trending', 'views'];
$allowedTypes = ['all', 'archive', 'highlight', 'upload'];

$mode = isset($_GET['mode']) && in_array($_GET['mode'], $allowedModes, true) ? $_GET['mode'] : 'user_id';
$period = isset($_GET['period']) && in_array($_GET['period'], $allowedPeriods, true) ? $_GET['period'] : 'all';
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSort, true) ? $_GET['sort'] : 'time';
$type = isset($_GET['type']) && in_array($_GET['type'], $allowedTypes, true) ? $_GET['type'] : 'all';
$first = isset($_GET['first']) ? max(1, min(100, (int) $_GET['first'])) : 20;

$rawUserId = isset($_GET['user_id']) ? trim((string) $_GET['user_id']) : (string) $defaultUserId;
$rawGameId = isset($_GET['game_id']) ? trim((string) $_GET['game_id']) : '';
$rawVideoIds = isset($_GET['video_ids']) ? trim((string) $_GET['video_ids']) : '';
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

$queryForApi = [];
$currentModeLabel = '';

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
	$videosUrl = 'https://api.twitch.tv/helix/videos?' . buildTwitchQuery($queryForApi);
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

$baseParams = [
	'mode' => $mode,
	'user_id' => $rawUserId,
	'game_id' => $rawGameId,
	'video_ids' => $rawVideoIds,
	'period' => $period,
	'sort' => $sort,
	'type' => $type,
	'first' => $first,
	'language' => $rawLanguage,
];

$prevLink = '';
$nextLink = '';
if ($mode === 'user_id') {
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

ob_start();
?>
<div class="columns is-centered">
	<div class="column is-fullwidth">
		<div class="card has-background-dark has-text-white mb-5" style="border-radius:14px; box-shadow:0 4px 24px #000a;">
			<header class="card-header" style="border-bottom:1px solid #23272f;">
				<div class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
					<span class="icon mr-2"><i class="fas fa-photo-video"></i></span>
					Videos Analytics
				</div>
			</header>
			<div class="card-content">
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
					<div class="columns is-multiline">
						<div class="column is-12-mobile is-4-desktop">
							<label class="label has-text-white">Lookup Mode</label>
							<div class="select is-fullwidth">
								<select name="mode" id="videosModeSelect">
									<option value="user_id" <?php echo $mode === 'user_id' ? 'selected' : ''; ?>>By User ID</option>
									<option value="game_id" <?php echo $mode === 'game_id' ? 'selected' : ''; ?>>By Game/Category ID</option>
									<option value="id" <?php echo $mode === 'id' ? 'selected' : ''; ?>>By Video ID(s)</option>
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
						<div class="column is-12-mobile is-3-desktop js-filter-common">
							<label class="label has-text-white">Results per page</label>
							<input class="input" type="number" name="first" min="1" max="100" value="<?php echo (int) $first; ?>">
						</div>
						<div class="column is-12-mobile is-4-desktop js-filter-game">
							<label class="label has-text-white">Language (optional)</label>
							<input class="input" type="text" name="language" value="<?php echo htmlspecialchars($rawLanguage, ENT_QUOTES, 'UTF-8'); ?>" placeholder="en, de, fr, other">
						</div>
					</div>
					<div class="buttons mt-2">
						<button type="submit" class="button is-link is-medium"><i class="fas fa-search mr-2"></i>Load Videos</button>
						<a class="button is-light is-medium" href="videos.php"><i class="fas fa-undo mr-2"></i>Reset</a>
					</div>
				</form>
				<div class="is-flex is-justify-content-space-between is-align-items-center mb-4" style="gap:1rem; flex-wrap:wrap;">
					<div>
						<p class="has-text-grey-light mb-1">Source: <?php echo htmlspecialchars($currentModeLabel !== '' ? $currentModeLabel : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
						<p class="has-text-white is-size-5">Found <?php echo count($videos); ?> video<?php echo count($videos) === 1 ? '' : 's'; ?></p>
					</div>
					<?php if ($mode === 'user_id' && ($prevLink !== '' || $nextLink !== '')): ?>
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
					<div class="notification is-info">No videos matched the current filters.</div>
				<?php endif; ?>
				<div class="columns is-multiline">
					<?php foreach ($videos as $video): ?>
						<?php
						$videoId = isset($video['id']) ? (string) $video['id'] : '';
						$videoTitle = isset($video['title']) ? (string) $video['title'] : 'Untitled Video';
						$videoDescription = isset($video['description']) ? (string) $video['description'] : '';
						$thumbnail = isset($video['thumbnail_url']) ? (string) $video['thumbnail_url'] : '';
						if ($thumbnail !== '') {
							$thumbnail = str_replace(['%{width}', '%{height}'], ['320', '180'], $thumbnail);
						}
						$videoUrl = isset($video['url']) ? (string) $video['url'] : '';
						$videoViews = isset($video['view_count']) ? (int) $video['view_count'] : 0;
						$videoType = isset($video['type']) ? (string) $video['type'] : 'unknown';
						$videoLang = isset($video['language']) ? (string) $video['language'] : 'unknown';
						$videoDuration = formatVideoDuration($video['duration'] ?? '');
						$videoDate = formatVideoDate($video['published_at'] ?? '');
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
									<p class="is-size-7 has-text-grey-light mb-3" style="min-height:4.5em; overflow:hidden;">
										<?php echo htmlspecialchars($videoDescription !== '' ? $videoDescription : 'No description provided.', ENT_QUOTES, 'UTF-8'); ?>
									</p>
									<div class="tags mb-3">
										<span class="tag is-info is-light"><?php echo htmlspecialchars($videoType, ENT_QUOTES, 'UTF-8'); ?></span>
										<span class="tag is-warning is-light"><?php echo htmlspecialchars($videoLang, ENT_QUOTES, 'UTF-8'); ?></span>
										<span class="tag is-primary is-light"><?php echo htmlspecialchars($videoDuration, ENT_QUOTES, 'UTF-8'); ?></span>
									</div>
									<p class="is-size-7 has-text-grey-light mb-1"><strong>Views:</strong> <?php echo number_format($videoViews); ?></p>
									<p class="is-size-7 has-text-grey-light mb-4"><strong>Published:</strong> <?php echo htmlspecialchars($videoDate, ENT_QUOTES, 'UTF-8'); ?></p>
									<div class="buttons is-flex is-justify-content-space-between">
										<?php if ($videoUrl !== ''): ?>
											<a class="button is-link is-small" href="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
												<i class="fas fa-external-link-alt mr-1"></i>Open
											</a>
										<?php else: ?>
											<button class="button is-light is-small" disabled>No URL</button>
										<?php endif; ?>
										<?php if ($videoId !== ''): ?>
											<button
												type="button"
												class="button is-danger is-small delete-video-btn"
												data-video-id="<?php echo htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8'); ?>">
												<i class="fas fa-trash mr-1"></i>Delete
											</button>
										<?php endif; ?>
									</div>
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
	const modeUserFields = document.querySelectorAll('.js-mode-user, .js-filter-common');
	const modeGameFields = document.querySelectorAll('.js-mode-game, .js-filter-common, .js-filter-game');
	const modeIdFields = document.querySelectorAll('.js-mode-id');
	function toggleVisibility(elements, visible) {
		elements.forEach(function (el) {
			el.style.display = visible ? '' : 'none';
		});
	}

	function updateModeFields() {
		const mode = modeSelect ? modeSelect.value : 'user_id';
		toggleVisibility(modeUserFields, mode === 'user_id');
		toggleVisibility(modeGameFields, mode === 'game_id');
		toggleVisibility(modeIdFields, mode === 'id');
		if (mode === 'user_id') {
			toggleVisibility(document.querySelectorAll('.js-mode-user'), true);
			toggleVisibility(document.querySelectorAll('.js-mode-game, .js-filter-game'), false);
			toggleVisibility(document.querySelectorAll('.js-filter-common'), true);
		} else if (mode === 'game_id') {
			toggleVisibility(document.querySelectorAll('.js-mode-user'), false);
			toggleVisibility(document.querySelectorAll('.js-mode-game'), true);
			toggleVisibility(document.querySelectorAll('.js-filter-common, .js-filter-game'), true);
		} else {
			toggleVisibility(document.querySelectorAll('.js-mode-user, .js-mode-game, .js-filter-common, .js-filter-game'), false);
			toggleVisibility(document.querySelectorAll('.js-mode-id'), true);
		}
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