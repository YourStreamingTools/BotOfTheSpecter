<?php
// Main Infomation
$server_username = '';
$server_password = '';

// BOTS Server
$bots_ssh_host = '';
$bots_ssh_username = $server_username;
$bots_ssh_password = $server_password;

// API Server Information
$api_server_host = '';
$api_server_username = $server_username;
$api_server_password = $server_password;

// WebSocket Server Information
$websocket_server_host = '';
$websocket_server_username = $server_username;
$websocket_server_password = $server_password;

// Database Server Information
$sql_server_host = '';
$sql_server_username = $server_username;
$sql_server_password = $server_password;

// Stream Server Information
// AU East 1
$stream_au_east_1_host = '';
$stream_au_east_1_username = $server_username;
$stream_au_east_1_password = $server_password;
// US East 1
$stream_us_east_1_host = '';
$stream_us_east_1_username = $server_username;
$stream_us_east_1_password = $server_password;
// US West 1
$stream_us_west_1_host = '';
$stream_us_west_1_username = $server_username;
$stream_us_west_1_password = $server_password;

// SSH Connection Manager - Maintains persistent connections
if (!class_exists('SSHConnectionManager')) {
    class SSHConnectionManager {
        private static $connections = [];
        private static $last_activity = [];
        private static $connection_timeout = 60; // 1 minute (reduced from 2 minutes)
        public static function getConnection($host, $username, $password) {
            $key = md5($host . $username);
            // Check if we have a valid connection
            if (isset(self::$connections[$key]) && isset(self::$last_activity[$key])) {
                // Check if connection is still alive and not timed out
                if ((time() - self::$last_activity[$key]) < self::$connection_timeout) {
                    // Test connection with a simple command
                    $test_stream = @ssh2_exec(self::$connections[$key], 'echo "test"');
                    if ($test_stream) {
                        stream_set_timeout($test_stream, 2); // Quick test
                        fclose($test_stream);
                        self::$last_activity[$key] = time();
                        return self::$connections[$key];
                    }
                }
                // Connection is dead or timed out, clean it up
                self::closeConnection($key);
            }
            // Create new connection
            $connection = self::createNewConnection($host, $username, $password);
            if ($connection) {
                self::$connections[$key] = $connection;
                self::$last_activity[$key] = time();
            }
            return $connection;
        }
        private static function createNewConnection($host, $username, $password) {
            // Check if SSH2 extension is loaded
            if (!extension_loaded('ssh2')) {
                error_log('SSH2 PHP extension is not loaded');
                throw new Exception('Bot service is temporarily unavailable. Please contact support if this issue persists.');
            }
            // Test basic network connectivity first with shorter timeout
            $fp = @fsockopen($host, 22, $errno, $errstr, 2); // Reduced from 3 to 2 seconds
            if (!$fp) {
                error_log("Network connectivity test failed to {$host}:22 - Error: {$errstr} (Code: {$errno})");
                throw new Exception("Bot service is temporarily unavailable. Please try again in a few minutes or contact support if this issue persists.");
            }
            fclose($fp);
            // Set a maximum time limit for the entire connection process
            $start_time = time();
            $max_connection_time = 3; // Reduced from 5 to 3 seconds for entire connection process
            // Establish SSH connection with minimal retry
            $max_retries = 1; // Only 1 retry to avoid hanging
            $base_delay = 0.1; // Very short delay
            for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
                // Check if we're taking too long
                if ((time() - $start_time) >= $max_connection_time) {
                    error_log("SSH connection attempt to {$host} exceeded maximum time limit");
                    throw new Exception("Connection timeout. Please try again later.");
                }
                if ($attempt > 1) {
                    usleep(100000); // 0.1 second delay
                }
                $connection = ssh2_connect($host, 22);
                if ($connection) {
                    // Authenticate
                    if (ssh2_auth_password($connection, $username, $password)) {
                        return $connection;
                    } else {
                        if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
                        error_log("SSH authentication failed for user {$username} to {$host}");
                        throw new Exception("Authentication failed. Please contact support if this issue persists.");
                    }
                }
            }
            error_log("SSH connection failed to {$host}:22 after {$max_retries} attempts");
            throw new Exception("Bot service is temporarily unavailable. Please try again later or contact support if this issue persists.");
        }
        public static function closeConnection($key) {
            if (isset(self::$connections[$key])) {
                if (function_exists('ssh2_disconnect')) {
                    @ssh2_disconnect(self::$connections[$key]);
                }
                unset(self::$connections[$key]);
                unset(self::$last_activity[$key]);
            }
        }
        public static function closeAllConnections() {
            foreach (array_keys(self::$connections) as $key) {
                self::closeConnection($key);
            }
        }
        public static function executeCommand($connection, $command, $isBackground = false) {
            $stream = ssh2_exec($connection, $command);
            if (!$stream) {
                return false;
            }
            if ($isBackground) {
                // For background processes, don't block - just return immediately
                stream_set_blocking($stream, false);
                // Give it a brief moment to start
                usleep(100000); // 0.1 seconds
                fclose($stream);
                return "Background process started";
            } else {
                // For regular commands, block and wait for output
                stream_set_blocking($stream, true);
                // Set a stream timeout to prevent hanging
                $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                stream_set_timeout($stream, 5); // Reduced from 8 to 5 seconds timeout
                stream_set_timeout($errorStream, 5);
                $output = stream_get_contents($stream);
                $info = stream_get_meta_data($stream);
                fclose($stream);
                fclose($errorStream);
                // Check if stream timed out
                if ($info['timed_out']) {
                    error_log("SSH command timed out: $command");
                    return false;
                }
                return $output;
            }
        }
    }
}

/**
    * Clean up old SSH connections periodically
    * Call this function periodically (e.g., via cron or on shutdown)
*/
if (!function_exists('cleanupSSHConnections')) {
    function cleanupSSHConnections() {
        SSHConnectionManager::closeAllConnections();
    }
}

// Register shutdown function to cleanup connections
register_shutdown_function('cleanupSSHConnections');
?>