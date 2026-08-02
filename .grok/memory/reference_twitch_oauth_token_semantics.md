---
name: reference-twitch-oauth-token-semantics
description: Twitch OAuth token lifetimes & invalidation rules — refresh does NOT kill prior access tokens
metadata: 
  node_type: memory
  type: reference
  originSessionId: 3a492675-c412-4a60-8bec-605585635930
---

Verified against Twitch dev docs (2026-06-28) while debugging dashboard/admin/twitch_tokens.php.

- **User access tokens** ~4h (14400s). **App access tokens** (client_credentials) ~60 days, no refresh_token (just request a new one).
- **Exchanging a refresh token does NOT invalidate the grant's previous access token.** Multiple access tokens per refresh token coexist until natural expiry (docs cite ~50 per refresh token). My earlier assumption that "a second refresh kills the first token" was WRONG — do not build logic on it.
- **Refresh tokens DO rotate**: a refresh may return a new refresh_token and invalidate the old (confidential clients / device-code one-time-use). Two processes consuming the same refresh_token → one gets HTTP 400.
- **Separate authorizations of the same user+client coexist** as independent grants; a new auth does not revoke prior tokens. Undocumented per-client/user pool (~25) before the oldest rolls off. Minting a new app token also does not invalidate old ones.

Implication: a freshly-refreshed token reading "invalid" is NOT caused by a sibling refresh — suspect out-of-band revocation (password reset / app disconnect / wrong client_id) or a read/row/column mismatch. See [[project-twitch-tokens-admin-page]], [[project-php-twitch-credentials]].
