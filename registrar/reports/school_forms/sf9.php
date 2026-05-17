<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/report_export_helper.php';

$pdo = db_connect();

$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';
$student_id = $_GET['student_id'] ?? '';

// Get default school year if not set
if (!$school_year) {
    $sy_stmt = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC LIMIT 1");
    $school_year = $sy_stmt->fetchColumn();
}

$students = [];
if ($grade_level && $section) {
    $stmt = $pdo->prepare("SELECT e.student_id, e.student_name, r.lrn, r.sex 
                           FROM enrollments e 
                           LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
                           WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
                           ORDER BY r.sex, e.student_name");
    $stmt->execute([$grade_level, $section, $school_year]);
    $students = $stmt->fetchAll();
}

$report_card = null;
if ($student_id) {
    // 1. Get Student Info
    $stmt = $pdo->prepare("SELECT e.*, r.lrn, r.sex, r.birthdate 
                           FROM enrollments e 
                           LEFT JOIN registrations r ON (r.id = e.registration_id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
                           WHERE e.student_id = ? AND e.school_year = ?");
    $stmt->execute([$student_id, $school_year]);
    $report_card['student'] = $stmt->fetch();

    // 2. Get Grades
    $stmt = $pdo->prepare("SELECT g.*, s.subject_name, s.subject_code 
                           FROM grades g 
                           JOIN subjects s ON g.subject_id = s.id 
                           WHERE g.student_id = ? AND g.school_year = ?");
    $stmt->execute([$student_id, $school_year]);
    $report_card['grades'] = $stmt->fetchAll();

    // Handle export
    if (isset($_GET['export'])) {
        $filename = 'sf9_' . $student_id . '_' . $school_year;
        if ($_GET['export'] === 'excel') {
            $data = array_map(function($g) {
                return [
                    'Subject' => $g['subject_name'],
                    'Q1' => $g['q1'], 'Q2' => $g['q2'], 'Q3' => $g['q3'], 'Q4' => $g['q4'],
                    'Final' => round($g['final_grade'] ?: 0, 0)
                ];
            }, $report_card['grades']);
            exportToExcel($data, ['SUBJECT', 'Q1', 'Q2', 'Q3', 'Q4', 'FINAL'], $filename, 'SF9 Report Card');
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF9 - Individual Progress Card</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .no-print { margin-bottom: 25px; }
        .card-header { text-align: center; border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 20px; }
        .card-header h1 { font-size: 20px; color: #0d47a1; margin: 0; }
        .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; font-size: 14px; }
        .student-info div { border-bottom: 1px solid #f1f5f9; padding: 5px 0; }
        .student-info label { font-weight: 600; color: #64748b; margin-right: 10px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: center; }
        th { background: #f8fafc; color: #1e293b; font-size: 12px; }
        .subject-name { text-align: left; font-weight: 500; }
        
        .footer-sig { display: grid; grid-template-columns: 1fr 1fr; gap: 100px; margin-top: 50px; text-align: center; }
        .sig-line { border-top: 1px solid #000; margin-top: 40px; padding-top: 5px; font-weight: 600; text-transform: uppercase; }
        
        @media print {
            .no-print { display: none; }
            body { background: white; padding: 0; }
            .container { box-shadow: none; max-width: 100%; padding: 0; }
            @page { size: portrait; margin: 15mm; }
        }
        
        .btn-print { padding: 10px 20px; background: #0d47a1; color: white; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
        .filter-form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; background: #f8fafc; padding: 20px; border-radius: 8px; }
        .form-select { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; min-width: 150px; }
    </style>
</head>
<body>
    <div class="container main-content">
        <div class="no-print">
            <a href="dashboard.php" style="text-decoration: none; color: #64748b; font-weight: 600; display: block; margin-bottom: 20px;">← Back to Dashboard</a>
            <form class="filter-form" method="GET">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Grade Level</label>
                    <select name="grade_level" class="form-select" onchange="this.form.submit()">
                        <option value="">Select Grade</option>
                        <?php 
                        $gls = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($gls as $gl): ?>
                            <option value="<?= $gl ?>" <?= $grade_level===$gl ? 'selected':'' ?>><?= $gl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Section</label>
                    <select name="section" class="form-select" onchange="this.form.submit()">
                        <option value="">Select Section</option>
                        <?php 
                        if ($grade_level) {
                            $sects = $pdo->prepare("SELECT DISTINCT section FROM enrollments WHERE grade_level=? ORDER BY section");
                            $sects->execute([$grade_level]);
                            foreach ($sects->fetchAll(PDO::FETCH_COLUMN) as $s): ?>
                                <option value="<?= $s ?>" <?= $section===$s ? 'selected':'' ?>><?= $s ?></option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Student</label>
                    <select name="student_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Select Student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= $s['student_id'] ?>" <?= $student_id===$s['student_id'] ? 'selected':'' ?>><?= htmlspecialchars($s['student_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($student_id): ?>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-print" onclick="window.print()">Print Card</button>
                        <a href="?export=excel&student_id=<?= $student_id ?>&school_year=<?= $school_year ?>&grade_level=<?= $grade_level ?>&section=<?= $section ?>" class="btn-print" style="background: #10b981; text-decoration: none;">📊 Excel</a>
                    </div>
                <?php endif; ?>
            </form>
            <?php if (!empty($students)): ?>
                <div style="margin-top: 15px;">
                    <input type="text" id="reportSearch" placeholder="🔍 Search student in list..." 
                           style="padding: 10px 15px; width: 100%; border: 1px solid #ddd; border-radius: 8px; outline: none;">
                </div>
            <?php endif; ?>
        </div>

        <?php if ($report_card): ?>
            <div class="card-header">
                <h1>SCHOOL FORM 9 (SF9) - INDIVIDUAL PROGRESS CARD</h1>
                <p style="margin:5px 0; font-size:12px; font-weight:600;">Republic of the Philippines | Department of Education</p>
            </div>

            <div class="student-info">
                <div><label>Name:</label> <?= htmlspecialchars($report_card['student']['student_name']) ?></div>
                <div><label>LRN:</label> <?= htmlspecialchars($report_card['student']['lrn']) ?></div>
                <div><label>Grade & Section:</label> <?= htmlspecialchars($report_card['student']['grade_level'] . ' - ' . $report_card['student']['section']) ?></div>
                <div><label>School Year:</label> <?= htmlspecialchars($school_year) ?></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 40%">Learning Areas</th>
                        <th colspan="4">Quarterly Rating</th>
                        <th rowspan="2">Final Rating</th>
                        <th rowspan="2">Action Taken</th>
                    </tr>
                    <tr>
                        <th>1</th><th>2</th><th>3</th><th>4</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_final = 0;
                    $subj_count = 0;
                    foreach ($report_card['grades'] as $g): 
                        $final = $g['final_grade'];
                        if (!$final) {
                            $qs = array_filter([$g['q1'], $g['q2'], $g['q3'], $g['q4']], fn($v) => !is_null($v));
                            if (!empty($qs)) $final = array_sum($qs) / count($qs);
                        }
                        if ($final > 0) {
                            $total_final += $final;
                            $subj_count++;
                        }
                    ?>
                        <tr>
                            <td class="subject-name"><?= htmlspecialchars($g['subject_name']) ?></td>
                            <td><?= $g['q1'] ?: '-' ?></td>
                            <td><?= $g['q2'] ?: '-' ?></td>
                            <td><?= $g['q3'] ?: '-' ?></td>
                            <td><?= $g['q4'] ?: '-' ?></td>
                            <td style="font-weight:700;"><?= $final ? round($final, 0) : '-' ?></td>
                            <td style="font-size:10px;"><?= $final >= 75 ? 'PASSED' : ($final > 0 ? 'FAILED' : '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right; font-weight:700;">General Average</td>
                        <td style="font-weight:700; background:#f8fafc; font-size:14px;">
                            <?= ($subj_count > 0) ? round($total_final / $subj_count, 0) : '-' ?>
                        </td>
                        <td style="font-weight:700;">
                            <?php 
                            if ($subj_count > 0) {
                                $avg = $total_final / $subj_count;
                                echo $avg >= 75 ? 'PROMOTED' : 'RETAINED';
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div class="footer-sig">
                <div>
                    <div class="sig-line">Class Adviser</div>
                </div>
                <div>
                    <div class="sig-line">School Head</div>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:100px; color:#64748b;">
                <div style="font-size:48px; margin-bottom:20px;">📑</div>
                <h3>Select a student to generate their report card.</h3>
            </div>
        <?php endif; ?>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('reportSearch');
            const studentSelect = document.querySelector('select[name="student_id"]');
            
            if (searchInput && studentSelect) {
                searchInput.addEventListener('keyup', function() {
                    const term = this.value.toLowerCase();
                    const options = studentSelect.options;
                    for (let i = 0; i < options.length; i++) {
                        const text = options[i].text.toLowerCase();
                        options[i].style.display = text.includes(term) || options[i].value==='' ? '' : 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>
