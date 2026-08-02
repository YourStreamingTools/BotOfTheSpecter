---
name: project_dashboard_i18n_remediation
description: Dashboard i18n remediation COMPLETE (uncommitted) — the working method + gotchas, kept for the next i18n pass
metadata: 
  node_type: memory
  type: project
  originSessionId: d4535b42-54e1-402f-9d82-0ce83a3c4802
---

**STATUS: COMPLETE (uncommitted, ~2026-06-04).** Internationalized ALL dashboard PHP pages **including admin** with full **German + French** translations. Result: **88 files changed, 2307 new keys × en/de/fr (balanced), all `php -l` clean, no new duplicate keys.** Done across 9 sequential workflow batches; several latent bugs fixed en route (e.g. `media.php` upload-success used `strpos` on now-translated text → boolean flag; `bot.php` toast de-dup `.includes()` → marker keys; a few `$pageTitle`-before-i18n-include orderings; `login.php`/various `api/` endpoints had no i18n loader → guarded include added). `menu.php` was already done. Left uncommitted for the user. Method/gotchas below kept for the next i18n pass.

**POST-FIX (500 on dashboard.php):** a `t()` call must NEVER run before the page's i18n loader. `dashboard.php` set `$pageTitle = t(...)` at line 17, but i18n only loads at line 22 via `include 'userdata.php'` (which includes `lang/i18n.php` + sets `$userLanguage`) → `Call to undefined function t()` → HTTP 500 (php -l does NOT catch this — it's runtime). Fixed by moving the assignment after the userdata include. Note: `lib/require_auth.php` / `require_auth_ajax.php` do NOT load i18n; `userdata.php` and `layout.php` (line ~14) do. After fixing, I scanned every changed page (first `t()` line vs first i18n-loader line) — dashboard.php was the only offender. **Always run that "no t() before its loader" scan after an i18n pass.**

**CLEANUP DONE:** the pre-existing `music_repeat_one` duplicate key was removed (kept `'Repeat current song'`, the value `music.php` actually uses), and the ~58 pre-existing en-only keys were backfilled into de/fr — all three lang files are now at **full parity (5545 keys each)**, php -l clean, zero duplicate keys. (Uncommitted.)

Original task (user chose MAXIMAL scope): internationalize ALL dashboard PHP pages **including admin**, with full **German + French** translations, per the worklist in `.grok/plans/dashboard-i18n-audit.md` (~95 files, 827+ findings).

**t() system** (`dashboard/lang/i18n.php`): `en.php` is the base; `de.php`/`fr.php` are `array_merge`d over it, so a key present in en.php but missing from de/fr **falls back to the English text** (never a raw key). So the en.php key is mandatory; de/fr are the user's explicit ask here.

**Working method** (validated): one "complete-job" agent **per file** that wraps the still-hardcoded user-facing strings in `t()` AND appends the new keys to en/de/fr AND runs `php -l` on all four files. Run these agents **SEQUENTIALLY** (a workflow `for` loop, NOT `parallel`/`pipeline`) — the three lang files are shared, so concurrent writers corrupt/conflict. Use a flat status schema. **Do NOT** use the structured wrap→lang handoff (separate wrap agents returning a nested `entries` array) — those agents repeatedly errored to null.

**Gotchas that wasted runs:**
1. **Workflow `args` did not reach the script** — the file list came through empty (`Array.isArray(args)` false → 0 agents). EMBED the file list as a `const` literal in the script instead (the light-mode and audit workflows worked because they embedded). 
2. The audit lists **basenames only**; many pages live in subfolders (`dashboard/todolist/`, `dashboard/api/`). Resolve real paths (Glob) before each batch.
3. **French apostrophes** in single-quoted PHP values are the fatal-parse landmine (`L\'association`) — escape every one and `php -l`-gate every batch. PHP 8.1 CLI is available locally for linting.

**Notes:** pre-existing duplicate key `music_repeat_one` exists in all 3 lang files (two different values; not from this work). All i18n changes are uncommitted. See [[feedback_dashboard_page_menu_registration]] for the en+de+fr lang convention.
