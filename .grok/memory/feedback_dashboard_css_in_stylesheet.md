---
name: dashboard-css-in-stylesheet
description: "Dashboard component CSS belongs in dashboard.css, not inline styles or page <style> blocks"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 512bed90-6d7e-48c5-8c96-1be0bdf3a74c
---

When styling a dashboard page, put component styles in the shared stylesheet `./dashboard/css/dashboard.css` (in a `/* ===== Page: section ===== */` block), reusing the existing design tokens. Do NOT hardcode a page-scoped `<style>` block or pile on inline `style="..."` attributes — even though some older pages (e.g. the todolist pages) historically did. The user pushed back on this directly: "why did you hardcode css when we have the css file?"

Use the existing CSS variables (`--blue`, `--blue-bg`, `--bg-card-hover`, `--border-hover`, `--text-muted`, `--radius`, `--transition`, etc., defined in `dashboard.css` `:root` with light/dark overrides). The `ui-theme` skill is the authority on which stylesheet governs each surface and what tokens exist.

**Why:** keeping styles in `dashboard.css` gives one source of truth, reuses theme tokens so the page tracks the light/dark toggle automatically, and matches the project's established pattern (e.g. the `.media-*` classes live in `dashboard.css`). Inline/page styles drift from the theme and can't be reused.

**How to apply:** add the rules to `dashboard.css` with token-based values; reference them by class from the page markup. If a sub-agent is told to "edit only this one file," that instruction is what causes inline-CSS shortcuts — give it permission to touch `dashboard.css` too. Same spirit as [[dashboard-page-menu-registration]]: wire new UI into the project's shared systems, not a one-off in the page.
