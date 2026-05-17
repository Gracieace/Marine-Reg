<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

// Get SF2 report details (latest for this section/SY)
$stmt = $pdo->prepare("
    SELECT r.*, s.*
    FROM sf2_reports r
    LEFT JOIN sf2_monthly_summary s ON r.id = s.sf2_report_id
    WHERE r.grade_level = ? AND r.section = ? AND r.school_year = ?
    ORDER BY r.created_at DESC LIMIT 1
");
$stmt->execute([$grade, $section, $sy]);
$report = $stmt->fetch();

if (!$report) {
    echo "No teacher-submitted SF2 report found for this section.";
    exit;
}

$report_id = $report['sf2_report_id'] ?: $report['id'];

// Get student records
$stmt = $pdo->prepare("
    SELECT * FROM sf2_student_records 
    WHERE sf2_report_id = ? 
    ORDER BY sex DESC, student_name ASC
");
$stmt->execute([$report_id]);
$students = $stmt->fetchAll();

// Get unique dates for the month from attendance records
$stmt = $pdo->prepare("
    SELECT DISTINCT attendance_date FROM sf2_daily_attendance 
    WHERE sf2_report_id = ? 
    ORDER BY attendance_date ASC
");
$stmt->execute([$report_id]);
$dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Group attendance by student and date
$stmt = $pdo->prepare("
    SELECT * FROM sf2_daily_attendance 
    WHERE sf2_report_id = ?
");
$stmt->execute([$report_id]);
$attendance_records = $stmt->fetchAll();

$attendance_by_student = [];
foreach ($attendance_records as $record) {
    $attendance_by_student[$record['student_id']][$record['attendance_date']] = $record['attendance_status'];
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher SF2 View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .main-content { padding: 120px 32px 48px; max-width: 1600px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0d47a1; padding-bottom: 20px; }
        .report-header h1 { color: #0d47a1; margin: 0; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        .student-name { text-align: left; min-width: 150px; padding-left: 8px; font-weight: 600; }
        .present { color: #15803d; font-weight: bold; }
        .absent { color: #b91c1c; font-weight: bold; }
        .tardy { color: #b45309; font-weight: bold; }
        .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-top: 20px; font-size: 12px; }
        @media print {
            .sidebar, .header-panel, .no-print { display: none !important; }
            .main-content { padding: 0; }
            .report-card { border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <?php include $header_file; ?>
    <?php include $sidebar_file; ?>

    <div class="main-content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <a href="javascript:history.back()" style="text-decoration: none; color: #64748b; font-weight: 600;">← Back to Submissions</a>
            <button onclick="window.print()" style="background: #0d47a1; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">🖨️ Print Report</button>
        </div>

        <div class="report-card">
            <div class="report-header">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 15px;">
                    <div style="text-align: left;">
                        <h1 style="margin: 0;">School Form 2 (SF2) Daily Attendance Report</h1>
                        <p style="margin: 5px 0; color: #64748b;">Teacher's Submitted Snapshot (Month: <?= htmlspecialchars($report['report_month']) ?>)</p>
                    </div>
                    <input type="text" id="reportSearch" placeholder="🔍 Search learner..." 
                           style="padding: 10px 15px; width: 300px; border: 1px solid #e2e8f0; border-radius: 20px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                </div>
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 10px; font-weight: 600; font-size: 13px;">
                    <span>Grade: <?= htmlspecialchars($grade) ?></span>
                    <span>Section: <?= htmlspecialchars($section) ?></span>
                    <span>SY: <?= htmlspecialchars($sy) ?></span>
                </div>
            </div>

            <table id="sf2Table">
                <thead>
                    <tr>
                        <th rowspan="2">No.</th>
                        <th rowspan="2">NAME (Last Name, First Name)</th>
                        <?php foreach ($dates as $date): ?>
                            <th><?= date('d', strtotime($date)) ?></th>
                        <?php endforeach; ?>
                        <th colspan="2">Total</th>
                        <th rowspan="2">REMARKS</th>
                    </tr>
                    <tr>
                        <?php foreach ($dates as $date): ?>
                            <th><?= substr(date('D', strtotime($date)), 0, 1) ?></th>
                        <?php endforeach; ?>
                        <th>ABS</th>
                        <th>PRE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $count = 1; foreach ($students as $s): ?>
                        <tr>
                            <td><?= $count++ ?></td>
                            <td class="student-name"><?= htmlspecialchars($s['student_name']) ?></td>
                            <?php foreach ($dates as $date): 
                                $status = $attendance_by_student[$s['student_id']][$date] ?? '';
                                ?>
                                <td>
                                    <?php if ($status === 'present'): ?>
                                        <span class="present">/</span>
                                    <?php elseif ($status === 'absent'): ?>
                                        <span class="absent">x</span>
                                    <?php elseif (strpos($status, 'tardy') !== false): ?>
                                        <span class="tardy">t</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><?= $s['total_absent'] ?></td>
                            <td><?= $s['total_present'] ?></td>
                            <td><?= htmlspecialchars($s['remarks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="summary-box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ddd; padding-bottom:5px;">Monthly Summary Statistics</h3>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <div>
                        <p><strong>Registered Learners:</strong> <?= $report['registered_total_eom'] ?></p>
                        <p><strong>Avg Daily Attendance:</strong> <?= $report['average_daily_attendance'] ?></p>
                    </div>
                    <div>
                        <p><strong>Male Total:</strong> <?= $report['registered_male_eom'] ?></p>
                        <p><strong>Female Total:</strong> <?= $report['registered_female_eom'] ?></p>
                    </div>
                    <div>
                        <p><strong>% of Attendance:</strong> <?= $report['percentage_attendance'] ?>%</p>
                        <p><strong>Days of Classes:</strong> <?= $report['days_of_classes'] ?></p>
                    </div>
                </div>
                <div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; text-align: right;">
                    <p style="margin: 0;"><strong>Prepared by:</strong> <?= htmlspecialchars($report['adviser_name'] ?: 'Class Adviser') ?></p>
                    <p style="margin: 5px 0 0; font-size: 11px; color: #64748b;">Digitally Attested: <?= $report['updated_at'] ?></p>
                </div>
            </div>
        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf2Table')) {
                initReportSearch('reportSearch', 'sf2Table');
            }
        });
    </script>
</body>
</html>
