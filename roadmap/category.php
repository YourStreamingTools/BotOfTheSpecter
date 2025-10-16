<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roadmap Category</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
</head>
<body class="bg-gradient-to-br from-blue-600 to-blue-800 text-white">
    <nav class="bg-white text-gray-800 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold text-blue-600">Roadmap</a>
            <div id="user-info" class="text-sm"></div>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <a href="index.php" class="inline-block bg-gray-200 text-gray-800 hover:bg-gray-300 font-semibold py-2 px-4 rounded-lg mb-6 transition-colors duration-200">← Back to Categories</a>
        <h1 id="category-title" class="text-4xl font-bold mb-8"></h1>
        <div id="boards" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <!-- Boards will be loaded here -->
        </div>
        <div id="edit-controls" class="hidden">
            <button onclick="createBoard()" class="bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-lg transition-colors duration-200 text-lg">+ Create New Board</button>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const categoryId = new URLSearchParams(window.location.search).get('id');

        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                if (data.admin) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else if (data.logged_in) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" class="text-blue-600 hover:text-blue-700 font-medium">Login</a>');
                }
                loadCategory();
            });
        }

        function loadCategory() {
            $.get(`api/category.php?id=${categoryId}`, function(data) {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                $('#category-title').text(data.name);
                $('#boards').empty();
                data.boards.forEach(board => {
                    $('#boards').append(`
                        <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 hover:bg-opacity-20 transition-all duration-300 shadow-lg">
                            <h3 class="text-xl font-bold mb-4">${board.name}</h3>
                            <a href="board.php?id=${board.id}" class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200">View Board</a>
                        </div>
                    `);
                });
                // Show edit if admin
                if (data.admin) {
                    $('#edit-controls').removeClass('hidden');
                }
            });
        }

        function createBoard() {
            const name = prompt('Enter board name:');
            if (name) {
                $.post('api/boards.php', JSON.stringify({ name: name, category_id: categoryId }), function(data) {
                    if (data.id) {
                        loadCategory();
                    }
                }, 'json');
            }
        }

        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>