<?php
// Dashboard landing page - main entry point.
// Cookie + session config is owned by the shared bootstrap so the cookie
// is scoped to .botofthespecter.com and the session row lives in
// website.web_sessions. Do not re-call session_set_cookie_params() here:
// passing domain="" used to override the bootstrap's .botofthespecter.com
// scope, which broke the shared login across home/dashboard/support/members.
require_once '/var/www/lib/session_bootstrap.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['access_token']);
$config = include '/var/www/config/main.php';
$dashboardVersion = $config['dashboardVersion'];

if ($isLoggedIn) {
    // User is logged in - show the operational dashboard.
    //
    // FAST SHELL: this page renders only the zone skeletons server-side, then
    // streams the heavy data in from the FastAPI /dashboard/* endpoints (via the
    // browser, V2 X-API-KEY auth) and live events from the WebSocket. The old
    // synchronous Twitch subscriber cURL and the ~20-query includes/user_db.php
    // load were intentionally removed from this path -- they made first paint
    // wait up to ~16s. usr_database.php (per-user schema bootstrap) still runs
    // via layout.php, so all tables exist before the client fires its requests.
    require_once "/var/www/config/db_connect.php";
    include 'includes/userdata.php'; // sets $username, $api_key, $twitchDisplayName, $twitchUserId, $user
    session_write_close();

    $pageTitle = t('dashboard_page_title_management');

    // ---- Page body (skeletons; JS fills them in) ----
    ob_start();
    ?>
    <div class="sp-page-header">
        <h1><i class="fas fa-gauge-high"></i> <?= t('dashboard_welcome') ?>, <?php echo htmlspecialchars($twitchDisplayName); ?>!</h1>
        <p><?= t('dashboard_welcome_subtitle') ?></p>
    </div>

    <!-- Zone 1: Live ribbon (seeded by /dashboard/live, kept live by WebSocket) -->
    <div class="db-live-ribbon">
        <div class="db-live-state">
            <div class="db-live-status">
                <span class="db-live-dot is-offline" id="dbLiveDot"></span>
                <span class="db-live-label is-offline" id="dbLiveLabel"><?= t('dashboard_live_checking') ?></span>
            </div>
            <div class="db-live-title" id="dbLiveTitle"></div>
            <div class="db-live-meta" id="dbLiveMeta"></div>
            <div class="db-chat-pulse">
                <div class="db-pulse-metric">
                    <span class="db-pulse-value" id="dbPulseRate">0</span>
                    <span class="db-pulse-label"><?= t('dashboard_chat_per_min') ?></span>
                </div>
                <div class="db-pulse-metric">
                    <span class="db-pulse-value" id="dbPulseChatters">0</span>
                    <span class="db-pulse-label"><?= t('dashboard_active_chatters') ?></span>
                </div>
            </div>
        </div>
        <div class="db-ticker">
            <div class="db-ticker-head">
                <span><?= t('dashboard_live_activity') ?></span>
                <span id="dbTickerConn"><?= t('dashboard_connecting') ?></span>
            </div>
            <div class="db-ticker-feed" id="dbTickerFeed">
                <div class="db-ticker-empty" id="dbTickerEmpty"><?= t('dashboard_waiting_for_events') ?></div>
            </div>
        </div>
    </div>

    <!-- Zone 2: What your bot did for you -->
    <div class="db-zone">
        <div class="db-zone-head">
            <div class="db-section-label"><?= t('dashboard_zone_bot_did') ?> <span class="db-alltime-tag"><?= t('dashboard_all_time') ?></span></div>
        </div>
        <div class="sp-stat-row" id="dbBotDid">
            <div class="db-loading"><i class="fas fa-circle-notch fa-spin"></i> <?= t('dashboard_loading') ?></div>
        </div>
    </div>

    <!-- Zone 3: What's new / what changed -->
    <div class="db-zone">
        <div class="db-zone-head">
            <div class="db-section-label"><?= t('dashboard_zone_whats_new') ?></div>
            <div class="db-window-switch" id="dbWindowSwitch">
                <button type="button" class="db-window-btn" data-window="today"><?= t('dashboard_window_today') ?></button>
                <button type="button" class="db-window-btn is-active" data-window="7d"><?= t('dashboard_window_7d') ?></button>
                <button type="button" class="db-window-btn" data-window="30d"><?= t('dashboard_window_30d') ?></button>
            </div>
        </div>
        <div class="db-trend-grid" id="dbWhatsNew">
            <div class="db-loading"><i class="fas fa-circle-notch fa-spin"></i> <?= t('dashboard_loading') ?></div>
        </div>
    </div>

    <!-- Zone 4: Community & channel health -->
    <div class="db-zone">
        <div class="db-zone-head">
            <div class="db-section-label"><?= t('dashboard_zone_community') ?></div>
        </div>
        <div class="db-board-grid" id="dbBoards">
            <div class="db-loading"><i class="fas fa-circle-notch fa-spin"></i> <?= t('dashboard_loading') ?></div>
        </div>
    </div>

    <!-- Quick links (slim) -->
    <div class="db-section-label"><?= t('dashboard_quick_links') ?></div>
    <div class="db-quick-grid">
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--blue);"><i class="fas fa-robot fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_bot_control') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_bot_control_desc') ?></p>
                <a href="bot.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cogs"></i> <?= t('dashboard_manage_bot') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--green);"><i class="fas fa-terminal fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_commands') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_commands_desc') ?></p>
                <a href="custom_commands.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-plus"></i> <?= t('dashboard_edit_commands') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--red);"><i class="fas fa-gift fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_rewards') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_rewards_desc') ?></p>
                <a href="channel_rewards.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-star"></i> <?= t('dashboard_setup_rewards') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--blue);"><i class="fas fa-music fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_dmca_music') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_dmca_music_desc') ?></p>
                <a href="music.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-play"></i> <?= t('dashboard_browse_music') ?></a>
            </div>
        </div>
        <div class="sp-card db-quick-card">
            <div class="sp-card-body">
                <div class="db-quick-icon" style="color: var(--accent-hover);"><i class="fab fa-discord fa-2x"></i></div>
                <h4 class="db-quick-title"><?= t('dashboard_discord_bot') ?></h4>
                <p class="db-quick-desc"><?= t('dashboard_discord_bot_desc') ?></p>
                <a href="discordbot.php" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cog"></i> <?= t('dashboard_manage_discord') ?></a>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();

    // ---- Page scripts: Socket.io client + dashboard data logic ----
    ob_start();
    ?>
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
    (function () {
        "use strict";
        var API = 'https://api.botofthespecter.com';
        var CODE = <?php echo json_encode($_SESSION['api_key'] ?? ''); ?>;
        var I18N = {
            live: <?php echo json_encode(t('dashboard_js_live')); ?>,
            offline: <?php echo json_encode(t('dashboard_js_offline')); ?>,
            offline_sub: <?php echo json_encode(t('dashboard_js_offline_sub')); ?>,
            viewers: <?php echo json_encode(t('dashboard_js_viewers')); ?>,
            uptime: <?php echo json_encode(t('dashboard_js_uptime')); ?>,
            connected: <?php echo json_encode(t('dashboard_js_connected')); ?>,
            reconnecting: <?php echo json_encode(t('dashboard_js_reconnecting')); ?>,
            all_time: <?php echo json_encode(t('dashboard_js_all_time')); ?>,
            since_visit: <?php echo json_encode(t('dashboard_js_since_visit')); ?>,
            no_data: <?php echo json_encode(t('dashboard_js_no_data')); ?>,
            load_error: <?php echo json_encode(t('dashboard_js_load_error')); ?>,
            commands: <?php echo json_encode(t('dashboard_js_commands')); ?>,
            rewards_fulfilled: <?php echo json_encode(t('dashboard_js_rewards_fulfilled')); ?>,
            deaths: <?php echo json_encode(t('dashboard_js_deaths')); ?>,
            songs: <?php echo json_encode(t('dashboard_js_songs')); ?>,
            welcomed: <?php echo json_encode(t('dashboard_js_welcomed')); ?>,
            shoutouts: <?php echo json_encode(t('dashboard_js_shoutouts')); ?>,
            quotes: <?php echo json_encode(t('dashboard_js_quotes')); ?>,
            points: <?php echo json_encode(t('dashboard_js_points')); ?>,
            interactions: <?php echo json_encode(t('dashboard_js_interactions')); ?>,
            new_followers: <?php echo json_encode(t('dashboard_js_new_followers')); ?>,
            new_subs: <?php echo json_encode(t('dashboard_js_new_subs')); ?>,
            bits: <?php echo json_encode(t('dashboard_js_bits')); ?>,
            tips: <?php echo json_encode(t('dashboard_js_tips')); ?>,
            raids: <?php echo json_encode(t('dashboard_js_raids')); ?>,
            new_viewers: <?php echo json_encode(t('dashboard_js_new_viewers')); ?>,
            new_quotes: <?php echo json_encode(t('dashboard_js_new_quotes')); ?>,
            chat_messages: <?php echo json_encode(t('dashboard_js_chat_messages')); ?>,
            top_commands: <?php echo json_encode(t('dashboard_js_top_commands')); ?>,
            top_rewards: <?php echo json_encode(t('dashboard_js_top_rewards')); ?>,
            watch_time: <?php echo json_encode(t('dashboard_js_watch_time')); ?>,
            streaks: <?php echo json_encode(t('dashboard_js_streaks')); ?>,
            deaths_by_game: <?php echo json_encode(t('dashboard_js_deaths_by_game')); ?>,
            chat_leaders: <?php echo json_encode(t('dashboard_js_chat_leaders')); ?>,
            top_songs: <?php echo json_encode(t('dashboard_js_top_songs')); ?>,
            interaction_leaders: <?php echo json_encode(t('dashboard_js_interaction_leaders')); ?>,
            hugs: <?php echo json_encode(t('dashboard_js_hugs')); ?>,
            kisses: <?php echo json_encode(t('dashboard_js_kisses')); ?>,
            highfives: <?php echo json_encode(t('dashboard_js_highfives')); ?>,
            ev_followed: <?php echo json_encode(t('dashboard_js_ev_followed')); ?>,
            ev_subbed: <?php echo json_encode(t('dashboard_js_ev_subbed')); ?>,
            ev_gifted: <?php echo json_encode(t('dashboard_js_ev_gifted')); ?>,
            ev_cheered: <?php echo json_encode(t('dashboard_js_ev_cheered')); ?>,
            ev_raided: <?php echo json_encode(t('dashboard_js_ev_raided')); ?>,
            ev_redeemed: <?php echo json_encode(t('dashboard_js_ev_redeemed')); ?>,
            ev_hype: <?php echo json_encode(t('dashboard_js_ev_hype')); ?>,
            ev_charity: <?php echo json_encode(t('dashboard_js_ev_charity')); ?>,
            ev_tip: <?php echo json_encode(t('dashboard_js_ev_tip')); ?>
        };

        function $(id) { return document.getElementById(id); }
        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function fmt(n) { return (Number(n) || 0).toLocaleString(); }
        function fmtDuration(sec) {
            sec = Number(sec) || 0;
            var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
            return (h > 0 ? h + 'h ' : '') + m + 'm';
        }
        function getCookie(name) {
            var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
            return m ? decodeURIComponent(m[1]) : null;
        }
        function markVisit() {
            if (typeof hasCookieConsent === 'function' && !hasCookieConsent()) return;
            var d = new Date(); d.setTime(d.getTime() + 90 * 86400000);
            document.cookie = 'dbLastVisit=' + Math.floor(Date.now() / 1000) + '; expires=' + d.toUTCString() + '; path=/';
        }
        function apiGet(path, params) {
            // V1 query auth (?api_key=) -- a "simple" cross-origin GET with no custom
            // header, so the browser sends NO CORS preflight. This matches how bot.php /
            // raffles.php / layout.php call the API. (The V2 X-API-KEY header form triggers
            // an OPTIONS preflight that the v2 auth middleware rejects before CORS replies.)
            var all = { api_key: CODE };
            if (params) { for (var k in params) { if (params[k] !== null && params[k] !== undefined) all[k] = params[k]; } }
            var parts = [];
            for (var pk in all) parts.push(encodeURIComponent(pk) + '=' + encodeURIComponent(all[pk]));
            return fetch(API + path + '?' + parts.join('&')).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
        }
        function errRow() { return '<div class="db-loading"><i class="fas fa-triangle-exclamation"></i> ' + esc(I18N.load_error) + '</div>'; }

        var sessionSince = null;       // last-visit epoch captured once per session
        var currentWindow = '7d';
        var trendsSeries = {};

        // ---- Zone 1: live state ----
        function loadLive() {
            apiGet('/dashboard/live').then(renderLive).catch(function () {});
        }
        function renderLive(d) {
            var dot = $('dbLiveDot'), label = $('dbLiveLabel'), title = $('dbLiveTitle'), meta = $('dbLiveMeta');
            if (d && d.online) {
                dot.className = 'db-live-dot is-live';
                label.className = 'db-live-label is-live';
                label.textContent = I18N.live;
                title.textContent = d.title || '';
                var parts = [];
                if (d.game) parts.push('<strong>' + esc(d.game) + '</strong>');
                parts.push(fmt(d.viewer_count) + ' ' + esc(I18N.viewers));
                if (d.uptime_seconds) parts.push(esc(I18N.uptime) + ' ' + fmtDuration(d.uptime_seconds));
                meta.innerHTML = parts.join(' &middot; ');
            } else {
                dot.className = 'db-live-dot is-offline';
                label.className = 'db-live-label is-offline';
                label.textContent = I18N.offline;
                title.textContent = '';
                meta.textContent = I18N.offline_sub;
            }
        }

        // ---- Zone 2 + 3: summary ----
        function summaryParams() {
            var p = { window: currentWindow };
            if (sessionSince) p.since = sessionSince;
            return p;
        }
        function loadInitial() {
            Promise.all([
                apiGet('/dashboard/summary', summaryParams()).catch(function () { return null; }),
                apiGet('/dashboard/trends', { days: 30 }).catch(function () { return null; })
            ]).then(function (res) {
                var summary = res[0], trends = res[1];
                trendsSeries = (trends && trends.series) ? trends.series : {};
                if (summary) {
                    renderBotDid(summary.lifetime);
                    renderWhatsNew(summary.window, summary.since_visit);
                    markVisit();
                } else {
                    $('dbBotDid').innerHTML = errRow();
                    $('dbWhatsNew').innerHTML = errRow();
                }
            });
        }
        function loadSummaryOnly() {
            apiGet('/dashboard/summary', summaryParams()).then(function (s) {
                renderBotDid(s.lifetime);
                renderWhatsNew(s.window, s.since_visit);
            }).catch(function () { $('dbWhatsNew').innerHTML = errRow(); });
        }
        function statTile(label, value) {
            return '<div class="sp-stat"><div class="sp-stat-label">' + esc(label) + '</div>' +
                '<div class="sp-stat-value">' + fmt(value) + '</div>' +
                '<div class="sp-stat-sub"><span class="db-alltime-tag">' + esc(I18N.all_time) + '</span></div></div>';
        }
        function renderBotDid(l) {
            l = l || {};
            $('dbBotDid').innerHTML = [
                statTile(I18N.commands, l.commands),
                statTile(I18N.rewards_fulfilled, l.rewards),
                statTile(I18N.deaths, l.deaths),
                statTile(I18N.songs, l.songs),
                statTile(I18N.welcomed, l.welcomed),
                statTile(I18N.shoutouts, l.shoutouts),
                statTile(I18N.quotes, l.quotes),
                statTile(I18N.points, l.points),
                statTile(I18N.interactions, l.interactions)
            ].join('');
        }
        function sparkline(data) {
            if (!data || data.length < 2) return '';
            var vals = data.map(function (p) { return Number(p.count) || 0; });
            var max = Math.max.apply(null, vals.concat([1]));
            var n = vals.length, w = 100, h = 28, pts = [];
            for (var i = 0; i < n; i++) {
                var x = (i / (n - 1)) * w;
                var y = h - (vals[i] / max) * h;
                pts.push(x.toFixed(1) + ',' + y.toFixed(1));
            }
            var poly = pts.join(' ');
            var area = '0,' + h + ' ' + poly + ' ' + w + ',' + h;
            return '<svg class="db-sparkline" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">' +
                '<polygon points="' + area + '"></polygon><polyline points="' + poly + '"></polyline></svg>';
        }
        function deltaSpan(n) {
            if (n === null || n === undefined) return '';
            n = Number(n) || 0;
            var cls = n > 0 ? 'up' : 'flat';
            var arrow = n > 0 ? '&#9650;' : '&#8211;';
            return '<span class="db-trend-delta ' + cls + '">' + arrow + ' ' + fmt(n) + ' ' + esc(I18N.since_visit) + '</span>';
        }
        function trendTile(label, value, deltaVal, sub, sparkKey) {
            return '<div class="db-trend-tile"><div class="db-trend-label">' + esc(label) + '</div>' +
                '<div class="db-trend-value">' + fmt(value) + '</div>' +
                deltaSpan(deltaVal) +
                (sub ? '<div class="db-trend-sub">' + sub + '</div>' : '') +
                (sparkKey ? sparkline(trendsSeries[sparkKey]) : '') + '</div>';
        }
        function renderWhatsNew(w, since) {
            w = w || {};
            since = since || null;
            function sv(getter) { return since ? getter(since) : null; }
            var subsSub = (w.subs ? ('T1 ' + (w.subs.t1 || 0) + ' &middot; T2 ' + (w.subs.t2 || 0) + ' &middot; T3 ' + (w.subs.t3 || 0)) : '');
            var tipsSub = (w.tips && w.tips.amount) ? fmt(w.tips.amount) : '';
            var raidsSub = (w.raids ? (fmt(w.raids.viewers) + ' ' + esc(I18N.viewers)) : '');
            $('dbWhatsNew').innerHTML = [
                trendTile(I18N.new_followers, w.followers, sv(function (s) { return s.followers; }), '', 'followers'),
                trendTile(I18N.new_subs, (w.subs ? w.subs.total : 0), sv(function (s) { return s.subs ? s.subs.total : 0; }), subsSub, 'subs'),
                trendTile(I18N.bits, w.bits, sv(function (s) { return s.bits; }), '', 'bits'),
                trendTile(I18N.tips, (w.tips ? w.tips.count : 0), sv(function (s) { return s.tips ? s.tips.count : 0; }), tipsSub, null),
                trendTile(I18N.raids, (w.raids ? w.raids.count : 0), sv(function (s) { return s.raids ? s.raids.count : 0; }), raidsSub, null),
                trendTile(I18N.new_viewers, w.new_viewers, sv(function (s) { return s.new_viewers; }), '', null),
                trendTile(I18N.new_quotes, w.new_quotes, sv(function (s) { return s.new_quotes; }), '', null),
                trendTile(I18N.chat_messages, w.chat_messages, sv(function (s) { return s.chat_messages; }), '', 'chat')
            ].join('');
        }

        // ---- Zone 4: leaderboards ----
        function loadBoards() {
            apiGet('/dashboard/leaderboards', { limit: 8 }).then(renderBoards).catch(function () { $('dbBoards').innerHTML = errRow(); });
        }
        function boardRows(arr, nameKey, valFn) {
            if (!arr || !arr.length) return '<div class="db-board-empty">' + esc(I18N.no_data) + '</div>';
            return arr.map(function (r, i) {
                return '<div class="db-board-row"><span class="db-board-rank">' + (i + 1) + '</span>' +
                    '<span class="db-board-name">' + esc(r[nameKey]) + '</span>' +
                    '<span class="db-board-val">' + valFn(r) + '</span></div>';
            }).join('');
        }
        function board(title, icon, rowsHtml) {
            return '<div class="db-board"><div class="db-board-head"><i class="' + icon + '"></i> ' + esc(title) + '</div>' +
                '<div class="db-board-list">' + rowsHtml + '</div></div>';
        }
        function renderBoards(d) {
            d = d || {};
            function topOf(arr) { return (arr && arr.length) ? esc(arr[0].username) + ' (' + fmt(arr[0].count) + ')' : esc(I18N.no_data); }
            var it = d.interactions || {};
            var interRows =
                '<div class="db-board-row"><span class="db-board-name">' + esc(I18N.hugs) + '</span><span class="db-board-val">' + topOf(it.hugs) + '</span></div>' +
                '<div class="db-board-row"><span class="db-board-name">' + esc(I18N.kisses) + '</span><span class="db-board-val">' + topOf(it.kisses) + '</span></div>' +
                '<div class="db-board-row"><span class="db-board-name">' + esc(I18N.highfives) + '</span><span class="db-board-val">' + topOf(it.highfives) + '</span></div>';
            $('dbBoards').innerHTML = [
                board(I18N.top_commands, 'fas fa-terminal', boardRows(d.top_commands, 'command', function (r) { return fmt(r.count); })),
                board(I18N.top_rewards, 'fas fa-star', boardRows(d.top_rewards, 'reward_title', function (r) { return fmt(r.count); })),
                board(I18N.watch_time, 'fas fa-clock', boardRows(d.watch_time, 'username', function (r) { return fmt(r.live); })),
                board(I18N.streaks, 'fas fa-fire', boardRows(d.streaks, 'username', function (r) { return fmt(r.highest); })),
                board(I18N.deaths_by_game, 'fas fa-skull', boardRows(d.deaths_by_game, 'game', function (r) { return fmt(r.deaths); })),
                board(I18N.chat_leaders, 'fas fa-comments', boardRows(d.chat_leaders, 'username', function (r) { return fmt(r.messages); })),
                board(I18N.top_songs, 'fas fa-music', boardRows(d.top_songs, 'song', function (r) { return fmt(r.count); })),
                board(I18N.interaction_leaders, 'fas fa-hands-clapping', interRows)
            ].join('');
        }

        // ---- Zone 1: WebSocket live ticker + chat pulse ----
        var TICKER_MAX = 12;
        var chatTimes = [];
        var chatters = {};
        function pushTicker(type, icon, actor, text) {
            var feed = $('dbTickerFeed'); if (!feed) return;
            var empty = $('dbTickerEmpty'); if (empty) empty.remove();
            var item = document.createElement('div');
            item.className = 'db-ticker-item is-' + type;
            item.innerHTML = '<span class="db-ticker-icon"><i class="' + icon + '"></i></span>' +
                '<span>' + (actor ? '<span class="db-ticker-actor">' + esc(actor) + '</span> ' : '') + text + '</span>';
            feed.insertBefore(item, feed.firstChild);
            while (feed.children.length > TICKER_MAX) feed.removeChild(feed.lastChild);
        }
        function actorOf(p) { if (!p) return ''; return p['twitch-username'] || p.username || p.user || ''; }
        var ACT_MAP = {
            follow: ['follow', 'fas fa-heart', 'ev_followed'],
            sub: ['sub', 'fas fa-star', 'ev_subbed'],
            cheer: ['cheer', 'fas fa-gem', 'ev_cheered'],
            tip: ['tip', 'fas fa-mug-hot', 'ev_tip'],
            raid: ['raid', 'fas fa-users', 'ev_raided'],
            redeem: ['points', 'fas fa-circle-dot', 'ev_redeemed'],
            quote: ['follow', 'fas fa-quote-right', null]
        };
        function seedTicker(items) {
            var feed = $('dbTickerFeed'); if (!feed || !items || !items.length) return;
            var empty = $('dbTickerEmpty'); if (empty) empty.remove();
            items.slice(0, TICKER_MAX).forEach(function (it) {
                var m = ACT_MAP[it.type] || ['follow', 'fas fa-bell', null];
                var label = (it.type === 'quote') ? esc(it.detail) : esc(I18N[m[2]] || '');
                var item = document.createElement('div');
                item.className = 'db-ticker-item is-' + m[0];
                item.innerHTML = '<span class="db-ticker-icon"><i class="' + m[1] + '"></i></span>' +
                    '<span>' + (it.actor ? '<span class="db-ticker-actor">' + esc(it.actor) + '</span> ' : '') + label + '</span>';
                feed.appendChild(item);
            });
        }
        function loadActivity() {
            apiGet('/dashboard/activity', { limit: 10 }).then(function (d) {
                if (d && d.items) seedTicker(d.items);
            }).catch(function () {});
        }
        function onChat(p) {
            var now = Date.now();
            chatTimes.push(now);
            var cutoff = now - 60000;
            while (chatTimes.length && chatTimes[0] < cutoff) chatTimes.shift();
            if (p && p.username) chatters[String(p.username).toLowerCase()] = now;
            updatePulse();
        }
        function updatePulse() {
            var now = Date.now(), active = 0, cutoff = now - 300000;
            for (var k in chatters) { if (chatters[k] < cutoff) delete chatters[k]; else active++; }
            var cut60 = now - 60000;
            while (chatTimes.length && chatTimes[0] < cut60) chatTimes.shift();
            $('dbPulseRate').textContent = chatTimes.length;
            $('dbPulseChatters').textContent = active;
        }
        var socket, reconnectAttempts = 0;
        function connectWebSocket() {
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
            socket.on('connect', function () {
                reconnectAttempts = 0;
                $('dbTickerConn').textContent = I18N.connected;
                socket.emit('REGISTER', { code: CODE, channel: 'Dashboard', name: 'Dashboard' });
            });
            socket.on('disconnect', attemptReconnect);
            socket.on('connect_error', attemptReconnect);
            socket.on('STREAM_ONLINE', function () { loadLive(); });
            socket.on('STREAM_OFFLINE', function () { loadLive(); });
            socket.on('TWITCH_FOLLOW', function (p) { pushTicker('follow', 'fas fa-heart', actorOf(p), esc(I18N.ev_followed)); });
            socket.on('TWITCH_SUB', function (p) { pushTicker('sub', 'fas fa-star', actorOf(p), esc(I18N.ev_subbed)); });
            socket.on('TWITCH_CHEER', function (p) { pushTicker('cheer', 'fas fa-gem', actorOf(p), esc(I18N.ev_cheered)); });
            socket.on('TWITCH_RAID', function (p) { pushTicker('raid', 'fas fa-users', actorOf(p), esc(I18N.ev_raided)); });
            socket.on('TWITCH_CHANNELPOINTS', function (p) {
                var actor = actorOf(p);
                var title = (p && p.reward_title) || '';
                if ((!actor || !title) && p && p.rewards) {
                    try {
                        var rd = (typeof p.rewards === 'string') ? JSON.parse(p.rewards) : p.rewards;
                        if (rd) {
                            if (!actor) actor = rd.user_name || rd.user_login || '';
                            if (!title) title = (rd.reward && rd.reward.title) || '';
                        }
                    } catch (e) {}
                }
                pushTicker('points', 'fas fa-circle-dot', actor, esc(title || I18N.ev_redeemed));
            });
            socket.on('CHAT_MESSAGE', onChat);
            socket.onAny(function (event, p) {
                if (event === 'TWITCH_GIFT_SUB') pushTicker('sub', 'fas fa-gift', actorOf(p), esc(I18N.ev_gifted));
                else if (event === 'TWITCH_HYPE_TRAIN') pushTicker('hype', 'fas fa-train', '', esc(I18N.ev_hype));
                else if (event === 'TWITCH_CHARITY') pushTicker('charity', 'fas fa-hand-holding-heart', actorOf(p), esc(I18N.ev_charity));
                else if (event === 'KOFI' || event === 'PATREON' || event === 'FOURTHWALL') pushTicker('tip', 'fas fa-mug-hot', '', esc(I18N.ev_tip) + ' (' + esc(event.toLowerCase()) + ')');
            });
        }
        function attemptReconnect() {
            reconnectAttempts++;
            var conn = $('dbTickerConn'); if (conn) conn.textContent = I18N.reconnecting;
            var delay = Math.min(5000 * reconnectAttempts, 30000);
            setTimeout(connectWebSocket, delay);
        }

        document.addEventListener('DOMContentLoaded', function () {
            sessionSince = getCookie('dbLastVisit');
            loadLive();
            loadInitial();
            loadBoards();
            loadActivity();
            connectWebSocket();
            setInterval(updatePulse, 5000);
            var sw = $('dbWindowSwitch');
            if (sw) {
                sw.addEventListener('click', function (e) {
                    var btn = e.target.closest('.db-window-btn');
                    if (!btn) return;
                    currentWindow = btn.getAttribute('data-window');
                    var all = sw.querySelectorAll('.db-window-btn');
                    for (var i = 0; i < all.length; i++) all[i].classList.toggle('is-active', all[i] === btn);
                    loadSummaryOnly();
                });
            }
        });
    })();
    </script>
    <?php
    $scripts = ob_get_clean();
    include "layout.php";
} else {
    // User is not logged in - show landing page
    // This branch renders its own HTML document (no layout.php), so load the
    // i18n helper here to make t() available for the landing-page strings.
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    include_once __DIR__ . '/lang/i18n.php';
    $pageTitle = t('dashboard_page_title_landing');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <!-- Theme bootstrap: apply saved/OS theme before stylesheets paint (avoids flash) -->
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('sp-theme');
                    if (t !== 'light' && t !== 'dark') {
                        t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
                    }
                    document.documentElement.setAttribute('data-theme', t);
                    document.documentElement.className = (t === 'light' ? 'light-theme' : 'dark-theme');
                } catch (e) {}
            })();
        </script>
        <title>BotOfTheSpecter - <?php echo $pageTitle; ?></title>
        <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
        <link rel="stylesheet" href="/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/css/dashboard.css'); ?>">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
        <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
        <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    </head>
    <body>
        <!-- Top nav -->
        <header class="db-topnav">
            <a href="dashboard.php" class="db-topnav-brand">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter">
                BotOfTheSpecter
            </a>
            <button class="sp-theme-toggle" id="spThemeToggle" type="button" aria-label="<?= htmlspecialchars(t('dashboard_toggle_theme_aria')) ?>" title="<?= htmlspecialchars(t('dashboard_toggle_theme_title')) ?>">
                <i class="fas fa-moon"></i>
            </button>
            <a href="login.php" class="sp-btn sp-btn-primary" style="border-radius: var(--radius-pill);"><i class="fab fa-twitch"></i> <?= t('dashboard_login_with_twitch') ?></a>
        </header>
        <!-- Hero -->
        <section class="db-hero">
            <h1><i class="fas fa-robot"></i> <?= t('dashboard_hero_title') ?></h1>
            <p class="db-hero-sub"><?= t('dashboard_hero_sub') ?></p>
            <p class="db-hero-desc"><?= t('dashboard_hero_desc') ?></p>
        </section>
        <!-- Login card -->
        <div class="db-login-card">
            <h3><i class="fas fa-sign-in-alt"></i> <?= t('dashboard_access_your_dashboard') ?></h3>
            <p><?= t('dashboard_access_desc') ?></p>
            <a href="login.php" class="db-twitch-btn"><i class="fab fa-twitch"></i> <?= t('dashboard_login_with_twitch') ?></a>
            <p class="db-login-note"><i class="fas fa-shield-alt"></i> <?= t('dashboard_data_secure_note') ?></p>
        </div>
        <!-- Features -->
        <div class="db-landing-section">
            <div class="db-landing-section-header">
                <h2><?= t('dashboard_features_title') ?></h2>
                <p><?= t('dashboard_features_subtitle') ?></p>
            </div>
            <div class="db-features-grid">
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-robot"></i></div>
                    <h4><?= t('dashboard_feature_bot_control') ?></h4>
                    <p><?= t('dashboard_feature_bot_control_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--green);"><i class="fas fa-terminal"></i></div>
                    <h4><?= t('dashboard_feature_custom_commands') ?></h4>
                    <p><?= t('dashboard_feature_custom_commands_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--amber);"><i class="fas fa-chart-line"></i></div>
                    <h4><?= t('dashboard_feature_analytics_logs') ?></h4>
                    <p><?= t('dashboard_feature_analytics_logs_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--red);"><i class="fas fa-gift"></i></div>
                    <h4><?= t('dashboard_feature_channel_rewards') ?></h4>
                    <p><?= t('dashboard_feature_channel_rewards_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-volume-up"></i></div>
                    <h4><?= t('dashboard_feature_stream_alerts') ?></h4>
                    <p><?= t('dashboard_feature_stream_alerts_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--accent-hover);"><i class="fas fa-plug"></i></div>
                    <h4><?= t('dashboard_feature_integrations') ?></h4>
                    <p><?= t('dashboard_feature_integrations_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--amber);"><i class="fas fa-coins"></i></div>
                    <h4><?= t('dashboard_feature_points_system') ?></h4>
                    <p><?= t('dashboard_feature_points_system_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--blue);"><i class="fas fa-layer-group"></i></div>
                    <h4><?= t('dashboard_feature_stream_overlays') ?></h4>
                    <p><?= t('dashboard_feature_stream_overlays_desc') ?></p>
                </div>
                <div class="db-feature-card">
                    <div class="db-feature-card-icon" style="color: var(--green);"><i class="fas fa-users"></i></div>
                    <h4><?= t('dashboard_feature_user_management') ?></h4>
                    <p><?= t('dashboard_feature_user_management_desc') ?></p>
                </div>
            </div>
        </div>
        <!-- Premium Plans -->
        <div class="db-landing-section" style="padding-top: 0;">
            <div class="db-landing-section-header">
                <h2><?= t('dashboard_premium_plans') ?></h2>
                <p><?= t('dashboard_premium_plans_subtitle') ?></p>
            </div>
            <div class="db-plans-grid">
                <!-- Free Plan -->
                <div class="db-plan-card">
                    <div class="db-plan-card-icon" style="color: var(--text-muted);"><i class="fas fa-rocket"></i></div>
                    <h3><?= t('dashboard_plan_free') ?></h3>
                    <div class="db-plan-price"><?= t('dashboard_plan_free_price') ?></div>
                    <ul>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_core_bot_features') ?></li>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_community_support') ?></li>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_20mb_storage') ?></li>
                        <li><i class="fas fa-check"></i> <?= t('dashboard_plan_shared_bot_name') ?></li>
                        <li><i class="fas fa-flask"></i> <?= t('dashboard_plan_custom_bot_name') ?></li>
                    </ul>
                    <a href="login.php" class="sp-btn sp-btn-success" style="width: 100%; justify-content: center;"><i class="fas fa-sign-in-alt"></i> <?= t('dashboard_get_started') ?></a>
                    <p style="font-size: 0.75rem; color: var(--text-muted); text-align: center; margin-top: 0.75rem;"><?= t('dashboard_free_percent_note') ?></p>
                </div>
                <?php
                $plans = [
                    '1000' => [
                        'name' => 'Tier 1',
                        'price' => '$4.99/month',
                        'features' => [t('dashboard_plan_song_request_command'), t('dashboard_plan_priority_support'), t('dashboard_plan_beta_access'), t('dashboard_plan_50mb_storage')],
                        'icon' => 'fas fa-star',
                        'icon_color' => 'var(--blue)',
                    ],
                    '2000' => [
                        'name' => 'Tier 2',
                        'price' => '$9.99/month',
                        'features' => [t('dashboard_plan_everything_tier1'), t('dashboard_plan_personal_support'), t('dashboard_plan_ai_features'), t('dashboard_plan_100mb_storage')],
                        'icon' => 'fas fa-crown',
                        'icon_color' => 'var(--amber)',
                    ],
                    '3000' => [
                        'name' => 'Tier 3',
                        'price' => '$24.99/month',
                        'features' => [t('dashboard_plan_everything_tier2'), t('dashboard_plan_200mb_storage')],
                        'icon' => 'fas fa-gem',
                        'icon_color' => 'var(--red)',
                    ],
                ];
                foreach ($plans as $planDetails): ?>
                <div class="db-plan-card">
                    <div class="db-plan-card-icon" style="color: <?php echo $planDetails['icon_color']; ?>;"><i class="<?php echo $planDetails['icon']; ?>"></i></div>
                    <h3><?php echo htmlspecialchars($planDetails['name']); ?></h3>
                    <div class="db-plan-price"><?php echo htmlspecialchars($planDetails['price']); ?></div>
                    <ul>
                        <?php foreach ($planDetails['features'] as $feature): ?>
                        <li><i class="fas fa-check"></i> <?php echo htmlspecialchars($feature); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="https://www.twitch.tv/subs/gfaundead" target="_blank" class="sp-btn sp-btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-plus-circle"></i> <?= t('dashboard_subscribe') ?></a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Footer -->
        <footer class="db-landing-footer">
            &copy; 2023&ndash;<?php echo date('Y'); ?> <?= t('dashboard_footer_rights') ?><br>
            <?php include '/var/www/config/project-time.php'; ?>
            <?= t('dashboard_footer_business_name') ?><br>
            <?= t('dashboard_footer_not_affiliated') ?><br>
            <?= t('dashboard_footer_trademarks') ?>
            <br><span class="sp-version-badge" style="margin-top: 0.5rem; display: inline-flex;"><?= t('dashboard_footer_version_label') ?> v<?php echo $dashboardVersion; ?></span>
        </footer>
        <script>
            // Light/dark theme toggle (landing top nav). The <head> bootstrap sets the initial theme.
            (function () {
                var btn = document.getElementById('spThemeToggle');
                function current() { return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
                function syncIcon(theme) {
                    if (!btn) return;
                    var icon = btn.querySelector('i');
                    if (icon) icon.className = (theme === 'light' ? 'fas fa-sun' : 'fas fa-moon');
                }
                function apply(theme, persist) {
                    document.documentElement.setAttribute('data-theme', theme);
                    document.documentElement.className = (theme === 'light' ? 'light-theme' : 'dark-theme');
                    if (persist) { try { localStorage.setItem('sp-theme', theme); } catch (e) {} }
                    syncIcon(theme);
                }
                syncIcon(current());
                if (btn) btn.addEventListener('click', function () { apply(current() === 'light' ? 'dark' : 'light', true); });
                window.addEventListener('storage', function (e) {
                    if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) { apply(e.newValue, false); }
                });
            })();
        </script>
    </body>
    </html>
    <?php
}
?>
