# Alert Box Drag-to-Position - Design Spec

**Date:** 2026-06-28
**Scope:** the unified alerts builder (`dashboard/alerts.php`), its stylesheet (`dashboard/css/alerts.css`), the per-user schema manager (`dashboard/includes/usr_database.php`), the alert overlay (`overlay/index.php`), the dashboard language files, and a small documentation tweak to `dashboard/makers.php`.
**Status:** Design decisions agreed; ready to turn into an implementation plan.

## Problem

On the alerts page a streamer can only place an alert at one of nine fixed spots - the corners, edges, and centre of a 3×3 grid. Each alert variant stores that choice in `twitch_alerts.screen_position` (`left-top` … `right-bottom`), the dashboard picks it with a grid of dots, and the overlay anchors the box to the matching corner with a fixed 24px margin.

The makers overlay already does better: it lets the streamer drag a box anywhere on a preview canvas and stores its position as a free x/y percentage. We want the alert box to be placed the same way - drag it to exactly where it should sit on stream - rather than snapping to one of nine presets.

The alerts page has one advantage makers doesn't: it already renders a live preview of the alert in the centre column. So instead of bolting on a separate mini editor, we make the preview itself the thing you drag.

## Goal

Let a streamer position each alert by dragging the live preview box around a true-to-scale canvas and dropping it where it belongs. The dropped spot is saved as a free x/y percentage and reproduced on the overlay. This replaces the 3×3 preset grid everywhere it appears - the main variant settings and the simpler weather / death-counter / walk-on panels - so there is a single positioning surface for every category.

## Design decisions

1. **Drag the live preview, not a separate editor.** The box already shown in the centre preview becomes draggable. Where the streamer drops it is where the alert lands. This is the most direct "what you see is what you get" we can offer, and it avoids a second positioning widget that would duplicate the preview.

2. **The preview becomes a true OBS canvas.** Today the preview is a freeform box whose width and height the streamer types in, positioned by flexbox alignment. We replace that with a fixed 16:9 canvas representing the OBS browser source, plus a resolution selector - **1280×720 (720p) as the minimum, 1920×1080, and 2560×1440 (2K) as the maximum**, matching the makers editor. The box renders true-to-scale for the chosen resolution so the preview reflects real proportions.

3. **Free x/y replaces the nine presets.** Both 3×3 grids (the main variant grid and the simple-category grid) are removed. Dragging - with snapping to edges, centre, and thirds - covers everything the presets did, with the precision the presets couldn't.

4. **Position is stored as the box's top-left, as a 0–100% of the canvas**, the same convention makers uses. Because the canvas is always 16:9, the stored percentage is resolution-independent: a box placed at 720p sits in the same relative spot at 2K.

5. **New columns, with the old one kept as a fallback.** Two nullable `DECIMAL(5,2)` columns - `position_x` and `position_y` - are added to `twitch_alerts`. The legacy `screen_position` column stays in the schema but is no longer written by the new UI. Existing users keep their spot: the overlay falls back to the old preset (then to the per-category default) whenever x/y is null, and the editor seeds the box from the old preset the first time it opens so the first drag starts where the streamer already was.

6. **Every category drags, including the simple ones.** Weather, death counter, and walk-ons share the same draggable canvas. Weather and deaths already render a sample in the preview; walk-ons don't (their mode is per-viewer), so a representative walk-on card sample is shown purely as the drag target.

## Data model

Two new columns on the per-user `twitch_alerts` table:

```
position_x DECIMAL(5,2) DEFAULT NULL
position_y DECIMAL(5,2) DEFAULT NULL
```

They hold the box's top-left corner as a percentage of the canvas (0–100, two decimals). `NULL` means "never positioned by drag" - the overlay then falls back to `screen_position`, then to the category default.

Both columns are declared in the `twitch_alerts` definition inside the per-user schema manager (`dashboard/includes/usr_database.php`). That manager compares the declared columns against each database's actual columns and issues an `ALTER TABLE … ADD` for anything missing on the next dashboard page load, so the columns propagate to every existing user database without a standalone migration script. `screen_position` is left in place and untouched.

## Positioning behaviour

**The convention.** The stored x/y is the box's top-left, as a percentage of the canvas. On the overlay the box is placed with `left` and `top` at those percentages, anchored top-left (no centring transform). Because alert content varies in size - a long username, an image, a short word - the box could otherwise run off the edge, so the overlay clamps the placement using the box's *rendered* size: the top-left is pulled back so the box's right and bottom stay inside the viewport. This mirrors makers, which clamps a chip's top-left to the canvas minus the chip's own size.

**In the dashboard.** The streamer drags the real preview box, so its true rendered size is known at drag time. The same clamp keeps it on the canvas, and snapping aligns its near edge, centre, or far edge to the canvas edges, centre, and thirds - and to a guide line that appears only while a snap is active. Holding Alt bypasses snapping for free placement, as in makers.

**Animations.** The resting position is `left`/`top`; the in/out animations are transforms and opacity, so they compose with the absolute position without fighting it. The box animates into and out of the spot it was dragged to.

## Dashboard changes (`alerts.php`)

### Preview panel

The freeform width/height number inputs are replaced by a resolution selector offering 720p, 1080p, and 2K, with 720p and 2K called out as the supported minimum and maximum. The preview area becomes a fixed 16:9 frame - the OBS canvas - and switches from flex-alignment positioning to absolute positioning, with the box placed at its stored x/y. Autoplay and the background swatches stay as they are.

### Dragging

The preview box (and the weather/deaths sample, and the new walk-on sample) is made draggable with pointer events: grab, move, clamp, snap, release. While dragging, thin guide lines flag an active snap. On release the box's clamped top-left percentage is written to the variant's working state and marked for saving. The snapping, guide, and expand logic is ported from the makers editor and adapted to operate on the real box's measured size rather than a synthesised chip footprint.

An expand-to-fullscreen control - the same affordance makers has - promotes the canvas to a large centred panel for precise placement, backed by a dim backdrop, closable with the button, the backdrop, or Escape. It is the same canvas and box, only larger, so dragging and saving keep working untouched.

### Removing the grids

The `position-grid-form` grid in the main variant settings and the `position-grid-simple` grid in the simple-category panel are both removed, along with the click handlers that drove them and the flex-based `applyPreviewPosition` helper. The centre canvas is now the only place position is set, for every category.

### Saving

For the main alert variants, x/y folds into the existing bundled save (the `save_alert` action) alongside the rest of the variant's settings. For the simple categories, which save immediately on change today, the existing immediate-save action is reworked to persist x/y instead of a preset. The server clamps x/y to 0–100 before writing.

### Seeding the editor

When a variant opens, the box starts at its stored x/y. If those are null but a legacy `screen_position` is present, a small map converts the preset to its equivalent x/y so the box appears where the preset used to put it. If both are absent, it falls back to the per-category default (weather top-left, deaths bottom-left, everything else centred).

## Overlay changes (`overlay/index.php`)

The overlay's `applyScreenPosition` helper becomes position-aware. It prefers the variant's `position_x`/`position_y` - placing the box at those percentages, top-left anchored, then clamping with the rendered box size so dynamic content never overflows. When x/y is absent it falls back to the existing preset behaviour, and when that's absent too, to the category default. The four places that position something - the main alert container, the death overlay, the weather overlay, and the walk-on overlay - all flow through this helper, and each passes the relevant variant's x/y along with the category it already passes.

## Documentation tweak (`makers.php`)

The makers canvas-size selector already offers exactly 720p, 1080p, and 2K. We make the supported range explicit there - a short hint by the selector and a clarifying comment - stating the 1280×720 minimum and 2560×1440 maximum, so the two pages document the same constraint and stay in lockstep.

## Internationalization

The new labels - the resolution/canvas-size label, the drag hint, the snap hint, and the expand/collapse controls - go through the dashboard's `t()` layer. Keys are added to the English base and to the German and French files per the project's i18n rule, with French apostrophes escaped. Where the makers editor already has an equivalent string, the alerts keys mirror its wording.

## Out of scope (deliberately)

- Per-resolution positions. One stored percentage serves all three resolutions because the canvas is always 16:9.
- Rotation, scaling, or free resizing of the box by drag. Size still comes from the existing layout and font settings; only position is dragged.
- Changing how the alert box itself is built, animated, or themed.
- Touching any bot, the API server, or the websocket server. This is a dashboard-plus-overlay change against the per-user database.

## Risks and edge cases

- **Rendered alert differs from the preview.** Fonts and images load late and content length varies, so the live alert may be a slightly different size than the preview box. The overlay re-clamps at render using the actual size, so the alert stays on-screen; the dragged top-left is the intent and the clamp protects the edges.
- **Walk-ons have no live sample.** Their per-viewer mode means there's nothing to preview, so the editor shows a representative card sample as the drag target only. The saved position still applies to the real walk-on card and video on the overlay.
- **A user who never re-saves.** Their x/y stays null and the overlay keeps honouring their old preset, so nothing moves until they choose to drag. Opening the editor seeds the box from that preset, so their first drag starts from the familiar spot.
- **Losing the freeform preview sizes.** Anyone who used odd width/height previews loses that, but real OBS sources are 16:9; the fixed canvas is more accurate, not less.
- **The long save type-string.** `save_alert` binds a large, order-sensitive parameter list; adding two decimal parameters has to keep the type string and the value order aligned. This is called out so it's checked carefully rather than assumed.

## How we'll know it's right

Pick a follow variant, drag its preview box to the upper-left third, and save. The overlay then shows the follow alert in that upper-left spot, clamped on-screen, and reloading the dashboard puts the box back where it was dropped. Switch the canvas to 2K and the box holds the same relative position. A legacy variant with `screen_position = 'right-bottom'` and null x/y still renders bottom-right on the overlay, and opening its editor starts the box bottom-right rather than jumping to centre. Each touched file passes its language's syntax check before the change is considered done.

## Areas of the system affected

| Area | Change |
| ---- | ------ |
| Per-user schema manager (`dashboard/includes/usr_database.php`) | Add `position_x` / `position_y` `DECIMAL(5,2)` to the `twitch_alerts` definition so they auto-migrate everywhere |
| `dashboard/alerts.php` | Reframe the preview as a 16:9 canvas with a resolution selector; make the box draggable with snap + expand; remove both 3×3 grids; seed from x/y → preset → default; save x/y (bundled and immediate) |
| `dashboard/css/alerts.css` | Canvas, draggable box, snap-guide, and expand/backdrop styles (alerts-namespaced, theme tokens); retire the now-unused grid styles |
| `overlay/index.php` | Make `applyScreenPosition` prefer x/y with a render-time clamp, falling back to preset then default; pass x/y through the four call sites |
| `dashboard/makers.php` | Make the 720p-min / 2K-max range explicit by the canvas-size selector |
| Dashboard language files (`en` / `de` / `fr`) | New position/canvas/drag translation keys |
