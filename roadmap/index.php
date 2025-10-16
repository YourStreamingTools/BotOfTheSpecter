<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter Roadmap</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0079bf; color: white; }
        .category-card { background: rgba(255,255,255,0.1); border-radius: 8px; padding: 20px; margin: 10px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">Roadmap</a>
            <div id="user-info" class="navbar-text ms-auto"></div>
        </div>
    </nav>
    <div class="container mt-5">
        <h1 class="text-center mb-4">BotOfTheSpecter Roadmap</h1>
        <div id="categories" class="row">
            <!-- Categories will be loaded here -->
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                if (data.admin) {
                    $('#user-info').html('Logged in as ' + data.username + ' (Admin) | <a href="logout.php" style="color: #0079bf;">Logout</a>');
                } else if (data.logged_in) {
                    $('#user-info').html('Logged in as ' + data.username + ' | <a href="logout.php" style="color: #0079bf;">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" style="color: #0079bf;">Login</a>');
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
                                <div class="col-md-4">
                                    <div class="category-card">
                                        <h3>${category.name}</h3>
                                        <p>${category.description}</p>
                                        <a href="category.php?id=${category.id}" class="btn btn-primary">View Boards</a>
                                    </div>
                                </div>
                            `);
                        });
                    } else {
                        $('#categories').append('<p class="text-center">No categories found. Please check back later.</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading categories:', error);
                    console.error('Response:', xhr.responseText);
                    $('#categories').append('<p class="text-center text-danger">Error loading categories: ' + (xhr.responseJSON ? xhr.responseJSON.error : error) + '</p>');
                }
            });
        }
        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>