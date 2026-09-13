---
name: hosts
description: Specter VPS inventory — hostname, OS, public IP, private IP, RAM, disk. SSH uses public IPs.
metadata:
  node_type: memory
  type: project
---

All current boxes are **Ubuntu 26.04 LTS**. Operator SSH (`ssh bots`, `ssh api`, …) uses the **public** IP. The `10.240.0.0/24` addresses are the private LAN between these hosts.

| Hostname | Alias | Public IP | Private IP | RAM | Disk |
| -------- | ----- | --------- | ---------- | --- | ---- |
| `api.botofthespecter.com` | `api` | `43.229.61.72` | `10.240.0.8` | 1024 MB | 20 GB |
| `bots.botofthespecter.com` | `bots` | `43.229.60.77` | `10.240.0.6` | 4096 MB | 60 GB |
| `sql.botofthespecter.com` | `sql` | `150.107.75.252` | `10.240.0.7` | 4096 MB | 50 GB |
| `syd1.stream.botofthespecter.com` | `stream-syd` / `syd1` | `203.57.114.104` | `10.240.0.9` | 2048 MB | 1000 GB |
| `web1.botofthespecter.com` | `web` / `web1` | `66.226.145.231` | `10.240.0.4` | 2048 MB | 20 GB |
| `websocket.botofthespecter.com` | `websocket` | `66.226.147.137` | `10.240.0.5` | 2048 MB | 40 GB |

There is no backup SQL host. Stream ingest currently has one region: Sydney (`syd1`).
