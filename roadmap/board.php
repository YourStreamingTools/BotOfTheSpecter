<?php
$page_title = "Board";
$body_class = "bg-blue-600";
$nav_width = "max-w-full";
$nav_center = '
    <div class="flex items-center gap-4">
        <div id="board-title" class="text-xl font-semibold"></div>
        <button id="add-list-btn" class="edit-only hidden bg-gradient-to-r from-blue-400 to-blue-500 hover:from-blue-500 hover:to-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-all duration-200 text-sm" onclick="addList()">
            <i class="fas fa-plus mr-2"></i>Add List
        </button>
    </div>
';
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
    .edit-only { display: none; }
</style>';
include 'includes/header.php';
?>
    <div class="board" id="board">
        <!-- Lists will be loaded here -->
    </div>
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
                // Sort lists to ensure Completed is last
                const order = ['Upcoming', 'Upcoming/Pending', 'In Progress', 'Beta', 'Completed'];
                data.lists.sort((a, b) => {
                    const aIndex = order.indexOf(a.name);
                    const bIndex = order.indexOf(b.name);
                    return (aIndex === -1 ? 999 : aIndex) - (bIndex === -1 ? 999 : bIndex);
                });
                data.lists.forEach(list => {
                    const listEl = $(`
                        <div class="list" data-id="${list.id}">
                            <h5 class="font-bold text-gray-800 mb-3">${list.name}</h5>
                            <div class="cards" data-list-id="${list.id}">
                                ${list.cards.map(card => `<div class="card" data-id="${card.id}">${card.title}</div>`).join('')}
                            </div>
                            <div class="add-card edit-only" onclick="addCard(${list.id})">+ Add a card</div>
                        </div>
                    `);
                    $('#board').append(listEl);
                    // Always initialize Sortable for cards
                    new Sortable(listEl.find('.cards')[0], {
                        group: 'cards',
                        onEnd: function(evt) {
                            if (!loggedIn) {
                                loadBoard();
                                return;
                            }
                            const cardId = evt.item.dataset.id;
                            const newListId = evt.to.dataset.listId;
                            const newIndex = Array.from(evt.to.children).indexOf(evt.item);
                            updateCardPosition(cardId, newListId, newIndex);
                        }
                    });
                });
                // Don't sort lists - keep them in fixed order
            });
        }
        function addCard(sectionId) {
            Swal.fire({
                title: 'Add Card',
                html: `
                    <div class="text-left space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Title *</label>
                            <input type="text" id="card-title" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" placeholder="Enter card title...">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Section *</label>
                            <select id="card-section" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                <option value="">-- Select a section --</option>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Beta">Beta</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                            <textarea id="card-description" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" placeholder="Enter card description..." rows="3"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Card',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280',
                preConfirm: () => {
                    const title = document.getElementById('card-title').value.trim();
                    const section = document.getElementById('card-section').value;
                    const description = document.getElementById('card-description').value.trim();
                    if (!title) {
                        Swal.showValidationMessage('Please enter a card title');
                        return false;
                    }
                    if (!section) {
                        Swal.showValidationMessage('Please select a section');
                        return false;
                    }
                    return { title, section, description };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const { title, section, description } = result.value;
                    $.post('api/cards.php', JSON.stringify({ 
                        board_id: boardId,
                        title: title,
                        section: section,
                        description: description 
                    }), function(data) {
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
                html: `
                    <div class="text-left space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">List Name *</label>
                            <input type="text" id="list-name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" placeholder="Enter list name...">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Section *</label>
                            <select id="list-section" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                <option value="">-- Select a section --</option>
                                <option value="Upcoming">Upcoming/Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Beta">Beta</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                            <textarea id="list-description" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500" placeholder="Enter list description..." rows="3"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add List',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280',
                preConfirm: () => {
                    const name = document.getElementById('list-name').value.trim();
                    const section = document.getElementById('list-section').value;
                    const description = document.getElementById('list-description').value.trim();
                    if (!name) {
                        Swal.showValidationMessage('Please enter a list name');
                        return false;
                    }
                    if (!section) {
                        Swal.showValidationMessage('Please select a section');
                        return false;
                    }
                    return { name, section, description };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const { name, section, description } = result.value;
                    $.post('api/lists.php', JSON.stringify({ 
                        board_id: boardId, 
                        name: name,
                        description: description
                    }), function(data) {
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
        function updateCardPosition(cardId, section, position) {
            $.post('api/update.php', JSON.stringify({ type: 'move_card', card_id: cardId, section: section, position: position }), function(data) {
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
                    $('#user-info').html('<a href="admin.php" class="text-yellow-300 hover:text-yellow-100 font-medium"><i class="fas fa-cog mr-1"></i>Admin</a> | Logged in as <strong>' + data.username + '</strong> (Admin) | <a href="logout.php" class="text-yellow-300 hover:text-yellow-100 font-medium">Logout</a>');
                    $('.edit-only').show();
                    $('#add-list-btn').removeClass('hidden');
                } else if (loggedIn) {
                    $('#user-info').html('Logged in as <strong>' + data.username + '</strong> | <a href="logout.php" class="text-yellow-300 hover:text-yellow-100 font-medium">Logout</a>');
                } else {
                    $('#user-info').html('<a href="login.php" class="text-yellow-300 hover:text-yellow-100 font-medium">Login</a>');
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