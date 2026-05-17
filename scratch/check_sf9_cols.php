<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("DESCRIBE sf9_reports");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
