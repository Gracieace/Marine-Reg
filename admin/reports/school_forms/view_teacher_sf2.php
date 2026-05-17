<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

// Fetch System Settings for Header
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_id = get_system_setting($pdo, 'school_id', '300000');
$district = get_system_setting($pdo, 'district', 'Malolos City');
$division = get_system_setting($pdo, 'division', 'City of Malolos');
$region = get_system_setting($pdo, 'region', 'Region III');
$school_logo = trim(get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png'));
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');
$display_logo = (strpos($school_logo, 'http') === 0) ? $school_logo : url_for('/' . ltrim($school_logo, '/'));

$grade = trim($_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');
$sy = trim($_GET['sy'] ?? '');
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';

// Bidirectional normalization for robust matching
$grade_clean = trim(str_ireplace('Grade', '', $grade));
$grade_with_prefix = "Grade " . $grade_clean;

$section_clean = trim(str_ireplace('Section', '', $section));
$section_with_prefix = "Section " . $section_clean;

// Normalize SY (handle spaces)
$sy_clean = str_replace(' ', '', $sy); // "2024-2025"
$sy_with_spaces = str_replace('-', ' - ', $sy_clean); // "2024 - 2025"

// Get available months for this section/SY
$stmt = $pdo->prepare("
    SELECT DISTINCT report_month, report_year 
    FROM sf2_reports 
    WHERE (grade_level = ? OR grade_level = ? OR grade_level = ?) 
    AND (section = ? OR section = ? OR section = ?) 
    AND (school_year = ? OR school_year = ? OR school_year = ?)
    ORDER BY report_year DESC, FIELD(report_month, 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') DESC
");
$stmt->execute([
    $grade, $grade_clean, $grade_with_prefix,
    $section, $section_clean, $section_with_prefix,
    $sy, $sy_clean, $sy_with_spaces
]);
$available_months = $stmt->fetchAll();

// If no month selected, pick the latest one
if (!$month && !empty($available_months)) {
    $month = $available_months[0]['report_month'];
    $year = $available_months[0]['report_year'];
}

// Get SF2 report details
$report = null;
if ($month && $year) {
    $stmt = $pdo->prepare("
        SELECT r.id AS report_actual_id, r.*, s.*
        FROM sf2_reports r
        LEFT JOIN sf2_monthly_summary s ON r.id = s.sf2_report_id
        WHERE (r.grade_level = ? OR r.grade_level = ? OR r.grade_level = ?) 
        AND (r.section = ? OR r.section = ? OR r.section = ?) 
        AND (r.school_year = ? OR r.school_year = ? OR r.school_year = ?)
        AND r.report_month = ? AND r.report_year = ?
        LIMIT 1
    ");
    $stmt->execute([
        $grade, $grade_clean, $grade_with_prefix,
        $section, $section_clean, $section_with_prefix,
        $sy, $sy_clean, $sy_with_spaces,
        $month, $year
    ]);
    $report = $stmt->fetch();
}

if (!$report) {
    // Check if we have ANY report at all
    $stmt = $pdo->prepare("
        SELECT r.id AS report_actual_id, r.*, s.* 
        FROM sf2_reports r 
        LEFT JOIN sf2_monthly_summary s ON r.id = s.sf2_report_id
        WHERE (r.grade_level = ? OR r.grade_level = ? OR r.grade_level = ?) 
        AND (r.section = ? OR r.section = ? OR r.section = ?) 
        AND (r.school_year = ? OR r.school_year = ? OR r.school_year = ?)
        ORDER BY r.created_at DESC LIMIT 1
    ");
    $stmt->execute([
        $grade, $grade_clean, $grade_with_prefix,
        $section, $section_clean, $section_with_prefix,
        $sy, $sy_clean, $sy_with_spaces
    ]);
    $report = $stmt->fetch();
}

if (!$report) {
    echo "<div style='padding: 30px; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); margin: 40px auto; max-width: 600px; text-align: center; font-family: system-ui, -apple-system, sans-serif;'>";
    echo "<div style='color: #ef4444; font-size: 48px; margin-bottom: 20px;'><i class='bi bi-exclamation-circle'></i></div>";
    echo "<h2 style='color: #1e293b; margin: 0 0 10px 0; font-weight: 700;'>No SF2 Report Found</h2>";
    echo "<p style='color: #64748b; margin-bottom: 20px;'>We couldn't find a teacher-submitted SF2 report snapshot for the selected criteria. This usually means the class adviser hasn't finalized or saved their attendance report for this period yet.</p>";
    echo "<div style='background: #f8fafc; padding: 15px; border-radius: 8px; text-align: left; font-size: 14px; color: #475569;'>";
    echo "<b>Criteria:</b><br>";
    echo "• Grade: $grade<br>";
    echo "• Section: $section<br>";
    echo "• School Year: $sy<br>";
    if ($month) echo "• Month: $month $year<br>";
    echo "</div>";
    echo "<a href='dashboard.php' style='display: inline-block; margin-top: 25px; padding: 10px 20px; background: #0038a8; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;'>Return to Dashboard</a>";
    echo "</div>";
    exit;
}

$report_id = $report['report_actual_id'];

// Get student records
$stmt = $pdo->prepare("
    SELECT * FROM sf2_student_records 
    WHERE sf2_report_id = ? 
    ORDER BY sex DESC, student_name ASC
");
$stmt->execute([$report_id]);
$students = $stmt->fetchAll();

// Get unique dates for the month
$stmt = $pdo->prepare("
    SELECT DISTINCT attendance_date FROM sf2_daily_attendance 
    WHERE sf2_report_id = ? 
    ORDER BY attendance_date ASC
");
$stmt->execute([$report_id]);
$dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Group attendance
$stmt = $pdo->prepare("SELECT * FROM sf2_daily_attendance WHERE sf2_report_id = ?");
$stmt->execute([$report_id]);
$attendance_records = $stmt->fetchAll();

$attendance_by_student = [];
foreach ($attendance_records as $record) {
    $attendance_by_student[$record['student_id']][$record['attendance_date']] = strtolower($record['attendance_status']);
}

// Fallback Computation: If disaggregated columns are missing/zero, calculate from student records
$days_of_classes = (int)($report['days_of_classes'] ?: count($dates));
if ($days_of_classes > 0 && ($report['ada_male'] == 0 || $report['registered_male_eom'] == 0)) {
    $m_pres = 0; $f_pres = 0; $m_count = 0; $f_count = 0;
    foreach ($students as $s) {
        if (strtoupper($s['sex']) === 'M') {
            $m_pres += $s['total_present'];
            $m_count++;
        } else {
            $f_pres += $s['total_present'];
            $f_count++;
        }
    }
    
    // Only update if database was empty
    if ($report['registered_male_eom'] == 0) $report['registered_male_eom'] = $m_count;
    if ($report['registered_female_eom'] == 0) $report['registered_female_eom'] = $f_count;
    if ($report['registered_total_eom'] == 0) $report['registered_total_eom'] = $m_count + $f_count;
    
    if ($report['ada_male'] == 0) $report['ada_male'] = $m_pres / $days_of_classes;
    if ($report['ada_female'] == 0) $report['ada_female'] = $f_pres / $days_of_classes;
    if ($report['average_daily_attendance'] == 0) $report['average_daily_attendance'] = ($m_pres + $f_pres) / $days_of_classes;

    // Percentages
    if ($report['registered_male_eom'] > 0) $report['perc_male'] = ($report['ada_male'] / $report['registered_male_eom']) * 100;
    if ($report['registered_female_eom'] > 0) $report['perc_female'] = ($report['ada_female'] / $report['registered_female_eom']) * 100;
    
    // Percentage of Enrolment (simplified fallback)
    if ($report['registered_male_eom'] > 0) $report['perc_male_enrollment'] = 100; // Default to 100 if no BOSY data
    if ($report['registered_female_eom'] > 0) $report['perc_female_enrollment'] = 100;
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher SF2 View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --deped-blue: #0d47a1;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; overflow-x: hidden; }
        .main-content { padding: 100px 40px 48px; margin-left: 0; transition: all 0.3s ease; }
        
        /* Hide sidebar toggle since sidebar is removed */
        #sidebarToggle { display: none !important; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0 !important; padding: 90px 15px 40px; }
            .action-bar { right: 20px; top: 15px; }
        }

        .action-bar {
            position: fixed; top: 20px; right: 40px; z-index: 1001; display: flex; gap: 12px; align-items: center;
            background: rgba(255, 255, 255, 0.9); padding: 8px 16px; border-radius: 50px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15); backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .btn-action {
            display: flex; align-items: center; gap: 8px; padding: 10px 22px; border-radius: 30px;
            font-size: 14px; font-weight: 700; text-decoration: none; transition: all 0.3s;
            cursor: pointer; border: none;
        }

        .filter-select {
            padding: 8px 16px; border-radius: 20px; border: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; outline: none; background: white; cursor: pointer;
        }

        .report-card { 
            background: white; border-radius: 20px; padding: 40px; box-shadow: var(--glass-shadow); 
            border: 1px solid rgba(226, 232, 240, 0.8); margin-top: 20px; position: relative; overflow: hidden;
            width: 100%; max-width: 100%; box-sizing: border-box;
        }
        
        @media (max-width: 768px) {
            .main-content { padding: 80px 10px 20px; }
            .report-card { padding: 20px 12px; border-radius: 12px; }
            .official-header { flex-direction: column; gap: 15px; text-align: center; }
            .header-text h1 { font-size: 18px; }
            .legend { flex-wrap: wrap; gap: 8px; font-size: 11px; }
            .form-identity { grid-template-columns: 1fr 1fr; gap: 15px; padding: 15px; }
            .id-item span { font-size: 14px; }
            .summary-container { flex-direction: column; gap: 20px; }
            .summary-table { width: 100%; }
            .signatures { flex-direction: column; gap: 40px; align-items: center; }
            .sig-box { min-width: 100%; }
            .action-bar { position: relative; top: 0; right: 0; margin-bottom: 20px; width: 100%; border-radius: 12px; justify-content: center; }
        }

        .report-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--deped-blue), #2563eb); }

        .official-header {
            display: flex; align-items: center; justify-content: center; gap: 50px;
            margin-bottom: 30px; padding-bottom: 25px; border-bottom: 2px solid #f1f5f9;
        }
        .deped-logo { width: 80px; height: auto; }
        .header-text { text-align: center; }
        .header-text h2 { margin: 0; font-size: 12px; font-weight: 500; text-transform: uppercase; color: #64748b; }
        .header-text h1 { margin: 8px 0; font-size: 24px; color: var(--deped-blue); font-family: 'Outfit', sans-serif; font-weight: 800; }
        .header-text p { margin: 0; font-size: 13px; color: #475569; font-weight: 600; }

        .form-identity {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px; margin: 30px 0; padding: 24px; background: #f8fafc; border-radius: 16px; border: 1px solid #f1f5f9;
        }
        .id-item b { color: var(--deped-blue); text-transform: uppercase; font-size: 11px; display: block; margin-bottom: 4px; font-weight: 800; }
        .id-item span { font-weight: 700; color: #0f172a; font-size: 16px; }
        
        .table-container { 
            margin-top: 20px; border-radius: 12px; border: 1px solid #e2e8f0; overflow-x: auto; background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            -webkit-overflow-scrolling: touch;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; color: #1e293b; }
        th { background: #f8fafc; color: #1e293b; font-weight: 700; padding: 12px 8px; border: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 10; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 8px; border: 1px solid #e2e8f0; text-align: center; height: 40px; }
        .student-name { text-align: left; font-weight: 700; min-width: 220px; padding-left: 15px; position: sticky; left: 0; background: white; z-index: 5; box-shadow: 4px 0 10px rgba(0,0,0,0.03); color: #0f172a; }
        th.student-name { z-index: 15; background: #f8fafc; }

        .status-A { color: #ef4444; font-weight: 800; font-size: 14px; background: rgba(239, 68, 68, 0.1); border-radius: 4px; padding: 2px 6px; }
        .status-L { color: #f59e0b; font-weight: 800; font-size: 14px; background: rgba(245, 158, 11, 0.1); border-radius: 4px; padding: 2px 6px; }
        .status-E { color: #10b981; font-weight: 800; font-size: 14px; background: rgba(16, 185, 129, 0.1); border-radius: 4px; padding: 2px 6px; }
        .status-P { color: #3b82f6; font-weight: 800; font-size: 14px; }
        
        .summary-container { display: flex; justify-content: space-between; gap: 30px; margin-top: 40px; }
        .summary-table { width: 50%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
        .summary-table th, .summary-table td { border: 1px solid #e2e8f0; padding: 10px; font-size: 14px; }
        .summary-table th { background: #f8fafc; color: #475569; font-weight: 700; text-align: left; }
        .summary-table td { color: #0f172a; font-weight: 600; text-align: center; }
        .summary-table .label-col { text-align: left; width: 60%; }

        .signatures { margin-top: 50px; display: flex; justify-content: space-around; padding: 20px 0; }
        .sig-box { text-align: center; min-width: 250px; }
        .sig-line { border-bottom: 2px solid #0f172a; font-weight: 800; text-transform: uppercase; font-size: 15px; margin-bottom: 8px; color: #0f172a; padding-bottom: 5px; }
        .sig-title { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }
    </style>
</head>
<body>
    <?php include $header_file; ?>

    <div class="main-content">
        <div class="action-bar no-print">
            <form method="GET" style="display: flex; gap: 8px; align-items: center;">
                <input type="hidden" name="grade" value="<?= htmlspecialchars($grade) ?>">
                <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                <input type="hidden" name="sy" value="<?= htmlspecialchars($sy) ?>">
                <select name="month" class="filter-select" onchange="this.form.submit()">
                    <?php foreach ($available_months as $am): ?>
                        <option value="<?= $am['report_month'] ?>" <?= $month == $am['report_month'] ? 'selected' : '' ?>>
                            <?= $am['report_month'] ?> <?= $am['report_year'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>">
            </form>
        </div>

        <div class="report-card">
            <div class="official-header">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" alt="DepEd Logo" class="deped-logo">
                <div class="header-text">
                    <h2>Republic of the Philippines</h2>
                    <h1>Department of Education</h1>
                    <p>SCHOOL FORM 2 (SF2) DAILY ATTENDANCE REPORT</p>
                    <p style="font-weight: 800; color: #ef4444; margin-top: 8px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">Institutional Snapshot: Teacher Submitted Version</p>
                </div>
                <img src="<?= $display_logo ?>" alt="School Logo" class="deped-logo" onerror="this.src='/favicon.ico'">
            </div>

            <div class="form-identity">
                <div class="id-item">
                    <b>School ID</b>
                    <span><?= htmlspecialchars($school_id) ?></span>
                </div>
                <div class="id-item">
                    <b>School Year</b>
                    <span><?= htmlspecialchars($sy) ?></span>
                </div>
                <div class="id-item">
                    <b>Month of</b>
                    <span><?= htmlspecialchars($month) ?> <?= htmlspecialchars($year) ?></span>
                </div>
                <div class="id-item">
                    <b>Grade & Section</b>
                    <span><?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?></span>
                </div>
                <div class="id-item">
                    <b>Days of Classes</b>
                    <span><?= $report['days_of_classes'] ?? 0 ?></span>
                </div>
            </div>

            <div class="legend">
                <div class="legend-item"><span class="status-A">A</span> Absent</div>
                <div class="legend-item"><span class="status-L">L</span> Late / Tardy</div>
                <div class="legend-item"><span class="status-E">E</span> Excused</div>
                <div class="legend-item"><span class="status-P">P</span> Present</div>
            </div>

            <div class="table-container">
                <?php if (empty($students)): ?>
                    <div class="no-data">
                        <i class="bi bi-folder-x" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
                        No student records found in this report.
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th rowspan="2" width="50">NO</th>
                                <th rowspan="2" class="student-name">NAME OF LEARNER</th>
                                <th colspan="<?= count($dates) ?>">DATES OF THE MONTH</th>
                                <th colspan="2">TOTAL</th>
                                <th rowspan="2">REMARKS</th>
                            </tr>
                            <tr>
                                <?php foreach ($dates as $date): ?>
                                    <th><?= date('d', strtotime($date)) ?></th>
                                <?php endforeach; ?>
                                <th>ABS</th>
                                <th>PRE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $males = array_filter($students, function($s) { return strtoupper($s['sex']) === 'M'; });
                            $females = array_filter($students, function($s) { return strtoupper($s['sex']) === 'F'; });
                            
                            if (!empty($males)): ?>
                                <tr>
                                    <td colspan="<?= count($dates) + 5 ?>" style="background: #f1f5f9; text-align: left; padding-left: 15px; font-weight: 800; color: #475569;">MALE</td>
                                </tr>
                                <?php $count = 1; foreach ($males as $s): ?>
                                    <tr>
                                        <td><?= $count++ ?></td>
                                        <td class="student-name"><?= htmlspecialchars($s['student_name']) ?></td>
                                        <?php foreach ($dates as $date): 
                                            $status = $attendance_by_student[$s['student_id']][$date] ?? '';
                                            ?>
                                            <td>
                                                <?php if ($status === 'present' || $status === 'p'): ?>
                                                    <span class="status-P">P</span>
                                                <?php elseif ($status === 'absent' || $status === 'a'): ?>
                                                    <span class="status-A">A</span>
                                                <?php elseif (strpos($status, 'tardy') !== false || strpos($status, 'late') !== false || $status === 'l'): ?>
                                                    <span class="status-L">L</span>
                                                <?php elseif (strpos($status, 'excused') !== false || $status === 'e'): ?>
                                                    <span class="status-E">E</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td><b><?= $s['total_absent'] ?></b></td>
                                        <td><b><?= $s['total_present'] ?></b></td>
                                        <td><small><?= htmlspecialchars($s['remarks']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (!empty($females)): ?>
                                <tr>
                                    <td colspan="<?= count($dates) + 5 ?>" style="background: #f1f5f9; text-align: left; padding-left: 15px; font-weight: 800; color: #475569;">FEMALE</td>
                                </tr>
                                <?php $count = 1; foreach ($females as $s): ?>
                                    <tr>
                                        <td><?= $count++ ?></td>
                                        <td class="student-name"><?= htmlspecialchars($s['student_name']) ?></td>
                                        <?php foreach ($dates as $date): 
                                            $status = $attendance_by_student[$s['student_id']][$date] ?? '';
                                            ?>
                                            <td>
                                                <?php if ($status === 'present' || $status === 'p'): ?>
                                                    <span class="status-P">P</span>
                                                <?php elseif ($status === 'absent' || $status === 'a'): ?>
                                                    <span class="status-A">A</span>
                                                <?php elseif (strpos($status, 'tardy') !== false || strpos($status, 'late') !== false || $status === 'l'): ?>
                                                    <span class="status-L">L</span>
                                                <?php elseif (strpos($status, 'excused') !== false || $status === 'e'): ?>
                                                    <span class="status-E">E</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td><b><?= $s['total_absent'] ?></b></td>
                                        <td><b><?= $s['total_present'] ?></b></td>
                                        <td><small><?= htmlspecialchars($s['remarks']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="summary-container">
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th class="label-col">Summary Item</th>
                            <th width="50">M</th>
                            <th width="50">F</th>
                            <th width="80">TOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="label-col">Enrolment (as of 1st Friday of SY)</td>
                            <td><?= $report['enrolment_male_bosy'] ?? 0 ?></td>
                            <td><?= $report['enrolment_female_bosy'] ?? 0 ?></td>
                            <td><?= $report['enrolment_total_bosy'] ?? 0 ?></td>
                        </tr>
                        <tr>
                            <td class="label-col">Late Enrolment during the month</td>
                            <td><?= $report['late_enrolment_male'] ?? 0 ?></td>
                            <td><?= $report['late_enrolment_female'] ?? 0 ?></td>
                            <td><?= $report['late_enrolment_total'] ?? 0 ?></td>
                        </tr>
                        <tr>
                            <td class="label-col">Registered Learners (End of Month)</td>
                            <td><?= $report['registered_male_eom'] ?? 0 ?></td>
                            <td><?= $report['registered_female_eom'] ?? 0 ?></td>
                            <td><?= $report['registered_total_eom'] ?? 0 ?></td>
                        </tr>
                        <tr>
                            <td class="label-col">Average Daily Attendance</td>
                            <td><?= number_format($report['ada_male'] ?? 0, 2) ?></td>
                            <td><?= number_format($report['ada_female'] ?? 0, 2) ?></td>
                            <td><?= number_format($report['average_daily_attendance'] ?? 0, 2) ?></td>
                        </tr>
                        <tr>
                            <td class="label-col">Percentage of Enrolment</td>
                            <td><?= number_format($report['perc_male_enrollment'] ?? 0, 1) ?>%</td>
                            <td><?= number_format($report['perc_female_enrollment'] ?? 0, 1) ?>%</td>
                            <td><?= number_format($report['percentage_enrolment'] ?? 0, 1) ?>%</td>
                        </tr>
                        <tr>
                            <td class="label-col">Percentage of Attendance</td>
                            <td><?= number_format($report['perc_male'] ?? 0, 1) ?>%</td>
                            <td><?= number_format($report['perc_female'] ?? 0, 1) ?>%</td>
                            <td><?= number_format($report['percentage_attendance'] ?? 0, 1) ?>%</td>
                        </tr>
                        <tr style="background: #fff7ed;">
                            <td class="label-col" style="color: #9a3412;">Transferred In (Cumulative)</td>
                            <td colspan="3" style="text-align: right; padding-right: 25px; color: #9a3412;"><?= $report['transferred_in'] ?? 0 ?></td>
                        </tr>
                        <tr style="background: #fff1f2;">
                            <td class="label-col" style="color: #9f1239;">Transferred Out / Dropped Out (Cumulative)</td>
                            <td colspan="3" style="text-align: right; padding-right: 25px; color: #9f1239;"><?= $report['transferred_out'] ?? 0 ?></td>
                        </tr>
                        <tr style="background: #f1f5f9;">
                            <td class="label-col">No Longer in School (NLS) Count</td>
                            <td colspan="3" style="text-align: right; padding-right: 25px;"><?= $report['nls_count'] ?? 0 ?></td>
                        </tr>
                        <tr style="background: #f1f5f9;">
                            <td class="label-col">5 Consecutive Days Absent</td>
                            <td colspan="3" style="text-align: right; padding-right: 25px;"><?= $report['absent_5_consecutive_days'] ?? 0 ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="width: 45%; background: #f8fafc; padding: 25px; border-radius: 16px; border: 1px solid #f1f5f9;">
                    <h3 style="margin: 0 0 15px 0; font-size: 13px; color: var(--deped-blue); text-transform: uppercase; font-weight: 800;">Report Guidelines</h3>
                    <div style="font-size: 14px; color: #475569; line-height: 1.6;">
                        <p>1. Attendance is recorded daily using standard codes: <b>P</b> (Present), <b>A</b> (Absent), <b>L</b> (Late), <b>E</b> (Excused).</p>
                        <p>2. Registered Learners (End of Month) = (BOSY Enrolment + Late Enrolment) - (Transferred Out/Dropped Out).</p>
                        <p>3. This report represents the teacher-submitted snapshot for the specified month.</p>
                    </div>
                </div>
            </div>

            <div class="signatures">
                <div class="sig-box">
                    <div class="sig-line"><?= htmlspecialchars($report['adviser_name'] ?: 'Class Adviser') ?></div>
                    <div class="sig-title">Class Adviser Signature</div>
                </div>
                <div class="sig-box">
                    <div class="sig-line"><?= strtoupper(htmlspecialchars($principal_name)) ?></div>
                    <div class="sig-title">School Head / Principal</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
