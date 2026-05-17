<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/db.php';
try {
    if (!isset($pdo)) {
        $pdo = db_connect();
    }
    $school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
    $school_logo = trim(get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png'));
} catch (Exception $e) {
    // Fallback if database is unreachable or connection fails
    $school_name = 'Malolos Marine Fishery School and Laboratory';
    $school_logo = '/assets/images/school_logo.png';
}

if (empty($school_logo)) {
    $school_logo = '/assets/images/school_logo.png';
}
?>

<!-- Root-relative CSS path so it works on live hosting (mmfslreg.ct.ws) -->
<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
<link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
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
    <?php
    // Ensure school logo path is absolute for url_for
    $display_logo = (strpos($school_logo, 'http') === 0) ? $school_logo : url_for('/' . ltrim($school_logo, '/'));
    ?>
    <div class="brand">
        <img src="<?= $display_logo ?>" 
             alt="School Logo" 
             class="brand-logo"
             onerror="this.onerror=null; this.src='<?= url_for('/assets/images/school_logo.png') ?>';">
        <div class="brand-text">
            <h1><?= htmlspecialchars($school_name) ?></h1>
            <span>Admin Portal</span>
        </div>
    </div>

    <!-- USER -->
    <!-- USER ACCOUNT -->
    <a href="<?= url_for('/admin/admin_profile.php') ?>" class="account-control" style="text-decoration: none; color: inherit;">
        <div class="user-avatar">
            <?php
            $photo = $_SESSION['user']['profile_photo'] ?? 'default.png';
            $photoPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $photo;
            if ($photo !== 'default.png' && file_exists($photoPath)): ?>
                <img src="<?= url_for('/uploads/' . $photo) ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
            <?php else: ?>
                <?= strtoupper(substr($_SESSION['user']['username'] ?? 'A', 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="account-text">
            <strong>
                <?php
                echo 'Admin';
                ?>
            </strong>
        </div>
    </a>
</header>