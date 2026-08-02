---
name: project_openai_model_config
description: All bots pick the OpenAI chat model from a single per-file OPENAI_MODEL constant; current default gpt-5.4-mini
metadata: 
  node_type: memory
  type: project
  originSessionId: e16f7f5e-4789-4c87-9314-0a848712ed9b
---

Every bot process selects its OpenAI **chat** model from a single module-level `OPENAI_MODEL` constant defined near its `openai_client = AsyncOpenAI(...)` setup, and ALL `model=` callsites reference it — never inline a model string. As of 2026-06-08 the constant exists in all six: `bot/bot.py`, `bot/beta.py`, `bot/beta-v6.py`, `bot/specterdiscord.py`, `bot/kick.py`, and `bot/custom_channel_modules/botofthespecter.py`. They are six independent literals (the bots run as separate processes — no shared global). To change the model, edit those constants, nothing else.

Current fleet-wide default: **`gpt-5.4-mini`** ("best bang for buck / intelligence" per user; it replaced an earlier drift of gpt-5.4-nano / gpt-5-nano / gpt-4o-mini). It is a GPT‑5 reasoning-family model, so Chat Completions requires `max_completion_tokens` (NOT `max_tokens`) — only `kick.py` caps tokens (`max_completion_tokens=200`).

Gotchas: TTS is separate — `websocket/tts_handler.py` `MODEL_NAME = "gpt-4o-mini-tts"` is NOT governed by OPENAI_MODEL; don't touch it when changing the chat model. Discord's `specterdiscord.py:6695` realtime URL (`gpt-4o-realtime-preview`) is a dormant/disabled feature — leave it. Admin cost reporting (`config/openai.php` + `dashboard/admin/index.php` pricing maps) has no `gpt-5.4-mini` row, so dashboard cost falls back to the default $2.50/$10 until a real pricing row is added. Reference doc: `.grok/docs/API/External/openai.md`. See [[feedback_bot_file_function_placement]].
