<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FourthWall Overlay</title>
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
        #fw-container {
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
            function parseFwData(raw) {
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
            // Build alert element for each known event type
            function buildAlertElement(eventType, eventPayload) {
                const el = document.createElement('div');
                el.className = 'fourthwall-overlay-page-alert';
                let icon = '🛒', cssClass = '', label = 'FourthWall', headline = '', detail = '', amount = '';
                if (eventType === 'ORDER_PLACED') {
                    cssClass = 'fourthwall-overlay-page-order';
                    icon = '🛒';
                    label = 'New Order';
                    const username = eventPayload.username || 'Someone';
                    const offer = (eventPayload.offers && eventPayload.offers[0]) || {};
                    const itemName = offer.name || 'an item';
                    const qty = (offer.variant && offer.variant.quantity) || 1;
                    const total = eventPayload.amounts && eventPayload.amounts.total;
                    const price = total ? `${total.value} ${total.currency}` : '';
                    headline = `${username} placed an order!`;
                    detail = qty > 1 ? `${qty}× ${itemName}` : itemName;
                    amount = price;
                } else if (eventType === 'DONATION') {
                    cssClass = 'fourthwall-overlay-page-donation';
                    icon = '💰';
                    label = 'Donation';
                    const username = eventPayload.username || 'Someone';
                    const total = eventPayload.amounts && eventPayload.amounts.total;
                    const price = total ? `${total.value} ${total.currency}` : '';
                    const msg = eventPayload.message || '';
                    headline = `${username} donated!`;
                    amount = price;
                    detail = msg;
                } else if (eventType === 'GIVEAWAY_PURCHASED') {
                    cssClass = 'fourthwall-overlay-page-giveaway';
                    icon = '🎁';
                    label = 'Giveaway Purchase';
                    const username = eventPayload.username || 'Someone';
                    const offer = eventPayload.offer || {};
                    const itemName = offer.name || 'a giveaway';
                    const total = eventPayload.amounts && eventPayload.amounts.total;
                    const price = total ? `${total.value} ${total.currency}` : '';
                    headline = `${username} purchased a giveaway!`;
                    detail = itemName;
                    amount = price;
                } else if (eventType === 'SUBSCRIPTION_PURCHASED') {
                    cssClass = 'fourthwall-overlay-page-sub';
                    icon = '⭐';
                    label = 'New Subscription';
                    const nickname = eventPayload.nickname || 'Someone';
                    const sub = eventPayload.subscription || {};
                    const variant = sub.variant || {};
                    const interval = variant.interval || '';
                    const amtObj = variant.amount || {};
                    const price = amtObj.value ? `${amtObj.value} ${amtObj.currency || ''}`.trim() : '';
                    headline = `${nickname} subscribed!`;
                    detail = interval ? `${interval} plan` : '';
                    amount = price;
                } else {
                    // Generic fallback
                    cssClass = '';
                    icon = '🔔';
                    label = 'FourthWall';
                    headline = eventType.replace(/_/g, ' ');
                }
                el.classList.add(cssClass);
                el.innerHTML = `
                    <div class="fourthwall-overlay-page-icon">${icon}</div>
                    <div class="fourthwall-overlay-page-body">
                        <div class="fourthwall-overlay-page-label">${label}</div>
                        <div class="fourthwall-overlay-page-headline">${escapeHtml(headline)}</div>
                        ${detail ? `<div class="fourthwall-overlay-page-detail">${escapeHtml(detail)}</div>` : ''}
                        ${amount ? `<div class="fourthwall-overlay-page-amount">${escapeHtml(amount)}</div>` : ''}
                    </div>`;
                return el;
            }
            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
            function showNextAlert() {
                if (alertQueue.length === 0) {
                    alertDisplaying = false;
                    return;
                }
                alertDisplaying = true;
                const { eventType, eventPayload } = alertQueue.shift();
                const container = document.getElementById('fw-container');
                const el = buildAlertElement(eventType, eventPayload);
                container.appendChild(el);
                setTimeout(() => {
                    el.classList.add('fourthwall-overlay-page-dismissing');
                    el.addEventListener('animationend', () => {
                        el.remove();
                        showNextAlert();
                    }, { once: true });
                }, ALERT_DURATION);
            }
            function enqueueAlert(eventType, eventPayload) {
                alertQueue.push({ eventType, eventPayload });
                if (!alertDisplaying) showNextAlert();
            }
            function handleFourthWallEvent(raw) {
                console.log('FOURTHWALL raw data:', raw);
                const parsed = parseFwData(raw.data !== undefined ? raw.data : raw);
                if (!parsed) {
                    console.warn('Could not parse FOURTHWALL data:', raw);
                    return;
                }
                const eventType = parsed.type;
                const eventPayload = parsed.data || {};
                if (!eventType) {
                    console.warn('FOURTHWALL event missing type:', parsed);
                    return;
                }
                console.log(`FOURTHWALL event type=${eventType}`, eventPayload);
                enqueueAlert(eventType, eventPayload);
            }
            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });
                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'FourthWall' });
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
                socket.on('FOURTHWALL', (data) => {
                    handleFourthWallEvent(data);
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
    <div id="fw-container"></div>
</body>
</html>