<?php
if (!isset($pdo)) {
    require_once dirname(__DIR__) . '/config/db.php';
    $pdo = db_connect();
}
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_logo = get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png');
?>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <!-- MOBILE HEADER (Close Button) -->
    <div class="sidebar-header">
        <button class="close-btn" id="close-sidebar">&times;</button>
    </div>




    <!-- NAVIGATION -->
    <nav class="sidebar-nav">
        <div
            style="padding: 10px 16px 8px; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
            General</div>

        <a href="<?= url_for('/registrar/dashboard.php') ?>">
            <span class="icon">🏠</span>
            <span class="text">Dashboard</span>
        </a>

        <a href="<?= url_for('/registration_final.php') ?>">
            <span class="icon">📝</span>
            <span class="text">Enrollment</span>
        </a>

        <a href="<?= url_for('/registrar/enrollment.php') ?>">
            <span class="icon">🔍</span>
            <span class="text">QR Enrollment</span>
        </a>

        <a href="<?= url_for('/registrar/identification.php') ?>">
            <span class="icon">🪪</span>
            <span class="text">Student ID Generation</span>
        </a>

        <div
            style="margin-top: 16px; padding: 10px 16px 8px; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
            Submissions</div>

        <a href="<?= url_for('/registrar/school_reports.php') ?>">
            <span class="icon">📋</span>
            <span class="text">Reports & Submissions</span>
        </a>

        <div
            style="margin-top: 16px; padding: 10px 16px 8px; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
            Academics</div>

        <a href="<?= url_for('/registrar/curriculum.php') ?>">
            <span class="icon">📚</span>
            <span class="text">Curriculum</span>
        </a>

        <a href="<?= url_for('/registrar/school_year.php') ?>">
            <span class="icon">📅</span>
            <span class="text">School Year</span>
        </a>

        <a href="<?= url_for('/registrar/sections.php') ?>">
            <span class="icon">🏫</span>
            <span class="text">Section Management</span>
        </a>

        <a href="<?= url_for('/registrar/books.php') ?>">
            <span class="icon">📖</span>
            <span class="text">Books</span>
        </a>

        <a href="<?= url_for('/registrar/employees.php') ?>">
            <span class="icon">👤</span>
            <span class="text">Employees</span>
        </a>

        <div
            style="margin-top: 20px; padding: 10px 16px 8px; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">
            System</div>

        <a href="<?= url_for('/registrar/settings.php') ?>">
            <span class="icon">⚙️</span>
            <span class="text">Settings</span>
        </a>

        <a href="<?= url_for('/registrar/profile.php') ?>">
            <span class="icon">👤</span>
            <span class="text">Profile</span>
        </a>

        <a href="<?= url_for('/admin/logout.php') ?>">
            <span class="icon">🚪</span>
            <span class="text">Logout</span>
        </a>
    </nav>
</aside>

<script>
    // Auto-highlight active link
    document.addEventListener('DOMContentLoaded', () => {
        const currentPath = window.location.pathname;
        const links = document.querySelectorAll('.sidebar-nav a');

        links.forEach(link => {
            const linkPath = new URL(link.href, window.location.origin).pathname;
            const currentDir = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
            const linkDir = linkPath.substring(0, linkPath.lastIndexOf('/') + 1);

            // Exact match OR if current page is in the same directory as the link (for sub-pages)
            const isMatch = (currentPath === linkPath) ||
                (linkPath !== '/registrar/' && linkPath.endsWith('dashboard.php') && currentPath.startsWith(linkDir)) ||
                (linkPath !== '/registrar/' && !linkPath.endsWith('/') && currentPath.startsWith(linkPath));

            if (isMatch && !link.href.includes('logout.php')) {
                link.classList.add('active');
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