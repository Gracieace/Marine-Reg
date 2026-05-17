<?php if (session_status() === PHP_SESSION_NONE) { session_start(); } ?>
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <!-- MOBILE HEADER (Close Button) -->
    <div class="sidebar-header">
        <button class="close-btn" id="close-sidebar">&times;</button>
    </div>

    <!-- BRAND -->
    <div class="brand">
        <img src="<?= url_for('/assets/images/school_logo.png') ?>" alt="Logo" class="brand-logo">
        <span class="school-name">Malolos Marine Fishery School and Laboratory</span>
    </div>



    <!-- NAVIGATION -->
    <nav class="sidebar-nav">
        <a href="<?= url_for('/student/dashboard.php') ?>">
            <span class="icon">🏠</span>
            <span class="text">Dashboard</span>
        </a>
        <a href="<?= url_for('/student/dashboard.php#enrollment') ?>">
            <span class="icon">📝</span>
            <span class="text">My Enrollment</span>
        </a>
        <a href="<?= url_for('/student/dashboard.php#grades') ?>">
            <span class="icon">📚</span>
            <span class="text">My Grades</span>
        </a>
        <a href="<?= url_for('/student/dashboard.php#profile') ?>">
            <span class="icon">🧑</span>
            <span class="text">Profile</span>
        </a>
        <a href="<?= url_for('/student/dashboard.php#settings') ?>">
            <span class="icon">⚙️</span>
            <span class="text">Settings</span>
        </a>

        <!-- LOGOUT -->
        <a href="<?= url_for('/logout.php') ?>" style="margin-top: 40px; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 10px; color: #ef4444;">
            <span class="icon">🚪</span>
            <span class="text">Logout</span>
        </a>
    </nav>
</aside>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const currentPath = window.location.pathname;
        const currentHash = window.location.hash;
        const links = document.querySelectorAll('.sidebar-nav a');

        links.forEach(link => {
            const linkUrl = new URL(link.href, window.location.origin);
            const isSamePath = linkUrl.pathname.includes(currentPath);
            const isSameHash = !linkUrl.hash || linkUrl.hash === currentHash;

            if (isSamePath && isSameHash && currentPath.length > 1) {
                link.style.background = 'var(--primary-light)';
                link.style.color = 'var(--primary)';
                link.style.fontWeight = '600';
            }
        });

        // Mobile Toggle logic
        const closeBtn = document.getElementById('close-sidebar');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                sidebar.classList.remove('is-open');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('is-open');
            });
        }
    });
</script>

<!-- Mobile Overlay -->
<div class="sidebar-overlay"></div>
