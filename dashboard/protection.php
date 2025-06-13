<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

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
<h1 class="title is-3 mb-4">
    <span class="icon has-text-info"><i class="fas fa-shield-alt"></i></span>
    <?php echo t('protection_title'); ?>
</h1>
<?php if (!empty($message)): ?>
    <div class="notification is-primary is-light has-text-black has-text-weight-bold is-rounded mb-5">
        <?php echo $message; ?>
    </div>
<?php endif; ?>
<div class="columns is-multiline is-variable is-5 is-centered">
    <!-- URL Blocking Settings -->
    <div class="column is-4">
        <div class="card" style="height: 100%;">
            <div class="card-content">
                <div class="has-text-centered mb-4">
                    <h3 class="title is-5">
                        <span class="icon has-text-link"><i class="fas fa-link-slash"></i></span>
                        <?php echo t('protection_enable_url_blocking'); ?>
                    </h3>
                </div>
                <form action="" method="post">
                    <div class="field">
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="url_blocking" id="url_blocking">
                                    <option value="True"<?php echo $currentSettings == 'True' ? ' selected' :'';?>><?php echo t('yes'); ?></option>
                                    <option value="False"<?php echo $currentSettings == 'False' ? ' selected' :'';?>><?php echo t('no'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field mt-4">
                        <button type="submit" name="submit" class="button is-primary is-fullwidth">
                            <span class="icon"><i class="fas fa-save"></i></span>
                            <span><?php echo t('protection_update_btn'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Whitelist Link Form -->
    <div class="column is-4">
        <div class="card" style="height: 100%;">
            <div class="card-content">
                <div class="has-text-centered mb-4">
                    <h3 class="title is-5">
                        <span class="icon has-text-success"><i class="fas fa-check-circle"></i></span>
                        <?php echo t('protection_enter_link_whitelist'); ?>
                    </h3>
                </div>
                <form action="" method="post">
                    <div class="field">
                        <div class="control has-icons-left">
                            <input class="input" type="url" name="whitelist_link" id="whitelist_link" placeholder="<?php echo t('protection_enter_url_placeholder'); ?>" required>
                            <span class="icon is-small is-left"><i class="fas fa-link"></i></span>
                        </div>
                    </div>
                    <div class="field mt-4">
                        <button type="submit" name="submit" class="button is-link is-fullwidth">
                            <span class="icon"><i class="fas fa-plus-circle"></i></span>
                            <span><?php echo t('protection_add_to_whitelist'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Blacklist Link Form -->
    <div class="column is-4">
        <div class="card" style="height: 100%;">
            <div class="card-content">
                <div class="has-text-centered mb-4">
                    <h3 class="title is-5">
                        <span class="icon has-text-danger"><i class="fas fa-ban"></i></span>
                        <?php echo t('protection_enter_link_blacklist'); ?>
                    </h3>
                </div>
                <form action="" method="post">
                    <div class="field">
                        <div class="control has-icons-left">
                            <input class="input" type="url" name="blacklist_link" id="blacklist_link" placeholder="<?php echo t('protection_enter_url_placeholder'); ?>" required>
                            <span class="icon is-small is-left"><i class="fas fa-link"></i></span>
                        </div>
                    </div>
                    <div class="field mt-4">
                        <button type="submit" name="submit" class="button is-danger is-fullwidth">
                            <span class="icon"><i class="fas fa-minus-circle"></i></span>
                            <span><?php echo t('protection_add_to_blacklist'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Whitelist and Blacklist Tables -->
    <div class="column is-6">
        <div class="box">
            <h2 class="subtitle is-5 mb-3">
                <span class="icon has-text-success"><i class="fas fa-list-ul"></i></span>
                <?php echo t('protection_whitelist_links'); ?>
            </h2>
            <table class="table is-fullwidth is-bordered is-striped is-hoverable">
                <tbody>
                    <?php foreach ($whitelistLinks as $link): ?>
                        <tr>
                            <td class="is-size-6"><?php echo htmlspecialchars($link['link']); ?></td>
                            <td class="has-text-right">
                                <form action="" method="post" style="display:inline;">
                                    <input type="hidden" name="remove_whitelist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                    <button type="submit" class="button is-danger is-small is-rounded">
                                        <span class="icon"><i class="fas fa-trash-alt"></i></span>
                                        <span><?php echo t('protection_remove'); ?></span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="column is-6">
        <div class="box">
            <h2 class="subtitle is-5 mb-3">
                <span class="icon has-text-danger"><i class="fas fa-list-ul"></i></span>
                <?php echo t('protection_blacklist_links'); ?>
            </h2>
            <table class="table is-fullwidth is-bordered is-striped is-hoverable">
                <tbody>
                    <?php foreach ($blacklistLinks as $link): ?>
                        <tr>
                            <td class="is-size-6"><?php echo htmlspecialchars($link['link']); ?></td>
                            <td class="has-text-right">
                                <form action="" method="post" style="display:inline;">
                                    <input type="hidden" name="remove_blacklist_link" value="<?php echo htmlspecialchars($link['link']); ?>">
                                    <button type="submit" class="button is-danger is-small is-rounded">
                                        <span class="icon"><i class="fas fa-trash-alt"></i></span>
                                        <span><?php echo t('protection_remove'); ?></span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>