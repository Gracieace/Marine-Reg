<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['admin']);
require_once __DIR__ . '/../../../config/db.php';

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

$permanent_record = null;
if ($student_id) {
    // 1. Get Student Personal Info
    $stmt = $pdo->prepare("SELECT e.*, r.lrn, r.sex, r.birthdate, r.father_first, r.father_last, r.mother_first, r.mother_last
                           FROM enrollments e 
                           LEFT JOIN registrations r ON (r.id = e.registration_id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
                           WHERE e.student_id = ? AND e.school_year = ?");
    $stmt->execute([$student_id, $school_year]);
    $permanent_record['personal'] = $stmt->fetch();

    // 2. Get Academic History (All years found in enrollments or archives)
    $hist_stmt = $pdo->prepare("SELECT grade_level, section, school_year FROM enrollments WHERE student_id = ? ORDER BY school_year ASC");
    $hist_stmt->execute([$student_id]);
    $history = $hist_stmt->fetchAll();

    foreach ($history as $idx => $year_data) {
        $g_stmt = $pdo->prepare("SELECT g.*, s.subject_name 
                                 FROM grades g 
                                 JOIN subjects s ON g.subject_id = s.id 
                                 WHERE g.student_id = ? AND g.school_year = ?");
        $g_stmt->execute([$student_id, $year_data['school_year']]);
        $history[$idx]['grades'] = $g_stmt->fetchAll();
    }
    $permanent_record['history'] = $history;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF10 - Permanent Record | Admin</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #eaeff2; margin: 0; padding: 20px; padding-top: var(--header-height); color: #1e293b; }
        .container { max-width: 100%; margin: 0; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .no-print { margin-bottom: 25px; }
        
        .letterhead { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #3b82f6; padding-bottom: 20px; }
        .letterhead h1 { font-size: 18px; margin: 5px 0; color: #1e40af; }
        .letterhead h2 { font-size: 16px; margin: 5px 0; font-weight: 500; }
        
        .data-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 30px; font-size: 12px; }
        .data-item { display: flex; border-bottom: 1px solid #e2e8f0; padding: 4px 0; }
        .data-label { font-weight: 700; width: 140px; color: #64748b; }
        
        .year-block { margin-bottom: 40px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; }
        .year-header { background: #f1f5f9; padding: 12px 15px; border-bottom: 1px solid #cbd5e1; display: flex; justify-content: space-between; font-weight: 700; font-size: 13px; color: #334155; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 12px; font-size: 11px; text-align: center; }
        th { background: #f8fafc; color: #334155; }
        .subj-name { text-align: left; width: 50%; font-weight: 500; }
        
        .summary-row { font-weight: 700; background: #f8fafc; }
        
        @media print {
            .no-print, .header-panel, .sidebar { display: none !important; }
            body { background: white; padding: 0 !important; }
            .container { box-shadow: none; border: none; max-width: 100%; padding: 0; margin-top: 0; }
            .year-block { border: 1px solid #000; border-radius: 0; }
            .year-header, table, th, td { border-color: #000; }
            @page { size: portrait; margin: 15mm; }
        }
        
        .filter-form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; background: #fff; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 20px; }
        .form-select { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; min-width: 150px; }
        .btn-print { padding: 10px 20px; background: #1e293b; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
    </style>
</head>
<body>
    <?php include '../../../admin_header.php'; ?>
    <?php include '../../admin_sidebar.php'; ?>

    <div class="container main-content">
        <div class="no-print">
            <a href="dashboard.php" style="text-decoration: none; color: #64748b; font-weight: 600; display: block; margin-bottom: 20px;">← Back to Dashboard</a>
            <form class="filter-form" method="GET">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px;">Grade Level</label>
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
                    <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px;">Section</label>
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
                    <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px;">Student</label>
                    <select name="student_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Select Student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= $s['student_id'] ?>" <?= $student_id===$s['student_id'] ? 'selected':'' ?>><?= htmlspecialchars($s['student_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($student_id): ?>
                    <button type="button" class="btn-print" onclick="window.print()">Print Record</button>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($permanent_record): ?>
            <div class="letterhead">
                <h1 style="font-weight: 800; text-transform: uppercase;">Learner's Permanent Record (SF10-ES)</h1>
                <p style="margin: 0; color: #64748b; font-weight: 600;">Admin Monitoring View</p>
            </div>

            <div class="data-grid">
                <div class="data-item"><span class="data-label">LRN:</span> <?= htmlspecialchars($permanent_record['personal']['lrn']) ?></div>
                <div class="data-item"><span class="data-label">Sex:</span> <?= htmlspecialchars($permanent_record['personal']['sex']) ?></div>
                <div class="data-item"><span class="data-label">Last Name:</span> <?= htmlspecialchars($permanent_record['personal']['last_name'] ?? explode(' ', $permanent_record['personal']['student_name'])[0]) ?></div>
                <div class="data-item"><span class="data-label">First Name:</span> <?= htmlspecialchars($permanent_record['personal']['first_name'] ?? '') ?></div>
                <div class="data-item"><span class="data-label">Birthdate:</span> <?= htmlspecialchars($permanent_record['personal']['birthdate']) ?></div>
                <div class="data-item"><span class="data-label">School:</span> Malolos Marine Fishery School</div>
            </div>

            <?php foreach ($permanent_record['history'] as $year): ?>
                <div class="year-block">
                    <div class="year-header">
                        <span>Grade: <?= htmlspecialchars($year['grade_level']) ?></span>
                        <span>Section: <?= htmlspecialchars($year['section']) ?></span>
                        <span>School Year: <?= htmlspecialchars($year['school_year']) ?></span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th class="subj-name">Learning Areas</th>
                                <th>Final Rating</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total = 0;
                            $count = 0;
                            foreach ($year['grades'] as $g): 
                                $final = $g['final_grade'];
                                if (!$final) {
                                    $qs = array_filter([$g['q1'], $g['q2'], $g['q3'], $g['q4']], fn($v) => !is_null($v));
                                    if (!empty($qs)) $final = array_sum($qs) / count($qs);
                                }
                                if ($final > 0) {
                                    $total += $final;
                                    $count++;
                                }
                            ?>
                                <tr>
                                    <td class="subj-name"><?= htmlspecialchars($g['subject_name']) ?></td>
                                    <td><?= $final ? round($final, 0) : '-' ?></td>
                                    <td><?= $final >= 75 ? 'PASSED' : ($final > 0 ? 'FAILED' : '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="summary-row">
                                <td class="subj-name">General Average</td>
                                <td><?= ($count > 0) ? round($total/$count, 0) : '-' ?></td>
                                <td>
                                    <?php 
                                    if ($count > 0) {
                                        echo ($total/$count >= 75) ? 'PROMOTED' : 'RETAINED';
                                    } else { echo '-'; }
                                    ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endforeach; ?>

            <?php $principal_name = get_system_setting($pdo, 'principal_name', 'School Head'); ?>
            <div style="margin-top: 50px; display: flex; justify-content: flex-end;">
                <div style="text-align: center; width: 250px;">
                    <div style="border-bottom: 1px solid #000; font-weight: 700; text-transform: uppercase;"><?= strtoupper($principal_name) ?></div>
                    <div style="font-size: 11px; margin-top: 5px;">School Head / Principal</div>
                    <div style="font-size: 10px; color: #64748b;">(Signature over Printed Name)</div>
                </div>
            </div>
            <div style="margin-top: 30px; font-size: 11px; font-style: italic; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                * Admin portal archive view. Official copies must be printed from the Registrar's portal.
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:100px; color:#64748b;">
                <div style="font-size:48px; margin-bottom:20px;">📜</div>
                <h3>Select a student to view their permanent academic record.</h3>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
