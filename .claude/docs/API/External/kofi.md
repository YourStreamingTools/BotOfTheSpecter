# Ko-fi Webhooks — API Reference

Ko-fi sends a webhook POST to a configured URL for every monetised interaction on a creator's page: one-off tips, recurring subscriptions, commissions, and shop orders.

---

## 1. Webhook setup

**Where to configure:** `https://ko-fi.com/manage/webhooks` (Settings → API / Advanced → Webhooks in the Ko-fi UI).

| Field | What to enter |
| ----- | ------------- |
| **Webhook URL** | `https://api.botofthespecter.com/kofi?api_key=<USER_API_KEY>` |
| **Verification Token** | Shown on this same page — a UUID-style string. Copy it; Ko-fi embeds it in every payload (there is no header). |

Click **Send Test** after saving. Ko-fi fires a synthetic `Donation` event with zeroed-out amounts so the endpoint can confirm receipt without real money.

---

## 2. Request format

Ko-fi sends an HTTP POST with:

```
Content-Type: application/x-www-form-urlencoded
```

The body is a **single form field** named `data` whose value is a JSON-encoded string. There is no JSON body; do not switch to a JSON body parser.

```
POST /kofi?api_key=<USER_API_KEY> HTTP/1.1
Host: api.botofthespecter.com
Content-Type: application/x-www-form-urlencoded

data=%7B%22verification_token%22%3A%22...%22%2C%22type%22%3A%22Donation%22%2C...%7D
```

**FastAPI parsing pattern:**

```python
async def handle_kofi_webhook(api_key: str = Query(...), data: str = Form(...)):
    kofi_data = json.loads(data)
```

---

## 3. Verification token

Ko-fi's authentication model uses an **in-body token**, not an HMAC signature or HTTP header.

- The JSON payload always contains `"verification_token": "<UUID>"`.
- The UUID is the static secret shown on the creator's `ko-fi.com/manage/webhooks` page.
- Verification is a constant-time equality check — there is no HMAC and no timestamp to validate.

```python
import hmac

expected = load_user_kofi_token(api_key)   # per-user value stored in DB
received = kofi_data.get("verification_token", "")
if not hmac.compare_digest(received, expected):
    raise HTTPException(status_code=403, detail="Invalid Ko-fi verification token")
```

**Security notes:**
- The token travels inside the form-encoded body. Any logging of the raw request body exposes it — mask `verification_token` in all logging paths.
- Without this check, anyone who learns a user's API key can spoof Ko-fi events against their endpoint.
- The current BotOfTheSpecter implementation does **not** enforce the token (only the `api_key` query param is checked). That gap is documented in the callsites section.

---

## 4. Event types

| `type` value | When fired |
| ------------ | ---------- |
| `Donation` | One-off tip ("Buy me a coffee"). |
| `Subscription` | Monthly Ko-fi Gold or creator membership. First payment and every renewal fire separately. |
| `Commission` | Custom commission accepted and paid by the buyer. |
| `Shop Order` | Ko-fi Shop product purchase (digital or physical). |

---

## 5. Universal payload fields

Every event type shares this base envelope. All fields are always present unless noted otherwise.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `verification_token` | `string` (UUID) | Per-creator webhook secret. Verify before trusting any other field. |
| `message_id` | `string` (UUID) | Unique per delivery attempt. Use as an idempotency key. |
| `timestamp` | `string` (ISO 8601) | UTC transaction completion time. Always has a `Z` suffix (e.g. `"2024-11-22T13:23:35Z"`). |
| `type` | `string` (enum) | `"Donation"`, `"Subscription"`, `"Commission"`, or `"Shop Order"`. |
| `is_public` | `boolean` | `true` if the supporter ticked "show on public feed". Consider gating overlay display on this. |
| `from_name` | `string` | Display name chosen by the supporter. User-controlled — XSS-sanitize before rendering. |
| `message` | `string` \| `null` | Free-text message from the supporter. May be an empty string or `null`. |
| `amount` | `string` (decimal) | **Major currency units** — e.g. `"5.00"`, not cents. Always a string; parse with `Decimal`, not `float`. Guard against `""` and `null` on some Shop Order edge cases. |
| `url` | `string` | Ko-fi receipt URL for this transaction. |
| `email` | `string` | Supporter's email address. Treat as PII; do not log or expose. |
| `currency` | `string` (ISO 4217) | `"USD"`, `"GBP"`, `"EUR"`, `"AUD"`, etc. |
| `is_subscription_payment` | `boolean` | `true` for any payment that is part of a subscription (both first and renewals). `false` for all other types. |
| `is_first_subscription_payment` | `boolean` | `true` only for the very first payment in a subscription. Always `false` for non-subscription events. |
| `kofi_transaction_id` | `string` (UUID) | Ko-fi's internal transaction identifier. Stable across retry deliveries of the same transaction — use for deduplication when persisting. |
| `shop_items` | `array` \| `null` | Populated only for `Shop Order`; `null` for all other types. See §6.4. |
| `tier_name` | `string` \| `null` | Populated only for `Subscription` events where the creator has configured membership tiers; otherwise `null`. |
| `shipping` | `object` \| `null` | Populated only for `Shop Order` events for **physical goods** that require shipping; otherwise `null`. See §6.4. |

---

## 6. Per-event-type payload schemas

### 6.1 Donation

A one-off tip with no recurring component.

Distinguishing flags: `is_subscription_payment: false`, `is_first_subscription_payment: false`, `shop_items: null`, `tier_name: null`, `shipping: null`.

```json
{
  "verification_token": "0d8cea64-c858-4f99-a2b4-xxxxxxxxxxxxxxxx",
  "message_id": "3a1fa772-9426-4d1a-xxxx-xxxxxxxxxxxx",
  "timestamp": "2024-11-22T13:23:35Z",
  "type": "Donation",
  "is_public": true,
  "from_name": "Jo Example",
  "message": "Good luck with the integration!",
  "amount": "3.00",
  "url": "https://ko-fi.com/Home/CoffeeShop?txid=00000000-1111-2222-3333-444444444444",
  "email": "jo@example.com",
  "currency": "USD",
  "is_subscription_payment": false,
  "is_first_subscription_payment": false,
  "kofi_transaction_id": "00000000-1111-2222-3333-444444444444",
  "shop_items": null,
  "tier_name": null,
  "shipping": null
}
```

---

### 6.2 Subscription

Fires for the first payment **and** every subsequent renewal. Distinguish them via `is_first_subscription_payment`.

Distinguishing flags: `is_subscription_payment: true`, `shop_items: null`, `shipping: null`. `tier_name` is non-null when the creator uses membership tiers.

**First payment:**

```json
{
  "verification_token": "0d8cea64-c858-4f99-a2b4-xxxxxxxxxxxxxxxx",
  "message_id": "b1ac68e2-42e0-4a9a-bf43-xxxxxxxxxxxx",
  "timestamp": "2024-11-22T14:00:00Z",
  "type": "Subscription",
  "is_public": true,
  "from_name": "Jane Supporter",
  "message": "Happy to support!",
  "amount": "10.00",
  "url": "https://ko-fi.com/Home/CoffeeShop?txid=11111111-2222-3333-4444-555555555555",
  "email": "jane@example.com",
  "currency": "USD",
  "is_subscription_payment": true,
  "is_first_subscription_payment": true,
  "kofi_transaction_id": "11111111-2222-3333-4444-555555555555",
  "shop_items": null,
  "tier_name": "Gold Supporter",
  "shipping": null
}
```

**Renewal** (monthly re-billing): identical payload, only `is_first_subscription_payment` changes to `false`. The `message` may be `null` on renewals.

**No-tier subscription:** `tier_name` is `null` when the creator has not configured membership tiers.

---

### 6.3 Commission

A custom commission accepted and paid. Ko-fi does not publish a detailed example payload; field population mirrors Donation (no `shop_items`, no `tier_name`, no `shipping`).

Distinguishing flag: `type: "Commission"`. All subscription flags are `false`.

```json
{
  "verification_token": "0d8cea64-c858-4f99-a2b4-xxxxxxxxxxxxxxxx",
  "message_id": "cccc1234-abcd-ef01-2345-xxxxxxxxxxxx",
  "timestamp": "2024-11-23T09:15:00Z",
  "type": "Commission",
  "is_public": false,
  "from_name": "Alex Buyer",
  "message": "Bust portrait of my OC, reference in email",
  "amount": "50.00",
  "url": "https://ko-fi.com/Home/CoffeeShop?txid=cccccccc-dddd-eeee-ffff-000000000000",
  "email": "alex@example.com",
  "currency": "USD",
  "is_subscription_payment": false,
  "is_first_subscription_payment": false,
  "kofi_transaction_id": "cccccccc-dddd-eeee-ffff-000000000000",
  "shop_items": null,
  "tier_name": null,
  "shipping": null
}
```

Note: Commissions are often private (`is_public: false`). The overlay should respect this rather than rendering all events unconditionally.

---

### 6.4 Shop Order

A Ko-fi Shop purchase. Always has a populated `shop_items` array. Physical goods that require delivery also populate `shipping`; digital-only orders have `shipping: null`.

**shop_items array — each element:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `direct_link_code` | `string` | Ko-fi's short code identifying the specific shop listing. |
| `variation_name` | `string` | The variation/variant the buyer selected (e.g. `"Sticker — Large"`, `"Red / Medium"`). Empty string `""` if the product has no variations. |
| `quantity` | `integer` | Number of units ordered. Always a positive integer. |

**shipping object — all fields:**

| Field | Type | Description |
| ----- | ---- | ----------- |
| `full_name` | `string` | Recipient's full name. |
| `street_address` | `string` | Street address line. |
| `city` | `string` | City. |
| `state_or_province` | `string` | State, province, or region. May be empty string for countries without states. |
| `postal_code` | `string` | Postcode / ZIP. Always a string; may contain letters (e.g. UK postcodes). |
| `country` | `string` | Full country name (e.g. `"Australia"`). |
| `country_code` | `string` | ISO 3166-1 alpha-2 code (e.g. `"AU"`, `"US"`, `"GB"`). |
| `telephone` | `string` | Phone number as entered by the buyer. May be empty string if not required. |

**Physical shop order (full payload):**

```json
{
  "verification_token": "0d8cea64-c858-4f99-a2b4-xxxxxxxxxxxxxxxx",
  "message_id": "shop1234-abcd-ef01-2345-xxxxxxxxxxxx",
  "timestamp": "2024-11-24T10:30:00Z",
  "type": "Shop Order",
  "is_public": true,
  "from_name": "Jane Doe",
  "message": null,
  "amount": "12.00",
  "url": "https://ko-fi.com/Home/CoffeeShop?txid=aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
  "email": "jane@example.com",
  "currency": "AUD",
  "is_subscription_payment": false,
  "is_first_subscription_payment": false,
  "kofi_transaction_id": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
  "shop_items": [
    {
      "direct_link_code": "abc123",
      "variation_name": "Sticker — Large",
      "quantity": 2
    },
    {
      "direct_link_code": "def456",
      "variation_name": "",
      "quantity": 1
    }
  ],
  "tier_name": null,
  "shipping": {
    "full_name": "Jane Doe",
    "street_address": "1 Main Street",
    "city": "Brisbane",
    "state_or_province": "QLD",
    "postal_code": "4000",
    "country": "Australia",
    "country_code": "AU",
    "telephone": "+61400000000"
  }
}
```

**Digital shop order** (no physical delivery): identical but `shipping: null`.

---

## 7. Response requirements

Ko-fi requires an HTTP `2xx` response. Return `200 OK` as quickly as possible — ideally before doing any heavy processing. A non-2xx response causes Ko-fi to mark the delivery as failed.

```python
return {"status": "success", "message": "Kofi event forwarded to WebSocket server"}
```

Do **not** return a `500` from internal handler errors — catch exceptions, log them, and still return `200`. A persistent `500` triggers Ko-fi retries indefinitely.

---

## 8. Retry behaviour

Ko-fi retries failed deliveries (non-2xx responses) with exponential back-off. The exact retry schedule is not publicly documented, but:

- Retries carry the same `message_id` and `kofi_transaction_id`.
- Use `kofi_transaction_id` to deduplicate when persisting transactions to a database.
- Use `message_id` as the idempotency key when the delivery itself must be processed exactly once.
- Always return `200` even if a duplicate is detected — do not return `409` or similar.

---

## 9. Transport and TLS

- Ko-fi only POSTs to `https://` endpoints. A valid TLS certificate is required; self-signed or expired certs cause delivery failures.
- No custom HTTP headers are set by Ko-fi beyond standard `Content-Type` and `User-Agent`. Do not rely on headers for authentication.

---

## 10. Gotchas and edge cases

| Gotcha | Detail |
| ------ | ------ |
| `amount` is a string | Never cast directly to `float` — use `Decimal("5.00")` for financial arithmetic. Guard against `""` and `null` on some shop edge cases before parsing. |
| `from_name` and `message` are user-controlled | XSS-sanitize before injecting into HTML. The overlay uses `escapeHtml()` — keep it. |
| `verification_token` is in the body | Any logging of the raw request body exposes this secret. Mask it in all log paths. |
| Commission alerts use the generic fallback | The overlay's `buildAlertElement` has no `Commission` branch — they fall through to the `☕` icon. Add an `else if (eventType === 'Commission')` block if Commission alerts are wanted. |
| `is_public: false` events are rendered anyway | The overlay currently displays all events regardless of `is_public`. Consider gating on `eventData.is_public !== false` to hide private commissions and quiet donations. |
| Subscription with no tier | `tier_name` is `null` when the creator has no membership tiers configured, even for a valid subscription payment. |
| Test event | The Ko-fi "Send Test" button sends a synthetic `Donation` with `amount: "3.00"` and a placeholder `kofi_transaction_id`. Filter it out in prod by checking whether the transaction ID matches the known test UUID or by adding a `is_test` guard. |
| Python dict serialisation | `urllib.parse.urlencode({"data": kofi_data})` stringifies a Python dict with single quotes and `True`/`False`/`None`. The overlay includes a `parseKofiData()` fallback parser for this. The fix is to `json.dumps(kofi_data)` before encoding. |

---

## BotOfTheSpecter callsites

| Concern | File | Detail |
| ------- | ---- | ------ |
| HTTP ingest endpoint | `./api/api.py:1718` | `handle_kofi_webhook` — `POST /kofi?api_key=`. Parses `data` form field, forwards to WebSocket server via `GET /notify`. |
| API key validation | `./api/api.py` (`verify_api_key`) | Validates `api_key` query param against `users.api_key` in central `website` DB. Returns HTTP 401 on failure. |
| `verification_token` check | **Not currently enforced** | Only the `api_key` query param is checked. The Ko-fi token is not validated. Anyone with a valid user API key can spoof events. |
| WebSocket routing | `./websocket/server.py:822` | `elif event == "KOFI": await self.handle_kofi_event(code, data)` |
| WebSocket delegation | `./websocket/server.py:956` | Delegates to `self.donation_handler.handle_kofi_event(code, data)` |
| Event broadcaster | `./websocket/donation_handler.py:24` | `broadcast_with_globals("KOFI", data, code)` — fans out to all clients registered under the channel code. |
| Overlay | `./overlay/kofi.php` | Socket.io 4.8.3 client. Registers as `{ code, channel: 'Overlay', name: 'Ko-Fi' }`. Queues alerts via `alertQueue`; displays each for `ALERT_DURATION = 7000 ms`. Includes `parseKofiData()` Python-dict fallback parser. |
| Alert rendering | `./overlay/kofi.php:buildAlertElement()` | `Donation` (💰), `Subscription` (⭐), `Shop Order` (🛒), everything else including `Commission` → generic ☕ fallback. |
| Reconnect logic | `./overlay/kofi.php:attemptReconnect()` | Exponential back-off up to 30 s cap (`Math.min(5000 * attempts, 30000)`). |
