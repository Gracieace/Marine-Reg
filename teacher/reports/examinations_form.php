<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user_id = $_SESSION['user']['id'];

// 1. Initialize Tables
$pdo->exec("CREATE TABLE IF NOT EXISTS exam_papers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    grade_level VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    instructions TEXT,
    school_year VARCHAR(20) NOT NULL,
    period VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS exam_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_text TEXT,
    option_a TEXT,
    option_b TEXT,
    option_c TEXT,
    option_d TEXT,
    correct_answer CHAR(1),
    points INT DEFAULT 1,
    FOREIGN KEY (exam_id) REFERENCES exam_papers(id) ON DELETE CASCADE
)");

// --- 1. Parameter Handling (Dashboard Sync) ---
$selected_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$selected_sy = $_GET['sy'] ?? $_GET['school_year'] ?? '2024-2025';
$selected_sub = $_GET['subject_id'] ?? '';

// --- 2. Fetch Teacher's Assigned Subjects (Official Source of Truth) ---
$stmt = $pdo->prepare("
    SELECT DISTINCT st.grade_level, c.subject_name, st.curriculum_id as id
    FROM subject_teachers st
    JOIN curriculum c ON st.curriculum_id = c.id
    WHERE st.teacher_id = (SELECT id FROM teachers WHERE email = (SELECT username FROM users WHERE id = ?))
    AND st.school_year = ?
    ORDER BY st.grade_level, c.subject_name
");
$stmt->execute([$user_id, $selected_sy]);
$assigned_loads = $stmt->fetchAll();

// Get unique grade levels for the filter dropdown
$grade_levels = array_unique(array_column($assigned_loads, 'grade_level'));

if (!$selected_grade && !empty($grade_levels)) {
    $selected_grade = $grade_levels[0];
}

// Filter subjects for the selected grade
$subjects = array_filter($assigned_loads, function($load) use ($selected_grade) {
    return $load['grade_level'] === $selected_grade;
});

// 2. Handle Action (Create/Save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_exam') {
    $title = $_POST['title'];
    $sub_id = $_POST['subject_id'];
    $period = $_POST['period'];
    $sy = $_POST['school_year'];
    $instr = $_POST['instructions'];

    $stmt = $pdo->prepare("INSERT INTO exam_papers (teacher_id, subject_id, grade_level, title, instructions, school_year, period) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $sub_id, $selected_grade, $title, $instr, $sy, $period]);
    $exam_id = $pdo->lastInsertId();

    $qs = $_POST['q'] ?? [];
    foreach ($qs as $q) {
        if (empty($q['text']))
            continue;
        $stmt = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$exam_id, $q['text'], $q['a'], $q['b'], $q['c'], $q['d'], $q['ans']]);
    }
    header("Location: ?grade=$selected_grade&success=1");
    exit;
}

$exams = [];
if ($selected_grade) {
    $stmt = $pdo->prepare("SELECT e.*, s.subject_name FROM exam_papers e JOIN subjects s ON e.subject_id = s.id WHERE e.teacher_id = ? AND e.grade_level = ? ORDER BY e.created_at DESC");
    $stmt->execute([$user_id, $selected_grade]);
    $exams = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Examinations - Periodic Test Generator</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
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

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 12px;
            font-family: inherit;
        }

        .q-card {
            border: 1px solid var(--border);
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            background: #fafafa;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-outline {
            border: 1px solid var(--border);
            background: white;
        }

        .exam-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="content main-content">
        <div class="card" style="display:flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin:0; font-size:24px; color:#1e293b;">Examinations</h1>
                <p style="color:#64748b;">Create and generate professional periodic test papers.</p>
            </div>
            <select class="form-control" style="width:200px; margin:0;"
                onchange="const url = new URL(window.location); url.searchParams.set('grade', this.value); window.location.href = url.href;">
                <?php foreach ($grade_levels as $gl)
                    echo "<option value='$gl' " . ($selected_grade == $gl ? 'selected' : '') . ">$gl</option>"; ?>
            </select>
        </div>

        <div class="grid">
            <!-- Create New Exam -->
            <div class="card">
                <h3 style="margin-top:0;">Create New Periodic Test</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_exam">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <input type="text" name="title" placeholder="Exam Title (e.g. 1st Periodic Test)"
                            class="form-control" required>
                        <select name="subject_id" class="form-control" required>
                            <?php foreach ($subjects as $s)
                                echo "<option value='" . $s['id'] . "'>" . $s['subject_name'] . "</option>"; ?>
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <select name="period" class="form-control">
                            <option>1st Quarter</option>
                            <option>2nd Quarter</option>
                            <option>3rd Quarter</option>
                            <option>4th Quarter</option>
                        </select>
                        <input type="text" name="school_year" value="2024-2025" class="form-control">
                    </div>
                    <textarea name="instructions" placeholder="General Instructions..." class="form-control"
                        style="height:60px;"></textarea>

                    <div id="questions-container">
                        <div class="q-card">
                            <strong>Question 1:</strong>
                            <textarea name="q[0][text]" placeholder="Enter question text..." class="form-control"
                                style="height:50px;"></textarea>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                                <input type="text" name="q[0][a]" placeholder="Option A" class="form-control">
                                <input type="text" name="q[0][b]" placeholder="Option B" class="form-control">
                                <input type="text" name="q[0][c]" placeholder="Option C" class="form-control">
                                <input type="text" name="q[0][d]" placeholder="Option D" class="form-control">
                            </div>
                            <select name="q[0][ans]" class="form-control" style="width:150px;">
                                <option value="">Correct Ans</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline" style="width:100%; margin-bottom:15px;"
                        onclick="addQuestion()">➕ Add Question</button>
                    <button type="submit" class="btn btn-primary" style="width:100%;">💾 Generate & Save Exam</button>
                </form>
            </div>

            <!-- Existing Exams -->
            <div class="card">
                <h3 style="margin-top:0;">Generated Papers</h3>
                <?php if (empty($exams))
                    echo "<p style='color:#64748b;'>No exams created for this grade yet.</p>"; ?>
                <?php foreach ($exams as $e): ?>
                    <div class="exam-item">
                        <div>
                            <div style="font-weight:600;">
                                <?= $e['title'] ?>
                            </div>
                            <div style="font-size:12px; color:#64748b;">
                                <?= $e['subject_name'] ?> •
                                <?= $e['period'] ?>
                            </div>
                        </div>
                        <a href="exam_print.php?id=<?= $e['id'] ?>" target="_blank" class="btn btn-outline"
                            style="font-size:12px; padding:6px 12px;">🖨️ View/Print</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        let qCount = 1;
        function addQuestion() {
            const container = document.getElementById('questions-container');
            const div = document.createElement('div');
            div.className = 'q-card';
            div.innerHTML = `
                <strong>Question ${qCount + 1}:</strong>
                <textarea name="q[${qCount}][text]" placeholder="Enter question text..." class="form-control" style="height:50px;"></textarea>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <input type="text" name="q[${qCount}][a]" placeholder="Option A" class="form-control">
                    <input type="text" name="q[${qCount}][b]" placeholder="Option B" class="form-control">
                    <input type="text" name="q[${qCount}][c]" placeholder="Option C" class="form-control">
                    <input type="text" name="q[${qCount}][d]" placeholder="Option D" class="form-control">
                </div>
                <select name="q[${qCount}][ans]" class="form-control" style="width:150px;">
                    <option value="">Correct Ans</option><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
                </select>
            `;
            container.appendChild(div);
            qCount++;
        }
    </script>
</body>

</html>