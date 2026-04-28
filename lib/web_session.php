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

class WebSessionHandler implements SessionHandlerInterface
{
    /** @var mysqli */
    private $db;
    /** @var int session lifetime in seconds (matches session.gc_maxlifetime) */
    private $lifetime;
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
        return true;
    }
    public function read($id): string
    {
        $stmt = $this->db->prepare(
            "SELECT data FROM web_sessions
             WHERE session_id = ? AND last_seen_at > NOW() - INTERVAL ? SECOND
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
        $twitch_user_id = (string)($decoded['twitch_user_id']
            ?? $decoded['twitchUserId']
            ?? '');
        $username      = (string)($decoded['username']      ?? '');
        $display_name  = (string)($decoded['display_name']  ?? '');
        $profile_image = (string)($decoded['profile_image'] ?? '');
        $is_admin      = (int)   ($decoded['is_admin']      ?? 0);
        $access_token  = (string)($decoded['access_token']  ?? '');
        $refresh_token = (string)($decoded['refresh_token'] ?? '');
        $expires_at = null;
        if (!empty($decoded['twitch_expires_at'])) {
            $ts = (int)$decoded['twitch_expires_at'];
            if ($ts > 0) {
                $expires_at = gmdate('Y-m-d H:i:s', $ts);
            }
        }
        $ip = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;
        $stmt = $this->db->prepare(
            "INSERT INTO web_sessions
                (session_id, twitch_user_id, username, display_name, profile_image,
                 is_admin, access_token, refresh_token, twitch_expires_at,
                 data, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        if (!$stmt) return false;
        $stmt->bind_param(
            'sssssisssssss',
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
        $ok = $stmt->execute();
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
        $stmt = $this->db->prepare(
            "DELETE FROM web_sessions WHERE last_seen_at < NOW() - INTERVAL ? SECOND"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('i', $max_lifetime);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}

// ----------------------------------------------------------------
// Twitch token validation against id.twitch.tv/oauth2/validate.
// Returns the JSON payload on 200 (with client_id/login/scopes/user_id/expires_in),
// null on any non-200 or transport error.
// ----------------------------------------------------------------
function bots_twitch_validate(string $access_token): ?array
{
    if ($access_token === '') return null;
    $ch = curl_init('https://id.twitch.tv/oauth2/validate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: OAuth ' . $access_token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http === 200 && is_string($resp) && $resp !== '') {
        $j = json_decode($resp, true);
        if (is_array($j) && !empty($j['user_id'])) {
            return $j;
        }
    }
    return null;
}
