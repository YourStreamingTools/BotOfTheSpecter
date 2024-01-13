<META http-equiv="refresh" content="60">
<?php
try {
    $db = new PDO("sqlite:/var/www/bot/commands/{$username}_commands.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch all custom commands
    $getCommands = $db->query("SELECT * FROM custom_commands");
    $commands = $getCommands->fetchAll(PDO::FETCH_ASSOC);

    // Fetch typo counts
    $getTypos = $db->query("SELECT * FROM user_typos");
    $typos = $getTypos->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Lurkers
    $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times");
    $lurkers = $getLurkers->fetchAll(PDO::FETCH_ASSOC);

    // Prepare the Twitch API request for user data
    $userIds = array_column($lurkers, 'user_id');
    $userIdParams = implode('&id=', $userIds);
    $twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . $userIdParams;
    $clientID = ''; // CHANGE TO MAKE THIS WORK
    $headers = [
        "Client-ID: $clientID",
        "Authorization: Bearer $authToken",
    ];

    // Execute the Twitch API request
    $ch = curl_init($twitchApiUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // Decode the JSON response
    $userData = json_decode($response, true);

    // Map user IDs to usernames
    $usernames = [];
    foreach ($userData['data'] as $user) {
        $usernames[$user['id']] = $user['display_name'];
    }

    // Fetch total deaths
    $getTotalDeaths = $db->query("SELECT death_count FROM total_deaths");
    $totalDeaths = $getTotalDeaths->fetch(PDO::FETCH_ASSOC);

    // Fetch game-specific deaths
    $getGameDeaths = $db->query("SELECT game_name, death_count FROM game_deaths");
    $gameDeaths = $getGameDeaths->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch total hug counts
    $getTotalHugs = $db->query("SELECT SUM(hug_count) AS total_hug_count FROM hug_counts");
    $totalHugs = $getTotalHugs->fetch(PDO::FETCH_ASSOC);

    // Fetch hug username-specific counts
    $getHugCounts = $db->query("SELECT username, hug_count FROM hug_counts");
    $hugCounts = $getHugCounts->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch total kiss counts
    $getTotalKisses = $db->query("SELECT SUM(kiss_count) AS total_kiss_count FROM kiss_counts");
    $totalKisses = $getTotalKisses->fetch(PDO::FETCH_ASSOC);
    
    // Fetch kiss counts
    $getKissCounts = $db->query("SELECT username, kiss_count FROM kiss_counts");
    $kissCounts = $getKissCounts->fetchAll(PDO::FETCH_ASSOC);

    // Calculate lurk durations for each user
    foreach ($lurkers as $key => $lurker) {
      $startTime = new DateTime($lurker['start_time']);
      $currentTime = new DateTime();
      $interval = $currentTime->diff($startTime);

      $timeStringParts = [];
      if ($interval->y > 0) {
          $timeStringParts[] = "{$interval->y} year(s)";
      }
      if ($interval->m > 0) {
          $timeStringParts[] = "{$interval->m} month(s)";
      }
      if ($interval->d > 0) {
          $timeStringParts[] = "{$interval->d} day(s)";
      }
      if ($interval->h > 0) {
          $timeStringParts[] = "{$interval->h} hour(s)";
      }
      if ($interval->i > 0) {
          $timeStringParts[] = "{$interval->i} minute(s)";
      }
      $lurkers[$key]['lurk_duration'] = implode(', ', $timeStringParts);
    }
} catch (PDOException $e) {
  echo 'Error: ' . $e->getMessage();
}
?>