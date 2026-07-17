---
name: openweathermap-onecall-api
description: Use when calling, extending, or debugging OpenWeatherMap One Call API 3.0 - the 5 endpoints (current/forecast, timemachine, day_summary, overview, AI assistant), the always-applies units gotcha (default is Kelvin + m/s, NOT Celsius), exclude= rules, error envelope shape, weather condition code ranges, icon URL format, and integration with the BotOfTheSpecter weather flow (Specter API proxy at ./api/api.py - bot files never hit OpenWeatherMap directly).
---

# OpenWeatherMap One Call API 3.0

Reference for One Call API 3.0 - OpenWeatherMap's proprietary unified weather endpoint. Five endpoints under `https://api.openweathermap.org/data/3.0/onecall/*` plus an AI assistant under `https://api.openweathermap.org/assistant/*`. Subscription is **"One Call by Call"** (separate from the legacy 2.5 plans): 1,000 free calls/day by default, pay-per-call beyond.

This file documents the API surface. For BotOfTheSpecter-specific integration (proxy at `./api/api.py:3782`, MySQL quota counter in `api_counts`, bot/overlay flow, WeatherAPI.com fallback for Kick), see `./.grok/docs/API/External/weather.md`.

## When to use this skill

- Adding any new weather feature (forecast, alerts, historical, daily summary, AI assistant)
- Debugging unexpected One Call response shape (missing `current`, wrong units, polar-region surprises)
- Choosing between the 5 endpoints
- Picking the right `exclude=` value to save quota
- Extending `./api/api.py:3829` (`fetch_weather_data`) to return more than just `current`
- Triaging an OpenWeatherMap error response (`{cod, message, parameters}`)
- Adding the AI Weather Assistant (`/assistant/session`) - note: AI assistant prompts are free, but the weather lookups it performs count against your One Call quota

Skip this file if you're working on the Kick.com `!weather` command - that uses WeatherAPI.com, a different provider with a different shape. See `weather.md`.

## The 5 endpoints

| # | Endpoint | Purpose | Quota cost per call |
|---|----------|---------|---------------------|
| 1 | `GET /data/3.0/onecall` | Current + minute (1h) + hourly (48h) + daily (8d) + alerts | 1 |
| 2 | `GET /data/3.0/onecall/timemachine` | One specific timestamp, 1979-01-01 → +4 days ahead | 1 |
| 3 | `GET /data/3.0/onecall/day_summary` | Aggregated single day, 1979-01-02 → +1.5 years ahead | 1 |
| 4 | `GET /data/3.0/onecall/overview` | Human-readable AI summary for today or tomorrow | 1 |
| 5 | `POST /assistant/session` + `POST /assistant/session/{session_id}` | Conversational AI weather assistant | 0 for the prompt itself, but any location lookup it triggers internally calls #1 and counts |

Base URL: `https://api.openweathermap.org`

Auth (all endpoints):
- Endpoints 1–4: query param `&appid={key}`
- Endpoint 5: HTTP header `X-Api-Key: {key}` (POST, JSON body)

In this repo the key lives in env var `WEATHER_API`, loaded at `./api/api.py:66`. Never hardcode. Never log in full. See `secrets.md`.

## Endpoint 1 - `/data/3.0/onecall` (current + forecasts + alerts)

```text
GET /data/3.0/onecall?lat={lat}&lon={lon}&appid={key}
                    [&exclude={parts}][&units={units}][&lang={lang}]
```

**Required:** `lat` (−90..90), `lon` (−180..180), `appid`.

**`exclude`** - comma-delimited, **no spaces**. Valid values: `current`, `minutely`, `hourly`, `daily`, `alerts`. Excluding a block does NOT save quota (you're still charged 1 call), but it shrinks the response. Use it to keep parsing simple and bandwidth low.

Common patterns:
- `exclude=minutely,hourly,daily,alerts` - current only. **This is what `./api/api.py:3831` uses.**
- `exclude=current,minutely,hourly,daily` - alerts only (the "alerts for location" use case)
- `exclude=minutely` - current + hourly + daily + alerts; skip the minute-by-minute block which is huge

**`units`** - `standard` (default - **Kelvin** + m/s), `metric` (Celsius + m/s), `imperial` (Fahrenheit + mph). **Always pass this explicitly.** The default returning Kelvin is the #1 bug source.

**`lang`** - translates `weather[].description` only. 47 codes supported (en, fr, de, zh_cn, zh_tw, ja, ko, hi, ar, ru, es, pt_br, etc.). Numeric `id` and `main` stay English. Branch on `id`, not `description`.

### Response (top-level)

```json
{
  "lat": 33.44,
  "lon": -94.04,
  "timezone": "America/Chicago",
  "timezone_offset": -18000,
  "current": { ... },        // unless excluded
  "minutely": [ ... ],       // 60 entries (1/min for 1h), unless excluded
  "hourly":   [ ... ],       // 48 entries (1/hr for 48h), unless excluded
  "daily":    [ ... ],       // 8 entries (today + 7 days), unless excluded
  "alerts":   [ ... ]        // ABSENT entirely when no alerts active
}
```

### `current` block (key fields)

| Field | Type | Notes |
|-------|------|-------|
| `dt`, `sunrise`, `sunset` | int Unix UTC | `sunrise`/`sunset` **absent** in polar midnight-sun / polar-night periods |
| `temp`, `feels_like`, `dew_point` | float | Units per the `units=` param |
| `pressure` | int hPa | Sea level |
| `humidity` | int % | |
| `clouds` | int % | Cloudiness |
| `uvi` | float | UV index |
| `visibility` | int m | **Capped at 10000** |
| `wind_speed`, `wind_gust` | float | m/s or mph; `wind_gust` only "where available" |
| `wind_deg` | int | Meteorological degrees from N |
| `rain.1h`, `snow.1h` | float | mm/h. **mm/h only - units= doesn't affect these.** Only present if precipitation is happening |
| `weather` | array | Always at least one element; multiple if compound conditions (rain + thunderstorm) |
| `weather[].id` | int | **Numeric condition code** - branch on this, not `description` |
| `weather[].main` | string | English group: `Clear`, `Clouds`, `Rain`, `Snow`, `Thunderstorm`, `Drizzle`, `Atmosphere` |
| `weather[].description` | string | Translated if `lang=` set |
| `weather[].icon` | string | e.g. `01d`, `10n` - see [Icon URL](#icon-url) |

### `minutely` block

Array of 60 objects, one per minute for the next hour:
```json
[{ "dt": 1595243460, "precipitation": 2.928 }, ...]
```
`precipitation` is **mm/h only** (not affected by `units=`).

### `hourly` block

Array of 48 objects, one per hour for the next 48 hours. Same fields as `current` plus:
- `pop` - Probability of precipitation, **0.0–1.0** (not 0–100)

### `daily` block

Array of 8 objects (today + 7 days). Has `sunrise`/`sunset` (absent in polar regions), `moonrise`/`moonset`/`moon_phase`, `summary` (human-readable day description), and:

- `temp` and `feels_like` are **objects, not scalars**:
  ```json
  "temp": { "morn": ..., "day": ..., "eve": ..., "night": ..., "min": ..., "max": ... }
  "feels_like": { "morn": ..., "day": ..., "eve": ..., "night": ... }
  ```
- `rain` / `snow` are **scalar mm totals** for the day (not the `.1h` object shape used in `current`/`hourly`)
- `pop` - 0.0–1.0
- `uvi` - daily **max**, not midday

`moon_phase`: 0 and 1 = new moon, 0.25 = first quarter, 0.5 = full, 0.75 = last quarter. Intermediate values are linear between.

### `alerts` block

```json
[{
  "sender_name": "NWS Philadelphia - Mount Holly ...",
  "event": "Small Craft Advisory",
  "start": 1684952747,
  "end":   1684988747,
  "description": "...",
  "tags": [...]
}]
```

**The `alerts` key is absent from the response entirely when there are no active alerts** - don't `KeyError` on it. Always use `.get("alerts", [])` or equivalent.

`description` is the raw alert text from the agency (often in ALL CAPS with embedded newlines). Default language is English; some agencies provide local-language only and the `lang=` param does **not** translate this field.

## Endpoint 2 - `/data/3.0/onecall/timemachine`

```text
GET /data/3.0/onecall/timemachine?lat={lat}&lon={lon}&dt={unix}&appid={key}[&units=][&lang=]
```

One specific moment. `dt` accepts any Unix timestamp from **1979-01-01 onwards, up to 4 days into the future**. Returns one weather snapshot (object inside a `data` array of length 1):

```json
{
  "lat": 52.2297, "lon": 21.0122,
  "timezone": "Europe/Warsaw", "timezone_offset": 3600,
  "data": [{
    "dt": 1645888976,
    "sunrise": 1645853361, "sunset": 1645891727,
    "temp": 279.13, "feels_like": 276.44,
    "pressure": 1029, "humidity": 64, "dew_point": 272.88,
    "uvi": 0.06, "clouds": 0, "visibility": 10000,
    "wind_speed": 3.6, "wind_deg": 340,
    "weather": [{"id": 800, "main": "Clear", "description": "clear sky", "icon": "01d"}]
  }]
}
```

Gotchas:
- **Historical `uvi` only goes back 5 days** by default. Older UV index requires a contact-OpenWeatherMap request.
- `data` is an array of one - don't expect multiple timestamps per call.
- For ranges, you call this endpoint N times (and pay N quota units).

## Endpoint 3 - `/data/3.0/onecall/day_summary`

```text
GET /data/3.0/onecall/day_summary?lat={lat}&lon={lon}&date={YYYY-MM-DD}&appid={key}
                                 [&units=][&lang=][&tz=±HH:MM]
```

Aggregated weather for one calendar day. Range: **1979-01-02 to +1.5 years ahead** (note: not 1979-01-01 - the timemachine endpoint covers that day).

`date` is `YYYY-MM-DD`. Optional `tz` overrides the auto-detected timezone in `±HH:MM` format (e.g. `+03:00`); when set, the "morning/afternoon/evening/night" buckets are computed relative to that timezone.

Response (no `data` wrapper):
```json
{
  "lat": 33, "lon": 35, "tz": "+02:00",
  "date": "2020-03-04", "units": "standard",
  "cloud_cover":   { "afternoon": 0 },
  "humidity":      { "afternoon": 33 },
  "precipitation": { "total": 0 },
  "pressure":      { "afternoon": 1015 },
  "temperature":   { "min": 286.48, "max": 299.24,
                     "morning": 287.59, "afternoon": 296.15,
                     "evening": 295.93, "night": 289.56 },
  "wind":          { "max": { "speed": 8.7, "direction": 120 } }
}
```

Buckets are at fixed local hours: **morning = 06:00, afternoon = 12:00, evening = 18:00, night = 00:00**.

## Endpoint 4 - `/data/3.0/onecall/overview`

```text
GET /data/3.0/onecall/overview?lat={lat}&lon={lon}&appid={key}[&date=YYYY-MM-DD][&units=]
```

AI-generated paragraph summarising the weather. `date` is optional - today or tomorrow only. Defaults to today.

```json
{
  "lat": 51.509865, "lon": -0.118092,
  "tz": "+01:00", "date": "2024-05-13", "units": "metric",
  "weather_overview": "The current weather is overcast with a temperature of 16°C ..."
}
```

Good for: dashboard widgets, TTS announcements, chat `!weather` variants that want a friendlier sentence. Not suitable for programmatic decisions - the text is unstructured.

No `lang` param supported on this endpoint as of the docs reviewed; output language follows the user's account default.

## Endpoint 5 - AI Weather Assistant

Two HTTP calls. Free per call, but **internal weather lookups it performs count against your One Call quota**.

### Start a session

```http
POST https://api.openweathermap.org/assistant/session
Content-Type: application/json
X-Api-Key: {key}

{ "prompt": "What's weather like in London?" }
```

### Continue a session

```http
POST https://api.openweathermap.org/assistant/session/{session_id}
Content-Type: application/json
X-Api-Key: {key}

{ "prompt": "Do I need a hat?" }
```

Response:
```json
{
  "answer": "Hello! Right now in London, it's quite cloudy ...",
  "data": { "London": { ... full current/minutely/hourly/daily payload ... } },
  "session_id": "d47d2211-f1cf-409c-8297-617d74945571"
}
```

- `data` is **empty `{}`** when the assistant didn't need to fetch fresh weather (e.g. follow-up question about the same location it just looked up).
- Numeric values in `data` are **always Kelvin + m/s** (the AI assistant ignores any `units` preference).
- Persist `session_id` if you want conversational continuity; new POST to `/assistant/session` resets context.
- Supported in 50+ languages - pass the prompt in any of them; the assistant replies in the same language.

There's also a web interface at `https://openweathermap.org/weather-assistant?apikey={key}` for testing without writing code.

## Units - the most common gotcha

| `units` value | Temperature | Wind speed | Pressure | Humidity | Rain/Snow |
|---------------|-------------|------------|----------|----------|-----------|
| (unset / `standard`) | **Kelvin** | m/s | hPa | % | mm/h |
| `metric` | Celsius | m/s | hPa | % | mm/h |
| `imperial` | Fahrenheit | mph | hPa | % | mm/h |

**Always pass `units=` explicitly.** Forgetting it returns Kelvin and breaks every display.

`pressure`, `humidity`, `rain.1h`, `snow.1h`, `pop`, `clouds`, `visibility`, `uvi` are **never** affected by `units=`. Only `temp*`, `feels_like*`, `dew_point`, `wind_speed`, and `wind_gust`.

**The repo currently fetches the One Call endpoint twice - once with `units=metric` and once with `units=imperial` - to show both °C/°F and m/s/mph in chat (`./api/api.py:3831`).** This costs 2 quota units per `!weather` invocation. To reduce to 1, fetch metric only and convert imperial client-side:
- °F = °C × 9/5 + 32
- mph = m/s × 2.23694
- (kph = m/s × 3.6)

## Multilingual support

47 language codes for `lang=` (translates `weather[].description`, `daily.summary`, and the AI assistant's `answer`). The codes the docs explicitly list:

`sq af ar az eu be bg ca zh_cn zh_tw hr cz da nl en fi fr gl de el he hi hu is id it ja kr ku la lt mk no fa pl pt pt_br ro ru sr sk sl sp es sv se th tr ua uk vi zu`

(Some have synonyms: `sp` and `es` both = Spanish; `sv` and `se` both = Swedish; `ua` and `uk` both = Ukrainian.)

## Icon URL

`weather[].icon` is just the code (e.g. `01d`, `10n`). To get an image URL:

```text
https://openweathermap.org/img/wn/{icon}.png       # 50×50
https://openweathermap.org/img/wn/{icon}@2x.png    # 100×100
https://openweathermap.org/img/wn/{icon}@4x.png    # 200×200
```

Repo builds the `@2x` form at `./api/api.py:3851`. The trailing `d`/`n` is day/night - OpenWeatherMap derives this from the **sun's position at the location**, not from the request time, so an evening request for a daytime hemisphere correctly returns `d`.

## Weather condition codes

`weather[].id` is a 3-digit code. **Branch on this, not on `description`.**

| Range | Group | `main` value |
|-------|-------|--------------|
| 2xx | Thunderstorm | `Thunderstorm` |
| 3xx | Drizzle | `Drizzle` |
| 5xx | Rain | `Rain` |
| 6xx | Snow | `Snow` |
| 7xx | Atmosphere (mist, fog, dust, haze, smoke, sand, ash, squall, tornado) | `Mist`/`Smoke`/`Haze`/etc. |
| 800 | Clear sky | `Clear` |
| 80x | Clouds (801 few, 802 scattered, 803 broken, 804 overcast) | `Clouds` |

Full table: https://openweathermap.org/weather-conditions - fetch that page if you need exact mappings (e.g. `511` = freezing rain, `622` = heavy shower snow).

## Error response envelope

All endpoints return this shape on error:

```json
{
  "cod": 400,
  "message": "Invalid date format",
  "parameters": ["date"]
}
```

- `cod` is an integer (sometimes returned as string - handle both)
- `parameters` is optional; only present for input-validation errors
- 401 = bad/missing key (or key not subscribed to One Call 3.0)
- 404 = location not found / coordinates out of supported range
- 429 = quota exceeded - back off

**Detect errors by checking for the expected payload block, not by HTTP status alone.** The repo wrapper at `./api/api.py:3829` raises `ValueError` if `current` is missing from the response, because OpenWeatherMap sometimes returns 200 with an error envelope on quota issues.

## Polar regions

For locations inside the Arctic / Antarctic circles during midnight-sun or polar-night periods:
- `sunrise` and `sunset` keys are **absent** from `current` and each `daily` entry
- The rest of the payload is normal

Always `.get("sunrise")` rather than indexing.

## Recommended call cadence

The OpenWeatherMap docs say the underlying model is updated every 10 minutes. Polling more often than that wastes quota for stale data. The BotOfTheSpecter weather command is on-demand (no polling), but any new dashboard widget or overlay that auto-refreshes should cap itself at one call per 10 minutes per `(lat, lon)`.

## BotOfTheSpecter integration map

Currently used:
- `GET /data/3.0/onecall` with `exclude=minutely,hourly,daily,alerts` → at `./api/api.py:3831` via `fetch_weather_data()`

Not currently used (would extend this skill's footprint):
- `daily` block - for a forecast feature on the overlay
- `alerts` block - for severe-weather notifications via TTS or Discord
- `/timemachine` - for "weather on this day last year" features
- `/day_summary` - for streamer-set "historic stream day" recaps
- `/overview` - for the AI-summary version of `!weather`
- `/assistant/session` - would slot into the existing OpenAI chat flow

Rules if adding any of the above:
1. **Always route through `./api/api.py`.** Bot files never call OpenWeatherMap directly - see `data-flow.md`. The proxy is the global quota gate.
2. **Update `api_counts` decrement.** Currently 3 per `!weather` (1 geocode + 1 metric + 1 imperial). New endpoints add to that - see `./api/api.py:501,574,3805`.
3. **Emit results over WebSocket** if overlays should receive them - don't poll the API from overlays (`overlays.md`).
4. **Schema additions go in `format_weather_data()`** (`./api/api.py:3839`) so overlay HTML and bot chat formatters get the new fields consistently across `bot.py`, `beta.py`, `beta-v6.py`.
5. **No second weather provider as a fallback** without coordinating - the Kick bot's WeatherAPI.com integration is intentionally separate, not a fallback (see `weather.md`).

## Quick reference

| Want | Endpoint | Key fields | Quota |
|------|----------|------------|-------|
| Current conditions | `/onecall?exclude=minutely,hourly,daily,alerts` | `current.*` | 1 |
| Alerts only for a location | `/onecall?exclude=current,minutely,hourly,daily` | `alerts[]` | 1 |
| 7-day forecast | `/onecall?exclude=minutely,hourly,alerts` | `daily[]` | 1 |
| Hourly for next 2 days | `/onecall?exclude=minutely,daily,alerts` | `hourly[]` | 1 |
| Weather "right now" plus next-hour rain | `/onecall?exclude=hourly,daily,alerts` | `current` + `minutely[]` | 1 |
| Historical / past timestamp | `/onecall/timemachine?dt=` | `data[0]` | 1 per timestamp |
| Aggregated day stats | `/onecall/day_summary?date=YYYY-MM-DD` | `temperature` / `wind` / `precipitation` | 1 |
| AI human-readable summary | `/onecall/overview` | `weather_overview` | 1 |
| Conversational AI | `POST /assistant/session` then `/session/{id}` | `answer` + `data` | 0 for prompt; weather lookups inside count |

## Common mistakes

| Mistake | Fix |
|---------|-----|
| Omitting `units=` and getting Kelvin | Always pass `units=metric` or `imperial` |
| Branching on `weather[].description` text | Branch on `weather[].id` (numeric, never translated) |
| `KeyError` on `alerts` | Use `.get("alerts", [])` - key absent when no alerts |
| `KeyError` on `sunrise`/`sunset` for polar regions | Always `.get()` |
| Treating `daily.temp` as a scalar | It's an object: `{morn, day, eve, night, min, max}` |
| Treating `daily.rain` as `{1h: ...}` | It's a scalar mm total at `daily` level; the `.1h` object shape is `current`/`hourly` only |
| Treating `pop` as 0–100 | It's 0.0–1.0 |
| Setting `exclude` with spaces (`current, hourly`) | No spaces: `current,hourly` |
| Setting `exclude` to skip the only block you need | Excluded blocks are gone from the response - re-read the request |
| Calling `/timemachine` for date ranges | One timestamp per call; loop with N quota units |
| Calling OpenWeatherMap directly from a bot file | Route through `./api/api.py` so `api_counts` stays accurate |
| Adding a second `units` fetch in a new feature | Convert client-side instead - quota doubles per call otherwise |
| Polling more often than every 10 min | Underlying model updates every 10 min; faster polls waste quota |
| Logging the key | Log length or last-4 only (see `secrets.md`) |
