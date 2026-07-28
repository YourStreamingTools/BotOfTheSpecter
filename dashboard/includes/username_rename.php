<?php
/**
 * Handle Twitch username renames at login.
 *
 * Twitch user IDs never change; login names do. Per-user MySQL databases and
 * several on-disk paths are named after the Twitch login. When a returning user
 * authenticates with a new login, rename the schema + paths before the
 * website.users.username row is updated so data stays attached to the account.
 *
 * Safe charset for DB identifiers matches usr_database.php: [a-zA-Z0-9_]{1,64}
 */

if (!function_exists('bots_is_safe_username_identifier')) {
    /**
     * @param string $name
     * @return bool
     */
    function bots_is_safe_username_identifier($name)
    {
        return is_string($name) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name) === 1;
    }
}

if (!function_exists('bots_schema_exists')) {
    /**
     * @param mysqli $mysqli
     * @param string $schema
     * @return bool
     */
    function bots_schema_exists($mysqli, $schema)
    {
        $stmt = $mysqli->prepare(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $schema);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('bots_rename_user_database')) {
    /**
     * Move every base table from $oldName schema into a new $newName schema, then drop the old DB.
     * MySQL has no supported RENAME DATABASE; table-level RENAME is the standard approach.
     *
     * @param string $oldName
     * @param string $newName
     * @return array{ok:bool, skipped:bool, message:string}
     */
    function bots_rename_user_database($oldName, $newName)
    {
        if ($oldName === $newName) {
            return ['ok' => true, 'skipped' => true, 'message' => 'same name'];
        }
        if (!bots_is_safe_username_identifier($oldName) || !bots_is_safe_username_identifier($newName)) {
            return ['ok' => false, 'skipped' => false, 'message' => 'unsafe identifier'];
        }

        require_once '/var/www/config/database.php';
        $mysqli = @new mysqli($db_servername, $db_username, $db_password);
        if ($mysqli->connect_error) {
            return [
                'ok' => false,
                'skipped' => false,
                'message' => 'connect failed: ' . $mysqli->connect_error,
            ];
        }
        $mysqli->set_charset('utf8mb4');

        try {
            $oldExists = bots_schema_exists($mysqli, $oldName);
            // Fallback: historical rows may not match schema casing on case-sensitive MySQL
            if (!$oldExists && strtolower($oldName) !== $oldName && bots_is_safe_username_identifier(strtolower($oldName))) {
                $lowerOld = strtolower($oldName);
                if (bots_schema_exists($mysqli, $lowerOld)) {
                    $oldName = $lowerOld;
                    $oldExists = true;
                }
            }
            $newExists = bots_schema_exists($mysqli, $newName);

            // No prior per-user DB — usr_database.php will create the new one on first dashboard load.
            if (!$oldExists) {
                $mysqli->close();
                return ['ok' => true, 'skipped' => true, 'message' => 'old schema missing'];
            }

            // Collision: another schema already uses the new login name.
            if ($newExists) {
                $mysqli->close();
                return [
                    'ok' => false,
                    'skipped' => false,
                    'message' => "target schema `$newName` already exists",
                ];
            }

            if (!$mysqli->query('CREATE DATABASE `' . $newName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
                $err = $mysqli->error;
                $mysqli->close();
                return ['ok' => false, 'skipped' => false, 'message' => 'CREATE DATABASE failed: ' . $err];
            }

            $tablesRes = $mysqli->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string($oldName) . "'
                   AND TABLE_TYPE = 'BASE TABLE'"
            );
            if ($tablesRes === false) {
                $err = $mysqli->error;
                // Best-effort cleanup of empty new DB
                @$mysqli->query('DROP DATABASE `' . $newName . '`');
                $mysqli->close();
                return ['ok' => false, 'skipped' => false, 'message' => 'list tables failed: ' . $err];
            }

            $tables = [];
            while ($row = $tablesRes->fetch_assoc()) {
                $t = $row['TABLE_NAME'] ?? '';
                // Extra guard: only allow simple identifiers inside the schema
                if (preg_match('/^[a-zA-Z0-9_]+$/', $t) === 1) {
                    $tables[] = $t;
                }
            }
            $tablesRes->free();

            if (count($tables) > 0) {
                // Single multi-table RENAME is atomic: either all tables move or none do,
                // which avoids splitting a user schema across two database names.
                $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
                $parts = [];
                foreach ($tables as $t) {
                    $parts[] = '`' . $oldName . '`.`' . $t . '` TO `' . $newName . '`.`' . $t . '`';
                }
                $sql = 'RENAME TABLE ' . implode(', ', $parts);
                if (!$mysqli->query($sql)) {
                    $err = $mysqli->error;
                    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
                    // Remove the empty target schema so a retry can recreate it cleanly
                    @$mysqli->query('DROP DATABASE `' . $newName . '`');
                    $mysqli->close();
                    return [
                        'ok' => false,
                        'skipped' => false,
                        'message' => 'RENAME TABLE failed: ' . $err,
                    ];
                }
                $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
            }

            // Drop leftover schema (empty, or only views/routines left — rare)
            if (!$mysqli->query('DROP DATABASE `' . $oldName . '`')) {
                // Tables already moved; log but treat as non-fatal so username can still update
                error_log(
                    'bots_rename_user_database: tables moved but DROP DATABASE `'
                    . $oldName . '` failed: ' . $mysqli->error
                );
            }

            $mysqli->close();
            return [
                'ok' => true,
                'skipped' => false,
                'message' => 'renamed ' . count($tables) . ' table(s)',
            ];
        } catch (Throwable $e) {
            @$mysqli->close();
            return ['ok' => false, 'skipped' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('bots_rename_user_directories')) {
    /**
     * Rename on-disk paths that are keyed by Twitch login.
     *
     * @param string $oldName
     * @param string $newName
     * @return array{ok:bool, renamed:array<int,string>, errors:array<int,string>}
     */
    function bots_rename_user_directories($oldName, $newName)
    {
        $result = ['ok' => true, 'renamed' => [], 'errors' => []];
        if ($oldName === $newName) {
            return $result;
        }
        if (!bots_is_safe_username_identifier($oldName) || !bots_is_safe_username_identifier($newName)) {
            $result['ok'] = false;
            $result['errors'][] = 'unsafe identifier';
            return $result;
        }

        $dirPairs = [
            '/var/www/walkons/' . $oldName => '/var/www/walkons/' . $newName,
            '/var/www/soundalerts/' . $oldName => '/var/www/soundalerts/' . $newName,
            '/var/www/videoalerts/' . $oldName => '/var/www/videoalerts/' . $newName,
            '/var/www/media/' . $oldName => '/var/www/media/' . $newName,
            '/var/www/usermusic/' . $oldName => '/var/www/usermusic/' . $newName,
            '/var/www/private/music_user/' . $oldName => '/var/www/private/music_user/' . $newName,
        ];

        foreach ($dirPairs as $from => $to) {
            if (!is_dir($from)) {
                continue;
            }
            if (file_exists($to)) {
                $result['ok'] = false;
                $result['errors'][] = "target exists, skip dir rename: $to";
                error_log("bots_rename_user_directories: target exists ($to), left $from in place");
                continue;
            }
            if (@rename($from, $to)) {
                $result['renamed'][] = $from . ' -> ' . $to;
            } else {
                $result['ok'] = false;
                $result['errors'][] = "failed rename: $from -> $to";
                error_log("bots_rename_user_directories: failed rename $from -> $to");
            }
        }

        // Known-users cache is a single JSON file named after the login
        $cacheFrom = '/var/www/cache/known_users/' . $oldName . '.json';
        $cacheTo = '/var/www/cache/known_users/' . $newName . '.json';
        if (is_file($cacheFrom) && !file_exists($cacheTo)) {
            if (@rename($cacheFrom, $cacheTo)) {
                $result['renamed'][] = $cacheFrom . ' -> ' . $cacheTo;
            } else {
                $result['ok'] = false;
                $result['errors'][] = "failed rename: $cacheFrom -> $cacheTo";
            }
        }

        return $result;
    }
}

if (!function_exists('bots_update_website_username_refs')) {
    /**
     * Update secondary website-DB rows that store a Twitch login (not the primary users row).
     * Callers still update website.users themselves as part of the normal login UPDATE.
     *
     * @param mysqli $conn website DB connection
     * @param string $twitchUserId
     * @param string $oldName
     * @param string $newName
     * @return void
     */
    function bots_update_website_username_refs($conn, $twitchUserId, $oldName, $newName)
    {
        if (!$conn || $oldName === $newName) {
            return;
        }

        // restricted_users may match by either column
        $stmt = $conn->prepare('UPDATE restricted_users SET username = ? WHERE twitch_user_id = ? OR username = ?');
        if ($stmt) {
            $stmt->bind_param('sss', $newName, $twitchUserId, $oldName);
            if (!$stmt->execute()) {
                error_log('bots_update_website_username_refs: restricted_users: ' . $stmt->error);
            }
            $stmt->close();
        }

        // Best-effort: table may not exist on all envs
        $stmt = @$conn->prepare('UPDATE discord_twitch_links SET twitch_username = ? WHERE twitch_user_id = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $newName, $twitchUserId);
            if (!$stmt->execute()) {
                error_log('bots_update_website_username_refs: discord_twitch_links: ' . $stmt->error);
            }
            $stmt->close();
        }
    }
}

if (!function_exists('bots_handle_username_change_on_login')) {
    /**
     * If the stored login for this twitch_user_id differs from the live Twitch login,
     * rename the per-user MySQL database and username-keyed paths, then update secondary refs.
     *
     * Does NOT update website.users — the caller continues with its normal UPDATE/INSERT.
     *
     * @param mysqli $conn website DB connection (already open)
     * @param string $twitchUserId stable Twitch numeric id
     * @param string $newUsername live Twitch login (will be lowercased)
     * @return array{
     *   changed:bool,
     *   old_username:?string,
     *   new_username:string,
     *   db:array{ok:bool,skipped:bool,message:string}|null,
     *   files:array{ok:bool,renamed:array,errors:array}|null,
     *   apply_username_update:bool
     * }
     */
    function bots_handle_username_change_on_login($conn, $twitchUserId, $newUsername)
    {
        $newUsername = strtolower(trim((string) $newUsername));
        $result = [
            'changed' => false,
            'old_username' => null,
            'new_username' => $newUsername,
            'db' => null,
            'files' => null,
            'bot_stop' => null,
            // When false, caller should keep users.username at the old value
            // (tokens/display name can still update) so the account keeps pointing at data.
            'apply_username_update' => true,
        ];

        if ($twitchUserId === '' || $newUsername === '' || !bots_is_safe_username_identifier($newUsername)) {
            error_log(
                'bots_handle_username_change_on_login: invalid args twitch_user_id='
                . var_export($twitchUserId, true) . ' new=' . var_export($newUsername, true)
            );
            return $result;
        }

        $stmt = $conn->prepare('SELECT username FROM users WHERE twitch_user_id = ? LIMIT 1');
        if (!$stmt) {
            error_log('bots_handle_username_change_on_login: prepare failed: ' . $conn->error);
            return $result;
        }
        $stmt->bind_param('s', $twitchUserId);
        $stmt->execute();
        $stmt->bind_result($existingUsername);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || $existingUsername === null || $existingUsername === '') {
            // New user — nothing to rename
            return $result;
        }

        // Keep the exact stored string for schema/path renames (MySQL schema names can be
        // case-sensitive on Linux). Only use lowercasing for the "did it change?" check.
        $oldUsernameExact = trim((string) $existingUsername);
        $oldUsername = strtolower($oldUsernameExact);
        $result['old_username'] = $oldUsernameExact;

        if ($oldUsername === $newUsername) {
            // Login unchanged (ignore display-name-only updates). If only casing drifted in
            // website.users, still allow the caller's UPDATE to normalise to lowercase.
            return $result;
        }

        $result['changed'] = true;
        $result['bot_stop'] = null;
        error_log(
            "bots_handle_username_change_on_login: twitch_user_id=$twitchUserId "
            . "rename `$oldUsernameExact` -> `$newUsername`"
        );

        // 0) Stop any bot process still running under the OLD channel name.
        // Processes are keyed by -channel login; after rename they would keep the
        // stale name and (worse) write to a dropped schema. Do not auto-start under
        // the new name — user restarts from the dashboard with correct credentials.
        if (!function_exists('bots_api_stop_all_for_channel')) {
            require_once __DIR__ . '/bots_api_client.php';
        }
        $botStop = bots_api_stop_all_for_channel($oldUsernameExact);
        $result['bot_stop'] = $botStop;
        if (!empty($botStop['stopped'])) {
            error_log(
                "bots_handle_username_change_on_login: stopped bot for old channel `$oldUsernameExact`: "
                . implode(',', $botStop['stopped'])
            );
        }
        if (!$botStop['ok']) {
            error_log(
                "bots_handle_username_change_on_login: bot stop issues for `$oldUsernameExact`: "
                . implode('; ', $botStop['errors'] ?? [])
            );
        }

        // 1) MySQL schema first — this is the critical data store
        $dbResult = bots_rename_user_database($oldUsernameExact, $newUsername);
        $result['db'] = $dbResult;

        if (!$dbResult['ok']) {
            error_log(
                "bots_handle_username_change_on_login: DB rename FAILED for twitch_user_id=$twitchUserId: "
                . $dbResult['message']
            );
            // Do not change users.username — keep pointing at the old schema so the user still has data.
            $result['apply_username_update'] = false;
            // Session should also keep using the old login for DB connections
            return $result;
        }

        // 2) On-disk media / cache paths (best-effort; non-fatal)
        $filesResult = bots_rename_user_directories($oldUsernameExact, $newUsername);
        $result['files'] = $filesResult;
        if (!$filesResult['ok']) {
            error_log(
                "bots_handle_username_change_on_login: some file renames failed for twitch_user_id=$twitchUserId: "
                . implode('; ', $filesResult['errors'])
            );
        }

        // 3) Secondary website rows
        bots_update_website_username_refs($conn, $twitchUserId, $oldUsernameExact, $newUsername);

        error_log(
            "bots_handle_username_change_on_login: success twitch_user_id=$twitchUserId "
            . "db={$dbResult['message']}"
        );

        return $result;
    }
}
