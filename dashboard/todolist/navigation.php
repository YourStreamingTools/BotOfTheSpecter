<div class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
  <div class="navbar-brand is-flex is-flex-direction-column is-align-items-center">
    <div class="navbar-item">
      <span>BotOfTheSpecter</span>
    </div>
    <div class="navbar-item is-size-7 has-text-grey-light" style="margin-top: -0.5rem; padding-top: 0;">
      &copy; 2023-<?php echo date("Y"); ?> | All rights reserved.
    </div>
    <button class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarBasic">
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
      <span aria-hidden="true"></span>
    </button>
  </div>
  <div id="navbarBasic" class="navbar-menu">
  <div class="navbar-start">
      <a class="navbar-item" href="../bot.php">Back to Bot</a>
      <a class="navbar-item" href="index.php">Dashboard</a>
      <a class="navbar-item" href="insert.php">Add</a>
      <a class="navbar-item" href="remove.php">Remove</a>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Update</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="update_objective.php">Update Objective</a>
          <a class="navbar-item" href="update_category.php">Update Objective Category</a>
        </div>
      </div>
      <a class="navbar-item" href="completed.php">Completed</a>
      <div class="navbar-item has-dropdown is-hoverable">
        <a class="navbar-link">Categories</a>
        <div class="navbar-dropdown">
          <a class="navbar-item" href="categories.php">View Categories</a>
          <a class="navbar-item" href="add_category.php">Add Category</a>
        </div>
      </div>
      <a class="navbar-item" href="obs_options.php">OBS Viewing Options</a>
    </div>
    <div class="navbar-end">
      <div class="navbar-item" style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 14px; color: #fff;"><?php echo htmlspecialchars($twitchDisplayName); ?></span>
        <img id="profile-image" class="round-image" src="<?php echo $twitch_profile_image_url; ?>" alt="<?php echo htmlspecialchars($twitchDisplayName); ?> Profile Image">
      </div>
    </div>
  </div>
</div>