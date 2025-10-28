<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = 'Discord Stream Tracking Overview';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

// Fetch all users
$users = [];
$result = $conn->query("SELECT username FROM users ORDER BY username ASC");
while ($row = $result->fetch_assoc()) {
    $users[] = $row['username'];
}

// Initialize data array
$trackingData = [];

foreach ($users as $username) {
    $userDbName = $username;
    try {
        $userConn = new mysqli($db_servername, $db_username, $db_password, $userDbName);
        if ($userConn->connect_error) {
            // Skip if database doesn't exist
            continue;
        }
        // Check if member_streams table exists
        $tableCheck = $userConn->query("SHOW TABLES LIKE 'member_streams'");
        if ($tableCheck->num_rows == 0) {
            $userConn->close();
            continue;
        }
        // Fetch tracked streams
        $streams = [];
        $stmt = $userConn->prepare("SELECT username, stream_url FROM member_streams ORDER BY username ASC");
        if ($stmt) {
            $stmt->execute();
            $resultStreams = $stmt->get_result();
            while ($row = $resultStreams->fetch_assoc()) {
                $streams[] = $row;
            }
            $stmt->close();
        }
        if (!empty($streams)) {
            // Remove duplicates based on username
            $uniqueStreams = [];
            foreach ($streams as $stream) {
                $uniqueStreams[$stream['username']] = $stream;
            }
            $trackingData[$username] = array_values($uniqueStreams);
        }
        $userConn->close();
    } catch (mysqli_sql_exception $e) {
        // Skip users whose database doesn't exist
        continue;
    }
}

ob_start();
?>
<div class="box">
    <div class="level">
        <div class="level-left">
            <h1 class="title is-4"><span class="icon"><i class="fab fa-discord"></i></span> Discord Stream Tracking</h1>
        </div>
        <!-- Modal used to show user's tracked streams -->
        <div id="user-details-modal" class="modal">
            <div class="modal-background"></div>
            <div class="modal-card">
                <header class="modal-card-head">
                    <p id="modal-title" class="modal-card-title">Tracked Streams</p>
                    <button class="delete" aria-label="close" id="modal-close"></button>
                </header>
                <section class="modal-card-body" id="modal-body">
                    <!-- populated by JS -->
                </section>
                <footer class="modal-card-foot">
                    <button class="button" id="modal-close-btn">Close</button>
                </footer>
            </div>
        </div>
        <div class="level-right">
            <div class="field has-addons">
                <div class="control">
                    <input id="user-search" class="input" type="text" placeholder="Search users or streamers...">
                </div>
                <div class="control">
                    <a id="clear-search" class="button is-light" title="Clear search">Clear</a>
                </div>
            </div>
        </div>
    </div>
    <p class="mb-4">Overview of users with Discord stream tracking. Click a user to expand tracked streams.</p>
    <?php if (empty($trackingData)): ?>
        <div class="notification is-info">
            <p>No users currently have Discord stream tracking configured.</p>
        </div>
    <?php else: ?>
        <div class="columns is-multiline" id="tracking-cards">
            <?php foreach ($trackingData as $username => $streams): ?>
                <?php $safeUser = htmlspecialchars($username); $count = count($streams); ?>
                <div class="column is-6-tablet is-4-desktop tracking-card" data-username="<?php echo strtolower($safeUser); ?>">
                        <div class="card">
                            <header class="card-header user-details-open" role="button" aria-expanded="false" tabindex="0">
                                <p class="card-header-title">
                                    <span class="has-text-weight-semibold"><?php echo $safeUser; ?></span>
                                    <span class="ml-3 has-text-grey">&middot; <?php echo $count; ?> tracked</span>
                                </p>
                                <a href="#" class="card-header-icon" aria-label="view details">
                                    <span class="icon"><i class="fas fa-external-link-alt" aria-hidden="true"></i></span>
                                </a>
                            </header>
                            <!-- Store the details markup hidden so JS can move into modal -->
                            <div class="card-content details-template" style="display:none;">
                                <?php if ($count === 0): ?>
                                    <div class="content"><em>No streams tracked.</em></div>
                                <?php else: ?>
                                    <div class="content stream-list">
                                        <?php foreach ($streams as $stream): ?>
                                            <div class="stream-item" data-streamer="<?php echo strtolower(htmlspecialchars($stream['username'])); ?>">
                                                <div class="columns is-vcentered is-mobile">
                                                    <div class="column is-5">
                                                        <strong><?php echo htmlspecialchars($stream['username']); ?></strong>
                                                    </div>
                                                    <div class="column is-7">
                                                        <?php if (!empty($stream['stream_url'])): ?>
                                                            <a href="<?php echo htmlspecialchars($stream['stream_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                                <?php echo htmlspecialchars($stream['stream_url']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <em>No URL</em>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="level mt-4">
            <div class="level-left">
                <div class="level-item">
                    <p><strong>Total Users with Tracking:</strong> <?php echo count($trackingData); ?></p>
                </div>
            </div>
            <div class="level-right">
                <div class="level-item">
                    <p><strong>Total Tracked Streams:</strong> <?php echo array_sum(array_map('count', $trackingData)); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('user-search');
    const clearBtn = document.getElementById('clear-search');
    const cardsContainer = document.getElementById('tracking-cards');
    // Debounce helper
    function debounce(fn, delay) {
        let t;
        return function(...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), delay);
        };
    }
    function filterCards() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const cards = cardsContainer.querySelectorAll('.tracking-card');
        if (!q) {
            cards.forEach(c => c.style.display = '');
            return;
        }
        cards.forEach(card => {
            const user = card.getAttribute('data-username') || '';
            let matched = user.includes(q);
            // If not matched by username, check streams inside
            if (!matched) {
                const rows = card.querySelectorAll('tbody tr');
                rows.forEach(r => {
                    const streamer = r.getAttribute('data-streamer') || '';
                    const urlCell = r.querySelector('td:nth-child(2)');
                    const urlText = urlCell ? (urlCell.textContent || '').toLowerCase() : '';
                    if (streamer.includes(q) || urlText.includes(q)) matched = true;
                });
            }
            card.style.display = matched ? '' : 'none';
        });
    }
    const debouncedFilter = debounce(filterCards, 220);
    searchInput.addEventListener('input', debouncedFilter);
    clearBtn.addEventListener('click', function(e) { e.preventDefault(); searchInput.value = ''; debouncedFilter(); });
    // Open modal with user details when header clicked
    const modal = document.getElementById('user-details-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalBody = document.getElementById('modal-body');
    const modalClose = document.getElementById('modal-close');
    const modalCloseBtn = document.getElementById('modal-close-btn');
    function openModal(title, contentNode) {
        modalTitle.textContent = title;
        // Clear previous
        modalBody.innerHTML = '';
        // Append a clone so we don't remove from the card
        const clone = contentNode.cloneNode(true);
        // details-template was initially hidden via inline style; ensure cloned content is visible in modal
        clone.style.display = '';
        clone.classList.remove('details-template');
        // If the clone contains a table, ensure it's visible
        const hiddenEls = clone.querySelectorAll('[style*="display:none"]');
        hiddenEls.forEach(el => el.style.display = '');
    modalBody.appendChild(clone);
        modal.classList.add('is-active');
        // focus close for accessibility
        modalCloseBtn.focus();
    }
    function closeModal() {
        modal.classList.remove('is-active');
        modalBody.innerHTML = '';
    }
    // Click handlers on headers
    document.querySelectorAll('.user-details-open').forEach(header => {
        header.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.tracking-card');
            if (!card) return;
            const username = card.getAttribute('data-username') || '';
            const template = card.querySelector('.details-template');
            if (!template) return;
            // Set title nicely
            const pretty = username.replace(/-/g, ' ');
            openModal(pretty + ' — Tracked Streams', template);
        });
        header.addEventListener('keypress', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); } });
    });
    // modal close events
    modalClose.addEventListener('click', closeModal);
    modalCloseBtn.addEventListener('click', closeModal);
    modal.querySelector('.modal-background').addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
});
</script>
<?php
$scripts = ob_get_clean();
include "admin_layout.php";
?>