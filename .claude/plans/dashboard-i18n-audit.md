# Dashboard i18n Audit — hardcoded user-facing text not in lang files

> Generated 2026-05-31 from an Explore sweep of the dashboard (109 top-level pages plus admin and todolist subdirs).
> The i18n system (keys in lang/en.php, en = base) is only partially adopted.

- **Total findings:** 827  (high=758, medium=64, low=5)
- **Files with gaps:** 95   **Clean files:** 38
- **Undercount:** several large files (working-or-study.php ~1677 lines, modules.php ~2800, bot.php, channel_rewards.php, dashboard.php) could only be partially scanned; true total is higher (~1000+).
- **Admin pages** (dashboard/admin/, internal tooling — likely intentionally English-only / out of scope): api_keys.php, beta_programs.php, discordbot_overview.php, event_sub.php, feedback.php, logs.php, service_status.php, spam_patterns.php, start_bots.php, stream_command.php, terminal.php, terminal_stream.php, twitch_tokens.php, users.php, websocket_clients.php

## Per-file summary (sorted: most high-confidence first)

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

## Full findings by file

### working-or-study.php  (73)
- L125 [high] _heading_: Working / Study Overlay Control
- L127 [high] _button text_: Copy Timer Overlay Link
- L134 [high] _label_: Focus Sprint Duration
- L143 [high] _label_: Micro Break Duration
- L152 [high] _label_: Recharge Break Duration
- L163 [high] _button text_: Start Focus Sprint
- L165 [high] _button text_: Start Micro Break
- L167 [high] _button text_: Start Recharge Stretch
- L173 [high] _button text_: Update Overlay
- L175 [high] _button text_: Reset Overlay
- L194 [high] _button text_: Start
- L194 [high] _button text_: Pause
- L194 [high] _button text_: Resume
- L194 [high] _button text_: Reset
- L194 [high] _button text_: Stop
- L214 [high] _stat label_: Focus Sprints
- L216 [high] _stat label_: Micro Breaks
- L218 [high] _stat label_: Recharge Breaks
- L220 [high] _stat label_: Total Sessions
- L222 [high] _stat label_: Total Focus Time
- L236 [high] _section heading_: Channel Task Manager
- L252 [high] _button text_: Copy Link (Combined)
- L254 [high] _button text_: Copy Link (Streamer)
- L256 [high] _button text_: Copy Link (Users)
- L276 [high] _label_: Default Reward Points per Task
- L287 [high] _checkbox label_: Require streamer approval before awarding points
- L292 [high] _checkbox label_: Allow viewers to submit tasks
- L297 [high] _checkbox label_: Show tasks on overlay
- L333 [high] _table header_: Task
- L333 [high] _table header_: Status
- L333 [high] _table header_: Pts
- L333 [high] _table header_: Actions
- L357 [high] _table header_: User
- L357 [high] _table header_: Task
- L357 [high] _table header_: Status
- L357 [high] _table header_: Approval
- L408 [high] _empty state message_: No tasks yet.
- L418 [high] _empty state message_: No viewer tasks yet.
- L1232 [high] _toast message_: Timer overlay link copied to clipboard!
- L1235 [high] _button text (inline)_: Copied!
- L1241 [high] _error toast message_: Failed to copy link to clipboard
- L1248 [high] _toast message_: Streamer task list link copied!
- L1250 [high] _button text (inline)_: Copied!
- L1256 [high] _error toast message_: Failed to copy link
- L1262 [high] _toast message_: Users task list link copied!
- L1264 [high] _button text (inline)_: Copied!
- L1334 [high] _error toast message_: Focus duration must be at least 1 minute
- L1343 [high] _error toast message_: Micro break duration must be at least 1 minute
- L1352 [high] _error toast message_: Break duration must be at least 1 minute
- L1373 [high] _toast message (concatenated with data)_: Task created:
- L1408 [high] _empty state message in table_: No tasks yet.
- L1418 [high] _empty state message in table_: No viewer tasks yet.
- L1436 [high] _button text_: Edit
- L1437 [high] _button text_: Done
- L1438 [high] _button text_: Delete
- L1460 [high] _button text_: Done
- L1461 [high] _button text_: Award
- L1462 [high] _button text_: Reject
- L1501 [high] _modal title text_: Edit Task
- L1505 [high] _confirmation dialog_: Delete this task?
- L1510 [high] _toast message_: Task deleted.
- L1519 [high] _toast message_: Task completed.
- L1534 [high] _toast message (template)_: Task for
- L1558 [high] _toast message (template)_: Awarded task for
- L1579 [high] _toast message_: Task rejected.
- L1595 [high] _success toast message_: Settings saved.
- L1597 [high] _error toast message_: Failed to save settings.
- L1615 [high] _modal title text_: Add Streamer Task
- L1629 [high] _warning toast message_: Title is required.
- L1640 [high] _toast message_: Task updated.
- L1640 [high] _toast message_: Task created.
- L1642 [high] _error toast message_: Failed to save task.
- L1653 [high] _error toast message (with concatenation)_: Network error:

### dashboard.php  (72)
- L17 [high] _Page title variable (shown on page as $pageTitle)_: Management Dashboard
- L33 [high] _Status subtext label (displayed on dashboard)_: Live Twitch subscriptions
- L96 [high] _Status subtext label (displayed on dashboard)_: Live Twitch subscriptions
- L98 [high] _Status subtext label (displayed on dashboard)_: Unable to read subscriber total from Twitch
- L101 [high] _Status subtext label (displayed on dashboard)_: Unable to fetch subscribers from Twitch right now
- L104 [high] _Status subtext label (displayed on dashboard)_: Channel is not Affiliate/Partner (no Twitch subs endpoint access)
- L107 [high] _Status subtext label (displayed on dashboard)_: Missing Twitch auth/config for live subscriber count
- L144 [high] _Bot status label (displayed on dashboard)_: Not Running
- L144 [high] _Bot version label (fallback)_: Unknown
- L152 [high] _Bot system status (displayed on dashboard)_: Not Running
- L154 [high] _Bot version status (displayed on dashboard)_: Not Running
- L155 [medium] _Latest version label (displayed on dashboard)_: N/A
- L181 [high] _Page subtitle text_: Live overview of your bot systems, storage, and community activity.
- L184 [high] _Section heading (dashboard section label)_: Channel Info
- L187 [high] _Stat label (dashboard card)_: Followers
- L189 [high] _Stat subtext (dashboard card)_: Tracked follower records
- L192 [high] _Stat label (dashboard card)_: Subscribers
- L197 [high] _Stat label (dashboard card)_: Raids
- L199 [high] _Stat subtext with dynamic values (dashboard card)_: total raider viewers, unique raiders
- L203 [high] _Section heading (dashboard section label)_: Dashboard Metrics
- L206 [high] _Stat label (dashboard card)_: Active Bot
- L212 [high] _Stat subtext (dashboard card)_: No active bot runtime
- L217 [high] _Stat label (dashboard card)_: Storage Usage
- L222 [high] _Stat label (dashboard card)_: Commands
- L224 [high] _Stat subtext with counts (dashboard card)_: Custom, Built-in
- L227 [high] _Stat label (dashboard card)_: To-Do Progress
- L229 [high] _Stat subtext (dashboard card)_: task(s) remaining
- L237 [high] _Badge text (bot status badge)_: Running, Stopped
- L253 [high] _Snapshot item label (dashboard card)_: Known Users
- L254 [high] _Snapshot item label (dashboard card)_: Live Lurkers
- L255 [high] _Snapshot item label (dashboard card)_: Watch Time Profiles
- L256 [high] _Snapshot item label (dashboard card)_: Rewards Configured
- L257 [high] _Snapshot item label (dashboard card)_: Quotes Saved
- L258 [high] _Snapshot item label (dashboard card)_: Moderator Channels
- L263 [high] _Section heading (dashboard section label)_: Quick Links
- L268 [high] _Card title (quick link card)_: Bot Control
- L269 [high] _Card description (quick link card)_: Start, stop, and monitor your bot
- L276 [high] _Card title (quick link card)_: Commands
- L277 [high] _Card description (quick link card)_: Create and edit custom commands
- L284 [high] _Card title (quick link card)_: Discord Bot
- L285 [high] _Card description (quick link card)_: Manage your Discord integration
- L292 [high] _Card title (quick link card)_: To-Do List
- L293 [high] _Card description (quick link card)_: Manage your streaming tasks
- L300 [high] _Card title (quick link card)_: Rewards
- L301 [high] _Card description (quick link card)_: Manage channel rewards
- L316 [high] _Card title (quick link card)_: DMCA Music
- L317 [high] _Card description (quick link card)_: Safe music for streaming
- L324 [high] _Card title (quick link card)_: Documentation
- L325 [high] _Card description (quick link card)_: Learn how to use BotOfTheSpecter
- L369 [medium] _aria-label attribute (accessibility label)_: Toggle light or dark theme
- L377 [high] _Hero section subtitle (landing page)_: Your Complete Twitch Bot Management Solution
- L378 [high] _Hero section description (landing page)_: Take control of your Twitch channel with our powerful, feature-rich bot and dashboard. Manage commands, configure your a…
- L383 [high] _Login card description (landing page)_: Join the rest of the streamers who use BotOfTheSpecter to enhance and manage their Twitch channel.
- L390 [high] _Section heading (landing page)_: Dashboard Features
- L391 [high] _Section description (landing page)_: Explore the powerful features that make BotOfTheSpecter the ultimate Twitch bot management solution, with many more feat…
- L396 [high] _Feature card title (landing page)_: Bot Control
- L397 [high] _Feature card description (landing page)_: Start, stop, and monitor your bot with real-time status updates and comprehensive logging.
- L401 [high] _Feature card title (landing page)_: Custom Commands
- L402 [high] _Feature card description (landing page)_: Create and manage custom chat commands with advanced features and permission levels.
- L406 [high] _Feature card title (landing page)_: Analytics & Logs
- L407 [high] _Feature card description (landing page)_: Track your channel's growth, monitor user activity, and analyze command usage statistics.
- L411 [high] _Feature card title (landing page)_: Channel Rewards
- L412 [high] _Feature card description (landing page)_: Manage Twitch channel point rewards and create engaging interactive experiences.
- L416 [high] _Feature card title (landing page)_: Stream Alerts
- L417 [high] _Feature card description (landing page)_: Configure sound alerts, video alerts, and walk-on alerts for followers and subscribers.
- L421 [high] _Feature card title (landing page)_: Integrations
- L422 [high] _Feature card description (landing page)_: Connect with Discord, Spotify, StreamElements, and other popular streaming platforms.
- L426 [high] _Feature card title (landing page)_: Points System
- L427 [high] _Feature card description (landing page)_: Reward your viewers with a custom points system and create point-based mini-games.
- L431 [high] _Feature card title (landing page)_: Stream Overlays
- L432 [high] _Feature card description (landing page)_: Create dynamic overlays for recent followers, latest donations, and now playing music.
- L436 [high] _Feature card title (landing page)_: User Management

### obs_options.php  (43)
- L82 [high] _error message echoed to user_: Error updating settings:
- L90 [high] _error message echoed to user_: Error inserting settings:
- L99 [high] _card header title_: OBS Font & Color Settings
- L103 [high] _button text_: How to put on your stream
- L109 [high] _modal title_: How to use the ToDo List in OBS
- L113 [high] _modal instruction text_: The ToDo List is fully compatible with any streaming software: OBS, SLOBS, xSplit, Wirecast, etc.
- L114 [high] _modal instruction text_: All you have to do is add the following link (with your API key from your profile page) into a browser source and it wor…
- L116 [high] _modal instruction text_: If you wish to define a working category, add it like this:
- L118 [high] _modal instruction text_: (where ID 1 is called Default, defined on the
- L118 [high] _link text in modal_: categories
- L118 [high] _modal instruction text_: page.)
- L119 [high] _modal instruction text_: To add a styled box around your list (useful if your stream overlay makes it hard to read), add
- L121 [high] _modal instruction text_: This wraps the list in a dark semi-transparent box with rounded corners, helping it stand out over any stream overlay. Y…
- L124 [high] _button text_: Close
- L129 [high] _heading text_: Font & Color Settings:
- L133 [high] _label text_: Font:
- L135 [high] _label text_: Color:
- L139 [high] _label text_: List Type:
- L140 [high] _label text_: Font Size:
- L141 [high] _label text_: Text Shadow:
- L141 [high] _badge/span text_: Enabled
- L141 [high] _badge/span text_: Disabled
- L142 [high] _label text_: Text Bold:
- L143 [high] _label text_: Show Completed:
- L143 [high] _span text_: Yes
- L143 [high] _span text_: No
- L149 [high] _alert title text_: Customize your lists!
- L150 [high] _alert body text_: No font and color settings have been set. Use the controls below to personalize the look of your lists.
- L158 [high] _form label_: Font
- L160-164 [high] _select option labels_: Arial, Arial Narrow, Verdana, Times New Roman
- L167 [high] _form label_: Color
- L169-173 [high] _select option labels_: Black, White, Red, Blue, Other
- L176 [high] _form label_: Custom Color
- L181 [high] _form label_: List Type
- L183-184 [high] _select option labels_: Bullet List, Numbered List
- L188 [high] _form label_: Font Size
- L192 [high] _form label_: Text Shadow
- L195 [high] _checkbox label text_: Enable shadow
- L199 [high] _form label_: Text Bold
- L202 [high] _checkbox label text_: Enable bold
- L206 [high] _form label_: Show Completed Tasks
- L209 [high] _checkbox label text_: Show completed tasks
- L214 [high] _button text_: Save

### modules.php  (35)
- L431 [high] _h2 heading text (visible page heading)_: Variables for Modules
- L463 [high] _tab span text_: Game Deaths
- L475 [high] _tab span text_: Automated Shoutouts
- L478 [high] _tab span text_: TTS Settings
- L481 [high] _tab span text_: Custom Module Bots
- L518 [high] _sp-badge span text_: Joke Command
- L579 [high] _h2 heading text_: Welcome Message Configuration
- L588 [high] _sp-badge span text_: Welcome Messages
- L603 [high] _p descriptive text_: Toggle automatic welcome messages for viewers
- L615 [high] _h5 heading text_: Regular Members
- L619 [high] _button span text_: Save Regular Members
- L648 [high] _h5 heading text_: VIP Members
- L652 [high] _button span text_: Save VIP Members
- L682 [high] _h5 heading text_: Moderators
- L686 [high] _button span text_: Save Moderators
- L726 [high] _h4 heading text_: URL Blocking System Overview (Version 5.8)
- L728 [high] _p bold text_: How URL Blocking Works:
- L731-755 [high] _li list items and span strong text_: Multiple inline text segments: 'Blacklist (Always Active)', 'Code Red', 'URL Blocking Enabled', 'Twitch.tv and clips.twi…
- L798 [high] _label text_: Blocking Mode
- L800 [high] _option value label_: Allow for all commands
- L801 [high] _option value label_: Allow for selected commands only
- L805 [high] _label text_: Commands to block until user has chatted
- L811 [high] _p field-help text_: Includes built-in and custom commands. Hold Ctrl (Windows) or Cmd (Mac) to select multiple commands.
- L876 [high] _td colspan text_: No whitelisted links configured
- L913 [high] _td colspan text_: No blacklisted links configured
- L943 [high] _h2 heading text_: Text Term Blocking
- L950 [high] _h4 heading text_: Term Blocking System (Beta Feature)
- L978 [high] _h3 heading text_: Enable Term Blocking
- L1001 [high] _h3 heading text_: Add Blocked Term
- L1005 [high] _input placeholder text_: Enter term to block...
- L1010 [high] _button span text_: Add to Blocked Terms
- L1021 [high] _h3 heading text_: Blocked Terms List
- L1030 [high] _td colspan text_: No blocked terms configured
- L1042 [high] _button span text_: Remove
- L1063 [high] _h2 heading text_: Game Deaths Configuration

### media.php  (34)
- L42, 72, 77, 97, 103 [medium] _JSON error messages from AJAX endpoint_: "Invalid login", "Bot credentials unavailable", "Helix request failed", "User not found"
- L170 [high] _Twitch event type labels (inline array)_: "Follow", "Raid", "Cheer", "Subscription", "Gift Subscription", "Hype Train Start", "Hype Train End"
- L220, 226, 239, 245, 258, 264 [high] _Status update messages concatenated to $status_: "Sound alert mapping added.", "Sound alert mapping removed.", "Video alert mapping added.", "Video alert mapping removed…
- L300-318 [high] _Upload error status messages_: "Error uploading ...", "Failed to upload ...", "Storage limit exceeded ...", "Not an image (png/jpg/gif): ...", "File co…
- L346, 376, 380, 382 [high] _Delete operation status messages_: "Failed to delete ...", error explanation text with file references
- L430 [high] _Card header title_: "Upload Media"
- L436 [high] _Help text paragraph_: "Upload audio (MP3), video (MP4/WEBM) or images..." and long description text
- L441-442 [high] _Drop zone UI text_: "No files selected", "Click or drag files here"
- L449-451 [medium] _Upload progress display text_: "Preparing upload...", "0%"
- L457 [high] _Button text_: "Upload Media"
- L466 [high] _Card header title_: "Upgrade to Unified Media Library"
- L470-488 [high] _Migration notice and description blocks (user-facing text)_: "Important — read before you migrate.", "The unified media library only works on ...", "Bot version 5.8 and above", "You…
- L498-505 [high] _Filter button labels and search placeholder_: "All", "With rewards", "With events", "Walkons", "Unused", "Videos", "Search files…"
- L509-511 [high] _Card header and button text_: "Your Media Library", "Delete Selected"
- L517-518 [high] _Empty state message_: "No media files uploaded yet..."
- L532-536 [high] _Dynamic summary text fragments_: " reward", " event", " walkon", " alert", "Unused" (summary text building)
- L558 [high] _Button title/tooltip_: "Images are managed via the alerts builder"
- L561 [high] _Button title/tooltip_: "This file is in use. Remove its links before deleting."
- L704 [high] _Modal info text (readonly usage chips)_: "Not attached to any alert variant yet..." and "Open Specter Alerts and use Browse library..."
- L738, 754, 767 [high] _Modal section titles_: "Channel Point Rewards (Video)", "Channel Point Rewards", "Twitch Events", "Walkons", "Used by Alert Builder"
- L666 [high] _Empty mapping message in modal_: "No mappings yet."
- L763-765 [high] _Input placeholder and button text in modal_: "Twitch username…", "+ Add user"
- L981-982 [high] _SweetAlert dialog title and text_: "No Files Selected", "Please select at least one file to upload."
- L992 [high] _Dynamic upload status text_: "Uploading ... file(s)..."
- L1008 [high] _Upload progress status text_: "Processing files on server..."
- L1022 [high] _Success status text_: "Upload completed successfully!"
- L1019, 1030 [high] _SweetAlert error dialogs_: "Upload Failed", "An error occurred during upload.", "An error occurred during upload. Please try again."
- L1065 [high] _SweetAlert confirmation dialog_: "Delete Files?", "Are you sure you want to delete the selected ... file(s)?"
- L1067 [high] _Button labels in SweetAlert_: "Yes, delete", "Cancel"
- L1074-1077 [high] _SweetAlert confirmation dialog_: "Delete File?", "Are you sure you want to delete...", "Yes, delete", "Cancel"
- L1091-1095 [high] _SweetAlert info dialog_: "File is in use", "... is still attached to ...", "Open the file to remove its channel-point reward..."
- L1111-1115 [high] _SweetAlert confirmation dialog_: "Migrate to Unified Library?", "Beta Bot required.", "The Stable Bot (5.7.x) cannot fire alerts...", "Version 5.8 will r…
- L1118 [high] _Button labels in SweetAlert_: "Yes, migrate", "Cancel"
- L1124, 1130 [high] _SweetAlert success/error dialogs_: "Migration Complete", "Migration Failed", "Server error during migration."

### channel_rewards.php  (27)
- L1204 [high] _Button text in SweetAlert_: Continue
- L1205 [high] _Button text in SweetAlert_: Cancel
- L1220 [high] _SweetAlert title_: Success!
- L1270 [high] _SweetAlert title_: Error!
- L1284 [high] _SweetAlert error message_: An error occurred during creation.
- L1299 [high] _SweetAlert title_: Error!
- L1313 [high] _SweetAlert error message_: An error occurred while processing your request.
- L1376 [high] _SSE stream output line_: Connecting to the sync service...
- L1386 [high] _SSE stream output line_: [ERROR] Unable to read completion details.
- L1389 [high] _SSE stream output message_: [PROCESS DONE]
- L1393 [high] _SSE error message_: [ERROR] Connection interrupted; waiting for the script to finish.
- L1491 [high] _SweetAlert error title_: Error
- L1492 [high] _SweetAlert validation error message_: Please fill in Title and Cost.
- L1501 [high] _Button text during form submission_: Creating...
- L1514 [high] _SweetAlert success title_: Success!
- L1524 [high] _SweetAlert error title_: Error!
- L1539 [high] _SweetAlert error message_: A network error occurred.
- L1552 [high] _SweetAlert title_: Error
- L1552 [high] _SweetAlert error message_: Could not find reward data. Try syncing first.
- L1595 [high] _SweetAlert title_: Error
- L1595 [high] _SweetAlert validation error message_: Title and Cost are required.
- L1600 [high] _Button text during save operation_: Saving...
- L1626 [high] _Button text for edit form submission_: Save Changes
- L1630 [high] _SweetAlert success title_: Success!
- L1631 [high] _SweetAlert success message_: Reward updated successfully.
- L1637 [high] _SweetAlert title_: Error
- L1643 [high] _SweetAlert error message_: Network error occurred.

### embed_builder_backend_handlers.php  (18)
- L44 [high] _JSON error message returned to user_: Embed name is required
- L56 [high] _JSON error message returned to user_: Database error:
- L68 [high] _JSON success message returned to user_: Embed updated successfully
- L76 [high] _JSON error message returned to user_: Failed to update embed:
- L86 [high] _JSON error message returned to user_: Database error:
- L99 [high] _JSON success message returned to user_: Embed created successfully
- L107 [high] _JSON error message returned to user_: Failed to create embed:
- L120 [high] _JSON error message returned to user_: Embed ID and Channel ID are required
- L128 [high] _JSON error message returned to user_: Channel ID cannot be empty
- L143 [high] _JSON error message returned to user_: API key not found. Please refresh the page and try again.
- L188 [high] _JSON error message returned to user_: Failed to initialize HTTP request
- L200 [high] _JSON error message returned to user_: HTTP request failed:
- L210 [high] _JSON error message returned to user_: Failed to send embed to Discord channel
- L218 [high] _JSON success message returned to user_: Embed sent to Discord channel successfully
- L229 [high] _JSON error message returned to user_: Embed not found
- L240 [high] _JSON error message returned to user_: Embed ID is required
- L262 [high] _JSON success message returned to user_: Embed deleted successfully
- L269 [high] _JSON error message returned to user_: Failed to delete embed:

### layout.php  (18)
- L6 [high] _Page title (HTML title tag)_: "BotOfTheSpecter"
- L8 [medium] _Meta description tag_: "BotOfTheSpecter is a powerful bot system designed..."
- L63 [high] _Sidebar brand text (layout mode)_: "Admin Panel"
- L67 [high] _Sidebar brand text (layout mode)_: "To Do List"
- L71 [high] _Sidebar brand text (default mode)_: "BotOfTheSpecter"
- L129 [high] _HTML page title_: "BotOfTheSpecter - Twitch Login"
- L179 [high] _Sidebar menu link text_: "User Dashboard"
- L184 [high] _Sidebar menu link text_: "Mod Channels"
- L217 [high] _Top bar tag (admin mode)_: "ADMIN DASHBOARD — Restricted Access"
- L219 [high] _Top bar tag (dev stream indicator)_: "Dev Stream Online — ..."
- L222 [high] _Top bar tag (act-as mode)_: "Viewing as <strong>..." and stop acting as label
- L225 [high] _Top bar tag (maintenance mode)_: "Maintenance in progress — Some features may be temporarily unavailable"
- L244 [high] _Modal title_: "Maintenance Notice"
- L248 [high] _Modal body text describing maintenance_: "We are currently performing maintenance..."
- L250-256 [high] _Modal body content (maintenance info)_: "What you can expect:", list items, "Thank you for your understanding!"
- L259 [high] _Modal button text_: "I Understand"
- L260 [high] _Modal button text_: "Don't show again today"
- L271-275 [high] _Footer copyright and legal text_: "All rights reserved", "BotOfTheSpecter is a project operated..."

### makers.php  (27)
- L10 [high] _Page title_: "Makers & Crafting Overlay"
- L102 [medium] _JSON error message response_: "Title is required"
- L135 [medium] _JSON error message response_: "Link must start with http:// or https://"
- L138 [medium] _JSON error message response_: "Invalid project"
- L153 [medium] _JSON error message response_: "Invalid project"
- L173 [medium] _JSON error message response_: "Invalid project"
- L179 [medium] _JSON error message response_: "No such project"
- L191, 192 [medium] _JSON error message responses_: "Invalid project", "No files received"
- L205-220 [medium] _JSON error messages in array_: "Not an image (png/jpg/gif): ...", "Contents do not match type: ...", error messages in upload loop
- L232 [medium] _JSON error message response_: "Project and filename required"
- L245 [medium] _JSON error message response_: "Invalid image"
- L254 [medium] _JSON error message response_: "Unknown action"
- L301 [high] _Card heading_: "Makers &amp; Crafting Overlay"
- L302 [high] _Info text paragraphs_: "Show your viewers what you're making..." and "!craft" command info
- L309 [high] _Card header title_: "Overlay Settings"
- L314-342 [high] _Form labels and button text_: "Display mode", "Position", "Font", "Image change (sec)", "Project rotate (sec)", "Accent colour", "Text colour", "Overl…
- L316-326 [high] _Select option values_: "Current project", "Finished projects", "Upcoming ideas", "Top left", "Top right", "Bottom left", "Bottom right"
- L369 [high] _Card header title_: "Add Project"
- L374 [high] _Input placeholder text_: "e.g. Hand-knit winter scarf"
- L379-381 [high] _Select option values_: "Current", "Upcoming", "Finished"
- L391 [high] _Card header title_: "Project Library"
- L394 [high] _Empty state text_: "No projects yet..." and "!craft new <title>" command reference
- L406 [high] _Badge label_: "Featured"
- L410 [medium] _Button title/tooltip_: "Feature as current" (title attribute)
- L433 [high] _Form label_: "Description / context"
- L437 [high] _Form label and placeholder_: "Link (optional)", "https://..."
- L492, 507, 524 [high] _Alert/confirm messages shown to user_: "Saved!", "Error: ...", "Could not add project", "Delete this project and its images?", "Failed"

### bot.php  (19)
- L1175 [high] _JavaScript notification message_: Session expired. Redirecting to login...
- L1189 [high] _JavaScript notification message_: Failed to stop v6 bot: (dynamic)
- L1197 [high] _JavaScript notification message_: Error processing request: (dynamic)
- L1208 [high] _JavaScript notification message_: Checking (bot) bot status...
- L1214 [high] _JavaScript notification message_: bot status check timed out. Please refresh the page to check current status.
- L1224 [high] _JavaScript notification message_: Checking (bot) bot status... (dynamic/dynamic)
- L1239 [high] _JavaScript success notification_: bot is now running with PID (dynamic)!
- L1259 [high] _JavaScript success notification_: bot appears to be running! Refreshing status...
- L1290 [high] _JavaScript notification message_: bot status verification timed out. The bot may take longer to (action).
- L1303 [high] _JavaScript success notification_: bot (action) successfully and is now (status)!
- L1397 [medium] _aria-label on close button_: Dismiss
- L1619 [high] _JavaScript notification message_: A new (bot) bot version is available!
- L1623 [high] _JavaScript notification message_: Beta bot code has been updated since your last run. Please restart the bot to apply changes.
- L1633 [high] _Tag text in version card_: Code Update Available; Update Available
- L1708 [low] _Fallback text for lastModified_: Unknown
- L1722 [high] _Status text when bot not running_: Offline
- L1748 [low] _Fallback text for API errors_: Error loading
- L1994 [high] _Hardcoded service status text_: Monitoring Disabled
- L2009 [low] _Technical info text_: (Dead)

### index.php  (14)
- L49 [high] _card header title_: Your Tasks
- L54 [high] _form label_: Search Objectives
- L55 [high] _input placeholder_: Search...
- L58 [high] _form label_: Filter by Category
- L60 [high] _select option text_: All
- L79 [high] _alert text_: Your to-do list is empty!
- L79 [high] _alert text_: Start adding tasks to get organized.
- L82 [high] _paragraph text (numeric display)_: Number of total tasks in the category:
- L97 [high] _badge text_: Completed
- L98 [high] _badge text_: Not completed
- L100 [high] _badge text_: Private
- L105 [high] _paragraph text label_: Created:
- L109 [high] _paragraph text label_: Updated:
- L129-139 [high] _relative time format strings in JavaScript_: 'second'/'seconds'/'minute'/'minutes'/'hour'/'hours'/'day'/'days'/'month'/'months'/'year'/'years' + ' ago'

### tanggle.php  (14)
- L45 [high] _heading_: Tanggle Integration
- L47 [high] _status message_: Configuration Required
- L60 [high] _label_: API Access Token
- L66 [high] _label_: Community UUID
- L77 [high] _heading_: How to Get Your Tanggle Credentials
- L83 [high] _section heading_: Active Puzzle
- L104 [high] _section heading_: Puzzle Queue
- L127 [high] _section heading_: Recent Completed Puzzles
- L133 [high] _table header_: Puzzle
- L133 [high] _table header_: Winner
- L133 [high] _table header_: Pieces
- L133 [high] _table header_: Completed
- L145 [high] _empty state message_: No Active Puzzle
- L167 [high] _empty state message_: No Completion History Yet

### custom_commands.php  (16)
- L156 [high] _Flash message (session-stored status displayed on redirect)_: Failed to update: The new command name matches a built-in command.
- L172 [high] _Flash message (session-stored status displayed on redirect)_: Command [name] updated successfully!
- L175 [high] _Flash message (session-stored status displayed on redirect)_: [name] not found or no changes made.
- L185 [high] _Flash message (session-stored status displayed on redirect)_: Error updating [name]:
- L198 [high] _Flash message (session-stored status displayed on redirect)_: Failed to add: The custom command name matches a built-in command.
- L209 [high] _Flash message (session-stored status displayed on redirect)_: Failed to add: The command '![name]' already exists in the list.
- L276 [high] _Flash message (session-stored status displayed on redirect)_: Command removed successfully
- L278 [high] _Flash message (session-stored status displayed on redirect)_: Error removing command:
- L320 [high] _Checkbox label text_: Using Beta Bot? Enables 500 character limit.
- L375 [high] _Button span text_: Manage options for your command (Beta 5.8)
- L386 [high] _Form label_: Permission Level
- L441 [high] _Button span text_: Manage options for your command (Beta 5.8)
- L452 [high] _Form label_: Permission Level
- L784 [medium] _Form label (JavaScript modal)_: Options (one per line)
- L785 [medium] _Textarea placeholder text (JavaScript modal)_: Item 1, Item 2, Item 3
- L786 [medium] _Help text (JavaScript modal)_: No limit on item count. Empty lines are ignored.

### create_reward.php  (13)
- L14 [high] _JSON error message (returned to user via AJAX)_: Client ID not configured
- L45 [high] _JSON error message (returned to user via AJAX)_: Missing required fields (title, cost)
- L65 [high] _JSON error message (returned to user via AJAX)_: Invalid title: required and max 45 characters
- L69 [high] _JSON error message (returned to user via AJAX)_: Invalid cost: must be at least 1
- L73 [high] _JSON error message (returned to user via AJAX)_: Invalid prompt: maximum 200 characters
- L77 [high] _JSON error message (returned to user via AJAX)_: Invalid max_per_stream: must be at least 1 when enabled
- L81 [high] _JSON error message (returned to user via AJAX)_: Invalid max_per_user_per_stream: must be at least 1 when enabled
- L85 [high] _JSON error message (returned to user via AJAX)_: Invalid global_cooldown_seconds: must be at least 1 when enabled
- L133 [high] _JSON message (returned to user via AJAX)_: Reward already exists
- L195 [high] _JSON error message (returned to user via AJAX)_: Twitch API Error (duplicate):
- L200 [high] _JSON error message (returned to user via AJAX)_: Twitch API Error: channel has reached maximum number of custom rewards
- L215 [high] _JSON error message (returned to user via AJAX)_: Created but no ID returned
- L224 [high] _JSON message (returned to user via AJAX)_: Created on Twitch (DB Sync Failed)

### streamlabs.php  (13)
- L47 [high] _heading_: StreamLabs Integration
- L49 [high] _status badge_: Linked
- L49 [high] _status badge_: Not Linked
- L61 [high] _success message_: StreamLabs account successfully linked!
- L68 [high] _button text_: Unlink
- L83 [high] _section heading_: Recent Donations
- L90 [high] _table header_: Donor
- L90 [high] _table header_: Amount
- L90 [high] _table header_: Message
- L90 [high] _table header_: Date
- L100 [high] _empty state message_: No donations yet
- L111 [high] _button text_: Copy Access Token
- L115 [high] _label_: Socket Token (Real-time Events)

### terminal.php  (13)
- L16 [high] _h1 heading text_: Web Terminal
- L19 [high] _paragraph description text_: Execute commands on remote servers and view live output. Select a server and enter commands below.
- L21 [high] _label text_: Select Server
- L23 [high] _select option placeholder_: Choose a server...
- L24-29 [high] _select option values_: Bot Server, Web Server, API Server, WebSocket Server, SQL Server
- L32 [high] _label text_: Command
- L33 [high] _input placeholder_: Enter command...
- L34 [high] _help text_: Press Enter or click Execute. Use 'clear' to reset the terminal.
- L38 [high] _h2 heading text_: Terminal Tools
- L43, 55 [high] _label text_: Quick Preset, Saved Snippets
- L46, 60 [high] _select option placeholders_: Select preset command..., Select saved snippet...
- L50, 62, 75, 77, 80, 87, 90 [high] _button text_: Run, Save, Execute, Clear Terminal, Interrupt, Copy Output, Download Log
- L99-100 [high] _badge text_: Status: waiting for server selection, Server: none

### video-alerts.php  (13)
- L55 [high] _section heading_: How-to info panel
- L61 [high] _section heading_: Upload
- L73 [high] _section heading_: File Management
- L82 [high] _table header_: Select
- L82 [high] _table header_: File Name
- L82 [high] _table header_: Channel Point Reward
- L82 [high] _table header_: Action
- L107 [high] _button text_: Delete Selected
- L140 [high] _button text_: Remove Mapping
- L164 [high] _button text_: Select Reward
- L169 [high] _status text_: Not Mapped
- L271 [high] _JavaScript alert text_: No Files Selected
- L284 [high] _JavaScript alert text_: Upload Failed

### remove.php  (15)
- L62 [high] _card header title_: Remove a Task
- L69 [high] _alert title text_: Your to-do list is empty!
- L70 [high] _alert body text_: You can't remove any tasks because there aren't any yet.
- L76 [high] _form label_: Search Tasks
- L77 [high] _input placeholder_: Search todos
- L80 [high] _form label_: Filter by Category
- L82 [high] _select option text_: All
- L98 [high] _heading text_: Please pick which task to remove from your list:
- L110 [high] _span text_: Uncategorized
- L111 [high] _badge text_: Completed
- L111 [high] _badge text_: Not completed
- L117 [high] _button text_: Remove
- L143 [medium] _SweetAlert title_: Are you sure?
- L144 [medium] _SweetAlert text_: This will remove the task.
- L149 [medium] _SweetAlert button text_: Yes, remove it!

### raffles.php  (12)
- L11 [high] _pageTitle variable_: Raffles
- L203 [high] _sp-alert div text_: Beta Feature: This is a beta 5.8 version feature currently in testing. Functionality may change or have unexpected behav…
- L212, 218, 222, 226 [high] _span and label text in form_: Create New Raffle, Raffle Name, Prize Description, What are they winning?, Number of Winners
- L232, 258, 263, 268 [high] _label text in checkboxes_: Enable Weighted Raffle (subscribers and VIPs get enhanced odds), Exclude Moderators from winning, Only Subscribers Can E…
- L274 [high] _label text_: Require Minimum Follow Time
- L279, 283-286 [high] _label and option text_: Minimum Follow Time, Days, Weeks, Months, Years
- L318 [high] _button span text_: Create Raffle
- L328 [high] _sp-card-title span text_: Active Raffles
- L334-343 [high] _table th headers_: ID, Name, Prize, # Winners, Status, Weights, Exclusions, Winner(s), Action
- L367 [high] _span sp-badge text_: ✓ Weighted
- L376-383 [high] _string literals in $exclusions array pushed to output_: Mods excluded, Subs only, Followers only
- L398, 404 [high] _button span text and icon_: Start, Draw

### api_keys.php  (12)
- L7 [high] _page title (visible heading)_: API Key Management
- L119 [high] _h1 heading text_: API Key Management
- L122 [high] _paragraph description text_: Manage admin API keys for different services
- L127 [high] _h2 heading text_: Create New API Key
- L132 [high] _form label text_: Service Name
- L133 [high] _input placeholder_: Enter service name
- L134 [high] _sp-help text_: Enter a unique name for the service that will use this API key
- L141 [high] _button text_: Generate API Key
- L149 [high] _h2 heading text_: Existing API Keys
- L154 [high] _alert notification text_: No API keys found. Create one above.
- L161-163 [high] _table header cells_: Service, API Key, Actions
- L18, 27, 33, 56, 62, 87 [medium] _JSON messages returned to user (SweetAlert/alert)_: Service name cannot be empty, API key created successfully, Failed to create API key, Failed to delete API key, etc.

### add_category.php  (10)
- L39 [high] _validation error message (PHP variable $category_err shown on page)_: Please enter a category name.
- L50 [high] _validation error message (PHP variable $category_err shown on page)_: This category name already exists.
- L64 [high] _success message displayed to user_: Category added successfully!
- L67 [high] _error message displayed to user_: Oops! Something went wrong. Please try again later.
- L77 [high] _card header title (hardcoded HTML text)_: Add New Category
- L95 [high] _form instruction heading_: Type in what your new category will be:
- L97 [high] _form label_: Category Name
- L98 [high] _input placeholder text_: e.g. Work, Personal, Shopping
- L104 [high] _button value/text_: Submit
- L105 [high] _button link text_: Cancel

### bot_action.php  (10)
- L19 [high] _JSON error message returned to user_: Bot start/stop is disabled while acting as another channel.
- L27 [high] _JSON error message_: Missing required parameters
- L38 [high] _JSON error message_: Invalid action
- L45 [high] _JSON error message_: Invalid bot type
- L71 [high] _JSON error message_: Username not found in session
- L117 [high] _JSON error message_: Bot is BANNED from your channel
- L124 [high] _JSON error message_: Bot is not a moderator on your channel. Please make the bot a moderator before starting.
- L146 [high] _JSON error message_: Custom bot is not verified. Please verify your custom bot in Profile settings.
- L153 [high] _JSON error message_: No custom bot configured. Please configure a custom bot in Profile settings.
- L181 [high] _JSON error message_: Operation timed out. Bot may still be processing in background.

### discordbot_overview.php  (10)
- L111 [high] _h1 heading text_: Discord Bot Configuration Overview
- L113 [high] _input placeholder_: Search users...
- L114 [high] _button link text_: Clear
- L121 [high] _modal heading text_: Discord Configuration Details
- L128 [high] _modal button text_: Close
- L133 [high] _paragraph description text_: Overview of all users with Discord bot configuration. Click a user card to view full details.
- L136 [high] _alert notification text_: No users currently have Discord bot configuration set up.
- L147, 152 [high] _badge status text_: Linked, Not Linked
- L156-180 [high] _configuration label text on cards_: Discord User:, Guild ID:, Live Channel:, Tracked Streams:, Stream Alerts, Moderation, Alerts, Stream Monitoring, Welcome…
- L202-206 [high] _stat summary text_: Total Users:, Linked Users:, With Guild:, Total Tracked Streams:

### insert.php  (10)
- L41 [high] _validation error message (shown to user)_: Please enter a task.
- L48 [high] _success message displayed to user_: Task added successfully!
- L51 [high] _error message displayed to user_: Error adding task. Please try again.
- L61 [high] _card header title_: Add a New Task
- L78 [high] _form label with icon_: Task
- L79 [high] _textarea placeholder_: Describe your task...
- L82 [high] _form label_: Category
- L96 [high] _checkbox label text_: Private (hide from OBS overlay)
- L100 [high] _button text_: Add
- L101 [high] _button link text_: Cancel

### login.php  (10)
- L5 [high] _Login page info variable (shown to user)_: "Please wait while we redirect you to Twitch for authorization."
- L94 [high] _Login page info message_: "Authentication failed or was cancelled."
- L132 [high] _Restricted access page message_: "Your account has been banned from using this system..."
- L151 [high] _Memorial access page message_: "This account has been preserved in memory..."
- L188 [high] _Error message echoed to page_: "Error updating user: ..."
- L254 [high] _Login page info message_: "Failed to parse authentication data from StreamersConnect."
- L257 [high] _Login page info message_: "Unexpected response service from StreamersConnect."
- L280, 286, 314, 320 [high] _Error messages echoed to page_: "cURL error: ...", "HTTP error: ..."
- L401 [high] _Error message echoed to page_: "Error updating user: ..."
- L546 [high] _Page heading_: "BotOfTheSpecter"

### videos.php  (10)
- L52 [high] _tab label_: Archive VODs
- L52 [high] _tab label_: Highlights
- L52 [high] _tab label_: Uploads
- L52 [high] _tab label_: Clips
- L189 [high] _error message_: Unable to connect to Twitch
- L193 [high] _error message_: Twitch API error
- L197 [high] _error message_: Video ID is required
- L201 [high] _error message_: Video deleted from Twitch
- L226 [high] _button text_: Load 20 More
- L230 [high] _button text_: Load All

### completed.php  (12)
- L68 [high] _card header title_: Mark Tasks as Completed
- L75 [high] _alert title text_: Your to-do list is empty!
- L76 [high] _alert body text_: Start adding tasks to get organized.
- L82 [high] _form label_: Search Tasks
- L83 [high] _input placeholder_: Search objectives
- L86 [high] _form label_: Filter by Category
- L88 [high] _select option text_: All
- L97 [high] _paragraph text (numeric display)_: Number of total tasks in the category:
- L113 [high] _button text_: Mark as completed
- L140 [medium] _SweetAlert title_: Mark as completed?
- L141 [medium] _SweetAlert text_: This will mark the task as completed.
- L146 [medium] _SweetAlert button text_: Yes, mark completed!

### logs.php  (12)
- L237 [high] _Log display fallback text_: "Nothing has been logged yet."
- L255, 262 [high] _Log content fallback messages_: "(log file is empty)", "Nothing has been logged yet.", "Error: ..."
- L302-304 [high] _Form label and dropdown option_: "Log Rotation:", "Current"
- L319 [high] _Button text_: "Download"
- L329 [low] _Dynamic heading_: Log title placeholder (empty on load)
- L407 [high] _Dropdown option value_: "Current"
- L437 [high] _Log display fallback_: "(log is empty)"
- L445 [high] _Error message in log display_: "Network error: Failed to fetch log data"
- L464 [high] _Error message in log display_: "Auto-refresh error: Failed to fetch log data"
- L472 [medium] _Placeholder option text check_: "SELECT A LOG TYPE"
- L544 [low] _Timezone offset logic (not text but hardcoded GMT offset)_: "isDaylightSavings ? '11' : '10'" - hardcoded timezone offsets
- L7 [high] _uses t() function - CLEAN_: admin_dashboard_title

### download_stream.php  (9)
- L20 [high] _HTTP error message header_: streaming_missing_parameters
- L30 [high] _HTTP error message header_: streaming_invalid_filename
- L54 [high] _HTTP error message header_: streaming_invalid_server_selection
- L61 [high] _HTTP error message header_: streaming_ssh2_not_installed
- L69 [high] _HTTP error message header_: streaming_connection_failed
- L76 [high] _HTTP error message header_: streaming_authentication_failed
- L84 [high] _HTTP error message header_: streaming_sftp_init_failed
- L95 [high] _HTTP error message header_: streaming_file_not_found
- L116 [high] _HTTP error message header_: streaming_file_open_failed

### mod_channels.php  (9)
- L8 [high] _Page title variable_: "Mod Channels"
- L64 [high] _Page heading_: "Mod Channels"
- L65 [high] _Page subtitle_: "Channels you can moderate for"
- L69 [high] _Alert message_: "Moderator Act As mode has been stopped."
- L72 [high] _Alert message_: "You do not have permission to Act As that channel."
- L76 [high] _Alert message_: "The selected channel could not be found."
- L82 [high] _Form label and input placeholder_: "Search channels", "Type streamer name or username"
- L88 [high] _Alert message (empty state)_: "No channels to mod, if you believe this is incorrect please ask your broadcaster to add you to the allow list."
- L104 [high] _Button text_: "Act As This Channel"

### update_objective.php  (9)
- L63 [high] _card header title_: Update Task Objective
- L70 [high] _alert title text_: Your to-do list is empty!
- L71 [high] _alert body text_: You can't update any tasks because there aren't any yet.
- L76 [high] _form heading text_: Edit your task objectives and categories below and click "Update All" to save changes:
- L95 [high] _form label_: Objective
- L99 [high] _form label_: Category
- L111 [high] _checkbox label text_: Private (hide from OBS overlay)
- L119 [high] _button text_: Update All
- L120 [high] _button link text_: Cancel

### event_sub.php  (9)
- L223 [high] _h1 heading text_: EventSub Connections
- L226 [high] _button text_: Refresh
- L230 [high] _paragraph description text_: Admin overview of Twitch EventSub connections for all users with stored bot tokens.
- L232 [high] _alert initial loading text_: Loading EventSub data...
- L236-265 [high] _stat card labels_: Users Scanned, Healthy Users, Users With Active WS, Users With Errors, Enabled WS Connections, Enabled WS Subs, Disabled…
- L271 [high] _h2 heading text_: Per-user Twitch Connection Status
- L277-286 [high] _table header cells_: User, Twitch ID, Status, Connections, Enabled WS, Disabled WS, Webhook, Cost, Error
- L291 [high] _table loading message_: Loading...
- L315, 318, 321, 323 [medium] _badge status text (JS)_: Error, High Usage, Connected, No Active Connections

### manage_custom_user_commands.php  (8)
- L49 [high] _User feedback message_: "Command '...' already exists. Please choose a different name."
- L58, 61 [high] _Success/error status messages_: "User command '...' for user '...' added successfully!", "Error: Command was not added to the database."
- L97, 100, 110 [high] _Status messages_: "User command ... updated successfully!", "... not found or no changes made.", "Error updating ..."
- L124, 127, 150, 153 [high] _Status and feedback messages_: "User command ... approved successfully!", "Error approving command:", "User command ... deleted successfully!", "... no…
- L192 [high] _Help text paragraph_: "Access: User commands can be used by the specified user and all channel moderators."
- L232 [high] _Help text_: "This command will also be available to all channel moderators."
- L308 [high] _Input placeholder text_: "Search commands or users..."
- L349 [high] _Confirmation dialog text_: "Are you sure you want to delete this command?"

### menu.php  (8)
- L17 [high] _Menu submenu label_: "Settings"
- L28 [high] _Menu item label_: "EventSub Notifications"
- L31-32 [high] _Menu item labels_: "Schedule", "Videos"
- L40 [high] _Menu item label_: "Stream Watch Streaks"
- L45 [high] _Menu item label_: "Streaming"
- L53, 68 [high] _Menu item labels_: "Alerts", "Tanggle"
- L80-112 [high] _Admin menu item labels_: "Dashboard", "User Management", "Start User Bots", "EventSub Connections", "Log Management", "Feedback", "Beta Programs"…
- L101-111 [high] _Todolist menu item labels_: "View Tasks", "Mark Tasks as Completed", "Add Task", "Update Task", "Remove Task", "View Categories", "Add Category", "O…

### music.php  (8)
- L10 [high] _pageTitle variable for layout_: Music Dashboard
- L83 [high] _error message echoed to user (status variable)_: Failed to upload ... Only MP3 files are allowed.
- L255 [high] _button span text_: Repeat 1
- L280 [high] _label text_: Music source
- L282 [high] _option label_: Built-in (DMCA-free)
- L283 [high] _option label_: Use my uploads
- L291 [high] _div warning disclaimer text_: You are responsible for all files you upload and must have the legal rights to use and share them. We do not verify or g…
- L151, 153 [high] _span label text_: Slow, Fast

### streamelements.php  (8)
- L45 [high] _heading_: StreamElements Integration
- L47 [high] _status badge_: Connected
- L47 [high] _status badge_: Not Connected
- L59 [high] _success message_: Your StreamElements account is successfully linked
- L81 [high] _table header_: Tipper
- L81 [high] _table header_: Amount
- L81 [high] _table header_: Message
- L81 [high] _table header_: Date

### beta_programs.php  (8)
- L104 [high] _h1 heading text_: Beta Programs
- L106 [high] _button text_: New Program
- L116 [high] _alert notification text_: No beta programs have been created yet.
- L122-127 [high] _table header cells_: Slug, Name, Description, Status, Created, Actions
- L138 [high] _badge status text_: Active, Inactive
- L195, 205 [high] _modal title text_: Create Beta Program, Edit Beta Program
- L174 [high] _label text with constraint hint_: Slug (lowercase, letters, numbers, hyphens, underscores)
- L24-87 [medium] _JSON error/success messages_: Slug must be at least 2 characters, Name is required, Program updated, Update failed, Program created, A program with th…

### feedback.php  (7)
- L44 [high] _h1 heading text_: Feedback Management
- L47 [high] _paragraph description text_: View and manage user feedback submissions
- L50 [high] _alert notification text_: No feedback submissions found.
- L57-63 [high] _table header cells_: ID, Type, Display Name, Message/Summary, Details, Submitted At, Actions
- L73, 77 [high] _badge type text_: Bug, Feedback
- L99, 113 [high] _button text_: View Details, Delete
- L33, 35, 50 [medium] _JSON response messages_: Feedback deleted successfully, Failed to delete feedback

### overlays.php  (6)
- L66 [high] _p text content in sp-alert_: Upcoming Overlay System Update, complete overlay experience, Specter Alerts
- L151, 153 [high] _span span text_: Slow, Fast
- L394 [high] _div sp-card-title text_: Counter Display
- L398 [high] _p descriptive text_: Display the live value of any counter ... great for things like "cats spotted: 7" on screen. Type a counter name below, …
- L399 [high] _p font-size hint text_: Built-in counters you can use right away: deaths, stream_deaths, hugs, kisses, highfives, typos, lurkers. Any other name…
- L402, 406, 410 [high] _label text_: Counter name, Text colour (optional), Background (optional)

### streaming.php  (6)
- L42 [high] _heading_: BotOfTheSpecter Streaming
- L44 [high] _badge text_: Beta Access Confirmed
- L47 [high] _feature status_: Coming Soon
- L49 [high] _section heading_: What's Being Built
- L53 [high] _feature description_: Stream Key Management
- L54 [high] _feature description_: Auto Record from Twitch

### timed_messages.php  (6)
- L88 [high] _section heading_: Current Timed Messages
- L125 [high] _trigger type label_: Timer (minutes)
- L125 [high] _trigger type label_: Chat Lines
- L125 [high] _trigger type label_: Both
- L142 [high] _badge text_: 5.8 Beta
- L142 [high] _help text_: Using Beta Bot? Enables 500 character limit

### vips.php  (6)
- L42 [high] _heading_: Manage VIPs
- L49 [high] _badge text_: VIP
- L61 [high] _empty state message_: No VIPs found
- L77 [high] _input placeholder_: Enter username
- L80 [high] _button text_: Add
- L89 [high] _button text_: Remove

### walkons.php  (6)
- L45 [high] _section heading_: Upload
- L57 [high] _section heading_: File Management
- L66 [high] _table header_: Select
- L66 [high] _table header_: File Name
- L66 [high] _table header_: Action
- L91 [high] _button text_: Delete Selected

### categories.php  (8)
- L36 [high] _error message (die() output to user)_: Error retrieving categories:
- L45 [high] _message to user_: Cannot remove the default category.
- L72 [high] _message to user_: Category not found.
- L81 [high] _alert title text_: Manage Your Categories
- L82 [high] _alert body text_: Here's the list of categories you've created. Each category helps you organize your tasks into separate lists.
- L122 [medium] _SweetAlert title_: Are you sure?
- L123 [medium] _SweetAlert confirmation text_: This will remove the category.
- L128 [medium] _SweetAlert button text_: Yes, remove it!

### known_users.php  (8)
- L157-182 [high] _Alert box content (informational text blocks)_: "Custom Variables for Welcome Messages", "You can use the following variables...", "(shoutout)", "Important: How to Use …
- L192, 198 [high] _Table header text_: "First Seen", "Last Seen", "Test"
- L216, 225 [high] _Table cell fallback text_: "Unknown"
- L236 [medium] _Character counter text_: "/255 characters"
- L270 [high] _Button title attribute (tooltip)_: "User is inactive"
- L302-304 [high] _Select option labels in dropdown_: "Current", "Crash Log"
- L424 [medium] _Toast message (success notification)_: "✓ Test Sent: ..."
- L428, 433, 438 [medium] _Toast messages (error notifications)_: "✗ Error: ...", "✗ Error: Invalid response...", "✗ Error: Failed to send..."

### counters.php  (6)
- L308 [high] _Tab button text (navigation label)_: View Data
- L308 [high] _Tab button text (navigation label)_: Edit Data
- L771 [high] _Button text_: Random Pick Lists
- L1391 [medium] _Rendered inline HTML text (JavaScript)_: No options saved
- L1553 [high] _Option label (select dropdown)_: Select User
- L1570 [high] _Option label (select dropdown)_: Select User

### get_custom_embed.php  (5)
- L9 [high] _JSON error message returned to user_: Not authenticated
- L26 [high] _JSON error message returned to user_: Missing embed ID or server ID
- L34 [high] _JSON error message returned to user_: Discord database connection failed
- L51 [high] _JSON error message returned to user_: Embed not found
- L58 [high] _JSON error message returned to user_: Server error:

### check_bot_status.php  (4)
- L18 [high] _JSON error message (returned to user via AJAX)_: Missing bot parameter
- L25 [high] _JSON error message (returned to user via AJAX)_: Invalid bot type
- L35 [high] _JSON error message (returned to user via AJAX)_: Username not found in session
- L172-181 [high] _Hardcoded time duration format strings in formatTimeAgo() function_: {$diff} seconds ago, {$minutes} minute(s) ago, {$hours} hour(s) ago, {$days} day(s) ago

### check_subscription.php  (4)
- L21 [high] _JSON error message (returned to user via AJAX)_: Not authenticated
- L29 [high] _JSON error message (returned to user via AJAX)_: Database query preparation failed
- L36 [high] _JSON error message (returned to user via AJAX)_: Broadcaster token not found
- L58 [high] _JSON error message (returned to user via AJAX)_: Twitch API request failed

### edit_reward.php  (4)
- L15 [high] _JSON error message returned to user_: Missing reward_id.
- L23 [high] _JSON error message returned to user_: DB connection failed.
- L37 [high] _JSON error message returned to user_: Reward is not managed by Specter.
- L84 [high] _JSON error message returned to user_: No fields to update.

### get_custom_embeds.php  (4)
- L9 [high] _JSON error message returned to user_: Not authenticated
- L25 [high] _JSON error message returned to user_: Missing server ID
- L33 [high] _JSON error message returned to user_: Discord database connection failed
- L59 [high] _JSON error message returned to user_: Server error:

### subscribers.php  (4)
- L82 [high] _label_: Tier
- L95 [high] _tier display text_: Tier 1
- L95 [high] _tier display text_: Tier 2
- L95 [high] _tier display text_: Tier 3

### mods.php  (4)
- L101 [high] _Page title_: Page title wrapped in t() but line 101 comment shows 't('mods_page_title')'
- L177 [high] _Error message displayed on page_: "Your Twitch authentication token is invalid or expired. Please ... to refresh your session."
- L274 [medium] _Dynamic fallback text for stale moderators_: "User ... " (stale name fallback)
- L386 [high] _Badge label for stale access_: "No longer mod"

### check_blocked_term.php  (3)
- L58 [high] _JSON message (returned to user via AJAX)_: This term matches a globally blocked spam pattern and cannot be added to your personal block list.
- L77 [high] _JSON message (returned to user via AJAX)_: This term is already whitelisted in your URL protection settings. Remove it from the whitelist first.
- L97 [high] _JSON message (returned to user via AJAX)_: This term is already blacklisted in your URL protection settings. It's already being blocked.

### fetch_banned_status.php  (3)
- L16 [high] _JSON error message returned to user_: Not authenticated
- L57 [high] _JSON error message returned to user_: Invalid usernames format
- L69 [high] _JSON error message returned to user_: Bad request

### module_data.php  (3)
- L38, 40, 42 [high] _Default welcome message strings assigned as fallback/default values; used in chat alerts shown to users_: Welcome back (user), glad to see you again! / ATTENTION! A very important person has entered the chat, welcome (user) / …
- L53-56 [high] _Default ad notice messages assigned as fallback values; shown to chat users_: Ads will be starting in (minutes). / Ads are running for (duration). We'll be right back after these ads. / Thanks for s…
- L94-100 [high] _Default Twitch chat alert messages; shown in chat when events occur_: Thank you (user) for following! Welcome to the channel! / Thank you (user) for (bits) bits! You've given a total of (tot…

### regen_api_key.php  (3)
- L10, 29 [high] _die() message, error message_: Twitch user ID not set. Please log in again. / Error updating API key:
- L10 [high] _PHP die() message shown to user_: Twitch user ID not set. Please log in again.
- L29 [high] _Echo message shown to user on API key update failure (concat with error)_: Error updating API key:

### update_banned_users_cache.php  (3)
- L19 [high] _JSON error message_: User session not found
- L23 [high] _JSON error message_: Invalid JSON received
- L27 [high] _JSON error message_: Could not create cache directory

### update_use_custom.php  (3)
- L16 [high] _JSON error message_: Not authenticated
- L19 [high] _JSON error message_: Method not allowed
- L22 [high] _JSON error message_: Invalid value for use_custom

### followers.php  (5)
- L51 [high] _JSON error message returned to user_: Failed to fetch followers
- L56 [high] _JSON error message returned to user_: Error decoding JSON response
- L271 [medium] _Chart label in JavaScript_: Follower Growth
- L345 [medium] _Dynamic text in JavaScript_: Loading 0/
- L377 [medium] _Dynamic text in JavaScript_: Loading

### notifications.php  (3)
- L14 [high] _pageTitle variable_: EventSub Notifications
- L141, 162 [high] _h1, p, h2, div text headings and labels_: EventSub Notifications, Monitor and manage your Twitch EventSub subscriptions, Internal Websocket Connections, Loading i…
- L194, 279, 281 [medium] _showNotification messages and div text_: Success, Error, No EventSub subscriptions found

### alerts.php  (2)
- L25-50 [high] _Toast notifications, form labels, modal title_: Variant saved; Invalid alert ID; No image selected; Alert configuration; Enable alert
- L60-100 [high] _Form labels, button labels_: Alert type; Alert image; Alert position; Alert duration; Alert sound; Save alert; Delete alert; Cancel

### bingo.php  (2)
- L90-110 [high] _HTML headings, labels, table headers_: API Key Required; Twitch Extension Configuration; Game ID; Start Time; End Time; Events; Status; Actions; No bingo games…
- L125-150 [high] _Button labels, JavaScript alert messages_: View Winners; View Players; Call Random; Call All; Start Vote; Random number called successfully!; Error calling random …

### bot_points.php  (2)
- L25-35 [high] _Toast notifications_: Update Points Success; Remove Points Success; Points Settings Update Success
- L40-60 [high] _Table headers, button labels_: Username; Points; Actions; Update; Remove; Settings

### builtin.php  (2)
- L50-85 [high] _Placeholder, checkbox labels, table headers, button labels_: No commands yet; Search files; Show enabled commands; Show disabled commands; Command; Description; Usage Level; Status;…
- L120-140 [high] _Button labels, tooltip, badge, modal title_: Edit; Delete this variant; Locked permission; Update Available; Cooldown Options

### check_spam_pattern.php  (2)
- L23 [high] _JSON error message (returned to user via AJAX)_: Database connection failed
- L48 [high] _JSON error message (returned to user via AJAX)_: Server error

### notifications_content.php  (2)
- L63 [high] _div class stat-secondary text_: Connection ... subscriptions, disabled/stale
- L176 [high] _div info-box text_: These subscriptions are no longer active and can be safely deleted. They do not count toward your connection or subscrip…

### premium.php  (2)
- L199-201 [high] _li title and text_: Custom Bot Name - Experimental or Coming Soon
- L204 [high] _p sp-plan-note text_: 90-95% of the bot is FREE!

### spam_patterns.php  (2)
- L8 [high] _page title_: Spam Pattern Management
- L24, 31, 38, 53, 62, 89, 91 [medium] _JSON success/error messages_: Pattern cannot be empty, Spam pattern added successfully, Failed to add pattern, Pattern updated successfully, Pattern d…

### twitch_tokens.php  (2)
- L8 [high] _page title_: Twitch App Access Tokens
- L76, 86 [medium] _JSON error messages_: Client credentials are required, cURL initialization failed

### users.php  (2)
- L31, 35, 39, 43, 47 [high] _notification messages from query string_: Invalid user selected for Act As, The selected user could not be found, Cannot Act As this user because no access token …
- L130, 136, 149 [medium] _JSON error messages_: User not found, You cannot delete your own account, You do not have permission to delete admin users

### websocket_clients.php  (2)
- L7 [high] _uses t() function - CLEAN_: admin_websocket_clients_title
- L15, 21, 23 [medium] _fallback display text_: Unknown

### check_url_conflict.php  (1)
- L15 [high] _JSON error message (returned to user via AJAX)_: Database connection failed

### controllerapp.php  (1)
- L187 [high] _Badge label (hardcoded version tag in feature card)_: Bot v5.8 Beta

### delete_stream.php  (1)
- L18-99 [high] _Flash messages and session status (displayed on redirect)_: Various t('streaming_*') calls with hardcoded fallback messages from stream deletion logic

### persistent_storage.php  (1)
- L293 [high] _p has-text-weight-bold text_: Persistent Storage Service - Terminated

### profile.php  (1)
- L345, 358, 366, 370 [high] _error message variables (hardcoded in PHP)_: Password cannot be empty., Passwords do not match., Password must be at least 6 characters., Unable to set app password …

### raids.php  (1)
- L79, 85, 88, 95-97, 115, 117, 121, 127, 145, 177, 179, 183, 188 [high] _Page headings, table headers, button labels, empty state messages_: Raids / Recent Raids - Received / Raider / Viewers / Date / Time / Latest Raid - Sent / Show Last 5 / No sent raid data …

### recording.php  (1)
- L26, 249, 252, 256, 258, 262, 264, 268, 275, 283, 289, 296, 303, 310, 346, 355, 381-390, 395, 398, 401-416, 423, 432, 436, 451-455, 464, 466, 468 [high] _Page title, error messages, alert text, form labels, button labels, table headers, instruction text_: Recording / Channel recording is currently disabled / SSH2 extension not installed / Recorder server connection details …

### restricted.php  (1)
- L7-12, 17, 19, 21, 26-33, 142, 149 [high] _Page titles, headings, restriction list items, button labels_: Restriction explanation messages (6 items) / This account has been preserved / Access denied / Your account has been res…

### schedule.php  (1)
- L9, 52, 101, 107, 115, 189, 211, 227, 233, 246, 260, 262, 269, 299, 305, 312, 331, 334, 338, 343, 364, 376, 402, 411, 429, 453, 499, 502, 509, 540, 570, 730-731, 753-788, 814-816, 948, 981, 1014 [high] _Page title, form labels, error messages, success messages, button labels, validation messages_: Twitch Schedule / Broadcaster ID not available / Segment ID is required to cancel / Start and end are required to start …

### sound-alerts.php  (1)
- L10, 81, 83, 91, 93, 103, 105, 126, 132, 137, 139, 154, 160, 163, 167, 404, 407, 413, 436, 443, 450, 456-457, 747 [high] _Page title, status messages (HTML), SweetAlert messages, JavaScript status text_: Sound Alerts / Failed to update mapping / The file has been uploaded / Failed to upload / Only MP3 files are allowed / T…

### spotifylink.php  (1)
- L62, 85, 107, 110, 118, 173, 184, 217, 225, 237-238, 264, 268, 277, 282-287, 301, 317, 354, 362, 366, 374, 436, 449, 476, 481-482 [high] _Page messages, form labels, card titles, headings, alert text, warning text_: Linking Spotify is disabled while using Act As mode / Failed to contact Spotify / Your Spotify account has been successf…

### start_bots.php  (1)
- L12 [high] _page title_: Start User Bots

### stream_streak.php  (1)
- L9, 56, 62, 68, 71, 78-82, 89-91, 101, 103, 116, 118, 124 [high] _Page title, alert text, headings, table headers, empty state messages_: Stream Watch Streaks / Beta 5.8 Feature: Stream Watch Streak tracking / Recent Milestones / Viewer / Current Streak / Be…

### userdata.php  (1)
- L35 [high] _PHP die() message shown to user on database query failure_: An error occurred.

### serve_user_music.php  (5)
- L7 [medium] _HTTP 403 error response message_: Forbidden
- L12 [medium] _HTTP 400 error response message_: Missing file
- L18 [medium] _HTTP 400 error response message_: Invalid file
- L24 [medium] _HTTP 404 error response message_: Not found
- L30 [medium] _HTTP 500 error response message_: Could not open file

### subathon.php  (3)
- L74 [medium] _hardcoded default numeric value in help text_: 60
- L75 [medium] _hardcoded default numeric value in help text_: 5
- L76 [medium] _hardcoded default numeric value in help text_: 10

### service_status.php  (2)
- L27, 33 [medium] _JSON error messages_: Authentication required, Admin access required
- L112 [medium] _JSON error message_: Invalid service

### save_discord_channel_config.php  (1)
- L14, 51, 64, 75, 113, 122, 133, 140, 167-172, 215-217, 311-315, 448-452 [medium] _JSON response messages (error and success)_: User session not found / Method not allowed / Missing required parameters / Server ID is required / Discord database con…

### send_welcome_message.php  (1)
- L46, 56, 73, 84, 120, 144, 147, 278, 281, 290, 295, 300 [medium] _JSON response messages (error and success)_: Invalid request method / Username and message are required / Welcome message is empty / Twitch app credentials missing /…

### server_metrics.php  (1)
- L17, 102 [medium] _JSON response messages (error)_: Technical access required / Error getting server metrics

### stream_command.php  (1)
- L16, 27, 46 [medium] _SSE event data messages_: Unauthorized - Please log in, Unauthorized - Admin access required, Invalid script requested

### terminal_stream.php  (1)
- L71, 91, 97 [medium] _SSE error messages_: Unhandled error, Failed to connect to bot server, Failed to start remote command

### user_db.php  (1)
- L31 [medium] _PHP die() message; partial (concat with error). Shows on fatal database connection failure_: Connection failed:

## Clean files (no user-facing hardcoded text found)

, , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , , 

## Audit notes

- **bot.php / channel_rewards.php:** Both files are large; bot.php alone contains approximately 40+ hardcoded notification messages and UI strings in JavaScript, and channel_rewards.php contains 30+ hardcoded SweetAlert messages and UI strings. Files with 25+ hardcoded strings have been summarized with the most representative examples listed. The alerts.php file (1166 lines) contains 25+ hardcoded form labels, button text, and notification strings. Dynamic content placeholders (inserted via template literals) are marked with (dynamic) or named variables. Clean files listed are pure AJAX endpoints, SSE streaming endpoints, or backend utility files with no rendered HTML UI shown to users.
- **AJAX/API handler files (check_* files, create_reward.php):** 109+ hardcoded user-facing strings found. Most are JSON error/status messages that should use i18n keys instead of hardcoded English. Several are user-visible dashboard labels, section headings, and card titles in dashboard.php and counters.php hardcoded in English. custom_commands.php has hardcoded flash messages and form labels. Note: download_stream.php and delete_stream.php appear to use t() calls but some HTTP response text is still hardcoded. The landing page section of dashboard.php (lines 335-535) has extensive hardcoded marketing/feature copy that should be either i18n'd or reviewed for consistency. Time formatting in check_bot_status.php uses hardcoded English (seconds/minutes/hours/days). Form labels in controllerapp.php and custom_commands.php hardcode "Permission Level" instead of using t(). All of these represent significant i18n audit gaps.
- **embed_builder_backend_handlers.php** is a template/reference implementation file (noted in comments at top) for code to be integrated elsewhere. fetch_banned_status.php, get_custom_embed.php, get_custom_embeds.php are pure AJAX/API backends returning JSON with hardcoded messages. followers.php has additional hardcoded strings in JavaScript chart label and loading counter at lines 271, 345, 377 (user-visible text inserted dynamically). edit_reward.php is an AJAX handler with JSON error responses. All identified findings are in JSON error/success messages or chart/UI labels shown to users.
- **menu.php, media.php, makers.php, logs.php, known_users.php, layout.php, login.php, manage_custom_user_commands.php:** ~200+ hardcoded user-facing strings total in this group. Three files (manage_redemption.php, manage_reward.php, migrate_media.php) are AJAX/backend-only with no HTML rendering to users. Most hardcoded strings are visible UI elements (headings, buttons, alerts, form labels, tooltips, dropdowns). media.php alone has ~70+ hardcoded strings across UI, modals, and SweetAlert dialogs. Many files show good i18n compliance for main content (using t() for page titles and key UI labels) but miss smaller UI strings (buttons, placeholders, help text, dynamic messages). Several strings appear in JavaScript as user-visible messages (toast notifications, alerts, confirm dialogs). Some are in form placeholders and tooltip attributes which are definitely user-facing.
- **modules.php** (~2800 lines): Scanned through line ~1078; found ~50 hardcoded strings. File contains numerous visible UI text labels, headings, button labels, placeholder text, and description text not wrapped in t(). Most notably: tab names (Game Deaths, Automated Shoutouts, TTS Settings, Custom Module Bots), section headings (Welcome Message Configuration, URL Blocking System Overview), subsection titles (Regular Members, VIP Members, Moderators), UI helper text, and descriptive paragraphs in alerts. Given file length and systematic UI patterns, the actual count of hardcoded strings exceeds ~25. Listed most representative findings from visible page sections.

  overlays.php: Contains multiple hardcoded labels and descriptions (Counter Display, counter names advice text, color/background labels, form hints).

  notifications.php and notifications_content.php: Headings and descriptive text in alert boxes and tables are hardcoded.

  premium.php, profile.php, raffles.php: Various UI labels, button text, form labels, validation error messages, and table headers are hardcoded literals.
- **Rendered pages (raids.php, recording.php, restricted.php, schedule.php, sound-alerts.php, spotifylink.php, stream_streak.php):** 3 files (regen_api_key, relink, stop_act_as) are clean — session/redirect logic only or pure backend/AJAX with no rendered UI text. The 11 files with findings have extensive hardcoded headings, form labels, button text, table headers, alert messages, and empty state messages. AJAX handlers (save_discord_channel_config.php, send_welcome_message.php, server_metrics.php) return hardcoded strings in JSON error/success messages. schedule.php is particularly heavy with ~40+ hardcoded message strings across form validation, Twitch API errors, success confirmations, and UI labels. save_discord_channel_config.php is 1483 lines and contains 30+ hardcoded JSON message strings throughout various action handlers. All hardcoded text should be moved to t() calls with appropriate keys in lang/en.php, de.php, fr.php.
- **working-or-study.php, video-alerts.php, videos.php, tanggle.php and related:** 155 hardcoded user-facing strings found across this group. Two files (switch_channel.php and upload_to_s3.php) are clean with proper i18n implementation. The most common patterns: (1) section headings and button text not wrapped in t() function, (2) empty state messages like "No tasks yet" and "No VIPs found" hardcoded in HTML, (3) status badges displaying hardcoded text like "Connected"/"Not Connected", (4) table header text in HTML not using t(), (5) Toast/alert messages concatenated with hardcoded strings and user data, (6) Modal titles and form labels as inline HTML text, (7) JavaScript showToast() and confirm() dialogs using literal strings. Most findings are HIGH confidence. Medium confidence entries (subathon.php numeric defaults) represent hardcoded numeric display values in help text that might be configuration-dependent. Files switch_channel.php and upload_to_s3.php properly use t() throughout and have no user-facing hardcoded text.
- **Backend helper files (module_data.php, serve_user_music.php, regen_api_key.php, user_db.php, userdata.php):** Most are backend includes/handlers with no user-visible output. module_data.php contains extensive hardcoded default messages (welcome alerts, ad notices, Twitch chat event alerts) that are shown to users — these should be moved to i18n lang files. serve_user_music.php uses HTTP exit() calls with simple error messages not wrapped in t(). regen_api_key.php has die() and echo statements with hardcoded user-facing text. usr_database.php console.log statements and SQL schema strings are developer/internal output only, not user-facing, and not flagged.
- **Admin subdir (21 files):** export_user_data.php and admin_access.php contain JSON error messages and status strings returned to clients (medium confidence). Several files (api_keys.php, beta_programs.php, discordbot_overview.php, event_sub.php, feedback.php, spam_patterns.php) have 20-25+ hardcoded user-facing strings each. Most issues are page titles/headings not wrapped in t(), button/form labels, placeholder text, table headers, badge labels, help text, and JSON response messages. logs.php, websocket_clients.php, and users.php mostly use t() for page titles but have scattered hardcoded messages and notification strings. terminal.php has extensive hardcoded UI text (labels, placeholders, button text). service_status.php is a pure backend/API endpoint and is clean for user-facing rendered text.
- **todolist subdir (8 files):** All 8 PHP files in dashboard/todolist/ contain extensive hardcoded user-facing strings. None are backend-only; all render HTML pages with visible text for users. Multiple hardcoded strings exist across form labels, buttons, alert messages, validation errors, modal instructions, and badge/span text. These should all be migrated to use t('key') calls with corresponding entries in lang/en.php, lang/de.php, and lang/fr.php. The most significant files are obs_options.php (70+ hardcoded strings) and index.php/remove.php (40+ each), while others have 20-40+ hardcoded strings each.