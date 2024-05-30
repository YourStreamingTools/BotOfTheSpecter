<div class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
  <div class="navbar-brand">
    <a class="navbar-item" href="#">
      <span class="icon">
        <i class="fas fa-bars"></i>
      </span>
      <span>BotOfTheSpecter</span>
    </a>
    <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasic">
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
    </a>
  </div>
  <div id="navbarBasic" class="navbar-menu">
    <div class="navbar-start">
      <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/bot.php') echo 'is-active'; ?>" href="bot.php">Dashboard</a>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Twitch Data</a>
        <div class="navbar-dropdown">
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/mods.php') echo 'is-active'; ?>" href="mods.php">Your Mods</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/followers.php') echo 'is-active'; ?>" href="followers.php">Your Followers</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/subscribers.php') echo 'is-active'; ?>" href="subscribers.php">Your Subscribers</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/vips.php') echo 'is-active'; ?>" href="vips.php">Your VIPs</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/channel_rewards.php') echo 'is-active'; ?>" href="channel_rewards.php">Channel Point Rewards</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Logs</a>
        <div class="navbar-dropdown">
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/logs.php') echo 'is-active'; ?>" href="logs.php">View Logs</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/chat_history.php') echo 'is-active'; ?>" href="chat_history.php">Chat History</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Bot Messages</a>
        <div class="navbar-dropdown">
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/known_users.php') echo 'is-active'; ?>" href="known_users.php">Welcome Messages</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/timed_messages.php') echo 'is-active'; ?>" href="timed_messages.php">Timed Messages</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Bot Counting</a>
        <div class="navbar-dropdown">
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/counters.php') echo 'is-active'; ?>" href="counters.php">Counters</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/edit_typos.php') echo 'is-active'; ?>" href="edit_typos.php">Edit Typos</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/edit_custom_counts.php') echo 'is-active'; ?>" href="edit_custom_counts.php">Edit Custom Counters</a>
        </div>
      </div>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Bot Commands</a>
        <div class="navbar-dropdown">
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/builtin.php') echo 'is-active'; ?>" href="builtin.php">View Built-in Commands</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/commands.php') echo 'is-active'; ?>" href="commands.php">View Custom Commands</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/add-commands.php') echo 'is-active'; ?>" href="add-commands.php">Add Custom Command</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/remove-commands.php') echo 'is-active'; ?>" href="remove-commands.php">Remove Custom Command</a>
          <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/edit-commands.php') echo 'is-active'; ?>" href="edit-commands.php">Edit Custom Command</a>
        </div>
      </div>
      <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/discordbot.php') echo 'is-active'; ?>" href="discordbot.php">Discord Bot</a>
      <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/profile.php') echo 'is-active'; ?>" href="profile.php">Profile</a>
      <a class="navbar-item <?php if($_SERVER['REQUEST_URI'] == '/logout.php') echo 'is-active'; ?>" href="logout.php">Logout</a>
    </div>
    <div class="navbar-end">
      <div class="navbar-item">
        <a class="popup-link" onclick="showPopup()">&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.</a>
      </div>
    </div>
  </div>
</div>