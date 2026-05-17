<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$user_id = $_GET['adviser_id'] ?? null;
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

if (!$user_id || !$section || !$sy) {
    echo "Missing parameters.";
    exit;
}

// Fetch TOS reports for this section/SY/adviser
$stmt = $pdo->prepare("
    SELECT tr.*, s.subject_name, s.subject_code 
    FROM tos_reports tr
    JOIN subjects s ON tr.subject_id = s.id
    WHERE tr.section = ? AND tr.school_year = ? AND tr.teacher_id = ?
    ORDER BY tr.period ASC, s.subject_name ASC
");
$stmt->execute([$section, $sy, $user_id]);
$reports = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TOS View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .content { padding: 120px 32px 48px; max-width: 1200px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 40px; }
        .report-header { border-bottom: 2px solid #3b82f6; padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
        .report-header h1 { color: #1e40af; margin: 0; font-size: 18px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 11px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        .objective-col { text-align: left; min-width: 250px; font-weight: 500; }
        .summary-stats { display: flex; gap: 30px; margin-top: 20px; font-size: 12px; font-weight: 600; background: #f8fafc; padding: 10px; border-radius: 6px; }
        @media print {
            .sidebar, .header-panel, .no-print { display: none !important; }
            .content { padding: 0; }
            .report-card { border: none; box-shadow: none; margin-bottom: 100px; page-break-after: always; }
        }
    </style>
</head>
<body>
    <?php include $header_file; ?>
    <?php include $sidebar_file; ?>

    <div class="content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <a href="javascript:history.back()" style="text-decoration: none; color: #64748b; font-weight: 600;">← Back to Submissions</a>
            <button onclick="window.print()" style="background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">🖨️ Print All TOS</button>
        </div>

        <?php if (empty($reports)): ?>
            <div class="report-card" style="text-align: center; color: #64748b;">
                <p>No Table of Specification (TOS) reports found for this section and SY.</p>
            </div>
        <?php else: foreach ($reports as $tos): 
            $stmt = $pdo->prepare("SELECT * FROM tos_items WHERE tos_id = ?");
            $stmt->execute([$tos['id']]);
            $items = $stmt->fetchAll();
        ?>
            <div class="report-card">
                <div class="report-header">
                    <div>
                        <h1>Table of Specification (TOS)</h1>
                        <p style="margin: 5px 0 0; color: #64748b; font-size: 13px; font-weight: 700;">
                            <?= htmlspecialchars($tos['subject_name']) ?> (<?= htmlspecialchars($tos['subject_code']) ?>)
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-weight: 800; color: #3b82f6; font-size: 14px;"><?= $tos['period'] ?></span>
                        <p style="margin: 0; font-size: 11px; color: #64748b;">Section: <?= htmlspecialchars($tos['section']) ?> | SY: <?= $tos['school_year'] ?></p>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 40px;">No.</th>
                            <th rowspan="2" class="objective-col">Learning Objectives / Competencies</th>
                            <th rowspan="2">Days Taught</th>
                            <th rowspan="2">No. of Items</th>
                            <th colspan="6">Cognitive Process Dimensions (Item Placement)</th>
                        </tr>
                        <tr>
                            <th style="width: 40px;">Rem.</th>
                            <th style="width: 40px;">Und.</th>
                            <th style="width: 40px;">App.</th>
                            <th style="width: 40px;">Ana.</th>
                            <th style="width: 40px;">Eva.</th>
                            <th style="width: 40px;">Cre.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $count = 1; foreach ($items as $item): ?>
                            <tr>
                                <td><?= $count++ ?></td>
                                <td class="objective-col"><?= htmlspecialchars($item['objective']) ?></td>
                                <td><?= $item['days_taught'] ?></td>
                                <td><?= $item['num_items'] ?></td>
                                <td><?= htmlspecialchars($item['remembering']) ?: '-' ?></td>
                                <td><?= htmlspecialchars($item['understanding']) ?: '-' ?></td>
                                <td><?= htmlspecialchars($item['applying']) ?: '-' ?></td>
                                <td><?= htmlspecialchars($item['analyzing']) ?: '-' ?></td>
                                <td><?= htmlspecialchars($item['evaluating']) ?: '-' ?></td>
                                <td><?= htmlspecialchars($item['creating']) ?: '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="summary-stats">
                    <span>Total Days: <?= $tos['total_days'] ?></span>
                    <span>Total Items: <?= $tos['total_items'] ?></span>
                    <span style="margin-left: auto; font-style: italic; font-weight: 500;">Submitted on: <?= date('M d, Y', strtotime($tos['created_at'])) ?></span>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</body>
</html>
