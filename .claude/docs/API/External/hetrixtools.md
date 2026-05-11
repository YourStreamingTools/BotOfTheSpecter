# HetrixTools API Reference

Comprehensive local copy of the HetrixTools API documentation, covering v1, v2, and v3 endpoints across uptime monitors, blacklist monitors, server monitors, and contact lists.

**Base URLs:**
- Primary: `https://api.hetrixtools.com/`
- Relay (CloudFlare bypass): `https://relay.hetrixtools.com/api/` — identical behaviour, swap base URL only.

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [API Versions Overview](#2-api-versions-overview)
3. [Rate Limits](#3-rate-limits)
4. [Uptime Monitor Endpoints](#4-uptime-monitor-endpoints)
5. [Monitor Types](#5-monitor-types)
6. [Monitor Status Values](#6-monitor-status-values)
7. [Monitoring Locations](#7-monitoring-locations)
8. [Blacklist Monitor Endpoints](#8-blacklist-monitor-endpoints)
9. [Blacklist Check API](#9-blacklist-check-api)
10. [Server Monitor Endpoints](#10-server-monitor-endpoints)
11. [Contact Lists](#11-contact-lists)
12. [Status Page (Bulk Report) Announcements](#12-status-page-bulk-report-announcements)
13. [Utility Endpoints](#13-utility-endpoints)
14. [Error Responses](#14-error-responses)
15. [BotOfTheSpecter Callsites](#15-botofthespecter-callsites)

---

## 1. Authentication

### v3 (current, recommended)

All v3 endpoints use Bearer token authentication via the `Authorization` HTTP header.

```
Authorization: Bearer <API_KEY>
```

The token goes in the header — **not** the URL. Never put it in a query string or URL path for v3 endpoints.

### v1 / v2 (legacy)

Token is embedded directly in the URL path:

```
https://api.hetrixtools.com/v1/<API_TOKEN>/...
https://api.hetrixtools.com/v2/<API_TOKEN>/...
```

### Obtaining API Keys

Keys are managed at `https://hetrixtools.com/dashboard/account/api/`.

- Free accounts: maximum 2 API keys.
- Each key supports a descriptive note for tracking usage.
- Keys can be scoped to restrict which API calls they may make via "Configure Access".
- Keys can be regenerated; the old key stops working immediately upon regeneration.
- Each key tracks a monthly API call counter that resets on the 1st of every month.
- Keep keys private — treat them as passwords.

---

## 2. API Versions Overview

| Version | Style | Auth | Status | Notes |
|---------|-------|------|--------|-------|
| v3 | RESTful | Bearer header | Current, actively developed | Gradually replacing v1/v2; not all endpoints ported yet |
| v2 | Non-RESTful | Token in URL path | Legacy, maintained | Most add/edit/delete operations |
| v1 | Non-RESTful | Token in URL path | Legacy, maintained | List/stats operations |

HetrixTools is progressively porting all endpoints to v3. Until that migration is complete, some operations require v1 or v2 calls. All three versions remain functional.

---

## 3. Rate Limits

### v3 Endpoints

- **No monthly caps** on the number of requests.
- Rate-limited per-user, per-endpoint, per-minute.
- Current limit and reset time are returned in response headers on every v3 call:

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Maximum requests per minute for this endpoint |
| `X-RateLimit-Remaining` | Remaining requests in current window |
| `X-RateLimit-Reset` | Unix timestamp when the window resets |

- Enterprise customers can request customised thresholds.
- HTTP 429 is returned when a limit is exceeded.

### v1 / v2 Endpoints

- **120 requests per minute** across all v1/v2 endpoints combined (shared bucket).
- **Monthly quota** based on your subscription plan. Check remaining calls via the `v1 API Status` endpoint (available in the API Explorer):
  - `Max_API_Calls`: total monthly allowance for v1/v2.
  - `Remaining_API_Calls`: remaining for the current month; resets on the 1st.

### Blacklist Check Credits

Blacklist Check API calls operate on a separate credit system (not the standard monthly quota):

- One credit consumed per real-time check (non-cached result).
- Results are cached for 30 minutes; fetching a cached result costs 0 credits but counts as 1 API call.
- Monthly credits are included with blacklist monitoring packages; extra credits can be purchased ($6 per 1,000 extra at baseline, with package-level discounts up to 35%).
- Monthly credits reset on the 1st and do not roll over. Purchased extra credits never expire and are consumed after monthly credits deplete.
- Credit balance visible at `https://hetrixtools.com/dashboard/account/api/`.

---

## 4. Uptime Monitor Endpoints

### 4.1 GET Uptime Monitor Report (v3)

Fetch the uptime report for a single monitor. **This is the endpoint used by this project.**

```
GET https://api.hetrixtools.com/v3/uptime-monitors/{monitor_id}/report
Authorization: Bearer <API_KEY>
```

**Path parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `monitor_id` | string | The HetrixTools monitor ID (32-character string) |

**Response (HTTP 200):**

```json
{
  "summary": {
    "uptime": "99.98",
    "response_time": 123
  },
  "data": {
    "2025-05-10": {
      "uptime": "100.00",
      "response_time": {
        "avg": 118,
        "min": 95,
        "max": 210
      }
    },
    "2025-05-09": { ... }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `summary.uptime` | string | Uptime percentage over the report period (e.g. `"99.98"`) |
| `summary.response_time` | number | Average response time in milliseconds |
| `data` | object | Keyed by `YYYY-MM-DD` date strings |
| `data.<date>.uptime` | string | Uptime percentage for that calendar day |
| `data.<date>.response_time` | object | Per-day response time stats (`avg`, `min`, `max` in ms) |

**Notes:**
- `response_time` values are not guaranteed to be present on all accounts/tiers.
- `data` keys are `YYYY-MM-DD` strings and sort lexicographically. The most-recent day is `sorted(data.keys(), reverse=True)[0]`.
- This project strips all `response_time` fields before exposing the data publicly (see section 15).

---

### 4.2 List Uptime Monitors (v1)

List all uptime monitors with pagination. Referenced throughout the docs as the canonical way to discover monitor IDs.

```
GET https://api.hetrixtools.com/v1/<API_TOKEN>/uptime/monitors/<PAGE>/<PER_PAGE>/
```

**Path parameters:**

| Parameter | Type | Default | Max | Description |
|-----------|------|---------|-----|-------------|
| `PAGE` | integer | 0 | — | Zero-based page number |
| `PER_PAGE` | integer | 30 | 1024 | Items per page |

**Response:** Array of monitor objects. Response schema is not fully documented in public docs; use the API Explorer in the HetrixTools dashboard to inspect live responses.

---

### 4.3 Add Uptime Monitor (v2)

Create a new uptime monitor.

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/uptime/add/
Content-Type: application/json
```

**Request body — general parameters (all types except Server Agent unless noted):**

| Parameter | Type | Required | Accepted values / notes |
|-----------|------|----------|-------------------------|
| `Type` | integer | Yes | `1`=Website, `2`=Ping/Service, `3`=SMTP, `9`=Server Agent |
| `Name` | string | Yes | a–z, A–Z, 0–9, spaces, dots, dashes |
| `Target` | string | Conditional | URL for Website; IP/hostname for Ping/SMTP; omit for Server Agent |
| `Timeout` | integer | Yes | Website: `3`, `5`, `10`, `15` (seconds). Server Agent: `60`–`3600` (seconds). |
| `Frequency` | integer | Yes | Check interval in minutes: `1`, `3`, `5`, `10`. Ignored for Server Agent. |
| `FailsBeforeAlert` | integer | Yes | Consecutive failures before alerting: `1`–`3`. Ignored for Server Agent. |
| `FailedLocations` | integer | No | Number of locations that must fail before alerting: `2`–`12`. Auto-calculated if empty. |
| `ContactList` | string | No | Contact List ID. Empty string for no notifications. |
| `Category` | string | No | Category label. Empty string for none. |
| `AlertAfter` | integer | No | Delay before first alert (minutes). Must be a multiple of `Frequency`. |
| `RepeatTimes` | integer | No | Number of times to repeat the alert: `0`–`30`. |
| `RepeatEvery` | integer | No | Repeat interval in minutes. Must be a multiple of `Frequency`. |
| `Public` | boolean | Yes | `true` to make the uptime report public. |
| `ShowTarget` | boolean | Yes | `true` to show the monitored target in the public report. |
| `VerSSLCert` | boolean | Yes | `true` to verify SSL certificate validity. |
| `VerSSLHost` | boolean | Yes | `true` to verify SSL hostname matches certificate. |

**Request body — monitoring locations (all types except Server Agent):**

Each location is a boolean field. Include at least one.

| Field | Location |
|-------|----------|
| `nyc` | New York, USA |
| `sfo` | San Francisco, USA |
| `dal` | Dallas, USA |
| `ams` | Amsterdam, Netherlands |
| `lon` | London, UK |
| `fra` | Frankfurt, Germany |
| `sgp` | Singapore |
| `syd` | Sydney, Australia |
| `sao` | São Paulo, Brazil |
| `tok` | Tokyo, Japan |
| `mba` | Mumbai, India |
| `waw` | Warsaw, Poland |

**Request body — Website-specific parameters (Type=1):**

| Parameter | Type | Required | Accepted values / notes |
|-----------|------|----------|-------------------------|
| `Method` | string | No | `GET` or `HEAD` |
| `Keyword` | string | No | String to search for on the page (max 128 chars). If set and not found, monitor reports offline. |
| `HTTPCodes` | string | No | Comma-separated list of HTTP status codes to treat as success (e.g. `"200,301"`) |
| `MaxRedirects` | integer | No | Max redirects to follow: `0`–`10` |
| `SSLExpiryReminder` | integer | No | Days before SSL expiry to alert: `0`, `1`, `2`, `3`, `5`, `10`, `15`, `30`. `0` = disabled. |
| `DomainExpiryReminder` | integer | No | Days before domain expiry to alert: `0`, `1`, `2`, `3`, `5`, `10`, `15`, `30`. `0` = disabled. |
| `NSChangeAlert` | integer | No | `0`=disabled, `1`=alert on nameserver changes |

**Request body — Ping/Service-specific parameters (Type=2):**

| Parameter | Type | Required | Accepted values / notes |
|-----------|------|----------|-------------------------|
| `Port` | integer | No | Port to monitor: `0`–`65535`. Omit or `0` for ICMP ping. |
| `DomainExpiryReminder` | integer | No | Same as Website type |
| `NSChangeAlert` | integer | No | Same as Website type |

**Request body — SMTP-specific parameters (Type=3):**

| Parameter | Type | Required | Accepted values / notes |
|-----------|------|----------|-------------------------|
| `Port` | integer | Yes | SMTP port: `0`–`65535` |
| `CheckAuth` | boolean | No | `true` to verify SMTP authentication |
| `SMTPUser` | string | Conditional | SMTP username — required when `CheckAuth=true` |
| `SMTPPass` | string | Conditional | SMTP password — required when `CheckAuth=true` |
| `DomainExpiryReminder` | integer | No | Same as Website type |
| `NSChangeAlert` | integer | No | Same as Website type |

**Request body — Server Agent-specific parameters (Type=9):**

| Parameter | Type | Required | Accepted values / notes |
|-----------|------|----------|-------------------------|
| `Timeout` | integer | Yes | Heartbeat interval (seconds): `60`, `120`, `180`, `240`, `300`, `600`, `900`, `1800`, `3600` |
| `Grace` | integer | Yes | Seconds after `Timeout` before marking monitor DOWN: same accepted values as `Timeout` |
| `ContactList` | string | No | Same as general |
| `Category` | string | No | Same as general |
| `AlertAfter` | integer | No | `1`–`60` (minutes): `1`, `2`, `3`, `4`, `5`, `6`, `7`, `8`, `9`, `10`, `15`, `20`, `30`, `40`, `50`, `60` |
| `RepeatTimes` | integer | No | `0`–`30` |
| `RepeatEvery` | integer | No | `1`–`60` (minutes), same accepted values as `AlertAfter` |
| `Public` | boolean | Yes | Same as general |
| `ShowTarget` | boolean | Yes | Same as general |
| `INFOPub` | boolean | Yes | `true` to publish the Server Info section on the report |
| `CPUPub` | boolean | Yes | `true` to publish the CPU Usage section |
| `RAMPub` | boolean | Yes | `true` to publish the RAM Usage section |
| `DISKPub` | boolean | Yes | `true` to publish the Disk Usage section |
| `NETPub` | boolean | Yes | `true` to publish the Network Usage section |

**Success response:**

```json
{"status": "SUCCESS", "monitor_id": "xyz", "action": "added"}
```

For Server Agent type, `server_id` is also returned — this is the SID used in the server monitoring agent:

```json
{"status": "SUCCESS", "monitor_id": "xyz", "server_id": "abc", "action": "added"}
```

**Error response:**

```json
{"status": "ERROR", "error_message": "maximum number of monitors has been reached"}
```

---

### 4.4 Edit Uptime Monitor (v2)

Edit an existing uptime monitor. Uses the same endpoint and same parameters as Add, plus the monitor ID.

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/uptime/edit/
Content-Type: application/json
```

**Additional required parameter:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MID` | string | Yes | Monitor ID (32-character string) of the monitor to edit |

All other parameters are the same as [4.3 Add Uptime Monitor](#43-add-uptime-monitor-v2). Only fields you wish to change need to be included alongside `MID`.

**Success response:**

```json
{"status": "SUCCESS", "monitor_id": "xyz", "action": "updated"}
```

---

### 4.5 Delete Uptime Monitor (v2)

Delete an existing uptime monitor.

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/uptime/delete/
Content-Type: application/json
```

**Request body:**

```json
{"MID": "<monitor_id>"}
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MID` | string | Yes | Monitor ID (32-character string) |

**Success response:**

```json
{"status": "SUCCESS", "monitor_id": "xyz", "action": "deleted"}
```

**Error response:**

```json
{"status": "ERROR", "error_message": "monitor id does not exist"}
```

---

### 4.6 Maintenance Mode (v2)

Put a monitor into or out of maintenance mode.

```
GET https://api.hetrixtools.com/v2/<API_TOKEN>/maintenance/<UPTIME_MONITOR_ID>/<MAINTENANCE_MODE>/
```

**Path parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `UPTIME_MONITOR_ID` | string | Monitor ID; obtain via v1 List Uptime Monitors |
| `MAINTENANCE_MODE` | integer | `1`=normal operation (exit maintenance), `2`=maintenance with notifications, `3`=maintenance without notifications |

---

## 5. Monitor Types

| Type code | Name | Description |
|-----------|------|-------------|
| `1` | Website | Checks an HTTP/HTTPS URL. Supports keyword detection, SSL/domain expiry alerts, redirect following, HTTP method selection, custom accepted HTTP codes. |
| `2` | Ping / Service | Checks reachability of an IP or hostname. If a port is specified, checks TCP connectivity on that port (service monitor). If no port, uses ICMP ping. Supports domain/NS monitoring. |
| `3` | SMTP | Connects to an SMTP server on a specified port. Optionally authenticates with credentials to verify the mail server is fully functional. |
| `9` | Server Agent (Heartbeat) | A heartbeat monitor — the server must actively check in within the configured interval. Used with the HetrixTools server monitoring agent. Tracks CPU, RAM, disk, network metrics. |

---

## 6. Monitor Status Values

HetrixTools does not publish a complete status enum in its public docs, but the following values appear in the interface and API responses:

| Status | Meaning |
|--------|---------|
| **Online** / up | Monitor is reachable and responding within expected parameters |
| **Offline** / down | Monitor is unreachable or failing checks from the required number of locations |
| **Maintenance** | Monitor has been deliberately placed in maintenance mode; alerts suppressed depending on mode setting |
| **Paused** | Monitor checks are suspended |

For the report endpoint (section 4.1), status is not returned directly — only `uptime` percentage and optionally per-day breakdown data are returned.

---

## 7. Monitoring Locations

These are the 12 locations available for uptime checks. Each is referenced by its short code in the API.

| Code | City | Country |
|------|------|---------|
| `nyc` | New York | USA |
| `sfo` | San Francisco | USA |
| `dal` | Dallas | USA |
| `ams` | Amsterdam | Netherlands |
| `lon` | London | UK |
| `fra` | Frankfurt | Germany |
| `sgp` | Singapore | Singapore |
| `syd` | Sydney | Australia |
| `sao` | São Paulo | Brazil |
| `tok` | Tokyo | Japan |
| `mba` | Mumbai | India |
| `waw` | Warsaw | Poland |

---

## 8. Blacklist Monitor Endpoints

### 8.1 List Blacklist Monitors (v2)

```
GET https://api.hetrixtools.com/v2/<API_TOKEN>/blacklist/monitors/<PAGE>/<PER_PAGE>/
```

| Parameter | Type | Default | Max | Description |
|-----------|------|---------|-----|-------------|
| `PAGE` | integer | 0 | — | Zero-based page number |
| `PER_PAGE` | integer | 30 | 1024 | Items per page |

Returns all blacklist monitors and includes any RBLs on which each monitor is currently listed.

---

### 8.2 Add Blacklist Monitor (v2)

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/blacklist/add/
Content-Type: application/x-www-form-urlencoded
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `target` | string | Yes | IPv4 address (`1.2.3.4`), IP range (`1.2.3.4 – 1.2.3.7`), IP block in CIDR (`1.2.3.4/28`), or domain name |
| `label` | string | No | Human-readable label for the monitor(s) |
| `contact` | string | Yes | Contact List ID for notifications; retrieve via v1 List Contact Lists |

Monitors are added and begin checking immediately. Consumes **1 API call** regardless of how many IPs are submitted in a single call.

---

### 8.3 Edit Blacklist Monitor (v2)

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/blacklist/edit/
Content-Type: application/x-www-form-urlencoded
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `target` | string | Yes | Same formats as Add: IPv4, range, CIDR block, or domain |
| `label` | string | No | New label; leave empty to clear the existing label |
| `contact` | string | No | New Contact List ID; leave empty to retain the current list |

Consumes 1 API call regardless of how many monitors are modified.

---

### 8.4 Delete Blacklist Monitor (v2)

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/blacklist/delete/
Content-Type: application/x-www-form-urlencoded
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `target` | string | Yes | IPv4, range, CIDR block, or domain to remove |

Removes matching monitors. Consumes 1 API call regardless of how many monitors are removed.

---

## 9. Blacklist Check API

On-demand real-time blacklist checks. Uses the **Blacklist Check Credit** system (see section 3), not the standard monthly quota.

### 9.1 Check IPv4 Address

```
GET https://api.hetrixtools.com/v2/<API_TOKEN>/blacklist-check/ipv4/<IP_ADDRESS>/
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `IP_ADDRESS` | string | IPv4 address to check |

### 9.2 Check Domain / Hostname

```
GET https://api.hetrixtools.com/v2/<API_TOKEN>/blacklist-check/domain/<DOMAIN>/
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `DOMAIN` | string | Domain or hostname to check |

### Response fields (both endpoints)

| Field | Type | Description |
|-------|------|-------------|
| `status` | string | `"SUCCESS"` or `"ERROR"` |
| `api_calls_left` | integer | Remaining monthly API calls |
| `blacklist_check_credits_left` | integer | Remaining blacklist check credits |
| `blacklisted_count` | integer | Number of RBLs listing the target |
| `blacklisted_on` | array | Array of `{rbl: "<list name>", delist: "<removal URL>"}` objects |
| `links` | object | Contains public report URL and API report URL |
| `error_message` | string | Present on error; e.g. `"blacklist check in progress for this ipv4"`, `"no blacklist check credits"` |

**Notes:**
- Real-time checks take up to 5 minutes to complete.
- Results are cached for 30 minutes. A request hitting the cache returns immediately and costs 0 credits (but counts as 1 API call).
- A check already in progress for the same target returns `{"status": "ERROR", "error_message": "blacklist check in progress for this ipv4"}` — retry after a few seconds.

---

## 10. Server Monitor Endpoints

### 10.1 Get Server Stats (v1)

Retrieve the latest metrics from the server monitoring agent installed on a machine.

```
GET https://api.hetrixtools.com/v1/<API_TOKEN>/server/stats/<UPTIME_MONITOR_ID>/
```

**Response schema:**

| Field | Type | Description |
|-------|------|-------------|
| `UptimeMonitorName` | string | Name of the monitor |
| `AgentID` | string | Unique server agent ID |
| `AgentAddTime` | integer | Unix timestamp when agent was installed |
| `AgentLastData` | integer | Unix timestamp of last data receipt |
| `AgentVersion` | string | Agent software version |
| `AgentType` | string | Operating system type |
| `AgentIPAddress` | string | IP address used for data transmission |
| `SystemUptime` | integer | Server uptime in seconds |
| `OperatingSystem` | string | OS name and version |
| `Kernel` | string | Kernel version |
| `RebootRequired` | boolean | Whether a reboot is needed for pending updates |
| `CPUModel` | string | Processor model name |
| `CPUSpeed` | integer | CPU speed in MHz |
| `CPUCores` | integer | Number of CPU cores |
| `RAM` | integer | Total RAM in bytes |
| `Swap` | integer | Total swap in bytes |
| `Disk` | integer | Total disk in bytes |
| `Disks` | array | Per-mount disk details (see below) |
| `Drives` | array | Per-physical-drive SMART details (see below) |
| `NetworkInterfaces` | array | Per-NIC traffic and address info (see below) |
| `Services` | array | Per-service status (see below) |
| `PortConnections` | array | Per-port active connection counts (see below) |
| `Stats` | array | 60-minute rolling stats, one entry per minute (see below) |

**`Disks` array entry:**

| Field | Type | Description |
|-------|------|-------------|
| `Mount` | string | Mount point path |
| `Size` | integer | Total size in bytes |
| `Used` | integer | Used space in bytes |
| `Available` | integer | Available space in bytes |
| `IORead` | integer | Read I/O operations |
| `IOWrite` | integer | Write I/O operations |
| `Inodes` | integer | Total inodes |
| `InodesUsed` | integer | Used inodes |
| `RAID` | object\|null | RAID config: `Type`, `Health`, `State`, `TotalDrives`, `ActiveDrives`, `WorkingDrives`, `SpareDrives`, `FailedDrives` |

**`Drives` array entry:**

| Field | Type | Description |
|-------|------|-------------|
| `Name` | string | Drive identifier |
| `Type` | string | Drive type: `NVMe`, `HDD`, `SSD` |
| `SMARTTest` | string | SMART test result |
| `PowerOnHours` | integer | Hours the drive has been powered on |
| `PowerCycles` | integer | Total power cycles |
| `UnsafeShutdowns` | integer | Count of unsafe shutdowns |
| `ErrorCount` | integer | Total errors detected |
| `ErrorDetails` | object\|null | Detailed error breakdown |

**`NetworkInterfaces` array entry:**

| Field | Type | Description |
|-------|------|-------------|
| `Name` | string | Interface name (e.g. `eth0`) |
| `NetIn` | integer | Incoming bytes |
| `NetOut` | integer | Outgoing bytes |
| `IPv4` | array\|null | IPv4 address strings |
| `IPv6` | array\|null | IPv6 address strings |

**`Services` array entry:**

| Field | Type | Description |
|-------|------|-------------|
| `Name` | string | Service name |
| `Status` | string | e.g. `"online"` |

**`PortConnections` array entry:**

| Field | Type | Description |
|-------|------|-------------|
| `Port` | integer | Port number |
| `Connections` | integer | Active connection count |

**`Stats` array entry (last 60 minutes, one entry per minute):**

| Field | Type | Description |
|-------|------|-------------|
| `Minute` | integer | Unix timestamp |
| `CPU` | number | Overall CPU usage percentage |
| `IOWait` | number | I/O wait percentage |
| `Steal` | number | CPU steal time percentage |
| `User` | number | User CPU time percentage |
| `System` | number | System CPU time percentage |
| `Temp` | number | CPU temperature in °C |
| `Load1` | number | 1-minute load average |
| `Load5` | number | 5-minute load average |
| `Load15` | number | 15-minute load average |
| `RAM` | number | RAM usage percentage |
| `Swap` | number | Swap usage percentage |
| `Buffered` | number | Buffered memory percentage |
| `Cached` | number | Cached memory percentage |
| `Disk` | number | Disk usage percentage |
| `NetIn` | integer | Incoming traffic in bytes |
| `NetOut` | integer | Outgoing traffic in bytes |

---

### 10.2 Get Server Agent ID (v1)

Retrieve (or generate) the Server Agent ID associated with an uptime monitor. The SID is required when installing the server monitoring agent.

```
GET https://api.hetrixtools.com/v1/<API_TOKEN>/get/agentid/<UPTIME_MONITOR_ID>/
```

**Response:**

```json
{"AgentID": "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz", "Status": "old"}
```

| Field | Type | Description |
|-------|------|-------------|
| `AgentID` | string | The unique Server Agent ID |
| `Status` | string | `"new"` (freshly generated) or `"old"` (pre-existing) |

---

## 11. Contact Lists

Contact list IDs are required by several create/edit endpoints (`ContactList` parameter for uptime monitors, `contact` for blacklist monitors). Retrieve available list IDs using the v1 List Contact Lists call available in the API Explorer. The public docs do not detail the response schema for contact list endpoints.

---

## 12. Status Page (Bulk Report) Announcements

### Set Announcement (v2)

Create or overwrite the announcement on a Bulk Report (Status Page).

```
POST https://api.hetrixtools.com/v2/<API_TOKEN>/announcement/<BULK_REP_ID>/set/
Content-Type: application/json
```

**Path parameter:**
- `BULK_REP_ID`: The Bulk Report ID — visible in the URL when accessing the report in the browser.

**Request body:**

```json
{
  "Title": "Scheduled Maintenance",
  "Body": "We will be performing maintenance.\nExpected duration: 2 hours.",
  "Color": "warning",
  "Affected": ["monitorID1", "monitorID2"]
}
```

| Parameter | Type | Required | Accepted values / notes |
|-----------|------|----------|-------------------------|
| `Title` | string | Yes | Cannot be empty |
| `Body` | string | No | Announcement text body. Use `\n` for newlines. |
| `Color` | string | No | `"none"`, `"success"`, `"info"`, `"warning"`, `"danger"` |
| `Affected` | array | No | List of monitor ID strings affected by the announcement |

This call creates a new announcement or overwrites any previously set announcement. Monitor IDs can be retrieved via v2 List Blacklist Monitors or v1 List Uptime Monitors.

---

## 13. Utility Endpoints

### 13.1 API Relay

When the primary endpoint (`api.hetrixtools.com`) is blocked by CloudFlare due to IP reputation, use the relay endpoint instead:

```
https://relay.hetrixtools.com/api/
```

Replace the base URL only. All paths, tokens, and parameters remain identical.

Example:
- Original: `https://api.hetrixtools.com/v1/<TOKEN>/status/`
- Via relay: `https://relay.hetrixtools.com/api/v1/<TOKEN>/status/`

### 13.2 v1 API Status

Retrieve API quota usage. Available via the API Explorer. Returns `Max_API_Calls` (monthly v1/v2 allowance) and `Remaining_API_Calls` (remaining this month).

---

## 14. Error Responses

### Common error response structure

All v1/v2 endpoints return a consistent error envelope:

```json
{"status": "ERROR", "error_message": "<description>"}
```

### Known error messages

| Error message | Cause |
|---------------|-------|
| `"monitor id does not exist"` | `MID` passed to edit/delete does not exist in the account |
| `"maximum number of monitors has been reached"` | Account plan limit for monitor count is hit |
| `"blacklist check in progress for this ipv4"` | A real-time check is already running for this IP — retry in a few seconds |
| `"no blacklist check credits"` | Blacklist Check Credit balance is zero |

### HTTP status codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 400 | Bad request (malformed parameters) |
| 403 | Forbidden (invalid API token or insufficient key permissions) |
| 429 | Rate limit exceeded (v3 per-minute limit or v1/v2 120 req/min limit) |

---

## 15. BotOfTheSpecter Callsites

This project uses HetrixTools exclusively for external reachability reporting on the public status page.

**Single endpoint used:**

```
GET https://api.hetrixtools.com/v3/uptime-monitors/{monitor_id}/report
Authorization: Bearer <HETRIXTOOLS_API_KEY>
```

**Where it's invoked:** `./api/api.py`, inside the `/system/uptime` endpoint handler (~lines 2645–2730).

**Five monitors configured:**

| Section key | Env var holding the monitor ID |
|-------------|-------------------------------|
| `API` | `HETRIX_MONITOR_API` |
| `WEBSOCKET` | `HETRIX_MONITOR_WEBSOCKET` |
| `WEB1` | `HETRIX_MONITOR_WEB1` |
| `SQL` | `HETRIX_MONITOR_SQL` |
| `BOTS` | `HETRIX_MONITOR_BOTS` |

**Auth env var:** `HETRIXTOOLS_API_KEY` — read via `os.getenv()` in `./api/api.py`. If unset, the entire HetrixTools loop is skipped and the endpoint falls back to local SSH markers and DB metrics only.

**Sanitisation applied before public exposure:**

1. `summary.response_time` is removed.
2. `data.<date>.response_time` objects are removed for every day.
3. Any nested `response_time` keys inside sub-objects under a day are also stripped.
4. Only the most-recent day from `data` is kept — selected as `sorted(data.keys(), reverse=True)[0]` — and surfaced as `External API Metrics.latest_day`.
5. The sanitised `summary` object is surfaced as `External API Metrics` in the relevant section of the final response.

**Where results land in the `/system/uptime` response:**

- `API` monitor → `response.API["External API Metrics"]`
- `WEBSOCKET` monitor → `response.WEBSOCKET["External API Metrics"]`
- `WEB1`, `SQL`, `BOTS` → `response.<NAME>["External API Metrics"]`

**Error handling:** per-monitor failures (timeout, non-200, malformed JSON) are logged via `logging.warning` / `logging.exception` and skip only that monitor — the rest of the response is returned normally. Errors are never exposed in the public response body.

**Rate-limit mitigation:** monitors are fetched sequentially (one `aiohttp.ClientSession`, one GET at a time) within a single `aiohttp.ClientSession`. The `/system/uptime` endpoint is itself throttled per-IP via `SYSTEM_UPTIME_RATE_LIMIT_SECONDS` (default 300 seconds), limiting worst-case to 5 HetrixTools GETs per 5-minute window per IP.

**Gotchas:**

- v3 docs are a JavaScript-rendered SPA; the schema above for section 4.1 is derived from the code's parsing logic plus any fragments available from static doc pages.
- v2 puts the token in the URL path. v3 uses Bearer headers. They are not interchangeable.
- Monitor IDs are account-scoped. Don't copy a monitor ID from a different HetrixTools account.
- Date key sorting assumes `YYYY-MM-DD` format. If HetrixTools changes the key format, "latest day" selection in `sorted(..., reverse=True)[0]` will silently break.
- One monitor per logical server, not per process. If a server moves to multi-instance, the monitor reports aggregate reachability. Add a new monitor + env var + `monitor_map` entry if per-instance reporting is ever needed.
