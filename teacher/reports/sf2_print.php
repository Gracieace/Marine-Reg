<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';

$pdo = db_connect();
$report_id = $_GET['id'] ?? null;

if (!$report_id) {
    die("Report ID is required.");
}

// Fetch report header
$stmt = $pdo->prepare("SELECT * FROM sf2_reports WHERE id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch();

if (!$report) {
    die("Report not found.");
}

// Fetch students
$stmt = $pdo->prepare("SELECT * FROM sf2_student_records WHERE sf2_report_id = ? ORDER BY sex DESC, student_name ASC");
$stmt->execute([$report_id]);
$students = $stmt->fetchAll();

// Fetch attendance data
$stmt = $pdo->prepare("SELECT * FROM sf2_daily_attendance WHERE sf2_report_id = ?");
$stmt->execute([$report_id]);
$attendance_raw = $stmt->fetchAll();

// Organize attendance by student_id (LRN) and date for absolute linking accuracy
$attendance_map = [];
foreach ($attendance_raw as $att) {
    $sid = $att['student_id'];
    $cleanName = strtolower(trim($att['student_name'] ?? ''));
    // Ensure we only use the date part in case the DB returns a DATETIME
    $safeDate = explode(' ', $att['attendance_date'])[0];
    
    // Store by ID (most reliable)
    if ($sid) {
        $attendance_map["id_$sid"][$safeDate] = $att['attendance_status'];
    }
    // Also store by Name as fallback
    if ($cleanName) {
        $attendance_map["name_$cleanName"][$safeDate] = $att['attendance_status'];
    }
}


// Fetch monthly summary
$stmt = $pdo->prepare("SELECT * FROM sf2_monthly_summary WHERE sf2_report_id = ?");
$stmt->execute([$report_id]);
$summary = $stmt->fetch();

// Helper to get dates of the month
function getDatesForMonth($monthName, $year) {
    $dates = [];
    $monthNum = date('m', strtotime($monthName));
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
    
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $time = mktime(0, 0, 0, $monthNum, $d, $year);
        if (date('N', $time) < 6) { // Monday to Friday only
            $dates[] = [
                'day' => $d,
                'dayInitial' => substr(date('D', $time), 0, 1) === 'T' ? (date('D', $time) === 'Thu' ? 'Th' : 'T') : substr(date('D', $time), 0, 1),
                'dateString' => date('Y-m-d', $time)
            ];
        }
    }
    return $dates;
}

$attendanceDates = getDatesForMonth($report['report_month'], $report['report_year']);
$totalDays = count($attendanceDates);

// Fallback calculations for disaggregated summary if missing in DB or incomplete
if (!$summary || (isset($summary['ada_male']) && $summary['ada_male'] == 0 && isset($summary['ada_female']) && $summary['ada_female'] == 0)) {
    if (!$summary) $summary = [];
    
    // Ensure basic keys exist
    $defaults = [
        'enrolment_male_bosy' => 0, 'enrolment_female_bosy' => 0, 'enrolment_total_bosy' => 0,
        'late_enrolment_male' => 0, 'late_enrolment_female' => 0, 'late_enrolment_total' => 0,
        'registered_male_eom' => 0, 'registered_female_eom' => 0, 'registered_total_eom' => 0,
        'ada_male' => 0, 'ada_female' => 0, 'average_daily_attendance' => 0,
        'perc_male' => 0, 'perc_female' => 0, 'percentage_attendance' => 0,
        'perc_male_enrollment' => 0, 'perc_female_enrollment' => 0, 'percentage_enrolment' => 0
    ];
    foreach ($defaults as $k => $v) {
        if (!isset($summary[$k])) $summary[$k] = $v;
    }
    
    $mCount = 0; $fCount = 0;
    $mPresent = 0; $fPresent = 0;
    
    foreach ($students as $s) {
        $sex = strtoupper(substr($s['sex'] ?? 'M', 0, 1));
        // Prioritize the total_present column which is already calculated/saved in sf2_student_records
        $presentCount = (int)($s['total_present'] ?? 0);
        
        // If total_present is 0, try manual count as last resort
        if ($presentCount == 0 && !empty($attendanceDates)) {
            $sid = $s['student_id'];
            foreach ($attendanceDates as $d) {
                $status = $attendance_map["id_$sid"][$d['dateString']] ?? '';
                if ($status === 'P') $presentCount++;
            }
        }
        
        if ($sex === 'M') {
            $mCount++;
            $mPresent += $presentCount;
        } else {
            $fCount++;
            $fPresent += $presentCount;
        }
    }
    
    // Update Registered Learners if they are 0
    if ($summary['registered_male_eom'] == 0) $summary['registered_male_eom'] = $mCount;
    if ($summary['registered_female_eom'] == 0) $summary['registered_female_eom'] = $fCount;
    $summary['registered_total_eom'] = $summary['registered_male_eom'] + $summary['registered_female_eom'];
    
    if ($totalDays > 0) {
        $summary['ada_male'] = $mPresent / $totalDays;
        $summary['ada_female'] = $fPresent / $totalDays;
        $summary['average_daily_attendance'] = ($mPresent + $fPresent) / $totalDays;
        
        if ($summary['registered_male_eom'] > 0) {
            $summary['perc_male'] = ($summary['ada_male'] / $summary['registered_male_eom']) * 100;
            $summary['perc_male_enrollment'] = $summary['perc_male'];
        }
        if ($summary['registered_female_eom'] > 0) {
            $summary['perc_female'] = ($summary['ada_female'] / $summary['registered_female_eom']) * 100;
            $summary['perc_female_enrollment'] = $summary['perc_female'];
        }
        if ($summary['registered_total_eom'] > 0) {
            $summary['percentage_attendance'] = ($summary['average_daily_attendance'] / $summary['registered_total_eom']) * 100;
            $summary['percentage_enrolment'] = $summary['percentage_attendance'];
        }
    }
}

// System Settings for Header
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_id = get_system_setting($pdo, 'school_id', '300000');
$district = get_system_setting($pdo, 'district', 'Malolos City');
$division = get_system_setting($pdo, 'division', 'City of Malolos');
$region = get_system_setting($pdo, 'region', 'Region III');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print SF2 - <?= htmlspecialchars($report['report_month'] . ' ' . $report['report_year']) ?></title>
    <style>
        @page {
            size: legal landscape;
            margin: 0.25in;
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 9px;
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            display: flex;
            justify-content: center;
            min-height: 100vh;
            padding-top: 60px;
            padding-bottom: 40px;
        }
        
        .print-area { 
            width: 13.5in; 
            background: white; 
            padding: 0.4in; 
            box-shadow: 0 15px 50px rgba(0,0,0,0.15); 
            min-height: 8in;
            position: relative;
            border-radius: 8px;
            color: #1e293b;
        }
        .official-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #0038a8; padding-bottom: 12px; margin-bottom: 20px; }
        .logo { height: 60px; width: auto; }
        .header-center { text-align: center; flex: 1; }
        .header-center h2 { margin: 0; font-size: 11px; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .header-center h1 { margin: 2px 0; font-size: 20px; color: #0038a8; font-weight: 800; }
        .header-center p { margin: 0; font-size: 10px; font-weight: 700; color: #1e293b; text-transform: uppercase; }

        .title {
            text-align: center;
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 2px;
            text-transform: uppercase;
            color: #0f172a;
        }
        .subtitle {
            text-align: center;
            font-style: italic;
            font-size: 9px;
            margin-bottom: 15px;
            color: #64748b;
        }
        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .info-grid td {
            border-bottom: 1px solid #000;
            padding: 2px 5px;
        }
        .label {
            font-weight: 800;
            text-transform: uppercase;
            font-size: 8px;
            color: #0038a8;
            border-bottom: none !important;
        }
        .info-grid span { font-weight: 700; color: #0f172a; }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .attendance-table th, .attendance-table td {
            border: 1px solid #000;
            text-align: center;
            padding: 2px;
        }
        .attendance-table th {
            background-color: #f2f2f2;
            font-size: 8px;
        }
        .name-col {
            text-align: left !important;
            padding-left: 5px !important;
            white-space: nowrap;
            width: 180px;
        }
        .sex-header {
            background-color: #eee;
            font-weight: bold;
            text-align: left !important;
            padding-left: 10px !important;
        }
        
        .summary-container {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-top: 20px;
        }
        .summary-table {
            width: 45%;
            border-collapse: collapse;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #000;
            padding: 3px 5px;
            font-size: 9px;
        }
        .summary-table th {
            background-color: #f2f2f2;
        }
        
        .signatures {
            margin-top: 30px;
            display: flex;
            justify-content: space-around;
        }
        .sig-box {
            text-align: center;
            width: 250px;
        }
        .sig-line {
            border-bottom: 1px solid #000;
            margin-bottom: 5px;
            height: 20px;
        }
        
        .print-btn-fixed { 
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: #0038a8; 
            color: white; 
            border: none; 
            padding: 12px 25px; 
            border-radius: 50px; 
            font-weight: 800; 
            cursor: pointer; 
            box-shadow: 0 10px 25px rgba(0,56,168,0.3); 
            z-index: 1000; 
            transition: 0.3s; 
            text-transform: uppercase; 
            letter-spacing: 1px; 
            font-size: 11px;
        }
        .print-btn-fixed:hover { background: #002d86; transform: scale(1.05); }

        @media print {
            body { background: white; padding: 0; display: block; }
            .print-area { width: 100%; padding: 0; box-shadow: none; border-radius: 0; }
            .print-btn-fixed { display: none; }
            @page { size: legal landscape; margin: 0.25in; }
        }

        .status-cell {
            font-weight: bold;
            font-size: 10px;
        }
        .status-P { color: #000; }
        .status-A { color: #000; font-weight: 900; }
        .status-L { color: #000; }
        .status-E { color: #000; }
    </style>
</head>
<body>
    <button class="print-btn-fixed" onclick="window.print()">Print Official SF2</button>

    <div class="print-area">
        <div class="official-header">
            <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="logo">
            <div class="header-center">
                <h2>Republic of the Philippines</h2>
                <h1>Department of Education</h1>
                <p>Region III - Central Luzon</p>
            </div>
            <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="logo">
        </div>

        <div class="title">School Form 2 (SF2) Daily Attendance Report of Learners</div>
        <div class="subtitle">(This replaces Form 1, Form 2 & STS Form 4 - Absenteeism and Dropout Profile)</div>

    <table class="info-grid">
        <tr>
            <td class="label">School ID:</td>
            <td><span><?= htmlspecialchars($school_id) ?></span></td>
            <td class="label">School Year:</td>
            <td><span><?= htmlspecialchars($report['school_year']) ?></span></td>
            <td class="label">Report for the Month of:</td>
            <td><span><?= htmlspecialchars($report['report_month']) ?></span></td>
        </tr>
        <tr>
            <td class="label">Name of School:</td>
            <td colspan="3"><span><?= htmlspecialchars($school_name) ?></span></td>
            <td class="label">Grade & Section:</td>
            <td><span><?= htmlspecialchars($report['grade_level'] . ' - ' . $report['section']) ?></span></td>
        </tr>
    </table>

    <table class="attendance-table">
        <thead>
            <tr>
                <th rowspan="3" style="width: 20px;">No</th>
                <th rowspan="3">LEARNER'S NAME</th>
                <th colspan="<?= count($attendanceDates) ?>">DATES</th>
                <th rowspan="3" style="width: 40px;">ABSENT</th>
                <th rowspan="3" style="width: 40px;">PRESENT</th>
                <th rowspan="3">REMARKS</th>
            </tr>
            <tr>
                <?php foreach ($attendanceDates as $d): ?>
                    <th style="width: 18px; font-size: 7px;"><?= $d['dayInitial'] ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($attendanceDates as $d): ?>
                    <th style="width: 18px; border-top: none;"><?= $d['day'] ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $males = array_filter($students, function($s) { return $s['sex'] === 'M'; });
            $females = array_filter($students, function($s) { return $s['sex'] === 'F'; });
            
            // Render Males
            if (!empty($males)): ?>
                <tr>
                    <td colspan="<?= count($attendanceDates) + 5 ?>" class="sex-header">MALE</td>
                </tr>
                <?php $i = 1; foreach ($males as $s): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="name-col"><?= htmlspecialchars($s['student_name']) ?></td>
                        <?php foreach ($attendanceDates as $d): 
                            $sid = $s['student_id'];
                            $cleanName = strtolower(trim($s['student_name'] ?? ''));
                            $status = ($attendance_map["id_$sid"][$d['dateString']] ?? ($attendance_map["name_$cleanName"][$d['dateString']] ?? '')) ?? '';
                        ?>
                            <td class="status-cell status-<?= $status ?>"><?= $status ?></td>
                        <?php endforeach; ?>
                        <td style="font-weight: bold;"><?= $s['total_absent'] ?></td>
                        <td><?= $s['total_present'] ?></td>
                        <td style="font-size: 8px; text-align: left;"><?= htmlspecialchars($s['remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php 
            // Render Females
            if (!empty($females)): ?>
                <tr>
                    <td colspan="<?= count($attendanceDates) + 5 ?>" class="sex-header">FEMALE</td>
                </tr>
                <?php $i = 1; foreach ($females as $s): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="name-col"><?= htmlspecialchars($s['student_name']) ?></td>
                        <?php foreach ($attendanceDates as $d): 
                            $sid = $s['student_id'];
                            $cleanName = strtolower(trim($s['student_name'] ?? ''));
                            $status = ($attendance_map["id_$sid"][$d['dateString']] ?? ($attendance_map["name_$cleanName"][$d['dateString']] ?? '')) ?? '';
                        ?>
                            <td class="status-cell status-<?= $status ?>"><?= $status ?></td>
                        <?php endforeach; ?>
                        <td style="font-weight: bold;"><?= $s['total_absent'] ?></td>
                        <td><?= $s['total_present'] ?></td>
                        <td style="font-size: 8px; text-align: left;"><?= htmlspecialchars($s['remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="summary-container">
        <table class="summary-table">
            <thead>
                <tr>
                    <th style="text-align: left;">Summary Item</th>
                    <th style="width: 40px;">M</th>
                    <th style="width: 40px;">F</th>
                    <th style="width: 50px;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Enrolment as of 1st Friday of SY</td>
                    <td><?= $summary['enrolment_male_bosy'] ?? 0 ?></td>
                    <td><?= $summary['enrolment_female_bosy'] ?? 0 ?></td>
                    <td><?= $summary['enrolment_total_bosy'] ?? 0 ?></td>
                </tr>
                <tr>
                    <td>Late Enrolment during the month</td>
                    <td><?= $summary['late_enrolment_male'] ?? 0 ?></td>
                    <td><?= $summary['late_enrolment_female'] ?? 0 ?></td>
                    <td><?= $summary['late_enrolment_total'] ?? 0 ?></td>
                </tr>
                <tr>
                    <td>Registered Learners (End of Month)</td>
                    <td><?= $summary['registered_male_eom'] ?? 0 ?></td>
                    <td><?= $summary['registered_female_eom'] ?? 0 ?></td>
                    <td><?= $summary['registered_total_eom'] ?? 0 ?></td>
                </tr>
                <tr>
                    <td>Average Daily Attendance</td>
                    <td><?= number_format($summary['ada_male'] ?? 0, 2) ?></td>
                    <td><?= number_format($summary['ada_female'] ?? 0, 2) ?></td>
                    <td><?= number_format($summary['average_daily_attendance'] ?? 0, 2) ?></td>
                </tr>
                <tr>
                    <td>Percentage of Enrolment for the Month</td>
                    <td><?= number_format($summary['perc_male_enrollment'] ?? 0, 2) ?>%</td>
                    <td><?= number_format($summary['perc_female_enrollment'] ?? 0, 2) ?>%</td>
                    <td><?= number_format($summary['percentage_enrolment'] ?? 0, 2) ?>%</td>
                </tr>
                <tr>
                    <td>Percentage of Attendance for the Month</td>
                    <td><?= number_format($summary['perc_male'] ?? 0, 2) ?>%</td>
                    <td><?= number_format($summary['perc_female'] ?? 0, 2) ?>%</td>
                    <td><?= number_format($summary['percentage_attendance'] ?? 0, 2) ?>%</td>
                </tr>
            </tbody>
        </table>

        <div style="width: 50%; font-size: 9px;">
            <div style="font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 2px;">Attendance Legend & Guidelines:</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div>
                    <strong>P</strong> - Present<br>
                    <strong>A</strong> - Absent<br>
                    <strong>L</strong> - Late<br>
                    <strong>E</strong> - Excused
                </div>
                <div>
                    1. Attendance should be recorded daily.<br>
                    2. Only official codes (P, A, L, E) should be used.<br>
                    3. Summary totals are calculated automatically.
                </div>
            </div>
        </div>
    </div>

    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line" style="font-weight: 900; text-transform: uppercase; font-size: 11px;">
                <?= htmlspecialchars($summary['adviser_name'] ?? ($_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name'])) ?>
            </div>
            <div style="font-weight: bold;">Class Adviser</div>
            <div style="font-size: 8px;">(Signature over Printed Name)</div>
        </div>
        <div class="sig-box">
            <div class="sig-line" style="font-weight: 900; text-transform: uppercase; font-size: 11px;">
                <?php 
                    $principal_name = get_system_setting($pdo, 'principal_name', 'School Head'); 
                    echo strtoupper($principal_name);
                ?>
            </div>
            <div style="font-weight: bold;">School Head</div>
            <div style="font-size: 8px;">(Signature over Printed Name)</div>
        </div>
    </div>

    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                // Optional: window.print();
            }, 1000);
        };
    </script>
</body>
</html>
