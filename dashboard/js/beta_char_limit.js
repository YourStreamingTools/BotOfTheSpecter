// Saves the beta-bot 500-character editor toggle to the channel database.
function saveBetaCharLimit(enabled, done) {
    var body = 'ajax_action=beta_char_limit&enabled=' + (enabled ? '1' : '0');
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body
    }).then(function (response) {
        return response.json();
    }).then(function (data) {
        if (typeof done === 'function') {
            done(!!(data && data.success));
        }
    }).catch(function () {
        if (typeof done === 'function') {
            done(false);
        }
    });
}

function onBetaBotToggle(enabled) {
    var toggle = document.getElementById('betaBotToggle');
    var next = !!enabled;
    if (typeof applyBetaBotCharLimit === 'function') {
        applyBetaBotCharLimit(next);
    }
    saveBetaCharLimit(next, function (ok) {
        if (ok) {
            try { localStorage.removeItem('betaBotMode'); } catch (e) {}
            return;
        }
        var reverted = !next;
        if (toggle) {
            toggle.checked = reverted;
        }
        if (typeof applyBetaBotCharLimit === 'function') {
            applyBetaBotCharLimit(reverted);
        }
    });
}

// serverEnabled comes from bot_settings. A leftover localStorage "1" is copied once, then dropped.
function initBetaCharLimitToggle(serverEnabled) {
    var toggle = document.getElementById('betaBotToggle');
    var enabled = !!serverEnabled;
    var legacy = null;
    try { legacy = localStorage.getItem('betaBotMode'); } catch (e) {}
    if (legacy === '1' && !enabled) {
        enabled = true;
        if (toggle) {
            toggle.checked = true;
        }
        if (typeof applyBetaBotCharLimit === 'function') {
            applyBetaBotCharLimit(true);
        }
        saveBetaCharLimit(true, function (ok) {
            if (!ok) {
                if (toggle) {
                    toggle.checked = !!serverEnabled;
                }
                if (typeof applyBetaBotCharLimit === 'function') {
                    applyBetaBotCharLimit(!!serverEnabled);
                }
                return;
            }
            try { localStorage.removeItem('betaBotMode'); } catch (e) {}
        });
        return;
    }
    if (toggle) {
        toggle.checked = enabled;
    }
    if (typeof applyBetaBotCharLimit === 'function') {
        applyBetaBotCharLimit(enabled);
    }
    if (legacy !== null) {
        try { localStorage.removeItem('betaBotMode'); } catch (e) {}
    }
}
