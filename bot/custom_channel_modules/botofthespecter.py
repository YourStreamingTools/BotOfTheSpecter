# Custom channel module for the 'botofthespecter' Twitch channel.
# Handles AI-driven chat responses that are exclusive to this channel.
# Called from bot file when the bot detects it is running in the botofthespecter channel.

import re
import json
from pathlib import Path

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
        api_logger.debug(f"Could not create bot-home history directory {bot_home_ai_history_dir}: {e}")
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
        api_logger.error(f"Failed to build bot-home user context for AI: {e}")
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
                api_logger.debug(f"Failed to read bot-home history for {history_key}: {e}")
        if isinstance(history, list) and history:
            for item in history[-12:]:
                if isinstance(item, dict) and 'role' in item and 'content' in item:
                    messages.append({'role': item['role'], 'content': item['content']})
    except Exception as e:
        api_logger.debug(f"Error loading bot-home history for {history_key}: {e}")
    messages.append({'role': 'user', 'content': user_message})
    try:
        api_logger.debug("Calling OpenAI chat completion from botofthespecter module")
        chat_client = getattr(openai_client, 'chat', None)
        ai_text = None
        resp = None
        if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
            resp = await chat_client.completions.create(model="gpt-5-nano", messages=messages)
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
            resp = await openai_client.chat_completions.create(model="gpt-5-nano", messages=messages)
            if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
            else:
                choices = getattr(resp, 'choices', None)
                if choices and len(choices) > 0:
                    ai_text = getattr(choices[0].message, 'content', None)
        else:
            api_logger.error("No compatible chat completions method found on openai_client")
            return "AI chat completions API is not available."
    except Exception as e:
        api_logger.error(f"Error calling chat completion API for bot-home mode: {e}")
        return "An error occurred while contacting the AI chat service."
    if not ai_text:
        api_logger.error(f"Bot-home chat completion returned no usable text: {resp}")
        return "The AI chat service returned an unexpected response."
    try:
        history = []
        if history_file.exists():
            try:
                with history_file.open('r', encoding='utf-8') as hf:
                    history = json.load(hf)
            except Exception as e:
                api_logger.debug(f"Failed to read existing bot-home history for append {history_key}: {e}")
        history.append({'role': 'user', 'content': user_message})
        history.append({'role': 'assistant', 'content': ai_text})
        if len(history) > 200:
            history = history[-200:]
        with history_file.open('w', encoding='utf-8') as hf:
            json.dump(history, hf, ensure_ascii=False, indent=2)
    except Exception as e:
        api_logger.debug(f"Error while persisting bot-home chat history for {history_key}: {e}")
    return ai_text
