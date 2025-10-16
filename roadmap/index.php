<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Roadmap</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="dist/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gradient-to-br from-blue-600 to-blue-800 text-white">
    <nav class="bg-white text-gray-800 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <div class="flex items-center gap-6">
                <a href="index.php" class="text-2xl font-bold text-blue-600">Roadmap</a>
                <a href="index.php" class="text-gray-800 hover:text-blue-600 font-medium transition-colors duration-200">
                    <i class="fas fa-home mr-1"></i>HOME
                </a>
            </div>
            <div id="user-info" class="text-sm flex items-center gap-4">
                <!-- Admin link will be inserted here by JavaScript -->
            </div>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <h1 class="text-center text-4xl font-bold mb-12">BotOfTheSpecter Roadmap</h1>
        <!-- Completion Summary Section -->
        <div class="mb-12 bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-lg">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-chart-pie mr-3"></i>Overall Progress
            </h2>
            <div id="overall-stats" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Stats will be loaded here -->
            </div>
        </div>
        <!-- Categories Grid -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold">Categories</h2>
            <button id="add-category-btn" onclick="openCreateCategoryModal()" class="hidden bg-green-500 hover:bg-green-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200">
                <i class="fas fa-plus mr-2"></i>New Category
            </button>
        </div>
        <div id="categories" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
            <!-- Categories will be loaded here -->
        </div>
        <!-- Completed Items Section -->
        <div id="completed-section" class="hidden">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-check-circle mr-3 text-green-400"></i>Recently Completed
            </h2>
            <div id="completed-items" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                <!-- Completed items will be loaded here -->
            </div>
        </div>
        <!-- Beta Testing Items Section -->
        <div id="beta-section" class="hidden">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-flask mr-3 text-yellow-400"></i>Testing in Beta
            </h2>
            <div id="beta-items" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                <!-- Beta items will be loaded here -->
            </div>
        </div>
        <!-- Create Category Modal -->
        <div id="create-category-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white text-gray-800 rounded-lg p-8 max-w-md w-full mx-4">
                <h3 class="text-2xl font-bold mb-6">Create New Category</h3>
                <form id="create-category-form" onsubmit="createCategory(event)">
                    <div class="mb-4">
                        <label class="block text-sm font-semibold mb-2">Category Name</label>
                        <input type="text" id="category-name" name="name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" placeholder="e.g., Bot Features" required>
                    </div>
                    <div class="mb-6">
                        <label class="block text-sm font-semibold mb-2">Description</label>
                        <textarea id="category-description" name="description" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" placeholder="Brief description of this category" rows="3"></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-check mr-2"></i>Create
                        </button>
                        <button type="button" onclick="closeCreateCategoryModal()" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                if (data.admin) {
                    $('#user-info').html('<a href="admin.php" class="text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Admin</a> | Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                    $('#add-category-btn').removeClass('hidden');
                } else if (data.logged_in) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" class="text-blue-600 hover:text-blue-700 font-medium">Login</a>');
                }
                loadStats();
                loadCategories();
                loadCompletedItems();
                loadBetaItems();
            });
        }
        function loadStats() {
            $.ajax({
                url: 'api/category-stats.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data && data.length > 0) {
                        let totalCards = 0;
                        let totalCompleted = 0;
                        data.forEach(cat => {
                            totalCards += cat.total_cards;
                            totalCompleted += cat.completed_cards;
                        });
                        const overallPercentage = totalCards > 0 ? Math.round((totalCompleted / totalCards) * 100) : 0;
                        $('#overall-stats').empty().html(`
                            <div class="text-center">
                                <div class="text-5xl font-bold text-green-400 mb-2">${totalCompleted}</div>
                                <div class="text-lg">Items Completed</div>
                            </div>
                            <div class="text-center">
                                <div class="text-5xl font-bold text-blue-300 mb-2">${totalCards}</div>
                                <div class="text-lg">Total Items</div>
                            </div>
                            <div class="text-center">
                                <div class="text-5xl font-bold text-purple-300 mb-2">${overallPercentage}%</div>
                                <div class="text-lg">Complete</div>
                                <div class="w-full bg-gray-700 rounded-full h-2 mt-3">
                                    <div class="bg-purple-500 h-2 rounded-full" style="width: ${overallPercentage}%"></div>
                                </div>
                            </div>
                        `);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading stats:', error);
                }
            });
        }
        function loadCategories() {
            $.ajax({
                url: 'api/categories.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    $('#categories').empty();
                    if (data && data.length > 0) {
                        data.forEach(category => {
                            $.get(`api/category-stats.php?id=${category.id}`, function(stats) {
                                const progressColor = stats.percentage >= 75 ? 'text-green-400' : stats.percentage >= 50 ? 'text-yellow-400' : stats.percentage >= 25 ? 'text-orange-400' : 'text-red-400';
                                
                                $('#categories').append(`
                                    <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 hover:bg-opacity-20 transition-all duration-300 shadow-lg">
                                        <h3 class="text-xl font-bold mb-3">${category.name}</h3>
                                        <p class="text-blue-100 mb-4">${category.description}</p>
                                        
                                        <div class="mb-6 pb-4 border-b border-white border-opacity-20">
                                            <div class="flex justify-between items-center mb-2">
                                                <span class="text-sm">Progress: <strong class="${progressColor}">${stats.percentage}%</strong></span>
                                                <span class="text-sm text-blue-200">${stats.completed_cards}/${stats.total_cards}</span>
                                            </div>
                                            <div class="w-full bg-gray-700 rounded-full h-2">
                                                <div class="bg-gradient-to-r from-blue-500 to-purple-500 h-2 rounded-full transition-all duration-500" style="width: ${stats.percentage}%"></div>
                                            </div>
                                        </div>
                                        
                                        <a href="category.php?id=${category.id}" class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200 w-full text-center">
                                            <i class="fas fa-arrow-right mr-2"></i>View Boards
                                        </a>
                                    </div>
                                `);
                            });
                        });
                    } else {
                        $('#categories').append('<p class="text-center text-blue-100 col-span-full">No categories found. Please check back later.</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading categories:', error);
                    console.error('Response:', xhr.responseText);
                    $('#categories').append('<p class="text-center text-red-300 col-span-full">Error loading categories: ' + (xhr.responseJSON ? xhr.responseJSON.error : error) + '</p>');
                }
            });
        }
        function loadCompletedItems() {
            $.ajax({
                url: 'api/completed-items.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data && data.length > 0) {
                        $('#completed-section').removeClass('hidden');
                        $('#completed-items').empty();
                        
                        data.forEach(item => {
                            $('#completed-items').append(`
                                <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-6 hover:bg-opacity-20 transition-all duration-300 shadow-lg border-l-4 border-green-400">
                                    <div class="flex items-start justify-between mb-2">
                                        <h4 class="text-lg font-bold">${item.card_title}</h4>
                                        <i class="fas fa-check-circle text-green-400 text-xl"></i>
                                    </div>
                                    <p class="text-sm text-blue-200 mb-2">${item.board_name}</p>
                                    <p class="text-xs text-blue-300"><i class="fas fa-folder mr-1"></i>${item.category_name}</p>
                                </div>
                            `);
                        });
                    } else {
                        $('#completed-section').addClass('hidden');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading completed items:', error);
                }
            });
        }
        function loadBetaItems() {
            $.ajax({
                url: 'api/beta-items.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data && data.length > 0) {
                        $('#beta-section').removeClass('hidden');
                        $('#beta-items').empty();
                        
                        data.forEach(item => {
                            $('#beta-items').append(`
                                <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-6 hover:bg-opacity-20 transition-all duration-300 shadow-lg border-l-4 border-yellow-400">
                                    <div class="flex items-start justify-between mb-2">
                                        <h4 class="text-lg font-bold">${item.card_title}</h4>
                                        <i class="fas fa-flask text-yellow-400 text-xl"></i>
                                    </div>
                                    <p class="text-sm text-blue-200 mb-2">${item.board_name}</p>
                                    <p class="text-xs text-blue-300"><i class="fas fa-folder mr-1"></i>${item.category_name}</p>
                                </div>
                            `);
                        });
                    } else {
                        $('#beta-section').addClass('hidden');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading beta items:', error);
                }
            });
        }
        function openCreateCategoryModal() {
            $('#create-category-modal').removeClass('hidden');
            $('#category-name').focus();
        }
        function closeCreateCategoryModal() {
            $('#create-category-modal').addClass('hidden');
            $('#create-category-form')[0].reset();
        }
        function createCategory(event) {
            event.preventDefault();
            const name = $('#category-name').val().trim();
            const description = $('#category-description').val().trim();
            if (!name) {
                toastr.error('Please enter a category name');
                return;
            }
            $.ajax({
                url: 'api/create-category.php',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    name: name,
                    description: description
                }),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        toastr.success('Category created successfully!');
                        closeCreateCategoryModal();
                        loadCategories();
                        loadStats();
                    } else {
                        toastr.error(data.error || 'Failed to create category');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error creating category:', error);
                    const errorMsg = xhr.responseJSON ? xhr.responseJSON.error : error;
                    toastr.error('Error creating category: ' + errorMsg);
                }
            });
        }
        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>