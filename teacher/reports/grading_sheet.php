<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user_id = $_SESSION['user']['id'];

// 1. Fetch Teacher's Advisory Class
$stmt = $pdo->prepare("SELECT * FROM position_assignments WHERE (user_id = ? OR employee_id IN (SELECT id FROM teachers WHERE email = (SELECT username FROM users WHERE id = ?))) AND position_type = 'class_adviser' ORDER BY school_year DESC LIMIT 1");
$stmt->execute([$user_id, $user_id]);
$advisory = $stmt->fetch();

$view = 'master'; // Unified master view as default
$school_year = $advisory['school_year'] ?? '';

// 2. Handle Batch Save (from Edit modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_modal') {
    $sid = $_POST['student_id'];
    $grades_data = $_POST['grades'] ?? [];

    $pdo->beginTransaction();
    try {
        foreach ($grades_data as $sub_id => $g) {
            $q1 = $g['q1'] !== '' ? floatval($g['q1']) : null;
            $q2 = $g['q2'] !== '' ? floatval($g['q2']) : null;
            $q3 = $g['q3'] !== '' ? floatval($g['q3']) : null;
            $q4 = $g['q4'] !== '' ? floatval($g['q4']) : null;

            $vals = array_filter([$q1, $q2, $q3, $q4], fn($v) => !is_null($v));
            $final = !empty($vals) ? array_sum($vals) / count($vals) : null;
            $remarks = ($final >= 75) ? 'PASSED' : (($final !== null) ? 'FAILED' : '');

            $stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, school_year, q1, q2, q3, q4, final_grade, remarks) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE q1=VALUES(q1), q2=VALUES(q2), q3=VALUES(q3), q4=VALUES(q4), 
                                   final_grade=VALUES(final_grade), remarks=VALUES(remarks)");
            $stmt->execute([$sid, $sub_id, $school_year, $q1, $q2, $q3, $q4, $final, $remarks]);
        }
        $pdo->commit();
        header("Location: grading_sheet.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// 3. Data Fetching
$subjects = [];
$students = [];
$all_student_grades = []; // For modal data: student_id => [subject_id => {q1,q2,q3,q4,final,remarks}]

if ($advisory) {
    // Subjects for this specific section from the curriculum
    $stmt = $pdo->prepare("
        SELECT id, subject_name 
        FROM curriculum 
        WHERE grade_level = ? 
        ORDER BY subject_name
    ");
    $stmt->execute([$advisory['grade_level']]);
    $subjects = $stmt->fetchAll();

    // All enrolled students
    $stmt = $pdo->prepare("SELECT e.student_id, e.student_name 
                           FROM enrollments e 
                           WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
                           ORDER BY e.student_name ASC");
    $stmt->execute([$advisory['grade_level'], $advisory['section'], $school_year]);
    $enrolled = $stmt->fetchAll();

    // Get ALL grades for ALL students (needed for modals on every view)
    $stmt = $pdo->prepare("SELECT * FROM grades WHERE school_year = ? AND student_id IN (SELECT student_id FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?)");
    $stmt->execute([$school_year, $advisory['grade_level'], $advisory['section'], $school_year]);
    $raw_grades = $stmt->fetchAll();

    foreach ($raw_grades as $g) {
        $all_student_grades[$g['student_id']][$g['subject_id']] = [
            'q1' => $g['q1'],
            'q2' => $g['q2'],
            'q3' => $g['q3'],
            'q4' => $g['q4'],
            'final' => $g['final_grade'],
            'remarks' => $g['remarks']
        ];
    }

    // Build student list (Unified Master View)
    $subject_totals = [];
    $subject_counts = [];
    $total_gwa = 0;
    $gwa_count = 0;

    foreach ($enrolled as $e) {
        $row = $e;
        $total = 0;
        $cnt = 0;
        foreach ($subjects as $sub) {
            $f = $all_student_grades[$e['student_id']][$sub['id']]['final'] ?? null;
            $row['subs'][$sub['id']] = $f;
            if ($f) {
                $total += $f;
                $cnt++;
                
                if (!isset($subject_totals[$sub['id']])) {
                    $subject_totals[$sub['id']] = 0;
                    $subject_counts[$sub['id']] = 0;
                }
                $subject_totals[$sub['id']] += $f;
                $subject_counts[$sub['id']]++;
            }
        }
        $row['gwa'] = $cnt > 0 ? $total / $cnt : null;
        if ($row['gwa']) {
            $total_gwa += $row['gwa'];
            $gwa_count++;
        }
        $students[] = $row;
    }
    
    $class_averages = [];
    foreach ($subjects as $sub) {
        $class_averages[$sub['id']] = (isset($subject_counts[$sub['id']]) && $subject_counts[$sub['id']] > 0) 
            ? $subject_totals[$sub['id']] / $subject_counts[$sub['id']] 
            : null;
    }
    $class_gwa = $gwa_count > 0 ? $total_gwa / $gwa_count : null;
}

// Prepare JSON for modal use
$modal_json = json_encode($all_student_grades);
$subjects_json = json_encode(array_map(fn($s) => ['id' => $s['id'], 'name' => $s['subject_name']], $subjects));
$enrolled_json = json_encode(array_map(fn($s) => ['id' => $s['student_id'], 'name' => $s['student_name']], $enrolled ?? []));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Class Record / Grading Sheet</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: var(--primary);
            --primary-600: var(--primary-dark);
            --bg: var(--bg-main);
            --border: var(--border);
        }

        body {
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            margin: 0;
        }

        .main-content {
            padding-top: 120px !important;
            transition: margin-left 0.25s ease;
        }

        .content-wrap {
            padding: 0 32px 48px;
            max-width: 1400px;
            margin: auto;
        }

        .card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        .flex {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .nav-pills {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
            flex-wrap: wrap;
        }

        .pill {
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            background: #f1f5f9;
            transition: 0.2s;
            white-space: nowrap;
        }

        .pill.active {
            background: var(--primary);
            color: white;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13.5px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            /* Internal Scroll */
            max-height: 550px;
            overflow-y: auto;
        }

        th,
        td {
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            padding: 12px 14px;
            text-align: center;
            vertical-align: middle;
        }

        th:last-child, td:last-child {
            border-right: none;
        }
        
        tr:last-child td {
            border-bottom: none;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #f8fafc;
            box-shadow: inset 0 -1px 0 var(--border);
        }

        tfoot th, tfoot td {
            position: sticky;
            bottom: 0;
            z-index: 100;
            background: #eff6ff;
            box-shadow: inset 0 1px 0 var(--border);
        }

        /* Sticky Columns */
        th:first-child, td:first-child {
            position: sticky;
            left: 0;
            z-index: 20;
            background: inherit;
        }
        
        thead th:first-child { z-index: 110; background: #f8fafc; }
        tfoot th:first-child, tfoot td:first-child { z-index: 110; background: #eff6ff; }
        
        tr:nth-child(even) td:first-child { background: #fcfdfe; }
        tr:nth-child(odd) td:first-child { background: #fff; }

        th {
            background: #f8fafc;
            color: #334155;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 11px;
        }

        .text-left {
            text-align: left !important;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 13.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn:active {
            transform: scale(0.97);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:hover {
            background: var(--primary-600);
            box-shadow: 0 6px 8px -1px rgba(37, 99, 235, 0.3);
        }

        .btn-outline {
            border: 1.5px solid #cbd5e1;
            background: white;
            color: #475569;
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #1e293b;
        }

        .btn-success {
            background: #10b981;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }

        .btn-success:hover {
            background: #059669;
            box-shadow: 0 6px 8px -1px rgba(16, 185, 129, 0.3);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12.5px;
            border-radius: 6px;
        }

        .btn-view {
            background: #f1f5f9;
            color: var(--primary-600);
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .btn-view:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        .btn-edit {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.15);
        }

        .btn-edit:hover {
            background: var(--primary-600);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.2);
        }

        .btn-lg {
            padding: 10px 20px;
            font-size: 15px;
            border-radius: 10px;
        }

        .master-scroll {
            overflow-x: auto;
            max-width: 100%;
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .master-table {
            border: none;
        }

        .master-table th {
            white-space: nowrap;
            font-size: 11px;
        }

        .gwa-box {
            font-weight: 700;
            color: var(--primary);
            background: #eff6ff;
        }

        #modalForm {
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.4);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: var(--bg-card);
            border-radius: 20px;
            width: 90%;
            max-width: 950px;
            max-height: 88vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            animation: modalIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid var(--border);
        }

        @keyframes modalIn {
            from {
                transform: scale(0.95) translateY(10px);
                opacity: 0;
            }

            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 24px 32px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header .close-btn {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-header .close-btn:hover {
            color: #0f172a;
            background: #e2e8f0;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 24px 32px;
            overflow-y: auto;
            flex: 1;
            background: #f8fafc;
        }

        .modal-footer {
            padding: 20px 32px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #ffffff;
        }

        .modal-body table th {
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 1px 0 var(--border);
        }

        .modal-body .grade-input {
            width: 65px;
            padding: 8px 6px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            transition: all 0.2s;
            background: white;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
        }

        .modal-body .grade-input:hover {
            border-color: #cbd5e1;
        }

        .modal-body .grade-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
            transform: translateY(-1px);
        }

        /* Hide standard number spinners */
        .grade-input::-webkit-outer-spin-button,
        .grade-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .gwa-row {
            background: #eff6ff !important;
        }

        .gwa-row td, .gwa-row th {
            color: var(--primary-600);
            font-weight: 800;
            border-top: none;
        }

        .actions-cell {
            white-space: nowrap;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            .content {
                padding: 0;
                max-width: 100%;
            }

            .card {
                border: none;
                box-shadow: none;
                padding: 0;
            }
        }

        .class-card {
            break-inside: avoid;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .modal-box { /* Changed from .modal-content to .modal-box based on existing CSS */
                width: 95%;
                margin: 10px;
                max-height: 90vh;
            }

            .modal-header, .modal-footer {
                padding: 16px;
            }

            .modal-body {
                padding: 12px;
            }

            .modal-body .grade-input {
                font-size: 16px; /* prevent iOS zoom */
            }
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="main-content">
        <div class="content-wrap">
            <div class="card no-print">
            <div style="display:flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <h1 style="margin:0; font-size:24px; color:#1e293b;">Class Record & Grading Sheet</h1>
                    <p style="color:#64748b; margin-top:4px;">
                        <?= htmlspecialchars($advisory['grade_level'] ?? 'N/A') ?> -
                        <?= htmlspecialchars($advisory['section'] ?? 'N/A') ?>
                        | SY: <?= htmlspecialchars($school_year) ?> 
                        | Enrolled: <strong><?= count($enrolled ?? []) ?> students</strong>
                    </p>
                </div>
                <button onclick="window.print()" class="btn btn-outline btn-lg">🖨️ Print</button>
            </div>

            <!-- Removed subject navigation pills for a unified master view -->
        </div>

        <?php if (isset($_GET['success']))
            echo "<div class='card' style='color:#15803d; background:#dcfce7; border-color:#bbf7d0; padding:14px 24px; font-weight:600;'>✅ Grades saved successfully!</div>"; ?>

        <?php if (true): // Always show master view ?>
            <div class="card">
                <h3 style="margin:0 0 20px;">Consolidated Master Record
                    <span style="font-size:13px; color:#94a3b8; font-weight:400; margin-left:10px;">(<?= count($students) ?>
                        students)</span>
                </h3>
                <div class="master-scroll">
                    <table class="master-table">
                        <thead>
                            <tr>
                                <th style="width:35px;">#</th>
                                <th class="text-left" style="min-width:200px;">Learner's Name</th>
                                <?php foreach ($subjects as $sub): ?>
                                    <th style="min-width:70px;"><?= htmlspecialchars($sub['subject_name']) ?></th>
                                <?php endforeach; ?>
                                <th class="gwa-box" style="min-width:70px;">GWA</th>
                                <th class="no-print" style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $idx => $s): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td class="text-left" style="font-weight:500;"><?= htmlspecialchars($s['student_name']) ?>
                                    </td>
                                    <?php foreach ($subjects as $sub):
                                        $grade = $s['subs'][$sub['id']] ?? null;
                                        ?>
                                        <td
                                            style="color:<?= $grade ? ($grade >= 75 ? '#15803d' : '#991b1b') : '#94a3b8' ?>; font-weight:500;">
                                            <?= $grade ? number_format($grade, 0) : '—' ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="gwa-box"><?= $s['gwa'] ? number_format($s['gwa'], 2) : '—' ?></td>
                                    <td class="no-print actions-cell">
                                        <button class="btn btn-sm btn-view"
                                            onclick="openModal('<?= $s['student_id'] ?>', 'view')">👁️ View</button>
                                        <button class="btn btn-sm btn-edit"
                                            onclick="openModal('<?= $s['student_id'] ?>', 'edit')">✏️ Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="gwa-row">
                            <tr>
                                <th colspan="2" class="text-left">CLASS AVERAGE</th>
                                <?php foreach ($subjects as $sub): 
                                    $avg = $class_averages[$sub['id']] ?? null;
                                ?>
                                    <th><?= $avg ? number_format($avg, 0) : '—' ?></th>
                                <?php endforeach; ?>
                                <th class="gwa-box"><?= $class_gwa ? number_format($class_gwa, 2) : '—' ?></th>
                                <th class="no-print"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Grades Modal -->
    <div class="modal-overlay" id="gradeModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2 id="modalTitle">Student Grades</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="modalForm">
                <input type="hidden" name="action" value="save_modal">
                <input type="hidden" name="student_id" id="modalStudentId">
                <div class="modal-body" id="modalBody">
                    <!-- Dynamically populated -->
                </div>
                <div class="modal-footer" id="modalFooter">
                    <button type="button" class="btn btn-outline btn-lg" onclick="closeModal()">Close</button>
                    <button type="submit" class="btn btn-success btn-lg" id="modalSaveBtn" style="display:none;">💾 Save
                        All Grades</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const allGrades = <?= $modal_json ?>;
        const subjects = <?= $subjects_json ?>;
        const enrolledStudents = <?= $enrolled_json ?>;

        function getStudentName(sid) {
            const s = enrolledStudents.find(e => e.id == sid);
            return s ? s.name : 'Unknown';
        }

        function openModal(studentId, mode) {
            const name = getStudentName(studentId);
            const isEdit = mode === 'edit';
            document.getElementById('modalTitle').innerText = (isEdit ? '✏️ Edit Grades — ' : '👁️ Grades — ') + name;
            document.getElementById('modalStudentId').value = studentId;
            document.getElementById('modalSaveBtn').style.display = isEdit ? 'inline-flex' : 'none';

            const grades = allGrades[studentId] || {};
            let html = '<div class="table-responsive">';
            html += '<table>';
            html += '<thead><tr>';
            html += '<th class="text-left">Subject</th><th style="width:70px;">Q1</th><th style="width:70px;">Q2</th><th style="width:70px;">Q3</th><th style="width:70px;">Q4</th><th style="width:70px;">Final</th><th style="width:90px;">Remarks</th>';
            html += '</tr></thead><tbody>';

            let totalFinal = 0, finalCount = 0;

            subjects.forEach(sub => {
                const g = grades[sub.id] || {};
                const q1 = g.q1 ?? '';
                const q2 = g.q2 ?? '';
                const q3 = g.q3 ?? '';
                const q4 = g.q4 ?? '';
                const final_g = g.final ?? '';
                const remarks = g.remarks ?? '';

                if (final_g) { totalFinal += parseFloat(final_g); finalCount++; }

                html += '<tr>';
                html += '<td class="text-left" style="font-weight:600;">' + sub.name + '</td>';

                if (isEdit) {
                    html += '<td><input type="number" step="0.01" inputmode="decimal" pattern="[0-9]*" class="grade-input q-input" data-sub="' + sub.id + '" name="grades[' + sub.id + '][q1]" value="' + q1 + '"></td>';
                    html += '<td><input type="number" step="0.01" inputmode="decimal" pattern="[0-9]*" class="grade-input q-input" data-sub="' + sub.id + '" name="grades[' + sub.id + '][q2]" value="' + q2 + '"></td>';
                    html += '<td><input type="number" step="0.01" inputmode="decimal" pattern="[0-9]*" class="grade-input q-input" data-sub="' + sub.id + '" name="grades[' + sub.id + '][q3]" value="' + q3 + '"></td>';
                    html += '<td><input type="number" step="0.01" inputmode="decimal" pattern="[0-9]*" class="grade-input q-input" data-sub="' + sub.id + '" name="grades[' + sub.id + '][q4]" value="' + q4 + '"></td>';
                } else {
                    html += '<td>' + (q1 ? parseFloat(q1).toFixed(0) : '—') + '</td>';
                    html += '<td>' + (q2 ? parseFloat(q2).toFixed(0) : '—') + '</td>';
                    html += '<td>' + (q3 ? parseFloat(q3).toFixed(0) : '—') + '</td>';
                    html += '<td>' + (q4 ? parseFloat(q4).toFixed(0) : '—') + '</td>';
                }

                html += '<td style="font-weight:700; color:#2563eb;" id="final_' + sub.id + '">' + (final_g ? parseFloat(final_g).toFixed(0) : '—') + '</td>';

                if (remarks) {
                    const color = remarks === 'PASSED' ? '#15803d' : '#991b1b';
                    const bg    = remarks === 'PASSED' ? '#dcfce7' : '#fee2e2';
                    html += '<td id="remarks_' + sub.id + '"><span style="padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;background:' + bg + ';color:' + color + ';">' + remarks + '</span></td>';
                } else {
                    html += '<td id="remarks_' + sub.id + '">—</td>';
                }

                html += '</tr>';
            });

            // General Average row
            const gwa = finalCount > 0 ? (totalFinal / finalCount).toFixed(2) : '—';
            const gwaRemarks = finalCount > 0 ? (totalFinal / finalCount >= 75 ? 'PASSED' : 'FAILED') : '';
            html += '</tbody><tfoot class="gwa-row"><tr><td class="text-left" colspan="5" style="font-size:14px;">GENERAL AVERAGE</td>';
            html += '<td style="font-size:16px;" id="gwa_final">' + gwa + '</td>';
            if (gwaRemarks) {
                const gc = gwaRemarks === 'PASSED' ? '#15803d' : '#991b1b';
                const gb = gwaRemarks === 'PASSED' ? '#dcfce7' : '#fee2e2';
                html += '<td id="gwa_remarks"><span style="padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;background:' + gb + ';color:' + gc + ';">' + gwaRemarks + '</span></td>';
            } else {
                html += '<td id="gwa_remarks">—</td>';
            }
            html += '</tr></tfoot></table>';
            html += '</div>';

            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('gradeModal').classList.add('active');

            if (isEdit) {
                attachLiveCalculation();
            }
        }

        function attachLiveCalculation() {
            const inputs = document.querySelectorAll('.q-input');
            inputs.forEach(input => {
                input.addEventListener('input', calculateLiveGrades);
            });
        }

        function calculateLiveGrades() {
            let totalGWA = 0;
            let subjectsWithFinal = 0;

            subjects.forEach(sub => {
                // Get all 4 quarter inputs for this subject
                const qInputs = document.querySelectorAll(`.q-input[data-sub="${sub.id}"]`);
                let sum = 0;
                let count = 0;

                qInputs.forEach(inp => {
                    const val = parseFloat(inp.value);
                    if (!isNaN(val)) {
                        sum += val;
                        count++;
                    }
                });

                const finalTd = document.getElementById(`final_${sub.id}`);
                const remarksTd = document.getElementById(`remarks_${sub.id}`);

                if (count > 0) {
                    const finalG = sum / count;
                    totalGWA += finalG;
                    subjectsWithFinal++;
                    
                    finalTd.innerText = finalG.toFixed(0); // Display as whole number per standard DepEd format
                    
                    const passed = finalG >= 75;
                    const color = passed ? '#15803d' : '#991b1b';
                    const bg = passed ? '#dcfce7' : '#fee2e2';
                    const text = passed ? 'PASSED' : 'FAILED';
                    remarksTd.innerHTML = `<span style="padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;background:${bg};color:${color};">${text}</span>`;
                } else {
                    finalTd.innerText = '—';
                    remarksTd.innerHTML = '—';
                }
            });

            // Update GWA row
            const gwaTd = document.getElementById('gwa_final');
            const gwaRemarksTd = document.getElementById('gwa_remarks');

            if (subjectsWithFinal > 0) {
                const gwa = totalGWA / subjectsWithFinal;
                gwaTd.innerText = gwa.toFixed(2);
                
                const passed = gwa >= 75;
                const color = passed ? '#15803d' : '#991b1b';
                const bg = passed ? '#dcfce7' : '#fee2e2';
                const text = passed ? 'PASSED' : 'FAILED';
                gwaRemarksTd.innerHTML = `<span style="padding:3px 8px;border-radius:10px;font-size:11px;font-weight:700;background:${bg};color:${color};">${text}</span>`;
            } else {
                gwaTd.innerText = '—';
                gwaRemarksTd.innerHTML = '—';
            }
        }

        function closeModal() {
            document.getElementById('gradeModal').classList.remove('active');
        }

        // Close on overlay click
        document.getElementById('gradeModal').addEventListener('click', function (e) {
            if (e.target === this) closeModal();
        });

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeModal();
        });

        // Form Submit Visual Feedback
        document.getElementById('modalForm').addEventListener('submit', function() {
            const btn = document.getElementById('modalSaveBtn');
            btn.innerHTML = '⌛ Saving...';
            btn.style.opacity = '0.7';
            btn.disabled = true;
        });
    </script>
</body>

</html>