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

// Active beta programs — fetched from website DB (authoritative source)
$betaPrograms = [];
$_bpWdb = website_db();
$_bpRes = $_bpWdb->query('SELECT slug, name FROM beta_programs WHERE is_active = 1 ORDER BY name ASC');
if ($_bpRes) $betaPrograms = $_bpRes->fetch_all(MYSQLI_ASSOC);
$_bpWdb->close();

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
// POST: Staff — approve or decline a beta program request
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])
    && in_array($_POST['_action'], ['approve_beta', 'decline_beta'], true)) {
    if (!is_staff()) {
        $errors[] = 'Insufficient permissions.';
    } elseif (!verify_csrf()) {
        $errors[] = 'Security token mismatch.';
    } else {
        $bAction   = $_POST['_action'];
        $bTicketId = (int)($_POST['ticket_id'] ?? 0);
        $tstmt = $db->prepare('SELECT id, ticket_number, twitch_user_id, display_name, meta FROM tickets WHERE id = ? AND category = ?');
        $catKey = 'beta_request';
        $tstmt->bind_param('is', $bTicketId, $catKey);
        $tstmt->execute();
        $trow = $tstmt->get_result()->fetch_assoc();
        $tstmt->close();
        if ($trow) {
            $bMeta    = json_decode($trow['meta'] ?? '{}', true);
            $bSlug    = $bMeta['program'] ?? '';
            $bProgName = $bMeta['program_name'] ?? $bSlug;
            if ($bAction === 'approve_beta' && $bSlug) {
                // Add program to website.users.beta_programs
                $wdb   = website_db();
                $wstmt = $wdb->prepare('SELECT beta_programs FROM users WHERE twitch_user_id = ? LIMIT 1');
                $wstmt->bind_param('s', $trow['twitch_user_id']);
                $wstmt->execute();
                $wstmt->bind_result($rawProgs);
                $wstmt->fetch();
                $wstmt->close();
                $enrolled = json_decode($rawProgs ?? '[]', true) ?? [];
                if (!in_array($bSlug, $enrolled, true)) {
                    $enrolled[] = $bSlug;
                }
                $newJson = json_encode(array_values($enrolled));
                $uw = $wdb->prepare('UPDATE users SET beta_programs = ? WHERE twitch_user_id = ?');
                $uw->bind_param('ss', $newJson, $trow['twitch_user_id']);
                $uw->execute();
                $uw->close();
                $wdb->close();
                // Staff reply
                $staffName  = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Staff';
                $approveMsg = "Your request for access to the \"{$bProgName}\" beta program has been approved! You now have access — head to your dashboard to get started.";
                $rs = $db->prepare('INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message) VALUES (?, ?, ?, 1, ?)');
                $rs->bind_param('isss', $bTicketId, $_SESSION['twitch_user_id'], $staffName, $approveMsg);
                $rs->execute();
                $rs->close();
                $db->query("UPDATE tickets SET status = 'resolved' WHERE id = {$bTicketId}");
            } elseif ($bAction === 'decline_beta') {
                $staffName  = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Staff';
                $reason = trim($_POST['reason'] ?? '');
                $declineMsg = "Your request for access to the \"{$bProgName}\" beta program has been declined."
                    . ($reason ? "\n\nReason: {$reason}" : '');
                $rs = $db->prepare('INSERT INTO ticket_replies (ticket_id, author_twitch_id, author_display_name, is_staff, message) VALUES (?, ?, ?, 1, ?)');
                $rs->bind_param('isss', $bTicketId, $_SESSION['twitch_user_id'], $staffName, $declineMsg);
                $rs->execute();
                $rs->close();
                $db->query("UPDATE tickets SET status = 'closed' WHERE id = {$bTicketId}");
            }
            header('Location: /tickets.php?id=' . urlencode($trow['ticket_number']));
            exit;
        } else {
            $errors[] = 'Ticket not found or is not a beta request.';
        }
    }
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
        $validCats  = ['billing', 'technical', 'account', 'general', 'feedback', 'beta_request'];
        $validPrios = ['low', 'normal', 'high'];
        $allowedPrios = is_staff() ? $validPrios : ['low', 'normal'];
        $ticketMeta = null;
        // Feedback tickets get auto-generated subject and default priority
        if ($category === 'feedback') {
            $subject  = 'Feedback from ' . ($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Unknown');
            $priority = 'normal';
        }
        // Beta request tickets get auto-generated subject and program stored in meta
        if ($category === 'beta_request') {
            $reqSlug = trim($_POST['program_slug'] ?? '');
            $bpMap   = array_column($betaPrograms, 'name', 'slug');
            if (!isset($bpMap[$reqSlug])) {
                $errors[] = 'Please select a valid beta program.';
            } else {
                // Prevent duplicate pending requests
                $dupStmt = $db->prepare(
                    "SELECT id FROM tickets WHERE twitch_user_id = ? AND category = 'beta_request'
                     AND JSON_EXTRACT(meta, '$.program') = ? AND status IN ('open','in_progress') LIMIT 1"
                );
                $dupStmt->bind_param('ss', $_SESSION['twitch_user_id'], $reqSlug);
                $dupStmt->execute();
                $dupStmt->store_result();
                if ($dupStmt->num_rows > 0) {
                    $errors[] = 'You already have a pending request for that beta program.';
                }
                $dupStmt->close();
                $subject    = 'Beta Access Request: ' . $bpMap[$reqSlug];
                $priority   = 'normal';
                $ticketMeta = json_encode(['program' => $reqSlug, 'program_name' => $bpMap[$reqSlug]]);
            }
        }
        if ($category !== 'feedback' && $category !== 'beta_request' && strlen($subject) < 5)   $errors[] = 'Subject must be at least 5 characters.';
        if ($category !== 'feedback' && $category !== 'beta_request' && strlen($subject) > 255) $errors[] = 'Subject must be 255 characters or fewer.';
        if (!in_array($category, $validCats, true)) $errors[] = 'Invalid category.';
        if ($category !== 'feedback' && $category !== 'beta_request' && !in_array($priority, $allowedPrios, true)) $errors[] = 'Invalid priority.';
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
                'INSERT INTO tickets (ticket_number, twitch_user_id, username, display_name, category, subject, status, priority, meta)
                 VALUES (?, ?, ?, ?, ?, ?, "open", ?, ?)'
            );
            $stmt->bind_param(
                'ssssssss',
                $tickNum,
                $_SESSION['twitch_user_id'],
                $_SESSION['username'],
                $_SESSION['display_name'],
                $category,
                $subject,
                $priority,
                $ticketMeta
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
    $map = ['billing'=>'Billing','technical'=>'Technical','account'=>'Account','general'=>'General','feedback'=>'Feedback','beta_request'=>'Beta Program Request'];
    return $map[$c] ?? ucfirst(str_replace('_', ' ', $c));
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
    <?php if ($ticket['category'] === 'beta_request'): ?>
    <?php $tMeta = json_decode($ticket['meta'] ?? '{}', true); $tProgName = $tMeta['program_name'] ?? $tMeta['program'] ?? 'Unknown'; ?>
    <span><i class="fa-solid fa-flask"></i> Program: <strong><?php echo htmlspecialchars($tProgName); ?></strong></span>
    <?php endif; ?>
</div>

<?php if (is_staff() && $ticket['category'] === 'beta_request' && in_array($ticket['status'], ['open','in_progress'], true)): ?>
<div class="sp-card sp-mt-3" style="border-left:3px solid var(--accent,#7c3aed);">
    <div class="sp-card-header"><i class="fa-solid fa-flask"></i> Beta Request — <?php echo htmlspecialchars($tProgName); ?></div>
    <div class="sp-card-body">
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
            <form method="POST" action="/tickets.php">
                <input type="hidden" name="_action"    value="approve_beta">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="ticket_id"  value="<?php echo (int)$ticket['id']; ?>">
                <button type="submit" class="sp-btn sp-btn-primary">
                    <i class="fa-solid fa-circle-check"></i> Approve
                </button>
            </form>
            <div>
                <button type="button" class="sp-btn sp-btn-danger" onclick="document.getElementById('decline-form').style.display=''">
                    <i class="fa-solid fa-circle-xmark"></i> Decline
                </button>
                <form id="decline-form" method="POST" action="/tickets.php" style="display:none;margin-top:0.75rem;">
                    <input type="hidden" name="_action"    value="decline_beta">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="ticket_id"  value="<?php echo (int)$ticket['id']; ?>">
                    <div class="sp-form-group">
                        <textarea name="reason" class="sp-textarea" rows="3"
                            placeholder="Optional: reason for declining…"></textarea>
                    </div>
                    <button type="submit" class="sp-btn sp-btn-danger">
                        <i class="fa-solid fa-circle-xmark"></i> Confirm Decline
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
        <?php
        $preCategory = $_GET['category'] ?? ($_POST['category'] ?? 'general');
        $preProgram  = $_GET['program']  ?? ($_POST['program_slug'] ?? '');
        ?>
        <form method="POST" action="/tickets.php" data-once>
            <input type="hidden" name="_action"    value="new_ticket">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="sp-form-row">
                <div class="sp-form-group">
                    <label class="sp-label" for="ticket_category">Category</label>
                    <select id="ticket_category" name="category" class="sp-select">
                        <option value="general"       <?php echo $preCategory==='general'       ?'selected':''; ?>>General</option>
                        <option value="technical"     <?php echo $preCategory==='technical'     ?'selected':''; ?>>Technical</option>
                        <option value="account"       <?php echo $preCategory==='account'       ?'selected':''; ?>>Account</option>
                        <option value="billing"       <?php echo $preCategory==='billing'       ?'selected':''; ?>>Billing</option>
                        <option value="feedback"      <?php echo $preCategory==='feedback'      ?'selected':''; ?>>Feedback</option>
                        <?php if (!empty($betaPrograms)): ?>
                        <option value="beta_request"  <?php echo $preCategory==='beta_request'  ?'selected':''; ?>>Beta Program Request</option>
                        <?php endif; ?>
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
            <!-- Beta program picker (shown only for beta_request category) -->
            <div class="sp-form-group" id="program_group" style="display:none;">
                <label class="sp-label" for="ticket_program">Beta Program <span class="sp-req">*</span></label>
                <select id="ticket_program" name="program_slug" class="sp-select">
                    <option value="">— Select a program —</option>
                    <?php foreach ($betaPrograms as $bp): ?>
                    <option value="<?php echo htmlspecialchars($bp['slug']); ?>"
                        <?php echo $preProgram === $bp['slug'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($bp['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
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
    var catSelect     = document.getElementById('ticket_category');
    var subjectGroup  = document.getElementById('subject_group');
    var priorityGroup = document.getElementById('priority_group');
    var programGroup  = document.getElementById('program_group');
    var messageLabel  = document.querySelector('label[for="ticket_message"]');
    var messageTa     = document.getElementById('ticket_message');
    var submitBtn     = document.getElementById('submit_ticket_btn');
    if (!catSelect) return;
    function updateForm() {
        var v = catSelect.value;
        var isFeedback = v === 'feedback';
        var isBeta     = v === 'beta_request';
        subjectGroup.style.display  = (isFeedback || isBeta) ? 'none' : '';
        priorityGroup.style.display = (isFeedback || isBeta) ? 'none' : '';
        programGroup.style.display  = isBeta ? '' : 'none';
        if (isFeedback) {
            messageLabel.innerHTML = 'Your Feedback <span class="sp-req">*</span>';
            messageTa.placeholder  = 'Tell us what you think — what\'s working, what\'s not, or what you\'d like to see improved.';
            submitBtn.innerHTML    = '<i class="fa-solid fa-paper-plane"></i> Submit Feedback';
        } else if (isBeta) {
            messageLabel.innerHTML = 'Why do you want access? <span class="sp-req">*</span>';
            messageTa.placeholder  = 'Tell us a little about yourself and why you\'d like to join this beta program.';
            submitBtn.innerHTML    = '<i class="fa-solid fa-paper-plane"></i> Submit Request';
        } else {
            messageLabel.innerHTML = 'Description <span class="sp-req">*</span>';
            messageTa.placeholder  = 'Please describe the issue in detail - what happened, what you expected, and any error messages you saw.';
            submitBtn.innerHTML    = '<i class="fa-solid fa-paper-plane"></i> Submit Ticket';
        }
    }
    catSelect.addEventListener('change', updateForm);
    updateForm();
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