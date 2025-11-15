<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '../userdata.php';
$pageTitle = "Web Terminal";

ob_start();
?>
<div class="box">
    <h1 class="title is-3"><span class="icon"><i class="fas fa-terminal"></i></span> Web Terminal</h1>
    <p class="mb-4">Execute commands on remote servers and view live output. Select a server and enter commands below.</p>
    <div class="field">
        <label class="label">Select Server</label>
        <div class="control">
            <div class="select">
                <select id="server-select">
                    <option value="">Choose a server...</option>
                    <option value="bots">Bot Server</option>
                    <option value="web">Web Server</option>
                    <option value="api">API Server</option>
                    <option value="websocket">WebSocket Server</option>
                    <option value="sql">SQL Server</option>
                </select>
            </div>
        </div>
    </div>
    <div class="field">
        <label class="label">Command</label>
        <div class="control">
            <input class="input" type="text" id="command-input" placeholder="Enter command..." disabled>
        </div>
        <p class="help">Press Enter or click Execute. Use 'clear' to reset the terminal.</p>
    </div>
    <div class="field">
        <div class="control">
            <button class="button is-primary" id="execute-btn" disabled>
                <span class="icon"><i class="fas fa-play"></i></span>
                <span>Execute</span>
            </button>
            <button class="button is-light" id="clear-btn">
                <span class="icon"><i class="fas fa-broom"></i></span>
                <span>Clear Terminal</span>
            </button>
            <button class="button is-warning" id="interrupt-btn" disabled>
                <span class="icon"><i class="fas fa-stop"></i></span>
                <span>Interrupt</span>
            </button>
        </div>
    </div>
    <div class="field">
        <div class="control">
            <div class="tags terminal-metadata">
                <span class="tag is-info" id="connection-status">Status: waiting for server selection</span>
                <span class="tag is-dark has-text-white" id="current-server">Server: none</span>
                <span class="tag is-dark has-text-white" id="last-command">Last command: none</span>
            </div>
        </div>
    </div>
    <div class="box" style="background-color: #1e1e1e; color: #ffffff; font-family: 'Courier New', monospace; height: 500px; overflow-y: auto; white-space: pre-wrap; padding: 1rem;" id="terminal-output">
        <div style="color: #00ff00;">Web Terminal Ready</div>
        <div style="color: #888;">Select a server and enter commands to get started.</div>
    </div>
    <div class="field mt-4">
        <div class="control">
            <label class="checkbox">
                <input type="checkbox" id="auto-scroll">
                Auto-scroll to bottom
            </label>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const STATUS_CLASSES = {
    info: 'is-info',
    success: 'is-success',
    warning: 'is-warning',
    danger: 'is-danger'
};
const HISTORY_STORAGE_KEY = 'botofthespecter_webterminal_history';
const HISTORY_LIMIT = 100;

let currentEventSource = null;
let commandHistory = loadHistory();
let historyIndex = -1;
let isExecuting = false;

const serverSelect = document.getElementById('server-select');
const commandInput = document.getElementById('command-input');
const executeBtn = document.getElementById('execute-btn');
const clearBtn = document.getElementById('clear-btn');
const interruptBtn = document.getElementById('interrupt-btn');
const terminalOutput = document.getElementById('terminal-output');
const autoScrollCheckbox = document.getElementById('auto-scroll');
const connectionStatusTag = document.getElementById('connection-status');
const currentServerTag = document.getElementById('current-server');
const lastCommandTag = document.getElementById('last-command');

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
    terminalOutput.appendChild(div);
    if (autoScrollCheckbox.checked) {
        terminalOutput.scrollTop = terminalOutput.scrollHeight;
    }
}

function setStatus(message, type = 'info') {
    connectionStatusTag.textContent = `Status: ${message}`;
    connectionStatusTag.className = `tag ${STATUS_CLASSES[type] || STATUS_CLASSES.info}`;
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
    if (executing) {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Executing...</span>';
    } else {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-play"></i></span><span>Execute</span>';
    }
}

function finalizeExecution(message, type = 'info', statusLabel = 'Idle') {
    if (message) {
        appendToTerminal(message, type === 'danger' ? 'error' : 'info');
    }
    setStatus(statusLabel, type);
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
    setCurrentServer(label);
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

clearBtn.addEventListener('click', function() {
    terminalOutput.innerHTML = '<div style="color: #00ff00;">Terminal cleared</div>';
    setStatus('Terminal cleared', 'info');
});

interruptBtn.addEventListener('click', function() {
    if (!currentEventSource) return;
    cleanupEventSource();
    finalizeExecution('Command interrupted by user', 'danger', 'Command interrupted');
});

function executeCommand() {
    const server = serverSelect.value;
    const command = commandInput.value.trim();
    if (!server || !command) return;
    if (command === 'clear') {
        clearBtn.click();
        commandInput.value = '';
        return;
    }
    addToHistory(command);
    historyIndex = -1;
    appendToTerminal(`$ ${command}`, 'command');
    setLastCommandLabel(command);
    setStatus(`Executing command on ${serverSelect.options[serverSelect.selectedIndex].text}`, 'warning');
    setExecutionState(true);
    const encodedCommand = encodeURIComponent(command);
    const encodedServer = encodeURIComponent(server);
    currentEventSource = new EventSource(`terminal_stream.php?server=${encodedServer}&command=${encodedCommand}`);

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
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('BotOfTheSpecter Web Terminal v1.1', 'success');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('Commands:', 'info');
    appendToTerminal('  - Use ↑/↓ arrows to navigate command history', 'info');
    appendToTerminal('  - Type "clear" to clear the terminal', 'info');
    appendToTerminal('  - Press Ctrl+C or click Interrupt to stop running commands', 'info');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('Command history restored: ' + commandHistory.length + ' entries', 'info');
    appendToTerminal('');
});
</script>
<?php
$content = ob_get_clean();
include "admin_layout.php";
?>