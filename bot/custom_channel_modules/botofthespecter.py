# Custom channel module for the 'botofthespecter' Twitch channel.
# Handles AI-driven chat responses that are exclusive to this channel.
# Called from bot file when the bot detects it is running in the botofthespecter channel.
# BotOfTheSpecterModule also keeps the channel title in step with /versions.

import os
import re
import json
import asyncio
from pathlib import Path
from aiomysql import DictCursor

# OpenAI chat model used for this channel's AI responses
OPENAI_MODEL = "gpt-5.4-mini"

def is_bot_home_channel(channel_name: str, bot_home_channel_name: str) -> bool:
    return str(channel_name).lower() == str(bot_home_channel_name).lower()

def _should_trigger_ai(normalized_message: str) -> bool:
    if not normalized_message:
        return False
    if normalized_message.startswith('!'):
        return False
    return True

def _extract_user_message(original_message: str, bot_home_channel_name: str, bot_nick: str | None) -> str:
    cleaned = original_message or ""
    cleaned = re.sub(rf"@{re.escape(bot_home_channel_name)}\b", "", cleaned, flags=re.IGNORECASE)
    if bot_nick:
        cleaned = re.sub(rf"@{re.escape(bot_nick)}\b", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned

async def handle_bot_home_channel_ai(
    bot_nick,
    original_message,
    normalized_message,
    user_id,
    author_name,
    bot_home_channel_name,
    openai_client,
    get_remote_instruction_messages,
    api_logger,
    bot_home_ai_history_dir,
    max_chat_message_length,
):
    if not _should_trigger_ai(normalized_message):
        return None
    if not author_name:
        return None
    bot_nick_lower = bot_nick.lower() if bot_nick else ""
    if author_name.lower() in {bot_home_channel_name.lower(), bot_nick_lower}:
        return None
    user_message = _extract_user_message(original_message, bot_home_channel_name, bot_nick)
    if not user_message:
        user_message = "Say a friendly hello and ask what they feel like chatting about."
    return await _get_ai_response(
        user_message=user_message,
        user_id=user_id,
        message_author_name=author_name,
        openai_client=openai_client,
        get_remote_instruction_messages=get_remote_instruction_messages,
        api_logger=api_logger,
        bot_home_ai_history_dir=bot_home_ai_history_dir,
        max_chat_message_length=max_chat_message_length,
    )

async def _get_ai_response(
    user_message,
    user_id,
    message_author_name,
    openai_client,
    get_remote_instruction_messages,
    api_logger,
    bot_home_ai_history_dir,
    max_chat_message_length,
):
    try:
        Path(bot_home_ai_history_dir).mkdir(parents=True, exist_ok=True)
    except Exception as e:
        api_logger.debug(f"[AI] Could not create bot-home history directory {bot_home_ai_history_dir}: {e}")
    messages = await get_remote_instruction_messages(home_ai=True)
    try:
        user_context = (
            f"You are speaking to Twitch user '{message_author_name}' (id: {user_id}). "
            f"Address them naturally and include @{message_author_name} when it helps clarity."
        )
        messages.append({'role': 'system', 'content': user_context})
        messages.append({
            'role': 'system',
            'content': (
                f"Keep your final reply under {max_chat_message_length} characters total. "
                "One compact Twitch-ready message is preferred."
            )
        })
    except Exception as e:
        api_logger.error(f"[AI] Failed to build bot-home user context for AI: {e}")
    history_key = str(user_id or message_author_name or 'unknown').strip().lower()
    history_key = re.sub(r'[^a-z0-9_\-]', '_', history_key)
    history_file = Path(bot_home_ai_history_dir) / f"{history_key}.json"
    try:
        history = []
        if history_file.exists():
            try:
                with history_file.open('r', encoding='utf-8') as hf:
                    history = json.load(hf)
            except Exception as e:
                api_logger.debug(f"[AI] Failed to read bot-home history for {history_key}: {e}")
        if isinstance(history, list) and history:
            for item in history[-12:]:
                if isinstance(item, dict) and 'role' in item and 'content' in item:
                    messages.append({'role': item['role'], 'content': item['content']})
    except Exception as e:
        api_logger.debug(f"[AI] Error loading bot-home history for {history_key}: {e}")
    messages.append({'role': 'user', 'content': user_message})
    try:
        api_logger.debug("[AI] Calling OpenAI chat completion from botofthespecter module")
        chat_client = getattr(openai_client, 'chat', None)
        ai_text = None
        resp = None
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
            resp = await openai_client.chat_completions.create(model=OPENAI_MODEL, messages=messages)
            if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
            else:
                choices = getattr(resp, 'choices', None)
                if choices and len(choices) > 0:
                    ai_text = getattr(choices[0].message, 'content', None)
        else:
            api_logger.error("[AI] No compatible chat completions method found on openai_client")
            return "AI chat completions API is not available."
    except Exception as e:
        api_logger.error(f"[AI] Error calling chat completion API for bot-home mode: {e}")
        return "An error occurred while contacting the AI chat service."
    if not ai_text:
        api_logger.error(f"[AI] Bot-home chat completion returned no usable text: {resp}")
        return "The AI chat service returned an unexpected response."
    try:
        history = []
        if history_file.exists():
            try:
                with history_file.open('r', encoding='utf-8') as hf:
                    history = json.load(hf)
            except Exception as e:
                api_logger.debug(f"[AI] Failed to read existing bot-home history for append {history_key}: {e}")
        history.append({'role': 'user', 'content': user_message})
        history.append({'role': 'assistant', 'content': ai_text})
        if len(history) > 200:
            history = history[-200:]
        with history_file.open('w', encoding='utf-8') as hf:
            json.dump(history, hf, ensure_ascii=False, indent=2)
    except Exception as e:
        api_logger.debug(f"[AI] Error while persisting bot-home chat history for {history_key}: {e}")
    return ai_text


_CHANNEL_NAME = "botofthespecter"
_VERSIONS_URL = "https://api.botofthespecter.com/versions"
_TITLE_CHECK_SECONDS = 15 * 60
_TITLE_TEMPLATE = (
    "BotOfTheSpecter | Stable: v{STABLE_VERSION} | Beta v{BETA_VERSION} "
    "| !v6 | !who | !changelog | !roadmap | !versions | !setup"
)
_TWITCH_TITLE_MAX = 140


def _version_number(value) -> str:
    text = str(value or "").strip()
    if text.lower().startswith("v"):
        text = text[1:].strip()
    return text


class BotOfTheSpecterModule:
    def __init__(self, mysql_handler, http_session, chat_logger=None):
        self.mysql_handler = mysql_handler
        self.http_session = http_session
        self.chat_logger = chat_logger
        self._broadcaster_id = ""
        self._title_task = None

    @classmethod
    def claims_channel(cls, channel_name: str) -> bool:
        return str(channel_name).lower() == _CHANNEL_NAME

    async def ensure_tables(self) -> None:
        return None

    async def handle_ready(self, broadcaster_id: str, **kwargs) -> None:
        self._broadcaster_id = str(broadcaster_id or "")
        if self._title_task is None or self._title_task.done():
            self._title_task = asyncio.create_task(self._title_loop())
        if self.chat_logger:
            self.chat_logger.info("[botofthespecter] version title check started")

    async def handle_stream_online(self, broadcaster_id: str = "", **kwargs) -> None:
        if broadcaster_id:
            self._broadcaster_id = str(broadcaster_id)
        await self._sync_title()

    async def _title_loop(self) -> None:
        while True:
            try:
                await self._sync_title()
            except Exception as e:
                if self.chat_logger:
                    self.chat_logger.error(f"[botofthespecter] version title check failed: {e}")
            await asyncio.sleep(_TITLE_CHECK_SECONDS)

    async def _sync_title(self) -> None:
        broadcaster_id = self._broadcaster_id
        if not broadcaster_id or self.http_session is None:
            return
        versions = await self._fetch_versions()
        if not versions:
            return
        stable = _version_number(versions.get("stable_version"))
        beta = _version_number(versions.get("beta_version"))
        if not stable or not beta:
            if self.chat_logger:
                self.chat_logger.warning("[botofthespecter] /versions did not include stable and beta")
            return
        title = _TITLE_TEMPLATE.format(STABLE_VERSION=stable, BETA_VERSION=beta)
        if len(title) > _TWITCH_TITLE_MAX:
            if self.chat_logger:
                self.chat_logger.error(
                    f"[botofthespecter] version title is {len(title)} characters, over the {_TWITCH_TITLE_MAX} limit"
                )
            return
        token = await self._bot_token(broadcaster_id)
        client_id = os.getenv("CLIENT_ID") or os.getenv("TWITCH_OAUTH_API_CLIENT_ID") or ""
        if not token or not client_id:
            if self.chat_logger:
                self.chat_logger.warning("[botofthespecter] version title skipped: bot token or client id missing")
            return
        current = await self._current_title(broadcaster_id, token, client_id)
        if current is not None and current.strip() == title:
            return
        await self._set_title(broadcaster_id, token, client_id, title)

    async def _fetch_versions(self) -> dict | None:
        try:
            async with self.http_session.get(_VERSIONS_URL) as response:
                if response.status != 200:
                    if self.chat_logger:
                        self.chat_logger.warning(f"[botofthespecter] /versions HTTP {response.status}")
                    return None
                data = await response.json(content_type=None)
        except Exception as e:
            if self.chat_logger:
                self.chat_logger.error(f"[botofthespecter] /versions request failed: {e}")
            return None
        return data if isinstance(data, dict) else None

    async def _bot_token(self, broadcaster_id: str) -> str:
        try:
            async with await self.mysql_handler.get_connection(db_name="website") as conn:
                async with conn.cursor(DictCursor) as cur:
                    await cur.execute(
                        "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s LIMIT 1",
                        (broadcaster_id,),
                    )
                    row = await cur.fetchone()
        except Exception as e:
            if self.chat_logger:
                self.chat_logger.error(f"[botofthespecter] bot token lookup failed: {e}")
            return ""
        if not row:
            return ""
        return str(row.get("twitch_access_token") or "").strip()

    def _helix_headers(self, token: str, client_id: str) -> dict:
        return {
            "Authorization": f"Bearer {token}",
            "Client-Id": client_id,
            "Content-Type": "application/json",
        }

    async def _current_title(self, broadcaster_id: str, token: str, client_id: str) -> str | None:
        url = "https://api.twitch.tv/helix/channels"
        try:
            async with self.http_session.get(
                url,
                headers=self._helix_headers(token, client_id),
                params={"broadcaster_id": broadcaster_id},
            ) as response:
                if response.status != 200:
                    if self.chat_logger:
                        self.chat_logger.warning(f"[botofthespecter] channel title read HTTP {response.status}")
                    return None
                data = await response.json(content_type=None)
        except Exception as e:
            if self.chat_logger:
                self.chat_logger.error(f"[botofthespecter] channel title read failed: {e}")
            return None
        rows = data.get("data") if isinstance(data, dict) else None
        if not rows:
            return None
        return str((rows[0] or {}).get("title") or "")

    async def _set_title(self, broadcaster_id: str, token: str, client_id: str, title: str) -> None:
        url = "https://api.twitch.tv/helix/channels"
        try:
            async with self.http_session.patch(
                url,
                headers=self._helix_headers(token, client_id),
                params={"broadcaster_id": broadcaster_id},
                json={"title": title},
            ) as response:
                if response.status in (200, 204):
                    if self.chat_logger:
                        self.chat_logger.info(f"[botofthespecter] stream title set to: {title}")
                    return
                body = await response.text()
                if self.chat_logger:
                    self.chat_logger.error(
                        f"[botofthespecter] stream title update HTTP {response.status}: {body[:300]}"
                    )
        except Exception as e:
            if self.chat_logger:
                self.chat_logger.error(f"[botofthespecter] stream title update failed: {e}")

