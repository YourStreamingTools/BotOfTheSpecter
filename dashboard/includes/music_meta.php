<?php
// Artist/title metadata for VOD (DMCA-free) music tracks.
// Shared by dashboard/music.php and overlay/music.php.
//
// Artist precedence: "Artist - Title.mp3" filename convention first (so a rename can
// set or correct it), then the MP3's ID3 artist tag, otherwise blank. The title always
// comes from the filename so renaming a track keeps changing its displayed title.
// ID3 reads are cached per directory keyed on filename + size + mtime.

const MUSIC_META_CACHE_DIR = '/var/www/cache/music_meta';

// Split "Artist - Title" (spaces or underscores around the hyphen) into its parts.
function music_meta_split_filename($base) {
    if (preg_match('/^(.+?)[\s_]+-[\s_]+(.+)$/u', $base, $m)) {
        $artist = trim(str_replace('_', ' ', $m[1]));
        if ($artist !== '') {
            return ['artist' => $artist, 'title' => trim($m[2])];
        }
    }
    return ['artist' => '', 'title' => $base];
}

function music_meta_to_utf8($bytes, $from) {
    if ($bytes === '') return '';
    $out = @iconv($from, 'UTF-8//IGNORE', $bytes);
    if ($out === false && function_exists('mb_convert_encoding')) {
        $out = @mb_convert_encoding($bytes, 'UTF-8', $from);
    }
    return $out === false ? '' : $out;
}

// Decode an ID3v2 text frame body (leading encoding byte + text).
function music_meta_decode_text_frame($data) {
    if ($data === '') return '';
    $enc = ord($data[0]);
    $raw = substr($data, 1);
    switch ($enc) {
        case 1: // UTF-16 with BOM
            $text = music_meta_to_utf8($raw, 'UTF-16');
            break;
        case 2: // UTF-16BE without BOM
            $text = music_meta_to_utf8($raw, 'UTF-16BE');
            break;
        case 3: // UTF-8
            $text = $raw;
            break;
        default: // ISO-8859-1
            $text = music_meta_to_utf8($raw, 'ISO-8859-1');
    }
    // ID3v2.4 separates multiple values with NULs; join them for display.
    $parts = array_filter(array_map('trim', explode("\0", $text)), fn($p) => $p !== '');
    return implode(', ', $parts);
}

function music_meta_syncsafe($bytes) {
    $b = array_values(unpack('C4', $bytes));
    return ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
}

// Walk the ID3v2 frames at the start of the file looking for the artist frame.
// Frames are skipped with fseek so embedded cover art is never downloaded.
function music_meta_read_id3v2_artist($fh) {
    $header = fread($fh, 10);
    if ($header === false || strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') return '';
    $major = ord($header[3]);
    $flags = ord($header[5]);
    if ($major < 2 || $major > 4) return '';
    // Whole-tag unsynchronisation (v2.2/2.3) would need the tag de-unsynced first; rare, so fall back to ID3v1.
    if ($major < 4 && ($flags & 0x80)) return '';
    $tagEnd = 10 + music_meta_syncsafe(substr($header, 6, 4));
    if ($major >= 3 && ($flags & 0x40)) {
        $ext = fread($fh, 4);
        if ($ext === false || strlen($ext) < 4) return '';
        $extSize = $major === 4 ? music_meta_syncsafe($ext) : unpack('N', $ext)[1] + 4;
        fseek($fh, 10 + $extSize);
    }
    $artistId = $major === 2 ? 'TP1' : 'TPE1';
    $headerLen = $major === 2 ? 6 : 10;
    while (ftell($fh) + $headerLen <= $tagEnd) {
        $frameHeader = fread($fh, $headerLen);
        if ($frameHeader === false || strlen($frameHeader) < $headerLen) break;
        if ($major === 2) {
            $id = substr($frameHeader, 0, 3);
            $b = unpack('C3', substr($frameHeader, 3, 3));
            $size = ($b[1] << 16) | ($b[2] << 8) | $b[3];
            $frameFlags = 0;
        } else {
            $id = substr($frameHeader, 0, 4);
            $sizeBytes = substr($frameHeader, 4, 4);
            $size = $major === 4 ? music_meta_syncsafe($sizeBytes) : unpack('N', $sizeBytes)[1];
            $frameFlags = unpack('n', substr($frameHeader, 8, 2))[1];
        }
        // Padding or garbage means there are no more frames.
        if (!preg_match('/^[A-Z0-9]+$/', $id) || $size < 0) break;
        if ($size === 0) continue;
        if (ftell($fh) + $size > $tagEnd) break;
        if ($id !== $artistId) {
            fseek($fh, $size, SEEK_CUR);
            continue;
        }
        if ($size > 65536) return '';
        $data = fread($fh, $size);
        if ($data === false || strlen($data) < $size) return '';
        if ($major === 4) {
            // Compressed/encrypted frames are not worth decoding for a display string.
            if ($frameFlags & 0x000C) return '';
            if ($frameFlags & 0x0040) $data = substr($data, 1); // group identifier
            if ($frameFlags & 0x0001) $data = substr($data, 4); // data length indicator
            if ($frameFlags & 0x0002) $data = str_replace("\xFF\x00", "\xFF", $data); // frame unsync
        } elseif ($major === 3) {
            if ($frameFlags & 0x00C0) return '';
            if ($frameFlags & 0x0020) $data = substr($data, 1); // group identifier
        }
        return music_meta_decode_text_frame($data);
    }
    return '';
}

function music_meta_read_id3v1_artist($fh, $size) {
    if ($size < 128) return '';
    if (fseek($fh, -128, SEEK_END) !== 0) return '';
    $tag = fread($fh, 128);
    if ($tag === false || strlen($tag) < 128 || substr($tag, 0, 3) !== 'TAG') return '';
    return trim(music_meta_to_utf8(rtrim(substr($tag, 33, 30), "\0 "), 'ISO-8859-1'));
}

function music_meta_read_id3_artist($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $artist = '';
    try {
        $artist = music_meta_read_id3v2_artist($fh);
        if ($artist === '') {
            $stat = fstat($fh);
            $artist = music_meta_read_id3v1_artist($fh, $stat ? $stat['size'] : 0);
        }
    } finally {
        fclose($fh);
    }
    // Strip control characters left by malformed tags.
    return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $artist) ?? '');
}

function music_meta_cache_path($dir) {
    return MUSIC_META_CACHE_DIR . '/' . sha1($dir) . '.json';
}

function music_meta_load_cache($dir) {
    $path = music_meta_cache_path($dir);
    if (!is_file($path)) return [];
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function music_meta_save_cache($dir, $cache) {
    if (!is_dir(MUSIC_META_CACHE_DIR) && !@mkdir(MUSIC_META_CACHE_DIR, 0775, true)) return;
    $path = music_meta_cache_path($dir);
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($cache)) === false) return;
    if (!@rename($tmp, $path)) @unlink($tmp);
}

// Returns filename => ['title' => ..., 'artist' => ...] for the given .mp3 files in $dir.
// $readMissing reads tags for uncached files; only pass true for a local-disk directory.
// On the object-storage mounts a single read pulls the whole file (several seconds), so
// those dirs are cache-only at page load and filled by the CLI backfill below.
function music_meta_annotate($dir, $filenames, $readMissing = false, $saveEvery = 0) {
    $result = [];
    $cache = music_meta_load_cache($dir);
    $newCache = [];
    $changed = false;
    $reads = 0;
    foreach ($filenames as $file) {
        $split = music_meta_split_filename(pathinfo($file, PATHINFO_FILENAME));
        if ($split['artist'] === '') {
            $full = $dir . '/' . $file;
            $stat = @stat($full);
            $size = $stat ? (int) $stat['size'] : 0;
            $mtime = $stat ? (int) $stat['mtime'] : 0;
            $cached = $cache[$file] ?? null;
            if (is_array($cached) && ($cached['size'] ?? null) === $size && ($cached['mtime'] ?? null) === $mtime) {
                $split['artist'] = (string) ($cached['artist'] ?? '');
                $newCache[$file] = $cached;
            } elseif ($stat && $readMissing) {
                $split['artist'] = music_meta_read_id3_artist($full);
                $newCache[$file] = ['size' => $size, 'mtime' => $mtime, 'artist' => $split['artist']];
                $changed = true;
                // Long backfills checkpoint so progress survives an interruption.
                if ($saveEvery > 0 && ++$reads % $saveEvery === 0) {
                    music_meta_save_cache($dir, $newCache + $cache);
                }
            }
        }
        $result[$file] = $split;
    }
    // Drop entries for deleted/renamed files, and skip the write when nothing moved.
    if ($changed || count($newCache) !== count($cache)) {
        music_meta_save_cache($dir, $newCache);
    }
    return $result;
}

// CLI backfill for an object-storage music dir, e.g.:
//   sudo -u www-data php /var/www/dashboard/includes/music_meta.php /var/www/cdn/music
if (PHP_SAPI === 'cli' && isset($argv[1]) && realpath($argv[0]) === __FILE__) {
    $dir = rtrim($argv[1], '/');
    if (!is_dir($dir)) {
        fwrite(STDERR, "Not a directory: $dir\n");
        exit(1);
    }
    $files = array_values(array_filter(scandir($dir), fn($f) => str_ends_with($f, '.mp3')));
    $meta = music_meta_annotate($dir, $files, true, 25);
    $withArtist = count(array_filter($meta, fn($m) => $m['artist'] !== ''));
    echo count($files) . " tracks, $withArtist with an artist\n";
}
