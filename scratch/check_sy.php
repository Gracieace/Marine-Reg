<?php
require 'config/db.php';
$pdo = db_connect();
echo "Enrollments SY:\n";
print_r($pdo->query('SELECT DISTINCT school_year FROM enrollments')->fetchAll(PDO::FETCH_COLUMN));
echo "\nRegistrations SY:\n";
print_r($pdo->query('SELECT DISTINCT school_year FROM registrations')->fetchAll(PDO::FETCH_COLUMN));
?>
