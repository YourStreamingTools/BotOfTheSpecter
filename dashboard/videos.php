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
$channelUserId = trim((string) ($_SESSION['twitchUserId'] ?? ($broadcasterID ?? '')));
$editorUserId = ($isActAs && !empty($originalContext['twitchUserId'])) ? (string) $originalContext['twitchUserId'] : $channelUserId;
$allowedTabs = ['videos', 'highlights', 'uploads', 'clips'];
$requestedTab = isset($_GET['tab']) ? trim((string) $_GET['tab']) : '';
$tab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'videos';

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

function fetchTwitchPage($endpoint, array $query, $accessToken, $clientID) {
	$url = 'https://api.twitch.tv/helix/' . $endpoint . '?' . buildTwitchQuery($query);
	$result = twitchApiRequest('GET', $url, $accessToken, $clientID);
	$httpCode = (int) $result['http_code'];
	$body = json_decode((string) $result['body'], true);
	if ($result['curl_error']) {
		return [
			'items' => [],
			'cursor' => '',
			'http_code' => $httpCode,
			'error' => 'Unable to connect to Twitch: ' . $result['curl_error'],
		];
	}
	if ($httpCode !== 200) {
		$apiMessage = is_array($body) && isset($body['message']) ? (string) $body['message'] : '';
		return [
			'items' => [],
			'cursor' => '',
			'http_code' => $httpCode,
			'error' => 'Twitch API error (HTTP ' . $httpCode . ')' . ($apiMessage !== '' ? ': ' . $apiMessage : '.'),
		];
	}
	return [
		'items' => isset($body['data']) && is_array($body['data']) ? $body['data'] : [],
		'cursor' => isset($body['pagination']['cursor']) ? (string) $body['pagination']['cursor'] : '',
		'http_code' => $httpCode,
		'error' => '',
	];
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
		$page = fetchTwitchPage($endpoint, $query, $accessToken, $clientID);
		$httpCode = $page['http_code'];
		if ($page['error'] !== '') {
			$apiError = $page['error'];
			break;
		}
		$pageData = $page['items'];
		if (empty($pageData)) {
			break;
		}
		$spaceLeft = $maxItems - count($items);
		if (count($pageData) > $spaceLeft) {
			$pageData = array_slice($pageData, 0, $spaceLeft);
		}
		$items = array_merge($items, $pageData);
		$cursor = $page['cursor'];
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
	foreach (array_chunk($uniqueClipIds, 10) as $chunk) {
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

function countChannelClips($broadcasterId, $accessToken, $clientID, $maxItems = 1000) {
	if ($broadcasterId === '') {
		return [
			'count' => 0,
			'capped' => false,
			'error' => 'No Twitch broadcaster ID found for this account.',
		];
	}
	$count = 0;
	$cursor = '';
	$error = '';
	while ($count < $maxItems) {
		$query = [
			'broadcaster_id' => $broadcasterId,
			'first' => 100,
		];
		if ($cursor !== '') {
			$query['after'] = $cursor;
		}
		$page = fetchTwitchPage('clips', $query, $accessToken, $clientID);
		if ($page['error'] !== '') {
			$error = $page['error'];
			break;
		}
		$pageCount = count($page['items']);
		if ($pageCount === 0) {
			break;
		}
		$spaceLeft = $maxItems - $count;
		$count += min($spaceLeft, $pageCount);
		if ($page['cursor'] === '' || $pageCount === 0) {
			break;
		}
		$cursor = $page['cursor'];
	}
	return [
		'count' => $count,
		'capped' => $count >= $maxItems,
		'error' => $error,
	];
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

function sortClipsByCreatedDate(array &$clips) {
	usort($clips, static function ($left, $right) {
		$leftTime = isset($left['created_at']) ? strtotime((string) $left['created_at']) : false;
		$rightTime = isset($right['created_at']) ? strtotime((string) $right['created_at']) : false;
		$leftTimestamp = $leftTime !== false ? (int) $leftTime : 0;
		$rightTimestamp = $rightTime !== false ? (int) $rightTime : 0;
		return $rightTimestamp <=> $leftTimestamp;
	});
}

function fetchSortedChannelClips($channelUserId, $accessToken, $clientID, $maxItems = 1000) {
	$result = fetchAllTwitchItems('clips', [
		'broadcaster_id' => $channelUserId,
		'first' => 100,
	], $accessToken, $clientID, $maxItems);
	$clips = $result['items'];
	sortClipsByCreatedDate($clips);
	return [
		'items' => $clips,
		'error' => $result['error'],
	];
}

function renderMediaCard(array $video, $isClipsMode, array $clipDownloadUrls = []) {
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
	$clipFeatured = isset($video['is_featured']) ? (bool) $video['is_featured'] : false;
	$clipVodOffset = isset($video['vod_offset']) && $video['vod_offset'] !== null ? (int) $video['vod_offset'] : null;
	$clipDownloads = ($isClipsMode && $videoId !== '' && isset($clipDownloadUrls[$videoId]) && is_array($clipDownloadUrls[$videoId])) ? $clipDownloadUrls[$videoId] : [];
	$clipLandscapeDownload = isset($clipDownloads['landscape_download_url']) ? (string) $clipDownloads['landscape_download_url'] : '';
	$clipPortraitDownload = isset($clipDownloads['portrait_download_url']) ? (string) $clipDownloads['portrait_download_url'] : '';
	ob_start();
	?>
	<div class="media-card-col">
		<div class="sp-card" style="height:100%; display:flex; flex-direction:column;">
			<div class="media-card-thumb">
				<?php if ($thumbnail !== ''): ?>
					<img src="<?php echo htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8'); ?>" alt="Video thumbnail">
				<?php else: ?>
					<div class="media-card-no-thumb">
						<span><i class="fas fa-image mr-2"></i>No Thumbnail</span>
					</div>
				<?php endif; ?>
			</div>
			<div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
				<p class="sp-card-title mb-2" style="min-height:3em; overflow:hidden;">
					<?php echo htmlspecialchars($videoTitle, ENT_QUOTES, 'UTF-8'); ?>
				</p>
				<?php if (!$isClipsMode): ?>
					<p class="sp-text-muted mb-3" style="font-size:0.82rem; min-height:4.5em; overflow:hidden;">
						<?php echo htmlspecialchars($videoDescription !== '' ? $videoDescription : 'No description provided.', ENT_QUOTES, 'UTF-8'); ?>
					</p>
				<?php endif; ?>
				<div class="media-card-tags">
					<span class="sp-badge sp-badge-blue"><?php echo htmlspecialchars($videoType, ENT_QUOTES, 'UTF-8'); ?></span>
					<span class="sp-badge sp-badge-accent"><?php echo htmlspecialchars($videoDuration, ENT_QUOTES, 'UTF-8'); ?></span>
					<?php if ($isClipsMode): ?>
						<span class="sp-badge sp-badge-green"><?php echo $clipFeatured ? 'featured' : 'not featured'; ?></span>
					<?php endif; ?>
				</div>
				<?php if ($isClipsMode): ?>
					<p class="media-card-meta"><strong>Creator:</strong> <?php echo htmlspecialchars($clipCreator !== '' ? $clipCreator : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
					<p class="media-card-meta"><strong>Broadcaster:</strong> <?php echo htmlspecialchars($clipBroadcaster !== '' ? $clipBroadcaster : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
					<p class="media-card-meta"><strong>VOD Offset:</strong> <?php echo $clipVodOffset !== null ? number_format($clipVodOffset) . 's' : 'N/A'; ?></p>
				<?php endif; ?>
				<p class="media-card-meta"><strong>Views:</strong> <?php echo number_format($videoViews); ?></p>
				<p class="media-card-meta" style="margin-bottom:1rem;"><strong><?php echo $isClipsMode ? 'Created' : 'Published'; ?>:</strong> <?php echo htmlspecialchars($videoDate, ENT_QUOTES, 'UTF-8'); ?></p>
				<div class="sp-btn-group" style="margin-top:auto; flex-wrap:wrap;">
					<?php if ($videoUrl !== ''): ?>
						<a class="sp-btn sp-btn-primary sp-btn-sm" href="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
							<i class="fas fa-external-link-alt mr-1"></i>Open
						</a>
					<?php else: ?>
						<button class="sp-btn sp-btn-sm" disabled>No URL</button>
					<?php endif; ?>
					<?php if (!$isClipsMode && $videoId !== ''): ?>
						<button type="button" class="sp-btn sp-btn-danger sp-btn-sm delete-video-btn" data-video-id="<?php echo htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8'); ?>">
							<i class="fas fa-trash mr-1"></i>Delete
						</button>
					<?php endif; ?>
					<?php if ($isClipsMode): ?>
						<?php if ($clipLandscapeDownload !== ''): ?>
							<a class="sp-btn sp-btn-primary sp-btn-sm" href="<?php echo htmlspecialchars($clipLandscapeDownload, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" download>
								<i class="fas fa-download mr-1"></i>Landscape
							</a>
						<?php endif; ?>
						<?php if ($clipPortraitDownload !== ''): ?>
							<a class="sp-btn sp-btn-primary sp-btn-sm" href="<?php echo htmlspecialchars($clipPortraitDownload, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" download>
								<i class="fas fa-download mr-1"></i>Portrait
							</a>
						<?php endif; ?>
						<?php if ($clipLandscapeDownload === '' && $clipPortraitDownload === ''): ?>
							<button class="sp-btn sp-btn-sm" disabled>
								<i class="fas fa-download mr-1"></i>Download unavailable
							</button>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'clips') {
	header('Content-Type: application/json; charset=utf-8');
	if ($channelUserId === '' || $accessToken === '') {
		echo json_encode([
			'success' => false,
			'message' => 'No Twitch channel ID is available for this login session.',
		]);
		exit();
	}
	$ajaxAction = isset($_GET['ajax_action']) ? trim((string) $_GET['ajax_action']) : 'batch';
	if ($ajaxAction === 'count') {
		$countResult = countChannelClips($channelUserId, $accessToken, $clientID, 1000);
		echo json_encode([
			'success' => $countResult['error'] === '',
			'total_count' => $countResult['count'],
			'capped' => $countResult['capped'],
			'message' => $countResult['error'],
		]);
		exit();
	}
	$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
	$loadMode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'more';
	$loadAll = $loadMode === 'all';
	$loadedItems = [];
	$error = '';
	$sortedClipsResult = fetchSortedChannelClips($channelUserId, $accessToken, $clientID, 1000);
	$loadedItems = [];
	$totalClips = 0;
	$nextOffset = $offset;
	$hasMore = false;
	if ($sortedClipsResult['error'] !== '') {
		$error = $sortedClipsResult['error'];
	} else {
		$allClips = $sortedClipsResult['items'];
		$totalClips = count($allClips);
		$limit = $loadAll ? max(0, $totalClips - $offset) : 20;
		$loadedItems = array_slice($allClips, $offset, $limit);
		$nextOffset = $offset + count($loadedItems);
		$hasMore = $nextOffset < $totalClips;
	}
	$clipIds = [];
	foreach ($loadedItems as $clip) {
		if (isset($clip['id']) && (string) $clip['id'] !== '') {
			$clipIds[] = (string) $clip['id'];
		}
	}
	$downloadLookup = fetchClipDownloadUrls($clipIds, $channelUserId, $editorUserId, $accessToken, $clientID);
	if ($downloadLookup['error'] !== '' && $error === '') {
		$error = $downloadLookup['error'];
	}
	sortClipsByCreatedDate($loadedItems);
	$html = '';
	foreach ($loadedItems as $clip) {
		$html .= renderMediaCard($clip, true, $downloadLookup['downloads']);
	}
	echo json_encode([
		'success' => $error === '',
		'html' => $html,
		'next_offset' => $nextOffset,
		'has_more' => $hasMore,
		'total_count' => $totalClips,
		'loaded_count' => count($loadedItems),
		'message' => $error,
	]);
	exit();
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
		} elseif ((int) $deleteResult['http_code'] === 200) {
			$deletedItems = isset($deleteBody['data']) && is_array($deleteBody['data']) ? $deleteBody['data'] : [];
			if (in_array($deleteVideoId, $deletedItems, true) || empty($deletedItems)) {
				$flashSuccess = 'Video deleted from Twitch.';
			} else {
				$flashSuccess = 'Delete request completed.';
			}
		} else {
			$apiMessage = is_array($deleteBody) && isset($deleteBody['message']) ? (string) $deleteBody['message'] : '';
			$flashError = 'Delete failed (HTTP ' . (int) $deleteResult['http_code'] . ').' . ($apiMessage !== '' ? ' ' . $apiMessage : '');
		}
	}
}

$videos = [];
$apiError = '';
$isClipsMode = $tab === 'clips';
$clipsNextOffset = 0;
$clipsHasMore = false;
$clipDownloadUrls = [];

$videoTypeMap = [
	'videos'     => 'archive',
	'highlights' => 'highlight',
	'uploads'    => 'upload',
];

if ($channelUserId === '') {
	$flashError = 'No Twitch channel ID is available for this login session.';
} else {
	if ($isClipsMode) {
		$sortedClipsResult = fetchSortedChannelClips($channelUserId, $accessToken, $clientID, 1000);
		if ($sortedClipsResult['error'] !== '') {
			$apiError = $sortedClipsResult['error'];
		} else {
			$allClips = $sortedClipsResult['items'];
			$videos = array_slice($allClips, 0, 20);
			$clipsNextOffset = count($videos);
			$clipsHasMore = $clipsNextOffset < count($allClips);
			$clipIds = [];
			foreach ($videos as $clip) {
				if (isset($clip['id']) && (string) $clip['id'] !== '') {
					$clipIds[] = (string) $clip['id'];
				}
			}
			$downloadLookup = fetchClipDownloadUrls($clipIds, $channelUserId, $editorUserId, $accessToken, $clientID);
			$clipDownloadUrls = $downloadLookup['downloads'];
			if ($downloadLookup['error'] !== '' && $flashError === '') {
				$flashError = $downloadLookup['error'];
			}
		}
	} else {
		$videoTypeFilter = $videoTypeMap[$tab] ?? 'archive';
		$allVideos = fetchAllTwitchItems('videos', [
			'user_id' => $channelUserId,
			'first'   => 100,
			'type'    => $videoTypeFilter,
		], $accessToken, $clientID, 1000);
		$videos = $allVideos['items'];
		$apiError = $allVideos['error'];
	}
}

$videosTabLink     = 'videos.php?tab=videos';
$highlightsTabLink = 'videos.php?tab=highlights';
$uploadsTabLink    = 'videos.php?tab=uploads';
$clipsTabLink      = 'videos.php?tab=clips';

ob_start();
?>
<div class="sp-card mb-5">
	<div class="sp-card-header">
		<span class="sp-card-title">
			<span class="icon mr-2"><i class="fas fa-photo-video"></i></span>
			<?php
			echo match($tab) {
				'highlights' => 'Highlighted Videos',
				'uploads'    => 'Uploaded Videos',
				'clips'      => 'Clips',
				default      => 'Archive VODs',
			};
			?>
		</span>
	</div>
	<div class="sp-card-body">
		<ul class="sp-tabs-nav mb-4">
			<li class="<?php echo $tab === 'videos' ? 'is-active' : ''; ?>">
				<a href="<?php echo htmlspecialchars($videosTabLink, ENT_QUOTES, 'UTF-8'); ?>">
					<span class="icon is-small"><i class="fas fa-photo-video"></i></span>
					<span>Archive VODs</span>
				</a>
			</li>
			<li class="<?php echo $tab === 'highlights' ? 'is-active' : ''; ?>">
				<a href="<?php echo htmlspecialchars($highlightsTabLink, ENT_QUOTES, 'UTF-8'); ?>">
					<span class="icon is-small"><i class="fas fa-star"></i></span>
					<span>Highlights</span>
				</a>
			</li>
			<li class="<?php echo $tab === 'uploads' ? 'is-active' : ''; ?>">
				<a href="<?php echo htmlspecialchars($uploadsTabLink, ENT_QUOTES, 'UTF-8'); ?>">
					<span class="icon is-small"><i class="fas fa-upload"></i></span>
					<span>Uploads</span>
				</a>
			</li>
			<li class="<?php echo $tab === 'clips' ? 'is-active' : ''; ?>">
				<a href="<?php echo htmlspecialchars($clipsTabLink, ENT_QUOTES, 'UTF-8'); ?>">
					<span class="icon is-small"><i class="fas fa-film"></i></span>
					<span>Clips</span>
				</a>
			</li>
		</ul>
		<?php if ($flashSuccess !== ''): ?>
			<div class="sp-alert sp-alert-success"><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
		<?php endif; ?>
		<?php if ($flashError !== ''): ?>
			<div class="sp-alert sp-alert-danger"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div>
		<?php endif; ?>
		<?php if ($apiError !== ''): ?>
			<div class="sp-alert sp-alert-danger"><?php echo htmlspecialchars($apiError, ENT_QUOTES, 'UTF-8'); ?></div>
		<?php endif; ?>
		<?php if (empty($videos) && $apiError === '' && $flashError === '' && $channelUserId !== ''): ?>
			<?php
			$emptyLabel = match($tab) {
				'clips'      => 'clips',
				'highlights' => 'highlights',
				'uploads'    => 'uploaded videos',
				default      => 'archive VODs',
			};
			?>
			<div class="sp-alert sp-alert-info">No <?php echo $emptyLabel; ?> found for this channel.</div>
		<?php endif; ?>
		<?php if ($isClipsMode): ?>
			<div class="sp-card mb-4">
				<div class="sp-card-body" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
					<p class="sp-text-muted" id="clipsLoadStatus">Loaded <?php echo count($videos); ?> clips.</p>
					<div class="sp-btn-group" style="margin-bottom:0;">
						<button id="clipsLoadMoreBtn" class="sp-btn sp-btn-primary" <?php echo !$clipsHasMore ? 'disabled' : ''; ?>>Load 20 More</button>
						<button id="clipsLoadAllBtn" class="sp-btn sp-btn-warning" <?php echo !$clipsHasMore ? 'disabled' : ''; ?>>Load All</button>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<div class="media-cards-grid" id="mediaCardsContainer">
			<?php foreach ($videos as $video): ?>
				<?php echo renderMediaCard($video, $isClipsMode, $clipDownloadUrls); ?>
			<?php endforeach; ?>
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
	const isClipsMode = <?php echo $isClipsMode ? 'true' : 'false'; ?>;
	if (!isClipsMode) {
		return;
	}
	const cardsContainer = document.getElementById('mediaCardsContainer');
	const loadMoreBtn = document.getElementById('clipsLoadMoreBtn');
	const loadAllBtn = document.getElementById('clipsLoadAllBtn');
	const statusLabel = document.getElementById('clipsLoadStatus');
	let nextOffset = <?php echo (int) $clipsNextOffset; ?>;
	let hasMore = <?php echo $clipsHasMore ? 'true' : 'false'; ?>;
	let loadedCount = <?php echo (int) count($videos); ?>;
	let totalCount = null;
	let totalCapped = false;
	let loading = false;
	function updateButtons() {
		if (loadMoreBtn) {
			loadMoreBtn.disabled = loading || !hasMore;
		}
		if (loadAllBtn) {
			loadAllBtn.disabled = loading || !hasMore;
			if (totalCount !== null) {
				const remaining = Math.max(totalCount - loadedCount, 0);
				loadAllBtn.textContent = remaining > 0 ? `Load All (${remaining} more)` : 'Load All';
			} else {
				loadAllBtn.textContent = 'Load All';
			}
		}
		if (statusLabel) {
			if (totalCount !== null) {
				statusLabel.textContent = totalCapped
					? `Loaded ${loadedCount} clips (count capped at ${totalCount}).`
					: `Loaded ${loadedCount} of ${totalCount} clips.`;
			} else {
				statusLabel.textContent = `Loaded ${loadedCount} clips.`;
			}
		}
	}
	function fetchClipCount() {
		const url = new URL('videos.php', window.location.origin);
		url.searchParams.set('tab', 'clips');
		url.searchParams.set('ajax', 'clips');
		url.searchParams.set('ajax_action', 'count');
		return fetch(url.toString(), {
			method: 'GET',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		})
			.then(response => response.json())
			.then(data => {
				if (data && data.success) {
					totalCount = Number(data.total_count || 0);
					totalCapped = !!data.capped;
				}
				updateButtons();
			});
	}
	function fetchClipsBatch(mode) {
		if (loading || !hasMore) {
			return Promise.resolve();
		}
		loading = true;
		updateButtons();
		const url = new URL('videos.php', window.location.origin);
		url.searchParams.set('tab', 'clips');
		url.searchParams.set('ajax', 'clips');
		url.searchParams.set('ajax_action', 'batch');
		url.searchParams.set('offset', String(nextOffset));
		url.searchParams.set('mode', mode);
		return fetch(url.toString(), {
			method: 'GET',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		})
			.then(response => response.json())
			.then(data => {
				if (!data || !data.success) {
					throw new Error(data && data.message ? data.message : 'Unable to load more clips.');
				}
				if (data.html && cardsContainer) {
					cardsContainer.insertAdjacentHTML('beforeend', data.html);
				}
				loadedCount += Number(data.loaded_count || 0);
				nextOffset = Number(data.next_offset || nextOffset);
				hasMore = !!data.has_more;
				if (typeof data.total_count !== 'undefined') {
					totalCount = Number(data.total_count || totalCount || 0);
				}
				if (totalCount !== null && loadedCount > totalCount) {
					totalCount = loadedCount;
				}
			})
			.catch(error => {
				Swal.fire({
					title: 'Unable to load clips',
					text: error.message || 'Please try again.',
					icon: 'error',
					background: '#333',
					color: '#fff'
				});
			})
			.finally(() => {
				loading = false;
				updateButtons();
			});
	}
	if (loadMoreBtn) {
		loadMoreBtn.addEventListener('click', function () {
			fetchClipsBatch('more');
		});
	}
	if (loadAllBtn) {
		loadAllBtn.addEventListener('click', function () {
			const knownTotal = totalCount !== null ? totalCount : loadedCount;
			const remaining = Math.max(knownTotal - loadedCount, 0);
			Swal.fire({
				title: 'Load all clips?',
				text: remaining > 0
					? `You have ${knownTotal} clips in total (${remaining} remaining to load). This can take a long time. Continue?`
					: `You currently have ${knownTotal} loaded clips. Loading all remaining clips can take a long time. Continue?`,
				icon: 'warning',
				showCancelButton: true,
				confirmButtonText: 'Yes, load all',
				cancelButtonText: 'Cancel',
				confirmButtonColor: '#d33',
				cancelButtonColor: '#3085d6',
				background: '#333',
				color: '#fff'
			}).then(result => {
				if (result.isConfirmed) {
					fetchClipsBatch('all');
				}
			});
		});
	}
	fetchClipCount();
	updateButtons();
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>