<nav class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
	<div class="navbar-brand">
		<div class="has-text-centered" style="flex: 1; display: flex; flex-direction: column; align-items: center;">
			<span class="navbar-item has-text-white" style="pointer-events: none;">
				BotOfTheSpecter
			</span>
			<div class="navbar-item is-size-7 has-text-white" style="margin-top: -0.5rem; padding-top: 0;">
				&copy; 2023-<?php echo date("Y"); ?> | All rights reserved.
			</div>
		</div>
		<button class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasic">
			<span aria-hidden="true"></span>
			<span aria-hidden="true"></span>
			<span aria-hidden="true"></span>
		</button>
	</div>
	<div id="navbarBasic" class="navbar-menu">
		<div class="navbar-start">
			<a class="navbar-item" href="../bot.php">Dashboard</a>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link">Twitch Data</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="../mods.php">Your Mods</a>
					<a class="navbar-item" href="../followers.php">Your Followers</a>
					<a class="navbar-item" href="../subscribers.php">Your Subscribers</a>
					<a class="navbar-item" href="../vips.php">Your VIPs</a>
					<a class="navbar-item" href="../channel_rewards.php">Channel Point Rewards</a>
				</div>
			</div>
			<a class="navbar-item" href="../logs.php">Logs</a>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link">Bot Functions</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="../known_users.php">User Welcome Messages</a>
					<a class="navbar-item" href="../timed_messages.php">Timed Chat Messages</a>
					<a class="navbar-item" href="../bot_points.php">Bot Point System</a>
					<hr class="navbar-divider">
					<a class="navbar-item" href="../counters.php">Counters and Information</a>
					<a class="navbar-item" href="../edit_counters.php">Edit Counters</a>
					<hr class="navbar-divider">
					<a class="navbar-item" href="../commands.php">View Custom Commands</a>
					<a class="navbar-item" href="../manage_custom_commands.php">Manage Custom Commands</a>
					<hr class="navbar-divider">
					<a class="navbar-item" href="../walkons.php">Walkon Audio</a>
					<a class="navbar-item" href="../sound-alerts.php">Sound Alerts</a>
					<a class="navbar-item" href="../video-alerts.php">Video Alerts</a>
				</div>
			</div>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link">Bot Settings</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="../builtin.php">Built-in Commands</a>
					<a class="navbar-item" href="../modules.php">Module Settings</a>
					<a class="navbar-item" href="../subathon.php">Subathon Settings</a>
				</div>
			</div>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link">Bot Integrations</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="../discordbot.php">Discord Bot</a>
					<a class="navbar-item" href="../overlays.php">Overlays</a>
					<a class="navbar-item" href="../spotifylink.php">Link Spotify</a>
					<a class="navbar-item" href="../streaming.php">Specter Streaming</a>
					<a class="navbar-item" href="../todolist">To Do List</a>
				</div>
			</div>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link">External Services</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="../integrations.php">Fourthwall</a>
					<a class="navbar-item" href="../integrations.php">Ko-Fi</a>
					<a class="navbar-item" href="../integrations.php#patreon">Patreon</a>
				</div>
			</div>
			<a class="navbar-item" href="../premium.php">Premium</a>
			<a class="navbar-item" href="../profile.php">Profile</a>
			<a class="navbar-item" href="../logout.php">Logout</a>
		</div>
		<div class="navbar-end">
			<div class="navbar-item">
				<div class="media">
					<div class="media-content" style="margin-right: 10px;">
						<span style="font-size: 14px; color: #fff;"><?php echo htmlspecialchars($twitchDisplayName); ?></span>
					</div>
					<figure class="image is-32x32">
						<img class="is-rounded" src="<?php echo $twitch_profile_image_url; ?>" alt="<?php echo htmlspecialchars($twitchDisplayName); ?> Profile Image" style="object-fit: cover; width: 32px; height: 32px;">
					</figure>
				</div>
			</div>
		</div>
	</div>
</nav>
