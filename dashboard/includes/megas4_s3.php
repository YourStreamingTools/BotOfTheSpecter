<?php
require_once '/var/www/config/megas4.php';
require_once '/var/www/vendor/aws-autoloader.php';
require_once __DIR__ . '/upload_helpers.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

if (!function_exists('_megas4_normalise_segment')) {
    function _megas4_normalise_segment($segment) {
        $segment = ltrim((string)$segment, '/');
        $segment = (string)preg_replace('#/+#', '/', $segment);
        foreach (explode('/', $segment) as $part) {
            if ($part === '..') {
                return false;
            }
        }
        return $segment;
    }
}

if (!function_exists('_megas4_check_prefix_guard')) {
    function _megas4_check_prefix_guard($prefix, $key) {
        if ($prefix === '' || $key === '') {
            return false;
        }
        $guard = $prefix . '/';
        return strncmp($key, $guard, strlen($guard)) === 0;
    }
}

if (!function_exists('_megas4_build_listing_prefix')) {
    function _megas4_build_listing_prefix($prefix, $folder) {
        $normPrefix = _megas4_normalise_segment((string)$prefix);
        if ($normPrefix === false || $normPrefix === '') {
            return false;
        }
        $folder = trim((string)$folder, '/');
        if ($folder !== '') {
            $normFolder = _megas4_normalise_segment($folder);
            if ($normFolder === false) {
                return false;
            }
            return $normPrefix . '/' . $normFolder . '/';
        }
        return $normPrefix . '/';
    }
}

if (!function_exists('megas4_client')) {
    function megas4_client() {
        static $client = null;
        if ($client !== null) {
            return $client;
        }
        global $megas4_endpoint, $megas4_region, $megas4_access_key, $megas4_secret_key;
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => $megas4_region,
            'endpoint'                => $megas4_endpoint,
            'credentials'             => [
                'key'    => $megas4_access_key,
                'secret' => $megas4_secret_key,
            ],
            'use_path_style_endpoint' => true,
        ]);
        return $client;
    }
}

if (!function_exists('megas4_list')) {
    function megas4_list($prefix, $folder) {
        global $megas4_bucket;
        $listPrefix = _megas4_build_listing_prefix($prefix, $folder);
        if ($listPrefix === false) {
            return ['error' => 'Invalid prefix or folder path.'];
        }
        $s3      = megas4_client();
        $folders = [];
        $files   = [];
        $token   = null;
        try {
            do {
                $params = [
                    'Bucket'    => $megas4_bucket,
                    'Prefix'    => $listPrefix,
                    'Delimiter' => '/',
                ];
                if ($token !== null) {
                    $params['ContinuationToken'] = $token;
                }
                $result = $s3->listObjectsV2($params);
                // Virtual folders (CommonPrefixes)
                if (!empty($result['CommonPrefixes'])) {
                    foreach ($result['CommonPrefixes'] as $cp) {
                        $cpKey   = (string)($cp['Prefix'] ?? '');
                        // Strip the listing prefix and the trailing slash to get
                        // just the next-level segment name.
                        $segment = rtrim(substr($cpKey, strlen($listPrefix)), '/');
                        if ($segment !== '') {
                            $folders[] = $segment;
                        }
                    }
                }
                // Objects at this level (skip the folder-marker key itself)
                if (!empty($result['Contents'])) {
                    foreach ($result['Contents'] as $obj) {
                        $key = (string)$obj['Key'];
                        if ($key === $listPrefix) {
                            continue;
                        }
                        $lm = $obj['LastModified'] ?? null;
                        $files[] = [
                            'key'           => $key,
                            'basename'      => basename($key),
                            'size'          => (int)($obj['Size'] ?? 0),
                            'last_modified' => ($lm instanceof \DateTimeInterface)
                                                ? $lm->getTimestamp()
                                                : 0,
                        ];
                    }
                }
                $token = $result['IsTruncated']
                    ? ($result['NextContinuationToken'] ?? null)
                    : null;
            } while ($token !== null);
        } catch (AwsException $e) {
            return ['error' => $e->getAwsErrorMessage() ?: $e->getMessage()];
        }
        return ['folders' => $folders, 'files' => $files];
    }
}

if (!function_exists('megas4_upload')) {
    function megas4_upload($prefix, $folder, $localTmpPath, $filename) {
        global $megas4_bucket;
        $normPrefix = _megas4_normalise_segment((string)$prefix);
        if ($normPrefix === false || $normPrefix === '') {
            return false;
        }
        // Sanitise the filename - keeps only safe characters.
        $ext          = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $safeFilename = upload_sanitize_filename($filename, $ext);
        // Build the full S3 key.
        $folderNorm = trim((string)$folder, '/');
        if ($folderNorm !== '') {
            $folderNorm = _megas4_normalise_segment($folderNorm);
            if ($folderNorm === false) {
                return false;
            }
            $objectKey = $normPrefix . '/' . $folderNorm . '/' . $safeFilename;
        } else {
            $objectKey = $normPrefix . '/' . $safeFilename;
        }
        // Sanity-check confinement before touching S3.
        if (!_megas4_check_prefix_guard($normPrefix, $objectKey)) {
            return false;
        }
        $fh = @fopen($localTmpPath, 'rb');
        if ($fh === false) {
            return false;
        }
        try {
            megas4_client()->putObject([
                'Bucket' => $megas4_bucket,
                'Key'    => $objectKey,
                'Body'   => $fh,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        } finally {
            fclose($fh);
        }
    }
}

if (!function_exists('megas4_exists')) {
    function megas4_exists($prefix, $key) {
        global $megas4_bucket;
        if (!_megas4_check_prefix_guard($prefix, $key)) {
            return false;
        }
        try {
            return megas4_client()->doesObjectExist($megas4_bucket, $key);
        } catch (AwsException $e) {
            // On a lookup error, err toward allowing the write rather than blocking.
            return false;
        }
    }
}

if (!function_exists('megas4_delete')) {
    function megas4_delete($prefix, $key) {
        global $megas4_bucket;
        if (!_megas4_check_prefix_guard($prefix, $key)) {
            return false;
        }
        try {
            megas4_client()->deleteObject([
                'Bucket' => $megas4_bucket,
                'Key'    => $key,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }
}

if (!function_exists('megas4_delete_folder')) {
    function megas4_delete_folder($prefix, $folderKey) {
        global $megas4_bucket;
        if (!_megas4_check_prefix_guard($prefix, $folderKey)) {
            return false;
        }
        if (substr($folderKey, -1) !== '/') {
            return false;
        }
        $s3    = megas4_client();
        $token = null;
        try {
            do {
                $params = ['Bucket' => $megas4_bucket, 'Prefix' => $folderKey];
                if ($token !== null) {
                    $params['ContinuationToken'] = $token;
                }
                $result  = $s3->listObjectsV2($params);
                $objects = [];
                if (!empty($result['Contents'])) {
                    foreach ($result['Contents'] as $obj) {
                        $objects[] = ['Key' => (string)$obj['Key']];
                    }
                }
                if (!empty($objects)) {
                    // DeleteObjects handles up to 1000 keys per call, matching
                    // the ListObjectsV2 page size, so one batch per page is safe.
                    $s3->deleteObjects([
                        'Bucket' => $megas4_bucket,
                        'Delete' => ['Objects' => $objects],
                    ]);
                }
                $token = $result['IsTruncated']
                    ? ($result['NextContinuationToken'] ?? null)
                    : null;
            } while ($token !== null);
        } catch (AwsException $e) {
            return false;
        }
        return true;
    }
}

if (!function_exists('megas4_rename')) {
    function megas4_rename($prefix, $oldKey, $newKey) {
        global $megas4_bucket;
        if (!_megas4_check_prefix_guard($prefix, $oldKey)) {
            return false;
        }
        if (!_megas4_check_prefix_guard($prefix, $newKey)) {
            return false;
        }
        $s3 = megas4_client();
        $copySource = $megas4_bucket . '/' . str_replace('%2F', '/', rawurlencode($oldKey));
        try {
            $s3->copyObject([
                'Bucket'     => $megas4_bucket,
                'Key'        => $newKey,
                'CopySource' => $copySource,
            ]);
        } catch (AwsException $e) {
            return false;
        }
        try {
            $s3->deleteObject([
                'Bucket' => $megas4_bucket,
                'Key'    => $oldKey,
            ]);
        } catch (AwsException $e) {
            // Delete of source failed - roll back by removing the copy.
            try {
                $s3->deleteObject([
                    'Bucket' => $megas4_bucket,
                    'Key'    => $newKey,
                ]);
            } catch (AwsException $inner) {
                // Ignore rollback failure; the original object is still intact.
            }
            return false;
        }
        return true;
    }
}

if (!function_exists('megas4_make_folder')) {
    function megas4_make_folder($prefix, $folder, $name) {
        global $megas4_bucket;
        $normPrefix = _megas4_normalise_segment((string)$prefix);
        if ($normPrefix === false || $normPrefix === '') {
            return false;
        }
        // Folder name must be a single, non-empty segment.
        $name = trim((string)$name, '/');
        $normName = _megas4_normalise_segment($name);
        if ($normName === false || $normName === '' || strpos($normName, '/') !== false) {
            return false;
        }
        $folderNorm = trim((string)$folder, '/');
        if ($folderNorm !== '') {
            $folderNorm = _megas4_normalise_segment($folderNorm);
            if ($folderNorm === false) {
                return false;
            }
            $objectKey = $normPrefix . '/' . $folderNorm . '/' . $normName . '/';
        } else {
            $objectKey = $normPrefix . '/' . $normName . '/';
        }
        // Confirm the key stays within the store prefix before writing.
        if (!_megas4_check_prefix_guard($normPrefix, $objectKey)) {
            return false;
        }
        try {
            megas4_client()->putObject([
                'Bucket'      => $megas4_bucket,
                'Key'         => $objectKey,
                'Body'        => '',
                'ContentType' => 'application/x-directory',
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }
}
