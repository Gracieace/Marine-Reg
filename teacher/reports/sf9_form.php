<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
try { initialize_schema($pdo); } catch (Exception $e) { }
$user = auth_user();
$user_id = $user['id'];

// 1. Fetch Advisory Info
$target_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$target_section = $_GET['section'] ?? '';
$target_sy = $_GET['sy'] ?? $_GET['school_year'] ?? get_active_school_year($pdo);

if ($target_grade && $target_section && $target_sy) {
    $stmt = $pdo->prepare('SELECT pa.* FROM position_assignments pa
         WHERE pa.position_type = "class_adviser"
           AND pa.user_id = :uid
           AND pa.grade_level = :gl
           AND pa.section = :sec
           AND pa.school_year = :sy
         LIMIT 1');
    $stmt->execute([':uid' => $user_id, ':gl' => $target_grade, ':sec' => $target_section, ':sy' => $target_sy]);
    $advisory = $stmt->fetch();
    
    // Fallback if not found in position_assignments but exists in sections
    if (!$advisory) {
        $stmt = $pdo->prepare('SELECT grade_level, section_name as section, school_year 
                               FROM sections 
                               WHERE adviser_id = ? AND grade_level = ? AND section_name = ? AND school_year = ?
                               LIMIT 1');
        $stmt->execute([$user_id, $target_grade, $target_section, $target_sy]);
        $advisory = $stmt->fetch();
    }
} else {
    // Check position_assignments first (latest)
    $stmt = $pdo->prepare('SELECT pa.* FROM position_assignments pa
         WHERE pa.position_type = "class_adviser"
           AND pa.user_id = :uid
         ORDER BY pa.school_year DESC LIMIT 1');
    $stmt->execute([':uid' => $user_id]);
    $advisory = $stmt->fetch();

    // Fallback to sections table
    if (!$advisory) {
        $stmt = $pdo->prepare('SELECT grade_level, section_name as section, school_year 
                               FROM sections 
                               WHERE adviser_id = ? 
                               ORDER BY school_year DESC LIMIT 1');
        $stmt->execute([$user_id]);
        $advisory = $stmt->fetch();
    }
}

$students = [];
if ($advisory) {
    $stmt = $pdo->prepare("SELECT 
        e.student_id, 
        UPPER(COALESCE(CONCAT(r.last_name, ', ', r.first_name, ' ', COALESCE(SUBSTRING(r.middle_name, 1, 1), ''), '.'), e.student_name)) as student_name,
        COALESCE(r.lrn, e.lrn) as lrn, 
        r.sex, r.birthdate, r.age as reg_age 
    FROM enrollments e 
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.lrn = r.lrn AND e.lrn IS NOT NULL AND e.lrn != '')) 
    WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ? 
    ORDER BY r.sex DESC, student_name ASC");
    $stmt->execute([$advisory['grade_level'], $advisory['section'], $advisory['school_year']]);
    $students = $stmt->fetchAll();
}

// 2. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $student_id = $_POST['student_id'];
    $sy = $advisory['school_year'];

    try {
        if ($action === 'save_grades') {
            $grades = $_POST['grades'] ?? [];
            $pdo->beginTransaction();
            foreach ($grades as $subject_id => $data) {
                $q1 = $data['q1'] !== '' ? floatval($data['q1']) : null;
                $q2 = $data['q2'] !== '' ? floatval($data['q2']) : null;
                $q3 = $data['q3'] !== '' ? floatval($data['q3']) : null;
                $q4 = $data['q4'] !== '' ? floatval($data['q4']) : null;
                
                $vals = array_filter([$q1, $q2, $q3, $q4], fn($v) => !is_null($v));
                $final = !empty($vals) ? round(array_sum($vals) / count($vals)) : null;
                $remarks = ($final >= 75) ? 'PASSED' : (($final !== null) ? 'FAILED' : '');

                $stmt = $pdo->prepare("INSERT INTO sf9_grades (student_id, subject_id, school_year, q1, q2, q3, q4, final_grade, remarks) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                       ON DUPLICATE KEY UPDATE q1=VALUES(q1), q2=VALUES(q2), q3=VALUES(q3), q4=VALUES(q4), 
                                       final_grade=VALUES(final_grade), remarks=VALUES(remarks)");
                $stmt->execute([$student_id, $subject_id, $sy, $q1, $q2, $q3, $q4, $final, $remarks]);
            }
            $pdo->commit();
            $success = "Grades updated successfully!";
        } 
        
        elseif ($action === 'save_observed_values') {
            $observed = $_POST['observed'] ?? [];
            $pdo->beginTransaction();
            foreach ($observed as $quarter => $statements) {
                foreach ($statements as $stmt_id => $rating) {
                    if ($rating) {
                        $stmt = $pdo->prepare("INSERT INTO observed_values (student_id, school_year, quarter, behavior_statement_id, rating) 
                                               VALUES (?, ?, ?, ?, ?) 
                                               ON DUPLICATE KEY UPDATE rating=VALUES(rating)");
                        $stmt->execute([$student_id, $sy, $quarter, $stmt_id, $rating]);
                    }
                }
            }
            $pdo->commit();
            $success = "Observed values updated!";
        }

        elseif ($action === 'save_sf9_report') {
            $remarks = $_POST['adviser_remarks'] ?? '';
            $status = $_POST['promotion_status'] ?? 'Promoted';
            $rating = $_POST['final_rating'] ?? null;
            $stmt = $pdo->prepare("INSERT INTO sf9_reports (student_id, school_year, adviser_remarks, promotion_status, final_rating) 
                                   VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE adviser_remarks=VALUES(adviser_remarks), promotion_status=VALUES(promotion_status), final_rating=VALUES(final_rating)");
            $stmt->execute([$student_id, $sy, $remarks, $status, $rating]);
            $success = "Promotion details saved!";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// 3. Fetch Selected Student Data
$selected_student_id = $_GET['student_id'] ?? '';
$current_grades = [];
$current_observed = [];
$sf9_report = [];
$attendance_data = [];

if ($selected_student_id) {
    $sy = $advisory['school_year'];
    
    // Identify selected student info FIRST
    $sel_student = null;
    foreach($students as $s) if($s['student_id'] == $selected_student_id) { $sel_student = $s; break; }

    // Grades
    $stmt = $pdo->prepare("SELECT * FROM sf9_grades WHERE student_id = ? AND school_year = ?");
    $stmt->execute([$selected_student_id, $sy]);
    while ($row = $stmt->fetch()) $current_grades[$row['subject_id']] = $row;
    
    // Observed Values
    $stmt = $pdo->prepare("SELECT * FROM observed_values WHERE student_id = ? AND school_year = ?");
    $stmt->execute([$selected_student_id, $sy]);
    while ($row = $stmt->fetch()) $current_observed[$row['quarter']][$row['behavior_statement_id']] = $row['rating'];
    
    // SF9 Report (Remarks/Promotion)
    $stmt = $pdo->prepare("SELECT * FROM sf9_reports WHERE student_id = ? AND school_year = ?");
    $stmt->execute([$selected_student_id, $sy]);
    $sf9_report = $stmt->fetch() ?: ['adviser_remarks' => '', 'promotion_status' => 'Promoted'];

    // Attendance (from SF2)
    // We check both student_id and LRN because SF2 might use either as the identifier
    $stmt = $pdo->prepare("SELECT r.report_month, s.total_present, s.total_absent, m.days_of_classes 
                           FROM sf2_reports r 
                           JOIN sf2_student_records s ON r.id = s.sf2_report_id 
                           JOIN sf2_monthly_summary m ON r.id = m.sf2_report_id
                           WHERE (s.student_id = ? OR s.student_id = ?) AND r.school_year = ?");
    $stmt->execute([$selected_student_id, $sel_student['lrn'] ?? '', $sy]);
    $attendance_data = $stmt->fetchAll();
}

// 4. Metadata & Auto-Sync MAPEH Components
$subjects = [];
if ($advisory) {
    $gl = $advisory['grade_level'];
    $required_mapeh = ['Music', 'Arts', 'Physical Education', 'Health'];
    
    // Check and Insert Missing Components
    foreach ($required_mapeh as $m) {
        $stmt = $pdo->prepare("SELECT id FROM curriculum WHERE grade_level = ? AND subject_name = ?");
        $stmt->execute([$gl, $m]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO curriculum (grade_level, subject_name, subject_code) VALUES (?, ?, ?)");
            $stmt->execute([$gl, $m, strtoupper(substr($m, 0, 3)) . '-' . substr($gl, -1)]);
        }
    }

    $stmt = $pdo->prepare("SELECT id, subject_name FROM curriculum WHERE grade_level = ? ORDER BY subject_name");
    $stmt->execute([$gl]);
    $subjects = $stmt->fetchAll();
}

$core_values = [
    'Maka-Diyos' => ['v1' => 'Expresses spiritual beliefs while respecting the spiritual beliefs of others.', 'v2' => 'Shows adherence to ethical principles by upholding truth.'],
    'Makatao' => ['v3' => 'Is sensitive to individual, social, and cultural differences.', 'v4' => 'Demonstrates contributions toward solidarity.'],
    'Makakalikasan' => ['v5' => 'Cares for the environment and utilizes resources wisely, judiciously, and economically.'],
    'Makabansa' => ['v6' => 'Demonstrates pride in being a Filipino; exercises the rights and responsibilities of a Filipino citizen.', 'v7' => 'Demonstrates appropriate behavior in carrying out activities in the school, community, and country.']
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF9 - Learner's Progress Report Card</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #0f172a; --accent: #2563eb; --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0; }
        body { font-family: 'Outfit', sans-serif; background: var(--bg); margin: 0; color: #334155; }
        .main-content { padding-top: 80px !important; margin-left: 260px; min-height: 100vh; }
        .container { max-width: 1200px; margin: auto; padding: 40px; }
        .card { background: var(--card); border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid var(--border); overflow: hidden; margin-bottom: 30px; }
        
        .grid { display: grid; grid-template-columns: 320px 1fr; gap: 30px; align-items: start; }
        .sidebar-list { max-height: 70vh; overflow-y: auto; padding: 10px; }
        .student-item { display: block; padding: 15px; border-radius: 12px; text-decoration: none; color: inherit; margin-bottom: 8px; transition: 0.3s; border: 1px solid transparent; }
        .student-item:hover { background: #f1f5f9; transform: translateX(5px); }
        .student-item.active { background: #eff6ff; border-color: var(--accent); }
        .student-name { font-weight: 700; font-size: 14px; display: block; }
        .student-lrn { font-size: 11px; color: #64748b; }

        .tabs { display: flex; background: #f1f5f9; padding: 5px; border-radius: 12px; margin-bottom: 30px; }
        .tab-btn { flex: 1; padding: 12px; border: none; background: none; font-weight: 600; cursor: pointer; border-radius: 8px; transition: 0.3s; color: #64748b; }
        .tab-btn.active { background: white; color: var(--accent); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 14px; }
        .grade-input { width: 60px; padding: 8px; border: 1.5px solid var(--border); border-radius: 8px; text-align: center; font-weight: 700; transition: 0.3s; }
        .grade-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }

        .btn { padding: 12px 25px; border-radius: 12px; font-weight: 700; cursor: pointer; border: none; transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3); }

        .avg-card { background: linear-gradient(135deg, #0f172a, #1e293b); color: white; padding: 25px; border-radius: 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .status-badge { padding: 5px 15px; border-radius: 50px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .bg-promoted { background: #dcfce7; color: #15803d; }
        .bg-conditional { background: #fef3c7; color: #92400e; }
        .bg-retained { background: #fee2e2; color: #991b1b; }

        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn 0.4s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="card" style="padding: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h1 style="margin: 0; font-size: 28px; font-weight: 800; color: var(--primary);">
                            <?= $selected_student_id ? 'Grading: ' . htmlspecialchars($sel_student['student_name'] ?? 'Learner') : "Learner Progress Report" ?>
                        </h1>
                        <p style="color: #64748b; margin-top: 5px;">Managing SF9 for <strong><?= htmlspecialchars($advisory['grade_level'] ?? 'N/A') ?> - <?= htmlspecialchars($advisory['section'] ?? 'N/A') ?></strong> (SY <?= htmlspecialchars($advisory['school_year'] ?? get_current_school_year($pdo)) ?>)</p>
                    </div>
                    <?php if($selected_student_id): ?>
                        <a href="sf9_print.php?student_id=<?=$selected_student_id?>" target="_blank" class="btn btn-primary">🖨️ Generate & Print SF9</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if(!$advisory): ?>
                <div class="card" style="padding: 100px; text-align: center; color: #94a3b8;">
                    <h2>No advisory class assigned.</h2>
                </div>
            <?php else: ?>
                <div class="grid">
                    <div class="card">
                        <div style="padding: 20px; border-bottom: 1px solid var(--border); background: #f8fafc;">
                            <h3 style="margin:0; font-size: 16px;">Learners List (<?= count($students) ?>)</h3>
                        </div>
                        <div class="sidebar-list">
                            <?php foreach($students as $s): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['student_id' => $s['student_id']])) ?>" 
                                   class="student-item <?= $selected_student_id == $s['student_id'] ? 'active' : '' ?>">
                                    <span class="student-name"><?= htmlspecialchars($s['student_name']) ?></span>
                                    <span class="student-lrn">LRN: <?= htmlspecialchars($s['lrn']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card" style="padding: 30px;">
                        <?php if($selected_student_id && $sel_student): ?>
                            <div style="margin-bottom: 25px; display: flex; align-items: center; gap: 20px; border-bottom: 1px solid var(--border); padding-bottom: 20px;">
                                <div style="width: 50px; height: 50px; background: var(--accent); color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800;">
                                    <?= substr($sel_student['student_name'] ?? 'S', 0, 1) ?>
                                </div>
                                <div>
                                    <h2 style="margin: 0; color: var(--primary); font-weight: 800;"><?= htmlspecialchars($sel_student['student_name'] ?? 'Unknown Student') ?></h2>
                                    <div style="display: flex; gap: 15px; margin-top: 4px;">
                                        <span style="font-size: 13px; color: #64748b;"><strong>LRN:</strong> <?= htmlspecialchars($sel_student['lrn'] ?? 'N/A') ?></span>
                                        <span style="font-size: 13px; color: #64748b;"><strong>Sex:</strong> <?= htmlspecialchars($sel_student['sex'] ?? 'N/A') ?></span>
                                        <?php 
                                            $calc_age = 0;
                                            if (!empty($sel_student['birthdate'])) {
                                                $bday = date_create($sel_student['birthdate']);
                                                $now = date_create(date('Y-m-d'));
                                                $calc_age = date_diff($bday, $now)->y;
                                            }
                                            $display_age = (!empty($sel_student['reg_age']) && $sel_student['reg_age'] > 0) ? $sel_student['reg_age'] : $calc_age;
                                        ?>
                                        <span style="font-size: 13px; color: #64748b;"><strong>Age:</strong> <?= $display_age ?></span>
                                    </div>
                                </div>
                            </div>

                            <?php if(isset($success)) echo "<div style='background:#dcfce7; color:#15803d; padding:15px; border-radius:12px; margin-bottom:20px; font-weight:700;'>✅ $success</div>"; ?>
                            <?php if(isset($error)) echo "<div style='background:#fee2e2; color:#991b1b; padding:15px; border-radius:12px; margin-bottom:20px; font-weight:700;'>❌ $error</div>"; ?>
                            
                            <div class="tabs">
                                <button class="tab-btn active" data-tab="grades">📚 Quarterly Grades</button>
                                <button class="tab-btn" data-tab="conduct">🌟 Values & Conduct</button>
                                <button class="tab-btn" data-tab="attendance">🗓️ Attendance</button>
                                <button class="tab-btn" data-tab="promotion">🏆 Promotion & Remarks</button>
                            </div>

                            <!-- Tab 1: Grades -->
                            <div id="grades" class="tab-pane active">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_grades">
                                    <input type="hidden" name="student_id" value="<?=$selected_student_id?>">
                                    <table>
                                        <thead>
                                            <tr><th>Learning Areas</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Final</th><th>Remarks</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total_final = 0; $count_final = 0; $failing_count = 0;
                                            $q_totals = [1=>0, 2=>0, 3=>0, 4=>0];
                                            $q_counts = [1=>0, 2=>0, 3=>0, 4=>0];

                                            $mapeh_main = null;
                                            $mapeh_subs = [];
                                            $other_subs = [];

                                            foreach($subjects as $sub) {
                                                $name = strtoupper($sub['subject_name']);
                                                if ($name === 'MAPEH') $mapeh_main = $sub;
                                                elseif (in_array($name, ['MUSIC', 'ARTS', 'PHYSICAL EDUCATION', 'HEALTH', 'PE', 'P.E.'])) $mapeh_subs[] = $sub;
                                                else $other_subs[] = $sub;
                                            }

                                            // Render Helper Function
                                            $renderRow = function($sub, $indent = false) use ($current_grades, &$total_final, &$count_final, &$failing_count, &$q_totals, &$q_counts) {
                                                $g = $current_grades[$sub['id']] ?? ['q1'=>'','q2'=>'','q3'=>'','q4'=>'','final_grade'=>'','remarks'=>''];
                                                if($g['final_grade']) { 
                                                    $total_final += $g['final_grade']; 
                                                    $count_final++; 
                                                    if($g['final_grade'] < 75) $failing_count++; 
                                                }
                                                for($i=1; $i<=4; $i++) {
                                                    if(!empty($g["q$i"])) {
                                                        $q_totals[$i] += $g["q$i"];
                                                        $q_counts[$i]++;
                                                    }
                                                }
                                                ?>
                                                <tr>
                                                    <td style="font-weight:600; <?= $indent ? 'padding-left:30px; font-size:12px; color:var(--accent);' : '' ?>">
                                                        <?= htmlspecialchars($sub['subject_name']) ?>
                                                    </td>
                                                    <td><input type="number" step="0.01" name="grades[<?=$sub['id']?>][q1]" value="<?=$g['q1']?>" class="grade-input" data-subject="<?= strtoupper($sub['subject_name']) ?>" data-quarter="1" <?= strtoupper($sub['subject_name']) === 'MAPEH' ? 'readonly style="background:#f1f5f9; cursor:not-allowed;"' : '' ?>></td>
                                                    <td><input type="number" step="0.01" name="grades[<?=$sub['id']?>][q2]" value="<?=$g['q2']?>" class="grade-input" data-subject="<?= strtoupper($sub['subject_name']) ?>" data-quarter="2" <?= strtoupper($sub['subject_name']) === 'MAPEH' ? 'readonly style="background:#f1f5f9; cursor:not-allowed;"' : '' ?>></td>
                                                    <td><input type="number" step="0.01" name="grades[<?=$sub['id']?>][q3]" value="<?=$g['q3']?>" class="grade-input" data-subject="<?= strtoupper($sub['subject_name']) ?>" data-quarter="3" <?= strtoupper($sub['subject_name']) === 'MAPEH' ? 'readonly style="background:#f1f5f9; cursor:not-allowed;"' : '' ?>></td>
                                                    <td><input type="number" step="0.01" name="grades[<?=$sub['id']?>][q4]" value="<?=$g['q4']?>" class="grade-input" data-subject="<?= strtoupper($sub['subject_name']) ?>" data-quarter="4" <?= strtoupper($sub['subject_name']) === 'MAPEH' ? 'readonly style="background:#f1f5f9; cursor:not-allowed;"' : '' ?>></td>
                                                    <td style="font-weight:800; color:var(--accent);"><?= $g['final_grade'] ? number_format($g['final_grade'], 0) : '—' ?></td>
                                                    <td>
                                                        <span class="status-badge <?= $g['remarks'] === 'PASSED' ? 'bg-promoted' : 'bg-retained' ?>">
                                                            <?= htmlspecialchars($g['remarks']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php
                                            };

                                            // Render Core
                                            foreach($other_subs as $sub) $renderRow($sub);

                                            // Render MAPEH Block
                                            if($mapeh_main) {
                                                echo '<tr style="background:#f8fafc;"><td colspan="7" style="font-weight:800; font-size:11px; color:var(--accent); text-transform:uppercase; letter-spacing:0.1em;">MAPEH Components & Averaging</td></tr>';
                                                $renderRow($mapeh_main);
                                                foreach($mapeh_subs as $sub) $renderRow($sub, true);
                                            }
                                            ?>
                                        </tbody>
                                        <tfoot style="background: #f8fafc; font-weight: 800; border-top: 2px solid var(--border);">
                                            <tr>
                                                <td>GENERAL AVERAGE</td>
                                                <td></td><td></td><td></td><td></td>
                                                <?php $gen_avg = ($count_final > 0) ? round($total_final / $count_final) : 0; ?>
                                                <td style="color: white; background: var(--accent);"><?= $gen_avg ?: '—' ?></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <div style="margin-top: 30px; text-align: right;">
                                        <button type="submit" class="btn btn-primary">💾 Save Quarterly Grades</button>
                                    </div>
                                </form>
                            </div>

                            <!-- Tab 2: Conduct -->
                             <div id="conduct" class="tab-pane">
                                 <form method="POST">
                                     <input type="hidden" name="action" value="save_observed_values">
                                     <input type="hidden" name="student_id" value="<?=$selected_student_id?>">
                                     
                                     <!-- Grading Scale Legend -->
                                     <div style="display: flex; gap: 15px; background: #f8fafc; padding: 12px; border-radius: 10px; margin-bottom: 20px; border: 1px dashed var(--border); justify-content: center;">
                                         <div style="font-size: 11px;"><strong>AO</strong> - Always Observed</div>
                                         <div style="font-size: 11px;"><strong>SO</strong> - Sometimes Observed</div>
                                         <div style="font-size: 11px;"><strong>RO</strong> - Rarely Observed</div>
                                         <div style="font-size: 11px;"><strong>NO</strong> - Not Observed</div>
                                     </div>

                                     <table>
                                         <thead>
                                             <tr><th style="text-align:left;">Behavior Statement</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th></tr>
                                         </thead>
                                         <tbody>
                                             <?php 
                                             $rating_meanings = [
                                                 'AO' => 'Always Observed',
                                                 'SO' => 'Sometimes Observed',
                                                 'RO' => 'Rarely Observed',
                                                 'NO' => 'Not Observed'
                                             ];
                                             foreach($core_values as $val => $stmts): ?>
                                                 <tr style="background:#f8fafc;"><td colspan="5" style="font-weight:800; color:var(--accent);"><?= $val ?></td></tr>
                                                 <?php foreach($stmts as $id => $text): ?>
                                                     <tr>
                                                         <td style="font-size:12px; color:#475569;"><?= $text ?></td>
                                                         <?php for($q=1; $q<=4; $q++): $r = $current_observed["Q$q"][$id] ?? ''; ?>
                                                             <td>
                                                                 <select name="observed[Q<?=$q?>][<?=$id?>]" style="width:100%; padding:5px; border-radius:5px; border:1px solid #ddd; font-size:12px;">
                                                                     <option value=""></option>
                                                                     <?php foreach($rating_meanings as $opt => $meaning) echo "<option value='$opt' ".($r==$opt?'selected':'').">$opt - $meaning</option>"; ?>
                                                                 </select>
                                                             </td>
                                                         <?php endfor; ?>
                                                     </tr>
                                                 <?php endforeach; ?>
                                             <?php endforeach; ?>
                                         </tbody>
                                     </table>
                                    <div style="margin-top: 30px; text-align: right;">
                                        <button type="submit" class="btn btn-primary">💾 Save Values</button>
                                    </div>
                                </form>
                            </div>

                            <!-- Tab 3: Attendance -->
                            <div id="attendance" class="tab-pane">
                                <div style="background:#f1f5f9; padding:20px; border-radius:15px; margin-bottom:20px;">
                                    <p style="margin:0; font-size:14px; color:#64748b;">Attendance data is synced from finalized SF2 reports.</p>
                                </div>
                                <table>
                                    <thead><tr><th>Month</th><th>Days of School</th><th>Days Present</th><th>Days Absent</th></tr></thead>
                                    <tbody>
                                        <?php if(empty($attendance_data)): ?>
                                            <tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">No SF2 reports found for this learner yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($attendance_data as $att): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($att['report_month']) ?></strong></td>
                                                    <td><?= $att['days_of_classes'] ?></td>
                                                    <td style="color:#15803d; font-weight:700;"><?= $att['total_present'] ?></td>
                                                    <td style="color:#991b1b;"><?= $att['total_absent'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Tab 4: Promotion & Remarks -->
                            <div id="promotion" class="tab-pane">
                                <?php 
                                    // RE-CALCULATE FOR PROMOTION TAB
                                    $total_final = 0; $count_final = 0; $failing_count = 0;
                                    $failed_subs = [];
                                    foreach($subjects as $sub) {
                                        $g = $current_grades[$sub['id']] ?? [];
                                        if(!empty($g['final_grade'])) {
                                            $total_final += $g['final_grade'];
                                            $count_final++;
                                            if($g['final_grade'] < 75) {
                                                $failing_count++;
                                                $failed_subs[] = $sub['subject_name'];
                                            }
                                        }
                                    }
                                    
                                    // Check if ALL subjects have grades
                                    $is_complete = ($count_final > 0 && $count_final === count($subjects));
                                    
                                    $gen_avg = $is_complete ? round($total_final / $count_final) : null;
                                    
                                    // Determine suggested status
                                    $suggested_promo = "";
                                    if ($is_complete) {
                                        $suggested_promo = "Promoted";
                                        if ($failing_count >= 3) $suggested_promo = "Retained";
                                        else if ($failing_count > 0) $suggested_promo = "Conditional";
                                    }

                                    // Auto-remark for Conditional
                                    $current_remarks = $sf9_report['adviser_remarks'] ?? '';
                                    if ($is_complete && $suggested_promo === 'Conditional' && empty($current_remarks)) {
                                        $current_remarks = "Needs remedial classes in: " . implode(', ', $failed_subs) . ".";
                                    }
                                    // If not complete, we should not show the previous remarks if they were saved in an incomplete state (optional but safer)
                                    if (!$is_complete) $current_remarks = "";
                                ?>
                                <div style="display:flex; gap:30px; align-items:center; background: <?= $is_complete ? '#f0f9ff' : '#f8fafc' ?>; padding: 25px; border-radius: 15px; border: 1px solid <?= $is_complete ? '#bae6fd' : '#e2e8f0' ?>; margin-bottom: 30px;">
                                    <div style="flex:1;">
                                        <span style="font-size:12px; text-transform:uppercase; opacity:0.7; color: <?= $is_complete ? '#0369a1' : '#64748b' ?>;">General Average</span>
                                        <h2 style="margin:0; font-size:48px; color: <?= $is_complete ? '#0c4a6e' : '#94a3b8' ?>;"><?= $gen_avg ?: '—' ?></h2>
                                    </div>
                                    <div style="flex:2; border-left: 2px solid <?= $is_complete ? '#bae6fd' : '#e2e8f0' ?>; padding-left: 30px;">
                                        <label style="font-weight: 700; color: <?= $is_complete ? '#0369a1' : '#64748b' ?>; display:block; margin-bottom: 5px;">Suggested Promotion Status</label>
                                        <div style="font-size: 20px; font-weight: 800; color: <?= $is_complete ? 'var(--accent)' : '#94a3b8' ?>;">
                                            <?= $is_complete ? $suggested_promo : '<span style="font-weight:400; font-style:italic;">Awaiting final grades...</span>' ?>
                                        </div>
                                    </div>
                                </div>

                                <?php 
                                    $final_status = $sf9_report['promotion_status'] ?: $suggested_promo;
                                ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_sf9_report">
                                    <input type="hidden" name="final_rating" value="<?= $gen_avg ?>">
                                    <input type="hidden" name="student_id" value="<?=$selected_student_id?>">
                                    
                                    <input type="hidden" name="promotion_status" value="<?= htmlspecialchars($suggested_promo) ?>">
                                    
                                    <div style="margin-bottom: 25px;">
                                        <label style="display:block; font-weight:700; margin-bottom:5px;">Promotion Status</label>
                                        <div style="font-size: 20px; font-weight: 800; color: var(--accent); display: flex; align-items: center; gap: 10px;">
                                            <?= $suggested_promo ?: '<span style="color:#94a3b8; font-weight:400; font-style:italic;">Awaiting final grades...</span>' ?>
                                            <?php if($suggested_promo): ?>
                                                <span class="status-badge bg-<?= strtolower($suggested_promo) ?>" style="font-size: 12px; padding: 4px 12px;">Official</span>
                                            <?php endif; ?>
                                        </div>
                                        <p style="font-size:12px; color:#64748b; margin-top:5px;">This status is automatically determined based on the learner's quarterly grades and failing subjects.</p>
                                    </div>

                                    <div style="margin-bottom: 25px;">
                                        <label style="display:block; font-weight:700; margin-bottom:10px;">Adviser's Remarks / Recommendations</label>
                                        <textarea name="adviser_remarks" rows="5" style="width:100%; padding:15px; border-radius:15px; border:1px solid var(--border); font-family:inherit;" placeholder="e.g. Learner showed consistent improvement in Mathematics."><?= htmlspecialchars($current_remarks) ?></textarea>
                                    </div>

                                    <div style="text-align: right;">
                                        <button type="submit" class="btn btn-primary">💾 Save Promotion & Remarks</button>
                                    </div>
                                </form>
                            </div>

                        <?php else: ?>
                            <div style="text-align:center; padding:150px 40px; color:#94a3b8;">
                                <div style="font-size:60px; margin-bottom:20px;">👈</div>
                                <h3>Select a student to manage their SF9 Report Card</h3>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const gradeInputs = document.querySelectorAll('.grade-input');
            const mapehComponents = ['MUSIC', 'ARTS', 'PHYSICAL EDUCATION', 'HEALTH'];

            function updateMapeh(quarter) {
                let total = 0;
                let count = 0;
                
                mapehComponents.forEach(comp => {
                    const input = document.querySelector(`.grade-input[data-subject="${comp}"][data-quarter="${quarter}"]`);
                    if (input && input.value) {
                        total += parseFloat(input.value);
                        count++;
                    }
                });

                const mapehInput = document.querySelector(`.grade-input[data-subject="MAPEH"][data-quarter="${quarter}"]`);
                if (mapehInput) {
                    if (count > 0) {
                        mapehInput.value = Math.round(total / count);
                    } else {
                        mapehInput.value = '';
                    }
                }
            }

            gradeInputs.forEach(input => {
                input.addEventListener('input', function() {
                    const subject = this.getAttribute('data-subject');
                    const quarter = this.getAttribute('data-quarter');
                    
                    if (mapehComponents.includes(subject)) {
                        updateMapeh(quarter);
                    }
                });
            });
        });
    </script>
</body>
</html>
