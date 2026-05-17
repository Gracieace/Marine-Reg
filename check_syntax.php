<?php
// Quick syntax check for sf8.php
$output = [];
$return_var = 0;
exec('php -l "' . __DIR__ . '/admin/reports/school_forms/sf8.php" 2>&1', $output, $return_var);
echo implode("\n", $output) . "\n";
echo "Exit code: " . $return_var . "\n";
