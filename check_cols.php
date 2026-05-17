<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SHOW COLUMNS FROM registrations");
echo "<pre>";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";
?>
