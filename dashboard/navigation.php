<div class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
  <div class="navbar-brand">
    <a class="navbar-item" href="../">
      <span>BotOfTheSpecter</span>
    </a>
    <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasic">
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
    </a>
  </div>
  <div id="navbarBasic" class="navbar-menu is-flex">
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
          <a class="navbar-item" href="../counters.php">Counters</a>
          <a class="navbar-item" href="../edit_typos.php">Edit Typos</a>
          <a class="navbar-item" href="../edit_custom_counts.php">Edit Custom Counters</a>
          <hr class="navbar-divider">
          <a class="navbar-item" href="../commands.php">View Custom Commands</a>
          <a class="navbar-item" href="../add-commands.php">Add Custom Command</a>
          <a class="navbar-item" href="../edit-commands.php">Edit Custom Command</a>
          <a class="navbar-item" href="../remove-commands.php">Remove Custom Command</a>
          <hr class="navbar-divider">
          <a class="navbar-item" href="../walkons.php">Walkon Audio</a>
          <a class="navbar-item" href="../sound-alerts.php">Sound Alerts</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Bot Settings</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="../builtin.php">Built-in Commands</a>
          <a class="navbar-item" href="../subathon.php">Subathon Settings</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Bot Integrations</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="../discordbot.php">Discord Bot</a>
          <a class="navbar-item" href="../overlays.php">Overlays</a>
          <a class="navbar-item" href="../integrations.php#fourthwall">Fourthwall Integration</a>
          <a class="navbar-item" href="../integrations.php#kofi">Ko-fi Integration</a>
          <a class="navbar-item" href="../spotifylink.php">Link Spotify</a>
          <a class="navbar-item" href="../todolist">To Do List</a>
        </div>
      </div>
      <a class="navbar-item" href="../premium.php">Premium</a>
      <a class="navbar-item" href="../profile.php">Profile</a>
      <a class="navbar-item" href="../logout.php">Logout</a>
    </div>
    <div class="navbar-end">
      <div class="navbar-item">
        <a class="popup-link" onclick="showPopup()">&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.</a>
      </div>
    </div>
  </div>
</div>