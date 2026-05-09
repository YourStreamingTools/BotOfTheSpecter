# Overlay Rules

Overlays are PHP pages loaded as **OBS browser sources**. They have constraints normal web pages don't.

## Current overlay set (20 files in `./overlay/`)

`all.php` (master / recommended), `index.php`, `alert.php`, `sound-alert.php`, `tts.php`, `music.php`, `walkons.php`, `chat.php`, `video-alert.php`, `deaths.php`, `weather.php`, `discord.php`, `credits.php`, `subathon.php`, `todolist.php`, `twitch.php`, `working-or-study.php`, `patreon.php`, `kofi.php`, `fourthwall.php`.

## Rules

1. **Overlays are stateless.** All preferences are fetched on page load from the user's DB via the API key in the URL (`?code=API_KEY`). Don't add server-side session state.
2. **All real-time data comes from the WebSocket.** Connect via Socket.io 4.8.3 to `wss://websocket.botofthespecter.com`, register with the user's code, and listen for events. Don't poll the API for live updates.
3. **Audio and visual events queue.** Multiple alerts firing at once must play sequentially, not on top of each other. Use the existing queue logic — don't add a parallel one.
4. **Auto-reconnect on WebSocket drop.** OBS browser sources stay open for hours; a dropped connection that doesn't reconnect leaves the overlay silently dead.
5. **Default volume for TTS is 30%.** Don't change this without an explicit user request — louder defaults blow out streamer ears.
6. **Respect the streamer's timezone.** Time-related displays (timers, schedules, clocks) read timezone from the user DB.
7. **Test resolution-independence.** Overlays render at any size; don't hardcode pixel dimensions that break at 1080p, 1440p, or 4K browser sources.
8. **Don't add new overlays casually.** Most "new overlay" requests are better served as a configuration option on `all.php`. Confirm with the user before creating a new file.
9. **API key in the URL is the authentication.** No login flow, no cookies, no OAuth on overlay pages — they need to load instantly inside OBS.
