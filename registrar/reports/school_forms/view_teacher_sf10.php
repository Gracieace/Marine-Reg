<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

if (!$section || !$sy) {
    echo "Missing section or SY.";
    exit;
}

// Fetch enrolled students
$stmt = $pdo->prepare("
    SELECT e.student_id, e.student_name, e.lrn, r.sex 
    FROM enrollments e 
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
    WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
    ORDER BY e.student_name ASC
");
$stmt->execute([$grade, $section, $sy]);
$students = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF10 Oversight | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .main-content { padding: 120px 32px 48px; max-width: 1000px; margin: auto; }
        .card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0d47a1; padding-bottom: 20px; }
        .header h1 { color: #0d47a1; margin: 0; font-size: 20px; }
        .student-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-top: 20px; }
        .student-card { background: white; border: 1px solid #e2e8f0; padding: 16px; border-radius: 12px; display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; transition: 0.2s; }
        .student-card:hover { border-color: #0d47a1; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .info h3 { margin: 0; font-size: 14px; color: #1e293b; }
        .info p { margin: 2px 0 0; font-size: 11px; color: #64748b; }
        .btn-view { margin-left: auto; color: #0d47a1; font-weight: 600; font-size: 12px; }
        @media print { .sidebar, .header-panel, .no-print { display: none !important; } .main-content { padding: 0; } }
    </style>
</head>
<body>
    <?php include $header_file; ?>
    <?php include $sidebar_file; ?>

    <div class="main-content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <a href="javascript:history.back()" style="text-decoration: none; color: #64748b; font-weight: 600;">← Back to Submissions</a>
        </div>

        <div class="card">
            <div class="header">
                <h1>SF10 - Learner's Permanent Academic Record</h1>
                <p style="margin: 5px 0; color: #64748b; font-size: 13px;">Oversight View: Grade <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?></p>
            </div>

            <?php if (empty($students)): ?>
                <div style="text-align: center; color: #64748b; padding: 40px;">No students found in this section.</div>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($students as $s): ?>
                        <a href="../../../teacher/reports/sf10_print.php?student_id=<?= $s['student_id'] ?>" target="_blank" class="student-card">
                            <div class="avatar"><?= strtoupper(substr($s['student_name'], 0, 1)) ?></div>
                            <div class="info">
                                <h3><?= htmlspecialchars($s['student_name']) ?></h3>
                                <p>LRN: <?= htmlspecialchars($s['lrn']) ?></p>
                            </div>
                            <div class="btn-view">Print SF10 →</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
