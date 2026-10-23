<?php
// Server-side rules for Specter Modules saves. The page JS runs the same
// checks for quick feedback, but these are the ones that count.

if (!function_exists('modules_joke_categories')) {
    function modules_joke_categories(): array
    {
        return ['Miscellaneous', 'Coding', 'Development', 'Halloween', 'Pun', 'nsfw', 'religious', 'political', 'racist', 'sexist', 'dark', 'explicit'];
    }

    function modules_sound_events(): array
    {
        return ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'Hype Train Start', 'Hype Train End'];
    }

    function modules_tts_voices(): array
    {
        return ['Alloy', 'Ash', 'Ballad', 'Coral', 'Echo', 'Fable', 'Nova', 'Onyx', 'Sage', 'Shimmer', 'Verse'];
    }

    function modules_tts_languages(): array
    {
        return ['en'];
    }

    // True when the text matches a known spam pattern. Null when the check
    // could not run (treat as a failure, do not save).
    function modules_matches_spam(string $text): ?bool
    {
        global $db_servername, $db_username, $db_password;
        try {
            $spam = new mysqli($db_servername, $db_username, $db_password, 'spam_pattern');
        } catch (Throwable $e) {
            error_log('modules_matches_spam connect: ' . $e->getMessage());
            return null;
        }
        if ($spam->connect_error) {
            error_log('modules_matches_spam connect: ' . $spam->connect_error);
            return null;
        }
        $hit = false;
        $res = $spam->query("SELECT spam_pattern FROM spam_patterns");
        while ($res && ($row = $res->fetch_assoc())) {
            if (preg_match('/' . preg_quote((string) $row['spam_pattern'], '/') . '/i', $text)) {
                $hit = true;
                break;
            }
        }
        $spam->close();
        return $hit;
    }

    function modules_count(mysqli $db, string $sql, string $value): int
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log('modules_count prepare: ' . $db->error);
            return 0;
        }
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return (int) $n;
    }

    // Returns an error message for a whitelist/blacklist link, or null when ok.
    function modules_link_problem(mysqli $db, string $list, string $link): ?string
    {
        if ($link === '' || mb_strlen($link) > 255) {
            return 'Enter a link of 255 characters or fewer.';
        }
        if (preg_match('/\s/', $link)) {
            return function_exists('t') ? t('modules_js_no_spaces_urls') : 'Links cannot contain spaces.';
        }
        [$own, $other] = $list === 'whitelist'
            ? ['link_whitelist', 'link_blacklisting']
            : ['link_blacklisting', 'link_whitelist'];
        $tr = function (string $key, string $fallback): string {
            return function_exists('t') ? t($key) : $fallback;
        };
        $spam = modules_matches_spam($link);
        if ($spam === null) {
            return 'Could not check that link right now. Try again.';
        }
        if ($spam) {
            return $list === 'whitelist'
                ? $tr('modules_js_cant_whitelist_blocked', 'This link matches a known spam pattern and cannot be whitelisted.')
                : $tr('modules_js_globally_blocked', 'This link is already blocked globally.');
        }
        if (modules_count($db, "SELECT COUNT(*) FROM `$other` WHERE link = ?", $link) > 0) {
            return $list === 'whitelist'
                ? $tr('modules_js_url_already_blacklist', 'This link is already on the blacklist.')
                : $tr('modules_js_url_already_whitelist', 'This link is already on the whitelist.');
        }
        if (modules_count($db, "SELECT COUNT(*) FROM `$own` WHERE link = ?", $link) > 0) {
            return 'That link is already on the list.';
        }
        return null;
    }

    // Same rules as api/check_blocked_term.php.
    function modules_term_problem(mysqli $db, string $term): ?string
    {
        if ($term === '' || mb_strlen($term) > 255) {
            return 'Enter a term of 255 characters or fewer.';
        }
        $spam = modules_matches_spam($term);
        if ($spam === null) {
            return 'Could not check that term right now. Try again.';
        }
        if ($spam) {
            return function_exists('t') ? t('check_blocked_term_msg_spam_pattern') : 'This term matches a known spam pattern.';
        }
        foreach ([['link_whitelist', 'check_blocked_term_msg_whitelist'], ['link_blacklisting', 'check_blocked_term_msg_blacklist']] as [$tbl, $msgKey]) {
            $res = $db->query("SELECT link FROM `$tbl`");
            while ($res && ($row = $res->fetch_assoc())) {
                $l = (string) $row['link'];
                if ($l !== '' && (stripos($l, $term) !== false || stripos($term, $l) !== false)) {
                    return function_exists('t') ? t($msgKey) : 'This term conflicts with a saved link.';
                }
            }
        }
        if (modules_count($db, "SELECT COUNT(*) FROM blocked_terms WHERE term = ?", $term) > 0) {
            return 'That term is already blocked.';
        }
        return null;
    }
}
