<?php 
// Fetch protection settings
$getProtection = $db->query("SELECT * FROM protection LIMIT 1");
if ($getProtection) {
    $settings = $getProtection->fetch_assoc();
    $currentSettings = isset($settings['url_blocking']) ? $settings['url_blocking'] : 'False';
    $getProtection->free();
}

// Fetch whitelist and blacklist links
$whitelistLinks = [];
$blacklistLinks = [];
$getWhitelist = $db->query("SELECT link FROM link_whitelist");
if ($getWhitelist) {
    while ($row = $getWhitelist->fetch_assoc()) {
        $whitelistLinks[] = $row;
    }
    $getWhitelist->free();
}

$getBlacklist = $db->query("SELECT link FROM link_blacklisting");
if ($getBlacklist) {
    while ($row = $getBlacklist->fetch_assoc()) {
        $blacklistLinks[] = $row;
    }
    $getBlacklist->free();
}

// Update database with settings
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // URL Blocking Settings
    if (isset($_POST['url_blocking'])) {
        $url_blocking = $_POST['url_blocking'] == 'True' ? 'True' : 'False';
        $stmt = $db->prepare("UPDATE protection SET url_blocking = ?");
        $stmt->bind_param("s", $url_blocking);
        if ($stmt->execute()) {
            $message .= "URL Blocking setting updated successfully.<br>";
        } else {
            $message .= "Failed to update your URL Blocking settings.<br>";
            error_log("Error updating URL blocking: " . $db->error);
        }
        $stmt->close();
    } else {
        $message .= "Please select either True or False.<br>";
    }

    // Whitelist Links
    if (isset($_POST['whitelist_link'])) {
        $whitelist_link = $_POST['whitelist_link'];
        $stmt = $db->prepare("INSERT INTO link_whitelist (link) VALUES (?)");
        $stmt->bind_param("s", $whitelist_link);
        if ($stmt->execute()) {
            $message .= "Link added to the whitelist.<br>";
        } else {
            $message .= "Failed to add the link to the whitelist.<br>";
            error_log("Error inserting whitelist link: " . $db->error);
        }
        $stmt->close();
    }

    // Blacklist Links
    if (isset($_POST['blacklist_link'])) {
        $blacklist_link = $_POST['blacklist_link'];
        $stmt = $db->prepare("INSERT INTO link_blacklisting (link) VALUES (?)");
        $stmt->bind_param("s", $blacklist_link);
        if ($stmt->execute()) {
            $message .= "Link added to the blacklist.<br>";
        } else {
            $message .= "Failed to add the link to the blacklist.<br>";
            error_log("Error inserting blacklist link: " . $db->error);
        }
        $stmt->close();
    }

    // Remove Whitelist Link
    if (isset($_POST['remove_whitelist_link'])) {
        $remove_whitelist_link = $_POST['remove_whitelist_link'];
        $stmt = $db->prepare("DELETE FROM link_whitelist WHERE link = ?");
        $stmt->bind_param("s", $remove_whitelist_link);
        if ($stmt->execute()) {
            $message .= "Link removed from the whitelist.<br>";
        } else {
            $message .= "Failed to remove the link from the whitelist.<br>";
            error_log("Error deleting whitelist link: " . $db->error);
        }
        $stmt->close();
    }

    // Remove Blacklist Link
    if (isset($_POST['remove_blacklist_link'])) {
        $remove_blacklist_link = $_POST['remove_blacklist_link'];
        $stmt = $db->prepare("DELETE FROM link_blacklisting WHERE link = ?");
        $stmt->bind_param("s", $remove_blacklist_link);
        if ($stmt->execute()) {
            $message .= "Link removed from the blacklist.<br>";
        } else {
            $message .= "Failed to remove the link from the blacklist.<br>";
            error_log("Error deleting blacklist link: " . $db->error);
        }
        $stmt->close();
    }
}
?>
<div class="container">
    <h1 class="title">Chat Protection Settings:</h1>
    <?php if (!empty($message)): ?>
        <div class="notification is-primary has-text-black has-text-weight-bold">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <!-- URL Blocking Settings -->
        <div class="column is-2 bot-box" style="position: relative;">
            <form action="" method="post">
                <div class="field">
                    <label for="url_blocking">Enable URL Blocking:</label>
                    <div class="control">
                        <div class="select">
                            <select name="url_blocking" id="url_blocking">
                                <option value="True"<?php echo $currentSettings == 'True' ? ' selected' :'';?>>True</option>
                                <option value="False"<?php echo $currentSettings == 'False' ? ' selected' :'';?>>False</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <input type="submit" name="submit" value="Update" class="button is-primary"></input>
                </div>
            </form>
        </div>
        <!-- Whitelist Link Form -->
        <div class="column is-4 bot-box" style="position: relative;">
            <form action="" method="post">
                <div class="field">
                    <label for="whitelist_link">Enter Link to Whitelist:</label>
                    <div class="control has-icons-left">
                        <input class="input" type="url" name="whitelist_link" id="whitelist_link" placeholder="Enter a URL" required>
                        <div class="icon is-small is-left"><i class="fas fa-link"></i></div>
                    </div>
                </div>
                <div class="field">
                    <input type="submit" name="submit" value="Add to Whitelist" class="button is-primary"></input>
                </div>
            </form>
        </div>
        <!-- Blacklist Link Form -->
        <div class="column is-4 bot-box" style="position: relative;">
            <form action="" method="post">
                <div class="field">
                    <label for="blacklist_link">Enter Link to Blacklist:</label>
                    <div class="control has-icons-left">
                        <input class="input" type="url" name="blacklist_link" id="blacklist_link" placeholder="Enter a URL" required>
                        <div class="icon is-small is-left"><i class="fas fa-link"></i></div>
                    </div>
                </div>
                <div class="field">
                    <input type="submit" name="submit" value="Add to Blacklist" class="button is-primary"></input>
                </div>
            </form>
        </div>
        <!-- Whitelist and Blacklist Tables -->
        <div class="column is-5" style="position: relative;">
            <h2 class="subtitle">Whitelist Links</h2>
            <table class="table is-fullwidth is-bordered">
                <tbody>
                    <?php foreach ($whitelistLinks as $link): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($link['link']); ?></td>
                            <td>
                                <form action="" method="post" style="display:inline;">
                                    <input type="hidden" name="remove_whitelist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                    <button type="submit" class="button is-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="column is-5" style="position: relative;">
            <h2 class="subtitle">Blacklist Links</h2>
            <table class="table is-fullwidth is-bordered">
                <tbody>
                    <?php foreach ($blacklistLinks as $link): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($link['link']); ?></td>
                            <td>
                                <form action="" method="post" style="display:inline;">
                                    <input type="hidden" name="remove_blacklist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                    <button type="submit" class="button is-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>