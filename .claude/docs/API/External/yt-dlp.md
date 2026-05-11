# yt-dlp Python Library Reference

**Installed version (as of 2026-05-11):** 2025.1.26  
**License:** Unlicense (public domain)  
**PyPI package:** `yt-dlp`  
**Import:** `import yt_dlp`

yt-dlp is a feature-rich command-line audio/video downloader and Python library. It is a fork of youtube-dl with significant additional features, bug fixes, and active maintenance. It supports hundreds of sites including YouTube, Twitch VODs, SoundCloud, Twitter, Reddit, and many others.

---

## 1. Overview

### Import and class

```python
import yt_dlp

# Main class — all functionality is accessed through this
yt_dlp.YoutubeDL(params=None, auto_init=True)
```

### Context manager pattern (recommended)

The `YoutubeDL` object implements `__enter__` and `__exit__`, so it should always be used as a context manager. The context manager ensures proper cleanup of network connections and temporary resources.

```python
with yt_dlp.YoutubeDL(ydl_opts) as ydl:
    info = ydl.extract_info(url, download=False)
```

### How it works

1. You create a `YoutubeDL` object with an options dict (`params`).
2. When you call `extract_info()` or `download()`, yt-dlp finds the appropriate `InfoExtractor` for the URL.
3. The extractor fetches metadata and returns an **info dict** — a dictionary describing the video/audio, available formats, and (if download=True) download results.
4. If `download=True`, yt-dlp selects a format, downloads it, and runs any configured post-processors.

### Important API note

> The return value of `YoutubeDL.extract_info` is not guaranteed to be JSON-serializable or even a plain dictionary — it is dictionary-like. To get a serializable dict, pass it through `YoutubeDL.sanitize_info(info)`.

---

## 2. YoutubeDL Constructor Options

All options are passed as a single dict to `YoutubeDL(params)`. No options are required; an empty dict `{}` works.

### 2.1 Authentication

| Option | Type | Description |
|--------|------|-------------|
| `username` | str | Username for authentication |
| `password` | str | Password for authentication |
| `videopassword` | str | Password for accessing a password-protected video |
| `ap_mso` | str | Adobe Pass multiple-system operator identifier |
| `ap_username` | str | MSO account username |
| `ap_password` | str | MSO account password |
| `usenetrc` | bool | Use `~/.netrc` for authentication |
| `netrc_location` | str | Path to netrc file (default: `~/.netrc`) |
| `netrc_cmd` | str | Shell command to get credentials |

### 2.2 Output Control

| Option | Type | Description |
|--------|------|-------------|
| `verbose` | bool | Print additional debug info to stdout |
| `quiet` | bool | Suppress all messages to stdout |
| `no_warnings` | bool | Suppress warning messages |
| `logtostderr` | bool | Print everything to stderr instead of stdout |
| `logger` | object | Custom logger object with `debug(msg)`, `warning(msg)`, `error(msg)` methods. Both debug and info messages go to `debug()` — debug messages are prefixed `[debug] ` |
| `consoletitle` | bool | Display progress in the console window title bar |
| `noprogress` | bool | Do not print the progress bar |
| `forceprint` | dict/list | Dict with WHEN keys mapped to lists of templates to print to stdout. Allowed keys: `video`, or any item in `utils.POSTPROCESS_WHEN`. For compatibility, a single list is also accepted |
| `print_to_file` | dict | Dict with WHEN keys mapped to lists of `(template, filename)` tuples |
| `forcejson` | bool | Force printing info_dict as JSON |
| `dump_single_json` | bool | Force printing the info_dict of the whole playlist as a single JSON line |

### 2.3 Download Behavior

| Option | Type | Description |
|--------|------|-------------|
| `simulate` | bool/None | Do not download. If None (unset), simulate only if `listsubtitles`, `listformats`, or `list_thumbnails` is used |
| `skip_download` | bool | Skip the actual download but still process and extract info |
| `format` | str/callable | Format selector string — see Section 5. Can also be a function taking `ctx` and returning formats |
| `allow_unplayable_formats` | bool | Allow extracting and downloading unplayable formats |
| `ignore_no_formats_error` | bool | Ignore "No video formats" error (useful for metadata-only extraction) |
| `format_sort` | list | List of fields by which to sort video formats. See Section 5.2 |
| `format_sort_force` | bool | Force the given format_sort (overrides defaults) |
| `prefer_free_formats` | bool | Prefer video formats with free containers over non-free ones of the same quality |
| `allow_multiple_video_streams` | bool | Allow multiple video streams to be merged into a single file |
| `allow_multiple_audio_streams` | bool | Allow multiple audio streams to be merged into a single file |
| `check_formats` | bool/str/None | Test if formats are downloadable. `True` = check all, `False` = check none, `'selected'` = check selected formats, `None` = check only if requested by extractor |
| `ignoreerrors` | bool/str | Do not stop on download/postprocessing errors. `'only_download'` ignores only download errors. API default is `False`; CLI default is `'only_download'` |
| `skip_playlist_after_errors` | int | Number of allowed failures before skipping the rest of a playlist |

### 2.4 File System / Output

| Option | Type | Description |
|--------|------|-------------|
| `paths` | dict | Output paths dict. Keys: `'home'`, `'temp'`, and keys of `OUTTMPL_TYPES` |
| `outtmpl` | str/dict | Output template. Dict with `'default'` key plus optional per-type keys. String for compatibility with youtube-dl. See Section 6 |
| `outtmpl_na_placeholder` | str | Placeholder for unavailable meta fields in outtmpl (default: `'NA'`) |
| `restrictfilenames` | bool | Do not allow `&` and spaces in filenames |
| `trim_file_name` | int | Limit filename length (extension excluded) |
| `windowsfilenames` | bool | `True`: Force Windows-compatible filenames. `False`: Sanitize only minimally. No effect on Windows |
| `overwrites` | bool/None | `True`: Overwrite all files. `None`: Overwrite only non-video files. `False`: Never overwrite |
| `cachedir` | str/bool | Cache files directory. `False` to disable filesystem cache |
| `download_archive` | set/str | Set or filename where downloads are recorded. Already-present videos are skipped |
| `break_on_existing` | bool | Stop download process when a video already in archive is encountered |
| `break_per_url` | bool | Whether `break_on_existing` acts per input URL vs. for the entire queue |
| `force_write_download_archive` | bool | Force writing download archive even with `skip_download` or `simulate` |
| `keepvideo` | bool | Keep the video file after post-processing |
| `final_ext` | str | Expected final extension; used to detect already-downloaded-and-converted files |

### 2.5 Playlist Handling

| Option | Type | Description |
|--------|------|-------------|
| `noplaylist` | bool | Download single video instead of a playlist if in doubt |
| `playlist_items` | str | Specific indices of playlist to download (e.g., `'1,3,5-7'`) |
| `playlistrandom` | bool | Download playlist items in random order |
| `lazy_playlist` | bool | Process playlist entries as they are received |
| `extract_flat` | bool/str | Whether to resolve url_results further: `False` = always process (API default), `True` = never process, `'in_playlist'` = don't process inside playlist, `'discard'` = process but don't return from inside playlist, `'discard_in_playlist'` = discard only for playlists (CLI default) |

### 2.6 Filtering / Selection

| Option | Type | Description |
|--------|------|-------------|
| `matchtitle` | str | Download only videos whose title matches this regex |
| `rejecttitle` | str | Reject videos whose title matches this regex |
| `age_limit` | int | Skip videos unsuitable for this age (in years) |
| `min_views` | int/None | Minimum view count. Videos without view count are always downloaded. `None` = no limit |
| `max_views` | int/None | Maximum view count. `None` = no limit |
| `daterange` | DateRange | Download only if upload_date is in range (`utils.DateRange` object) |
| `match_filter` | callable | Function `(info_dict, *, incomplete: bool) -> Optional[str]`. Return a message string to skip, `None` to download, `utils.NO_DEFAULT` to ask interactively, or raise `utils.DownloadCancelled` to abort |
| `allowed_extractors` | list | List of regex patterns to match against extractor names that are allowed |

### 2.7 Network / Proxy

| Option | Type | Description |
|--------|------|-------------|
| `proxy` | str | URL of the proxy server (e.g., `'socks5://user:pass@127.0.0.1:1080/'`) |
| `geo_verification_proxy` | str | Proxy URL for IP address verification on geo-restricted sites |
| `source_address` | str | Client-side IP address to bind to (e.g., `'0.0.0.0'` to let OS choose) |
| `socket_timeout` | int/float | Seconds to wait for unresponsive hosts |
| `nocheckcertificate` | bool | Do not verify SSL certificates |
| `legacyserverconnect` | bool | Explicitly allow HTTPS to servers that don't support RFC 5746 |
| `client_certificate` | str | Path to PEM client certificate (may include private key) |
| `client_certificate_key` | str | Path to private key file for client certificate |
| `client_certificate_password` | str | Password for encrypted client certificate key |
| `prefer_insecure` | bool | Use HTTP instead of HTTPS (only some extractors) |
| `enable_file_urls` | bool | Enable `file://` URLs (disabled by default for security) |
| `http_headers` | dict | Dict of custom headers for all requests |
| `bidi_workaround` | bool | Work around buggy terminals without bidirectional text support |
| `debug_printtraffic` | bool | Print sent and received HTTP traffic |
| `geo_bypass` | bool | Bypass geographic restrictions via faking `X-Forwarded-For` HTTP header |
| `geo_bypass_country` | str | Two-letter ISO 3166-2 country code for geo bypass |
| `geo_bypass_ip_block` | str | IP range in CIDR notation for geo bypass |
| `impersonate` | ImpersonateTarget | Client to impersonate for requests (`yt_dlp.networking.impersonate.ImpersonateTarget`) |

### 2.8 Cookies

| Option | Type | Description |
|--------|------|-------------|
| `cookiefile` | str/stream | Path (or text stream) for reading and dumping cookies. Netscape format |
| `cookiesfrombrowser` | tuple | Tuple: `(browser_name, profile, keyring, container)`. E.g., `('chrome',)` or `('firefox', 'default', None, 'Meta')`. Supported browsers: `brave`, `chrome`, `chromium`, `edge`, `firefox`, `opera`, `safari`, `vivaldi`, `whale` |

### 2.9 Search

| Option | Type | Description |
|--------|------|-------------|
| `default_search` | str | Prepend this string if input URL is not valid. `'auto'` for elaborate guessing (searches YouTube). Any extractor prefix like `'ytsearch:'`, `'scsearch:'` |

### 2.10 Rate Limiting / Sleep

| Option | Type | Description |
|--------|------|-------------|
| `sleep_interval` | float | Seconds to sleep before each download (or lower bound of random range) |
| `max_sleep_interval` | float | Upper bound of random sleep range (must use with `sleep_interval`) |
| `sleep_interval_requests` | float | Seconds to sleep between requests during extraction |
| `sleep_interval_subtitles` | float | Seconds to sleep before each subtitle download |
| `wait_for_video` | tuple | `(min_secs, max_secs)` — wait for scheduled streams to become available |

### 2.11 Subtitles

| Option | Type | Description |
|--------|------|-------------|
| `writesubtitles` | bool | Write video subtitles to a file |
| `writeautomaticsub` | bool | Write auto-generated subtitles to a file |
| `listsubtitles` | bool | List all available subtitles for the video and exit |
| `subtitlesformat` | str | Format code for subtitles (e.g., `'srt'`, `'vtt'`) |
| `subtitleslangs` | list | List of language codes to download. May contain `'all'`. Prefix with `-` to exclude (e.g., `['all', '-live_chat']`) |

### 2.12 Thumbnails / Descriptions / Info Files

| Option | Type | Description |
|--------|------|-------------|
| `writethumbnail` | bool | Write thumbnail image to file |
| `write_all_thumbnails` | bool | Write all thumbnail formats to files |
| `writedescription` | bool | Write video description to `.description` file |
| `writeinfojson` | bool | Write video info to `.info.json` file |
| `clean_infojson` | bool | Remove internal metadata from the infojson |
| `getcomments` | bool | Extract video comments (not written to disk unless `writeinfojson` is also set) |
| `allow_playlist_files` | bool | Whether to write playlists' description/infojson to disk when using write* options |
| `writelink` | bool | Write internet shortcut file (`.url`/`.webloc`/`.desktop` per platform) |
| `writeurllink` | bool | Write Windows internet shortcut (`.url`) |
| `writewebloclink` | bool | Write macOS internet shortcut (`.webloc`) |
| `writedesktoplink` | bool | Write Linux internet shortcut (`.desktop`) |

### 2.13 Post-Processing

| Option | Type | Description |
|--------|------|-------------|
| `postprocessors` | list | List of post-processor dicts. Each dict has `key` (name) and optional `when` (timing). See Section 7 |
| `ffmpeg_location` | str | Path to ffmpeg binary or its containing directory |
| `postprocessor_args` | dict/list | Dict of `postprocessor/executable` keys (lowercase) to lists of additional CLI args. Dict can have `'PP+EXE'` keys. Use `'default'` for args passed to all PPs. Compat: single list also accepted |
| `merge_output_format` | str | `/`-separated list of extensions for format merging (e.g., `'mp4/mkv'`) |
| `fixup` | str | Automatically correct known file faults: `'never'`, `'warn'`, `'detect_or_warn'` (default) |

### 2.14 Progress Hooks

| Option | Type | Description |
|--------|------|-------------|
| `progress_hooks` | list | List of callables called on download progress. Each receives a dict with `status` (`'downloading'`, `'error'`, or `'finished'`), `info_dict`, and when downloading/finished: `filename`, `tmpfilename`, `downloaded_bytes`, `total_bytes`, `total_bytes_estimate`, `elapsed`, `eta`, `speed`, `fragment_index`, `fragment_count` |
| `postprocessor_hooks` | list | List of callables called on postprocessor progress. Each receives a dict with `status` (`'started'`, `'processing'`, or `'finished'`), `postprocessor` (name), `info_dict` |
| `progress_template` | dict | Templates for progress outputs. Keys: `'download'`, `'postprocess'`, `'download-title'`, `'postprocess-title'`. Template mapped on dict with `'progress'` and `'info'` keys |

### 2.15 Downloader Options (passed through to downloader)

These are not used by `YoutubeDL` directly but by the underlying file downloader:

| Option | Description |
|--------|-------------|
| `nopart` | Do not use `.part` files |
| `updatetime` | Use the Last-modified header to set the file modification time |
| `buffersize` | Size of download buffer |
| `ratelimit` | Maximum download rate in bytes/sec |
| `throttledratelimit` | Minimum download rate below which throttling is assumed |
| `min_filesize` | Minimum file size to download |
| `max_filesize` | Maximum file size to download |
| `retries` | Number of retries (default: 10) |
| `file_access_retries` | Number of times to retry on file access error |
| `fragment_retries` | Number of retries for a fragment |
| `continuedl` | Try to resume partially downloaded files |
| `hls_use_mpegts` | Use mpegts for HLS downloads |
| `http_chunk_size` | Size of chunk for HTTP downloads |
| `concurrent_fragment_downloads` | Number of fragments to download concurrently |
| `progress_delta` | Minimum time interval between progress outputs |

### 2.16 Extractor Options

| Option | Type | Description |
|--------|------|-------------|
| `extractor_retries` | int | Number of retries for known errors (default: 3) |
| `dynamic_mpd` | bool | Process dynamic DASH manifests (default: `True`) |
| `hls_split_discontinuity` | bool | Split HLS playlists at discontinuities like ad breaks (default: `False`) |
| `extractor_args` | dict | Per-extractor arguments dict. E.g., `{'youtube': {'skip': ['dash', 'hls']}}`. Values must be lists of strings |
| `mark_watched` | bool | Mark videos watched (even with --simulate). YouTube only |

### 2.17 Miscellaneous

| Option | Type | Description |
|--------|------|-------------|
| `encoding` | str | Use this encoding instead of the system-specified |
| `download_ranges` | callable | Function `(info_dict, ydl) -> Iterable[Section]`. Each Section is a dict with `start_time`, `end_time`, optional `title`, optional `index` |
| `force_keyframes_at_cuts` | bool | Re-encode video when downloading ranges to get precise cuts |
| `live_from_start` | bool | Download livestream videos from the start |
| `external_downloader` | dict | Dict of `protocol: executable` pairs. Protocols: `default`, `http`, `ftp`, `m3u8`, `dash`, `rtsp`, `rtmp`, `mms`. Use `'native'` for the native downloader |
| `compat_opts` | set | Compatibility options (see upstream docs). Note: some compat options don't work via the API |
| `color` | dict/str | Output color policy. Stream keys: `'stdout'`, `'stderr'`. Policies: `'always'`, `'auto'`, `'no_color'`, `'never'`, `'auto-tty'`, `'no_color-tty'` |
| `listformats` | bool | Print overview of available formats and exit |
| `list_thumbnails` | bool | Print table of thumbnails and exit |
| `retry_sleep_functions` | dict | Dict of functions `(attempts) -> seconds`. Keys: `'http'`, `'fragment'`, `'file_access'`, `'extractor'` |

### 2.18 Deprecated Options (do not use)

| Old option | Replacement |
|------------|-------------|
| `break_on_reject` | `raise DownloadCancelled(msg)` in `match_filter` |
| `force_generic_extractor` | `allowed_extractors = ['generic', 'default']` |
| `playliststart` / `playlistend` / `playlistreverse` | `playlist_items` |
| `forceurl` / `forcetitle` / `forceid` / `forcethumbnail` / `forcedescription` / `forcefilename` / `forceduration` | `forceprint` |
| `allsubtitles` | `subtitleslangs = ['all']` |
| `post_hooks` | Register a custom postprocessor via `add_post_processor()` |
| `hls_prefer_native` | `external_downloader = {'m3u8': 'native'}` or `{'m3u8': 'ffmpeg'}` |
| `no_color` | `color = 'no_color'` |
| `no_overwrites` | `overwrites = False` |
| `youtube_include_dash_manifest` | `extractor_args = {'youtube': {'skip': ['dash']}}` |
| `youtube_include_hls_manifest` | `extractor_args = {'youtube': {'skip': ['hls']}}` |

---

## 3. `extract_info()` — Method Reference

```python
ydl.extract_info(
    url,
    download=True,
    ie_key=None,
    extra_info=None,
    process=True,
    force_generic_extractor=False  # deprecated
)
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `url` | str | required | URL to extract. Can be a direct video URL, playlist URL, or a search query if `default_search` is set |
| `download` | bool | `True` | Whether to download the video(s). Set `False` for metadata-only extraction |
| `process` | bool | `True` | Whether to resolve all unresolved references (URLs, playlist items). Must be `True` for download to work |
| `ie_key` | str | `None` | Use only the extractor with this key (e.g., `'Youtube'`, `'Generic'`) |
| `extra_info` | dict | `None` | Extra values to add to the info dict. For internal use |
| `force_generic_extractor` | bool | `False` | Deprecated — use `ie_key='Generic'` instead |

### Return value

Returns an **info dict** (see Section 4) or `None` if extraction failed and `ignoreerrors` is set.

For playlists, returns a dict with `_type: 'playlist'` and an `entries` list. Each entry is a video info dict.

**Important:** The return value may not be JSON-serializable. Use `ydl.sanitize_info(info)` to get a clean serializable dict:

```python
import json
with yt_dlp.YoutubeDL({'quiet': True}) as ydl:
    info = ydl.extract_info(url, download=False)
    clean = ydl.sanitize_info(info)
    print(json.dumps(clean))
```

---

## 4. Info Dict Fields

The info dict returned by `extract_info()` contains the following fields. All fields are optional — their presence depends on the extractor and the website. Always use `.get()` with a default.

### 4.1 Core Video Identity

| Field | Type | Description |
|-------|------|-------------|
| `id` | str | Video identifier (extractor-specific) |
| `title` | str | Video title |
| `fulltitle` | str | Video title ignoring live timestamp and generic title |
| `display_id` | str | Alternative identifier for the video |
| `alt_title` | str | Secondary/alternative title |
| `description` | str | Full video description |
| `webpage_url` | str | URL to the video webpage (can be re-fed to yt-dlp) |
| `webpage_url_basename` | str | Basename of the webpage URL |
| `webpage_url_domain` | str | Domain of the webpage URL |
| `original_url` | str | The URL originally given by the user |
| `extractor` | str | Name of the extractor used |
| `extractor_key` | str | Key name of the extractor |

### 4.2 Uploader / Channel

| Field | Type | Description |
|-------|------|-------------|
| `uploader` | str | Full name of the video uploader |
| `uploader_id` | str | Nickname or ID of the uploader |
| `uploader_url` | str | URL to the uploader's profile |
| `channel` | str | Full name of the channel |
| `channel_id` | str | Channel ID |
| `channel_url` | str | URL of the channel |
| `channel_follower_count` | int | Number of channel followers |
| `channel_is_verified` | bool | Whether the channel is verified |
| `creators` | list | List of creator names |
| `creator` | str | Creators, comma-separated |

### 4.3 Dates / Timestamps

| Field | Type | Description |
|-------|------|-------------|
| `timestamp` | int | UNIX timestamp when the video became available |
| `upload_date` | str | Upload date in UTC: `'YYYYMMDD'` |
| `release_timestamp` | int | UNIX timestamp of release |
| `release_date` | str | Release date in UTC: `'YYYYMMDD'` |
| `release_year` | int | Year of release |
| `modified_timestamp` | int | UNIX timestamp of last modification |
| `modified_date` | str | Last modification date in UTC: `'YYYYMMDD'` |

### 4.4 Duration / Timing

| Field | Type | Description |
|-------|------|-------------|
| `duration` | float | Video length in seconds |
| `duration_string` | str | Human-readable duration: `'HH:mm:ss'` |
| `start_time` | float | Start time in seconds (from URL fragment) |
| `end_time` | float | End time in seconds (from URL fragment) |

### 4.5 Statistics

| Field | Type | Description |
|-------|------|-------------|
| `view_count` | int | Number of views |
| `concurrent_view_count` | int | Current live viewer count |
| `like_count` | int | Number of likes |
| `dislike_count` | int | Number of dislikes |
| `repost_count` | int | Number of reposts |
| `average_rating` | float | Average user rating |
| `comment_count` | int | Number of comments |
| `save_count` | int | Number of saves |

### 4.6 Classification / Availability

| Field | Type | Description |
|-------|------|-------------|
| `age_limit` | int | Age restriction in years |
| `categories` | list | List of category strings |
| `tags` | list | List of tag strings |
| `cast` | list | List of cast member names |
| `license` | str | License name |
| `location` | str | Physical location where filmed |
| `live_status` | str | `'not_live'`, `'is_live'`, `'is_upcoming'`, `'was_live'`, `'post_live'` |
| `is_live` | bool | Whether this is a live stream |
| `was_live` | bool | Whether this was originally a live stream |
| `playable_in_embed` | bool/str | Whether playable in embedded players |
| `availability` | str | `'private'`, `'premium_only'`, `'subscriber_only'`, `'needs_auth'`, `'unlisted'`, `'public'` |
| `media_type` | str | Site's classification (e.g., `'episode'`, `'clip'`, `'trailer'`) |

### 4.7 Playlist Fields

| Field | Type | Description |
|-------|------|-------------|
| `playlist_id` | str | Playlist identifier |
| `playlist_title` | str | Playlist name |
| `playlist` | str | `playlist_title` or `playlist_id` |
| `playlist_count` | int | Total items in playlist (may be unknown) |
| `playlist_index` | int | Index of video in playlist (zero-padded) |
| `playlist_autonumber` | int | Position in download queue (zero-padded) |
| `playlist_uploader` | str | Full name of playlist uploader |
| `playlist_uploader_id` | str | Nickname or ID of playlist uploader |
| `playlist_channel` | str | Display name of channel that uploaded the playlist |
| `playlist_channel_id` | str | ID of channel that uploaded the playlist |
| `playlist_webpage_url` | str | URL of playlist webpage |
| `n_entries` | int | Total number of extracted items in this playlist |

### 4.8 Format / Quality Fields (on the info dict itself — best selected format)

After format selection, the info dict is updated with the fields of the chosen best format:

| Field | Type | Description |
|-------|------|-------------|
| `format` | str | Human-readable format description |
| `format_id` | str | Short format identifier |
| `format_note` | str | Additional format info |
| `ext` | str | File extension |
| `url` | str | Direct download URL |
| `manifest_url` | str | URL of the manifest for streaming formats |
| `width` | int | Video width in pixels |
| `height` | int | Video height in pixels |
| `resolution` | str | Textual description of width and height |
| `aspect_ratio` | float | Video aspect ratio |
| `dynamic_range` | str | Dynamic range type |
| `fps` | float | Frame rate |
| `vcodec` | str | Video codec name (`'none'` if audio-only) |
| `acodec` | str | Audio codec name (`'none'` if video-only) |
| `vbr` | float | Video bitrate in kbps |
| `abr` | float | Audio bitrate in kbps |
| `tbr` | float | Total average bitrate in kbps |
| `asr` | int | Audio sample rate in Hz |
| `audio_channels` | int | Number of audio channels |
| `filesize` | int | Exact file size in bytes (if known in advance) |
| `filesize_approx` | int | Approximate file size in bytes |
| `container` | str | Container format name |
| `protocol` | str | Download protocol (e.g., `'https'`, `'m3u8'`, `'dash'`) |
| `language` | str | Language code |
| `language_preference` | int | Language preference score |
| `quality` | float | Format quality score |
| `has_drm` | bool | Whether the format has DRM protection |
| `rows` | int | Number of rows in a storyboard thumbnail |
| `columns` | int | Number of columns in a storyboard thumbnail |

### 4.9 Post-Download Fields

These fields are populated after downloading and post-processing:

| Field | Type | Description |
|-------|------|-------------|
| `_filename` | str | The prepared output filename (set before download, for backward compat) |
| `filepath` | str | Actual path of the downloaded/processed file |
| `requested_downloads` | list | List of downloaded format dicts. Each has `filepath` and other format fields |
| `requested_formats` | list | List of format dicts that were requested for merging |
| `requested_subtitles` | dict | Subtitle info dict |

### 4.10 Accessing `requested_downloads`

After a download with `download=True`, the filepath of the final file can be found in:

```python
info['requested_downloads'][0].get('filepath')
# or for compatibility:
info['requested_downloads'][0].get('filename')   # older yt-dlp versions
# or the backward-compat field set before post-processing:
info.get('_filename')
```

The `requested_downloads` list contains one entry per downloaded stream. For merged video+audio, there is still one entry (the merged output). Each entry is a dict with the same format fields as above plus `filepath`.

### 4.11 Computed / Misc Fields

| Field | Type | Description |
|-------|------|-------------|
| `epoch` | int | Unix timestamp when info extraction completed |
| `autonumber` | int | Auto-incrementing download counter (5 digits, padded) |
| `video_autonumber` | int | Auto-incrementing video counter |
| `_type` | str | Result type: `'video'`, `'playlist'`, `'multi_video'`, `'url'`, `'url_transparent'` |

---

## 5. Format Selection

### 5.1 Format Selector Syntax

Passed as the `format` option in `ydl_opts`. The default (when `format` is not set) is `bestvideo*+bestaudio/best`.

#### Special names

| Selector | Description |
|----------|-------------|
| `best` | Best quality format containing both video and audio |
| `worst` | Worst quality format containing both video and audio |
| `bestvideo` / `bv` | Best video-only format (no audio) |
| `bestaudio` / `ba` | Best audio-only format (no video) |
| `bestvideo*` / `bv*` | Best format containing video (may also have audio) |
| `bestaudio*` / `ba*` | Best format containing audio (may also have video) — do not use |
| `worstvideo` / `wv` | Worst video-only format |
| `worstaudio` / `wa` | Worst audio-only format |
| `worstvideo*` / `wv*` | Worst format containing video |
| `worstaudio*` / `wa*` | Worst format containing audio |
| `worst*` / `w*` | Worst format containing either video or audio |
| `all` | Select all formats separately |
| `mergeall` | Select and merge all formats (requires multistream flags) |

#### Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `/` | Fallback — try left side, use right if unavailable | `bestaudio/best` |
| `+` | Merge — combine video and audio into one file (requires ffmpeg) | `bestvideo+bestaudio` |
| `,` | Download multiple formats separately | `bv,ba` |
| `[condition]` | Filter by format field | `best[height<=720]` |
| `(group)` | Group selectors | `(mp4,webm)[height<480]` |
| `.n` | Select n-th best | `bv*.2` (2nd best video-containing format) |

#### Filter conditions

Filter fields are placed in square brackets after the selector.

**Numeric comparisons** (`<`, `<=`, `>`, `>=`, `=`, `!=`):

| Field | Description |
|-------|-------------|
| `filesize` | Exact file size in bytes |
| `filesize_approx` | Approximate file size in bytes |
| `width` | Video width |
| `height` | Video height |
| `aspect_ratio` | Aspect ratio |
| `tbr` | Total bitrate in kbps |
| `abr` | Audio bitrate in kbps |
| `vbr` | Video bitrate in kbps |
| `asr` | Audio sample rate in Hz |
| `fps` | Frame rate |
| `audio_channels` | Number of audio channels |
| `stretched_ratio` | Pixel aspect ratio |

**String comparisons** (`=`, `^=` starts with, `$=` ends with, `*=` contains, `~=` regex):

| Field | Description |
|-------|-------------|
| `url` | Video URL |
| `ext` | File extension |
| `acodec` | Audio codec |
| `vcodec` | Video codec |
| `container` | Container format |
| `protocol` | Download protocol |
| `language` | Language code |
| `dynamic_range` | Dynamic range |
| `format_id` | Format ID string |
| `format` | Human-readable format description |
| `format_note` | Additional format info |
| `resolution` | Textual resolution description |

Prefix any string comparison with `!` to negate it (e.g., `!*=dash`).

Append `?` after an operator to include formats where the field is unknown (e.g., `[height<=?720]`).

### 5.2 Format Sorting (`format_sort` option)

The `format_sort` option (list of field names) or `-S` CLI flag changes how formats are ranked. All fields sort descending unless prefixed with `+` (ascending). Append `:value` for a preferred cap.

| Sort key | Description |
|----------|-------------|
| `hasvid` | Prioritize formats with a video stream |
| `hasaud` | Prioritize formats with an audio stream |
| `ie_pref` | Extractor format preference |
| `lang` | Language preference |
| `quality` | Overall quality score |
| `source` | Source preference |
| `proto` | Protocol (https/ftps > http/ftp > m3u8_native > m3u8 > ...) |
| `vcodec` | Video codec (av01 > vp9.2 > vp9 > h265 > h264 > vp8 > h263 > theora) |
| `acodec` | Audio codec (flac/alac > wav/aiff > opus > vorbis > aac > mp4a > mp3 > ac4 > eac3 > ac3 > dts) |
| `codec` | Equivalent to `vcodec,acodec` |
| `vext` | Video extension (mp4 > mov > webm > flv) |
| `aext` | Audio extension (m4a > aac > mp3 > ogg > opus > webm) |
| `ext` | Equivalent to `vext,aext` |
| `filesize` | Exact file size (if known) |
| `fs_approx` | Approximate file size |
| `size` | Exact filesize if available, else approximate |
| `height` | Video height |
| `width` | Video width |
| `res` | Video resolution (smallest dimension) |
| `fps` | Frame rate |
| `hdr` | Dynamic range (DV > HDR12 > HDR10+ > HDR10 > HLG > SDR) |
| `channels` | Number of audio channels |
| `tbr` | Total average bitrate in kbps |
| `vbr` | Video bitrate in kbps |
| `abr` | Audio bitrate in kbps |
| `br` | Average bitrate (tbr/vbr/abr) |
| `asr` | Audio sample rate in Hz |

`hasvid` and `ie_pref` always have highest priority, regardless of user-defined order.

### 5.3 Common Format Examples

```python
# Best quality (default behavior)
'format': 'bestvideo*+bestaudio/best'

# Best audio only (Discord music bot use case)
'format': 'bestaudio/best'

# Best mp4 container
'format': 'best[ext=mp4]'

# Best with height cap
'format': 'best[height<=720]'

# Best m4a, then mp3, then best available (fallback chain)
'format': 'best[ext=m4a]/best[ext=mp3]/best'

# Worst quality (small file size)
'format': 'worst'

# Best audio, prefer m4a container
'format': 'm4a/bestaudio/best'
```

### 5.4 Format Selector as a Function

The `format` option can also be a callable for custom selection logic:

```python
def format_selector(ctx):
    formats = ctx.get('formats')[::-1]  # worst to best, reversed = best first
    best_video = next(f for f in formats if f['vcodec'] != 'none' and f['acodec'] == 'none')
    audio_ext = {'mp4': 'm4a', 'webm': 'webm'}[best_video['ext']]
    best_audio = next(f for f in formats if f['acodec'] != 'none' and f['vcodec'] == 'none' and f['ext'] == audio_ext)
    yield {
        'format_id': f'{best_video["format_id"]}+{best_audio["format_id"]}',
        'ext': best_video['ext'],
        'requested_formats': [best_video, best_audio],
        'protocol': f'{best_video["protocol"]}+{best_audio["protocol"]}',
    }

ydl_opts = {'format': format_selector}
```

---

## 6. Output Template (`outtmpl`)

### 6.1 Basic Syntax

Templates use Python `%`-style string formatting: `%(FIELD)s`, `%(FIELD)05d`, etc.

```python
'outtmpl': '%(title)s.%(ext)s'
'outtmpl': '/path/to/files/%(uploader)s/%(title)s.%(ext)s'
```

For per-file-type templates, use a dict:

```python
'outtmpl': {
    'default': '%(title)s.%(ext)s',
    'thumbnail': '%(title)s/%(title)s.%(ext)s',
}
```

Supported type keys for `outtmpl` dict: `default`, `subtitle`, `thumbnail`, `description`, `annotation` (deprecated), `infojson`, `link`, `pl_thumbnail`, `pl_description`, `pl_infojson`, `chapter`, `pl_video`.

### 6.2 Field Name Special Operations

| Feature | Syntax | Example |
|---------|--------|---------|
| Object traversal | `%(field.key)s` | `%(tags.0)s`, `%(subtitles.en.-1.ext)s` |
| Python slicing | `%(field.start:stop)s` | `%(id.3:7)s`, `%(id.6:2:-1)s` |
| Dict with specific keys | `%(field.:.{key1,key2})s` | `%(formats.:.{format_id,height})#j` |
| Entire infodict | `%()s` | `%(.{id,title})s` |
| Arithmetic | `%(field+N)s` | `%(playlist_index+10)03d` |
| strftime format | `%(field>strftime)s` | `%(upload_date>%Y-%m-%d)s` |
| Alternate fields | `%(field1,field2)s` | `%(release_date>%Y,upload_date>%Y\|Unknown)s` |
| Conditional replacement | `%(field&replacement\|fallback)s` | `%(chapters&has chapters\|no chapters)s` |
| Default value | `%(field\|default)s` | `%(uploader\|Unknown)s` |

### 6.3 Additional Conversion Types

Beyond standard Python format types (`diouxXeEfFgGcrs`):

| Type | Description |
|------|-------------|
| `B` | Convert bytes to human-readable size |
| `j` | JSON encoding (`#` flag = pretty-print, `+` flag = ensure Unicode) |
| `h` | HTML escape |
| `l` | Comma-separated list (`#` flag = newline-separated) |
| `q` | Quoted for terminal (`#` flag = split list into separate args) |
| `D` | Decimal suffixes (e.g., `10M`) (`#` flag = use 1024 as factor) |
| `S` | Sanitize as filename (`#` flag = restricted sanitization) |
| `U` | NFC Unicode normalization (`#` flag = NFD, `+` flag = NFKC/NFKD) |

### 6.4 General Field Syntax

```
%(name[.keys][addition][>strf][,alternate][&replacement][|default])[flags][width][.precision][length]type
```

### 6.5 Available Template Fields

All fields from Section 4 are available in templates. Additional computed fields:

| Field | Description |
|-------|-------------|
| `epoch` | Unix timestamp of when extraction completed |
| `autonumber` | Auto-incrementing counter (5-digit padded) |
| `video_autonumber` | Auto-incrementing video counter |
| `n_entries` | Total items in the playlist |

Note: Use `yt-dlp -j URL` to see all available fields for a specific video.

---

## 7. Post-Processors

### 7.1 Using Post-Processors via the `postprocessors` Option

Post-processors are specified as a list of dicts in the `postprocessors` option:

```python
'postprocessors': [
    {
        'key': 'FFmpegExtractAudio',   # The PP name (without 'PP' suffix)
        'preferredcodec': 'mp3',
        'preferredquality': '192',
        'nopostoverwrites': False,
        'when': 'post_process',        # Optional; see timing values below
    }
]
```

### 7.2 Post-Processor Timing (`when`)

The `when` field controls when the post-processor runs. Valid values (from `yt_dlp.utils.POSTPROCESS_WHEN`):

| Value | Runs |
|-------|------|
| `'pre_process'` | Before any processing |
| `'after_filter'` | After match_filter check |
| `'video'` | After video info extraction |
| `'before_dl'` | Before download starts |
| `'post_process'` | After download, before file move (default) |
| `'after_move'` | After file is moved to final location |
| `'after_video'` | After all formats of a video are processed |
| `'playlist'` | After entire playlist is processed |

### 7.3 FFmpegExtractAudioPP — Audio Extraction

**Key:** `'FFmpegExtractAudio'`

```python
'postprocessors': [{
    'key': 'FFmpegExtractAudio',
    'preferredcodec': 'mp3',      # Target codec
    'preferredquality': '192',    # Bitrate in kbps (if > 10) or VBR quality (0-10)
    'nopostoverwrites': False,    # Skip if output file already exists
}]
```

| Parameter | Description |
|-----------|-------------|
| `preferredcodec` | Target audio format. One of `'mp3'`, `'aac'`, `'m4a'`, `'opus'`, `'vorbis'`, `'flac'`, `'alac'`, `'wav'`, or `'best'` (default) |
| `preferredquality` | Quality setting. Values `> 10` are treated as bitrate in kbps (e.g., `'192'` = 192 kbps). Values `0-10` are VBR quality for supported codecs. `None` = use codec defaults |
| `nopostoverwrites` | If `True`, skip conversion if output file already exists |

**ACODECS mapping** (what codec/extension each `preferredcodec` produces):

| Input codec | Output ext | FFmpeg encoder | Notes |
|-------------|-----------|----------------|-------|
| `'mp3'` | `.mp3` | `libmp3lame` | |
| `'aac'` | `.m4a` | `aac` | Writes ADTS format |
| `'m4a'` | `.m4a` | `aac` | Uses BSF for proper M4A |
| `'opus'` | `.opus` | `libopus` | |
| `'vorbis'` | `.ogg` | `libvorbis` | |
| `'flac'` | `.flac` | `flac` | |
| `'alac'` | `.m4a` | — | Stream copy with ALAC codec |
| `'wav'` | `.wav` | — | PCM output |

**Quality behavior:**
- If `preferredquality` is `None`, codec default quality is used.
- If `preferredquality > 10`, `-b:a {quality}k` bitrate flag is passed.
- If `0 <= preferredquality <= 10`, VBR quality (`-q:a`) is calculated per codec:
  - `libmp3lame`: range 0-10 maps to VBR 10 (worst) to 0 (best)
  - `libvorbis`: range 0-10 maps to VBR 0 (worst) to 10 (best)
  - `aac`: maps to `-q:a` 0.1-4
  - `libfdk_aac`: maps to `-vbr` 1-5

**What the PP does:**
1. Detects the current audio codec in the downloaded file using ffprobe.
2. If the file is already in a common audio format matching `preferredcodec`, it skips conversion.
3. If the file codec matches `preferredcodec`, it stream-copies (lossless) instead of re-encoding.
4. Otherwise, runs ffmpeg to extract and convert the audio stream.
5. Removes the original video file (unless `keepvideo` is set).
6. Updates `info['filepath']` and `info['ext']` to reflect the new file.

### 7.4 Other Available Post-Processors

All available post-processors (key values for the `postprocessors` option):

| Key | Description |
|-----|-------------|
| `FFmpegExtractAudio` | Extract audio from video (see above) |
| `FFmpegVideoConvertor` | Re-encode video to specified format |
| `FFmpegVideoRemuxer` | Remux video to different container without re-encoding |
| `FFmpegMerger` | Merge separate video and audio streams |
| `FFmpegEmbedSubtitle` | Embed subtitles into video |
| `FFmpegMetadata` | Write metadata tags to file |
| `FFmpegThumbnailsConvertor` | Convert thumbnails to different format |
| `FFmpegSubtitlesConvertor` | Convert subtitle format |
| `FFmpegSplitChapters` | Split video into separate files by chapters |
| `FFmpegCopyStream` | Copy streams without re-encoding |
| `FFmpegFixupM4a` | Fix m4a_dash container |
| `FFmpegFixupM3u8` | Fix HLS stream issues |
| `FFmpegFixupTimestamp` | Fix timestamp issues |
| `FFmpegFixupDuration` | Fix duration metadata |
| `FFmpegFixupStretchedVideo` | Fix stretched video ratio |
| `FFmpegFixupDuplicateMoov` | Fix duplicate moov atom |
| `FFmpegConcatPP` | Concatenate playlist videos |
| `EmbedThumbnail` | Embed thumbnail as cover art |
| `MetadataFromField` | Parse metadata from field values |
| `MetadataFromTitle` | Parse metadata from title using regex |
| `MoveFilesAfterDownload` | Move files to final destination |
| `ModifyChapters` | Modify/remove chapters |
| `SponsorBlock` | Skip sponsor segments (requires SponsorBlock API) |
| `SponSkrub` | Remove sponsors from video |
| `XAttrMetadata` | Write metadata using extended file attributes |
| `Exec` | Execute a command after download |
| `ExecAfterDownload` | (alias for Exec) |

### 7.5 Adding Post-Processors Programmatically

```python
with yt_dlp.YoutubeDL() as ydl:
    ydl.add_post_processor(my_pp_instance, when='post_process')
    ydl.download(urls)
```

### 7.6 Custom Post-Processor

```python
class MyPostProcessor(yt_dlp.postprocessor.PostProcessor):
    def run(self, info):
        self.to_screen('Processing...')
        # Modify info dict here
        # Return: (list_of_files_to_delete, updated_info_dict)
        return [], info

with yt_dlp.YoutubeDL() as ydl:
    ydl.add_post_processor(MyPostProcessor(), when='pre_process')
    ydl.download(urls)
```

---

## 8. Cookies

### 8.1 Netscape Cookie File Format

The `cookiefile` option accepts a file in Netscape HTTP Cookie File format. This is the standard format used by most browsers when exporting cookies.

Format of each line (tab-separated):
```
domain  flag  path  secure  expiry  name  value
```

File header:
```
# Netscape HTTP Cookie File
# This file was generated by yt-dlp. Edit at your own risk.
```

Example:
```
.youtube.com	TRUE	/	TRUE	1893456000	LOGIN_INFO	some_value_here
.youtube.com	TRUE	/	FALSE	1893456000	VISITOR_INFO1_LIVE	another_value
```

Fields:
- `domain` — domain the cookie applies to (leading dot = include subdomains)
- `flag` — `TRUE` if valid for all machines in the domain
- `path` — path within the domain
- `secure` — `TRUE` if only sent over HTTPS
- `expiry` — expiration as UNIX timestamp (`0` = session cookie)
- `name` — cookie name
- `value` — cookie value

### 8.2 Exporting Cookies from Browsers

**Recommended: use `cookiesfrombrowser` option to load directly**

```python
'cookiesfrombrowser': ('chrome',)           # Default Chrome profile
'cookiesfrombrowser': ('firefox', 'default', None, 'Default')  # Firefox default profile
'cookiesfrombrowser': ('edge',)
'cookiesfrombrowser': ('brave',)
```

Supported browsers: `brave`, `chrome`, `chromium`, `edge`, `firefox`, `opera`, `safari`, `vivaldi`, `whale`

Tuple format: `(browser_name, profile_name_or_path, keyring, container_name)`
- `keyring` (Linux only): `None`, `'basictext'`, `'gnomekeyring'`, `'kwallet'`, `'kwallet5'`, `'kwallet6'`
- `container` (Firefox only): container name, or `'none'` for no container

**Exporting to file:**

Browser extensions that can export cookies in Netscape format:
- Chrome/Edge/Brave: "EditThisCookie" or "Get cookies.txt LOCALLY"
- Firefox: "cookies.txt" extension

Or use `yt-dlp --cookies-from-browser chrome --cookies cookies.txt --skip-download URL` to dump cookies to a file.

### 8.3 Using Cookie File in Code

```python
ydl_opts = {
    'cookiefile': '/path/to/cookies.txt',
    'quiet': True,
}
with yt_dlp.YoutubeDL(ydl_opts) as ydl:
    info = ydl.extract_info(url, download=False)
```

The cookie file is both read from and written to (updated) during the session, so the path must be writable.

---

## 9. Error Handling

### 9.1 Exception Hierarchy

All exceptions are in `yt_dlp.utils`:

```
Exception
└── YoutubeDLError
    ├── DownloadError            — download failed (wraps underlying error)
    ├── ExtractorError           — info extraction failed
    │   ├── GeoRestrictedError   — geo-restricted content
    │   ├── RegexNotFoundError   — regex not found in page
    │   ├── UnsupportedError     — URL not supported
    │   └── UserNotLive          — user is not live
    ├── PostProcessingError      — post-processor failed
    ├── ContentTooShortError     — downloaded file too short
    ├── SameFileError            — output paths clash
    ├── UnavailableVideoError    — video unavailable
    ├── ReExtractInfo            — re-extract is needed
    │   └── ThrottledDownload    — download is throttled
    ├── XAttrMetadataError       — extended attribute error
    ├── XAttrUnavailableError    — xattr not available
    └── DownloadCancelled (also base for "stop" signals)
        ├── MaxDownloadsReached  — max download count hit
        ├── ExistingVideoReached — video already in archive
        └── RejectedVideoReached — video rejected by filter
```

`CookieLoadError` inherits from `YoutubeDLError` and is raised in `yt_dlp.cookies`.

### 9.2 Catching Errors

```python
from yt_dlp.utils import DownloadError, ExtractorError

try:
    with yt_dlp.YoutubeDL(opts) as ydl:
        info = ydl.extract_info(url, download=False)
except DownloadError as e:
    print(f'Download failed: {e}')
except ExtractorError as e:
    print(f'Extraction failed: {e}')
except Exception as e:
    print(f'Unexpected error: {e}')
```

### 9.3 Suppressing Errors

Use `ignoreerrors=True` to continue on errors (returns `None` for failed URLs):

```python
ydl_opts = {
    'ignoreerrors': True,   # Continue on any error
    # or:
    'ignoreerrors': 'only_download',  # Continue only on download errors
}
```

### 9.4 Custom Logger

To redirect yt-dlp output to a custom logger:

```python
class SilentLogger:
    def debug(self, msg): pass
    def info(self, msg): pass
    def warning(self, msg): pass
    def error(self, msg): pass

ydl_opts = {'logger': SilentLogger()}
```

Note: Both `debug` and `info` messages go to the `debug()` method. Debug messages have the prefix `[debug] `.

---

## 10. Rate Limits and Operational Considerations

### 10.1 YouTube Bot Detection

YouTube actively detects and blocks automated downloads. Mitigation strategies in order of effectiveness:

1. **Use cookies from a logged-in browser session** (`cookiefile` or `cookiesfrombrowser`). This is the most reliable method. The bot's cookie file is at `/home/botofthespecter/ytdl-cookies.txt` (server path).

2. **Add sleep between requests:**
   ```python
   'sleep_interval': 1,
   'max_sleep_interval': 5,
   'sleep_interval_requests': 1,
   ```

3. **Limit concurrent downloads** — avoid running multiple YoutubeDL instances simultaneously.

4. **Use `extractor_args` to skip some manifests** (reduces requests, may affect quality):
   ```python
   'extractor_args': {'youtube': {'skip': ['dash', 'hls']}}
   ```

5. **PO Token (Player Token)** — YouTube increasingly requires a proof-of-origin token. If you encounter "Sign in to confirm you're not a bot" errors, check the [yt-dlp PO Token Guide wiki page](https://github.com/yt-dlp/yt-dlp/wiki/PO-Token-Guide).

### 10.2 General Rate Limiting

```python
ydl_opts = {
    'ratelimit': 1_000_000,     # 1 MB/s max download rate
    'sleep_interval': 2,        # 2s sleep before each download
    'max_sleep_interval': 10,   # Random sleep 2-10s
    'retries': 10,              # Retry up to 10 times on failure
}
```

### 10.3 Thread Safety

`YoutubeDL` instances are not thread-safe. For concurrent downloads, create a separate `YoutubeDL` instance per thread. Running `extract_info()` in a thread pool:

```python
import asyncio
import concurrent.futures

loop = asyncio.get_event_loop()

def run_yt():
    with yt_dlp.YoutubeDL(opts) as ydl:
        return ydl.extract_info(url, download=True)

info = await loop.run_in_executor(None, run_yt)
```

### 10.4 Keeping yt-dlp Updated

Extractors are frequently updated to keep up with site changes. Run updates regularly:

```bash
pip install -U yt-dlp
```

The installed version as of this doc is **2025.1.26**. YouTube changes frequently; if downloads start failing, check for a newer version first.

---

## 11. BotOfTheSpecter Callsites

### 11.1 Pattern 1: Title Extraction (Twitch bot `!songrequest`)

**Files:** `./bot/bot.py:3726–3742`, `./bot/beta.py:5684–5700`, `./bot/beta-v6.py:4761–4777`

**Purpose:** When a user runs `!songrequest <YouTube URL>`, yt-dlp extracts the video title, which is then used as a Spotify search query. No file is downloaded.

```python
ydl_opts = {
    'quiet': True,
    'no_warnings': True,
    'skip_download': True,
    'cookiefile': '/home/botofthespecter/ytdl-cookies.txt',
}
with yt_dlp.YoutubeDL(ydl_opts) as ydl:
    info = ydl.extract_info(message_content, download=False)
    video_title = info.get('title', '')
```

**Key options:**
- `quiet=True` — suppress all console output (important for bot processes)
- `no_warnings=True` — suppress warnings
- `skip_download=True` — no file is written
- `download=False` on `extract_info` — metadata only, no download attempted
- `cookiefile` — uses the shared bot cookie file to avoid YouTube bot detection

**Fields used from info dict:**
- `info.get('title', '')` — the video title string

**Notes:**
- `skip_download=True` combined with `download=False` is redundant but harmless; either alone suffices for metadata-only extraction.
- `message_content` is the raw message text containing the YouTube URL. yt-dlp will parse the URL out of it.
- The title is then passed to Spotify's search API to find the track.

### 11.2 Pattern 2: Audio Download (Discord bot music playback)

**File:** `./bot/specterdiscord.py:5254–5367`

**Purpose:** The Discord bot's music playback feature downloads audio from YouTube (or other supported sites) for playback in a voice channel.

```python
self.ytdl_format_options = {
    'format': 'bestaudio/best',
    'outtmpl': os.path.join(tempfile.gettempdir(), 'bot_music_cache', '%(id)s.%(ext)s'),
    'restrictfilenames': True,
    'noplaylist': True,
    'nocheckcertificate': True,
    'ignoreerrors': False,
    'logtostderr': False,
    'quiet': True,
    'no_warnings': True,
    'default_search': 'auto',
    'source_address': '0.0.0.0',
    'cookiefile': cookies_path,
    'extractaudio': True,    # Note: this is a CLI alias; see below
    'audioformat': 'mp3',   # Note: this is a CLI alias; see below
    'audioquality': '192K',  # Note: this is a CLI alias; see below
}
```

**Important:** `extractaudio`, `audioformat`, and `audioquality` are CLI-style options that yt-dlp also accepts in the params dict for compatibility. They map to the `FFmpegExtractAudioPP` post-processor. Equivalent API-style:

```python
'postprocessors': [{
    'key': 'FFmpegExtractAudio',
    'preferredcodec': 'mp3',
    'preferredquality': '192',
}]
```

**Three format fallback tries (in specterdiscord.py):**
1. `'format': 'bestaudio/best'` with extractaudio + mp3 + 192K
2. `'format': 'best[ext=m4a]/best[ext=mp3]/best'` (if first fails)
3. `'format': 'worst'` (last resort)

**Fields used from info dict after download:**

```python
info.get('title', '')                                     # Song title
info.get('id', 'unknown')                                # Video ID
info.get('_filename')                                    # Pre-move filepath
info['requested_downloads'][0].get('filepath')           # Final filepath after move
info['requested_downloads'][0].get('filename')           # Alternative filepath key
```

**Key options explained:**
- `format: 'bestaudio/best'` — prefer audio-only format, fall back to best combined
- `outtmpl` — saves to `{tmp}/bot_music_cache/{video_id}.{ext}` to allow caching by ID
- `restrictfilenames=True` — no `&` or spaces in filenames (safe for filesystem operations)
- `noplaylist=True` — if a playlist URL is accidentally passed, download only the first video
- `nocheckcertificate=True` — skip SSL verification (reduces failures on some networks)
- `ignoreerrors=False` — fail loudly on any error (so the bot can handle it)
- `default_search='auto'` — treat non-URL strings as YouTube search queries
- `source_address='0.0.0.0'` — let the OS choose the source IP for outbound connections
- `cookiefile=cookies_path` — `cookies_path` comes from `config.cookies_path` (server: `/home/botofthespecter/ytdl-cookies.txt`)

**Execution is in a thread pool** (to avoid blocking the async event loop):

```python
loop = asyncio.get_event_loop()
info = await loop.run_in_executor(None, run_yt)
```

---

## 12. Other Useful YoutubeDL Methods

| Method | Description |
|--------|-------------|
| `ydl.download(url_list)` | Download list of URLs. Returns count of errors |
| `ydl.extract_info(url, download=False)` | Extract metadata only |
| `ydl.sanitize_info(info)` | Return a JSON-serializable copy of info dict |
| `ydl.download_with_info_file(info_file)` | Download using a saved `.info.json` file |
| `ydl.add_post_processor(pp, when='post_process')` | Add a PostProcessor instance |
| `ydl.add_progress_hook(hook)` | Add a download progress hook function |
| `ydl.prepare_filename(info_dict, type='default')` | Get the output filename for a given info dict |
| `ydl.to_screen(msg)` | Print to screen (respects `quiet`) |
| `ydl.to_stderr(msg)` | Print to stderr |
| `ydl.params` | Dict of current options |

---

## 13. Quick Reference: Minimal Usage Patterns

### Metadata only (no download)

```python
import yt_dlp

with yt_dlp.YoutubeDL({'quiet': True, 'no_warnings': True}) as ydl:
    info = ydl.extract_info(url, download=False)
title = info.get('title', '')
```

### Download best audio as MP3

```python
import yt_dlp

opts = {
    'format': 'bestaudio/best',
    'outtmpl': '/tmp/%(id)s.%(ext)s',
    'quiet': True,
    'postprocessors': [{
        'key': 'FFmpegExtractAudio',
        'preferredcodec': 'mp3',
        'preferredquality': '192',
    }],
}
with yt_dlp.YoutubeDL(opts) as ydl:
    info = ydl.extract_info(url, download=True)
    filepath = info['requested_downloads'][0].get('filepath')
```

### Download with progress hook

```python
import yt_dlp

def on_progress(d):
    if d['status'] == 'finished':
        print(f'Done: {d["filename"]}')
    elif d['status'] == 'downloading':
        print(f'{d.get("_percent_str", "?%")} at {d.get("_speed_str", "?/s")}')

with yt_dlp.YoutubeDL({'progress_hooks': [on_progress]}) as ydl:
    ydl.download(['https://www.youtube.com/watch?v=BaW_jenozKc'])
```

### YouTube search

```python
import yt_dlp

with yt_dlp.YoutubeDL({'quiet': True, 'default_search': 'ytsearch1'}) as ydl:
    info = ydl.extract_info('never gonna give you up', download=False)
    if info and 'entries' in info:
        first = info['entries'][0]
        print(first.get('title'), first.get('webpage_url'))
```

### Suppress all output with silent logger

```python
import yt_dlp

class NullLogger:
    def debug(self, msg): pass
    def info(self, msg): pass
    def warning(self, msg): pass
    def error(self, msg): pass

with yt_dlp.YoutubeDL({'logger': NullLogger()}) as ydl:
    info = ydl.extract_info(url, download=False)
```

---

*Documented from yt-dlp v2025.1.26 source. For the latest upstream docs: https://github.com/yt-dlp/yt-dlp — The library is under active development; option names and behavior may change in newer versions.*
