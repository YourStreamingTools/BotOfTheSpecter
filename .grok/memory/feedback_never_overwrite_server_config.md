---
name: Never overwrite production PHP config files
description: Patch /var/www/config/*.php in place on the server. Never scp or copy a repo config file over production — those files hold live API keys and passwords.
type: feedback
---
Never copy repo `./config/*.php` onto `/var/www/config/` on web1 (or any production host). Production config files contain live API keys and passwords. The repo copies are empty placeholders.

**Why:** The user called this out after a Sydney stream deploy. Overwriting `megas4.php`, `ssh.php`, `twitch.php`, `database.php`, etc. would wipe credentials. Even a “harmless” full-file scp is wrong if the live file might have extra keys.

**How to apply:**
- **Repo:** edit `./config/{service}.php` as the empty/template version (URLs, variable names, comments).
- **Server:** SSH in and **patch the existing file in place** (search-replace a host, insert a store entry, append a non-secret URL). Do not `scp` the whole file.
- New production config file: create it on the server by writing only the public keys first, then tell the user which secret lines to fill, or copy an existing live file and edit. Never upload a blank secret file over a filled one.
- `stream.php` on web1 currently holds only public URLs (no secrets). Still patch it in place rather than overwriting, so extra live keys cannot be lost.
- Python `.env` on stream/bot/api hosts is the same idea: append or edit keys, do not replace the whole file from the repo.
