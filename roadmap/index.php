<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Roadmap</title>
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
        <h1 class="text-center text-4xl font-bold mb-8">BotOfTheSpecter Roadmap</h1>
        <div id="categories" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Categories will be loaded here -->
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                if (data.admin) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else if (data.logged_in) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" class="text-blue-600 hover:text-blue-700 font-medium">Login</a>');
                }
                loadCategories();
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
                            $('#categories').append(`
                                <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 hover:bg-opacity-20 transition-all duration-300 shadow-lg">
                                    <h3 class="text-xl font-bold mb-3">${category.name}</h3>
                                    <p class="text-blue-100 mb-6">${category.description}</p>
                                    <a href="category.php?id=${category.id}" class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200">View Boards</a>
                                </div>
                            `);
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
        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>