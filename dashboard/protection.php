<?php 
// Fetch protection settings
$getProtection = $db->query("SELECT * FROM protection LIMIT 1");
$settings = $getProtection->fetchAll(PDO::FETCH_ASSOC);
$currentSettings = isset($settings[0]['url_blocking']) ? $settings[0]['url_blocking'] : 'False';

// Fetch whitelist and blacklist links
$whitelistLinks = $db->query("SELECT link FROM link_whitelist")->fetchAll(PDO::FETCH_ASSOC);
$blacklistLinks = $db->query("SELECT link FROM link_blacklisting")->fetchAll(PDO::FETCH_ASSOC);

// Update database with settings
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // URL Blocking Settings
    if (isset($_POST['url_blocking'])) {
        $url_blocking = $_POST['url_blocking'] == 'True' ? 'True' : 'False';
        $stmt = $db->prepare("UPDATE protection SET url_blocking = ?");
        $stmt->bindParam(1, $url_blocking, PDO::PARAM_STR);
        if ($stmt->execute()) {
            $message .= "URL Blocking setting updated successfully.<br>";
        } else {
            $message .= "Failed to update your URL Blocking settings.<br>";
            error_log("Error updating URL blocking: " . implode(", ", $stmt->errorInfo()));
        }
    } else {
        $message .= "Please select either True or False.<br>";
    }

    // Whitelist Links
    if (isset($_POST['whitelist_link'])) {
        $whitelist_link = $_POST['whitelist_link'];
        $stmt = $db->prepare("INSERT INTO link_whitelist (link) VALUES (?)");
        $stmt->bindParam(1, $whitelist_link, PDO::PARAM_STR);
        if ($stmt->execute()) {
            $message .= "Link added to the whitelist.<br>";
        } else {
            $message .= "Failed to add the link to the whitelist.<br>";
            error_log("Error inserting whitelist link: " . implode(", ", $stmt->errorInfo()));
        }
    }

    // Blacklist Links
    if (isset($_POST['blacklist_link'])) {
        $blacklist_link = $_POST['blacklist_link'];
        $stmt = $db->prepare("INSERT INTO link_blacklisting (link) VALUES (?)");
        $stmt->bindParam(1, $blacklist_link, PDO::PARAM_STR);
        if ($stmt->execute()) {
            $message .= "Link added to the blacklist.<br>";
        } else {
            $message .= "Failed to add the link to the blacklist.<br>";
            error_log("Error inserting blacklist link: " . implode(", ", $stmt->errorInfo()));
        }
    }
}
?>
<div class="container">
    <br>
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
        <div class="column is-5 bot-box" style="position: relative;">
            <i class="fas fa-question-circle" id="whitelist-links-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
            <h2 class="subtitle">Whitelist Links</h2>
            <table class="table is-fullwidth is-bordered">
                <tbody>
                    <?php foreach ($whitelistLinks as $link): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($link['link']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="column is-5 bot-box" style="position: relative;">
            <i class="fas fa-question-circle" id="blacklist-links-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
            <h2 class="subtitle">Blacklist Links</h2>
            <table class="table is-fullwidth is-bordered">
                <tbody>
                    <?php foreach ($blacklistLinks as $link): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($link['link']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal" id="whitelist-links-modal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head has-background-dark">
            <p class="modal-card-title has-text-white">Whitelist Links</p>
            <button class="delete" aria-label="close" id="whitelist-links-modal-close"></button>
        </header>
        <section class="modal-card-body has-background-dark has-text-white">
            <p>Adding links to the whitelist allows them to be shared freely in your Twitch chat, regardless of any URL-blocking settings enabled for general link sharing. Whitelisted links are exempt from any automatic deletion, ensuring that trusted sources or specific links you approve can always appear in chat.</p>
            <br>
            <p>This is particularly useful for allowing links to community resources, your own websites, or trusted external platforms, providing your viewers with easy access to valuable information while maintaining strict control over unapproved content.</p>
        </section>
    </div>
</div>

<div class="modal" id="blacklist-links-modal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head has-background-dark">
            <p class="modal-card-title has-text-white">Blacklist Links</p>
            <button class="delete" aria-label="close" id="blacklist-links-modal-close"></button>
        </header>
        <section class="modal-card-body has-background-dark has-text-white">
            <p>Any link added to the blacklist will be permanently banned from appearing in your Twitch chat. Blacklisted links will be automatically detected and deleted by the Twitch bot without further intervention. This feature is invaluable for blocking spam, harmful, or distracting links that might detract from the viewer experience or violate chat guidelines.</p>
            <br>
            <p>Blacklisting provides an additional layer of security, helping to prevent phishing attempts, unwanted advertisements, or disruptive content from appearing. By curating this list, you maintain a safe, respectful, and distraction-free environment for your community.</p>
        </section>
    </div>
</div>

<script>
const modalIds = [
    { open: "whitelist-links-modal-open", close: "whitelist-links-modal-close" },
    { open: "blacklist-links-modal-open", close: "blacklist-links-modal-close" }
];

modalIds.forEach(modal => {
    const openButton = document.getElementById(modal.open);
    const closeButton = document.getElementById(modal.close);
    if (openButton) {
        openButton.addEventListener("click", function() {
            document.getElementById(modal.close.replace('-close', '')).classList.add("is-active");
        });
    }
    if (closeButton) {
        closeButton.addEventListener("click", function() {
            document.getElementById(modal.close.replace('-close', '')).classList.remove("is-active");
        });
    }
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>