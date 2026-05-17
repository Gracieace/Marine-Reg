<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();

echo "--- Registrations Sex Diversity ---\n";
$stmt = $pdo->query("SELECT sex, COUNT(*) as count FROM registrations GROUP BY sex");
while($row = $stmt->fetch()) { echo "Reg Sex: [" . $row['sex'] . "] Count: " . $row['count'] . "\n"; }

echo "\n--- SF5 Students Sex Diversity ---\n";
$stmt = $pdo->query("SELECT sex, COUNT(*) as count FROM sf5_students GROUP BY sex");
while($row = $stmt->fetch()) { echo "SF5 Sex: [" . $row['sex'] . "] Count: " . $row['count'] . "\n"; }

echo "\n--- Sample Female Records ---\n";
$stmt = $pdo->query("SELECT id, sex FROM registrations WHERE sex LIKE 'F%' OR sex = '2' LIMIT 5");
while($row = $stmt->fetch()) { echo "Reg ID: {$row['id']} Sex: [{$row['sex']}]\n"; }
?>
