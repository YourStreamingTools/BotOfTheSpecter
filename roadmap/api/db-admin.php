<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    // Check admin access first
    session_start();
    if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
        http_response_code(403);
        throw new Exception('Admin access required');
    }
    // Load database config - try multiple paths
    $config_path = $_SERVER['DOCUMENT_ROOT'] . '/../config/database.php';
    if (!file_exists($config_path)) {
        $config_path = __DIR__ . '/../../config/database.php';
    }
    if (!file_exists($config_path)) {
        $config_path = '/var/www/config/database.php';
    }
    if (!file_exists($config_path)) {
        throw new Exception('Database configuration file not found');
    }
    require_once $config_path;
    if (empty($db_servername) || empty($db_username)) {
        throw new Exception('Database configuration not properly set');
    }
    $dbname = "roadmap";
    // Create connection
    $conn = new mysqli($db_servername, $db_username, $db_password);
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }
    // Create database if it doesn't exist
    if (!$conn->select_db($dbname)) {
        $create_sql = "CREATE DATABASE IF NOT EXISTS $dbname";
        if (!$conn->query($create_sql)) {
            throw new Exception('Failed to create database: ' . $conn->error);
        }
        $conn->select_db($dbname);
    }
    // Load schema definition
    require_once "db-schema.php";
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Check status of all tables
        $status = [
            'database' => $dbname,
            'tables' => [],
            'issues' => [],
            'all_ok' => true
        ];
        foreach ($DATABASE_SCHEMA as $table_name => $table_def) {
            $table_status = [
                'name' => $table_name,
                'exists' => false,
                'columns' => [],
                'missing_columns' => [],
                'extra_columns' => []
            ];
            // Check if table exists
            $check_sql = "SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'";
            $result = $conn->query($check_sql);
            if ($result && $result->num_rows > 0) {
                $table_status['exists'] = true;
                // Get existing columns
                $cols_sql = "SHOW COLUMNS FROM `$table_name`";
                $cols_result = $conn->query($cols_sql);
                $existing_cols = [];
                while ($col = $cols_result->fetch_assoc()) {
                    $existing_cols[$col['Field']] = $col['Type'];
                    $table_status['columns'][] = [
                        'name' => $col['Field'],
                        'type' => $col['Type'],
                        'null' => $col['Null'],
                        'key' => $col['Key'],
                        'default' => $col['Default']
                    ];
                }
                // Check for missing columns
                foreach ($table_def['columns'] as $col_name => $col_type) {
                    if (strpos($col_name, 'FOREIGN KEY') === false && !isset($existing_cols[$col_name])) {
                        $table_status['missing_columns'][] = $col_name;
                        $status['all_ok'] = false;
                        $status['issues'][] = "Table '$table_name' missing column '$col_name'";
                    }
                }
                // Check for extra columns (not defined in schema)
                foreach ($existing_cols as $col_name => $col_type) {
                    $found = false;
                    foreach ($table_def['columns'] as $schema_col => $schema_type) {
                        if ($schema_col === $col_name || strpos($schema_col, 'FOREIGN KEY') === 0) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $table_status['extra_columns'][] = $col_name;
                    }
                }
            } else {
                $status['all_ok'] = false;
                $status['issues'][] = "Table '$table_name' does not exist";
            }
            $status['tables'][] = $table_status;
        }
        echo json_encode($status);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Fix/create tables
        $response = [
            'success' => true,
            'created' => [],
            'altered' => [],
            'errors' => []
        ];
        foreach ($DATABASE_SCHEMA as $table_name => $table_def) {
            // Check if table exists
            $check_sql = "SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'";
            $result = $conn->query($check_sql);
            if ($result && $result->num_rows === 0) {
                // Create table
                $create_sql = "CREATE TABLE IF NOT EXISTS `$table_name` (\n";
                $all_parts = [];
                // Add columns
                foreach ($table_def['columns'] as $col_name => $col_type) {
                    if (strpos($col_name, 'FOREIGN KEY') === 0) {
                        $all_parts[] = "  $col_name $col_type";
                    } else {
                        $all_parts[] = "  `$col_name` $col_type";
                    }
                }
                // Add indexes
                if (isset($table_def['indexes'])) {
                    foreach ($table_def['indexes'] as $idx_name => $idx_def) {
                        $all_parts[] = "  $idx_def";
                    }
                }
                $create_sql .= implode(",\n", $all_parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                if ($conn->query($create_sql)) {
                    $response['created'][] = $table_name;
                } else {
                    $response['success'] = false;
                    $response['errors'][] = "Failed to create table '$table_name': " . $conn->error;
                }
            } else {
                // Check and add missing columns
                $cols_sql = "SHOW COLUMNS FROM `$table_name`";
                $cols_result = $conn->query($cols_sql);
                $existing_cols = [];
                while ($col = $cols_result->fetch_assoc()) {
                    $existing_cols[$col['Field']] = true;
                }
                foreach ($table_def['columns'] as $col_name => $col_type) {
                    if (strpos($col_name, 'FOREIGN KEY') === false && !isset($existing_cols[$col_name])) {
                        $alter_sql = "ALTER TABLE `$table_name` ADD COLUMN `$col_name` $col_type";
                        if ($conn->query($alter_sql)) {
                            $response['altered'][] = "Added column '$col_name' to '$table_name'";
                        } else {
                            $response['success'] = false;
                            $response['errors'][] = "Failed to add column '$col_name' to '$table_name': " . $conn->error;
                        }
                    }
                }
            }
        }
        echo json_encode($response);
    } else {
        throw new Exception('Invalid request method');
    }
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'debug' => [
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET',
            'script_dir' => __DIR__,
            'tested_paths' => [
                $_SERVER['DOCUMENT_ROOT'] . '/../config/database.php',
                __DIR__ . '/../../config/database.php',
                '/var/www/config/database.php'
            ]
        ]
    ]);
}
?>
