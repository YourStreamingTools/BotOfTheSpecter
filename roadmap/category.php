<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roadmap Category</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0079bf; color: white; }
        .board-card { background: rgba(255,255,255,0.1); border-radius: 8px; padding: 20px; margin: 10px; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <a href="index.php" class="btn btn-light mb-3">Back to Categories</a>
        <h1 id="category-title"></h1>
        <div id="boards" class="row">
            <!-- Boards will be loaded here -->
        </div>
        <div id="edit-controls" style="display: none;">
            <button class="btn btn-success mt-3" onclick="createBoard()">Create New Board</button>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const categoryId = new URLSearchParams(window.location.search).get('id');

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
                        <div class="col-md-4">
                            <div class="board-card">
                                <h3>${board.name}</h3>
                                <a href="board.php?id=${board.id}" class="btn btn-primary">View Board</a>
                            </div>
                        </div>
                    `);
                });
                // Show edit if admin
                if (data.admin) {
                    $('#edit-controls').show();
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
            loadCategory();
        });
    </script>
</body>
</html>