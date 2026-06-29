<?php
// Initialize the session
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('todolist_dashboard_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '../includes/userdata.php';
include '../includes/bot_control.php';
include "../includes/mod_access.php";
include '../includes/user_db.php';
include '../includes/storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

require_once __DIR__ . '/category_filter.php';

$categoryFilter = parse_todo_category_filter();

$categories_sql = "SELECT * FROM categories ORDER BY id ASC";
$categories_stmt = $db->prepare($categories_sql);
$categories_stmt->execute();
$categories_result = $categories_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT t.*, c.category AS category_name FROM todos t LEFT JOIN categories c ON t.category = c.id";
$whereParts = [];
$bindTypes = '';
$bindParams = [];

$categorySqlFilter = todo_category_sql_filter($categoryFilter);
if ($categorySqlFilter !== null) {
  $whereParts[] = $categorySqlFilter['sql'];
  $bindTypes .= $categorySqlFilter['types'];
  $bindParams = array_merge($bindParams, $categorySqlFilter['params']);
}

if (!empty($whereParts)) {
  $sql .= ' WHERE ' . implode(' AND ', $whereParts);
}
$sql .= ' ORDER BY t.id ASC';

$stmt = $db->prepare($sql);
if ($bindTypes !== '') {
  $stmt->bind_param($bindTypes, ...$bindParams);
}
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$num_rows = count($result);

ob_start();
?>
<div class="sp-card">
  <div class="sp-card-header">
    <div class="sp-card-title"><i class="fas fa-list-check"></i> <?= t('todo_index_your_tasks') ?></div>
  </div>
  <div class="sp-card-body">
    <div style="display:flex; align-items:flex-end; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap;">
      <div style="flex:1; min-width:200px;">
        <label for="searchInput" class="sp-label"><?= t('todo_index_search_objectives') ?></label>
        <input class="sp-input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="<?= htmlspecialchars(t('todo_index_search_placeholder')) ?>">
      </div>
      <?php render_todo_category_filter($categories_result, $categoryFilter, 'index.php', 'todo_index_filter_by_category', 'todo_index_category_all'); ?>
    </div>
    <?php if ($num_rows < 1): ?>
      <div class="sp-alert sp-alert-info">
        <i class="fas fa-tasks" style="margin-right:0.5rem;"></i>
        <?= t('todo_index_empty_message') ?>
      </div>
    <?php else: ?>
      <p style="margin-bottom:1rem; color:var(--text-secondary);"><?= t('todo_index_total_tasks_label') ?> <?php echo $num_rows; ?></p>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem;" id="taskCardList">
        <?php foreach ($result as $row): ?>
          <div class="sp-card" style="margin-bottom:0;">
            <div class="sp-card-body">
              <p style="font-weight:600; margin-bottom:0.4rem;">
                <?php
                  $objective = htmlspecialchars($row['objective']);
                  echo ($row['completed'] == 'Yes') ? '<s>' . $objective . '</s>' : $objective;
                ?>
              </p>
              <p style="font-size:0.8rem; margin-bottom:0.4rem; color:var(--text-secondary); display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                <i class="fas fa-folder"></i>
                <?php echo htmlspecialchars($row['category_name'] ?? t('todo_index_uncategorized')); ?>
                <?php echo ($row['completed'] === 'Yes')
                  ? '<span class="sp-badge sp-badge-green">' . htmlspecialchars(t('todo_index_badge_completed')) . '</span>'
                  : '<span class="sp-badge sp-badge-amber">' . htmlspecialchars(t('todo_index_badge_not_completed')) . '</span>'; ?>
                <?php if (!empty($row['private']) && $row['private'] == 1): ?>
                  <span class="sp-badge sp-badge-red"><i class="fas fa-eye-slash" style="margin-right:0.2rem;"></i><?= htmlspecialchars(t('todo_index_badge_private')) ?></span>
                <?php endif; ?>
              </p>
              <p style="font-size:0.8rem; display:flex; align-items:center; gap:0.3rem; color:var(--text-secondary); margin-bottom:0.2rem;">
                <i class="fas fa-calendar-plus"></i>
                <?= t('todo_index_created_label') ?> <span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['created_at']); ?>"><?php echo htmlspecialchars($row['created_at']); ?></span>
              </p>
              <p style="font-size:0.8rem; display:flex; align-items:center; gap:0.3rem; color:var(--text-secondary); margin-bottom:0;">
                <i class="fas fa-calendar-pen"></i>
                <?= t('todo_index_updated_label') ?> <span class="timestamp" data-timestamp="<?php echo htmlspecialchars($row['updated_at']); ?>"><?php echo htmlspecialchars($row['updated_at']); ?></span>
              </p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="../js/search.js?v=<?php echo filemtime(__DIR__ . '/../js/search.js'); ?>"></script>
<script>
  const RELATIVE_TIME_STRINGS = {
    second: <?php echo json_encode(t('todo_index_time_second')); ?>,
    seconds: <?php echo json_encode(t('todo_index_time_seconds')); ?>,
    minute: <?php echo json_encode(t('todo_index_time_minute')); ?>,
    minutes: <?php echo json_encode(t('todo_index_time_minutes')); ?>,
    hour: <?php echo json_encode(t('todo_index_time_hour')); ?>,
    hours: <?php echo json_encode(t('todo_index_time_hours')); ?>,
    day: <?php echo json_encode(t('todo_index_time_day')); ?>,
    days: <?php echo json_encode(t('todo_index_time_days')); ?>,
    month: <?php echo json_encode(t('todo_index_time_month')); ?>,
    months: <?php echo json_encode(t('todo_index_time_months')); ?>,
    year: <?php echo json_encode(t('todo_index_time_year')); ?>,
    years: <?php echo json_encode(t('todo_index_time_years')); ?>,
    ago: <?php echo json_encode(t('todo_index_time_ago')); ?>
  };
  function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    if (diff < 60) return diff + ' ' + (diff !== 1 ? RELATIVE_TIME_STRINGS.seconds : RELATIVE_TIME_STRINGS.second) + ' ' + RELATIVE_TIME_STRINGS.ago;
    const minutes = Math.floor(diff / 60);
    if (minutes < 60) return minutes + ' ' + (minutes !== 1 ? RELATIVE_TIME_STRINGS.minutes : RELATIVE_TIME_STRINGS.minute) + ' ' + RELATIVE_TIME_STRINGS.ago;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + ' ' + (hours !== 1 ? RELATIVE_TIME_STRINGS.hours : RELATIVE_TIME_STRINGS.hour) + ' ' + RELATIVE_TIME_STRINGS.ago;
    const days = Math.floor(hours / 24);
    if (days < 30) return days + ' ' + (days !== 1 ? RELATIVE_TIME_STRINGS.days : RELATIVE_TIME_STRINGS.day) + ' ' + RELATIVE_TIME_STRINGS.ago;
    const months = Math.floor(days / 30);
    if (months < 12) return months + ' ' + (months !== 1 ? RELATIVE_TIME_STRINGS.months : RELATIVE_TIME_STRINGS.month) + ' ' + RELATIVE_TIME_STRINGS.ago;
    const years = Math.floor(days / 365);
    return years + ' ' + (years !== 1 ? RELATIVE_TIME_STRINGS.years : RELATIVE_TIME_STRINGS.year) + ' ' + RELATIVE_TIME_STRINGS.ago;
  }
  function updateTimestamps() {
    const elements = document.querySelectorAll('.timestamp');
    elements.forEach(el => {
      const timestamp = el.getAttribute('data-timestamp');
      el.textContent = formatTimestamp(timestamp);
    });
  }
  setInterval(updateTimestamps, 1000);
  document.addEventListener('DOMContentLoaded', updateTimestamps);
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>