(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var hamburger = document.getElementById('hsHamburger');
        var mobileNav = document.getElementById('hsMobileNav');
        if (!hamburger || !mobileNav) return;

        hamburger.addEventListener('click', function () {
            var open = mobileNav.classList.toggle('open');
            hamburger.classList.toggle('open', open);
            hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (!hamburger.contains(e.target) && !mobileNav.contains(e.target)) {
                mobileNav.classList.remove('open');
                hamburger.classList.remove('open');
                hamburger.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                mobileNav.classList.remove('open');
                hamburger.classList.remove('open');
                hamburger.setAttribute('aria-expanded', 'false');
            }
        });
    });
}());