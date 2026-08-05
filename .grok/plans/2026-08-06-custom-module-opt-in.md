# Custom Channel Module Opt-In — Implementation Plan

**Goal:** give streamers with an operator-deployed channel module a dashboard toggle so the bot only loads that module when they turn it on. Channels without a module file never see the control; disabled channels never import the file.

**Approach in one paragraph:** the bots host already holds private packages under `custom_channel_modules/{twitch_login}.py`. The private bots API reports whether that file exists for a channel. The streamer’s preference lives as `users.use_custom_module` in the central website database (default off). On start, only when both the preference and the file are present does the control plane pass `-load-custom-module` into beta or v6; those processes then import **only** that channel’s package and instantiate classes that implement `claims_channel`. This is separate from beta’s **Custom Bot Name** / **Use Self** modes (`use_custom` / `use_self` / `-custom` / `-self`).

**Touch points:** bots API (process control), beta and v6 bot loaders, website DB column + dashboard Bot page, admin start bots, public API `/bot/start`.

## Constraints to honor

- **Bot version policy.** Change `./bot/beta.py` and `./bot/beta-v6.py` only. Do **not** touch stable `./bot/bot.py`. Do **not** bump frozen version strings (`5.8`, `6.0.0`) or `api/versions.json` beta/v6 fields. Day-to-day beta notes go in `./docs/5.8.md` (append), not a new public version doc.
- **Bots API for lifecycle.** Start/stop/status stay on `https://bots.botofthespecter.com` (`./bot/bots_api/`). No SSH process control. File existence is checked **on the bot host** next to the running scripts.
- **Paths.** Repo package path is `./bot/custom_channel_modules/`. Runtime (server): under bot home, default `/home/botofthespecter/custom_channel_modules/{channel}.py` (override via env if needed). Always label server-only paths as such in docs and rules.
- **PHP config.** Dashboard never reads `.env`; bots control key stays in `./config/bots_api.php` + DB admin key (`service=bots`).
- **Database.** New flag is on central `website.users`, not a per-user streamer DB. Parameterized queries only.
- **Naming collision.** Keep `use_custom` for custom **bot account**. Use `use_custom_module` for this feature, `custom_module_available` for the file probe, `load_custom_module` on the start body, CLI `-load-custom-module`.
- **Deploy model.** Operator uploads files via SFTP and restarts services as needed. Do not document `git pull` (or any git) as a deploy step. Changelog / version docs are separate from this plan.

## Current problem

Today beta and v6 hard-import a fixed list of channel modules at process start. Ready only *instantiates* modules that claim the current channel, but every channel still pays the import cost and cannot opt out of a module the operator already placed on disk. New channels need a bot code edit to appear in that import list.

## Target flow

```text
Bot page status poll
  → bots API: custom_module_available (file on host?)
  → show Custom Module toggle only if true (beta/v6)

Toggle save
  → website.users.use_custom_module (0/1)

Bot start (dashboard / admin / public API)
  → load_custom_module from DB/session
  → bots API: if flag and file → argv includes -load-custom-module

beta / beta-v6
  → flag set: import only custom_channel_modules.{channel}
  → flag clear: no channel-module package import; empty active list
```

## Work items

### 1. Bots API — availability on status

In `./bot/bots_api/manager.py`, resolve modules under bot home’s `custom_channel_modules` directory (env override allowed). Accept only safe Twitch login segments (`[a-z0-9_]+`) so a channel name cannot escape the directory. Add `custom_module_available` to the existing `GET /api/bot/status` payload. No separate endpoint is required if the dashboard already polls status.

### 2. Bots API — start/restart CLI flag

Extend the start body with `load_custom_module` (default false). When starting **beta** or **v6**, if that flag is true **and** the file exists, append `-load-custom-module` to the process argv. If the flag is true but the file is missing, start the bot without the CLI flag and log a warning (do not fail the start). Custom bot name / self remain beta-only and independent of this flag.

Wire the field through `./bot/bots_api/server.py` start and restart handlers.

### 3. Website column and migration

Add `use_custom_module TINYINT(1) NOT NULL DEFAULT 0` on `website.users` via a website migration under `./migrations/website/` (same pattern as other column adds). Default **off** is intentional: after go-live, channels that already have a module (e.g. gfaundead) must enable once and restart. Optional one-time SQL for known logins is an ops choice, not automatic enable-for-all.

### 4. Dashboard preference + Bot page UI

- Load the column in userdata / session.
- New save endpoint dedicated to this flag (do not overload `update_use_custom.php`, which owns custom bot name / use self).
- On `./dashboard/bot.php`, show a **Custom Module** switch only when status reports the file present and the selected track is beta or v6. Persist immediately; warn that a **restart** is required for the next start to pick up the flag.
- Status helper surfaces `custom_module_available` from the bots API into the status JSON the page already polls.
- i18n: English base plus the other dashboard languages used for bot strings.
- `performBotAction` and `bot_action.php` pass `load_custom_module` on start for beta/v6 from session (and optional POST for live checkbox state).

### 5. Beta and v6 loaders

Add CLI `-load-custom-module`. When absent, do not import other streamers’ channel packages; keep the active module list empty.

When present: sanitize the channel login, import only `custom_channel_modules.{channel}`, discover classes that implement `claims_channel`, instantiate those that claim this channel, keep existing dispatch paths for events/commands once instances exist. Failures are non-fatal (log and continue without a module).

Remove the hard-coded multi-channel import list. Package-level helpers that previously assumed a fixed import (e.g. hedgehog ready/bureau hooks on v6) must only run against the **opt-in loaded package**, never a static import of another channel. Platform **bot-home** helpers in `botofthespecter.py` (home-channel AI) are not the same product as per-streamer modules; soft-load them only when needed on the home channel.

### 6. Admin start and public API

Admin start/restart (`./dashboard/admin/start_bots.php`) and public API bot start (`./api/api.py`) must read `use_custom_module` from the user row and pass `load_custom_module` on the bots API start body the same way the dashboard does. Act-as-user session restore should not drop the preference when admins switch context.

### 7. Operator docs

- Update `./bot/custom_channel_modules/README.md`: opt-in, filename = Twitch login, enable on Bot page, restart required.
- Update `.grok/rules/bots-api.md` for the new status field and start body field (label server paths).
- Append a short note to `./docs/5.8.md` for the beta track (feature exists; not a version bump).

## Security / isolation

- Filename equals channel login only; reject path characters.
- Modules stay operator-deployed on the bot host (no dashboard upload in this work).
- File on disk alone never loads; preference + CLI gate required.
- `claims_channel` remains a second gate after import.
- One process must not import another channel’s `.py`.

## Out of scope

- Stable `bot.py` module loading
- Streamer self-upload of Python modules
- Hot-reload without restart
- Changing custom bot name / use-self behaviour
- Auto-enabling the flag for every existing module channel

## How we’ll know it works

- No module file → status false, no toggle, start argv has no load flag, process never logs a channel-module load.
- File present, preference off → toggle visible unchecked, no load flag on start.
- File present, preference on → start includes `-load-custom-module`, ready log shows load for that channel only; module commands/events behave as before for that channel.
- Another channel’s process does not import that file.
- Missing file with preference on still starts; no crash.
- Preference survives page refresh; only a restart applies it to a running bot.
- Admin start and public API start honor the same preference.

## Deploy / ops (server side only)

Order of operations on the hosts:

1. Apply the website migration so `use_custom_module` exists before admin start queries that column.
2. Deploy and restart the bots API service so status/start understand the new fields.
3. Deploy beta and v6 bot scripts (SFTP as usual).
4. Deploy dashboard + public API code.
5. For channels that already rely on a module: enable **Custom Module** on the Bot page and restart that bot (or set the column for known logins, then restart).

Do not treat VCS sync as the deploy path; file placement and service restarts on the hosts are the gate.

## Implementation status

The work described above is reflected in the current working tree (bots API, beta/v6 loaders, migration, dashboard, admin, public API, README, bots-api rule). Prefer reviewing the diff as uncommitted changes; release is operator-controlled when ready.

Still worth a pass before ship: append `./docs/5.8.md` beta note if not already done; smoke-test status + start on a channel that has a module file and one that does not; confirm gfaundead (or another known module) after enable + restart.
