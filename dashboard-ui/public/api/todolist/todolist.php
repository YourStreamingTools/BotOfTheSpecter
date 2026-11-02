<?php
require_once dirname(__DIR__) . '/_live.php';
// To Do List for the beta dashboard: the same per-user tables as dashboard/todolist/*.php
// (todos, categories, showobs), with the live pages' actions as one JSON endpoint.
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once '/var/www/dashboard/lang/i18n.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/dashboard/includes/userdata.php';
include "/var/www/dashboard/includes/mod_access.php";
include '/var/www/dashboard/includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

const TODO_FONTS = ['Arial', 'Arial Narrow', 'Verdana', 'Times New Roman'];
const TODO_COLORS = ['Black', 'White', 'Red', 'Blue'];
const TODO_LISTS = ['Bullet', 'Numbered'];

function todo_out(array $body, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit();
}
function todo_fail(string $msg, int $code = 200): void { todo_out(['success' => false, 'error' => $msg], $code); }
function todo_text(string $key, int $max): string {
    $v = trim((string) ($_POST[$key] ?? ''));
    return ($v === '' || mb_strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/u', $v)) ? '' : $v;
}
function todo_id(string $key): int {
    $v = (string) ($_POST[$key] ?? '');
    return preg_match('/^\d{1,10}$/', $v) ? (int) $v : 0;
}
function todo_category_exists(mysqli $db, int $id): bool {
    $st = $db->prepare('SELECT 1 FROM categories WHERE id = ?'); $st->bind_param('i', $id); $st->execute();
    $ok = $st->get_result()->num_rows > 0; $st->close(); return $ok;
}
function todo_task_exists(mysqli $db, int $id): bool {
    $st = $db->prepare('SELECT 1 FROM todos WHERE id = ?'); $st->bind_param('i', $id); $st->execute();
    $ok = $st->get_result()->num_rows > 0; $st->close(); return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') todo_fail('Unknown action.', 400);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add' || $action === 'edit') {
            $objective = todo_text('objective', 255);
            if ($objective === '') todo_fail('Enter a task of 1 to 255 characters on one line.');
            $category = todo_id('category');
            if (!todo_category_exists($db, $category)) todo_fail('Choose a category.');
            $private = ($_POST['private'] ?? '') === '1' ? 1 : 0;
            if ($action === 'add') {
                $st = $db->prepare("INSERT INTO todos (objective, category, created_at, updated_at, completed, private) VALUES (?, ?, NOW(), NOW(), 'No', ?)");
                $st->bind_param('sii', $objective, $category, $private);
                $st->execute(); $st->close();
                todo_out(['success' => true, 'message' => 'Task added.']);
            }
            $id = todo_id('id');
            if (!todo_task_exists($db, $id)) todo_fail('Task not found.', 404);
            $st = $db->prepare('UPDATE todos SET objective = ?, category = ?, private = ? WHERE id = ?');
            $st->bind_param('siii', $objective, $category, $private, $id);
            $st->execute(); $st->close();
            todo_out(['success' => true, 'message' => 'Task updated.']);
        }
        if ($action === 'complete' || $action === 'delete') {
            $id = todo_id('id');
            if (!todo_task_exists($db, $id)) todo_fail('Task not found.', 404);
            $st = $db->prepare($action === 'complete' ? "UPDATE todos SET completed = 'Yes' WHERE id = ?" : 'DELETE FROM todos WHERE id = ?');
            $st->bind_param('i', $id);
            $st->execute(); $st->close();
            todo_out(['success' => true, 'message' => $action === 'complete' ? 'Task marked as completed.' : 'Task removed.']);
        }
        if ($action === 'add_category') {
            $name = todo_text('category', 255);
            if ($name === '') todo_fail('Enter a category name of 1 to 255 characters.');
            $st = $db->prepare('SELECT 1 FROM categories WHERE category = ?'); $st->bind_param('s', $name); $st->execute();
            $taken = $st->get_result()->num_rows > 0; $st->close();
            if ($taken) todo_fail('That category already exists.');
            $st = $db->prepare('INSERT INTO categories (category) VALUES (?)');
            $st->bind_param('s', $name);
            $st->execute(); $st->close();
            todo_out(['success' => true, 'message' => 'Category added.']);
        }
        if ($action === 'delete_category') {
            // Same as categories.php: Default (id 1) stays, and the category's tasks move to it.
            $id = todo_id('id');
            if ($id === 1) todo_fail('The Default category cannot be removed.');
            if (!todo_category_exists($db, $id)) todo_fail('Category not found.', 404);
            if (!todo_category_exists($db, 1)) $db->query("INSERT INTO categories (id, category) VALUES (1, 'Default')");
            $st = $db->prepare('UPDATE todos SET category = 1 WHERE category = ?'); $st->bind_param('i', $id); $st->execute(); $st->close();
            $st = $db->prepare('DELETE FROM categories WHERE id = ?'); $st->bind_param('i', $id); $st->execute(); $st->close();
            todo_out(['success' => true, 'message' => 'Category removed. Its tasks moved to Default.']);
        }
        if ($action === 'save_obs') {
            $font = (string) ($_POST['font'] ?? '');
            if (!in_array($font, TODO_FONTS, true)) todo_fail('Choose a font.');
            $color = trim((string) ($_POST['color'] ?? ''));
            if (!in_array($color, TODO_COLORS, true) && !preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color)) todo_fail('Colour must be Black, White, Red, Blue or a hex code like #ff8800.');
            $list = (string) ($_POST['list'] ?? '');
            if (!in_array($list, TODO_LISTS, true)) todo_fail('Choose a list style.');
            $size = (string) ($_POST['font_size'] ?? '');
            if (!preg_match('/^\d{1,2}$/', $size) || (int) $size < 8 || (int) $size > 72) todo_fail('Font size must be 8 to 72.');
            $size = (int) $size;
            $shadow = ($_POST['shadow'] ?? '') === '1' ? 1 : 0;
            $bold = ($_POST['bold'] ?? '') === '1' ? 1 : 0;
            $showCompleted = ($_POST['show_completed'] ?? '') === '1' ? 1 : 0;
            $has = $db->query('SELECT 1 FROM showobs LIMIT 1')->num_rows > 0;
            $st = $db->prepare($has
                ? 'UPDATE showobs SET font = ?, color = ?, list = ?, shadow = ?, bold = ?, font_size = ?, show_completed = ? LIMIT 1'
                : 'INSERT INTO showobs (font, color, list, shadow, bold, font_size, show_completed) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $st->bind_param('sssiiii', $font, $color, $list, $shadow, $bold, $size, $showCompleted);
            $st->execute(); $st->close();
            todo_out(['success' => true, 'message' => 'Overlay settings saved.']);
        }
    } catch (Throwable $e) {
        error_log('beta todolist ' . $action . ': ' . $e->getMessage());
        todo_fail('Something went wrong. Please try again.');
    }
    todo_fail('Unknown action.', 400);
}

try {
    $categories = $db->query('SELECT id, category FROM categories ORDER BY id ASC')->fetch_all(MYSQLI_ASSOC);
    $tasks = $db->query("SELECT t.id, t.objective, t.category, t.completed, t.private, UNIX_TIMESTAMP(t.created_at) AS created_at, UNIX_TIMESTAMP(t.updated_at) AS updated_at
        FROM todos t ORDER BY t.id ASC")->fetch_all(MYSQLI_ASSOC);
    $obs = $db->query('SELECT font, color, list, shadow, bold, font_size, show_completed FROM showobs LIMIT 1')->fetch_assoc();
} catch (Throwable $e) {
    error_log('beta todolist list: ' . $e->getMessage());
    todo_fail('Could not load your to do list.');
}
todo_out([
    'success' => true,
    'categories' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => (string) $c['category']], $categories),
    'tasks' => array_map(fn($t) => [
        'id' => (int) $t['id'],
        'objective' => (string) $t['objective'],
        'category' => (int) $t['category'],
        'completed' => $t['completed'] === 'Yes',
        'private' => (int) $t['private'] === 1,
        'created_at' => (int) $t['created_at'],
        'updated_at' => (int) $t['updated_at'],
    ], $tasks),
    'obs' => [
        'font' => (string) ($obs['font'] ?? 'Arial'),
        'color' => (string) ($obs['color'] ?? 'Black'),
        'list' => (string) ($obs['list'] ?? 'Bullet'),
        'shadow' => !empty($obs['shadow']),
        'bold' => !empty($obs['bold']),
        'font_size' => (int) ($obs['font_size'] ?? 22),
        'show_completed' => isset($obs['show_completed']) ? (int) $obs['show_completed'] === 1 : true,
    ],
]);
