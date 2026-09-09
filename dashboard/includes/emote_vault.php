<?php
/**
 * Emote Vault — fetch channel emotes from Twitch Helix, 7TV, BetterTTV, and
 * FrankerFaceZ, and proxy full-size downloads from those CDNs only.
 */

const EMOTE_VAULT_UA = 'BotOfTheSpecter-EmoteVault/1.0 (https://botofthespecter.com)';
const EMOTE_VAULT_MAX_BYTES = 10485760;
const EMOTE_VAULT_ZIP_MAX_ITEMS = 400;
const EMOTE_VAULT_ZIP_MAX_BYTES = 67108864;
const EMOTE_VAULT_CDN_HOSTS = [
    'static-cdn.jtvnw.net',
    'cdn.7tv.app',
    'cdn.betterttv.net',
    'emotes.betterttv.net',
    'cdn.frankerfacez.com',
];

function emote_vault_bearer_token(string $token): string
{
    $token = trim($token);
    if (stripos($token, 'oauth:') === 0) {
        $token = substr($token, 6);
    }
    return trim($token);
}

function emote_vault_http_get_many(array $requests): array
{
    $results = [];
    if ($requests === []) {
        return $results;
    }
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $key => $req) {
        $url = (string) ($req['url'] ?? '');
        if ($url === '') {
            $results[$key] = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'missing url'];
            continue;
        }
        $headers = $req['headers'] ?? [];
        $headers[] = 'User-Agent: ' . EMOTE_VAULT_UA;
        $headers[] = 'Accept: application/json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => (int) ($req['timeout'] ?? 12),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do {
        $mrc = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running > 0 && $mrc === CURLM_OK);
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$key] = [
            'ok' => ($errno === 0 && $status >= 200 && $status < 300),
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $errno ? curl_error($ch) : null,
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}

function emote_vault_http_get_binary(string $url, int $maxBytes = EMOTE_VAULT_MAX_BYTES): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . EMOTE_VAULT_UA,
            'Accept: image/*,*/*',
        ],
        CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = $errno ? curl_error($ch) : null;
    curl_close($ch);
    if ($errno !== 0 || $status < 200 || $status >= 300 || !is_string($body) || $body === '') {
        return ['ok' => false, 'status' => $status, 'body' => '', 'content_type' => '', 'error' => $error ?: ('HTTP ' . $status)];
    }
    if (strlen($body) > $maxBytes) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'content_type' => '', 'error' => 'too large'];
    }
    if (!emote_vault_is_image_type($contentType)) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'content_type' => '', 'error' => 'not an image'];
    }
    return [
        'ok' => true,
        'status' => $status,
        'body' => $body,
        'content_type' => $contentType,
        'error' => null,
    ];
}

function emote_vault_is_image_type(string $contentType): bool
{
    $contentType = strtolower(trim(explode(';', $contentType)[0]));
    return strpos($contentType, 'image/') === 0 || $contentType === 'application/octet-stream';
}

function emote_vault_cdn_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if ($scheme !== 'https' || $host === '') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }
    if (strpos($path, '..') !== false) {
        return false;
    }
    return in_array($host, EMOTE_VAULT_CDN_HOSTS, true);
}

function emote_vault_absolute_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    return $url;
}

function emote_vault_safe_basename(string $name): string
{
    $name = preg_replace('/[^\w\-\.]+/u', '_', $name) ?? '';
    $name = trim($name, '._-');
    if ($name === '') {
        $name = 'emote';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 80);
    }
    return substr($name, 0, 80);
}

function emote_vault_ext_from_type(string $contentType, string $fallback = 'png'): string
{
    $contentType = strtolower(trim(explode(';', $contentType)[0]));
    $map = [
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
    ];
    return $map[$contentType] ?? $fallback;
}

function emote_vault_ext_from_format(string $format, bool $animated): string
{
    $format = strtolower($format);
    if ($format === 'gif' || $format === 'png' || $format === 'webp' || $format === 'avif' || $format === 'jpg' || $format === 'jpeg') {
        return $format === 'jpeg' ? 'jpg' : $format;
    }
    return $animated ? 'gif' : 'png';
}

function emote_vault_item(
    string $source,
    string $id,
    string $name,
    string $previewUrl,
    string $downloadUrl,
    bool $animated,
    string $kind,
    string $ext,
    int $width = 0,
    int $height = 0,
    string $tier = ''
): array {
    return [
        'id' => $source . ':' . $id,
        'source' => $source,
        'source_id' => (string) $id,
        'name' => $name,
        'preview_url' => $previewUrl,
        'download_url' => $downloadUrl,
        'animated' => $animated,
        'kind' => $kind,
        'tier' => $tier,
        'ext' => $ext,
        'width' => $width,
        'height' => $height,
    ];
}

function emote_vault_twitch_url(string $template, string $id, string $format, string $theme, string $scale): string
{
    if ($template === '') {
        $template = 'https://static-cdn.jtvnw.net/emoticons/v2/{{id}}/{{format}}/{{theme_mode}}/{{scale}}';
    }
    return strtr($template, [
        '{{id}}' => rawurlencode($id),
        '{{format}}' => $format,
        '{{theme_mode}}' => $theme,
        '{{scale}}' => $scale,
    ]);
}

function emote_vault_parse_twitch(string $body): array
{
    $emotes = [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['data']) || !is_array($decoded['data'])) {
        return $emotes;
    }
    $template = (string) ($decoded['template'] ?? '');
    foreach ($decoded['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $name = (string) ($row['name'] ?? '');
        if ($id === '' || $name === '') {
            continue;
        }
        $formats = $row['format'] ?? [];
        if (!is_array($formats)) {
            $formats = [];
        }
        $animated = in_array('animated', $formats, true);
        $format = $animated ? 'animated' : 'static';
        $preview = emote_vault_twitch_url($template, $id, $format, 'dark', '2.0');
        $download = emote_vault_twitch_url($template, $id, $format, 'dark', '3.0');
        if ($download === '' && !empty($row['images']['url_4x'])) {
            $download = (string) $row['images']['url_4x'];
            $preview = (string) ($row['images']['url_2x'] ?? $download);
        }
        $kind = (string) ($row['emote_type'] ?? 'channel');
        $tier = (string) ($row['tier'] ?? '');
        $emotes[] = emote_vault_item(
            'twitch',
            $id,
            $name,
            $preview,
            $download,
            $animated,
            $kind,
            $animated ? 'gif' : 'png',
            112,
            112,
            $tier
        );
    }
    return $emotes;
}

function emote_vault_pick_7tv_file(array $files, bool $preview, bool $animated): ?array
{
    if ($files === []) {
        return null;
    }
    $scored = [];
    foreach ($files as $file) {
        if (!is_array($file) || empty($file['name'])) {
            continue;
        }
        $width = (int) ($file['width'] ?? 0);
        $format = strtoupper((string) ($file['format'] ?? ''));
        $name = (string) $file['name'];
        if (strpos($name, '_static') !== false) {
            continue;
        }
        $scored[] = [
            'file' => $file,
            'width' => $width,
            'format' => $format,
            'name' => $name,
        ];
    }
    if ($scored === []) {
        return null;
    }
    $formatRank = $animated
        ? ['GIF' => 0, 'WEBP' => 1, 'AVIF' => 2, 'PNG' => 3]
        : ['PNG' => 0, 'WEBP' => 1, 'AVIF' => 2, 'GIF' => 3];
    if ($preview) {
        usort($scored, static function ($a, $b) use ($formatRank) {
            $aDist = abs($a['width'] - 64);
            $bDist = abs($b['width'] - 64);
            if ($aDist !== $bDist) {
                return $aDist <=> $bDist;
            }
            $ar = $formatRank[$a['format']] ?? 9;
            $br = $formatRank[$b['format']] ?? 9;
            return $ar <=> $br;
        });
        return $scored[0]['file'];
    }
    $maxW = 0;
    foreach ($scored as $row) {
        if ($row['width'] > $maxW) {
            $maxW = $row['width'];
        }
    }
    $atMax = array_values(array_filter($scored, static function ($row) use ($maxW) {
        return $row['width'] === $maxW;
    }));
    usort($atMax, static function ($a, $b) use ($formatRank) {
        $ar = $formatRank[$a['format']] ?? 9;
        $br = $formatRank[$b['format']] ?? 9;
        return $ar <=> $br;
    });
    return $atMax[0]['file'] ?? null;
}

function emote_vault_parse_7tv(string $body): array
{
    $emotes = [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $emotes;
    }
    $setEmotes = $decoded['emote_set']['emotes'] ?? [];
    if (!is_array($setEmotes)) {
        return $emotes;
    }
    foreach ($setEmotes as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        $id = (string) ($data['id'] ?? $row['id'] ?? '');
        $name = (string) ($row['name'] ?? $data['name'] ?? '');
        if ($id === '' || $name === '') {
            continue;
        }
        $host = is_array($data['host'] ?? null) ? $data['host'] : [];
        $base = emote_vault_absolute_url((string) ($host['url'] ?? ''));
        if ($base === '') {
            $base = 'https://cdn.7tv.app/emote/' . rawurlencode($id);
        }
        $files = is_array($host['files'] ?? null) ? $host['files'] : [];
        $animated = !empty($data['animated']);
        $previewFile = emote_vault_pick_7tv_file($files, true, $animated);
        $downloadFile = emote_vault_pick_7tv_file($files, false, $animated);
        $previewName = $previewFile['name'] ?? ($animated ? '2x.webp' : '2x.webp');
        $downloadName = $downloadFile['name'] ?? ($animated ? '4x.gif' : '4x.webp');
        $preview = rtrim($base, '/') . '/' . ltrim((string) $previewName, '/');
        $download = rtrim($base, '/') . '/' . ltrim((string) $downloadName, '/');
        $ext = emote_vault_ext_from_format((string) ($downloadFile['format'] ?? ''), $animated);
        $emotes[] = emote_vault_item(
            '7tv',
            $id,
            $name,
            $preview,
            $download,
            $animated,
            'channel',
            $ext,
            (int) ($downloadFile['width'] ?? 0),
            (int) ($downloadFile['height'] ?? 0)
        );
    }
    return $emotes;
}

function emote_vault_parse_bttv(string $body): array
{
    $emotes = [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $emotes;
    }
    $groups = [
        'channel' => is_array($decoded['channelEmotes'] ?? null) ? $decoded['channelEmotes'] : [],
        'shared' => is_array($decoded['sharedEmotes'] ?? null) ? $decoded['sharedEmotes'] : [],
    ];
    foreach ($groups as $kind => $rows) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $name = (string) ($row['code'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $imageType = strtolower((string) ($row['imageType'] ?? 'png'));
            $animated = !empty($row['animated']) || $imageType === 'gif';
            $preview = 'https://cdn.betterttv.net/emote/' . rawurlencode($id) . '/2x';
            $download = 'https://cdn.betterttv.net/emote/' . rawurlencode($id) . '/3x';
            $emotes[] = emote_vault_item(
                'bttv',
                $id,
                $name,
                $preview,
                $download,
                $animated,
                $kind,
                $imageType === 'gif' || $imageType === 'webp' || $imageType === 'png' ? $imageType : ($animated ? 'gif' : 'png'),
                112,
                112
            );
        }
    }
    return $emotes;
}

function emote_vault_ffz_best_url(array $urls): array
{
    $best = '';
    $bestScale = 0;
    foreach ($urls as $scale => $url) {
        if (!is_string($url) || $url === '') {
            continue;
        }
        $n = (int) $scale;
        if ($n >= $bestScale) {
            $bestScale = $n;
            $best = $url;
        }
    }
    return ['url' => emote_vault_absolute_url($best), 'scale' => $bestScale];
}

function emote_vault_parse_ffz(string $body): array
{
    $emotes = [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['sets']) || !is_array($decoded['sets'])) {
        return $emotes;
    }
    foreach ($decoded['sets'] as $set) {
        if (!is_array($set) || empty($set['emoticons']) || !is_array($set['emoticons'])) {
            continue;
        }
        foreach ($set['emoticons'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $name = (string) ($row['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $animatedMap = is_array($row['animated'] ?? null) ? $row['animated'] : [];
            $staticMap = is_array($row['urls'] ?? null) ? $row['urls'] : [];
            $animated = $animatedMap !== [];
            $picked = emote_vault_ffz_best_url($animated ? $animatedMap : $staticMap);
            $previewSource = $animated ? $animatedMap : $staticMap;
            $preview = '';
            if (isset($previewSource['2']) && is_string($previewSource['2'])) {
                $preview = emote_vault_absolute_url($previewSource['2']);
            } elseif (isset($previewSource[2]) && is_string($previewSource[2])) {
                $preview = emote_vault_absolute_url($previewSource[2]);
            } else {
                $preview = $picked['url'];
            }
            $download = $picked['url'];
            if ($download === '') {
                continue;
            }
            $width = (int) ($row['width'] ?? 0);
            $height = (int) ($row['height'] ?? 0);
            if ($picked['scale'] > 1) {
                $width *= $picked['scale'];
                $height *= $picked['scale'];
            }
            $emotes[] = emote_vault_item(
                'ffz',
                $id,
                $name,
                $preview !== '' ? $preview : $download,
                $download,
                $animated,
                'channel',
                $animated ? 'gif' : 'png',
                $width,
                $height
            );
        }
    }
    return $emotes;
}

function emote_vault_collect(string $broadcasterId, string $clientId, string $helixToken, string $login = ''): array
{
    $errors = [];
    $emotes = [];
    $broadcasterId = trim($broadcasterId);
    $login = strtolower(trim($login));
    if ($broadcasterId === '') {
        return ['emotes' => [], 'errors' => ['twitch' => 'missing broadcaster']];
    }

    $helixToken = emote_vault_bearer_token($helixToken);
    $clientId = trim($clientId);

    $requests = [
        'twitch' => [
            'url' => 'https://api.twitch.tv/helix/chat/emotes?broadcaster_id=' . rawurlencode($broadcasterId),
            'headers' => ($clientId !== '' && $helixToken !== '')
                ? ['Client-ID: ' . $clientId, 'Authorization: Bearer ' . $helixToken]
                : [],
            'timeout' => 12,
        ],
        '7tv' => [
            'url' => 'https://7tv.io/v3/users/twitch/' . rawurlencode($broadcasterId),
            'timeout' => 12,
        ],
        'bttv' => [
            'url' => 'https://api.betterttv.net/3/cached/users/twitch/' . rawurlencode($broadcasterId),
            'timeout' => 12,
        ],
        'ffz' => [
            'url' => 'https://api.frankerfacez.com/v1/room/id/' . rawurlencode($broadcasterId),
            'timeout' => 12,
        ],
    ];
    if ($clientId === '' || $helixToken === '') {
        unset($requests['twitch']);
        $errors['twitch'] = 'missing twitch credentials';
    }

    $results = emote_vault_http_get_many($requests);

    if (isset($results['ffz']) && (int) $results['ffz']['status'] === 404 && $login !== '') {
        $fallback = emote_vault_http_get_many([
            'ffz' => [
                'url' => 'https://api.frankerfacez.com/v1/room/' . rawurlencode($login),
                'timeout' => 12,
            ],
        ]);
        if (isset($fallback['ffz'])) {
            $results['ffz'] = $fallback['ffz'];
        }
    }

    $parsers = [
        'twitch' => 'emote_vault_parse_twitch',
        '7tv' => 'emote_vault_parse_7tv',
        'bttv' => 'emote_vault_parse_bttv',
        'ffz' => 'emote_vault_parse_ffz',
    ];
    foreach ($parsers as $source => $parser) {
        if (!isset($results[$source])) {
            continue;
        }
        $row = $results[$source];
        $status = (int) $row['status'];
        if ($status === 404) {
            continue;
        }
        if (empty($row['ok'])) {
            $errors[$source] = $row['error'] ?: ('HTTP ' . $status);
            continue;
        }
        $parsed = $parser((string) $row['body']);
        foreach ($parsed as $item) {
            if (!emote_vault_cdn_url_allowed((string) ($item['download_url'] ?? ''))) {
                continue;
            }
            $emotes[] = $item;
        }
    }

    return ['emotes' => $emotes, 'errors' => $errors];
}

function emote_vault_content_disposition(string $filename): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'emote';
    if ($ascii === '' || $ascii === '.') {
        $ascii = 'emote';
    }
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

function emote_vault_stream_download(string $url, string $name, string $fallbackExt): bool
{
    $url = emote_vault_absolute_url($url);
    if (!emote_vault_cdn_url_allowed($url)) {
        return false;
    }
    $fetched = emote_vault_http_get_binary($url);
    if (empty($fetched['ok'])) {
        return false;
    }
    $ext = emote_vault_ext_from_type((string) $fetched['content_type'], $fallbackExt !== '' ? $fallbackExt : 'png');
    $base = emote_vault_safe_basename($name);
    $filename = $base . '.' . $ext;
    $contentType = trim(explode(';', (string) $fetched['content_type'])[0]);
    if ($contentType === '') {
        $contentType = 'application/octet-stream';
    }
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . emote_vault_content_disposition($filename));
    header('Content-Length: ' . strlen($fetched['body']));
    header('Cache-Control: private, max-age=3600');
    echo $fetched['body'];
    return true;
}

function emote_vault_http_get_binary_many(array $urls, int $maxBytes = EMOTE_VAULT_MAX_BYTES): array
{
    $results = [];
    if ($urls === []) {
        return $results;
    }
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . EMOTE_VAULT_UA,
                'Accept: image/*,*/*',
            ],
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do {
        $mrc = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running > 0 && $mrc === CURLM_OK);
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $ok = ($errno === 0 && $status >= 200 && $status < 300 && is_string($body) && $body !== '' && strlen($body) <= $maxBytes && emote_vault_is_image_type($contentType));
        $results[$key] = [
            'ok' => $ok,
            'status' => $status,
            'body' => $ok ? $body : '',
            'content_type' => $contentType,
            'error' => $errno ? curl_error($ch) : null,
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}

function emote_vault_build_zip(array $items): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    if (count($items) > EMOTE_VAULT_ZIP_MAX_ITEMS) {
        return null;
    }
    $prepared = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $url = emote_vault_absolute_url((string) ($item['url'] ?? ''));
        if (!emote_vault_cdn_url_allowed($url)) {
            continue;
        }
        $prepared[] = [
            'url' => $url,
            'name' => (string) ($item['name'] ?? 'emote'),
            'ext' => (string) ($item['ext'] ?? 'png'),
        ];
    }
    if ($prepared === []) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'emotevault');
    if ($tmp === false) {
        return null;
    }
    $zipPath = $tmp . '.zip';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    $used = [];
    $added = 0;
    $totalBytes = 0;
    foreach (array_chunk($prepared, 8, true) as $batch) {
        $urls = [];
        foreach ($batch as $idx => $row) {
            $urls[$idx] = $row['url'];
        }
        $fetchedBatch = emote_vault_http_get_binary_many($urls);
        foreach ($batch as $idx => $row) {
            $fetched = $fetchedBatch[$idx] ?? null;
            if (!is_array($fetched) || empty($fetched['ok'])) {
                continue;
            }
            $size = strlen($fetched['body']);
            if ($totalBytes + $size > EMOTE_VAULT_ZIP_MAX_BYTES) {
                continue;
            }
            $ext = emote_vault_ext_from_type((string) $fetched['content_type'], $row['ext'] !== '' ? $row['ext'] : 'png');
            $base = emote_vault_safe_basename($row['name']);
            $entry = $base . '.' . $ext;
            $n = 2;
            while (isset($used[$entry])) {
                $entry = $base . '-' . $n . '.' . $ext;
                $n++;
            }
            $used[$entry] = true;
            $zip->addFromString($entry, $fetched['body']);
            $totalBytes += $size;
            $added++;
        }
    }
    $zip->close();
    if ($added === 0 || !is_file($zipPath)) {
        @unlink($zipPath);
        return null;
    }
    return $zipPath;
}
