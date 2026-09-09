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
| OAuth + settings | `./dashboard/youtubelink.php` |
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

**Twitch VOD import:** any stream host can pull (Twitch CDN). Job `source=twitch_vod`. Worker claims `pulling`, FFmpeg copies HLS to MP4 (`-c copy`), then uploads. Playback URL comes from Twitch GQL `videoPlaybackAccessToken` + Usher, using the streamer's `users.access_token` when present (subscriber-only VODs). Only the channel's own archive/highlight IDs can be queued.

Related: [[feedback_php_config]].
