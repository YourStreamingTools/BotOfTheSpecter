---
name: project_cloudflare_zone_owner
description: The botofthespecter.com Cloudflare zone lives on the LochStudios account (two LochStudios CF accounts exist — confirm which)
metadata: 
  node_type: memory
  type: project
  originSessionId: 0d52c458-9e3e-4049-97ca-a1cf4e23670e
---

The `botofthespecter.com` Cloudflare zone is held on the **LochStudios** Cloudflare account — the same account whose DNS-01 API token Caddy uses (see [[project_caddy_cf_token_env]]). This is the account to deploy any Worker / proxied record / DNS change for the domain onto.

Caveat: there are **two** LochStudios CF accounts visible to the session — `LochStudios` (8d74221154fa29a1aaa1ae28a70fce96) and `LochStudios Websites` (7cd05992321508f5df58013909ae8ea3). Confirm which one actually owns the zone before deploying.

**Why:** The session can see ~10 Cloudflare accounts; without this, the right target for botofthespecter.com infra changes is a guess (GamingForAustralia looks plausible but is wrong).

**How to apply:** For any Cloudflare change to botofthespecter.com (Workers, proxied records, page rules, etc.), target the LochStudios account. Relevant to the GeoIP CDN routing design ([[project_network_architecture]]).
