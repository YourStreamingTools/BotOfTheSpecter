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
// Temporarily allow all logged-in users for debugging
$isTechnical = isset($_SESSION['access_token']) && isset($_SESSION['username']);

// For debugging, let's not block non-technical users and see what happens
if (!$isTechnical) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Technical access required', 'debug' => [
        'has_access_token' => isset($_SESSION['access_token']),
        'has_username' => isset($_SESSION['username']),
        'session_keys' => array_keys($_SESSION)
    ]]);
    exit();
}

// Function to get server metrics
function getServerMetrics() {
    $metrics = [];
    // CPU Load (if available)
    $cpuLoad = null;
    try {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpuCores = (int)shell_exec('nproc 2>/dev/null');
            if ($cpuCores <= 0) $cpuCores = 1; // fallback
            $cpuLoad = round($load[0] * 100 / $cpuCores, 1);
        } else if (file_exists('/proc/loadavg')) {
            $loadavg = file_get_contents('/proc/loadavg');
            if ($loadavg) {
                $load = explode(' ', $loadavg);
                $cpuCores = (int)shell_exec('nproc 2>/dev/null');
                if ($cpuCores <= 0) $cpuCores = 1; // fallback
                $cpuLoad = round($load[0] * 100 / $cpuCores, 1);
            }
        }
    } catch (Exception $e) { $cpuLoad = null;}
    // Memory Usage
    $memoryUsage = null;
    try {
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $memTotal);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $memAvailable);
                
                if (isset($memTotal[1]) && isset($memAvailable[1])) {
                    $totalMem = $memTotal[1];
                    $availableMem = $memAvailable[1];
                    $usedMem = $totalMem - $availableMem;
                    $memoryUsage = round(($usedMem / $totalMem) * 100, 1);
                }
            }
        }
    } catch (Exception $e) { $memoryUsage = null; }
    // Disk Usage
    $diskUsage = null;
    try {
        if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
            $totalSpace = disk_total_space('/');
            $freeSpace = disk_free_space('/');
            if ($totalSpace && $freeSpace) {
                $usedSpace = $totalSpace - $freeSpace;
                $diskUsage = round(($usedSpace / $totalSpace) * 100, 1);
            }
        }
    } catch (Exception $e) { $diskUsage = null; }
    // Server uptime
    $uptime = null;
    try {
        if (file_exists('/proc/uptime')) {
            $uptimeData = file_get_contents('/proc/uptime');
            if ($uptimeData) {
                $uptimeSeconds = floatval(explode(' ', $uptimeData)[0]);
                $uptime = $uptimeSeconds;
            }
        }
    } catch (Exception $e) { $uptime = null; }
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
try {
    $metrics = getServerMetrics();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error getting server metrics', 'message' => $e->getMessage()]);
    exit();
}

// Return data as JSON
ob_clean(); // Clear any accidental output
header('Content-Type: application/json');
echo json_encode($metrics);
exit();
?>
