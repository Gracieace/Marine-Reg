<?php
require_once 'config/db.php';
$pdo = db_connect();
try {
    $res = $pdo->query("DESCRIBE users")->fetchAll();
    echo "USERS TABLE COLUMNS:\n";
    foreach($res as $r) echo $r['Field'] . " (" . $r['Type'] . ")\n";
    
    $res = $pdo->query("DESCRIBE sections")->fetchAll();
    echo "\nSECTIONS TABLE COLUMNS:\n";
    foreach($res as $r) echo $r['Field'] . " (" . $r['Type'] . ")\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
