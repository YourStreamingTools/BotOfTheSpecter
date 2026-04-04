<?php
// support/tickets.php
// ----------------------------------------------------------------
// All ticket views in one file:
//   ?action=list        - user's own tickets (default)
//   ?action=list&view=queue - staff queue (is_staff() only)
//   ?action=new         - submit a ticket form
//   ?id=SPT-XXXXX       - ticket thread view
//
// All POST actions verified with CSRF.
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
support_session_start();
require_login(); // tickets always require auth

$action   = $_GET['action']   ?? 'list';
$ticketId = $_GET['id']       ?? null;
$queueView = (isset($_GET['view']) && $_GET['view'] === 'queue' && is_staff());
$db      = support_db();
$success = '';
$errors  = [];

// Check if the logged-in user is a registered BotOfTheSpecter user
$isRegisteredUser = is_staff(); // staff always pass
if (!$isRegisteredUser) {
    $wdb   = website_db();
    $wstmt = $wdb->prepare('SELECT 1 FROM users WHERE twitch_user_id = ? LIMIT 1');
    if ($wstmt) {
        $wstmt->bind_param('s', $_SESSION['twitch_user_id']);
        $wstmt->execute();
        $wstmt->store_result();
        $isRegisteredUser = ($wstmt->num_rows === 1);
        $wstmt->close();
    }
    $wdb->close();
}
// ----------------------------------------------------------------
// POST: Submit new ticket
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'new_ticket') {
    if (!verify_csrf()) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $subject  = trim($_POST['subject'] ?? '');
        $category = $_POST['category']  ?? 'general';
        $priority = $_POST['priority']  ?? 'normal';
        $message  = trim($_POST['message'] ?? '');
        $validCats  = ['billing', 'technical', 'account', 'general', 'feedback'];
        $validPrios = ['low', 'normal', 'high'];
        $allowedPrios = is_staff() ? $validPrios : ['low', 'normal'];
        // Feedback tickets get auto-generated subject and default priority
        if ($category === 'feedback') {
            $subject  = 'Feedback from ' . ($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Unknown');
            $priority = 'normal';
        }
        if (strlen($subject) < 5)               $errors[] = 'Subject must be at least 5 characters.';
        if (strlen($subject) > 255)             $errors[] = 'Subject must be 255 characters or fewer.';
        if (!in_array($category, $validCats, true)) $errors[] = 'Invalid category.';
        if ($category !== 'feedback' && !in_array($priority, $allowedPrios, true)) $errors[] = 'Invalid priority.';
        if (strlen($message) < 20)              $errors[] = 'Message must be at least 20 characters.';
        if (strlen($message) > 5000)            $errors[] = 'Message must be 5000 characters or fewer.';
        if (empty($errors)) {
            // Generate ticket number: SPT-XXXXX
            $stmt = $db->prepare('SELECT MAX(id) AS max_id FROM tickets');
            $stmt->execute();
            $row     = $stmt->get_result()->fetch_assoc();
            $nextId  = ($row['max_id'] ?? 0) + 1;
            $tickNum = 'SPT-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);
            $stmt->close();
            $stmt = $db->prepare(
                'INSERT INTO tickets (ticket_number, twitch_user_id, username, display_name, category, subject, status, priority)
                 VALUES (?, ?, ?, ?, ?, ?, "open", ?)'
            );
            $stmt->bind_param(
                'sssssss',
                $tickNum,
                $_SESSION['twitch_user_id'],
                $_SESSION['username'],
                $_SESSION['display_name'],
                $category,
                $subject,
                $priority
            );
            $stmt->execute();
            $newTicketId = $stmt->insert_id;
            $stmt->close();
            // First reply = opening message
            $isStaffInt = is_staff() ? 1 : 0;
            $stmt = $db->prepare(
                'INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'isssi',
                $newTicketId,
                $_SESSION['twitch_user_id'],
                $_SESSION['display_name'],
                $isStaffInt,
                $message
            );
            $stmt->execute();
            $stmt->close();
            header('Location: /tickets.php?id=' . urlencode($tickNum));
            exit;
        }
    }
}

// ----------------------------------------------------------------
// POST: Reply to ticket
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'reply') {
    if (!verify_csrf()) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $replyTickId = (int)($_POST['ticket_id'] ?? 0);
        $message     = trim($_POST['message'] ?? '');
        if (strlen($message) < 1)    $errors[] = 'Reply cannot be empty.';
        if (strlen($message) > 5000) $errors[] = 'Reply must be 5000 characters or fewer.';
        if (empty($errors)) {
            // Confirm the ticket belongs to the user (or staff)
            if (is_staff()) {
                $tstmt = $db->prepare('SELECT id, ticket_number FROM tickets WHERE id = ?');
                $tstmt->bind_param('i', $replyTickId);
            } else {
                $tstmt = $db->prepare('SELECT id, ticket_number FROM tickets WHERE id = ? AND twitch_user_id = ?');
                $tstmt->bind_param('is', $replyTickId, $_SESSION['twitch_user_id']);
            }
            $tstmt->execute();
            $trow = $tstmt->get_result()->fetch_assoc();
            $tstmt->close();
            if ($trow) {
                $isStaffInt = is_staff() ? 1 : 0;
                $stmt = $db->prepare(
                    'INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'issis',
                    $replyTickId,
                    $_SESSION['twitch_user_id'],
                    $_SESSION['display_name'],
                    $isStaffInt,
                    $message
                );
                $stmt->execute();
                $stmt->close();
                // Reopen closed/resolved tickets when user replies
                if (!is_staff()) {
                    $upd = $db->prepare("UPDATE tickets SET status = 'open' WHERE id = ? AND status IN ('resolved','closed')");
                    $upd->bind_param('i', $replyTickId);
                    $upd->execute();
                    $upd->close();
                }
                header('Location: /tickets.php?id=' . urlencode($trow['ticket_number']));
                exit;
            } else {
                $errors[] = 'Ticket not found or access denied.';
            }
        }
    }
}

// ----------------------------------------------------------------
// POST: Staff - update ticket status / priority
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'staff_update') {
    if (!is_staff()) {
        $errors[] = 'Insufficient permissions.';
    } elseif (!verify_csrf()) {
        $errors[] = 'Security token mismatch.';
    } else {
        $upTickId  = (int)($_POST['ticket_id'] ?? 0);
        $newStatus = $_POST['status']   ?? '';
        $newPrio   = $_POST['priority'] ?? '';
        $validSt   = ['open', 'in_progress', 'resolved', 'closed'];
        $validPr   = ['low', 'normal', 'high'];
        $fields = [];
        if (in_array($newStatus, $validSt, true)) $fields[] = "status = '{$db->real_escape_string($newStatus)}'";
        if (in_array($newPrio,   $validPr, true)) $fields[] = "priority = '{$db->real_escape_string($newPrio)}'";
        if (!empty($fields)) {
            $db->query("UPDATE tickets SET " . implode(', ', $fields) . " WHERE id = {$upTickId}");
        }
        // Redirect back to ticket
        $tnum = $_POST['ticket_number'] ?? '';
        if ($tnum) { header('Location: /tickets.php?id=' . urlencode($tnum)); exit; }
        header('Location: /tickets.php');
        exit;
    }
}

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
function status_badge(string $s): string {
    $labels = ['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'];
    return '<span class="sp-badge sp-status-' . htmlspecialchars($s) . '">' . ($labels[$s] ?? ucfirst($s)) . '</span>';
}
function prio_badge(string $p): string {
    $map = ['low'=>'sp-prio-low','normal'=>'sp-prio-normal','high'=>'sp-prio-high'];
    $icons = ['low'=>'fa-arrow-down','normal'=>'fa-minus','high'=>'fa-arrow-up'];
    $ic    = $icons[$p] ?? 'fa-minus';
    $cls   = $map[$p]   ?? '';
    return '<span class="sp-badge ' . $cls . '"><i class="fa-solid ' . $ic . '"></i> ' . ucfirst($p) . '</span>';
}
function cat_label(string $c): string {
    $map = ['billing'=>'Billing','technical'=>'Technical','account'=>'Account','general'=>'General','feedback'=>'Feedback'];
    return $map[$c] ?? ucfirst($c);
}
function time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60)  . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

// ----------------------------------------------------------------
// Build page
// ----------------------------------------------------------------
$pageTitle   = 'Support Tickets';
$topbarTitle = 'Support Tickets';

ob_start();

// ---- show flash errors ----
foreach ($errors as $err): ?>
<div class="sp-alert sp-alert-danger" data-dismiss="6000">
    <i class="fa-solid fa-circle-xmark"></i><span><?php echo htmlspecialchars($err); ?></span>
</div>
<?php endforeach;

// ============================================================
// VIEW: THREAD
// ============================================================
if ($ticketId):
    $tn = $db->real_escape_string($ticketId);
    if (is_staff()) {
        $tq = $db->query("SELECT * FROM tickets WHERE ticket_number = '{$tn}'");
    } else {
        $uid = $db->real_escape_string($_SESSION['twitch_user_id']);
        $tq  = $db->query("SELECT * FROM tickets WHERE ticket_number = '{$tn}' AND twitch_user_id = '{$uid}'");
    }
    $ticket = $tq ? $tq->fetch_assoc() : null;
    if (!$ticket):
?>
<div class="sp-empty-state">
    <div class="sp-empty-icon"><i class="fa-solid fa-circle-xmark"></i></div>
    <h3>Ticket Not Found</h3>
    <p>That ticket doesn't exist or you don't have permission to view it.</p>
    <a href="/tickets.php" class="sp-btn sp-btn-primary sp-mt-2">Back to My Tickets</a>
</div>
<?php
    else:
        $rq = $db->query("SELECT * FROM ticket_replies WHERE ticket_id = {$ticket['id']} ORDER BY created_at ASC");
        $replies = $rq ? $rq->fetch_all(MYSQLI_ASSOC) : [];
?>
<!-- Page header -->
<div class="sp-page-header">
    <div>
        <a href="/tickets.php" class="sp-back-link"><i class="fa-solid fa-arrow-left"></i> <?php echo is_staff() ? 'Staff Queue' : 'My Tickets'; ?></a>
        <h1><?php echo htmlspecialchars($ticket['subject']); ?></h1>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;align-items:center;">
            <span style="font-family:monospace;font-size:0.85rem;color:var(--text-muted);"><?php echo htmlspecialchars($ticket['ticket_number']); ?></span>
            <?php echo status_badge($ticket['status']); ?>
            <?php echo prio_badge($ticket['priority']); ?>
            <span class="sp-badge sp-cat"><?php echo cat_label($ticket['category']); ?></span>
        </div>
    </div>
    <?php if (is_staff()): ?>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
        <form method="POST" action="/tickets.php" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="_action"       value="staff_update">
            <input type="hidden" name="csrf_token"    value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="ticket_id"     value="<?php echo (int)$ticket['id']; ?>">
            <input type="hidden" name="ticket_number" value="<?php echo htmlspecialchars($ticket['ticket_number']); ?>">
            <select name="status" class="sp-select sp-select-sm">
                <?php foreach (['open','in_progress','resolved','closed'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $ticket['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_',' ',$st)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="sp-select sp-select-sm">
                <?php foreach (['low','normal','high'] as $pr): ?>
                <option value="<?php echo $pr; ?>" <?php echo $ticket['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm"><i class="fa-solid fa-check"></i> Update</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Ticket meta bar -->
<div class="sp-ticket-meta">
    <span><i class="fa-regular fa-user"></i> Opened by <strong><?php echo htmlspecialchars($ticket['display_name'] ?: $ticket['username']); ?></strong></span>
    <span><i class="fa-regular fa-clock"></i> <?php echo date('d M Y, H:i', strtotime($ticket['created_at'])); ?></span>
    <span><i class="fa-regular fa-rotate"></i> Updated <?php echo time_ago($ticket['updated_at']); ?></span>
</div>

<!-- Thread -->
<div class="sp-ticket-thread">
<?php foreach ($replies as $r):
    $isMe   = ($r['author_twitch_id'] === ($_SESSION['twitch_user_id'] ?? ''));
    $isStff = (bool)$r['is_staff'];
    $cls    = $isMe ? 'sp-msg sp-msg-user' : 'sp-msg sp-msg-staff';
?>
<div class="<?php echo $cls; ?>">
    <div class="sp-msg-header">
        <span class="sp-msg-author">
            <?php if ($isStff && !$isMe): ?><span class="sp-badge sp-staff-badge"><i class="fa-solid fa-shield-halved"></i> Staff</span> <?php endif; ?>
            <?php echo htmlspecialchars($r['author_display_name']); ?>
        </span>
        <span class="sp-msg-time"><?php echo date('d M Y H:i', strtotime($r['created_at'])); ?></span>
    </div>
    <div class="sp-msg-body"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
</div>
<?php endforeach; ?>
</div>

<?php if (in_array($ticket['status'], ['open', 'in_progress'], true)): ?>
<!-- Reply form -->
<div class="sp-card sp-mt-3">
    <div class="sp-card-header"><i class="fa-solid fa-reply"></i> Reply</div>
    <div class="sp-card-body">
        <form method="POST" action="/tickets.php" data-once>
            <input type="hidden" name="_action"    value="reply">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="ticket_id"  value="<?php echo (int)$ticket['id']; ?>">
            <div class="sp-form-group">
                <label class="sp-label" for="reply_msg">Message</label>
                <textarea id="reply_msg" name="message" class="sp-textarea" rows="5"
                    data-max-chars="5000" placeholder="Write your reply here…"></textarea>
                <div class="sp-char-counter" data-for="reply_msg"></div>
            </div>
            <button type="submit" class="sp-btn sp-btn-primary"><i class="fa-solid fa-paper-plane"></i> Send Reply</button>
        </form>
    </div>
</div>
<?php elseif ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed'): ?>
<div class="sp-alert sp-alert-info sp-mt-3">
    <i class="fa-solid fa-lock"></i>
    <span>This ticket is <strong><?php echo ucfirst($ticket['status']); ?></strong>. <?php if (!is_staff()): ?>Replying will automatically reopen it.<?php endif; ?></span>
    <?php if (!is_staff()): ?>
    <form method="POST" action="/tickets.php" data-once style="margin-top:0.75rem;">
        <input type="hidden" name="_action"    value="reply">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="ticket_id"  value="<?php echo (int)$ticket['id']; ?>">
        <div class="sp-form-group">
            <textarea name="message" class="sp-textarea" rows="4" data-max-chars="5000" placeholder="Write a follow-up…"></textarea>
        </div>
        <button type="submit" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-paper-plane"></i> Reopen &amp; Reply</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; // ticket found ?>
<?php
// ============================================================
// VIEW: NEW TICKET
// ============================================================
elseif ($action === 'new'):
?>
<div class="sp-page-header">
    <div>
        <a href="/tickets.php" class="sp-back-link"><i class="fa-solid fa-arrow-left"></i> My Tickets</a>
        <h1>Submit a Support Ticket</h1>
    </div>
</div>
<?php if (!$isRegisteredUser): ?>
<div class="sp-alert sp-alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span>This support system is only for users of <strong>BotOfTheSpecter</strong> the Twitch and Discord bot.
    You don't appear to have a BotOfTheSpecter account. Please <a href="https://botofthespecter.com" target="_blank" rel="noopener">sign up at botofthespecter.com</a> before submitting a ticket.</span>
</div>
<?php endif; ?>
<div class="sp-card" style="max-width:640px;<?php echo !$isRegisteredUser ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
    <div class="sp-card-header"><i class="fa-solid fa-ticket"></i> New Ticket</div>
    <div class="sp-card-body">
        <form method="POST" action="/tickets.php" data-once>
            <input type="hidden" name="_action"    value="new_ticket">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="sp-form-row">
                <div class="sp-form-group">
                    <label class="sp-label" for="ticket_category">Category</label>
                    <select id="ticket_category" name="category" class="sp-select">
                        <option value="general">General</option>
                        <option value="technical">Technical</option>
                        <option value="account">Account</option>
                        <option value="billing">Billing</option>
                        <option value="feedback">Feedback</option>
                    </select>
                </div>
                <div class="sp-form-group" id="priority_group">
                    <label class="sp-label" for="ticket_priority">Priority</label>
                    <select id="ticket_priority" name="priority" class="sp-select">
                        <option value="normal">Normal</option>
                        <option value="low">Low</option>
                        <?php if (is_staff()): ?>
                        <option value="high">High</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="sp-form-group" id="subject_group">
                <label class="sp-label" for="ticket_subject">Subject <span class="sp-req">*</span></label>
                <input type="text" id="ticket_subject" name="subject" class="sp-input" maxlength="255"
                    placeholder="Briefly describe your issue"
                    value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="ticket_message">Description <span class="sp-req">*</span></label>
                <textarea id="ticket_message" name="message" class="sp-textarea" rows="7"
                    data-min-chars="20" data-max-chars="5000"
                    placeholder="Please describe the issue in detail - what happened, what you expected, and any error messages you saw."><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                <div class="sp-char-counter" data-for="ticket_message"></div>
            </div>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
                <button type="submit" class="sp-btn sp-btn-primary" id="submit_ticket_btn"><i class="fa-solid fa-paper-plane"></i> Submit Ticket</button>
                <a href="/tickets.php" class="sp-btn sp-btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var catSelect = document.getElementById('ticket_category');
    var subjectGroup = document.getElementById('subject_group');
    var priorityGroup = document.getElementById('priority_group');
    var messageLabel = document.querySelector('label[for="ticket_message"]');
    var messageTextarea = document.getElementById('ticket_message');
    var submitBtn = document.getElementById('submit_ticket_btn');
    if (!catSelect) return;
    function toggleFeedback() {
        var isFeedback = catSelect.value === 'feedback';
        subjectGroup.style.display = isFeedback ? 'none' : '';
        priorityGroup.style.display = isFeedback ? 'none' : '';
        if (isFeedback) {
            messageLabel.innerHTML = 'Your Feedback <span class="sp-req">*</span>';
            messageTextarea.placeholder = 'Tell us what you think — what\'s working, what\'s not, or what you\'d like to see improved.';
            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Feedback';
        } else {
            messageLabel.innerHTML = 'Description <span class="sp-req">*</span>';
            messageTextarea.placeholder = 'Please describe the issue in detail - what happened, what you expected, and any error messages you saw.';
            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Ticket';
        }
    }
    catSelect.addEventListener('change', toggleFeedback);
    toggleFeedback();
});
</script>
<?php
// ============================================================
// VIEW: STAFF QUEUE
// ============================================================
elseif ($queueView):
    $filter_status = $_GET['status'] ?? 'all';
    $filter_prio   = $_GET['priority'] ?? 'all';
    $where = ['1=1'];
    if ($filter_status !== 'all') $where[] = "status = '" . $db->real_escape_string($filter_status) . "'";
    if ($filter_prio   !== 'all') $where[] = "priority = '" . $db->real_escape_string($filter_prio) . "'";
    $sql = 'SELECT * FROM tickets WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(priority,"high","normal","low"), created_at ASC';
    $result = $db->query($sql);
    $tickets = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<div class="sp-page-header">
    <div><h1><i class="fa-solid fa-shield-halved"></i> Staff Queue</h1>
         <p style="color:var(--text-secondary);"><?php echo count($tickets); ?> ticket<?php echo count($tickets) !== 1 ? 's' : ''; ?> found</p>
    </div>
    <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary"><i class="fa-solid fa-plus"></i> New Ticket</a>
</div>
<form method="GET" action="/tickets.php" class="sp-filters">
    <input type="hidden" name="view" value="queue">
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
        <select name="status" class="sp-select sp-select-sm" onchange="this.form.submit()">
            <option value="all" <?php echo $filter_status==='all'?'selected':''; ?>>All Statuses</option>
            <?php foreach (['open','in_progress','resolved','closed'] as $st): ?>
            <option value="<?php echo $st; ?>" <?php echo $filter_status===$st?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$st)); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority" class="sp-select sp-select-sm" onchange="this.form.submit()">
            <option value="all" <?php echo $filter_prio==='all'?'selected':''; ?>>All Priorities</option>
            <?php foreach (['high','normal','low'] as $pr): ?>
            <option value="<?php echo $pr; ?>" <?php echo $filter_prio===$pr?'selected':''; ?>><?php echo ucfirst($pr); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
<?php if (empty($tickets)): ?>
<div class="sp-empty-state">
    <div class="sp-empty-icon"><i class="fa-solid fa-inbox"></i></div>
    <h3>No tickets match your filter</h3>
    <p>Try changing the status or priority filter.</p>
</div>
<?php else: ?>
<div class="sp-table-wrap">
    <table class="sp-table">
        <thead>
            <tr>
                <th>Ticket #</th>
                <th>Subject</th>
                <th>From</th>
                <th>Category</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Opened</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
            <tr>
                <td><a href="/tickets.php?id=<?php echo urlencode($t['ticket_number']); ?>" style="font-family:monospace;white-space:nowrap;"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
                <td><a href="/tickets.php?id=<?php echo urlencode($t['ticket_number']); ?>"><?php echo htmlspecialchars($t['subject']); ?></a></td>
                <td><?php echo htmlspecialchars($t['display_name'] ?: $t['username']); ?></td>
                <td><?php echo cat_label($t['category']); ?></td>
                <td><?php echo prio_badge($t['priority']); ?></td>
                <td><?php echo status_badge($t['status']); ?></td>
                <td style="white-space:nowrap;"><?php echo time_ago($t['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php
// ============================================================
// VIEW: MY TICKETS (default)
// ============================================================
else:
    $uid = $db->real_escape_string($_SESSION['twitch_user_id']);
    $filter_status = $_GET['status'] ?? 'all';
    $where = ["twitch_user_id = '{$uid}'"];
    if ($filter_status !== 'all') $where[] = "status = '" . $db->real_escape_string($filter_status) . "'";
    $sql = 'SELECT * FROM tickets WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC';
    $result = $db->query($sql);
    $tickets = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<?php if (!$isRegisteredUser): ?>
<div class="sp-alert sp-alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span>This support system is only for users of <strong>BotOfTheSpecter</strong> the Twitch and Discord bot.
    You don't appear to have a BotOfTheSpecter account. Please <a href="https://botofthespecter.com" target="_blank" rel="noopener">sign up at botofthespecter.com</a> before submitting a ticket.</span>
</div>
<?php endif; ?>
<div class="sp-page-header">
    <div>
        <h1>My Support Tickets</h1>
        <p style="color:var(--text-secondary);"><?php echo count($tickets); ?> ticket<?php echo count($tickets) !== 1 ? 's' : ''; ?></p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <?php if (is_staff()): ?>
        <a href="/tickets.php?view=queue" class="sp-btn sp-btn-secondary"><i class="fa-solid fa-shield-halved"></i> Staff Queue</a>
        <?php endif; ?>
        <?php if ($isRegisteredUser): ?>
        <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary"><i class="fa-solid fa-plus"></i> New Ticket</a>
        <?php else: ?>
        <button class="sp-btn sp-btn-primary" disabled title="You must be a registered BotOfTheSpecter user to submit a ticket"><i class="fa-solid fa-plus"></i> New Ticket</button>
        <?php endif; ?>
    </div>
</div>
<form method="GET" action="/tickets.php" class="sp-filters">
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
        <select name="status" class="sp-select sp-select-sm" onchange="this.form.submit()">
            <option value="all" <?php echo $filter_status==='all'?'selected':''; ?>>All Statuses</option>
            <?php foreach (['open','in_progress','resolved','closed'] as $st): ?>
            <option value="<?php echo $st; ?>" <?php echo $filter_status===$st?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$st)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
<?php if (empty($tickets)): ?>
<div class="sp-empty-state">
    <div class="sp-empty-icon"><i class="fa-solid fa-ticket"></i></div>
    <h3>No tickets yet</h3>
    <p>Submit your first support ticket if you need help.</p>
    <?php if ($isRegisteredUser): ?>
    <a href="/tickets.php?action=new" class="sp-btn sp-btn-primary sp-mt-2"><i class="fa-solid fa-plus"></i> Submit a Ticket</a>
    <?php else: ?>
    <button class="sp-btn sp-btn-primary sp-mt-2" disabled title="You must be a registered BotOfTheSpecter user to submit a ticket"><i class="fa-solid fa-plus"></i> Submit a Ticket</button>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="sp-table-wrap">
    <table class="sp-table">
        <thead>
            <tr>
                <th>Ticket #</th>
                <th>Subject</th>
                <th>Category</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
            <tr>
                <td><a href="/tickets.php?id=<?php echo urlencode($t['ticket_number']); ?>" style="font-family:monospace;white-space:nowrap;"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
                <td><a href="/tickets.php?id=<?php echo urlencode($t['ticket_number']); ?>"><?php echo htmlspecialchars($t['subject']); ?></a></td>
                <td><?php echo cat_label($t['category']); ?></td>
                <td><?php echo prio_badge($t['priority']); ?></td>
                <td><?php echo status_badge($t['status']); ?></td>
                <td style="white-space:nowrap;"><?php echo time_ago($t['updated_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; // end view routing ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>