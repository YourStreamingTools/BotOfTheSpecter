<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: text/html; charset=utf-8');
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = "Spam Pattern Management";
require_once "/var/www/config/db_connect.php";
$spam_db_name = 'spam_pattern';
$spam_conn = new mysqli($servername, $username, $password, $spam_db_name);
if ($spam_conn->connect_error) {
    die("Connection failed: " . $spam_conn->connect_error);
}
$spam_conn->set_charset("utf8mb4");
include "../userdata.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_pattern'])) {
    $pattern = trim($_POST['pattern']);
    $success = false;
    $message = '';
    if (empty($pattern)) {
        $message = 'Pattern cannot be empty';
    } else {
        try {
            $stmt = $spam_conn->prepare("INSERT INTO spam_patterns (spam_pattern) VALUES (?)");
            $stmt->bind_param("s", $pattern);
            if ($stmt->execute()) {
                $success = true;
                $message = 'Spam pattern added successfully';
                $new_id = $stmt->insert_id;
            } else {
                $message = 'Failed to add pattern: ' . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'id' => $success ? $new_id : null], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_pattern'])) {
    $id = intval($_POST['id']);
    $pattern = trim($_POST['pattern']);
    $success = false;
    $message = '';
    if (empty($pattern)) {
        $message = 'Pattern cannot be empty';
    } elseif ($id <= 0) {
        $message = 'Invalid pattern ID';
    } else {
        try {
            $stmt = $spam_conn->prepare("UPDATE spam_patterns SET spam_pattern = ? WHERE id = ?");
            $stmt->bind_param("si", $pattern, $id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = true;
                $message = 'Pattern updated successfully';
            } else {
                $message = 'Failed to update pattern or no changes made';
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_pattern'])) {
    $id = intval($_POST['id']);
    $success = false;
    $message = '';
    if ($id <= 0) {
        $message = 'Invalid pattern ID';
    } else {
        try {
            $stmt = $spam_conn->prepare("DELETE FROM spam_patterns WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success = true;
                $message = 'Pattern deleted successfully';
            } else {
                $message = 'Failed to delete pattern: ' . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$spam_patterns = [];
if ($spam_conn) {
    $result = $spam_conn->query("SELECT id, spam_pattern FROM spam_patterns ORDER BY id");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $spam_patterns[] = $row;
        }
        $result->free();
    }
}

ob_start();
?>
<div class="box">
    <h1 class="title">Spam Pattern Management</h1>
    <p class="subtitle">Manage spam patterns for chat moderation</p>
    <div class="notification is-info is-light">
        <p><strong>Note:</strong> These patterns are used to detect and block spam messages in chat. Patterns may
            contain special Unicode characters to match obfuscated spam.</p>
    </div>
</div>
<div class="box">
    <h2 class="title is-4">Add New Spam Pattern</h2>
    <form id="addPatternForm">
        <div class="field">
            <label class="label">Spam Pattern</label>
            <div class="control">
                <input class="input" type="text" name="pattern" placeholder="Enter spam pattern to detect" required>
            </div>
            <p class="help">Enter the text pattern that should be flagged as spam. Special characters are preserved.</p>
        </div>
        <div class="field">
            <div class="control">
                <button type="submit" class="button is-primary">
                    <span class="icon">
                        <i class="fas fa-plus"></i>
                    </span>
                    <span>Add Pattern</span>
                </button>
            </div>
        </div>
    </form>
</div>
<div class="box">
    <h2 class="title is-4">Spam Patterns (<?php echo count($spam_patterns); ?>)</h2>
    <?php if (empty($spam_patterns)): ?>
        <div class="notification is-info">
            No spam patterns found. Add one above.
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-striped is-hoverable">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Spam Pattern</th>
                        <th style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="patternsTable">
                    <?php foreach ($spam_patterns as $pattern): ?>
                        <tr data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td><strong>#<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td>
                                <span
                                    class="pattern-display"><?php echo htmlspecialchars($pattern['spam_pattern'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <input class="input pattern-edit" type="text"
                                    value="<?php echo htmlspecialchars($pattern['spam_pattern'], ENT_QUOTES, 'UTF-8'); ?>"
                                    style="display: none;">
                            </td>
                            <td>
                                <div class="buttons action-buttons">
                                    <button class="button is-info is-small edit-pattern"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-edit"></i>
                                        </span>
                                        <span>Edit</span>
                                    </button>
                                    <button class="button is-danger is-small delete-pattern"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                        <span>Delete</span>
                                    </button>
                                </div>
                                <div class="buttons edit-buttons" style="display: none;">
                                    <button class="button is-success is-small save-pattern"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-check"></i>
                                        </span>
                                        <span>Save</span>
                                    </button>
                                    <button class="button is-light is-small cancel-edit"
                                        data-id="<?php echo htmlspecialchars($pattern['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="icon">
                                            <i class="fas fa-times"></i>
                                        </span>
                                        <span>Cancel</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        const addForm = document.getElementById('addPatternForm');
        addForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('add_pattern', '1');
            const pattern = addForm.querySelector('[name="pattern"]').value;
            formData.append('pattern', pattern);
            try {
                const response = await fetch('spam_patterns.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Pattern Added',
                        text: result.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    let tableBody = document.getElementById('patternsTable');
                    const noPatternNotification = document.querySelector('.notification.is-info');
                    if (noPatternNotification && noPatternNotification.textContent.includes('No spam patterns found')) {
                        const box = noPatternNotification.closest('.box');
                        noPatternNotification.remove();
                        box.innerHTML = `
                        <h2 class="title is-4">Spam Patterns (1)</h2>
                        <div class="table-container">
                            <table class="table is-fullwidth is-striped is-hoverable">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">ID</th>
                                        <th>Spam Pattern</th>
                                        <th style="width: 200px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="patternsTable"></tbody>
                            </table>
                        </div>
                    `;
                        tableBody = document.getElementById('patternsTable');
                    }
                    const newRow = document.createElement('tr');
                    newRow.setAttribute('data-id', result.id);
                    newRow.innerHTML = `
                    <td><strong>#${result.id}</strong></td>
                    <td>
                        <span class="pattern-display">${escapeHtml(pattern)}</span>
                        <input class="input pattern-edit" type="text" value="${escapeHtml(pattern)}" style="display: none;">
                    </td>
                    <td>
                        <div class="buttons action-buttons">
                            <button class="button is-info is-small edit-pattern" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-edit"></i></span>
                                <span>Edit</span>
                            </button>
                            <button class="button is-danger is-small delete-pattern" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-trash"></i></span>
                                <span>Delete</span>
                            </button>
                        </div>
                        <div class="buttons edit-buttons" style="display: none;">
                            <button class="button is-success is-small save-pattern" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-check"></i></span>
                                <span>Save</span>
                            </button>
                            <button class="button is-light is-small cancel-edit" data-id="${result.id}">
                                <span class="icon"><i class="fas fa-times"></i></span>
                                <span>Cancel</span>
                            </button>
                        </div>
                    </td>
                `;
                    tableBody.appendChild(newRow);
                    attachRowEventListeners(newRow);
                    addForm.reset();
                    const titleElement = document.querySelector('.box:last-child .title');
                    const currentCount = tableBody.children.length;
                    titleElement.textContent = `Spam Patterns (${currentCount})`;
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: result.message
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while adding the pattern'
                });
            }
        });
        function attachRowEventListeners(row) {
            const editBtn = row.querySelector('.edit-pattern');
            const deleteBtn = row.querySelector('.delete-pattern');
            const saveBtn = row.querySelector('.save-pattern');
            const cancelBtn = row.querySelector('.cancel-edit');
            if (editBtn) {
                editBtn.addEventListener('click', function () {
                    const patternDisplay = row.querySelector('.pattern-display');
                    const patternEdit = row.querySelector('.pattern-edit');
                    const actionButtons = row.querySelector('.action-buttons');
                    const editButtons = row.querySelector('.edit-buttons');
                    patternDisplay.style.display = 'none';
                    patternEdit.style.display = 'block';
                    actionButtons.style.display = 'none';
                    editButtons.style.display = 'flex';
                    patternEdit.focus();
                });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    const patternDisplay = row.querySelector('.pattern-display');
                    const patternEdit = row.querySelector('.pattern-edit');
                    const actionButtons = row.querySelector('.action-buttons');
                    const editButtons = row.querySelector('.edit-buttons');
                    patternEdit.value = patternDisplay.textContent;
                    patternDisplay.style.display = 'inline';
                    patternEdit.style.display = 'none';
                    actionButtons.style.display = 'flex';
                    editButtons.style.display = 'none';
                });
            }
            if (saveBtn) {
                saveBtn.addEventListener('click', async function () {
                    const id = this.dataset.id;
                    const patternEdit = row.querySelector('.pattern-edit');
                    const newPattern = patternEdit.value.trim();
                    if (!newPattern) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Pattern cannot be empty'
                        });
                        return;
                    }
                    const formData = new FormData();
                    formData.append('update_pattern', '1');
                    formData.append('id', id);
                    formData.append('pattern', newPattern);
                    try {
                        const response = await fetch('spam_patterns.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            const patternDisplay = row.querySelector('.pattern-display');
                            const actionButtons = row.querySelector('.action-buttons');
                            const editButtons = row.querySelector('.edit-buttons');
                            patternDisplay.textContent = newPattern;
                            patternDisplay.style.display = 'inline';
                            patternEdit.style.display = 'none';
                            actionButtons.style.display = 'flex';
                            editButtons.style.display = 'none';
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated',
                                text: result.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: result.message
                            });
                        }
                    } catch (error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while updating the pattern'
                        });
                    }
                });
            }
            if (deleteBtn) {
                deleteBtn.addEventListener('click', async function () {
                    const id = this.dataset.id;
                    const patternText = row.querySelector('.pattern-display').textContent;
                    const result = await Swal.fire({
                        icon: 'warning',
                        title: 'Delete Pattern?',
                        text: `Are you sure you want to delete pattern #${id}: "${patternText}"?`,
                        showCancelButton: true,
                        confirmButtonText: 'Yes, delete it',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#f14668',
                        cancelButtonColor: '#3085d6'
                    });
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        formData.append('delete_pattern', '1');
                        formData.append('id', id);
                        try {
                            const response = await fetch('spam_patterns.php', {
                                method: 'POST',
                                body: formData
                            });
                            const data = await response.json();
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted',
                                    text: data.message,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                row.remove();
                                const tableBody = document.getElementById('patternsTable');
                                const titleElement = document.querySelector('.box:last-child .title');
                                const currentCount = tableBody.children.length;
                                titleElement.textContent = `Spam Patterns (${currentCount})`;
                                if (tableBody && tableBody.children.length === 0) {
                                    const box = tableBody.closest('.box');
                                    if (box) {
                                        box.innerHTML = `
                                        <h2 class="title is-4">Spam Patterns (0)</h2>
                                        <div class="notification is-info">
                                            No spam patterns found. Add one above.
                                        </div>
                                    `;
                                    }
                                }
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: data.message
                                });
                            }
                        } catch (error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'An error occurred while deleting the pattern'
                            });
                        }
                    }
                });
            }
        }
        document.querySelectorAll('#patternsTable tr').forEach(row => {
            attachRowEventListeners(row);
        });
    });
</script>
<?php
$spam_conn->close();
$content = ob_get_clean();
include "admin_layout.php";
?>