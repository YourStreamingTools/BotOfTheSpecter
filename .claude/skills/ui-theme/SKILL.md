---
name: ui-theme
description: BotOfTheSpecter UI theme system — covers every visible surface of the project. Use when creating or modifying any HTML, PHP, or CSS that produces UI on the dashboard, admin, overlays, members portal, support portal, roadmap, landing/home page, SpecterBotApp, YourLinks, YourChat, or the Twitch extension. The project has 13 stylesheets across 12 surfaces — this skill maps each one to its purpose, lists the design tokens and class namespaces in use, and gives the rules for picking the right file to edit. NEVER invent colours, spacing, or component classes — always reuse the matching file's existing system.
---

# BotOfTheSpecter UI Theme

The project ships **12 stylesheets** across **11 distinct surfaces**. Every surface is dark-themed (except YourChat, which is a gradient theme) and most descend from a shared `sp-*` base, but they live in separate files because each one is deployed as its own subdomain or PHP entrypoint. **You must pick the right file for the surface you're editing.** Editing dashboard.css does not propagate to members or support; each portal has its own copy.

Bulma is no longer used anywhere in the project. The `dashboard.css` file still contains a Bulma-alias layer (`.button.is-primary`, `.field`, `.column`, `.modal`, etc.) so existing markup keeps rendering — treat those classes as *aliases* of the `sp-*` system, not as a separate framework.

## Surface → file map

| # | Surface | URL (rough) | Stylesheet | Lines | Convention |
| - | ------- | ----------- | ---------- | ----- | ---------- |
| 1 | **Dashboard** (main UI users log into) | `dashboard.botofthespecter.com` | `./dashboard/css/dashboard.css` | 3346 | `sp-*` + Bulma-alias aliases (`.button.is-primary`, `.field`, `.column`, etc.) |
| 2 | **Admin** (admin pages within the dashboard) | `dashboard.botofthespecter.com/admin/*` | `./dashboard/css/admin.css` | 525 | Additive layer on top of `dashboard.css`. Adds `admin-*`, `discord-config-card-*`, `search-*`, `bot-message-*`, `hover-box`, `.config-card` |
| 3 | **Alerts configurator** (alerts builder UI on the dashboard) | `dashboard.botofthespecter.com/alerts/*` | `./dashboard/css/alerts.css` | 454 | Additive layer. `alerts-*` namespace for the sidebar + preview + settings 3-column builder. Uses Twitch purple `#9147ff` for active states (deliberate — it's the alerts builder for Twitch alerts) |
| 4 | **Help** (variable-grid help page) | `dashboard.botofthespecter.com/help/*` | `./help/css/custom.css` | 16 | Tiny additive layer. Just `.help-variable-grid > .column > .card` adjustments. Sits on top of `dashboard.css` |
| 5 | **Members portal** | `members.botofthespecter.com` | `./members/style.css` | 1656 | Self-contained: own `:root`, own sp-* base, plus `ms-*` for members features (search, games, autocomplete), `ac-*` (autocomplete dropdown), `memorial-*` (in-memoriam page with candle/dove/star animations), `tab-item`, `reward-filter-btn`, `data-tabs` |
| 6 | **Support portal** | `support.botofthespecter.com` | `./support/css/style.css` | 1902 | Self-contained: own `:root`, own sp-* base, plus support-ticket UI |
| 7 | **Roadmap portal** | `roadmap.botofthespecter.com` | `./roadmap/css/style.css` | 1587 | Self-contained: own `:root`, own sp-* base, **extra status colours** (`--purple`, `--purple-bg`, `--teal`, `--teal-bg`, `--orange`, `--orange-bg`) for roadmap categories |
| 8 | **Marketing home page** | `botofthespecter.com` | `./home/style.css` | 585 | Own `hs-*` namespace (`hs-topnav`, `hs-btn`, `hs-mobile-nav`, `hs-container`). No sidebar — top-nav only. Same `:root` palette as the dashboard |
| 9 | **SpecterBotApp** (separate web app for the Specter bot) | `specterbotapp.botofthespecter.com` (or similar) | `./specterbotapp/home/css/custom.css` | 677 | Self-contained: own `:root`, own sp-* base, top-fixed `.navbar` (no sidebar) |
| 10 | **YourLinks** (short-link service, separate domain) | `yourlinks.click` | `./yourlinks.click/yourlinks.click/css/site.css` | 3144 | **Forked from `dashboard.css`** plus `yl-*` for YourLinks-specific UI (`yl-topbar`, `yl-main`, `yl-form-row`, `yl-modal*`, `yl-profile-*`, `yl-category-grid`). Custom Toastify themes |
| 11 | **YourChat** (lightweight chat overlay/window) | `yourchat.botofthespecter.com` | `./yourchat/style.css` | 1371 | **Different aesthetic** — gradient background (`#667eea` → `#764ba2` indigo→purple), white translucent panels with `backdrop-filter: blur`. NOT the dark `sp-*` system. Class names: `.header`, `.status-bar`, `.compact-actions`, `.chat-overlay`, etc. |
| 12 | **Overlays** (OBS browser sources for streamers) | served via `dashboard.botofthespecter.com/overlay/...` | `./overlay/index.css` | 1450 | `{name}-overlay-page-*` namespace per overlay (deaths, weather, discord, twitch, fourthwall, kofi, chat, study, credits, todolist, subathon, video-alert, plus the configurable `twitch-alert-*` engine). Transparent body, hidden scrollbars, animation-driven `.show`/`.hide` states |

## Hard rules

1. **Always edit the matching surface file.** Dashboard work → `dashboard.css`. Admin pages on the dashboard → `admin.css`. Members portal → `members/style.css`. And so on. Never push members-only styles into `dashboard.css`, and don't import one surface's CSS into another.
2. **Don't create new top-level CSS files.** If you need new styles for an existing surface, add them to that surface's file as a new numbered section. New surfaces are a product decision — confirm with the user before adding a 13th file.
3. **Inside dark sp-\* surfaces (1, 2, 3, 4, 5, 6, 7, 8, 9, 10): use the local `:root` variables.** Do not hardcode hex/rgb colours. If a variable doesn't exist for what you need, ask first — adding a new token is a deliberate change.
4. **Inside `overlay/index.css` (12):** brand colours (Twitch `#9146ff` / `#9147ff`, Discord blurple, Ko-fi `#29abe0`, Fourthwall `#9b59b6`) are deliberately hardcoded. For neutral overlay chrome, follow the existing `rgba(0, 0, 0, 0.8)` panel + `#ffffff` text pattern.
5. **Inside `yourchat/style.css` (11):** the gradient aesthetic is the brand — don't refactor it to match the dark dashboard. White translucent panels with `backdrop-filter: blur(10px)` and the indigo→purple gradient are deliberate.
6. **Token drift exists between portal copies.** Members, support, roadmap, and specterbotapp each have their own `:root` block. Some values have drifted (e.g., dashboard's `--border: rgba(255, 255, 255, 0.07)` vs members/support's `--border: #2a2a35`; dashboard's `--amber: #fbbf24` vs members/support's `--amber: #f0a500`). When changing a token in one file, decide whether the other portals should follow — usually yes for genuine palette changes, no for surface-specific tweaks. Ask the user when unsure.
7. **No new CSS framework imports.** No Tailwind, no Bootstrap, **no Bulma** (it was removed — don't bring it back). The codebase has its own systems already.
8. **No inline `<style>` blocks** in PHP/HTML for things that belong in a stylesheet. Inline `style="..."` is OK only for dynamic values set by JS (user-configured colours, calculated positions).
9. **Dark theme only on surfaces 1–10 and 12.** Don't introduce light-theme variants without explicit user authorisation.
10. **Mobile-aware on dashboard surfaces.** Reuse the established breakpoints: `1200`, `1100`, `1023`, `900`, `768`, `640`, `600`, `480`. Overlays (12) should render cleanly at 1080p / 1440p / 4K — don't hardcode pixel widths that clip.

## The shared `sp-*` design system

Surfaces 1, 2, 3, 4, 5, 6, 7, 9, 10 all use the `sp-*` system. The **canonical version is `./dashboard/css/dashboard.css`** — that's the most complete, has the most components, and is the model the others were forked from.

### Canonical design tokens (from `./dashboard/css/dashboard.css` `:root`)

```css
/* Backgrounds */
--bg-base:       #0d0d0f;
--bg-surface:    #141418;
--bg-card:       #1a1a20;
--bg-card-hover: #1f1f27;
--bg-input:      #16161c;

/* Brand / Accent (purple) */
--accent:        #7c5cbf;
--accent-hover:  #9070d8;
--accent-light:  rgba(124, 92, 191, 0.12);
--accent-glow:   rgba(124, 92, 191, 0.25);

/* Text */
--text-primary:   #e8e8f0;
--text-secondary: #a8a8bc;
--text-muted:     #6c6c84;

/* Borders */
--border:       rgba(255, 255, 255, 0.07);
--border-hover: rgba(255, 255, 255, 0.14);

/* Status — always paired with the *-bg variant for tinted backgrounds */
--green: #3ecf8e;  --green-bg: rgba(62, 207, 142, 0.12);
--blue:  #5cb8ff;  --blue-bg:  rgba(92, 184, 255, 0.12);
--amber: #fbbf24;  --amber-bg: rgba(251, 191, 36, 0.12);
--red:   #f87171;  --red-bg:   rgba(248, 113, 113, 0.12);
--grey:  #6c6c84;  --grey-bg:  rgba(108, 108, 132, 0.12);

/* Layout */
--sidebar-width: 260px;
--topbar-height: 56px;

/* Radii */
--radius:      6px;
--radius-sm:   4px;
--radius-lg:   10px;
--radius-pill: 999px;

/* Transitions */
--transition: 150ms ease;
```

**Members / support / roadmap / specterbotapp drift:** these portals use slightly different values for `--border` (`#2a2a35`), `--border-hover` (`#3d3d50`), `--amber` (`#f0a500`), `--blue` (`#4aa3f0`), `--red` (`#f05050`), `--text-secondary` (`#9090a8`), `--text-muted` (`#5a5a72`), `--bg-input` (`#111116`), and they add `--shadow-sm`, `--shadow`, `--shadow-lg`. They also use `--radius: 8px` (vs dashboard's 6px) and `--radius-lg: 12px` (vs 10px). Roadmap additionally defines `--purple`, `--teal`, `--orange` and their `*-bg` pairs.

The drift is small enough that components feel consistent, but if you copy a class from dashboard.css into members/style.css, the visual rendering may shift slightly. Test it.

### Canonical component inventory (in `dashboard.css`)

The canonical file is the inventory. Below is a quick map; refer to the file itself when you need exact behaviour.

**Layout shell** — `.sp-layout`, `.sp-sidebar`, `.sp-brand`, `.sp-nav`, `.sp-nav-link`, `.sp-nav-label`, `.sp-nav-section`, `.sp-main`, `.sp-topbar`, `.sp-topbar-title`, `.sp-topbar-center`, `.sp-topbar-tag` (`-admin`, `-dev`, `-act-as`, `-maintenance`), `.sp-hamburger`, `.sp-content`, `.sp-footer`, `.sp-overlay`, `.sp-page-header`.

**Buttons** — `.sp-btn` + variants (`-primary`, `-secondary`, `-ghost`, `-danger`, `-success`, `-warning`, `-info`), size `.sp-btn-sm`, states `:disabled` and `.sp-btn-loading`. Bulma-alias: `.button.is-primary` / `.is-link` / `.is-info` / `.is-success` / `.is-warning` / `.is-danger` / `.is-dark` / `.is-ghost`, sizes `.is-small` / `.is-medium` / `.is-large`, `.is-light` modifier, `.is-static`. Groups: `.sp-btn-group`, `.buttons`.

**Cards** — `.sp-card` + `.sp-card-header` + `.sp-card-title` + `.sp-card-body`. Stats: `.sp-stat-row` + `.sp-stat` + `.sp-stat-label` + `.sp-stat-value` + `.sp-stat-sub` (modifiers `.online`, `.offline`, `.warn`). Bulma-alias: `.card`, `.card-header`, `.card-header-title`, `.card-content`, `.box`.

**Forms** — `.sp-form-group`, `.sp-label`, `.sp-input`, `.sp-select`, `.sp-textarea`, `.sp-input-wrap` + `.sp-input-icon`, `.sp-help` (+ `-danger` / `-warning`), error states `.sp-input-error` / `.sp-input.is-danger` / `.input-error`. Toggle: `<input type="checkbox" class="switch">` + `<label>` pattern, or label-wrapping `<label class="switch">`. Bulma-alias: `.field`, `.field.has-addons`, `.control`, `.label`, `.input`, `.textarea`, `.help`, `label.checkbox`.

**Tables** — `.sp-table-wrap` + `.sp-table`. Bulma-alias: `.table-container` + `.table.is-fullwidth` / `.is-hoverable` / `.is-striped` / `.is-narrow`. Mobile safety net auto-applies horizontal scroll at `≤768px`.

**Badges & tags** — `.sp-badge` + `-green` / `-blue` / `-amber` / `-red` / `-grey` / `-accent`. Bulma-alias: `.tag.is-success` / `.is-info` / `.is-warning` / `.is-danger` / `.is-dark` / `.is-light` / `.is-primary`. Status pill: `.status-indicator` + `.online` / `.offline` / `.warn`.

**Alerts & toasts** — `.sp-alert` + `-info` / `-success` / `-warning` / `-danger`. Toast queue: `.toast-area` + `.working-study-toast` (+ `.success` / `.danger` / `.visible`). Dismissable: `.sp-notif` + `.sp-notif-close`. Bulma-alias: `.notification.is-success` / `.is-info` / `.is-warning` / `.is-danger`. SweetAlert2 dark overrides are already applied.

**Modals** — Generic: `.sp-modal-backdrop` + `.sp-modal` + `.sp-modal-head` / `-title` / `-close` / `-body`. Bulma-alias: `.modal.is-active` + `.modal-background` + `.modal-content` (or `.modal-card` + `.modal-card-head` / `-title` / `-body` / `-foot`) + `.modal-close` / `.delete`. Maintenance-style: `.db-modal-backdrop` + `.db-modal`. YourLinks-style: `.cc-modal-backdrop` + `.cc-modal`.

**Navigation** — Sidebar render: `.sidebar-menu`, `.sidebar-menu-item`, `.sidebar-menu-link`, `.sidebar-submenu`, `.sidebar-submenu-link`, `.sidebar-menu-divider`, `.sidebar-submenu-divider`, `.sidebar-user-section`, `.sidebar-user-item`. Tabs: `.sp-tabs-nav` + `<li>.is-active`. Pagination: `.sp-pagination` + `.sp-pagination-link` (+ `.is-current`).

**Page-specific helpers** (already styled — reuse, don't recreate):

| Page | Pattern |
| ---- | ------- |
| Dashboard home | `.db-two-col`, `.db-snapshot-item`, `.db-quick-grid`, `.db-quick-card`, `.db-quick-icon`, `.db-quick-title`, `.db-quick-desc`, `.db-section-label` |
| Bot management | `.bot-page-cols`, `.bot-stream-status` (+ `-online` / `-offline` / `-unknown`), `.bot-header-wrapper`, `.service-grid`, `.service-box` |
| EventSub | `.stats-grid`, `.stat-card` (+ `.danger-card` / `.warning-card`), `.stat-label`, `.stat-value`, `.stat-secondary`, `.session-group`, `.session-header`, `.sub-count`, `.sub-type`, `.sub-version`, `.status-badge`, `.delete-btn`, `.custom-btn` |
| Custom Commands | `.cc-form-grid`, `.cc-modal-*`, `.sp-betabot-toggle` |
| Schedule | `.schedule-day-columns`, `.sched-col`, `.sched-day-label`, `.schedule-summary-grid`, `.schedule-segment-card` |
| Videos / Media | `.media-cards-grid`, `.media-card-thumb`, `.media-drop-zone`, `.media-storage-bar`, `.media-upload-card`, `.media-table` |
| Followers / Subs | `.followers-grid`, `.follower-card-media`, `.follower-avatar-img`, `.follower-avatar-initials`, `.subscribers-grid` |
| Raids | `.raids-layout`, `.raids-section-head` |
| Premium / Plans | `.sp-plan-grid`, `.sp-plan-card` (+ `.is-current`), `.sp-plan-icon-area`, `.sp-plan-name`, `.sp-plan-price`, `.sp-plan-features`, `.sp-plan-current-ribbon`, `.sp-plan-beta-card` |
| Discord | `.server-management-toggles`, `.toggle-item`, `label.switch` (label-wrapping variant) |
| Landing (logged-out, inside `dashboard.php`) | `.db-topnav`, `.db-hero`, `.db-login-card`, `.db-twitch-btn`, `.db-landing-section`, `.db-features-grid`, `.db-feature-card`, `.db-plans-grid`, `.db-plan-card` |

**Utilities** — `.sp-two-col`, `.sp-field-row`, `.sp-btn-group`, `.w-100`, `.sp-btn-block`. Text: `.sp-text-success` / `-danger` / `-muted` / `-info` / `-warning` / `-accent`. Bulma utilities: `.has-text-centered/left/right/grey/danger/success/warning/info`, `.mt-0`–`.mt-5`, `.mb-0`–`.mb-5`, `.mr-1`–`.mr-4`, `.ml-1`–`.ml-4`, `.py-4`. Bulma columns: `.columns` / `.column.is-half/-one-third/-two-thirds/-one-quarter/-three-quarters/-full`. Level: `.level`, `.level-left`, `.level-right`. Titles: `.title.is-1`–`.title.is-6`.

## Surface-specific notes

### Surface 2 — `./dashboard/css/admin.css`

Loaded **on top of** `dashboard.css` for admin pages. It assumes `dashboard.css` has already loaded (uses `var(--bg-card)`, `var(--border)`, etc.). It also sneaks in some lighter-theme values (`#3273dc`, `#7a7a7a` from older Bulma) — those are intentional for admin highlights and shouldn't be sanitised away. Notable additions:

- Generic: `.icon`, `.collapsible-header`, `.collapse-icon`, `.collapsible-content`, `.tracking-card`, `.fa-chevron-down`, `.hover-box`
- API key display: `.masked-api-key`, `.full-api-key`
- Search: `.search-wrapper`, `.search-input`, `.search-clear`, `.search-count`
- Discord config: `.config-card`, `.discord-config-card-*`, `.admin-discord-grid`
- Bot message counts: `.bot-message-box`, `.bot-message-count-*`
- Admin users table: `.admin-users-table`, `.is-restricted-row`, `.is-memorial-row`, `.restricted-label`, `.memorial-label`, `.memorial-action-btn`, `.actions-wrap`, `.admin-date-cell`, `.admin-date-line`, `.admin-time-line`
- Action bar: `.admin-action-bar`
- Modal tweak: `.db-modal { border-radius: 12px }`
- Badge variants: `.sp-badge-info` (gradient), `.sp-badge-success` (gradient), `.sp-badge-dark` (gradient)

When adding admin-only styles, prefer extending `admin.css` over polluting `dashboard.css`.

### Surface 3 — `./dashboard/css/alerts.css`

The alerts configurator has a very specific layout (sidebar list of alerts → centre preview → right settings panel). Use the `alerts-*` namespace exclusively for that builder. Keyframes for entry/exit animations are in `dashboard.css` and `overlay/index.css`, not here.

Notable: this file uses Twitch purple `#9147ff` for active states — that's a deliberate brand cue, not a violation of the dark-theme palette. Don't normalise it to `--accent`.

### Surface 4 — `./help/css/custom.css`

Tiny additive layer for the help page. Just makes `.help-variable-grid > .column > .card > .card-content` allow content to overflow rather than truncate. If you find yourself adding more than a few rules here, consider whether they belong in `dashboard.css` instead.

### Surfaces 5 / 6 — `./members/style.css` and `./support/css/style.css`

Both portals are siblings. Same `:root`, same sp-* base, same shell (sidebar + topbar + content). They diverge in their portal-specific helpers (`ms-*` for members, support-specific for support). When changing the shared base in one, consider mirroring in the other.

Members-specific patterns:
- `.ms-search-card`, `.ms-search-row`
- `.ms-tabs-container`, `.ms-tabs-wrap`, `.data-tabs`, `.tab-item`
- `.ac-wrapper`, `.ac-dropdown`, `.ac-item`, `.ac-avatar`, `.ac-name`, `.ac-username` (autocomplete)
- `.ms-game-featured`, `.ms-game-card`, `.ms-games-grid`, `.ms-games-heading`
- `.reward-filters`, `.reward-filter-btn`
- `.memorial-page` family — candle/wick/flame/dove/star animations for the in-memoriam page (`memorial-twinkle`, `memorial-float`, `memorial-glow`, `candle-flicker` keyframes). Has its own purple `#c39bd3` / `#9b59b6` accent for memorial pages
- `.memorial-helplines`, `.memorial-local-helpline` — crisis helpline display (handle with care; the page is sensitive)

### Surface 7 — `./roadmap/css/style.css`

Self-contained sp-* portal with **extra status colours** for roadmap categories: `--purple`, `--teal`, `--orange` and their `*-bg` pairs. Use these for category swatches/labels — don't reach for `--accent` for non-purple categories.

### Surface 8 — `./home/style.css`

The marketing landing page. Uses `hs-*` namespace (top-nav layout instead of sidebar). Same dark palette as the dashboard, but doesn't include any `sp-*` shell — only `hs-topnav`, `hs-btn`, `hs-mobile-nav`, `hs-container`, `hs-main`, etc. If you find yourself wanting a card here, check whether you need `.hs-*` or just plain markup with the shared tokens.

### Surface 9 — `./specterbotapp/home/css/custom.css`

SpecterBotApp landing/home with a fixed `.navbar` (no sidebar). Self-contained sp-* + `.navbar`-based top navigation. Adds `--navbar-height` and `--footer-height` tokens. Treat as its own portal — don't share styles with `home/style.css`.

### Surface 10 — `./yourlinks.click/yourlinks.click/css/site.css`

Forked from `dashboard.css` (you'll see identical sp-* base for the first ~2700 lines), then adds `yl-*` for YourLinks-specific UI:
- `.yl-topbar`, `.yl-main`, `.yl-form-row`
- `.yl-modal`, `.yl-modal-box`, `.yl-modal-header`, `.yl-modal-close`
- `.yl-profile-links-list`, `.yl-profile-link-row`, `.yl-profile-link-icon`, `.yl-profile-link-info`, `.yl-profile-link-title`, `.yl-profile-link-url`, `.yl-profile-link-actions`, `.yl-profile-link-inactive`, `.yl-brand-icon`
- `.yl-category-grid`
- Custom Toastify themes (`.toastify.success-toast`, `.toastify.error-toast`, `.toastify.info-toast`)

When upgrading a shared sp-* component in `dashboard.css`, decide whether to mirror it into `yourlinks.click/.../site.css`. Usually yes for visual fixes, no for dashboard-only feature components.

### Surface 11 — `./yourchat/style.css`

**Different aesthetic.** Gradient background `linear-gradient(135deg, #667eea 0%, #764ba2 100%)`, white translucent panels (`rgba(255, 255, 255, 0.1)` + `backdrop-filter: blur(10px)`), rounded 15px boxes. This is intentional — YourChat is a lightweight chat overlay/window with its own brand. Class names are unprefixed (`.header`, `.status-bar`, `.compact-actions`, `.chat-overlay`, `.status-indicator`, `.status-light`).

**Do not refactor it to match the dashboard.** The gradient/glassmorphism look is the brand. The custom `::-webkit-scrollbar` styling (gradient thumb) is part of that look — keep it.

### Surface 12 — `./overlay/index.css`

OBS browser-source overlays. See the dedicated section below.

## Overlay stylesheet — `./overlay/index.css` in detail

Every overlay PHP file in `./overlay/*.php` loads `./overlay/index.css` and renders inside an OBS browser source. Constraints:

- **Transparent body, no scrollbars** — `html, body { overflow: hidden }`, `* { scrollbar-width: none }`, `::-webkit-scrollbar { display: none }`. Never override these.
- **No CSS variables in `:root`** — overlay tokens are inlined per-section, not centralised.
- **Animations are first-class.** Most overlay components have `.show` and `.hide` states using shared keyframes.

### Class-naming convention

Always `{overlay-name}-overlay-page-{element}`. Existing roots: `.deaths-overlay-page`, `.weather-overlay-page`, `.discord-overlay-page`, `.twitch-overlay-page`, `.subathon-overlay-page`, `.video-alert-overlay-page-video`, `.fourthwall-overlay-page-alert` (with type modifiers `-order` / `-donation` / `-giveaway` / `-sub` and dismissal `-dismissing`), `.kofi-overlay-page-alert` (with `-donation` / `-subscription` / `-shop` and `-dismissing`), `.chat-overlay-page-container`, `.study-overlay-page-root` (with modifiers `--timer-only` / `--tasks-only`, plus full task-system panel namespace), `.credits-overlay-page` (with scoped `--credits-text-color` / `--credits-font-family`), `.todolist-overlay-page-theme-box`, plus the configurable Twitch alert engine (`.twitch-alert-container`, `.twitch-alert-box` with `.layout-above` / `-below` / `-left` / `-right` / `-behind`, `.twitch-alert-image`, `.twitch-alert-text`, `.twitch-alert-accent`).

### Shared keyframes (defined in `overlay/index.css` — reuse, don't redefine)

`slideIn`, `slideOut`, `fadeIn`, `fadeOut`, `glow`, `slideInLeft`, `slideOutLeft`, `slideInRight`, `slideOutRight`, `slideInUp`, `slideOutUp`, `slideInDown`, `slideOutDown`, `bounceIn`, `bounceOut`, `zoomIn`, `zoomOut`, plus per-overlay scoped keyframes (`fourthwall-overlay-page-slide-in/out`, `kofi-overlay-page-slide-in/out`, `chat-overlay-page-msg-in/out`, `study-overlay-page-task-slide-in`, `study-overlay-page-task-pop-in`).

The Twitch alert engine picks animations dynamically from JS based on user settings — don't add new alert-entry animations without checking these first.

### Recipe: adding a new overlay

1. **Don't add casually.** Most "new overlay" requests are better as a configuration toggle in `all.php` (the master overlay). Confirm with the user first — see [`./.claude/rules/overlays.md`](../../rules/overlays.md).
2. Create `./overlay/{name}.php`. Follow the auth/Socket.io scaffolding from a similar existing overlay.
3. Add styles to `./overlay/index.css` in a new `/* ===== {Name} Overlay ===== */` section.
4. Use `{name}-overlay-page-{element}` naming. Don't reuse another overlay's namespace.
5. Reuse existing keyframes from `overlay/index.css`; don't invent new ones.
6. Brand colours: match the upstream service. Neutral chrome: `rgba(0, 0, 0, 0.8)` panel + `#ffffff` text + 1px translucent white border + 6–10px radius.
7. Always pair `.show` / `.hide` with keyframe animations — never just toggle `display: none`. OBS sources look jarring otherwise.
8. Auto-reconnect on WebSocket drop, queue audio/visual events, respect timezone — see `./.claude/rules/overlays.md`.

### Cross-stylesheet boundaries

- Don't import `dashboard.css` (or any portal CSS) into an overlay PHP file. OBS browser sources need to stay transparent and lightweight.
- Don't import `overlay/index.css` into the dashboard. The overlay sheet hides scrollbars globally and sets `overflow: hidden` on `html, body` — it would break dashboard scrolling instantly.
- The Twitch extension (in `./extension/`) is a separate package — it loads neither file. Inline the tokens it needs.

### Overlay configuration UI on the dashboard

The form on the dashboard where streamers configure overlay colours, animations, positions, etc. lives in the dashboard, not the overlay. **Use `dashboard.css` classes there** — don't pull `overlay/index.css` patterns into the configuration page.

## Recipes

### Adding a dashboard page

```php
<div class="sp-page-header">
    <h1>My New Page</h1>
    <p>Short description.</p>
</div>

<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fa-solid fa-..."></i> Section title</div>
        <button class="sp-btn sp-btn-primary sp-btn-sm">Primary action</button>
    </div>
    <div class="sp-card-body">
        <div class="sp-form-group">
            <label class="sp-label" for="thing">Thing</label>
            <input id="thing" class="sp-input" type="text" placeholder="...">
            <span class="sp-help">Helper text.</span>
        </div>
    </div>
</div>
```

For data display, prefer `.sp-stat-row` + `.sp-stat`. For tables, wrap them in `.sp-table-wrap`. Page-specific styles go at the bottom of `dashboard.css` in a new `/* ----- N. My new page ----- */` section.

### Adding an admin page

Same skeleton as a dashboard page, but include `admin.css` and reach for `admin-*` / `discord-config-card-*` / `search-*` patterns where they apply. New admin-only styles go in `admin.css`, not `dashboard.css`.

### Adding a portal page (members / support / roadmap)

Use the matching portal's stylesheet. For members:

```php
<div class="sp-page-header">
    <h1>My New Members Page</h1>
</div>
<div class="sp-card">
    <div class="sp-card-body">
        <!-- members-specific helpers like .ms-*, .ac-*, .tab-item where relevant -->
    </div>
</div>
```

New styles go at the bottom of the portal's `style.css`. If a member-specific helper would also be useful in the support portal, mirror it there.

### Adding a Twitch Extension panel

`./extension/` is a separate package. Twitch extension iframes have their own constraints. Inline the design tokens you need from `:root`, then build with the same component patterns (cards, badges, stats). Don't import `dashboard.css` directly.

## Quick verification before shipping UI

1. **Right file:** edits landed in the matching surface's stylesheet — not inline `<style>` blocks, not new top-level `.css` files.
2. **No hex codes in dark sp-\* surfaces** outside `:root`. Use `var(--*)`.
3. **No new font imports**, no `@import url('https://fonts...')`.
4. **No inline `style="..."`** carrying colour or spacing on dashboard surfaces (OK on overlays for JS-driven dynamic values).
5. **Mobile check (dashboard / portal / home / specterbotapp / yourlinks):** layout works at 480px.
6. **Resolution check (overlays):** renders cleanly at 1080p / 1440p / 4K.
7. **Component reuse check:** could this have been built from existing classes? If yes, refactor.
8. **Token-drift check:** if you changed a token in one portal's `:root`, decide whether the others should follow.
9. **Naming check (overlays):** new classes use `{name}-overlay-page-{element}` — they don't reuse another overlay's root.
10. **Aesthetic check (yourchat):** if you're touching `yourchat/style.css`, the gradient + glassmorphism aesthetic is preserved.

This skill is the contract: every visible surface across BotOfTheSpecter — dashboard, admin, alerts builder, help, members, support, roadmap, home, SpecterBotApp, YourLinks, YourChat, overlays, and the Twitch extension — has a designated stylesheet and a designated namespace. Pick the right one, and the project's visual coherence is preserved.
