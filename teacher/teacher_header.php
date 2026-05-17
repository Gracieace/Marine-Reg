<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/db.php';
if (!isset($pdo)) {
    $pdo = db_connect();
}
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_logo = trim(get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png'));
if (empty($school_logo)) {
    $school_logo = '/assets/images/school_logo.png';
}
?>

<!-- Root-relative CSS path so it works on live hosting (mmfslreg.ct.ws) -->
<link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
<!-- Sidebar toggle script (safe if sidebar not present) -->
<script src="<?= url_for('/js/sidebar-toggle.js') ?>" defer></script>

<header class="header">
    <!-- BURGER / SIDEBAR TOGGLE -->
    <button class="burger" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <!-- BRAND -->
    <div class="brand">
        <img src="<?= url_for($school_logo) ?>" alt="School Logo" class="brand-logo">

        <div class="brand-text">
            <h1><?= htmlspecialchars($school_name) ?></h1>
            <span style="display: flex; align-items: center; gap: 8px;">
                Teacher Portal 
                <span style="background: #eff6ff; color: #3b82f6; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; border: 1px solid #dbeafe;">
                    SY <?= htmlspecialchars(get_active_school_year($pdo)) ?>
                </span>
            </span>
        </div>
    </div>

    <!-- USER -->
    <!-- USER ACCOUNT -->
    <a href="<?= url_for('/teacher/teacher_profile.php') ?>" class="account-control" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 10px; padding: 5px 15px 5px 5px; background: #f8fafc; border-radius: 50px; border: 1px solid #e2e8f0; transition: all 0.2s;">
        <div class="user-avatar" style="width: 35px; height: 35px; background: #eff6ff; color: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0;">
            <?php if (!empty($_SESSION['user']['profile_photo']) && file_exists(dirname(__DIR__) . '/uploads/' . $_SESSION['user']['profile_photo'])): ?>
                <img src="<?= url_for('/uploads/' . $_SESSION['user']['profile_photo']) ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
            <?php else: ?>
                <?= strtoupper(substr($_SESSION['user']['username'] ?? 'T', 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="account-text" style="display: flex; flex-direction: column; line-height: 1;">
            <strong style="font-size: 14px; color: #1e293b; font-weight: 700; letter-spacing: 0.02em;">
                Teacher
            </strong>
        </div>
    </a>
</header>