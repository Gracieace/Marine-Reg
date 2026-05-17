<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = db_connect();
$student_id = $_GET['student_id'] ?? '';

if (!$student_id) {
    die("Student ID is required.");
}

// 1. Fetch Student Info
$stmt = $pdo->prepare("SELECT e.*, r.sex, r.birthdate, r.age as reg_age, r.lrn as reg_lrn, r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province 
                       FROM enrollments e 
                       LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.lrn = r.lrn AND e.lrn IS NOT NULL AND e.lrn != ''))
                       WHERE e.student_id = ? 
                       ORDER BY e.school_year DESC LIMIT 1");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) die("Student not found.");

$sy = $student['school_year'];
$grade = $student['grade_level'];
$section = $student['section'];

// 2. Fetch Grades (SF9 Specific)
$stmt = $pdo->prepare("SELECT g.*, s.subject_name 
                       FROM sf9_grades g 
                       JOIN curriculum s ON g.subject_id = s.id 
                       WHERE g.student_id = ? AND g.school_year = ? 
                       ORDER BY s.subject_name");
$stmt->execute([$student_id, $sy]);
$grades = $stmt->fetchAll();

// 3. Fetch Observed Values
$stmt = $pdo->prepare("SELECT * FROM observed_values WHERE student_id = ? AND school_year = ?");
$stmt->execute([$student_id, $sy]);
$obs_rows = $stmt->fetchAll();
$observed = [];
foreach ($obs_rows as $row) $observed[$row['quarter']][$row['behavior_statement_id']] = $row['rating'];

// 4. Fetch Promotion/Remarks (from SF9 Reports)
$stmt = $pdo->prepare("SELECT * FROM sf9_reports WHERE student_id = ? AND school_year = ?");
$stmt->execute([$student_id, $sy]);
$sf9_report = $stmt->fetch() ?: ['adviser_remarks' => '', 'promotion_status' => 'Promoted'];

// 5. Fetch Attendance (from SF2)
$stmt = $pdo->prepare("SELECT r.report_month, s.total_present, s.total_absent, m.days_of_classes 
                       FROM sf2_student_records s
                       JOIN sf2_reports r ON s.sf2_report_id = r.id 
                       LEFT JOIN sf2_monthly_summary m ON s.sf2_report_id = m.sf2_report_id
                       WHERE (s.student_id = ? OR s.student_id = ? OR s.student_name = ?) 
                       AND r.school_year = ?");
$stmt->execute([$student_id, $student['reg_lrn'] ?? '', $student['student_name'], $sy]);
$att_rows = $stmt->fetchAll();

$months_list = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];
$attendance = [];
foreach ($months_list as $m) $attendance[$m] = ['p' => 0, 'a' => 0, 'd' => 0];

foreach ($att_rows as $row) {
    if (!$row['report_month']) continue;
    $m_key = "";
    foreach($months_list as $ml) {
        if(stripos($row['report_month'], $ml) !== false) {
            $m_key = $ml;
            break;
        }
    }
    if ($m_key && isset($attendance[$m_key])) {
        $attendance[$m_key] = [
            'p' => (int)($row['total_present'] ?? 0), 
            'a' => (int)($row['total_absent'] ?? 0), 
            'd' => (int)($row['days_of_classes'] ?? 0)
        ];
    }
}

// 6. System & Adviser Settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch()) $settings[$row['setting_key']] = $row['setting_value'];

// Auto-detect Adviser
$adviser_name = strtoupper($settings['class_adviser_name'] ?? 'CLASS ADVISER');
$stmt = $pdo->prepare("SELECT u.first_name, u.last_name, u.middle_name 
                       FROM users u 
                       JOIN position_assignments pa ON u.id = pa.user_id 
                       WHERE pa.grade_level = ? AND pa.section = ? AND pa.school_year = ? AND pa.position_type = 'class_adviser'
                       LIMIT 1");
$stmt->execute([$grade, $section, $sy]);
$adv_user = $stmt->fetch();
if (!$adv_user) {
    // Try sections table
    $stmt = $pdo->prepare("SELECT u.first_name, u.last_name, u.middle_name 
                           FROM users u 
                           JOIN sections s ON u.id = s.adviser_id 
                           WHERE s.grade_level = ? AND s.section_name = ? AND s.school_year = ?
                           LIMIT 1");
    $stmt->execute([$grade, $section, $sy]);
    $adv_user = $stmt->fetch();
}
if ($adv_user) {
    $adviser_name = strtoupper($adv_user['first_name'] . ' ' . ($adv_user['middle_name'] ? substr($adv_user['middle_name'],0,1).'. ' : '') . $adv_user['last_name']);
}
$principal_name = strtoupper(get_system_setting($pdo, 'principal_name', 'School Head'));

// Metadata
$core_values = [
    'Maka-Diyos' => ['v1' => 'Expresses spiritual beliefs while respecting the spiritual beliefs of others.', 'v2' => 'Shows adherence to ethical principles by upholding truth.'],
    'Makatao' => ['v3' => 'Is sensitive to individual, social, and cultural differences.', 'v4' => 'Demonstrates contributions toward solidarity.'],
    'Makakalikasan' => ['v5' => 'Cares for the environment and utilizes resources wisely, judiciously, and economically.'],
    'Makabansa' => ['v6' => 'Demonstrates pride in being a Filipino; exercises the rights and responsibilities of a Filipino citizen.', 'v7' => 'Demonstrates appropriate behavior in carrying out activities in the school, community, and country.']
];

// Handle Export
if (($_GET['export'] ?? '') === 'pdf') {
    require_once __DIR__ . '/../../includes/report_export_helper.php';
    ob_start();
    // (Simplified PDF CSS for DOMPDF)
    ?>
    <style>
        body { font-family: Arial, sans-serif; font-size: 9px; line-height: 1.3; }
        .header { text-align: center; margin-bottom: 20px; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .table th, .table td { border: 1px solid #000; padding: 4px; text-align: center; }
        .text-left { text-align: left; }
        .section-title { background: #eee; font-weight: bold; padding: 5px; text-transform: uppercase; margin: 10px 0; border: 1px solid #000; }
    </style>
    <!-- PDF Content Placeholder -->
    <div class="header">
        <h2>Learner's Progress Report Card (SF9)</h2>
        <p>SY: <?= $sy ?> | Grade: <?= $grade ?> - <?= $section ?></p>
    </div>
    <div class="section-title">Academic Progress</div>
    <table class="table">
        <thead><tr><th>Learning Areas</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Final</th></tr></thead>
        <tbody>
            <?php foreach($grades as $g): ?>
                <tr><td class="text-left"><?= $g['subject_name'] ?></td><td><?= round($g['q1']) ?></td><td><?= round($g['q2']) ?></td><td><?= round($g['q3']) ?></td><td><?= round($g['q4']) ?></td><td><?= round($g['final_grade']) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $html = ob_get_clean();
    exportToPDF($html, "SF9_".$student['student_id'], 'portrait');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print SF9 - <?= htmlspecialchars($student['student_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page { size: portrait; margin: 0; }
        body { font-family: 'Times New Roman', Times, serif; margin: 0; padding: 0; background: #f1f5f9; font-size: 10px; color: #1a1a1a; line-height: 1.1; }
        
        .action-bar { position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; gap: 12px; padding: 10px; background: rgba(255,255,255,0.8); backdrop-filter: blur(10px); border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2); }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: 700; text-decoration: none; color: white; display: flex; align-items: center; gap: 8px; font-family: 'Inter', system-ui, sans-serif; font-size: 12px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .btn:active { transform: translateY(0); }
        .btn-print { background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); }
        .btn-back { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; }
        .btn-back:hover { background: #f8fafc; border-color: #cbd5e1; }

        .page-container { width: 210mm; background: white; margin: 10px auto; padding: 8mm; min-height: 297mm; position: relative; box-sizing: border-box; border: 1px solid #e2e8f0; }
        
        .official-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px double #000; padding-bottom: 6px; margin-bottom: 8px; }
        .logo { height: 55px; width: auto; }
        .header-text { text-align: center; flex: 1; }
        .header-text h1 { margin: 0; font-size: 14px; color: #000; font-family: Arial, Helvetica, sans-serif; font-weight: 900; }
        .header-text h2 { margin: 1px 0; font-size: 11px; color: #000; font-weight: 400; font-style: italic; }
        .header-text p { margin: 0; font-size: 9px; }
        
        .report-title { text-align: center; font-size: 13px; font-weight: 900; text-transform: uppercase; margin: 8px 0; border: 1px solid #000; padding: 3px; background: #f8fafc; }
        
        .section-header { background: #f1f5f9; color: #000; padding: 3px 8px; font-weight: 900; text-transform: uppercase; margin: 8px 0 5px; border: 1px solid #000; font-size: 9px; text-align: center; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 5px; border: 1px solid #000; padding: 8px; margin-bottom: 8px; font-size: 9px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #000; padding: 2px 1px; text-align: center; font-size: 9px; height: 14px; }
        th { background: #f8fafc; font-weight: 900; text-transform: uppercase; }
        .text-left { text-align: left; padding-left: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: bold; }
        
        .columns { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .sig-box { margin-top: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; text-align: center; }
        .sig-name-line { border-bottom: 1.2px solid #000; padding-bottom: 2px; font-weight: 900; font-size: 11px; text-transform: uppercase; display: inline-block; min-width: 180px; }
        .sig-label { font-size: 8px; font-style: italic; color: #4b5563; }

        @media print {
            body { background: white; padding: 0; }
            .page-container { margin: 0; box-shadow: none; width: 100%; height: 100%; padding: 10mm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="action-bar no-print">
        <a href="sf9_form.php?student_id=<?=$student_id?>" class="btn btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-print">
            <i class="fa-solid fa-print"></i> Print Report Card
        </button>
    </div>

    <div class="page-container">
        <div class="official-header">
            <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="logo">
            <div class="header-text">
                <p style="margin:0;">Republic of the Philippines</p>
                <h1>Department of Education</h1>
                <p style="margin:0;">Region III - Central Luzon</p>
                <h2><?= strtoupper(htmlspecialchars($settings['school_name'] ?? '')) ?></h2>
            </div>
            <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="logo">
        </div>

        <div style="text-align: center; margin-bottom: 20px;">
            <h2 style="margin: 0; text-transform: uppercase; font-size: 16px;">Learner's Progress Report Card (SF9)</h2>
            <p style="font-weight: 700;">School Year: <?= htmlspecialchars($sy) ?></p>
        </div>

        <div class="info-grid">
            <div><strong>NAME:</strong> <?= strtoupper(htmlspecialchars($student['student_name'])) ?></div>
            <div><strong>LRN:</strong> <?= htmlspecialchars($student['lrn'] ?: ($student['reg_lrn'] ?? '')) ?></div>
            <div><strong>SEX:</strong> <?= htmlspecialchars($student['sex'] ?? 'M') ?></div>
            <?php 
                $sy_parts = explode('-', $sy);
                $start_year = (int)($sy_parts[0] ?? date('Y'));
                $oct31 = date_create($start_year . '-10-31');
                $bday = date_create(!empty($student['birthdate']) ? $student['birthdate'] : date('Y-m-d'));
                
                $final_age = 0;
                if ($oct31 && $bday) {
                    $diff = date_diff($bday, $oct31);
                    $final_age = $diff->y;
                }
                
                // Use profile age if exists and valid
                $display_age = (!empty($student['reg_age']) && $student['reg_age'] > 0) ? $student['reg_age'] : $final_age;
            ?>
            <div><strong>AGE:</strong> <?= htmlspecialchars($display_age) ?></div>
            <div><strong>GRADE & SECTION:</strong> <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?></div>
            <div><strong>CURRICULUM:</strong> K-12 BASIC EDUCATION</div>
        </div>

        <div class="columns">
            <div>
                <div class="section-header">Report on Learning Progress and Achievement</div>
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2" class="text-left" style="width: 45%;">Learning Areas</th>
                            <th colspan="4">Quarter</th>
                            <th rowspan="2" style="width: 45px;">Final Grade</th>
                            <th rowspan="2">Remarks</th>
                        </tr>
                        <tr><th>1</th><th>2</th><th>3</th><th>4</th></tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_final = 0; $count_final = 0;
                        
                        $mapeh_main = null;
                        $mapeh_subs = [];
                        $other_subs = [];

                        foreach($grades as $g) {
                            $name = strtoupper($g['subject_name']);
                            if ($name === 'MAPEH') $mapeh_main = $g;
                            elseif (in_array($name, ['MUSIC', 'ARTS', 'PHYSICAL EDUCATION', 'HEALTH', 'PE', 'P.E.'])) $mapeh_subs[] = $g;
                            else $other_subs[] = $g;
                        }

                        $renderPrintRow = function($g, $indent = false) use (&$total_final, &$count_final) {
                            if (!$g) return;
                            $f = $g['final_grade']; 
                            if($f){ $total_final += $f; $count_final++; }
                            ?>
                            <tr>
                                <td class="text-left" style="<?= $indent ? 'padding-left:15px; font-weight:normal; font-size:8px;' : '' ?>">
                                    <?= htmlspecialchars($g['subject_name']) ?>
                                </td>
                                <td><?= $g['q1'] ? number_format($g['q1'],0) : '' ?></td>
                                <td><?= $g['q2'] ? number_format($g['q2'],0) : '' ?></td>
                                <td><?= $g['q3'] ? number_format($g['q3'],0) : '' ?></td>
                                <td><?= $g['q4'] ? number_format($g['q4'],0) : '' ?></td>
                                <td style="font-weight: 800; background: #f8fafc;"><?= $f ? number_format($f,0) : '' ?></td>
                                <td style="font-size: 8px; font-weight: 700;">
                                    <?= htmlspecialchars($g['remarks']) ?>
                                </td>
                            </tr>
                            <?php
                        };

                        // 1. Core Subjects
                        foreach($other_subs as $g) $renderPrintRow($g);

                        // 2. MAPEH Aggregate & Components
                        if($mapeh_main) {
                            $renderPrintRow($mapeh_main);
                            foreach($mapeh_subs as $g) $renderPrintRow($g, true);
                        }
                        ?>
                        <tr style="font-weight: 800; background: #eee;">
                            <td class="text-left">GENERAL AVERAGE</td>
                            <td colspan="4"></td>
                            <td><?= ($count_final > 0) ? number_format($total_final / $count_final, 0) : '' ?></td>
                            <td><?= ($count_final > 0 && ($total_final / $count_final) >= 75) ? 'PASSED' : (($count_final > 0) ? 'FAILED' : '') ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="section-header">Attendance Report</div>
                <table style="font-size: 9px;">
                    <tr style="font-weight: bold;">
                        <th style="text-align:left; width: 70px;">Month</th>
                        <?php foreach($months_list as $m) echo "<td>$m</td>"; ?>
                        <th>Total</th>
                    </tr>
                    <tr>
                        <td class="text-left">School Days</td>
                        <?php 
                        $td=0; 
                        foreach($months_list as $m) { 
                            $val = $attendance[$m]['d'];
                            echo "<td>".($val > 0 ? $val : "")."</td>"; 
                            $td += $val; 
                        } 
                        echo "<td style='font-weight:800; background:#f8fafc;'>$td</td>"; 
                        ?>
                    </tr>
                    <tr>
                        <td class="text-left">Present</td>
                        <?php 
                        $tp=0; 
                        foreach($months_list as $m) { 
                            $val = $attendance[$m]['p'];
                            echo "<td>".($val > 0 ? $val : "")."</td>"; 
                            $tp += $val; 
                        } 
                        echo "<td style='font-weight:800; background:#f8fafc;'>$tp</td>"; 
                        ?>
                    </tr>
                    <tr>
                        <td class="text-left">Absent</td>
                        <?php 
                        $ta=0; 
                        foreach($months_list as $m) { 
                            $val = $attendance[$m]['a'];
                            echo "<td>".($val > 0 ? $val : "")."</td>"; 
                            $ta += $val; 
                        } 
                        echo "<td style='font-weight:800; background:#f8fafc;'>$ta</td>"; 
                        ?>
                    </tr>
                </table>
            </div>

            <div>
                <div class="section-header">Report on Learner's Observed Values</div>
                <table style="font-size: 9px;">
                    <thead>
                        <tr><th style="width: 80px;">Core Values</th><th>Behavior Statements</th><th style="width: 15px;">1</th><th style="width: 15px;">2</th><th style="width: 15px;">3</th><th style="width: 15px;">4</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($core_values as $group => $stmts): $first = true; $cnt = count($stmts); foreach($stmts as $id => $text): ?>
                        <tr>
                            <?php if($first){ echo "<td rowspan='$cnt' style='font-weight:bold; font-size:8px;'>$group</td>"; $first=false; } ?>
                            <td class="text-left" style="font-size: 8px; white-space: normal; line-height: 1;"><?= $text ?></td>
                            <td><?= $observed['Q1'][$id] ?? '' ?></td><td><?= $observed['Q2'][$id] ?? '' ?></td><td><?= $observed['Q3'][$id] ?? '' ?></td><td><?= $observed['Q4'][$id] ?? '' ?></td>
                        </tr>
                        <?php endforeach; endforeach; ?>
                    </tbody>
                </table>

                <div class="section-header" style="background: #334155;">Adviser's Remarks & Promotion Status</div>
                <div style="border: 1px solid #000; padding: 10px; min-height: 80px; margin-bottom: 10px;">
                    <strong>Remarks:</strong><br>
                    <?= nl2br(htmlspecialchars($sf9_report['adviser_remarks'])) ?>
                </div>
                <div style="border: 1px solid #000; padding: 10px; text-align: center;">
                    <strong>PROMOTION STATUS:</strong> 
                    <span style="font-size: 14px; font-weight: 900; margin-left: 10px; text-decoration: underline;">
                        <?= strtoupper($sf9_report['promotion_status']) ?>
                    </span>
                </div>

                <div class="sig-box">
                    <div class="sig-item">
                        <div class="sig-name-line"><?= $adviser_name ?></div>
                        <p style="margin: 0; font-size: 9px;">Class Adviser</p>
                    </div>
                    <div class="sig-item">
                        <div class="sig-name-line"><?= $principal_name ?></div>
                        <p style="margin: 0; font-size: 9px;">School Head / Principal</p>
                    </div>
                </div>
            </div>
        </div>

        <div style="position: absolute; bottom: 10mm; left: 15mm; right: 15mm; border-top: 1px solid #eee; padding-top: 5px; font-size: 8px; color: #64748b; text-align: center;">
            SF9 - Progress Report Card | School ID: <?= htmlspecialchars($settings['school_id'] ?? '300750') ?> | Generated by Marine Registrar System on <?= date('Y-m-d H:i') ?>
        </div>
    </div>
</body>
</html>