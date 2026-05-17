<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

// Fetch the SF3 report metadata
$stmt = $pdo->prepare("SELECT * FROM sf3_reports WHERE grade_level = ? AND section = ? AND school_year = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$grade, $section, $sy]);
$report = $stmt->fetch();

if (!$report) {
    echo "No teacher-submitted SF3 report found for this section.";
    exit;
}

// Fetch inventory
$stmt = $pdo->prepare("SELECT * FROM sf3_books_inventory WHERE sf3_report_id = ? ORDER BY id");
$stmt->execute([$report['id']]);
$inventory = $stmt->fetchAll();

// Fetch students and their book records
$stmt = $pdo->prepare("
    SELECT e.student_id as lrn, e.student_name, r.sex
    FROM enrollments e
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
    WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ?
    ORDER BY r.sex DESC, e.student_name ASC
");
$stmt->execute([$sy, $grade, $section]);
$students = $stmt->fetchAll();

// Get book records
$stmt = $pdo->prepare("SELECT * FROM sf3_student_books WHERE sf3_report_id = ?");
$stmt->execute([$report['id']]);
$book_records = $stmt->fetchAll();

$books_by_lrn = [];
foreach ($book_records as $br) {
    $books_by_lrn[$br['student_lrn']][$br['inventory_id']] = $br;
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher SF3 View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .main-content { padding: 120px 32px 48px; max-width: 1600px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0d47a1; padding-bottom: 20px; }
        .report-header h1 { color: #0d47a1; margin: 0; font-size: 20px; }
        .table-responsive { overflow-x: auto; margin-top: 20px; border: 1px solid #cbd5e1; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        .student-name { text-align: left; min-width: 150px; font-weight: 600; }
        .status-badge { padding: 2px 4px; border-radius: 3px; font-size: 8px; font-weight: 700; }
        .status-Good { background: #dcfce7; color: #166534; }
        .status-Lost { background: #fee2e2; color: #991b1b; }
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
                        <h1 style="margin: 0;">School Form 3 (SF3) Books Issued and Returned</h1>
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

            <div class="table-responsive">
                <table id="sf3Table">
                    <thead>
                        <tr>
                            <th rowspan="2">No.</th>
                            <th rowspan="2">NAME (Last Name, First Name)</th>
                            <?php foreach ($inventory as $book): ?>
                                <th colspan="2"><?= htmlspecialchars($book['subject']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach ($inventory as $book): ?>
                                <th>Issued</th>
                                <th>Ret.</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $count = 1; foreach ($students as $s): ?>
                            <tr>
                                <td><?= $count++ ?></td>
                                <td class="student-name"><?= htmlspecialchars($s['student_name']) ?></td>
                                <?php foreach ($inventory as $book): 
                                    $record = $books_by_lrn[$s['lrn']][$book['id']] ?? null;
                                    ?>
                                    <td><?= $record && $record['date_issued'] ? '✓' : '-' ?></td>
                                    <td>
                                        <?php if ($record && $record['date_returned']): ?>
                                            <span class="status-badge status-<?= $record['condition_returned'] ?>"><?= $record['condition_returned'] ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px;">
                <div>
                    <h3 style="font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Inventory Summary</h3>
                    <table style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Subject/Title</th>
                                <th>Received</th>
                                <th>Good</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $inv): ?>
                                <tr>
                                    <td style="text-align: left;"><?= htmlspecialchars($inv['subject'] . ' - ' . $inv['title']) ?></td>
                                    <td><?= $inv['total_copies_received'] ?></td>
                                    <td><?= $inv['copies_in_good_condition'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align: right; padding-top: 40px;">
                    <div style="font-weight: 700; border-bottom: 1px solid #000; display: inline-block; min-width: 200px; text-align: center;">
                        <?= htmlspecialchars($report['teacher_id']) // Not good, should be name ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf3Table')) {
                initReportSearch('reportSearch', 'sf3Table');
            }
        });
    </script>
</body>
</html>
