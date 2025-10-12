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
    <div class="container mt-5">
        <h1 class="text-center mb-4">BotOfTheSpecter Roadmap</h1>
        <div id="categories" class="row">
            <!-- Categories will be loaded here -->
        </div>
        <div class="text-center mt-4">
            <a href="login.php" class="btn btn-light">Login to Edit</a>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function loadCategories() {
            $.get('api/categories.php', function(data) {
                $('#categories').empty();
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
            });
        }

        $(document).ready(function() {
            loadCategories();
        });
    </script>
</body>
</html>
