# JokeAPI v2 — Comprehensive API Reference

Upstream free REST API for jokes. No registration, no payment, no API key required for standard use.

- **Base URL:** `https://v2.jokeapi.dev`
- **Legacy mirror:** `https://sv443.net/jokeapi/v2` (deprecated, still proxied)
- **Python wrapper:** `jokeapi` package (`from jokeapi import Jokes`)

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [Rate Limits](#2-rate-limits)
3. [Global Parameters](#3-global-parameters)
4. [Endpoints](#4-endpoints)
   - [GET /joke/{category}](#41-get-jokecategory)
   - [GET /info](#42-get-info)
   - [GET /categories](#43-get-categories)
   - [GET /langcode/{language}](#44-get-langcodelanguage)
   - [GET /languages](#45-get-languages)
   - [GET /flags](#46-get-flags)
   - [GET /formats](#47-get-formats)
   - [GET /ping](#48-get-ping)
   - [GET /endpoints](#49-get-endpoints)
   - [POST /submit](#410-post-submit)
5. [Joke Categories](#5-joke-categories)
6. [Blacklist Flags](#6-blacklist-flags)
7. [Response Formats](#7-response-formats)
8. [Language Support](#8-language-support)
9. [Safe Mode](#9-safe-mode)
10. [Response Schemas](#10-response-schemas)
    - [Single joke](#101-single-joke)
    - [Two-part joke](#102-two-part-joke)
    - [Multiple jokes](#103-multiple-jokes-amount--2)
    - [Error response](#104-error-response)
11. [HTTP Status Codes](#11-http-status-codes)
12. [Error Codes](#12-error-codes)
13. [BotOfTheSpecter Callsites](#13-botofthespecter-callsites)

---

## 1. Authentication

**No token is required** for standard use. Do not add an Authorization header — it is ignored unless you have been issued a token.

**Optional API tokens** are available for:
- Clients that are being rate-limited due to high volume
- Clients that have been incorrectly blacklisted
- Business/commercial use cases

When a token is present, supply it as:

```
Authorization: <token>
```

The response will include a `Token-Valid` header: `1` if the token is recognized, `0` if not.

---

## 2. Rate Limits

| Limit type | Value | Scope |
| --- | --- | --- |
| General requests | **120 / minute** | Per client IP |
| Joke submissions | **5 / minute** | Per client IP |
| Exceeded → | HTTP 429 | — |

**Rate-limit response headers** (present on all responses):

| Header | Description |
| --- | --- |
| `RateLimit-Limit` | Maximum requests allowed per window |
| `RateLimit-Remaining` | Requests remaining in current window |
| `RateLimit-Reset` | IMF-fixdate timestamp when the window resets |
| `Retry-After` | Seconds to wait before retrying (only on 429) |

**HTTP 403 note:** If a 403 is returned, the client IP has been blacklisted for suspected malicious behavior. If the URL works in a browser but not in code, add an explicit `User-Agent` header to your request.

---

## 3. Global Parameters

These query parameters are accepted by **all** endpoints.

| Parameter | Values | Default | Description |
| --- | --- | --- | --- |
| `format` | `json`, `xml`, `yaml`, `txt` | `json` | Response serialization format |
| `lang` | ISO 639-1 code (`en`, `de`, `ru`, …) | `en` | Language for system messages and joke content |

---

## 4. Endpoints

### 4.1 GET /joke/{category}

Fetch one or more jokes, with optional filtering.

**URL:** `GET https://v2.jokeapi.dev/joke/{category}`

**Path parameter:**

| Parameter | Required | Description |
| --- | --- | --- |
| `{category}` | Yes | One or more categories (see §5). Use `Any` for random. Combine with `,` or `+` (union) or `-` (exclusion). Examples: `Programming`, `Programming,Misc`, `Any-Dark` |

**Query parameters:**

| Parameter | Values | Default | Description |
| --- | --- | --- | --- |
| `type` | `single`, `twopart` | both | Restrict to a specific joke structure |
| `blacklistFlags` | comma/plus-separated flag names | none | Exclude jokes that have any of these flags set. Example: `nsfw,religious` |
| `contains` | any string (percent-encoded) | none | Case-insensitive substring search across joke text |
| `idRange` | `0-100`, `42`, `0-55,100-200` | none | Restrict to specific joke IDs or ranges |
| `amount` | `1`–`10` | `1` | Number of jokes to return in a single response |
| `safe-mode` | (no value) | disabled | Enable safe mode — see §9 |
| `format` | see §7 | `json` | Response format |
| `lang` | ISO 639-1 | `en` | Joke language |

**Examples:**

```
# Single random joke
GET /joke/Any

# Programming or Misc joke, no nsfw, single type
GET /joke/Programming,Misc?blacklistFlags=nsfw&type=single

# Five dark jokes in German
GET /joke/Dark?amount=5&lang=de

# Safe-mode joke containing the word "python"
GET /joke/Programming?safe-mode&contains=python

# Joke with ID between 50 and 100
GET /joke/Any?idRange=50-100

# XML response
GET /joke/Pun?format=xml
```

---

### 4.2 GET /info

Returns API metadata and statistics. Useful for discovering current category lists, supported languages, and joke counts without hard-coding them.

**URL:** `GET https://v2.jokeapi.dev/info`

**Query parameters:** `format`, `lang`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `version` | string | Current API version (e.g. `"2.3.5"`) |
| `jokes.totalCount` | integer | Total number of jokes across all languages |
| `jokes.categories` | string[] | All available category names |
| `jokes.categoryAliases` | object[] | Alias → category mappings |
| `jokes.flags` | string[] | All available blacklist flag names |
| `jokes.types` | string[] | Joke types (`["single", "twopart"]`) |
| `jokes.submissionURL` | string | URL to the joke submission form |
| `jokes.idRange` | object | Per-language `{ "en": { "min": 0, "max": N } }` |
| `jokes.safeJokes` | object | Per-language count of safe-mode-eligible jokes |
| `formats` | string[] | Available response format names |
| `info` | string | Message of the day / informational string |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.3 GET /categories

Lists all available joke categories and their aliases.

**URL:** `GET https://v2.jokeapi.dev/categories`

**Query parameters:** `format`, `lang`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `categories` | string[] | Canonical category names |
| `categoryAliases` | object[] | `{ "alias": "Coding", "resolved": "Programming" }` |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.4 GET /langcode/{language}

Resolves a human-readable language name to its ISO 639-1 code using fuzzy matching. Useful when you have a language name and need the code for other API calls.

**URL:** `GET https://v2.jokeapi.dev/langcode/{language}`

**Path parameter:**

| Parameter | Description |
| --- | --- |
| `{language}` | Human-readable language name, e.g. `english`, `german`, `czech` |

**Query parameters:** `format`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `code` | string | ISO 639-1 code, e.g. `"en"` |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.5 GET /languages

Returns all languages the API supports, divided into joke languages (languages that have joke content) and system languages (languages that have translated error/system messages).

**URL:** `GET https://v2.jokeapi.dev/languages`

**Query parameters:** `format`, `lang`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `defaultLanguage` | string | The fallback language code (`"en"`) |
| `jokeLangs` | string[] | ISO 639-1 codes that have joke entries |
| `systemLangs` | string[] | ISO 639-1 codes with translated system messages |
| `possibleLangs` | string[] | All codes the API will accept |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.6 GET /flags

Returns all available blacklist flag names.

**URL:** `GET https://v2.jokeapi.dev/flags`

**Query parameters:** `format`, `lang`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `flags` | string[] | All flag names (see §6) |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.7 GET /formats

Returns all supported response format names.

**URL:** `GET https://v2.jokeapi.dev/formats`

**Query parameters:** `format`, `lang`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `formats` | string[] | e.g. `["json", "xml", "yaml", "txt"]` |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.8 GET /ping

Lightweight liveness check. Returns a "Pong!" message and a timestamp. Suitable for uptime monitors.

**URL:** `GET https://v2.jokeapi.dev/ping`

**Query parameters:** `format`, `lang`

**Response fields (JSON):**

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `false` on success |
| `ping` | string | `"Pong!"` |
| `timestamp` | integer | 13-digit Unix timestamp (ms) |

---

### 4.9 GET /endpoints

Returns a structured list of all API endpoints with their descriptions and usage details. Mirrors the official documentation programmatically.

**URL:** `GET https://v2.jokeapi.dev/endpoints`

**Query parameters:** `format`, `lang`

**Response:** JSON array of endpoint descriptor objects (structure varies by API version).

---

### 4.10 POST /submit

Submit a joke for review. Joke submissions are manually curated before appearing in the API. **Submissions are currently disabled** on the live API; check `/info` for current status.

**URL:** `POST https://v2.jokeapi.dev/submit`

**Query parameters:**

| Parameter | Description |
| --- | --- |
| `dry-run` | Validate the payload without saving. No value needed; presence of the parameter enables it. |

**Request body** (JSON, `Content-Type: application/json`):

```json
{
  "formatVersion": 3,
  "category": "Programming",
  "type": "single",
  "joke": "Why do programmers prefer dark mode? Because light attracts bugs.",
  "flags": {
    "nsfw": false,
    "religious": false,
    "political": false,
    "racist": false,
    "sexist": false,
    "explicit": false
  },
  "lang": "en"
}
```

For `type: "twopart"`, replace `"joke"` with `"setup"` and `"delivery"`:

```json
{
  "formatVersion": 3,
  "category": "Misc",
  "type": "twopart",
  "setup": "Why did the scarecrow win an award?",
  "delivery": "Because he was outstanding in his field.",
  "flags": { "nsfw": false, "religious": false, "political": false, "racist": false, "sexist": false, "explicit": false },
  "lang": "en"
}
```

**Submission constraints:**
- `category` must be one of the canonical names — **not** `"Any"`
- Only Unicode code points U+0000–U+0FFF are accepted
- `id` is auto-assigned; do not include it
- Body must not exceed 5,120 bytes
- Returns HTTP 201 on success

---

## 5. Joke Categories

| Category | Description |
| --- | --- |
| `Any` | Wildcard — selects from all categories randomly. Cannot be used as a submit target. |
| `Misc` | Miscellaneous jokes that don't fit other categories. Alias: `Miscellaneous` |
| `Programming` | Jokes about software development, languages, computers. Alias: `Coding` |
| `Dark` | Dark-humour jokes. Excluded by safe mode. |
| `Pun` | Wordplay and pun-based jokes |
| `Spooky` | Halloween/horror-themed jokes |
| `Christmas` | Christmas and holiday-themed jokes |

**Category aliases** (introduced in v2.3.0): `Coding` resolves to `Programming`; `Miscellaneous` resolves to `Misc`. Use canonical names in new code.

**Combining categories:**

| Syntax | Behaviour |
| --- | --- |
| `/joke/Programming` | Only Programming jokes |
| `/joke/Programming,Misc` | Programming **or** Misc jokes |
| `/joke/Programming+Misc` | Same as comma — union |
| `/joke/Any-Dark` | Any category **except** Dark |
| `/joke/Any-Dark-Spooky` | Any except Dark and Spooky |

---

## 6. Blacklist Flags

Flags describe content properties of a joke. Setting a flag in `blacklistFlags` **excludes** jokes that have that flag set to `true`.

| Flag | What it marks |
| --- | --- |
| `nsfw` | Not Safe For Work — broadly offensive or adult content |
| `religious` | References to religion or religious figures |
| `political` | Political content or commentary |
| `racist` | Racist content |
| `sexist` | Sexist content |
| `explicit` | Explicit language / profanity |

All flags are `boolean` in every joke response. A joke can have multiple flags simultaneously.

**Usage in request:**

```
?blacklistFlags=nsfw,religious,explicit
```

or equivalently:

```
?blacklistFlags=nsfw+religious+explicit
```

---

## 7. Response Formats

Controlled by the `?format` parameter. Default is `json`.

### JSON (default)

Standard JSON object. See §10 for full schemas.

### XML

Same fields wrapped in `<data>` root element. Boolean values are the strings `"true"` / `"false"`.

```xml
<data>
  <category>Programming</category>
  <type>single</type>
  <joke>// This line doesn't do anything, but the code breaks when deleted.</joke>
  <flags>
    <nsfw>false</nsfw>
    <religious>false</religious>
    <political>false</political>
    <racist>false</racist>
    <sexist>false</sexist>
    <explicit>false</explicit>
  </flags>
  <id>12</id>
  <safe>true</safe>
  <lang>en</lang>
</data>
```

### YAML

Same fields as JSON, serialized as YAML.

```yaml
category: Programming
type: single
joke: "// This line doesn't do anything, but the code breaks when deleted."
flags:
  nsfw: false
  religious: false
  political: false
  racist: false
  sexist: false
  explicit: false
id: 12
safe: true
lang: en
```

### Plain text (txt)

Returns **only the joke content** as a raw string, with no metadata. For single-type jokes this is the joke text. For twopart jokes this is `setup\ndelivery` (newline-separated). Error responses in txt format are plain text descriptions.

**Note:** The `jokeapi` Python wrapper always requests JSON internally regardless of the `format` parameter. The `?format` parameter only matters when calling the raw HTTP endpoint directly.

**Compression:** Responses for requests returning 5 or more jokes are compressed with Brotli, Gzip, or Deflate depending on the `Accept-Encoding` header sent by the client.

---

## 8. Language Support

JokeAPI distinguishes two language categories:

| Type | Description | Current codes |
| --- | --- | --- |
| **Joke languages** | Languages that have actual joke content | `en`, `de` (others may be added) |
| **System languages** | Languages with translated error/system messages | `en`, `de`, `ru` |

- The `?lang` parameter controls both which jokes are returned **and** which language error messages use.
- If a requested language has no jokes, the API returns an error.
- Non-English joke content is not guaranteed for quality by the API author.
- Use `/langcode/{language}` to resolve a human name (`"german"`) to a code (`"de"`).
- Use `/languages` to enumerate all currently available codes programmatically.

**Total joke count at time of writing:** ~1,368 across all languages.

---

## 9. Safe Mode

Enabled by adding `safe-mode` (no value) to the query string:

```
GET /joke/Any?safe-mode
```

Safe mode applies an automatic, conservative filter:
- Excludes the `Dark` category entirely
- Excludes any joke where **any** blacklist flag is `true`
- The `safe` field in the response will be `true` for all jokes returned

Safe mode and `blacklistFlags` can be combined; safe mode is the more restrictive superset.

**Limitation:** Safe mode relies on human curation of the joke database. Occasional edge cases may still slip through.

---

## 10. Response Schemas

### 10.1 Single joke

```json
{
  "error": false,
  "category": "Programming",
  "type": "single",
  "joke": "Why do Java developers wear glasses? Because they don't C#.",
  "flags": {
    "nsfw": false,
    "religious": false,
    "political": false,
    "racist": false,
    "sexist": false,
    "explicit": false
  },
  "safe": true,
  "id": 178,
  "lang": "en"
}
```

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | `false` on success |
| `category` | string | Canonical category name |
| `type` | `"single"` | Joke structure |
| `joke` | string | The joke text |
| `flags` | object | All six boolean flags |
| `safe` | boolean | `true` if no flags are set and category is not Dark |
| `id` | integer | Unique joke ID within its language |
| `lang` | string | ISO 639-1 code of the joke |

### 10.2 Two-part joke

```json
{
  "error": false,
  "category": "Programming",
  "type": "twopart",
  "setup": "What is a dying programmer's last program?",
  "delivery": "Goodbye, world!",
  "flags": {
    "nsfw": false,
    "religious": false,
    "political": false,
    "racist": false,
    "sexist": false,
    "explicit": false
  },
  "safe": true,
  "id": 58,
  "lang": "en"
}
```

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | `false` on success |
| `category` | string | Canonical category name |
| `type` | `"twopart"` | Joke structure |
| `setup` | string | The question / premise |
| `delivery` | string | The punchline |
| `flags` | object | All six boolean flags |
| `safe` | boolean | `true` if no flags are set and category is not Dark |
| `id` | integer | Unique joke ID within its language |
| `lang` | string | ISO 639-1 code of the joke |

### 10.3 Multiple jokes (`amount` >= 2)

```json
{
  "error": false,
  "jokes": [
    {
      "category": "Misc",
      "type": "single",
      "joke": "...",
      "flags": { "nsfw": false, "religious": false, "political": false, "racist": false, "sexist": false, "explicit": false },
      "safe": true,
      "id": 7,
      "lang": "en"
    },
    {
      "category": "Programming",
      "type": "twopart",
      "setup": "...",
      "delivery": "...",
      "flags": { "nsfw": false, "religious": false, "political": false, "racist": false, "sexist": false, "explicit": false },
      "safe": false,
      "id": 22,
      "lang": "en"
    }
  ],
  "amount": 2
}
```

Individual joke objects inside `jokes` follow the same schema as §10.1 / §10.2. The top-level `error` field is still present.

### 10.4 Error response

Returned when `error` is `true`. The HTTP status code will be 4xx or 5xx.

```json
{
  "error": true,
  "internalError": false,
  "code": 106,
  "message": "No matching joke found",
  "causedBy": [
    "No jokes were found that match your provided filter(s)"
  ],
  "additionalInfo": "There are no jokes that match all the provided filter parameters in the requested category.",
  "timestamp": 1579170794412
}
```

| Field | Type | Description |
| --- | --- | --- |
| `error` | boolean | Always `true` |
| `internalError` | boolean | `true` if the error originated server-side; `false` if caused by a bad request |
| `code` | integer | JokeAPI internal error code (see §12) |
| `message` | string | Short human-readable description |
| `causedBy` | string[] | List of specific contributing factors |
| `additionalInfo` | string | Detailed explanation |
| `timestamp` | integer | 13-digit Unix timestamp (ms) of the request |

---

## 11. HTTP Status Codes

| Code | Name | Meaning |
| --- | --- | --- |
| `200` | OK | Request succeeded; joke(s) returned |
| `201` | Created | Joke submission accepted and queued for review |
| `400` | Bad Request | Malformed request (invalid parameter, missing field, etc.) |
| `403` | Forbidden | Client IP is blacklisted for suspected malicious activity |
| `404` | Not Found | The requested endpoint path does not exist |
| `413` | Payload Too Large | Request body exceeds 5,120 bytes (submit endpoint) |
| `414` | URI Too Long | URL exceeds 250 characters |
| `429` | Too Many Requests | Rate limit exceeded; check `Retry-After` header |
| `500` | Internal Server Error | Unhandled server-side error |
| `523` | Origin Unreachable | API is offline for maintenance |

---

## 12. Error Codes

JokeAPI error codes are returned in the `code` field of error responses. Known codes:

| Code | Meaning |
| --- | --- |
| `100` | URL scheme not HTTPS |
| `101` | Endpoint not found |
| `102` | Parameter not supported by endpoint |
| `103` | Invalid parameter value |
| `104` | Invalid category |
| `105` | Invalid blacklist flag |
| `106` | No matching joke found (filters too restrictive) |
| `107` | Internal parsing error |
| `108` | Invalid joke type |
| `109` | Too many jokes requested (>10) |
| `110` | Invalid ID range |
| `111` | Invalid `amount` value |
| `112` | Invalid language code |
| `113` | Joke ID not found |
| `114` | Invalid `contains` string |

**Note:** This list reflects publicly documented codes; the API may return additional undocumented codes for internal errors.

---

## 13. BotOfTheSpecter Callsites

This project uses a single call pattern: fetch one joke at a time via the `jokeapi` Python wrapper. No raw HTTP calls are made to JokeAPI from within the codebase.

### Call pattern

```python
from jokeapi import Jokes

# Stable / beta (sync, run in executor)
j = Jokes()
result = await loop.run_in_executor(None, j.get_joke)

# v6 (async)
j = await Jokes()
result = await j.get_joke()
```

The wrapper calls `GET /joke/Any` internally. No category, type, or blacklist parameters are forwarded by the wrapper — the bot post-filters category against its own per-channel MySQL blacklist by looping `get_joke()` until an acceptable category is returned.

### Files

| File | Import line | Joke command lines | Notes |
| --- | --- | --- | --- |
| `./bot/bot.py` | 37 | ~3242–3318 | Stable. Sync `Jokes()` + `run_in_executor`. Defends against list return shape. Has per-channel blacklist loop. |
| `./bot/beta.py` | 39 | ~5177–5246 | Beta. Same pattern as stable. |
| `./bot/beta-v6.py` | 39 | ~4273–4321 | v6. `await Jokes()` then `await get_joke()`. No list-shape defence. |
| `./bot/kick.py` | 28 | ~985–1001 | Kick bot. Simpler — no per-channel blacklist. |
| `./api/api.py` | 38 | — | Imported, currently unused at API layer. |

### Endpoints actually used

| Endpoint | Used? | How |
| --- | --- | --- |
| `GET /joke/Any` | Yes | Via `jokeapi` wrapper (`get_joke()`) |
| `GET /info` | No | — |
| `GET /categories` | No | Categories are hard-coded in bot config |
| `GET /langcode/{language}` | No | — |
| `GET /languages` | No | — |
| `GET /flags` | No | — |
| `GET /formats` | No | — |
| `GET /ping` | No | — |
| `POST /submit` | No | — |

### Known gotchas

- **Constructor is sync in stable/beta, async in v6.** Don't mix patterns — `Jokes()` vs `await Jokes()`.
- **Some wrapper versions return a `list` instead of a `dict`.** `bot.py` and `beta.py` guard against this; `beta-v6.py` does not.
- **Blacklist loop is unbounded.** If a streamer blacklists all categories the loop never terminates. A max-attempts guard should be added when this code is next modified.
- **Wrapper doesn't expose `blacklistFlags` or `safe-mode`.** To use those parameters, call the raw HTTP endpoint instead of the wrapper.
