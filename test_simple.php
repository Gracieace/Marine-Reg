<?php
header('Content-Type: text/plain');
echo "PHP is working! Current server details:\n";
echo "Server Name: " . ($_SERVER['SERVER_NAME'] ?? 'N/A') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
?>
