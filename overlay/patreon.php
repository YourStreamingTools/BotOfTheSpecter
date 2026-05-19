<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patreon Overlay</title>
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
        #patreon-container {
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
            const ALERT_DURATION = 7000;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                console.error('No code provided in the URL');
                return;
            }

            // Patreon webhooks are forwarded by api.py via urlencode(dict), which
            // serialises the Python dict using str() — that produces single-quoted
            // keys/values and Python literals (None/True/False) rather than JSON.
            // Mirror the kofi/fourthwall tolerant parser so the overlay survives
            // until the upstream serialisation bug is fixed.
            function parsePatreonData(raw) {
                if (!raw) return null;
                if (typeof raw === 'object') return raw;
                try { return JSON.parse(raw); } catch (_) {}
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
                return String(str ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            // Patreon stores all monetary values as integers in the campaign's
            // base currency unit (cents for USD). Format with the supplied
            // currency_code when available, otherwise just show the number.
            function formatCents(cents, currency) {
                const n = Number(cents);
                if (!Number.isFinite(n)) return '';
                const dollars = (n / 100).toFixed(2);
                return currency ? `${currency} ${dollars}` : `$${dollars}`;
            }

            // Pull a tier title out of the JSON:API `included` array, if present.
            function findTierTitle(parsed) {
                const included = parsed && parsed.included;
                if (!Array.isArray(included)) return '';
                const tier = included.find(r => r && r.type === 'tier' && r.attributes && r.attributes.title);
                return tier ? tier.attributes.title : '';
            }

            // Without an X-Patreon-Event header (api.py drops it), best we can
            // do is infer the variant from patron_status.
            function classifyEvent(attrs) {
                const status = (attrs.patron_status || '').toLowerCase();
                if (status === 'declined_patron' || status === 'former_patron') return 'cancelled';
                if (attrs.last_charge_status === 'Paid' && attrs.lifetime_support_cents) return 'update';
                return 'pledge';
            }

            function buildAlertElement(parsed) {
                const data = (parsed && parsed.data) || parsed || {};
                const attrs = data.attributes || {};
                const variant = classifyEvent(attrs);
                const name = attrs.full_name || attrs.email || 'A patron';
                const currentCents = attrs.currently_entitled_amount_cents;
                const lifetimeCents = attrs.campaign_lifetime_support_cents;
                const currency = attrs.currency_code || 'USD';
                const tierTitle = findTierTitle(parsed);

                let cssClass = `patreon-overlay-page-${variant}`;
                let icon, label, headline, detail = '', amount = '';

                if (variant === 'cancelled') {
                    icon = '👋';
                    label = 'Patreon Cancellation';
                    headline = `${name} ended their support`;
                    if (lifetimeCents) {
                        amount = `Lifetime: ${formatCents(lifetimeCents, currency)}`;
                    }
                } else if (variant === 'update') {
                    icon = '🔄';
                    label = 'Patreon Update';
                    headline = `${name} updated their pledge`;
                    if (currentCents) amount = formatCents(currentCents, currency);
                    if (tierTitle) detail = `Tier: ${tierTitle}`;
                } else {
                    icon = '💖';
                    label = 'New Patron';
                    headline = `${name} is now a patron!`;
                    if (currentCents) amount = formatCents(currentCents, currency);
                    if (tierTitle) detail = `Tier: ${tierTitle}`;
                }

                const el = document.createElement('div');
                el.className = `patreon-overlay-page-alert ${cssClass}`;
                el.innerHTML = `
                    <div class="patreon-overlay-page-icon">${icon}</div>
                    <div class="patreon-overlay-page-body">
                        <div class="patreon-overlay-page-label">${escapeHtml(label)}</div>
                        <div class="patreon-overlay-page-headline">${escapeHtml(headline)}</div>
                        ${detail ? `<div class="patreon-overlay-page-detail">${escapeHtml(detail)}</div>` : ''}
                        ${amount ? `<div class="patreon-overlay-page-amount">${escapeHtml(amount)}</div>` : ''}
                    </div>`;
                return el;
            }

            function showNextAlert() {
                if (alertQueue.length === 0) {
                    alertDisplaying = false;
                    return;
                }
                alertDisplaying = true;
                const parsed = alertQueue.shift();
                const container = document.getElementById('patreon-container');
                const el = buildAlertElement(parsed);
                container.appendChild(el);
                setTimeout(() => {
                    el.classList.add('patreon-overlay-page-dismissing');
                    el.addEventListener('animationend', () => {
                        el.remove();
                        showNextAlert();
                    }, { once: true });
                }, ALERT_DURATION);
            }

            function enqueueAlert(parsed) {
                alertQueue.push(parsed);
                if (!alertDisplaying) showNextAlert();
            }

            function handlePatreonEvent(raw) {
                console.log('PATREON raw data:', raw);
                const parsed = parsePatreonData(raw && raw.data !== undefined ? raw.data : raw);
                if (!parsed) {
                    console.warn('Could not parse PATREON data:', raw);
                    return;
                }
                enqueueAlert(parsed);
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
                socket.on('connect', () => {
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code, channel: 'Overlay', name: 'Patreon' });
                });
                socket.on('disconnect', attemptReconnect);
                socket.on('connect_error', attemptReconnect);
                socket.on('WELCOME', (data) => console.log('Server says:', data && data.message));
                socket.on('NOTIFY', (data) => console.log('Notification:', data));
                socket.on('PATREON', handlePatreonEvent);
                socket.onAny((event, ...args) => console.log(`[onAny] Event: ${event}`, ...args));
            }

            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`[patreon] Reconnecting in ${delay / 1000}s...`);
                setTimeout(connectWebSocket, delay);
            }

            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="patreon-container"></div>
</body>
</html>
