# Dashboard i18n Audit — hardcoded user-facing text not in lang files

**Date:** 2026-05-31
**Status:** Audit / inventory — feeds the i18n remediation plan

I went through the dashboard — 109 top-level pages plus the `admin/` and `todolist/` subdirectories — hunting for user-facing text that's still hardcoded in English instead of going through the translation layer. Our i18n system keys everything off `lang/en.php` (en is the base, with `de.php` and `fr.php` mirroring it), and adoption so far is only partial. This is the inventory I'm working from to plan the cleanup and decide what to tackle first.

Where things stand:

- **Gaps found:** 827 (758 high-confidence, 64 medium, 5 low).
- **Files affected:** 95 have gaps; 38 came back clean.
- **This is an undercount.** A few large files — `working-or-study.php` (~1677 lines), `modules.php` (~2800), `bot.php`, `channel_rewards.php`, `dashboard.php` — could only be partially scanned, so the true total is higher, probably north of 1000.
- **Admin pages are likely out of scope.** Everything under `dashboard/admin/` is internal tooling and is probably meant to stay English-only. That covers: `api_keys.php`, `beta_programs.php`, `discordbot_overview.php`, `event_sub.php`, `feedback.php`, `logs.php`, `service_status.php`, `spam_patterns.php`, `start_bots.php`, `stream_command.php`, `terminal.php`, `terminal_stream.php`, `twitch_tokens.php`, `users.php`, `websocket_clients.php`. I've still inventoried them below for completeness, but I'd hold off translating them unless we decide admin localization is worth it.

## How I'll work through this

The per-file summary below is sorted worst-first by high-confidence count, and that doubles as the work order: knock out the heaviest offenders (`working-or-study.php`, `dashboard.php`, `obs_options.php`, `modules.php`, `media.php`, `channel_rewards.php`) first, since they account for most of the visible English text, then sweep the long tail of small files. For each file the job is the same: pull the hardcoded strings into `t()` calls, add the keys to `lang/en.php`, and mirror them in `de.php` and `fr.php`. The detailed lists further down are the raw material — every string that needs a key, grouped by file and by the kind of UI element it sits in.

## Per-file summary (worst first)

| File | high | med | low | admin? |
|---|--:|--:|--:|:-:|
| working-or-study.php | 73 | 0 | 0 |  |
| dashboard.php | 70 | 2 | 0 |  |
| obs_options.php | 43 | 0 | 0 |  |
| modules.php | 35 | 0 | 0 |  |
| media.php | 32 | 2 | 0 |  |
| channel_rewards.php | 27 | 0 | 0 |  |
| embed_builder_backend_handlers.php | 18 | 0 | 0 |  |
| layout.php | 17 | 1 | 0 |  |
| makers.php | 15 | 12 | 0 |  |
| bot.php | 15 | 1 | 3 |  |
| index.php | 14 | 0 | 0 |  |
| tanggle.php | 14 | 0 | 0 |  |
| custom_commands.php | 13 | 3 | 0 |  |
| create_reward.php | 13 | 0 | 0 |  |
| streamlabs.php | 13 | 0 | 0 |  |
| terminal.php | 13 | 0 | 0 | yes |
| video-alerts.php | 13 | 0 | 0 |  |
| remove.php | 12 | 3 | 0 |  |
| raffles.php | 12 | 0 | 0 |  |
| api_keys.php | 11 | 1 | 0 | yes |
| add_category.php | 10 | 0 | 0 |  |
| bot_action.php | 10 | 0 | 0 |  |
| discordbot_overview.php | 10 | 0 | 0 | yes |
| insert.php | 10 | 0 | 0 |  |
| login.php | 10 | 0 | 0 |  |
| videos.php | 10 | 0 | 0 |  |
| completed.php | 9 | 3 | 0 |  |
| logs.php | 9 | 1 | 2 | yes |
| download_stream.php | 9 | 0 | 0 |  |
| mod_channels.php | 9 | 0 | 0 |  |
| update_objective.php | 9 | 0 | 0 |  |
| event_sub.php | 8 | 1 | 0 | yes |
| manage_custom_user_commands.php | 8 | 0 | 0 |  |
| menu.php | 8 | 0 | 0 |  |
| music.php | 8 | 0 | 0 |  |
| streamelements.php | 8 | 0 | 0 |  |
| beta_programs.php | 7 | 1 | 0 | yes |
| feedback.php | 6 | 1 | 0 | yes |
| overlays.php | 6 | 0 | 0 |  |
| streaming.php | 6 | 0 | 0 |  |
| timed_messages.php | 6 | 0 | 0 |  |
| vips.php | 6 | 0 | 0 |  |
| walkons.php | 6 | 0 | 0 |  |
| categories.php | 5 | 3 | 0 |  |
| known_users.php | 5 | 3 | 0 |  |
| counters.php | 5 | 1 | 0 |  |
| get_custom_embed.php | 5 | 0 | 0 |  |
| check_bot_status.php | 4 | 0 | 0 |  |
| check_subscription.php | 4 | 0 | 0 |  |
| edit_reward.php | 4 | 0 | 0 |  |
| get_custom_embeds.php | 4 | 0 | 0 |  |
| subscribers.php | 4 | 0 | 0 |  |
| mods.php | 3 | 1 | 0 |  |
| check_blocked_term.php | 3 | 0 | 0 |  |
| fetch_banned_status.php | 3 | 0 | 0 |  |
| module_data.php | 3 | 0 | 0 |  |
| regen_api_key.php | 3 | 0 | 0 |  |
| update_banned_users_cache.php | 3 | 0 | 0 |  |
| update_use_custom.php | 3 | 0 | 0 |  |
| followers.php | 2 | 3 | 0 |  |
| notifications.php | 2 | 1 | 0 |  |
| alerts.php | 2 | 0 | 0 |  |
| bingo.php | 2 | 0 | 0 |  |
| bot_points.php | 2 | 0 | 0 |  |
| builtin.php | 2 | 0 | 0 |  |
| check_spam_pattern.php | 2 | 0 | 0 |  |
| notifications_content.php | 2 | 0 | 0 |  |
| premium.php | 2 | 0 | 0 |  |
| spam_patterns.php | 1 | 1 | 0 | yes |
| twitch_tokens.php | 1 | 1 | 0 | yes |
| users.php | 1 | 1 | 0 | yes |
| websocket_clients.php | 1 | 1 | 0 | yes |
| check_url_conflict.php | 1 | 0 | 0 |  |
| controllerapp.php | 1 | 0 | 0 |  |
| delete_stream.php | 1 | 0 | 0 |  |
| persistent_storage.php | 1 | 0 | 0 |  |
| profile.php | 1 | 0 | 0 |  |
| raids.php | 1 | 0 | 0 |  |
| recording.php | 1 | 0 | 0 |  |
| restricted.php | 1 | 0 | 0 |  |
| schedule.php | 1 | 0 | 0 |  |
| sound-alerts.php | 1 | 0 | 0 |  |
| spotifylink.php | 1 | 0 | 0 |  |
| start_bots.php | 1 | 0 | 0 | yes |
| stream_streak.php | 1 | 0 | 0 |  |
| userdata.php | 1 | 0 | 0 |  |
| serve_user_music.php | 0 | 5 | 0 |  |
| subathon.php | 0 | 3 | 0 |  |
| service_status.php | 0 | 2 | 0 | yes |
| save_discord_channel_config.php | 0 | 1 | 0 |  |
| send_welcome_message.php | 0 | 1 | 0 |  |
| server_metrics.php | 0 | 1 | 0 |  |
| stream_command.php | 0 | 1 | 0 | yes |
| terminal_stream.php | 0 | 1 | 0 | yes |
| user_db.php | 0 | 1 | 0 |  |

## Findings by file

Each file lists the hardcoded strings I found, grouped by the kind of UI element they sit in. These are the strings that need `t()` keys.

### working-or-study.php  (73)
- _heading_: Working / Study Overlay Control
- _button text_: Copy Timer Overlay Link
- _label_: Focus Sprint Duration
- _label_: Micro Break Duration
- _label_: Recharge Break Duration
- _button text_: Start Focus Sprint
- _button text_: Start Micro Break
- _button text_: Start Recharge Stretch
- _button text_: Update Overlay
- _button text_: Reset Overlay
- _button text_: Start
- _button text_: Pause
- _button text_: Resume
- _button text_: Reset
- _button text_: Stop
- _stat label_: Focus Sprints
- _stat label_: Micro Breaks
- _stat label_: Recharge Breaks
- _stat label_: Total Sessions
- _stat label_: Total Focus Time
- _section heading_: Channel Task Manager
- _button text_: Copy Link (Combined)
- _button text_: Copy Link (Streamer)
- _button text_: Copy Link (Users)
- _label_: Default Reward Points per Task
- _checkbox label_: Require streamer approval before awarding points
- _checkbox label_: Allow viewers to submit tasks
- _checkbox label_: Show tasks on overlay
- _table header_: Task
- _table header_: Status
- _table header_: Pts
- _table header_: Actions
- _table header_: User
- _table header_: Task
- _table header_: Status
- _table header_: Approval
- _empty state message_: No tasks yet.
- _empty state message_: No viewer tasks yet.
- _toast message_: Timer overlay link copied to clipboard!
- _button text (inline)_: Copied!
- _error toast message_: Failed to copy link to clipboard
- _toast message_: Streamer task list link copied!
- _button text (inline)_: Copied!
- _error toast message_: Failed to copy link
- _toast message_: Users task list link copied!
- _button text (inline)_: Copied!
- _error toast message_: Focus duration must be at least 1 minute
- _error toast message_: Micro break duration must be at least 1 minute
- _error toast message_: Break duration must be at least 1 minute
- _toast message (concatenated with data)_: Task created:
- _empty state message in table_: No tasks yet.
- _empty state message in table_: No viewer tasks yet.
- _button text_: Edit
- _button text_: Done
- _button text_: Delete
- _button text_: Done
- _button text_: Award
- _button text_: Reject
- _modal title text_: Edit Task
- _confirmation dialog_: Delete this task?
- _toast message_: Task deleted.
- _toast message_: Task completed.
- _toast message (template)_: Task for
- _toast message (template)_: Awarded task for
- _toast message_: Task rejected.
- _success toast message_: Settings saved.
- _error toast message_: Failed to save settings.
- _modal title text_: Add Streamer Task
- _warning toast message_: Title is required.
- _toast message_: Task updated.
- _toast message_: Task created.
- _error toast message_: Failed to save task.
- _error toast message (with concatenation)_: Network error:

### dashboard.php  (72)
- _page title variable (shown on page as $pageTitle)_: Management Dashboard
- _status subtext label (displayed on dashboard)_: Live Twitch subscriptions
- _status subtext label (displayed on dashboard)_: Live Twitch subscriptions
- _status subtext label (displayed on dashboard)_: Unable to read subscriber total from Twitch
- _status subtext label (displayed on dashboard)_: Unable to fetch subscribers from Twitch right now
- _status subtext label (displayed on dashboard)_: Channel is not Affiliate/Partner (no Twitch subs endpoint access)
- _status subtext label (displayed on dashboard)_: Missing Twitch auth/config for live subscriber count
- _bot status label (displayed on dashboard)_: Not Running
- _bot version label (fallback)_: Unknown
- _bot system status (displayed on dashboard)_: Not Running
- _bot version status (displayed on dashboard)_: Not Running
- _latest version label (medium, displayed on dashboard)_: N/A
- _page subtitle text_: Live overview of your bot systems, storage, and community activity.
- _section heading (dashboard section label)_: Channel Info
- _stat label (dashboard card)_: Followers
- _stat subtext (dashboard card)_: Tracked follower records
- _stat label (dashboard card)_: Subscribers
- _stat label (dashboard card)_: Raids
- _stat subtext with dynamic values (dashboard card)_: total raider viewers, unique raiders
- _section heading (dashboard section label)_: Dashboard Metrics
- _stat label (dashboard card)_: Active Bot
- _stat subtext (dashboard card)_: No active bot runtime
- _stat label (dashboard card)_: Storage Usage
- _stat label (dashboard card)_: Commands
- _stat subtext with counts (dashboard card)_: Custom, Built-in
- _stat label (dashboard card)_: To-Do Progress
- _stat subtext (dashboard card)_: task(s) remaining
- _badge text (bot status badge)_: Running, Stopped
- _snapshot item label (dashboard card)_: Known Users
- _snapshot item label (dashboard card)_: Live Lurkers
- _snapshot item label (dashboard card)_: Watch Time Profiles
- _snapshot item label (dashboard card)_: Rewards Configured
- _snapshot item label (dashboard card)_: Quotes Saved
- _snapshot item label (dashboard card)_: Moderator Channels
- _section heading (dashboard section label)_: Quick Links
- _card title (quick link card)_: Bot Control
- _card description (quick link card)_: Start, stop, and monitor your bot
- _card title (quick link card)_: Commands
- _card description (quick link card)_: Create and edit custom commands
- _card title (quick link card)_: Discord Bot
- _card description (quick link card)_: Manage your Discord integration
- _card title (quick link card)_: To-Do List
- _card description (quick link card)_: Manage your streaming tasks
- _card title (quick link card)_: Rewards
- _card description (quick link card)_: Manage channel rewards
- _card title (quick link card)_: DMCA Music
- _card description (quick link card)_: Safe music for streaming
- _card title (quick link card)_: Documentation
- _card description (quick link card)_: Learn how to use BotOfTheSpecter
- _aria-label attribute (medium, accessibility label)_: Toggle light or dark theme
- _hero section subtitle (landing page)_: Your Complete Twitch Bot Management Solution
- _hero section description (landing page)_: Take control of your Twitch channel with our powerful, feature-rich bot and dashboard. Manage commands, configure your a…
- _login card description (landing page)_: Join the rest of the streamers who use BotOfTheSpecter to enhance and manage their Twitch channel.
- _section heading (landing page)_: Dashboard Features
- _section description (landing page)_: Explore the powerful features that make BotOfTheSpecter the ultimate Twitch bot management solution, with many more feat…
- _feature card title (landing page)_: Bot Control
- _feature card description (landing page)_: Start, stop, and monitor your bot with real-time status updates and comprehensive logging.
- _feature card title (landing page)_: Custom Commands
- _feature card description (landing page)_: Create and manage custom chat commands with advanced features and permission levels.
- _feature card title (landing page)_: Analytics & Logs
- _feature card description (landing page)_: Track your channel's growth, monitor user activity, and analyze command usage statistics.
- _feature card title (landing page)_: Channel Rewards
- _feature card description (landing page)_: Manage Twitch channel point rewards and create engaging interactive experiences.
- _feature card title (landing page)_: Stream Alerts
- _feature card description (landing page)_: Configure sound alerts, video alerts, and walk-on alerts for followers and subscribers.
- _feature card title (landing page)_: Integrations
- _feature card description (landing page)_: Connect with Discord, Spotify, StreamElements, and other popular streaming platforms.
- _feature card title (landing page)_: Points System
- _feature card description (landing page)_: Reward your viewers with a custom points system and create point-based mini-games.
- _feature card title (landing page)_: Stream Overlays
- _feature card description (landing page)_: Create dynamic overlays for recent followers, latest donations, and now playing music.
- _feature card title (landing page)_: User Management

### obs_options.php  (43)
- _error message echoed to user_: Error updating settings:
- _error message echoed to user_: Error inserting settings:
- _card header title_: OBS Font & Color Settings
- _button text_: How to put on your stream
- _modal title_: How to use the ToDo List in OBS
- _modal instruction text_: The ToDo List is fully compatible with any streaming software: OBS, SLOBS, xSplit, Wirecast, etc.
- _modal instruction text_: All you have to do is add the following link (with your API key from your profile page) into a browser source and it wor…
- _modal instruction text_: If you wish to define a working category, add it like this:
- _modal instruction text_: (where ID 1 is called Default, defined on the
- _link text in modal_: categories
- _modal instruction text_: page.)
- _modal instruction text_: To add a styled box around your list (useful if your stream overlay makes it hard to read), add
- _modal instruction text_: This wraps the list in a dark semi-transparent box with rounded corners, helping it stand out over any stream overlay. Y…
- _button text_: Close
- _heading text_: Font & Color Settings:
- _label text_: Font:
- _label text_: Color:
- _label text_: List Type:
- _label text_: Font Size:
- _label text_: Text Shadow:
- _badge/span text_: Enabled
- _badge/span text_: Disabled
- _label text_: Text Bold:
- _label text_: Show Completed:
- _span text_: Yes
- _span text_: No
- _alert title text_: Customize your lists!
- _alert body text_: No font and color settings have been set. Use the controls below to personalize the look of your lists.
- _form label_: Font
- _select option labels_: Arial, Arial Narrow, Verdana, Times New Roman
- _form label_: Color
- _select option labels_: Black, White, Red, Blue, Other
- _form label_: Custom Color
- _form label_: List Type
- _select option labels_: Bullet List, Numbered List
- _form label_: Font Size
- _form label_: Text Shadow
- _checkbox label text_: Enable shadow
- _form label_: Text Bold
- _checkbox label text_: Enable bold
- _form label_: Show Completed Tasks
- _checkbox label text_: Show completed tasks
- _button text_: Save

### modules.php  (35)
- _h2 heading text (visible page heading)_: Variables for Modules
- _tab span text_: Game Deaths
- _tab span text_: Automated Shoutouts
- _tab span text_: TTS Settings
- _tab span text_: Custom Module Bots
- _sp-badge span text_: Joke Command
- _h2 heading text_: Welcome Message Configuration
- _sp-badge span text_: Welcome Messages
- _p descriptive text_: Toggle automatic welcome messages for viewers
- _h5 heading text_: Regular Members
- _button span text_: Save Regular Members
- _h5 heading text_: VIP Members
- _button span text_: Save VIP Members
- _h5 heading text_: Moderators
- _button span text_: Save Moderators
- _h4 heading text_: URL Blocking System Overview (Version 5.8)
- _p bold text_: How URL Blocking Works:
- _li list items and span strong text_: Multiple inline text segments: 'Blacklist (Always Active)', 'Code Red', 'URL Blocking Enabled', 'Twitch.tv and clips.twi…
- _label text_: Blocking Mode
- _option value label_: Allow for all commands
- _option value label_: Allow for selected commands only
- _label text_: Commands to block until user has chatted
- _p field-help text_: Includes built-in and custom commands. Hold Ctrl (Windows) or Cmd (Mac) to select multiple commands.
- _td colspan text_: No whitelisted links configured
- _td colspan text_: No blacklisted links configured
- _h2 heading text_: Text Term Blocking
- _h4 heading text_: Term Blocking System (Beta Feature)
- _h3 heading text_: Enable Term Blocking
- _h3 heading text_: Add Blocked Term
- _input placeholder text_: Enter term to block...
- _button span text_: Add to Blocked Terms
- _h3 heading text_: Blocked Terms List
- _td colspan text_: No blocked terms configured
- _button span text_: Remove
- _h2 heading text_: Game Deaths Configuration

### media.php  (34)
- _JSON error messages from AJAX endpoint (medium)_: "Invalid login", "Bot credentials unavailable", "Helix request failed", "User not found"
- _Twitch event type labels (inline array)_: "Follow", "Raid", "Cheer", "Subscription", "Gift Subscription", "Hype Train Start", "Hype Train End"
- _status update messages concatenated to $status_: "Sound alert mapping added.", "Sound alert mapping removed.", "Video alert mapping added.", "Video alert mapping removed…
- _upload error status messages_: "Error uploading ...", "Failed to upload ...", "Storage limit exceeded ...", "Not an image (png/jpg/gif): ...", "File co…
- _delete operation status messages_: "Failed to delete ...", error explanation text with file references
- _card header title_: "Upload Media"
- _help text paragraph_: "Upload audio (MP3), video (MP4/WEBM) or images..." and long description text
- _drop zone UI text_: "No files selected", "Click or drag files here"
- _upload progress display text (medium)_: "Preparing upload...", "0%"
- _button text_: "Upload Media"
- _card header title_: "Upgrade to Unified Media Library"
- _migration notice and description blocks (user-facing text)_: "Important — read before you migrate.", "The unified media library only works on ...", "Bot version 5.8 and above", "You…
- _filter button labels and search placeholder_: "All", "With rewards", "With events", "Walkons", "Unused", "Videos", "Search files…"
- _card header and button text_: "Your Media Library", "Delete Selected"
- _empty state message_: "No media files uploaded yet..."
- _dynamic summary text fragments_: " reward", " event", " walkon", " alert", "Unused" (summary text building)
- _button title/tooltip_: "Images are managed via the alerts builder"
- _button title/tooltip_: "This file is in use. Remove its links before deleting."
- _modal info text (readonly usage chips)_: "Not attached to any alert variant yet..." and "Open Specter Alerts and use Browse library..."
- _modal section titles_: "Channel Point Rewards (Video)", "Channel Point Rewards", "Twitch Events", "Walkons", "Used by Alert Builder"
- _empty mapping message in modal_: "No mappings yet."
- _input placeholder and button text in modal_: "Twitch username…", "+ Add user"
- _SweetAlert dialog title and text_: "No Files Selected", "Please select at least one file to upload."
- _dynamic upload status text_: "Uploading ... file(s)..."
- _upload progress status text_: "Processing files on server..."
- _success status text_: "Upload completed successfully!"
- _SweetAlert error dialogs_: "Upload Failed", "An error occurred during upload.", "An error occurred during upload. Please try again."
- _SweetAlert confirmation dialog_: "Delete Files?", "Are you sure you want to delete the selected ... file(s)?"
- _button labels in SweetAlert_: "Yes, delete", "Cancel"
- _SweetAlert confirmation dialog_: "Delete File?", "Are you sure you want to delete...", "Yes, delete", "Cancel"
- _SweetAlert info dialog_: "File is in use", "... is still attached to ...", "Open the file to remove its channel-point reward..."
- _SweetAlert confirmation dialog_: "Migrate to Unified Library?", "Beta Bot required.", "The Stable Bot (5.7.x) cannot fire alerts...", "Version 5.8 will r…
- _button labels in SweetAlert_: "Yes, migrate", "Cancel"
- _SweetAlert success/error dialogs_: "Migration Complete", "Migration Failed", "Server error during migration."

### channel_rewards.php  (27)
- _button text in SweetAlert_: Continue
- _button text in SweetAlert_: Cancel
- _SweetAlert title_: Success!
- _SweetAlert title_: Error!
- _SweetAlert error message_: An error occurred during creation.
- _SweetAlert title_: Error!
- _SweetAlert error message_: An error occurred while processing your request.
- _SSE stream output line_: Connecting to the sync service...
- _SSE stream output line_: [ERROR] Unable to read completion details.
- _SSE stream output message_: [PROCESS DONE]
- _SSE error message_: [ERROR] Connection interrupted; waiting for the script to finish.
- _SweetAlert error title_: Error
- _SweetAlert validation error message_: Please fill in Title and Cost.
- _button text during form submission_: Creating...
- _SweetAlert success title_: Success!
- _SweetAlert error title_: Error!
- _SweetAlert error message_: A network error occurred.
- _SweetAlert title_: Error
- _SweetAlert error message_: Could not find reward data. Try syncing first.
- _SweetAlert title_: Error
- _SweetAlert validation error message_: Title and Cost are required.
- _button text during save operation_: Saving...
- _button text for edit form submission_: Save Changes
- _SweetAlert success title_: Success!
- _SweetAlert success message_: Reward updated successfully.
- _SweetAlert title_: Error
- _SweetAlert error message_: Network error occurred.

### embed_builder_backend_handlers.php  (18)
- _JSON error message returned to user_: Embed name is required
- _JSON error message returned to user_: Database error:
- _JSON success message returned to user_: Embed updated successfully
- _JSON error message returned to user_: Failed to update embed:
- _JSON error message returned to user_: Database error:
- _JSON success message returned to user_: Embed created successfully
- _JSON error message returned to user_: Failed to create embed:
- _JSON error message returned to user_: Embed ID and Channel ID are required
- _JSON error message returned to user_: Channel ID cannot be empty
- _JSON error message returned to user_: API key not found. Please refresh the page and try again.
- _JSON error message returned to user_: Failed to initialize HTTP request
- _JSON error message returned to user_: HTTP request failed:
- _JSON error message returned to user_: Failed to send embed to Discord channel
- _JSON success message returned to user_: Embed sent to Discord channel successfully
- _JSON error message returned to user_: Embed not found
- _JSON error message returned to user_: Embed ID is required
- _JSON success message returned to user_: Embed deleted successfully
- _JSON error message returned to user_: Failed to delete embed:

### layout.php  (18)
- _page title (HTML title tag)_: "BotOfTheSpecter"
- _meta description tag (medium)_: "BotOfTheSpecter is a powerful bot system designed..."
- _sidebar brand text (layout mode)_: "Admin Panel"
- _sidebar brand text (layout mode)_: "To Do List"
- _sidebar brand text (default mode)_: "BotOfTheSpecter"
- _HTML page title_: "BotOfTheSpecter - Twitch Login"
- _sidebar menu link text_: "User Dashboard"
- _sidebar menu link text_: "Mod Channels"
- _top bar tag (admin mode)_: "ADMIN DASHBOARD — Restricted Access"
- _top bar tag (dev stream indicator)_: "Dev Stream Online — ..."
- _top bar tag (act-as mode)_: "Viewing as <strong>..." and stop acting as label
- _top bar tag (maintenance mode)_: "Maintenance in progress — Some features may be temporarily unavailable"
- _modal title_: "Maintenance Notice"
- _modal body text describing maintenance_: "We are currently performing maintenance..."
- _modal body content (maintenance info)_: "What you can expect:", list items, "Thank you for your understanding!"
- _modal button text_: "I Understand"
- _modal button text_: "Don't show again today"
- _footer copyright and legal text_: "All rights reserved", "BotOfTheSpecter is a project operated..."

### makers.php  (27)
- _page title_: "Makers & Crafting Overlay"
- _JSON error message response (medium)_: "Title is required"
- _JSON error message response (medium)_: "Link must start with http:// or https://"
- _JSON error message response (medium)_: "Invalid project"
- _JSON error message response (medium)_: "Invalid project"
- _JSON error message response (medium)_: "Invalid project"
- _JSON error message response (medium)_: "No such project"
- _JSON error message responses (medium)_: "Invalid project", "No files received"
- _JSON error messages in array (medium)_: "Not an image (png/jpg/gif): ...", "Contents do not match type: ...", error messages in upload loop
- _JSON error message response (medium)_: "Project and filename required"
- _JSON error message response (medium)_: "Invalid image"
- _JSON error message response (medium)_: "Unknown action"
- _card heading_: "Makers &amp; Crafting Overlay"
- _info text paragraphs_: "Show your viewers what you're making..." and "!craft" command info
- _card header title_: "Overlay Settings"
- _form labels and button text_: "Display mode", "Position", "Font", "Image change (sec)", "Project rotate (sec)", "Accent colour", "Text colour", "Overl…
- _select option values_: "Current project", "Finished projects", "Upcoming ideas", "Top left", "Top right", "Bottom left", "Bottom right"
- _card header title_: "Add Project"
- _input placeholder text_: "e.g. Hand-knit winter scarf"
- _select option values_: "Current", "Upcoming", "Finished"
- _card header title_: "Project Library"
- _empty state text_: "No projects yet..." and "!craft new <title>" command reference
- _badge label_: "Featured"
- _button title/tooltip (medium)_: "Feature as current" (title attribute)
- _form label_: "Description / context"
- _form label and placeholder_: "Link (optional)", "https://..."
- _alert/confirm messages shown to user_: "Saved!", "Error: ...", "Could not add project", "Delete this project and its images?", "Failed"

### bot.php  (19)
- _JavaScript notification message_: Session expired. Redirecting to login...
- _JavaScript notification message_: Failed to stop v6 bot: (dynamic)
- _JavaScript notification message_: Error processing request: (dynamic)
- _JavaScript notification message_: Checking (bot) bot status...
- _JavaScript notification message_: bot status check timed out. Please refresh the page to check current status.
- _JavaScript notification message_: Checking (bot) bot status... (dynamic/dynamic)
- _JavaScript success notification_: bot is now running with PID (dynamic)!
- _JavaScript success notification_: bot appears to be running! Refreshing status...
- _JavaScript notification message_: bot status verification timed out. The bot may take longer to (action).
- _JavaScript success notification_: bot (action) successfully and is now (status)!
- _aria-label on close button (medium)_: Dismiss
- _JavaScript notification message_: A new (bot) bot version is available!
- _JavaScript notification message_: Beta bot code has been updated since your last run. Please restart the bot to apply changes.
- _tag text in version card_: Code Update Available; Update Available
- _fallback text for lastModified (low)_: Unknown
- _status text when bot not running_: Offline
- _fallback text for API errors (low)_: Error loading
- _hardcoded service status text_: Monitoring Disabled
- _technical info text (low)_: (Dead)

### index.php  (14)
- _card header title_: Your Tasks
- _form label_: Search Objectives
- _input placeholder_: Search...
- _form label_: Filter by Category
- _select option text_: All
- _alert text_: Your to-do list is empty!
- _alert text_: Start adding tasks to get organized.
- _paragraph text (numeric display)_: Number of total tasks in the category:
- _badge text_: Completed
- _badge text_: Not completed
- _badge text_: Private
- _paragraph text label_: Created:
- _paragraph text label_: Updated:
- _relative time format strings in JavaScript_: 'second'/'seconds'/'minute'/'minutes'/'hour'/'hours'/'day'/'days'/'month'/'months'/'year'/'years' + ' ago'

### tanggle.php  (14)
- _heading_: Tanggle Integration
- _status message_: Configuration Required
- _label_: API Access Token
- _label_: Community UUID
- _heading_: How to Get Your Tanggle Credentials
- _section heading_: Active Puzzle
- _section heading_: Puzzle Queue
- _section heading_: Recent Completed Puzzles
- _table header_: Puzzle
- _table header_: Winner
- _table header_: Pieces
- _table header_: Completed
- _empty state message_: No Active Puzzle
- _empty state message_: No Completion History Yet

### custom_commands.php  (16)
- _flash message (session-stored status displayed on redirect)_: Failed to update: The new command name matches a built-in command.
- _flash message (session-stored status displayed on redirect)_: Command [name] updated successfully!
- _flash message (session-stored status displayed on redirect)_: [name] not found or no changes made.
- _flash message (session-stored status displayed on redirect)_: Error updating [name]:
- _flash message (session-stored status displayed on redirect)_: Failed to add: The custom command name matches a built-in command.
- _flash message (session-stored status displayed on redirect)_: Failed to add: The command '![name]' already exists in the list.
- _flash message (session-stored status displayed on redirect)_: Command removed successfully
- _flash message (session-stored status displayed on redirect)_: Error removing command:
- _checkbox label text_: Using Beta Bot? Enables 500 character limit.
- _button span text_: Manage options for your command (Beta 5.8)
- _form label_: Permission Level
- _button span text_: Manage options for your command (Beta 5.8)
- _form label_: Permission Level
- _form label (medium, JavaScript modal)_: Options (one per line)
- _textarea placeholder text (medium, JavaScript modal)_: Item 1, Item 2, Item 3
- _help text (medium, JavaScript modal)_: No limit on item count. Empty lines are ignored.

### create_reward.php  (13)
- _JSON error message (returned to user via AJAX)_: Client ID not configured
- _JSON error message (returned to user via AJAX)_: Missing required fields (title, cost)
- _JSON error message (returned to user via AJAX)_: Invalid title: required and max 45 characters
- _JSON error message (returned to user via AJAX)_: Invalid cost: must be at least 1
- _JSON error message (returned to user via AJAX)_: Invalid prompt: maximum 200 characters
- _JSON error message (returned to user via AJAX)_: Invalid max_per_stream: must be at least 1 when enabled
- _JSON error message (returned to user via AJAX)_: Invalid max_per_user_per_stream: must be at least 1 when enabled
- _JSON error message (returned to user via AJAX)_: Invalid global_cooldown_seconds: must be at least 1 when enabled
- _JSON message (returned to user via AJAX)_: Reward already exists
- _JSON error message (returned to user via AJAX)_: Twitch API Error (duplicate):
- _JSON error message (returned to user via AJAX)_: Twitch API Error: channel has reached maximum number of custom rewards
- _JSON error message (returned to user via AJAX)_: Created but no ID returned
- _JSON message (returned to user via AJAX)_: Created on Twitch (DB Sync Failed)

### streamlabs.php  (13)
- _heading_: StreamLabs Integration
- _status badge_: Linked
- _status badge_: Not Linked
- _success message_: StreamLabs account successfully linked!
- _button text_: Unlink
- _section heading_: Recent Donations
- _table header_: Donor
- _table header_: Amount
- _table header_: Message
- _table header_: Date
- _empty state message_: No donations yet
- _button text_: Copy Access Token
- _label_: Socket Token (Real-time Events)

### terminal.php  (13)  *(admin)*
- _h1 heading text_: Web Terminal
- _paragraph description text_: Execute commands on remote servers and view live output. Select a server and enter commands below.
- _label text_: Select Server
- _select option placeholder_: Choose a server...
- _select option values_: Bot Server, Web Server, API Server, WebSocket Server, SQL Server
- _label text_: Command
- _input placeholder_: Enter command...
- _help text_: Press Enter or click Execute. Use 'clear' to reset the terminal.
- _h2 heading text_: Terminal Tools
- _label text_: Quick Preset, Saved Snippets
- _select option placeholders_: Select preset command..., Select saved snippet...
- _button text_: Run, Save, Execute, Clear Terminal, Interrupt, Copy Output, Download Log
- _badge text_: Status: waiting for server selection, Server: none

### video-alerts.php  (13)
- _section heading_: How-to info panel
- _section heading_: Upload
- _section heading_: File Management
- _table header_: Select
- _table header_: File Name
- _table header_: Channel Point Reward
- _table header_: Action
- _button text_: Delete Selected
- _button text_: Remove Mapping
- _button text_: Select Reward
- _status text_: Not Mapped
- _JavaScript alert text_: No Files Selected
- _JavaScript alert text_: Upload Failed

### remove.php  (15)
- _card header title_: Remove a Task
- _alert title text_: Your to-do list is empty!
- _alert body text_: You can't remove any tasks because there aren't any yet.
- _form label_: Search Tasks
- _input placeholder_: Search todos
- _form label_: Filter by Category
- _select option text_: All
- _heading text_: Please pick which task to remove from your list:
- _span text_: Uncategorized
- _badge text_: Completed
- _badge text_: Not completed
- _button text_: Remove
- _SweetAlert title (medium)_: Are you sure?
- _SweetAlert text (medium)_: This will remove the task.
- _SweetAlert button text (medium)_: Yes, remove it!

### raffles.php  (12)
- _pageTitle variable_: Raffles
- _sp-alert div text_: Beta Feature: This is a beta 5.8 version feature currently in testing. Functionality may change or have unexpected behav…
- _span and label text in form_: Create New Raffle, Raffle Name, Prize Description, What are they winning?, Number of Winners
- _label text in checkboxes_: Enable Weighted Raffle (subscribers and VIPs get enhanced odds), Exclude Moderators from winning, Only Subscribers Can E…
- _label text_: Require Minimum Follow Time
- _label and option text_: Minimum Follow Time, Days, Weeks, Months, Years
- _button span text_: Create Raffle
- _sp-card-title span text_: Active Raffles
- _table th headers_: ID, Name, Prize, # Winners, Status, Weights, Exclusions, Winner(s), Action
- _span sp-badge text_: ✓ Weighted
- _string literals in $exclusions array pushed to output_: Mods excluded, Subs only, Followers only
- _button span text and icon_: Start, Draw

### api_keys.php  (12)  *(admin)*
- _page title (visible heading)_: API Key Management
- _h1 heading text_: API Key Management
- _paragraph description text_: Manage admin API keys for different services
- _h2 heading text_: Create New API Key
- _form label text_: Service Name
- _input placeholder_: Enter service name
- _sp-help text_: Enter a unique name for the service that will use this API key
- _button text_: Generate API Key
- _h2 heading text_: Existing API Keys
- _alert notification text_: No API keys found. Create one above.
- _table header cells_: Service, API Key, Actions
- _JSON messages returned to user (medium, SweetAlert/alert)_: Service name cannot be empty, API key created successfully, Failed to create API key, Failed to delete API key, etc.

### add_category.php  (10)
- _validation error message (PHP variable $category_err shown on page)_: Please enter a category name.
- _validation error message (PHP variable $category_err shown on page)_: This category name already exists.
- _success message displayed to user_: Category added successfully!
- _error message displayed to user_: Oops! Something went wrong. Please try again later.
- _card header title (hardcoded HTML text)_: Add New Category
- _form instruction heading_: Type in what your new category will be:
- _form label_: Category Name
- _input placeholder text_: e.g. Work, Personal, Shopping
- _button value/text_: Submit
- _button link text_: Cancel

### bot_action.php  (10)
- _JSON error message returned to user_: Bot start/stop is disabled while acting as another channel.
- _JSON error message_: Missing required parameters
- _JSON error message_: Invalid action
- _JSON error message_: Invalid bot type
- _JSON error message_: Username not found in session
- _JSON error message_: Bot is BANNED from your channel
- _JSON error message_: Bot is not a moderator on your channel. Please make the bot a moderator before starting.
- _JSON error message_: Custom bot is not verified. Please verify your custom bot in Profile settings.
- _JSON error message_: No custom bot configured. Please configure a custom bot in Profile settings.
- _JSON error message_: Operation timed out. Bot may still be processing in background.

### discordbot_overview.php  (10)  *(admin)*
- _h1 heading text_: Discord Bot Configuration Overview
- _input placeholder_: Search users...
- _button link text_: Clear
- _modal heading text_: Discord Configuration Details
- _modal button text_: Close
- _paragraph description text_: Overview of all users with Discord bot configuration. Click a user card to view full details.
- _alert notification text_: No users currently have Discord bot configuration set up.
- _badge status text_: Linked, Not Linked
- _configuration label text on cards_: Discord User:, Guild ID:, Live Channel:, Tracked Streams:, Stream Alerts, Moderation, Alerts, Stream Monitoring, Welcome…
- _stat summary text_: Total Users:, Linked Users:, With Guild:, Total Tracked Streams:

### insert.php  (10)
- _validation error message (shown to user)_: Please enter a task.
- _success message displayed to user_: Task added successfully!
- _error message displayed to user_: Error adding task. Please try again.
- _card header title_: Add a New Task
- _form label with icon_: Task
- _textarea placeholder_: Describe your task...
- _form label_: Category
- _checkbox label text_: Private (hide from OBS overlay)
- _button text_: Add
- _button link text_: Cancel

### login.php  (10)
- _login page info variable (shown to user)_: "Please wait while we redirect you to Twitch for authorization."
- _login page info message_: "Authentication failed or was cancelled."
- _restricted access page message_: "Your account has been banned from using this system..."
- _memorial access page message_: "This account has been preserved in memory..."
- _error message echoed to page_: "Error updating user: ..."
- _login page info message_: "Failed to parse authentication data from StreamersConnect."
- _login page info message_: "Unexpected response service from StreamersConnect."
- _error messages echoed to page_: "cURL error: ...", "HTTP error: ..."
- _error message echoed to page_: "Error updating user: ..."
- _page heading_: "BotOfTheSpecter"

### videos.php  (10)
- _tab label_: Archive VODs
- _tab label_: Highlights
- _tab label_: Uploads
- _tab label_: Clips
- _error message_: Unable to connect to Twitch
- _error message_: Twitch API error
- _error message_: Video ID is required
- _error message_: Video deleted from Twitch
- _button text_: Load 20 More
- _button text_: Load All

### completed.php  (12)
- _card header title_: Mark Tasks as Completed
- _alert title text_: Your to-do list is empty!
- _alert body text_: Start adding tasks to get organized.
- _form label_: Search Tasks
- _input placeholder_: Search objectives
- _form label_: Filter by Category
- _select option text_: All
- _paragraph text (numeric display)_: Number of total tasks in the category:
- _button text_: Mark as completed
- _SweetAlert title (medium)_: Mark as completed?
- _SweetAlert text (medium)_: This will mark the task as completed.
- _SweetAlert button text (medium)_: Yes, mark completed!

### logs.php  (12)  *(admin)*
- _log display fallback text_: "Nothing has been logged yet."
- _log content fallback messages_: "(log file is empty)", "Nothing has been logged yet.", "Error: ..."
- _form label and dropdown option_: "Log Rotation:", "Current"
- _button text_: "Download"
- _dynamic heading (low)_: Log title placeholder (empty on load)
- _dropdown option value_: "Current"
- _log display fallback_: "(log is empty)"
- _error message in log display_: "Network error: Failed to fetch log data"
- _error message in log display_: "Auto-refresh error: Failed to fetch log data"
- _placeholder option text check (medium)_: "SELECT A LOG TYPE"
- _timezone offset logic (low; not text but a hardcoded GMT offset)_: `isDaylightSavings ? '11' : '10'` — hardcoded timezone offsets
- _already uses t() — clean_: admin_dashboard_title

### download_stream.php  (9)
- _HTTP error message header_: streaming_missing_parameters
- _HTTP error message header_: streaming_invalid_filename
- _HTTP error message header_: streaming_invalid_server_selection
- _HTTP error message header_: streaming_ssh2_not_installed
- _HTTP error message header_: streaming_connection_failed
- _HTTP error message header_: streaming_authentication_failed
- _HTTP error message header_: streaming_sftp_init_failed
- _HTTP error message header_: streaming_file_not_found
- _HTTP error message header_: streaming_file_open_failed

### mod_channels.php  (9)
- _page title variable_: "Mod Channels"
- _page heading_: "Mod Channels"
- _page subtitle_: "Channels you can moderate for"
- _alert message_: "Moderator Act As mode has been stopped."
- _alert message_: "You do not have permission to Act As that channel."
- _alert message_: "The selected channel could not be found."
- _form label and input placeholder_: "Search channels", "Type streamer name or username"
- _alert message (empty state)_: "No channels to mod, if you believe this is incorrect please ask your broadcaster to add you to the allow list."
- _button text_: "Act As This Channel"

### update_objective.php  (9)
- _card header title_: Update Task Objective
- _alert title text_: Your to-do list is empty!
- _alert body text_: You can't update any tasks because there aren't any yet.
- _form heading text_: Edit your task objectives and categories below and click "Update All" to save changes:
- _form label_: Objective
- _form label_: Category
- _checkbox label text_: Private (hide from OBS overlay)
- _button text_: Update All
- _button link text_: Cancel

### event_sub.php  (9)  *(admin)*
- _h1 heading text_: EventSub Connections
- _button text_: Refresh
- _paragraph description text_: Admin overview of Twitch EventSub connections for all users with stored bot tokens.
- _alert initial loading text_: Loading EventSub data...
- _stat card labels_: Users Scanned, Healthy Users, Users With Active WS, Users With Errors, Enabled WS Connections, Enabled WS Subs, Disabled…
- _h2 heading text_: Per-user Twitch Connection Status
- _table header cells_: User, Twitch ID, Status, Connections, Enabled WS, Disabled WS, Webhook, Cost, Error
- _table loading message_: Loading...
- _badge status text (medium, JS)_: Error, High Usage, Connected, No Active Connections

### manage_custom_user_commands.php  (8)
- _user feedback message_: "Command '...' already exists. Please choose a different name."
- _success/error status messages_: "User command '...' for user '...' added successfully!", "Error: Command was not added to the database."
- _status messages_: "User command ... updated successfully!", "... not found or no changes made.", "Error updating ..."
- _status and feedback messages_: "User command ... approved successfully!", "Error approving command:", "User command ... deleted successfully!", "... no…
- _help text paragraph_: "Access: User commands can be used by the specified user and all channel moderators."
- _help text_: "This command will also be available to all channel moderators."
- _input placeholder text_: "Search commands or users..."
- _confirmation dialog text_: "Are you sure you want to delete this command?"

### menu.php  (8)
- _menu submenu label_: "Settings"
- _menu item label_: "EventSub Notifications"
- _menu item labels_: "Schedule", "Videos"
- _menu item label_: "Stream Watch Streaks"
- _menu item label_: "Streaming"
- _menu item labels_: "Alerts", "Tanggle"
- _admin menu item labels_: "Dashboard", "User Management", "Start User Bots", "EventSub Connections", "Log Management", "Feedback", "Beta Programs"…
- _todolist menu item labels_: "View Tasks", "Mark Tasks as Completed", "Add Task", "Update Task", "Remove Task", "View Categories", "Add Category", "O…

### music.php  (8)
- _pageTitle variable for layout_: Music Dashboard
- _error message echoed to user (status variable)_: Failed to upload ... Only MP3 files are allowed.
- _button span text_: Repeat 1
- _label text_: Music source
- _option label_: Built-in (DMCA-free)
- _option label_: Use my uploads
- _div warning disclaimer text_: You are responsible for all files you upload and must have the legal rights to use and share them. We do not verify or g…
- _span label text_: Slow, Fast

### streamelements.php  (8)
- _heading_: StreamElements Integration
- _status badge_: Connected
- _status badge_: Not Connected
- _success message_: Your StreamElements account is successfully linked
- _table header_: Tipper
- _table header_: Amount
- _table header_: Message
- _table header_: Date

### beta_programs.php  (8)  *(admin)*
- _h1 heading text_: Beta Programs
- _button text_: New Program
- _alert notification text_: No beta programs have been created yet.
- _table header cells_: Slug, Name, Description, Status, Created, Actions
- _badge status text_: Active, Inactive
- _modal title text_: Create Beta Program, Edit Beta Program
- _label text with constraint hint_: Slug (lowercase, letters, numbers, hyphens, underscores)
- _JSON error/success messages (medium)_: Slug must be at least 2 characters, Name is required, Program updated, Update failed, Program created, A program with th…

### feedback.php  (7)  *(admin)*
- _h1 heading text_: Feedback Management
- _paragraph description text_: View and manage user feedback submissions
- _alert notification text_: No feedback submissions found.
- _table header cells_: ID, Type, Display Name, Message/Summary, Details, Submitted At, Actions
- _badge type text_: Bug, Feedback
- _button text_: View Details, Delete
- _JSON response messages (medium)_: Feedback deleted successfully, Failed to delete feedback

### overlays.php  (6)
- _p text content in sp-alert_: Upcoming Overlay System Update, complete overlay experience, Specter Alerts
- _span span text_: Slow, Fast
- _div sp-card-title text_: Counter Display
- _p descriptive text_: Display the live value of any counter ... great for things like "cats spotted: 7" on screen. Type a counter name below, …
- _p font-size hint text_: Built-in counters you can use right away: deaths, stream_deaths, hugs, kisses, highfives, typos, lurkers. Any other name…
- _label text_: Counter name, Text colour (optional), Background (optional)

### streaming.php  (6)
- _heading_: BotOfTheSpecter Streaming
- _badge text_: Beta Access Confirmed
- _feature status_: Coming Soon
- _section heading_: What's Being Built
- _feature description_: Stream Key Management
- _feature description_: Auto Record from Twitch

### timed_messages.php  (6)
- _section heading_: Current Timed Messages
- _trigger type label_: Timer (minutes)
- _trigger type label_: Chat Lines
- _trigger type label_: Both
- _badge text_: 5.8 Beta
- _help text_: Using Beta Bot? Enables 500 character limit

### vips.php  (6)
- _heading_: Manage VIPs
- _badge text_: VIP
- _empty state message_: No VIPs found
- _input placeholder_: Enter username
- _button text_: Add
- _button text_: Remove

### walkons.php  (6)
- _section heading_: Upload
- _section heading_: File Management
- _table header_: Select
- _table header_: File Name
- _table header_: Action
- _button text_: Delete Selected

### categories.php  (8)
- _error message (die() output to user)_: Error retrieving categories:
- _message to user_: Cannot remove the default category.
- _message to user_: Category not found.
- _alert title text_: Manage Your Categories
- _alert body text_: Here's the list of categories you've created. Each category helps you organize your tasks into separate lists.
- _SweetAlert title (medium)_: Are you sure?
- _SweetAlert confirmation text (medium)_: This will remove the category.
- _SweetAlert button text (medium)_: Yes, remove it!

### known_users.php  (8)
- _alert box content (informational text blocks)_: "Custom Variables for Welcome Messages", "You can use the following variables...", "(shoutout)", "Important: How to Use …
- _table header text_: "First Seen", "Last Seen", "Test"
- _table cell fallback text_: "Unknown"
- _character counter text (medium)_: "/255 characters"
- _button title attribute (tooltip)_: "User is inactive"
- _select option labels in dropdown_: "Current", "Crash Log"
- _toast message (medium, success notification)_: "✓ Test Sent: ..."
- _toast messages (medium, error notifications)_: "✗ Error: ...", "✗ Error: Invalid response...", "✗ Error: Failed to send..."

### counters.php  (6)
- _tab button text (navigation label)_: View Data
- _tab button text (navigation label)_: Edit Data
- _button text_: Random Pick Lists
- _rendered inline HTML text (medium, JavaScript)_: No options saved
- _option label (select dropdown)_: Select User
- _option label (select dropdown)_: Select User

### get_custom_embed.php  (5)
- _JSON error message returned to user_: Not authenticated
- _JSON error message returned to user_: Missing embed ID or server ID
- _JSON error message returned to user_: Discord database connection failed
- _JSON error message returned to user_: Embed not found
- _JSON error message returned to user_: Server error:

### check_bot_status.php  (4)
- _JSON error message (returned to user via AJAX)_: Missing bot parameter
- _JSON error message (returned to user via AJAX)_: Invalid bot type
- _JSON error message (returned to user via AJAX)_: Username not found in session
- _hardcoded time duration format strings in formatTimeAgo()_: {$diff} seconds ago, {$minutes} minute(s) ago, {$hours} hour(s) ago, {$days} day(s) ago

### check_subscription.php  (4)
- _JSON error message (returned to user via AJAX)_: Not authenticated
- _JSON error message (returned to user via AJAX)_: Database query preparation failed
- _JSON error message (returned to user via AJAX)_: Broadcaster token not found
- _JSON error message (returned to user via AJAX)_: Twitch API request failed

### edit_reward.php  (4)
- _JSON error message returned to user_: Missing reward_id.
- _JSON error message returned to user_: DB connection failed.
- _JSON error message returned to user_: Reward is not managed by Specter.
- _JSON error message returned to user_: No fields to update.

### get_custom_embeds.php  (4)
- _JSON error message returned to user_: Not authenticated
- _JSON error message returned to user_: Missing server ID
- _JSON error message returned to user_: Discord database connection failed
- _JSON error message returned to user_: Server error:

### subscribers.php  (4)
- _label_: Tier
- _tier display text_: Tier 1
- _tier display text_: Tier 2
- _tier display text_: Tier 3

### mods.php  (4)
- _page title_: already wrapped in t() (mods_page_title)
- _error message displayed on page_: "Your Twitch authentication token is invalid or expired. Please ... to refresh your session."
- _dynamic fallback text for stale moderators (medium)_: "User ... " (stale name fallback)
- _badge label for stale access_: "No longer mod"

### check_blocked_term.php  (3)
- _JSON message (returned to user via AJAX)_: This term matches a globally blocked spam pattern and cannot be added to your personal block list.
- _JSON message (returned to user via AJAX)_: This term is already whitelisted in your URL protection settings. Remove it from the whitelist first.
- _JSON message (returned to user via AJAX)_: This term is already blacklisted in your URL protection settings. It's already being blocked.

### fetch_banned_status.php  (3)
- _JSON error message returned to user_: Not authenticated
- _JSON error message returned to user_: Invalid usernames format
- _JSON error message returned to user_: Bad request

### module_data.php  (3)
- _default welcome message strings assigned as fallback/default values; used in chat alerts shown to users_: Welcome back (user), glad to see you again! / ATTENTION! A very important person has entered the chat, welcome (user) / …
- _default ad notice messages assigned as fallback values; shown to chat users_: Ads will be starting in (minutes). / Ads are running for (duration). We'll be right back after these ads. / Thanks for s…
- _default Twitch chat alert messages; shown in chat when events occur_: Thank you (user) for following! Welcome to the channel! / Thank you (user) for (bits) bits! You've given a total of (tot…

### regen_api_key.php  (3)
- _die() message, error message_: Twitch user ID not set. Please log in again. / Error updating API key:
- _PHP die() message shown to user_: Twitch user ID not set. Please log in again.
- _echo message shown to user on API key update failure (concat with error)_: Error updating API key:

### update_banned_users_cache.php  (3)
- _JSON error message_: User session not found
- _JSON error message_: Invalid JSON received
- _JSON error message_: Could not create cache directory

### update_use_custom.php  (3)
- _JSON error message_: Not authenticated
- _JSON error message_: Method not allowed
- _JSON error message_: Invalid value for use_custom

### followers.php  (5)
- _JSON error message returned to user_: Failed to fetch followers
- _JSON error message returned to user_: Error decoding JSON response
- _chart label in JavaScript (medium)_: Follower Growth
- _dynamic text in JavaScript (medium)_: Loading 0/
- _dynamic text in JavaScript (medium)_: Loading

### notifications.php  (3)
- _pageTitle variable_: EventSub Notifications
- _h1, p, h2, div text headings and labels_: EventSub Notifications, Monitor and manage your Twitch EventSub subscriptions, Internal Websocket Connections, Loading i…
- _showNotification messages and div text (medium)_: Success, Error, No EventSub subscriptions found

### alerts.php  (2)
- _toast notifications, form labels, modal title_: Variant saved; Invalid alert ID; No image selected; Alert configuration; Enable alert
- _form labels, button labels_: Alert type; Alert image; Alert position; Alert duration; Alert sound; Save alert; Delete alert; Cancel

### bingo.php  (2)
- _HTML headings, labels, table headers_: API Key Required; Twitch Extension Configuration; Game ID; Start Time; End Time; Events; Status; Actions; No bingo games…
- _button labels, JavaScript alert messages_: View Winners; View Players; Call Random; Call All; Start Vote; Random number called successfully!; Error calling random …

### bot_points.php  (2)
- _toast notifications_: Update Points Success; Remove Points Success; Points Settings Update Success
- _table headers, button labels_: Username; Points; Actions; Update; Remove; Settings

### builtin.php  (2)
- _placeholder, checkbox labels, table headers, button labels_: No commands yet; Search files; Show enabled commands; Show disabled commands; Command; Description; Usage Level; Status;…
- _button labels, tooltip, badge, modal title_: Edit; Delete this variant; Locked permission; Update Available; Cooldown Options

### check_spam_pattern.php  (2)
- _JSON error message (returned to user via AJAX)_: Database connection failed
- _JSON error message (returned to user via AJAX)_: Server error

### notifications_content.php  (2)
- _div class stat-secondary text_: Connection ... subscriptions, disabled/stale
- _div info-box text_: These subscriptions are no longer active and can be safely deleted. They do not count toward your connection or subscrip…

### premium.php  (2)
- _li title and text_: Custom Bot Name - Experimental or Coming Soon
- _p sp-plan-note text_: 90-95% of the bot is FREE!

### spam_patterns.php  (2)  *(admin)*
- _page title_: Spam Pattern Management
- _JSON success/error messages (medium)_: Pattern cannot be empty, Spam pattern added successfully, Failed to add pattern, Pattern updated successfully, Pattern d…

### twitch_tokens.php  (2)  *(admin)*
- _page title_: Twitch App Access Tokens
- _JSON error messages (medium)_: Client credentials are required, cURL initialization failed

### users.php  (2)  *(admin)*
- _notification messages from query string_: Invalid user selected for Act As, The selected user could not be found, Cannot Act As this user because no access token …
- _JSON error messages (medium)_: User not found, You cannot delete your own account, You do not have permission to delete admin users

### websocket_clients.php  (2)  *(admin)*
- _already uses t() — clean_: admin_websocket_clients_title
- _fallback display text (medium)_: Unknown

### check_url_conflict.php  (1)
- _JSON error message (returned to user via AJAX)_: Database connection failed

### controllerapp.php  (1)
- _badge label (hardcoded version tag in feature card)_: Bot v5.8 Beta

### delete_stream.php  (1)
- _flash messages and session status (displayed on redirect)_: various t('streaming_*') calls with hardcoded fallback messages from stream deletion logic

### persistent_storage.php  (1)
- _p has-text-weight-bold text_: Persistent Storage Service - Terminated

### profile.php  (1)
- _error message variables (hardcoded in PHP)_: Password cannot be empty., Passwords do not match., Password must be at least 6 characters., Unable to set app password …

### raids.php  (1)
- _page headings, table headers, button labels, empty state messages_: Raids / Recent Raids - Received / Raider / Viewers / Date / Time / Latest Raid - Sent / Show Last 5 / No sent raid data …

### recording.php  (1)
- _page title, error messages, alert text, form labels, button labels, table headers, instruction text_: Recording / Channel recording is currently disabled / SSH2 extension not installed / Recorder server connection details …

### restricted.php  (1)
- _page titles, headings, restriction list items, button labels_: restriction explanation messages (6 items) / This account has been preserved / Access denied / Your account has been res…

### schedule.php  (1)
- _page title, form labels, error messages, success messages, button labels, validation messages_: Twitch Schedule / Broadcaster ID not available / Segment ID is required to cancel / Start and end are required to start … (~40+ message strings spread across form validation, Twitch API errors, success confirmations, and UI labels)

### sound-alerts.php  (1)
- _page title, status messages (HTML), SweetAlert messages, JavaScript status text_: Sound Alerts / Failed to update mapping / The file has been uploaded / Failed to upload / Only MP3 files are allowed / T…

### spotifylink.php  (1)
- _page messages, form labels, card titles, headings, alert text, warning text_: Linking Spotify is disabled while using Act As mode / Failed to contact Spotify / Your Spotify account has been successf…

### start_bots.php  (1)  *(admin)*
- _page title_: Start User Bots

### stream_streak.php  (1)
- _page title, alert text, headings, table headers, empty state messages_: Stream Watch Streaks / Beta 5.8 Feature: Stream Watch Streak tracking / Recent Milestones / Viewer / Current Streak / Be…

### userdata.php  (1)
- _PHP die() message shown to user on database query failure_: An error occurred.

### serve_user_music.php  (5, medium)
- _HTTP 403 error response message_: Forbidden
- _HTTP 400 error response message_: Missing file
- _HTTP 400 error response message_: Invalid file
- _HTTP 404 error response message_: Not found
- _HTTP 500 error response message_: Could not open file

### subathon.php  (3, medium)
- _hardcoded default numeric value in help text_: 60
- _hardcoded default numeric value in help text_: 5
- _hardcoded default numeric value in help text_: 10
- Note: these are numeric defaults in help text and may be configuration-dependent — confirm before treating them as translatable.

### service_status.php  (2, medium)  *(admin)*
- _JSON error messages_: Authentication required, Admin access required
- _JSON error message_: Invalid service

### save_discord_channel_config.php  (1, medium)
- _JSON response messages (error and success)_: User session not found / Method not allowed / Missing required parameters / Server ID is required / Discord database con… (30+ message strings across the various action handlers; 1483-line file)

### send_welcome_message.php  (1, medium)
- _JSON response messages (error and success)_: Invalid request method / Username and message are required / Welcome message is empty / Twitch app credentials missing /…

### server_metrics.php  (1, medium)
- _JSON response messages (error)_: Technical access required / Error getting server metrics

### stream_command.php  (1, medium)  *(admin)*
- _SSE event data messages_: Unauthorized - Please log in, Unauthorized - Admin access required, Invalid script requested

### terminal_stream.php  (1, medium)  *(admin)*
- _SSE error messages_: Unhandled error, Failed to connect to bot server, Failed to start remote command

### user_db.php  (1, medium)
- _PHP die() message; partial (concat with error). Shows on fatal database connection failure_: Connection failed:

## Clean files

Thirty-eight files came back clean — no user-facing hardcoded text. These are overwhelmingly pure AJAX endpoints, SSE streaming endpoints, and backend utility files that never render UI. Examples that turned up clean during the sweep include `switch_channel.php` and `upload_to_s3.php` (proper `t()` usage throughout), the AJAX/backend-only `manage_redemption.php`, `manage_reward.php`, and `migrate_media.php`, the session/redirect-only `regen_api_key.php`, `relink.php`, and `stop_act_as.php`, and on the admin side `service_status.php`. (The original sweep lost the full filename list for this section, so treat this as a representative sample rather than the exhaustive 38.)

## Notes and observations

A few things worth recording while the context is fresh, mostly about the heaviest files and where the biggest concentrations of work are.

- **bot.php and channel_rewards.php are both large.** `bot.php` alone carries roughly 40+ hardcoded notification messages and UI strings in JavaScript; `channel_rewards.php` has 30+ hardcoded SweetAlert messages and UI strings. For files with 25+ strings I listed the most representative examples rather than every single one. `alerts.php` (1166 lines) similarly holds 25+ hardcoded form labels, button text, and notification strings. Where dynamic content is spliced in via template literals, I've marked it `(dynamic)` or with the variable name.

- **AJAX/API handlers (the `check_*` files, `create_reward.php`, etc.) account for a big chunk — 109+ strings.** Most are JSON error/status messages that should be keyed instead of hardcoded English. Several are user-visible dashboard labels, section headings, and card titles in `dashboard.php` and `counters.php`. `custom_commands.php` has hardcoded flash messages and form labels. `download_stream.php` and `delete_stream.php` already use `t()` for some output but leave some HTTP response text hardcoded. The landing-page section of `dashboard.php` carries extensive marketing/feature copy that should either be keyed or reviewed for consistency. Time formatting in `check_bot_status.php` hardcodes English (seconds/minutes/hours/days). `controllerapp.php` and `custom_commands.php` hardcode "Permission Level" instead of using `t()`.

- **`embed_builder_backend_handlers.php`** is a template/reference implementation (it says so in the file header) meant to be integrated elsewhere. `fetch_banned_status.php`, `get_custom_embed.php`, and `get_custom_embeds.php` are pure AJAX/API backends returning JSON with hardcoded messages. `followers.php` has additional hardcoded strings in its JavaScript chart label and loading counter. `edit_reward.php` is an AJAX handler with hardcoded JSON error responses.

- **The `menu.php` / `media.php` / `makers.php` / `logs.php` / `known_users.php` / `layout.php` / `login.php` / `manage_custom_user_commands.php` group** carries ~200+ strings between them. Three siblings (`manage_redemption.php`, `manage_reward.php`, `migrate_media.php`) are AJAX/backend-only with no rendered UI. Most strings are visible UI (headings, buttons, alerts, form labels, tooltips, dropdowns). `media.php` alone has ~70+ across UI, modals, and SweetAlert dialogs. Many of these files already do well on main content (page titles and key labels go through `t()`) but miss the smaller stuff — buttons, placeholders, help text, dynamic JS messages, toast/alert/confirm dialogs, and tooltip attributes.

- **`modules.php` (~2800 lines)** could only be scanned to about line 1078, and even that surfaced ~50 hardcoded strings: tab names (Game Deaths, Automated Shoutouts, TTS Settings, Custom Module Bots), section headings (Welcome Message Configuration, URL Blocking System Overview), subsection titles (Regular Members, VIP Members, Moderators), helper text, and descriptive alert paragraphs. Given the file length and the systematic UI patterns, the real count comfortably exceeds 25; the list above is the representative subset from the sections I could see. `overlays.php` has several hardcoded labels and descriptions (Counter Display, counter-name advice, colour/background labels, form hints); `notifications.php` and `notifications_content.php` hardcode headings and descriptive text in alert boxes and tables; `premium.php`, `profile.php`, and `raffles.php` carry assorted UI labels, button text, form labels, validation errors, and table headers.

- **The rendered-page cluster (`raids.php`, `recording.php`, `restricted.php`, `schedule.php`, `sound-alerts.php`, `spotifylink.php`, `stream_streak.php`)** is heavy. Three nearby files (`regen_api_key`, `relink`, `stop_act_as`) are clean — session/redirect or backend logic with no rendered text. The eleven with findings have extensive hardcoded headings, form labels, button text, table headers, alert messages, and empty-state messages. The associated AJAX handlers (`save_discord_channel_config.php`, `send_welcome_message.php`, `server_metrics.php`) return hardcoded JSON strings. `schedule.php` is the worst of this group with ~40+ message strings across form validation, Twitch API errors, success confirmations, and UI labels; `save_discord_channel_config.php` is 1483 lines with 30+ hardcoded JSON messages across its action handlers. Everything here should move to `t()` keys mirrored in `en.php`, `de.php`, and `fr.php`.

- **The `working-or-study.php` / `video-alerts.php` / `videos.php` / `tanggle.php` group** holds 155 strings between them. Two siblings (`switch_channel.php`, `upload_to_s3.php`) are clean. The recurring patterns: section headings and button text outside `t()`, empty-state messages ("No tasks yet", "No VIPs found") hardcoded in HTML, status badges with literal text ("Connected"/"Not Connected"), table headers in HTML, toast/alert messages concatenated with hardcoded fragments and user data, modal titles and form labels as inline HTML, and JavaScript `showToast()`/`confirm()` dialogs using literal strings.

- **Backend helpers (`module_data.php`, `serve_user_music.php`, `regen_api_key.php`, `user_db.php`, `userdata.php`)** are mostly includes/handlers with no visible output — but `module_data.php` carries extensive hardcoded default messages (welcome alerts, ad notices, Twitch chat event alerts) that *are* shown to users and should move to lang files. `serve_user_music.php` uses HTTP `exit()` calls with simple error messages outside `t()`. `regen_api_key.php` has `die()`/`echo` with hardcoded user-facing text. Note: `usr_database.php` `console.log` output and SQL schema strings are developer/internal only — not user-facing, and not flagged.

- **Admin subdir (21 files).** `export_user_data.php` and `admin_access.php` return JSON error/status strings to clients (medium confidence). Several files (`api_keys.php`, `beta_programs.php`, `discordbot_overview.php`, `event_sub.php`, `feedback.php`, `spam_patterns.php`) each have 20–25+ hardcoded strings: page titles/headings, button/form labels, placeholders, table headers, badge labels, help text, and JSON responses. `logs.php`, `websocket_clients.php`, and `users.php` mostly use `t()` for page titles but have scattered hardcoded messages. `terminal.php` is heavy on hardcoded UI text. `service_status.php` is a pure backend endpoint and is clean for rendered text. As noted up top, I'd treat the whole admin subdir as out of scope unless we explicitly decide to localize admin tooling.

- **todolist subdir (8 files).** All eight render HTML pages with visible text; none are backend-only. They carry hardcoded form labels, buttons, alert messages, validation errors, modal instructions, and badge/span text throughout, all of which should move to `t('key')` with entries in `en.php`, `de.php`, and `fr.php`. The biggest offenders are `obs_options.php` (70+ strings) and `index.php` / `remove.php` (40+ each); the rest sit in the 20–40 range.

## What "done" looks like

For each file I work through, the check is straightforward: every string listed above now resolves through a `t()` call, the key exists in `lang/en.php`, and `de.php` and `fr.php` carry matching entries (no missing keys, no key falling back to its raw English default). Each touched PHP file should still pass `php -l`, and the page should render the same text it did before — just sourced from the lang files now, and switching correctly when the language changes. Because this is a long tail of small edits, I'll lean on the per-file summary table as the running worklist and check files off there as their strings are fully migrated.
