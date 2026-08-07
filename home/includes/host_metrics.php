<?php
/**
 * Linux host metrics for web servers (same JSON shape as Python /health/metrics).
 * Swap is folded into RAM totals — no separate swap fields.
 *
 * @return array<string, mixed>|null
 */
function specter_collect_host_metrics(string $serverName, string $service = 'web'): ?array {
    if (!is_readable('/proc/meminfo') || !is_readable('/proc/stat')) {
        return null;
    }

    $memKb = [];
    $memRaw = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($memRaw)) {
        return null;
    }
    foreach ($memRaw as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $memKb[$m[1]] = (int) $m[2];
        }
    }
    $memTotalB = (int) (($memKb['MemTotal'] ?? 0) * 1024);
    $memAvailB = (int) (($memKb['MemAvailable'] ?? ($memKb['MemFree'] ?? 0)) * 1024);
    $memUsedB = max(0, $memTotalB - $memAvailB);
    $swapTotalB = (int) (($memKb['SwapTotal'] ?? 0) * 1024);
    $swapFreeB = (int) (($memKb['SwapFree'] ?? 0) * 1024);
    $swapUsedB = max(0, $swapTotalB - $swapFreeB);
    $ramTotalB = $memTotalB + $swapTotalB;
    $ramUsedB = $memUsedB + $swapUsedB;
    $ramPercent = $ramTotalB > 0 ? ($ramUsedB / $ramTotalB) * 100.0 : 0.0;

    $readCpu = static function (): array {
        $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
        if (!is_array($line) || empty($line[0]) || strpos($line[0], 'cpu ') !== 0) {
            return [0, 0];
        }
        $parts = preg_split('/\s+/', trim($line[0]));
        $nums = [];
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            if (ctype_digit((string) $parts[$i])) {
                $nums[] = (int) $parts[$i];
            }
        }
        if (count($nums) < 4) {
            return [0, 0];
        }
        $idle = $nums[3] + ($nums[4] ?? 0);
        return [array_sum($nums), $idle];
    };
    [$t1, $i1] = $readCpu();
    usleep(100000);
    [$t2, $i2] = $readCpu();
    $dTotal = max(0, $t2 - $t1);
    $dIdle = max(0, $i2 - $i1);
    $cpuPercent = $dTotal > 0 ? max(0.0, min(100.0, (1.0 - ($dIdle / $dTotal)) * 100.0)) : 0.0;

    $diskPath = is_dir('/') ? '/' : dirname(__DIR__);
    $diskTotal = @disk_total_space($diskPath);
    $diskFree = @disk_free_space($diskPath);
    if ($diskTotal === false || $diskFree === false || $diskTotal <= 0) {
        $diskTotal = 0;
        $diskUsed = 0;
        $diskPercent = 0.0;
    } else {
        $diskUsed = max(0.0, (float) $diskTotal - (float) $diskFree);
        $diskPercent = ($diskUsed / (float) $diskTotal) * 100.0;
    }

    $readNet = static function (): array {
        $sent = 0;
        $recv = 0;
        $raw = @file('/proc/net/dev', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) {
            return [0, 0];
        }
        foreach ($raw as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $rest] = explode(':', $line, 2);
            if (trim($name) === 'lo') {
                continue;
            }
            $cols = preg_split('/\s+/', trim($rest));
            if (!is_array($cols) || count($cols) < 9) {
                continue;
            }
            $recv += (int) $cols[0];
            $sent += (int) $cols[8];
        }
        return [$sent, $recv];
    };

    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $serverName) ?: 'web';
    $netCache = sys_get_temp_dir() . '/specter_web_net_' . $safeId . '.json';
    $now = microtime(true);
    [$bytesSent, $bytesRecv] = $readNet();
    $netSent = 0.0;
    $netRecv = 0.0;
    if (is_file($netCache)) {
        $prev = json_decode((string) @file_get_contents($netCache), true);
        if (is_array($prev) && isset($prev['t'], $prev['sent'], $prev['recv'])) {
            $dt = max($now - (float) $prev['t'], 0.001);
            $netSent = max(0.0, ($bytesSent - (int) $prev['sent']) / $dt / (1024 ** 2));
            $netRecv = max(0.0, ($bytesRecv - (int) $prev['recv']) / $dt / (1024 ** 2));
        }
    }
    @file_put_contents($netCache, json_encode([
        't' => $now,
        'sent' => $bytesSent,
        'recv' => $bytesRecv,
    ]));

    $gb = static function ($bytes): float {
        return round(((float) $bytes) / (1024 ** 3), 2);
    };

    return [
        'ok' => true,
        'server_name' => $serverName,
        'service' => $service,
        'cpu_percent' => round($cpuPercent, 1),
        'ram_percent' => round($ramPercent, 1),
        'ram_used' => $gb($ramUsedB),
        'ram_total' => $gb($ramTotalB),
        'disk_percent' => round($diskPercent, 1),
        'disk_used' => $gb($diskUsed),
        'disk_total' => $gb($diskTotal),
        'net_sent' => round($netSent, 3),
        'net_recv' => round($netRecv, 3),
        'collected_at' => (int) $now,
    ];
}
