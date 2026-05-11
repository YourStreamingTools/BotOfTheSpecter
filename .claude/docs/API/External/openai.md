# OpenAI API — BotOfTheSpecter Reference

Local reference for how this project uses the OpenAI API. Covers Twitch/Discord/Kick chat replies, ad-break commentary, the bot's own home-channel chat AI, OpenAI TTS in the WebSocket server, and the dashboard admin usage/billing widget.

This is a **reference doc**, not a tutorial. It maps each call site to the SDK method and the request shape, and records the project conventions on top.

---

## 1. Overview

OpenAI is invoked from three runtimes:

1. **Twitch bot** (`./bot/bot.py`, `./bot/beta.py`, `./bot/beta-v6.py`) — chat reply AI for users with premium tier ≥ 2000, plus AI-driven ad-break commentary.
2. **Discord bot** (`./bot/specterdiscord.py`) — DM and guild-channel reply AI, with both non-streaming and streaming variants.
3. **Kick bot** (`./bot/kick.py`) — `!story` command only (one-shot text generation).
4. **WebSocket server** (`./websocket/tts_handler.py`) — OpenAI TTS (`gpt-4o-mini-tts`) used to synthesise alert / chat TTS audio for overlays.
5. **Custom-channel module** (`./bot/custom_channel_modules/botofthespecter.py`) — extra AI flow when the bot runs in its own home channel `botofthespecter`.
6. **Dashboard admin** (`./dashboard/admin/index.php`) — read-only panel that calls `https://api.openai.com/v1/organization/usage/completions` to display token usage and dollar cost.

There are **two distinct flows** to be aware of:

| Flow | Where | History store | Instructions |
| ---- | ----- | ------------- | ------------ |
| Per-user Twitch chat reply | `Bot.get_ai_response()` | `/home/botofthespecter/ai/chat-history/{user_id}.json` | API endpoint `?` (default) |
| Discord chat reply | `Bot.get_ai_response()` / `get_ai_response_stream()` | `/home/botofthespecter/ai/chat-history/{guild_id}_{channel_name}.json` or `{channel_name}.json` | API endpoint `?discord=true` |
| Ad-break commentary | `handle_ad_break_start()` | `/home/botofthespecter/ai/ad_break_chat/{channel_name}.json` (chat log used as context, not OpenAI history) | API endpoint `?ad_messages=true` |
| Bot home-channel | `custom_channel_modules.botofthespecter` | `/home/botofthespecter/ai/bot-channel-chat-history/{user_id}.json` | API endpoint `?home_ai=true` |
| `!story` (Kick) | `cmd_story` | none (one-shot) | inline prompt |
| TTS synthesis | `TTSHandler.generate_api_tts()` | n/a | n/a |

The shared infrastructure piece is the **`/chat-instructions`** endpoint on `api.botofthespecter.com`, which serves the `system` prompt(s) for each flow. See §4.

---

## 2. Authentication

### Python runtimes (bot, websocket)

- Single env var: `OPENAI_KEY` (note: not the SDK's default `OPENAI_API_KEY`).
- Loaded via `dotenv` from `/home/botofthespecter/.env` on the server. Local repo example: `./bot/.env.example` line 10 (`OPENAI_KEY=`).
- The SDK is constructed explicitly with the key, never via env auto-discovery:

```python
from openai import AsyncOpenAI
import os

OPENAI_API_KEY = os.getenv('OPENAI_KEY')
openai_client = AsyncOpenAI(api_key=OPENAI_API_KEY)
```

Repo callsites (one client per process, module-level):

- `./bot/bot.py:191`
- `./bot/beta.py:254`
- `./bot/beta-v6.py:233`
- `./bot/specterdiscord.py` (loaded via `OPENAI_API_KEY = os.getenv('OPENAI_KEY')`)
- `./bot/kick.py` — same pattern
- `./websocket/tts_handler.py:25` (`self.openai_client = AsyncOpenAI(api_key=os.getenv('OPENAI_KEY'))`)

> **Rule:** Never hardcode an OpenAI key. Never log the key. Never echo it back to a Twitch/Discord channel or webhook. See `.claude/rules/secrets.md`.

The `bot.py` token-reload paths re-read `OPENAI_KEY` on demand — see `./bot/bot.py:13943`, `./bot/beta.py:13943` (handler in `globals()` block), `./bot/beta-v6.py:11373`. The `AsyncOpenAI` client object is **not** re-instantiated; only the cached env var is. If you ever rotate the key on a live process, restart the bot to pick it up at the SDK level.

### PHP runtime (dashboard)

- Loaded from `./config/openai.php` (dev) / `/var/www/config/openai.php` (server). PHP **never** uses `.env` — see `.claude/rules/php-config.md`.
- `./config/openai.php` returns an array with `admin_key`, `organization_id`, `project_id`, plus a `pricing_per_million` map used for cost estimation (see §5).
- The admin key is sent as `Authorization: Bearer <admin_key>` to `https://api.openai.com/v1/organization/usage/*`. This is a **separate** key from the chat-completions key used by the bot — it must be a key with `api.usage.read` scope on the organization.

---

## 3. Endpoints used

The project only ever uses the **Chat Completions** API (`POST /v1/chat/completions`) for text, plus the **Audio Speech** API for TTS, plus the **Organization Usage** API for the admin dashboard. The Responses API is **not** used. The Realtime API is referenced in `./bot/specterdiscord.py:6463` (URL only — `wss://api.openai.com/v1/realtime?model=gpt-4o-realtime-preview`) but the integration is dormant.

### 3.1 Chat Completions

| Field | Value used |
| ----- | ---------- |
| HTTP | `POST /v1/chat/completions` |
| SDK method (preferred) | `await openai_client.chat.completions.create(...)` |
| SDK method (fallback) | `await openai_client.chat_completions.create(...)` (legacy attribute name; kept for forward/back compat) |
| Streaming SDK method | `async with openai_client.chat.completions.stream(...) as stream:` (Discord only) |

**Required parameters used everywhere:**

- `model` — see §5.
- `messages` — list of `{role: 'system'|'user'|'assistant', content: str}`.

**Parameters NOT set anywhere in this project (left at SDK defaults):**

- `temperature`, `top_p`, `n`, `stop`, `presence_penalty`, `frequency_penalty`, `response_format`, `tools`, `tool_choice`, `seed`, `user`.
- `max_tokens` is set in **only one place**: `./bot/kick.py:1540` (`max_tokens=200` for `!story`). Every other call relies on the model's default cap — which is why each flow injects a `system` message instructing the model to stay under `MAX_CHAT_MESSAGE_LENGTH` (500 chars).

**Sample request (mirrors `Bot.get_ai_response`):**

```python
resp = await openai_client.chat.completions.create(
    model=OPENAI_MODEL,                              # e.g. 'gpt-5.4-nano'
    messages=[
        {"role": "system",    "content": "<remote instructions from /chat-instructions>"},
        {"role": "system",    "content": "You are speaking to Twitch user 'Foo' (id: 12345)..."},
        {"role": "system",    "content": "Important: Keep your final reply under 500 characters..."},
        {"role": "user",      "content": "<previous user turn>"},
        {"role": "assistant", "content": "<previous assistant turn>"},
        # ... up to last 8 turns of history ...
        {"role": "user",      "content": "<current user message>"},
    ],
)
```

**Response shape (as consumed by this project):**

The code accepts both dict-style and attribute-style responses (defensive against SDK changes). The actual modern SDK returns Pydantic models; the dict branch is dead code in practice but is left in place. Reference fields:

```python
resp.choices[0].message.content          # main reply text
resp.choices[0].finish_reason            # 'stop' | 'length' | 'content_filter' | 'tool_calls'
resp.usage.prompt_tokens                 # not consumed by bot, but logged via dashboard usage endpoint
resp.usage.completion_tokens
resp.usage.total_tokens
resp.id                                  # 'chatcmpl-xxxx'
resp.model                               # echoes the requested model
resp.created                             # unix ts
```

Extraction logic (verbatim from `./bot/beta.py:4416-4443`):

```python
chat_client = getattr(openai_client, 'chat', None)
ai_text = None
if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
    resp = await chat_client.completions.create(model=OPENAI_MODEL, messages=messages)
    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
        choice = resp['choices'][0]
        if 'message' in choice and 'content' in choice['message']:
            ai_text = choice['message']['content']
        elif 'text' in choice:
            ai_text = choice['text']
    else:
        choices = getattr(resp, 'choices', None)
        if choices and len(choices) > 0:
            ai_text = getattr(choices[0].message, 'content', None)
elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'create'):
    # legacy attribute name fallback
    ...
```

**Repo callsites (chat completions):**

| File | Line | Flow |
| ---- | ---- | ---- |
| `./bot/bot.py` | 2585, 2598 | per-user Twitch reply (stable) |
| `./bot/beta.py` | 4421, 4434 | per-user Twitch reply (beta) |
| `./bot/beta.py` | 14685, 14698 | ad-break AI (beta) |
| `./bot/beta-v6.py` | 3529, 3542 | per-user Twitch reply (v6) |
| `./bot/beta-v6.py` | 12085, 12097 | ad-break AI (v6) |
| `./bot/specterdiscord.py` | 2377, 2389 | Discord non-streaming reply |
| `./bot/specterdiscord.py` | 2531, 2556 | Discord streaming reply |
| `./bot/specterdiscord.py` | 2583, 2595 | Discord streaming fallback (non-stream) |
| `./bot/custom_channel_modules/botofthespecter.py` | 115, 127 | bot home-channel reply |
| `./bot/kick.py` | 1537 | Kick `!story` |

### 3.2 Audio Speech (TTS)

`./websocket/tts_handler.py` uses **streaming-response** mode to write directly to disk:

```python
async with self.openai_client.audio.speech.with_streaming_response.create(
    model="gpt-4o-mini-tts",     # MODEL_NAME constant
    voice=voice_name,            # one of AVAILABLE_VOICES
    input=text,                  # max 4096 chars (pre-validated)
    response_format="mp3",
) as response:
    await response.stream_to_file(filepath)
```

Constants (`./websocket/tts_handler.py:11-13`):

```python
MODEL_NAME = "gpt-4o-mini-tts"
DEFAULT_VOICE = "alloy"
AVAILABLE_VOICES = ["alloy", "ash", "ballad", "coral", "echo", "fable",
                    "nova", "onyx", "sage", "shimmer"]
```

Project conventions:

- Voice is normalised to lowercase before the API call. Unknown voice → `alloy`.
- Input text is rejected if `len(text) > 4096`.
- Output filename: `tts_output_{code}_{uuid8}.mp3` written to `/home/botofthespecter/tts/`.
- After generation the file is SCP'd to the web host and the websocket emits a `TTS` event. Local file is deleted after estimated playback duration + 5 s.

### 3.3 Organization Usage (admin dashboard only)

PHP-side, not used by the bot. Hits:

- `GET https://api.openai.com/v1/organization/usage/completions`
- Auth: `Authorization: Bearer <openai_config['admin_key']>` (super-admin org key).
- Query: `start_time`, `end_time` (Unix), `bucket_width=1d`, `limit=30` (overrideable in `./config/openai.php`), plus optional `group_by`, `models`, `project_ids`, `user_ids`, `api_key_ids`, `batch`.
- Walks pagination via `openai_get_all_pages()` in `./dashboard/admin/index.php` (max 20 pages).
- Output is reduced into per-model token totals → multiplied by `pricing_per_million` to estimate dollar cost.

This is the **only** PHP code path that talks to OpenAI. Don't add chat-completions calls in PHP — PHP is the wrong runtime for that here.

---

## 4. Prompt construction

### 4.1 The `/chat-instructions` API endpoint

System prompts are not in code — they live in JSON files on the server and are served by the FastAPI endpoint:

```
GET https://api.botofthespecter.com/chat-instructions
GET https://api.botofthespecter.com/chat-instructions?discord=true
GET https://api.botofthespecter.com/chat-instructions?ad_messages=true
GET https://api.botofthespecter.com/chat-instructions?home_ai=true
```

Endpoint code: `./api/api.py:2742-2784` (operation_id `get_chat_instructions`).

Server-side files (loaded in priority by query flag):

| Flag | File |
| ---- | ---- |
| `?ad_messages=true` | `/home/botofthespecter/ai.ad_messages.json` |
| `?home_ai=true` | `/home/botofthespecter/ai.home.json` |
| `?discord=true` | `/home/botofthespecter/ai.discord.json` |
| (none) | `/home/botofthespecter/ai.json` (default) |

Returns 404 if the flagged file is missing. The default file (`ai.json`) is the fallback for the bot's main per-user reply flow.

**Accepted response shapes** (the bots tolerate all three):

```json
// shape 1: bare list of message dicts
[
  {"role": "system", "content": "You are SpecterBot..."},
  {"role": "system", "content": "Be concise..."}
]
```

```json
// shape 2: single system string
{"system": "You are SpecterBot, the witty Twitch chat bot..."}
```

```json
// shape 3: wrapped messages
{"messages": [
  {"role": "system", "content": "..."}
]}
```

### 4.2 Caching

Each bot caches the parsed instructions for `INSTRUCTIONS_CACHE_TTL` seconds (300 s = 5 min):

- `_cached_instructions` — default flow.
- `_cached_ad_instructions` — `?ad_messages=true`.
- `_cached_home_instructions` — `?home_ai=true`.

Discord uses the same `_cached_instructions` slot but appends `?discord=true` to the URL (slight inconsistency: a Twitch-bot fetch and a Discord-bot fetch will not happen in the same process, so this is fine in practice).

Reference: `./bot/beta.py:14447-14506` (`get_remote_instruction_messages()`).

### 4.3 Per-user history files

For Twitch-bot per-user replies, history is stored as a JSON list of `{role, content}` objects, one file per Twitch user_id:

```
/home/botofthespecter/ai/chat-history/{user_id}.json
```

Constraints:

- Read on every reply; the **last 8 entries** are inserted into the message list before the current user turn.
- After each reply, both the user message and the assistant reply are appended.
- File is **trimmed to the last 200 entries** on every write.
- Format:

```json
[
  {"role": "user",      "content": "what's my watchtime?"},
  {"role": "assistant", "content": "@Foo you've watched for 12h 4m."},
  {"role": "user",      "content": "thanks"},
  {"role": "assistant", "content": "anytime!"}
]
```

For Discord, the file naming differs:

- DM channel: `{channel_name}.json` (where `channel_name` is the user ID for DMs).
- Guild channel: `{guild_id}_{channel_name}.json`.

For the bot home-channel module, history is keyed by `user_id` but the path is `/home/botofthespecter/ai/bot-channel-chat-history/{user_id}.json`, and **last 12 entries** are restored (vs 8 for the standard flow). The file name is also sanitised: `re.sub(r'[^a-z0-9_\-]', '_', history_key)`.

For ad-break AI, the "history" is the raw recent **chat** log (not OpenAI turns) read from `/home/botofthespecter/ai/ad_break_chat/{channel_name}.json` and embedded into a single user-content string. It is not OpenAI message history.

### 4.4 Final message list assembly (Twitch user reply)

Order matters. The bot constructs:

1. System prompt(s) from `/chat-instructions` (one or more `system` messages).
2. **User-context system message** (`./bot/beta.py:4384`): `"You are speaking to Twitch user 'Foo' (id: 12345). Address them by their display name @Foo and tailor the response to them. Keep responses concise and suitable for Twitch chat."`
3. **Length-limit system message** (`./bot/beta.py:4388`): `"Important: Keep your final reply under 500 characters total..."`
4. Recent history — last 8 turns from `chat-history/{user_id}.json`.
5. Current user message as the final `user` turn.

### 4.5 Token budget

There is no explicit token budget enforcement. The implicit budget comes from:

- `MAX_CHAT_MESSAGE_LENGTH = 500` characters (Twitch limit; 2000 for Discord) injected as a soft instruction in step 3.
- History trimmed to last 8 (Twitch) / 12 (home channel) turns.
- File cap: 200 entries hard-trimmed on write.

If you change the model, **recheck the system-message length-cap instructions** — they're written for a 500-char Twitch response and 2000-char Discord response. Reference: `MAX_CHAT_MESSAGE_LENGTH` in each bot file.

### 4.6 Post-processing filter

`./bot/beta.py:4451`:

```python
if "Chaos Crew" in ai_text:
    ai_text = ai_text.replace("Chaos Crew", "Stream Team")
```

A specific hallucination filter — the model will sometimes invent the phrase "Chaos Crew" for the bot owner's stream team, so it is rewritten. Don't remove this without confirming with the user.

---

## 5. Model

### 5.1 Constant location

| File | Constant | Value |
| ---- | -------- | ----- |
| `./bot/beta.py:114` | `OPENAI_MODEL` | `'gpt-5.4-nano'` |
| `./bot/specterdiscord.py:163` | `OPENAI_MODEL` | `'gpt-5.4-nano'` |
| `./bot/bot.py` | (inline) | `'gpt-5-nano'` (line 2585) |
| `./bot/beta-v6.py` | (inline) | `'gpt-5-nano'` (line 3529 etc.) |
| `./bot/custom_channel_modules/botofthespecter.py` | (inline) | `'gpt-5-nano'` (line 115) |
| `./bot/kick.py` | (inline) | `'gpt-4o-mini'` (line 1538) |
| `./websocket/tts_handler.py:11` | `MODEL_NAME` | `'gpt-4o-mini-tts'` |

`gpt-5.4-nano` is the **configured model name** in beta/Discord. Treat it as authoritative — don't "fix" it to `gpt-5-nano` without checking whether the user's OpenAI org actually exposes that model alias. If you need a single source of truth, lift it to `OPENAI_MODEL = os.getenv('OPENAI_MODEL', 'gpt-5.4-nano')` and update all callsites.

### 5.2 Pricing reference

`./config/openai.php:11-35` keeps the per-million pricing table the dashboard uses for cost estimation:

```
gpt-5             input $1.25 / output $10.00
gpt-5-mini        input $0.25 / output $2.00
gpt-5-nano        input $0.05 / output $0.40
gpt-5-chat-latest input $1.25 / output $10.00
gpt-4.1           input $2.00 / output $8.00
gpt-4.1-mini      input $0.40 / output $1.60
gpt-4.1-nano      input $0.10 / output $0.40
gpt-4o            input $2.50 / output $10.00
gpt-4o-mini       input $0.15 / output $0.60
gpt-4o-mini-tts   input $0.60 / output $12.00
o1                input $15.00 / output $60.00
o3                input $2.00 / output $8.00
o4-mini           input $1.10 / output $4.40
```

`gpt-5.4-nano` is **not** in this table — pricing falls back to the `default` row (`$2.50 / $10.00`). If `gpt-5.4-nano` is a real model and you want accurate dashboard cost, add a row to `./config/openai.php`.

### 5.3 Swapping models

To change the model fleet-wide:

1. Update each callsite listed in §5.1.
2. Update `./bot/specterdiscord.py:163` and `./bot/beta.py:114` constants.
3. If the new model has a different price, add it to `./config/openai.php` `pricing_per_million`.
4. Verify TTS model (`gpt-4o-mini-tts`) — TTS is a separate model family.
5. If you switch to a `o1`/`o3`/`gpt-5` reasoning model, **pass `max_completion_tokens` instead of `max_tokens`** in `./bot/kick.py` — older `max_tokens` is deprecated for the reasoning families.

---

## 6. Rate limits and cost

OpenAI rate limits are tier-based (RPM = requests/minute, TPM = tokens/minute, RPD = requests/day) and per model. The free tier is too restrictive for production; this project is on a paid org.

This project does **not** implement client-side rate limiting against OpenAI. The expected mitigations are:

1. **Premium gating** — Twitch-bot AI replies are tier-gated (`premium_tier in (2000, 3000, 4000)` — see `./bot/beta.py:4346`). Random viewers cannot trigger AI calls.
2. **Cooldowns** — bot commands have per-user / per-bucket cooldowns enforced in MySQL.
3. **Length cap via system message** — keeps per-call token cost low (mostly < 500 output tokens).
4. **Instructions caching** — `/chat-instructions` is cached for 5 min, but this is to relieve the API server, not OpenAI.

If 429s start to appear:

- Check the dashboard admin page (`./dashboard/admin/index.php`) AI Stats card for daily token volume.
- Check `Retry-After` header in the SDK error (auto-retried by SDK, see §8).
- Consider switching model tier (`gpt-5-nano` vs `gpt-5-mini`) if hitting per-model TPM limits.

OpenAI publishes current limits at `https://platform.openai.com/account/limits` (org-specific, login required).

---

## 7. Streaming vs non-streaming

| Flow | Mode | Why |
| ---- | ---- | --- |
| Twitch user reply | **Non-streaming** | Twitch chat doesn't render incremental output; need full response before sending. |
| Twitch ad-break AI | **Non-streaming** | Single message posted at start of break. |
| Twitch home-channel | **Non-streaming** | Same as Twitch user reply. |
| Discord DM reply | **Streaming** (with non-stream fallback) | Discord typing indicator + can chunk on word boundaries; gives user perceived responsiveness. |
| Discord guild reply | **Streaming** (with non-stream fallback) | Same as DM. |
| Kick `!story` | **Non-streaming** | Single chat post. |
| TTS | **Streaming** (`with_streaming_response`) | Audio bytes streamed directly to disk to avoid buffering full file in memory. |

### 7.1 Discord streaming pattern

`./bot/specterdiscord.py:2531`:

```python
chat_client = getattr(openai_client, 'chat', None)
buffer = ""
streamed = False
if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'stream'):
    async with chat_client.completions.stream(model=OPENAI_MODEL, messages=messages) as stream:
        async for chunk in stream:
            delta = ""
            if isinstance(chunk, dict):
                choice = chunk.get('choices', [None])[0]
                if choice:
                    delta = (choice.get('delta') or {}).get('content') or choice.get('text', '')
            else:
                choice = getattr(chunk, 'choices', None)
                if choice and len(choice) > 0:
                    delta = getattr(choice[0].delta, 'content', None) or getattr(choice[0], 'text', '')
            if delta:
                buffer += delta
                streamed = True
                yield delta
# if streaming yielded nothing, fall back to non-stream chat.completions.create
```

The fallback to non-stream is intentional — if the SDK ever drops the `.stream()` async context manager, the bot doesn't go silent.

### 7.2 Why we don't use `stream=True` parameter

The Discord bot uses the dedicated `client.chat.completions.stream(...)` async context manager rather than `client.chat.completions.create(..., stream=True)` because the context manager handles `[DONE]` parsing and yields fully-typed `ChatCompletionChunk` objects. Both work; this project picked the context-manager flavour.

---

## 8. Error handling

### 8.1 SDK exception hierarchy (openai>=1.x)

```
APIError
├── APIConnectionError       (network / DNS / TLS)
│   └── APITimeoutError
├── APIStatusError           (any 4xx / 5xx)
│   ├── BadRequestError              400
│   ├── AuthenticationError          401
│   ├── PermissionDeniedError        403
│   ├── NotFoundError                404
│   ├── ConflictError                409
│   ├── UnprocessableEntityError     422
│   ├── RateLimitError               429
│   └── InternalServerError          500-5xx
```

### 8.2 What this project does

The bots use a single broad `except Exception:` around every chat-completion call and return a friendly fallback string. They do **not** distinguish between rate-limit, auth, and server errors. Examples:

`./bot/beta.py:4444`:

```python
except Exception as e:
    api_logger.error(f"[AI] Error calling chat completion API: {e}")
    return "An error occurred while contacting the AI chat service."
```

`./bot/specterdiscord.py:2399`:

```python
except Exception as e:
    self.logger.error(f"Error calling chat completion API: {e}")
    return ["An error occurred while contacting the AI chat service."]
```

This is intentional — Twitch chat is not a place to leak SDK error class names. The error is logged to the per-channel `api.txt` log for debugging.

### 8.3 Retries

The SDK retries automatically (default 2 retries) on:

- `APIConnectionError`
- HTTP 408 (request timeout)
- HTTP 409 (conflict, lock-related)
- HTTP 429 (rate limit) — respects `Retry-After`
- HTTP 5xx (server errors)

This project does **not** override `max_retries` or `timeout` on the client. Defaults are:

- `max_retries=2`
- `timeout=600.0` (10 min)

For Twitch chat replies, 10 min is too long — a viewer's `!chat` will time out from their perspective long before. Consider lowering this if 5xx storms cause hangs:

```python
openai_client = AsyncOpenAI(api_key=OPENAI_API_KEY, max_retries=1, timeout=20.0)
```

(Not currently applied; flag if you change it.)

### 8.4 Common error symptoms in this project's logs

| Log line | Likely cause | Fix |
| -------- | ------------ | --- |
| `[AI] Error calling chat completion API: AuthenticationError` | Missing or rotated `OPENAI_KEY` | Update `/home/botofthespecter/.env` and restart bot |
| `[AI] Error calling chat completion API: BadRequestError ... model ... does not exist` | `OPENAI_MODEL='gpt-5.4-nano'` is wrong for this org | Change to a model your org has access to (e.g. `gpt-5-nano`) |
| `[AI] Error calling chat completion API: RateLimitError` | TPM/RPM exceeded | Wait; SDK retries. If persistent, request limit increase or downgrade model |
| `[AI] Chat completion returned no usable text: <resp>` | Response shape unexpected; choices[] empty | Check `finish_reason` — content_filter blocked? |
| `[AI] No compatible chat completions method found on openai_client` | SDK version mismatch — neither `chat.completions` nor `chat_completions` exists | `pip install -U openai` |
| `[TTS] OPENAI_API_KEY not set` | `OPENAI_KEY` env var missing on websocket server | Set it on the websocket host's `.env` |
| `Text too long: NNNN characters (max 4096)` | TTS input over 4096 chars | Caller must chunk before submitting |

### 8.5 PHP error handling (dashboard)

The PHP usage call uses a multi-cURL helper (`openai_multi_curl`) and pages through results. Failures are surfaced into the admin page with `$openai_debug_info`. There's no automatic retry on the PHP side — a transient 5xx will simply show "—" in the AI Stats card.

---

## 9. AsyncOpenAI SDK notes specific to this project

### 9.1 Version

The bot's `requirements.txt` pins `openai` (check the file for the current pin). The code is written defensively against minor SDK changes — it probes for `client.chat.completions.create` first and falls back to `client.chat_completions.create` if the attribute layout ever differs. This dual-path is dead in current SDKs but kept for safety.

### 9.2 Single client per process

Each bot constructs **one** module-level `AsyncOpenAI` client. Don't construct a client per request — connection pooling lives inside the SDK's HTTPX client and you'll lose it.

### 9.3 No `OPENAI_API_KEY` env var pickup

The SDK looks for `OPENAI_API_KEY` by default. This project uses `OPENAI_KEY` deliberately and **always** passes `api_key=` explicitly. If you copy a snippet from the SDK README that relies on env auto-discovery, it will silently fail (the `AsyncOpenAI()` no-args constructor will raise `OpenAIError` in this project's environment).

### 9.4 Async context for the WebSocket TTS streaming

The TTS path uses `with_streaming_response` to avoid loading the full MP3 into RAM:

```python
async with self.openai_client.audio.speech.with_streaming_response.create(
    model="gpt-4o-mini-tts", voice=voice, input=text, response_format="mp3",
) as response:
    await response.stream_to_file(filepath)
```

This is the right pattern. **Don't** switch to `client.audio.speech.create(...).content` — that buffers the whole file.

### 9.5 The `chat_completions` (underscore) attribute

You will see `hasattr(openai_client, 'chat_completions')` in many places. This was a legacy attribute on very early SDK versions (pre-1.0). It does not exist on modern SDKs. The fallback branch will never run on current installs. Leave it; removing it doesn't simplify enough to be worth the risk.

### 9.6 Don't use the top-level `openai` module

This project never calls `openai.ChatCompletion.create(...)` (the pre-1.0 module-level API). All calls go through the `AsyncOpenAI` instance. Code review reject any PR that reintroduces the module-level call.

### 9.7 Discord streaming consumes async generators carefully

In `./bot/specterdiscord.py:2713`, the `on_message` handler awaits `async for delta in self.get_ai_response_stream(...)` inside `async with channel.typing()`. Don't refactor this to materialise the full response first — it defeats the typing-indicator UX.

### 9.8 Dashboard PHP — separate admin key

The dashboard's OpenAI key (`./config/openai.php` → `admin_key`) **must be a different key** from the one the bot uses, with `api.usage.read` scope. Don't paste the bot's chat key here — it will work for chat completions but won't have org-usage access.

---

## 10. Quick reference card

```
Bot env var ........... OPENAI_KEY                (NOT OPENAI_API_KEY)
SDK class ............. openai.AsyncOpenAI
Chat method ........... await openai_client.chat.completions.create(model=..., messages=...)
TTS method ............ async with openai_client.audio.speech.with_streaming_response.create(...)
Default chat model .... gpt-5.4-nano (beta/Discord) | gpt-5-nano (stable/v6/home) | gpt-4o-mini (Kick)
Default TTS model ..... gpt-4o-mini-tts
TTS voices ............ alloy ash ballad coral echo fable nova onyx sage shimmer
TTS max input ......... 4096 chars
History trim .......... last 200 entries on disk, last 8 turns sent (12 for home channel)
Length cap ............ 500 chars (Twitch) / 2000 chars (Discord) via system message
Cache TTL ............. 300 s for /chat-instructions
Premium gate .......... tier 2000+ for Twitch chat AI
Instructions URL ...... https://api.botofthespecter.com/chat-instructions[?discord|ad_messages|home_ai=true]
Instructions files .... /home/botofthespecter/ai.json (default)
                        /home/botofthespecter/ai.discord.json
                        /home/botofthespecter/ai.ad_messages.json
                        /home/botofthespecter/ai.home.json
History dir ........... /home/botofthespecter/ai/chat-history/{user_id}.json (Twitch)
                        /home/botofthespecter/ai/chat-history/{guild_id}_{channel_name}.json (Discord guild)
                        /home/botofthespecter/ai/chat-history/{channel_name}.json (Discord DM)
                        /home/botofthespecter/ai/bot-channel-chat-history/{user_id}.json (home channel)
                        /home/botofthespecter/ai/ad_break_chat/{channel_name}.json (ad-break chat log)
PHP config ............ ./config/openai.php (dev) / /var/www/config/openai.php (server)
PHP usage endpoint .... https://api.openai.com/v1/organization/usage/completions
```

---

## 11. Things that are NOT used (don't add without discussion)

- **Responses API** (`/v1/responses`) — newer endpoint, not adopted here. All text generation goes through Chat Completions.
- **Assistants API / Threads / Runs** — not used. The conversation state is project-managed JSON files, not OpenAI-managed threads.
- **Function calling / tools** — not used. The bot's "tools" are Twitch/Discord/Kick command handlers, not OpenAI tool calls.
- **Embeddings API** (`/v1/embeddings`) — `OPENAI_VECTOR_ID` exists in `.env.example` but no embedding code exists in the repo currently. If you add RAG, this is where it'd land.
- **Moderation API** (`/v1/moderations`) — no automated content moderation on output. The "Chaos Crew" replace is a hardcoded post-filter, not Moderation API.
- **Realtime API** — referenced in `./bot/specterdiscord.py:6463` but the integration is dormant.
- **Image generation** (`/v1/images`) — not used.
- **Vision input** — Discord bot accepts text attachments only, not image inputs.

If the user asks for any of the above, treat it as new work and confirm scope before implementing.

---

## 12. Where to start when modifying AI behaviour

| Goal | Edit |
| ---- | ---- |
| Change the system prompt | Edit `/home/botofthespecter/ai.json` (or `ai.discord.json` etc.) on the server. **Do not** hardcode in bot files. |
| Change the model | Update `OPENAI_MODEL` in `./bot/beta.py:114` and `./bot/specterdiscord.py:163`, plus inline `model=` in `./bot/bot.py`, `./bot/beta-v6.py`, `./bot/kick.py`, `./bot/custom_channel_modules/botofthespecter.py`. Add pricing row to `./config/openai.php`. |
| Adjust history length | `recent = history[-8:]` in each `get_ai_response`. |
| Adjust history file cap | `if len(history) > 200:` in each `get_ai_response`. |
| Change instructions cache TTL | `INSTRUCTIONS_CACHE_TTL = int('300')` in each bot file. |
| Change TTS voice list | `AVAILABLE_VOICES` in `./websocket/tts_handler.py:13`. |
| Change TTS model | `MODEL_NAME` in `./websocket/tts_handler.py:11`. |
| Change premium gate | `if premium_tier in (2000, 3000, 4000)` in each `get_ai_response`. |
| Add a new instructions flow | Extend `./api/api.py:2749` (`chat_instructions`) with a new query flag and a new JSON file path. |
| Add a hallucination filter | Extend the post-processing block (see "Chaos Crew" example, `./bot/beta.py:4451`). |

---

**Last checked against repo:** 2026-05-10. Bot file line numbers are accurate as of this date but will drift — re-grep with `OPENAI_MODEL`, `chat.completions`, or `get_ai_response` to find current locations.
