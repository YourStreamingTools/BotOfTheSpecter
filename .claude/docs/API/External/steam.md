# Steam Web API — Comprehensive Local Reference (BotOfTheSpecter)

Read-only reference covering every Steam API surface used by (or relevant to) BotOfTheSpecter.  
All research confirmed against live responses on 2026-05-11.

- **Env var:** `STEAM_API` — key from `https://steamcommunity.com/dev`
- **Web API domain:** `api.steampowered.com` (authenticated + unauthenticated)
- **Partner API domain:** `partner.steam-api.com` (publisher key only — not used here)
- **Storefront API domain:** `store.steampowered.com/api` (no key)

---

## 1. Authentication

### 1.1 API key

Obtain a key at `https://steamcommunity.com/dev/apikey`. One key per Steam account; the form asks for a domain name (any value accepted).

The key is passed as a **query parameter** named `key`:

```
GET https://api.steampowered.com/<Interface>/<Method>/v<N>/?key=YOUR_KEY&...
```

There is also a `partner.steam-api.com` host that requires a *publisher* key and grants access to private partner endpoints (app betas, builds, depots). None of those endpoints are used here; all BotOfTheSpecter calls go to `api.steampowered.com`.

### 1.2 Which endpoints require a key?

| Endpoint | Key required? | Notes |
|----------|--------------|-------|
| `ISteamApps/GetAppList/v1` | No | Returns same data as v2; key does nothing |
| `ISteamApps/GetAppList/v2` | No | Public, no auth |
| `IStoreService/GetAppList/v1` | Yes (in practice) | Without a key returns HTTP 403 |
| `store.steampowered.com/api/storesearch` | No | Storefront API, public |
| `store.steampowered.com/api/appdetails` | No | Storefront API, public |

### 1.3 Key in this project

```python
STEAM_API = os.getenv('STEAM_API')  # loaded in bot.py, beta.py, beta-v6.py, api.py
```

`kick.py` assigns it to `STEAM_API_KEY` but does not use it (the storefront call needs no key).

---

## 2. Request conventions

### 2.1 Base URL and URI pattern

```
https://api.steampowered.com/<Interface>/<Method>/v<version>/
```

Example: `https://api.steampowered.com/ISteamApps/GetAppList/v2/`

- HTTP 1.1, UTF-8
- Parameters sent as GET query string (or POST form-body; GET preferred for read-only calls)
- A trailing slash after the version number is conventional but optional

### 2.2 Response format

Add `format=json` (or `format=xml` / `format=vdf`) to request a specific format.  
JSON is the default and the only format used in this project.

### 2.3 Array parameters

When a method accepts an array, use the `[0]` / `[1]` index notation with a `count` field:

```
appids[0]=440&appids[1]=570&count=2
```

### 2.4 Service interface variant (protobuf-backed)

Interfaces whose names end in `Service` (e.g. `IStoreService`) are backed by protobuf.  
They accept an alternative `input_json` parameter containing a URL-encoded JSON body:

```
input_json={"max_results":50000}
```

The plain query-string form also works and is what this project uses.

---

## 3. Response envelope differences

Steam has three distinct response shapes depending on the interface:

| Interface | Top-level key | App list shape |
|-----------|--------------|---------------|
| `ISteamApps/GetAppList/v1` and `v2` | `applist` | `applist.apps` → array of `{appid, name}` |
| `IStoreService/GetAppList/v1` | `response` | `response.apps` → array of `{appid, name, last_modified, price_change_number}` |
| `appdetails` | `"<appid>"` (string key) | `"<appid>".data` → full object |
| `storesearch` | `total` + `items` | `items` → array of result objects |

`_normalize_steam_app_list()` in `./api/api.py` handles all three app-list shapes (including the flat `{name: appid}` dict that the API service itself returns on subsequent internal calls).

---

## 4. ISteamApps interface

Base URL: `https://api.steampowered.com/ISteamApps/`

### 4.1 GetAppList/v1

Alias of v2; identical behaviour. Documented for completeness.

```
GET https://api.steampowered.com/ISteamApps/GetAppList/v1/
```

No parameters. No key required.

**Response** — identical schema to v2 (see below).

### 4.2 GetAppList/v2

```
GET https://api.steampowered.com/ISteamApps/GetAppList/v2/
```

**Parameters:** none (key is accepted but ignored).

**Response:**

```json
{
  "applist": {
    "apps": [
      { "appid": 10,  "name": "Counter-Strike" },
      { "appid": 440, "name": "Team Fortress 2" }
    ]
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `applist` | object | Envelope |
| `applist.apps` | array | All public Steam entries (games + DLC + software + tools + videos) |
| `applist.apps[].appid` | integer | Unique Steam application ID |
| `applist.apps[].name` | string | Display name; may be empty string for unlisted entries |

- No pagination. Returns everything in one response.
- Typical payload: 150 000+ entries, 5–15 MB JSON.
- Includes DLC, tools, demos, videos — not just games.
- Name matching requires exact, case-insensitive comparison; no fuzzy search.
- **Status:** Valve considers this deprecated; prefer `IStoreService/GetAppList/v1` when a key is available.
- Current bot code calls `http://` (not `https://`) — Steam redirects to HTTPS. New code should use `https://` directly.

---

## 5. IStoreService interface

Base URL: `https://api.steampowered.com/IStoreService/`  
(Not `partner.steam-api.com` — that host is for publisher-only endpoints.)

### 5.1 GetAppList/v1

```
GET https://api.steampowered.com/IStoreService/GetAppList/v1/
    ?key=YOUR_KEY
    &format=json
    &max_results=50000
    &last_appid=0
```

**Parameters:**

| Parameter | Type | Required | Default | Notes |
|-----------|------|----------|---------|-------|
| `key` | string | Yes | — | Standard Web API key; 403 without it |
| `format` | string | No | `json` | `json` / `xml` / `vdf` |
| `max_results` | uint32 | No | 10 000 | Maximum entries per page; hard cap 50 000 |
| `last_appid` | uint32 | No | 0 | Pagination cursor: pass `response.last_appid` from previous page |
| `if_modified_since` | uint32 | No | — | Unix timestamp; return only entries modified after this time |
| `have_description_language` | string | No | — | BCP 47 language code; return only entries with descriptions in this language (e.g. `english`, `german`) |
| `include_games` | bool | No | `true` | Include games |
| `include_dlc` | bool | No | `false` | Include DLC |
| `include_software` | bool | No | `false` | Include software |
| `include_videos` | bool | No | `false` | Include video content |
| `include_hardware` | bool | No | `false` | Include hardware |

**Response (single page):**

```json
{
  "response": {
    "apps": [
      {
        "appid": 10,
        "name": "Counter-Strike",
        "last_modified": 1690000000,
        "price_change_number": 0
      }
    ],
    "have_more_results": true,
    "last_appid": 12345
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `response` | object | Envelope |
| `response.apps` | array | Entries for this page; may be empty on the final page |
| `response.apps[].appid` | integer | Steam application ID |
| `response.apps[].name` | string | Display name |
| `response.apps[].last_modified` | uint32 | Unix timestamp of last store change |
| `response.apps[].price_change_number` | uint32 | Counter that increments when price changes; use with `if_modified_since` for incremental sync |
| `response.have_more_results` | bool | `true` means another page exists |
| `response.last_appid` | uint32 | Pass as `last_appid` to fetch the next page |

**Pagination loop:**

```python
last_appid = 0
more_results = True
combined = {}
while more_results:
    params = {"key": STEAM_API, "format": "json",
              "max_results": 50000, "last_appid": last_appid}
    resp = await session.get(primary_url, params=params)
    data = await resp.json()
    page = data.get("response", {})
    for app in page.get("apps", []):
        if app.get("name") and app.get("appid"):
            combined[app["name"].lower()] = app["appid"]
    more_results = bool(page.get("have_more_results"))
    last_appid = int(page.get("last_appid") or 0)
    if more_results and last_appid <= 0:
        break  # guard against infinite loop
```

**Advantages over `ISteamApps/GetAppList/v2`:**

- Paginated — no single giant response.
- Includes `last_modified` and `price_change_number` for incremental updates.
- Filterable by content type and language.
- Valve's actively maintained endpoint.

---

## 6. Storefront API — `store.steampowered.com/api`

Not part of the Web API. No key. No versioning. Undocumented officially (Valve does not publish it).  
**Do not send `key=`** — it has no effect and may cause unexpected filtering.

### 6.1 storesearch

```
GET https://store.steampowered.com/api/storesearch/
    ?term=<query>
    &cc=us
    &l=english
```

**Parameters:**

| Parameter | Notes |
|-----------|-------|
| `term` | Search query string (URL-encoded) |
| `cc` | ISO 3166-1 alpha-2 country code for pricing/availability (`us`, `au`, `gb`, etc.) |
| `l` | Language for name/description. `english` and `en` both accepted here; prefer `english` for consistency with `appdetails` |

**Response:**

```json
{
  "total": 10,
  "items": [
    {
      "type": "app",
      "name": "Elden Ring",
      "id": 1245620,
      "tiny_image": "https://cdn.akamai.steamstatic.com/steam/apps/1245620/capsule_184x69.jpg",
      "metascore": "94",
      "platforms": {
        "windows": true,
        "mac": false,
        "linux": false
      },
      "streamingvideo": false,
      "controller_support": "full",
      "price": {
        "currency": "USD",
        "initial": 5999,
        "final": 5999,
        "discount_percent": 0
      }
    }
  ]
}
```

| Field | Type | Notes |
|-------|------|-------|
| `total` | integer | Total matching results (not just current page) |
| `items` | array | Up to 25 results; no pagination |
| `items[].type` | string | `"app"` (always in practice) |
| `items[].name` | string | Display name |
| `items[].id` | integer | Steam AppID |
| `items[].tiny_image` | string | URL to 184x69 capsule image |
| `items[].metascore` | string | Metacritic score, or empty string if none |
| `items[].platforms.windows` | bool | Windows availability |
| `items[].platforms.mac` | bool | macOS availability |
| `items[].platforms.linux` | bool | Linux availability |
| `items[].streamingvideo` | bool | Whether it is video content |
| `items[].controller_support` | string | `"full"`, `"partial"`, or absent |
| `items[].price` | object | Absent for free-to-play items |
| `items[].price.currency` | string | ISO 4217 code (`"USD"`) |
| `items[].price.initial` | integer | Base price in cents |
| `items[].price.final` | integer | Current price in cents (after discount) |
| `items[].price.discount_percent` | integer | 0–100; only present on some responses |

**Key behaviours:**

- **Maximum 25 results.** No `page` or `offset` parameter exists. For exhaustive lookup, use the `IStoreService/GetAppList/v1` cached map.
- **`price` is absent on free items** — always guard: `(item.get("price") or {}).get("final", 0)`.
- **`discount_percent` may be absent** even on paid items — confirmed missing in live responses; do not rely on it.
- `initial_formatted` and `final_formatted` are **not present** in `storesearch` responses (only in `appdetails`).
- Results are ranked by relevance, not exact match. The first result is usually but not always the intended game.

### 6.2 appdetails

```
GET https://store.steampowered.com/api/appdetails/
    ?appids=440
    &cc=us
    &l=english
    &filters=basic,price_overview
```

**Parameters:**

| Parameter | Notes |
|-----------|-------|
| `appids` | Comma-separated AppIDs (e.g. `440,570`). Multiple IDs require `filters` that include `price_overview` or the response may omit pricing for later entries. |
| `cc` | Country code for pricing and availability (default varies by IP). |
| `l` | Language. **Use `english`, not `en`** — `en` does not work for `appdetails`. |
| `filters` | Comma-separated field groups to return (see below). Omit for full response. |

**`filters` values** (any combination, comma-separated):

`basic`, `detailed_description`, `about_the_game`, `short_description`, `fullgame`, `supported_languages`, `header_image`, `website`, `pc_requirements`, `mac_requirements`, `linux_requirements`, `legal_notice`, `developers`, `demos`, `price_overview`, `metacritic`, `categories`, `genres`, `screenshots`, `movies`, `recommendations`, `achievements`, `release_date`, `support_info`, `background`, `content_descriptors`

**Response envelope:**

The top-level object is keyed by AppID **as a string**:

```json
{
  "440": {
    "success": true,
    "data": { ... }
  }
}
```

If `success` is `false`, the `data` key is absent. This happens for removed apps, region-locked apps, or non-existent AppIDs.

**Full `data` object schema:**

```json
{
  "type": "game",
  "name": "Team Fortress 2",
  "steam_appid": 440,
  "required_age": 0,
  "is_free": true,
  "dlc": [629330],
  "controller_support": "full",
  "detailed_description": "<html>...",
  "about_the_game": "<html>...",
  "short_description": "One sentence summary.",
  "supported_languages": "English<strong>*</strong>, French, ...",
  "header_image": "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/440/header.jpg",
  "capsule_image": "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/440/capsule_231x87.jpg",
  "capsule_imagev5": "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/440/capsule_184x69.jpg",
  "website": "http://www.teamfortress.com/",
  "pc_requirements": {
    "minimum": "<html>...",
    "recommended": "<html>..."
  },
  "mac_requirements": { "minimum": "<html>..." },
  "linux_requirements": { "minimum": "<html>..." },
  "developers": ["Valve"],
  "publishers": ["Valve"],
  "packages": [197845, 330198],
  "package_groups": [
    {
      "name": "default",
      "title": "Buy Team Fortress 2",
      "description": "",
      "subs": [
        {
          "packageid": 469,
          "percent_savings": 0,
          "option_text": "Free To Play",
          "is_free_license": true,
          "price_in_cents_with_discount": 0
        }
      ]
    }
  ],
  "platforms": {
    "windows": true,
    "mac": true,
    "linux": true
  },
  "metacritic": {
    "score": 92,
    "url": "https://www.metacritic.com/game/..."
  },
  "categories": [
    { "id": 1,  "description": "Multi-player" },
    { "id": 22, "description": "Steam Achievements" }
  ],
  "genres": [
    { "id": "1", "description": "Action" }
  ],
  "screenshots": [
    {
      "id": 0,
      "path_thumbnail": "https://shared.akamai.steamstatic.com/..._200x113.jpg",
      "path_full": "https://shared.akamai.steamstatic.com/..._1920x1080.jpg"
    }
  ],
  "movies": [
    {
      "id": 2032328,
      "name": "Trailer",
      "thumbnail": "https://shared.akamai.steamstatic.com/...jpg",
      "dash_av1": "https://cdn.cloudflare.steamstatic.com/...av1.mpd",
      "dash_h264": "https://cdn.cloudflare.steamstatic.com/...h264.mpd",
      "hls_h264": "https://cdn.cloudflare.steamstatic.com/...m3u8",
      "highlight": true
    }
  ],
  "recommendations": { "total": 46836 },
  "achievements": {
    "total": 520,
    "highlighted": [
      {
        "name": "ACHIEVEMENT_NAME",
        "localized_name": "Display Name",
        "path": "https://cdn.akamai.steamstatic.com/steamcommunity/public/images/apps/440/...jpg"
      }
    ]
  },
  "release_date": {
    "coming_soon": false,
    "date": "Oct 10, 2007"
  },
  "support_info": {
    "url": "http://www.valvesoftware.com/support/",
    "email": ""
  },
  "background": "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/440/page_bg_generated_v6b.jpg",
  "background_raw": "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/440/...",
  "content_descriptors": {
    "ids": [2, 5],
    "notes": null
  },
  "ratings": {
    "usk": { "rating": "16", "descriptors": "..." },
    "agcom": { "rating": "16", "use_age_gate": "false", "required_age": "16" },
    "dejus": { "rating": "16", "required_age": "16", "banned": "0" },
    "steam_germany": { "rating_generated": "1", "rating": "16", "required_age": "16", "banned": "0", "use_age_gate": "0", "descriptors": "..." }
  }
}
```

**`price_overview` object** (present only on paid apps; absent when `is_free: true`):

```json
"price_overview": {
  "currency": "USD",
  "initial": 5999,
  "final": 5999,
  "discount_percent": 0,
  "initial_formatted": "",
  "final_formatted": "$59.99"
}
```

| Field | Type | Notes |
|-------|------|-------|
| `currency` | string | ISO 4217 (`"USD"`) |
| `initial` | integer | Base price in cents before any discount |
| `final` | integer | Current price in cents |
| `discount_percent` | integer | 0–100 |
| `initial_formatted` | string | Formatted base price; **empty string when there is no active discount** |
| `final_formatted` | string | Formatted current price (e.g. `"$59.99"`) |

**`categories` vs `genres`:**

| | `categories[]` | `genres[]` |
|-|---------------|-----------|
| `id` type | integer | string (despite being a number) |
| Meaning | Steam feature flags: Multi-player, Achievements, Workshop, etc. | Genre classifications: Action, RPG, Strategy, etc. |

**Field absence rules:**

- `price_overview` — absent when `is_free: true`
- `metacritic` — absent if no Metacritic score
- `controller_support` — absent if no controller support
- `movies` — absent if no trailers
- `dlc` — absent if no DLC
- `achievements` — absent if no achievements
- `ratings` — varies by region
- `mac_requirements`, `linux_requirements` — present even when platform not supported (contains empty object)

---

## 7. CDN image URL patterns

These URLs are stable and predictable — no API call needed if you already have the AppID.

### 7.1 Store capsule images

```
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/header.jpg
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/capsule_231x87.jpg
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/capsule_184x69.jpg
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/capsule_616x353.jpg
```

### 7.2 Library images (Steam library view)

```
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/library_600x900.jpg
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/library_hero.jpg
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/library_hero_blur.jpg
```

### 7.3 Page background

```
https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{appid}/page_bg_generated_v6b.jpg
```

### 7.4 `storesearch` `tiny_image`

The `tiny_image` field in storesearch results follows this pattern (also constructable directly):

```
https://cdn.akamai.steamstatic.com/steam/apps/{appid}/capsule_184x69.jpg
```

Note: `cdn.akamai.steamstatic.com/steam/apps/` (older CDN path) vs `shared.akamai.steamstatic.com/store_item_assets/steam/apps/` (newer path returned by `appdetails`). Both work. Prefer the `shared.akamai` form for new code.

### 7.5 Community images (avatars, achievement icons)

```
https://cdn.akamai.steamstatic.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
```

These are not predictable from the AppID alone — use the hash from the API response.

---

## 8. Rate limits and caching guidance

Valve does not publish numeric rate limits. Community-observed behaviour:

| Endpoint | Observed limit | Recommended cache TTL |
|----------|---------------|----------------------|
| `ISteamApps/GetAppList/v2` | Generous; Valve explicitly asks callers to cache | 1 hour minimum |
| `IStoreService/GetAppList/v1` | Generous for paginated bulk load | 1 hour minimum |
| `appdetails` | ~200 requests / 5 min per IP (community report) | Per-game: hours or days |
| `storesearch` | Generous; no hard cap observed | Per-search: minutes; not typically cached |

**Caching strategy in this project:**

- API service (`./api/api.py`): in-memory `_steam_app_list_cache` + on-disk `steamapplist.json` (server path: `/home/botofthespecter/steamapplist.json`). TTL configurable via `STEAM_APP_LIST_CACHE_TTL_SECONDS` env var (default 3600 s).
- Stable bot / v6 bot: on-disk cache at `/var/www/api/steamapplist.json` with 1-hour file-mtime check.
- On Steam API failure: stale disk cache is served with a warning rather than returning an error.

Steam is not currently counted in the `api_counts` table. If rate-limit counting is added, use key `steam`.

---

## 9. Gotchas and edge cases

**HTTP vs HTTPS.** `bot.py` and `beta-v6.py` call `http://api.steampowered.com/ISteamApps/GetAppList/v2` — Steam 308-redirects to HTTPS. `aiohttp` follows redirects. New code should use `https://` directly to avoid the round-trip.

**Response envelope mismatch.** Three completely different top-level shapes exist across the four endpoints. Never assume which one you have — always key-check before accessing nested fields.

**`is_free: true` drops `price_overview`.** The field is simply absent, not `null`. Guard every price access: `data.get("price_overview") or {}`.

**`price.discount_percent` absent in `storesearch`.** The field appears inconsistently — do not rely on it. Use `appdetails` `price_overview.discount_percent` when you need a reliable discount value.

**`l=en` vs `l=english`.** `en` works for `storesearch` but **not** for `appdetails`. Use `english` / `us` everywhere to be safe.

**`genres[].id` is a string.** `"1"` not `1`. `categories[].id` is an integer. They look similar but have different types — compare correctly.

**App list includes non-games.** Both `ISteamApps/GetAppList/v2` and `IStoreService/GetAppList/v1` include DLC, tools, demos, soundtracks, and videos unless you filter with `include_*` params (IStoreService only). A name match might resolve to a DLC, not a base game — `appdetails` `type` field (`"game"`, `"dlc"`, `"demo"`, `"music"`, etc.) can disambiguate.

**Name matching is exact, case-insensitive.** The `the ` prefix workaround (strip leading "the " and retry) is the only fuzzy logic in the current implementation. Games with subtitle differences, trademark symbols, or edition suffixes will not match.

**Empty name entries.** Both app-list endpoints include entries with empty string names. `_normalize_steam_app_list` filters them out (`if app.get("name") and app.get("appid")`).

**`storesearch` max 25 results, no pagination.** For exhaustive lookup, use the locally cached `IStoreService/GetAppList/v1` map — it contains the full catalogue.

**`appdetails` multi-ID behaviour.** Passing multiple `appids` (`?appids=440,570`) returns a top-level object with one key per AppID as a string. Always parse the specific AppID key you care about, not `data` directly.

---

## 10. BotOfTheSpecter callsites

| Concern | File | Lines | Endpoint used |
|---------|------|-------|---------------|
| Env var load | `./bot/bot.py` | 76 | — |
| Env var load | `./bot/beta.py` | 104 | — |
| Env var load | `./bot/beta-v6.py` | 98 | — |
| Env var load | `./api/api.py` | 67 | — |
| Env var load (as `STEAM_API_KEY`) | `./bot/kick.py` | 77 | — |
| `!steam` command (stable) | `./bot/bot.py` | 5340–5405 | `ISteamApps/GetAppList/v2` (file cache fallback) |
| `!steam` command (beta) | `./bot/beta.py` | 7435–7490 | Internal `/api/steamapplist` endpoint |
| `!steam` command (v6) | `./bot/beta-v6.py` | 6438–6505 | `ISteamApps/GetAppList/v2` (file cache fallback) |
| `!steam` command (Kick) | `./bot/kick.py` | 1482–1505 | `store.steampowered.com/api/storesearch/` |
| App list cache loader | `./api/api.py` | 2867–2994 | `IStoreService/GetAppList/v1` (primary); `ISteamApps/GetAppList/v2` (legacy fallback) |
| `_normalize_steam_app_list` | `./api/api.py` | 1488–1512 | Normalises all three app-list shapes |
| Disk cache persist/load | `./api/api.py` | 1514–1541 | Server path: `/home/botofthespecter/steamapplist.json` |

### Fetch strategy summary

```
beta.py  →  GET https://api.botofthespecter.com/api/steamapplist
                 ↓
api.py /api/steamapplist  →  in-memory cache (TTL: STEAM_APP_LIST_CACHE_TTL_SECONDS, default 3600s)
                          →  disk cache (/home/botofthespecter/steamapplist.json)
                          →  IStoreService/GetAppList/v1  (with key, paginated)
                          →  ISteamApps/GetAppList/v2    (legacy fallback)
                          →  stale disk cache            (if both fail, with warning)

bot.py / beta-v6.py  →  file cache (/var/www/api/steamapplist.json, 1h mtime)
                      →  ISteamApps/GetAppList/v2        (if cache miss or expired)

kick.py  →  storesearch (no cache; direct per-command search by user-supplied term)
```
