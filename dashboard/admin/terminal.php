<?php
session_start();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '../userdata.php';
$pageTitle = "Web Terminal";

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><span class="icon"><i class="fas fa-terminal"></i></span> Web Terminal</h1>
    </div>
    <div class="sp-card-body">
    <p style="margin-bottom:1rem;">Execute commands on remote servers and view live output. Select a server and enter commands below.</p>
    <div class="sp-form-group">
        <label class="sp-label">Select Server</label>
        <select class="sp-select" id="server-select">
            <option value="">Choose a server...</option>
            <option value="bots">Bot Server</option>
            <option value="web">Web Server</option>
            <option value="api">API Server</option>
            <option value="websocket">WebSocket Server</option>
            <option value="sql">SQL Server</option>
        </select>
    </div>
    <div class="sp-form-group">
        <label class="sp-label">Command</label>
        <input class="sp-input" type="text" id="command-input" placeholder="Enter command..." disabled>
        <p class="sp-help">Press Enter or click Execute. Use 'clear' to reset the terminal.</p>
    </div>
    <div class="sp-card" style="margin-bottom:1.25rem;">
        <div class="sp-card-header">
            <h2 class="sp-card-title"><span class="icon"><i class="fas fa-toolbox"></i></span> Terminal Tools</h2>
        </div>
        <div class="sp-card-body" style="padding:0.75rem 1rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label class="sp-label">Quick Preset</label>
                <div class="sp-btn-group">
                    <select class="sp-select" id="preset-select" disabled style="flex:1;">
                        <option value="">Select preset command...</option>
                    </select>
                    <button class="sp-btn sp-btn-info" id="run-preset-btn" disabled>
                        <span class="icon"><i class="fas fa-bolt"></i></span>
                        <span>Run</span>
                    </button>
                </div>
            </div>
            <div>
                <label class="sp-label">Saved Snippets</label>
                <div class="sp-btn-group">
                    <select class="sp-select" id="snippet-select" style="flex:1;">
                        <option value="">Select saved snippet...</option>
                    </select>
                    <button class="sp-btn" id="save-snippet-btn" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save</span>
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
                <span>Execute</span>
            </button>
            <button class="sp-btn" id="clear-btn">
                <span class="icon"><i class="fas fa-broom"></i></span>
                <span>Clear Terminal</span>
            </button>
            <button class="sp-btn sp-btn-warning" id="interrupt-btn" disabled>
                <span class="icon"><i class="fas fa-stop"></i></span>
                <span>Interrupt</span>
            </button>
            <button class="sp-btn sp-btn-info" id="copy-output-btn">
                <span class="icon"><i class="fas fa-copy"></i></span>
                <span>Copy Output</span>
            </button>
            <button class="sp-btn sp-btn-info" id="download-output-btn">
                <span class="icon"><i class="fas fa-download"></i></span>
                <span>Download Log</span>
            </button>
        </div>
    </div>
    <div class="sp-form-group">
        <div style="display:flex;flex-wrap:wrap;gap:0.35rem;">
            <span class="sp-badge sp-badge-blue" id="connection-status">Status: waiting for server selection</span>
            <span class="sp-badge sp-badge-grey" id="current-server">Server: none</span>
            <span class="sp-badge sp-badge-grey" id="last-command">Last command: none</span>
            <span class="sp-badge sp-badge-grey" id="runtime-stat">Runtime: 00:00</span>
            <span class="sp-badge sp-badge-grey" id="line-count-stat">Lines: 0</span>
        </div>
    </div>
    <div class="sp-form-group">
        <label class="sp-label">Filter Output</label>
        <input class="sp-input" type="text" id="output-filter" placeholder="Type to filter terminal output...">
    </div>
    <div style="background-color:#1e1e1e;color:#ffffff;font-family:'Courier New',monospace;height:500px;overflow-y:auto;white-space:pre-wrap;padding:1rem;border-radius:var(--radius);margin-bottom:1.25rem;" id="terminal-output">
        <div style="color: #00ff00;">Web Terminal Ready</div>
        <div style="color: #888;">Select a server and enter commands to get started.</div>
    </div>
    <div class="sp-form-group" style="margin-top:1rem;">
        <label style="display:inline-flex;align-items:center;gap:0.5rem;margin-right:1.5rem;cursor:pointer;">
            <input type="checkbox" id="auto-scroll">
            Auto-scroll to bottom
        </label>
        <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;">
            <input type="checkbox" id="safe-mode" checked>
            Safe mode (blocks risky commands unless confirmed)
        </label>
    </div>
    </div><!-- /sp-card-body --></div><!-- /sp-card -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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
        { label: 'Discord bot service status', command: 'systemctl status discordbot.service --no-pager' },
        { label: 'Discord bot logs', command: 'journalctl -u discordbot.service -n 100 --no-pager' },
        { label: 'Export queue worker status', command: 'systemctl status export_queue_worker.service --no-pager' },
        { label: 'Export queue worker logs', command: 'journalctl -u export_queue_worker.service -n 100 --no-pager' },
        { label: 'Disk usage', command: 'df -h' }
    ],
    web: [
        { label: 'Apache status', command: 'systemctl status apache2 --no-pager' },
        { label: 'Apache access log (tail)', command: 'tail -n 100 /var/log/apache2/access.log' },
        { label: 'Apache error log (tail)', command: 'tail -n 100 /var/log/apache2/error.log' }
    ],
    api: [
        { label: 'FastAPI service status', command: 'systemctl status fastapi.service --no-pager' },
        { label: 'FastAPI logs', command: 'journalctl -u fastapi.service -n 100 --no-pager' },
        { label: 'Open ports', command: 'ss -tulpen | head -n 30' }
    ],
    websocket: [
        { label: 'WebSocket service status', command: 'systemctl status websocket.service --no-pager' },
        { label: 'WebSocket logs', command: 'journalctl -u websocket.service -n 100 --no-pager' },
        { label: 'Socket connections', command: 'ss -tunap | head -n 40' }
    ],
    sql: [
        { label: 'MySQL service status', command: 'systemctl status mysql.service --no-pager' },
        { label: 'MySQL process list', command: 'mysqladmin processlist' },
        { label: 'MySQL error log (tail)', command: 'tail -n 100 /var/log/mysql/error.log' }
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
    snippetSelect.innerHTML = '<option value="">Select saved snippet...</option>';
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
    presetSelect.innerHTML = '<option value="">Select preset command...</option>';
    presets.forEach((preset) => {
        const option = document.createElement('option');
        option.value = preset.command;
        option.textContent = `${preset.label} — ${preset.command}`;
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
        setStatus('Nothing to copy', 'warning');
        return;
    }
    try {
        await navigator.clipboard.writeText(text);
        setStatus('Output copied to clipboard', 'success');
    } catch (error) {
        setStatus('Clipboard copy failed', 'danger');
        console.error('Clipboard copy failed', error);
    }
}

function downloadTerminalOutput() {
    const text = getTerminalText();
    if (!text.trim()) {
        setStatus('Nothing to download', 'warning');
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
    setStatus('Log download started', 'success');
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
    lineCountStatTag.textContent = `Lines: ${lineCount}`;
    const runtime = executionStartTime ? formatDuration(Date.now() - executionStartTime) : '00:00';
    runtimeStatTag.textContent = `Runtime: ${runtime}`;
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
        title: 'Risky command detected',
        text: 'This command looks destructive. Continue anyway?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Run anyway',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33'
    });
    return { proceed: result.isConfirmed, force: result.isConfirmed };
}

function setStatus(message, type = 'info') {
    connectionStatusTag.textContent = `Status: ${message}`;
    connectionStatusTag.className = `sp-badge ${STATUS_CLASSES[type] || STATUS_CLASSES.info}`;
}

function setCurrentServer(label) {
    currentServerTag.textContent = `Server: ${label}`;
}

function setLastCommandLabel(label) {
    lastCommandTag.textContent = `Last command: ${label}`;
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
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Executing...</span>';;
    } else {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-play"></i></span><span>Execute</span>';
    }
}

function finalizeExecution(message, type = 'info', statusLabel = 'Idle') {
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
    const label = serverSelected ? this.options[this.selectedIndex].text : 'none';
    commandInput.disabled = !serverSelected || isExecuting;
    executeBtn.disabled = !serverSelected || isExecuting;
    saveSnippetBtn.disabled = !serverSelected || isExecuting;
    setCurrentServer(label);
    refreshPresetOptions();
    if (serverSelected) {
        setStatus(`Connected to ${label}`, 'success');
        commandInput.focus();
    } else {
        setStatus('Waiting for server selection', 'info');
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
        setStatus('Nothing to save as snippet', 'warning');
        return;
    }
    saveSnippet(command);
    setStatus('Snippet saved', 'success');
});

copyOutputBtn.addEventListener('click', copyTerminalOutput);
downloadOutputBtn.addEventListener('click', downloadTerminalOutput);
outputFilterInput.addEventListener('input', applyOutputFilter);

clearBtn.addEventListener('click', function() {
    terminalOutput.innerHTML = '<div style="color: #00ff00;">Terminal cleared</div>';
    lineCount = 1;
    updateLiveStats();
    setStatus('Terminal cleared', 'info');
});

interruptBtn.addEventListener('click', function() {
    if (!currentEventSource) return;
    cleanupEventSource();
    finalizeExecution('Command interrupted by user', 'danger', 'Command interrupted');
});

async function executeCommand() {
    const server = serverSelect.value;
    const command = commandInput.value.trim();
    if (!server || !command) return;
    if (command === 'clear') {
        clearBtn.click();
        commandInput.value = '';
        return;
    }
    const safetyDecision = await confirmCommandSafety(command);
    if (!safetyDecision.proceed) {
        setStatus('Command cancelled', 'warning');
        return;
    }
    addToHistory(command);
    historyIndex = -1;
    appendToTerminal(`$ ${command}`, 'command');
    setLastCommandLabel(command);
    setStatus(`Executing command on ${serverSelect.options[serverSelect.selectedIndex].text}`, 'warning');
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
        setStatus('Streaming output...', 'warning');
    });

    currentEventSource.addEventListener('done', function(event) {
        let message = 'Command completed';
        let eventType = 'success';
        let statusLabel = 'Command finished successfully';
        try {
            const payload = JSON.parse(event.data);
            if (payload.error) {
                message = `Error: ${payload.error}`;
                eventType = 'danger';
                statusLabel = 'Command finished with errors';
            } else {
                message = `Command completed (exit code: ${payload.exit_code || 0})`;
            }
        } catch (error) {
            message = 'Command completed';
        }
        finalizeExecution(message, eventType === 'danger' ? 'danger' : 'info', statusLabel);
    });

    currentEventSource.onerror = function() {
        if (!currentEventSource) {
            return;
        }
        if (currentEventSource.readyState === EventSource.CLOSED) {
            finalizeExecution('Connection closed unexpectedly', 'danger', 'Connection lost');
        } else if (currentEventSource.readyState === EventSource.CONNECTING) {
            setStatus('Reconnecting to stream...', 'warning');
        }
    };
}

document.addEventListener('DOMContentLoaded', function() {
    setStatus('Waiting for server selection', 'info');
    renderSnippetOptions();
    refreshPresetOptions();
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('BotOfTheSpecter Web Terminal v1.2', 'success');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('Commands:', 'info');
    appendToTerminal('  - Use ↑/↓ arrows to navigate command history', 'info');
    appendToTerminal('  - Save snippets for commands you run frequently', 'info');
    appendToTerminal('  - Choose server-specific presets from Terminal Tools', 'info');
    appendToTerminal('  - Type "clear" to clear the terminal', 'info');
    appendToTerminal('  - Use output filter, copy output, or download log anytime', 'info');
    appendToTerminal('  - Safe mode warns on risky commands', 'info');
    appendToTerminal('  - Press Ctrl+C or click Interrupt to stop running commands', 'info');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('Command history restored: ' + commandHistory.length + ' entries', 'info');
    appendToTerminal('Saved snippets restored: ' + snippets.length + ' entries', 'info');
    appendToTerminal('');
    updateLiveStats();
});
</script>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>