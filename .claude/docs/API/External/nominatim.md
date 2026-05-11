# Nominatim / geopy Reference

Local reference for the Nominatim geocoding service as accessed via the `geopy` Python library. Covers the geopy client API and the underlying Nominatim HTTP API. Sources: https://geopy.readthedocs.io/en/stable/, https://nominatim.org/release-docs/latest/api/Search/, https://nominatim.org/release-docs/latest/api/Output/. Verified 2026-05-11.

---

## Contents

1. [Overview](#1-overview)
2. [Installation and Import](#2-installation-and-import)
3. [Nominatim Class — Constructor](#3-nominatim-class--constructor)
4. [geocode() Method](#4-geocode-method)
5. [reverse() Method](#5-reverse-method)
6. [Location Object](#6-location-object)
7. [raw Dict Structure](#7-raw-dict-structure)
   - 7.1 [Top-level place fields](#71-top-level-place-fields)
   - 7.2 [address breakdown (addressdetails=True)](#72-address-breakdown-addressdetailstrue)
8. [Nominatim HTTP API](#8-nominatim-http-api)
   - 8.1 [Search endpoint](#81-search-endpoint)
   - 8.2 [Reverse endpoint](#82-reverse-endpoint)
   - 8.3 [Response format](#83-response-format)
9. [Rate Limits and Usage Policy](#9-rate-limits-and-usage-policy)
10. [RateLimiter and AsyncRateLimiter](#10-ratelimiter-and-asyncratelimiter)
11. [OSM Place Types](#11-osm-place-types)
12. [Address Output Fields — Complete Reference](#12-address-output-fields--complete-reference)
13. [Common Gotchas](#13-common-gotchas)
14. [BotOfTheSpecter Callsites](#14-botofthespecter-callsites)

---

## 1. Overview

Nominatim is OpenStreetMap's geocoding service. It converts place names and addresses into geographic coordinates (forward geocoding) and coordinates back into addresses (reverse geocoding). The data is derived entirely from OpenStreetMap.

**Two ways to use Nominatim:**

1. **Via geopy** — a Python client library that wraps the HTTP API. This is what BotOfTheSpecter uses. Import: `from geopy.geocoders import Nominatim`.
2. **Direct HTTP** — REST calls to `https://nominatim.openstreetmap.org/search` or `/reverse`. Documented in §8 for reference.

**Key characteristics:**

- Free, no API key required (but a `user_agent` is mandatory — see §9).
- Powered by OpenStreetMap data, which is crowd-sourced. Coverage and accuracy vary by region.
- Rate limited to 1 request per second on the public instance.
- Results ranked by `importance` (computed relevance score). The highest-ranked result is returned when `exactly_one=True`.
- Settlement hierarchy: country → state → county → city/municipality → town → village → hamlet → suburb/neighbourhood.

---

## 2. Installation and Import

```bash
pip install geopy
```

```python
from geopy.geocoders import Nominatim
```

The `Nominatim` class lives in `geopy.geocoders`. No separate install for Nominatim — it is bundled with geopy.

For rate-limiting helpers:

```python
from geopy.extra.rate_limiter import RateLimiter, AsyncRateLimiter
```

---

## 3. Nominatim Class — Constructor

```python
geolocator = Nominatim(
    user_agent="your_app_name",   # required
    domain="nominatim.openstreetmap.org",
    scheme="https",
    timeout=1,
    proxies=None,
    ssl_context=None,
    adapter_factory=None,
)
```

### Constructor Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `user_agent` | str | **required** | Identifies your application to the Nominatim server. Must be a non-generic string. OSM ToS prohibits using a shared/generic name like `"test"` or `"geopy"`. Using a project-specific value (e.g. `"BotOfTheSpecter"`) is the minimum requirement. |
| `domain` | str | `"nominatim.openstreetmap.org"` | The Nominatim server hostname. Set to a private instance's hostname when self-hosting. |
| `scheme` | str | `"https"` | URL scheme. Only change to `"http"` if your private instance does not support HTTPS. |
| `timeout` | int or None | `1` | Default timeout in seconds for requests. Can be overridden per-call. `None` means no timeout. |
| `proxies` | dict, str, or None | `None` | HTTP proxy configuration. Accepts a dict like `{"http": "192.0.2.0:8080"}`, a bare `"host:port"` string, or `"user:pass@host:port"`. Pass `{}` (empty dict) to explicitly disable system proxies (`HTTP_PROXY` / `HTTPS_PROXY` env vars). Only HTTP proxies are supported. |
| `ssl_context` | ssl.SSLContext or None | `None` | Custom SSL context. Use to add a custom CA bundle or disable certificate verification. If `None`, the system default is used. |
| `adapter_factory` | callable or None | `None` | Advanced: factory for the underlying HTTP adapter. Used for async adapters. Normally left as `None`. |

### user_agent — Why It Is Required

The OpenStreetMap Nominatim ToS requires every client to identify itself. The server will reject requests from clients whose `user_agent` matches known scrapers or is empty. The header is sent as the HTTP `User-Agent` value. Using a unique, descriptive string (application name + version or contact) is correct practice.

---

## 4. geocode() Method

Converts a place name or address string into geographic coordinates.

```python
location = geolocator.geocode(
    query,
    exactly_one=True,
    timeout=DEFAULT_SENTINEL,
    addressdetails=False,
    language=False,
    namedetails=False,
    country_codes=None,
    viewbox=None,
    bounded=False,
    featuretype=None,
    limit=None,
    geometry=None,
)
```

Returns a `Location` object (or `None` if nothing found), or a list of `Location` objects when `exactly_one=False`.

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `query` | str or dict | **required** | The search string (e.g. `"Sydney,AU"`). When a dict, uses structured query mode with keys: `street`, `city`, `county`, `state`, `country`, `postalcode`. Structured queries are more precise when you have separate fields. |
| `exactly_one` | bool | `True` | Return only the top-ranked result (`True`) or a list of all results (`False`). The public Nominatim instance caps results at 40. |
| `timeout` | int or None | constructor default | Per-call timeout in seconds. Overrides the constructor-level `timeout`. |
| `addressdetails` | bool | `False` | When `True`, request a full address breakdown in the response. Populates `location.raw['address']` with granular components (city, state, country, postcode, road, etc.). **BotOfTheSpecter always passes `True`.** |
| `language` | str or False | `False` | Preferred language for result names, as a BCP 47 language code or comma-separated list (e.g. `"en"`, `"fr,en"`). Maps to the HTTP `accept-language` parameter. When `False`, uses the server's Accept-Language header default (usually English). |
| `namedetails` | bool | `False` | When `True`, include all available name variants (official names, translations, abbreviations) in `location.raw['namedetails']`. |
| `country_codes` | str or list | `None` | Restrict results to specific countries. Accepts an ISO 3166-1 alpha-2 code string (`"AU"`) or a list (`["AU", "NZ"]`). Mapped to the `countrycodes` HTTP parameter. |
| `viewbox` | list or tuple | `None` | Bias results toward a bounding box. Provide as `[Point(ne), Point(sw)]` or a list of four floats `[west_lon, south_lat, east_lon, north_lat]`. Results inside the box are ranked higher. When combined with `bounded=True`, restricts to only results inside the box. |
| `bounded` | bool | `False` | When `True` and `viewbox` is set, restricts results to only those within the viewbox. When `False`, the viewbox only biases ranking. |
| `featuretype` | str | `None` | Restrict results to a specific OSM feature type. Accepted values: `"country"`, `"state"`, `"city"`, `"settlement"`. `"settlement"` matches city, town, village, or hamlet. Useful for rejecting roads and POIs. Maps to the `featureType` HTTP parameter. |
| `limit` | int | `None` | Maximum number of results to return (when `exactly_one=False`). Nominatim caps this at 40. |
| `geometry` | str | `None` | Request geometry output in addition to the centroid point. Accepted values: `"wkt"`, `"svg"`, `"kml"`, `"geojson"`. The geometry is added to `location.raw`. |

### Structured Query Example

```python
location = geolocator.geocode({
    "city": "Sydney",
    "country": "AU"
}, addressdetails=True)
```

Structured queries avoid ambiguity when you know exactly which fields apply, but Nominatim's free-form parser is generally good enough for `"City,CountryCode"` strings.

---

## 5. reverse() Method

Converts geographic coordinates into a human-readable address.

```python
location = geolocator.reverse(
    query,
    exactly_one=True,
    timeout=DEFAULT_SENTINEL,
    language=False,
    addressdetails=True,
    namedetails=False,
    zoom=None,
    geometry=None,
)
```

Returns a `Location` object or `None`.

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `query` | Point, tuple, or str | **required** | Coordinates to reverse-geocode. Accepts a `geopy.Point`, a `(latitude, longitude)` tuple, or a `"lat, lon"` string. |
| `exactly_one` | bool | `True` | Nominatim reverse geocoding always returns one result; this parameter exists for API consistency. |
| `timeout` | int or None | constructor default | Per-call timeout override. |
| `language` | str or False | `False` | Preferred language for the result address. |
| `addressdetails` | bool | `True` | Include full address breakdown. Unlike `geocode()`, this defaults to `True` for `reverse()` because an address breakdown is usually the whole point of reverse geocoding. |
| `namedetails` | bool | `False` | Include all name variants in `location.raw['namedetails']`. |
| `zoom` | int | `None` | Level of detail for the result. Range: 0 (country) to 18 (building). When `None`, Nominatim picks the most specific match at the given coordinates. Common useful values: 3 (country), 5 (state), 8 (county), 10 (city), 14 (suburb), 16 (street), 18 (building). |
| `geometry` | str | `None` | Include geometry in the response. Same values as `geocode()`: `"wkt"`, `"svg"`, `"kml"`, `"geojson"`. |

### Example

```python
location = geolocator.reverse((33.8688, 151.2093), language="en")
print(location.address)
# "Sydney, New South Wales, Australia"
```

---

## 6. Location Object

`geocode()` and `reverse()` return `geopy.location.Location` instances.

### Attributes

| Attribute | Type | Description |
|-----------|------|-------------|
| `address` | str | Full human-readable address as a comma-separated string (e.g. `"Sydney, New South Wales, Australia"`). This is the `display_name` from the raw response. |
| `latitude` | float | Latitude of the result in decimal degrees. This is the centroid of the matched OSM feature. |
| `longitude` | float | Longitude in decimal degrees. |
| `altitude` | float | Elevation. Nominatim does not return elevation; this is always `0.0` for Nominatim results. |
| `point` | geopy.Point | A `geopy.Point(latitude, longitude, altitude)` instance. Can be iterated as `(lat, lon, alt)`. |
| `raw` | dict | The complete JSON response object from Nominatim for this result. All Nominatim-specific fields (place_id, osm_type, class, type, importance, address breakdown, etc.) are here. See §7. |

### Iteration

A `Location` can be unpacked as a two-element tuple:

```python
address_str, (lat, lon) = location
# or
address_str, coords = location   # coords is (lat, lon, alt)
```

### None Check

`geocode()` returns `None` when no result is found — always check before accessing attributes:

```python
location = geolocator.geocode("NonExistentPlace,ZZ", addressdetails=True)
if not location:
    # handle not found
    return
lat = location.latitude
```

---

## 7. raw Dict Structure

`location.raw` is the unmodified JSON object Nominatim returned for this result. Its shape depends on which parameters were requested.

### 7.1 Top-level place fields

These fields are always present (using the default `jsonv2` format):

| Field | Type | Description |
|-------|------|-------------|
| `place_id` | int | Nominatim's internal database ID. **Not stable across servers or database updates.** Do not store this as a persistent identifier. |
| `osm_type` | str | OpenStreetMap object type: `"node"`, `"way"`, or `"relation"`. |
| `osm_id` | int | OpenStreetMap object ID. Stable within OSM, but the same real-world place can have its OSM ID change if redrawn. Use `osm_type` + `osm_id` + `class` together for cross-server consistency. |
| `place_rank` | int | Nominatim's internal rank for the place (1–30). Lower numbers are broader (country = 4, city = 16, street = 26, building = 30). |
| `address_rank` | int | Address rank — how the place is treated for address composition. |
| `category` | str | OSM tag key (e.g. `"place"`, `"boundary"`, `"highway"`, `"amenity"`). This is what geopy calls the `class` field; `jsonv2` renames it to `category`. |
| `type` | str | OSM tag value under the category/class key (e.g. `"city"`, `"administrative"`, `"residential"`). Together with `category`, this gives the full OSM tag: `category=type`. |
| `importance` | float | Computed relevance score. Higher is more important. Nominatim uses Wikipedia article counts, link density, and place rank to compute this. A national capital scores near 1.0; a small hamlet scores near 0.1. |
| `display_name` | str | Full address as a human-readable comma-separated string. Same value as `location.address`. |
| `lat` | str | Latitude as a string (not a float — same value as `location.latitude` but string-typed in `raw`). |
| `lon` | str | Longitude as a string (same as `location.longitude` but string-typed in `raw`). |
| `boundingbox` | list[str] | Four-element list: `[min_lat, max_lat, min_lon, max_lon]`. Values are strings. The bounding box of the matched feature's geometry. |
| `licence` | str | Attribution text for OSM data (e.g. `"Data © OpenStreetMap contributors, ODbL 1.0. https://osm.org/copyright"`). |
| `icon` | str | URL to a small icon representing the category/type. Not always present. |
| `address` | dict | Address breakdown object. Only present when `addressdetails=True` was requested. See §7.2. |
| `namedetails` | dict | All available name variants. Only present when `namedetails=True`. Keys are OSM name tags (`"name"`, `"name:en"`, `"official_name"`, etc.). |
| `extratags` | dict | Additional OSM metadata. Only present when `extratags=1` is passed at the HTTP level (not directly exposed in geopy's geocode() parameters; accessible if you build a raw HTTP query). |
| `geojson` | dict | GeoJSON geometry object. Only present when `geometry="geojson"` was requested. |
| `svg` | str | SVG path string. Only present when `geometry="svg"` was requested. |
| `geotext` | str | WKT geometry string. Only present when `geometry="wkt"` was requested. |
| `geokml` | str | KML geometry string. Only present when `geometry="kml"` was requested. |

### 7.2 address breakdown (addressdetails=True)

When `addressdetails=True` is passed to `geocode()`, `location.raw['address']` is populated with a flat dict of address components. The specific keys present depend on what Nominatim could determine for that location. Not all keys appear for all places.

**Administrative hierarchy — always try these in order:**

| Key | When present | Example |
|-----|-------------|---------|
| `continent` | Very rarely — large ocean/regional queries | `"Europe"` |
| `country` | Almost always | `"Australia"` |
| `country_code` | Almost always | `"au"` (always lowercase) |
| `region` | Some countries (non-standard administrative level) | `"South-East Queensland"` |
| `state` | Most countries | `"New South Wales"` |
| `state_district` | Some countries | `"Greater Sydney"` |
| `county` | Many countries (county / district / shire) | `"Cumberland County"` |
| `municipality` | Some countries — similar level to city | `"City of Sydney"` |
| `city` | Major cities and urban areas | `"Sydney"` |
| `town` | Towns smaller than cities | `"Katoomba"` |
| `village` | Rural settlements | `"Leura"` |
| `hamlet` | Very small settlements | `"Medlow Bath"` |
| `suburb` | Suburb within a city | `"Darlinghurst"` |
| `city_district` | Administrative district within a city | `"Inner West"` |
| `district` | District (alternate to city_district in some regions) | — |
| `borough` | Borough (especially in US/UK) | `"Brooklyn"` |
| `neighbourhood` | Neighbourhood (alternate spelling: `neighborhood` rare) | `"Surry Hills"` |
| `quarter` | Quarter within a city | — |
| `allotments` | Land allotment area | — |
| `croft` | Small farm unit | — |
| `isolated_dwelling` | Single isolated building | — |
| `subdivision` | Subdivision | — |
| `residential` | Residential area name | — |
| `commercial` | Commercial area name | — |
| `industrial` | Industrial area name | — |
| `retail` | Retail area name | — |
| `farm` | Farm name | — |
| `farmyard` | Farmyard name | — |
| `city_block` | City block | — |

**ISO administrative level codes:**

| Key | Description |
|-----|-------------|
| `ISO3166-2-lvl4` | ISO 3166-2 code for the level-4 administrative division (state/province). E.g. `"AU-NSW"` for New South Wales. |
| `ISO3166-2-lvl6` | Level-6 administrative division code. |
| `ISO3166-2-lvl8` | Level-8 administrative division code. |

**Infrastructure:**

| Key | When present | Example |
|-----|-------------|---------|
| `road` | When result is on a road | `"George Street"` |
| `house_number` | When result is a specific building | `"123"` |
| `house_name` | Named buildings | `"Parliament House"` |
| `postcode` | When postal code is known | `"2000"` |

**Feature-type keys** (when a POI or specific feature is matched):

| Key | Description |
|-----|-------------|
| `amenity` | Amenity (restaurant, school, hospital, etc.) |
| `historic` | Historic site name |
| `military` | Military installation name |
| `natural` | Natural feature name (river, lake, mountain) |
| `aeroway` | Airport / aerodrome name |
| `aerialway` | Cable car / aerial tramway |
| `boundary` | Administrative boundary name |
| `bridge` | Bridge name |
| `club` | Club or association name |
| `craft` | Craft business name |
| `emergency` | Emergency service name |
| `landuse` | Land use area name |
| `leisure` | Leisure facility name (park, sports field) |
| `man_made` | Man-made structure name |
| `mountain_pass` | Mountain pass name |
| `office` | Office name |
| `place` | Named place (when the result is a place node) |
| `railway` | Railway station or infrastructure name |
| `shop` | Shop name |
| `tourism` | Tourism site name (hotel, attraction) |
| `tunnel` | Tunnel name |
| `waterway` | River, stream, canal name |

**Practical note — settlement key priority:**

For city/town/place lookups, the most specific populated settlement key wins. The order Nominatim uses internally:

```
city > municipality > town > village > hamlet
```

The BotOfTheSpecter `!time` command chains these in the same order when building `display_location`:

```python
display_location = (
    address.get('city') or
    address.get('town') or
    address.get('village') or
    address.get('state') or
    address.get('country') or
    input_string.split(',')[0]
)
```

---

## 8. Nominatim HTTP API

This section documents the underlying HTTP API directly. geopy wraps this API — you do not need to call it directly when using geopy. This section is reference material for debugging raw responses or building non-geopy integrations.

### 8.1 Search endpoint

**Base URL:** `https://nominatim.openstreetmap.org/search`

**Method:** `GET`

**Required headers:** `User-Agent: your_app_name` (or the equivalent `user_agent` geopy parameter)

#### Query parameters

**Input — choose one mode:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Free-form query string. Processed left-to-right then right-to-left. Commas are optional but improve parsing. E.g. `q=Sydney,AU`. Cannot be combined with structured parameters. |
| `amenity` | string | Structured: amenity/POI type. |
| `street` | string | Structured: house number and street name. |
| `city` | string | Structured: city name. |
| `county` | string | Structured: county name. |
| `state` | string | Structured: state or province. |
| `country` | string | Structured: country name or ISO code. |
| `postalcode` | string | Structured: postal code. |

**Output format:**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `format` | `xml`, `json`, `jsonv2`, `geojson`, `geocodejson` | `jsonv2` | Response format. `jsonv2` renames `class` to `category` and adds `place_rank`. `geojson` and `geocodejson` return GeoJSON FeatureCollections. `geocodejson` provides stable geocoding-specific classification. geopy uses `jsonv2` by default. |
| `json_callback` | string | unset | Wraps the response in a JSONP callback. |
| `limit` | int (max 40) | 10 | Maximum number of results. |

**Output detail:**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `addressdetails` | `0` or `1` | `0` | Include the `address` breakdown object. |
| `extratags` | `0` or `1` | `0` | Include additional OSM tags (Wikipedia links, opening hours, etc.) in an `extratags` object. |
| `namedetails` | `0` or `1` | `0` | Include all name variants in a `namedetails` object. |
| `entrances` | `0` or `1` | `0` | Include entrance nodes for the matched feature. |

**Language:**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `accept-language` | BCP 47 language string | HTTP Accept-Language header | Preferred language for result display names. |

**Result restriction:**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `countrycodes` | Comma-separated ISO 3166-1 alpha-2 | unset | Restrict results to listed countries. E.g. `countrycodes=au,nz`. |
| `layer` | `address`, `poi`, `railway`, `natural`, `manmade` | unset | Filter by feature category. `address` = roads, buildings, inhabited places, admin boundaries. `poi` = restaurants, hotels, shops. Comma-separate for multiple. |
| `featureType` | `country`, `state`, `city`, `settlement` | unset | Restrict to a specific place level. `settlement` matches any of city/town/village/hamlet. Maps to geopy's `featuretype` parameter. |
| `exclude_place_ids` | Comma-separated place_ids or OSM IDs | unset | Exclude specific results by Nominatim place_id. Use OSM IDs (`N12345`, `W12345`, `R12345`) for cross-server stability. |
| `viewbox` | `west_lon,south_lat,east_lon,north_lat` | unset | Bias results toward this bounding box. |
| `bounded` | `0` or `1` | `0` | When `1`, restrict results to inside `viewbox`. |
| `dedupe` | `0` or `1` | `1` | When `1`, Nominatim attempts to detect and merge duplicate results. |

**Polygon geometry (optional):**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `polygon_geojson` | `0` or `1` | `0` | Include the feature's boundary polygon as GeoJSON. |
| `polygon_kml` | `0` or `1` | `0` | Include boundary as KML. |
| `polygon_svg` | `0` or `1` | `0` | Include boundary as SVG path. |
| `polygon_text` | `0` or `1` | `0` | Include boundary as WKT. |
| `polygon_threshold` | float (degrees) | `0.0` | Simplification tolerance for polygon output. |

**Miscellaneous:**

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `email` | valid email | unset | Include a contact email in requests if making high-volume queries. Nominatim uses this to contact you about issues rather than blocking. |
| `debug` | `0` or `1` | `0` | Returns debug HTML showing query internals. |

#### Example requests

```
# Basic free-form query
https://nominatim.openstreetmap.org/search?q=Sydney,AU&format=jsonv2&addressdetails=1

# Restrict to one country, featureType settlement
https://nominatim.openstreetmap.org/search?q=Springfield&countrycodes=us&featureType=settlement&format=jsonv2&addressdetails=1&limit=5

# Structured query
https://nominatim.openstreetmap.org/search?city=Paris&country=FR&format=jsonv2&addressdetails=1

# With viewbox bias (bounding box around Australia)
https://nominatim.openstreetmap.org/search?q=Darwin&viewbox=113,-44,154,-10&bounded=1&format=jsonv2
```

### 8.2 Reverse endpoint

**Base URL:** `https://nominatim.openstreetmap.org/reverse`

**Method:** `GET`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `lat` | float | Yes | Latitude in decimal degrees. |
| `lon` | float | Yes | Longitude in decimal degrees. |
| `zoom` | int (0–18) | No | Detail level. 0 = country, 18 = building. Default: 18 (most specific). |
| `format` | string | No | Same values as search. Default: `jsonv2`. |
| `addressdetails` | `0` or `1` | No | Include address breakdown. Default: `1`. |
| `namedetails` | `0` or `1` | No | Include all name variants. |
| `extratags` | `0` or `1` | No | Include extra OSM tags. |
| `accept-language` | string | No | Preferred language for display name. |
| `polygon_*` | see §8.1 | No | Same polygon parameters as search. |

#### Example request

```
https://nominatim.openstreetmap.org/reverse?lat=-33.8688&lon=151.2093&format=jsonv2&addressdetails=1&zoom=10
```

### 8.3 Response format

**JSON array (search) / JSON object (reverse):**

For the search endpoint, the response is a JSON array of place objects (or a FeatureCollection for `geojson`/`geocodejson`). For the reverse endpoint, the response is a single place object.

**Place object example (jsonv2 format with addressdetails=1):**

```json
{
  "place_id": 236179502,
  "licence": "Data © OpenStreetMap contributors, ODbL 1.0. https://osm.org/copyright",
  "osm_type": "relation",
  "osm_id": 5750005,
  "boundingbox": ["-34.1682983", "-33.4155027", "150.5201549", "151.3430170"],
  "lat": "-33.8688197",
  "lon": "151.2092955",
  "display_name": "Sydney, New South Wales, Australia",
  "place_rank": 16,
  "category": "place",
  "type": "city",
  "importance": 0.9368963523796,
  "icon": "https://nominatim.openstreetmap.org/ui/mapicons/poi_place_city.p.20.png",
  "address": {
    "city": "Sydney",
    "state": "New South Wales",
    "ISO3166-2-lvl4": "AU-NSW",
    "country": "Australia",
    "country_code": "au"
  }
}
```

---

## 9. Rate Limits and Usage Policy

### Public Nominatim instance (nominatim.openstreetmap.org)

| Limit | Value |
|-------|-------|
| Rate limit | **1 request per second** (hard limit) |
| Bulk geocoding | Not permitted on the public instance |
| Maximum results per request | 40 |
| Cost | Free (no API key) |

### Usage Policy Requirements

The OpenStreetMap Foundation's Nominatim usage policy mandates:

1. **Set a valid `user_agent`** that identifies your application. Generic strings (`"test"`, `"geopy"`, `"python-requests"`) are explicitly prohibited.
2. **Do not exceed 1 request per second.** Bursting above this will result in requests being dropped or your IP being blocked.
3. **Cache results where possible.** Nominatim is a shared public resource. If your use case requires bulk geocoding (more than a few hundred addresses), use a self-hosted Nominatim instance or a commercial provider.
4. **Provide contact information.** For automated applications, passing your email via the HTTP `email` parameter allows OSM admins to contact you rather than blocking you.
5. **Do not scrape.** Systematic bulk downloads of OSM data must use the OSM data export tools, not Nominatim.

### Alternatives for high-volume use

| Provider | Notes |
|----------|-------|
| Self-hosted Nominatim | Full control, same OSM data, no rate limits. Requires a server with ~64GB RAM for a full planet import. |
| PickPoint | Commercial Nominatim hosting. geopy has a `PickPoint` geocoder class. |
| OpenMapQuest | Commercial, higher quotas. geopy `OpenMapQuest` class. |
| Photon | OSM-based geocoder by Komoot, alternative to Nominatim, no official rate limit on public instance. |

### BotOfTheSpecter policy compliance

The bot uses `user_agent="BotOfTheSpecter"` and makes one request per user-triggered `!time` command. This pattern is well within the 1 req/s limit (chat commands are throttled by cooldowns) and complies with the usage policy.

---

## 10. RateLimiter and AsyncRateLimiter

For bulk geocoding (not currently used by BotOfTheSpecter), geopy provides rate-limiting wrappers.

### RateLimiter

For synchronous geocoders or when running geocode in a thread executor:

```python
from geopy.extra.rate_limiter import RateLimiter

geolocator = Nominatim(user_agent="MyApp")
geocode = RateLimiter(geolocator.geocode, min_delay_seconds=1)

# Now call geocode() through the rate limiter:
location = geocode("Sydney,AU", addressdetails=True)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `func` | callable | required | The geocoder method to wrap (e.g. `geolocator.geocode`). |
| `min_delay_seconds` | float | `0` | Minimum delay between consecutive calls in seconds. Set to `1` for Nominatim. |
| `max_retries` | int | `2` | Number of retries on `GeocoderServiceError`. Does not retry on 4xx errors. |
| `error_wait_seconds` | float | `min_delay_seconds` | Wait time between retry attempts. Must be >= `min_delay_seconds`. |
| `swallow_exceptions` | bool | `True` | When `True`, exceptions after all retries are swallowed and `return_value_on_exception` is returned. When `False`, the exception propagates. |
| `return_value_on_exception` | any | `None` | Value returned when an exception is swallowed. |

### AsyncRateLimiter

For async workflows (e.g. with `aiohttp` adapters):

```python
from geopy.extra.rate_limiter import AsyncRateLimiter

geocode = AsyncRateLimiter(geolocator.geocode, min_delay_seconds=1)
location = await geocode("Sydney,AU", addressdetails=True)
```

Same parameters as `RateLimiter`. Requires the geolocator to use an async adapter (`adapter_factory` set to an async adapter in the constructor).

**Note:** The BotOfTheSpecter `!time` command runs Nominatim synchronously inside an async bot handler. This works because Nominatim requests are rare (triggered by individual chat commands). For higher-volume use, wrap in `asyncio.get_event_loop().run_in_executor(None, ...)` to avoid blocking the event loop, or switch to an async adapter with `AsyncRateLimiter`.

---

## 11. OSM Place Types

Nominatim results include `category` (geopy: `class`) and `type` fields that describe what the matched object is. This is critical for filtering results to only meaningful location types.

### Common category/type combinations for place lookups

| category | type | Description |
|----------|------|-------------|
| `place` | `country` | A country |
| `place` | `state` | A state, province, or territory |
| `place` | `county` | A county or district |
| `place` | `city` | A city |
| `place` | `municipality` | A municipality |
| `place` | `town` | A town (smaller than a city) |
| `place` | `village` | A village |
| `place` | `hamlet` | A hamlet (smaller than a village) |
| `place` | `suburb` | A suburb or neighbourhood |
| `place` | `island` | An island |
| `boundary` | `administrative` | Administrative boundary (country/state borders) |
| `highway` | `residential` | A residential road |
| `highway` | `primary` | A primary road |
| `highway` | `motorway` | A motorway |
| `amenity` | `restaurant` | A restaurant |
| `amenity` | `school` | A school |
| `amenity` | `hospital` | A hospital |
| `tourism` | `hotel` | A hotel |
| `tourism` | `attraction` | A tourist attraction |
| `natural` | `water` | A lake or water body |
| `natural` | `peak` | A mountain peak |

### place_rank values

`place_rank` indicates the administrative level. Higher rank = more specific:

| place_rank | Typical match |
|------------|--------------|
| 4 | Country |
| 8 | State / province |
| 12 | County / district |
| 16 | City / municipality |
| 17 | Town |
| 18 | Village |
| 19 | Hamlet |
| 22 | Suburb / neighbourhood |
| 26 | Road / street |
| 28 | Address range |
| 30 | Specific building / POI |

---

## 12. Address Output Fields — Complete Reference

Complete alphabetical list of keys that may appear in `location.raw['address']` when `addressdetails=True`. Nominatim only includes keys it can determine — the list for any given result is typically a subset.

| Key | Level / category | Notes |
|-----|-----------------|-------|
| `aerialway` | Feature | Cable car, gondola, etc. |
| `aeroway` | Feature | Airport, helipad, etc. |
| `allotments` | Settlement | Allotment garden area |
| `amenity` | Feature | Restaurant, school, bank, etc. |
| `borough` | Settlement | Borough within a city (common in New York, London) |
| `boundary` | Administrative | Administrative boundary name |
| `bridge` | Feature | Named bridge |
| `city` | Settlement | City name. Most specific populated place key for large cities. |
| `city_block` | Settlement | Named city block (common in Japan) |
| `city_district` | Settlement | Administrative district within a city |
| `club` | Feature | Club or association |
| `commercial` | Land use | Named commercial area |
| `continent` | Administrative | Continent name (rarely populated) |
| `country` | Administrative | Country name in the requested language |
| `country_code` | Administrative | ISO 3166-1 alpha-2 code, always lowercase (e.g. `"au"`, `"us"`) |
| `county` | Administrative | County, shire, or district |
| `craft` | Feature | Craftsperson or workshop |
| `croft` | Settlement | Small agricultural holding |
| `district` | Settlement | District (alternate to city_district) |
| `emergency` | Feature | Emergency service |
| `farm` | Land use | Farm name |
| `farmyard` | Land use | Farmyard |
| `hamlet` | Settlement | Hamlet (smaller than village) |
| `historic` | Feature | Historic site or building |
| `house_name` | Infrastructure | Name of a specific named building |
| `house_number` | Infrastructure | Street address number |
| `industrial` | Land use | Industrial zone name |
| `ISO3166-2-lvl4` | Administrative | ISO 3166-2 subdivision code at level 4 (state/province), e.g. `"AU-NSW"` |
| `ISO3166-2-lvl6` | Administrative | Level-6 subdivision code |
| `ISO3166-2-lvl8` | Administrative | Level-8 subdivision code |
| `isolated_dwelling` | Settlement | Single isolated building in a rural area |
| `landuse` | Land use | Land use area name |
| `leisure` | Feature | Park, sports facility, etc. |
| `man_made` | Feature | Man-made structure |
| `military` | Feature | Military installation |
| `mountain_pass` | Feature | Mountain pass name |
| `municipality` | Settlement | Municipality (can be same level as city in some countries) |
| `natural` | Feature | River, lake, mountain, etc. |
| `neighbourhood` | Settlement | Neighbourhood within a suburb |
| `office` | Feature | Office name |
| `place` | Feature | Named place (when the matched object is a `place` node) |
| `postcode` | Infrastructure | Postal / ZIP code |
| `quarter` | Settlement | Quarter within a neighbourhood |
| `railway` | Feature | Railway station or infrastructure |
| `region` | Administrative | Non-standard regional level between state and country |
| `residential` | Land use | Named residential area |
| `retail` | Land use | Retail zone name |
| `road` | Infrastructure | Street or road name |
| `shop` | Feature | Shop name |
| `state` | Administrative | State, province, or territory name |
| `state_district` | Administrative | Administrative district within a state |
| `subdivision` | Settlement | Subdivision |
| `suburb` | Settlement | Suburb within a city or town |
| `tourism` | Feature | Hotel, museum, tourist attraction |
| `town` | Settlement | Town (smaller than city, larger than village) |
| `tunnel` | Feature | Tunnel name |
| `village` | Settlement | Village |
| `waterway` | Feature | River, canal, stream |

---

## 13. Common Gotchas

### Ambiguous place names

Many place names exist in multiple countries. Without a country code, Nominatim picks the highest-importance result globally:

- `"Springfield"` — returns Springfield, Missouri, US (most important by Wikipedia score), not the many other Springfields.
- `"Newcastle"` — could be Newcastle, Australia or Newcastle-upon-Tyne, UK.

**Fix:** Always append a country code: `"Newcastle,AU"` or use `country_codes=["au"]` parameter.

### City vs town vs village

A place that looks like a city to a human might be tagged as `town` or `village` in OSM. This is especially common for:
- Australian regional cities (often tagged `town`)
- US cities outside major metros (often tagged `city` if >50k population, `town` below)
- UK cities (official city status is separate from OSM size tagging)

The BotOfTheSpecter `!time` command handles this by trying all settlement keys in order (`city`, then `town`, then `village`).

### Road results instead of place results

Nominatim can return a road as the top result when the query is ambiguous. For example, `"George Street,AU"` would match the road, not a place. The road result will have `category="highway"` and its `address` dict will have a `road` key but no `city`/`town`/`village` key.

**Fix:** Use the `valid_location_types` allowlist approach (as BotOfTheSpecter does) or pass `featuretype="settlement"` to restrict results to populated places.

### The `address` dict vs `raw['address']` confusion

`location.address` (no brackets) is the `display_name` string — the full comma-separated address.
`location.raw['address']` is the address breakdown dict — the granular components.

These are two completely different things. The breakdown dict only exists when `addressdetails=True`.

### country_code is lowercase

`location.raw['address']['country_code']` is always lowercase (`"au"`, `"us"`, `"gb"`), not the ISO-standard uppercase `"AU"`. If you are comparing against ISO codes, normalise before comparing.

### lat and lon in raw are strings

`location.raw['lat']` and `location.raw['lon']` are string-typed (e.g. `"-33.8688197"`), not floats. Use `location.latitude` and `location.longitude` (the `Location` object attributes) for numeric access — geopy converts them to floats.

### boundingbox values are also strings

`location.raw['boundingbox']` is a list of four strings, not floats:
```python
# Wrong: treats them as strings
for val in location.raw['boundingbox']:
    print(val)  # "-34.1682983", "-33.4155027", "150.5201549", "151.3430170"

# Right: convert if needed
min_lat, max_lat, min_lon, max_lon = [float(x) for x in location.raw['boundingbox']]
```

### place_id is not portable

`location.raw['place_id']` changes between Nominatim server instances and database rebuilds. Do not store it as a persistent reference. If you need a stable OSM object identifier, use `osm_type` + `osm_id` + `category` together.

### Synonymous input formats

All of these return the same Sydney result:

```python
geolocator.geocode("Sydney,AU")
geolocator.geocode("Sydney, Australia")
geolocator.geocode({"city": "Sydney", "country": "AU"})
geolocator.geocode("Sydney", country_codes=["au"])
```

The free-form string with a comma is sufficient and is what BotOfTheSpecter uses.

### Synchronous geocode in async context

`Nominatim.geocode()` is a synchronous blocking call. Calling it directly inside an `async def` function will block the event loop for the duration of the HTTP request (typically 100–500ms). For the `!time` command this is acceptable because it fires rarely. If you need it to be truly non-blocking:

```python
import asyncio
location_data = await asyncio.get_event_loop().run_in_executor(
    None,
    lambda: geolocator.geocode(timezone, addressdetails=True)
)
```

---

## 14. BotOfTheSpecter Callsites

### Integration overview

The `!time` command in the three Twitch bot versions uses Nominatim to convert a user-provided location string (`"City,CountryCode"`) into latitude and longitude, which are then passed to TimezoneDB to resolve the IANA timezone name.

**Full flow:**

```
User types: !time Sydney,AU
    ↓
Nominatim.geocode("Sydney,AU", addressdetails=True)
    → location.latitude = -33.8688
    → location.longitude = 151.2093
    → location.raw['address']['city'] = "Sydney"
    ↓
TimezoneDB get-time-zone?by=position&lat=-33.8688&lng=151.2093
    → zoneName = "Australia/Sydney"
    ↓
pytz.timezone("Australia/Sydney")
    → current local time formatted as "Sunday, May 11, 2026 and the time is: 10:30 AM"
```

### Kick bot — different approach

`./bot/kick.py` imports `Nominatim` at line 27 but its `cmd_time` function (lines 1039–1052) does **not** use it. The Kick `!time` command accepts a raw IANA timezone string directly from the user (e.g. `!time Australia/Sydney`) and passes it straight to `pytz.timezone()`. Nominatim is not called.

### Callsite table

| File | Nominatim lines | Valid location types |
|------|----------------|---------------------|
| `./bot/bot.py` | 3177–3205 | `city`, `town`, `village`, `state`, `country`, `county`, `municipality` |
| `./bot/beta.py` | 5112–5140 | `city`, `town`, `village`, `state`, `country`, `county`, `municipality` |
| `./bot/beta-v6.py` | 4208–4236 | `city`, `town`, `village`, `state`, `country`, `county`, `municipality` |
| `./bot/kick.py` | 27 (import only) | N/A — `cmd_time` bypasses Nominatim entirely |

### Exact code pattern (identical in all three Twitch bot versions)

```python
from geopy.geocoders import Nominatim

# Inside the !time command handler:

# 1. Validate input format
if ',' not in timezone:
    await send_chat_message("Please use the format: Location,Country (e.g., 'NewYork,US' or 'Sydney,AU')")
    return

# 2. Geocode the location
geolocator = Nominatim(user_agent="BotOfTheSpecter")
location_data = geolocator.geocode(timezone, addressdetails=True)

# 3. Handle not found
if not location_data:
    await send_chat_message(f"Could not find the location '{timezone}'. ...")
    return

# 4. Validate that the result is a meaningful place (not a road, POI, etc.)
address = location_data.raw.get('address', {})
valid_location_types = ['city', 'town', 'village', 'state', 'country', 'county', 'municipality']
has_valid_location = any(key in address for key in valid_location_types)
if not has_valid_location:
    await send_chat_message(f"Could not find a valid location for '{timezone}'. ...")
    return

# 5. Use lat/lon for TimezoneDB lookup
# location_data.latitude, location_data.longitude → TimezoneDB → zoneName

# 6. Build display name from address breakdown
display_location = (
    address.get('city') or
    address.get('town') or
    address.get('village') or
    address.get('state') or
    address.get('country') or
    timezone.split(',')[0]
)
```

### Design notes

- A new `Nominatim` instance is created on every `!time` invocation. This is safe (there is no persistent connection to maintain) but means no HTTP connection reuse across calls.
- The `user_agent="BotOfTheSpecter"` is hardcoded as a string literal in all three bot files.
- `addressdetails=True` is always passed — the bot needs the `address` dict to validate the result type and build `display_location`.
- No timeout is explicitly passed to `geocode()` — the constructor default of 1 second applies.
- The call is synchronous (blocking). The Twitch bot event loop is blocked for the duration of the HTTP request. See §13 "Synchronous geocode in async context" if this becomes a problem.
- The `valid_location_types` allowlist (`city`, `town`, `village`, `state`, `country`, `county`, `municipality`) effectively acts as a `featuretype` filter applied after the fact. Passing `featuretype="settlement"` to `geocode()` could achieve a similar result at the API level, though it would exclude `state` and `country` results.

### Known limitations

| Issue | Impact |
|-------|--------|
| Nominatim instance created per call | Minor overhead; safe but no connection reuse |
| Synchronous call blocks event loop | Latency spike of ~100–500ms per `!time` command |
| No timeout override | Falls back to 1-second constructor default; slow servers may time out silently |
| No retry logic | A transient Nominatim error shows as "Could not find the location..." to users |
| No country_codes restriction | A query for `"Paris,US"` will find Paris, Texas correctly, but `"Paris"` alone would find Paris, France (no US restriction applied when comma+code is present in the query string) |
