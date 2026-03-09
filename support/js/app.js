/* ============================================================
   BotOfTheSpecter Support Portal — app.js
   Vanilla JS only, no dependencies
   ============================================================ */

(function () {
    'use strict';
    /* ----------------------------------------------------------
       Sidebar toggle (mobile)
    ---------------------------------------------------------- */
    function initSidebar() {
        var hamburger = document.getElementById('sp-hamburger');
        var sidebar   = document.getElementById('sp-sidebar');
        var overlay   = document.getElementById('sp-sidebar-overlay');
        if (!hamburger || !sidebar) return;
        function openSidebar() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeSidebar() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('open');
            document.body.style.overflow = '';
        }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }
        // Close on ESC
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    }
    /* ----------------------------------------------------------
       Tabs (doc sections on index.php)
    ---------------------------------------------------------- */
    function initTabs() {
        var tabs   = document.querySelectorAll('.sp-tab[data-tab]');
        var panels = document.querySelectorAll('.sp-tab-panel[data-panel]');
        if (!tabs.length) return;
        function activate(id) {
            tabs.forEach(function (t) {
                t.classList.toggle('active', t.dataset.tab === id);
            });
            panels.forEach(function (p) {
                p.classList.toggle('active', p.dataset.panel === id);
            });
            // Persist selection in session storage so reload stays on same tab
            try { sessionStorage.setItem('sp_active_tab', id); } catch (e) {}
        }
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                activate(tab.dataset.tab);
            });
        });
        // Restore from session or URL hash
        var hash    = window.location.hash.replace('#', '');
        var stored  = '';
        try { stored = sessionStorage.getItem('sp_active_tab') || ''; } catch (e) {}
        var initial = hash || stored || (tabs[0] && tabs[0].dataset.tab);
        if (initial) activate(initial);
    }
    /* ----------------------------------------------------------
       FAQ accordion
    ---------------------------------------------------------- */
    function initFaq() {
        document.querySelectorAll('.sp-faq-q').forEach(function (q) {
            q.addEventListener('click', function () {
                var item = q.closest('.sp-faq-item');
                if (!item) return;
                var isOpen = item.classList.contains('open');
                // Close all
                document.querySelectorAll('.sp-faq-item.open').forEach(function (i) {
                    i.classList.remove('open');
                });
                // Open clicked if it wasn't already open
                if (!isOpen) item.classList.add('open');
            });
        });
    }
    /* ----------------------------------------------------------
       Inline search (searches visible tab-panel text)
    ---------------------------------------------------------- */
    var SEARCH_INDEX = [];
    function buildSearchIndex() {
        var panels = document.querySelectorAll('.sp-tab-panel[data-panel]');
        panels.forEach(function (panel) {
            var panelId    = panel.dataset.panel;
            var panelLabel = '';
            var tab = document.querySelector('.sp-tab[data-tab="' + panelId + '"]');
            if (tab) panelLabel = tab.textContent.trim();
            // Index headings
            panel.querySelectorAll('h2, h3, h4, .sp-faq-q').forEach(function (el) {
                var text = el.textContent.trim();
                if (text) {
                    SEARCH_INDEX.push({
                        title:   text,
                        section: panelLabel,
                        tab:     panelId,
                        el:      el,
                    });
                }
            });
        });
    }
    function initSearch() {
        var input   = document.getElementById('sp-search-input');
        var results = document.getElementById('sp-search-results');
        if (!input || !results) return;
        buildSearchIndex();
        function renderResults(query) {
            query = query.trim().toLowerCase();
            results.innerHTML = '';
            if (query.length < 2) {
                results.classList.remove('open');
                return;
            }
            var matches = SEARCH_INDEX.filter(function (item) {
                return item.title.toLowerCase().indexOf(query) !== -1;
            }).slice(0, 8);
            if (!matches.length) {
                results.innerHTML = '<div class="sp-search-no-results">No results for "<strong>' +
                    escHtml(query) + '</strong>"</div>';
                results.classList.add('open');
                return;
            }
            matches.forEach(function (match) {
                var el = document.createElement('a');
                el.className = 'sp-search-result-item';
                el.href = '#';
                el.innerHTML =
                    '<span class="sp-search-result-title">' + escHtml(match.title) + '</span>' +
                    '<span class="sp-search-result-section">' + escHtml(match.section) + '</span>';
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    // Switch to the right tab
                    var tabs = document.querySelectorAll('.sp-tab[data-tab]');
                    var panels = document.querySelectorAll('.sp-tab-panel[data-panel]');
                    tabs.forEach(function (t) {
                        t.classList.toggle('active', t.dataset.tab === match.tab);
                    });
                    panels.forEach(function (p) {
                        p.classList.toggle('active', p.dataset.panel === match.tab);
                    });
                    // Scroll to element
                    match.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    results.classList.remove('open');
                    input.value = '';
                    // Expand FAQ if it's inside one
                    var faqItem = match.el.closest('.sp-faq-item');
                    if (faqItem) faqItem.classList.add('open');
                });
                results.appendChild(el);
            });
            results.classList.add('open');
        }
        input.addEventListener('input', function () {
            renderResults(input.value);
        });
        input.addEventListener('focus', function () {
            if (input.value.length >= 2) results.classList.add('open');
        });
        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                results.classList.remove('open');
            }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                results.classList.remove('open');
                input.blur();
            }
        });
    }
    /* ----------------------------------------------------------
       Character counter for textareas
    ---------------------------------------------------------- */
    function initCharCounters() {
        document.querySelectorAll('textarea[data-min-chars]').forEach(function (ta) {
            var min     = parseInt(ta.dataset.minChars || '0', 10);
            var counter = ta.parentElement && ta.parentElement.querySelector('.sp-char-counter');
            if (!counter) return;
            function update() {
                var len = ta.value.length;
                counter.textContent = len + ' chars' + (min > 0 ? ' (min ' + min + ')' : '');
                counter.classList.toggle('warn', min > 0 && len > 0 && len < min);
                counter.classList.toggle('ok',   min > 0 && len >= min);
                ta.classList.toggle('error', min > 0 && len > 0 && len < min);
            }
            ta.addEventListener('input', update);
            update();
        });
    }
    /* ----------------------------------------------------------
       Form CSRF submit once (prevent double submit)
    ---------------------------------------------------------- */
    function initForms() {
        document.querySelectorAll('form[data-once]').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (btn.dataset.loading || 'Submitting…');
                });
            });
        });
    }
    /* ----------------------------------------------------------
       Auto-dismiss alert messages
    ---------------------------------------------------------- */
    function initAlerts() {
        document.querySelectorAll('.sp-alert[data-dismiss]').forEach(function (alert) {
            var delay = parseInt(alert.dataset.dismiss || '4000', 10);
            setTimeout(function () {
                alert.style.transition = 'opacity 0.4s ease';
                alert.style.opacity = '0';
                setTimeout(function () { alert.remove(); }, 450);
            }, delay);
        });
    }
    /* ----------------------------------------------------------
       Active nav link highlight
    ---------------------------------------------------------- */
    function initActiveNav() {
        var path = window.location.pathname;
        document.querySelectorAll('.sp-nav-link[href]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href === '#') return;
            // Match current file name
            var linkFile = href.split('/').pop().split('?')[0];
            var pathFile = path.split('/').pop().split('?')[0];
            if (linkFile && pathFile && linkFile === pathFile) {
                link.classList.add('active');
            }
        });
    }
    /* ----------------------------------------------------------
       Helper: escape HTML
    ---------------------------------------------------------- */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    /* ----------------------------------------------------------
       Boot
    ---------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initTabs();
        initFaq();
        initSearch();
        initCharCounters();
        initForms();
        initAlerts();
        initActiveNav();
    });
}());