---
name: project-twitch-tokens-admin-page
description: twitch_tokens.php auto-validate behavior + chat-token persist anchoring + UNRESOLVED chat-token root cause
metadata: 
  node_type: memory
  type: project
  originSessionId: 3a492675-c412-4a60-8bec-605585635930
---

Work on dashboard/admin/twitch_tokens.php (2026-06-28, **SHIPPED** — committed `5b8c500d` "Fix token validation error handling flow").

**Auto-validate on page load** (all 3 sections):
- Chat token: always validates on load (unchanged).
- Custom Bot Tokens: validates every row with a non-empty `data-token`, throttled 6 concurrent; token-less module rows skipped; runs in `loadTokenCache().finally` AFTER the cached paint (avoids clobber).
- View Existing User Tokens: CACHE-FIRST (user's explicit choice) — cached status shown instantly, re-check only rows with no cache or cache older than `TOKEN_CACHE_TTL_SECONDS` (600s), throttled 6. A FAILED cache GET skips the bulk re-validate (`cacheLoadOk` guard) so a backend hiccup can't trigger validate-all — that table has NO server-side row limit.
- Shared `runWithConcurrency(thunks, limit)` bounded pool; `refreshInvalidUserTokensButton()` arms "Renew Invalid" from both cached- and freshly-invalid rows.

**Chat-token renewal fixes** (`persistWebsiteChatToken()`):
- UPDATE now anchored to `WHERE id = ?` (the lowest-id row every reader loads via `ORDER BY id ASC LIMIT 1`), not an unanchored `UPDATE ... LIMIT 1`.
- READ-BACK verification BUILT: ends with `verifyPersistedChatToken()` which re-reads the row and fails with `admin_twitch_tokens_err_persist_mismatch` (new key, en/de/fr) if stored != written (catches silent VARCHAR truncation OR wrong-row write). Centralized, so ALL callers (manual renew, auto_renew_if_24h, sync_chat_expiry, renew_if_invalid) are covered. Compares trimmed-vs-trimmed.

**Auto-renew chat token on INVALID** (user request): `validateChatToken(token, allowAutoRenew=true)` sends `renew_if_invalid=1` (+ `auto_renew_if_24h=1`) on primary validations (page load / Validate button). The `validate_token` handler, on a non-200 (invalid) AND `renew_if_invalid`, mints a fresh APP token via client_credentials → persists → returns `auto_renewed`; bot picks it up via its ~60s cache. Gated so ONLY the chat path mints (user/custom token validation never does). Post-renew re-validations pass `allowAutoRenew=false` → at most ONE mint per page-load chain (no loop).

**Chat-token "invalid after refresh" is now self-diagnosing**: if it's truncation, the read-back surfaces the mismatch error; if it's multi-row, the `WHERE id` anchoring fixes it. Definitive prod check still useful: `SELECT COUNT(*) FROM bot_chat_token` + `SHOW CREATE TABLE bot_chat_token`.

**Pre-existing nits (NOT fixed, flagged to user)**: (1) no-token branch in `validateToken`/`validateCustomToken` sets statusNoToken then `Promise.reject` → `.catch` overwrites to red "Error"; now more visible via auto-validate. (2) `curl_close($ch)` missing in all 4 curl blocks (lines ~98/260/447/780). (3) stray `;;` in validateBtn JS.

Custom-bot "fresh-expiry but invalid" is NOT cross-table token invalidation (see [[reference-twitch-oauth-token-semantics]]); needs evidence from `/home/botofthespecter/logs/custom_bot_token_refresh.log` (HTTP 400s) + DB shared-refresh-lineage check. Related: [[project-php-twitch-credentials]], [[project-specter-app-token-stale-env]].
