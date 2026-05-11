# IPLocate API Reference

IPLocate (`iplocate.io`) provides IPv4 and IPv6 geolocation, ASN data, company and hosting identification, abuse contacts, and privacy/threat detection (VPN, proxy, Tor, iCloud Relay, bogon, abuser).

- **Base URL:** `https://iplocate.io/api/`
- **Protocol:** HTTPS only
- **Response format:** JSON
- **IPv6:** Fully supported — colons in IPv6 addresses must be URL-encoded when passed as a path segment

---

## Authentication

Every authenticated request must supply an API key. Two methods are accepted:

### Method 1 — HTTP header (recommended)

```
X-API-Key: {your_api_key}
```

Header auth keeps the key out of server access logs and web proxies. This is the method used by the BotOfTheSpecter dashboard.

### Method 2 — Query parameter

```
GET /api/lookup/{ip}?apikey={your_api_key}
```

Functionally identical but the key appears in server logs and browser history. Avoid in server-side code.

### Unauthenticated requests

Requests sent without an API key are accepted and return the full response schema, but consume from the free-tier daily quota tied to your IP address. Callers with an API key should always send it to ensure quota is attributed to the account rather than the origin IP.

---

## Endpoints

### 1. Single IP Lookup

```
GET /api/lookup/{ip}
```

Resolves geolocation, ASN, company, hosting, privacy, and abuse data for a single IPv4 or IPv6 address.

**Path parameter:**

| Parameter | Type | Required | Description |
| --------- | ---- | -------- | ----------- |
| `ip` | string | Yes | IPv4 or IPv6 address to look up. URL-encode IPv6 addresses (colons become `%3A`). |

**Example request:**

```
GET https://iplocate.io/api/lookup/8.8.8.8
X-API-Key: your_api_key
```

**Example response — 8.8.8.8 (Google Public DNS):**

```json
{
  "ip": "8.8.8.8",
  "country": "United States",
  "country_code": "US",
  "is_eu": false,
  "city": "Mountain View",
  "continent": "North America",
  "latitude": 37.38605,
  "longitude": -122.08385,
  "time_zone": "America/Los_Angeles",
  "postal_code": "94035",
  "subdivision": "California",
  "currency_code": "USD",
  "calling_code": "1",
  "is_anycast": true,
  "is_satellite": false,
  "asn": {
    "asn": "AS15169",
    "route": "8.8.0/24",
    "netname": "GOOGLE",
    "name": "Google LLC",
    "country_code": "US",
    "domain": "google.com",
    "type": "hosting",
    "rir": "ARIN"
  },
  "privacy": {
    "is_abuser": false,
    "is_anonymous": false,
    "is_bogon": false,
    "is_hosting": true,
    "is_icloud_relay": false,
    "is_proxy": false,
    "is_tor": false,
    "is_vpn": false
  },
  "hosting": {
    "provider": "Google Cloud",
    "domain": "cloud.google.com",
    "network": "8.8.8.0/24"
  },
  "company": {
    "name": "Google LLC",
    "domain": "google.com",
    "country_code": "US",
    "type": "hosting"
  },
  "abuse": {
    "address": "1600 Amphitheatre Parkway, Mountain View, CA, 94043, US",
    "country_code": "US",
    "email": "network-abuse@google.com",
    "name": "Google LLC",
    "network": "8.8.8.0 - 8.8.8.255",
    "phone": "+1-650-253-0000"
  }
}
```

---

### 2. Self-Lookup

```
GET /api/lookup/
```

Returns geolocation data for the IP address of the caller (i.e. the machine making the HTTP request). No path parameter is provided.

**Important constraint:** In server-side PHP, Python, or any backend code, this endpoint returns the **server's** IP, not the end user's IP. It is only useful in client-side JavaScript running directly in a browser, or for verifying the server's own egress IP.

**Example request:**

```
GET https://iplocate.io/api/lookup/
X-API-Key: your_api_key
```

The response schema is identical to the single IP lookup above.

---

### 3. Batch Lookup

```
POST /api/lookup/batch
```

Enriches up to 1,000 IP addresses in a single HTTP call. Available on paid plans and pay-as-you-go credits only — not available on the free tier.

**Request headers:**

```
Content-Type: application/json
X-API-Key: your_api_key
```

**Request body:**

```json
{
  "ips": ["8.8.8.8", "1.1.1.1", "2606:4700:4700::1111"]
}
```

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `ips` | array of strings | Yes | List of IPv4 or IPv6 addresses. Maximum 1,000 per request. |

**Response:**

An object keyed by the requested IP addresses. Each value is the same schema as a single IP lookup response, or an error object if that individual IP failed.

```json
{
  "8.8.8.8": { ... },
  "1.1.1.1": { ... }
}
```

---

## Response Schema — Full Field Reference

All fields at the top level are always present in a successful 200 response. Nested objects (`asn`, `privacy`, `hosting`, `company`, `abuse`) may be `null` when IPLocate has no data for that category for the requested IP.

### Top-level fields

| Field | Type | Description |
| ----- | ---- | ----------- |
| `ip` | string | The IP address that was looked up, echoed back. |
| `country` | string | Full country name in English. e.g. `"Australia"`. Empty string if unknown. |
| `country_code` | string | ISO 3166-1 alpha-2 country code. e.g. `"AU"`. Empty string if unknown. |
| `is_eu` | boolean | `true` if the country is a current European Union member state. |
| `city` | string | City name. May be an empty string for IPs with coarse geolocation. |
| `continent` | string | Continent name in English. e.g. `"North America"`, `"Europe"`, `"Oceania"`. |
| `latitude` | number | Latitude of the approximate location (decimal degrees, WGS-84). |
| `longitude` | number | Longitude of the approximate location (decimal degrees, WGS-84). |
| `time_zone` | string | IANA time zone identifier. e.g. `"America/Los_Angeles"`. May be empty. |
| `postal_code` | string | Postal / ZIP code of the approximate location. May be empty. |
| `subdivision` | string | First administrative subdivision (state, province, region). e.g. `"California"`. May be empty. |
| `currency_code` | string | ISO 4217 currency code for the country. e.g. `"USD"`, `"AUD"`. |
| `calling_code` | string | International dialling prefix without the leading `+`. e.g. `"1"`, `"61"`. |
| `is_anycast` | boolean | `true` if the IP is an anycast address (same IP announced from multiple geographic locations). Common for CDN and DNS resolver IPs. |
| `is_satellite` | boolean | `true` if the IP belongs to a satellite internet provider (e.g. Starlink). |

### `asn` object

Autonomous System data. `null` if no ASN record is found.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `asn` | string | ASN in `ASnnnnnn` format. e.g. `"AS15169"`. |
| `route` | string | The announced CIDR prefix for this IP. e.g. `"8.8.0/24"`. |
| `netname` | string | Short technical network name from the RIR database. e.g. `"GOOGLE"`. |
| `name` | string | Full organisation name registered to the ASN. e.g. `"Google LLC"`. |
| `country_code` | string | ISO 3166-1 alpha-2 country where the ASN is registered. |
| `domain` | string | Primary domain of the ASN holder. e.g. `"google.com"`. |
| `type` | string | Classification of the ASN operator. Known values: `"hosting"`, `"isp"`, `"business"`, `"education"`, `"government"`. |
| `rir` | string | Regional Internet Registry that manages this ASN. One of: `"ARIN"` (North America), `"RIPE"` (Europe/Middle East), `"APNIC"` (Asia-Pacific), `"LACNIC"` (Latin America), `"AFRINIC"` (Africa). |

### `privacy` object

Threat and anonymisation detection. All fields are booleans. Never `null` — if the parent object is present, all eight flags will be present. A `false` value means no signal was detected, not that the IP is definitively clean.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `is_vpn` | boolean | IP is a known VPN exit node or belongs to a commercial VPN service. |
| `is_proxy` | boolean | IP is a known open proxy, web proxy, or anonymising proxy. May fire on shared CGNAT IPs — treat as informational. |
| `is_tor` | boolean | IP is a Tor exit node listed in the public Tor consensus or known Tor relay lists. |
| `is_icloud_relay` | boolean | IP belongs to Apple's iCloud Private Relay infrastructure (used by iCloud+ subscribers on Apple devices). |
| `is_hosting` | boolean | IP belongs to a cloud provider, data centre, or hosting company. Does not imply malicious intent — many legitimate corporate and developer IPs trigger this. |
| `is_anonymous` | boolean | Aggregate flag that is `true` when any of `is_vpn`, `is_proxy`, `is_tor`, or `is_icloud_relay` is `true`. Useful as a single "anonymised connection" check. |
| `is_abuser` | boolean | IP has been reported in abuse databases or blocklists for spam, scraping, brute-force, or other malicious activity. |
| `is_bogon` | boolean | IP is a bogon address — a range that should never appear as a source address on the public internet (RFC 1918 private ranges, link-local, loopback, IANA reserved, etc.). |

### `hosting` object

Identifies the specific hosting or cloud product the IP belongs to. `null` if the IP is not a hosting address.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `provider` | string | Human-friendly provider name. e.g. `"Google Cloud"`, `"Amazon Web Services"`, `"DigitalOcean"`. Prefer this over `asn.name` for end-user-facing labels. |
| `domain` | string | Domain of the hosting service. e.g. `"cloud.google.com"`. |
| `network` | string | CIDR block the IP belongs to within the hosting provider's ranges. e.g. `"8.8.8.0/24"`. |

### `company` object

The commercial organisation using the IP, which may differ from the ASN holder. `null` if no company record exists.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `name` | string | Organisation name. e.g. `"Google LLC"`. |
| `domain` | string | Primary domain of the company. |
| `country_code` | string | ISO 3166-1 alpha-2 country where the company is registered. |
| `type` | string | Company classification. Same value set as `asn.type`: `"hosting"`, `"isp"`, `"business"`, `"education"`, `"government"`. |

### `abuse` object

Abuse contact information sourced from RIR WHOIS records. `null` if no abuse record is available.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `address` | string | Physical mailing address for abuse reports. |
| `country_code` | string | ISO 3166-1 alpha-2 country for the abuse contact. |
| `email` | string | Abuse contact email address. e.g. `"network-abuse@google.com"`. |
| `name` | string | Organisation or individual name for the abuse contact. |
| `network` | string | IP range covered by this abuse contact, expressed as a range. e.g. `"8.8.8.0 - 8.8.8.255"`. |
| `phone` | string | Abuse contact phone number in international format. e.g. `"+1-650-253-0000"`. |

---

## HTTP Status Codes

| Code | Meaning | Notes |
| ---- | ------- | ----- |
| `200 OK` | Successful lookup | Response body is the JSON object described above. |
| `400 Bad Request` | Invalid IP address | The path segment could not be parsed as a valid IPv4 or IPv6 address. Response body contains an error message. |
| `401 Unauthorized` | Missing or invalid API key | Returned when an API key is supplied but is not recognised. Unauthenticated requests (no key at all) fall through to the free-tier quota rather than returning 401. |
| `404 Not Found` | Unknown endpoint | The request path does not match a known endpoint. |
| `429 Too Many Requests` | Rate limit exceeded | Daily quota for the account (or IP, for unauthenticated calls) has been exhausted. No `Retry-After` header is documented. The quota resets at midnight UTC. |
| `500 Internal Server Error` | Upstream error | Transient server-side fault. No retry logic is documented; callers should treat this the same as a network failure. |

### Error response body

For 4xx and 5xx responses, the body is typically a JSON object with a single `error` key:

```json
{
  "error": "Invalid IP address"
}
```

---

## Rate Limits

### Free tier

| Dimension | Limit |
| --------- | ----- |
| Daily requests | 1,000 per day (resets midnight UTC) |
| Requests per second | Not publicly documented; well-behaved clients should not need bursting |
| Batch API | Not available |
| Credit card required | No |
| Data included | All data types — same schema and accuracy as paid |

### Paid tiers (credits and monthly plans)

| Dimension | Detail |
| --------- | ------ |
| Pay-as-you-go credits | One-time purchase, no expiry, variable volume |
| Monthly plans | Start from 100,000 requests/month; higher tiers available |
| Annual plans | Same as monthly with approximately 2 months free |
| Batch API | Available on all paid plans and with pay-as-you-go credits |
| Batch limit | Up to 1,000 IPs per single batch call |
| Throughput | Scales to 15,000+ requests/second on enterprise plans |
| Average latency | Under 20 ms globally (documented by IPLocate) |

All paid tiers return the same full response schema as the free tier — there is no premium-only data field gating.

---

## Provider Comparison Notes

### `hosting.provider` vs `asn.name`

When displaying an organisation name to end users, prefer `hosting.provider` over `asn.name`:

- `hosting.provider` is a human-friendly product name: `"Google Cloud"`, `"Amazon Web Services"`, `"DigitalOcean"`.
- `asn.name` is the raw RIR registration name: `"GOOGLE"`, `"AMAZON-02"`, `"DIGITALOCEAN-ASN"`.

`hosting.provider` is only present when `privacy.is_hosting` is `true`. For non-hosting IPs (residential ISPs, businesses), fall back to `asn.name`.

### Caveats on privacy flags

- `is_proxy` can trigger for shared CGNAT addresses (many users behind one IP) — treat it as informational, not punitive.
- `is_hosting` fires for legitimate developer and corporate egress IPs. It does not indicate malicious intent.
- `is_bogon` should never be `true` for a real public IP. If seen, it indicates a misconfigured or spoofed request.
- `is_anonymous` is a convenience aggregate; checking it is equivalent to `is_vpn || is_proxy || is_tor || is_icloud_relay`.

---

## BotOfTheSpecter callsites

| File | Lines | What it does |
| ---- | ----- | ------------ |
| `./home/profile.php` | 16 (require), 142–176 | `bots_fetch_ip_geo(string $ip, string $apiKey): ?array` — cURL-based single IP lookup with a function-static per-request cache. Sends `X-API-Key` header. `CURLOPT_TIMEOUT=8`, `CURLOPT_HTTP_VERSION=CURL_HTTP_VERSION_1_1`. Returns `null` on empty key, empty IP, cURL error, HTTP 429, or non-200. |
| `./config/iplocate.php` | all | Declares `$iplocate_api_key`. Dev copy ships an empty string; production copy at `/var/www/config/iplocate.php` holds the real key. PHP never reads from `.env` per [php-config.md](../../../rules/php-config.md). |

**Fields consumed by the dashboard** (`./home/profile.php` lines 225–248):

- `city`, `subdivision`, `country` → assembled into a "City, Region, Country" label per session row.
- `hosting.provider` (preferred) or `asn.name` (fallback) → ISP/org label shown in parentheses.
- `privacy.is_vpn`, `privacy.is_proxy`, `privacy.is_tor`, `privacy.is_icloud_relay` → inline coloured badge per flag that is `true`.

**Fields not consumed but present in the API response:** `continent`, `is_eu`, `latitude`, `longitude`, `time_zone`, `postal_code`, `currency_code`, `calling_code`, `is_anycast`, `is_satellite`, `asn.*` (except `asn.name`), `privacy.is_hosting`, `privacy.is_anonymous`, `privacy.is_abuser`, `privacy.is_bogon`, `company.*`, `abuse.*`.

**Implementation notes:**

- Per-request static cache in `bots_fetch_ip_geo` deduplicates lookups when multiple sessions share an IP. Cache is not persisted across PHP requests.
- The self-lookup endpoint (`GET /api/lookup/` with no IP) is deliberately not used — on a server-side PHP request it would return the web server's egress IP, not the user's.
- IPv6 session IPs are handled correctly; `urlencode()` encodes colons before passing to the URL.
- HTTP 429 is explicitly handled: logs the event and caches `null` so remaining sessions in the same page load still render (without geo enrichment) rather than blocking.
- No retries on 5xx or cURL timeout. The 8-second timeout is a deliberate trade-off between data completeness and page-load p95.
