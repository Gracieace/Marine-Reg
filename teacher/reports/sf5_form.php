<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user = auth_user();
$teacher_id = $user['id'] ?? '';

// 1. Initialize Schema
try {
    if (function_exists('initialize_schema')) {
        initialize_schema($pdo);
    }
} catch (Exception $e) {}

// 2. Fetch Advisory Info
$stmt = $pdo->prepare("SELECT DISTINCT grade_level, section, school_year 
                       FROM position_assignments 
                       WHERE user_id = ? AND position_type = 'class_adviser'
                       ORDER BY school_year DESC, grade_level ASC");
$stmt->execute([$teacher_id]);
$all_advisories = $stmt->fetchAll();

// Fallback to sections table
if (empty($all_advisories)) {
    $stmt = $pdo->prepare("SELECT grade_level, section_name as section, school_year 
                           FROM sections 
                           WHERE adviser_id = ? 
                           ORDER BY school_year DESC");
    $stmt->execute([$teacher_id]);
    $all_advisories = $stmt->fetchAll();
}

// Determine Selected Values
$sy = $_GET['sy'] ?? ($_GET['school_year'] ?? get_active_school_year($pdo));

// Helper: Normalize
$norm = function($s) { return trim(str_ireplace(['Grade', 'Section'], '', (string)$s)); };

// Find active advisory
$advisory = null;
foreach ($all_advisories as $adv) {
    if ($adv['school_year'] == $sy) {
        if (isset($_GET['grade']) && isset($_GET['section'])) {
            if ($norm($_GET['grade']) == $norm($adv['grade_level']) && $norm($_GET['section']) == $norm($adv['section'])) {
                $advisory = $adv;
                break;
            }
        } else {
            $advisory = $adv;
            break;
        }
    }
}

$grade = $_GET['grade'] ?? ($advisory['grade_level'] ?? '');
$section = $_GET['section'] ?? ($advisory['section'] ?? '');

$q_grade = $norm($grade);
$q_section = $norm($section);

// 3. Fetch Students & Grades
$students = [];
$subjects = [];
if ($grade && $section) {
    // Subjects
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE grade_level = ? OR grade_level LIKE ? OR grade_level = ? ORDER BY id ASC");
    $stmt->execute([$grade, "%$q_grade%", $q_grade]);
    $subjects = $stmt->fetchAll();
    if (empty($subjects)) {
        $stmt = $pdo->prepare("SELECT id, subject_name, subject_code FROM curriculum WHERE grade_level = ? OR grade_level = ? ORDER BY subject_name");
        $stmt->execute([$grade, $q_grade]);
        $subjects = $stmt->fetchAll();
    }

    // Students - Optimized for maximum profile matching (Registrations -> Students -> Enrollments)
    $stmt = $pdo->prepare("SELECT e.student_id, e.student_name, e.lrn as e_lrn, 
                           COALESCE(r.lrn, s.student_id, e.lrn) as r_lrn, 
                           COALESCE(r.first_name, s.first_name, '') as first_name,
                           COALESCE(r.middle_name, s.middle_name, '') as middle_name,
                           COALESCE(r.last_name, s.last_name, '') as last_name,
                           COALESCE(NULLIF(TRIM(r.sex), ''), NULLIF(TRIM(s.sex), ''), 'M') as r_sex
                           FROM enrollments e 
                           LEFT JOIN registrations r ON (e.registration_id = r.id OR (TRIM(e.lrn) = TRIM(r.lrn) AND e.lrn != ''))
                           LEFT JOIN students s ON (e.student_id = s.student_id OR (TRIM(e.lrn) = TRIM(s.student_id) AND e.lrn != ''))
                           WHERE (e.grade_level = ? OR e.grade_level = ? OR e.grade_level LIKE ?) 
                           AND (e.section = ? OR e.section = ? OR e.section LIKE ?) 
                           AND (e.school_year = ? OR e.school_year LIKE ?)
                           ORDER BY CASE WHEN COALESCE(NULLIF(TRIM(r.sex), ''), NULLIF(TRIM(s.sex), ''), 'M') IN ('F', 'Female', '2') THEN 2 ELSE 1 END ASC, 
                                    COALESCE(r.last_name, s.last_name, e.student_name) ASC");
    $stmt->execute([$grade, $q_grade, "%$q_grade%", $section, $q_section, "%$q_section%", $sy, "%$sy%"]);
    $students = $stmt->fetchAll();

    // Grades
    $all_grades = [];
    if (!empty($students)) {
        $stmt = $pdo->prepare("SELECT * FROM sf9_grades WHERE school_year = ? AND student_id IN (
            SELECT student_id FROM enrollments WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) AND (section = ? OR section = ? OR section LIKE ?) AND (school_year = ? OR school_year LIKE ?)
        )");
        $stmt->execute([$sy, $grade, $q_grade, "%$q_grade%", $section, $q_section, "%$q_section%", $sy, "%$sy%"]);
        while ($row = $stmt->fetch()) { $all_grades[$row['student_id']][$row['subject_id']] = $row; }
    }

    // 4. Automated & Read-Only SF9 & SF5 Synchronization
    $sf9_data = [];
    $stmt = $pdo->prepare("SELECT * FROM sf9_reports WHERE (school_year = ? OR school_year LIKE ?) AND student_id IN (
        SELECT student_id FROM enrollments WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) AND (section = ? OR section = ? OR section LIKE ?) AND (school_year = ? OR school_year LIKE ?)
    )");
    $stmt->execute([$sy, "%$sy%", $grade, $q_grade, "%$q_grade%", $section, $q_section, "%$q_section%", $sy, "%$sy%"]);
    while ($row = $stmt->fetch()) { $sf9_data[$row['student_id']] = $row; }

    // Proactively query and prepare SF9 data for dynamic rendering
    if (!empty($students)) {
        $sync_notice = true;
    }
} // Ends if ($grade && $section)

function get_proficiency($avg) {
    if ($avg >= 90) return ['Advanced', '#10b981'];
    if ($avg >= 85) return ['Proficient', '#3b82f6'];
    if ($avg >= 80) return ['Approaching Proficiency', '#8b5cf6'];
    if ($avg >= 75) return ['Developing', '#f59e0b'];
    if ($avg > 0) return ['Beginning', '#ef4444'];
    return ['N/A', '#94a3b8'];
}

$is_saved = false;
if ($grade && $section) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sf5_reports WHERE school_year = ? AND grade_level = ? AND section = ?");
    $stmt->execute([$sy, $grade, $section]);
    $is_saved = $stmt->fetchColumn() > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF5 - Report on Promotion</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #0f172a; --accent: #2563eb; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0; }
        body { font-family: 'Outfit', sans-serif; background: var(--bg); margin: 0; color: #334155; }
        .main-content { padding-top: 80px !important; margin-left: 260px; min-height: 100vh; transition: 0.3s; }
        .container { max-width: 1400px; margin: auto; padding: 30px; }
        .header-card { background: var(--card); padding: 30px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin-bottom: 30px; border: 1px solid var(--border); }
        .filter-bar { display: flex; gap: 15px; margin-top: 25px; flex-wrap: wrap; }
        .filter-group select { padding: 12px 18px; border-radius: 12px; border: 1.5px solid var(--border); background: #f8fafc; font-weight: 600; min-width: 180px; font-family: inherit; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--card); padding: 25px; border-radius: 20px; border: 1px solid var(--border); text-align: center; }
        .stat-val { font-size: 32px; font-weight: 800; color: var(--primary); }
        .table-card { background: var(--card); border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8fafc; padding: 18px 15px; text-align: left; font-weight: 700; text-transform: uppercase; font-size: 11px; color: #64748b; border-bottom: 2px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); }
        .sticky-col { position: sticky; left: 0; background: white; z-index: 10; border-right: 1px solid var(--border); }
        th.sticky-col { background: #f8fafc; z-index: 11; }
        .grade-pill { padding: 5px 10px; border-radius: 8px; font-weight: 700; background: #f1f5f9; display: inline-block; min-width: 35px; text-align: center; }
        .grade-pill.fail { background: #fee2e2; color: #ef4444; }
        .status-badge { padding: 6px 14px; border-radius: 50px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .bg-promoted { background: #dcfce7; color: #15803d; }
        .bg-conditional { background: #fef3c7; color: #92400e; }
        .bg-retained { background: #fee2e2; color: #b91c1c; }
        .proficiency-badge { padding: 5px 12px; border-radius: 6px; font-size: 10px; font-weight: 700; color: white; }
        .btn { padding: 12px 24px; border-radius: 12px; border: none; cursor: pointer; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 10px; transition: 0.3s; font-family: inherit; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-outline { border: 2px solid var(--border); color: #475569; background: white; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>
    <div class="main-content">
        <div class="container">
            <div class="header-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
                    <div>
                        <h1 style="margin:0; font-size:28px; font-weight:800; color:var(--primary);">SF5 Management</h1>
                        <p style="margin:8px 0 0; color:#64748b; font-size:15px;">
                            Class: <strong><?= $q_grade ? "Grade $q_grade" : 'N/A' ?> - <?= $section ?: 'N/A' ?></strong> | SY: <strong><?= $sy ?></strong>
                        </p>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <?php if($grade && $section && !empty($students)): ?>

                            <a href="sf5_print.php?sy=<?=$sy?>&grade=<?=$grade?>&section=<?=$section?>" target="_blank" class="btn btn-primary">🖨️ Print Preview</a>
                        <?php endif; ?>
                    </div>
                </div>
                <form class="filter-bar" method="GET">
                    <div class="filter-group">
                        <select name="sy" onchange="this.form.submit()">
                            <?php 
                            $years = array_unique(array_column($all_advisories, 'school_year'));
                            if(empty($years)) $years = ['2026-2027', '2025-2026'];
                            foreach($years as $y) echo "<option value='$y' ".($sy==$y?'selected':'').">$y</option>";
                            ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <select name="grade">
                            <option value="">-- Grade --</option>
                            <?php 
                            $sy_advs = array_filter($all_advisories, function($a) use ($sy) { return $a['school_year'] == $sy; });
                            $grades = array_unique(array_column($sy_advs, 'grade_level'));
                            foreach($grades as $g) echo "<option value='$g' ".($norm($grade)==$norm($g)?'selected':'').">Grade $g</option>";
                            ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <select name="section">
                            <option value="">-- Section --</option>
                            <?php 
                            foreach($sy_advs as $adv) {
                                if($norm($grade) == $norm($adv['grade_level']) || !$grade) {
                                    echo "<option value='{$adv['section']}' ".($norm($section)==$norm($adv['section'])?'selected':'').">{$adv['section']}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">🔍 Load Records</button>
                </form>
            </div>

            <?php if(isset($sync_notice) && $sync_notice): ?>
                <div style="background: #eff6ff; color: #1e40af; padding: 12px 20px; border-radius: 12px; margin-bottom: 25px; font-weight: 600; display: flex; align-items: center; gap: 10px; border: 1px solid #bfdbfe; font-size: 14px;">
                    <span style="font-size: 18px;">🔄</span> All learner data has been automatically synchronized with the latest SF9 Quarterly Grades.
                </div>
            <?php endif; ?>


            <?php if(!empty($students)): ?>
                <?php
                    $total_m = 0; $total_f = 0; $promoted_m = 0; $promoted_f = 0; $cond_m = 0; $cond_f = 0; $ret_m = 0; $ret_f = 0;
                ?>
                <div class="table-card"><div style="overflow-x: auto;"><table>
                    <thead><tr>
                        <th class="sticky-col">LRN</th>
                        <th class="sticky-col" style="left: 100px; width: 350px;">Learner's Name</th>
                        <th style="text-align: center; width: 100px;">Sex</th>
                        <th style="text-align: center; width: 150px;">General Average</th>
                        <th style="text-align: center; width: 180px;">Action Taken</th>
                        <th style="text-align: center;">Remarks / Learning Areas Not Met</th>
                    </tr></thead>
                    <tbody><?php foreach($students as $s): 
                        $s_sex = $s['r_sex'] ?: 'M';
                        $sf9 = $sf9_data[$s['student_id']] ?? null;
                        
                        // Automated Detection for Display
                        $failed_subjects_disp = [];
                        $sum_final_disp = 0; $count_final_disp = 0;
                        foreach($subjects as $sub) {
                            $g_disp = $all_grades[$s['student_id']][$sub['id']] ?? null;
                            if(!empty($g_disp['final_grade'])) {
                                $sum_final_disp += $g_disp['final_grade'];
                                $count_final_disp++;
                                if($g_disp['final_grade'] < 75) {
                                    $failed_subjects_disp[] = $sub['subject_code'] ?? $sub['subject_name'];
                                }
                            }
                        }

                        // 1. Calculate General Average Dynamically (Mirroring SF9 Quarterly Grades Tab)
                        $gen_avg = ($count_final_disp > 0) ? round($sum_final_disp / $count_final_disp) : null;

                        // 2. Automated Promotion Logic
                        $num_fails = count($failed_subjects_disp);
                        $status = "PROMOTED";
                        if ($num_fails >= 3) $status = "RETAINED";
                        elseif ($num_fails >= 1) $status = "CONDITIONAL";
                        if ($count_final_disp == 0) $status = "—"; // Incomplete

                        // Stats tracking
                        $s_sex_norm = ($s_sex == 'F' || $s_sex == 'Female' || $s_sex == '2') ? 'F' : 'M';
                        if($s_sex_norm == 'M') $total_m++; else $total_f++;
                        if($status == "PROMOTED") { if($s_sex_norm == 'M') $promoted_m++; else $promoted_f++; }
                        elseif($status == "CONDITIONAL") { if($s_sex_norm == 'M') $cond_m++; else $cond_f++; }
                        elseif($status == "RETAINED") { if($s_sex_norm == 'M') $ret_m++; else $ret_f++; }
                    ?><tr>
                        <td class="sticky-col" style="color: #64748b; font-weight: 600;"><?= $s['r_lrn'] ?: $s['e_lrn'] ?></td>
                        <td class="sticky-col" style="left: 100px; font-weight: 700;"><?= $s['last_name'] ? strtoupper("{$s['last_name']}, {$s['first_name']} {$s['middle_name']}") : strtoupper($s['student_name']) ?></td>
                        <td style="text-align: center; font-weight: 600;"><?= $s_sex ?></td>
                        <td style="text-align: center; font-weight: 800; color: var(--accent); font-size: 15px;"><?= $gen_avg ? number_format($gen_avg, 0) : '—' ?></td>
                        <td style="text-align: center;"><?php if($status != "—"): ?><span class="status-badge bg-<?=strtolower($status)?>"><?= $status ?></span><?php endif; ?></td>
                        <td style="text-align: center; font-size: 12px; font-weight: 700; color: #b91c1c;"><?= !empty($failed_subjects_disp) ? implode(', ', $failed_subjects_disp) : '—' ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div></div>
            <?php else: ?>
                <div style="text-align:center; padding:100px; color:#94a3b8; background:white; border-radius:20px; border:1px solid var(--border);">
                    <div style="font-size:60px; margin-bottom:20px;">📂</div>
                    <h3>No students found for this class.</h3>
                    <p>Select Grade/Section and click "Load Records". Ensure students are enrolled for SY <?= $sy ?>.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>