# Weather APIs — BotOfTheSpecter Reference

Self-contained reference for the weather data providers used by this project. Two providers are in play:

1. **OpenWeatherMap** (`api.openweathermap.org`) — primary. Used by the API server (`./api/api.py`) which is the single source of weather for the stable Twitch bot, beta Twitch bot, v6 Twitch bot, and the weather overlay.
2. **WeatherAPI.com** (`api.weatherapi.com`) — used **only** by the Kick.com bot (`./bot/kick.py`) for its standalone `!weather` command. Not a fallback for OpenWeatherMap; a separate, simpler integration.

Both providers read their key from the same env var: **`WEATHER_API`**. Whichever bot/server runtime loads `.env` decides which provider that value targets. In production, the OpenWeatherMap account holds the primary key; the Kick bot is configured with a WeatherAPI.com key when deployed (or is left disabled — `cmd_weather` short-circuits if `WEATHER_API_KEY` is unset).

---

## Architecture: who calls what

```text
Twitch !weather (stable / beta / v6)
  → GET https://api.botofthespecter.com/weather?api_key=...&location=...
       (Specter API proxy — ./api/api.py)
       → OpenWeatherMap Geocoding 1.0 (city → lat/lon)
       → OpenWeatherMap One Call 3.0 (lat/lon → current, units=metric)
       → OpenWeatherMap One Call 3.0 (lat/lon → current, units=imperial)
       → WebSocket emit WEATHER_DATA → overlay + bot

Twitch bot WEATHER_DATA handler
  → process_weather_websocket() formats and posts to Twitch chat

Weather overlay (./overlay/weather.php)
  → Socket.io client connects to wss://websocket.botofthespecter.com
  → Renders WEATHER_DATA event in DOM, auto-hides after ~10s

Kick !weather (./bot/kick.py)
  → GET https://api.weatherapi.com/v1/current.json?key=...&q=...&aqi=no
  → Posts result directly to Kick chat (no overlay, no WebSocket)

Profile location validation (dashboard "save weather location")
  → GET https://api.botofthespecter.com/weather/location?api_key=...&location=...
       → OpenWeatherMap Geocoding 1.0 only (no current weather fetch)

Public quota check (admin / status pages)
  → GET https://api.botofthespecter.com/api/weather
       (returns remaining_requests + time until UTC midnight)
```

Important: the Twitch bots **do not call OpenWeatherMap directly**. They call the internal Specter API, which holds the OpenWeatherMap key and centralises rate-limit accounting in MySQL. Removing the proxy and hitting OpenWeatherMap from the bot would defeat the global quota counter.

Repo evidence:
- `./bot/bot.py:2983` — `https://api.botofthespecter.com/weather?api_key={API_TOKEN}&location={location}`
- `./bot/beta.py:4919` — same proxy call
- `./bot/beta-v6.py:4015` — same proxy call
- `./bot/kick.py:1020` — direct WeatherAPI.com call (the only place that does this)
- `./api/api.py:3782` — `fetch_weather_via_api` proxy entry point
- `./api/api.py:3823` — OpenWeatherMap Geocoding direct
- `./api/api.py:3831` — OpenWeatherMap One Call 3.0 (current only)
- `./api/api.py:4266` — `web_weather` location validation endpoint
- `./api/api.py:2849` — `/api/weather` public quota endpoint
- `./overlay/weather.php:74` — `WEATHER_DATA` socket listener

---

## Provider 1 — OpenWeatherMap

### 1.1 Overview

Primary weather provider. Used via the Specter API proxy. Two products are consumed:

- **Geocoding API 1.0** (free) — city/country string → `(lat, lon, name, state, country)`
- **One Call API 3.0** (paid; 1,000 calls/day free tier under the "One Call by Call" subscription) — current conditions for a `(lat, lon)` pair

The 5-day/3-hour Forecast endpoint and the 2.5 Current Weather endpoint are **not** used in this codebase. If a forecast feature is ever added, prefer extending One Call 3.0 (it returns current + minutely + hourly + daily + alerts in one call) before introducing 2.5 endpoints.

### 1.2 Authentication

- Query param: `appid={key}`
- Env var: `WEATHER_API` (loaded at `./api/api.py:66` via `os.getenv('WEATHER_API')`)
- Server location of `.env`: `/home/botofthespecter/.env` (server)
- Never hardcoded. Never logged in full.

### 1.3 Endpoints used

#### 1.3.1 Direct Geocoding

```text
GET https://api.openweathermap.org/geo/1.0/direct?q={location}&limit=1&appid={WEATHER_API}
```

Required:
- `q` — `"City"`, `"City,Country"`, or `"City,State,Country"` (ISO 3166 country codes; state code applies to US only)
- `appid` — API key

Optional:
- `limit` — max results, 1–5

Response (array of objects, take `[0]`):

```json
[
  {
    "name": "Sydney",
    "local_names": { "en": "Sydney", "fr": "Sydney", "...": "..." },
    "lat": -33.8698439,
    "lon": 151.2082848,
    "country": "AU",
    "state": "New South Wales"
  }
]
```

Repo callsite: `./api/api.py:3820` (`get_weather_lat_lon`). The wrapper URL-encodes spaces as `%20` before issuing the request and returns `(location_data, lat, lon)` or `(None, None, None)` if the array is empty.

#### 1.3.2 One Call 3.0 — current

```text
GET https://api.openweathermap.org/data/3.0/onecall
    ?lat={lat}
    &lon={lon}
    &exclude=minutely,hourly,daily,alerts
    &units={metric|imperial}
    &appid={WEATHER_API}
```

Required: `lat`, `lon`, `appid`.

Optional (used here):
- `exclude` — set to `minutely,hourly,daily,alerts` so only `current` is returned (saves bytes)
- `units` — the API server fetches twice, once `metric` and once `imperial`, to display both °C/°F and kph/mph in chat

Optional (not used here):
- `lang` — translates `weather[].description`
- `exclude=current` — would skip the only block this code reads; do not add

Response (relevant subset):

```json
{
  "lat": -33.8698,
  "lon": 151.2083,
  "timezone": "Australia/Sydney",
  "timezone_offset": 36000,
  "current": {
    "dt": 1731139200,
    "temp": 22.4,
    "feels_like": 22.1,
    "humidity": 64,
    "wind_speed": 3.6,
    "wind_deg": 180,
    "weather": [
      {
        "id": 800,
        "main": "Clear",
        "description": "clear sky",
        "icon": "01d"
      }
    ]
  }
}
```

Repo callsite: `./api/api.py:3829` (`fetch_weather_data`). Raises `ValueError` if the `current` key is missing — important because OpenWeatherMap returns `{"cod": 401, "message": "..."}` on auth failure with no `current` block.

#### 1.3.3 Icon URL (static)

```text
https://openweathermap.org/img/wn/{icon}@2x.png
```

Built at `./api/api.py:3851` using the `icon` field from the metric response (e.g. `01d`, `10n`). Served unchanged in the WebSocket payload; the overlay uses it as `<img src>`.

### 1.4 Rate limits / free-tier quotas

One Call 3.0 free tier: **1,000 calls/day** (the "One Call by Call" model). The repo enforces this internally via the `api_counts` table in the `website` MySQL DB:

- Initial row inserted with `count=1000, reset_day=0` if missing (`./api/api.py:501`).
- Reset at UTC midnight back to 1000 (`./api/api.py:574`).
- Each `!weather` invocation decrements by **3**: 1 geocode + 1 metric + 1 imperial (`./api/api.py:3805`).
- Each profile-page location validation decrements by **1** (`./api/api.py:4276`).
- Public visibility: `GET /api/weather` returns `{ requests_remaining, time_remaining }` for status displays.

Geocoding 1.0 is free with generous limits and is **not** counted against the One Call quota in OpenWeatherMap's billing — but this codebase counts it locally as part of the 3-per-command decrement to avoid burst overruns when many channels run `!weather` near reset.

Per-channel cooldowns in the bot also gate request frequency (`builtin_commands.cooldown_rate / cooldown_time / cooldown_bucket` for `command='weather'`).

### 1.5 Geocoding flow

OpenWeatherMap's Geocoding 1.0 does the heavy lifting for `!weather`. **`geopy.Nominatim` is imported in the bot files but is not used by the weather command** — it is used by the `!time` command for timezone lookup (`./bot/bot.py:3177`). Do not confuse the two.

Order of operations for `!weather <location>`:
1. If `location` is empty, the bot loads `profile.weather_location` from the user's per-channel DB (`get_streamer_weather()` at `./bot/bot.py:7464`). If still empty, the API will reject the request with a 404.
2. Bot calls Specter API: `/weather?api_key=...&location=...`.
3. API server URL-encodes spaces, calls OpenWeatherMap Geocoding `q={location}&limit=1`.
4. If the array is empty → API returns 404 `Location '...' not found.` → bot logs and surfaces the error to chat.
5. Otherwise, `(lat, lon)` is passed into two One Call 3.0 requests (metric and imperial) in series.
6. Combined result is formatted and emitted as `WEATHER_DATA` over the WebSocket.

There is no fallback geocoder. If OpenWeatherMap geocoding is down, the command fails. (Adding `geopy.Nominatim` as a fallback would be plausible — it is already a project dependency — but it would not give you the OpenWeatherMap `country`/`state` strings the formatter expects.)

### 1.6 Common gotchas

- **Two-call cost per command.** Because the API fetches metric and imperial separately rather than converting locally, each `!weather` use is at minimum 2 quota units against One Call 3.0 (plus 1 geocode locally counted = 3 total in the `api_counts` row).
- **Units do not affect humidity/pressure.** Only temperature and wind change between `standard`/`metric`/`imperial`. The default (no `units` param) is **Kelvin + m/s**, not Celsius — always pass `units` explicitly.
- **`wind_speed` units.** `metric` = m/s. The API server treats this value as **kph** in the formatter (`./api/api.py:3855` outputs `f"{wind_speed_kph} kph"`). This is technically a unit mislabel — the value is m/s. If precision matters to a downstream consumer, multiply by 3.6 to get true kph. Imperial side: `wind_speed` from OpenWeatherMap with `units=imperial` is mph, which matches the label.
- **Wind direction.** `wind_deg` is degrees from north. `get_wind_direction()` at `./api/api.py:3861` buckets it into 8 cardinals (N/NE/E/SE/S/SW/W/NW) using 22.5° wedges with a wrap-around case for North.
- **Timezone offset.** One Call 3.0 returns `timezone` (IANA name) and `timezone_offset` (seconds from UTC). Currently unused — the overlay reads timezone from `profile.timezone` in the user's DB, not from the weather payload (`./overlay/weather.php:17`).
- **Locale / language.** No `lang` param is sent, so `weather[0].description` is always English (`"clear sky"`, `"light rain"`, etc.). Do not parse `description` for logic — use the numeric `id` or `main` if you need to branch on conditions.
- **Icon code suffix.** The `icon` value embeds day/night (`01d` vs `01n`). OpenWeatherMap derives this from the local sun position at the requested coords, not from the request time — so an evening request for a daytime hemisphere correctly returns `d`.
- **Missing `current` block = error response.** Auth failures, exceeded quota, or invalid lat/lon return JSON without `current`. The wrapper raises `ValueError`; the proxy returns 500. There is no automatic retry.

---

## Provider 2 — WeatherAPI.com

### 2.1 Overview

Used by **`./bot/kick.py`** only. Independent integration; does not go through the Specter API and does not emit WebSocket events. The Kick bot posts the formatted result straight back to the Kick chat. There is no overlay variant for Kick weather.

This is **not** a fallback for OpenWeatherMap. Don't wire it up as one without first deciding how the WebSocket overlay payload should be reconstructed (the formats differ — see 2.6).

### 2.2 Authentication

- Query param: `key={key}`
- Env var: `WEATHER_API` (loaded at `./bot/kick.py:76` as `WEATHER_API_KEY`)
- If unset, `cmd_weather` posts `"Weather API not configured."` and returns (no API call made).

### 2.3 Endpoints used

#### 2.3.1 Current Weather

```text
GET https://api.weatherapi.com/v1/current.json
    ?key={key}
    &q={location}
    &aqi=no
```

Required:
- `key` — API key
- `q` — city name, lat/lon (`"-33.87,151.21"`), zip/postcode, IP address, or `iata:SYD` airport code

Optional (sent here):
- `aqi=no` — disables air-quality block in the response

Optional (not used here):
- `lang` — localises `current.condition.text` (`fr`, `de`, `es`, `pt`, `it`, etc.)

Response (relevant subset):

```json
{
  "location": {
    "name": "Sydney",
    "region": "New South Wales",
    "country": "Australia",
    "lat": -33.87,
    "lon": 151.21,
    "tz_id": "Australia/Sydney",
    "localtime_epoch": 1731139200,
    "localtime": "2026-05-10 12:00"
  },
  "current": {
    "last_updated_epoch": 1731139140,
    "temp_c": 22.4,
    "temp_f": 72.3,
    "is_day": 1,
    "condition": {
      "text": "Sunny",
      "icon": "//cdn.weatherapi.com/weather/64x64/day/113.png",
      "code": 1000
    },
    "wind_kph": 12.6,
    "wind_mph": 7.8,
    "wind_dir": "S",
    "humidity": 64,
    "feelslike_c": 22.1,
    "feelslike_f": 71.7,
    "uv": 7.0
  }
}
```

Repo callsite: `./bot/kick.py:1019`. Reads `loc['name']`, `loc['country']`, `cur['condition']['text']`, `temp_c`, `temp_f`, `humidity`, `wind_kph` and posts a one-line message to chat. Non-200 status produces `"Location not found or weather service unavailable."`.

### 2.4 Rate limits / free-tier quotas

WeatherAPI.com free plan: **1,000,000 calls/month** (plan terms can change — verify on the dashboard). Per-second rate limits exist on the free plan; back-to-back requests from many Kick channels could throttle. There is **no local request-counting in the codebase for this provider** — the Kick bot does not write to `api_counts`. Rely on cooldowns in the Kick bot's command framework if quota becomes a concern.

### 2.5 Geocoding flow

There is no separate geocoding step for WeatherAPI.com. `current.json` accepts `q=<freeform>` and resolves it server-side: city names, coordinates, postcodes, IPs, airport IATA, and partial matches all work. If `q` is unresolvable, WeatherAPI returns HTTP 400 with a JSON error body (`{"error": {"code": 1006, "message": "No matching location found."}}`). The Kick bot treats any non-200 as a generic failure.

If autocomplete is ever needed (e.g. a profile-page picker for Kick users), `GET /v1/search.json?key=...&q=...` returns up to 10 ranked matches with `id`, `name`, `region`, `country`, `lat`, `lon`, `url`. Don't add it for the chat command — `current.json` already does freeform resolution.

### 2.6 Common gotchas

- **Different field names than OpenWeatherMap.** `temp_c` / `temp_f` / `wind_kph` / `wind_mph` / `condition.text` — not interchangeable with the One Call 3.0 shape. If you ever swap providers behind the Specter API, write an adapter that produces the existing `format_weather_data()` output.
- **`location.country`** is a **full name** (`"Australia"`), unlike OpenWeatherMap's ISO code (`"AU"`). Display strings vary if the providers are mixed.
- **`condition.icon`** is a **protocol-relative URL** (`//cdn.weatherapi.com/...`). Prepend `https:` when embedding in HTML.
- **HTTP, not HTTPS, in some docs examples.** Always use `https://api.weatherapi.com/v1/...` — the codebase already does.
- **`q` is case-insensitive but punctuation-sensitive.** `"sydney australia"` works; `"Sydney, Australia"` works; `"Sydney/Australia"` does not.
- **`aqi=no` is always passed here.** If air-quality is ever displayed, switch to `aqi=yes` and read `current.air_quality.{pm2_5,pm10,o3,...}`.
- **No One-Call equivalent.** For forecast or historical data, use `/v1/forecast.json` or `/v1/history.json` — different endpoints, different params (`days`, `dt`).
- **Timeout.** The Kick bot uses `aiohttp.ClientTimeout(total=10)`. WeatherAPI typically responds in <500 ms; a timeout almost always means network or provider outage.

---

## Operational notes

### Adding new weather features

- **New display field on the overlay or in chat.** Extend `format_weather_data()` at `./api/api.py:3839`. Add the field to the returned dict; the overlay reads it from `data.weather_data` after JSON-parsing. Update `process_weather_websocket()` in all three bot files (`bot.py`, `beta.py`, `beta-v6.py`) to parse and include it in the chat message.
- **Forecast.** Drop `daily` from the `exclude` list in `fetch_weather_data()` and add a new endpoint that returns a forecast block. Account for the extra quota cost (each forecast call still counts as 1 against One Call 3.0).
- **Provider failover.** Not currently implemented. If added, decide at the proxy layer (`./api/api.py`) — never in the bot — and keep the response shape identical so overlays don't need to change.

### Server config

- OpenWeatherMap key: `/home/botofthespecter/.env` → `WEATHER_API` (server)
- Kick.com WeatherAPI.com key: same `WEATHER_API` env var, set on the host running `./bot/kick.py` (server)
- Quota state: MySQL `website.api_counts` row where `type='weather'`
- Logs: `/home/botofthespecter/logs/logs/api/{channel}.txt` (server) — `[WEATHER]` prefix in beta/v6 bots

### Security

- API key never appears in WebSocket payloads, overlay HTML, or browser-visible URLs. The overlay receives only the formatted `weather_data` dict.
- Do not log the full `WEATHER_API` value. Length or last-4 only.
- The `/weather` proxy endpoint requires a valid Specter API key (`verify_key()`); unauthenticated requests get 401. Do not weaken this — the endpoint is the global throttle for the OpenWeatherMap quota.

---

## Quick reference

| Need | Endpoint | File:line |
| --- | --- | --- |
| `!weather` from Twitch | `GET https://api.botofthespecter.com/weather` | `./bot/bot.py:2983`, `./bot/beta.py:4919`, `./bot/beta-v6.py:4015` |
| `!weather` from Kick | `GET https://api.weatherapi.com/v1/current.json` | `./bot/kick.py:1020` |
| Geocode city → lat/lon | `GET https://api.openweathermap.org/geo/1.0/direct` | `./api/api.py:3823` |
| Current conditions | `GET https://api.openweathermap.org/data/3.0/onecall` | `./api/api.py:3831` |
| Validate user-set location | `GET /weather/location` (proxy) | `./api/api.py:4266` |
| Quota remaining (public) | `GET /api/weather` (proxy) | `./api/api.py:2849` |
| Overlay receiver | Socket.io `WEATHER_DATA` event | `./overlay/weather.php:74` |
| Bot WebSocket handler | `process_weather_websocket()` | `./bot/bot.py:7836`, `./bot/beta.py:11333`, `./bot/beta-v6.py:9158` |
| Streamer default location | `profile.weather_location` (per-user DB) | `./bot/bot.py:7464` (`get_streamer_weather`) |
