# Brandfetch API Reference (BotOfTheSpecter)

[Brandfetch](https://brandfetch.com/) provides two distinct products: a **CDN Logo Link** system for serving brand images directly by URL, and a **JSON Brand API** for programmatic access to full brand data (colors, fonts, logos, company info). The repo currently uses only the CDN for dashboard icons. `./config/brandfetch.php` holds both credentials for future API use.

- **CDN host:** `https://cdn.brandfetch.io`
- **API host:** `https://api.brandfetch.io`
- **Developer Portal:** `https://developers.brandfetch.com/`

---

## 1. Authentication

### 1.1 CDN Logo Link — `client_id`

All CDN requests require a `client_id` passed as the `c=` query parameter. Without it the CDN may rate-limit or refuse the request.

```
https://cdn.brandfetch.io/{identifier}/...?c={client_id}
```

**How to obtain:** Register a free account at [developers.brandfetch.com](https://developers.brandfetch.com/). A `client_id` is issued on signup. It is **not a secret** — it is embedded in rendered HTML and visible to any browser.

### 1.2 Brand API — Bearer token (`api_key`)

All JSON API requests require an `Authorization` header:

```
Authorization: Bearer {api_key}
```

**How to obtain:** Same developer portal as above. The `api_key` **is a secret** — store it in `./config/brandfetch.php` (`$brandfetch_api_key`) and never commit a real value. See [secrets.md](../../../rules/secrets.md).

### 1.3 Credentials in this repo

| Variable | File | Used for | Secret? |
| -------- | ---- | -------- | ------- |
| `$brandfetch_client_id` | `./config/brandfetch.php` | CDN `c=` param | No |
| `$brandfetch_api_key` | `./config/brandfetch.php` | Brand API Bearer token | Yes |

Two distinct client IDs are currently hardcoded in dashboard HTML (cleanup task pending):

- `c=1bxid64Mup7aczewSAYMX` — primary; used for all services except StreamElements
- `c=1dxbfHSJFAPEGdCLU4o5B` — used only for the StreamElements icon

---

## 2. CDN Logo Link

The CDN Logo Link system serves brand images by constructing a URL. No JSON parsing required — point an `<img src>` directly at it.

### 2.1 Full URL pattern

```
https://cdn.brandfetch.io/{identifier}[/{modifiers...}][/{asset}[.{ext}]]?c={client_id}[&t={timestamp}]
```

Every segment is optional except `{identifier}` and `?c=`. Segments are positional and order matters within modifier groups.

### 2.2 Identifier types

The identifier is the first path segment after the host. Five forms are accepted:

| Form | Example | Notes |
| ---- | ------- | ----- |
| `{brandId}` (opaque) | `idIwZCwD2f` | Brandfetch-issued stable ID. Preferred — survives domain changes. |
| `{domain}` (auto-detect) | `nike.com` | Auto-detected as domain if it contains a `.`. |
| `domain/{domain}` (explicit) | `domain/nike.com` | Forces domain resolution; avoids collisions with tickers/ISINs. |
| `ticker/{symbol}` | `ticker/AAPL` | Stock or ETF ticker. |
| `crypto/{symbol}` | `crypto/BTC` | Cryptocurrency symbol. |
| `isin/{isin}` | `isin/US0378331005` | International Securities Identification Number. |

**Auto-detection resolution order** (when no explicit type prefix is given): domain → ticker → ISIN → crypto.

The repo uses opaque `brandId` exclusively because IDs are stable even if a brand's domain or ticker changes.

### 2.3 Modifiers (path segments)

Modifiers appear between the identifier and the asset type. Multiple modifiers can be combined; `theme/` must come before asset type.

| Modifier | Syntax | Values | Notes |
| -------- | ------ | ------ | ----- |
| Theme | `theme/{value}` | `light`, `dark` | Selects the brand's light or dark variant. |
| Width | `w/{px}` | integer pixels | Resizes to this width; aspect ratio preserved. |
| Height | `h/{px}` | integer pixels | Resizes to this height; aspect ratio preserved. |
| Fallback | `fallback/{type}` | `lettermark`, others | Asset to serve if the brand has no matching logo. |

**Retina displays:** double dimension values. To display at 64 px, request `w/128` or `h/128`.

**Example combining modifiers:**
```
https://cdn.brandfetch.io/idj4DI2QBL/w/400/h/400/theme/dark/icon.png?c=1dxbfHSJFAPEGdCLU4o5B
```

### 2.4 Asset types

The asset type appears as the final path segment before the extension.

| Asset | Description | SVG support |
| ----- | ----------- | ----------- |
| `icon` | Square icon / app icon variant (default if omitted) | No |
| `symbol` | Logomark only (the graphic symbol, no wordmark) | Yes |
| `logo` | Full logo (wordmark + symbol combined) | Yes |

**Default:** If the asset type is omitted the CDN returns `icon`.

### 2.5 Output formats

Specify format via the file extension appended to the asset type:

| Extension | Format | Notes |
| --------- | ------ | ----- |
| (none) | WebP | Default; modern, compressed. |
| `.svg` | SVG | Vector; only available for `symbol` and `logo`. |
| `.png` | PNG | Raster with transparency. |
| `.jpeg` / `.jpg` | JPEG | Raster, no transparency. |

Examples:
```
symbol.svg       → SVG vector symbol
icon.png         → PNG icon
icon.jpeg        → JPEG icon
logo             → WebP logo (no extension = WebP)
```

### 2.6 Query parameters

| Parameter | Required | Description |
| --------- | -------- | ----------- |
| `c={client_id}` | Yes | Your Brandfetch client ID. Omitting may cause rate-limiting or 403. |
| `t={epoch_ms}` | No | Cache-buster timestamp. Embed a fixed historical value to pin a specific cached asset version; omit to always get the latest. |

### 2.7 Complete URL examples

```
# Opaque brandId, dark symbol SVG
https://cdn.brandfetch.io/idIwZCwD2f/theme/dark/symbol.svg?c=1bxid64Mup7aczewSAYMX&t=1668070397594

# Domain identifier, PNG icon at 400×400
https://cdn.brandfetch.io/nike.com/w/400/h/400/icon.png?c=1bxid64Mup7aczewSAYMX

# Ticker, dark logo WebP
https://cdn.brandfetch.io/ticker/AAPL/theme/dark/logo?c=1bxid64Mup7aczewSAYMX

# Crypto symbol, dark icon PNG
https://cdn.brandfetch.io/crypto/BTC/theme/dark/icon.png?c=1bxid64Mup7aczewSAYMX
```

---

## 3. Brand API v2

Returns full brand data as JSON. Requires a Bearer token (see section 1.2).

### 3.1 GET /v2/brands/{identifier}

Retrieves complete brand data for a single brand.

```
GET https://api.brandfetch.io/v2/brands/{identifier}
Authorization: Bearer {api_key}
```

**Path parameter — `identifier`:**

Same five forms as the CDN (see section 2.2). Explicit type-prefix routes are available to avoid resolution ambiguity:

```
GET /v2/brands/{domain}              # e.g. /v2/brands/nike.com
GET /v2/brands/domain/{domain}       # explicit domain route
GET /v2/brands/ticker/{ticker}       # e.g. /v2/brands/ticker/AAPL
GET /v2/brands/isin/{isin}           # e.g. /v2/brands/isin/US0378331005
GET /v2/brands/crypto/{symbol}       # e.g. /v2/brands/crypto/BTC
```

**Query parameters:**

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `allowNsfw` | boolean | unset | `true` returns all brands regardless of NSFW status. `false` filters all NSFW brands (returns 404). Unset returns some NSFW brands (flagged `isNsfw: true`) while filtering others. |

#### 3.1.1 Response schema (200 OK)

```json
{
  "id":              "string   — Brandfetch stable brand ID",
  "name":            "string   — Brand display name",
  "domain":          "string   — Primary domain (e.g. 'nike.com')",
  "claimed":         "boolean  — true if verified and claimed by the brand owner",
  "description":     "string   — Short brand description",
  "longDescription": "string   — Extended brand description",
  "urn":             "string   — Uniform Resource Name",
  "qualityScore":    "number   — 0–1 data quality score (bottom third = poor, middle = OK, top = high)",
  "isNsfw":          "boolean  — true if flagged as adult content",
  "links":           [...],    // see 3.1.2
  "logos":           [...],    // see 3.1.3
  "colors":          [...],    // see 3.1.4
  "fonts":           [...],    // see 3.1.5
  "images":          [...],    // see 3.1.6
  "company":         {...}     // see 3.1.7
}
```

**`qualityScore` factors:** data recency, claimed status, manual verification, domain ranking, completeness.

#### 3.1.2 `links` array

```json
[
  {
    "name": "string  — platform name (twitter, facebook, instagram, github, youtube, linkedin, crunchbase)",
    "url":  "string  — full profile URL"
  }
]
```

#### 3.1.3 `logos` array

```json
[
  {
    "type":    "string   — icon | logo | symbol | other",
    "theme":   "string   — dark | light | null",
    "tags":    ["string  — e.g. 'photographic', 'portrait'"],
    "formats": [
      {
        "src":        "string   — CDN URL of this format",
        "format":     "string   — svg | webp | png | jpeg",
        "height":     "integer  — pixels (null for SVG)",
        "width":      "integer  — pixels (null for SVG)",
        "size":       "integer  — file size in bytes",
        "background": "boolean  — true if format has a background (non-transparent)"
      }
    ]
  }
]
```

#### 3.1.4 `colors` array

```json
[
  {
    "hex":        "string   — e.g. '#FF0000'",
    "type":       "string   — accent | dark | light | brand",
    "brightness": "number   — perceptual brightness value"
  }
]
```

#### 3.1.5 `fonts` array

```json
[
  {
    "name":    "string   — font family name",
    "type":    "string   — title | body",
    "origin":  "string   — google | custom | system",
    "weights": ["integer — e.g. 400, 700"]
  }
]
```

#### 3.1.6 `images` array

```json
[
  {
    "type":    "string   — banner | other",
    "formats": [
      {
        "src":        "string   — CDN URL",
        "format":     "string   — webp | png | jpeg",
        "height":     "integer",
        "width":      "integer",
        "size":       "integer  — bytes",
        "background": "boolean"
      }
    ]
  }
]
```

#### 3.1.7 `company` object

```json
{
  "employees":            "integer | null  — headcount",
  "foundedYear":          "integer | null  — e.g. 1994",
  "kind":                 "string  | null  — e.g. 'public', 'private', 'nonprofit'",
  "location": {
    "city":    "string | null",
    "country": "string | null",
    "region":  "string | null",
    "state":   "string | null",
    "subRegion":"string | null"
  },
  "industries": [
    {
      "id":   "string",
      "name": "string",
      "emoji":"string | null",
      "parent": { "id": "string", "name": "string" }
    }
  ],
  "financialIdentifiers": {
    "isin":   ["string"],
    "ticker": ["string"]
  }
}
```

#### 3.1.8 HTTP response codes

| Code | Meaning |
| ---- | ------- |
| 200 | Success |
| 400 | Bad Request — malformed identifier or parameter |
| 401 | Unauthorized — missing or invalid Bearer token |
| 404 | Not Found — brand not in database, or NSFW filtered via `allowNsfw=false` |
| 429 | Quota exceeded — API key rate limit hit |

---

### 3.2 GET /v2/search/{query}

Autocomplete-style brand search. Returns a ranked list of brand suggestions matching the query string.

```
GET https://api.brandfetch.io/v2/search/{query}
Authorization: Bearer {api_key}
```

**Path parameter — `query`:** Free-text search string (brand name, partial domain, etc.).

#### 3.2.1 Response schema (200 OK)

Returns an array of brand suggestion objects:

```json
[
  {
    "name":    "string  — brand display name",
    "domain":  "string  — primary domain",
    "claimed": "boolean — verified/claimed status",
    "icon":    "string  — CDN URL for brand icon (ready to use as <img src>)"
  }
]
```

**Use case:** Powering a brand search input (type-ahead) where users pick a brand and the app then fetches full data via `/v2/brands/{domain}`.

---

## 4. Rate Limits

### 4.1 CDN Logo Link

The CDN is a **free product** with per-`client_id` rate limiting. Rate limit details are not publicly documented; the dashboard's static logo use (a small fixed set of images loaded on page visit) is well within free-tier limits.

- If rate-limited, the CDN returns 429 or serves a placeholder image.
- Requests without a valid `c=` parameter may be throttled more aggressively.

### 4.2 Brand API

Rate limits are **tier-dependent** and enforced per `api_key`. Exceeding the quota returns HTTP 429.

| Tier | Limit (indicative) | Notes |
| ---- | ------------------ | ----- |
| Free | Low monthly call quota | Suitable for prototyping / infrequent lookups |
| Paid | Higher quotas (tier-specific) | Verify current limits on the Developer Portal before adding API-driven features |

The developer portal at [developers.brandfetch.com](https://developers.brandfetch.com/) shows the current quota and consumption for each key.

---

## 5. Licensing and Attribution

- **No attribution required.** Per Brandfetch documentation: *"we don't ask for any attribution."*
- Logos are licensed for display use within the context of identifying the brand they belong to.
- Do not alter, distort, or misrepresent logos.
- Do not use logos to imply sponsorship, endorsement, or partnership without permission from the brand owner.
- Some brands update assets periodically. A stale-looking logo usually means Brandfetch's CDN has a newer version — removing the `t=` cache-buster or updating its value will pull the latest.

---

## 6. Logo Types and Formats — Quick Reference

| Asset type | Meaning | SVG | WebP | PNG | JPEG |
| ---------- | ------- | :-: | :--: | :-: | :--: |
| `icon` | Square app-icon variant | — | Yes | Yes | Yes |
| `symbol` | Logomark only (no wordmark) | Yes | Yes | Yes | Yes |
| `logo` | Full logo (wordmark + symbol) | Yes | Yes | Yes | Yes |

**Theme variants** (`dark` / `light`): brands may supply different assets for dark vs light backgrounds. If the requested theme variant doesn't exist, Brandfetch may fall back to the other theme or return the un-themed asset.

---

## 7. PHP Integration Pattern

Per [php-config.md](../../../rules/php-config.md), credentials come from `./config/brandfetch.php`, not `.env`.

```php
require_once '/var/www/config/brandfetch.php';  // server
// dev: require_once __DIR__ . '/../config/brandfetch.php';

// CDN usage (current pattern — hardcoded, cleanup pending)
$url = "https://cdn.brandfetch.io/{$brandId}/theme/dark/symbol.svg?c={$brandfetch_client_id}";

// Brand API usage (future)
$ch = curl_init("https://api.brandfetch.io/v2/brands/{$domain}");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$brandfetch_api_key}"]);
```

**Known cleanup task:** The `c=` client ID is currently hardcoded in 11+ places across `menu.php`, `profile.php`, and `recording.php`. Grep targets if rotating: `c=1bxid64Mup7aczewSAYMX` and `c=1dxbfHSJFAPEGdCLU4o5B`.

---

## 8. BotOfTheSpecter Callsites

### 8.1 Brand IDs in use

| Service | brandId | Asset URL fragment | Client ID | Files |
| ------- | ------- | ------------------ | --------- | ----- |
| Twitch | `idIwZCwD2f` | `/theme/dark/symbol.svg` | `1bxid64Mup7aczewSAYMX` | `profile.php` |
| Discord | `idM8Hlme1a` | `/theme/dark/symbol.svg` | `1bxid64Mup7aczewSAYMX` | `menu.php`, `profile.php` |
| Spotify | `id20mQyGeY` | `/theme/dark/symbol.svg` | `1bxid64Mup7aczewSAYMX` | `menu.php`, `profile.php` |
| StreamElements | `idj4DI2QBL` | `/w/400/h/400/theme/dark/icon.png` | `1dxbfHSJFAPEGdCLU4o5B` | `menu.php`, `profile.php` |
| StreamLabs | `idIDKnQFO2` | `/w/400/h/400/theme/dark/icon.jpeg` | `1bxid64Mup7aczewSAYMX` | `menu.php`, `profile.php` |
| YouTube | `idVfYwcuQz` | `/theme/dark/symbol.svg` | `1bxid64Mup7aczewSAYMX` | `profile.php`, `recording.php` |
| Kick | `id3gkQXO6j` | `/w/400/h/400/theme/dark/icon.jpeg` | `1bxid64Mup7aczewSAYMX` | `profile.php`*, `recording.php` |
| Trovo | `idiHGB0VOK` | `/theme/dark/logo.svg` | `1bxid64Mup7aczewSAYMX` | `recording.php` |

*Note: The Kick entry in `profile.php` line 1027 is missing the `?c=` query parameter entirely — it will be rate-limited or refused. `recording.php` line 155 has the correct `?c=` param for the same brand ID.

### 8.2 Source file reference

| File | Lines | Notes |
| ---- | ----- | ----- |
| `./config/brandfetch.php` | 1–4 | Credential placeholders; empty in dev. |
| `./dashboard/menu.php` | 60–63 | Sidebar nav icons (Discord, Spotify, StreamElements, StreamLabs). |
| `./dashboard/profile.php` | 915, 929, 950, 971, 992, 1013, 1027 | Service-link cards for all 7 services. |
| `./dashboard/recording.php` | 154–156 | Streaming destination icons (YouTube, Kick, Trovo). |
