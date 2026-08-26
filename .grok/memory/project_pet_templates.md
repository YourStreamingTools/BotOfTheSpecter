---
name: project_pet_templates
description: "Shared pet starter packs on the CDN (Specter, Specter Bot, plus cat/dog/bat/alien/squirrel/chicken/cow/duck/bunny). 128×128 cells, 30 frames, 15 FPS. template: sprite_file tokens; first enable seeds Specter."
metadata:
  node_type: memory
  type: project
---

# Pet starter packs

Streamers can use operator art instead of uploading their own. Packs live on the CDN so they do not count against a channel’s media quota or the 2G hot-media cache on web1.

## Packs

| Id | Public name | Files |
|----|-------------|--------|
| `specter` | Specter | purple ghost |
| `bot` | Specter Bot | purple hover robot (visor + antenna) |
| `cat` | Cat | orange tabby |
| `dog` | Dog | golden puppy |
| `bat` | Bat | purple-gray bat |
| `alien` | Alien | green visitor |
| `squirrel` | Squirrel | fluffy tail |
| `chicken` | Chicken | plump hen |
| `cow` | Cow | black-and-white |
| `duck` | Duck | yellow duckling |
| `bunny` | Bunny | cream rabbit |

Public URLs: `https://cdn.botofthespecter.com/pet-templates/{id}/{idle,happy,hype,sad,sleep,eat}.png`

On-disk (rclone `cdn` mount): `/var/www/cdn/pet-templates/{id}/`

## Sheet contract (starters and custom uploads that should look the same)

- PNG, transparent background
- Horizontal strip, **128×128** pixels per frame
- **30** frames, **15** FPS, loop on (two display frames on a 30 FPS stream; 30 FPS playback would make a 1s loop with this frame count)
- Sheet size **3840×128**
- States: idle, happy, hype, sad, sleep, eat

The overlay samples a grid of `frame_width` × `frame_height` cells. Keep the character in the same place in every cell.

## How it is stored

`pet_animations.sprite_file` for a starter is `template:{pack}/{file}.png` (example `template:specter/idle.png`). Overlay and dashboard resolve the CDN URL from that token. Playback fps / frame size for templates come from `dashboard/includes/pet_templates.php`, not from stale DB rows, so timing can be retuned without asking every channel to re-apply.

Custom uploads still store a basename under `/var/www/media/{user}/pet/` and count toward storage.

## Streamer flow (what the Pet page tells them)

1. Enable the pet overlay. First enable with zero animations applies **Specter**.
2. Optional: Starter pets → **Specter Bot**.
3. Copy the OBS browser-source URL. Size **800×200**, transparent background.
4. Own art: match the sheet contract above.

Dashboard copy lives in `dashboard/lang/{en,de,fr,es,zh}.php` (`pet_setup_*`, `pet_templates_*`, `pet_upload_help`).

## Code

- Catalog: `dashboard/includes/pet_templates.php`
- Apply / auto-seed: `dashboard/pet.php` (`apply_template`, first `set_enabled`)
- Overlay URL resolve: `overlay/pet.php` via `pet_resolve_sprite_url`

Default pack on first enable is `specter`. Do not copy starter files into per-user media folders.
