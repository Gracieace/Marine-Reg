<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=sampleweb', 'root', '');
$stmt = $pdo->query('DESCRIBE registrations');
foreach ($stmt as $row) {
    echo $row['Field'] . "\n";
}
