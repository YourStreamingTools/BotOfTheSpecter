<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roadmap Board</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <style>
        .board { display: flex; overflow-x: auto; padding: 20px; min-height: calc(100vh - 80px); gap: 20px; }
        .list { min-width: 300px; background: #ebecf0; border-radius: 8px; padding: 15px; flex-shrink: 0; }
        .card { background: white; margin-bottom: 12px; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); cursor: grab; transition: all 0.2s; }
        .card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .card:active { cursor: grabbing; }
        .add-card, .add-list { color: #5e6c84; cursor: pointer; padding: 10px; font-weight: 500; transition: all 0.2s; }
        .add-card:hover, .add-list:hover { background: rgba(0,0,0,0.05); border-radius: 4px; }
        .add-list { background: rgba(0,0,0,0.08); border-radius: 6px; width: 300px; text-align: center; }
        .edit-only { display: none; }
    </style>
</head>
<body class="bg-blue-600">
    <nav class="bg-white text-gray-800 shadow-lg">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold text-blue-600">Roadmap</a>
            <div id="board-title" class="text-xl font-semibold"></div>
            <div id="user-info" class="text-sm"></div>
        </div>
    </nav>
    <div class="board" id="board">
        <!-- Lists will be loaded here -->
    </div>
    <div class="add-list edit-only" onclick="addList()">+ Add a list</div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        const boardId = new URLSearchParams(window.location.search).get('id');
        let loggedIn = false;
        if (!boardId) {
            window.location.href = 'index.php';
        }
        function loadBoard() {
            $.get(`api/board.php?id=${boardId}`, function(data) {
                if (data.error) {
                    toastr.error(data.error);
                    return;
                }
                $('#board-title').text(data.name);
                $('#board').empty();
                data.lists.forEach(list => {
                    const listEl = $(`
                        <div class="list" data-id="${list.id}">
                            <h5 class="font-bold text-gray-800 mb-3 cursor-move">${list.name}</h5>
                            <div class="cards" data-list-id="${list.id}">
                                ${list.cards.map(card => `<div class="card" data-id="${card.id}">${card.title}</div>`).join('')}
                            </div>
                            <div class="add-card edit-only" onclick="addCard(${list.id})">+ Add a card</div>
                        </div>
                    `);
                    $('#board').append(listEl);
                    if (loggedIn) {
                        // Make cards sortable
                        new Sortable(listEl.find('.cards')[0], {
                            group: 'cards',
                            onEnd: function(evt) {
                                const cardId = evt.item.dataset.id;
                                const newListId = evt.to.dataset.listId;
                                const newIndex = Array.from(evt.to.children).indexOf(evt.item);
                                updateCardPosition(cardId, newListId, newIndex);
                            }
                        });
                    }
                });
                if (loggedIn) {
                    // Make lists sortable
                    new Sortable(document.getElementById('board'), {
                        handle: '.list h5',
                        onEnd: function(evt) {
                            const listId = evt.item.dataset.id;
                            const newIndex = Array.from(evt.to.children).indexOf(evt.item);
                            updateListPosition(listId, newIndex);
                        }
                    });
                }
            });
        }
        function addCard(listId) {
            const title = prompt('Enter card title:');
            if (title) {
                $.post('api/cards.php', JSON.stringify({ list_id: listId, title: title }), function(data) {
                    if (data.id) {
                        loadBoard();
                    }
                }, 'json');
            }
        }
        function addList() {
            const name = prompt('Enter list name:');
            if (name) {
                $.post('api/lists.php', JSON.stringify({ board_id: boardId, name: name }), function(data) {
                    if (data.id) {
                        loadBoard();
                    }
                }, 'json');
            }
        }
        function updateCardPosition(cardId, listId, position) {
            $.post('api/update.php', JSON.stringify({ type: 'move_card', card_id: cardId, list_id: listId, position: position }), function(data) {
                // Handle response
            }, 'json');
        }
        function updateListPosition(listId, position) {
            $.post('api/update.php', JSON.stringify({ type: 'move_list', list_id: listId, position: position }), function(data) {
                // Handle response
            }, 'json');
        }
        function checkLogin() {
            $.get('api/login_status.php', function(data) {
                loggedIn = data.logged_in;
                if (data.admin) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                    $('.edit-only').show();
                } else if (loggedIn) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" class="text-blue-600 hover:text-blue-700 font-medium">Login</a>');
                }
                loadBoard();
            });
        }
        $(document).ready(function() {
            checkLogin();
        });
    </script>
</body>
</html>