<?php
require_once 'config/db.php';
try {
    $pdo = db_connect();
    $stmt = $pdo->query("DESCRIBE registrations");
    while($row = $stmt->fetch()) {
        echo $row['Field'] . "\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
