// Dashboard JavaScript functionality

// Global session-expiry handler.
// Wraps window.fetch so that any same-origin response with status 401
// (the standard reply from /var/www/lib/require_auth_ajax.php when the
// session is gone) auto-redirects the browser to /login.php, preserving
// the current page as the return target. This means individual fetch
// callers don't have to check for 401 themselves - they just become
// no-op promises that never resolve because the page is navigating away.
//
// Why patch window.fetch directly: every existing AJAX call in the
// dashboard (vanilla fetch, fetchWithTimeout helpers, jQuery $.ajax
// when it falls back to fetch) goes through window.fetch, so a single
// patch fixes every call site without per-file edits.
(function () {
    if (window.__BOTS_FETCH_AUTH_PATCHED) return;
    window.__BOTS_FETCH_AUTH_PATCHED = true;

    var _origFetch = window.fetch.bind(window);
    var _redirecting = false;

    window.fetch = function (input, init) {
        return _origFetch(input, init).then(function (response) {
            if (response.status !== 401 || _redirecting) return response;
            // Only redirect on 401 from same-origin requests. A 401 from a
            // third-party API (e.g. an external service the page probes)
            // shouldn't bounce the user out of the dashboard.
            var sameOrigin = true;
            try {
                var rawUrl = (typeof input === 'string') ? input : (input && input.url);
                var url = new URL(rawUrl, window.location.origin);
                sameOrigin = (url.origin === window.location.origin);
            } catch (e) { /* relative URL, treat as same-origin */ }
            if (!sameOrigin) return response;
            _redirecting = true;
            var returnTo = window.location.pathname + window.location.search;
            window.location.href = '/login.php?return_to=' + encodeURIComponent(returnTo);
            // Return a never-settling promise so callers don't try to parse
            // the 401 body while the navigation is happening.
            return new Promise(function () {});
        });
    };
})();

document.addEventListener('DOMContentLoaded', function() {
    // Initialize sidebar
    initializeSidebar();
    
    // Navbar burger menu toggle for mobile
    const navbarBurgers = Array.prototype.slice.call(document.querySelectorAll('.navbar-burger'), 0);
    if (navbarBurgers.length > 0) {
        navbarBurgers.forEach(el => {
            el.addEventListener('click', () => {
                const target = document.getElementById(el.dataset.target);
                el.classList.toggle('is-active');
                target.classList.toggle('is-active');
            });
        });
    }
    
    (function decorateSpecterErrorSupport() {
        var noteHtml = (window.SPECTER_ERROR_SUPPORT && window.SPECTER_ERROR_SUPPORT.noteHtml) || '';
        if (!noteHtml) return;
        function decorate(root) {
            var scope = root && root.querySelectorAll ? root : document;
            var nodes = [];
            if (root && root.matches && (root.matches('.sp-alert-danger') || root.matches('.notification.is-danger'))) {
                nodes.push(root);
            }
            var found = scope.querySelectorAll ? scope.querySelectorAll('.sp-alert-danger, .notification.is-danger') : [];
            for (var f = 0; f < found.length; f++) {
                nodes.push(found[f]);
            }
            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el.getAttribute('data-specter-support')) continue;
                var existing = el.textContent || '';
                if (existing.indexOf('tickets.php') !== -1 || el.querySelector('.specter-error-support')) {
                    el.setAttribute('data-specter-support', '1');
                    continue;
                }
                var p = document.createElement('p');
                p.className = 'specter-error-support';
                p.innerHTML = noteHtml;
                el.appendChild(p);
                el.setAttribute('data-specter-support', '1');
            }
        }
        decorate(document);
        if (typeof MutationObserver === 'undefined') return;
        var observer = new MutationObserver(function (mutations) {
            for (var m = 0; m < mutations.length; m++) {
                var added = mutations[m].addedNodes;
                for (var n = 0; n < added.length; n++) {
                    if (added[n].nodeType === 1) decorate(added[n]);
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    })();

    // Close notification buttons
    const closeButtons = Array.prototype.slice.call(document.querySelectorAll('.notification .delete'), 0);
    closeButtons.forEach(button => {
        const notification = button.parentNode;
        button.addEventListener('click', () => {
            notification.parentNode.removeChild(notification);
        });
    });
    
    // Bot selector dropdown functionality
    const botSelectorDropdown = document.getElementById('botSelector');
    if (botSelectorDropdown) {
        const trigger = botSelectorDropdown.querySelector('.dropdown-trigger');
        trigger.addEventListener('click', () => {
            botSelectorDropdown.classList.toggle('is-active');
        });
        
        // Close dropdown when clicking elsewhere on the page
        document.addEventListener('click', (event) => {
            if (!botSelectorDropdown.contains(event.target)) {
                botSelectorDropdown.classList.remove('is-active');
            }
        });
        
        // Bot selection handling
        const botOptions = document.querySelectorAll('.bot-option');
        botOptions.forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                
                // Update selected bot text
                document.getElementById('selectedBot').textContent = option.textContent;
                
                // Remove active class from all options
                botOptions.forEach(opt => opt.classList.remove('is-active'));
                
                // Add active class to selected option
                option.classList.add('is-active');
                
                // Close dropdown
                botSelectorDropdown.classList.remove('is-active');
                
                // Redirect to the bot page with the selected bot
                window.location.href = `bot.php?bot=${option.dataset.value}`;
            });
        });
    }
    
    // Status control buttons
    const forceOnlineBtn = document.getElementById('forceOnline');
    const forceOfflineBtn = document.getElementById('forceOffline');
    
    if (forceOnlineBtn) {
        forceOnlineBtn.addEventListener('click', function() {
            const botStatus = document.getElementById('botStatus');
            sendStreamEvent('STREAM_ONLINE');
            botStatus.textContent = 'STATUS: ONLINE';
            botStatus.style.color = 'var(--green)';
        });
    }

    if (forceOfflineBtn) {
        forceOfflineBtn.addEventListener('click', function() {
            const botStatus = document.getElementById('botStatus');
            sendStreamEvent('STREAM_OFFLINE');
            botStatus.textContent = 'STATUS: OFFLINE';
            botStatus.style.color = 'var(--amber)';
        });
    }
    
    // Function to send a stream event
    function sendStreamEvent(eventType) {
        fetch('/api/notify_event.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `event=${eventType}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log(`${eventType} event sent successfully`);
            } else {
                console.error(`Failed to send ${eventType} event: ${data.message}`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
});

// Sidebar functionality
function initializeSidebar() {
    const sidebar = document.getElementById('sidebarNav');
    const toggleBtn = document.getElementById('sidebarToggle');
    
    if (!sidebar || !toggleBtn) return;
    
    // Load saved state from cookie
    const savedState = getCookie('sidebar_collapsed');
    if (savedState === 'true') {
        sidebar.classList.add('collapsed');
    }
    
    // Toggle sidebar on button click
    toggleBtn.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        const isCollapsed = sidebar.classList.contains('collapsed');
        setCookie('sidebar_collapsed', isCollapsed, 365);
    });
    
    // Set active menu item based on current page
    setActiveMenuItem();
}

function toggleSubmenu(event, element) {
    event.preventDefault();
    const menuItem = element.closest('.sidebar-menu-item');
    const sidebar = document.getElementById('sidebarNav');
    
    // If sidebar is collapsed, don't toggle submenu
    if (sidebar && sidebar.classList.contains('collapsed')) {
        return;
    }
    
    // Close other submenus (accordion behavior)
    const allMenuItems = document.querySelectorAll('.sidebar-menu-item.has-submenu');
    allMenuItems.forEach(item => {
        if (item !== menuItem) {
            item.classList.remove('expanded');
        }
    });
    
    // Toggle current submenu
    menuItem.classList.toggle('expanded');
}

function setActiveMenuItem() {
    const currentPath = window.location.pathname;
    const fileName = currentPath.split('/').pop();
    const menuLinks = document.querySelectorAll('.sidebar-menu-link:not([onclick]), .sidebar-submenu-link');
    // Remove all active classes first
    menuLinks.forEach(link => link.classList.remove('active'));
    // Find the best matching link
    let bestMatch = null;
    let bestMatchLength = 0;
    menuLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (!href) return;
        // Extract just the filename from href (remove any query parameters or paths)
        const hrefFileName = href.split('/').pop().split('?')[0];
        // Exact match for the filename
        if (hrefFileName === fileName) {
            // Prefer longer matches (more specific)
            if (hrefFileName.length > bestMatchLength) {
                bestMatch = link;
                bestMatchLength = hrefFileName.length;
            }
        }
    });
    // Apply active state to the best match
    if (bestMatch) {
        bestMatch.classList.add('active');
        // If it's a submenu link, expand the parent
        const submenu = bestMatch.closest('.sidebar-submenu');
        if (submenu) {
            const parentItem = submenu.closest('.sidebar-menu-item');
            if (parentItem) {
                parentItem.classList.add('expanded');
            }
        }
    }
}

// Helper function to get cookie value
function getCookie(name) {
    var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
}

// Helper function to set cookie
function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = name + "=" + value + ";expires=" + d.toUTCString() + ";path=/";
}

// Append the support-ticket note on error SweetAlerts. Layout sets
// window.SPECTER_ERROR_SUPPORT before this file; SweetAlert2 is already loaded.
(function setupSpecterErrorSupportSwal() {
    function wrapSwalFire() {
        if (typeof Swal === 'undefined' || !Swal || typeof Swal.fire !== 'function') return;
        if (Swal.__specterErrorSupportWrapped) return;
        var origFire = Swal.fire.bind(Swal);
        Swal.fire = function specterSwalFire() {
            var args = arguments;
            var noteHtml = (window.SPECTER_ERROR_SUPPORT && window.SPECTER_ERROR_SUPPORT.noteHtml) || '';
            if (args.length === 1 && args[0] && Object.prototype.toString.call(args[0]) === '[object Object]') {
                var params = Object.assign({}, args[0]);
                var footerEmpty = params.footer == null || params.footer === false ||
                    (typeof params.footer === 'string' && params.footer.trim() === '');
                if (params.icon === 'error' && footerEmpty && noteHtml) {
                    params.footer = noteHtml;
                }
                return origFire(params);
            }
            if (args.length >= 3 && args[2] === 'error' && noteHtml) {
                return origFire({
                    title: args[0],
                    text: args[1],
                    icon: 'error',
                    footer: noteHtml
                });
            }
            return origFire.apply(Swal, args);
        };
        Swal.__specterErrorSupportWrapped = true;
    }
    wrapSwalFire();
    document.addEventListener('DOMContentLoaded', wrapSwalFire);
})();

// Global network-failure handlers. The dashboard has many AJAX calls
// without per-call .catch() - without these, a dropped connection or
// 500 produces a silent no-op that confuses users.
(function setupGlobalAjaxErrorHandling() {
    const showNetworkError = (msg) => {
        if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: 'error',
                title: 'Network error',
                text: msg,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true
            });
        } else if (typeof showNotification === 'function') {
            showNotification(msg, 'danger', 4000);
        } else {
            console.error('[network]', msg);
        }
    };

    if (typeof jQuery !== 'undefined' && jQuery && typeof jQuery.ajaxSetup === 'function') {
        jQuery(document).ajaxError(function (event, xhr, settings, thrownError) {
            // Skip aborted requests and intentional 4xx responses (those
            // are usually validation messages the page already displays).
            if (!xhr || xhr.status === 0 || xhr.statusText === 'abort') return;
            if (xhr.status >= 400 && xhr.status < 500) return;
            showNetworkError('Could not reach the server. Please try again.');
        });
    }

    window.addEventListener('unhandledrejection', function (event) {
        const reason = event.reason;
        const isFetchError = reason instanceof TypeError && /fetch|network|failed/i.test(String(reason.message || ''));
        if (!isFetchError) return;
        showNetworkError('Could not reach the server. Please try again.');
    });
})();

// Convenience wrapper for new code. Returns parsed JSON on 2xx, throws
// on non-JSON or network failure. Existing call sites continue to work
// untouched - the global handlers above protect callers that forgot
// .catch().
async function specterFetch(url, options) {
    const opts = Object.assign({ credentials: 'same-origin' }, options || {});
    const response = await fetch(url, opts);
    const contentType = response.headers.get('Content-Type') || '';
    let body = null;
    if (contentType.indexOf('application/json') !== -1) {
        body = await response.json().catch(() => null);
    } else {
        body = await response.text().catch(() => null);
    }
    return { ok: response.ok, status: response.status, body };
}

// Function to create toast notifications
function showNotification(message, type = 'info', duration = 3000) {
    var typeStr = String(type || '');
    if (/danger|error/i.test(typeStr)) {
        var supportNote = (window.SPECTER_ERROR_SUPPORT && window.SPECTER_ERROR_SUPPORT.note) || '';
        var msg = message == null ? '' : String(message);
        if (supportNote && msg.indexOf('tickets.php') === -1) {
            message = msg + ' | ' + supportNote;
        }
    }

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification is-${type} is-light`;
    notification.style.position = 'fixed';
    notification.style.top = '1rem';
    notification.style.right = '1rem';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '300px';
    notification.style.maxWidth = '500px';
    notification.style.opacity = '0';
    notification.style.transition = 'opacity 0.3s ease-in-out';
    
    // Add close button
    const closeButton = document.createElement('button');
    closeButton.className = 'delete';
    closeButton.addEventListener('click', () => {
        notification.style.opacity = '0';
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    });
    
    // Add message
    const messageElement = document.createElement('p');
    messageElement.textContent = message;
    
    // Assemble notification
    notification.appendChild(closeButton);
    notification.appendChild(messageElement);
    
    // Add to document
    document.body.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.style.opacity = '1';
    }, 10);
    
    // Auto-remove after duration
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => {
            if (notification.parentNode) {
                document.body.removeChild(notification);
            }
        }, 300);
    }, duration);
}

// Shared "rename an uploaded file" prompt used by media, music, sound/video
// alerts, walk-ons, and modules. Pages pass translated strings; the helper
// only talks to SweetAlert2 (loaded in layout.php before this file).
window.specterPromptRename = function (opts) {
    opts = opts || {};
    var current = String(opts.currentName || '');
    var stem = current.replace(/\\/g, '/');
    var slash = stem.lastIndexOf('/');
    if (slash >= 0) stem = stem.slice(slash + 1);
    stem = stem.replace(/\.[A-Za-z0-9]+$/, '');
    var hint = opts.hint ? '<p style="margin:0 0 0.75rem;color:var(--text-secondary);font-size:0.9rem;">' + opts.hint + '</p>' : '';
    return Swal.fire({
        title: opts.title || 'Rename file',
        html: hint || undefined,
        input: 'text',
        inputValue: stem,
        showCancelButton: true,
        confirmButtonText: opts.confirmText || 'Rename',
        cancelButtonText: opts.cancelText || 'Cancel',
        confirmButtonColor: '#7c5cbf',
        preConfirm: function (value) {
            if (!value || !String(value).trim()) {
                Swal.showValidationMessage(opts.emptyError || 'Enter a name.');
                return false;
            }
            return String(value).trim();
        }
    }).then(function (result) {
        if (!result.isConfirmed || !result.value) return null;
        return result.value;
    });
};

window.specterPostRename = function (url, fields) {
    var fd = new FormData();
    Object.keys(fields || {}).forEach(function (key) {
        fd.append(key, fields[key]);
    });
    return fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
        return response.json().then(function (data) {
            return data || { success: false, error: 'failed' };
        }).catch(function () {
            return { success: false, error: 'failed' };
        });
    });
};

window.specterRenameMessage = function (data, i18n) {
    i18n = i18n || {};
    if (data && data.success) {
        var name = (data.new || '').split('/').pop();
        return data.message || String(i18n.success || 'Renamed to %s').replace('%s', name);
    }
    var code = data && data.error;
    if (code && i18n[code]) return i18n[code];
    if (data && data.message) return data.message;
    return i18n.failed || 'Could not rename the file.';
};
