<?php
session_start();
$page_title = "Admin Panel";
$body_class = "bg-gradient-to-br from-blue-600 to-blue-800 text-white";
$nav_width = "max-w-7xl";
include 'includes/header.php';
?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <h1 class="text-4xl font-bold mb-2">Admin Panel</h1>
        <p class="text-blue-200 mb-8">Database Management & Schema Validation</p>
        <!-- Status Overview -->
        <div class="mb-8 bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-lg">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold flex items-center">
                    <i class="fas fa-database mr-3"></i>Database Status
                </h2>
                <button onclick="checkDatabase()" class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg transition-colors duration-200">
                    <i class="fas fa-sync-alt mr-2"></i>Check Database
                </button>
            </div>
            <div id="status-overview" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-white bg-opacity-10 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-blue-300 mb-2" id="table-count">-</div>
                    <div class="text-sm">Tables</div>
                </div>
                <div class="bg-white bg-opacity-10 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-green-400 mb-2" id="ok-count">-</div>
                    <div class="text-sm">Healthy</div>
                </div>
                <div class="bg-white bg-opacity-10 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-red-400 mb-2" id="issue-count">-</div>
                    <div class="text-sm">Issues Found</div>
                </div>
                <div class="bg-white bg-opacity-10 rounded-lg p-4 text-center">
                    <div id="status-badge" class="text-lg font-bold">
                        <i class="fas fa-circle text-gray-400"></i> Checking...
                    </div>
                </div>
            </div>
        </div>
        <!-- Issues Section -->
        <div id="issues-section" class="mb-8 bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-lg hidden">
            <h2 class="text-2xl font-bold mb-6 flex items-center text-red-300">
                <i class="fas fa-exclamation-triangle mr-3"></i>Database Issues
            </h2>
            <div id="issues-list" class="space-y-2 mb-6">
                <!-- Issues will be populated here -->
            </div>
            <button onclick="fixDatabase()" class="bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-lg transition-colors duration-200">
                <i class="fas fa-tools mr-2"></i>Auto-Fix Database
            </button>
        </div>
        <!-- Migration Section -->
        <div class="mb-8 bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-lg">
            <h2 class="text-2xl font-bold mb-6 flex items-center text-yellow-300">
                <i class="fas fa-exchange-alt mr-3"></i>Data Migration
            </h2>
            <p class="text-blue-200 mb-4">Create boards for existing categories that don't have them yet.</p>
            <button onclick="migrateCategories()" class="bg-yellow-500 hover:bg-yellow-600 text-white font-semibold py-3 px-6 rounded-lg transition-colors duration-200">
                <i class="fas fa-sync-alt mr-2"></i>Migrate Existing Categories
            </button>
            <div id="migration-status" class="mt-4 hidden">
                <!-- Migration status will appear here -->
            </div>
        </div>
        <!-- Data Cleanup Section -->
        <div class="mb-8 bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-lg">
            <h2 class="text-2xl font-bold mb-6 flex items-center text-red-300">
                <i class="fas fa-trash-alt mr-3"></i>Data Cleanup
            </h2>
            <p class="text-blue-200 mb-4">Remove test or unwanted data from the database.</p>
            <div class="space-y-3">
                <div>
                    <button onclick="clearCompletedItems()" class="bg-red-500 hover:bg-red-600 text-white font-semibold py-2 px-6 rounded-lg transition-colors duration-200">
                        <i class="fas fa-trash mr-2"></i>Clear All Completed Items
                    </button>
                    <p class="text-xs text-blue-300 mt-2">Remove all cards from the "Completed" list</p>
                </div>
            </div>
        </div>
        <!-- Tables Details -->
        <div class="mb-8 bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-lg">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-table mr-3"></i>Table Details
            </h2>
            <div id="tables-container" class="space-y-6">
                <!-- Table details will be populated here -->
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                if (data.admin) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                    checkDatabase();
                } else {
                    window.location.href = 'index.php';
                }
            });
        }
        function checkDatabase() {
            $.ajax({
                url: 'api/db-admin.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data.error) {
                        toastr.error(data.error);
                        return;
                    }
                    // Update overview
                    const healthyCount = data.tables.filter(t => t.exists && t.missing_columns.length === 0).length;
                    $('#table-count').text(data.tables.length);
                    $('#ok-count').text(healthyCount);
                    $('#issue-count').text(data.issues.length);
                    // Update status badge
                    if (data.all_ok) {
                        $('#status-badge').html('<i class="fas fa-circle text-green-400"></i> All Good');
                        $('#issues-section').addClass('hidden');
                    } else {
                        $('#status-badge').html('<i class="fas fa-circle text-red-400"></i> Issues Found');
                        $('#issues-section').removeClass('hidden');
                    }
                    // Populate issues
                    if (data.issues.length > 0) {
                        const issuesList = data.issues.map(issue => 
                            `<div class="bg-red-500 bg-opacity-20 border-l-4 border-red-400 p-3 rounded">
                                <i class="fas fa-times-circle mr-2 text-red-400"></i>${issue}
                            </div>`
                        ).join('');
                        $('#issues-list').html(issuesList);
                    }
                    // Populate tables
                    const tablesHtml = data.tables.map(table => {
                        const statusIcon = table.exists 
                            ? (table.missing_columns.length === 0 ? '<i class="fas fa-check-circle text-green-400"></i>' : '<i class="fas fa-exclamation-circle text-yellow-400"></i>')
                            : '<i class="fas fa-times-circle text-red-400"></i>';
                        const columnsHtml = table.columns.length > 0
                            ? `<div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-2">
                                ${table.columns.map(col => 
                                    `<div class="bg-gray-700 bg-opacity-30 p-2 rounded text-sm">
                                        <strong>${col.name}</strong><br>
                                        <span class="text-blue-200">${col.type}</span>
                                    </div>`
                                ).join('')}
                              </div>`
                            : '';
                        const missingHtml = table.missing_columns.length > 0
                            ? `<div class="mt-3 p-3 bg-red-500 bg-opacity-20 border-l-4 border-red-400 rounded">
                                <strong class="text-red-300">Missing Columns:</strong><br>
                                ${table.missing_columns.join(', ')}
                              </div>`
                            : '';
                        return `
                            <div class="bg-white bg-opacity-5 border border-white border-opacity-10 rounded-lg p-6">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-xl font-bold flex items-center">
                                        ${statusIcon}
                                        <span class="ml-2">${table.name}</span>
                                    </h3>
                                    <span class="text-sm ${table.exists ? 'text-green-300' : 'text-red-300'}">
                                        ${table.exists ? 'Exists' : 'Missing'}
                                    </span>
                                </div>
                                <div class="text-sm text-blue-200 mb-2">${table.columns.length} columns</div>
                                ${columnsHtml}
                                ${missingHtml}
                            </div>
                        `;
                    }).join('');
                    $('#tables-container').html(tablesHtml);
                },
                error: function(xhr, status, error) {
                    toastr.error('Error checking database');
                }
            });
        }
        function fixDatabase() {
            Swal.fire({
                title: 'Fix Database?',
                text: 'This will create missing tables and add missing columns.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, fix it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api/db-admin.php',
                        type: 'POST',
                        dataType: 'json',
                        success: function(data) {
                            if (data.error) {
                                toastr.error(data.error);
                                return;
                            }
                            if (data.created.length > 0) {
                                toastr.success('Created tables: ' + data.created.join(', '));
                            }
                            if (data.altered.length > 0) {
                                toastr.info('Altered tables: ' + data.altered.join(', '));
                            }
                            if (data.errors.length > 0) {
                                data.errors.forEach(err => toastr.error(err));
                            }
                            // Refresh status
                            setTimeout(() => checkDatabase(), 1000);
                        },
                        error: function(xhr, status, error) {
                            toastr.error('Error fixing database: ' + error);
                        }
                    });
                }
            });
        }
        function migrateCategories() {
            Swal.fire({
                title: 'Migrate Categories?',
                text: 'This will create missing boards for existing categories.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#eab308',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, migrate!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const statusDiv = $('#migration-status');
                    statusDiv.removeClass('hidden').html('<div class="bg-blue-500 bg-opacity-20 border-l-4 border-blue-400 p-3 rounded"><i class="fas fa-sync-alt mr-2 animate-spin"></i>Running migration...</div>');
                    $.ajax({
                        url: 'api/migrate-categories.php',
                        type: 'POST',
                        dataType: 'json',
                        success: function(data) {
                            if (data.error) {
                                statusDiv.html('<div class="bg-red-500 bg-opacity-20 border-l-4 border-red-400 p-3 rounded"><i class="fas fa-times-circle mr-2"></i>' + data.error + '</div>');
                                toastr.error(data.error);
                                return;
                            }
                            let html = '<div class="bg-green-500 bg-opacity-20 border-l-4 border-green-400 p-3 rounded mb-3"><i class="fas fa-check-circle mr-2"></i>Created <strong>' + data.created_count + '</strong> board(s)</div>';
                            if (data.errors.length > 0) {
                                html += '<div class="bg-yellow-500 bg-opacity-20 border-l-4 border-yellow-400 p-3 rounded"><strong>Errors:</strong><br>' + data.errors.join('<br>') + '</div>';
                            }
                            statusDiv.html(html);
                            toastr.success('Migration complete! Created ' + data.created_count + ' board(s)');
                        },
                        error: function(xhr, status, error) {
                            statusDiv.html('<div class="bg-red-500 bg-opacity-20 border-l-4 border-red-400 p-3 rounded"><i class="fas fa-times-circle mr-2"></i>Error: ' + error + '</div>');
                            toastr.error('Error running migration: ' + error);
                        }
                    });
                }
            });
        }
        function clearCompletedItems() {
            Swal.fire({
                title: 'Clear All Completed Items?',
                text: 'This will permanently delete all cards in the "Completed" list. This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, clear them!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api/clear-completed-items.php',
                        type: 'POST',
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                toastr.success(data.message);
                            } else {
                                toastr.error(data.error || 'Failed to clear completed items');
                            }
                        },
                        error: function(xhr, status, error) {
                            toastr.error('Error clearing completed items: ' + error);
                        }
                    });
                }
            });
        }
        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>
