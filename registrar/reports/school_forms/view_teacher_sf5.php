<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

// Fetch the SF5 report metadata
$stmt = $pdo->prepare("SELECT * FROM sf5_reports WHERE grade_level = ? AND section = ? AND school_year = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$grade, $section, $sy]);
$report = $stmt->fetch();

if (!$report) {
    echo "No teacher-submitted SF5 report found for this section.";
    exit;
}

// Fetch students
$stmt = $pdo->prepare("SELECT * FROM sf5_students WHERE sf5_report_id = ? ORDER BY sex DESC, student_name ASC");
$stmt->execute([$report['id']]);
$students = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher SF5 View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .main-content { padding: 120px 32px 48px; max-width: 1200px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0d47a1; padding-bottom: 20px; }
        .report-header h1 { color: #0d47a1; margin: 0; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 10px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        .name-col { text-align: left; font-weight: 600; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
        .badge-PROMOTED { background: #dcfce7; color: #15803d; }
        .badge-RETAINED { background: #fee2e2; color: #991b1b; }
        .badge-CONDITIONAL { background: #fef9c3; color: #854d0e; }
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
                        <h1 style="margin: 0;">School Form 5 (SF5) Report on Promotion</h1>
                        <p style="margin: 5px 0; color: #64748b;">Teacher's Submitted Snapshot</p>
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

            <table id="sf5Table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>LRN</th>
                        <th class="name-col">Student Name</th>
                        <th>Average</th>
                        <th>Action Taken</th>
                        <th>Learning Areas Not Met</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $count = 1; foreach ($students as $s): ?>
                        <tr>
                            <td><?= $count++ ?></td>
                            <td><?= htmlspecialchars($s['lrn']) ?></td>
                            <td class="name-col"><?= htmlspecialchars($s['student_name']) ?></td>
                            <td style="font-weight: 700;"><?= number_format($s['general_average'], 2) ?></td>
                            <td><span class="badge badge-<?= $s['action_taken'] ?>"><?= $s['action_taken'] ?></span></td>
                            <td><?= htmlspecialchars($s['learning_areas_not_met']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf5Table')) {
                initReportSearch('reportSearch', 'sf5Table');
            }
        });
    </script>
</body>
</html>
