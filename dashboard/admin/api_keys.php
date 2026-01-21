<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = "API Key Management";
require_once "/var/www/config/db_connect.php";
include "../userdata.php";

// Handle API key creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_key'])) {
    $service = trim($_POST['service']);
    $success = false;
    $message = '';
    if (empty($service)) {
        $message = 'Service name cannot be empty';
    } else {
        // Generate a random 32-character API key
        $api_key = bin2hex(random_bytes(16));
        try {
            $stmt = $conn->prepare("INSERT INTO admin_api_keys (service, api_key) VALUES (?, ?)");
            $stmt->bind_param("ss", $service, $api_key);
            if ($stmt->execute()) {
                $success = true;
                $message = 'API key created successfully';
            } else {
                $message = 'Failed to create API key: ' . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'api_key' => $success ? $api_key : null]);
    exit;
}

// Handle API key deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_key'])) {
    $service = trim($_POST['service']);
    $success = false;
    $message = '';
    if (empty($service)) {
        $message = 'Service name cannot be empty';
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM admin_api_keys WHERE service = ?");
            $stmt->bind_param("s", $service);
            
            if ($stmt->execute()) {
                $success = true;
                $message = 'API key deleted successfully';
            } else {
                $message = 'Failed to delete API key: ' . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Handle API key regeneration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['regenerate_key'])) {
    $service = trim($_POST['service']);
    $success = false;
    $message = '';
    if (empty($service)) {
        $message = 'Service name cannot be empty';
    } else {
        // Generate a new random 32-character API key
        $api_key = bin2hex(random_bytes(16));
        try {
            $stmt = $conn->prepare("UPDATE admin_api_keys SET api_key = ? WHERE service = ?");
            $stmt->bind_param("ss", $api_key, $service);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = true;
                $message = 'API key regenerated successfully';
            } else {
                $message = 'Failed to regenerate API key or service not found';
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'api_key' => $success ? $api_key : null]);
    exit;
}

// Fetch all API keys
$api_keys = [];
if ($conn) {
    $result = $conn->query("SELECT service, api_key FROM admin_api_keys ORDER BY service");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $api_keys[] = $row;
        }
        $result->free();
    }
}

ob_start();
?>
<div class="box">
    <h1 class="title">API Key Management</h1>
    <p class="subtitle">Manage admin API keys for different services</p>
</div>
<div class="box">
    <h2 class="title is-4">Create New API Key</h2>
    <form id="createKeyForm">
        <div class="field">
            <label class="label">Service Name</label>
            <div class="control">
                <input class="input" type="text" name="service" placeholder="Enter service name" required>
            </div>
            <p class="help">Enter a unique name for the service that will use this API key</p>
        </div>
        <div class="field">
            <div class="control">
                <button type="submit" class="button is-primary">
                    <span class="icon">
                        <i class="fas fa-plus"></i>
                    </span>
                    <span>Generate API Key</span>
                </button>
            </div>
        </div>
    </form>
</div>
<div class="box">
    <h2 class="title is-4">Existing API Keys</h2>
    <?php if (empty($api_keys)): ?>
        <div class="notification is-info">
            No API keys found. Create one above.
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="table is-fullwidth is-striped is-hoverable">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>API Key</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="apiKeysTable">
                    <?php foreach ($api_keys as $key): ?>
                        <tr data-service="<?php echo htmlspecialchars($key['service']); ?>">
                            <td><strong><?php echo htmlspecialchars($key['service']); ?></strong></td>
                            <td>
                                <div class="field has-addons">
                                    <div class="control is-expanded">
                                        <input class="input is-family-monospace api-key-input" type="text" value="<?php echo htmlspecialchars($key['api_key']); ?>" readonly autocomplete="off" style="-webkit-text-security: disc;">
                                    </div>
                                    <div class="control">
                                        <button class="button is-info toggle-visibility" title="Show/Hide">
                                            <span class="icon">
                                                <i class="fas fa-eye"></i>
                                            </span>
                                        </button>
                                    </div>
                                    <div class="control">
                                        <button class="button is-success copy-key" title="Copy to Clipboard">
                                            <span class="icon">
                                                <i class="fas fa-copy"></i>
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="buttons">
                                    <button class="button is-warning is-small regenerate-key" data-service="<?php echo htmlspecialchars($key['service']); ?>">
                                        <span class="icon">
                                            <i class="fas fa-sync"></i>
                                        </span>
                                        <span>Regenerate</span>
                                    </button>
                                    <button class="button is-danger is-small delete-key" data-service="<?php echo htmlspecialchars($key['service']); ?>">
                                        <span class="icon">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                        <span>Delete</span>
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
document.addEventListener('DOMContentLoaded', function() {
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    // Function to attach event listeners to row buttons
    function attachRowEventListeners(row) {
        // Toggle visibility
        const toggleBtn = row.querySelector('.toggle-visibility');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const input = this.closest('.field').querySelector('input');
                const icon = this.querySelector('i');
                if (input.style.webkitTextSecurity === 'disc') {
                    input.style.webkitTextSecurity = 'none';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.style.webkitTextSecurity = 'disc';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        }
        // Copy key
        const copyBtn = row.querySelector('.copy-key');
        if (copyBtn) {
            copyBtn.addEventListener('click', async function() {
                const input = this.closest('.field').querySelector('input');
                try {
                    await navigator.clipboard.writeText(input.value);
                    const originalIcon = this.querySelector('i');
                    originalIcon.classList.remove('fa-copy');
                    originalIcon.classList.add('fa-check');
                    setTimeout(() => {
                        originalIcon.classList.remove('fa-check');
                        originalIcon.classList.add('fa-copy');
                    }, 2000);
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to copy to clipboard'
                    });
                }
            });
        }
        // Regenerate key
        const regenerateBtn = row.querySelector('.regenerate-key');
        if (regenerateBtn) {
            regenerateBtn.addEventListener('click', handleRegenerateKey);
        }
        // Delete key
        const deleteBtn = row.querySelector('.delete-key');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', handleDeleteKey);
        }
    }
    // Handle regenerate key
    async function handleRegenerateKey() {
        const service = this.dataset.service;
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Regenerate API Key?',
            text: `Are you sure you want to regenerate the API key for "${service}"? The old key will stop working immediately.`,
            showCancelButton: true,
            confirmButtonText: 'Yes, regenerate it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#f39c12',
            cancelButtonColor: '#3085d6'
        });
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('regenerate_key', '1');
            formData.append('service', service);
            try {
                const response = await fetch('api_keys.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'API Key Regenerated',
                        html: `
                            <p>${data.message}</p>
                            <div class="field">
                                <label class="label">Your new API key:</label>
                                <div class="control">
                                    <input class="input is-family-monospace" type="text" value="${data.api_key}" readonly>
                                </div>
                                <p class="help has-text-danger mt-2">Make sure to copy this key now. You won't be able to see it again!</p>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    });
                    // Update the key in the table row
                    const row = document.querySelector(`tr[data-service="${service}"]`);
                    if (row) {
                        const input = row.querySelector('input.is-family-monospace');
                        if (input) {
                            input.value = data.api_key;
                            input.type = 'password';
                            
                            // Reset the eye icon if it was showing
                            const eyeIcon = row.querySelector('.toggle-visibility i');
                            if (eyeIcon) {
                                eyeIcon.classList.remove('fa-eye-slash');
                                eyeIcon.classList.add('fa-eye');
                            }
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
                    text: 'An error occurred while regenerating the API key'
                });
            }
        }
    }
    // Handle delete key
    async function handleDeleteKey() {
        const service = this.dataset.service;
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Delete API Key?',
            text: `Are you sure you want to delete the API key for "${service}"? This action cannot be undone.`,
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#f14668',
            cancelButtonColor: '#3085d6'
        });
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('delete_key', '1');
            formData.append('service', service);
            try {
                const response = await fetch('api_keys.php', {
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
                    // Remove the row from the table
                    const row = document.querySelector(`tr[data-service="${service}"]`);
                    if (row) {
                        row.remove();
                        // Check if table is now empty
                        const tableBody = document.getElementById('apiKeysTable');
                        if (tableBody && tableBody.children.length === 0) {
                            const box = tableBody.closest('.box');
                            if (box) {
                                box.innerHTML = `
                                    <h2 class="title is-4">Existing API Keys</h2>
                                    <div class="notification is-info">
                                        No API keys found. Create one above.
                                    </div>
                                `;
                            }
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
                    text: 'An error occurred while deleting the API key'
                });
            }
        }
    }
    // Handle create key form submission
    const createForm = document.getElementById('createKeyForm');
    createForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData();
        formData.append('create_key', '1');
        const service = createForm.querySelector('[name="service"]').value;
        formData.append('service', service);
        try {
            const response = await fetch('api_keys.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                await Swal.fire({
                    icon: 'success',
                    title: 'API Key Created',
                    html: `
                        <p>${result.message}</p>
                        <div class="field">
                            <label class="label">Your new API key:</label>
                            <div class="control">
                                <input class="input is-family-monospace" type="text" value="${result.api_key}" readonly>
                            </div>
                            <p class="help has-text-info mt-2">You can view this key anytime from the table below.</p>
                        </div>
                    `,
                    showConfirmButton: true,
                    confirmButtonText: 'OK'
                });
                // Check if table exists or if we need to create it
                let tableBody = document.getElementById('apiKeysTable');
                const noKeysNotification = document.querySelector('.notification.is-info');
                if (noKeysNotification && noKeysNotification.textContent.includes('No API keys found')) {
                    // Replace notification with table
                    const box = noKeysNotification.closest('.box');
                    noKeysNotification.remove();
                    box.innerHTML = `
                        <h2 class="title is-4">Existing API Keys</h2>
                        <div class="table-container">
                            <table class="table is-fullwidth is-striped is-hoverable">
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th>API Key</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="apiKeysTable"></tbody>
                            </table>
                        </div>
                    `;
                    tableBody = document.getElementById('apiKeysTable');
                }
                // Create new row
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-service', service);
                newRow.innerHTML = `
                    <td><strong>${escapeHtml(service)}</strong></td>
                    <td>
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input class="input is-family-monospace api-key-input" type="text" value="${result.api_key}" readonly autocomplete="off" style="-webkit-text-security: disc;">
                            </div>
                            <div class="control">
                                <button class="button is-info toggle-visibility" title="Show/Hide">
                                    <span class="icon">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </button>
                            </div>
                            <div class="control">
                                <button class="button is-success copy-key" title="Copy to Clipboard">
                                    <span class="icon">
                                        <i class="fas fa-copy"></i>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="buttons">
                            <button class="button is-warning is-small regenerate-key" data-service="${escapeHtml(service)}">
                                <span class="icon">
                                    <i class="fas fa-sync"></i>
                                </span>
                                <span>Regenerate</span>
                            </button>
                            <button class="button is-danger is-small delete-key" data-service="${escapeHtml(service)}">
                                <span class="icon">
                                    <i class="fas fa-trash"></i>
                                </span>
                                <span>Delete</span>
                            </button>
                        </div>
                    </td>
                `;
                tableBody.appendChild(newRow);
                // Attach event listeners to new row
                attachRowEventListeners(newRow);
                // Clear form
                createForm.reset();
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
                text: 'An error occurred while creating the API key'
            });
        }
    });
    // Initialize event listeners for existing rows
    document.querySelectorAll('#apiKeysTable tr').forEach(row => {
        attachRowEventListeners(row);
    });
});
</script>
<?php
$content = ob_get_clean();
include "admin_layout.php";
?>