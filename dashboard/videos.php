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
$channelUserId = trim((string) $defaultUserId);
$allowedTabs = ['videos', 'clips'];
$requestedTab = isset($_GET['tab']) ? trim((string) $_GET['tab']) : '';
$tab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'videos';
$mode = $tab === 'clips' ? 'clips_broadcaster_id' : 'user_id';
$first = 100;
$rawUserId = $channelUserId;
$rawBroadcasterId = $channelUserId;
$rawEditorId = $channelUserId;

function twitchApiRequest($method, $url, $accessToken, $clientID) {
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

function buildTwitchQuery(array $params) {
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

function fetchAllTwitchItems($endpoint, array $baseQuery, $accessToken, $clientID, $maxItems = 1000) {
	$items = [];
	$cursor = '';
	$httpCode = null;
	$apiError = '';
	while (count($items) < $maxItems) {
		$query = $baseQuery;
		if ($cursor !== '') {
			$query['after'] = $cursor;
		}
		$url = 'https://api.twitch.tv/helix/' . $endpoint . '?' . buildTwitchQuery($query);
		$result = twitchApiRequest('GET', $url, $accessToken, $clientID);
		$httpCode = (int) $result['http_code'];
		$body = json_decode((string) $result['body'], true);
		if ($result['curl_error']) {
			$apiError = 'Unable to connect to Twitch: ' . $result['curl_error'];
			break;
		}
		if ($httpCode !== 200) {
			$apiMessage = is_array($body) && isset($body['message']) ? (string) $body['message'] : '';
			$apiError = 'Twitch API error (HTTP ' . $httpCode . ')' . ($apiMessage !== '' ? ': ' . $apiMessage : '.');
			break;
		}
		$pageData = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
		if (empty($pageData)) {
			break;
		}
		$spaceLeft = $maxItems - count($items);
		if (count($pageData) > $spaceLeft) {
			$pageData = array_slice($pageData, 0, $spaceLeft);
		}
		$items = array_merge($items, $pageData);
		$cursor = isset($body['pagination']['cursor']) ? (string) $body['pagination']['cursor'] : '';
		if ($cursor === '' || count($pageData) === 0) {
			break;
		}
	}
	return [
		'items' => $items,
		'http_code' => $httpCode,
		'error' => $apiError,
	];
}

function fetchClipDownloadUrls(array $clipIds, $broadcasterId, $editorId, $accessToken, $clientID) {
	$downloads = [];
	$error = '';
	$uniqueClipIds = array_values(array_unique(array_filter(array_map('strval', $clipIds), static function ($value) {
		return $value !== '';
	})));
	if (empty($uniqueClipIds) || $broadcasterId === '' || $editorId === '') {
		return [
			'downloads' => $downloads,
			'error' => $error,
		];
	}
	$chunks = array_chunk($uniqueClipIds, 10);
	foreach ($chunks as $chunk) {
		$downloadUrl = 'https://api.twitch.tv/helix/clips/downloads?' . buildTwitchQuery([
			'editor_id' => $editorId,
			'broadcaster_id' => $broadcasterId,
			'clip_id' => $chunk,
		]);
		$downloadResult = twitchApiRequest('GET', $downloadUrl, $accessToken, $clientID);
		$downloadBody = json_decode((string) $downloadResult['body'], true);
		if ($downloadResult['curl_error']) {
			$error = 'Clip download lookup failed: ' . $downloadResult['curl_error'];
			break;
		}
		if ((int) $downloadResult['http_code'] !== 200) {
			$apiMessage = is_array($downloadBody) && isset($downloadBody['message']) ? (string) $downloadBody['message'] : '';
			$error = 'Clip download lookup failed (HTTP ' . (int) $downloadResult['http_code'] . ').' . ($apiMessage !== '' ? ' ' . $apiMessage : '');
			break;
		}
		$downloadData = isset($downloadBody['data']) && is_array($downloadBody['data']) ? $downloadBody['data'] : [];
		foreach ($downloadData as $item) {
			if (!isset($item['clip_id'])) {
				continue;
			}
			$clipIdKey = (string) $item['clip_id'];
			$downloads[$clipIdKey] = [
				'landscape_download_url' => isset($item['landscape_download_url']) ? (string) $item['landscape_download_url'] : '',
				'portrait_download_url' => isset($item['portrait_download_url']) ? (string) $item['portrait_download_url'] : '',
			];
		}
	}
	return [
		'downloads' => $downloads,
		'error' => $error,
	];
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

$queryForApi = [];
$currentModeLabel = '';
$apiEndpoint = 'videos';
$isClipsMode = false;

if ($mode === 'clips_broadcaster_id') {
	$isClipsMode = true;
	$apiEndpoint = 'clips';
	if ($rawBroadcasterId !== '') {
		$queryForApi['broadcaster_id'] = $rawBroadcasterId;
		$queryForApi['first'] = $first;
		$currentModeLabel = 'Clip Broadcaster';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'No Twitch broadcaster ID found for this account.';
	}
} else {
	if ($channelUserId !== '') {
		$queryForApi['user_id'] = $rawUserId;
		$queryForApi['first'] = $first;
		$currentModeLabel = 'Channel User';
	} else {
		$flashError = $flashError !== '' ? $flashError : 'No Twitch user ID found for this account.';
	}
}

$videos = [];
$apiError = '';
$httpCode = null;

if (empty($flashError) && !empty($queryForApi)) {
	$fetchResult = fetchAllTwitchItems($apiEndpoint, $queryForApi, $accessToken, $clientID, 1000);
	$videos = $fetchResult['items'];
	$httpCode = $fetchResult['http_code'];
	$apiError = $fetchResult['error'];
}

if ($isClipsMode && !empty($videos) && $channelUserId !== '') {
	$clipIdsForDownload = [];
	foreach ($videos as $clipItem) {
		if (isset($clipItem['id']) && (string) $clipItem['id'] !== '') {
			$clipIdsForDownload[] = (string) $clipItem['id'];
		}
	}
	$downloadLookup = fetchClipDownloadUrls($clipIdsForDownload, $channelUserId, $channelUserId, $accessToken, $clientID);
	$clipDownloadUrls = $downloadLookup['downloads'];
	if ($downloadLookup['error'] !== '' && $flashError === '') {
		$flashError = $downloadLookup['error'];
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

$videosTabLink = 'videos.php?' . http_build_query([
	'tab' => 'videos',
]);

$clipsTabLink = 'videos.php?' . http_build_query([
	'tab' => 'clips',
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
				<?php if (empty($videos) && $apiError === '' && $flashError === '' && !empty($queryForApi)): ?>
					<div class="notification is-info">No <?php echo $isClipsMode ? 'clips' : 'videos'; ?> matched the current filters.</div>
				<?php endif; ?>
				<?php if ($channelUserId === ''): ?>
					<div class="notification is-danger">No Twitch channel ID is available for this login session.</div>
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
											<?php if ($clipLandscapeDownload !== ''): ?>
												<a class="button is-primary is-small" href="<?php echo htmlspecialchars($clipLandscapeDownload, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" download>
													<i class="fas fa-download mr-1"></i>Landscape
												</a>
											<?php endif; ?>
											<?php if ($clipPortraitDownload !== ''): ?>
												<a class="button is-primary is-small" href="<?php echo htmlspecialchars($clipPortraitDownload, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" download>
													<i class="fas fa-download mr-1"></i>Portrait
												</a>
											<?php endif; ?>
											<?php if ($clipLandscapeDownload === '' && $clipPortraitDownload === ''): ?>
												<button class="button is-light is-small" disabled>
													<i class="fas fa-download mr-1"></i>Download unavailable
												</button>
											<?php endif; ?>
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