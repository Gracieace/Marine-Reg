<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

try {
    $pdo_header = db_connect();
    $school_name = get_system_setting($pdo_header, 'school_name') ?: 'Malolos Marine Fishery School and Laboratory';
    $school_logo = trim(get_system_setting($pdo_header, 'school_logo') ?: '/assets/images/school_logo.png');
} catch (Exception $e) {
    // Fallback if database is unreachable
    $school_name = 'Malolos Marine Fishery School and Laboratory';
    $school_logo = '/assets/images/school_logo.png';
}

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
      <span>
        <?php
        $role = $_SESSION['user']['role'] ?? '';
        if ($role === 'student') echo 'Student Portal';
        elseif ($role === 'admin') echo 'Admin Portal';
        elseif ($role === 'registrar') echo 'Registrar Portal'; // Added Registrar specifically
        elseif ($role === 'teacher') echo 'Teaching Personnel Portal';
        else echo 'Welcome';
        ?>
      </span>
    </div>
  </div>

  <!-- USER -->
  <!-- USER ACCOUNT -->
  <div class="account-control">
    <div class="user-avatar">
      <?= strtoupper(substr($_SESSION['user']['username'] ?? 'G', 0, 1)) ?>
    </div>
    <div class="account-text">
      <strong>
        <?php
        $role = $_SESSION['user']['role'] ?? 'Guest';
        if ($role === 'registrar') {
          echo 'Registrar';
        } elseif ($role === 'admin') {
          echo 'Admin';
        } elseif ($role === 'teacher') {
          echo 'Teacher';
        } else {
          echo htmlspecialchars(ucfirst($role));
        }
        ?>
      </strong>
    </div>
  </div>
</header>