console.log('panel.js loaded: v3');
window.Twitch.ext.onAuthorized((auth) => {
    const channelId = auth.channelId;
    const API_BASE = 'https://api.botofthespecter.com';
    function apiUrl(path) {
        return `${API_BASE}/extension/${path}?channel_id=${encodeURIComponent(channelId)}`;
    }
    function formatSeconds(s) {
        s = parseInt(s) || 0;
        if (s <= 0) return 'None';
        const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
        const parts = [];
        for (const [name, div] of units) {
            const q = Math.floor(s / div);
            if (q > 0) { parts.push(`${q} ${name}${q !== 1 ? 's' : ''}`); s -= q * div; }
        }
        return parts.slice(0, 2).join(', ') || 'Less than a minute';
    }
    function showLoading(container, title) {
        container.innerHTML = `<h3 class="ext-section-title">${title}</h3><p class="ext-muted">Loading...</p>`;
    }
    function showError(container, title) {
        container.innerHTML = `<h3 class="ext-section-title">${title}</h3><p class="ext-danger">Could not load data.</p>`;
    }
    function makeTable(headers, rows) {
        if (!rows.length) return '<p class="ext-muted">No data yet.</p>';
        const ths = headers.map(h => `<th>${h}</th>`).join('');
        const trs = rows.map(r => `<tr>${r.map(c => `<td>${c ?? ''}</td>`).join('')}</tr>`).join('');
        return `<div class="ext-table-wrap"><table class="ext-table"><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table></div>`;
    }
    const container = document.querySelector('.ext-container') || document.body;
    const dynamicContainer = document.createElement('div');
    dynamicContainer.id = 'dynamic-content';
    container.appendChild(dynamicContainer);
    const options = [
        { key: 'commands',     label: 'Commands',      endpoint: 'commands' },
        { key: 'lurkers',      label: 'Lurkers',        endpoint: 'lurkers' },
        { key: 'typos',        label: 'Typos',          endpoint: 'typos' },
        { key: 'deaths',       label: 'Deaths',         endpoint: 'deaths' },
        { key: 'hugs',         label: 'Hugs',           endpoint: 'hugs' },
        { key: 'kisses',       label: 'Kisses',         endpoint: 'kisses' },
        { key: 'highfives',    label: 'High-Fives',     endpoint: 'highfives' },
        { key: 'custom',       label: 'Custom Counts',  endpoint: 'custom-counts' },
        { key: 'userCounts',   label: 'User Counts',    endpoint: 'user-counts' },
        { key: 'rewardCounts', label: 'Rewards',        endpoint: 'reward-counts' },
        { key: 'watchTime',    label: 'Watch Time',     endpoint: 'watch-time' },
        { key: 'quotes',       label: 'Quotes',         endpoint: 'quotes' },
        { key: 'todos',        label: 'To-Do',          endpoint: 'todos' }
    ];
    const btnContainer = document.createElement('div');
    btnContainer.className = 'ext-button-row';
    options.forEach(opt => {
        const btn = document.createElement('button');
        btn.className = 'ext-btn ext-btn-primary';
        btn.textContent = opt.label;
        btn.onclick = () => {
            dynamicContainer.innerHTML = '';
            showLoading(dynamicContainer, opt.label);
            fetch(apiUrl(opt.endpoint))
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    dynamicContainer.innerHTML = '';
                    const title = document.createElement('h3');
                    title.className = 'ext-section-title';
                    title.textContent = opt.label;
                    dynamicContainer.appendChild(title);
                    let html = '';
                    switch (opt.key) {
                        case 'commands':
                            html = makeTable(['Command', 'Response'], (data.commands || []).map(r => [`!${r.command}`, r.response]));
                            break;
                        case 'lurkers':
                            html = makeTable(['User ID', 'Since'], (data.lurkers || []).map(r => [r.user_id, r.start_time]));
                            break;
                        case 'typos':
                            html = makeTable(['User', 'Typos'], (data.typos || []).map(r => [r.username, r.typo_count]));
                            break;
                        case 'deaths':
                            html = `<p>Total deaths: <span class="ext-meta">${data.total_deaths ?? 0}</span></p>`;
                            html += makeTable(['Game', 'Deaths'], (data.game_deaths || []).map(r => [r.game_name, r.death_count]));
                            break;
                        case 'hugs':
                            html = `<p>Total hugs: <span class="ext-meta">${data.total_hugs ?? 0}</span></p>`;
                            html += makeTable(['User', 'Hugs'], (data.hug_counts || []).map(r => [r.username, r.hug_count]));
                            break;
                        case 'kisses':
                            html = `<p>Total kisses: <span class="ext-meta">${data.total_kisses ?? 0}</span></p>`;
                            html += makeTable(['User', 'Kisses'], (data.kiss_counts || []).map(r => [r.username, r.kiss_count]));
                            break;
                        case 'highfives':
                            html = makeTable(['User', 'High-Fives'], (data.highfive_counts || []).map(r => [r.username, r.highfive_count]));
                            break;
                        case 'custom':
                            html = makeTable(['Command', 'Count'], (data.custom_counts || []).map(r => [r.command, r.count]));
                            break;
                        case 'userCounts':
                            html = makeTable(['Command', 'User', 'Count'], (data.user_counts || []).map(r => [r.command, r.user, r.count]));
                            break;
                        case 'rewardCounts':
                            html = makeTable(['Reward', 'User', 'Count'], (data.reward_counts || []).map(r => [r.reward_title || r.reward_id, r.user, r.count]));
                            break;
                        case 'watchTime':
                            html = makeTable(['User', 'Live', 'Offline'], (data.watch_time || []).map(r => [r.username, formatSeconds(r.total_watch_time_live), formatSeconds(r.total_watch_time_offline)]));
                            break;
                        case 'quotes':
                            html = makeTable(['#', 'Author', 'Quote'], (data.quotes || []).map(r => [r.id, r.author, r.quote]));
                            break;
                        case 'todos':
                            html = makeTable(['Task', 'Status'], (data.todos || []).map(r => [r.task, r.status]));
                            break;
                    }
                    dynamicContainer.insertAdjacentHTML('beforeend', html);
                })
                .catch(() => showError(dynamicContainer, opt.label));
        };
        btnContainer.appendChild(btn);
    });
    container.appendChild(btnContainer);
    const membersBtn = document.createElement('a');
    membersBtn.href = 'https://members.botofthespecter.com/';
    membersBtn.className = 'ext-btn ext-btn-link';
    membersBtn.textContent = 'Members Page';
    membersBtn.target = '_blank';
    membersBtn.rel = 'noopener noreferrer';
    container.appendChild(membersBtn);
});
