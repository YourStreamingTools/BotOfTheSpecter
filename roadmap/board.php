<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roadmap Board</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css">
    <style>
        body { background: #0079bf; }
        .board { display: flex; overflow-x: auto; padding: 20px; min-height: calc(100vh - 56px); }
        .list { min-width: 300px; margin-right: 20px; background: #ebecf0; border-radius: 3px; padding: 10px; }
        .card { background: white; margin-bottom: 10px; padding: 10px; border-radius: 3px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); cursor: pointer; }
        .add-card, .add-list { color: #5e6c84; cursor: pointer; padding: 10px; }
        .add-list { background: rgba(0,0,0,0.1); border-radius: 3px; width: 300px; text-align: center; }
        .edit-only { display: none; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">Roadmap</a>
            <div id="board-title" class="navbar-text"></div>
            <div id="user-info" class="navbar-text ms-auto"></div>
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
                    alert(data.error);
                    return;
                }
                $('#board-title').text(data.name);
                $('#board').empty();
                data.lists.forEach(list => {
                    const listEl = $(`
                        <div class="list" data-id="${list.id}">
                            <h5>${list.name}</h5>
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
                    $('#user-info').html('Logged in as ' + data.username + ' (Admin) | <a href="logout.php">Logout</a>');
                    $('.edit-only').show();
                } else if (loggedIn) {
                    $('#user-info').html('Logged in as ' + data.username + ' | <a href="logout.php">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php">Login</a>');
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