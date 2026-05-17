<?php
/* config folder is OUTSIDE admin */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

/* Admin access only */
if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['user']['role']) ||
    $_SESSION['user']['role'] !== 'admin'
) {
    header('Location: ' . url_for('/index.php'));
    exit;
}

/* Update last activity for ONLINE status */
try {
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        "UPDATE users SET last_activity = NOW() WHERE id = ?"
    );
    $stmt->execute([$_SESSION['user']['id']]);
} catch (Exception $e) {
    // silent fail (never break page)
}
