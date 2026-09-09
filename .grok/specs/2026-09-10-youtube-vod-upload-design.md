# YouTube VOD upload — design

**Date:** 2026-09-10  
**Status:** Implemented (connect + manual queue). Auto-queue waits on the recorder coming back.

## Problem

The profile Connected Accounts card for YouTube was a “coming soon” stub. Streamers who record through Specter need a way to send those MP4s to **their own** YouTube channel. A service account cannot do that (`NoLinkedYouTubeAccount`). The token has to be user OAuth 2.0 against YouTube Data API v3, bound to the channel they pick at consent (personal or Brand Account).

Live restream to YouTube already uses a stream key on the Recording page. That is a different product. This work is **after-the-fact VOD file upload**.

## Goal

A streamer can:

1. Connect a YouTube channel they own from Profile or Integrations → YouTube.
2. Optionally choose default visibility and whether new recordings should auto-queue later.
3. From Recording, send a finished MP4 to that channel (`videos.insert`, resumable).

Disconnect revokes the Google token and deletes the stored row.

## Design decisions

1. **User OAuth, not a service account.** Web-server authorization-code flow with `access_type=offline` and `prompt=consent select_account` so Brand Accounts show up in the picker. Refresh token lives on the server only.
2. **Minimum scopes:** `youtube.readonly` (so `channels.list?mine=true` works) plus `youtube.upload`. Inspect the granted `scope` string. Readonly-only links are allowed but cannot upload until they reconnect and grant upload.
3. **One channel per Specter user for v1.** `website.youtube_tokens` is unique on `user_id`. Connecting again replaces the channel. Studio managers of someone else’s channel cannot authorize it; the owner must.
4. **Tokens in `website`, not the per-user DB.** Same pattern as Spotify / Discord. PHP reads `./config/youtube.php` (never `.env`). Python refresh/uploader reads `YOUTUBE_CLIENT_ID` / `YOUTUBE_CLIENT_SECRET` from the bot host env.
5. **Default visibility is private.** YouTube forces private uploads on unaudited projects created after 28 July 2020. The UI says so. Users can still pick unlisted/public; YouTube may ignore that until the API Services audit lands. That audit is separate from Google OAuth verification.
6. **Manual upload first.** Recording is currently disabled. The Recording page still grows an “Upload to YouTube” action for when files exist. Auto-queue is stored as `auto_upload` but does **not** backfill every historical MP4 on page load (that would blow the 100/day project quota). The recorder can honour the flag when it is back.
7. **Upload worker on the stream server.** `stream/youtube_vod_uploader.py` reads the same directory `stream.py` writes after FLV→MP4 (`/mnt/s3/bots-stream/{username}/` on US/EU; script dir on Sydney unless overridden). Not the bot host and not twitch-recorder `/mnt/blockstorage`. After a successful convert, `stream.py` queues the job if `auto_upload` is on. Cron the uploader on each stream host.
8. **Twitch VOD import.** From Videos, the streamer can send an archive or highlight. The stream server resolves Twitch HLS (GQL playback token + Usher), FFmpeg-copies it to `twitch-{id}.mp4` in the stream files dir, then uploads that file to YouTube. Clips stay on the official Helix download URLs.
8. **Project-wide quota.** `videos.insert` is 100/day for the whole Google Cloud project, not per user. Failed quota jobs are marked failed so we do not retry every minute.

## Data

`website.youtube_tokens`: user_id, channel_id, title/custom URL/thumbnail, access + refresh tokens, expiry, granted_scopes, can_upload, auto_upload, privacy_status, needs_reauth.

`website.youtube_vod_uploads`: user_id, filename, title, privacy_status, youtube_video_id, status (`queued` / `uploading` / `done` / `failed` / `cancelled`), error_message.

Migration: `./migrations/website/20260910_0001_youtube_vod_oauth.php`.

## Surfaces

- `dashboard/youtubelink.php` — OAuth start/callback, channel card, settings, recent jobs.
- Profile Connected Accounts — Connect / Disconnect, same as Spotify.
- Integrations menu — YouTube.
- Recording — unchanged (Twitch recorder / live forward keys only). Stream MP4s are the VOD source.
- Admin token refresh + token logs.
- User data export includes both tables.

## Google Cloud (operator)

External OAuth client, YouTube Data API v3 enabled, redirect URI exactly `https://dashboard.botofthespecter.com/youtubelink.php`. Fill `config/youtube.php` on the web host and the matching env vars on the bot/recorder host. While the Cloud project is in Testing, only allowlisted Google accounts can finish consent. Sensitive YouTube scopes need OAuth verification for production; the upload-private restriction needs the separate YouTube API Services audit.

## What this is not

- Not live forwarding (that remains the YouTube stream key on Recording).
- Not downloading Twitch Helix VODs to re-upload.
- Not CMS `onBehalfOfContentOwner` / `youtubepartner`.
- Not encrypting tokens at rest beyond how Spotify already stores them.
