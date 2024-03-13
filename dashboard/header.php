<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
  <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
  <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
  <div class="top-bar-left">
    <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
      <li class="menu-text">BotOfTheSpecter</li>
      <li><a href="bot.php">Dashboard</a></li>
      <li>
        <a>Twitch Data</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="mods.php">View Mods</a></li>
          <li><a href="followers.php">View Followers</a></li>
          <li><a href="subscribers.php">View Subscribers</a></li>
          <li><a href="vips.php">View VIPs</a></li>
        </ul>
      </li>
      <li><a href="logs.php">View Logs</a></li>
      <li><a href="counters.php">Counters</a></li>
      <li><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li><a href="edit_typos.php">Edit Typos</a></li>
      <li><a href="app.php">Download App</a></li>
      <li><a href="profile.php">Profile</a></li>
      <li><a href="logout.php">Logout</a></li>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.</a></li>
    </ul>
  </div>
</nav>