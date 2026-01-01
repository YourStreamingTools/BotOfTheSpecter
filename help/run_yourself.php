<?php
ob_start();
?>
<nav class="breadcrumb has-text-light" aria-label="breadcrumbs" style="margin-bottom: 2rem; background-color: rgba(255, 255, 255, 0.05); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.1);">
    <ul>
        <li><a href="index.php" class="has-text-light">Home</a> <span style="color: #fff;">→</span></li>
        <li class="is-active"><a aria-current="page" class="has-text-link has-text-weight-bold">Run Yourself</a></li>
    </ul>
</nav>
<h1 class="title is-2 has-text-light">Run BotOfTheSpecter Yourself</h1>
<p class="subtitle has-text-light">Self-host BotOfTheSpecter and have complete control over your bot deployment</p>
<div class="content has-text-light">
    <div class="notification is-info has-background-dark">
        <h3 class="title is-4 has-text-light">
            <span class="icon">
                <i class="fas fa-info-circle"></i>
            </span>
            Complete Freedom & Control
        </h3>
        <p class="has-text-light">To run the source code of BotOfTheSpecter on your own set of servers and not use our hosted system, you'll have complete freedom to host it yourself with more control over your data. BotOfTheSpecter runs on a full headless Linux server architecture.</p>
    </div>
    <div class="notification is-warning has-background-dark">
        <h3 class="title is-4 has-text-light">
            <span class="icon">
                <i class="fas fa-exclamation-triangle"></i>
            </span>
            Advanced Setup Required
        </h3>
        <p class="has-text-light">Running SpecterSystems yourself requires technical knowledge of server administration, Python, PHP and Linux. This is recommended for experienced developers and system administrators only.</p>
    </div>
    <h2 class="title is-3 has-text-light" id="requirements">
        <span class="icon">
            <i class="fas fa-cogs"></i>
        </span>
        Server Architecture
    </h2>
    <div class="notification is-success has-background-dark">
        <h4 class="title is-5 has-text-light">
            <span class="icon">
                <i class="fas fa-cloud"></i>
            </span>
            Recommended Hosting: Linode
        </h4>
        <p class="has-text-light mb-3">We recommend running the SpecterSystems on <strong>Linode</strong>. Our systems have been fully tested and optimized to work seamlessly on Linode's infrastructure.</p>
        <p class="has-text-light mb-3">
            <strong>Get $100 in free credit:</strong> Use our referral link to receive <strong>$100 of Linode credit</strong> to use within 60 days once you've entered a valid payment method to your Linode account.
        </p>
        <a href="https://www.linode.com/lp/refer/?r=210010495bf7dc151d31289c7bc399f8933f79e3" target="_blank" class="button is-success">
            <span class="icon">
                <i class="fas fa-external-link-alt"></i>
            </span>
            <span>Get $100 Linode Credit</span>
        </a>
    </div>
    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5">Minimum Requirements: 4 Servers</h4>
        <p class="mb-4">The minimum server setup required to run SpecterSystems consists of <strong>4 servers</strong> running on a headless Linux architecture:</p>
        <div class="columns">
            <div class="column">
                <div style="background-color: #2a2a2a; border-left: 4px solid #3273dc; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h5 class="title is-6 has-text-light">Server 1: Web/Dashboard</h5>
                    <ul style="font-size: 0.9rem;">
                        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS+)</li>
                        <li><strong>CPU:</strong> 1+ core</li>
                        <li><strong>RAM:</strong> 1GB minimum</li>
                        <li><strong>Service:</strong> PHP/Apache2 Dashboard</li>
                    </ul>
                </div>
            </div>
            <div class="column">
                <div style="background-color: #2a2a2a; border-left: 4px solid #48c774; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h5 class="title is-6 has-text-light">Server 2: API</h5>
                    <ul style="font-size: 0.9rem;">
                        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS+)</li>
                        <li><strong>CPU:</strong> 1+ core</li>
                        <li><strong>RAM:</strong> 1GB minimum</li>
                        <li><strong>Service:</strong> FastAPI server</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="columns">
            <div class="column">
                <div style="background-color: #2a2a2a; border-left: 4px solid #ffdd57; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h5 class="title is-6 has-text-light">Server 3: WebSocket</h5>
                    <ul style="font-size: 0.9rem;">
                        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS+)</li>
                        <li><strong>CPU:</strong> 1+ core</li>
                        <li><strong>RAM:</strong> 1GB minimum</li>
                        <li><strong>Service:</strong> Python SocketIO server</li>
                    </ul>
                </div>
            </div>
            <div class="column">
                <div style="background-color: #2a2a2a; border-left: 4px solid #f14668; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h5 class="title is-6 has-text-light">Server 4: Database</h5>
                    <ul style="font-size: 0.9rem;">
                        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS+)</li>
                        <li><strong>CPU:</strong> 2+ cores</li>
                        <li><strong>RAM:</strong> 4GB minimum</li>
                        <li><strong>Service:</strong> MySQL</li>
                    </ul>
                </div>
            </div>
        </div>
        <h4 class="title is-5 mt-6">Recommended Setup: 5 Servers</h4>
        <p class="mb-4">For production deployments with improved reliability and scalability, a <strong>5-server setup</strong> is recommended, adding a dedicated bot server (this is how SpecterSystems currently runs):</p>
        <div class="columns">
            <div class="column">
                <div style="background-color: #2a2a2a; border-left: 4px solid #b56edb; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h5 class="title is-6 has-text-light">Server 5: Bot</h5>
                    <ul style="font-size: 0.9rem;">
                        <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS+)</li>
                        <li><strong>CPU:</strong> 2+ cores</li>
                        <li><strong>RAM:</strong> 4GB minimum</li>
                        <li><strong>Service:</strong> Python bot process</li>
                    </ul>
                    <div style="background-color: rgba(255, 255, 255, 0.1); padding: 0.75rem; margin-top: 0.75rem; border-radius: 4px; font-size: 0.85rem; border-left: 2px solid #b56edb;">
                        <strong>Note:</strong> The 2+ cores and 4GB RAM configuration is optimized for running <strong>many bots through the service for multiple users</strong>. If you're only running a <strong>single bot</strong> for personal use, 1 core and 1GB RAM is sufficient.
                    </div>
                </div>
            </div>
        </div>
        <h4 class="title is-5 mt-6">Common Software Requirements (All Servers)</h4>
        <ul>
            <li><strong>OS:</strong> Linux (Ubuntu 24.04 LTS or newer)</li>
            <li><strong>Python:</strong> 3.8+ (Bot, API, and WebSocket servers)</li>
            <li><strong>PHP:</strong> 8.0+ (Web/Dashboard server)</li>
            <li><strong>Apache2:</strong> (Web/Dashboard server)</li>
            <li><strong>MySQL:</strong> (Database server)</li>
            <li><strong>Git:</strong> For version control</li>
        </ul>
        <h4 class="title is-5 mt-6">Network & Services</h4>
        <ul>
            <li>Twitch API credentials (OAuth tokens)</li>
            <li>Discord bot token (optional)</li>
            <li>Spotify API credentials (optional)</li>
            <li>OpenWeatherMap API key (optional)</li>
            <li>SSL/TLS certificates for secure communication</li>
            <li>Firewall configured for internal communication</li>
        </ul>
    </div>
    <h2 class="title is-3 has-text-light" id="installation">
        <span class="icon">
            <i class="fas fa-download"></i>
        </span>
        Installation Steps
    </h2>
    <h3 class="title is-4 has-text-light">Prerequisites (All Servers)</h3>
    <div class="box has-background-dark has-text-light">
        <p>Before deploying to individual servers, ensure each Linux server has:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;">
# Update system packages (All Servers)
sudo apt update && sudo apt upgrade -y

# Install common dependencies (All Servers)
sudo apt install -y curl wget git build-essential openssl ssl-cert

# Create botofthespecter user (All Servers)
sudo useradd -m -s /bin/bash botofthespecter
sudo usermod -aG sudo botofthespecter

# For Servers 1, 2, 3, 5 - Install Python and pip
sudo apt install -y python3 python3-pip python3-venv

# For Server 1 Only - Install PHP and Apache2
sudo apt install -y php php-cli php-fpm php-curl php-json php-mysql php-ssh2 apache2 libapache2-mod-php

# For Server 4 Only - Install MySQL
sudo apt install -y mysql-server
        </pre>
    </div>
    <h3 class="title is-4 has-text-light">Step 1: Clone the Repository (Servers 1, 2, 3, 5)</h3>
    <div class="box has-background-dark has-text-light">
        <p>Clone the BotOfTheSpecter repository to a temporary directory on each server (except Server 4 - Database):</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">cd /tmp
git clone https://github.com/YourStreamingTools/BotOfTheSpecter.git botofthespecter-temp
cd botofthespecter-temp</code></pre>
        <p class="mt-3">Then move the appropriate files to their destinations based on your server type:</p>
        
        <h5 class="title is-6 mt-3">For Server 1 (Web/Dashboard):</h5>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo rm -rf /var/www/html
sudo cp -r /tmp/botofthespecter-temp/dashboard /var/www/
sudo cp -r /tmp/botofthespecter-temp/home /var/www/
sudo cp -r /tmp/botofthespecter-temp/html /var/www/
sudo cp -r /tmp/botofthespecter-temp/overlay /var/www/
sudo cp -r /tmp/botofthespecter-temp/roadmap /var/www/
sudo cp -r /tmp/botofthespecter-temp/tts /var/www/
sudo cp -r /tmp/botofthespecter-temp/walkons /var/www/
sudo cp -r /tmp/botofthespecter-temp/videoalerts /var/www/
sudo cp -r /tmp/botofthespecter-temp/soundalerts /var/www/
sudo cp -r /tmp/botofthespecter-temp/config /var/www/
sudo cp -r /tmp/botofthespecter-temp/cdn /var/www/
sudo chown -R www-data:www-data /var/www</code></pre>

        <h5 class="title is-6 mt-3">For Server 2 (API):</h5>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo cp -r /tmp/botofthespecter-temp/api /home/botofthespecter/
sudo chown -R botofthespecter:botofthespecter /home/botofthespecter</code></pre>

        <h5 class="title is-6 mt-3">For Server 3 (WebSocket):</h5>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo cp -r /tmp/botofthespecter-temp/websocket /home/botofthespecter/
sudo chown -R botofthespecter:botofthespecter /home/botofthespecter</code></pre>

        <h5 class="title is-6 mt-3">For Server 5 (Bot):</h5>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo cp -r /tmp/botofthespecter-temp/bot /home/botofthespecter/
sudo chown -R botofthespecter:botofthespecter /home/botofthespecter</code></pre>

        <h5 class="title is-6 mt-3">Clean up temporary directory (All Servers):</h5>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">rm -rf /tmp/botofthespecter-temp</code></pre>
    </div>
    <h3 class="title is-4 has-text-light">Step 2: Configure Database Server (Server 4 Only)</h3>
    <div class="box has-background-dark has-text-light">
        <p><strong>Note:</strong> Server 4 (Database) does not require any files from the repository. It only needs MySQL installed and configured.</p>
        <p class="mt-3"><strong>Important:</strong> User-specific databases are created automatically on login, so you do not need to create them manually.</p>
        <p class="mt-3">You only need to create the following databases manually:</p>
        
        <h5 class="title is-6 mt-3">Required Databases:</h5>
        <ul>
            <li><strong>spam_patterns</strong> - For the bot to auto-ban users matching spam patterns</li>
            <li><strong>website</strong> - For the main website</li>
            <li><strong>specterdiscordbot</strong> - If running the Discord bot (optional)</li>
            <li><strong>roadmap</strong> - If running the roadmap site (optional)</li>
        </ul>

        <p class="mt-3">Run the following SQL commands to set up the database(s). The <strong>user-specific</strong> databases are created automatically on login; the following are the manual databases and tables you should create for core features:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo mysql -u root -p
-- spam_patterns: stores spam regex/phrases for auto-ban
CREATE DATABASE IF NOT EXISTS spam_patterns;
USE spam_patterns;
CREATE TABLE IF NOT EXISTS spam_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spam_pattern TEXT NOT NULL
);

-- roadmap: roadmap site schema
CREATE DATABASE IF NOT EXISTS roadmap;
USE roadmap;

-- roadmap_items: Main roadmap items
CREATE TABLE IF NOT EXISTS roadmap_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT,
    category ENUM('REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED') NOT NULL DEFAULT 'REQUESTS',
    subcategory ENUM('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE') NOT NULL,
    priority ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    website_type ENUM('DASHBOARD', 'OVERLAYS') DEFAULT NULL,
    completed_date DATE,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_subcategory (subcategory),
    INDEX idx_priority (priority),
    INDEX idx_created_at (created_at)
);

-- roadmap_comments: Comments on roadmap items
CREATE TABLE IF NOT EXISTS roadmap_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    username VARCHAR(255) NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
    INDEX idx_item_id (item_id),
    INDEX idx_created_at (created_at)
);

-- specterdiscordbot: Discord bot
CREATE DATABASE IF NOT EXISTS specterdiscordbot;
USE specterdiscordbot;

-- channel_mappings
CREATE TABLE IF NOT EXISTS channel_mappings (
    channel_code VARCHAR(255) NOT NULL,
    guild_id VARCHAR(255) NOT NULL DEFAULT '',
    channel_id VARCHAR(255) NOT NULL DEFAULT '',
    channel_name VARCHAR(255),
    user_id VARCHAR(255),
    username VARCHAR(255),
    twitch_display_name VARCHAR(255),
    twitch_user_id VARCHAR(255),
    guild_name VARCHAR(255),
    stream_alert_channel_id VARCHAR(255),
    moderation_channel_id VARCHAR(255),
    alert_channel_id VARCHAR(255),
    online_text TEXT,
    offline_text TEXT,
    is_active TINYINT(1) DEFAULT 1,
    event_count INT DEFAULT 0,
    last_event_type VARCHAR(255),
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_code),
    KEY idx_guild_id (guild_id),
    KEY idx_channel_id (channel_id),
    KEY idx_twitch_user_id (twitch_user_id)
);

-- live_notifications
CREATE TABLE IF NOT EXISTS live_notifications (
    guild_id VARCHAR(255) NOT NULL DEFAULT '',
    username VARCHAR(255) NOT NULL,
    stream_id VARCHAR(255) NOT NULL,
    started_at DATETIME,
    posted_at DATETIME,
    PRIMARY KEY (guild_id, stream_id),
    KEY idx_username (username)
);

-- role_selection_messages
CREATE TABLE IF NOT EXISTS role_selection_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(255),
    channel_id VARCHAR(255),
    message_id VARCHAR(255),
    message_text TEXT,
    mappings TEXT,
    role_mappings JSON,
    allow_multiple TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_server_id (server_id),
    KEY idx_channel_id (channel_id),
    KEY idx_message_id (message_id)
);

-- rules_messages
CREATE TABLE IF NOT EXISTS rules_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(255),
    channel_id VARCHAR(255),
    message_id VARCHAR(255),
    title TEXT,
    rules_content TEXT,
    color VARCHAR(7),
    accept_role_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_server_id (server_id),
    KEY idx_channel_id (channel_id),
    KEY idx_message_id (message_id)
);

-- server_management
CREATE TABLE IF NOT EXISTS server_management (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id VARCHAR(255) NOT NULL,
    welcomeMessage TINYINT(1) DEFAULT 0,
    autoRole TINYINT(1) DEFAULT 0,
    roleHistory TINYINT(1) DEFAULT 0,
    messageTracking TINYINT(1) DEFAULT 0,
    roleTracking TINYINT(1) DEFAULT 0,
    serverRoleManagement TINYINT(1) DEFAULT 0,
    userTracking TINYINT(1) DEFAULT 0,
    reactionRoles TINYINT(1) DEFAULT 0,
    rulesConfiguration TINYINT(1) DEFAULT 0,
    streamSchedule TINYINT(1) DEFAULT 0,
    stream_schedule_configuration TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    welcome_message_configuration_channel VARCHAR(255),
    welcome_message_configuration_message VARCHAR(50),
    welcome_message_configuration_default INT,
    welcome_message_configuration_embed TINYINT(1) DEFAULT 0,
    welcome_message_configuration_colour VARCHAR(7) DEFAULT '#00d1b2',
    auto_role_assignment_configuration_role_id VARCHAR(255),
    role_history_configuration_setting INT,
    role_history_configuration_option VARCHAR(255),
    message_tracking_configuration_channel VARCHAR(255),
    message_tracking_configuration_message_edits INT,
    message_tracking_configuration_message_delete INT,
    role_tracking_configuration_channel VARCHAR(255),
    role_tracking_configuration_role_added INT,
    role_tracking_configuration_role_removed INT,
    server_role_management_configuration_channel VARCHAR(255),
    server_role_management_configuration_role_created INT,
    server_role_management_configuration_role_deleted INT,
    user_tracking_configuration_channel VARCHAR(255),
    user_tracking_configuration_nickname INT,
    user_tracking_configuration_avatar INT,
    user_tracking_configuration_status INT,
    reaction_roles_configuration JSON,
    rules_configuration JSON,
    KEY idx_server_id (server_id)
);

-- tickets
CREATE TABLE IF NOT EXISTS tickets (
    ticket_id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id VARCHAR(255) NOT NULL DEFAULT '',
    user_id VARCHAR(255) NOT NULL DEFAULT '',
    username VARCHAR(255),
    channel_id VARCHAR(255),
    issue TEXT,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    closed_by BIGINT NULL,
    KEY idx_guild_id (guild_id),
    KEY idx_user_id (user_id)
);

-- ticket_comments
CREATE TABLE IF NOT EXISTS ticket_comments (
    comment_id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id VARCHAR(255),
    ticket_id INT NOT NULL,
    user_id VARCHAR(255),
    username VARCHAR(255),
    comment TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_id (ticket_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE
);

-- ticket_history
CREATE TABLE IF NOT EXISTS ticket_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id VARCHAR(255),
    username VARCHAR(255),
    action VARCHAR(100),
    details TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_id (ticket_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE
);

-- ticket_settings
CREATE TABLE IF NOT EXISTS ticket_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guild_id VARCHAR(255),
    owner_id VARCHAR(255),
    info_channel_id VARCHAR(255),
    category_id VARCHAR(255),
    closed_category_id VARCHAR(255),
    support_role_id VARCHAR(255),
    mod_channel_id VARCHAR(255),
    enabled TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- website: core website tables (api metrics, users, tokens, settings)
CREATE DATABASE IF NOT EXISTS website;
USE website;

CREATE TABLE IF NOT EXISTS api_counts (
    id INT NOT NULL AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    reset_day INT NOT NULL,
    updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY type (type)
);

CREATE TABLE IF NOT EXISTS bot_messages (
    bot_system VARCHAR(255) NOT NULL,
    counted_since DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    messages_sent INT NOT NULL DEFAULT 0,
    last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bot_system)
);

CREATE TABLE IF NOT EXISTS custom_bots (
    channel_id VARCHAR(255) NOT NULL,
    bot_username VARCHAR(255) NOT NULL,
    bot_channel_id VARCHAR(255) NOT NULL,
    access_token VARCHAR(255) NOT NULL,
    refresh_token VARCHAR(255) NOT NULL,
    token_expires DATETIME NOT NULL,
    is_verified INT NOT NULL DEFAULT 0,
    PRIMARY KEY (channel_id)
);

CREATE TABLE IF NOT EXISTS discord_users (
    user_id INT NOT NULL,
    discord_id VARCHAR(255) NOT NULL,
    access_token VARCHAR(255) DEFAULT NULL,
    refresh_token VARCHAR(255) DEFAULT NULL,
    reauth INT NOT NULL DEFAULT 0,
    discord_allowed_callers TINYINT NOT NULL DEFAULT 0,
    manual_ids INT NOT NULL DEFAULT 0,
    guild_id VARCHAR(255) DEFAULT NULL,
    live_channel_id VARCHAR(255) DEFAULT NULL,
    stream_alert_channel_id VARCHAR(255) DEFAULT NULL,
    moderation_channel_id VARCHAR(255) DEFAULT NULL,
    alert_channel_id VARCHAR(255) DEFAULT NULL,
    member_streams_id VARCHAR(255) DEFAULT NULL,
    stream_alert_everyone TINYINT NOT NULL DEFAULT 1,
    stream_alert_custom_role VARCHAR(255) DEFAULT NULL,
    online_text VARCHAR(20) NOT NULL DEFAULT 'Live on Twitch',
    offline_text VARCHAR(20) NOT NULL DEFAULT 'Not Live',
    auto_role_id VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY user_id (user_id),
    CONSTRAINT discord_users_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback (
    id INT NOT NULL AUTO_INCREMENT,
    twitch_user_id VARCHAR(64) DEFAULT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    message TEXT,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS languages (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    code VARCHAR(50) NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS moderator_access (
    moderator_id VARCHAR(255) NOT NULL,
    broadcaster_id VARCHAR(255) NOT NULL,
    PRIMARY KEY (moderator_id, broadcaster_id),
    KEY broadcaster_id (broadcaster_id),
    CONSTRAINT moderator_access_ibfk_1 FOREIGN KEY (moderator_id) REFERENCES users (twitch_user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT moderator_access_ibfk_2 FOREIGN KEY (broadcaster_id) REFERENCES users (twitch_user_id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS restricted_users (
    id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    twitch_user_id VARCHAR(50) NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS spotify_tokens (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    email TEXT,
    auth TINYINT NOT NULL DEFAULT 1,
    has_access INT NOT NULL DEFAULT 0,
    access_token VARCHAR(255) NOT NULL,
    refresh_token VARCHAR(255) NOT NULL,
    own_client TINYINT NOT NULL DEFAULT 0,
    client_id VARCHAR(255) DEFAULT NULL,
    client_secret VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    CONSTRAINT spotify_tokens_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS streamelements_tokens (
    twitch_user_id VARCHAR(50) NOT NULL,
    access_token VARCHAR(255) NOT NULL,
    refresh_token VARCHAR(255) NOT NULL,
    jwt_token LONGTEXT,
    PRIMARY KEY (twitch_user_id)
);

CREATE TABLE IF NOT EXISTS streamlabs_tokens (
    twitch_user_id VARCHAR(255) NOT NULL DEFAULT '',
    access_token LONGTEXT NOT NULL,
    refresh_token LONGTEXT NOT NULL,
    socket_token LONGTEXT,
    expires_in INT NOT NULL DEFAULT 3600,
    created_at INT NOT NULL,
    PRIMARY KEY (twitch_user_id)
);

CREATE TABLE IF NOT EXISTS system_metrics (
    id INT NOT NULL AUTO_INCREMENT,
    server_name VARCHAR(255) NOT NULL,
    cpu_percent FLOAT NOT NULL,
    ram_percent FLOAT NOT NULL,
    ram_used FLOAT NOT NULL,
    ram_total FLOAT NOT NULL,
    disk_percent FLOAT NOT NULL,
    disk_used FLOAT NOT NULL,
    disk_total FLOAT NOT NULL,
    net_sent FLOAT NOT NULL,
    net_recv FLOAT NOT NULL,
    last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_server (server_name)
);

CREATE TABLE IF NOT EXISTS timezones (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS twitch_bot_access (
    twitch_user_id VARCHAR(255) NOT NULL,
    twitch_access_token VARCHAR(255) NOT NULL,
    PRIMARY KEY (twitch_user_id)
);

CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    twitch_display_name VARCHAR(50) DEFAULT NULL,
    twitch_user_id VARCHAR(255) NOT NULL,
    access_token VARCHAR(255) DEFAULT NULL,
    refresh_token VARCHAR(255) DEFAULT NULL,
    api_key VARCHAR(32) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    beta_access TINYINT(1) NOT NULL DEFAULT 0,
    is_technical TINYINT(1) NOT NULL DEFAULT 0,
    signup_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    profile_image VARCHAR(255) NOT NULL DEFAULT 'https://cdn.botofthespecter.com/noimage.png',
    email VARCHAR(255) DEFAULT NULL,
    app_password VARCHAR(50) DEFAULT NULL,
    language VARCHAR(5) NOT NULL DEFAULT 'EN',
    PRIMARY KEY (id),
    UNIQUE KEY username (username),
    UNIQUE KEY api_key (api_key),
    KEY idx_twitch_user_id (twitch_user_id)
);
</code></pre>

        <p class="mt-3">Then create your database user with access to all databases:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">CREATE USER 'your_username'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON *.* TO 'your_username'@'%';
FLUSH PRIVILEGES;</code></pre>

        <p class="mt-3">Configure MySQL to accept connections from other servers by editing <code>/etc/mysql/mysql.conf.d/mysqld.cnf</code>:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">bind-address = 0.0.0.0</code></pre>
    </div>
    <h3 class="title is-4 has-text-light">Step 3: Set Up Python Environment (Servers 2, 3, & 5)</h3>
    <div class="box has-background-dark has-text-light">
    <p>All application servers share the same repository path: <code>/home/botofthespecter</code>. Create the virtual environment in that directory and use the venv's pip/python directly so commands are deterministic and work the same on every server.</p>
    <p>Recommended venv location: <code>/home/botofthespecter/botofthespecter</code></p>
    <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code"># create the venv (run as the botofthespecter user)
python3 -m venv botofthespecter
# install all required packages from the single shared requirements file
/home/botofthespecter/botofthespecter/bin/pip install -r /home/botofthespecter/requirements.txt</code></pre>
    <p class="mt-3">Production notes:</p>
        <ul>
            <li>Reference the virtualenv executables directly in systemd unit files. Example: <code>Environment="PATH=/home/botofthespecter/botofthespecter/bin"</code> and <code>ExecStart=/home/botofthespecter/botofthespecter/bin/python /home/botofthespecter/api/api.py</code>.</li>
            <li>Always run the venv creation and package installs as the <code>botofthespecter</code> user to ensure correct ownership of files and installed packages.</li>
        </ul>
            <li>Always run the venv creation and package installs as the <code>botofthespecter</code> user to ensure correct ownership of files and installed packages.</li>
        </ul>
    </div>
    <h3 class="title is-4 has-text-light">Step 4: Configure Environment Variables (All Servers)</h3>
    <div class="box has-background-dark has-text-light">
        <p>Create a <code>.env</code> file in <code>/home/botofthespecter</code> with your configuration. Replace the placeholders with your actual values:</p>
    <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code># SQL Data
SQL_HOST=
SQL_USER=
SQL_PASSWORD=
SQL_PORT=
# API STUFF
SHAZAM_API=
WEATHER_API=
STEAM_API=
OPENAI_KEY=
OPENAI_VECTOR_ID=
STREAMELEMENTS_CLIENT_ID=
STREAMELEMENTS_SECRET_KEY=
HYPERATE_API_KEY=
# Twitch Bot
OAUTH_TOKEN=oauth:
TWITCH_OAUTH_API_TOKEN=
TWITCH_OAUTH_API_CLIENT_ID=
CLIENT_ID=
CLIENT_SECRET=
TWITCH_GQL=
TIMEZONE_API=
EXCHANGE_RATE_API=
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
BOT_ID=
# Discord Bot
DISCORD_TOKEN=
DISCORD_PUBLIC_KEY=
API_KEY=
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
# Guided Bot
GUIDED_BOT_USER_ID=
GUIDED_BOT_TOKEN=
# ADMINS
ADMIN_KEY=
# BACKUP SYSTEM
USE_BACKUP_SYSTEM=False
BACKUP_CLIENT_ID=
BACKUP_SECRET_KEY=
# SSH Settings
SSH_USERNAME=
SSH_PASSWORD=
API-HOST=
WEBSOCKET-HOST=
BOT-SRV-HOST=
SQL-HOST=
WEB-HOST=
BILLING-HOST=
STREAM-AU-EAST-1-HOST=
STREAM-US-EAST-1-HOST=
STREAM-US-WEST-1-HOST=
# STMP Email Settings
SMTP_HOST=
SMTP_PORT=465
SMTP_FROM_NAME=
SMTP_USERNAME=
SMTP_PASSWORD=
# S3 Bucket Settings for Exports Only
S3_ENDPOINT_HOSTNAME=
S3_CUSTOM_DOMAIN=
S3_BUCKET_NAME=
S3_ACCESS_KEY=
S3_SECRET_KEY=
S3_ALWAYS_UPLOAD=True</code></pre>
        <p class="mt-3"><strong>Required Variables:</strong></p>
        <ul>
            <li><strong>SQL_*:</strong> Database connection details (must match Server 4 configuration)</li>
            <li><strong>CLIENT_ID & CLIENT_SECRET:</strong> Your Twitch application credentials</li>
            <li><strong>OAUTH_TOKEN:</strong> Bot account OAuth token (get from <a href="https://twitchapps.com/tmi/" target="_blank" class="has-text-link">https://twitchapps.com/tmi/</a>)</li>
            <li><strong>API_KEY:</strong> Generate a secure random API key for internal service authentication</li>
        </ul>
        <p class="mt-3"><strong>Optional Variables:</strong></p>
        <ul>
            <li><strong>WEATHER_API:</strong> For weather commands (get from <a href="https://openweathermap.org/api" target="_blank" class="has-text-link">OpenWeatherMap</a>)</li>
            <li><strong>SPOTIFY_*:</strong> For Spotify integration (get from <a href="https://developer.spotify.com/" target="_blank" class="has-text-link">Spotify Developer Dashboard</a>)</li>
            <li><strong>DISCORD_*:</strong> For Discord bot functionality (get from <a href="https://discord.com/developers/applications" target="_blank" class="has-text-link">Discord Developer Portal</a>)</li>
            <li><strong>OPENAI_KEY:</strong> For AI features (get from <a href="https://platform.openai.com/" target="_blank" class="has-text-link">OpenAI</a>)</li>
            <li><strong>S3_*:</strong> For user data exports to object storage (optional, exports can be local)</li>
            <li><strong>SMTP_*:</strong> For email notifications (optional)</li>
        </ul>
        <p class="mt-3"><strong>Server Host Variables:</strong></p>
        <ul>
            <li><strong>API-HOST, WEBSOCKET-HOST, etc:</strong> Set these to the IP addresses or hostnames of your respective servers for inter-server communication</li>
        </ul>
    </div>
    <h3 class="title is-4 has-text-light">Step 5: Set Up Python Environment (Server 3 - WebSocket)</h3>
    <div class="box has-background-dark has-text-light">
        <p>Install Python dependencies for the WebSocket server:</p>
    <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">cd /home/botofthespecter
source /home/botofthespecter/botofthespecter/bin/activate
/home/botofthespecter/botofthespecter/bin/pip install -r /home/botofthespecter/requirements.txt</code></pre>
    </div>
    <h3 class="title is-4 has-text-light">Step 6: Set Up Web Server (Server 1)</h3>
    <div class="box has-background-dark has-text-light">
        <p>Configure Apache2 to serve the PHP dashboard:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo apt install -y apache2 libapache2-mod-php
# Enable Apache2 modules
sudo a2enmod rewrite
sudo a2enmod php8.1
# Create Apache2 configuration
sudo nano /etc/apache2/sites-available/botofthespecter.conf</code></pre>
        <p class="mt-3">Configure Apache (or your web server) however you prefer. You must serve the dashboard and related assets under your domain and the following example subdomains. We don't enforce a specific VirtualHost layout — pick the configuration that matches your infrastructure and SSL setup.</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">Example DNS / subdomain names you should configure for your deployment:
example.com
dashboard.example.com
overlay.example.com
videoalert.example.com
soundalert.example.com
tts.example.com

Tip: Use separate subdomains for static assets or features you want to scale independently.
Ensure each subdomain points to the correct server (Server 1 for the dashboard/static sites) and that TLS is enabled (Let's Encrypt is recommended).</code></pre>
    </div>
    <h2 class="title is-3 has-text-light" id="running">
        <span class="icon">
            <i class="fas fa-play"></i>
        </span>
        Running the Services
    </h2>
    <h3 class="title is-4 has-text-light">Server 1: Start the Web/Dashboard Server</h3>
    <div class="box has-background-dark has-text-light">
        <p>Ensure Apache2 and PHP are running:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo systemctl enable apache2
sudo systemctl start apache2
sudo systemctl status apache2</code></pre>
    </div>
    <h3 class="title is-4 has-text-light">Server 2: Start the API Server</h3>
    <div class="box has-background-dark has-text-light">
        <p>From the API Server:</p>
    <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">cd /home/botofthespecter
source /home/botofthespecter/botofthespecter/bin/activate
# Run the API with TLS (replace the paths with your domain's Let's Encrypt certs)
python -m uvicorn api.api:app --host 0.0.0.0 --port 443 \
  --ssl-keyfile=/etc/letsencrypt/live/api.example.com/privkey.pem \
  --ssl-certfile=/etc/letsencrypt/live/api.example.com/fullchain.pem</code></pre>
        <p class="mt-2">Note: TLS is required for the API server. Update the cert paths above to match your domain (for example: <code>/etc/letsencrypt/live/api.botofthespecter.com/privkey.pem</code>).</p>
        <p class="mt-3">For production, create a systemd service similar to the bot service below.</p>
    </div>
    <h3 class="title is-4 has-text-light">Server 3: Start the WebSocket Server</h3>
    <div class="box has-background-dark has-text-light">
        <p>From the WebSocket Server:</p>
    <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">cd /home/botofthespecter
source /home/botofthespecter/botofthespecter/bin/activate
python /home/botofthespecter/server.py</code></pre>
        <p class="mt-3">For production, create a systemd service similar to the bot service below.</p>
    </div>

    <h3 class="title is-4 has-text-light">Server 4: MySQL/MariaDB Database</h3>
    <div class="box has-background-dark has-text-light">
        <p>Ensure the database service is running:</p>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code">sudo systemctl enable mysql
sudo systemctl start mysql
sudo systemctl status mysql</code></pre>
    </div>

    <h3 class="title is-4 has-text-light">Server 5: Bot Service</h3>
    <div class="box has-background-dark has-text-light">
        <p>The bot is controlled and started from the dashboard (Server 1). No manual setup or startup is required on Server 5; it is ready once the Python environment and `.env` configuration from the earlier steps are complete.</p>
    </div>
    <h2 class="title is-3 has-text-light" id="networking">
        <span class="icon">
            <i class="fas fa-network-wired"></i>
        </span>
        Inter-Server Networking
    </h2>
    <div class="box has-background-dark has-text-light">
        <p>Configure your servers to communicate securely with each other:</p>
        <ul>
            <li><strong>Internal Network:</strong> Use private IP addresses for inter-server communication</li>
            <li><strong>DNS/Hostnames:</strong> Set up DNS or <code>/etc/hosts</code> entries for server-to-server connections</li>
            <li><strong>Firewall Rules:</strong> Only allow necessary ports between servers</li>
            <li><strong>SSL/TLS:</strong> Encrypt communication between services</li>
        </ul>
        <h4 class="title is-5 mt-4">Firewall Configuration Example</h4>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code"># Server 1 (Web) - Allow HTTP/HTTPS and communication with other services
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow from 10.10.10.2:8001  # API Server
sudo ufw allow from 10.10.10.3:8000  # WebSocket Server
sudo ufw allow from 10.10.10.4:3306  # Database Server

# Server 2 (API) - Allow inbound from Web and Bot servers
sudo ufw allow from 10.10.10.1:any   # Web Server
sudo ufw allow from 10.10.10.5:any   # Bot Server

# Server 3 (WebSocket) - Allow inbound from Web and Bot servers
sudo ufw allow from 10.10.10.1:any   # Web Server
sudo ufw allow from 10.10.10.5:any   # Bot Server

# Server 4 (Database) - Allow inbound from all services
sudo ufw allow from 10.10.10.1:any   # Web Server
sudo ufw allow from 10.10.10.2:any   # API Server
sudo ufw allow from 10.10.10.5:any   # Bot Server

# Server 5 (Bot) - Allow outbound to API, WebSocket, and Database
sudo ufw allow to 10.10.10.2:8001    # API Server
sudo ufw allow to 10.10.10.3:8000    # WebSocket Server
sudo ufw allow to 10.10.10.4:3306    # Database Server</code></pre>
    </div>
    <h2 class="title is-3 has-text-light" id="security">
        <span class="icon">
            <i class="fas fa-shield-alt"></i>
        </span>
        Security Considerations
    </h2>
    <div class="box has-background-dark has-text-light">
        <ul>
            <li><strong>HTTPS/SSL:</strong> Always use SSL certificates for secure communication (Let's Encrypt is free)</li>
            <li><strong>Firewall:</strong> Configure firewalls to restrict database access to localhost only</li>
            <li><strong>Environment Variables:</strong> Never commit <code>.env</code> files to version control</li>
            <li><strong>Database Backups:</strong> Set up automated daily backups of your database</li>
            <li><strong>Updates:</strong> Keep dependencies updated to patch security vulnerabilities</li>
            <li><strong>Monitoring:</strong> Monitor system resources and bot logs for issues</li>
        </ul>
    </div>
    <h2 class="title is-3 has-text-light" id="troubleshooting">
        <span class="icon">
            <i class="fas fa-wrench"></i>
        </span>
        Troubleshooting
    </h2>
    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5">Bot Not Connecting to Twitch</h4>
        <ul>
            <li>Verify your OAuth token is valid and not expired</li>
            <li>Check that your Twitch Client ID and Secret are correct</li>
            <li>Ensure the bot account has the proper permissions in your channel</li>
            <li>Review logs in <code>bot/logs/</code> for error messages</li>
        </ul>
        <h4 class="title is-5 mt-4">Database Connection Errors</h4>
        <ul>
            <li>Verify MySQL/MariaDB is running</li>
            <li>Check database credentials in <code>.env</code> and configuration files</li>
            <li>Ensure the user has proper database permissions</li>
            <li>Test connection: <code>mysql -u botuser -p -h localhost botofthespecter</code></li>
        </ul>
        <h4 class="title is-5 mt-4">API Server Not Responding</h4>
        <ul>
            <li>Verify FastAPI/Uvicorn is running correctly</li>
            <li>Check that port 443 is not in use by another service</li>
            <li>Review API logs for startup errors</li>
            <li>Ensure all Python dependencies are installed</li>
        </ul>
        <h4 class="title is-5 mt-4">WebSocket Connection Issues</h4>
        <ul>
            <li>Verify WebSocket server is running on port 8000</li>
            <li>Check firewall rules allow WebSocket connections</li>
            <li>Ensure the WebSocket URL is correctly configured in clients</li>
            <li>Review WebSocket server logs for errors</li>
        </ul>
    </div>
    <h2 class="title is-3 has-text-light" id="maintenance">
        <span class="icon">
            <i class="fas fa-tools"></i>
        </span>
        Maintenance
    </h2>
    <div class="box has-background-dark has-text-light">
        <h4 class="title is-5">Regular Tasks</h4>
        <ul>
            <li><strong>Daily:</strong> Check logs for errors and unusual activity</li>
            <li><strong>Weekly:</strong> Verify all services are running and responsive</li>
            <li><strong>Monthly:</strong> Update dependencies and apply security patches</li>
            <li><strong>Quarterly:</strong> Review and optimize database performance</li>
        </ul>
        <h4 class="title is-5 mt-4">Updating BotOfTheSpecter</h4>
        <pre style="background-color: #1a1a1a; border: 1px solid #444444; border-radius: 4px; padding: 1rem;"><code>git pull origin main
pip install -r bot/requirements.txt --upgrade
pip install -r api/requirements.txt --upgrade</code></pre>
    </div>
    <h2 class="title is-3 has-text-light" id="support">
        <span class="icon">
            <i class="fas fa-question-circle"></i>
        </span>
        Need Help?
    </h2>
    <div class="box has-background-dark has-text-light">
        <p>If you encounter issues while self-hosting BotOfTheSpecter:</p>
        <ul>
            <li><a href="https://github.com/YourStreamingTools/BotOfTheSpecter/issues" target="_blank" class="has-text-link">Report an issue on GitHub</a></li>
            <li><a href="https://discord.com/invite/ANwEkpauHJ" target="_blank" class="has-text-link">Join our Discord community</a></li>
            <li>Check existing documentation and issues for solutions</li>
            <li>Review bot logs for detailed error information</li>
        </ul>
    </div>
</div>

<?php
$content = ob_get_clean();
$pageTitle = 'Run Yourself';
include 'layout.php';
?>
