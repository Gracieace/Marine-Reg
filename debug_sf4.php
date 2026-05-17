<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once 'admin/reports/school_forms/sf4.php';
} catch (Throwable $e) {
    echo "<h1>Fatal Error</h1>";
    echo "<p>Message: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
    echo "<h3>Stack Trace</h3>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
