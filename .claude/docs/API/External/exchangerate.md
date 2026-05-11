# ExchangeRate-API v6 Reference

ExchangeRate-API v6 is a JSON REST API for currency conversion. BotOfTheSpecter uses it to power the `!convert` chat command.

- **Base URL:** `https://v6.exchangerate-api.com`
- **API version prefix:** `/v6/`
- **Transport:** HTTPS only
- **Response format:** JSON

---

## 1. Authentication

Two methods are supported. The bot uses **in-URL authentication** (the default).

### 1a. In-URL (default)

The API key is the third path segment of every URL. No headers required.

```
GET https://v6.exchangerate-api.com/v6/{YOUR-API-KEY}/latest/USD
```

The key appears in server-access logs if any proxy or load balancer logs the full URL. For this project, all error paths scrub the key before logging:

```python
str(e).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')
```

Preserve this pattern in every new error path that might surface a URL.

### 1b. Bearer header (alternative)

Pass the key via `Authorization` header; omit it from the URL path.

```
GET https://v6.exchangerate-api.com/v6/latest/USD
Authorization: Bearer YOUR-API-KEY
```

This prevents key leakage into URL logs. The bot does not use this form, but it is available on all plans if the logging risk ever needs to be addressed.

### Key source (BotOfTheSpecter)

- **Env var:** `EXCHANGE_RATE_API` (loaded from `/home/botofthespecter/.env` on server)
- **Module-level variable:** `EXCHANGE_RATE_API_KEY` in `./bot/bot.py`, `./bot/beta.py`, `./bot/beta-v6.py`, `./bot/kick.py`

---

## 2. Plans, Rate Limits, and Update Frequency

| Plan | Monthly quota | Historical data | Enriched data | Rate update frequency |
| ---- | ------------- | --------------- | ------------- | --------------------- |
| Free | 1,500 req/month | No | No | Once per UTC day |
| Pro | Higher quota | Yes (from 2021) | No | Once per UTC day |
| Business | Higher quota | Yes (from 1990) | Yes | Once per UTC day |
| Volume | Highest quota | Yes (from 1990) | Yes | Once per UTC day |

- **Quota reset:** Resets on the anniversary of the day you signed up — not necessarily the 1st of the month. The `refresh_day_of_month` field in the quota endpoint tells you the exact day.
- **Reporting delay:** Usage may take 5–60 minutes to appear in the `/quota` endpoint after calls are made.
- **No per-second rate limit** is documented for any tier.
- **Cache hint:** `time_next_update_unix` tells you the earliest moment rates can change. No call before that timestamp will return different rates.

BotOfTheSpecter is on the **Free plan**: 1,500 requests/month, no historical or enriched access.

---

## 3. Error Response (all endpoints)

Every endpoint returns the same error envelope when a request fails. HTTP status codes are not relied upon — always check `result`.

```json
{
  "result": "error",
  "error-type": "quota-reached"
}
```

A 200 HTTP response can carry `"result": "error"`. Always check `result` before reading data fields.

### `error-type` values

| `error-type` | Meaning |
| ------------ | ------- |
| `unsupported-code` | One or both currency codes are not in the supported list. |
| `malformed-request` | URL structure is broken — typically a missing path segment or wrong segment order. |
| `invalid-key` | API key is wrong or has been revoked. |
| `inactive-account` | Account email has not been confirmed after sign-up. |
| `quota-reached` | Monthly request quota is exhausted. |
| `no-data-available` | Historical endpoint only — no exchange rate record exists for the requested date. |
| `plan-upgrade-required` | Endpoint requires a higher-tier plan (e.g., enriched data on Free or Pro). |

---

## 4. Endpoints

### 4.1 Standard / Latest Rates

Returns all conversion rates from one base currency to all ~165 supported currencies. Available on all plans including Free.

```
GET /v6/{KEY}/latest/{BASE_CODE}
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key (in-URL auth) |
| `BASE_CODE` | ISO 4217 three-letter code for the base currency (e.g., `USD`) |

**Success response:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "time_last_update_unix": 1585267200,
  "time_last_update_utc": "Fri, 27 Mar 2020 00:00:00 +0000",
  "time_next_update_unix": 1585353600,
  "time_next_update_utc": "Sat, 28 Mar 2020 00:00:00 +0000",
  "base_code": "USD",
  "conversion_rates": {
    "USD": 1,
    "AED": 3.6725,
    "EUR": 0.9105,
    "GBP": 0.7734,
    "JPY": 149.82
  }
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `time_last_update_unix` | integer | Unix timestamp of the last rate update |
| `time_last_update_utc` | string | Human-readable UTC datetime of the last update |
| `time_next_update_unix` | integer | Unix timestamp of the next scheduled update |
| `time_next_update_utc` | string | Human-readable UTC datetime of the next update |
| `base_code` | string | The base currency code as given in the request |
| `conversion_rates` | object | Map of `{ "CURRENCY_CODE": rate }` for all ~165 supported currencies; base currency always has value `1` |

---

### 4.2 Historical Rates

Returns the exchange rates on a specific past date. Pro, Business, and Volume plans only.

#### Without amount

```
GET /v6/{KEY}/history/{BASE_CODE}/{YEAR}/{MONTH}/{DAY}
```

#### With amount

```
GET /v6/{KEY}/history/{BASE_CODE}/{YEAR}/{MONTH}/{DAY}/{AMOUNT}
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key |
| `BASE_CODE` | ISO 4217 base currency code |
| `YEAR` | Four-digit year (e.g., `2023`) |
| `MONTH` | Month **without** leading zero (e.g., `3` for March, not `03`) |
| `DAY` | Day **without** leading zero (e.g., `9`, not `09`) |
| `AMOUNT` | Optional decimal amount; when provided, response returns pre-computed converted amounts instead of raw rates |

**Data availability:**

| Date range | Currencies available |
| ---------- | -------------------- |
| 2021-01-01 to present | All ~165 supported currencies |
| 1990-01-01 to 2020-12-31 | 35 major currencies only (AUD, BRL, CAD, CHF, CNY, EUR, GBP, JPY, USD, and others) |

**Success response — without amount:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "year": 2020,
  "month": 3,
  "day": 27,
  "base_code": "USD",
  "conversion_rates": {
    "USD": 1,
    "EUR": 0.9105,
    "GBP": 0.7734
  }
}
```

**Success response — with amount:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "year": 2020,
  "month": 3,
  "day": 27,
  "base_code": "USD",
  "requested_amount": 100.0,
  "conversion_amounts": {
    "USD": 100.0,
    "EUR": 91.05,
    "GBP": 77.34
  }
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `year` | integer | Year of the requested date |
| `month` | integer | Month of the requested date (no leading zero) |
| `day` | integer | Day of the requested date (no leading zero) |
| `base_code` | string | Base currency code |
| `conversion_rates` | object | *(without amount)* Map of `{ "CODE": rate }` — rate relative to 1 unit of base |
| `requested_amount` | number | *(with amount)* The amount supplied in the request |
| `conversion_amounts` | object | *(with amount)* Map of `{ "CODE": converted_amount }` — pre-computed for the requested amount |

Additional error-type specific to this endpoint: `no-data-available` (no rate record exists for the requested date).

---

### 4.3 Pair Conversion — Without Amount

Returns the exchange rate between exactly two currencies. Produces a smaller, faster response than the standard endpoint. Available on all plans including Free.

```
GET /v6/{KEY}/pair/{BASE_CODE}/{TARGET_CODE}
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key |
| `BASE_CODE` | ISO 4217 source currency code (e.g., `EUR`) |
| `TARGET_CODE` | ISO 4217 target currency code (e.g., `GBP`) |

**Success response:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "time_last_update_unix": 1585267200,
  "time_last_update_utc": "Fri, 27 Mar 2020 00:00:00 +0000",
  "time_next_update_unix": 1585353600,
  "time_next_update_utc": "Sat, 28 Mar 2020 00:00:00 +0000",
  "base_code": "EUR",
  "target_code": "GBP",
  "conversion_rate": 0.8412
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `time_last_update_unix` | integer | Unix timestamp of the last rate update |
| `time_last_update_utc` | string | Human-readable UTC datetime of the last update |
| `time_next_update_unix` | integer | Unix timestamp of the next scheduled update |
| `time_next_update_utc` | string | Human-readable UTC datetime of the next update |
| `base_code` | string | Source currency code |
| `target_code` | string | Target currency code |
| `conversion_rate` | number | How many units of `target_code` equal 1 unit of `base_code` |

---

### 4.4 Pair Conversion — With Amount

Same as 4.3 but includes a pre-computed `conversion_result` for the supplied amount. Available on all plans including Free. **This is the endpoint BotOfTheSpecter uses.**

```
GET /v6/{KEY}/pair/{BASE_CODE}/{TARGET_CODE}/{AMOUNT}
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key |
| `BASE_CODE` | ISO 4217 source currency code |
| `TARGET_CODE` | ISO 4217 target currency code |
| `AMOUNT` | Decimal amount to convert (format `xxxx.xxxx`) |

**Success response:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "time_last_update_unix": 1585267200,
  "time_last_update_utc": "Fri, 27 Mar 2020 00:00:00 +0000",
  "time_next_update_unix": 1585353600,
  "time_next_update_utc": "Sat, 28 Mar 2020 00:00:00 +0000",
  "base_code": "EUR",
  "target_code": "GBP",
  "conversion_rate": 0.8412,
  "conversion_result": 5.8884
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `time_last_update_unix` | integer | Unix timestamp of the last rate update |
| `time_last_update_utc` | string | Human-readable UTC datetime of the last update |
| `time_next_update_unix` | integer | Unix timestamp of the next scheduled update |
| `time_next_update_utc` | string | Human-readable UTC datetime of the next update |
| `base_code` | string | Source currency code |
| `target_code` | string | Target currency code |
| `conversion_rate` | number | How many units of `target_code` equal 1 unit of `base_code` |
| `conversion_result` | number | `AMOUNT × conversion_rate`, pre-computed by the API |

---

### 4.5 Enriched Data

Returns the pair conversion rate plus rich metadata about the target currency: country name, ISO country code, full currency name, display symbol, and a flag image URL. Business and Volume plans only.

```
GET /v6/{KEY}/enriched/{BASE_CODE}/{TARGET_CODE}
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key |
| `BASE_CODE` | ISO 4217 source currency code (e.g., `GBP`) |
| `TARGET_CODE` | ISO 4217 target currency code (e.g., `JPY`) |

**Success response:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "time_last_update_unix": 1585267200,
  "time_last_update_utc": "Fri, 27 Mar 2020 00:00:00 +0000",
  "time_next_update_unix": 1585353600,
  "time_next_update_utc": "Sat, 28 Mar 2020 00:00:00 +0000",
  "base_code": "GBP",
  "target_code": "JPY",
  "conversion_rate": 135.42,
  "target_data": {
    "locale": "Japan",
    "two_letter_code": "JP",
    "currency_name": "Japanese Yen",
    "currency_name_short": "Yen",
    "display_symbol": "00A5",
    "flag_url": "https://www.exchangerate-api.com/img/flag-icons/jp.png"
  }
}
```

**Top-level fields** (same as pair conversion plus `target_data`):

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `time_last_update_unix` | integer | Unix timestamp of the last rate update |
| `time_last_update_utc` | string | Human-readable UTC datetime of the last update |
| `time_next_update_unix` | integer | Unix timestamp of the next scheduled update |
| `time_next_update_utc` | string | Human-readable UTC datetime of the next update |
| `base_code` | string | Source currency code |
| `target_code` | string | Target currency code |
| `conversion_rate` | number | How many units of `target_code` equal 1 unit of `base_code` |
| `target_data` | object | Enriched metadata about the target currency — see below |

**`target_data` fields:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `locale` | string | Country or region name for the target currency (e.g., `"Japan"`) |
| `two_letter_code` | string | ISO 3166-1 alpha-2 country code (e.g., `"JP"`) |
| `currency_name` | string | Full currency name (e.g., `"Japanese Yen"`) |
| `currency_name_short` | string | Abbreviated currency name (e.g., `"Yen"`) |
| `display_symbol` | string | Comma-delimited Unicode hex codepoints for the currency symbol (e.g., `"00A5"` for ¥). Render as `&#x{HEX};` — e.g., `&#x00A5;` — to display the symbol in HTML. |
| `flag_url` | string | Absolute URL to a country flag image hosted by ExchangeRate-API |

Additional error-type specific to this endpoint: `plan-upgrade-required` (account is not on Business or Volume plan).

---

### 4.6 Supported Codes

Returns the list of all currency codes currently supported by the API. Available on all plans.

```
GET /v6/{KEY}/codes
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key |

**Success response:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "supported_codes": [
    ["AED", "UAE Dirham"],
    ["AFN", "Afghan Afghani"],
    ["ALL", "Albanian Lek"],
    ["AUD", "Australian Dollar"],
    ["USD", "United States Dollar"]
  ]
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `supported_codes` | array | Array of two-element arrays: `[currency_code, currency_name]`. Currently ~165 entries. |

Use this endpoint to validate user-supplied currency codes before making a conversion call, or to populate a dropdown.

---

### 4.7 Request Quota

Returns the account's quota state. Calls to this endpoint count against the monthly quota.

```
GET /v6/{KEY}/quota
```

| Parameter | Description |
| --------- | ----------- |
| `KEY` | API key |

**Success response:**

```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  "plan_quota": 1500,
  "requests_remaining": 1243,
  "refresh_day_of_month": 14
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `result` | string | `"success"` or `"error"` |
| `documentation` | string | Link to API docs |
| `terms_of_use` | string | Link to terms of use |
| `plan_quota` | integer | Total requests allowed per billing period |
| `requests_remaining` | integer | Requests remaining until quota reset |
| `refresh_day_of_month` | integer | Day of the month the quota resets (anniversary of sign-up date, not necessarily the 1st) |

**Reporting delay:** Usage takes 5–60 minutes to appear here after calls are made. Do not rely on this for real-time counting — the local `api_counts` table in `website` DB is more current.

---

## 5. Supported Currency Codes

The API supports approximately 165 currencies. Retrieve the live list programmatically via the `/codes` endpoint (section 4.6).

Notable exceptions and caveats:

- **KPW (North Korean Won)** is not supported due to sanctions and lack of international trade activity.
- **Volatile currencies** — the API uses rates published by the respective central banks for these, which may differ substantially from market/parallel rates: ARS, LYD, SSP, SYP, VES, YER, ZWL.
- ISO 4217 codes can be deprecated and replaced over time. Always validate against the live `/codes` endpoint rather than a hardcoded list.

---

## 6. BotOfTheSpecter Callsites

### Upstream calls

| File | Lines | Notes |
| ---- | ----- | ----- |
| `./bot/bot.py` | 9215–9255 | Stable `!convert`, `convert_currency` async function. Calls `pair/{FROM}/{TO}/{AMOUNT}`. |
| `./bot/beta.py` | 13202–13241 | Beta equivalent. |
| `./bot/beta-v6.py` | 10688–10728 | v6 equivalent. |
| `./bot/kick.py` | 78 (import) | Key loaded; `!convert` also present in the Kick bot. |

All four share the same single `EXCHANGE_RATE_API` env var and therefore the same monthly quota.

### Internal proxy endpoint

`./api/api.py:2814–2838` — `GET /api/exchangerate`

Returns the local cached count from `website.api_counts` (`type='exchangerate'`). Not a live call upstream. Used by the dashboard to display "X of 1500 requests remaining; resets in N days." The monthly reset background task is at `./api/api.py:580–597`.

After each successful upstream call, every callsite decrements the `api_counts` row by 1. Crashes mid-call or calls from multiple processes can cause the local count to drift from real upstream usage.

### Key loading

- Env var: `EXCHANGE_RATE_API`
- Documented in `./bot/.env.example:23` and `./help/run_yourself.php:1196`
- Loaded at startup: `./bot/bot.py:77,9889`, `./bot/beta.py:105,13941`, `./bot/beta-v6.py:99,11371`, `./bot/kick.py:78`

### Operational notes

- **Key in path, not header.** All error paths must scrub with `.replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')` to prevent key leakage in log files.
- **`result` is a string.** Compare with `== "success"`. A 200 HTTP response can still carry `"result": "error"`.
- **One key, four bots.** Stable, beta, v6, and Kick all draw from the same quota.
- **Amount in URL = distinct cache key per amount.** If a caching layer is ever added, cache the rate-only form (`/pair/{FROM}/{TO}`) and multiply locally.
- **No automatic key rotation.** Rotating the key requires updating `/home/botofthespecter/.env` (server) and restarting all four processes that imported it at startup.
- **Enriched and Historical endpoints are not available on the Free plan.** Adding calls to those endpoints will return `plan-upgrade-required` until the account is upgraded.
