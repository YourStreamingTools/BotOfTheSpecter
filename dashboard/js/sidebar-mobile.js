(function(){
    // Mobile sidebar toggle behavior — robust init whether DOMContentLoaded already fired or not
    // Expose a global toggle so inline onclick or other scripts can toggle reliably
    window.toggleMobileSidebar = function(e){
        if(e && e.preventDefault) e.preventDefault();
        var body = document.body;
        if(!body) return;
        var mobileMenu = document.getElementById('mobileMenu');
        var toggle = document.getElementById('mobileSidebarToggle');
        var overlay = document.getElementById('mobileSidebarOverlay');
        if(body.classList.contains('sidebar-open')){
            body.classList.remove('sidebar-open');
            if(mobileMenu) mobileMenu.setAttribute('aria-hidden','true');
            if(toggle) toggle.setAttribute('aria-expanded','false');
            if(overlay) overlay.style.display = 'none';
            document.documentElement.style.overflow = '';
        } else {
            body.classList.add('sidebar-open');
            if(mobileMenu) mobileMenu.setAttribute('aria-hidden','false');
            if(toggle) toggle.setAttribute('aria-expanded','true');
            if(overlay) overlay.style.display = 'block';
            document.documentElement.style.overflow = 'hidden';
        }
    };
    function initMobileSidebar(){
        var toggle = document.getElementById('mobileSidebarToggle');
        var body = document.body;
        if(!body) return;
        // Inject a late CSS override to ensure mobile panel can be shown
        try{
            if(!document.getElementById('sidebar-mobile-runtime-style')){
                var s = document.createElement('style');
                s.id = 'sidebar-mobile-runtime-style';
                s.textContent = "@media screen and (max-width:1023px){ .mobile-menu, #mobileMenu { display: flex !important; visibility: visible !important; pointer-events: auto !important; transform: translateX(-110%) !important; } body.sidebar-open .mobile-menu, body.sidebar-open #mobileMenu { transform: translateX(0) !important; } }";
                document.head.appendChild(s);
            }
        }catch(e){ }
        // Runtime override: ensure navbar and toggle accept pointer events
        try{
            var mobileTopNavbar = document.getElementById('mobileTopNavbar');
            if(mobileTopNavbar){
                mobileTopNavbar.style.pointerEvents = 'auto';
                mobileTopNavbar.style.zIndex = '20000';
                mobileTopNavbar.style.position = 'relative';
            }
            if(toggle){
                toggle.style.pointerEvents = 'auto';
                toggle.style.zIndex = '20010';
                toggle.style.cursor = 'pointer';
            }
        }catch(e){ }
        var existingOverlay = document.getElementById('mobileSidebarOverlay');
        var overlay = existingOverlay || document.createElement('div');
        overlay.id = 'mobileSidebarOverlay';
        overlay.style.position = 'fixed';
        overlay.style.top = '0';
        overlay.style.left = '0';
        overlay.style.right = '0';
        overlay.style.bottom = '0';
        overlay.style.background = 'rgba(0,0,0,0.45)';
        overlay.style.zIndex = '1090';
        overlay.style.display = 'none';
        if(!existingOverlay) {
            document.body.appendChild(overlay);
        }
        var mobileMenu = document.getElementById('mobileMenu');
        var mobileMenuClose = document.getElementById('mobileMenuClose');
        function openSidebar(){
            body.classList.add('sidebar-open');
            overlay.style.display = 'block';
            if(mobileMenu) {
                // Mark visible to assistive tech before moving focus
                mobileMenu.setAttribute('aria-hidden', 'false');
                mobileMenu.classList.add('is-open');
                // Force inline styles so conflicting CSS can't keep it hidden
                mobileMenu.style.display = 'flex';
                mobileMenu.style.visibility = 'visible';
                mobileMenu.style.pointerEvents = 'auto';
                mobileMenu.style.transform = 'translateX(0)';
                mobileMenu.style.zIndex = '1112';
                // Move focus into the panel after it is revealed
                setTimeout(function(){
                    try{
                        if(mobileMenuClose && typeof mobileMenuClose.focus === 'function'){
                            mobileMenuClose.focus();
                        } else {
                            var firstFocusable = mobileMenu.querySelector('a, button, input, [tabindex]:not([tabindex="-1"])');
                            if(firstFocusable && typeof firstFocusable.focus === 'function') firstFocusable.focus();
                        }
                    }catch(e){}
                }, 50);
            }
            if(toggle) toggle.setAttribute('aria-expanded', 'true');
            document.documentElement.style.overflow = 'hidden';
        }
        function closeSidebar(){
            body.classList.remove('sidebar-open');
            overlay.style.display = 'none';
            if(mobileMenu) {
                mobileMenu.classList.remove('is-open');
                // animate out, then hide to avoid being blocked by other CSS
                mobileMenu.style.transform = 'translateX(-110%)';
                mobileMenu.style.pointerEvents = 'none';
                // move focus back to the toggle BEFORE hiding so focus isn't inside an aria-hidden container
                try{ if(toggle && typeof toggle.focus === 'function') toggle.focus(); }catch(e){}
                // now mark hidden for assistive tech
                mobileMenu.setAttribute('aria-hidden', 'true');
                setTimeout(function(){
                    try{ mobileMenu.style.display = 'none'; mobileMenu.style.visibility = 'hidden'; }catch(e){}
                }, 240);
            }
            if(toggle) toggle.setAttribute('aria-expanded', 'false');
            document.documentElement.style.overflow = '';
        }
        if(toggle){
            
            toggle.addEventListener('click', function(e){
                e.preventDefault();
                if(body.classList.contains('sidebar-open')){
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
            // keyboard accessibility
            toggle.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.click(); } });
            toggle.setAttribute('aria-controls','mobileMenu');
            toggle.setAttribute('aria-expanded','false');
            toggle.setAttribute('role','button');
        }
        overlay.addEventListener('click', function(){ closeSidebar(); });
        if(mobileMenuClose){ mobileMenuClose.addEventListener('click', function(e){ e.preventDefault(); closeSidebar(); }); }
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeSidebar(); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileSidebar);
    } else {
        initMobileSidebar();
    }
})();