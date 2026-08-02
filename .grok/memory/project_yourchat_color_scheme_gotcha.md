---
name: project_yourchat_color_scheme_gotcha
description: yourchat sets :root color-scheme:dark — new elements on its always-white panels need explicit colors or they render invisible/dark
metadata: 
  node_type: memory
  type: project
  originSessionId: d4535b42-54e1-402f-9d82-0ce83a3c4802
---

`yourchat/style.css` sets `:root { color-scheme: dark; }` (added with the dark/light `data-theme` toggle; light mode flips it via `[data-theme="light"] { color-scheme: light }`). But many yourchat surfaces are **always-white cards** (`.settings-panel` and the panels that share it like `.chat-features-panel`, plus `.login-container`). Under a dark color-scheme the browser renders any **unstyled** control/text in dark-mode system colors, so on a white card: unstyled text (`CanvasText`) becomes near-white and **invisible**, and unstyled `<input>`/`<textarea>` get a **dark** background.

**How to apply:** When adding any element to a yourchat white panel, give it an explicit `color` (dark, e.g. `#333`) and inputs an explicit `background`. The robust fix already applied: the white surfaces (`.settings-panel`, `.login-container`) declare `color-scheme: light` + `color: #333`, and `.filter-input` has explicit `background:#fff; color:#1f1f1f`. Don't add controls relying on system defaults. The genuinely-dark `.chat-overlay` and the activity feed/cards/message box are fine (explicit colors / intentionally dark) — leave them. This is a yourchat-only trap; other surfaces are dark-only (see [[feedback_dashboard_css_in_stylesheet]]).
