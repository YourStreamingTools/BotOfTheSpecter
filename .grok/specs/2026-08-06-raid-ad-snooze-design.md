# Raid Ad Snooze — Design Spec

**Date:** 2026-08-06  
**Scope:** beta Twitch bots (`bot/beta.py`, `bot/beta-v6.py`), Modules → Ad Notices, per-user `ad_notice_settings`, modules load/save handlers, and dashboard language files (`en`, `de`, `fr`, `es`, `zh`). Stable `bot/bot.py` is intentionally left alone.  
**Status:** Implemented on the beta track (dashboard + beta/v6).

## Problem

When someone raids into a channel, raiders often land during or just before a scheduled mid-roll. That dumps new viewers into an ad break and kills the welcome moment. Streamers can already snooze the next ad from Twitch’s Ads Manager (or the Helix “Snooze Next Ad” endpoint), but nothing ties that action to an incoming raid automatically.

The bot already:

- Handles incoming raids via EventSub and `process_raid_event`
- Polls `GET /helix/channels/ads` for `next_ad_at` and `snooze_count`
- Detects when `snooze_count` drops (manual dashboard snooze) and can chat `ad_snoozed_message`
- Authenticates users with OAuth scopes that already include `channel:manage:ads` and `channel:read:ads`

What it did **not** do is call  
`POST /helix/channels/ads/schedule/snooze` when a raid arrives and the next ad is imminent.

## Goal

After an **incoming** raid, if the next scheduled automatic mid-roll is within a configurable window (default 10 minutes) and the broadcaster still has Twitch snoozes left, the beta/v6 bot uses Helix once to push that ad back by about five minutes. Streamers can turn the feature off, tune the window, and use a **separate** chat message for raid-triggered snoozes (distinct from the generic “ads were snoozed” notice).

## Design decisions

1. **Opt-out toggle, default on.** `enable_raid_ad_snooze` on `ad_notice_settings`, default `1`. Existing channels get the feature without a dashboard visit; anyone who dislikes it can disable it under Ad Notices.
2. **One Helix snooze per qualifying raid.** Twitch only pushes the next ad by about five minutes per call. We deliberately do **not** loop until the ad is outside the window, so we conserve the limited snooze pool.
3. **Any incoming raid.** No minimum viewer threshold for v1. Outgoing raids do not trigger snooze — the stream is ending or leaving, and Twitch requires the channel to be live with an upcoming scheduled ad.
4. **Configurable window, default 10 minutes.** Stored as `raid_ad_snooze_window_minutes`. Clamped to **1–30** on save and when the bot reads settings.
5. **Separate raid-snooze chat copy.** Generic `ad_snoozed_message` stays for manual or external snoozes detected by the poller. Raid auto-snooze uses its own message and enable flag.
6. **Raid-time evaluation only (with one transient retry).** On the raid event: load settings → GET ad schedule → if `next_ad_at` is within the window and `snooze_count ≥ 1`, POST snooze once. No long-lived protection window in the ad poll loop. Transient network or 5xx failures may retry once after a short delay; still at most one successful snooze per raid.
7. **Beta + v6 only.** Stable is critical-fixes only.
8. **Avoid double chat on bot-initiated snooze.** After a bot raid-snooze succeeds, the poller must not also fire the generic snoozed chat for that same drop (short-lived “bot initiated this snooze” marker).

## Twitch API contract (relevant bits)

**Read schedule** (already used):

- `GET https://api.twitch.tv/helix/channels/ads?broadcaster_id={id}`
- Auth: broadcaster user token with `channel:read:ads`
- Useful fields: `next_ad_at`, `snooze_count`, `duration`, `last_ad_at`

**Snooze next ad** (new call):

- `POST https://api.twitch.tv/helix/channels/ads/schedule/snooze?broadcaster_id={id}`
- Auth: broadcaster user token with `channel:manage:ads`
- Success **200**: updated `snooze_count`, `snooze_refresh_at`, `next_ad_at`
- **400**: not live, invalid broadcaster, or no upcoming scheduled ad
- **429**: no snoozes left
- Each success moves the next ad roughly five minutes later and decrements `snooze_count`

Both calls reuse the existing broadcaster token (`CHANNEL_AUTH`). No new OAuth scope or app-token path.

## Data model

Extend per-user `ad_notice_settings` (schema manager in `dashboard/includes/usr_database.php`, plus column migrations and the default-row seed for that table):

| Column | Type / default | Purpose |
|--------|----------------|---------|
| `enable_raid_ad_snooze` | TINYINT(1) default **1** | Master feature toggle |
| `raid_ad_snooze_window_minutes` | INT default **10** | Next ad within N minutes of raid |
| `enable_raid_ad_snooze_message` | TINYINT(1) default **1** | Whether to chat after a successful raid snooze |
| `raid_ad_snooze_message` | VARCHAR(255) | Chat template for raid auto-snooze |

Default message (English storage default):  
`Snoozed the next ad for the raid from (user).`

Variables for the raid snooze message:

- `(user)` — raider name (same sense as raid alert)
- `(viewers)` — raid viewer count
- `(minutes)` — whole minutes remaining until the **pre-snooze** next ad (floor of seconds/60, minimum 1 if still within the window). Documented in Ad Notices help so it is not confused with the configured window length.

`get_ad_settings()` in beta and beta-v6 loads and caches these fields with the rest of the ad-notice settings, with sensible defaults if a column or row is missing.

## Runtime flow

Hook sits at the end of successful **incoming** raid handling in `process_raid_event`. Helix work is fire-and-forget so an ads outage cannot stall points or the raid welcome chat.

1. Read cached ad settings; if `enable_raid_ad_snooze` is off, stop.
2. If the bot does not believe the stream is live, stop.
3. Clamp window to 1–30 minutes.
4. GET ad schedule with the broadcaster token.
5. Parse `next_ad_at` with the same normalizer the ads poller uses (Unix seconds in practice).
6. If no upcoming ad, or `snooze_count < 1`, or time until next ad is not in `(0, window_seconds]`, stop and debug-log why.
7. POST snooze once.
8. On **200**: mark bot-initiated snooze for the poller; log new `next_ad_at` and remaining snoozes; if the raid message is enabled and non-empty, send chat after variable substitution. The API call does not require the global `enable_ad_notice` switch. Raid snooze chat is gated only by `enable_raid_ad_snooze_message`.
9. On **429 / 400 / other**: log with status and a short body snippet; no chat spam.
10. Transient failure: one delayed retry of the whole path; never more than one successful snooze for that raid.

## Interaction with existing ad poller

`check_and_handle_ads` already sends upcoming / one-minute notices and detects `snooze_count` decreases for the generic snoozed message. After a bot raid-snooze:

- Notices keyed on the old `next_ad_at` naturally stop applying once the schedule advances.
- The generic snoozed message is suppressed while the bot-initiated marker is active.

## Dashboard (Modules → Ad Notices)

Under the existing ad notice messages card, a **Raid auto-snooze** subsection:

- Toggle: enable auto-snooze after raids (default on)
- Number input: window in minutes (default 10, min 1, max 30)
- Toggle + textarea: raid snooze chat message (max 255, same character counter pattern as other ad messages)
- Help text: one Twitch charge, ~5 minute push, live + scheduled ad + remaining snoozes required

Load/save uses the same modules ad-notice path (`module_data_post.php` and the modules form). All UI strings go through `t()` with keys added to every dashboard language pack that already carries `modules_ad_*` — **English, German, French, Spanish, and Chinese**.

## Logging

Use existing ads / api logger tags for skip reasons, successful snooze (old/new next ad, remaining count, raider), and Helix failures (status + safe body excerpt; never full tokens).

## Out of scope

- Stable bot (`bot.py`)
- Outgoing raids
- Multiple snoozes per raid or “keep ad outside the window”
- Minimum raid viewer threshold
- A chat command like `!snooze`
- Changing Twitch OAuth scope lists (already sufficient)
- Auto-snooze on other high-engagement events (hype train, etc.)

## Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Streamer runs out of snoozes mid-session | Single snooze per raid; clear logs on 429 |
| Double chat (raid message + generic snoozed) | Bot-initiated snooze marker for the poller |
| Token missing `channel:manage:ads` (very old login) | Log and skip; no crash |
| Raid handling blocked on Helix latency | Async task; welcome/points path continues |
| Schema not migrated yet on a user DB | Code defaults + schema manager migration on dashboard load |

## Success criteria

- With feature on, live channel, next ad within the window, and snoozes remaining, an incoming raid results in exactly one successful Helix snooze and (if chat enabled) one raid-specific chat line.
- With feature off, next ad outside the window, or zero snoozes, no successful auto-snooze.
- Manual snoozes still produce the generic snoozed chat when that notice is enabled.
- Beta and v6 behave the same; stable is unchanged.
- Ad Notices can toggle and save all four settings; defaults match the table above after migration.
- Raid auto-snooze labels and help render correctly in en, de, fr, es, and zh.
