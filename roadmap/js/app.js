/* ============================================================
   BotOfTheSpecter Roadmap — app.js
   Sidebar toggle only (modal and roadmap logic is inline in layout.php)
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
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    }
    /* ----------------------------------------------------------
       Active nav link highlighting
    ---------------------------------------------------------- */
    function initActiveNav() {
        var path = window.location.pathname;
        var links = document.querySelectorAll('.sp-nav-link[href]');
        links.forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href.startsWith('http') || href === '#') return;
            // normalize: strip query/hash
            var linkPath = href.split('?')[0].split('#')[0];
            if (linkPath === path || (linkPath !== '/' && path.endsWith(linkPath))) {
                link.classList.add('active');
            }
        });
    }
    /* ----------------------------------------------------------
       Init
    ---------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initActiveNav();
    });
}());