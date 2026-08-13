<?php
require_once '/var/www/lib/session_bootstrap.php';

require_once '/var/www/lib/require_auth.php';

// Page Title and Header
$pageTitle = "Tanggle Integration";
$pageDescription = "Configure Tanggle puzzle integration settings";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

function tanggle_api_get($url, $token)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http_code, $response];
}

function tanggle_format_datetime($value)
{
    if (empty($value)) {
        return t('tanggle_not_available');
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return t('tanggle_not_available');
    }
    return date('M j, Y g:i A', $ts);
}

function tanggle_build_list_payload(mysqli $db): array
{
    $current_api_token = '';
    $current_community_uuid = '';
    $result = $db->query("SELECT tanggle_api_token, tanggle_community_uuid FROM profile LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $current_api_token = $row['tanggle_api_token'] ?? '';
        $current_community_uuid = $row['tanggle_community_uuid'] ?? '';
    }
    $credentials_exist = !empty($current_api_token) && !empty($current_community_uuid);

    $payload = [
        'success' => true,
        'credentials_exist' => $credentials_exist,
        'api_error' => null,
        'active_room' => null,
        'queue_items' => [],
        'puzzle_stats' => [
            'completed_count' => 0,
            'last_completed_display' => t('tanggle_not_available'),
        ],
        'recent_completions' => [],
    ];

    if (!$credentials_exist) {
        return $payload;
    }

    $tanggle_base_url = 'https://api.tanggle.io';
    $community = rawurlencode($current_community_uuid);
    [$http_code, $response] = tanggle_api_get("$tanggle_base_url/communities/$community/rooms", $current_api_token);
    if ($http_code === 200 && $response) {
        $rooms_data = json_decode($response, true);
        if (isset($rooms_data['items']) && count($rooms_data['items']) > 0) {
            $first_room_uuid = rawurlencode($rooms_data['items'][0]['uuid']);
            [$room_http_code, $room_response] = tanggle_api_get(
                "$tanggle_base_url/communities/$community/rooms/$first_room_uuid",
                $current_api_token
            );
            if ($room_http_code === 200 && $room_response) {
                $room_data = json_decode($room_response, true);
                if (isset($room_data['success']) && $room_data['success'] && isset($room_data['room'])) {
                    $room = $room_data['room'];
                    $payload['active_room'] = [
                        'preview_url' => $room['image']['sources']['preview'][3]['url'] ?? '',
                        'title' => $room['image']['attribution']['title'] ?? t('tanggle_untitled_puzzle'),
                        'is_completed' => !empty($room['isCompleted']),
                        'pieces_completed' => $room['pieces']['completed'] ?? 0,
                        'pieces_count' => $room['pieces']['count'] ?? 0,
                        'completed_rate' => isset($room['pieces']['completedRate'])
                            ? round($room['pieces']['completedRate'] * 100, 1)
                            : 0,
                        'grid_x' => $room['pieces']['x'] ?? 0,
                        'grid_y' => $room['pieces']['y'] ?? 0,
                        'player_count' => $room['playerCount'] ?? 0,
                        'player_limit' => $room['playerLimit'] ?? 0,
                        'creator' => $room['image']['attribution']['creator']['name'] ?? null,
                        'redirect_url' => $room['redirectUrl'] ?? '',
                    ];
                }
            }
        }
    } else {
        $payload['api_error'] = t('tanggle_api_fetch_error');
    }

    [$queue_http_code, $queue_response] = tanggle_api_get(
        "$tanggle_base_url/communities/$community/queue",
        $current_api_token
    );
    if ($queue_http_code === 200 && $queue_response) {
        $queue_data = json_decode($queue_response, true);
        if (is_array($queue_data)) {
            foreach ($queue_data as $queue_item) {
                $gx = (int) ($queue_item['body']['pieces']['x'] ?? 0);
                $gy = (int) ($queue_item['body']['pieces']['y'] ?? 0);
                $payload['queue_items'][] = [
                    'preview_url' => $queue_item['image']['sources']['preview'][3]['url'] ?? '',
                    'title' => $queue_item['image']['attribution']['title'] ?? t('tanggle_untitled'),
                    'alt' => $queue_item['image']['attribution']['title'] ?? t('tanggle_queue_item_alt'),
                    'position' => $queue_item['position'] ?? 0,
                    'grid_x' => $gx,
                    'grid_y' => $gy,
                    'piece_total' => $gx * $gy,
                    'creator' => $queue_item['image']['attribution']['creator']['name'] ?? null,
                ];
            }
        }
    }

    $stats_result = $db->query("SELECT completed_count, last_completed_at FROM tanggle_puzzle_stats WHERE id = 1 LIMIT 1");
    if ($stats_result && $stats_row = $stats_result->fetch_assoc()) {
        $payload['puzzle_stats']['completed_count'] = (int) ($stats_row['completed_count'] ?? 0);
        $payload['puzzle_stats']['last_completed_display'] = tanggle_format_datetime($stats_row['last_completed_at'] ?? null);
    }

    $recent_result = $db->query("SELECT room_uuid, redirect_url, room_title, piece_count, piece_completed, winner_username, winner_twitch_username, completed_at, recorded_at FROM tanggle_room_completions ORDER BY COALESCE(completed_at, recorded_at) DESC LIMIT 10");
    if ($recent_result) {
        while ($completion_row = $recent_result->fetch_assoc()) {
            $winner = $completion_row['winner_twitch_username'] ?: $completion_row['winner_username'];
            $completed_source = $completion_row['completed_at'] ?: $completion_row['recorded_at'];
            $payload['recent_completions'][] = [
                'room_title' => $completion_row['room_title'] ?: t('tanggle_untitled_puzzle'),
                'room_uuid' => $completion_row['room_uuid'] ?? '',
                'winner' => $winner ?: t('tanggle_winner_unknown'),
                'piece_completed' => isset($completion_row['piece_completed']) ? (int) $completion_row['piece_completed'] : 0,
                'piece_count' => isset($completion_row['piece_count']) ? (int) $completion_row['piece_count'] : 0,
                'completed_display' => tanggle_format_datetime($completed_source),
                'redirect_url' => $completion_row['redirect_url'] ?? '',
            ];
        }
    }

    return $payload;
}

// List endpoint first so the browser can paint skeletons, then fetch Tanggle + completions.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        echo json_encode(tanggle_build_list_payload($db));
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle POST request to save credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['api_token']) || isset($_POST['community_uuid']))) {
    $api_token = trim($_POST['api_token'] ?? '');
    $community_uuid = trim($_POST['community_uuid'] ?? '');
    if (!empty($api_token) && !empty($community_uuid)) {
        // Ensure a profile row exists; if not insert, otherwise update
        $checkStmt = mysqli_prepare($db, "SELECT COUNT(*) as cnt FROM profile");
        if ($checkStmt) {
            mysqli_stmt_execute($checkStmt);
            $checkRes = mysqli_stmt_get_result($checkStmt);
            $row = mysqli_fetch_assoc($checkRes);
            mysqli_stmt_close($checkStmt);
        } else {
            $row = ['cnt' => 0];
        }
        if (!isset($row['cnt']) || $row['cnt'] == 0) {
            $stmt = mysqli_prepare($db, "INSERT INTO profile (tanggle_api_token, tanggle_community_uuid) VALUES (?, ?)");
        } else {
            $stmt = mysqli_prepare($db, "UPDATE profile SET tanggle_api_token = ?, tanggle_community_uuid = ?");
        }
        if ($stmt === false) {
            $message = t('tanggle_msg_db_error') . mysqli_error($db);
            $message_is_success = false;
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $api_token, $community_uuid);
            if (mysqli_stmt_execute($stmt)) {
                $message = t('tanggle_msg_saved_success');
                $message_is_success = true;
            } else {
                $message = t('tanggle_msg_save_failed') . mysqli_error($db);
                $message_is_success = false;
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $message = t('tanggle_msg_enter_both');
        $message_is_success = false;
    }
}

// Cheap shell query only — Tanggle curls and completion lists stay on ajax_action=list.
$current_api_token = '';
$current_community_uuid = '';
$result = $db->query("SELECT tanggle_api_token, tanggle_community_uuid FROM profile LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $current_api_token = $row['tanggle_api_token'] ?? '';
    $current_community_uuid = $row['tanggle_community_uuid'] ?? '';
}
$credentials_exist = !empty($current_api_token) && !empty($current_community_uuid);

ob_start();
?>
<?php if (isset($message)): ?>
    <div class="sp-alert <?php echo (!empty($message_is_success)) ? 'sp-alert-success' : 'sp-alert-danger'; ?>" style="margin-bottom:1.5rem;">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if (!$credentials_exist): ?>
    <div class="sp-alert sp-alert-warning" style="margin-bottom:1.5rem;">
        <strong><?= t('tanggle_config_required_label') ?></strong> <?= t('tanggle_config_required_text') ?>
    </div>
<?php endif; ?>
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <i class="fas fa-puzzle-piece" style="margin-right:0.5rem;"></i>
            <?= t('tanggle_integration_title') ?>
        </p>
    </header>
    <div class="sp-card-body">
        <div class="sp-card" style="margin-bottom:1.5rem;">
            <header class="sp-card-header">
                <p class="sp-card-title">
                    <i class="fas fa-wrench" style="margin-right:0.5rem;"></i>
                    <?= t('tanggle_configuration_title') ?>
                </p>
            </header>
            <div class="sp-card-body">
                <p style="color:var(--text-secondary);margin-bottom:1.25rem;"><?= t('tanggle_configuration_intro') ?></p>
                <form method="post" action="">
                    <div class="sp-form-group">
                        <label class="sp-label"><?= t('tanggle_api_token_label') ?></label>
                        <div style="position:relative;">
                            <input class="sp-input" type="password" name="api_token"
                                value="<?php echo htmlspecialchars($current_api_token); ?>"
                                placeholder="<?= htmlspecialchars(t('tanggle_api_token_placeholder')) ?>" required id="api-token-field"
                                style="padding-right:2.5rem;">
                            <span id="api-token-visibility-icon"
                                style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--text-muted);">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.35rem;"><?= t('tanggle_api_token_hint') ?></p>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label"><?= t('tanggle_community_uuid_label') ?></label>
                        <input class="sp-input" type="text" name="community_uuid"
                            value="<?php echo htmlspecialchars($current_community_uuid); ?>"
                            placeholder="<?= htmlspecialchars(t('tanggle_community_uuid_placeholder')) ?>" required id="community-uuid-field">
                        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.35rem;"><?= t('tanggle_community_uuid_hint') ?></p>
                    </div>
                    <button class="sp-btn sp-btn-primary" type="submit"><?= t('tanggle_save_credentials_btn') ?></button>
                </form>
            </div>
        </div>
        <?php if (!$credentials_exist): ?>
            <div class="sp-card">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-question-circle" style="margin-right:0.5rem;"></i>
                        <?= t('tanggle_howto_title') ?>
                    </p>
                </header>
                <div class="sp-card-body">
                    <p style="font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;"><?= t('tanggle_howto_token_heading') ?></p>
                    <ol style="color:var(--text-secondary);padding-left:1.5rem;margin-bottom:1.25rem;">
                        <li><?= t('tanggle_howto_token_step1') ?></li>
                        <li><?= t('tanggle_howto_token_step2') ?></li>
                        <li><?= t('tanggle_howto_token_step3') ?></li>
                        <li><?= t('tanggle_howto_token_step4') ?></li>
                        <li><?= t('tanggle_howto_token_step5') ?></li>
                        <li><?= t('tanggle_howto_token_step6') ?></li>
                    </ol>
                    <p style="font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;"><?= t('tanggle_howto_uuid_heading') ?></p>
                    <ol style="color:var(--text-secondary);padding-left:1.5rem;">
                        <li><?= t('tanggle_howto_uuid_step1') ?></li>
                        <li><?= t('tanggle_howto_uuid_step2') ?></li>
                        <li><?= t('tanggle_howto_uuid_step3') ?></li>
                        <li><?= t('tanggle_howto_uuid_step4') ?></li>
                        <li><?= t('tanggle_howto_uuid_step5') ?></li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($credentials_exist): ?>
            <div id="tanggle-api-error" class="sp-alert sp-alert-danger" style="margin-bottom:1.5rem;display:none;">
                <strong><?= t('tanggle_api_error_label') ?></strong> <span id="tanggle-api-error-text"></span>
            </div>
            <div class="sp-card" style="margin-bottom:1.5rem;" id="tanggle-stats-host" aria-busy="true">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-chart-line" style="margin-right:0.5rem;"></i>
                        <?= t('tanggle_stats_title') ?>
                    </p>
                </header>
                <div class="sp-card-body">
                    <div id="tanggle-stats-skeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-50"></span>
                        <span class="sp-skeleton-line w-70"></span>
                    </div>
                    <div id="tanggle-stats-ready" style="display:none;gap:1.5rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <p style="color:var(--text-secondary);">
                                <strong style="color:var(--text-primary);"><?= t('tanggle_stats_total_completed') ?></strong>
                                <span class="sp-badge sp-badge-green" style="margin-left:0.5rem;" id="tanggle-stats-count"></span>
                            </p>
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <p style="color:var(--text-secondary);">
                                <strong style="color:var(--text-primary);"><?= t('tanggle_stats_last_completed') ?></strong>
                                <span id="tanggle-stats-last"></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div id="tanggle-puzzle-host" aria-busy="true">
                <div class="sp-card" style="margin-bottom:1.5rem;" id="tanggle-puzzle-skeleton" aria-hidden="true">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-puzzle-piece" style="margin-right:0.5rem;"></i>
                            <?= t('tanggle_active_puzzle_title') ?>
                        </p>
                    </header>
                    <div class="sp-card-body">
                        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
                            <div style="flex:0 0 240px;max-width:240px;">
                                <span class="sp-skeleton-thumb"></span>
                            </div>
                            <div class="sp-skeleton-stack" style="flex:1;min-width:200px;">
                                <span class="sp-skeleton-line w-70"></span>
                                <span class="sp-skeleton-line w-50"></span>
                                <span class="sp-skeleton-line w-60"></span>
                                <span class="sp-skeleton-line w-40"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="sp-card" style="margin-bottom:1.5rem;display:none;" id="tanggle-puzzle-ready">
                    <header class="sp-card-header">
                        <p class="sp-card-title">
                            <i class="fas fa-puzzle-piece" style="margin-right:0.5rem;"></i>
                            <?= t('tanggle_active_puzzle_title') ?>
                        </p>
                    </header>
                    <div class="sp-card-body">
                        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
                            <div style="flex:0 0 240px;max-width:240px;">
                                <img id="tanggle-puzzle-preview" src="" alt="<?= htmlspecialchars(t('tanggle_puzzle_preview_alt')) ?>" style="width:100%;border-radius:var(--radius);display:block;">
                            </div>
                            <div style="flex:1;min-width:200px;">
                                <p style="font-weight:700;font-size:1rem;color:var(--text-primary);margin-bottom:0.75rem;" id="tanggle-puzzle-title"></p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);"><?= t('tanggle_status_label') ?></strong>
                                    <span id="tanggle-puzzle-status"></span>
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);"><?= t('tanggle_pieces_label') ?></strong>
                                    <span id="tanggle-puzzle-pieces"></span>
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);"><?= t('tanggle_grid_label') ?></strong>
                                    <span id="tanggle-puzzle-grid"></span>
                                </p>
                                <p style="color:var(--text-secondary);margin-bottom:0.5rem;">
                                    <strong style="color:var(--text-primary);"><?= t('tanggle_players_label') ?></strong>
                                    <span id="tanggle-puzzle-players"></span>
                                </p>
                                <p id="tanggle-puzzle-creator-row" style="color:var(--text-secondary);margin-bottom:0.75rem;display:none;">
                                    <strong style="color:var(--text-primary);"><?= t('tanggle_creator_label') ?></strong>
                                    <span id="tanggle-puzzle-creator"></span>
                                </p>
                                <a id="tanggle-puzzle-link" href="#" target="_blank" class="sp-btn sp-btn-primary sp-btn-sm">
                                    <i class="fas fa-external-link-alt"></i>
                                    <?= t('tanggle_open_puzzle_btn') ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="sp-alert sp-alert-info" style="margin-bottom:1.5rem;display:none;" id="tanggle-puzzle-empty">
                    <strong><?= t('tanggle_no_active_puzzle_label') ?></strong> <?= t('tanggle_no_active_puzzle_text') ?>
                </div>
            </div>
            <div class="sp-card" style="margin-bottom:1.5rem;" id="tanggle-queue-host" aria-busy="true">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-list" style="margin-right:0.5rem;"></i>
                        <?= t('tanggle_queue_title') ?> <span id="tanggle-queue-count"></span>
                    </p>
                </header>
                <div class="sp-card-body">
                    <div id="tanggle-queue-skeleton" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;" aria-hidden="true">
                        <?php for ($sk = 0; $sk < 3; $sk++): ?>
                        <div class="sp-card" style="margin-bottom:0;">
                            <span class="sp-skeleton-thumb"></span>
                            <div class="sp-skeleton-stack" style="padding:0.75rem;">
                                <span class="sp-skeleton-line w-80"></span>
                                <span class="sp-skeleton-line w-50"></span>
                                <span class="sp-skeleton-line w-60"></span>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div id="tanggle-queue-ready" style="display:none;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;"></div>
                </div>
            </div>
            <div class="sp-card">
                <header class="sp-card-header">
                    <p class="sp-card-title">
                        <i class="fas fa-history" style="margin-right:0.5rem;"></i>
                        <?= t('tanggle_recent_completed_title') ?>
                    </p>
                </header>
                <div class="sp-card-body">
                    <div class="sp-table-wrap" id="tanggle-completions-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th><?= t('tanggle_table_puzzle') ?></th>
                                    <th><?= t('tanggle_table_winner') ?></th>
                                    <th><?= t('tanggle_table_pieces') ?></th>
                                    <th><?= t('tanggle_table_completed') ?></th>
                                    <th><?= t('tanggle_table_link') ?></th>
                                </tr>
                            </thead>
                            <tbody id="tanggle-completions-tbody" aria-busy="true">
                                <?php for ($sk = 0; $sk < 5; $sk++): ?>
                                <tr aria-hidden="true">
                                    <td><span class="sp-skeleton-line w-70"></span></td>
                                    <td><span class="sp-skeleton-line w-50"></span></td>
                                    <td><span class="sp-skeleton-line w-40"></span></td>
                                    <td><span class="sp-skeleton-line w-60"></span></td>
                                    <td><span class="sp-skeleton-badge"></span></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="sp-alert sp-alert-info" id="tanggle-completions-empty" style="display:none;">
                        <strong><?= t('tanggle_no_history_label') ?></strong> <?= t('tanggle_no_history_text') ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const TANGGLE_I18N = {
    statusCompleted: <?php echo json_encode(t('tanggle_status_completed')); ?>,
    statusInProgress: <?php echo json_encode(t('tanggle_status_in_progress')); ?>,
    queuePuzzlesWord: <?php echo json_encode(t('tanggle_queue_puzzles_word')); ?>,
    queuePosition: <?php echo json_encode(t('tanggle_queue_position_label')); ?>,
    gridLabel: <?php echo json_encode(t('tanggle_grid_label')); ?>,
    piecesWord: <?php echo json_encode(t('tanggle_pieces_word')); ?>,
    byLabel: <?php echo json_encode(t('tanggle_by_label')); ?>,
    openBtn: <?php echo json_encode(t('tanggle_open_btn')); ?>,
    notAvailable: <?php echo json_encode(t('tanggle_not_available')); ?>,
    apiFetchError: <?php echo json_encode(t('tanggle_api_fetch_error')); ?>
};
const TANGGLE_HAS_CREDENTIALS = <?php echo $credentials_exist ? 'true' : 'false'; ?>;

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function setBusy(el, busy) {
    if (el) el.setAttribute('aria-busy', busy ? 'true' : 'false');
}

function renderTanggleError(message) {
    var err = document.getElementById('tanggle-api-error');
    var errText = document.getElementById('tanggle-api-error-text');
    if (err) err.style.display = '';
    if (errText) errText.textContent = message || TANGGLE_I18N.apiFetchError;
    var statsSkel = document.getElementById('tanggle-stats-skeleton');
    var puzzleSkel = document.getElementById('tanggle-puzzle-skeleton');
    var queueSkel = document.getElementById('tanggle-queue-skeleton');
    if (statsSkel) statsSkel.style.display = 'none';
    if (puzzleSkel) puzzleSkel.style.display = 'none';
    if (queueSkel) queueSkel.style.display = 'none';
    setBusy(document.getElementById('tanggle-stats-host'), false);
    setBusy(document.getElementById('tanggle-puzzle-host'), false);
    setBusy(document.getElementById('tanggle-queue-host'), false);
    var tbody = document.getElementById('tanggle-completions-tbody');
    if (tbody) {
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">' + escapeHtml(message || TANGGLE_I18N.apiFetchError) + '</td></tr>';
    }
}

function renderTanggleStats(data) {
    var skel = document.getElementById('tanggle-stats-skeleton');
    var ready = document.getElementById('tanggle-stats-ready');
    var countEl = document.getElementById('tanggle-stats-count');
    var lastEl = document.getElementById('tanggle-stats-last');
    var stats = data.puzzle_stats || {};
    if (skel) skel.style.display = 'none';
    if (countEl) countEl.textContent = String(stats.completed_count || 0);
    if (lastEl) lastEl.textContent = stats.last_completed_display || TANGGLE_I18N.notAvailable;
    if (ready) ready.style.display = 'flex';
    setBusy(document.getElementById('tanggle-stats-host'), false);
}

function renderTangglePuzzle(data) {
    var skel = document.getElementById('tanggle-puzzle-skeleton');
    var ready = document.getElementById('tanggle-puzzle-ready');
    var empty = document.getElementById('tanggle-puzzle-empty');
    if (skel) skel.style.display = 'none';
    setBusy(document.getElementById('tanggle-puzzle-host'), false);
    if (data.api_error) {
        if (ready) ready.style.display = 'none';
        if (empty) empty.style.display = 'none';
        return;
    }
    var room = data.active_room;
    if (!room) {
        if (ready) ready.style.display = 'none';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    var preview = document.getElementById('tanggle-puzzle-preview');
    var title = document.getElementById('tanggle-puzzle-title');
    var status = document.getElementById('tanggle-puzzle-status');
    var pieces = document.getElementById('tanggle-puzzle-pieces');
    var grid = document.getElementById('tanggle-puzzle-grid');
    var players = document.getElementById('tanggle-puzzle-players');
    var creatorRow = document.getElementById('tanggle-puzzle-creator-row');
    var creator = document.getElementById('tanggle-puzzle-creator');
    var link = document.getElementById('tanggle-puzzle-link');
    if (preview) {
        preview.src = room.preview_url || '';
        preview.style.display = room.preview_url ? 'block' : 'none';
    }
    if (title) title.textContent = room.title || '';
    if (status) {
        var completed = !!room.is_completed;
        status.innerHTML = '<span class="sp-badge ' + (completed ? 'sp-badge-green' : 'sp-badge-blue') + '" style="margin-left:0.35rem;">' +
            escapeHtml(completed ? TANGGLE_I18N.statusCompleted : TANGGLE_I18N.statusInProgress) + '</span>';
    }
    if (pieces) pieces.textContent = (room.pieces_completed || 0) + ' / ' + (room.pieces_count || 0) + ' (' + (room.completed_rate || 0) + '%)';
    if (grid) grid.textContent = (room.grid_x || 0) + 'x' + (room.grid_y || 0);
    if (players) players.textContent = (room.player_count || 0) + ' / ' + (room.player_limit || 0);
    if (creatorRow && creator) {
        if (room.creator) {
            creator.textContent = room.creator;
            creatorRow.style.display = '';
        } else {
            creatorRow.style.display = 'none';
        }
    }
    if (link) {
        if (room.redirect_url) {
            link.href = room.redirect_url;
            link.style.display = '';
        } else {
            link.style.display = 'none';
        }
    }
    if (ready) ready.style.display = '';
}

function renderTanggleQueue(data) {
    var host = document.getElementById('tanggle-queue-host');
    var skel = document.getElementById('tanggle-queue-skeleton');
    var ready = document.getElementById('tanggle-queue-ready');
    var count = document.getElementById('tanggle-queue-count');
    var items = Array.isArray(data.queue_items) ? data.queue_items : [];
    if (skel) skel.style.display = 'none';
    setBusy(host, false);
    if (!items.length) {
        if (host) host.style.display = 'none';
        return;
    }
    if (count) count.textContent = '(' + items.length + ' ' + TANGGLE_I18N.queuePuzzlesWord + ')';
    if (ready) {
        ready.innerHTML = items.map(function(item) {
            var creatorHtml = item.creator
                ? '<p style="font-size:0.8rem;color:var(--text-secondary);"><strong>' + escapeHtml(TANGGLE_I18N.byLabel) + '</strong> ' + escapeHtml(item.creator) + '</p>'
                : '';
            return '<div class="sp-card" style="margin-bottom:0;">' +
                '<img src="' + escapeHtml(item.preview_url || '') + '" alt="' + escapeHtml(item.alt || '') + '" style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block;">' +
                '<div style="padding:0.75rem;">' +
                '<p style="font-weight:700;color:var(--text-primary);margin-bottom:0.3rem;font-size:0.88rem;">' + escapeHtml(item.title || '') + '</p>' +
                '<p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0.3rem;">' + escapeHtml(TANGGLE_I18N.queuePosition) + ' #' + escapeHtml(item.position) + '</p>' +
                '<p style="font-size:0.8rem;color:var(--text-secondary);"><strong>' + escapeHtml(TANGGLE_I18N.gridLabel) + '</strong> ' +
                escapeHtml(item.grid_x) + 'x' + escapeHtml(item.grid_y) + ' (' + escapeHtml(item.piece_total) + ' ' + escapeHtml(TANGGLE_I18N.piecesWord) + ')</p>' +
                creatorHtml +
                '</div></div>';
        }).join('');
        ready.style.display = 'grid';
    }
    if (host) host.style.display = '';
}

function renderTanggleCompletions(data) {
    var tbody = document.getElementById('tanggle-completions-tbody');
    var wrap = document.getElementById('tanggle-completions-wrap');
    var empty = document.getElementById('tanggle-completions-empty');
    var rows = Array.isArray(data.recent_completions) ? data.recent_completions : [];
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    if (!rows.length) {
        if (wrap) wrap.style.display = 'none';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (wrap) wrap.style.display = '';
    tbody.innerHTML = rows.map(function(row) {
        var linkHtml = row.redirect_url
            ? '<a href="' + escapeHtml(row.redirect_url) + '" target="_blank" class="sp-btn sp-btn-info sp-btn-sm">' + escapeHtml(TANGGLE_I18N.openBtn) + '</a>'
            : '<span style="color:var(--text-muted);">' + escapeHtml(TANGGLE_I18N.notAvailable) + '</span>';
        return '<tr>' +
            '<td>' + escapeHtml(row.room_title) + '<br><span style="font-size:0.78rem;color:var(--text-muted);">' + escapeHtml(row.room_uuid) + '</span></td>' +
            '<td>' + escapeHtml(row.winner) + '</td>' +
            '<td>' + escapeHtml(row.piece_completed) + ' / ' + escapeHtml(row.piece_count) + '</td>' +
            '<td>' + escapeHtml(row.completed_display) + '</td>' +
            '<td>' + linkHtml + '</td>' +
            '</tr>';
    }).join('');
}

function loadTanggleList() {
    if (!TANGGLE_HAS_CREDENTIALS) return;
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderTanggleError((data && data.error) ? data.error : TANGGLE_I18N.apiFetchError);
                return;
            }
            if (data.api_error) {
                var err = document.getElementById('tanggle-api-error');
                var errText = document.getElementById('tanggle-api-error-text');
                if (err) err.style.display = '';
                if (errText) errText.textContent = data.api_error;
            }
            renderTanggleStats(data);
            renderTangglePuzzle(data);
            renderTanggleQueue(data);
            renderTanggleCompletions(data);
        })
        .catch(function() {
            renderTanggleError(TANGGLE_I18N.apiFetchError);
        });
}

document.addEventListener('DOMContentLoaded', function () {
    const apiTokenField = document.getElementById('api-token-field');
    const visibilityIcon = document.getElementById('api-token-visibility-icon');
    if (apiTokenField && visibilityIcon) {
        visibilityIcon.addEventListener('click', function () {
            if (apiTokenField.type === 'password') {
                apiTokenField.type = 'text';
                visibilityIcon.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                apiTokenField.type = 'password';
                visibilityIcon.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });
    }
    loadTanggleList();
});
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>
