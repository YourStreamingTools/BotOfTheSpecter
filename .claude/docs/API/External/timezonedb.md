# TimezoneDB API Reference

Local copy of the TimezoneDB API documentation. Source: https://timezonedb.com/api and its linked reference pages. Verified 2026-05-11.

---

## Contents

1. [Overview](#1-overview)
2. [Authentication](#2-authentication)
3. [Rate Limits & Tiers](#3-rate-limits--tiers)
4. [Endpoints](#4-endpoints)
   - 4.1 [list-time-zone](#41-list-time-zone)
   - 4.2 [get-time-zone](#42-get-time-zone)
     - 4.2.1 [by=zone](#421-byzone)
     - 4.2.2 [by=position](#422-byposition)
     - 4.2.3 [by=city (Premium)](#423-bycity-premium)
     - 4.2.4 [by=ip (Premium)](#424-byip-premium)
   - 4.3 [convert-time-zone](#43-convert-time-zone)
5. [Common Parameters](#5-common-parameters)
6. [Response Envelope](#6-response-envelope)
7. [Error Handling](#7-error-handling)
8. [BotOfTheSpecter Callsites](#8-botofthespecter-callsites)

---

## 1. Overview

TimezoneDB provides three REST endpoints for working with time zones: listing all known zones, looking up a zone by various inputs, and converting a timestamp between two zones. All endpoints use HTTP GET and return either XML or JSON.

**Free-tier base URL:** `http://api.timezonedb.com/v2.1/`
**Premium base URL:** `http://vip.timezonedb.com/v2.1/`

The base URL is the only difference between tiers. All endpoint paths and parameter names are identical. Premium unlocks the `by=city` and `by=ip` lookup modes on `get-time-zone`, increases reliability, and removes the 1 req/s rate cap.

---

## 2. Authentication

All requests must include the `key` query parameter containing a valid API key.

```
http://api.timezonedb.com/v2.1/{endpoint}?key=YOUR_API_KEY&...
```

API keys are obtained by registering a free account at https://timezonedb.com/register. There is no header-based authentication — the key is always a query string parameter.

**BotOfTheSpecter env var:** `TIMEZONE_API` (loaded via `os.getenv('TIMEZONE_API')`).

---

## 3. Rate Limits & Tiers

| Tier | Cost | Rate limit | City/IP lookup | Host |
|------|------|------------|----------------|------|
| Free | $0 | **1 request/second** (hard cap — excess requests are blocked) | No | `api.timezonedb.com` |
| Premium | $4.20 USD/month | Unlimited | Yes | `vip.timezonedb.com` |

The free tier has no monthly request cap — only the per-second rate limit applies. Exceeding the rate limit returns a failure response (see §7), not an HTTP error code.

---

## 4. Endpoints

### 4.1 `list-time-zone`

Returns a list of all time zones supported by TimezoneDB, with optional filtering.

**URL:** `GET http://api.timezonedb.com/v2.1/list-time-zone`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | API key (see §2) |
| `format` | string | No | Response format: `xml` or `json`. Default: `xml` |
| `callback` | string | No | JSONP callback function name. Only used when `format=json` |
| `fields` | string | No | Comma-separated list of fields to include in each zone object. No spaces. Available values: `countryCode`, `countryName`, `zoneName`, `gmtOffset`, `dst`, `timestamp`. Default: `countryCode,countryName,zoneName,gmtOffset,timestamp` |
| `country` | string | No | Filter by ISO 3166-1 alpha-2 country code (e.g. `US`, `AU`, `NZ`) |
| `zone` | string | No | Filter by time zone name. Supports `*` wildcard (e.g. `*New*` matches any zone containing "New") |

#### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | `"OK"` on success, `"FAILED"` on error |
| `message` | string | Error description. Empty string on success |
| `zones` | array | Array of zone objects (see below) |
| `zones[].countryCode` | string | ISO 3166-1 alpha-2 country code |
| `zones[].countryName` | string | Full country name |
| `zones[].zoneName` | string | IANA time zone identifier (e.g. `Australia/Sydney`) |
| `zones[].gmtOffset` | integer | UTC offset in seconds (e.g. `36000` = UTC+10) |
| `zones[].dst` | integer | Whether DST is currently active: `0` = no, `1` = yes |
| `zones[].timestamp` | integer | Current local time as a Unix timestamp |

The fields returned depend on the `fields` parameter. Only requested fields appear in each zone object.

#### Example Requests

```
# All zones (JSON)
http://api.timezonedb.com/v2.1/list-time-zone?key=YOUR_API_KEY&format=json

# Filter by country
http://api.timezonedb.com/v2.1/list-time-zone?key=YOUR_API_KEY&format=json&country=NZ

# Filter by country + wildcard zone name
http://api.timezonedb.com/v2.1/list-time-zone?key=YOUR_API_KEY&format=json&country=US&zone=*New*

# Custom fields only
http://api.timezonedb.com/v2.1/list-time-zone?key=YOUR_API_KEY&format=json&zone=Asia/Tokyo&fields=zoneName,gmtOffset
```

#### Example Success Response

```json
{
  "status": "OK",
  "message": "",
  "zones": [
    {
      "countryCode": "NZ",
      "countryName": "New Zealand",
      "zoneName": "Pacific/Auckland",
      "gmtOffset": 43200,
      "timestamp": 1464537416
    }
  ]
}
```

#### Example Custom Fields Response

```json
{
  "status": "OK",
  "message": "",
  "zones": [
    {
      "zoneName": "Asia/Tokyo",
      "gmtOffset": 32400
    }
  ]
}
```

---

### 4.2 `get-time-zone`

Returns the local time, UTC offset, DST status, and related metadata for a single time zone, looked up by one of four methods controlled by the `by` parameter.

**URL:** `GET http://api.timezonedb.com/v2.1/get-time-zone`
**Premium URL:** `GET http://vip.timezonedb.com/v2.1/get-time-zone`

#### Common Parameters (all `by` modes)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | API key (see §2) |
| `by` | string | Yes | Lookup method: `zone`, `position`, `city`, `ip` |
| `format` | string | No | Response format: `xml` or `json`. Default: `xml` |
| `callback` | string | No | JSONP callback function name |
| `fields` | string | No | Comma-separated field names to return. Available values: `countryCode`, `countryName`, `regionName`, `cityName`, `zoneName`, `abbreviation`, `gmtOffset`, `dst`, `zoneStart`, `zoneEnd`, `nextAbbreviation`, `timestamp`, `formatted`. Default: all fields |
| `time` | integer | No | Unix timestamp (UTC) to evaluate at. Default: current server time |

#### Response Fields

| Field | Type | Premium only | Description |
|-------|------|:---:|-------------|
| `status` | string | No | `"OK"` on success, `"FAILED"` on error |
| `message` | string | No | Error description. Empty string on success |
| `countryCode` | string | No | ISO 3166-1 alpha-2 country code |
| `countryName` | string | No | Full country name |
| `regionName` | string | Yes | Region or state name |
| `cityName` | string | Yes | City or place name |
| `zoneName` | string | No | IANA time zone identifier (e.g. `America/Chicago`) |
| `abbreviation` | string | No | Zone abbreviation at the given time (e.g. `EDT`, `AEST`) |
| `gmtOffset` | integer | No | UTC offset in seconds (e.g. `-18000` = UTC-5) |
| `dst` | string | No | Whether DST is currently active: `"0"` = no, `"1"` = yes. **Note: this is a string, not an integer or boolean.** |
| `zoneStart` | integer | No | Start of the current DST period as a Unix timestamp |
| `zoneEnd` | integer | No | End of the current DST period as a Unix timestamp |
| `nextAbbreviation` | string | No | Zone abbreviation that will apply after the current DST period ends |
| `timestamp` | integer | No | Local time at the given zone as a Unix timestamp |
| `formatted` | string | No | Local time formatted as `Y-m-d H:i:s` (e.g. `"2026-05-10 22:52:12"`) |
| `totalPage` | integer | No | Total result pages (pagination — present in error responses) |
| `currentPage` | integer | No | Current page number (present in error responses) |

`regionName` and `cityName` are present in the response schema for all tiers but are only populated on Premium. Free-tier responses leave them empty or absent.

---

#### 4.2.1 `by=zone`

Look up a time zone by its IANA name or abbreviation.

**Additional required parameter:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `zone` | string | Yes | IANA time zone name (e.g. `America/Chicago`) or abbreviation (e.g. `CDT`) |

**Example request:**

```
http://api.timezonedb.com/v2.1/get-time-zone?key=YOUR_API_KEY&format=json&by=zone&zone=America/Chicago
```

**Example success response:**

```json
{
  "status": "OK",
  "message": "",
  "countryCode": "US",
  "countryName": "United States",
  "zoneName": "America/Chicago",
  "abbreviation": "CDT",
  "gmtOffset": -18000,
  "dst": "1",
  "timestamp": 1778453532,
  "formatted": "2026-05-10 22:52:12"
}
```

---

#### 4.2.2 `by=position`

Look up a time zone by geographic coordinates.

**Additional required parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `lat` | float | Yes | Latitude in decimal degrees (e.g. `40.689247`) |
| `lng` | float | Yes | Longitude in decimal degrees (e.g. `-74.044502`) |

**Example request:**

```
http://api.timezonedb.com/v2.1/get-time-zone?key=YOUR_API_KEY&format=json&by=position&lat=40.689247&lng=-74.044502
```

**Example success response:**

```json
{
  "status": "OK",
  "message": "",
  "countryCode": "US",
  "countryName": "United States",
  "zoneName": "America/New_York",
  "abbreviation": "EDT",
  "gmtOffset": -14400,
  "dst": "1",
  "zoneStart": 1710054000,
  "zoneEnd": 1730613600,
  "nextAbbreviation": "EST",
  "timestamp": 1731234567,
  "formatted": "2026-05-10 14:09:27"
}
```

---

#### 4.2.3 `by=city` (Premium)

Look up a time zone by city name. **Requires a Premium API key and the `vip.timezonedb.com` host.**

**Additional required parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `city` | string | Yes | City name. Supports `*` wildcard (e.g. `Buf*` matches Buffalo) |
| `country` | string | Yes | ISO 3166-1 alpha-2 country code to narrow results |
| `region` | string | No | US region/state code to further narrow results |

**Example request:**

```
http://vip.timezonedb.com/v2.1/get-time-zone?key=YOUR_API_KEY&format=json&by=city&city=Buffalo&country=US
```

---

#### 4.2.4 `by=ip` (Premium)

Look up a time zone from an IPv4 address. **Requires a Premium API key and the `vip.timezonedb.com` host.**

**Additional required parameter:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `ip` | string | Yes | IPv4 address (e.g. `144.6.230.17`) |

**Example request (with custom fields):**

```
http://vip.timezonedb.com/v2.1/get-time-zone?key=YOUR_API_KEY&format=json&by=ip&ip=144.6.230.17&fields=countryCode,cityName,gmtOffset,dst
```

---

### 4.3 `convert-time-zone`

Converts a Unix timestamp from one time zone to another and returns both the original and converted timestamps along with the offset between them.

**URL:** `GET http://api.timezonedb.com/v2.1/convert-time-zone`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | API key (see §2) |
| `from` | string | Yes | Origin time zone — a valid IANA name or abbreviation |
| `to` | string | Yes | Destination time zone — a valid IANA name or abbreviation |
| `format` | string | No | Response format: `xml` or `json`. Default: `xml` |
| `callback` | string | No | JSONP callback function name |
| `fields` | string | No | Comma-separated list of response fields to include |
| `time` | integer | No | Unix timestamp in the origin timezone to convert. Default: current UTC time |

#### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | `"OK"` on success, `"FAILED"` on error |
| `message` | string | Error description. Empty string on success |
| `fromZoneName` | string | IANA name of the origin time zone |
| `fromAbbreviation` | string | Abbreviation of the origin time zone at the given time |
| `fromTimestamp` | integer | The input time as a Unix timestamp (origin zone) |
| `toZoneName` | string | IANA name of the destination time zone |
| `toAbbreviation` | string | Abbreviation of the destination time zone at the converted time |
| `toTimestamp` | integer | The converted time as a Unix timestamp (destination zone) |
| `offset` | integer | Difference in seconds between origin and destination zones (destination − origin) |

#### Example Request

```
http://api.timezonedb.com/v2.1/convert-time-zone?key=YOUR_API_KEY&format=json&from=America/Los_Angeles&to=Australia/Sydney&time=1464793200
```

#### Example Success Response

```json
{
  "status": "OK",
  "message": "",
  "fromZoneName": "America/Los_Angeles",
  "fromAbbreviation": "PDT",
  "fromTimestamp": 1464793200,
  "toZoneName": "Australia/Sydney",
  "toAbbreviation": "AEST",
  "toTimestamp": 1464854400,
  "offset": 61200
}
```

---

## 5. Common Parameters

These parameters are accepted by all three endpoints and behave identically everywhere.

| Parameter | Type | Description |
|-----------|------|-------------|
| `key` | string | API key. Always required. |
| `format` | string | `xml` or `json`. Defaults to `xml` if omitted. Always pass `json` unless you need XML. |
| `callback` | string | Wraps the JSON response in a JavaScript function call for JSONP. Only meaningful with `format=json`. |
| `fields` | string | Comma-separated list of response fields to return. Omit for all fields. No spaces between values. |

---

## 6. Response Envelope

All endpoints share the same outer envelope regardless of format.

**Success:**

```json
{
  "status": "OK",
  "message": "",
  ... endpoint-specific fields ...
}
```

**Failure:**

```json
{
  "status": "FAILED",
  "message": "Human-readable error description.",
  "totalPage": 1,
  "currentPage": 1,
  "zones": []
}
```

- `status` is always a string — compare with `== "OK"` or `!= "OK"`, never treat it as a boolean.
- `message` is always present on failure and always empty on success.
- `zones` is only present in the failure envelope for `list-time-zone`-style responses; other endpoints omit it.

---

## 7. Error Handling

TimezoneDB does not use HTTP error status codes to signal application errors. **All responses return HTTP 200.** The `status` field in the body is the only reliable error indicator.

Common failure messages:

| `message` value | Likely cause |
|----------------|--------------|
| `"Record not found."` | No zone matched the input (bad coords, unknown zone name, city not in DB) |
| `"Invalid API key."` | Key not recognised or account suspended |
| `"You have exceeded the request limit."` | Free tier 1 req/s cap exceeded |

**No retry logic is built into the TimezoneDB client.** If a rate-limit error is returned, the caller must back off before retrying. There are no Retry-After headers.

---

## 8. BotOfTheSpecter Callsites

This section documents how BotOfTheSpecter uses the TimezoneDB API. The endpoints and features described above are the full upstream capability; the callsites below are the subset the bot actually exercises.

### What is called

Only `get-time-zone` with `by=position` is called. No other endpoint or `by` mode is used.

**Request pattern (all three bot versions):**

```python
url = (
    f"http://api.timezonedb.com/v2.1/get-time-zone"
    f"?key={os.getenv('TIMEZONE_API')}"
    f"&format=json"
    f"&by=position"
    f"&lat={latitude}"
    f"&lng={longitude}"
)
```

**Fields consumed:** `status`, `zoneName` only. The `formatted` and `timestamp` fields from TimezoneDB are deliberately ignored — `pytz` and Python `strftime` are the authoritative time source.

**Flow:** `!time <location>` → Nominatim geocodes the text to lat/lng → TimezoneDB resolves lat/lng to `zoneName` → `pytz.timezone(zoneName)` formats the local time.

### Callsite table

| File | Lines (approx.) | Notes |
|------|----------------|-------|
| `./bot/bot.py` | 3191–3209 | Stable `!time` command |
| `./bot/beta.py` | 5126–5144 | Beta equivalent |
| `./bot/beta-v6.py` | 4222–4240 | v6 equivalent |

### Known limitations / hardening targets

- **HTTP not HTTPS.** All callsites use `http://`. TimezoneDB accepts HTTPS but the code has not been updated.
- **Free tier only.** `by=city` and `by=ip` are Premium features. Do not add code paths that depend on them without confirming the production key tier.
- **No retry or backoff.** A rate-limit response surfaces to chat as "Could not retrieve time information from the API."
- **`dst` is a string `"0"` / `"1"`.** Not an integer or boolean — don't compare with `== 1`.
- **Two-stage failure modes.** Nominatim can succeed but return a non-city result (road, POI). The bot filters these before calling TimezoneDB via a `valid_location_types` allowlist. Both stages must succeed.
