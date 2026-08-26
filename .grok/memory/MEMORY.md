## Project Memory Index - BotOfTheSpecter

This directory is the **project AI knowledge base** (under `./.grok/memory/`). Grok should open this index for non-trivial work, then open the linked notes. Auto-loaded hard rules live in `./.grok/rules/` (not here). Plans/specs: `./.grok/plans/`, `./.grok/specs/`.

### Architecture (system docs)

- [BOT System](system_bot.md) - Twitch bot (stable/beta/v6): commands, **cooldowns**, **Working & Study tasks/timers**, EventSub, integrations, **bots-api process control**
- [API Server](system_api.md) - FastAPI data backbone: dual-auth, per-user DBs, webhooks, extension endpoints, **`/bot/*` → bots host**
- [WebSocket Server](system_websocket.md) - Real-time hub: 8 handlers, `TASK_*` / **`USER_POMO_*`**, **`OVERLAY_REFRESH`**, broadcasters
- [Secondary Systems](system_secondary.md) - Dashboard (**alerts**, builtin cooldowns), **~28 overlays**, RTMPS stream, Twitch extension, YourLinks, **bot control via bots API**

### Bot process control (quick pointer)

- **Private bots API** on bot host: `https://bots.botofthespecter.com` → `./bot/bots_api/`
- **Auth**: `website.admin_api_keys` service **`bots`** (not user API keys)
- **Dashboard**: `./dashboard/includes/bots_api_client.php` + `./config/bots_api.php`
- **Do not use SSH** for start/stop/status — see `.grok/rules/bots-api.md`
- **Username rename**: login stops old `-channel` via `bots_api_stop_all_for_channel` then renames per-user DB

### Working preferences (from prior AI sessions)

- [PHP config rule](feedback_php_config.md) — PHP never uses .env; always `./config/{service}.php`, one file per service
- [Never commit/push/branch](feedback_no_commits.md) — leave ALL git to the user; never commit (even via subagents), never create branches, never push; leave changes uncommitted
- [No co-author trailer](feedback_no_coauthor_trailer.md) — when explicitly asked to commit, never add a Co-Authored-By trailer
- [Plans go in .grok not docs](feedback_plans_in_dot_grok.md) — design specs → `.grok/specs/`, plans → `.grok/plans/`; NEVER `docs/` (that's public version docs)
- [Specs/plans authored voice](feedback_specs_plans_authored_voice.md) — `.grok` specs/plans read as English planning docs; no agentic-worker headers, pasted code, line numbers, or self-review meta
- [Always info before fix](feedback_always_info_before_fix.md) — when walking a task list, present info on every fix before editing, even trivial one-liners
- [Answer questions, don't edit](feedback_answer_questions_dont_edit.md) — a question gets a text answer only; don't make unsolicited code/comment edits
- [Diagnose regressions, don't defend](feedback_diagnose_regression_over_defending.md) — on a reported regression, get the visual symptom early and fix the root cause
- [Model routing for agents](feedback_model_routing.md) — prefer workflows/multi-agent; route subagents by task weight
- [Bot builtin command registration](feedback_bot_builtin_command_registration.md) — set registration + DB-config gating + builtin_commands.json
- [Bot file function placement](feedback_bot_file_function_placement.md) — helpers below the "# Functions for all the commands" marker as module-level funcs
- [Dashboard page menu registration](feedback_dashboard_page_menu_registration.md) — new pages in menu.php with t() lang key (en + de/fr)
- [Dashboard CSS in stylesheet](feedback_dashboard_css_in_stylesheet.md) — styles in dashboard.css with theme tokens, not inline

### Feature / ops knowledge

- [Stable version bump](project_stable_version_bump.md) — when bot.py (stable) gets a fix, bump version in 5 places
- [Specter app token stale env](project_specter_app_token_stale_env.md) — chat-send paths must read app token from `bot_chat_token` DB, not stale env
- [Websocket wildcard is intentional](project_websocket_wildcard.md) — the `*` catch-all in websocket/server.py is deliberate
- [Custom inbound webhooks](project_custom_inbound_webhooks.md) — admin-defined `/webhook/{slug}`; /notify authenticates `code`
- [Network architecture](project_network_architecture.md) — Cloudflare DNS-only; XFF/X-Real-IP are attacker-controlled
- [Per-user schema](project_per_user_schema.md) — `dashboard/usr_database.php` `$tables` is the central per-user schema manager
- [Bot→WebSocket signaling](project_bot_websocket_signaling.md) — websocket_notice /notify is whitelisted; use specterSocket.emit for new events
- [Unified alerts system](project_unified_alerts.md) — alerts.php + overlay/index.php; weather/deaths/walkons folded in
- [Media player song-request hub](project_media_player_song_request_hub.md) — media_player.php + spotify overlay (polls, not WebSocket)
- [yourchat color-scheme gotcha](project_yourchat_color_scheme_gotcha.md) — yourchat :root is color-scheme:dark; white panels need explicit colors
- [Dashboard i18n remediation](project_dashboard_i18n_remediation.md) — wrap dashboard PHP + de/fr; sequential complete-job agents
- [Working & Study expansion build](project_working_study_expansion_build.md) — all 7 phases SHIPPED; pomo_ticker is server-side single-writer
- [Closed Captions feature](project_closed_captions_feature.md) — SHIPPED; Web Speech STT + display overlay + YAMNet tags
- [OpenAI model config](project_openai_model_config.md) — per-file OPENAI_MODEL constant; default gpt-5.4-mini
- [WebSocket reconnection](project_websocket_reconnection.md) — one reconnection authority; never reconnection=False on Discord
- [Dashboard rebuild](project_dashboard_rebuild.md) — SHIPPED operational dashboard.php
- [DB migrations admin page (Phase 3)](project_db_migrations_admin_page.md) — future admin migrations.php
- [help folder removed](project_help_folder_deprecated.md) — `./help` deleted 2026-08-26; docs are `./support`
- [PHP Twitch credentials](project_php_twitch_credentials.md) — client ID from `config/twitch.php`, not DB
- [Media upload dir perms](project_media_upload_dir_perms.md) — "Could not save" usually means /var/www/media/<user> perms
- [Admin Caddy control page](project_admin_caddy_page.md) — SHIPPED; localhost:2019 control plane
- [Caddy CF token env](project_caddy_cf_token_env.md) — CF token in caddy.env; restart not reload when env changes
- [Caddyfile deploy path](project_caddy_deploy_path.md) — live file is /etc/caddy/Caddyfile, separate from repo copy
- [MEGA S4 public serving](project_megas4_public_serving.md) — durable hosts via Caddy reverse_proxy to S4 public-token URL; PHP I/O is rclone FUSE; TTS local disk
- [Twitch OAuth token semantics](reference_twitch_oauth_token_semantics.md) — refresh does not invalidate prior access token
- [Custom command aliases (BETA)](project_custom_command_aliases.md) — SHIPPED; aliases CSV + FIND_IN_SET in beta/v6
- [Point Store](project_point_store.md) — SHIPPED; bot points loyalty store + STORE websocket
- [Social overlay roller](project_social_overlay_roller.md) — SHIPPED; social-roller overlay
- [Twitch tokens admin page](project_twitch_tokens_admin_page.md) — auto-validates chat/custom/user token sections
- [Word Replacer feature](project_word_replace_feature.md) — SHIPPED; random syllable-swap chat module (beta-only)
- [Cloudflare zone owner](project_cloudflare_zone_owner.md) — botofthespecter.com zone on LochStudios CF account
- [Credential logging](project_credential_logging.md) — never log raw user code/api_key
- [rclone storage + admin file manager](project_s3fs_storage_and_file_manager.md) — MEGA S4 rclone mounts for 6 durable dirs (not TTS) + admin CDN file manager; s3fs retired
- [V6 module host parity](project_v6_module_host_parity.md) — BETA→V6 custom channel module host call sites (websocket_notice intercept, CP, chat, Stream Bingo)
- [Pet starter packs](project_pet_templates.md) — CDN Specter, Specter Bot, cat, dog, bat, alien, squirrel, chicken, cow, duck, bunny; 128×128, 30 frames, 15 FPS; `template:` sprite tokens

**Last verified**: 2026-08-25 (hot-media cache live; pet starters Specter + Specter Bot on CDN)
