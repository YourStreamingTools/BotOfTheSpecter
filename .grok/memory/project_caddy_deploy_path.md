---
name: project_caddy_deploy_path
description: "Caddy runs off /etc/caddy/Caddyfile, a SEPARATE copy from the repo's web/Caddyfile — deploying a change = server-side cp + validate + restart (user uploads the file via SFTP; NO git pull)"
metadata: 
  node_type: memory
  type: project
  originSessionId: b296958b-a11f-4dd5-901b-83ab303c166a
---

On the web host (web1) the repo lives under `/var/www/`, so repo paths map straight across: `dashboard/` → `/var/www/dashboard/`, `overlay/` → `/var/www/overlay/`, `lib/` → `/var/www/lib/`, `config/` → `/var/www/config/`, and `web/Caddyfile` → `/var/www/web/Caddyfile`. But Caddy **runs off `/etc/caddy/Caddyfile`**, which is a SEPARATE copy — the repo file is not what's live.

Deployment model (see [[feedback_no_commits]]): **the user uploads edited files manually via SFTP** (they control file perms) — NOT `git pull`, and never via a commit step from me. My job is only the server-side operational steps once the file is on the box:

1. `sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.bak` — back up the live config (it serves all 13 sites).
2. `sudo cp /var/www/web/Caddyfile /etc/caddy/Caddyfile` — copy the uploaded file into place.
3. `caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile` (source `/etc/caddy/caddy.env` first so `{env.X}` placeholders resolve). Don't proceed if it errors.
4. `sudo systemctl restart caddy` — **restart, not reload, whenever `caddy.env` also changed** (`{env.X}` is read at process start; a reload won't pick up new/changed env vars). See [[project_caddy_cf_token_env]].

Secrets/backend details stay in `/etc/caddy/caddy.env` (server-only) as `{env.X}` placeholders; the repo Caddyfile must stay provider-agnostic since it's public on GitHub.

**Why:** I kept hand-waving the deploy and even wrote `git pull`/commit steps, which is wrong on both counts — git and the SFTP upload are the user's; only the server-side copy/validate/restart is mine.

**How to apply:** Any task touching `web/Caddyfile` — give only the server-side steps above (copy `/var/www/web/Caddyfile` → `/etc/caddy/Caddyfile`, validate, restart), assuming the user has already uploaded the edited file. No git, no `git pull`. Context this arose: switching the 7 static asset hosts from s3fs-mount serving to `reverse_proxy` to the object-storage origin (mount served reads with no disk cache → buffered in RAM → 100% RAM crash).
