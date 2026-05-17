<?php
require_once 'config/db.php';
$conn = db_connect();
$tables = ['enrollments', 'textbook_distributions'];
foreach($tables as $t) {
    echo "\nTable: $t\n";
    $stmt = $conn->query("DESCRIBE $t");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$row['Field']} - {$row['Type']}\n";
    }
}
