<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$user_id = $_GET['adviser_id'] ?? null;
$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$sy = $_GET['sy'] ?? '';

if (!$section || !$sy) {
    echo "Missing section or SY.";
    exit;
}

// Fetch subjects for this grade level
$stmt = $pdo->prepare("SELECT id, subject_name FROM curriculum WHERE grade_level = ? ORDER BY subject_name");
$stmt->execute([$grade]);
$subjects = $stmt->fetchAll();

// Fetch enrolled students
$stmt = $pdo->prepare("
    SELECT e.student_id, e.student_name 
    FROM enrollments e 
    WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
    ORDER BY e.student_name ASC
");
$stmt->execute([$grade, $section, $sy]);
$students = $stmt->fetchAll();

// Fetch grades
$stmt = $pdo->prepare("
    SELECT * FROM sf9_grades 
    WHERE school_year = ? AND student_id IN (
        SELECT student_id FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?
    )
");
$stmt->execute([$sy, $grade, $section, $sy]);
$raw_grades = $stmt->fetchAll();

$grade_map = [];
foreach ($raw_grades as $g) {
    $grade_map[$g['student_id']][$g['subject_id']] = $g;
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grading Sheet View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .main-content { padding: 120px 32px 48px; max-width: 1600px; margin: auto; }
        .report-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #6366f1; padding-bottom: 20px; }
        .report-header h1 { color: #1e40af; margin: 0; font-size: 20px; }
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: center; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        .student-name { text-align: left; min-width: 180px; font-weight: 600; background: #fcfcfc; }
        .grade-val { font-weight: 700; }
        .failed { color: #dc2626; }
        .passed { color: #16a34a; }
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
            <button onclick="window.print()" style="background: #1e40af; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">🖨️ Print Grading Sheet</button>
        </div>

        <div class="report-card">
            <div class="report-header">
                <h1>Class Grading Sheet (E-Class Record Snapshot)</h1>
                <p style="margin: 5px 0; color: #64748b; font-weight: 600;">Grade: <?= htmlspecialchars($grade) ?> | Section: <?= htmlspecialchars($section) ?></p>
                <div style="font-size: 13px; color: #1e40af; font-weight: 700; margin-top: 10px;">SY: <?= htmlspecialchars($sy) ?></div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">#</th>
                            <th rowspan="2">Student Name</th>
                            <?php foreach ($subjects as $sub): ?>
                                <th colspan="4"><?= htmlspecialchars($sub['subject_name']) ?></th>
                                <th rowspan="2">Final</th>
                            <?php endforeach; ?>
                            <th rowspan="2">GWA</th>
                        </tr>
                        <tr>
                            <?php foreach ($subjects as $sub): ?>
                                <th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $count = 1; foreach ($students as $s): 
                            $student_gwa_sum = 0;
                            $student_subject_count = 0;
                        ?>
                            <tr>
                                <td><?= $count++ ?></td>
                                <td class="student-name"><?= htmlspecialchars($s['student_name']) ?></td>
                                <?php foreach ($subjects as $sub): 
                                    $g = $grade_map[$s['student_id']][$sub['id']] ?? null;
                                    if ($g && $g['final_grade']) {
                                        $student_gwa_sum += $g['final_grade'];
                                        $student_subject_count++;
                                    }
                                    $remarks_class = ($g && $g['final_grade'] < 75) ? 'failed' : 'passed';
                                ?>
                                    <td class="grade-val"><?= $g ? $g['q1'] : '-' ?></td>
                                    <td class="grade-val"><?= $g ? $g['q2'] : '-' ?></td>
                                    <td class="grade-val"><?= $g ? $g['q3'] : '-' ?></td>
                                    <td class="grade-val"><?= $g ? $g['q4'] : '-' ?></td>
                                    <td class="grade-val <?= $remarks_class ?>" style="background: #f8fafc;"><?= $g ? number_format($g['final_grade'], 1) : '-' ?></td>
                                <?php endforeach; ?>
                                <td style="font-weight: 800; background: #eff6ff; color: #1e40af;">
                                    <?= $student_subject_count > 0 ? number_format($student_gwa_sum / $student_subject_count, 2) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 30px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px; text-align: right;">
                This document is a digital snapshot of the teacher's grading records.
            </div>
        </div>
    </div>
</body>
</html>
