<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ob_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Check if user has technical access (only show real metrics to technical users)
include_once 'usr_database.php';
include 'user_db.php';

$isTechnical = isset($user['is_technical']) ? (bool)$user['is_technical'] : false;

if (!$isTechnical) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Technical access required']);
    exit();
}

// Function to get server metrics
function getServerMetrics() {
    $metrics = [];
    // CPU Load (if available)
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $cpuLoad = round($load[0] * 100 / (shell_exec('nproc') ?: 1), 1);
    } else {
        // Fallback: try to parse /proc/loadavg on Linux
        if (file_exists('/proc/loadavg')) {
            $loadavg = file_get_contents('/proc/loadavg');
            $load = explode(' ', $loadavg);
            $cpuCores = (int)(shell_exec('nproc') ?: 1);
            $cpuLoad = round($load[0] * 100 / $cpuCores, 1);
        } else {
            $cpuLoad = null;
        }
    }

    // Memory Usage
    $memoryUsage = null;
    if (file_exists('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $memTotal);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $memAvailable);
        
        if (isset($memTotal[1]) && isset($memAvailable[1])) {
            $totalMem = $memTotal[1];
            $availableMem = $memAvailable[1];
            $usedMem = $totalMem - $availableMem;
            $memoryUsage = round(($usedMem / $totalMem) * 100, 1);
        }
    }

    // Disk Usage
    $diskUsage = null;
    if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
        $totalSpace = disk_total_space('/');
        $freeSpace = disk_free_space('/');
        if ($totalSpace && $freeSpace) {
            $usedSpace = $totalSpace - $freeSpace;
            $diskUsage = round(($usedSpace / $totalSpace) * 100, 1);
        }
    }

    // Server uptime
    $uptime = null;
    if (file_exists('/proc/uptime')) {
        $uptimeData = file_get_contents('/proc/uptime');
        $uptimeSeconds = floatval(explode(' ', $uptimeData)[0]);
        $uptime = $uptimeSeconds;
    }

    return [
        'cpu_load' => $cpuLoad,
        'memory_usage' => $memoryUsage,
        'disk_usage' => $diskUsage,
        'uptime_seconds' => $uptime,
        'timestamp' => date('c'),
        'checked_at' => time()
    ];
}

// Get metrics
$metrics = getServerMetrics();

// Return data as JSON
ob_clean(); // Clear any accidental output
header('Content-Type: application/json');
echo json_encode($metrics);
exit();
?>
