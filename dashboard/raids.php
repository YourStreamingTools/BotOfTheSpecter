<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('navbar_raids');

// Includes
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
  header('Content-Type: application/json');
  $formatViewerAverage = static function ($value): string {
    if ($value === null || $value === '') {
      return '0';
    }
    $number = (float) $value;
    if (floor($number) == $number) {
      return (string) (int) $number;
    }
    return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
  };
  $recentReceivedRaids = [];
  $recentSentRaids = [];
  $latestSentRaid = null;
  $topRaiders = [];
  $avgViewers = null;
  try {
    $recentReceivedRes = $db->query("SELECT raider_name, viewers, created_at FROM analytic_raids WHERE source = 'received' OR source IS NULL ORDER BY created_at DESC LIMIT 25");
    if ($recentReceivedRes) {
      $recentReceivedRaids = $recentReceivedRes->fetch_all(MYSQLI_ASSOC);
    }
    $recentSentRes = $db->query("SELECT raider_name, viewers, created_at FROM analytic_raids WHERE source = 'sent' ORDER BY created_at DESC LIMIT 5");
    if ($recentSentRes) {
      $recentSentRaids = $recentSentRes->fetch_all(MYSQLI_ASSOC);
      if (!empty($recentSentRaids)) {
        $latestSentRaid = $recentSentRaids[0];
      }
    }
    $topRes = $db->query("SELECT raider_name, COUNT(*) AS raids, ROUND(AVG(viewers),1) AS avg_viewers, MAX(viewers) AS max_viewers FROM analytic_raids WHERE source = 'received' OR source IS NULL GROUP BY raider_name ORDER BY raids DESC, avg_viewers DESC LIMIT 5");
    if ($topRes) {
      $topRaiders = $topRes->fetch_all(MYSQLI_ASSOC);
      foreach ($topRaiders as &$raider) {
        $raider['avg_viewers'] = $formatViewerAverage($raider['avg_viewers'] ?? null);
      }
      unset($raider);
    }
    $avgRes = $db->query("SELECT ROUND(AVG(viewers),1) AS avg_viewers FROM analytic_raids");
    if ($avgRes) {
      $avgRow = $avgRes->fetch_assoc();
      $avgViewers = $avgRow['avg_viewers'];
    }
    echo json_encode([
      'success' => true,
      'recent_received' => $recentReceivedRaids,
      'recent_sent' => $recentSentRaids,
      'latest_sent' => $latestSentRaid,
      'top_raiders' => $topRaiders,
      'avg_viewers' => $avgViewers,
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit();
}

ob_start();
?>
<div class="sp-card mb-5">
  <header class="sp-card-header">
    <div class="sp-card-title">
      <span class="icon mr-2"><i class="fas fa-bullhorn"></i></span>
      <?= t('raids_heading') ?>
    </div>
  </header>
  <div class="sp-card-body">
    <div class="raids-layout">
      <div>
        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.85rem;"><?= t('raids_recent_received_title') ?></h3>
        <div id="receivedRaidsHost" aria-busy="true">
          <div class="sp-table-wrap">
            <table class="sp-table">
              <thead>
                <tr>
                  <th><?= t('raids_col_raider') ?></th>
                  <th><?= t('raids_col_viewers') ?></th>
                  <th><?= t('raids_col_datetime') ?></th>
                </tr>
              </thead>
              <tbody id="receivedRaidsBody">
                <?php for ($sk = 0; $sk < 6; $sk++): ?>
                <tr aria-hidden="true">
                  <td><span class="sp-skeleton-line w-60"></span></td>
                  <td><span class="sp-skeleton-line w-40"></span></td>
                  <td><span class="sp-skeleton-line w-70"></span></td>
                </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div>
        <div class="raids-section-head">
          <h3><?= t('raids_latest_sent_title') ?></h3>
          <button class="sp-btn sp-btn-info sp-btn-sm" id="showLastFiveSentRaidsBtn" disabled>
            <?= t('raids_show_last_5') ?>
          </button>
        </div>
        <div id="latestSentHost" aria-busy="true">
          <div class="sp-table-wrap">
            <table class="sp-table">
              <thead>
                <tr>
                  <th><?= t('raids_col_target') ?></th>
                  <th><?= t('raids_col_viewers') ?></th>
                  <th><?= t('raids_col_datetime') ?></th>
                </tr>
              </thead>
              <tbody id="latestSentBody">
                <tr aria-hidden="true">
                  <td><span class="sp-skeleton-line w-60"></span></td>
                  <td><span class="sp-skeleton-line w-40"></span></td>
                  <td><span class="sp-skeleton-line w-70"></span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="sp-modal-backdrop" id="lastFiveSentRaidsModal">
          <div class="sp-modal" style="max-width:min(900px,95vw);">
            <header class="sp-modal-head">
              <p class="sp-modal-title"><?= t('raids_modal_title') ?></p>
              <button class="sp-modal-close" aria-label="<?= htmlspecialchars(t('raids_close')) ?>" id="closeLastFiveSentRaidsModal">&times;</button>
            </header>
            <section class="sp-modal-body" id="lastFiveSentRaidsModalBody" aria-busy="true">
              <div class="sp-table-wrap">
                <table class="sp-table">
                  <thead>
                    <tr>
                      <th><?= t('raids_col_target') ?></th>
                      <th><?= t('raids_col_viewers') ?></th>
                      <th><?= t('raids_col_datetime') ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                      <td><span class="sp-skeleton-line w-60"></span></td>
                      <td><span class="sp-skeleton-line w-40"></span></td>
                      <td><span class="sp-skeleton-line w-70"></span></td>
                    </tr>
                    <?php endfor; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;"><?= t('raids_top_raiders_title') ?></h3>
        <div id="topRaidersHost" aria-busy="true">
          <div class="sp-skeleton-stack" aria-hidden="true">
            <span class="sp-skeleton-line w-80"></span>
            <span class="sp-skeleton-line w-70"></span>
            <span class="sp-skeleton-line w-90"></span>
            <span class="sp-skeleton-line w-60"></span>
            <span class="sp-skeleton-line w-80"></span>
          </div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">
        <h4 style="font-size:0.9rem;font-weight:700;color:var(--text-primary);margin-bottom:0.4rem;"><?= t('raids_overall_avg_title') ?></h4>
        <p id="avgViewersHost" aria-busy="true" style="font-size:1.1rem;"><span class="sp-skeleton-value" aria-hidden="true"></span></p>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const RAIDS_I18N = {
  noReceived: <?php echo json_encode(t('raids_no_received_data')); ?>,
  noSent: <?php echo json_encode(t('raids_no_sent_data')); ?>,
  noDataYet: <?php echo json_encode(t('raids_no_data_yet')); ?>,
  raidsLabel: <?php echo json_encode(t('raids_raids_label')); ?>,
  avgLabel: <?php echo json_encode(t('raids_avg_label')); ?>,
  viewersLabel: <?php echo json_encode(t('raids_viewers_label')); ?>,
  na: <?php echo json_encode(t('raids_na')); ?>,
  loadError: <?php echo json_encode(t('dashboard_js_load_error')); ?>
};

function escapeHtml(str) {
  return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
  });
}

function raidRowHtml(row) {
  return '<tr>' +
    '<td>' + escapeHtml(row.raider_name) + '</td>' +
    '<td>' + escapeHtml(row.viewers) + '</td>' +
    '<td>' + escapeHtml(row.created_at) + '</td>' +
    '</tr>';
}

function raidTableHtml(headers, rows) {
  return '<div class="sp-table-wrap"><table class="sp-table"><thead><tr>' +
    '<th>' + escapeHtml(headers[0]) + '</th>' +
    '<th>' + escapeHtml(headers[1]) + '</th>' +
    '<th>' + escapeHtml(headers[2]) + '</th>' +
    '</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function renderReceivedRaids(rows) {
  var host = document.getElementById('receivedRaidsHost');
  if (!host) return;
  host.setAttribute('aria-busy', 'false');
  if (!rows.length) {
    host.innerHTML = '<div style="text-align:center;padding:3rem 0;"><p class="sp-text-muted" style="font-size:1.1rem;">' + escapeHtml(RAIDS_I18N.noReceived) + '</p></div>';
    return;
  }
  var tbody = document.getElementById('receivedRaidsBody');
  if (tbody) {
    tbody.innerHTML = rows.map(raidRowHtml).join('');
    return;
  }
  host.innerHTML = raidTableHtml(
    [<?php echo json_encode(t('raids_col_raider')); ?>, <?php echo json_encode(t('raids_col_viewers')); ?>, <?php echo json_encode(t('raids_col_datetime')); ?>],
    rows.map(raidRowHtml).join('')
  );
}

function renderLatestSent(latest, sentRows) {
  var host = document.getElementById('latestSentHost');
  var openBtn = document.getElementById('showLastFiveSentRaidsBtn');
  if (openBtn) {
    openBtn.disabled = !sentRows.length;
  }
  if (!host) return;
  host.setAttribute('aria-busy', 'false');
  if (!latest) {
    host.innerHTML = '<p class="sp-text-muted">' + escapeHtml(RAIDS_I18N.noSent) + '</p>';
    return;
  }
  var tbody = document.getElementById('latestSentBody');
  if (tbody) {
    tbody.innerHTML = raidRowHtml(latest);
    return;
  }
  host.innerHTML = raidTableHtml(
    [<?php echo json_encode(t('raids_col_target')); ?>, <?php echo json_encode(t('raids_col_viewers')); ?>, <?php echo json_encode(t('raids_col_datetime')); ?>],
    raidRowHtml(latest)
  );
}

function renderSentModal(sentRows) {
  var body = document.getElementById('lastFiveSentRaidsModalBody');
  if (!body) return;
  body.setAttribute('aria-busy', 'false');
  if (!sentRows.length) {
    body.innerHTML = '<p class="sp-text-muted">' + escapeHtml(RAIDS_I18N.noSent) + '</p>';
    return;
  }
  body.innerHTML = raidTableHtml(
    [<?php echo json_encode(t('raids_col_target')); ?>, <?php echo json_encode(t('raids_col_viewers')); ?>, <?php echo json_encode(t('raids_col_datetime')); ?>],
    sentRows.map(raidRowHtml).join('')
  );
}

function renderTopRaiders(rows) {
  var host = document.getElementById('topRaidersHost');
  if (!host) return;
  host.setAttribute('aria-busy', 'false');
  if (!rows.length) {
    host.innerHTML = '<p class="sp-text-muted">' + escapeHtml(RAIDS_I18N.noDataYet) + '</p>';
    return;
  }
  host.innerHTML = '<ul style="padding-left:1.25rem;margin:0;">' + rows.map(function(row) {
    return '<li style="margin-bottom:0.5rem;"><strong>' + escapeHtml(row.raider_name) + '</strong> - ' +
      escapeHtml(row.raids) + ' ' + escapeHtml(RAIDS_I18N.raidsLabel) + ', ' +
      escapeHtml(RAIDS_I18N.avgLabel) + ' ' + escapeHtml(row.avg_viewers) + ' ' +
      escapeHtml(RAIDS_I18N.viewersLabel) + '</li>';
  }).join('') + '</ul>';
}

function renderAvgViewers(avgViewers) {
  var host = document.getElementById('avgViewersHost');
  if (!host) return;
  host.setAttribute('aria-busy', 'false');
  if (avgViewers === null || avgViewers === undefined || avgViewers === '') {
    host.textContent = RAIDS_I18N.na;
    return;
  }
  host.textContent = String(avgViewers) + ' ' + RAIDS_I18N.viewersLabel;
}

function renderRaidsError() {
  var received = document.getElementById('receivedRaidsHost');
  var latest = document.getElementById('latestSentHost');
  var modalBody = document.getElementById('lastFiveSentRaidsModalBody');
  var top = document.getElementById('topRaidersHost');
  var avg = document.getElementById('avgViewersHost');
  var openBtn = document.getElementById('showLastFiveSentRaidsBtn');
  if (openBtn) openBtn.disabled = true;
  if (received) {
    received.setAttribute('aria-busy', 'false');
    received.innerHTML = '<div style="text-align:center;padding:3rem 0;"><p class="sp-text-muted" style="font-size:1.1rem;">' + escapeHtml(RAIDS_I18N.loadError) + '</p></div>';
  }
  if (latest) {
    latest.setAttribute('aria-busy', 'false');
    latest.innerHTML = '<p class="sp-text-muted">' + escapeHtml(RAIDS_I18N.loadError) + '</p>';
  }
  if (modalBody) {
    modalBody.setAttribute('aria-busy', 'false');
    modalBody.innerHTML = '<p class="sp-text-muted">' + escapeHtml(RAIDS_I18N.loadError) + '</p>';
  }
  if (top) {
    top.setAttribute('aria-busy', 'false');
    top.innerHTML = '<p class="sp-text-muted">' + escapeHtml(RAIDS_I18N.loadError) + '</p>';
  }
  if (avg) {
    avg.setAttribute('aria-busy', 'false');
    avg.textContent = RAIDS_I18N.loadError;
  }
}

function loadRaids() {
  var url = new URL(window.location.pathname, window.location.origin);
  url.searchParams.set('ajax_action', 'list');
  fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.success) {
        renderRaidsError();
        return;
      }
      renderReceivedRaids(Array.isArray(data.recent_received) ? data.recent_received : []);
      renderLatestSent(data.latest_sent || null, Array.isArray(data.recent_sent) ? data.recent_sent : []);
      renderSentModal(Array.isArray(data.recent_sent) ? data.recent_sent : []);
      renderTopRaiders(Array.isArray(data.top_raiders) ? data.top_raiders : []);
      renderAvgViewers(data.avg_viewers);
    })
    .catch(function() {
      renderRaidsError();
    });
}

document.addEventListener('DOMContentLoaded', function () {
  const openBtn = document.getElementById('showLastFiveSentRaidsBtn');
  const modal = document.getElementById('lastFiveSentRaidsModal');
  const closeBtn = document.getElementById('closeLastFiveSentRaidsModal');
  if (openBtn && modal) {
    const closeModal = function () {
      modal.classList.remove('is-active');
    };
    openBtn.addEventListener('click', function () {
      if (!openBtn.disabled) {
        modal.classList.add('is-active');
      }
    });
    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeModal();
      }
    });
  }
  loadRaids();
});
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>
