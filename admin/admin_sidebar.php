<?php
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db_connect();
}
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_logo = trim(get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png'));
if (empty($school_logo)) {
    $school_logo = '/assets/images/school_logo.png';
}
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

        <a href="<?= url_for('/admin/admin_dashboard.php') ?>">
            <span class="icon">🏠</span>
            <span class="text">Dashboard</span>
        </a>

        <!-- ENROLLMENT LINK -->
        <a href="<?= url_for('/registration_final.php') ?>">
            <span class="icon">📋</span>
            <span class="text">Enrollment</span>
        </a>

        <!-- STUDENTS DROPDOWN -->
        <div class="nav-item-dropdown">
            <button class="dropdown-toggle" onclick="toggleDropdown(this)">
                <span class="icon-text">
                    <span class="icon">🎓</span>
                    <span class="text">Students</span>
                </span>
                <span class="chevron">▼</span>
            </button>
            <div class="dropdown-menu">
                <a href="<?= url_for('/admin/identification.php') ?>">
                    <span class="icon">🪪</span>
                    <span class="text">ID Generation</span>
                </a>
            </div>
        </div>

        <!-- TEACHERS DROPDOWN -->
        <div class="nav-item-dropdown">
            <button class="dropdown-toggle" onclick="toggleDropdown(this)">
                <span class="icon-text">
                    <span class="icon">👩‍🏫</span>
                    <span class="text">Personnel</span>
                </span>
                <span class="chevron">▼</span>
            </button>
            <div class="dropdown-menu">
                <a href="<?= url_for('/admin/teacher_approval.php') ?>">
                    <span class="icon">✅</span>
                    <span class="text">Approvals</span>
                </a>
                <a href="<?= url_for('/employees_final.php') ?>">
                    <span class="icon">👥</span>
                    <span class="text">Employees</span>
                </a>
            </div>
        </div>

        <!-- USERS TAB -->
        <a href="<?= url_for('/admin/user_management.php') ?>">
            <span class="icon">👤</span>
            <span class="text">Users</span>
        </a>

        <div
            style="margin-top: 16px; padding: 10px 16px 8px; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
            Reports</div>

        <!-- DIRECT REPORT LINKS (No Dropdowns) -->
        <a href="<?= url_for('/admin/reports/school_forms/dashboard.php') ?>">
            <span class="icon">🏫</span>
            <span class="text">School Forms</span>
        </a>

        <a href="<?= url_for('/admin/reports/eclass_record/view_adviser_uploads.php') ?>">
            <span class="icon">📋</span>
            <span class="text">Adviser Submissions</span>
        </a>



        <div
            style="margin-top: 16px; padding: 10px 16px 8px; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
            Academics</div>

        <!-- ACADEMICS DROPDOWN -->
        <div class="nav-item-dropdown">
            <button class="dropdown-toggle" onclick="toggleDropdown(this)">
                <span class="icon-text">
                    <span class="icon">📖</span>
                    <span class="text">Academics</span>
                </span>
                <span class="chevron">▼</span>
            </button>
            <div class="dropdown-menu">
                <a href="<?= url_for('/admin/curriculum.php?tab=programs') ?>">
                    <span class="icon">🎓</span>
                    <span class="text">Curriculum</span>
                </a>
                <a href="<?= url_for('/admin/school_year_management.php') ?>">
                    <span class="icon">📅</span>
                    <span class="text">School Year</span>
                </a>
                <a href="<?= url_for('/admin/school_calendar.php') ?>">
                    <span class="icon">🗓️</span>
                    <span class="text">School Calendar</span>
                </a>
                <a href="<?= url_for('/admin/section_management.php') ?>">
                    <span class="icon">🏗️</span>
                    <span class="text">Section Management</span>
                </a>
                <a href="<?= url_for('/admin/academics_books.php') ?>">
                    <span class="icon">📚</span>
                    <span class="text">Books</span>
                </a>
            </div>
        </div>

        <div
            style="margin-top: 20px; padding: 0 16px 8px; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">
            System</div>

        <a href="<?= url_for('/admin/settings_admin.php') ?>">
            <span class="icon">⚙️</span>
            <span class="text">Settings</span>
        </a>

        <a href="<?= url_for('/admin/logout.php') ?>">
            <span class="icon">🚪</span>
            <span class="text">Logout</span>
        </a>
    </nav>
</aside>

<script>
    function toggleDropdown(button) {
        // Close other dropdowns first (Optional: uncomment if you want accordian style)
        /*
        const allDropdowns = document.querySelectorAll('.dropdown-menu');
        const allToggles = document.querySelectorAll('.dropdown-toggle');
        allDropdowns.forEach(menu => {
            if (menu !== button.nextElementSibling) menu.classList.remove('open');
        });
        allToggles.forEach(toggle => {
            if (toggle !== button) toggle.classList.remove('active');
        });
        */

        button.classList.toggle('active');
        const menu = button.nextElementSibling;
        if (menu) {
            menu.classList.toggle('open');
        }
    }

    function toggleSubDropdown(e, button) {
        e.stopPropagation();
        button.classList.toggle('active');
        const menu = button.nextElementSibling;
        if (menu) {
            menu.classList.toggle('open');
        }
    }

    // Auto-highlight active link and expand parent dropdowns
    document.addEventListener('DOMContentLoaded', () => {
        const currentPath = window.location.pathname;
        const currentSearch = window.location.search;
        const links = document.querySelectorAll('.sidebar-nav a');

        links.forEach(link => {
            const linkUrl = new URL(link.href, window.location.origin);
            const linkPath = linkUrl.pathname;
            const linkSearch = linkUrl.search;

            const linkDir = linkPath.substring(0, linkPath.lastIndexOf('/') + 1);

            // Check for exact match (including path and optionally search)
            // Or check if currentPath starts with linkPath (for nested pages)
            let isMatch = (currentPath === linkPath);

            // Special case for dashboard
            if (linkPath.endsWith('dashboard.php') && currentPath.endsWith('dashboard.php')) {
                isMatch = true;
            }

            // Match for Academics links like curriculum.php?tab=programs
            if (!isMatch && linkSearch && currentPath === linkPath && currentSearch.includes(linkSearch)) {
                isMatch = true;
            }

            if (isMatch && !link.href.includes('logout.php')) {
                link.classList.add('active');

                // Expand parent dropdowns recursively
                let parent = link.closest('.dropdown-menu, .sub-dropdown-menu');
                while (parent) {
                    parent.classList.add('open');
                    const toggle = parent.previousElementSibling;
                    if (toggle && toggle.classList.contains('dropdown-toggle')) {
                        toggle.classList.add('active');
                    }
                    parent = parent.parentElement.closest('.dropdown-menu, .sub-dropdown-menu');
                }
            }
        });
    });
</script>

<!-- Mobile Overlay -->
<div class="sidebar-overlay"></div>