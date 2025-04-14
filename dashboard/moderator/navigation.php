<nav class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
	<div class="navbar-brand">
		<div class="has-text-centered" style="flex: 1; display: flex; flex-direction: column; align-items: center; vertical-align: middle;">
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
		<div class="navbar-start" style="align-items: center;">
			<a class="navbar-item" href="index.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
				<span class="icon" style="margin-right: 5px;"><i class="fas fa-home"></i></span>
				<span>Moderator Dashboard</span>
			</a>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link" style="display: flex; align-items: center;">
					<span class="icon" style="margin-right: 5px;"><i class="fas fa-robot"></i></span>
					<span>Bot Functions</span>
				</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="known_users.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-user-check"></i></span>
						<span>User Welcome Messages</span>
					</a>
					<a class="navbar-item" href="timed_messages.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-clock"></i></span>
						<span>Timed Chat Messages</span>
					</a>
					<a class="navbar-item" href="bot_points.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-coins"></i></span>
						<span>Bot Point System</span>
					</a>
					<hr class="navbar-divider">
					<a class="navbar-item" href="counters.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-info-circle"></i></span>
						<span>Counters and Information</span>
					</a>
					<a class="navbar-item" href="edit_counters.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-edit"></i></span>
						<span>Edit Counters</span>
					</a>
					<hr class="navbar-divider">
					<a class="navbar-item" href="commands.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-terminal"></i></span>
						<span>View Custom Commands</span>
					</a>
					<a class="navbar-item" href="manage_custom_commands.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-tools"></i></span>
						<span>Manage Custom Commands</span>
					</a>
					<hr class="navbar-divider">
					<a class="navbar-item" href="walkons.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-music"></i></span>
						<span>Walkon Audio</span>
					</a>
					<a class="navbar-item" href="sound-alerts.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-bell"></i></span>
						<span>Sound Alerts</span>
					</a>
					<a class="navbar-item" href="video-alerts.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-video"></i></span>
						<span>Video Alerts</span>
					</a>
				</div>
			</div>
			<div class="navbar-item has-dropdown is-hoverable">
				<a class="navbar-link" style="display: flex; align-items: center;">
					<span class="icon" style="margin-right: 5px;"><i class="fas fa-cogs"></i></span>
					<span>Bot Settings</span>
				</a>
				<div class="navbar-dropdown">
					<a class="navbar-item" href="builtin.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-code"></i></span>
						<span>Built-in Commands</span>
					</a>
					<a class="navbar-item" href="modules.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-puzzle-piece"></i></span>
						<span>Module Settings</span>
					</a>
					<a class="navbar-item" href="subathon.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" style="display: flex; align-items: center;">
						<span class="icon" style="margin-right: 5px;"><i class="fas fa-stopwatch"></i></span>
						<span>Subathon Settings</span>
					</a>
				</div>
			</div>
		</div>
		<div class="navbar-end">
			<div class="navbar-item">
				<div class="media" style="align-items: center;">
					<div class="media-content" style="display: flex; align-items: center;">
						<span style="font-size: 14px; color: #ffffff;"><?php echo htmlspecialchars($twitchDisplayName); ?></span>
						<figure class="image is-30x30" style="margin-left: 10px; display: inline-block;">
							<img class="is-rounded" src="<?php echo $twitch_profile_image_url; ?>" alt="<?php echo htmlspecialchars($twitchDisplayName); ?> Profile Image" style="object-fit: cover; width: 30px; height: 30px;">
						</figure>
					</div>
					<a href="../bot.php" title="Logout" style="margin-left: 10px; color: #ffffff; text-decoration: none;">
						<span class="icon" style="color: #ffffff;"><i class="fas fa-home"></i></span>
					</a>
				</div>
			</div>
		</div>
	</div>
</nav>