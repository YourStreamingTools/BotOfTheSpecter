<?php
if (!function_exists('formatBytes')) {
    function formatBytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
$storageUsedBytes = (int) ($storageUsedBytes ?? 0);
$storageQuotaBytes = (int) ($storageQuotaBytes ?? (100 * 1024 * 1024 * 1024));
$storageUnlimited = !empty($storageUnlimited) || $storageQuotaBytes === 0;
$visualQuota = $storageUnlimited ? (100 * 1024 * 1024 * 1024) : max($storageQuotaBytes, 1);
$storagePercent = min(100, round(($storageUsedBytes / $visualQuota) * 100, 1));
$storageLabel = $storageUnlimited
    ? sprintf(t('recording_storage_used_unlimited'), formatBytes($storageUsedBytes))
    : sprintf(t('recording_storage_used_of'), formatBytes($storageUsedBytes), formatBytes($storageQuotaBytes));
?>
<div class="sp-alert sp-alert-info media-storage-bar" id="recording-storage-bar">
    <div class="media-storage-header">
        <span><i class="fas fa-database"></i> <strong><?php echo t('recording_storage_usage'); ?>:</strong></span>
        <span id="recording-storage-text"><?php echo htmlspecialchars($storageLabel); ?></span>
    </div>
    <progress class="progress" id="recording-storage-progress" value="<?php echo htmlspecialchars((string) $storagePercent); ?>" max="100"></progress>
</div>
