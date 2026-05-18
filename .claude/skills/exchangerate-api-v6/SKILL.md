---
name: exchangerate-api-v6
description: Use when calling, extending, or debugging ExchangeRate-API v6 — the currency conversion API behind BotOfTheSpecter's `!convert` chat command. Covers all 7 endpoints (latest, history, pair, pair-with-amount, enriched, codes, quota), the `result` envelope (200 OK can still carry `"result": "error"`), the 7 `error-type` values, in-URL vs Bearer auth and the mandatory key-scrubbing pattern on logged errors, the Free-plan limits (1,500/month, no historical, no enriched), and the four bot files that share one `EXCHANGE_RATE_API` env var and quota.
---

# ExchangeRate-API v6

JSON REST API for currency conversion. Base URL `https://v6.exchangerate-api.com`, all paths prefixed `/v6/`. HTTPS only.

This file documents the API surface. For BotOfTheSpecter-specific integration detail (every callsite, the `api_counts` local counter, the monthly-reset background task, key-loading lines), see `./.claude/docs/API/External/exchangerate.md`.

## When to use this skill

- Adding any currency-related feature (Twitch points → USD, donation conversion display, multi-currency tipping)
- Debugging a `!convert` failure or a `"result": "error"` response
- Extending `convert_currency()` in `bot.py` / `beta.py` / `beta-v6.py` / `kick.py`
- Validating a user-supplied currency code (pre-check via `/codes`)
- Implementing the dashboard's "requests remaining" widget more accurately
- Considering historical-rates or enriched-data features (both require plan upgrade — Free plan blocks them)
- Triaging quota drift between the local `api_counts` row and the upstream `/quota` endpoint
- Reviewing any new code path that builds a URL containing the API key — the scrubbing pattern is mandatory

## Auth — pick one

### In-URL (what this project uses)

Key is the **third path segment** of every request. No headers.

```text
GET https://v6.exchangerate-api.com/v6/{KEY}/latest/USD
```

**Hard rule for this codebase:** every error path that might log a URL or an exception string must scrub the key first:

```python
sanitized_error = str(e).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')
```

Existing callsites at `./bot/bot.py:6688,9250,9253`. **Preserve this in every new error path you add.** Reverse proxies and load balancers log full URLs by default — a leaked key here means rotating it across all four bot processes.

### Bearer header (not currently used)

If logging risk ever needs to be addressed, switch to header auth — the key disappears from URL logs entirely:

```text
GET https://v6.exchangerate-api.com/v6/latest/USD
Authorization: Bearer {KEY}
```

Available on all plans. Mechanical swap: drop the `{KEY}/` segment from the URL, add the header. Don't mix forms in the same request — pick one per call.

### Key source in this repo

- Env var: `EXCHANGE_RATE_API` (loaded from `/home/botofthespecter/.env` on the server)
- Module-level variable in code: `EXCHANGE_RATE_API_KEY`
- Loaded at startup in `./bot/bot.py:77,9889`, `./bot/beta.py:105,13941`, `./bot/beta-v6.py:99,11371`, `./bot/kick.py:78`
- Never hardcoded, never logged. See `secrets.md`.

## The 7 endpoints

| # | Endpoint | Free plan? | Returns |
|---|----------|-----------|---------|
| 1 | `GET /v6/{KEY}/latest/{BASE}` | ✅ | `conversion_rates` to all ~165 currencies |
| 2 | `GET /v6/{KEY}/history/{BASE}/{Y}/{M}/{D}` | ❌ Pro+ | Rates on a past date |
| 2a | `GET /v6/{KEY}/history/{BASE}/{Y}/{M}/{D}/{AMOUNT}` | ❌ Pro+ | Past-date rates pre-multiplied |
| 3 | `GET /v6/{KEY}/pair/{FROM}/{TO}` | ✅ | One `conversion_rate` |
| 4 | `GET /v6/{KEY}/pair/{FROM}/{TO}/{AMOUNT}` | ✅ | One `conversion_rate` + `conversion_result` — **this is what `!convert` calls** |
| 5 | `GET /v6/{KEY}/enriched/{FROM}/{TO}` | ❌ Business+ | Rate + country/flag/symbol metadata |
| 6 | `GET /v6/{KEY}/codes` | ✅ | Array of `[code, name]` pairs for all supported currencies |
| 7 | `GET /v6/{KEY}/quota` | ✅ | `plan_quota`, `requests_remaining`, `refresh_day_of_month` (and counts against quota itself) |

Currency codes are **ISO 4217 three-letter** (USD, EUR, GBP, AUD, JPY...). Pass them uppercase.

For historical (endpoint 2): `MONTH` and `DAY` are passed **without leading zeros** — `/3/9`, not `/03/09`.

## Response envelope (all endpoints)

Every response — success or failure — has a top-level `result` field. **HTTP 200 is not enough; always check `result == "success"` before reading data fields.**

Success:
```json
{
  "result": "success",
  "documentation": "https://www.exchangerate-api.com/docs",
  "terms_of_use": "https://www.exchangerate-api.com/terms",
  ...endpoint-specific fields
}
```

Error:
```json
{
  "result": "error",
  "error-type": "quota-reached"
}
```

### `error-type` values

| `error-type` | Meaning | Action |
|--------------|---------|--------|
| `unsupported-code` | One of the currency codes isn't in the supported list | Validate against `/codes`; surface a clean message to the user |
| `malformed-request` | URL is structurally wrong (missing segment, wrong order, bad amount format) | Fix the URL builder; this is a bug, not user input |
| `invalid-key` | Key is wrong or revoked | Operator alert — rotate the env var |
| `inactive-account` | Account email never confirmed | Operator alert |
| `quota-reached` | Monthly quota exhausted | Show "out of quota until {refresh_day_of_month}" |
| `no-data-available` | Historical endpoint only — no record for that date | User-supplied dates: tell the user; programmatic: pick a nearer date |
| `plan-upgrade-required` | Endpoint is gated by plan tier (historical needs Pro+; enriched needs Business+) | Don't call this endpoint on Free; gate by plan in code |

Field name is literally `error-type` with a hyphen — quote the key in Python: `response.get("error-type")`.

## Endpoint detail

### 1. Latest rates — `/latest/{BASE}`

Returns every supported rate from `BASE` to ~165 other currencies.

```json
{
  "result": "success",
  "time_last_update_unix": 1585267200,
  "time_last_update_utc": "Fri, 27 Mar 2020 00:00:00 +0000",
  "time_next_update_unix": 1585353600,
  "time_next_update_utc": "Sat, 28 Mar 2020 00:00:00 +0000",
  "base_code": "USD",
  "conversion_rates": {
    "USD": 1,
    "AUD": 1.4817,
    "EUR": 0.9013,
    "GBP": 0.7679,
    "JPY": 149.82
  }
}
```

- `conversion_rates[BASE]` is always `1`.
- Rate is "1 unit of base = N units of target". So at the rates above, $1 USD = ¥149.82 JPY.
- **`time_next_update_unix` is a cache hint** — no call before that timestamp will return different rates. Rates only update **once per UTC day**.

### 2. Historical — `/history/{BASE}/{Y}/{M}/{D}[/{AMOUNT}]`

Pro+ plans only. Free plan returns `plan-upgrade-required`.

Without amount → `conversion_rates`. With amount → `conversion_amounts` (pre-multiplied) plus `requested_amount`. Same shape as `/pair` vs `/pair-with-amount`.

Data range:
- 2021-01-01 → present: all ~165 currencies
- 1990-01-01 → 2020-12-31: 35 major currencies only

Missing date → `error-type: no-data-available`.

### 3 & 4. Pair conversion — `/pair/{FROM}/{TO}[/{AMOUNT}]`

Free plan. Smaller, faster response than `/latest`.

Without amount:
```json
{
  "result": "success",
  "time_last_update_unix": 1585267200,
  "time_last_update_utc": "Fri, 27 Mar 2020 00:00:00 +0000",
  "time_next_update_unix": 1585353600,
  "time_next_update_utc": "Sat, 28 Mar 2020 00:00:00 +0000",
  "base_code": "EUR",
  "target_code": "GBP",
  "conversion_rate": 0.8412
}
```

With amount — adds `conversion_result` (= `AMOUNT × conversion_rate`, computed upstream):
```json
{ ..., "conversion_rate": 0.8412, "conversion_result": 5.8884 }
```

**Amount format is `xxxx.xxxx`.** Pass as a numeric path segment — `/pair/USD/JPY/100` or `/pair/USD/JPY/100.50`. No currency formatting, no commas.

**`!convert` uses the with-amount form** at `./bot/bot.py:9218`, mirrored across the beta/v6/kick bots.

### 5. Enriched — `/enriched/{FROM}/{TO}`

Business+ plans only. Adds `target_data` with country name, ISO-3166 alpha-2 code, full currency name, short name, **Unicode codepoint for the symbol** (not the symbol itself), and a flag image URL.

```json
{
  ...standard pair fields...,
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

`display_symbol` is a hex codepoint (or comma-separated list of them). Render in HTML as `&#x{HEX};` — `&#x00A5;` → `¥`. In Python: `chr(int("00A5", 16))`.

### 6. Supported codes — `/codes`

Free plan. Returns the live list of supported currency codes. Cheap call (counts as 1 against quota) — cache the result locally rather than calling on every conversion.

```json
{
  "result": "success",
  "supported_codes": [
    ["AED", "UAE Dirham"],
    ["AFN", "Afghan Afghani"],
    ["AUD", "Australian Dollar"],
    ["USD", "United States Dollar"]
  ]
}
```

Use this to validate user-supplied codes **before** the conversion call — a pre-check costs 1 quota but a bad code returns `unsupported-code` which also costs 1 and gives a worse error path.

### 7. Quota — `/quota`

Available on all plans. Returns the account's billing state:

```json
{
  "result": "success",
  "plan_quota": 30000,
  "requests_remaining": 25623,
  "refresh_day_of_month": 17
}
```

| Field | Notes |
|-------|-------|
| `plan_quota` | Total requests allowed per billing period. Free = 1,500; Pro = 30,000 (the docs' own example); higher tiers above |
| `requests_remaining` | Requests left until reset |
| `refresh_day_of_month` | Day of the month quota resets — **anniversary of sign-up date**, not the 1st |

**This call counts against the quota itself.** Per the docs, this was originally free, but users put it in `while(1){}` loops and generated tens of millions of useless requests — so the API team made it billable. Don't poll it.

**5–60 minute reporting delay** — usage takes time to surface here, so this lags reality. Useful for occasional health checks, not for real-time counting.

**Narrower error set than the other endpoints.** `/quota` only returns three `error-type` values:
- `invalid-key`
- `inactive-account`
- `quota-reached`

It will never return `unsupported-code`, `malformed-request`, `no-data-available`, or `plan-upgrade-required` (the path takes no currency, no date, and isn't gated by plan).

For real-time counting in this project, the local `website.api_counts` row (`type='exchangerate'`) decremented on every call is more current than `/quota`. See `./api/api.py:2814–2838` (the proxy endpoint that exposes the local count) and `./api/api.py:580–597` (the monthly reset task). Treat `/quota` as a reconciliation check at most — e.g., compare against the local counter once a day.

## Plan tiers (relevant fields only)

| Plan | Monthly quota | Historical | Enriched | Updates |
|------|---------------|-----------|----------|---------|
| Free (what this project is on) | 1,500 | ❌ | ❌ | 1×/day |
| Pro | Higher | ✅ (from 2021) | ❌ | 1×/day |
| Business | Higher | ✅ (from 1990) | ✅ | 1×/day |
| Volume | Highest | ✅ (from 1990) | ✅ | 1×/day |

No per-second rate limits documented. Rates update **once per UTC day** on every plan — there is no "real-time" tier.

## Supported currencies

~165 codes via `/codes`. Notable caveats:

- **KPW (North Korean Won) is not supported.**
- **Volatile-currency rates come from central bank publications, not market rates.** Affects ARS, LYD, SSP, SYP, VES, YER, ZWL — the API rate may differ substantially from what users see on the parallel market. Don't promise "live market rate" for these.
- **ISO 4217 codes get deprecated and replaced.** Don't hardcode a currency list — fetch from `/codes` and cache.

## BotOfTheSpecter integration map

Current usage:
- `!convert` command in all four bots calls `/pair/{FROM}/{TO}/{AMOUNT}` (endpoint 4)
- Dashboard quota widget reads the **local** `website.api_counts` row via the proxy at `./api/api.py:2814–2838` — does NOT call `/quota` upstream

Rules for any new ExchangeRate-API integration:

1. **Always scrub the key on logged errors.** Every new error path: `str(e).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')`. The key is in the URL — exception strings often include it.
2. **Always check `result == "success"`.** HTTP 200 doesn't guarantee success on this API.
3. **Decrement `api_counts` on every successful call.** One key, four bot processes, one shared 1,500/month quota — local counting is how the dashboard shows "remaining". See the existing callsites for the pattern.
4. **Don't call `/quota` on a timer.** It has a 5–60 min reporting lag AND each call burns 1 quota. Trust the local counter.
5. **Don't add `/history` or `/enriched` calls without a plan upgrade.** Both return `plan-upgrade-required` on Free. If a feature genuinely needs them, confirm the upgrade first.
6. **Validate currency codes against `/codes`** (cached) before user-driven conversion calls. Saves quota on `unsupported-code` errors and gives better UX.
7. **Cache rates client-side using `time_next_update_unix`.** If displaying the same rate to many users, hit `/pair` once before that timestamp and reuse — rates can't change before then.
8. **For multiplied results across many amounts of the same pair**, prefer `/pair/{FROM}/{TO}` (no amount) and multiply locally. Each `/pair/{FROM}/{TO}/{AMOUNT}` with a different amount is a distinct URL — useless to cache.
9. **No new request that puts the key in a header AND the path simultaneously.** Pick one auth form per call.
10. **Route data flow correctly.** `!convert` is a bot-direct call to the upstream API, not via the Specter API proxy — unlike weather, this one bypasses `./api/api.py`. Maintain that pattern unless adding a feature that genuinely needs central rate-limiting; see `data-flow.md`.

## Common mistakes

| Mistake | Fix |
|---------|-----|
| Treating HTTP 200 as success | Always check `result == "success"` |
| Forgetting to scrub the key from error logs | Add `.replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')` to every new error path |
| Accessing `response["error-type"]` without the quotes | Hyphen → must use bracket notation, not `.error_type` |
| Padding months/days with leading zeros on `/history` | `/3/9`, not `/03/09` |
| Hardcoding the currency list | Fetch `/codes` and cache; ISO codes change |
| Calling `/quota` to display remaining requests | Use the local `api_counts` counter — `/quota` lags 5–60 min and burns quota |
| Polling for fresh rates inside the same UTC day | Rates update 1×/day — use `time_next_update_unix` as a cache TTL |
| Assuming "market rate" for ARS/LYD/SSP/SYP/VES/YER/ZWL | These are central-bank rates — they can diverge wildly from parallel-market rates |
| Calling `/history` or `/enriched` from this project | Free plan returns `plan-upgrade-required` |
| Mixing in-URL and Bearer auth in one call | Pick one form per call |
| Putting commas in the `AMOUNT` segment | Plain decimal: `100`, `100.50`, `1234.5678` |
| Logging the full request URL anywhere | URL contains the key — log path-after-key only, or use Bearer auth |

## Quick reference

| Want | Endpoint | Free? |
|------|----------|-------|
| All rates from one base | `/v6/{KEY}/latest/{BASE}` | ✅ |
| One rate, two currencies | `/v6/{KEY}/pair/{FROM}/{TO}` | ✅ |
| One rate + pre-multiplied amount | `/v6/{KEY}/pair/{FROM}/{TO}/{AMOUNT}` ← **`!convert`** | ✅ |
| Past-date rates | `/v6/{KEY}/history/{BASE}/{Y}/{M}/{D}` | ❌ Pro+ |
| Past-date pre-multiplied | `/v6/{KEY}/history/{BASE}/{Y}/{M}/{D}/{AMOUNT}` | ❌ Pro+ |
| Rate + country/flag/symbol metadata | `/v6/{KEY}/enriched/{FROM}/{TO}` | ❌ Business+ |
| List of all supported currency codes | `/v6/{KEY}/codes` | ✅ |
| Account quota state | `/v6/{KEY}/quota` (counts as 1; lags 5–60 min) | ✅ |
