<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '../includes/userdata.php';
session_write_close();
$pageTitle = t('admin_terminal_page_title');

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><span class="icon"><i class="fas fa-terminal"></i></span> <?php echo t('admin_terminal_heading'); ?></h1>
    </div>
    <div class="sp-card-body">
    <p style="margin-bottom:1rem;"><?php echo t('admin_terminal_intro'); ?></p>
    <div class="sp-form-group">
        <label class="sp-label"><?php echo t('admin_terminal_select_server_label'); ?></label>
        <select class="sp-select" id="server-select">
            <option value=""><?php echo t('admin_terminal_choose_server_option'); ?></option>
            <option value="bots"><?php echo t('admin_terminal_server_bots'); ?></option>
            <option value="web"><?php echo t('admin_terminal_server_web'); ?></option>
            <option value="api"><?php echo t('admin_terminal_server_api'); ?></option>
            <option value="websocket"><?php echo t('admin_terminal_server_websocket'); ?></option>
            <option value="sql"><?php echo t('admin_terminal_server_sql'); ?></option>
        </select>
    </div>
    <div class="sp-form-group">
        <label class="sp-label"><?php echo t('admin_terminal_command_label'); ?></label>
        <input class="sp-input" type="text" id="command-input" placeholder="<?php echo htmlspecialchars(t('admin_terminal_command_placeholder'), ENT_QUOTES); ?>" disabled>
        <p class="sp-help"><?php echo t('admin_terminal_command_help'); ?></p>
    </div>
    <div class="sp-card" style="margin-bottom:1.25rem;">
        <div class="sp-card-header">
            <h2 class="sp-card-title"><span class="icon"><i class="fas fa-toolbox"></i></span> <?php echo t('admin_terminal_tools_heading'); ?></h2>
        </div>
        <div class="sp-card-body" style="padding:0.75rem 1rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label class="sp-label"><?php echo t('admin_terminal_quick_preset_label'); ?></label>
                <div class="sp-btn-group">
                    <select class="sp-select" id="preset-select" disabled style="flex:1;">
                        <option value=""><?php echo t('admin_terminal_select_preset_option'); ?></option>
                    </select>
                    <button class="sp-btn sp-btn-info" id="run-preset-btn" disabled>
                        <span class="icon"><i class="fas fa-bolt"></i></span>
                        <span><?php echo t('admin_terminal_btn_run'); ?></span>
                    </button>
                </div>
            </div>
            <div>
                <label class="sp-label"><?php echo t('admin_terminal_saved_snippets_label'); ?></label>
                <div class="sp-btn-group">
                    <select class="sp-select" id="snippet-select" style="flex:1;">
                        <option value=""><?php echo t('admin_terminal_select_snippet_option'); ?></option>
                    </select>
                    <button class="sp-btn" id="save-snippet-btn" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span><?php echo t('admin_terminal_btn_save'); ?></span>
                    </button>
                </div>
            </div>
        </div>
        </div>
    </div>
    <div class="sp-form-group">
        <div class="sp-btn-group">
            <button class="sp-btn sp-btn-primary" id="execute-btn" disabled>
                <span class="icon"><i class="fas fa-play"></i></span>
                <span><?php echo t('admin_terminal_btn_execute'); ?></span>
            </button>
            <button class="sp-btn" id="clear-btn">
                <span class="icon"><i class="fas fa-broom"></i></span>
                <span><?php echo t('admin_terminal_btn_clear'); ?></span>
            </button>
            <button class="sp-btn sp-btn-warning" id="interrupt-btn" disabled>
                <span class="icon"><i class="fas fa-stop"></i></span>
                <span><?php echo t('admin_terminal_btn_interrupt'); ?></span>
            </button>
            <button class="sp-btn sp-btn-danger" id="tmux-detach-btn" style="display:none;" title="<?php echo htmlspecialchars(t('admin_terminal_tmux_detach_title'), ENT_QUOTES); ?>">
                <span class="icon"><i class="fas fa-unlink"></i></span>
                <span><?php echo t('admin_terminal_btn_detach'); ?></span>
            </button>
            <button class="sp-btn sp-btn-info" id="copy-output-btn">
                <span class="icon"><i class="fas fa-copy"></i></span>
                <span><?php echo t('admin_terminal_btn_copy_output'); ?></span>
            </button>
            <button class="sp-btn sp-btn-info" id="download-output-btn">
                <span class="icon"><i class="fas fa-download"></i></span>
                <span><?php echo t('admin_terminal_btn_download_log'); ?></span>
            </button>
        </div>
    </div>
    <div class="sp-form-group">
        <div style="display:flex;flex-wrap:wrap;gap:0.35rem;">
            <span class="sp-badge sp-badge-blue" id="connection-status"><?php echo t('admin_terminal_status_prefix'); ?> <?php echo t('admin_terminal_status_waiting'); ?></span>
            <span class="sp-badge sp-badge-grey" id="current-server"><?php echo t('admin_terminal_server_prefix'); ?> <?php echo t('admin_terminal_value_none'); ?></span>
            <span class="sp-badge sp-badge-grey" id="last-command"><?php echo t('admin_terminal_last_command_prefix'); ?> <?php echo t('admin_terminal_value_none'); ?></span>
            <span class="sp-badge sp-badge-grey" id="runtime-stat"><?php echo t('admin_terminal_runtime_prefix'); ?> 00:00</span>
            <span class="sp-badge sp-badge-grey" id="line-count-stat"><?php echo t('admin_terminal_lines_prefix'); ?> 0</span>
            <span class="sp-badge sp-badge-amber" id="tmux-session-badge" style="display:none;"><?php echo t('admin_terminal_tmux_prefix'); ?> <?php echo t('admin_terminal_value_none'); ?></span>
        </div>
    </div>
    <div class="sp-form-group">
        <label class="sp-label"><?php echo t('admin_terminal_filter_output_label'); ?></label>
        <input class="sp-input" type="text" id="output-filter" placeholder="<?php echo htmlspecialchars(t('admin_terminal_filter_output_placeholder'), ENT_QUOTES); ?>">
    </div>
    <div style="background-color:#1e1e1e;color:#ffffff;font-family:'Courier New',monospace;height:500px;overflow-y:auto;white-space:pre-wrap;padding:1rem;border-radius:var(--radius);margin-bottom:1.25rem;" id="terminal-output">
        <div style="color: #00ff00;"><?php echo t('admin_terminal_ready'); ?></div>
        <div style="color: #888;"><?php echo t('admin_terminal_ready_hint'); ?></div>
    </div>
    <div class="sp-form-group" style="margin-top:1rem;">
        <label style="display:inline-flex;align-items:center;gap:0.5rem;margin-right:1.5rem;cursor:pointer;">
            <input type="checkbox" id="auto-scroll">
            <?php echo t('admin_terminal_auto_scroll_label'); ?>
        </label>
        <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;">
            <input type="checkbox" id="safe-mode" checked>
            <?php echo t('admin_terminal_safe_mode_label'); ?>
        </label>
    </div>
    </div><!-- /sp-card-body --></div><!-- /sp-card -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const T = {
    statusPrefix: <?php echo json_encode(t('admin_terminal_status_prefix')); ?>,
    serverPrefix: <?php echo json_encode(t('admin_terminal_server_prefix')); ?>,
    lastCommandPrefix: <?php echo json_encode(t('admin_terminal_last_command_prefix')); ?>,
    runtimePrefix: <?php echo json_encode(t('admin_terminal_runtime_prefix')); ?>,
    linesPrefix: <?php echo json_encode(t('admin_terminal_lines_prefix')); ?>,
    tmuxPrefix: <?php echo json_encode(t('admin_terminal_tmux_prefix')); ?>,
    valueNone: <?php echo json_encode(t('admin_terminal_value_none')); ?>,
    selectPresetOption: <?php echo json_encode(t('admin_terminal_select_preset_option')); ?>,
    selectSnippetOption: <?php echo json_encode(t('admin_terminal_select_snippet_option')); ?>,
    btnExecute: <?php echo json_encode(t('admin_terminal_btn_execute')); ?>,
    executing: <?php echo json_encode(t('admin_terminal_executing')); ?>,
    statusWaiting: <?php echo json_encode(t('admin_terminal_status_waiting')); ?>,
    nothingToCopy: <?php echo json_encode(t('admin_terminal_nothing_to_copy')); ?>,
    outputCopied: <?php echo json_encode(t('admin_terminal_output_copied')); ?>,
    clipboardFailed: <?php echo json_encode(t('admin_terminal_clipboard_failed')); ?>,
    nothingToDownload: <?php echo json_encode(t('admin_terminal_nothing_to_download')); ?>,
    logDownloadStarted: <?php echo json_encode(t('admin_terminal_log_download_started')); ?>,
    riskyTitle: <?php echo json_encode(t('admin_terminal_risky_title')); ?>,
    riskyText: <?php echo json_encode(t('admin_terminal_risky_text')); ?>,
    riskyConfirm: <?php echo json_encode(t('admin_terminal_risky_confirm')); ?>,
    riskyCancel: <?php echo json_encode(t('admin_terminal_risky_cancel')); ?>,
    idle: <?php echo json_encode(t('admin_terminal_idle')); ?>,
    connectedTo: <?php echo json_encode(t('admin_terminal_connected_to')); ?>,
    nothingToSave: <?php echo json_encode(t('admin_terminal_nothing_to_save')); ?>,
    snippetSaved: <?php echo json_encode(t('admin_terminal_snippet_saved')); ?>,
    terminalCleared: <?php echo json_encode(t('admin_terminal_cleared')); ?>,
    commandInterrupted: <?php echo json_encode(t('admin_terminal_command_interrupted')); ?>,
    commandInterruptedStatus: <?php echo json_encode(t('admin_terminal_command_interrupted_status')); ?>,
    sendingDetach: <?php echo json_encode(t('admin_terminal_sending_detach')); ?>,
    tmuxLabel: <?php echo json_encode(t('admin_terminal_tmux_label')); ?>,
    viewingTmux: <?php echo json_encode(t('admin_terminal_viewing_tmux')); ?>,
    tmuxTip: <?php echo json_encode(t('admin_terminal_tmux_tip')); ?>,
    commandCancelled: <?php echo json_encode(t('admin_terminal_command_cancelled')); ?>,
    executingOn: <?php echo json_encode(t('admin_terminal_executing_on')); ?>,
    streamingOutput: <?php echo json_encode(t('admin_terminal_streaming_output')); ?>,
    commandCompleted: <?php echo json_encode(t('admin_terminal_command_completed')); ?>,
    commandFinishedOk: <?php echo json_encode(t('admin_terminal_command_finished_ok')); ?>,
    commandFinishedErrors: <?php echo json_encode(t('admin_terminal_command_finished_errors')); ?>,
    errorPrefix: <?php echo json_encode(t('admin_terminal_error_prefix')); ?>,
    commandCompletedExit: <?php echo json_encode(t('admin_terminal_command_completed_exit')); ?>,
    connectionClosed: <?php echo json_encode(t('admin_terminal_connection_closed')); ?>,
    connectionLost: <?php echo json_encode(t('admin_terminal_connection_lost')); ?>,
    reconnecting: <?php echo json_encode(t('admin_terminal_reconnecting')); ?>,
    bannerVersion: <?php echo json_encode(t('admin_terminal_banner_version')); ?>,
    bannerCommandsHeading: <?php echo json_encode(t('admin_terminal_banner_commands_heading')); ?>,
    bannerHistoryNav: <?php echo json_encode(t('admin_terminal_banner_history_nav')); ?>,
    bannerSaveSnippets: <?php echo json_encode(t('admin_terminal_banner_save_snippets')); ?>,
    bannerPresets: <?php echo json_encode(t('admin_terminal_banner_presets')); ?>,
    bannerClear: <?php echo json_encode(t('admin_terminal_banner_clear')); ?>,
    bannerFilter: <?php echo json_encode(t('admin_terminal_banner_filter')); ?>,
    bannerSafeMode: <?php echo json_encode(t('admin_terminal_banner_safe_mode')); ?>,
    bannerInterrupt: <?php echo json_encode(t('admin_terminal_banner_interrupt')); ?>,
    bannerTmuxAttach: <?php echo json_encode(t('admin_terminal_banner_tmux_attach')); ?>,
    bannerTmuxDetach: <?php echo json_encode(t('admin_terminal_banner_tmux_detach')); ?>,
    historyRestored: <?php echo json_encode(t('admin_terminal_history_restored')); ?>,
    snippetsRestored: <?php echo json_encode(t('admin_terminal_snippets_restored')); ?>
};
const STATUS_CLASSES = {
    info: 'sp-badge-blue',
    success: 'sp-badge-green',
    warning: 'sp-badge-amber',
    danger: 'sp-badge-red'
};
const HISTORY_STORAGE_KEY = 'botofthespecter_webterminal_history';
const SNIPPETS_STORAGE_KEY = 'botofthespecter_webterminal_snippets';
const HISTORY_LIMIT = 100;
const SNIPPET_LIMIT = 40;
const DANGEROUS_COMMAND_PATTERNS = [
    /\brm\s+-rf\s+\//i,
    /\bdd\s+if=.*\bof=\/dev\//i,
    /\bmkfs(\.|\s)/i,
    /:\s*\(\)\s*\{\s*:\s*\|\s*:\s*&\s*\};\s*:/,
    /\bshutdown\b/i,
    /\breboot\b/i,
    /\bpoweroff\b/i,
    /\bhalt\b/i,
    /\bformat\b/i,
    /\bdel\s+\/f\s+\/s\s+\/q\b/i,
    /\btruncate\s+-s\s+0\b/i
];
const PRESET_COMMANDS = {
    bots: [
        { label: <?php echo json_encode(t('admin_terminal_preset_discord_status')); ?>, command: 'systemctl status discordbot.service --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_discord_logs')); ?>, command: 'journalctl -u discordbot.service -n 100 --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_export_status')); ?>, command: 'systemctl status export_queue_worker.service --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_export_logs')); ?>, command: 'journalctl -u export_queue_worker.service -n 100 --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_disk_usage')); ?>, command: 'df -h' }
    ],
    web: [
        { label: <?php echo json_encode(t('admin_terminal_preset_apache_status')); ?>, command: 'systemctl status apache2 --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_apache_access_log')); ?>, command: 'tail -n 100 /var/log/apache2/access.log' },
        { label: <?php echo json_encode(t('admin_terminal_preset_apache_error_log')); ?>, command: 'tail -n 100 /var/log/apache2/error.log' }
    ],
    api: [
        { label: <?php echo json_encode(t('admin_terminal_preset_fastapi_status')); ?>, command: 'systemctl status fastapi.service --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_fastapi_logs')); ?>, command: 'journalctl -u fastapi.service -n 100 --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_open_ports')); ?>, command: 'ss -tulpen | head -n 30' }
    ],
    websocket: [
        { label: <?php echo json_encode(t('admin_terminal_preset_ws_status')); ?>, command: 'systemctl status websocket.service --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_ws_logs')); ?>, command: 'journalctl -u websocket.service -n 100 --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_socket_connections')); ?>, command: 'ss -tunap | head -n 40' }
    ],
    sql: [
        { label: <?php echo json_encode(t('admin_terminal_preset_mysql_status')); ?>, command: 'systemctl status mysql.service --no-pager' },
        { label: <?php echo json_encode(t('admin_terminal_preset_mysql_processlist')); ?>, command: 'mysqladmin processlist' },
        { label: <?php echo json_encode(t('admin_terminal_preset_mysql_error_log')); ?>, command: 'tail -n 100 /var/log/mysql/error.log' }
    ]
};

let currentEventSource = null;
let commandHistory = loadHistory();
let historyIndex = -1;
let isExecuting = false;
let snippets = loadSnippets();
let lineCount = 0;
let executionStartTime = null;
let executionTimer = null;
let tmuxSessionActive = null;

const serverSelect = document.getElementById('server-select');
const commandInput = document.getElementById('command-input');
const executeBtn = document.getElementById('execute-btn');
const clearBtn = document.getElementById('clear-btn');
const interruptBtn = document.getElementById('interrupt-btn');
const presetSelect = document.getElementById('preset-select');
const runPresetBtn = document.getElementById('run-preset-btn');
const snippetSelect = document.getElementById('snippet-select');
const saveSnippetBtn = document.getElementById('save-snippet-btn');
const copyOutputBtn = document.getElementById('copy-output-btn');
const downloadOutputBtn = document.getElementById('download-output-btn');
const outputFilterInput = document.getElementById('output-filter');
const terminalOutput = document.getElementById('terminal-output');
const autoScrollCheckbox = document.getElementById('auto-scroll');
const safeModeCheckbox = document.getElementById('safe-mode');
const connectionStatusTag = document.getElementById('connection-status');
const currentServerTag = document.getElementById('current-server');
const lastCommandTag = document.getElementById('last-command');
const runtimeStatTag = document.getElementById('runtime-stat');
const lineCountStatTag = document.getElementById('line-count-stat');
const tmuxDetachBtn = document.getElementById('tmux-detach-btn');
const tmuxSessionBadge = document.getElementById('tmux-session-badge');

autoScrollCheckbox.checked = true;

function loadHistory() {
    try {
        const stored = localStorage.getItem(HISTORY_STORAGE_KEY);
        const parsed = stored ? JSON.parse(stored) : [];
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        console.warn('Failed to load terminal history', error);
        return [];
    }
}

function persistHistory() {
    localStorage.setItem(HISTORY_STORAGE_KEY, JSON.stringify(commandHistory));
}

function loadSnippets() {
    try {
        const stored = localStorage.getItem(SNIPPETS_STORAGE_KEY);
        const parsed = stored ? JSON.parse(stored) : [];
        return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
    } catch (error) {
        console.warn('Failed to load snippets', error);
        return [];
    }
}

function persistSnippets() {
    localStorage.setItem(SNIPPETS_STORAGE_KEY, JSON.stringify(snippets));
}

function renderSnippetOptions() {
    snippetSelect.innerHTML = '<option value=""></option>';
    snippetSelect.options[0].textContent = T.selectSnippetOption;
    snippets.forEach((snippet) => {
        const option = document.createElement('option');
        option.value = snippet;
        option.textContent = snippet;
        snippetSelect.appendChild(option);
    });
}

function saveSnippet(command) {
    if (!command) return;
    const existing = snippets.indexOf(command);
    if (existing !== -1) {
        snippets.splice(existing, 1);
    }
    snippets.push(command);
    if (snippets.length > SNIPPET_LIMIT) {
        snippets = snippets.slice(snippets.length - SNIPPET_LIMIT);
    }
    persistSnippets();
    renderSnippetOptions();
}

function refreshPresetOptions() {
    const server = serverSelect.value;
    const presets = PRESET_COMMANDS[server] || [];
    presetSelect.innerHTML = '<option value=""></option>';
    presetSelect.options[0].textContent = T.selectPresetOption;
    presets.forEach((preset) => {
        const option = document.createElement('option');
        option.value = preset.command;
        option.textContent = `${preset.label} - ${preset.command}`;
        presetSelect.appendChild(option);
    });
    const shouldDisable = !server || isExecuting || presets.length === 0;
    presetSelect.disabled = shouldDisable;
    runPresetBtn.disabled = shouldDisable;
}

function addToHistory(command) {
    if (!command || commandHistory[commandHistory.length - 1] === command) {
        return;
    }
    commandHistory.push(command);
    if (commandHistory.length > HISTORY_LIMIT) {
        commandHistory.shift();
    }
    persistHistory();
}

function navigateHistory(direction) {
    if (commandHistory.length === 0) return;

    if (direction === 'up') {
        if (historyIndex < commandHistory.length - 1) {
            historyIndex++;
            commandInput.value = commandHistory[commandHistory.length - 1 - historyIndex];
        }
    } else if (direction === 'down') {
        if (historyIndex > 0) {
            historyIndex--;
            commandInput.value = commandHistory[commandHistory.length - 1 - historyIndex];
        } else if (historyIndex === 0) {
            historyIndex = -1;
            commandInput.value = '';
        }
    }
}

function appendToTerminal(text, type = 'output') {
    const colors = {
        output: '#ffffff',
        error: '#ff6b6b',
        info: '#4ecdc4',
        success: '#95e1d3',
        command: '#feca57'
    };
    const div = document.createElement('div');
    const timestamp = new Date().toLocaleTimeString();
    div.style.color = colors[type] || colors.output;
    div.textContent = `[${timestamp}] ${text}`;
    const filterText = outputFilterInput.value.trim().toLowerCase();
    if (filterText && !div.textContent.toLowerCase().includes(filterText)) {
        div.style.display = 'none';
    }
    terminalOutput.appendChild(div);
    lineCount++;
    updateLiveStats();
    if (autoScrollCheckbox.checked) {
        terminalOutput.scrollTop = terminalOutput.scrollHeight;
    }
}

function getTerminalText() {
    return Array.from(terminalOutput.children).map(node => node.textContent || '').join('\n');
}

async function copyTerminalOutput() {
    const text = getTerminalText();
    if (!text.trim()) {
        setStatus(T.nothingToCopy, 'warning');
        return;
    }
    try {
        await navigator.clipboard.writeText(text);
        setStatus(T.outputCopied, 'success');
    } catch (error) {
        setStatus(T.clipboardFailed, 'danger');
        console.error('Clipboard copy failed', error);
    }
}

function downloadTerminalOutput() {
    const text = getTerminalText();
    if (!text.trim()) {
        setStatus(T.nothingToDownload, 'warning');
        return;
    }
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const timestamp = new Date().toISOString().replace(/[.:]/g, '-');
    link.href = url;
    link.download = `terminal-log-${timestamp}.txt`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    setStatus(T.logDownloadStarted, 'success');
}

function applyOutputFilter() {
    const filter = outputFilterInput.value.trim().toLowerCase();
    Array.from(terminalOutput.children).forEach((node) => {
        const text = (node.textContent || '').toLowerCase();
        node.style.display = !filter || text.includes(filter) ? '' : 'none';
    });
}

function formatDuration(ms) {
    if (!ms || ms < 0) return '00:00';
    const totalSeconds = Math.floor(ms / 1000);
    const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
    const seconds = (totalSeconds % 60).toString().padStart(2, '0');
    return `${minutes}:${seconds}`;
}

function updateLiveStats() {
    lineCountStatTag.textContent = `${T.linesPrefix} ${lineCount}`;
    const runtime = executionStartTime ? formatDuration(Date.now() - executionStartTime) : '00:00';
    runtimeStatTag.textContent = `${T.runtimePrefix} ${runtime}`;
}

function startExecutionTimer() {
    executionStartTime = Date.now();
    if (executionTimer) {
        clearInterval(executionTimer);
    }
    executionTimer = setInterval(updateLiveStats, 1000);
    updateLiveStats();
}

function stopExecutionTimer() {
    if (executionTimer) {
        clearInterval(executionTimer);
        executionTimer = null;
    }
    executionStartTime = null;
    updateLiveStats();
}

function commandLooksDangerous(command) {
    return DANGEROUS_COMMAND_PATTERNS.some((pattern) => pattern.test(command));
}

async function confirmCommandSafety(command) {
    if (!safeModeCheckbox.checked || !commandLooksDangerous(command)) {
        return { proceed: true, force: false };
    }
    const result = await Swal.fire({
        title: T.riskyTitle,
        text: T.riskyText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: T.riskyConfirm,
        cancelButtonText: T.riskyCancel,
        confirmButtonColor: '#d33'
    });
    return { proceed: result.isConfirmed, force: result.isConfirmed };
}

function setStatus(message, type = 'info') {
    connectionStatusTag.textContent = `${T.statusPrefix} ${message}`;
    connectionStatusTag.className = `sp-badge ${STATUS_CLASSES[type] || STATUS_CLASSES.info}`;
}

function setCurrentServer(label) {
    currentServerTag.textContent = `${T.serverPrefix} ${label}`;
}

function setLastCommandLabel(label) {
    lastCommandTag.textContent = `${T.lastCommandPrefix} ${label}`;
}

function cleanupEventSource() {
    if (currentEventSource) {
        currentEventSource.close();
        currentEventSource = null;
    }
}

function setExecutionState(executing) {
    isExecuting = executing;
    const disabled = executing || serverSelect.value === '';
    commandInput.disabled = disabled;
    executeBtn.disabled = disabled;
    interruptBtn.disabled = !executing;
    serverSelect.disabled = executing;
    saveSnippetBtn.disabled = disabled;
    refreshPresetOptions();
    if (executing) {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span></span>';
        executeBtn.querySelector('span:last-child').textContent = T.executing;
    } else {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-play"></i></span><span></span>';
        executeBtn.querySelector('span:last-child').textContent = T.btnExecute;
    }
}

function finalizeExecution(message, type = 'info', statusLabel = T.idle) {
    if (message) {
        appendToTerminal(message, type === 'danger' ? 'error' : 'info');
    }
    setStatus(statusLabel, type);
    stopExecutionTimer();
    cleanupEventSource();
    setExecutionState(false);
    commandInput.value = '';
    commandInput.focus();
}

serverSelect.addEventListener('change', function() {
    const serverSelected = this.value !== '';
    const label = serverSelected ? this.options[this.selectedIndex].text : T.valueNone;
    commandInput.disabled = !serverSelected || isExecuting;
    executeBtn.disabled = !serverSelected || isExecuting;
    saveSnippetBtn.disabled = !serverSelected || isExecuting;
    setCurrentServer(label);
    refreshPresetOptions();
    if (serverSelected) {
        setStatus(T.connectedTo.replace('%s', label), 'success');
        commandInput.focus();
    } else {
        setStatus(T.statusWaiting, 'info');
    }
});

commandInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !executeBtn.disabled) {
        executeCommand();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        navigateHistory('up');
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        navigateHistory('down');
    }
});

executeBtn.addEventListener('click', executeCommand);

runPresetBtn.addEventListener('click', function() {
    const preset = presetSelect.value;
    if (!preset || isExecuting) return;
    commandInput.value = preset;
    executeCommand();
});

snippetSelect.addEventListener('change', function() {
    if (!this.value || isExecuting || serverSelect.value === '') return;
    commandInput.value = this.value;
    commandInput.focus();
});

saveSnippetBtn.addEventListener('click', function() {
    const command = commandInput.value.trim();
    if (!command) {
        setStatus(T.nothingToSave, 'warning');
        return;
    }
    saveSnippet(command);
    setStatus(T.snippetSaved, 'success');
});

copyOutputBtn.addEventListener('click', copyTerminalOutput);
downloadOutputBtn.addEventListener('click', downloadTerminalOutput);
outputFilterInput.addEventListener('input', applyOutputFilter);

clearBtn.addEventListener('click', function() {
    terminalOutput.innerHTML = '<div style="color: #00ff00;"></div>';
    terminalOutput.firstChild.textContent = T.terminalCleared;
    lineCount = 1;
    updateLiveStats();
    setStatus(T.terminalCleared, 'info');
});

interruptBtn.addEventListener('click', function() {
    if (!currentEventSource) return;
    cleanupEventSource();
    finalizeExecution(T.commandInterrupted, 'danger', T.commandInterruptedStatus);
});

tmuxDetachBtn.addEventListener('click', async function() {
    if (!tmuxSessionActive || isExecuting) return;
    const session = tmuxSessionActive;
    tmuxSessionActive = null;
    tmuxDetachBtn.style.display = 'none';
    tmuxSessionBadge.style.display = 'none';
    appendToTerminal(`${T.sendingDetach} ${session}`, 'info');
    commandInput.value = `tmux detach-client -s ${session}`;
    await executeCommand();
});

async function executeCommand() {
    const server = serverSelect.value;
    let command = commandInput.value.trim();
    if (!server || !command) return;
    if (command === 'clear') {
        clearBtn.click();
        commandInput.value = '';
        return;
    }
    // Detect tmux attach command and convert to capture-pane (attach requires an interactive TTY)
    const tmuxAttachMatch = command.match(/^tmux\s+(?:attach(?:-session)?|a)\b.*?-t[= ]([a-zA-Z0-9_\-]+)/i);
    if (tmuxAttachMatch) {
        const session = tmuxAttachMatch[1];
        tmuxSessionActive = session;
        tmuxDetachBtn.style.display = '';
        tmuxSessionBadge.style.display = '';
        tmuxSessionBadge.textContent = `${T.tmuxLabel} ${session}`;
        command = `tmux capture-pane -t ${session} -p -S -2000`;
        appendToTerminal(`${T.viewingTmux} ${session}`, 'info');
        appendToTerminal(T.tmuxTip, 'info');
    }
    const safetyDecision = await confirmCommandSafety(command);
    if (!safetyDecision.proceed) {
        setStatus(T.commandCancelled, 'warning');
        return;
    }
    addToHistory(command);
    historyIndex = -1;
    appendToTerminal(`$ ${command}`, 'command');
    setLastCommandLabel(command);
    setStatus(T.executingOn.replace('%s', serverSelect.options[serverSelect.selectedIndex].text), 'warning');
    setExecutionState(true);
    startExecutionTimer();
    const encodedCommand = encodeURIComponent(command);
    const encodedServer = encodeURIComponent(server);
    const encodedSafeMode = safeModeCheckbox.checked ? '1' : '0';
    const encodedForce = safetyDecision.force ? '1' : '0';
    currentEventSource = new EventSource(`terminal_stream.php?server=${encodedServer}&command=${encodedCommand}&safe=${encodedSafeMode}&force=${encodedForce}`);

    currentEventSource.onmessage = function(event) {
        if (event.data) {
            appendToTerminal(event.data);
        }
    };

    currentEventSource.addEventListener('open', function() {
        setStatus(T.streamingOutput, 'warning');
    });

    currentEventSource.addEventListener('done', function(event) {
        let message = T.commandCompleted;
        let eventType = 'success';
        let statusLabel = T.commandFinishedOk;
        try {
            const payload = JSON.parse(event.data);
            if (payload.error) {
                message = `${T.errorPrefix} ${payload.error}`;
                eventType = 'danger';
                statusLabel = T.commandFinishedErrors;
            } else {
                message = T.commandCompletedExit.replace('%s', payload.exit_code || 0);
            }
        } catch (error) {
            message = T.commandCompleted;
        }
        finalizeExecution(message, eventType === 'danger' ? 'danger' : 'info', statusLabel);
    });

    currentEventSource.onerror = function() {
        if (!currentEventSource) {
            return;
        }
        if (currentEventSource.readyState === EventSource.CLOSED) {
            finalizeExecution(T.connectionClosed, 'danger', T.connectionLost);
        } else if (currentEventSource.readyState === EventSource.CONNECTING) {
            setStatus(T.reconnecting, 'warning');
        }
    };
}

document.addEventListener('DOMContentLoaded', function() {
    setStatus(T.statusWaiting, 'info');
    renderSnippetOptions();
    refreshPresetOptions();
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal(T.bannerVersion, 'success');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal(T.bannerCommandsHeading, 'info');
    appendToTerminal('  - ' + T.bannerHistoryNav, 'info');
    appendToTerminal('  - ' + T.bannerSaveSnippets, 'info');
    appendToTerminal('  - ' + T.bannerPresets, 'info');
    appendToTerminal('  - ' + T.bannerClear, 'info');
    appendToTerminal('  - ' + T.bannerFilter, 'info');
    appendToTerminal('  - ' + T.bannerSafeMode, 'info');
    appendToTerminal('  - ' + T.bannerInterrupt, 'info');
    appendToTerminal('  - ' + T.bannerTmuxAttach, 'info');
    appendToTerminal('  - ' + T.bannerTmuxDetach, 'info');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal(T.historyRestored.replace('%s', commandHistory.length), 'info');
    appendToTerminal(T.snippetsRestored.replace('%s', snippets.length), 'info');
    appendToTerminal('');
    updateLiveStats();
});
</script>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>