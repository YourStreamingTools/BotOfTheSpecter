---
name: dashboard-page-menu-registration
description: "New dashboard pages must be registered in dashboard/menu.php or users can't find them"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 512bed90-6d7e-48c5-8c96-1be0bdf3a74c
---

Creating a new dashboard PHP page (e.g. `./dashboard/makers.php`) is not enough — it must be registered in `./dashboard/menu.php` or it's effectively hidden.

`menu.php` is the SINGLE nav renderer for both mobile and desktop (`renderMenu($mode, $role)`); items come from `getMenuItems($role)`. Add user-facing pages to the `$default` array under the right submenu (overlay/media tools go under **Stream Tools**; there are also `$admin` and `$todolist` role menus). Entry shape: `[ 'label' => t('navbar_yourpage'), 'icon' => 'fas fa-...', 'href' => 'yourpage.php' ]`. **Labels MUST go through `t()` with a key defined in the lang files — never hardcode a plain string** (some older entries do, but the project uses the lang files deliberately). Add the key to `./dashboard/lang/en.php` (the REQUIRED base — `i18n.php` loads en as the base and `array_merge`es the active language over it, so a missing key falls back to the en value), then add translated entries to `de.php` and `fr.php`. One menu edit covers mobile + desktop.

**Why:** A page absent from `menu.php` has no nav entry; users can only reach it if some other page happens to link to it (a card on `overlays.php` is not discoverable enough on its own).

**How to apply:** Whenever you add a dashboard page, add its menu entry in the same change. Same class of miss as [[bot-builtin-command-registration]] — build the feature AND wire it into its discovery surface (command sets / nav menu), not just the standalone file.
