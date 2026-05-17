// Handles sidebar collapse/expand via burger button

function initSidebarToggle() {
    const burger = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const closeBtn = document.getElementById('close-sidebar');

    if (!burger || !sidebar) return;

    // Prevent double binding
    if (burger.hasAttribute('data-init')) return;
    burger.setAttribute('data-init', 'true');

    // Helper to sync burger icon state
    function syncBurgerState() {
        const isDesktop = window.innerWidth >= 769;
        const isOpen = isDesktop 
            ? !sidebar.classList.contains('is-closed') 
            : sidebar.classList.contains('is-open');
        
        if (isOpen) {
            burger.classList.add('active');
        } else {
            burger.classList.remove('active');
        }
    }

    // Initial sync
    syncBurgerState();

    // Toggle Sidebar via Burger
    burger.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (window.innerWidth >= 769) {
            sidebar.classList.toggle('is-closed');
        } else {
            sidebar.classList.toggle('is-open');
        }
        syncBurgerState();
    });

    // Close Sidebar via Close Button (Mobile mostly)
    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            sidebar.classList.remove('is-open');
            syncBurgerState();
        });
    }

    // Close when clicking overlay
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('is-open');
            syncBurgerState();
        });
    }

    // Close when clicking outside (fallback for mobile)
    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 768 && sidebar.classList.contains('is-open')) {
            if (!sidebar.contains(e.target) && !burger.contains(e.target)) {
                sidebar.classList.remove('is-open');
                syncBurgerState();
            }
        }
    });

    // Handle window resize to reset states if needed
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            syncBurgerState();
        }, 250);
    });
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebarToggle);
} else {
    initSidebarToggle();
}
