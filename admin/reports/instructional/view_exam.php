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

// Fetch exams
$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name 
    FROM exam_papers e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE e.teacher_id = ? AND e.grade_level = ? AND e.school_year = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$user_id, $grade, $sy]);
$exams = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar_side_panel.php' : '../../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Examinations View | <?= htmlspecialchars($grade) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }
        .content { padding: 120px 32px 48px; max-width: 1000px; margin: auto; }
        .exam-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 40px; }
        .exam-header { border-bottom: 2px solid #6366f1; padding-bottom: 15px; margin-bottom: 20px; }
        .exam-header h1 { color: #4338ca; margin: 0; font-size: 20px; text-transform: uppercase; }
        .instructions { background: #fefce8; border-left: 4px solid #eab308; padding: 15px; margin-bottom: 25px; font-style: italic; font-size: 14px; color: #854d0e; }
        .question { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px dashed #e2e8f0; }
        .question-text { font-weight: 700; color: #1e293b; margin-bottom: 10px; font-size: 15px; }
        .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-left: 20px; }
        .option { font-size: 14px; color: #475569; }
        .correct { color: #15803d; font-weight: 700; }
        @media print {
            .sidebar, .header-panel, .no-print { display: none !important; }
            .content { padding: 0; }
            .exam-card { border: none; box-shadow: none; margin-bottom: 60px; page-break-after: always; }
        }
    </style>
</head>
<body>
    <?php include $header_file; ?>
    <?php include $sidebar_file; ?>

    <div class="content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <a href="javascript:history.back()" style="text-decoration: none; color: #64748b; font-weight: 600;">← Back to Submissions</a>
            <button onclick="window.print()" style="background: #6366f1; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">🖨️ Print Periodic Tests</button>
        </div>

        <?php if (empty($exams)): ?>
            <div class="exam-card" style="text-align: center; color: #64748b;">
                <p>No periodic tests found for this grade and SY.</p>
            </div>
        <?php else: foreach ($exams as $exam): 
            $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ?");
            $stmt->execute([$exam['id']]);
            $questions = $stmt->fetchAll();
        ?>
            <div class="exam-card">
                <div class="exam-header">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h1><?= htmlspecialchars($exam['period']) ?> Periodic Test in <?= htmlspecialchars($exam['subject_name']) ?></h1>
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">SY: <?= $exam['school_year'] ?></span>
                    </div>
                    <p style="margin: 5px 0 0; color: #64748b; font-size: 14px; font-weight: 700;"><?= htmlspecialchars($exam['title']) ?></p>
                </div>

                <?php if ($exam['instructions']): ?>
                    <div class="instructions">
                        <strong>Instructions:</strong> <?= nl2br(htmlspecialchars($exam['instructions'])) ?>
                    </div>
                <?php endif; ?>

                <?php $qnum = 1; foreach ($questions as $q): ?>
                    <div class="question">
                        <div class="question-text"><?= $qnum++ ?>. <?= htmlspecialchars($q['question_text']) ?></div>
                        <div class="options-grid">
                            <div class="option <?= $q['correct_answer'] == 'A' ? 'correct' : '' ?>">A. <?= htmlspecialchars($q['option_a']) ?></div>
                            <div class="option <?= $q['correct_answer'] == 'B' ? 'correct' : '' ?>">B. <?= htmlspecialchars($q['option_b']) ?></div>
                            <div class="option <?= $q['correct_answer'] == 'C' ? 'correct' : '' ?>">C. <?= htmlspecialchars($q['option_c']) ?></div>
                            <div class="option <?= $q['correct_answer'] == 'D' ? 'correct' : '' ?>">D. <?= htmlspecialchars($q['option_d']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top: 30px; text-align: right; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 10px;">
                    Digitally Generated: <?= date('M d, Y', strtotime($exam['created_at'])) ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</body>
</html>
