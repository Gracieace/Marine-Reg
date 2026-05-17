<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();

echo "--- Registrations Sex Samples ---\n";
$stmt = $pdo->query("SELECT DISTINCT sex FROM registrations LIMIT 10");
while($row = $stmt->fetch()) { echo "Reg Sex: [" . $row['sex'] . "]\n"; }

echo "\n--- SF5 Students Sex Samples ---\n";
$stmt = $pdo->query("SELECT DISTINCT sex FROM sf5_students LIMIT 10");
while($row = $stmt->fetch()) { echo "SF5 Sex: [" . $row['sex'] . "]\n"; }
?>
