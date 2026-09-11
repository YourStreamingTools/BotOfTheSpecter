---
name: grok-ssh-key
description: Dedicated OpenSSH ed25519 key for operator SSH to Specter hosts. Connect by IP. Public key only in this note.
metadata:
  node_type: memory
  type: project
---

Operator SSH to Specter hosts uses a dedicated Ed25519 key (`grok@botofthespecter`). Connect **by IP**, not hostname. Use it for logs, systemd, and file checks — **not** bot start/stop/status, which stay on the bots HTTP API.

## Key

The private key lives only in `~/.ssh/` on the operator machine. It is never stored in the repo.

| | |
| --- | --- |
| Files | `~/.ssh/id_ed25519_grok` and `~/.ssh/id_ed25519_grok.pub` |
| Config | `~/.ssh/config` (aliases below; `HostName` is the IP) |
| Comment | `grok@botofthespecter` |
| Fingerprint | `SHA256:Zex0nsWfcTEPbkRZqDQb84K2eQW/vlFNVN5yTJ1wXgM` |
| Remote user | `root` |
| Passphrase | none |

Public key (also in `~/.ssh/authorized_keys` on each host below):

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHVaOyxzkYXGLu+cSi3TK+pmAvMZm1OYd8YY2vpI/Pis grok@botofthespecter
```

## Hosts

SSH goes to the IP. Aliases (`ssh bots`, `ssh api`, …) still work; they resolve in config to the address below. `IdentitiesOnly yes` is set so ssh does not try other identities.

| Alias | IP | Role |
| ----- | -- | ---- |
| `bots` | `43.229.60.77` | bot host |
| `api` | `43.229.61.72` | API |
| `websocket` | `66.226.147.137` | WebSocket |
| `sql` | `150.107.75.252` | database |
| `web` / `web1` | `66.226.145.231` | web |

Stream ingest IPs are not in this config. `*.botofthespecter.video` currently does not resolve, and those services are disabled in `./dashboard/api/api_status.php`.
