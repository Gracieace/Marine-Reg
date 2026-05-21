<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Database connection test script:\n";

require_once 'config/app.php';
echo "Loaded config/app.php\n";
echo "DB Host: " . DB_HOST . "\n";
echo "DB Port: " . DB_PORT . "\n";
echo "DB Name: " . DB_NAME . "\n";
echo "DB User: " . DB_USER . "\n";

try {
    echo "Connecting to database...\n";
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "SUCCESS: Connected successfully to the database!\n";
} catch (PDOException $e) {
    echo "ERROR: Database connection failed:\n";
    echo $e->getMessage() . "\n";
}
?>
