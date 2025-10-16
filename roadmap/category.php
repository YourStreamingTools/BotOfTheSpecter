<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roadmap Category</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="dist/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
</head>
<body class="bg-gradient-to-br from-blue-600 to-blue-800 text-white">
    <nav class="bg-white text-gray-800 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold text-blue-600">Roadmap</a>
            <div id="user-info" class="text-sm flex items-center gap-4">
                <!-- Admin link will be inserted here by JavaScript -->
            </div>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <a href="index.php" class="inline-block bg-gray-200 text-gray-800 hover:bg-gray-300 font-semibold py-2 px-4 rounded-lg mb-6 transition-colors duration-200"><i class="fas fa-arrow-left mr-2"></i>Back to Categories</a>
        <h1 id="category-title" class="text-4xl font-bold mb-8"></h1>
        <!-- Category Stats -->
        <div id="category-stats" class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-6 mb-8 shadow-lg hidden">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="text-center">
                    <div class="text-3xl font-bold text-green-400 mb-1" id="stat-completed">0</div>
                    <div class="text-sm">Completed</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-blue-400 mb-1" id="stat-total">0</div>
                    <div class="text-sm">Total Items</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-purple-400 mb-1" id="stat-percentage">0%</div>
                    <div class="text-sm">Complete</div>
                </div>
                <div>
                    <div class="w-full bg-gray-700 rounded-full h-3 mt-2">
                        <div id="progress-bar" class="bg-gradient-to-r from-blue-500 to-purple-500 h-3 rounded-full transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const categoryId = new URLSearchParams(window.location.search).get('id');
        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                if (data.admin) {
                    $('#user-info').html('<a href="admin.php" class="text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Admin</a> | Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else if (data.logged_in) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" class="text-blue-600 hover:text-blue-700 font-medium">Login</a>');
                }
                loadStats();
                loadCategory();
            });
        }
        function loadStats() {
            $.get(`api/category-stats.php?id=${categoryId}`, function(data) {
                if (data && data.total_cards > 0) {
                    $('#stat-completed').text(data.completed_cards);
                    $('#stat-total').text(data.total_cards);
                    $('#stat-percentage').text(data.percentage + '%');
                    $('#progress-bar').css('width', data.percentage + '%');
                    $('#category-stats').removeClass('hidden');
                }
            });
        }
        function loadCategory() {
            $.get(`api/category.php?id=${categoryId}`, function(data) {
                if (data.error) {
                    toastr.error(data.error);
                    return;
                }
                $('#category-title').text(data.name);
                // If board exists, redirect to it
                if (data.board && data.board.id) {
                    window.location.href = `board.php?id=${data.board.id}`;
                } else {
                    toastr.error('No board found for this category');
                }
            });
        }
        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>