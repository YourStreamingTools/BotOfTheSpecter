<?php
$page_title = "Board";
$body_class = "bg-blue-600";
$nav_width = "max-w-full";
$nav_center = '<div id="board-title" class="text-xl font-semibold"></div>';
$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>';
$extra_head = '<style>
    .board { display: flex; overflow-x: auto; padding: 20px; min-height: calc(100vh - 80px); gap: 20px; }
    .list { min-width: 300px; background: #364152; color: #fff; border-radius: 8px; padding: 15px; flex-shrink: 0; }
    .list h5 { color: #fff; }
    .card { background: white; margin-bottom: 12px; padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); cursor: grab; transition: all 0.2s; }
    .card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .card:active { cursor: grabbing; }
    .add-card { color: #5e6c84; cursor: pointer; padding: 10px; font-weight: 500; transition: all 0.2s; }
    .add-card:hover { background: rgba(0,0,0,0.1); border-radius: 4px; }
    .add-list { 
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-width: 280px;
        padding: 12px 16px;
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
        text-align: center;
        margin-top: 20px;
    }
    .add-list:hover { 
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(59, 130, 246, 0.4);
    }
    .add-list:active { 
        transform: translateY(0);
    }
    .edit-only { display: none; }
</style>';
include 'includes/header.php';
?>
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
            Swal.fire({
                title: 'Add Card',
                input: 'text',
                inputLabel: 'Card Title',
                inputPlaceholder: 'Enter card title...',
                showCancelButton: true,
                confirmButtonText: 'Add',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Please enter a card title';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('api/cards.php', JSON.stringify({ list_id: listId, title: result.value }), function(data) {
                        if (data.id) {
                            toastr.success('Card added successfully');
                            loadBoard();
                        } else {
                            toastr.error('Failed to add card');
                        }
                    }, 'json');
                }
            });
        }
        function addList() {
            Swal.fire({
                title: 'Add List',
                input: 'text',
                inputLabel: 'List Name',
                inputPlaceholder: 'Enter list name...',
                showCancelButton: true,
                confirmButtonText: 'Add',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Please enter a list name';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('api/lists.php', JSON.stringify({ board_id: boardId, name: result.value }), function(data) {
                        if (data.id) {
                            toastr.success('List added successfully');
                            loadBoard();
                        } else {
                            toastr.error('Failed to add list');
                        }
                    }, 'json');
                }
            });
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
                    $('#user-info').html('<a href="admin.php" class="text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Admin</a> | Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-blue-600 hover:text-blue-700 font-medium">Logout</a>');
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