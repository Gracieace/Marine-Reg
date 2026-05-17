<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = trim($_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');
$sy = trim($_GET['sy'] ?? '');

// Bidirectional normalization
$grade_clean = trim(str_ireplace('Grade', '', $grade));
$grade_with_prefix = "Grade " . $grade_clean;
$section_clean = trim(str_ireplace('Section', '', $section));
$section_with_prefix = "Section " . $section_clean;
$sy_clean = str_replace(' ', '', $sy);
$sy_with_spaces = str_replace('-', ' - ', $sy_clean);

// 1. Fetch Subjects
$stmt = $pdo->prepare("SELECT id, subject_name, subject_code FROM curriculum WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) ORDER BY subject_name");
$stmt->execute([$grade, $grade_clean, "%$grade_clean%"]);
$subjects = $stmt->fetchAll();

// 2. Fetch Students - DYNAMIC SOURCE OF TRUTH (Matches sf5_print.php exactly)
$stmt = $pdo->prepare("
    SELECT e.student_id, e.student_name, e.lrn as e_lrn, r.lrn as r_lrn, 
           r.first_name, r.middle_name, r.last_name, 
           COALESCE(r.sex, s.sex, 'M') as profile_sex
    FROM enrollments e 
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.lrn = r.lrn AND e.lrn != '' AND e.lrn IS NOT NULL))
    LEFT JOIN students s ON (e.student_id = s.student_id OR (e.lrn = s.student_id AND e.lrn != ''))
    WHERE (e.grade_level = ? OR e.grade_level = ? OR e.grade_level LIKE ?) 
    AND (e.section = ? OR e.section = ? OR e.section LIKE ?) 
    AND (e.school_year = ? OR e.school_year LIKE ? OR e.school_year = ?)
    ORDER BY CASE WHEN COALESCE(r.sex, s.sex, 'M') IN ('F', 'Female', '2') THEN 2 ELSE 1 END ASC, 
             COALESCE(r.last_name, s.last_name, e.student_name) ASC");
$stmt->execute([
    $grade, $grade_clean, "%$grade_clean%", 
    $section, $section_clean, "%$section_clean%", 
    $sy, "%$sy_clean%", $sy_with_spaces
]);
$students = $stmt->fetchAll();

if (empty($students)) {
    echo "<div style='padding:40px; font-family:sans-serif; text-align:center;'>
            <h2 style='color:#ef4444;'>No Data Found</h2>
            <p>No enrolled students found for $grade - $section ($sy).</p>
            <a href='javascript:history.back()' style='color:#3b82f6;'>← Go Back</a>
          </div>";
    exit;
}

// 3. Fetch Grades & Reports - Robust Subquery Approach
$stmt = $pdo->prepare("SELECT * FROM sf9_grades WHERE (school_year = ? OR school_year = ?) AND student_id IN (
    SELECT student_id FROM enrollments WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) AND (section = ? OR section = ? OR section LIKE ?) AND (school_year = ? OR school_year LIKE ?)
)");
$stmt->execute([$sy, $sy_clean, $grade, $grade_clean, "%$grade_clean%", $section, $section_clean, "%$section_clean%", $sy, "%$sy_clean%"]);
$all_grades = [];
while ($row = $stmt->fetch()) { $all_grades[$row['student_id']][$row['subject_id']] = $row; }

$stmt = $pdo->prepare("SELECT * FROM sf9_reports WHERE (school_year = ? OR school_year = ?) AND student_id IN (
    SELECT student_id FROM enrollments WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) AND (section = ? OR section = ? OR section LIKE ?) AND (school_year = ? OR school_year LIKE ?)
)");
$stmt->execute([$sy, $sy_clean, $grade, $grade_clean, "%$grade_clean%", $section, $section_clean, "%$section_clean%", $sy, "%$sy_clean%"]);
$sf9_data = [];
while ($row = $stmt->fetch()) { $sf9_data[$row['student_id']] = $row; }

// System Settings
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_id = get_system_setting($pdo, 'school_id', '300000');
$district = get_system_setting($pdo, 'district', 'Malolos');
$division = get_system_setting($pdo, 'division', 'City of Malolos');
$region = get_system_setting($pdo, 'region', 'Region III');
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');

// Fetch Adviser Name
$stmt = $pdo->prepare("SELECT u.first_name, u.last_name, u.middle_name 
                       FROM users u 
                       JOIN sections s ON u.id = s.adviser_id 
                       WHERE (s.grade_level = ? OR s.grade_level = ?) 
                       AND (s.section_name = ? OR s.section_name = ?) 
                       AND (s.school_year = ? OR s.school_year = ?) LIMIT 1");
$stmt->execute([$grade, $grade_clean, $section, $section_clean, $sy, $sy_clean]);
$adv = $stmt->fetch();
$adviser_name = $adv ? strtoupper($adv['first_name'] . ' ' . ($adv['middle_name'] ? substr($adv['middle_name'],0,1).'. ' : '') . $adv['last_name']) : 'CLASS ADVISER';

// Stats & Processing logic
$stats = [
    'M' => ['total'=>0, 'PROMOTED'=>0, 'CONDITIONAL'=>0, 'RETAINED'=>0],
    'F' => ['total'=>0, 'PROMOTED'=>0, 'CONDITIONAL'=>0, 'RETAINED'=>0]
];
$prof_summary = [
    'Advanced (90-100)' => ['M'=>0, 'F'=>0],
    'Proficient (85-89)' => ['M'=>0, 'F'=>0],
    'Approaching Proficiency (80-84)' => ['M'=>0, 'F'=>0],
    'Developing (75-79)' => ['M'=>0, 'F'=>0],
    'Beginning (74 and below)' => ['M'=>0, 'F'=>0]
];

function getProfLevel($avg) {
    if ($avg >= 90) return 'Advanced (90-100)';
    if ($avg >= 85) return 'Proficient (85-89)';
    if ($avg >= 80) return 'Approaching Proficiency (80-84)';
    if ($avg >= 75) return 'Developing (75-79)';
    if ($avg > 0) return 'Beginning (74 and below)';
    return '';
}

$processed_students = [];
foreach ($students as $s) {
    $sid = $s['student_id'];
    $sum = 0; $count = 0; $fails = 0; $failed_subjects = [];
    foreach($subjects as $sub) {
        $g = $all_grades[$sid][$sub['id']] ?? null;
        if($g && $g['final_grade']) { 
            $sum += $g['final_grade']; $count++; 
            if($g['final_grade'] < 75) {
                $fails++; $failed_subjects[] = $sub['subject_code'] ?: $sub['subject_name'];
            }
        }
    }
    
    $avg = ($count > 0) ? round($sum / $count) : 0;
    $sf9 = $sf9_data[$sid] ?? null;
    
    $status = strtoupper($sf9['promotion_status'] ?? ($avg == 0 ? '—' : ($fails >= 3 ? 'RETAINED' : ($fails > 0 ? 'CONDITIONAL' : 'PROMOTED'))));
    $remarks = $sf9['adviser_remarks'] ?? (!empty($failed_subjects) ? implode(', ', $failed_subjects) : 'None');

    $raw_sex = strtoupper(trim((string)($s['profile_sex'] ?? 'M')));
    $sex = (substr($raw_sex, 0, 1) === 'F' || substr($raw_sex, 0, 1) === 'G') ? 'F' : 'M';
    
    $stats[$sex]['total']++;
    if (isset($stats[$sex][$status])) $stats[$sex][$status]++;
    
    $prof = getProfLevel($avg);
    if($prof) $prof_summary[$prof][$sex]++;

    $processed_students[] = [
        'lrn' => $s['r_lrn'] ?: $s['e_lrn'],
        'name' => $s['last_name'] ? "<b>".strtoupper($s['last_name'])."</b>, {$s['first_name']} " . ($s['middle_name']?substr($s['middle_name'],0,1).'.':'') : strtoupper($s['student_name']),
        'avg' => $avg,
        'status' => $status,
        'remarks' => $remarks,
        'prof' => $prof,
        'sex' => $sex
    ];
}

$males = array_filter($processed_students, function($s) { return $s['sex'] === 'M'; });
$females = array_filter($processed_students, function($s) { return $s['sex'] === 'F'; });

function renderSf5Rows($list) {
    $count = 1;
    foreach ($list as $s) { ?>
        <tr>
            <td><?= $count++ ?></td>
            <td style="font-weight: 800; color: #0f172a; font-family: 'Outfit', sans-serif; font-size: 13px;"><?= htmlspecialchars($s['lrn'] ?: '—') ?></td>
            <td class="student-name"><?= $s['name'] ?></td>
            <td style="font-weight: 800; color: var(--deped-blue);">
                <?= $s['avg'] > 0 ? $s['avg'] : '—' ?>
                <br><small style="font-size: 8px; font-weight: 600; color: #64748b; text-transform: uppercase;"><?= $s['prof'] ?></small>
            </td>
            <td><span class="status-badge status-<?= $s['status'] ?>"><?= $s['status'] ?></span></td>
            <td style="text-align: left; font-size: 10px; color: #64748b;"><?= htmlspecialchars($s['remarks']) ?></td>
        </tr>
    <?php }
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF5 Monitoring | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --deped-blue: #0d47a1; --deped-red: #ef4444; --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.15); }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        .main-content { padding: 100px 40px 48px; margin-left: 0; transition: all 0.3s ease; }
        .report-card { background: white; border-radius: 20px; padding: 40px; box-shadow: var(--glass-shadow); border: 1px solid rgba(226, 232, 240, 0.8); margin-top: 20px; position: relative; overflow: hidden; }
        .report-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--deped-blue), #2563eb); }
        .official-header { display: flex; align-items: center; justify-content: center; gap: 50px; margin-bottom: 30px; border-bottom: 2px solid #f1f5f9; padding-bottom: 25px; }
        .deped-logo { width: 80px; height: auto; }
        .header-text { text-align: center; }
        .header-text h1 { margin: 8px 0; font-size: 24px; color: var(--deped-blue); font-family: 'Outfit', sans-serif; font-weight: 800; }
        .form-identity { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; padding: 24px; background: #f8fafc; border-radius: 16px; border: 1px solid #f1f5f9; }
        .id-item b { color: var(--deped-blue); text-transform: uppercase; font-size: 10px; display: block; margin-bottom: 4px; font-weight: 800; opacity: 0.7; }
        .id-item span { font-weight: 700; color: #0f172a; font-size: 15px; }
        .table-container { margin-top: 20px; border-radius: 12px; border: 1px solid #e2e8f0; overflow-x: auto; background: white; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1000px; }
        th { background: #f8fafc; color: #1e293b; font-weight: 700; padding: 12px 8px; border: 1px solid #e2e8f0; text-transform: uppercase; }
        td { padding: 10px 8px; border: 1px solid #e2e8f0; text-align: center; }
        .student-name { text-align: left; min-width: 250px; padding-left: 15px; position: sticky; left: 0; background: white; z-index: 5; color: #0f172a; }
        .status-badge { padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .status-PROMOTED { background: #dcfce7; color: #15803d; }
        .status-RETAINED { background: #fee2e2; color: #991b1b; }
        .status-CONDITIONAL { background: #fef9c3; color: #854d0e; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px; margin-top: 40px; }
        .summary-card { background: #f8fafc; padding: 24px; border-radius: 16px; border: 1px solid #f1f5f9; }
        .summary-card h3 { margin: 0 0 15px 0; font-size: 13px; color: var(--deped-blue); text-transform: uppercase; font-weight: 800; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .summary-table td { padding: 8px 4px; border-bottom: 1px solid #f1f5f9; font-weight: 600; }
        .signatures { margin-top: 80px; display: flex; justify-content: space-around; }
        .sig-box { text-align: center; min-width: 250px; }
        .sig-line { border-bottom: 2px solid #0f172a; font-weight: 800; text-transform: uppercase; font-size: 15px; margin-bottom: 8px; padding-bottom: 5px; }
        @media print { .no-print { display: none !important; } .main-content { padding: 0 !important; } .report-card { box-shadow: none; border: none; } }
    </style>
</head>
<body>
    <?php include $header_file; ?>
    <div class="main-content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between;">
            <a href="javascript:history.back()" style="text-decoration: none; color: #64748b; font-weight: 700;"><i class="bi bi-arrow-left"></i> Back</a>
            <button onclick="window.print()" style="background: var(--deped-blue); color: white; border: none; padding: 10px 20px; border-radius: 30px; cursor: pointer; font-weight: 700;">🖨️ Print Real-time Report</button>
        </div>
        <div class="report-card">
            <div class="official-header">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="deped-logo">
                <div class="header-text">
                    <h2>Republic of the Philippines</h2>
                    <h1>Department of Education</h1>
                    <p>SCHOOL FORM 5 (SF5) REPORT ON PROMOTION & PROFICIENCY</p>
                    <p style="color:var(--deped-red); font-size:11px; margin-top:5px; letter-spacing:1px; font-weight:700;">ADMINISTRATIVE REAL-TIME MONITORING VIEW</p>
                </div>
                <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="deped-logo">
            </div>

            <div class="form-identity">
                <div class="id-item"><b>Region</b><span><?= htmlspecialchars($region) ?></span></div>
                <div class="id-item"><b>Division</b><span><?= htmlspecialchars($division) ?></span></div>
                <div class="id-item"><b>District</b><span><?= htmlspecialchars($district) ?></span></div>
                <div class="id-item"><b>School ID</b><span><?= htmlspecialchars($school_id) ?></span></div>
                <div class="id-item"><b>School Year</b><span><?= htmlspecialchars($sy) ?></span></div>
                <div class="id-item"><b>Grade & Section</b><span><?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?></span></div>
                <div class="id-item" style="grid-column: span 2;"><b>Class Adviser</b><span><?= htmlspecialchars($adviser_name) ?></span></div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr><th width="40">No.</th><th width="150">LRN</th><th class="student-name">Learner's Name</th><th width="120">General Average</th><th width="150">Action Taken</th><th>Learning Areas Not Met</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($males)): ?>
                            <tr><td colspan="6" style="background: #f8fafc; text-align: left; padding-left: 15px; font-weight: 800; color: #475569; text-transform: uppercase; font-size: 10px;">Male</td></tr>
                            <?php renderSf5Rows($males); ?>
                        <?php endif; ?>
                        <?php if (!empty($females)): ?>
                            <tr><td colspan="6" style="background: #f8fafc; text-align: left; padding-left: 15px; font-weight: 800; color: #475569; text-transform: uppercase; font-size: 10px;">Female</td></tr>
                            <?php renderSf5Rows($females); ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Summary Table (Promotion)</h3>
                    <table class="summary-table">
                        <thead><tr><th style="background: transparent; border: none;">STATUS</th><th style="background: transparent; border: none;">MALE</th><th style="background: transparent; border: none;">FEMALE</th><th style="background: transparent; border: none;">TOTAL</th></tr></thead>
                        <tbody>
                            <?php foreach (['PROMOTED', 'CONDITIONAL', 'RETAINED'] as $st): ?>
                                <tr><td style="text-align: left; border: none;"><?= $st ?></td><td style="border: none;"><?= $stats['M'][$st] ?></td><td style="border: none;"><?= $stats['F'][$st] ?></td><td style="border: none;"><?= $stats['M'][$st] + $stats['F'][$st] ?></td></tr>
                            <?php endforeach; ?>
                            <tr style="background: #e2e8f0; font-weight: 800;"><td style="text-align: left; border: none;">TOTAL</td><td style="border: none;"><?= $stats['M']['total'] ?></td><td style="border: none;"><?= $stats['F']['total'] ?></td><td style="border: none;"><?= $stats['M']['total'] + $stats['F']['total'] ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="summary-card">
                    <h3>Level of Proficiency</h3>
                    <table class="summary-table">
                        <thead><tr><th style="background: transparent; border: none;">LEVEL</th><th style="background: transparent; border: none;">MALE</th><th style="background: transparent; border: none;">FEMALE</th><th style="background: transparent; border: none;">TOTAL</th></tr></thead>
                        <tbody>
                            <?php foreach ($prof_summary as $level => $v): ?>
                                <tr><td style="text-align: left; border: none;"><?= $level ?></td><td style="border: none;"><?= $v['M'] ?></td><td style="border: none;"><?= $v['F'] ?></td><td style="border: none;"><?= $v['M'] + $v['F'] ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="signatures">
                <div class="sig-box"><div class="sig-line"><?= htmlspecialchars($adviser_name) ?></div><div style="font-size: 11px; font-weight: 600; color: #64748b;">Class Adviser / Prepared By</div></div>
                <div class="sig-box"><div class="sig-line"><?= strtoupper(htmlspecialchars($principal_name)) ?></div><div style="font-size: 11px; font-weight: 600; color: #64748b;">School Head / Principal</div></div>
            </div>
        </div>
    </div>
</body>
</html>
