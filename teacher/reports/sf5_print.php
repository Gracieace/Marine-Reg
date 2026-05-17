<?php
ob_start();
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user = auth_user();

$sy = $_GET['sy'] ?? ($_GET['school_year'] ?? '');
$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';

// Normalize
$norm = function($s) { return trim(str_ireplace(['Grade', 'Section'], '', (string)$s)); };
$q_grade = $norm($grade);
$q_section = $norm($section);

if (!$sy || !$grade || !$section) {
    die("School Year, Grade, and Section are required.");
}

// 1. Fetch Subjects (Must match sf5_form.php exactly)
$stmt = $pdo->prepare("SELECT id, subject_name, subject_code FROM curriculum WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) ORDER BY subject_name");
$stmt->execute([$grade, $q_grade, "%$q_grade%"]);
$subjects = $stmt->fetchAll();

// 2. Fetch Students - Mirroring sf5_form.php matching logic
$stmt = $pdo->prepare("SELECT e.student_id, e.student_name, e.lrn as e_lrn, r.lrn as r_lrn, 
                       r.first_name, r.middle_name, r.last_name, 
                       COALESCE(r.sex, s.sex, 'M') as profile_sex
                       FROM enrollments e 
                       LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.lrn = r.lrn AND e.lrn != '' AND e.lrn IS NOT NULL))
                       LEFT JOIN students s ON (e.student_id = s.student_id OR (e.lrn = s.student_id AND e.lrn != ''))
                       WHERE (e.grade_level = ? OR e.grade_level = ? OR e.grade_level LIKE ?) 
                       AND (e.section = ? OR e.section = ? OR e.section LIKE ?) 
                       AND (e.school_year = ? OR e.school_year LIKE ?)
                       ORDER BY COALESCE(r.sex, s.sex, 'M') DESC, COALESCE(r.last_name, s.last_name, e.student_name) ASC");
$stmt->execute([$grade, $q_grade, "%$q_grade%", $section, $q_section, "%$q_section%", $sy, "%$sy%"]);
$students = $stmt->fetchAll();

// Separate Male and Female
$males = [];
$females = [];
foreach ($students as $s) {
    $raw_sex = strtoupper(trim((string)($s['profile_sex'] ?? 'M')));
    $first_char = substr($raw_sex, 0, 1);
    
    // Categorize as Female if starts with F (Female) or G (Girl)
    if ($first_char === 'F' || $first_char === 'G') {
        $females[] = $s;
    } else {
        $males[] = $s;
    }
}

// 3. Fetch Grades - Synchronized with sf5_form.php
$all_grades = [];
if (!empty($students)) {
    $stmt = $pdo->prepare("SELECT * FROM sf9_grades WHERE (school_year = ? OR school_year LIKE ?) AND student_id IN (
        SELECT student_id FROM enrollments WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) AND (section = ? OR section = ? OR section LIKE ?) AND (school_year = ? OR school_year LIKE ?)
    )");
    $stmt->execute([$sy, "%$sy%", $grade, $q_grade, "%$q_grade%", $section, $q_section, "%$q_section%", $sy, "%$sy%"]);
    while ($row = $stmt->fetch()) { $all_grades[$row['student_id']][$row['subject_id']] = $row; }
}

// 4. Fetch SF9 Reports - Synchronized with sf5_form.php
$sf9_data = [];
if (!empty($students)) {
    $stmt = $pdo->prepare("SELECT * FROM sf9_reports WHERE (school_year = ? OR school_year LIKE ?) AND student_id IN (
        SELECT student_id FROM enrollments WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) AND (section = ? OR section = ? OR section LIKE ?) AND (school_year = ? OR school_year LIKE ?)
    )");
    $stmt->execute([$sy, "%$sy%", $grade, $q_grade, "%$q_grade%", $section, $q_section, "%$q_section%", $sy, "%$sy%"]);
    while ($row = $stmt->fetch()) { $sf9_data[$row['student_id']] = $row; }
}

// 5. Fetch School & Adviser Info
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch()) $settings[$row['setting_key']] = $row['setting_value'];

// Fetch Assigned Adviser Name (Fallback to 'N/A' as we will fetch real names from DB)
$adviser_name = 'CLASS ADVISER';
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

// Statistics
$stats = [
    'M' => ['total'=>0, 'promoted'=>0, 'conditional'=>0, 'retained'=>0],
    'F' => ['total'=>0, 'promoted'=>0, 'conditional'=>0, 'retained'=>0]
];
$prof_summary = [
    'Advanced (90-100)' => ['M'=>0, 'F'=>0],
    'Proficient (85-89)' => ['M'=>0, 'F'=>0],
    'Approaching Proficiency (80-84)' => ['M'=>0, 'F'=>0],
    'Developing (75-79)' => ['M'=>0, 'F'=>0],
    'Beginning (Below 75)' => ['M'=>0, 'F'=>0]
];

function getProfLevel($avg) {
    if ($avg >= 90) return 'Advanced (90-100)';
    if ($avg >= 85) return 'Proficient (85-89)';
    if ($avg >= 80) return 'Approaching Proficiency (80-84)';
    if ($avg >= 75) return 'Developing (75-79)';
    if ($avg > 0) return 'Beginning (Below 75)';
    return '';
}

function renderLearner($s, $num, $sex) {
    global $all_grades, $sf9_data, $subjects, $stats, $prof_summary;
    $sid = $s['student_id'];
    $sum = 0; $count = 0; $fails = 0;
    foreach($subjects as $sub) {
        $g = $all_grades[$sid][$sub['id']] ?? null;
        if($g && $g['final_grade']) { $sum += $g['final_grade']; $count++; if($g['final_grade'] < 75) $fails++; }
    }
    
    $sf9 = $sf9_data[$sid] ?? null;
    
    // Calculate Holistic General Average Dynamically (Mirroring SF9 Quarterly Grades Tab)
    $avg = ($count > 0) ? round($sum / $count) : null;

    $status_raw = strtoupper($sf9['promotion_status'] ?? ($avg == 0 ? '—' : ($fails >= 3 ? 'RETAINED' : ($fails > 0 ? 'CONDITIONAL' : 'PROMOTED'))));
    $status = trim($status_raw);
    $remarks = $sf9['adviser_remarks'] ?? '';
    
    if(stripos($status, 'PROMOTED') !== false) $stats[$sex]['promoted']++;
    elseif(stripos($status, 'CONDITIONAL') !== false) $stats[$sex]['conditional']++;
    elseif(stripos($status, 'RETAINED') !== false) $stats[$sex]['retained']++;
    $prof = getProfLevel($avg);
    if($prof) $prof_summary[$prof][$sex]++;
    
    $name = $s['last_name'] ? "{$s['last_name']}, {$s['first_name']} " . substr($s['middle_name']??'',0,1) . "." : $s['student_name'];
    ?>
    <tr>
        <td><?= $s['r_lrn'] ?: $s['e_lrn'] ?></td>
        <td style="text-align: left; padding-left: 4px; font-weight: bold;"><?= strtoupper($name) ?></td>
        <td style="font-weight: bold; background: #fafafa;"><?= $avg ?: '—' ?></td>
        <td style="font-weight: bold;"><?= $status ?></td>
        <td style="text-align: left; padding-left: 5px;"><?= htmlspecialchars($remarks) ?></td>
    </tr>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DepEd SF5 - <?= htmlspecialchars($section) ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; font-size: 11px; margin: 0; padding: 0; color: #000; background: #fff; line-height: 1.2; }
        @media print {
            @page { size: 13in 8.5in; margin: 0.2in; }
            .no-print { display: none; }
            body { background: none; padding: 0; font-size: 11px; }
            .paper { box-shadow: none; margin: 0; width: 100%; border: none; }
        }
        body { background: #525659; margin: 0; padding: 20px; display: flex; justify-content: center; }
        .paper { 
            background: white; 
            width: 12.6in; /* Accounting for some padding/margins */
            min-height: 8.1in;
            padding: 0.2in;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
            position: relative;
            box-sizing: border-box;
        }
        
        /* Header Styling */
        .header-top { text-align: center; margin-bottom: 5px; position: relative; }
        .deped-logo { width: 65px; height: auto; position: absolute; left: 120px; top: 0; }
        .school-logo { width: 65px; height: auto; position: absolute; right: 120px; top: 0; }
        .header-top h4 { margin: 0; font-weight: normal; font-size: 11px; }
        .header-top h3 { margin: 2px 0; font-size: 13px; font-weight: bold; }
        .header-top h2 { margin: 5px 0; font-size: 18px; font-weight: 900; text-transform: uppercase; border-top: 1px solid #000; border-bottom: 1px solid #000; display: inline-block; padding: 2px 20px; }
        
        /* Information Grid */
        .info-table { width: 100%; border: 1px solid #000; margin-bottom: 10px; border-collapse: collapse; }
        .info-table td { border: 1px solid #000; padding: 4px 10px; font-size: 10px; }
        .label { font-weight: bold; text-transform: uppercase; font-size: 9px; margin-right: 5px; }
        
        /* Main Report Table */
        .report-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; table-layout: fixed; border: 2px solid #000; font-size: 11px; }
        .report-table th, .report-table td { border: 1px solid #000; padding: 6px 4px; text-align: center; word-wrap: break-word; }
        .report-table th { background: #f8fafc; font-weight: bold; font-size: 11px; height: 40px; border-bottom: 2px solid #000; }
        .gender-row { background: #f1f5f9; font-weight: 800; text-align: center; padding: 10px 0 !important; font-size: 13px; letter-spacing: 2px; border-top: 2px solid #000; color: #1e293b; }
        .subtotal { background: #f1f5f9; font-weight: bold; font-style: italic; font-size: 11px; }
        
        /* Summary Boxes */
        .summary-container { display: flex; justify-content: space-between; gap: 30px; align-items: flex-start; }
        .summary-box { flex: 1; }
        .summary-box table { width: 100%; border-collapse: collapse; border: 1px solid #000; }
        .summary-box th { background: #e2e8f0; border: 1px solid #000; padding: 6px; font-size: 11px; text-transform: uppercase; }
        .summary-box td { border: 1px solid #000; padding: 6px 10px; font-size: 11px; }
        
        /* Footer Signatures */
        .footer { margin-top: 50px; display: flex; justify-content: space-between; padding: 0 40px; }
        .sig-block { text-align: center; width: 250px; }
        .sig-name { border-bottom: 1.5px solid #000; font-weight: bold; text-transform: uppercase; font-size: 12px; margin-bottom: 3px; padding-bottom: 2px; }
        .sig-title { font-size: 10px; color: #333; }

        .no-print { position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 15px; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); border: 1px solid #ddd; z-index: 1000; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-family: inherit; }
        .btn-print { background: #000; color: #fff; }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn btn-print" onclick="window.print()">🖨️ PRINT OFFICIAL SF5</button>
        <button class="btn" onclick="window.close()" style="margin-left:10px;">CLOSE</button>
    </div>

    <div class="paper">
        <!-- OFFICIAL HEADER -->
        <div class="header-top">
            <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="deped-logo">
            <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="school-logo">
            <h4>Republic of the Philippines</h4>
            <h3>Department of Education</h3>
            <h2>School Form 5 (SF5) Report on Promotion and Level of Proficiency</h2>
            <p style="margin: 3px 0; font-size: 10px; font-weight: bold;">End of School Year <?= $sy ?></p>
        </div>

        <!-- SCHOOL INFO GRID -->
        <table class="info-table">
            <tr>
                <td width="40%"><span class="label">School Name:</span> <?= strtoupper($settings['school_name'] ?? 'N/A') ?></td>
                <td width="20%"><span class="label">School ID:</span> <?= $settings['school_id'] ?? 'N/A' ?></td>
                <td width="20%"><span class="label">District:</span> <?= strtoupper($settings['district'] ?? 'N/A') ?></td>
                <td width="20%"><span class="label">Division:</span> <?= strtoupper($settings['division'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td><span class="label">Region:</span> <?= strtoupper($settings['region'] ?? 'N/A') ?></td>
                <td><span class="label">Grade Level:</span> <?= strtoupper($grade) ?></td>
                <td><span class="label">Section:</span> <?= strtoupper($section) ?></td>
                <td><span class="label">Curriculum:</span> K TO 12 BASIC EDUCATION</td>
            </tr>
        </table>

        <!-- MALE TABLE -->
        <div style="margin-bottom: 5px; font-weight: bold; background: #f1f5f9; padding: 5px; border: 2px solid #000; border-bottom: none; text-align: center; letter-spacing: 2px;">MALE</div>
        <table class="report-table" style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="width: 12%;">LRN</th>
                    <th style="width: 32%;">LEARNER'S NAME<br><small>(Last Name, First Name, Middle Initial)</small></th>
                    <th style="width: 10%;">GENERAL AVERAGE</th>
                    <th style="width: 18%;">ACTION TAKEN</th>
                    <th style="width: 28%;">REMARKS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($males as $idx => $s): $stats['M']['total']++; renderLearner($s, $idx+1, 'M'); endforeach; ?>
                <tr class="subtotal" style="height: 35px; background: #fafafa;">
                    <td colspan="2" style="text-align: right; padding-right: 20px; font-weight: 800;">SUB-TOTAL MALE</td>
                    <td></td>
                    <td style="font-size: 12px; font-weight: 900; background: #f1f5f9;"><?= $stats['M']['total'] ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <!-- FEMALE TABLE -->
        <div style="margin-bottom: 5px; font-weight: bold; background: #f1f5f9; padding: 5px; border: 2px solid #000; border-bottom: none; text-align: center; letter-spacing: 2px;">FEMALE</div>
        <table class="report-table" style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="width: 12%;">LRN</th>
                    <th style="width: 32%;">LEARNER'S NAME<br><small>(Last Name, First Name, Middle Initial)</small></th>
                    <th style="width: 10%;">GENERAL AVERAGE</th>
                    <th style="width: 18%;">ACTION TAKEN</th>
                    <th style="width: 28%;">REMARKS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($females as $idx => $s): $stats['F']['total']++; renderLearner($s, $idx+1, 'F'); endforeach; ?>
                <tr class="subtotal" style="height: 35px; background: #fafafa;">
                    <td colspan="2" style="text-align: right; padding-right: 20px; font-weight: 800;">SUB-TOTAL FEMALE</td>
                    <td></td>
                    <td style="font-size: 12px; font-weight: 900; background: #f1f5f9;"><?= $stats['F']['total'] ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <!-- COMBINED TOTAL SUMMARY -->
        <table class="report-table" style="margin-top: -10px; margin-bottom: 25px; border-top: none;">
            <tbody>
                <tr style="background: #000; color: #fff; font-weight: bold; font-size: 12px; height: 40px;">
                    <td colspan="2" style="width: 44%; text-align: right; padding-right: 20px; border-top: 2px solid #000;">COMBINED TOTAL (Male + Female)</td>
                    <td style="width: 10%; border-top: 2px solid #000;"></td>
                    <td style="width: 18%; border-top: 2px solid #000; font-size: 15px;"><?= $stats['M']['total'] + $stats['F']['total'] ?></td>
                    <td style="width: 28%; border-top: 2px solid #000;"></td>
                </tr>
            </tbody>
        </table>

        <!-- SUMMARY TABLES -->
        <div class="summary-container">
            <div class="summary-box">
                <table>
                    <thead>
                        <tr><th colspan="4">SUMMARY TABLE (Promotion)</th></tr>
                        <tr><th>STATUS</th><th>MALE</th><th>FEMALE</th><th>TOTAL</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>PROMOTED</td><td><?= $stats['M']['promoted'] ?></td><td><?= $stats['F']['promoted'] ?></td><td><?= $stats['M']['promoted'] + $stats['F']['promoted'] ?></td></tr>
                        <tr><td>CONDITIONAL</td><td><?= $stats['M']['conditional'] ?></td><td><?= $stats['F']['conditional'] ?></td><td><?= $stats['M']['conditional'] + $stats['F']['conditional'] ?></td></tr>
                        <tr><td>RETAINED</td><td><?= $stats['M']['retained'] ?></td><td><?= $stats['F']['retained'] ?></td><td><?= $stats['M']['retained'] + $stats['F']['retained'] ?></td></tr>
                        <tr style="font-weight:bold; background:#e2e8f0;"><td>TOTAL</td><td><?= $stats['M']['total'] ?></td><td><?= $stats['F']['total'] ?></td><td><?= $stats['M']['total'] + $stats['F']['total'] ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="summary-box">
                <table>
                    <thead>
                        <tr><th colspan="4">LEVEL OF PROFICIENCY</th></tr>
                        <tr><th>LEVEL</th><th>MALE</th><th>FEMALE</th><th>TOTAL</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($prof_summary as $level => $v): ?>
                        <tr><td><?= $level ?></td><td><?= $v['M'] ?></td><td><?= $v['F'] ?></td><td><?= $v['M']+$v['F'] ?></td></tr>
                        <?php endforeach; ?>
                        <tr style="font-weight:bold; background:#e2e8f0;"><td>TOTAL</td>
                            <td><?= array_sum(array_column($prof_summary, 'M')) ?></td>
                            <td><?= array_sum(array_column($prof_summary, 'F')) ?></td>
                            <td><?= array_sum(array_column($prof_summary, 'M')) + array_sum(array_column($prof_summary, 'F')) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SIGNATURES -->
        <div class="footer">
            <div class="sig-block">
                <div class="sig-name"><?= $adviser_name ?></div>
                <div class="sig-title">Class Adviser</div>
                <p style="font-size: 8px; margin-top: 4px;">(Signature over Printed Name)</p>
            </div>
            <div class="sig-block">
                <div class="sig-name">
                    <?php 
                        $principal_name = get_system_setting($pdo, 'principal_name', 'School Head'); 
                        echo strtoupper($principal_name);
                    ?>
                </div>
                <div class="sig-title">School Head / Principal</div>
                <p style="font-size: 8px; margin-top: 4px;">(Signature over Printed Name)</p>
            </div>
        </div>

        <p style="text-align: center; font-size: 8px; color: #777; margin-top: 30px;">
            Generated by <?= strtoupper($user['username']) ?> | <?= date('F d, Y h:i A') ?> | System Version 2.1-DepEd-SF5
        </p>
    </div>
</body>
</html>
