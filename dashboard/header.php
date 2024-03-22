<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
  <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
  <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
  <div class="top-bar-left">
    <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
      <li class="menu-text">BotOfTheSpecter</li>
      <?php if($_SERVER['REQUEST_URI'] == '/bot.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="bot.php">Dashboard</a></li>
      <li>
        <a>Twitch Data</a>
        <ul class="vertical menu" data-dropdown-menu>
          <?php if($_SERVER['REQUEST_URI'] == '/mods.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="mods.php">View Mods</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/followers.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="followers.php">View Followers</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/subscribers.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="subscribers.php">View Subscribers</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/vips.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="vips.php">View VIPs</a></li>
        </ul>
      </li>
      <?php if($_SERVER['REQUEST_URI'] == '/logs.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="logs.php">View Logs</a></li>
      <?php if($_SERVER['REQUEST_URI'] == '/counters.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="counters.php">Counters</a></li>
      <li>
        <a>Bot Commnads</a>
        <ul class="vertical menu" data-dropdown-menu>
          <?php if($_SERVER['REQUEST_URI'] == '/bot-commands.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="">View Built-in Commands (COMING SOON)</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/commands.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="commands.php">View Custom Commands</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/add-commands.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="add-commands.php">Add Custom Command</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/remove-commands.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="">Remove Custom Command (COMING SOON)</a></li>
          <?php if($_SERVER['REQUEST_URI'] == '/edit-commands.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="">Edit Custom Command (COMING SOON)</a></li>
        </ul>
      </li>
      <?php if($_SERVER['REQUEST_URI'] == '/edit_typos.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="edit_typos.php">Edit Typos</a></li>
      <?php if($_SERVER['REQUEST_URI'] == '/app.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="app.php">Download App</a></li>
      <?php if($_SERVER['REQUEST_URI'] == '/profile.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="profile.php">Profile</a></li>
      <?php if($_SERVER['REQUEST_URI'] == '/logout.php') echo '<li class="is-active">'; else echo '<li>' ?><a href="logout.php">Logout</a></li>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.</a></li>
    </ul>
  </div>
</nav>
