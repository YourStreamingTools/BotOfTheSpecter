(function(){
    // Mobile sidebar toggle behavior
    document.addEventListener('DOMContentLoaded', function(){
        var toggle = document.getElementById('mobileSidebarToggle');
        var body = document.body;
        var overlay = document.createElement('div');
        overlay.id = 'mobileSidebarOverlay';
        overlay.style.position = 'fixed';
        overlay.style.top = '0';
        overlay.style.left = '0';
        overlay.style.right = '0';
        overlay.style.bottom = '0';
        overlay.style.background = 'rgba(0,0,0,0.45)';
        overlay.style.zIndex = '1090';
        overlay.style.display = 'none';
        document.body.appendChild(overlay);
        var mobileMenu = document.getElementById('mobileMenu');
        var mobileMenuClose = document.getElementById('mobileMenuClose');
        function openSidebar(){
            body.classList.add('sidebar-open');
            overlay.style.display = '';
            if(mobileMenu) { mobileMenu.setAttribute('aria-hidden', 'false'); }
        }
        function closeSidebar(){
            body.classList.remove('sidebar-open');
            overlay.style.display = 'none';
            if(mobileMenu) { mobileMenu.setAttribute('aria-hidden', 'true'); }
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
        }
        overlay.addEventListener('click', function(){ closeSidebar(); });
        if(mobileMenuClose){ mobileMenuClose.addEventListener('click', function(){ closeSidebar(); }); }
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeSidebar(); });
    });
})();
