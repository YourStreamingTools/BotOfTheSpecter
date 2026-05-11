# pronouns.alejo.io — Complete API Reference

Free public API that maps Twitch usernames to chat pronoun preferences. Users set their pronouns at `https://pr.alejo.io/` (the canonical URL — `pronouns.alejo.io` is a redirect alias). No account or key is required to read data.

The bot uses this API in `./bot/beta.py` to resolve the `(pronouns)`, `(pronouns.they)`, and `(pronouns.them)` placeholders in command responses and event alerts.

---

## Authentication

**None.** The API is fully public. No API key, `Authorization` header, cookie, or OAuth flow is required for any endpoint.

---

## Base URL

```
https://api.pronouns.alejo.io
```

All paths below are relative to this base. HTTPS only; HTTP is not served.

---

## GET /v1/pronouns

Returns the complete catalog of supported pronoun sets. Use this to resolve a `pronoun_id` or `alt_pronoun_id` into human-readable strings.

### Request

```
GET https://api.pronouns.alejo.io/v1/pronouns
```

No query parameters. No request body.

### Response — 200 OK

`Content-Type: application/json`

A flat JSON object keyed by `pronoun_id` string. Each value is a pronoun entry object:

```
{
  "<pronoun_id>": {
    "name":     string,   // Same as the key; the stable ID used in user records
    "subject":  string,   // Subject pronoun, title-cased  — e.g. "She", "They"
    "object":   string,   // Object pronoun, title-cased   — e.g. "Her", "Them"
    "singular": boolean   // true  → grammatically singular ("any", "other")
                          // false → standard plural or neopronouns
  },
  ...
}
```

### Full catalog as of 2026-05-11

The catalog has been stable for several years. New entries may appear without notice; always look up IDs at runtime rather than hardcoding them.

| pronoun_id | subject | object | singular |
| ---------- | ------- | ------ | -------- |
| `aeaer`    | Ae      | Aer    | false    |
| `any`      | Any     | Any    | true     |
| `eem`      | E       | Em     | false    |
| `faefaer`  | Fae     | Faer   | false    |
| `hehim`    | He      | Him    | false    |
| `itits`    | It      | Its    | false    |
| `other`    | Other   | Other  | true     |
| `perper`   | Per     | Per    | false    |
| `sheher`   | She     | Her    | false    |
| `theythem` | They    | Them   | false    |
| `vever`    | Ve      | Ver    | false    |
| `xexem`    | Xe      | Xem    | false    |
| `ziehir`   | Zie     | Hir    | false    |

13 entries total at last verification.

### Display string conventions

- **Standard** (no `alt_pronoun_id`): `{subject}/{object}` — e.g. `She/Her`, `They/Them`
- **With alt** (non-null `alt_pronoun_id`): `{subject}/{object}/{alt_subject}` — e.g. `She/Her/They`
- **Singular entries** (`singular: true`): `any` and `other` are intended as free-form labels. Display them as-is (`Any` or `Other`) rather than constructing a slash-separated string.

---

## GET /v1/users/{username}

Returns the pronoun preference for a specific Twitch user. The user must have visited `https://pr.alejo.io/` and set their preferences; there is no data for users who have never done so.

### Request

```
GET https://api.pronouns.alejo.io/v1/users/{username}
```

| Parameter  | Type   | Required | Notes |
| ---------- | ------ | -------- | ----- |
| `username` | string | yes      | Twitch login name. **Must be lowercased.** A capitalised username returns 404 even if the lowercase form has data. |

No query parameters. No request body.

### Response — 200 OK (user has pronouns set)

`Content-Type: application/json`

```json
{
  "channel":        "lokkobot",
  "pronoun_id":     "sheher",
  "alt_pronoun_id": null
}
```

| Field            | Type            | Notes |
| ---------------- | --------------- | ----- |
| `channel`        | string          | Lowercased Twitch login name, echoed back from the path parameter. |
| `pronoun_id`     | string          | Primary pronoun set. Always a key present in the `/v1/pronouns` catalog. |
| `alt_pronoun_id` | string or null  | Secondary pronoun set, used when the user selected a combination like "She/They". `null` when no secondary is set. Also absent on some older records — treat missing and `null` identically. |

### Response — 404 Not Found (no preference set)

The response body is not meaningful. A 404 means one of:

- The user has never visited `pr.alejo.io` to set pronouns.
- The username was not lowercased and does not match any stored record.
- The Twitch account does not exist.

**Treat 404 as a clean negative, not an error.** Cache it the same way a positive result is cached. The vast majority of viewers are 404s.

### Other status codes

The API does not document additional status codes. In practice:

- `5xx` — upstream service error; fall back to cached data if available.
- Timeouts — the upstream is occasionally slow; use a 5-second client timeout.

---

## Pronoun ID to display string mapping

The authoritative mapping lives in the `/v1/pronouns` response. The table below is a convenience snapshot for quick reference:

| pronoun_id | Short display | Full display |
| ---------- | ------------- | ------------ |
| `aeaer`    | Ae/Aer        | Ae/Aer       |
| `any`      | Any           | Any pronouns |
| `eem`      | E/Em          | E/Em         |
| `faefaer`  | Fae/Faer      | Fae/Faer     |
| `hehim`    | He/Him        | He/Him       |
| `itits`    | It/Its        | It/Its       |
| `other`    | Other         | Other        |
| `perper`   | Per/Per       | Per/Per      |
| `sheher`   | She/Her       | She/Her      |
| `theythem` | They/Them     | They/Them    |
| `vever`    | Ve/Ver        | Ve/Ver       |
| `xexem`    | Xe/Xem        | Xe/Xem       |
| `ziehir`   | Zie/Hir       | Zie/Hir      |

Never hardcode this mapping in code. Always resolve via the cached catalog so new entries work automatically.

---

## alt_pronoun_id usage

`alt_pronoun_id` represents a user's secondary preference (e.g., someone who uses both "she" and "they"). The display convention in BotOfTheSpecter is:

```
{primary.subject}/{primary.object}/{alt.subject}
```

Example: `pronoun_id = "sheher"`, `alt_pronoun_id = "theythem"` → `She/Her/They`

When `alt_pronoun_id` is `null` or absent, display only `{primary.subject}/{primary.object}`.

If `alt_pronoun_id` is present but not found in the current catalog (possible if the catalog is stale), fall back to the two-part display string rather than erroring.

---

## Rate limits

**Not documented.** The API is a free community service with no published rate limit. Observed behaviour:

- Single-user lookups for active chat participants work reliably without throttling.
- There is no batch endpoint; every user requires a separate HTTP call.

**Cache aggressively.** Do not call either endpoint on every chat message.

Recommended TTLs (as used in `./bot/beta.py`):

| Cache                | TTL       | Rationale |
| -------------------- | --------- | --------- |
| `/v1/pronouns` list  | 86400 s (24 h) | Catalog changes rarely; one fetch per bot process restart is sufficient. |
| `/v1/users/{name}`   | 3600 s (1 h)   | Users don't change pronouns frequently; hourly is generous. |

On fetch failure, return the previously cached value (stale-on-error). On startup before the first successful fetch, return `{}` for the catalog and `None` for any user.

---

## Error handling summary

| Condition                        | Correct behaviour |
| -------------------------------- | ----------------- |
| `/v1/pronouns` returns non-200   | Log error, return stale cache or `{}` |
| `/v1/users/{name}` returns 404   | Cache as "no pronouns" (`None`); this is the common case |
| `/v1/users/{name}` returns non-404 error | Log error, return `None` without caching |
| Network timeout (5 s)            | Log error, return stale cache or `None` |
| `pronoun_id` not found in catalog | Use the raw ID string as fallback display |
| `alt_pronoun_id` not in catalog  | Silently skip; display primary only |

---

## BotOfTheSpecter callsites

### beta.py only — stable and v6 do not implement pronoun lookup

| File | Lines | Function | Notes |
| ---- | ----- | -------- | ----- |
| `./bot/beta.py` | 541–557 | `get_pronouns_list()` | Fetches and caches the full `/v1/pronouns` catalog. TTL: `PRONOUNS_LIST_CACHE_TTL = 86400`. Falls back to stale cache on error. |
| `./bot/beta.py` | 559–597 | `get_user_pronouns(username)` | Per-user lookup against `/v1/users/{username_lower}`. Returns a resolved display string (`"She/Her"`, `"They/Them"`, etc.) or `None`. TTL: `USER_PRONOUNS_CACHE_TTL = 3600`. |
| `./bot/beta.py` | 599–606 | `_split_pronouns(pronoun_str)` | Splits a display string like `"she/her"` into `(subject, object)` tuple. Fallback: `("they", "them")`. |

### Placeholder substitution callsites

| File | Lines | Context |
| ---- | ----- | ------- |
| `./bot/beta.py` | ~10511–10523 | Custom command response substitution |
| `./bot/beta.py` | ~12170–12181 | Raid alert message substitution |
| `./bot/beta.py` | ~12248–12259 | Cheer alert message substitution |
| `./bot/beta.py` | ~12390+       | Additional event alert substitution |

### Placeholder reference

| Placeholder        | Expands to                                    | Fallback (no pronouns set) |
| ------------------ | --------------------------------------------- | -------------------------- |
| `(pronouns)`       | Full display string — `She/Her`, `They/Them`  | `they/them`                |
| `(pronouns.they)`  | Subject pronoun only — `She`, `They`, `He`   | `they`                     |
| `(pronouns.them)`  | Object pronoun only — `Her`, `Them`, `Him`   | `them`                     |

### Porting notes

If porting pronoun support to `./bot/bot.py` (stable) or `./bot/beta-v6.py` (v6), bring across:

1. The two cache globals and their TTL constants (lines 323–327 in beta.py).
2. `get_pronouns_list()`, `get_user_pronouns()`, and `_split_pronouns()` (lines 541–606).
3. The placeholder substitution blocks at each event alert callsite.
4. The `aiohttp.ClientTimeout(total=5)` — do not lower this value.

See [bot-versions.md](../../../rules/bot-versions.md) for the policy on which file to edit.
