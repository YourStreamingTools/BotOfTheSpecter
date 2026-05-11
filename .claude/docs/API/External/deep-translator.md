# deep-translator — Comprehensive Library Reference

**Package:** `deep-translator`  
**PyPI:** https://pypi.org/project/deep-translator/  
**Docs:** https://deep-translator.readthedocs.io/  
**Source:** https://github.com/nidhaloff/deep-translator  
**Latest stable:** 1.11.4 (2023-06-28)  
**Python:** >= 3.7, < 4.0  
**License:** Apache 2.0

`deep-translator` is a Python library that wraps multiple translation services behind a unified interface. It is **not** the official Google Cloud Translation API — the Google backend scrapes the public consumer endpoint, which is undocumented and ToS-constrained. Other backends (DeepL, Microsoft, Yandex, etc.) call their official APIs but require credentials.

---

## Installation

```bash
# Core (GoogleTranslator and most others)
pip install -U deep-translator

# Optional extras
pip install deep-translator[docx]   # DOCX file translation
pip install deep-translator[pdf]    # PDF file translation
pip install deep-translator[ai]     # ChatGptTranslator (pulls openai package)

# Poetry
poetry add deep-translator --extras "docx pdf ai"
```

---

## Public API Surface

All symbols importable from `deep_translator`:

```python
from deep_translator import (
    GoogleTranslator,
    MyMemoryTranslator,
    DeeplTranslator,
    LibreTranslator,
    LingueeTranslator,
    PonsTranslator,
    MicrosoftTranslator,
    ChatGptTranslator,
    BaiduTranslator,
    YandexTranslator,
    PapagoTranslator,
    QcriTranslator,
    TencentTranslator,
    single_detection,
    batch_detection,
)
```

---

## Common Interface

Every translator inherits from `BaseTranslator`. The abstract method is `translate()`; the rest are concrete helpers provided by the base class.

### Methods

| Method | Signature | Returns | Notes |
|--------|-----------|---------|-------|
| `translate` | `translate(text: str, **kwargs) -> str` | Translated string | Core method. Each translator must implement this. |
| `translate_batch` | `translate_batch(batch: list[str]) -> list[str]` | List of strings | Implemented in base class; calls `translate()` per item sequentially. Not parallelised. |
| `translate_file` | `translate_file(path: str) -> str` | Full translated text | Supports `.txt`, `.docx` (requires `[docx]` extra), `.pdf` (requires `[pdf]` extra). |
| `get_supported_languages` | `get_supported_languages(as_dict: bool = False)` | `list` or `dict` | Static method. `as_dict=True` returns `{"english": "en", ...}`. Each translator uses its own language set. |

### Language codes

All translators accept either a full name or an ISO code: `"english"` and `"en"` are both valid. Language sets differ per translator — call `get_supported_languages()` on the specific class to verify. Auto-detection is expressed as `source='auto'` where supported.

### Proxy support

Every translator that makes HTTP requests accepts an optional `proxies` dict:

```python
proxies = {"https": "http://IP:PORT", "http": "http://IP:PORT"}
translator = GoogleTranslator(source='auto', target='de', proxies=proxies)
```

### Async support

**There is no native async support.** All methods are synchronous. To use in an async context without blocking the event loop, wrap in `run_in_executor`:

```python
from asyncio import get_event_loop

result = await get_event_loop().run_in_executor(
    None, lambda: GoogleTranslator(source='auto', target='en').translate(text)
)
```

---

## Translator Classes

### GoogleTranslator

Scrapes the public Google Translate consumer endpoint (`translate.googleapis.com`). No API key required. **Not an official API.**

```python
GoogleTranslator(
    source: str = 'auto',
    target: str = 'en',
    proxies: Optional[dict] = None,
)
```

| Parameter | Description |
|-----------|-------------|
| `source` | Source language code/name, or `'auto'` for detection |
| `target` | Target language code/name |
| `proxies` | Optional proxy dict |

- **Authentication:** None
- **Auto-detect source:** Yes (`source='auto'`)
- **Language set:** 134 languages (`GOOGLE_LANGUAGES_TO_CODES`)
- **Character limit:** Not explicitly enforced in the library; Google's endpoint silently truncates or errors on very long inputs. Practical safe limit is ~5,000 characters per request.
- **Rate limits:** Undocumented. Google throttles by IP. Heavy use raises `TooManyRequests` or returns empty strings. There is no documented request-per-second or daily cap. Low-volume chat use (a few calls per stream) is generally safe.
- **ToS:** The consumer endpoint forbids automated scraping. Acceptable for low-volume use; do **not** use for bulk translation or dashboard enrichment.
- **Methods:** `translate`, `translate_batch`, `translate_file`, `get_supported_languages`

```python
from deep_translator import GoogleTranslator

text = GoogleTranslator(source='auto', target='de').translate("hello world")
batch = GoogleTranslator(source='en', target='fr').translate_batch(["hello", "goodbye"])
langs = GoogleTranslator.get_supported_languages(as_dict=True)  # {"english": "en", ...}
```

---

### MyMemoryTranslator

Calls the MyMemory public translation API (mymemory.translated.net).

```python
MyMemoryTranslator(
    source: str = 'auto',
    target: str = 'en',
    proxies: Optional[dict] = None,
    **kwargs,          # accepts email= for higher rate limits
)
```

| Parameter | Description |
|-----------|-------------|
| `source` | Source language code/name, or `'auto'` |
| `target` | Target language code/name |
| `proxies` | Optional proxy dict |
| `email` (kwarg) | Email address — increases the anonymous daily quota |

- **Authentication:** None (anonymous). Pass `email=` kwarg to raise anonymous quota.
- **Auto-detect source:** Yes, but documented as "not as powerful as Google Translate"
- **Language set:** 500+ regional variants (`MY_MEMORY_LANGUAGES_TO_CODES`)
- **Character limit:** **500 characters per request** (enforced by library via `is_input_valid(text, max_chars=500)`)
- **Rate limits:** Anonymous: limited daily quota. Raises `TooManyRequests` on HTTP 429. Adding an email address increases the allowance.
- **Methods:** `translate`, `translate_batch`, `translate_file`, `get_supported_languages`

```python
from deep_translator import MyMemoryTranslator

text = MyMemoryTranslator(source='en', target='fr').translate("hello")
# With email for higher quota:
text = MyMemoryTranslator(source='auto', target='en', email='you@example.com').translate("bonjour")
```

---

### DeeplTranslator

Calls the official DeepL API (free or pro tier).

```python
DeeplTranslator(
    api_key: str,
    source: str = 'en',
    target: str = 'en',
    use_free_api: bool = True,
)
```

| Parameter | Description |
|-----------|-------------|
| `api_key` | DeepL API key — required |
| `source` | Source language code/name |
| `target` | Target language code/name |
| `use_free_api` | `True` = free-tier endpoint; `False` = pro endpoint |

- **Authentication:** API key required. Get one at https://www.deepl.com/en/docs-api/. Set env var `DEEPL_API_KEY` or pass directly.
- **Auto-detect source:** Not explicitly supported via `'auto'`; omit source or check DeepL docs for source omission behavior.
- **Language set:** 27 languages (`DEEPL_LANGUAGE_TO_CODE`)
- **Character limits:** Free tier: 500,000 characters/month. Pro: pay-per-character.
- **Rate limits:** Per DeepL API documentation.
- **Methods:** `translate`, `translate_batch`

```python
import os
from deep_translator import DeeplTranslator

t = DeeplTranslator(api_key=os.getenv("DEEPL_API_KEY"), source="en", target="de", use_free_api=True)
result = t.translate("hello world")
```

---

### LibreTranslator

Calls a LibreTranslate instance (open-source, self-hostable or public mirrors).

```python
LibreTranslator(
    source: str = 'auto',
    target: str = 'en',
    base_url: str = 'https://libretranslate.com/',
    api_key: Optional[str] = None,
)
```

| Parameter | Description |
|-----------|-------------|
| `source` | Source language code/name, or `'auto'` |
| `target` | Target language code/name |
| `base_url` | LibreTranslate instance URL; swap to a self-hosted or alternative mirror |
| `api_key` | Optional; required by some mirrors/instances |

- **Authentication:** Optional — depends on the mirror. Public libretranslate.com requires an API key. Set env var `LIBRE_API_KEY`.
- **Auto-detect source:** Yes (`source='auto'`)
- **Language set:** 17 languages (`LIBRE_LANGUAGES_TO_CODES`)
- **Character limits:** Depends on the instance configuration.
- **Rate limits:** Depends on the instance.
- **Methods:** `translate`, `translate_batch`, `translate_file`, `get_supported_languages`

```python
from deep_translator import LibreTranslator

t = LibreTranslator(source='auto', target='en', base_url='https://libretranslate.com/', api_key='your_key')
result = t.translate("bonjour")
```

---

### MicrosoftTranslator

Calls the Microsoft Azure Cognitive Services Translator API.

```python
MicrosoftTranslator(
    api_key: str,
    source: Optional[str] = None,
    target: str = 'en',
    **kwargs,   # additional Azure parameters (region, endpoint, etc.)
)
```

| Parameter | Description |
|-----------|-------------|
| `api_key` | Azure Cognitive Services API key — required |
| `source` | Source language; omit or pass `None` for auto-detection |
| `target` | Target language code/name, or a list of language codes for multi-target |

- **Authentication:** Azure API key required. Set env var `MICROSOFT_API_KEY`.
- **Auto-detect source:** Yes — omit `source` or pass `None`
- **Language set:** Broad Microsoft Translator support
- **Character limits:** Free tier: **2,000,000 characters/month**
- **Rate limits:** Per Azure Cognitive Services quotas.
- **Methods:** `translate`, `translate_batch`, `translate_file`, `get_supported_languages`
- **Multi-target:** `target` can be a list: `target=['de', 'fr']`

```python
import os
from deep_translator import MicrosoftTranslator

t = MicrosoftTranslator(api_key=os.getenv("MICROSOFT_API_KEY"), source='en', target='de')
result = t.translate("hello")
```

---

### YandexTranslator

Calls the Yandex Translate API.

```python
YandexTranslator(
    api_key: str,
    source: str = 'auto',
    target: str = 'en',
)
```

| Parameter | Description |
|-----------|-------------|
| `api_key` | Yandex API key — required |
| `source` | Source language code/name, or `'auto'` |
| `target` | Target language code/name |

- **Authentication:** Private API key required. Set env var `YANDEX_API_KEY`.
- **Auto-detect source:** Yes (`source='auto'`); also exposes `detect(text)` method
- **Language set:** Broad Yandex support
- **Character limits:** Per Yandex API plan
- **Rate limits:** Per Yandex API quotas
- **Extra method:** `detect(text: str) -> str` — returns the detected language code
- **Methods:** `translate`, `translate_batch`, `translate_file`, `detect`, `get_supported_languages`

```python
import os
from deep_translator import YandexTranslator

t = YandexTranslator(api_key=os.getenv("YANDEX_API_KEY"), source='auto', target='en')
lang = t.detect("bonjour le monde")   # returns 'fr'
result = t.translate("bonjour")
```

---

### ChatGptTranslator

Uses the OpenAI Chat Completions API for translation.

```python
ChatGptTranslator(
    api_key: str,
    source: Optional[str] = None,
    target: str = 'en',
)
```

| Parameter | Description |
|-----------|-------------|
| `api_key` | OpenAI API key — required |
| `source` | Source language; optional for auto-detection |
| `target` | Target language code/name |

- **Authentication:** OpenAI API key required. Set env var `OPEN_API_KEY` (note: library uses `OPEN_API_KEY`, not `OPENAI_API_KEY`).
- **Extra install required:** `pip install deep-translator[ai]`
- **Auto-detect source:** Yes — omit source
- **Character limits:** Per OpenAI model context window; token-based billing
- **Rate limits:** Per OpenAI tier limits
- **Methods:** `translate`, `translate_batch`, `translate_file`

```python
import os
from deep_translator import ChatGptTranslator

t = ChatGptTranslator(api_key=os.getenv("OPEN_API_KEY"), target='de')
result = t.translate("hello world")
```

**Note for BotOfTheSpecter:** The bot already has `OPENAI_KEY` in the environment. If wiring this up, check whether the library reads `OPEN_API_KEY` or accepts the key directly.

---

### BaiduTranslator

Calls the Baidu Translate API.

```python
BaiduTranslator(
    appid: str,
    appkey: str,
    source: str = 'en',
    target: str = 'zh',
)
```

| Parameter | Description |
|-----------|-------------|
| `appid` | Baidu App ID — required. Set env var `BAIDU_APPID`. |
| `appkey` | Baidu App Key — required. Set env var `BAIDU_APPKEY`. |
| `source` | Source language code/name |
| `target` | Target language code/name |

- **Authentication:** Baidu App ID and App Key required
- **Auto-detect source:** Not documented
- **Language set:** 23 languages (`BAIDU_LANGUAGE_TO_CODE`)
- **Character limits:** Per Baidu API plan
- **Rate limits:** Per Baidu API quotas. `BaiduAPIerror` raised on API-level errors.
- **Methods:** `translate`, `translate_batch`, `translate_file`

```python
import os
from deep_translator import BaiduTranslator

t = BaiduTranslator(appid=os.getenv("BAIDU_APPID"), appkey=os.getenv("BAIDU_APPKEY"), source='en', target='zh')
result = t.translate("hello world")
```

---

### PapagoTranslator

Calls Naver's Papago translation service. Best for Korean, Japanese, Chinese.

```python
PapagoTranslator(
    client_id: str,
    secret_key: str,
    source: str = 'en',
    target: str = 'ko',
)
```

| Parameter | Description |
|-----------|-------------|
| `client_id` | Naver Developer client ID — required |
| `secret_key` | Naver Developer secret key — required |
| `source` | Source language code/name |
| `target` | Target language code/name |

- **Authentication:** Naver Developer client ID + secret required
- **Auto-detect source:** Not documented
- **Language set:** 10 language pairs (`PAPAGO_LANGUAGE_TO_CODE`): ko, en, ja, zh-cn, zh-tw, es, fr, de, ru, pt, it, vi, th, id, ar, hi
- **Character limits:** Per Naver API plan
- **Rate limits:** Per Naver API quotas
- **Methods:** `translate`

```python
from deep_translator import PapagoTranslator

t = PapagoTranslator(client_id='your_id', secret_key='your_secret', source='en', target='ko')
result = t.translate("hello world")
```

---

### LingueeTranslator

Scrapes the Linguee dictionary website. Designed for **word/phrase lookup**, not full-sentence translation. Returns dictionary entries and synonyms.

```python
LingueeTranslator(
    source: str = 'en',
    target: str = 'de',
    proxies: Optional[dict] = None,
)
```

| Parameter | Description |
|-----------|-------------|
| `source` | Source language code/name |
| `target` | Target language code/name |

- **Authentication:** None
- **Auto-detect source:** Not supported
- **Language set:** 18 languages (`LINGUEE_LANGUAGES_TO_CODES`)
- **Character limits:** Single words or short phrases only; full sentences may return empty results
- **Rate limits:** Scraping-based; throttle if abused
- **Special:** `return_all=True` returns all synonyms/alternatives found
- **Extra method:** `translate_words(words_list)` for batch word lookup
- **Methods:** `translate(word, return_all=False)`, `translate_words(words_list)`

```python
from deep_translator import LingueeTranslator

t = LingueeTranslator(source='en', target='de')
result = t.translate("good")               # returns primary translation
synonyms = t.translate("good", return_all=True)  # returns list of all found translations
```

**Caution:** LingueeTranslator does not call an official API — it scrapes the website. If Linguee changes their HTML structure, the translator breaks silently or raises `ElementNotFoundInGetRequest`.

---

### PonsTranslator

Scrapes the PONS dictionary website. Similar to LingueeTranslator — suited for word/phrase lookup, not full sentences.

```python
PonsTranslator(
    source: str = 'en',
    target: str = 'de',
    proxies: Optional[dict] = None,
)
```

| Parameter | Description |
|-----------|-------------|
| `source` | Source language code/name |
| `target` | Target language code/name |

- **Authentication:** None
- **Auto-detect source:** Not supported
- **Language set:** 20 language pairs (`PONS_CODES_TO_LANGUAGES`)
- **Character limits:** Words and short phrases only
- **Rate limits:** Scraping-based
- **Special:** `return_all=True` returns all found translations
- **Extra method:** `translate_words(words_list)` for batch word lookup
- **Methods:** `translate(word, return_all=False)`, `translate_words(words_list)`

```python
from deep_translator import PonsTranslator

t = PonsTranslator(source='en', target='fr')
result = t.translate("house")
```

---

### QcriTranslator

Calls the QCRI (Qatar Computing Research Institute) machine translation API.

```python
QcriTranslator(
    api_key: str,
    source: str = 'en',
    target: str = 'ar',
    domain: Optional[str] = None,
)
```

| Parameter | Description |
|-----------|-------------|
| `api_key` | QCRI API key — required (free). Set env var `QCRI_API_KEY`. |
| `source` | Source language code/name |
| `target` | Target language code/name |
| `domain` | Translation domain (e.g. `'general'`, `'news'`, `'ummah'`) — required |

- **Authentication:** Free API key from https://mt.qcri.org/api/
- **Auto-detect source:** Not supported
- **Language set:** Arabic, English, Spanish (`QCRI_LANGUAGE_TO_CODE`)
- **Properties:** `.languages` (list of languages), `.domains` (list of available domains)
- **Methods:** `translate`

```python
from deep_translator import QcriTranslator

t = QcriTranslator(api_key='your_key', source='en', target='ar', domain='general')
print(t.languages)   # supported languages
print(t.domains)     # available domains
result = t.translate("hello world")
```

---

### TencentTranslator

Calls the Tencent Cloud Machine Translation API.

```python
TencentTranslator(
    secret_id: str,
    secret_key: str,
    source: str = 'en',
    target: str = 'zh',
)
```

| Parameter | Description |
|-----------|-------------|
| `secret_id` | Tencent Cloud secret ID — required. Set env var `TENCENT_SECRET_ID`. |
| `secret_key` | Tencent Cloud secret key — required. Set env var `TENCENT_SECRET_KEY`. |
| `source` | Source language code/name |
| `target` | Target language code/name |

- **Authentication:** Tencent Cloud credentials required
- **Auto-detect source:** Not documented
- **Language set:** 17 languages (`TENCENT_LANGUAGE_TO_CODE`)
- **Rate limits:** Per Tencent Cloud quotas. `TencentAPIerror` raised on API-level errors.
- **Methods:** `translate`, `translate_batch`, `translate_file`

---

## Exception Reference

All exceptions are importable from `deep_translator.exceptions`. The base hierarchy:

```
Exception
├── BaseError (deep_translator.exceptions.BaseError)
│   ├── LanguageNotSupportedException
│   ├── NotValidPayload
│   ├── InvalidSourceOrTargetLanguage
│   ├── TranslationNotFound
│   ├── ElementNotFoundInGetRequest
│   ├── NotValidLength
│   └── ApiKeyException
├── RequestError
├── TooManyRequests
├── ServerException
├── AuthorizationException
├── MicrosoftAPIerror
├── TencentAPIerror
└── BaiduAPIerror
```

| Exception | When raised |
|-----------|-------------|
| `LanguageNotSupportedException` | The `source` or `target` language code/name is not in the translator's supported set |
| `NotValidPayload` | Input text is invalid — empty string, non-string type, or otherwise unparseable |
| `InvalidSourceOrTargetLanguage` | Source and target language are the same, or one is missing |
| `TranslationNotFound` | The request succeeded but no translation was returned in the response |
| `ElementNotFoundInGetRequest` | Expected HTML element missing from a scraped response (LingueeTranslator, PonsTranslator) |
| `NotValidLength` | Text length is outside the min/max bounds for the translator (e.g. MyMemoryTranslator enforces max 500 chars) |
| `ApiKeyException` | Required API key parameter was not provided |
| `AuthorizationException` | API key was provided but rejected by the service (HTTP 401/403) |
| `RequestError` | HTTP request failed — connection error, timeout, or unexpected HTTP error |
| `TooManyRequests` | Rate limit hit (HTTP 429). GoogleTranslator and MyMemoryTranslator both raise this. |
| `ServerException` | Server returned an error status code (used by YandexTranslator) |
| `MicrosoftAPIerror` | Microsoft Translator returned an error payload |
| `TencentAPIerror` | Tencent API returned an error payload |
| `BaiduAPIerror` | Baidu API returned an error payload |

**Catch pattern for chat bots:**

```python
from deep_translator.exceptions import (
    TooManyRequests, NotValidLength, NotValidPayload,
    LanguageNotSupportedException, TranslationNotFound,
)

try:
    result = GoogleTranslator(source='auto', target='en').translate(text)
except TooManyRequests:
    await send_chat_message("Translation service is busy, try again later.")
except (NotValidPayload, NotValidLength):
    await send_chat_message("Message too short or invalid for translation.")
except LanguageNotSupportedException:
    await send_chat_message("That language isn't supported.")
except TranslationNotFound:
    await send_chat_message("Couldn't find a translation for that text.")
except Exception as e:
    chat_logger.error(f"translate error: {e}")
    await send_chat_message("Translation failed.")
```

---

## Character and Length Limits Summary

| Translator | Enforced limit | Notes |
|------------|---------------|-------|
| GoogleTranslator | ~5,000 chars (practical) | Not library-enforced; Google endpoint silently fails on very long input |
| MyMemoryTranslator | **500 chars** (library-enforced) | Hard coded in `is_input_valid()` call |
| DeeplTranslator | 500,000 chars/month (free tier) | Per DeepL plan; per-request limit undocumented in library |
| MicrosoftTranslator | 2,000,000 chars/month (free tier) | Per Azure plan |
| LibreTranslator | Instance-dependent | Configure on the LibreTranslate host |
| LingueeTranslator | Short phrases only | Words/phrases; long text returns empty |
| PonsTranslator | Short phrases only | Words/phrases; long text returns empty |
| YandexTranslator | Per Yandex plan | |
| ChatGptTranslator | Model context window | Token-based billing |
| BaiduTranslator | Per Baidu plan | |
| PapagoTranslator | Per Naver plan | |
| QcriTranslator | Limited language set (en/ar/es) | |
| TencentTranslator | Per Tencent plan | |

---

## Rate Limit Notes

| Translator | Rate behavior |
|------------|--------------|
| GoogleTranslator | Undocumented. Google throttles by source IP. `TooManyRequests` or empty string returned when throttled. No safe public figure. Low-volume chat use is generally stable. |
| MyMemoryTranslator | Anonymous: limited daily quota. Pass `email=` kwarg to increase it. HTTP 429 → `TooManyRequests`. |
| DeeplTranslator | Per DeepL plan. Free tier: 500k chars/month total; no documented per-second limit. |
| MicrosoftTranslator | Per Azure plan. |
| YandexTranslator | Per Yandex plan. `ServerException` on HTTP errors. |
| LingueeTranslator / PonsTranslator | Scraping-based. No documented limit; abuse triggers blocks. |
| Others | Per their respective API plans. |

---

## Language Detection (Standalone)

Requires a separate API key from **detectlanguage.com** (free tier available).

```python
from deep_translator import single_detection, batch_detection

# Detect one text
lang = single_detection('bonjour la vie', api_key='your_detectlanguage_key')
# Returns: 'fr'

# Detect multiple texts
langs = batch_detection(['bonjour la vie', 'hello world'], api_key='your_detectlanguage_key')
# Returns: ['fr', 'en']
```

Note: `YandexTranslator` has its own built-in `detect()` method that does not require a separate key.

---

## CLI Usage

```bash
# Full names
deep-translator google --source "english" --target "german" --text "happy coding"

# Short flags
deep-translator -trans "google" -src "en" -tg "de" -txt "happy coding"

# List available translators
deep-translator list

# List supported languages for a translator
deep-translator languages google
```

---

## BotOfTheSpecter Callsites

The project currently uses only `GoogleTranslator`, exclusively for the `!translate` chat command.

### Imports

| File | Line | Import |
|------|------|--------|
| `./bot/bot.py` | 30 | `from deep_translator import GoogleTranslator as translator` |
| `./bot/beta.py` | 33 | `from deep_translator import GoogleTranslator as translator` |
| `./bot/beta-v6.py` | 30 | `from deep_translator import GoogleTranslator as translator` |
| `./bot/kick.py` | 25 | `from deep_translator import GoogleTranslator as translator` |
| `./bot/requirements.txt` | 52 | `deep_translator` (unpinned) |
| `./bot/beta_requirements.txt` | 49 | `deep_translator` (unpinned) |

### Call pattern

All four bots use the same single call:

```python
translator(source='auto', target='en').translate(text=message)
```

Translation is always to English. No target language is configurable by the user.

### Async behaviour (important)

| File | Pattern | Issue |
|------|---------|-------|
| `./bot/kick.py:1059–1061` | `await get_event_loop().run_in_executor(None, lambda: ...)` | Correct — non-blocking |
| `./bot/bot.py:4426` | Inline `.translate()` in async handler | Blocks event loop 200–500 ms |
| `./bot/beta.py:6495` | Inline `.translate()` in async handler | Blocks event loop 200–500 ms |
| `./bot/beta-v6.py:5492` | Inline `.translate()` in async handler | Blocks event loop 200–500 ms |

The three Twitch bot files should be refactored to use `run_in_executor` as kick.py already does.

### Minimum length guard

All four bots enforce a 5-character minimum before calling translate:

```python
if len(message.strip()) < 5:
    await send_chat_message("The provided message is too short for reliable translation.")
    return
```

Keep this guard — it prevents wasted calls and avoids `NotValidPayload` exceptions on empty/whitespace input.

### Exception handling (current state)

All three Twitch bots catch `AttributeError` and the bare `Exception` but do **not** catch `TooManyRequests`, `NotValidLength`, or `TranslationNotFound` specifically. If Google throttles the IP, the bare `Exception` catch surfaces the raw error to chat. The kick.py handler also only catches bare `Exception`. Tightening the exception handling to use the specific types listed in the Exceptions section above would improve the user-facing error messages.

### ToS note

The bot uses the scraped Google Translate consumer endpoint, not the official Google Cloud Translation API. This is acceptable for low-volume chat use (a few commands per stream). Do **not** expand `deep_translator` usage to batch enrichment, dashboard features, or any bulk processing. For higher-volume needs, migrate to the official Google Cloud Translation API, DeepL, or Microsoft Translator.
