<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user_id = $_SESSION['user']['id'];

// 1. Initialize Exam Scores Table
$pdo->exec("CREATE TABLE IF NOT EXISTS exam_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    score INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_score (exam_id, student_id),
    FOREIGN KEY (exam_id) REFERENCES exam_papers(id) ON DELETE CASCADE
)");

// --- 1. Parameter Handling (Dashboard Sync) ---
$target_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$target_section = $_GET['section'] ?? '';
$target_sy = $_GET['sy'] ?? $_GET['school_year'] ?? '2024-2025';
$target_sub = $_GET['subject_id'] ?? '';

// Fetch Exams for teacher - Filtered by dashboard context if available
$exam_sql = "SELECT e.*, s.subject_name 
             FROM exam_papers e 
             JOIN subjects s ON e.subject_id = s.id 
             WHERE e.teacher_id = ?";
$exam_params = [$user_id];

if ($target_grade) { $exam_sql .= " AND e.grade_level = ?"; $exam_params[] = $target_grade; }
if ($target_sub)   { $exam_sql .= " AND e.subject_id = ?";   $exam_params[] = $target_sub; }
if ($target_sy)    { $exam_sql .= " AND e.school_year = ?";  $exam_params[] = $target_sy; }

$exam_sql .= " ORDER BY e.created_at DESC";
$stmt = $pdo->prepare($exam_sql);
$stmt->execute($exam_params);
$exams = $stmt->fetchAll();

$selected_exam_id = $_GET['exam_id'] ?? ($exams[0]['id'] ?? '');

// 2. Handle Score Saving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_scores') {
    $scores = $_POST['scores'] ?? [];
    foreach ($scores as $sid => $val) {
        $stmt = $pdo->prepare("INSERT INTO exam_scores (exam_id, student_id, score) VALUES (?, ?, ?) 
                               ON DUPLICATE KEY UPDATE score=VALUES(score)");
        $stmt->execute([$selected_exam_id, $sid, $val]);
    }
    header("Location: ?exam_id=$selected_exam_id&success=1");
    exit;
}

// 3. Fetch Data for Report
$exam_meta = null;
$student_scores = [];
$mps = 0;
$total_students = 0;
if ($selected_exam_id) {
    $stmt = $pdo->prepare("SELECT e.*, s.subject_name, (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as item_count 
                           FROM exam_papers e JOIN subjects s ON e.subject_id = s.id WHERE e.id = ?");
    $stmt->execute([$selected_exam_id]);
    $exam_meta = $stmt->fetch();

    // Fetch students in that grade/section (Standardized to handle dashboard section param)
    $report_section = $target_section ?: ($_GET['section'] ?? '');
    
    if ($report_section) {
        $stmt = $pdo->prepare("SELECT e.student_id, e.student_name FROM enrollments e WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ? ORDER BY e.student_name ASC");
        $stmt->execute([$exam_meta['grade_level'], $report_section, $exam_meta['school_year']]);
    } else {
        $stmt = $pdo->prepare("SELECT e.student_id, e.student_name FROM enrollments e WHERE e.grade_level = ? AND e.school_year = ? ORDER BY e.student_name ASC");
        $stmt->execute([$exam_meta['grade_level'], $exam_meta['school_year']]);
    }
    $student_list = $stmt->fetchAll();

    // Get existing scores
    $stmt = $pdo->prepare("SELECT * FROM exam_scores WHERE exam_id = ?");
    $stmt->execute([$selected_exam_id]);
    $existing = $stmt->fetchAll();
    $score_map = [];
    foreach ($existing as $ex)
        $score_map[$ex['student_id']] = $ex['score'];

    $total_score_sum = 0;
    $scored_count = 0;
    foreach ($student_list as $sl) {
        $s = $score_map[$sl['student_id']] ?? null;
        $student_scores[] = ['id' => $sl['student_id'], 'name' => $sl['student_name'], 'score' => $s];
        if ($s !== null) {
            $total_score_sum += $s;
            $scored_count++;
        }
    }

    if ($scored_count > 0 && ($exam_meta['item_count'] > 0)) {
        $mps = ($total_score_sum / ($scored_count * $exam_meta['item_count'])) * 100;
    }
    $total_students = $scored_count;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>MPS - Mean Percentage Score</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #8b5cf6;
            --bg: #f8fafc;
            --border: #e2e8f0;
        }

        body {
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            margin: 0;
        }

        .content {
            padding: 120px 32px 48px;
            max-width: 1200px;
            margin: auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-box {
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            color: white;
        }

        .table-wrapper {
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid var(--border);
            padding: 12px;
            text-align: left;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
        }

        .score-input {
            width: 80px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            text-align: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: var(--primary);
            color: white;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="content main-content">
        <div class="card" style="display:flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin:0; font-size:24px; color:#1e293b;">Mean Percentage Score (MPS)</h1>
                <p style="color:#64748b;">Analyze academic performance across your periodic exams.</p>
            </div>
             <select class="form-control" style="width:250px;" onchange="const url = new URL(window.location); url.searchParams.set('exam_id', this.value); window.location.href = url.href;">
                 <option value="">Select Exam Paper</option>
                 <?php foreach ($exams as $e)
                     echo "<option value='" . $e['id'] . "' " . ($selected_exam_id == $e['id'] ? 'selected' : '') . ">" . $e['title'] . " (" . $e['subject_name'] . ")</option>"; ?>
             </select>
        </div>

        <?php if ($exam_meta): ?>
            <div class="stats-grid">
                <div class="stat-box" style="background: #6366f1;">
                    <div style="font-size:12px; opacity:0.8;">Total Students Tracked</div>
                    <div style="font-size:32px; font-weight:700;">
                        <?= $total_students ?>
                    </div>
                </div>
                <div class="stat-box" style="background: #a855f7;">
                    <div style="font-size:12px; opacity:0.8;">Exam Item Count</div>
                    <div style="font-size:32px; font-weight:700;">
                        <?= $exam_meta['item_count'] ?>
                    </div>
                </div>
                <div class="stat-box" style="background: #ec4899;">
                    <div style="font-size:12px; opacity:0.8;">Calculated MPS</div>
                    <div style="font-size:32px; font-weight:700;">
                        <?= number_format($mps, 2) ?>%
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-top:0;">Student Scores for:
                    <?= $exam_meta['title'] ?>
                </h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_scores">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th style="text-align:center;">Score (Raw)</th>
                                <th style="text-align:center;">Percentage</th>
                                <th style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_scores as $s):
                                $pct = ($exam_meta['item_count'] > 0 && $s['score'] !== null) ? ($s['score'] / $exam_meta['item_count']) * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="number" name="scores[<?= $s['id'] ?>]" value="<?= $s['score'] ?>"
                                            max="<?= $exam_meta['item_count'] ?>" class="score-input">
                                    </td>
                                    <td style="text-align:center; font-weight:bold; color:var(--primary);">
                                        <?= number_format($pct, 1) ?>%
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($s['score'] !== null): ?>
                                            <span
                                                style="padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700; background:<?= $pct >= 75 ? '#dcfce7' : '#fee2e2' ?>; color:<?= $pct >= 75 ? '#15803d' : '#991b1b' ?>;">
                                                <?= $pct >= 75 ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="text-align:right; margin-top:20px;">
                        <button type="submit" class="btn">💾 Save All Scores & Recalculate MPS</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="card" style="text-align:center; padding:100px; color:#64748b;">
                <div style="font-size:64px; opacity:0.3;">📉</div>
                <h3>Select an exam to view MPS analytics</h3>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>