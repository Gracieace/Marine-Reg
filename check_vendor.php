<?php
// Standalone vendor check - NO require_once of vendor/autoload.php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== PHP & Vendor Check ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";
echo "Script Path: " . __FILE__ . "\n";
echo "\n";

// Check vendor directory
$vendorPath = __DIR__ . '/vendor';
echo "Vendor path: $vendorPath\n";
echo "Vendor dir exists: " . (is_dir($vendorPath) ? 'YES' : 'NO') . "\n";

$autoloadFile = $vendorPath . '/autoload.php';
echo "vendor/autoload.php exists: " . (file_exists($autoloadFile) ? 'YES' : 'NO') . "\n";

$htmlPurifier = $vendorPath . '/ezyang/htmlpurifier/library/HTMLPurifier.composer.php';
echo "HTMLPurifier file exists: " . (file_exists($htmlPurifier) ? 'YES' : 'NO') . "\n";

$composerReal = $vendorPath . '/composer/autoload_real.php';
echo "composer/autoload_real.php exists: " . (file_exists($composerReal) ? 'YES' : 'NO') . "\n";

echo "\n=== Database Connection Test (Direct, No Vendor) ===\n";
$host = 'localhost';
$dbname = 'u957255050_db_marine_reg';
$user = 'u957255050_marine_reg';
$pass = 'M~rphsx7!+/5';

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "SUCCESS: Database connected!\n";
    echo "DB Host: $host\n";
    echo "DB Name: $dbname\n";
    echo "DB User: $user\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Config file check ===\n";
echo "config/hosting.php exists: " . (file_exists(__DIR__ . '/config/hosting.php') ? 'YES' : 'NO') . "\n";
echo "config/app.php exists: " . (file_exists(__DIR__ . '/config/app.php') ? 'YES' : 'NO') . "\n";
