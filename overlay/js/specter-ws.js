/**
 * Specter Overlay WebSocket session helper (Socket.IO 4.x client).
 * Manual reconnect only (reconnection:false). SUCCESS-gated ready.
 * Dispose old socket before reconnect to avoid sticky multi-connects.
 *
 * Usage:
 *   const session = SpecterOverlayWS.create({
 *     code: apiKey,
 *     name: 'Deaths',                 // string or () => string
 *     channel: 'Overlay',             // default
 *     onStatus: (text, state) => {},  // optional UI
 *     onRegistered: (socket, data) => {}, // after SUCCESS (post-register hooks)
 *     bind: (socket) => { socket.on('EVENT', handler); },
 *   });
 *   session.connect();
 */
(function (global) {
    'use strict';

    var WS_URL = 'wss://websocket.botofthespecter.com';
    // Match bot progressive backoff (seconds * 1000); first retry can be immediate
    var BACKOFF_MS = [0, 2000, 5000, 10000, 20000, 40000, 60000];

    function resolveName(name) {
        try {
            return typeof name === 'function' ? String(name()) : String(name || 'Overlay');
        } catch (e) {
            return 'Overlay';
        }
    }

    function create(options) {
        options = options || {};
        var code = options.code;
        var channel = options.channel || 'Overlay';
        var nameOpt = options.name || 'Overlay';
        var url = options.url || WS_URL;
        var onStatus = typeof options.onStatus === 'function' ? options.onStatus : null;
        var onRegistered = typeof options.onRegistered === 'function' ? options.onRegistered : null;
        var onConnect = typeof options.onConnect === 'function' ? options.onConnect : null;
        var onDisconnect = typeof options.onDisconnect === 'function' ? options.onDisconnect : null;
        var onError = typeof options.onError === 'function' ? options.onError : null;
        var bind = typeof options.bind === 'function' ? options.bind : null;

        var socket = null;
        var attempts = 0;
        var timer = null;
        var disposing = false;
        var registered = false;
        var stopped = false;

        function status(text, state) {
            if (onStatus) {
                try { onStatus(text, state); } catch (e) { /* ignore UI errors */ }
            }
        }

        function clearTimer() {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        }

        function dispose() {
            disposing = true;
            clearTimer();
            if (socket) {
                try { socket.removeAllListeners(); } catch (e) { /* ignore */ }
                try { socket.disconnect(); } catch (e) { /* ignore */ }
                socket = null;
            }
            registered = false;
            disposing = false;
        }

        function scheduleReconnect() {
            if (stopped || timer !== null) return;
            attempts += 1;
            var idx = Math.min(attempts, BACKOFF_MS.length - 1);
            var base = BACKOFF_MS[idx];
            var jitter = Math.floor(Math.random() * Math.min(500, Math.max(50, base * 0.1 + 50)));
            var delay = base + jitter;
            status('Reconnecting…', 'connecting');
            if (typeof console !== 'undefined' && console.log) {
                console.log('[SpecterWS] Reconnect attempt ' + attempts + ' in ' + (delay / 1000).toFixed(1) + 's');
            }
            timer = setTimeout(function () {
                timer = null;
                connect();
            }, delay);
        }

        function connect() {
            if (stopped) return null;
            if (typeof io !== 'function') {
                status('Socket.IO missing', 'error');
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[SpecterWS] window.io is not available — load socket.io before specter-ws.js');
                }
                return null;
            }
            if (!code) {
                status('No code', 'error');
                return null;
            }

            clearTimer();
            dispose();
            registered = false;
            status('Connecting…', 'connecting');

            socket = io(url, {
                reconnection: false,
                transports: ['websocket']
            });

            socket.on('connect', function () {
                // Transport up — not ready until SUCCESS
                status('Registering…', 'connecting');
                var regName = resolveName(nameOpt);
                try {
                    socket.emit('REGISTER', {
                        code: code,
                        channel: channel,
                        name: regName
                    });
                } catch (e) {
                    if (typeof console !== 'undefined' && console.error) {
                        console.error('[SpecterWS] REGISTER emit failed', e);
                    }
                    scheduleReconnect();
                    return;
                }
                if (onConnect) {
                    try { onConnect(socket); } catch (e) { /* ignore */ }
                }
            });

            socket.on('SUCCESS', function (data) {
                registered = true;
                attempts = 0;
                status('Connected', 'connected');
                if (typeof console !== 'undefined' && console.log) {
                    console.log('[SpecterWS] Registration confirmed', data);
                }
                if (onRegistered) {
                    try { onRegistered(socket, data); } catch (e) {
                        if (typeof console !== 'undefined' && console.error) {
                            console.error('[SpecterWS] onRegistered error', e);
                        }
                    }
                }
            });

            socket.on('ERROR', function (data) {
                var msg = (data && (data.message || data)) || data;
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[SpecterWS] Server ERROR', msg);
                }
                if (onError) {
                    try { onError(data); } catch (e) { /* ignore */ }
                }
                // Duplicate-session targets the *old* SID; do not tear down if we are registered
                var text = String(msg || '').toLowerCase();
                if (text.indexOf('duplicate') !== -1 && registered) return;
            });

            socket.on('disconnect', function (reason) {
                if (disposing || stopped) return;
                registered = false;
                status('Disconnected', 'error');
                if (onDisconnect) {
                    try { onDisconnect(reason); } catch (e) { /* ignore */ }
                }
                scheduleReconnect();
            });

            socket.on('connect_error', function (err) {
                if (disposing || stopped) return;
                status('Connection error', 'error');
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[SpecterWS] connect_error', err);
                }
                scheduleReconnect();
            });

            // App event handlers (must re-bind after every new socket instance)
            if (bind) {
                try { bind(socket); } catch (e) {
                    if (typeof console !== 'undefined' && console.error) {
                        console.error('[SpecterWS] bind() error', e);
                    }
                }
            }

            return socket;
        }

        function stop() {
            stopped = true;
            dispose();
        }

        function start() {
            stopped = false;
            return connect();
        }

        return {
            connect: connect,
            start: start,
            stop: stop,
            dispose: dispose,
            getSocket: function () { return socket; },
            isRegistered: function () { return registered; },
            getAttempts: function () { return attempts; }
        };
    }

    global.SpecterOverlayWS = {
        create: create,
        WS_URL: WS_URL,
        BACKOFF_MS: BACKOFF_MS
    };
})(typeof window !== 'undefined' ? window : this);
