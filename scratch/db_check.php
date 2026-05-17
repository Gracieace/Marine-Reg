<?php
include 'config/db.php';
$pdo = db_connect();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t) {
    echo "Table: $t\n";
    $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) {
        echo "  - {$c['Field']} ({$c['Type']})\n";
    }
    echo "\n";
}
?>
