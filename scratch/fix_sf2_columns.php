<?php
require 'config/db.php';
$pdo = db_connect();
try {
    $pdo->exec("ALTER TABLE sf2_monthly_summary ADD COLUMN perc_male_enrollment DECIMAL(10,2) DEFAULT 0");
    echo "Added perc_male_enrollment\n";
} catch (Exception $e) { echo "perc_male_enrollment exists\n"; }

try {
    $pdo->exec("ALTER TABLE sf2_monthly_summary ADD COLUMN perc_female_enrollment DECIMAL(10,2) DEFAULT 0");
    echo "Added perc_female_enrollment\n";
} catch (Exception $e) { echo "perc_female_enrollment exists\n"; }
?>
