# Dashboard Light Mode — Plan

> Status: decisions locked (see §9); ready to build. Scope for this pass: dashboard, home, support, members.
> Last revised 2026-05-28.

## 1. Scope

**In scope**
- `dashboard.botofthespecter.com` — `dashboard/css/dashboard.css` (~3,346 lines)
- Admin pages on the dashboard — `dashboard/css/admin.css` (~525 lines, additive on top of dashboard.css)

**Explicitly out of scope** (separate decisions, can be tackled later):
- Overlays (`overlay/index.css`) — transparent OBS sources, light mode is N/A
- YourChat (`yourchat/style.css`) — gradient/glassmorphism brand, lives in its own aesthetic
- Twitch Extension (`extension/panel.html`, `config.html`) — inlined tokens, sandboxed iframe
- Members / Support / Roadmap / SpecterBotApp / SpecterBotSystems / YourLinks portals — each has its own forked CSS sharing the same `sp-*` system. Worth doing eventually but not bundled into this pass.

The token model designed here is intended to be **portable** — when we later want light mode on the portal forks, the same `[data-theme="light"]` override block can be copy-pasted into each portal's stylesheet with minimal adjustment.

## 2. Mechanism

Switch from "tokens defined once in `:root`" to "tokens defined in `:root` (dark, default) and overridden by `[data-theme="light"]` on `<html>`". One-attribute toggle, no class proliferation, no per-component `if-light` rules in JS.

```css
:root {
    --bg-base: #0d0d0f;
    --text-primary: #e8e8f0;
    /* ...rest of dark palette... */
}

[data-theme="light"] {
    --bg-base: #f5f5f8;
    --text-primary: #1a1a20;
    /* ...rest of light palette... */
}
```

Every existing component that uses `var(--bg-base)` etc. automatically follows. Components don't need to be touched in 95% of cases — only the ones with hardcoded colours (see section 6).

## 3. Token mapping

Backgrounds and primary text invert. Status colours keep their hue but their `*-bg` tints need different alpha to read on a light surface (12% amber on dark is muted; on white it disappears).

| Token | Dark (current) | Light (proposed) | Notes |
|---|---|---|---|
| `--bg-base` | `#0d0d0f` | `#f5f5f8` | Page background |
| `--bg-surface` | `#141418` | `#ffffff` | Sidebar / topbar |
| `--bg-card` | `#1a1a20` | `#ffffff` | Cards |
| `--bg-card-hover` | `#1f1f27` | `#f0f0f4` | Hover state |
| `--bg-input` | `#16161c` | `#ffffff` | Form inputs |
| `--text-primary` | `#e8e8f0` | `#1a1a20` | Body text |
| `--text-secondary` | `#a8a8bc` | `#5a5a6e` | Labels, sub-text |
| `--text-muted` | `#6c6c84` | `#9090a0` | Helper text |
| `--border` | `rgba(255,255,255,0.07)` | `rgba(0,0,0,0.08)` | Card edges |
| `--border-hover` | `rgba(255,255,255,0.14)` | `rgba(0,0,0,0.16)` | Hover edges |
| `--accent` | `#7c5cbf` | `#6b48b0` | Slightly darker for AA contrast on white |
| `--accent-hover` | `#9070d8` | `#5a3a98` | |
| `--accent-light` | `rgba(124,92,191,0.12)` | `rgba(124,92,191,0.10)` | |
| `--accent-glow` | `rgba(124,92,191,0.25)` | `rgba(124,92,191,0.20)` | |
| `--green` | `#3ecf8e` | `#1f9d6b` | Darker for AA on white |
| `--green-bg` | `rgba(62,207,142,0.12)` | `rgba(62,207,142,0.18)` | Denser tint |
| `--blue` | `#5cb8ff` | `#2080d6` | Darker for AA on white |
| `--blue-bg` | `rgba(92,184,255,0.12)` | `rgba(92,184,255,0.18)` | |
| `--amber` | `#fbbf24` | `#b67200` | Much darker — pure amber is unreadable on white |
| `--amber-bg` | `rgba(251,191,36,0.12)` | `rgba(251,191,36,0.20)` | |
| `--red` | `#f87171` | `#c84040` | Darker for AA |
| `--red-bg` | `rgba(248,113,113,0.12)` | `rgba(248,113,113,0.18)` | |
| `--grey` | `#6c6c84` | `#7a7a8a` | Subtle adjustment |
| `--grey-bg` | `rgba(108,108,132,0.12)` | `rgba(108,108,132,0.10)` | |

These values are a starting point — every one needs a contrast check (WCAG AA = 4.5:1 for body text, 3:1 for large text and UI elements). Tools like https://webaim.org/resources/contrastchecker/ for spot checks.

## 4. Persistence

Three options:

| Option | Pros | Cons |
|---|---|---|
| **localStorage** | No DB writes, instant toggle | Per-device — different browser/computer = different theme. Risk of first-paint flash unless inlined in `<head>`. |
| **Session-only (`$_SESSION['theme']`)** | Server-rendered, no flash | Resets on logout / cookie clear. Doesn't follow the user across devices. |
| **Per-user (`profile.theme` column)** | Follows the user everywhere they sign in | Requires schema change + a write on toggle. Slight extra latency on first paint vs localStorage. |

**Recommendation: localStorage** with a tiny inline `<script>` in `<head>` (before the `<link rel="stylesheet">`) that reads the stored value and sets `document.documentElement.dataset.theme`. Zero DB churn, no flash, simplest implementation.

If "follows the user across devices" turns out to matter later, add the `profile.theme` column and sync the two on toggle and on login.

## 5. UI control

A single toggle in the topbar (next to the bot status / user menu). Sun/moon icon. Click → flips `document.documentElement.dataset.theme` between `"light"` and `""` (empty = default dark) + writes to localStorage.

Optionally: on first visit (no localStorage value), respect `@media (prefers-color-scheme: light)` and set initial state accordingly. Easy to add inside the inline `<head>` bootstrap script.

## 6. Cleanup work (the boring but necessary part)

Tokens-only swap won't cover everything. Places where the current code hardcodes colours need to be fixed first or they'll look broken in light mode.

1. **Hardcoded hex / rgb in dashboard.css** outside `:root` — there are some (e.g., shadows like `rgba(0,0,0,0.3)`, brand purples in landing-page sections, sweet-alert overrides). Inventory pass: grep for `#[0-9a-fA-F]` and `rgba\(` outside `:root` blocks, decide for each whether to (a) tokenize, (b) override per-theme, or (c) leave alone if it's an intentional brand colour.

2. **Hardcoded colours in `admin.css`** — uses legacy Bulma blues `#3273dc` and greys `#7a7a7a` for admin highlights. Decide per case.

3. **Inline `style="color:#..."`** in PHP/HTML across the dashboard — several pages do this for dynamic colouring (e.g., `bot.php` for service status icons, several `data-specter-*` styles). Need to either tokenise these (use a class) or accept that they'll need per-theme inline values.

4. **SweetAlert2 dark overrides** — the existing `.swal2-popup` styling for dark mode needs a light counterpart, or the overrides need to be wrapped in `[data-theme="light"]`-aware rules.

5. **Bulma alias layer** — the `.button.is-*`, `.notification.is-*`, `.tag.is-*` classes already delegate to `--*-bg` / `--*` tokens, so should "just work" once tokens are updated.

6. **Status pages and home alias layer** — small surface but worth checking for any unexpected hardcoded values.

This cleanup is the largest chunk of the work, probably **60% of total time**. The rest is mechanical.

## 7. Phase plan

1. **Token mapping draft** — write the `[data-theme="light"]` override block, contrast-check the proposed values against WCAG AA, finalise the palette. Output: a single block to paste into the top of `dashboard.css`. *(No visible UI change yet.)*

2. **Inline theme bootstrap** — add the `<head>` script that reads localStorage and sets `data-theme` before the CSS link. Place in `layout.php` so every page picks it up. *(Still no UI control; theme can be set via devtools or localStorage.)*

3. **Cleanup pass** — fix hardcoded colours (section 6), inline styles, SweetAlert overrides. Audit every page in light mode via devtools forcing the attribute.

4. **Toggle UI** — add the topbar button + JS that flips the attribute and saves to localStorage. Optionally add `prefers-color-scheme` initial detection.

5. **QA pass** — walk the 50+ dashboard pages with the theme set to light, fix anything that broke. Test edge cases: forms, modals, tables, alerts, admin pages.

6. *(Optional, separate)* **Mirror to portal forks** — copy the override block + cleanup to `members/style.css`, `support/css/style.css`, `roadmap/css/style.css`, `specterbotapp/home/css/custom.css`, `specterbotsystems/css/style.css`, `yourlinks.click/.../site.css`.

## 8. Risks & edge cases

- **First-paint flash** — mitigated by inlining the bootstrap script in `<head>` before the stylesheet link. Don't move it.
- **Browser without localStorage** (incognito with strict settings, ancient browsers) — falls back to default dark, no error.
- **Mid-session theme change in another tab** — listen for the `storage` event on `window` and re-apply the attribute in all open tabs. Trivial to add.
- **Third-party widgets** (Toastify, SweetAlert) — already need overrides for dark; ensure both themes are covered.
- **Charts / data viz** — if any exist (the dashboard uses chart libraries somewhere?), their default palettes need light-aware variants.
- **Images and icons** — most icons are Font Awesome which respect `color`. Any raster images used in headers/illustrations may need light-mode variants.

## 9. Decisions (locked 2026-05-28)

1. **Default mode for new users** (no localStorage value) — Auto: respect the OS `prefers-color-scheme`, falling back to dark when no JS runs.
2. **Persistence** — localStorage, key `sp-theme`. It's per-origin, so each subdomain stores its own preference (see follow-ups).
3. **Scope of this pass** (narrower than the earlier "all portals" idea) — dashboard + admin, home, support, members. Roadmap, specterbotapp, specterbotsystems, and yourlinks are deferred to a later pass.
4. **Toggle placement** — the topbar / top nav, plus the landing top nav for the logged-out dashboard.

## 10. Implementation approach for this pass

**Mechanism.** Each surface gets a `[data-theme="light"]` override block added alongside its `:root`, with `color-scheme` declared per theme. An inline `<head>` bootstrap reads `localStorage['sp-theme']` (falling back to the OS preference) and sets `data-theme` before the stylesheets paint, so there's no flash of the wrong theme. A topbar button (`#spThemeToggle`) flips the attribute, persists the choice to localStorage, and keeps other open tabs in sync via the `storage` event.

**Stylesheets that carry the light tokens** (plus the `.sp-theme-toggle` / `.hs-theme-toggle` control styles):
- `dashboard/css/dashboard.css`
- `support/css/style.css`
- `members/style.css`
- `home/style.css` — also tokenise the one hardcoded `a:hover` colour (`#b89fe8` → `var(--accent-hover)`)

**Palette adjustment vs §3.** The proposed `--text-muted` of `#9090a0` only reaches roughly 3:1 on white, which fails AA for body text, so it needs to be darker — around `#6e6e84`.

**Pages to wire:**
- Dashboard: `layout.php` (covers all authed pages) and the `dashboard.php` logged-out landing get the full toggle; `login.php` and `restricted.php` get the bootstrap only, so they render in-theme without an on-page button.
- Home: `layout.php` (covers all home pages).
- Support: `layout.php` (covers all support pages).
- Members (no shared layout, so per-page): `index.php` and `freegames.php` get the full toggle; `login.php` gets the bootstrap only.

Admin pages inherit through `dashboard.css` + `layout.php`. `admin.css` still carries legacy hardcoded colours (`#3273dc`, `#7a7a7a`) that haven't been light-audited.

**Members portal cleanup.** The members portal is token-driven, so its main pages follow light mode directly. Two specific fixes: tokenise the hardcoded `a:hover` (`#b89fe8` → `var(--accent-hover)`), and pin the In Memoriam page to dark in both themes via a `[data-theme="light"] .memorial-page { …dark tokens… }` block. The memorial page is a deliberately dark, cosmic, sensitive design (star field, candle glow, gradients fading into `--bg-surface`, embedded crisis helplines) that breaks if forced light; re-applying the dark palette across the whole `.memorial-page` subtree keeps the helplines and everything nested inside it readable. This same `.memorial-page` pin pattern is the template for any deliberately-dark or branded section in the other portals.

**Follow-ups (these don't block the toggle going live):**
- Hardcoded-colour cleanup pass (§6) for the remaining surfaces: inline `style="color:#..."` across pages, SweetAlert2 light overrides, brand-purple landing sections, `admin.css` legacy colours. Light mode functions without these; some spots will need polish.
- localStorage is per-origin, so the theme doesn't follow a user across subdomains. If cross-subdomain sync matters, switch the store to a cookie scoped to `.botofthespecter.com` — that also lets PHP set `data-theme` server-side and drop the JS bootstrap.
- Remaining portals (roadmap, specterbotapp, specterbotsystems, yourlinks): same override-block + toggle pattern, deferred.
