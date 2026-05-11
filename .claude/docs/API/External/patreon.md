# Patreon Webhooks v2 — Local API Reference

Patreon Webhooks v2 push campaign events (membership changes, pledge changes, posts) to a
registered HTTPS endpoint in real time. This document is the complete local reference for the
BotOfTheSpecter integration; do not poll the Patreon API to detect changes.

---

## Table of contents

1. [OAuth scopes](#1-oauth-scopes)
2. [Webhook management API](#2-webhook-management-api)
   - 2.1 Create a webhook
   - 2.2 List webhooks
   - 2.3 Update a webhook
   - 2.4 Delete a webhook
   - 2.5 Webhook resource schema
3. [Trigger events](#3-trigger-events)
4. [Inbound request headers](#4-inbound-request-headers)
5. [Signature verification](#5-signature-verification)
6. [Retry / failure behaviour](#6-retry--failure-behaviour)
7. [Payload structure (JSON:API)](#7-payload-structure-jsonapi)
8. [Resource schemas](#8-resource-schemas)
   - 8.1 Member
   - 8.2 Pledge-event (pledge_history)
   - 8.3 User
   - 8.4 Campaign
   - 8.5 Tier
   - 8.6 Post
9. [Included relationships and graph traversal](#9-included-relationships-and-graph-traversal)
10. [Amount representation](#10-amount-representation)
11. [BotOfTheSpecter callsites](#11-botofthespecter-callsites)

---

## 1. OAuth scopes

| Scope | Access granted |
| ----- | -------------- |
| `w:campaigns.webhook` | Read, write, update, and delete the campaign's webhooks created by this OAuth client. Required to call any webhook management endpoint. |
| `campaigns.members` | Read member data (attributes shown in §8.1). |
| `campaigns.members[email]` | Additionally exposes `email` on the member resource. Without this scope the `email` field is absent from the payload. |
| `identity[email]` | Exposes `email` on the user resource included in payloads. |

The webhook management endpoints require a **Creator's Access Token** (or an OAuth token from a
creator account) issued to your registered Patreon API client. Client registration:
`https://www.patreon.com/portal/registration/register-clients`.

---

## 2. Webhook management API

Base URL: `https://www.patreon.com/api/oauth2/v2`

All management requests must carry `Authorization: Bearer <creator_access_token>` and
`Content-Type: application/json`.

### 2.1 Create a webhook

```
POST /webhooks
```

Request body (JSON:API):

```json
{
  "data": {
    "type": "webhook",
    "attributes": {
      "triggers": [
        "members:create",
        "members:pledge:create",
        "members:pledge:update",
        "members:pledge:delete"
      ],
      "uri": "https://api.botofthespecter.com/patreon?api_key=<USER_API_KEY>"
    },
    "relationships": {
      "campaign": {
        "data": { "type": "campaign", "id": "<CAMPAIGN_ID>" }
      }
    }
  }
}
```

- `triggers` — array of trigger names (see §3). Omitting a trigger means that event will never
  be delivered to this webhook.
- `uri` — must be a fully qualified HTTPS URL; Patreon will reject plain HTTP.
- `relationships.campaign` — the campaign whose events should fire this webhook. A creator can
  have more than one campaign; specify which one explicitly.

Successful response (HTTP 200):

```json
{
  "data": {
    "type": "webhook",
    "id": "<WEBHOOK_ID>",
    "attributes": {
      "last_attempted_at": null,
      "num_consecutive_times_failed": 0,
      "paused": false,
      "secret": "<32+ CHARACTER RANDOM STRING>",
      "triggers": ["members:create", "members:pledge:create", ...],
      "uri": "https://api.botofthespecter.com/patreon?api_key=<USER_API_KEY>"
    },
    "relationships": {
      "campaign": { "data": { "type": "campaign", "id": "<CAMPAIGN_ID>" } },
      "client":   { "data": { "type": "oauthclient", "id": "<CLIENT_ID>" } }
    }
  }
}
```

**`attributes.secret` is returned only on creation and never again.** Store it immediately in a
secure location (e.g. `patreon_webhook_secret` per-user in the `users` table or a dedicated
`patreon_tokens` table). It is required for HMAC signature verification (§5).

### 2.2 List webhooks

```
GET /webhooks
```

Returns only webhooks created by the authenticating OAuth client. Webhooks created by other
clients are not visible, even for the same campaign.

Response: standard JSON:API list with `data` array of webhook objects (same schema as §2.5).

### 2.3 Update a webhook

```
PATCH /webhooks/{webhook_id}
```

Request body — include only the fields to change:

```json
{
  "data": {
    "id": "<WEBHOOK_ID>",
    "type": "webhook",
    "attributes": {
      "triggers": ["members:create", "members:update"],
      "uri": "https://api.botofthespecter.com/patreon?api_key=<NEW_API_KEY>",
      "paused": false
    }
  }
}
```

Setting `paused: false` is the only way to re-enable a paused webhook **and** flush the queued
events that accumulated while the endpoint was unreachable (see §6).

### 2.4 Delete a webhook

```
DELETE /webhooks/{webhook_id}
```

Returns HTTP 204 on success. No body.

### 2.5 Webhook resource schema

| Field | Type | Description |
| ----- | ---- | ----------- |
| `id` | string | Unique webhook identifier. |
| `type` | string | Always `"webhook"`. |
| `attributes.last_attempted_at` | ISO 8601 \| null | Timestamp of the most recent delivery attempt. |
| `attributes.num_consecutive_times_failed` | integer | Count of consecutive failed delivery attempts. Resets to 0 on any success. |
| `attributes.paused` | boolean | `true` when Patreon has stopped delivering events due to repeated failures. |
| `attributes.secret` | string | HMAC signing secret. **Only present in the creation response.** |
| `attributes.triggers` | string[] | List of trigger names configured on this webhook. |
| `attributes.uri` | string | Delivery URL. |
| `relationships.campaign` | relationship | The campaign this webhook listens to. |
| `relationships.client` | relationship | The OAuth client that owns this webhook. |

---

## 3. Trigger events

The trigger name is sent in the `X-Patreon-Event` request header on every delivery (§4). It is
**not** present in the JSON body.

### Member triggers

| Trigger | When it fires | Notes |
| ------- | ------------- | ----- |
| `members:create` | A new member resource is created — covers first follow, first free join, and first paid join. | May fire more than once for the same patron if they delete and rejoin. |
| `members:update` | Membership attributes change. Also fires on every payment charge attempt (success or failure). | `last_charge_status` and `last_charge_date` will reflect the new charge state. |
| `members:delete` | The membership resource is deleted. | May fire more than once per patron if they delete and rejoin. |
| `members:pledge:create` | A new paid pledge is created within an existing membership. | Does **not** fire when a user becomes a free member or redeems a gift membership. |
| `members:pledge:update` | A patron upgrades or downgrades their pledge tier. | Body shape is identical to `members:pledge:create`; use `X-Patreon-Event` to distinguish. |
| `members:pledge:delete` | A patron cancels a paid pledge. | Does **not** fire when a free or gifted membership is cancelled. |

All six member triggers deliver a **member** resource as `data` (§8.1). The `data.type` field
will be `"member"` for all of them. The only reliable distinction between triggers is the
`X-Patreon-Event` header.

### Post triggers

| Trigger | When it fires |
| ------- | ------------- |
| `posts:publish` | A post is published on the campaign (initial publish or republish). |
| `posts:update` | An existing post is edited. |
| `posts:delete` | A post is deleted. |

Post triggers deliver a **post** resource as `data` (§8.6).

---

## 4. Inbound request headers

Patreon sends the following headers on every webhook delivery:

| Header | Value |
| ------ | ----- |
| `Content-Type` | `application/json` |
| `X-Patreon-Event` | Trigger name, e.g. `members:pledge:create`. |
| `X-Patreon-Signature` | Lowercase hex HMAC-MD5 digest of the raw request body, signed with the webhook `secret`. |
| `User-Agent` | Patreon HTTP client string (not stable; do not rely on it). |

Both `X-Patreon-Event` and `X-Patreon-Signature` must be extracted **before** the body is
parsed, because signature verification operates on the raw bytes (§5).

---

## 5. Signature verification

Patreon signs each delivery with HMAC-MD5 using the `secret` returned at webhook creation time.

**Algorithm:**

1. Read the **raw** request body bytes (do not parse JSON first).
2. Compute `HMAC-MD5(key=secret, message=raw_body)`.
3. Hex-encode the digest (lowercase).
4. Compare with the `X-Patreon-Signature` header value using a constant-time comparison.

Python implementation:

```python
import hmac
import hashlib

def verify_patreon_signature(secret: str, raw_body: bytes, signature_header: str) -> bool:
    expected = hmac.new(secret.encode("utf-8"), raw_body, hashlib.md5).hexdigest()
    return hmac.compare_digest(expected, signature_header)
```

FastAPI / Starlette usage (read body before JSON parse):

```python
@app.post("/patreon")
async def handle_patreon_webhook(request: Request, api_key: str = Query(...)):
    raw_body = await request.body()          # bytes — must come before request.json()
    sig = request.headers.get("X-Patreon-Signature", "")
    event = request.headers.get("X-Patreon-Event", "")
    secret = await get_patreon_secret_for_key(api_key)   # load from DB
    if not verify_patreon_signature(secret, raw_body, sig):
        raise HTTPException(status_code=401, detail="Invalid Patreon signature")
    webhook_data = json.loads(raw_body)
    ...
```

**Current status in this codebase:** `handle_patreon_webhook` (./api/api.py:1754) does **not**
perform HMAC verification. The webhook `secret` is not stored per-user. This is a known TODO
(see §11).

---

## 6. Retry / failure behaviour

- Patreon retries delivery when the endpoint returns a non-2xx status code or does not respond
  within a timeout window.
- After repeated failures, `num_consecutive_times_failed` on the webhook resource increments.
- Once failures pass an internal threshold, Patreon sets `paused: true` and stops delivering
  new events. Events continue to be **queued** internally.
- To resume delivery and flush the queue, send:
  ```
  PATCH /api/oauth2/v2/webhooks/{id}
  { "data": { "id": "{id}", "type": "webhook", "attributes": { "paused": false } } }
  ```
- **No idempotency key is provided.** `members:create`, `members:update`, and
  `members:delete` may fire more than once for the same patron. Deduplicate in the handler
  using `(data.id, X-Patreon-Event, attributes.last_charge_date)` or a separate delivery log.

---

## 7. Payload structure (JSON:API)

All Patreon webhook payloads conform to the [JSON:API 1.0](https://jsonapi.org) specification.

Top-level envelope:

```json
{
  "data": {
    "type": "<resource-type>",
    "id": "<resource-id>",
    "attributes": { ... },
    "relationships": {
      "<rel-name>": {
        "data": { "type": "<type>", "id": "<id>" }
      }
    }
  },
  "included": [
    { "type": "<related-type>", "id": "<id>", "attributes": { ... }, "relationships": { ... } },
    ...
  ],
  "links": {
    "self": "<canonical-resource-url>"
  }
}
```

Key rules:
- `data.type` is the **resource type** (`"member"`, `"post"`), not the trigger name. Do not use
  it to distinguish `members:create` from `members:update`.
- `included` contains the full attribute payloads for related resources that Patreon chose to
  embed (campaign, user, currently-entitled tiers). Their presence is not guaranteed for every
  trigger; always guard with null checks.
- Relationship stubs inside `data.relationships` contain only `type` and `id`. Full attribute
  data for those resources appears as an element in `included` with matching `type` + `id`.
- Patreon may add new attributes to any resource without a version bump. Ignore unknown fields
  rather than failing.

---

## 8. Resource schemas

### 8.1 Member

`data.type = "member"` — delivered for all six member triggers.

**Attributes:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `campaign_lifetime_support_cents` | integer | Total amount ever paid to this campaign, in cents. Formerly `lifetime_support_cents`. |
| `currently_entitled_amount_cents` | integer | Pledge amount the patron is currently entitled to, in cents. 0 for free followers. |
| `email` | string \| absent | Patron email. Only present when the webhook was created with a token that has the `campaigns.members[email]` scope. |
| `full_name` | string | Patron's display name. |
| `is_follower` | boolean | Deprecated — always `false` in v2 API. |
| `last_charge_date` | ISO 8601 \| null | Timestamp of the most recent charge attempt. null if never charged. |
| `last_charge_status` | enum \| null | Result of the most recent charge. Values: `Paid`, `Declined`, `Deleted`, `Pending`, `Refunded`, `Fraud`, `Other`. null if never charged. |
| `next_charge_date` | ISO 8601 \| null | When the next charge is scheduled. null for non-recurring pledges. |
| `note` | string | Creator-visible private note about this patron. |
| `patron_status` | enum \| null | `active_patron` — has a paid active pledge. `declined_patron` — has a pledge but last charge was declined. `former_patron` — once pledged but no longer does. null — free follower with no pledge history. |
| `pledge_cadence` | integer \| null | Billing cadence in months (1 = monthly). |
| `pledge_relationship_start` | ISO 8601 \| null | When the patron first pledged (not necessarily the current pledge start). |
| `will_pay_amount_cents` | integer | Amount the patron will be charged on the next billing date, in cents. |

**Relationships:**

| Relationship | Resource type | Description |
| ------------ | ------------- | ----------- |
| `address` | Address | Shipping address (if collected). |
| `campaign` | Campaign | The campaign this membership belongs to. |
| `currently_entitled_tiers` | Tier[] | Array of tier(s) the patron currently has access to. |
| `pledge_history` | PledgeEvent[] | History of pledge events (charge attempts, tier changes). |
| `user` | User | The Patreon user who is the patron. |

### 8.2 Pledge-event (pledge_history)

Returned inside `included` when the member's `pledge_history` relationship is embedded.

**Attributes:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `amount_cents` | integer | Amount for this pledge event, in cents. |
| `currency_code` | string | ISO 4217 currency code (e.g. `"USD"`). |
| `date` | ISO 8601 | When this event occurred. |
| `payment_status` | string | `Paid`, `Declined`, `Pending`, `Refunded`, `Other`. |
| `type` | enum | `pledge_start`, `pledge_upgrade`, `pledge_downgrade`, `pledge_delete`, `subscription`. |

**Relationships:** `campaign`, `patron` (User), `tier`.

### 8.3 User

Returned inside `included` via the member's `user` relationship.

**Attributes:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `about` | string \| null | Profile bio text. |
| `created` | ISO 8601 | Patreon account creation timestamp. |
| `email` | string \| absent | Requires `identity[email]` scope on the creator's token. |
| `first_name` | string \| null | First name. |
| `full_name` | string | Full display name. |
| `image_url` | string | Profile image URL (400 px wide). |
| `is_creator` | boolean | Whether the user has an active campaign. |
| `is_email_verified` | boolean | Whether the user's email has been verified. |
| `last_name` | string \| null | Last name. |
| `social_connections` | object | Map of connected social platforms (keys: `deviantart`, `discord`, `facebook`, `google`, `instagram`, `reddit`, `spotify`, `twitch`, `twitter`, `youtube`). Each value is either null or `{ "user_id": "..." }`. |
| `thumb_url` | string | Thumbnail image URL (100×100 px). |
| `url` | string | Patreon profile URL. |
| `vanity` | string \| null | URL slug. |

**Relationships:** `campaign` (if the user is a creator), `memberships` (Member[]).

### 8.4 Campaign

Returned inside `included` via the member's or post's `campaign` relationship.

**Attributes:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `creation_name` | string \| null | Description of what the creator makes. |
| `currency` | string | Campaign billing currency (ISO 4217). |
| `discord_server_id` | string \| null | Linked Discord server ID. |
| `image_small_url` | string \| null | Small campaign cover image URL. |
| `image_url` | string \| null | Full campaign cover image URL. |
| `is_monthly` | boolean | `true` for monthly billing, `false` for pay-per-post. |
| `is_nsfw` | boolean | Adult content flag. |
| `name` | string | Campaign display name. |
| `patron_count` | integer | Current number of patrons with access. |
| `published_at` | ISO 8601 \| null | When the campaign was published. |
| `summary` | string \| null | Campaign description (may contain HTML). |
| `url` | string | Patreon campaign URL. |
| `vanity` | string \| null | Campaign URL slug. |

**Relationships:** `benefits`, `creator` (User), `goals`, `tiers`.

### 8.5 Tier

Returned inside `included` via the member's `currently_entitled_tiers` relationship.

**Attributes:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `amount_cents` | integer | Monthly tier cost in cents. |
| `description` | string \| null | Tier benefit description (may contain HTML). |
| `discord_role_ids` | string[] | Discord role IDs granted to subscribers of this tier. |
| `edited_at` | ISO 8601 | Last modification timestamp. |
| `patron_count` | integer | Number of patrons currently subscribed to this tier. |
| `post_count` | integer | Number of posts accessible to this tier. |
| `published` | boolean | Whether the tier is currently active and visible. |
| `published_at` | ISO 8601 \| null | When the tier was first published. |
| `requires_shipping` | boolean | Whether patrons on this tier must provide a shipping address. |
| `title` | string | Tier display name. |
| `unpublished_at` | ISO 8601 \| null | When the tier was unpublished (if applicable). |
| `user_limit` | integer \| null | Maximum subscriber count; null = unlimited. |

**Relationships:** `benefits`, `campaign`, `tier_image` (Media).

### 8.6 Post

`data.type = "post"` — delivered for all three post triggers.

**Attributes:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `content` | string \| null | Post body. May contain HTML. |
| `embed_data` | object \| null | Embedded media metadata (URL, title, thumbnail, etc.). |
| `embed_url` | string \| null | Embedded media URL. |
| `is_paid` | boolean | `true` in pay-per-post campaigns where this post charges patrons. |
| `is_public` | boolean | `true` if the post is publicly accessible without a pledge. |
| `published_at` | ISO 8601 \| null | Publish timestamp. null if still a draft. |
| `tiers` | relationship | Tiers that have access to this post (gate list). |
| `title` | string \| null | Post heading. |
| `url` | string | Canonical Patreon post URL. Paid-only posts may return 403 to unauthenticated visitors. |

**Relationships:** `campaign`, `user` (Author/Creator).

---

## 9. Included relationships and graph traversal

Patreon includes related resources in the top-level `included` array. To traverse the graph:

1. Identify the relationship reference in `data.relationships`:
   ```json
   "user": { "data": { "type": "user", "id": "12345678" } }
   ```
2. Find the matching object in `included` where `type == "user"` and `id == "12345678"`.
3. Read attributes from that object.

Example — extract patron's Twitch connection from a `members:pledge:create` payload:

```python
def find_included(included: list, rtype: str, rid: str) -> dict | None:
    return next((r for r in included if r["type"] == rtype and r["id"] == rid), None)

user_ref = payload["data"]["relationships"]["user"]["data"]
user = find_included(payload.get("included", []), user_ref["type"], user_ref["id"])
twitch = user["attributes"].get("social_connections", {}).get("twitch") if user else None
twitch_user_id = twitch["user_id"] if twitch else None
```

Relationships that may appear in `included` on member events:
- `user` — patron's Patreon user record
- `campaign` — the campaign resource
- `currently_entitled_tiers` — array; each element is a tier resource

`pledge_history` is typically **not** included by default on webhook deliveries. It is available
via direct API queries with `include=pledge_history`.

---

## 10. Amount representation

All monetary values in Patreon payloads are **integers in cents** (or the campaign's base
currency unit). Never assume dollars.

| Field | Example raw | Human display |
| ----- | ----------- | ------------- |
| `currently_entitled_amount_cents` | `500` | $5.00 |
| `campaign_lifetime_support_cents` | `12750` | $127.50 |
| `will_pay_amount_cents` | `1000` | $10.00 |
| `tier.amount_cents` | `2500` | $25.00 |
| `pledge_event.amount_cents` | `500` | $5.00 |

Formatting helper (Python):

```python
def cents_to_display(cents: int, currency: str = "USD") -> str:
    return f"{currency} {cents / 100:.2f}"
```

---

## 11. BotOfTheSpecter callsites

| Concern | File | Notes |
| ------- | ---- | ----- |
| HTTP ingest route | `./api/api.py:1754` (`handle_patreon_webhook`) | V1 auth (`?api_key=`). Reads `X-Patreon-Event` and raw body are **not** captured — signature verification is missing (TODO). |
| API key validation | `./api/api.py` (`verify_api_key`) | Validates against `users.api_key` in `website` DB. Wrong/missing → HTTP 401. |
| V2 webhook path list | `./api/api.py:721` (`_V2_WEBHOOK_PATHS`) | `/patreon` listed alongside `/kofi`, `/fourthwall`, `/github`, etc. |
| WebSocket event router | `./websocket/server.py:825` | `elif event == "PATREON": await self.handle_patreon_event(code, data)` |
| Server-side dispatcher | `./websocket/server.py:960` (`handle_patreon_event`) | Delegates to `donation_handler`. |
| Broadcaster | `./websocket/donation_handler.py:40` (`handle_patreon_event`) | Broadcasts `PATREON` event via `broadcast_with_globals()` (per-code clients + global admin listeners). |
| Overlay | `./overlay/patreon.php` | Socket.io 4.8.3 client. Registers as `{ code, channel: "Overlay", name: "Patreon" }`. Receives `PATREON` event — currently only `console.log`s it. `enqueueAudio` call is commented out. Audio volume hardcoded to `0.8` (80%). |

### Known issues / TODOs

1. **No HMAC signature verification.** `handle_patreon_webhook` does not read
   `X-Patreon-Signature` or compare it against the stored secret. Anyone who knows a user's
   API key can spoof Patreon events. Fix: store `patreon_webhook_secret` per-user (e.g. column
   on `users` table or a `patreon_tokens` table), call `verify_patreon_signature()` before
   forwarding.

2. **`X-Patreon-Event` header is not captured or forwarded.** The overlay and WebSocket
   clients receive a raw Patreon body with no trigger context. Recommendation: wrap the payload
   as `{"trigger": "<header-value>", "body": <original_json>}` before forwarding to the
   WebSocket server.

3. **Payload serialisation bug.** `urlencode({"data": webhook_data})` passes the raw Python
   dict through `str()`, producing Python-literal syntax (single quotes, `True`/`False`/`None`)
   instead of valid JSON. The overlay's `data` field cannot be parsed with `JSON.parse()`. Fix:
   `urlencode({"data": json.dumps(webhook_data)})`.

4. **Overlay renders nothing.** `./overlay/patreon.php` logs the event but shows no visual
   alert and plays no audio. Both the serialisation bug (#3) and the commented-out
   `enqueueAudio` call need to be resolved before the overlay is functional.
