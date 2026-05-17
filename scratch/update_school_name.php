<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();
$stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'school_name'");
$stmt->execute(['Malolos Marine Fishery School and Laboratory']);
echo "Updated school name.";
?>
