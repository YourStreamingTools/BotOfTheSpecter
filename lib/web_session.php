<?php
// /var/www/lib/web_session.php
// ----------------------------------------------------------------
// Custom PHP SessionHandlerInterface backed by the website.web_sessions
// table. Allows all *.botofthespecter.com subdomains to share one logical
// session by reading/writing a single MySQL row keyed by the session id
// in the .botofthespecter.com cookie.
//
// Loaded by /var/www/lib/session_bootstrap.php (do not include directly;
// the bootstrap handles cookie config, handler registration, and
// session_start() in the right order).
// ----------------------------------------------------------------

if (!defined('BOTS_SESSION_LIFETIME')) {
    define('BOTS_SESSION_LIFETIME', 14400);
}

/**
 * Delete web_sessions rows that are no longer valid by idle lifetime only.
 *
 * A row is stale when last_seen_at is older than $lifetime, or when
 * last_seen_at is NULL. Token validity is NOT decided here — Twitch
 * id.twitch.tv/oauth2/validate (in session_bootstrap) is the only
 * authority that may destroy an authenticated session. The denormalized
 * twitch_expires_at column is a display / "when to re-validate" hint.
 *
 * Pass $twitchUserId to scope the sweep to one user (profile/login).
 *
 * @return int rows deleted
 */
function bots_purge_stale_web_sessions(mysqli $db, ?string $twitchUserId = null, int $lifetime = BOTS_SESSION_LIFETIME): int
{
    if ($twitchUserId !== null && $twitchUserId !== '') {
        $stmt = $db->prepare(
            "DELETE FROM web_sessions
             WHERE twitch_user_id = ?
               AND (last_seen_at IS NULL
                    OR last_seen_at <= NOW() - INTERVAL ? SECOND)"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('si', $twitchUserId, $lifetime);
    } else {
        $stmt = $db->prepare(
            "DELETE FROM web_sessions
             WHERE last_seen_at IS NULL
                OR last_seen_at <= NOW() - INTERVAL ? SECOND"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('i', $lifetime);
    }
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return $count;
}

class WebSessionHandler implements SessionHandlerInterface
{
    /** @var mysqli */
    private $db;
    /** @var int session lifetime in seconds (matches session.gc_maxlifetime) */
    private $lifetime;
    private $lockName = null;
    public function __construct(mysqli $db, int $lifetime = 14400)
    {
        $this->db = $db;
        $this->lifetime = $lifetime;
    }
    public function open($savePath, $sessionName): bool
    {
        return true;
    }
    public function close(): bool
    {
        if ($this->lockName !== null) {
            $safelock = $this->db->real_escape_string($this->lockName);
            $this->db->query("SELECT RELEASE_LOCK('{$safelock}')");
            $this->lockName = null;
        }
        return true;
    }
    public function read($id): string
    {
        $this->lockName = 'ps:' . substr($id, 0, 60);
        $safelock = $this->db->real_escape_string($this->lockName);
        $this->db->query("SELECT GET_LOCK('{$safelock}', 5)");
        // Eagerly delete this cookie's row when last_seen_at has gone stale.
        // Token expiry is handled after read() in session_bootstrap via
        // id.twitch.tv/oauth2/validate — checking twitch_expires_at here
        // would run before that validation and log users out incorrectly.
        $purge = $this->db->prepare(
            "DELETE FROM web_sessions
             WHERE session_id = ?
               AND (last_seen_at IS NULL
                    OR last_seen_at <= NOW() - INTERVAL ? SECOND)"
        );
        if ($purge) {
            $purge->bind_param('si', $id, $this->lifetime);
            $purge->execute();
            $purge->close();
        }
        $stmt = $this->db->prepare(
            "SELECT data FROM web_sessions
             WHERE session_id = ?
               AND last_seen_at > NOW() - INTERVAL ? SECOND
             LIMIT 1"
        );
        if (!$stmt) return '';
        $stmt->bind_param('si', $id, $this->lifetime);
        $stmt->execute();
        $stmt->bind_result($data);
        $hasRow = $stmt->fetch();
        $stmt->close();
        return ($hasRow && is_string($data)) ? $data : '';
    }
    public function write($id, $data): bool
    {
        // The bootstrap configures session.serialize_handler = 'php_serialize',
        // so $data is the result of serialize($_SESSION). Decode for the
        // denormalized columns; the original blob still goes into `data`.
        $decoded = ($data === '') ? [] : @unserialize($data);
        if (!is_array($decoded)) $decoded = [];

        // Don't write empty sessions. Anything with no $_SESSION keys —
        // typically anonymous visitors who never started a flow, plus
        // any bot UAs that slipped past the bootstrap short-circuit —
        // would otherwise become a NULL-token row in web_sessions and
        // pile up until session_gc runs. Returning true tells PHP "saved";
        // the session id stays in the cookie but no row exists in DB.
        // On the next request from a real user we INSERT for the first
        // time as soon as something meaningful (post_login_redirect,
        // access_token, etc.) lands in $_SESSION.
        if (empty($decoded)) {
            return true;
        }
        $twitch_user_id = (string)($decoded['twitch_user_id']
            ?? $decoded['twitchUserId']
            ?? '');
        $username      = (string)($decoded['username']      ?? '');
        $display_name  = (string)($decoded['display_name']  ?? '');
        $profile_image = (string)($decoded['profile_image'] ?? '');
        $is_admin      = (int)   ($decoded['is_admin']      ?? 0);
        $access_token  = (string)($decoded['access_token']  ?? '');
        $refresh_token = (string)($decoded['refresh_token'] ?? '');
        // Store in the same clock MySQL NOW()/CURRENT_TIMESTAMP use
        // (PHP default timezone). gmdate(UTC) vs NOW()(local) previously
        // made brand-new sessions look expired on AU servers.
        $expires_at = null;
        if (!empty($decoded['twitch_expires_at'])) {
            $ts = (int)$decoded['twitch_expires_at'];
            if ($ts > 0) {
                $expires_at = date('Y-m-d H:i:s', $ts);
            }
        }
        $ip = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;
        // last_seen_at is set explicitly on INSERT (not just on UPDATE)
        // so the column has a real timestamp from the very first write.
        // If the schema doesn't default it to CURRENT_TIMESTAMP, fresh
        // rows would otherwise be NULL — and the session_gc cron would
        // skip them because NULL < NOW() - INTERVAL is itself NULL.
        $stmt = $this->db->prepare(
            "INSERT INTO web_sessions
                (session_id, twitch_user_id, username, display_name, profile_image,
                 is_admin, access_token, refresh_token, twitch_expires_at,
                 data, ip, user_agent, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                twitch_user_id    = VALUES(twitch_user_id),
                username          = VALUES(username),
                display_name      = VALUES(display_name),
                profile_image     = VALUES(profile_image),
                is_admin          = VALUES(is_admin),
                access_token      = VALUES(access_token),
                refresh_token     = VALUES(refresh_token),
                twitch_expires_at = VALUES(twitch_expires_at),
                data              = VALUES(data),
                ip                = VALUES(ip),
                user_agent        = VALUES(user_agent),
                last_seen_at      = CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            error_log('[web_session] write prepare failed: ' . $this->db->error);
            return false;
        }
        $bound = $stmt->bind_param(
            'sssssissssss',
            $id,
            $twitch_user_id,
            $username,
            $display_name,
            $profile_image,
            $is_admin,
            $access_token,
            $refresh_token,
            $expires_at,
            $data,
            $ip,
            $ua
        );
        if (!$bound) {
            error_log('[web_session] write bind_param failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('[web_session] write execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }
    public function destroy($id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM web_sessions WHERE session_id = ?");
        if (!$stmt) return true;
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    #[\ReturnTypeWillChange]
    public function gc($max_lifetime)
    {
        return bots_purge_stale_web_sessions($this->db, null, (int)$max_lifetime);
    }
}

// ----------------------------------------------------------------
// Twitch OAuth client credentials (for token refresh).
// Prefers bot_chat_token in the website DB (same source as config/twitch.php),
// then falls back to /var/www/config/twitch.php if present.
// ----------------------------------------------------------------
function bots_twitch_oauth_client_creds(?mysqli $db = null): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $clientId = '';
    $clientSecret = '';
    if ($db instanceof mysqli && !$db->connect_error) {
        $res = @$db->query('SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1');
        if ($res && ($row = $res->fetch_assoc()) && is_array($row)) {
            foreach (['twitch_client_id', 'client_id', 'clientID'] as $k) {
                if (!empty($row[$k])) {
                    $clientId = trim((string)$row[$k]);
                    break;
                }
            }
            foreach (['twitch_client_secret', 'client_secret', 'clientSecret'] as $k) {
                if (!empty($row[$k])) {
                    $clientSecret = trim((string)$row[$k]);
                    break;
                }
            }
        }
    }
    if ($clientId === '' || $clientSecret === '') {
        $cfgPath = '/var/www/config/twitch.php';
        if (is_readable($cfgPath)) {
            // Isolate include so local $clientID/$clientSecret cannot clobber
            // the caller's scope. twitch.php may open its own DB connection
            // to apply bot_chat_token overrides — acceptable as a fallback.
            $loader = static function () use ($cfgPath) {
                $clientID = '';
                $clientSecret = '';
                include $cfgPath;
                return [trim((string)$clientID), trim((string)$clientSecret)];
            };
            [$cfgId, $cfgSecret] = $loader();
            if ($clientId === '' && $cfgId !== '') {
                $clientId = $cfgId;
            }
            if ($clientSecret === '' && $cfgSecret !== '') {
                $clientSecret = $cfgSecret;
            }
        }
    }
    // Only cache successful lookups so a transient empty miss cannot
    // poison later requests in a long-lived PHP-FPM worker.
    if ($clientId !== '' && $clientSecret !== '') {
        $cached = [$clientId, $clientSecret];
    }
    return [$clientId, $clientSecret];
}

// ----------------------------------------------------------------
// Twitch token validation against id.twitch.tv/oauth2/validate.
// Returns:
//   ['ok' => true,  'payload' => array]            -> 200, valid token
//   ['ok' => false, 'reason' => 'invalid']         -> 401, token revoked / expired
//   ['ok' => false, 'reason' => 'transient',
//                   'http' => int, 'err' => string]-> network error / 5xx / weird response
//
// Callers should only sign the user out on reason='invalid' AFTER an
// optional refresh attempt fails. Transient failures must NOT destroy
// the session — a flaky id.twitch.tv response or a 30-second egress
// hiccup should not log every active user out.
// ----------------------------------------------------------------
function bots_twitch_validate(string $access_token): array
{
    if ($access_token === '') {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    $ch = curl_init('https://id.twitch.tv/oauth2/validate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: OAuth ' . $access_token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp     = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    if ($http === 200 && is_string($resp) && $resp !== '') {
        $j = json_decode($resp, true);
        if (is_array($j) && !empty($j['user_id'])) {
            return ['ok' => true, 'payload' => $j];
        }
        // 200 but malformed body — treat as transient, don't sign out.
        return ['ok' => false, 'reason' => 'transient', 'http' => $http, 'err' => 'malformed response body'];
    }
    if ($http === 401) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    return ['ok' => false, 'reason' => 'transient', 'http' => $http, 'err' => (string)$curl_err];
}

// ----------------------------------------------------------------
// Refresh a Twitch user access token via the refresh_token grant.
// Returns:
//   ['ok' => true,  'access_token' => ..., 'refresh_token' => ..., 'expires_in' => int]
//   ['ok' => false, 'reason' => 'invalid'|'transient', ...]
// ----------------------------------------------------------------
function bots_twitch_refresh_token(string $refresh_token, ?mysqli $db = null): array
{
    if ($refresh_token === '') {
        return ['ok' => false, 'reason' => 'invalid', 'err' => 'no refresh_token'];
    }
    [$clientId, $clientSecret] = bots_twitch_oauth_client_creds($db);
    if ($clientId === '' || $clientSecret === '') {
        // Missing creds is operational, not "user revoked" — keep session.
        return ['ok' => false, 'reason' => 'transient', 'err' => 'missing Twitch client credentials'];
    }
    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refresh_token,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp     = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    if ($http === 200 && is_string($resp) && $resp !== '') {
        $j = json_decode($resp, true);
        if (is_array($j) && !empty($j['access_token'])) {
            return [
                'ok'            => true,
                'access_token'  => (string)$j['access_token'],
                'refresh_token' => (string)($j['refresh_token'] ?? $refresh_token),
                'expires_in'    => (int)($j['expires_in'] ?? 14400),
            ];
        }
        return ['ok' => false, 'reason' => 'transient', 'http' => $http, 'err' => 'malformed refresh response'];
    }
    // Twitch returns 400 for invalid/expired refresh tokens.
    if ($http === 400 || $http === 401) {
        return ['ok' => false, 'reason' => 'invalid', 'http' => $http, 'err' => is_string($resp) ? substr($resp, 0, 200) : ''];
    }
    return ['ok' => false, 'reason' => 'transient', 'http' => $http, 'err' => (string)$curl_err];
}

