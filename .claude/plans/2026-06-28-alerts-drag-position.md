# Alert Box Drag-to-Position — Implementation Plan

**Goal:** let a streamer position each alert by dragging the live preview box on a true-to-scale 16:9 canvas and dropping it where it should sit on stream, saving the spot as a free x/y percentage and reproducing it on the overlay. This replaces the 3×3 preset grid on the alerts page for every category, including weather, deaths, and walk-ons.

**Approach in one paragraph:** add `position_x` / `position_y` `DECIMAL(5,2)` columns to the per-user `twitch_alerts` table, which the dashboard schema manager auto-migrates everywhere. On the alerts page, turn the centre preview into a fixed 16:9 OBS canvas with a 720p–2K resolution selector, make the preview box draggable with the snapping and expand behaviour ported from the makers editor, and persist its clamped top-left percentage. Remove both 3×3 grids and the flex-based preview positioning. In the overlay, make the position helper prefer x/y (clamped to the rendered box size) and fall back to the legacy preset then the category default, so nobody's existing placement moves until they choose to drag. Add a short size-range note to the makers page so the two editors document the same 720p-min / 2K-max constraint.

**Touch points:** PHP (mysqli, the alerts page and schema manager), front-end JavaScript (pointer-event dragging, snapping, the overlay's vanilla-JS position helper), MySQL (`DECIMAL` columns via the auto-migrating schema manager), and the dashboard i18n layer.

## Constraints to honor

- **Per-user database scope.** `twitch_alerts` lives in each user's own database; all reads and writes stay there.
- **Parameterized SQL only** — placeholders and bound parameters; no string-built SQL. The existing `save_alert` type-string is long and order-sensitive, so the two new decimal parameters must be inserted with the value order and type characters kept in lockstep.
- **Styles in the alerts stylesheet.** New canvas, drag, snap, and expand styles go in `dashboard/css/alerts.css` using the existing theme tokens — not inline, and not in `dashboard.css`. (The makers styles already live in `dashboard.css`; we are not moving them.)
- **i18n** — every new label is a `t()` key added to the English base plus German and French, with French apostrophes escaped.
- **Overlay stays stateless and poll-free.** Position comes from the variant config the overlay already loads; no new fetch or polling is added. Placement is resolution-independent because the canvas is always 16:9.
- **Backward compatibility is non-negotiable.** A user who never re-saves must see no movement: the overlay keeps honouring their `screen_position` until x/y is set.
- **No new automated test harness.** The alerts page and overlay have no existing tests; verification is a per-file syntax check plus the manual trace below.

## Work items

### 1. Add the position columns to the schema

In the per-user schema manager (`dashboard/includes/usr_database.php`), add `position_x DECIMAL(5,2) DEFAULT NULL` and `position_y DECIMAL(5,2) DEFAULT NULL` to the `twitch_alerts` table definition, near `screen_position`. The manager's auto-migration parses the definition column-by-column, skips constraint lines, and issues an `ALTER TABLE … ADD` for anything missing, so the two columns land on every existing user database on the next dashboard load. Leave `screen_position` exactly as it is.

### 2. Make the overlay position helper prefer x/y

In `overlay/index.php`, extend `applyScreenPosition` so it first looks for the variant's `position_x` / `position_y`. When present, place the element at those percentages with `left`/`top`, anchored top-left (no centring transform), then clamp the top-left using the element's rendered width and height so its right and bottom edges stay inside the viewport. When x/y is absent, run the existing preset logic; when that's absent too, use the category default. Update the four call sites — the main alert container, the death overlay, the weather overlay, and the walk-on overlay — to pass the variant's x/y alongside the category they already pass, reading x/y from the same config object the preset comes from.

### 3. Reframe the alerts preview as a 16:9 OBS canvas

In `dashboard/alerts.php`:

- Replace the freeform width/height number inputs in the preview options with a resolution selector offering 1280×720 (720p), 1920×1080, and 2560×1440 (2K), with 720p and 2K marked as the supported minimum and maximum.
- Make the preview area a fixed 16:9 frame and switch it from flex alignment to absolute positioning, with the box placed at its stored x/y.
- Scale the box true-to-scale for the selected resolution, the way the makers editor scales its chips, so the preview reflects real proportions. Keep autoplay and the background swatches.
- Remove the flex-based `applyPreviewPosition` helper; absolute placement replaces it.

### 4. Make the box draggable with snapping and expand

Still in `dashboard/alerts.php`, port the makers drag editor's interaction onto the preview box (and the weather/deaths sample, and a new walk-on card sample used only as a drag target):

- Pointer-event grab / move / release on the box, writing its clamped top-left percentage to the variant's working state on each move and marking the variant dirty on release.
- Snapping that aligns the box's near edge, centre, and far edge to the canvas edges, centre, and thirds, with vertical and horizontal guide lines shown only while a snap is active, and Alt to bypass. Adapt the makers snap maths to use the box's measured size rather than a synthesised chip footprint.
- An expand-to-fullscreen control that promotes the canvas to a large centred panel over a dim backdrop, closable by the button, the backdrop, or Escape — the same canvas and box, only larger.

### 5. Remove the 3×3 grids and seed from x/y

- Remove the `position-grid-form` grid from the main variant settings and the `position-grid-simple` grid from the simple-category panel, along with their click handlers and the `set_alert_position`-on-click wiring for presets.
- When a variant opens, seed the box position from its stored x/y; if those are null, convert a legacy `screen_position` to equivalent x/y with a small preset→percentage map; if both are absent, use the per-category default (weather top-left, deaths bottom-left, otherwise centred).
- The `alerts_render_position_grid` helper and the `$screenPositions` array become unused on the page; remove them along with the grids.

### 6. Persist x/y on save (bundled and immediate)

In `dashboard/alerts.php`'s request handlers:

- **`save_alert`** (the bundled save for main variants): add `position_x` and `position_y` to the UPDATE, and extend the bound-parameter list with two decimal (`d`) parameters inserted before the trailing `id`, keeping the type-string and value order aligned. Clamp both to 0–100 server-side before writing.
- **The simple-category immediate save** (currently `set_alert_position`, which writes a preset): rework it to accept and persist x/y for the dragged simple categories, clamped to 0–100, instead of a nine-preset string.
- Include x/y in the client-side settings the main form collects, so the bundled save carries the dragged position.

### 7. Style the canvas, box, snapping, and expand

In `dashboard/css/alerts.css`, add alerts-namespaced styles built on the existing theme tokens: the fixed 16:9 canvas frame, the draggable box affordance (grab/grabbing cursors, a disabled/dimmed state when a category is off), the thin snap guides shown only while active, and the expand panel plus its backdrop and close button. Retire the now-unused 3×3 grid styles (`alerts-position-grid` / `-btn` / `-dot`).

### 8. Note the supported size range on the makers page

In `dashboard/makers.php`, add a short hint by the canvas-size selector and a clarifying comment stating the 1280×720 minimum and 2560×1440 maximum. The selector already lists exactly those three steps, so this is documentation only — no behavioural change.

### 9. Translation keys

Add the new keys to the English base file and to the German and French files, near the existing alerts block: the resolution/canvas-size label, the drag hint, the snap hint, and the expand and collapse controls. Mirror the makers wording where an equivalent key already exists. Escape French apostrophes.

### 10. Verification

- Syntax-check every touched PHP file.
- Sanity-check the page and overlay JavaScript for the dragging, snapping, save, and seeding paths.
- Walk the manual trace: open a follow variant, drag its preview box to the upper-left third, save, and confirm the overlay shows the alert there (clamped on-screen) and that reloading the dashboard restores the dropped spot. Switch the canvas to 2K and confirm the box holds its relative position. Confirm a legacy variant with a `screen_position` and null x/y still renders at its old preset on the overlay and opens its editor at that preset rather than centre.

## Deployment note

The two columns must exist before the overlay starts reading x/y, but nothing breaks if they don't yet: the overlay falls back to the legacy preset whenever x/y is null. Loading any dashboard page once triggers the auto-migration, so do that before — or alongside — relying on the new positions. Because position is a dashboard-and-overlay change against the per-user database, no bot, API, or websocket restart is involved.
