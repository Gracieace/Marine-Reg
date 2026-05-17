<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

// Fetch the SF1 report metadata
$stmt = $pdo->prepare("SELECT * FROM sf1_reports WHERE grade_level = ? AND section = ? AND school_year = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$grade, $section, $sy]);
$report = $stmt->fetch();

if (!$report) {
    echo "No teacher-submitted SF1 report found for this section.";
    exit;
}

// Fetch the student records for this report
$stmt = $pdo->prepare("SELECT * FROM sf1_student_records WHERE sf1_report_id = ? ORDER BY last_name, first_name");
$stmt->execute([$report['id']]);
$students = $stmt->fetchAll();

// Fetch summary
$stmt = $pdo->prepare("SELECT * FROM sf1_summary WHERE sf1_report_id = ?");
$stmt->execute([$report['id']]);
$summary = $stmt->fetch();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher SF1 View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .main-content { padding: 120px 32px 48px; max-width: 1400px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0d47a1; padding-bottom: 20px; }
        .report-header h1 { color: #0d47a1; margin: 0; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        .male-row { background: #eff6ff; }
        .female-row { background: #fff1f2; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-m { background: #dbeafe; color: #1e40af; }
        .badge-f { background: #ffe4e6; color: #9f1239; }
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
                        <h1 style="margin: 0;">School Form 1 (SF1) School Register</h1>
                        <p style="margin: 5px 0; color: #64748b;">Teacher's Submitted Snapshot</p>
                    </div>
                    <input type="text" id="reportSearch" placeholder="🔍 Search learner..." 
                           style="padding: 10px 15px; width: 300px; border: 1px solid #e2e8f0; border-radius: 20px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                </div>
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 10px; font-weight: 600; font-size: 14px;">
                    <span>Grade: <?= htmlspecialchars($grade) ?></span>
                    <span>Section: <?= htmlspecialchars($section) ?></span>
                    <span>SY: <?= htmlspecialchars($sy) ?></span>
                </div>
            </div>

            <table id="sf1Table">
                <thead>
                    <tr>
                        <th>LRN</th>
                        <th>Name (Last, First, Middle)</th>
                        <th>Sex</th>
                        <th>Birth Date</th>
                        <th>Age</th>
                        <th>Mother Tongue</th>
                        <th>IP / Ethnicity</th>
                        <th>Religion</th>
                        <th>Address</th>
                        <th>Father's Name</th>
                        <th>Mother's Name</th>
                        <th>Guardian</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr class="<?= $s['sex'] == 'M' ? 'male-row' : 'female-row' ?>">
                            <td><?= htmlspecialchars($s['lrn']) ?></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' ' . $s['middle_name']) ?></td>
                            <td><span class="badge <?= $s['sex'] == 'M' ? 'badge-m' : 'badge-f' ?>"><?= $s['sex'] ?></span></td>
                            <td><?= $s['birth_date'] ?></td>
                            <td><?= $s['age_as_of_oct31'] ?></td>
                            <td><?= htmlspecialchars($s['mother_tongue']) ?></td>
                            <td><?= htmlspecialchars($s['ip_ethnicity']) ?></td>
                            <td><?= htmlspecialchars($s['religion']) ?></td>
                            <td><?= htmlspecialchars($s['house_no_street'] . ', ' . $s['barangay'] . ', ' . $s['municipality_city']) ?></td>
                            <td><?= htmlspecialchars($s['father_first_name'] . ' ' . $s['father_last_name']) ?></td>
                            <td><?= htmlspecialchars($s['mother_first_name'] . ' ' . $s['mother_last_name']) ?></td>
                            <td><?= htmlspecialchars($s['guardian_name']) ?></td>
                            <td><?= htmlspecialchars($s['remarks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 40px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px;">
                <div>
                    <h3 style="font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Summary Statistics</h3>
                    <div style="display: flex; gap: 20px; margin-top: 10px;">
                        <div><strong>Male:</strong> <?= $summary['total_male'] ?? 0 ?></div>
                        <div><strong>Female:</strong> <?= $summary['total_female'] ?? 0 ?></div>
                        <div><strong>Total:</strong> <?= $summary['total_combined'] ?? 0 ?></div>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="margin-bottom: 40px;">
                        <div style="font-weight: 700; border-bottom: 1px solid #000; display: inline-block; min-width: 200px;">
                            <?= htmlspecialchars($summary['prepared_by_name'] ?? 'Class Adviser') ?>
                        </div>
                        <div style="font-size: 12px; color: #64748b;">Prepared By (Class Adviser)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf1Table')) {
                initReportSearch('reportSearch', 'sf1Table');
            }
        });
    </script>
</body>
</html>
