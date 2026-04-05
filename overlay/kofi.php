<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ko-fi Overlay</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
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
                el.className = 'kofi-overlay-page-alert';
                const eventType = eventData.type || '';
                let icon = '☕', cssClass = '', label = 'Ko-fi', headline = '', detail = '', amount = '';
                if (eventType === 'Donation') {
                    cssClass = 'kofi-overlay-page-donation';
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
                    cssClass = 'kofi-overlay-page-subscription';
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
                    cssClass = 'kofi-overlay-page-shop';
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
                    <div class="kofi-overlay-page-icon">${icon}</div>
                    <div class="kofi-overlay-page-body">
                        <div class="kofi-overlay-page-label">${label}</div>
                        <div class="kofi-overlay-page-headline">${escapeHtml(headline)}</div>
                        ${detail ? `<div class="kofi-overlay-page-detail">${escapeHtml(detail)}</div>` : ''}
                        ${amount ? `<div class="kofi-overlay-page-amount">${escapeHtml(amount)}</div>` : ''}
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
                    el.classList.add('kofi-overlay-page-dismissing');
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
