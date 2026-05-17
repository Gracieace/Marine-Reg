<?php
require_once '../auth/auth.php';
require_once '../config/db.php';

$pdo = db_connect();

// Find any teacher or admin
$stmt = $pdo->query("SELECT id, username, role FROM users WHERE role IN ('teacher', 'admin') LIMIT 1");
$user = $stmt->fetch();

if ($user) {
    auth_login($user['username'], $user['role'], $user['id']);
    echo "Logged in as " . $user['username'] . " (" . $user['role'] . ")";
    header("Location: reports/sf3_form.php");
    exit;
} else {
    echo "No teacher or admin found in database.";
}
?>
