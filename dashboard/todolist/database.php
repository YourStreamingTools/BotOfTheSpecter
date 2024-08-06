<?php
$db = new PDO("mysql:host=sql.botofthespecter.com;dbname={$username}", "USERNAME", "PASSWORD");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
?>