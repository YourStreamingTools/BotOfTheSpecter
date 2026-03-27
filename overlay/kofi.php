<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ko-fi Overlay</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: transparent;
            overflow: hidden;
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
        }
        #kofi-container {
            position: fixed;
            bottom: 40px;
            left: 40px;
            display: flex;
            flex-direction: column-reverse;
            gap: 12px;
            pointer-events: none;
        }
        .kofi-alert {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: linear-gradient(135deg, rgba(15, 15, 20, 0.95) 0%, rgba(20, 25, 35, 0.95) 100%);
            border-left: 4px solid #29abe0;
            border-radius: 10px;
            padding: 14px 18px;
            min-width: 320px;
            max-width: 420px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(41, 171, 224, 0.2);
            opacity: 0;
            transform: translateX(-40px);
            animation: kofi-slide-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        .kofi-alert.kofi-dismissing {
            animation: kofi-slide-out 0.35s ease-in forwards;
        }
        /* Per-type accent colours */
        .kofi-alert.kofi-donation     { border-left-color: #f39c12; box-shadow: 0 6px 24px rgba(0,0,0,0.6), 0 0 0 1px rgba(243,156,18,0.2); }
        .kofi-alert.kofi-subscription { border-left-color: #29abe0; box-shadow: 0 6px 24px rgba(0,0,0,0.6), 0 0 0 1px rgba(41,171,224,0.2); }
        .kofi-alert.kofi-shop         { border-left-color: #2ecc71; box-shadow: 0 6px 24px rgba(0,0,0,0.6), 0 0 0 1px rgba(46,204,113,0.2); }
        .kofi-icon {
            font-size: 28px;
            line-height: 1;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .kofi-body {
            flex: 1;
            min-width: 0;
        }
        .kofi-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.45);
            margin-bottom: 4px;
        }
        .kofi-alert.kofi-donation     .kofi-label { color: rgba(243, 156, 18, 0.75); }
        .kofi-alert.kofi-subscription .kofi-label { color: rgba(41, 171, 224, 0.75); }
        .kofi-alert.kofi-shop         .kofi-label { color: rgba(46, 204, 113, 0.75); }
        .kofi-headline {
            font-size: 15px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kofi-detail {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.65);
            margin-top: 4px;
            line-height: 1.4;
            word-break: break-word;
        }
        .kofi-amount {
            font-size: 13px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.85);
            margin-top: 3px;
        }
        .kofi-alert.kofi-donation     .kofi-amount { color: #f39c12; }
        .kofi-alert.kofi-subscription .kofi-amount { color: #29abe0; }
        .kofi-alert.kofi-shop         .kofi-amount { color: #2ecc71; }
        @keyframes kofi-slide-in {
            from { opacity: 0; transform: translateX(-40px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes kofi-slide-out {
            from { opacity: 1; transform: translateX(0); }
            to   { opacity: 0; transform: translateX(-40px); }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            const alertQueue = [];
            let alertDisplaying = false;
            const ALERT_DURATION = 7000; // ms each alert stays visible
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                console.error('No code provided in the URL');
                return;
            }
            // Parse the data field which may arrive as a Python dict string or JSON string
            function parseKofiData(raw) {
                if (!raw) return null;
                if (typeof raw === 'object') return raw;
                // Try standard JSON first
                try {
                    return JSON.parse(raw);
                } catch (_) {}
                // Fall back: convert Python dict notation to JSON
                try {
                    const jsonStr = raw
                        .replace(/'/g, '"')
                        .replace(/\bNone\b/g, 'null')
                        .replace(/\bTrue\b/g, 'true')
                        .replace(/\bFalse\b/g, 'false');
                    return JSON.parse(jsonStr);
                } catch (_) {}
                return null;
            }
            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
            // Build alert element for each known Ko-fi event type
            function buildAlertElement(eventData) {
                const el = document.createElement('div');
                el.className = 'kofi-alert';
                const eventType = eventData.type || '';
                let icon = '☕', cssClass = '', label = 'Ko-fi', headline = '', detail = '', amount = '';
                if (eventType === 'Donation') {
                    cssClass = 'kofi-donation';
                    icon = '💰';
                    label = 'Ko-fi Donation';
                    const name = eventData.from_name || 'Someone';
                    const amt = eventData.amount || '';
                    const cur = eventData.currency || '';
                    const msg = eventData.message || '';
                    headline = `${name} donated!`;
                    amount = amt && cur ? `${amt} ${cur}` : amt;
                    detail = msg;
                } else if (eventType === 'Subscription') {
                    cssClass = 'kofi-subscription';
                    icon = '⭐';
                    label = 'Ko-fi Subscription';
                    const name = eventData.from_name || 'Someone';
                    const amt = eventData.amount || '';
                    const cur = eventData.currency || '';
                    const tier = eventData.tier_name || '';
                    const isFirst = eventData.is_first_subscription_payment;
                    headline = isFirst ? `${name} subscribed!` : `${name} renewed their subscription!`;
                    amount = amt && cur ? `${amt} ${cur}` : amt;
                    detail = tier ? `Tier: ${tier}` : '';
                } else if (eventType === 'Shop Order') {
                    cssClass = 'kofi-shop';
                    icon = '🛒';
                    label = 'Ko-fi Shop';
                    const name = eventData.from_name || 'Someone';
                    const amt = eventData.amount || '';
                    const cur = eventData.currency || '';
                    const items = eventData.shop_items || [];
                    headline = `${name} placed an order!`;
                    amount = amt && cur ? `${amt} ${cur}` : amt;
                    if (items.length > 0) {
                        detail = items.map(i => `${i.quantity}\u00d7 ${i.variation_name}`).join(', ');
                    }
                } else {
                    // Generic fallback
                    icon = '☕';
                    label = 'Ko-fi';
                    headline = eventType.replace(/_/g, ' ') || 'Ko-fi Event';
                }
                el.classList.add(cssClass);
                el.innerHTML = `
                    <div class="kofi-icon">${icon}</div>
                    <div class="kofi-body">
                        <div class="kofi-label">${label}</div>
                        <div class="kofi-headline">${escapeHtml(headline)}</div>
                        ${detail ? `<div class="kofi-detail">${escapeHtml(detail)}</div>` : ''}
                        ${amount ? `<div class="kofi-amount">${escapeHtml(amount)}</div>` : ''}
                    </div>`;
                return el;
            }
            function showNextAlert() {
                if (alertQueue.length === 0) {
                    alertDisplaying = false;
                    return;
                }
                alertDisplaying = true;
                const eventData = alertQueue.shift();
                const container = document.getElementById('kofi-container');
                const el = buildAlertElement(eventData);
                container.appendChild(el);
                setTimeout(() => {
                    el.classList.add('kofi-dismissing');
                    el.addEventListener('animationend', () => {
                        el.remove();
                        showNextAlert();
                    }, { once: true });
                }, ALERT_DURATION);
            }
            function enqueueAlert(eventData) {
                alertQueue.push(eventData);
                if (!alertDisplaying) showNextAlert();
            }
            function handleKofiEvent(raw) {
                console.log('KOFI raw data:', raw);
                const parsed = parseKofiData(raw.data !== undefined ? raw.data : raw);
                if (!parsed) {
                    console.warn('Could not parse KOFI data:', raw);
                    return;
                }
                if (!parsed.type) {
                    console.warn('KOFI event missing type:', parsed);
                    return;
                }
                console.log(`KOFI event type=${parsed.type}`, parsed);
                enqueueAlert(parsed);
            }
            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });
                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Ko-Fi' });
                });
                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    attemptReconnect();
                });
                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    attemptReconnect();
                });
                socket.on('WELCOME', (data) => {
                    console.log('Server says:', data.message);
                });
                socket.on('NOTIFY', (data) => {
                    console.log('Notification:', data);
                });
                socket.on('KOFI', (data) => {
                    handleKofiEvent(data);
                });
                socket.onAny((event, ...args) => {
                    console.log(`[onAny] Event: ${event}`, ...args);
                });
            }
            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
                setTimeout(connectWebSocket, delay);
            }
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="kofi-container"></div>
</body>
</html>
