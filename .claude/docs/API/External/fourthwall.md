# Fourthwall Webhooks — Local API Reference

Fourthwall is a creator commerce platform (storefront, donations, memberships, gifting, giveaways).
Webhooks fire for every customer-facing transaction and are delivered as HTTP POST with a JSON body.

---

## Table of Contents

1. [Webhook setup and configuration](#1-webhook-setup-and-configuration)
2. [Signature verification](#2-signature-verification)
3. [Envelope model](#3-envelope-model)
4. [Amount representation](#4-amount-representation)
5. [Event types and payload schemas](#5-event-types-and-payload-schemas)
   - [ORDER_PLACED](#order_placed)
   - [ORDER_UPDATED](#order_updated)
   - [DONATION](#donation)
   - [SUBSCRIPTION_PURCHASED](#subscription_purchased)
   - [SUBSCRIPTION_CHANGED](#subscription_changed)
   - [SUBSCRIPTION_EXPIRED](#subscription_expired)
   - [SUBSCRIPTION_CANCELLED](#subscription_cancelled)
   - [SUBSCRIPTION_CHARGE_FAILED](#subscription_charge_failed)
   - [GIFT_PURCHASE](#gift_purchase)
   - [GIVEAWAY_PURCHASED](#giveaway_purchased)
   - [TWITCH_CHARITY_DONATION](#twitch_charity_donation)
   - [Other / lesser-used events](#other--lesser-used-events)
6. [Retry behaviour](#6-retry-behaviour)
7. [Response requirements](#7-response-requirements)
8. [Delivery guarantees and limitations](#8-delivery-guarantees-and-limitations)
9. [BotOfTheSpecter callsites](#9-botofthespecter-callsites)

---

## 1. Webhook setup and configuration

### Via dashboard

Path: **Dashboard → Settings → For Developers → Webhooks** (some UI revisions show it as **Apps → Webhooks**).

1. Click **Add webhook**.
2. **URL:** `https://api.botofthespecter.com/fourthwall?api_key=<USER_API_KEY>`
3. **Subscribed events (`allowedTypes`):** select each event type to subscribe. Typical set for streamers: `ORDER_PLACED`, `DONATION`, `SUBSCRIPTION_PURCHASED`, `GIFT_PURCHASE`.
4. Save. Fourthwall displays a **secret** once at creation time — copy it immediately. This is the HMAC signing key for signature verification.

### Via API

```
POST https://api.fourthwall.com/api/webhooks
Authorization: Basic <base64(username:password)>
Content-Type: application/json

{
  "url": "https://api.botofthespecter.com/fourthwall?api_key=<USER_API_KEY>",
  "allowedTypes": ["ORDER_PLACED", "DONATION", "SUBSCRIPTION_PURCHASED", "GIFT_PURCHASE"]
}
```

Response fields:

| Field | Type | Description |
| ----- | ---- | ----------- |
| `id` | string | Webhook configuration ID |
| `url` | string | Registered endpoint URL |
| `allowedTypes` | string[] | Subscribed event type strings |
| `secret` | string (UUID) | HMAC signing key — store securely, shown only once |

Other management endpoints: `GET /api/webhooks`, `GET /api/webhooks/{id}`, `PUT /api/webhooks/{id}`, `DELETE /api/webhooks/{id}`.

### Platform Apps model

If a streamer connects via the **Platform App** model rather than shop-level webhooks, the signing header changes from `X-Fourthwall-Hmac-SHA256` to `X-Fourthwall-Hmac-Apps-SHA256`. The secret is found on the app's settings page. The algorithm and encoding are identical. Both headers must be accepted if implementing verification.

---

## 2. Signature verification

Fourthwall signs every delivery with HMAC-SHA256 over the raw request body bytes. The signature is base64-encoded and delivered in an HTTP header.

| Header | Used by |
| ------ | ------- |
| `X-Fourthwall-Hmac-SHA256` | Standard per-shop webhooks |
| `X-Fourthwall-Hmac-Apps-SHA256` | Platform App model |

### Algorithm

1. Retrieve the raw request body bytes (before any JSON parsing).
2. Compute `HMAC-SHA256(secret.encode('utf-8'), raw_body)`.
3. Base64-encode the resulting digest.
4. Compare with the header value using a constant-time comparison.

### Python reference implementation

```python
import hmac
import hashlib
import base64

def verify_fourthwall_signature(raw_body: bytes, header_value: str, secret: str) -> bool:
    digest = hmac.new(
        secret.encode("utf-8"),
        raw_body,
        digestmod=hashlib.sha256
    ).digest()
    computed = base64.b64encode(digest)
    return hmac.compare_digest(computed, header_value.encode("utf-8"))
```

### Secret storage

The secret must be stored per-user (e.g. a `fourthwall_webhook_secret` column on the central `users` table). Without per-user verification, a caller who knows a user's API key can spoof orders and donations.

**Current status in this codebase:** `handle_fourthwall_webhook` in `./api/api.py` does **not** currently read `X-Fourthwall-Hmac-SHA256`. Verification is a pending TODO.

---

## 3. Envelope model

Every webhook payload wraps the event-specific data in a consistent top-level envelope.

```json
{
  "id": "evt_01abc123",
  "webhookId": "wh_xyz789",
  "shopId": "shop_example",
  "type": "ORDER_PLACED",
  "apiVersion": "V1_BETA",
  "createdAt": "2026-05-10T12:34:56Z",
  "testMode": false,
  "data": { ... }
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `id` | string | Unique event delivery ID. Use for idempotency / duplicate detection. |
| `webhookId` | string | References the webhook configuration that triggered this delivery. |
| `shopId` | string | Shop identifier; useful for multi-shop integrations. |
| `type` | string | Event type string (e.g. `ORDER_PLACED`). |
| `apiVersion` | string | Schema version (currently `V1_BETA`). |
| `createdAt` | ISO 8601 string | Timestamp the event was created. |
| `testMode` | boolean | `true` for test deliveries sent via the dashboard "Send test notification" button. |
| `data` | object | Event-specific payload; shape varies by `type`. See §5. |

---

## 4. Amount representation

All monetary values in Fourthwall payloads use **decimal numbers in major currency units**.

```json
{ "value": 32.13, "currency": "USD" }
```

- `32.13` means $32.13 USD — do **not** divide by 100.
- This is the **opposite** of Patreon (which uses cents) and Stripe (which uses the smallest unit).
- Every `value` object carries its own `currency` field. Multi-currency shops send per-amount currencies.

### Common `amounts` object

Most order-related events include a composite amounts block:

```json
{
  "amounts": {
    "subtotal": { "value": 25.00, "currency": "USD" },
    "tax":      { "value": 2.13,  "currency": "USD" },
    "shipping": { "value": 5.00,  "currency": "USD" },
    "total":    { "value": 32.13, "currency": "USD" },
    "discount": { "value": 0.00,  "currency": "USD" }
  }
}
```

Donation and subscription events typically include only `amounts.total`.

---

## 5. Event types and payload schemas

### ORDER_PLACED

Fired when a new order is successfully placed and paid.

```json
{
  "type": "ORDER_PLACED",
  "data": {
    "id": "ord_abc123",
    "createdAt": "2026-05-10T12:34:56Z",
    "username": "jane_doe",
    "email": "jane@example.com",
    "status": "PAID",
    "message": "Custom note from buyer",
    "offers": [
      {
        "id": "off_xyz",
        "name": "Specter Logo Tee",
        "image": "https://cdn.fourthwall.com/.../tee.jpg",
        "price": { "value": 25.00, "currency": "USD" },
        "variant": {
          "id": "var_123",
          "name": "Black / Large",
          "quantity": 1,
          "sku": "TEE-BLK-L"
        }
      }
    ],
    "amounts": {
      "subtotal": { "value": 25.00, "currency": "USD" },
      "tax":      { "value": 2.13,  "currency": "USD" },
      "shipping": { "value": 5.00,  "currency": "USD" },
      "total":    { "value": 32.13, "currency": "USD" },
      "discount": { "value": 0.00,  "currency": "USD" }
    },
    "shipping": {
      "fullName":        "Jane Doe",
      "addressLine1":    "1 Main St",
      "addressLine2":    "",
      "city":            "Brisbane",
      "stateOrProvince": "QLD",
      "postalCode":      "4000",
      "country":         "AU"
    }
  }
}
```

**Field notes:**

| Field | Notes |
| ----- | ----- |
| `data.id` | Order ID, suitable as idempotency key. |
| `data.username` | Buyer's Fourthwall username; may be empty if guest checkout. |
| `data.status` | `PAID` at placement. |
| `data.offers` | Always an array even for single-item orders. Multi-item orders have multiple entries; the overlay currently reads only `offers[0]`. |
| `data.offers[].variant.quantity` | Item quantity for this line. |
| `data.message` | Optional buyer note; may be `null` or absent. |
| `data.shipping` | Physical address; absent for digital-only orders. |

---

### ORDER_UPDATED

Fired when an order's status, shipping address, or email changes.

Shape is identical to `ORDER_PLACED` with an updated `status` value:

| `status` | Meaning |
| -------- | ------- |
| `FULFILLED` | Order shipped/sent. |
| `SHIPPED` | Carrier has package. |
| `REFUNDED` | Order refunded. |
| `CANCELLED` | Order cancelled. |

The overlay's `buildAlertElement` does not have a specific branch for `ORDER_UPDATED` — it falls through to the generic fallback alert.

---

### DONATION

Fired when a new donation is received through the Fourthwall tip jar.

```json
{
  "type": "DONATION",
  "data": {
    "id": "don_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "username": "supporter42",
    "email": "supporter@example.com",
    "message": "Keep up the great streams!",
    "amounts": {
      "total": { "value": 10.00, "currency": "USD" }
    }
  }
}
```

| Field | Notes |
| ----- | ----- |
| `data.username` | Donor's Fourthwall username; may be absent for anonymous donations. |
| `data.message` | Optional message; may be `null` or absent. |
| `data.amounts.total` | Donation amount in major currency units. |

---

### SUBSCRIPTION_PURCHASED

Fired when a new membership subscription is purchased.

```json
{
  "type": "SUBSCRIPTION_PURCHASED",
  "data": {
    "id": "subevt_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "nickname": "Jane",
    "email": "jane@example.com",
    "subscription": {
      "id": "sub_xyz",
      "status": "ACTIVE",
      "startedAt": "2026-05-10T12:34:56Z",
      "currentPeriodEnd": "2026-06-10T12:34:56Z",
      "variant": {
        "id": "var_gold",
        "name": "Gold Tier",
        "interval": "MONTHLY",
        "amount": { "value": 5.00, "currency": "USD" }
      }
    }
  }
}
```

| Field | Notes |
| ----- | ----- |
| `data.nickname` | Display name the subscriber uses on Fourthwall. |
| `data.subscription.variant.interval` | `MONTHLY` or `YEARLY`. |
| `data.subscription.variant.amount` | Recurring charge amount in major currency units. |
| `data.subscription.currentPeriodEnd` | When the current billing period ends (ISO 8601). |

---

### SUBSCRIPTION_CHANGED

Fired when a subscriber changes tier or plan.

```json
{
  "type": "SUBSCRIPTION_CHANGED",
  "data": {
    "id": "subevt_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "nickname": "Jane",
    "email": "jane@example.com",
    "subscription": {
      "id": "sub_xyz",
      "status": "ACTIVE",
      "startedAt": "2026-05-10T12:34:56Z",
      "currentPeriodEnd": "2026-06-10T12:34:56Z",
      "variant": {
        "id": "var_platinum",
        "name": "Platinum Tier",
        "interval": "MONTHLY",
        "amount": { "value": 10.00, "currency": "USD" }
      }
    }
  }
}
```

Shape is the same as `SUBSCRIPTION_PURCHASED`; the `subscription.variant` reflects the **new** tier after the change.

---

### SUBSCRIPTION_EXPIRED

Fired when a membership subscription expires (reaches its end date without renewal).

```json
{
  "type": "SUBSCRIPTION_EXPIRED",
  "data": {
    "id": "subevt_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "nickname": "Jane",
    "email": "jane@example.com",
    "subscription": {
      "id": "sub_xyz",
      "status": "EXPIRED",
      "startedAt": "2026-04-10T12:34:56Z",
      "currentPeriodEnd": "2026-05-10T12:34:56Z",
      "variant": {
        "id": "var_gold",
        "name": "Gold Tier",
        "interval": "MONTHLY",
        "amount": { "value": 5.00, "currency": "USD" }
      }
    }
  }
}
```

---

### SUBSCRIPTION_CANCELLED

Fired when a subscriber manually cancels. The subscription typically remains active until `currentPeriodEnd`.

```json
{
  "type": "SUBSCRIPTION_CANCELLED",
  "data": {
    "id": "subevt_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "nickname": "Jane",
    "email": "jane@example.com",
    "subscription": {
      "id": "sub_xyz",
      "status": "CANCELLED",
      "startedAt": "2026-04-10T12:34:56Z",
      "currentPeriodEnd": "2026-05-10T12:34:56Z",
      "variant": {
        "id": "var_gold",
        "name": "Gold Tier",
        "interval": "MONTHLY",
        "amount": { "value": 5.00, "currency": "USD" }
      }
    }
  }
}
```

**Note:** `SUBSCRIPTION_CANCELLED` and `SUBSCRIPTION_CHARGE_FAILED` are listed as separate subscribable events in the Fourthwall docs but do not appear in the original `llms-full.txt` event enumeration — they may be surfaced under the same `SUBSCRIPTION_*` topic family. Treat them the same way structurally.

---

### SUBSCRIPTION_CHARGE_FAILED

Fired when a recurring charge attempt fails (e.g. expired card).

```json
{
  "type": "SUBSCRIPTION_CHARGE_FAILED",
  "data": {
    "id": "subevt_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "nickname": "Jane",
    "email": "jane@example.com",
    "subscription": {
      "id": "sub_xyz",
      "status": "PAST_DUE",
      "startedAt": "2026-04-10T12:34:56Z",
      "currentPeriodEnd": "2026-05-10T12:34:56Z",
      "variant": {
        "id": "var_gold",
        "name": "Gold Tier",
        "interval": "MONTHLY",
        "amount": { "value": 5.00, "currency": "USD" }
      }
    }
  }
}
```

`subscription.status` is typically `PAST_DUE` after a failed charge.

---

### GIFT_PURCHASE

Fired when a gift card or membership gift is purchased. The purchaser buys on behalf of one or more recipients.

```json
{
  "type": "GIFT_PURCHASE",
  "data": {
    "id": "gift_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "purchaser": {
      "username": "alice",
      "email": "alice@example.com"
    },
    "recipients": [
      {
        "email": "bob@example.com",
        "claimed": false
      }
    ],
    "offer": {
      "id": "off_gift_xyz",
      "name": "Membership Gift Card"
    },
    "amounts": {
      "total": { "value": 5.00, "currency": "USD" }
    }
  }
}
```

| Field | Notes |
| ----- | ----- |
| `data.purchaser.username` | Gifter's Fourthwall username. |
| `data.recipients` | Array of gift recipients; `claimed` is `false` until recipient redeems. |
| `data.offer.name` | Name of the gifted membership tier or gift card. |
| `data.amounts.total` | Total charge in major currency units. |

---

### GIVEAWAY_PURCHASED

Fired when a giveaway entry is purchased. This is the older event name for what Fourthwall now calls gift draw entries in some contexts; it is still delivered and handled.

```json
{
  "type": "GIVEAWAY_PURCHASED",
  "data": {
    "id": "gaw_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "username": "buyer",
    "email": "buyer@example.com",
    "offer": {
      "id": "off_giveaway_xyz",
      "name": "Stream Giveaway Entry"
    },
    "amounts": {
      "total": { "value": 1.00, "currency": "USD" }
    }
  }
}
```

| Field | Notes |
| ----- | ----- |
| `data.username` | Buyer's Fourthwall username. |
| `data.offer.name` | Name of the giveaway / draw entry product. |
| `data.amounts.total` | Purchase price in major currency units. |

Related events: `GIFT_DRAW_STARTED` and `GIFT_DRAW_ENDED` fire as the live-stream draw progresses (draw start, winner selection). Those are separate `type` values, not `GIVEAWAY_PURCHASED`.

---

### TWITCH_CHARITY_DONATION

Fired when a Twitch charity campaign donation is received through a Fourthwall integration.

```json
{
  "type": "TWITCH_CHARITY_DONATION",
  "data": {
    "id": "charity_abc",
    "createdAt": "2026-05-10T12:34:56Z",
    "username": "viewer123",
    "charityName": "Example Charity",
    "message": "Good luck!",
    "amounts": {
      "total": { "value": 25.00, "currency": "USD" }
    }
  }
}
```

| Field | Notes |
| ----- | ----- |
| `data.charityName` | Name of the Twitch charity campaign. |
| `data.username` | Donating viewer's Twitch username. |
| `data.message` | Optional message; may be absent. |
| `data.amounts.total` | Donation amount in major currency units. |

**Note:** `TWITCH_CHARITY_DONATION` is listed in the subscription topic set in the Fourthwall docs but does not appear in the `llms-full.txt` event type enumeration. Treat the payload shape above as an approximation derived from the donation pattern; verify against live test events when implementing.

---

### Other / lesser-used events

These event types are documented in the Fourthwall subscription options but are not currently handled by the BotOfTheSpecter overlay. They all follow the standard envelope (`id`, `type`, `createdAt`, `data`):

| `type` | When it fires |
| ------ | ------------- |
| `CART_ABANDONED_1H` | Cart abandoned for 1 hour. |
| `CART_ABANDONED_24H` | Cart abandoned for 24 hours. |
| `CART_ABANDONED_72H` | Cart abandoned for 72 hours. |
| `NEWSLETTER_SUBSCRIBED` | Someone subscribes to the shop newsletter. |
| `PRODUCT_CREATED` | New product created in the shop. |
| `PRODUCT_UPDATED` | Existing product updated. |
| `COLLECTION_UPDATED` | Product collection changes. |
| `PROMOTION_CREATED` | New promotion / discount code created. |
| `PROMOTION_UPDATED` | Promotion modified. |
| `PROMOTION_STATUS_CHANGED` | Promotion activated or deactivated. |
| `THANK_YOU_SENT` | A "Thank You" message sent to a customer. |
| `GIFT_DRAW_STARTED` | Live-stream gift draw started. |
| `GIFT_DRAW_ENDED` | Live-stream gift draw concluded with winners. |
| `PLATFORM_APP_DISCONNECTED` | Platform App integration disconnected. |

---

## 6. Retry behaviour

- **Retry count:** 5 delivery attempts total for a failed event.
- **Per-attempt timeout:** 5 seconds. If no response is received within 5 seconds the attempt counts as failed.
- **Slow response:** A response time exceeding ~2 seconds may be treated as a failed delivery.
- **Backoff:** Specific intervals between retries are not documented; assume exponential backoff.
- **Retry trigger:** Any response other than HTTP 2xx, any timeout, or no response.
- **Deduplication:** Because of at-least-once delivery, the same event may arrive more than once. Use the envelope `id` field as the idempotency key to detect duplicates.

---

## 7. Response requirements

- Return **HTTP 200** as quickly as possible.
- Do all heavy processing (database writes, WebSocket forwarding) asynchronously after sending 200.
- If a 200 is not returned within ~2 seconds, Fourthwall may mark the delivery as failed and retry.
- No specific response body is required; Fourthwall ignores it.

---

## 8. Delivery guarantees and limitations

- **At-least-once delivery.** Events may be delivered more than once, especially after retries. Always deduplicate on `id`.
- **No ordering guarantee.** Different event topics for the same resource can arrive out of sequence (e.g. `ORDER_UPDATED` may arrive before `ORDER_PLACED` in edge cases).
- **No delivery certainty.** Fourthwall does not guarantee every event reaches the endpoint. For financial reconciliation, supplement webhook data with direct API polling (`GET /api/orders`, `GET /api/donations`).
- **`testMode: true`** events are delivered when using the dashboard "Send test notification" button. Filter these if you are counting real revenue.
- **`apiVersion`** is currently `V1_BETA`. Schema may change; watch for version changes if you cache field paths.

---

## 9. BotOfTheSpecter callsites

### Ingest path

| Concern | File | Location |
| ------- | ---- | -------- |
| HTTP route handler | `./api/api.py` | Line 1673 `@app.post("/fourthwall", ...)` |
| Signature verification | `./api/api.py` | **Not implemented.** `X-Fourthwall-Hmac-SHA256` is not read. |
| API key validation | `./api/api.py` | Line 1118 `verify_api_key()` against central `website` DB |
| WebSocket forward | `./api/api.py` | Line 1698 `GET https://websocket.botofthespecter.com/notify?code=...&event=FOURTHWALL&data=...` |

### WebSocket routing

| Concern | File | Location |
| ------- | ---- | -------- |
| Event registration | `./websocket/server.py` | Line 202 `("FOURTHWALL", self.handle_fourthwall_event)` |
| HTTP notify router | `./websocket/server.py` | Line 819 `elif event == "FOURTHWALL"` |
| Server-level handler | `./websocket/server.py` | Line 952 `handle_fourthwall_event(code, data)` — delegates to donation handler |
| Broadcast | `./websocket/donation_handler.py` | Line 8 `handle_fourthwall_event()` — calls `broadcast_with_globals("FOURTHWALL", data, code)` |

### Overlay

| Concern | File | Notes |
| ------- | ---- | ----- |
| Browser source overlay | `./overlay/fourthwall.php` | Socket.io 4.8.3, registers as `{ code, channel: 'Overlay', name: 'FourthWall' }` |
| Data parser | `./overlay/fourthwall.php` | `parseFwData()` handles both JSON and Python-dict-literal string shapes |
| Handled event types | `./overlay/fourthwall.php` | `ORDER_PLACED`, `DONATION`, `GIVEAWAY_PURCHASED`, `SUBSCRIPTION_PURCHASED`; all others fall through to generic alert |
| Alert duration | `./overlay/fourthwall.php` | 7 seconds per alert, queued sequentially |
| Reconnect strategy | `./overlay/fourthwall.php` | Exponential backoff starting at 5 s, capped at 30 s |

### Known gaps and TODOs

- **HMAC verification is not implemented.** `handle_fourthwall_webhook` reads the body and forwards it without checking `X-Fourthwall-Hmac-SHA256`. To fix: add a `fourthwall_webhook_secret` column to `users`, read the header in the FastAPI handler, verify with `hmac.compare_digest`. Accept both `X-Fourthwall-Hmac-SHA256` and `X-Fourthwall-Hmac-Apps-SHA256` to support both webhook models.
- **Python-dict serialization bug.** The WebSocket forward uses `urlencode(params)` where `params["data"]` is a Python dict — this produces a Python `repr()` string (`{'key': 'value'}`) not valid JSON. The overlay's `parseFwData()` works around this with a string replacement heuristic. Fix by `json.dumps(webhook_data)` before URL encoding.
- **`ORDER_UPDATED` unhandled visually.** Falls through to the generic fallback. Consider suppressing it (status-change noise) or adding a dedicated branch.
- **Multi-item orders.** `offers` is always an array; the overlay reads only `offers[0]`. Multi-item orders silently drop all but the first item.
- **`TWITCH_CHARITY_DONATION` not in overlay.** Falls through to generic fallback; no dedicated branch exists.

---

*Sources: Fourthwall developer documentation at `https://docs.fourthwall.com/` (fetched 2026-05-11) — `webhooks/getting-started`, `webhooks/api-management`, `webhooks/signature-verification`, `webhooks/retry-policies`, `webhooks/limitations`, `webhooks/webhook-model`, `llms-full.txt` index. Payload schemas for uncommon events (`SUBSCRIPTION_CANCELLED`, `SUBSCRIPTION_CHARGE_FAILED`, `TWITCH_CHARITY_DONATION`) are inferred from the subscription event family pattern — verify against live test events.*
