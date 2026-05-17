<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Checking environment...<br>";

$files = [
    'config/db.php',
    'config/app.php',
    'auth/auth.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        echo "OK: $f exists<br>";
        require_once $f;
    } else {
        echo "ERROR: $f MISSING<br>";
    }
}

echo "Attempting DB connect...<br>";
try {
    $pdo = db_connect();
    echo "OK: DB Connected<br>";
} catch (Exception $e) {
    echo "ERROR: DB Failed: " . $e->getMessage() . "<br>";
}

echo "Environment check complete.";
