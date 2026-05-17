<?php
require 'config/db.php';
$pdo = db_connect();

echo "Sections Table Schema:\n";
$stmt = $pdo->query("DESCRIBE sections");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

echo "\nPosition Assignments Sample:\n";
$stmt = $pdo->query("SELECT * FROM position_assignments LIMIT 5");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
