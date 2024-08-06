<?php
$db = new PDO("mysql:host=sql.botofthespecter.com;dbname={$username}", "USERNAME", "PASSWORD");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fetch all custom commands
$getCommands = $db->query("SELECT * FROM custom_commands");
$commands = $getCommands->fetchAll(PDO::FETCH_ASSOC);

?>