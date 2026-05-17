<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$user_id = $_GET['adviser_id'] ?? null;
$sy = $_GET['sy'] ?? '';
$month = $_GET['month'] ?? date('F');

if (!$user_id) {
    echo "Adviser ID is required.";
    exit;
}

// Get teacher info
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE email = (SELECT username FROM users WHERE id = ?)");
$stmt->execute([$user_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    echo "Teacher record not found.";
    exit;
}

// Fetch report
$stmt = $pdo->prepare("SELECT * FROM accomplishment_reports WHERE teacher_id = ? AND school_year = ? ORDER BY created_at DESC");
$stmt->execute([$teacher['id'], $sy]);
$reports = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accomplishment Report View | <?= htmlspecialchars($teacher['first_name']) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .content { padding: 120px 32px 48px; max-width: 900px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .report-header { border-bottom: 2px solid #10b981; padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .report-header h1 { color: #10b981; margin: 0; font-size: 18px; }
        .section-title { font-weight: 700; color: #334155; margin-top: 20px; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; }
        .text-content { background: #f1f5f9; padding: 15px; border-radius: 8px; color: #475569; white-space: pre-wrap; font-size: 14px; min-height: 50px; }
        @media print {
            .sidebar, .header-panel, .no-print { display: none !important; }
            .content { padding: 0; }
            .report-card { border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <?php include $header_file; ?>
    <?php include $sidebar_file; ?>

    <div class="content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <a href="javascript:history.back()" style="text-decoration: none; color: #64748b; font-weight: 600;">← Back to Submissions</a>
            <button onclick="window.print()" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">🖨️ Print All</button>
        </div>

        <?php if (empty($reports)): ?>
            <div class="report-card" style="text-align: center; color: #64748b;">
                <p>No accomplishment reports found for this teacher and SY.</p>
            </div>
        <?php else: foreach ($reports as $report): ?>
            <div class="report-card">
                <div class="report-header">
                    <div>
                        <h1>Accomplishment Report</h1>
                        <p style="margin: 5px 0 0; color: #64748b; font-size: 12px; font-weight: 600;">Teacher: <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-weight: 700; color: #10b981;"><?= $report['month'] ?></span>
                        <p style="margin: 0; font-size: 12px; color: #64748b;">SY: <?= $report['school_year'] ?></p>
                    </div>
                </div>

                <div class="section-title">Activities Done</div>
                <div class="text-content"><?= htmlspecialchars($report['activities'] ?: 'None recorded.') ?></div>

                <div class="section-title">Learning Outcomes / Results</div>
                <div class="text-content"><?= htmlspecialchars($report['outcomes'] ?: 'None recorded.') ?></div>

                <div class="section-title">Issues & Challenges Encountered</div>
                <div class="text-content"><?= htmlspecialchars($report['challenges'] ?: 'None recorded.') ?></div>
                
                <div style="margin-top: 20px; text-align: right; font-size: 11px; color: #94a3b8;">
                    Submitted on: <?= date('M d, Y H:i', strtotime($report['created_at'])) ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</body>
</html>
