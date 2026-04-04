<?php
/**
    * Roadmap Database Setup
    * Creates the necessary tables for the roadmap system
*/

require_once "/var/www/config/database.php";

function initializeRoadmapDatabase() {
    global $db_servername, $db_username, $db_password;
    // Create connection
    $conn = new mysqli($db_servername, $db_username, $db_password);
    if ($conn->connect_error) {
        return array('success' => false, 'message' => 'Connection failed: ' . $conn->connect_error);
    }
    // Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS roadmap";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating database: ' . $conn->error);
    }
    // Select the database
    $conn->select_db('roadmap');
    // Create roadmap_items table
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description LONGTEXT,
        category ENUM('REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED') NOT NULL DEFAULT 'REQUESTS',
        subcategory ENUM('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER') NOT NULL,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_items table: ' . $conn->error);
    }
    // Create roadmap_comments table
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_comments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        username VARCHAR(255) NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
        INDEX idx_item_id (item_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_comments table: ' . $conn->error);
    }
    // Create roadmap_attachments table
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_attachments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_type VARCHAR(100),
        file_size INT,
        is_image BOOLEAN DEFAULT FALSE,
        uploaded_by VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
        INDEX idx_item_id (item_id),
        INDEX idx_is_image (is_image),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_attachments table: ' . $conn->error);
    }

    // Create roadmap_item_subcategories table (supports multiple subcategories per item)
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_item_subcategories (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        subcategory VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
        UNIQUE KEY uq_item_subcat (item_id, subcategory),
        INDEX idx_subcategory (subcategory),
        INDEX idx_item_id_subcat (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_item_subcategories table: ' . $conn->error);
    }

    // Backfill existing subcategory values into roadmap_item_subcategories (if missing)
    $sql = "INSERT IGNORE INTO roadmap_item_subcategories (item_id, subcategory)
            SELECT id, subcategory FROM roadmap_items WHERE subcategory IS NOT NULL";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error backfilling roadmap_item_subcategories: ' . $conn->error);
    }

    // Create roadmap_item_website_types table (supports multiple website types per item)
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_item_website_types (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        website_type VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
        UNIQUE KEY uq_item_webtype (item_id, website_type),
        INDEX idx_website_type (website_type),
        INDEX idx_item_id_webtype (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_item_website_types table: ' . $conn->error);
    }

    // Backfill existing website_type values into roadmap_item_website_types (if missing)
    $sql = "INSERT IGNORE INTO roadmap_item_website_types (item_id, website_type)
            SELECT id, website_type FROM roadmap_items WHERE website_type IS NOT NULL";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error backfilling roadmap_item_website_types: ' . $conn->error);
    }

    // Create roadmap_subcategories table (manages available subcategories)
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_subcategories (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL UNIQUE,
        color VARCHAR(50) NOT NULL DEFAULT 'light',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_subcategories table: ' . $conn->error);
    }

    // Seed default subcategories if the table is empty
    $countRes = $conn->query("SELECT COUNT(*) AS cnt FROM roadmap_subcategories");
    $countRow = $countRes ? $countRes->fetch_assoc() : null;
    if ($countRow && (int)$countRow['cnt'] === 0) {
        $defaults = [
            ['TWITCH BOT', 'primary', 1],
            ['DISCORD BOT', 'info', 2],
            ['WEBSOCKET SERVER', 'success', 3],
            ['API SERVER', 'warning', 4],
            ['WEBSITE', 'danger', 5],
            ['OTHER', 'light', 6],
        ];
        $ins = $conn->prepare("INSERT IGNORE INTO roadmap_subcategories (name, color, sort_order) VALUES (?, ?, ?)");
        foreach ($defaults as $d) {
            $ins->bind_param("ssi", $d[0], $d[1], $d[2]);
            $ins->execute();
        }
        $ins->close();
    }

    $conn->close();
    return array('success' => true, 'message' => 'Database initialized successfully');
}

// Get available subcategories from the database
function getAvailableSubcategories($conn = null) {
    $ownConn = false;
    if (!$conn) { $conn = getRoadmapConnection(); $ownConn = true; }
    $rows = [];
    $res = $conn->query("SELECT name, color FROM roadmap_subcategories ORDER BY sort_order, name");
    if ($res) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();
    }
    if ($ownConn) $conn->close();
    return $rows;
}

// Helper function to get database connection
function getRoadmapConnection() {
    global $db_servername, $db_username, $db_password;
    $conn = new mysqli($db_servername, $db_username, $db_password, 'roadmap');
    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}
?>