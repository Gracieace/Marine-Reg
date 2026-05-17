<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$user_id = $_GET['adviser_id'] ?? null;
$grade = $_GET['grade'] ?? '';
$sy = $_GET['sy'] ?? '';

if (!$user_id || !$grade || !$sy) {
    echo "Missing parameters.";
    exit;
}

// Fetch periodic exams for this teacher/grade/SY
$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name, 
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as item_count,
           (SELECT COUNT(DISTINCT student_id) FROM exam_scores WHERE exam_id = e.id) as students_tested,
           (SELECT SUM(score) FROM exam_scores WHERE exam_id = e.id) as total_raw_score
    FROM exam_papers e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE e.teacher_id = ? AND e.grade_level = ? AND e.school_year = ?
    ORDER BY e.period ASC
");
$stmt->execute([$user_id, $grade, $sy]);
$mps_data = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MPS View | <?= htmlspecialchars($grade) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .content { padding: 120px 32px 48px; max-width: 1000px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .report-header { border-bottom: 2px solid #8b5cf6; padding-bottom: 15px; margin-bottom: 20px; }
        .report-header h1 { color: #6d28d9; margin: 0; font-size: 20px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #cbd5e1; padding: 12px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; font-size: 13px; }
        .mps-value { font-size: 18px; font-weight: 800; color: #6d28d9; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; }
        .badge-high { background: #dcfce7; color: #15803d; }
        .badge-mid { background: #fef9c3; color: #854d0e; }
        .badge-low { background: #fee2e2; color: #991b1b; }
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
            <button onclick="window.print()" style="background: #8b5cf6; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">🖨️ Print MPS Report</button>
        </div>

        <div class="report-card">
            <div class="report-header">
                <h1>Mean Percentage Score (MPS) Summary</h1>
                <p style="margin: 5px 0 0; color: #64748b; font-size: 14px; font-weight: 600;">Grade: <?= htmlspecialchars($grade) ?> | SY: <?= htmlspecialchars($sy) ?></p>
            </div>

            <?php if (empty($mps_data)): ?>
                <div style="text-align: center; color: #64748b; padding: 40px;">
                    No exam data found to calculate MPS.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Period</th>
                            <th>Items</th>
                            <th>No. of Examinees</th>
                            <th>Total Raw Score</th>
                            <th>Mean Score</th>
                            <th>MPS (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mps_data as $row): 
                            $mean = $row['students_tested'] > 0 ? $row['total_raw_score'] / $row['students_tested'] : 0;
                            $mps = ($row['students_tested'] > 0 && $row['item_count'] > 0) ? ($mean / $row['item_count']) * 100 : 0;
                            $badge_class = ($mps >= 75) ? 'badge-high' : ($mps >= 50 ? 'badge-mid' : 'badge-low');
                        ?>
                            <tr>
                                <td style="text-align: left; font-weight: 600;"><?= htmlspecialchars($row['subject_name']) ?></td>
                                <td><?= htmlspecialchars($row['period']) ?></td>
                                <td><?= $row['item_count'] ?></td>
                                <td><?= $row['students_tested'] ?></td>
                                <td><?= $row['total_raw_score'] ?></td>
                                <td><?= number_format($mean, 2) ?></td>
                                <td>
                                    <span class="mps-value"><?= number_format($mps, 2) ?>%</span>
                                    <br>
                                    <span class="badge <?= $badge_class ?>">
                                        <?= $mps >= 75 ? 'Mastered' : ($mps >= 50 ? 'Near Mastery' : 'Low Mastery') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top: 40px; border-top: 1px solid #f1f5f9; padding-top: 20px; display: flex; justify-content: space-between; font-size: 12px; color: #64748b;">
                <div>* MPS = (Total Raw Score / (Examinees * Items)) * 100</div>
                <div>Generated: <?= date('M d, Y') ?></div>
            </div>
        </div>
    </div>
</body>
</html>
