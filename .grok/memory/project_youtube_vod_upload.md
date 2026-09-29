---
name: project_youtube_vod_upload
description: "User YouTube OAuth for optional VOD uploads — profile connect, youtubelink.php, website.youtube_tokens / youtube_vod_uploads, stream-server uploader"
type: project
---

# YouTube VOD upload

Streamers can connect **their own** YouTube channel (user OAuth 2.0, YouTube Data API v3) and have finished **stream server** MP4s uploaded via `videos.insert`. A service account will not work.

**Testing gate:** while the Google Cloud app is in Testing, only dashboard **admins** (`users.is_admin`) can connect or enqueue uploads. Everyone else sees Coming Soon on Profile, Videos, and `youtubelink.php`. The stream-server uploader and auto-queue also skip non-admin users.

## Pieces

| Piece | Location |
| ----- | -------- |
| PHP config | `./config/youtube.php` → production `/var/www/config/youtube.php` |
| OAuth connect | `./dashboard/youtubelink.php` (admin-only while in Testing; connect/disconnect only — no settings or upload list) |
| Helpers | `./dashboard/includes/youtube.php` |
| Profile card | `./dashboard/profile.php` Connected Accounts |
| Schema | `./migrations/website/20260910_0001_youtube_vod_oauth.php` (`youtube_tokens`, `youtube_vod_uploads`) |
| Token refresh | `./bot/refresh_youtube_tokens.py` (45 min via `token_refresh_scheduler`; bots API `refresh_youtube`) |
| Uploader | `./stream/youtube_vod_uploader.py` — run on each **stream server**, not the bot host |
| Auto-queue | `stream.py` after FLV→MP4, if `auto_upload` is on |
| Twitch VOD → YouTube | Videos page “Send to YouTube”: stream server FFmpeg-pulls the Twitch HLS to `{root}/{username}/twitch-{id}.mp4`, then the same uploader does `videos.insert` |

## Stream files (not twitch-recorder)

MP4s live where `stream.py` writes them after conversion:

- us-west / us-east / eu-central: `/mnt/s3/bots-stream/{username}/{file}.mp4`
- sydney: next to `stream.py` unless `STREAM_FILES_ROOT` is set
- Dashboard stream APIs also list `/mnt/s3/bots-stream/{username}/`

Do **not** use `/mnt/blockstorage` (that's twitch-recorder).

Set `STREAM_SERVER` on each stream host to the same value as `stream.py -server`. Override path with `STREAM_FILES_ROOT` if needed. `YOUTUBE_CLIENT_ID` / `YOUTUBE_CLIENT_SECRET` belong on the stream host `.env` as well as the bot host (refresh).

If a **stream** MP4 is not on this host, the uploader leaves the job queued (another region may have it).

**Twitch VOD import (shared pull, 2026-09-29):** Store, YouTube and S3 all go through `./stream/twitch_vod_downloader.py`. One row per (user, VOD) in `website.twitch_vod_pulls` (`queued/pulling/stored/failed`, 10s heartbeat, live = updated in last 120s) decides who downloads; everyone else waits. The worker runs as a transient `systemd-run` unit `specter-vod-pull-{user}-{vod}` (survives stream restarts), downloads HLS segments with `TWITCH_PULL_CONCURRENCY` (default 24) parallel connections into `twitch-{id}.mp4.part.d/` (resumable), then remuxes a local copy of the playlist with ffmpeg (no `+faststart` — it doubled remux time). Why parallel: Twitch caps each connection at ~5 MB/s; 24 connections hit ~120 MB/s on syd1 (1 Gbit link). YouTube/S3 jobs with `source=twitch_vod` sit in `pulling` while waiting and are skipped for the rest of that uploader run (no head-of-line blocking); stale sweeps exclude them. `user_s3_uploads` has `source`/`twitch_video_id` + `pulling` status; Import tab has "Send to my S3" (`send_twitch_s3`). stream.py `_resume_twitch_pulls` restarts dead workers on boot. Playback URL: Twitch GQL `videoPlaybackAccessToken` + Usher, using the streamer's `users.access_token` when present (subscriber-only VODs).

**Video length:** every finished MP4 gets `duration_seconds` in its `<stem>.json` sidecar (merged via `ffmpeg_jobs.update_media_meta` — never overwrite sidecars). Probed after FLV→MP4, after a pull, or lazily by `/api/me/recordings` (3 per request); `vod_extensions.duration_seconds` keeps it after Extend deletes the local file.

Related: [[feedback_php_config]].
