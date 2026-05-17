<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();

$sy = '2023-2024';
$grade = 'Grade 7';
$section = 'Diamond';

// 1. Create School Year if missing
$pdo->prepare("INSERT IGNORE INTO school_years (school_year, is_current) VALUES (?, 1)")->execute([$sy]);

// 2. Create sample registrations (SF1 source)
$samples = [
    ['101010101001', 'DELA CRUZ', 'JUAN', 'M', '2011-05-15'],
    ['101010101002', 'SANTOS', 'MARIA', 'F', '2011-08-22'],
    ['101010101003', 'BAUTISTA', 'PEDRO', 'M', '2010-12-05'],
    ['101010101004', 'REYES', 'ANA', 'F', '2011-02-14']
];

foreach ($samples as $s) {
    $pdo->prepare("INSERT IGNORE INTO registrations (lrn, last_name, first_name, sex, birthdate, grade_level_to_enroll, approval_status) 
                   VALUES (?, ?, ?, ?, ?, ?, 'approved')")
        ->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $grade]);
    
    // 3. Enroll them in Section Diamond
    $pdo->prepare("INSERT IGNORE INTO enrollments (student_id, student_name, grade_level, section, school_year) 
                   VALUES (?, ?, ?, ?, ?)")
        ->execute([$s[0], $s[1].', '.$s[2], $grade, $section, $sy]);

    // 4. Add mock health records
    $w = ($s[3] === 'M') ? 45.5 : 42.0;
    $h = 1.55;
    $bmi = round($w / ($h * $h), 1);
    $pdo->prepare("INSERT IGNORE INTO sf8_health_profile (student_id, school_year, weight_kg, height_m, bmi, nutritional_status, measurement_date) 
                   VALUES (?, ?, ?, ?, ?, 'Normal', CURRENT_DATE)")
        ->execute([$s[0], $sy, $w, $h, $bmi]);
}

// 5. Finalize the report
$pdo->prepare("INSERT IGNORE INTO sf8_reports (school_year, grade_level, section, status) VALUES (?, ?, ?, 'Finalized')")
    ->execute([$sy, $grade, $section]);

echo "Mock SF8 data generated for $grade - $section ($sy).";
?>
