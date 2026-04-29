<?php
// home/profile.php
// ----------------------------------------------------------------
// Account & active-sessions page on the IdP.
//
// Shows every row in web_sessions belonging to the current user (one
// row per logged-in browser/device). Each row has a "Remove" button
// that revokes that single session. There's also a "Log out
// everywhere" button that drops every session for the user.
//
// Standard IdP feature — Google's "Devices", GitHub's "Sessions",
// Discord's "Authorized apps".
// ----------------------------------------------------------------

require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/config/iplocate.php';

// Must be signed in to view this page.
if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) {
    $self = (!empty($_SERVER['HTTPS']) ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'botofthespecter.com')
          . $_SERVER['REQUEST_URI'];
    header('Location: /login.php?return=' . urlencode($self));
    exit;
}

$current_session_id = session_id();
$twitchUserId       = (string)$_SESSION['twitchUserId'];

// CSRF token tied to the session.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ----------------------------------------------------------------
// POST handlers — Remove one / Remove all others / Log out everywhere.
// All paths verify CSRF AND filter by twitchUserId so a user can never
// touch another user's rows even if they craft session_id.
// ----------------------------------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf']   ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $flash = ['type' => 'error', 'msg' => 'Security token mismatch — please reload and try again.'];
    } elseif ($action === 'remove' && !empty($_POST['session_id'])) {
        $target = (string)$_POST['session_id'];
        $stmt = $bots_session_db->prepare(
            "DELETE FROM web_sessions WHERE session_id = ? AND twitch_user_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $target, $twitchUserId);
            $stmt->execute();
            $stmt->close();
        }
        // If they removed the current device, kill our cookie too.
        if ($target === $current_session_id) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header('Location: /');
            exit;
        }
        $flash = ['type' => 'ok', 'msg' => 'Session removed.'];
    } elseif ($action === 'remove_all_others') {
        $stmt = $bots_session_db->prepare(
            "DELETE FROM web_sessions
             WHERE twitch_user_id = ? AND session_id <> ?"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $twitchUserId, $current_session_id);
            $stmt->execute();
            $removed = $stmt->affected_rows;
            $stmt->close();
            $flash = ['type' => 'ok', 'msg' => "Signed out of {$removed} other " . ($removed === 1 ? 'session' : 'sessions') . '.'];
        }
    } elseif ($action === 'remove_all') {
        $stmt = $bots_session_db->prepare(
            "DELETE FROM web_sessions WHERE twitch_user_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('s', $twitchUserId);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: /');
        exit;
    }
}

// ----------------------------------------------------------------
// Load every session belonging to this user.
// ----------------------------------------------------------------
$sessions = [];
$stmt = $bots_session_db->prepare(
    "SELECT session_id, ip, user_agent, created_at, last_seen_at, twitch_expires_at
     FROM web_sessions
     WHERE twitch_user_id = ?
     ORDER BY (session_id = ?) DESC, last_seen_at DESC"
);
if ($stmt) {
    $stmt->bind_param('ss', $twitchUserId, $current_session_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $sessions[] = $row;
    $stmt->close();
}

// ----------------------------------------------------------------
// Friendly UA parser. Order matters: Edge/Opera contain "Chrome",
// Chrome contains "Safari".
// ----------------------------------------------------------------
function bots_humanize_ua(?string $ua): string
{
    if (!$ua) return 'Unknown device';
    if (preg_match('/Windows NT 10\.0/i', $ua))      $os = 'Windows';
    elseif (preg_match('/Windows NT 6\.[123]/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS X/i', $ua))           $os = 'macOS';
    elseif (preg_match('/iPhone|iPad/i', $ua))        $os = 'iOS';
    elseif (preg_match('/Android/i', $ua))            $os = 'Android';
    elseif (preg_match('/Linux/i', $ua))              $os = 'Linux';
    else                                              $os = 'Unknown OS';

    if (preg_match('/Edg\//i', $ua))           $browser = 'Edge';
    elseif (preg_match('/OPR\//i', $ua))       $browser = 'Opera';
    elseif (preg_match('/Firefox\//i', $ua))   $browser = 'Firefox';
    elseif (preg_match('/Chrome\//i', $ua))    $browser = 'Chrome';
    elseif (preg_match('/Safari\//i', $ua))    $browser = 'Safari';
    else                                       $browser = 'Browser';

    return "$browser on $os";
}

function bots_fetch_ip_geo(string $ip, string $apiKey): ?array
{
    static $cache = [];
    if (!$apiKey || !$ip || $ip === '—') return null;
    if (array_key_exists($ip, $cache)) return $cache[$ip];

    $url = 'https://iplocate.io/api/lookup/' . urlencode($ip);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
    $raw      = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if (!$raw || $curl_err) {
        error_log('[profile.php] iplocate curl failed ip=' . $ip . ' err=' . $curl_err);
        $cache[$ip] = null;
        return null;
    }
    if ($http === 429) {
        error_log('[profile.php] iplocate rate limit exceeded ip=' . $ip);
        $cache[$ip] = null;
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || $http !== 200) {
        error_log('[profile.php] iplocate bad response ip=' . $ip . ' http=' . $http . ' body=' . substr($raw, 0, 300));
        $cache[$ip] = null;
        return null;
    }
    $cache[$ip] = $data;
    return $data;
}

function bots_format_when($datetime): string
{
    if (!$datetime) return '—';
    try {
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        return $dt->format('M j, Y g:ia') . ' UTC';
    } catch (Exception $e) {
        return htmlspecialchars((string)$datetime);
    }
}

// ----------------------------------------------------------------
// Render via the home layout.
// ----------------------------------------------------------------
ob_start();
$pageTitle       = 'Active Sessions — BotOfTheSpecter';
$pageDescription = 'Manage the devices and browsers signed in to your BotOfTheSpecter account.';
?>
<style>
  .sp-acct { max-width: 920px; margin: 32px auto; padding: 0 16px; }
  .sp-acct h1 { margin: 0 0 6px 0; }
  .sp-acct .sub { color: #8b949e; margin-bottom: 24px; }
  .sp-flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
  .sp-flash.ok    { background: #0f2a1a; color: #3fb950; border: 1px solid #1f6f3f; }
  .sp-flash.error { background: #2a0f0f; color: #f85149; border: 1px solid #6f1f1f; }
  .sp-session { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: start;
                background: #161b22; border: 1px solid #21262d; border-radius: 8px;
                padding: 14px 16px; margin-bottom: 10px; }
  .sp-session.current { border-color: #58a6ff; background: #161b22; }
  .sp-session .device { font-weight: 600; }
  .sp-session .meta { color: #8b949e; font-size: 13px; line-height: 1.6; }
  .sp-session .meta code { background: #0e1116; padding: 1px 6px; border-radius: 3px; }
  .sp-badge { display: inline-block; background: #58a6ff; color: #0e1116;
              font-size: 11px; padding: 2px 8px; border-radius: 999px; margin-left: 6px;
              font-weight: 600; letter-spacing: 0.02em; }
  .sp-actions { display: flex; flex-direction: column; gap: 6px; }
  .sp-btn-tiny { background: #21262d; color: #e6edf3; border: 1px solid #30363d;
                 padding: 6px 12px; border-radius: 4px; font-size: 13px; cursor: pointer;
                 font-family: inherit; }
  .sp-btn-tiny:hover  { background: #30363d; }
  .sp-btn-tiny.danger { color: #f85149; border-color: #6f1f1f; }
  .sp-btn-tiny.danger:hover { background: #2a0f0f; }
  .sp-bulk { margin-top: 24px; padding-top: 16px; border-top: 1px solid #21262d;
             display: flex; gap: 8px; flex-wrap: wrap; }
</style>
<div class="sp-acct">
    <h1>Active Sessions</h1>
    <p class="sub">
        Signed in as <strong><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? ''); ?></strong>.
        Each row below is a browser or device that's currently signed in to your BotOfTheSpecter account.
        Remove any session you don't recognise.
    </p>

    <?php if ($flash): ?>
        <div class="sp-flash <?php echo htmlspecialchars($flash['type']); ?>">
            <?php echo htmlspecialchars($flash['msg']); ?>
        </div>
    <?php endif; ?>

    <?php if (!$sessions): ?>
        <p class="sub">No active sessions found. Try signing out and back in.</p>
    <?php else: ?>
        <?php foreach ($sessions as $s):
            $isCurrent = ($s['session_id'] === $current_session_id);
            $geo = bots_fetch_ip_geo($s['ip'] ?? '', $iplocate_api_key ?? '');
        ?>
            <div class="sp-session<?php echo $isCurrent ? ' current' : ''; ?>">
                <div>
                    <div class="device">
                        <?php echo htmlspecialchars(bots_humanize_ua($s['user_agent'])); ?>
                        <?php if ($isCurrent): ?><span class="sp-badge">This device</span><?php endif; ?>
                    </div>
                    <div class="meta">
                        IP <code><?php echo htmlspecialchars($s['ip'] ?? '—'); ?></code>
                        <?php if ($geo): ?>
                            <?php
                                $geoCity        = $geo['city']                    ?? '';
                                $geoSubdivision = $geo['subdivision']             ?? '';
                                $geoCountry     = $geo['country']                 ?? '';
                                $geoAsn         = $geo['asn']['name']             ?? '';
                                $geoHosting     = $geo['hosting']['provider']    ?? '';
                                $geoOrg         = $geoHosting ?: $geoAsn;
                                $geoLoc         = implode(', ', array_filter([$geoCity, $geoSubdivision, $geoCountry]));
                            ?>
                            <span style="color:#6e7681;">
                                <?php echo htmlspecialchars($geoLoc ?: '—'); ?>
                                <?php if ($geoOrg): ?>(<?php echo htmlspecialchars($geoOrg); ?>)<?php endif; ?>
                            </span>
                        <?php endif; ?><br>
                        Signed in <?php echo bots_format_when($s['created_at']); ?><br>
                        Last seen <?php echo bots_format_when($s['last_seen_at']); ?><br>
                        Twitch token expires <?php echo bots_format_when($s['twitch_expires_at']); ?>
                    </div>
                </div>
                <div class="sp-actions">
                    <form method="post" action="/profile.php" onsubmit="return confirm('<?php echo $isCurrent ? 'This will sign you out of this device. Continue?' : 'Remove this session?'; ?>');">
                        <input type="hidden" name="csrf"       value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action"     value="remove">
                        <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($s['session_id']); ?>">
                        <button type="submit" class="sp-btn-tiny danger">
                            <i class="fa-solid fa-xmark"></i> Remove
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="sp-bulk">
            <?php if (count($sessions) > 1): ?>
                <form method="post" action="/profile.php" onsubmit="return confirm('Sign out of every other device? You will stay signed in here.');">
                    <input type="hidden" name="csrf"   value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="remove_all_others">
                    <button type="submit" class="sp-btn-tiny">
                        <i class="fa-solid fa-broom"></i> Sign out other devices
                    </button>
                </form>
            <?php endif; ?>
            <form method="post" action="/profile.php" onsubmit="return confirm('Sign out of every device, including this one?');">
                <input type="hidden" name="csrf"   value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="remove_all">
                <button type="submit" class="sp-btn-tiny danger">
                    <i class="fa-solid fa-right-from-bracket"></i> Log out everywhere
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>
