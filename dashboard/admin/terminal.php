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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        <p class="help">Press Enter to execute or click the Execute button. Use 'clear' to clear the terminal.</p>
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

<script>
let currentEventSource = null;
let commandHistory = [];
let historyIndex = -1;
let isExecuting = false;

const serverSelect = document.getElementById('server-select');
const commandInput = document.getElementById('command-input');
const executeBtn = document.getElementById('execute-btn');
const clearBtn = document.getElementById('clear-btn');
const interruptBtn = document.getElementById('interrupt-btn');
const terminalOutput = document.getElementById('terminal-output');
const autoScrollCheckbox = document.getElementById('auto-scroll');

// Set auto-scroll to checked by default
autoScrollCheckbox.checked = true;

// Enable/disable controls based on server selection
serverSelect.addEventListener('change', function() {
    const serverSelected = this.value !== '';
    commandInput.disabled = !serverSelected || isExecuting;
    executeBtn.disabled = !serverSelected || isExecuting;
    
    if (serverSelected) {
        commandInput.focus();
        appendToTerminal(`Connected to ${this.options[this.selectedIndex].text}`, 'info');
    }
});

// Execute command on Enter key
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

// Command history navigation
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

// Execute button click
executeBtn.addEventListener('click', executeCommand);

// Clear terminal
clearBtn.addEventListener('click', function() {
    terminalOutput.innerHTML = '<div style="color: #00ff00;">Terminal cleared</div>';
});

// Interrupt current command
interruptBtn.addEventListener('click', function() {
    if (currentEventSource) {
        currentEventSource.close();
        currentEventSource = null;
        setExecutionState(false);
        appendToTerminal('Command interrupted by user', 'error');
    }
});

function appendToTerminal(text, type = 'output') {
    const colors = {
        'output': '#ffffff',
        'error': '#ff6b6b',
        'info': '#4ecdc4',
        'success': '#95e1d3',
        'command': '#feca57'
    };
    
    const color = colors[type] || colors.output;
    const div = document.createElement('div');
    div.style.color = color;
    div.textContent = text;
    terminalOutput.appendChild(div);
    
    if (autoScrollCheckbox.checked) {
        terminalOutput.scrollTop = terminalOutput.scrollHeight;
    }
}

function setExecutionState(executing) {
    isExecuting = executing;
    commandInput.disabled = executing || serverSelect.value === '';
    executeBtn.disabled = executing || serverSelect.value === '';
    interruptBtn.disabled = !executing;
    
    if (executing) {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Executing...</span>';
    } else {
        executeBtn.innerHTML = '<span class="icon"><i class="fas fa-play"></i></span><span>Execute</span>';
    }
}

function executeCommand() {
    const server = serverSelect.value;
    const command = commandInput.value.trim();
    
    if (!server || !command) return;
    
    // Handle local 'clear' command
    if (command === 'clear') {
        clearBtn.click();
        commandInput.value = '';
        return;
    }
    
    // Add to command history
    if (commandHistory[commandHistory.length - 1] !== command) {
        commandHistory.push(command);
        if (commandHistory.length > 100) { // Limit history to 100 commands
            commandHistory.shift();
        }
    }
    historyIndex = -1;
    
    // Display command in terminal
    appendToTerminal(`$ ${command}`, 'command');
    
    setExecutionState(true);
    
    // Create EventSource for streaming output
    const encodedCommand = encodeURIComponent(command);
    const encodedServer = encodeURIComponent(server);
    currentEventSource = new EventSource(`terminal_stream.php?server=${encodedServer}&command=${encodedCommand}`);
    
    currentEventSource.onmessage = function(event) {
        if (event.data) {
            appendToTerminal(event.data);
        }
    };
    
    currentEventSource.addEventListener('error', function(event) {
        if (event.data) {
            appendToTerminal(event.data, 'error');
        }
    });
    
    currentEventSource.addEventListener('done', function(event) {
        try {
            const result = JSON.parse(event.data);
            if (result.error) {
                appendToTerminal(`Error: ${result.error}`, 'error');
            } else {
                appendToTerminal(`Command completed (exit code: ${result.exit_code || 0})`, 'info');
            }
        } catch (e) {
            appendToTerminal('Command completed', 'info');
        }
        
        currentEventSource.close();
        currentEventSource = null;
        setExecutionState(false);
        commandInput.value = '';
        commandInput.focus();
    });
    
    currentEventSource.onerror = function() {
        if (currentEventSource.readyState === EventSource.CLOSED) {
            appendToTerminal('Connection closed', 'error');
            setExecutionState(false);
        }
    };
}

// Focus command input when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Add welcome message
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('BotOfTheSpecter Web Terminal v1.0', 'success');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('Commands:', 'info');
    appendToTerminal('  - Use ↑/↓ arrows to navigate command history', 'info');
    appendToTerminal('  - Type "clear" to clear the terminal', 'info');
    appendToTerminal('  - Press Ctrl+C or click Interrupt to stop running commands', 'info');
    appendToTerminal('='.repeat(60), 'info');
    appendToTerminal('');
});
</script>

<?php
$content = ob_get_clean();
include "admin_layout.php";
?>