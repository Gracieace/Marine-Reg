<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = trim($_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');
$sy = trim($_GET['sy'] ?? '');
$view_type = $_GET['view'] ?? ($role === 'admin' ? 'monitor' : 'cards');

// Bidirectional normalization
$grade_clean = trim(str_ireplace('Grade', '', $grade));
$grade_with_prefix = "Grade " . $grade_clean;
$section_clean = trim(str_ireplace('Section', '', $section));
$section_with_prefix = "Section " . $section_clean;
$sy_clean = str_replace(' ', '', $sy);
$sy_with_spaces = str_replace('-', ' - ', $sy_clean);

// 1. Fetch Subjects (Needed for Monitor View)
$stmt = $pdo->prepare("SELECT id, subject_name FROM curriculum WHERE (grade_level = ? OR grade_level = ? OR grade_level LIKE ?) ORDER BY subject_name");
$stmt->execute([$grade, $grade_clean, "%$grade_clean%"]);
$subjects = $stmt->fetchAll();

// 2. Fetch Students
$stmt = $pdo->prepare("
    SELECT e.student_id, e.student_name, e.lrn, r.sex 
    FROM enrollments e 
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.lrn = r.lrn AND e.lrn IS NOT NULL AND e.lrn != ''))
    WHERE (e.grade_level = ? OR e.grade_level = ? OR e.grade_level LIKE ?)
    AND (e.section = ? OR e.section = ? OR e.section LIKE ?)
    AND (e.school_year = ? OR e.school_year LIKE ? OR e.school_year = ?)
    ORDER BY CASE WHEN COALESCE(r.sex, 'M') IN ('F', 'Female', '2') THEN 2 ELSE 1 END ASC, 
             COALESCE(r.last_name, e.student_name) ASC
");
$stmt->execute([
    $grade, $grade_clean, "%$grade_clean%", 
    $section, $section_clean, "%$section_clean%", 
    $sy, "%$sy_clean%", $sy_with_spaces
]);
$students = $stmt->fetchAll();

// 3. Fetch Grades (If Monitor View)
$grade_map = [];
if ($view_type === 'monitor') {
    $stmt = $pdo->prepare("
        SELECT * FROM sf9_grades 
        WHERE (school_year = ? OR school_year = ?)
        AND student_id IN (
            SELECT student_id FROM enrollments 
            WHERE (grade_level = ? OR grade_level = ?) AND (section = ? OR section = ?) AND (school_year = ? OR school_year = ?)
        )
    ");
    $stmt->execute([$sy, $sy_clean, $grade, $grade_clean, $section, $section_clean, $sy, $sy_clean]);
    while ($row = $stmt->fetch()) { $grade_map[$row['student_id']][$row['subject_id']] = $row; }
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF9 Portal | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --deped-blue: #0d47a1; --deped-red: #ef4444; --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.15); }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        .main-content { padding: 100px 40px 48px; margin-left: 0; }
        .portal-card { 
            background: white; border-radius: 24px; padding: 40px; box-shadow: var(--glass-shadow); 
            border: 1px solid rgba(226, 232, 240, 0.8); margin-top: 20px; position: relative; overflow: hidden;
            width: 100%; max-width: 1400px; margin-left: auto; margin-right: auto;
        }
        .portal-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--deped-blue), #2563eb); }
        
        /* View Switcher */
        .view-switcher { display: flex; background: #f1f5f9; padding: 5px; border-radius: 14px; width: fit-content; margin: 0 auto 30px; border: 1px solid #e2e8f0; }
        .view-btn { padding: 10px 25px; border-radius: 10px; border: none; background: none; font-weight: 700; font-size: 13px; color: #64748b; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; text-decoration: none; }
        .view-btn.active { background: white; color: var(--deped-blue); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }

        .official-header { display: flex; align-items: center; justify-content: center; gap: 50px; margin-bottom: 30px; padding-bottom: 25px; border-bottom: 2px solid #f1f5f9; }
        .deped-logo { width: 80px; height: auto; }
        .header-text { text-align: center; }
        .header-text h1 { margin: 8px 0; font-size: 26px; color: var(--deped-blue); font-family: 'Outfit', sans-serif; font-weight: 800; }
        .monitoring-badge { display: inline-block; padding: 5px 15px; background: #fef2f2; color: var(--deped-red); border-radius: 50px; font-size: 10px; font-weight: 800; text-transform: uppercase; margin-top: 10px; }

        /* Monitor View Table */
        .table-responsive { overflow-x: auto; border-radius: 16px; border: 1px solid #e2e8f0; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #cbd5e1; padding: 10px 8px; text-align: center; }
        th { background: #f8fafc; font-weight: 800; color: #334155; text-transform: uppercase; }
        .student-name { text-align: left; min-width: 180px; font-weight: 700; background: #fff; position: sticky; left: 0; z-index: 5; color: #0f172a; }
        .grade-cell { font-weight: 700; color: #475569; }
        .grade-cell.passed { color: #16a34a; }
        .grade-cell.failed { color: #dc2626; }

        /* Card View */
        .student-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px; }
        .student-card { background: #fff; border: 1px solid #e2e8f0; padding: 25px; border-radius: 20px; display: flex; align-items: center; gap: 20px; text-decoration: none; color: inherit; transition: 0.3s; position: relative; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .student-card:hover { transform: translateY(-5px); border-color: var(--deped-blue); box-shadow: 0 15px 30px rgba(0,0,0,0.08); }
        .avatar { width: 65px; height: 65px; border-radius: 16px; background: #eff6ff; color: var(--deped-blue); display: flex; align-items: center; justify-content: center; font-weight: 800; font-family: 'Outfit', sans-serif; font-size: 26px; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .female .avatar { background: #fff1f2; color: #e11d48; }

        @media print { .no-print, .sidebar, .header-panel, .view-switcher { display: none !important; } .main-content { padding: 0 !important; } }
    </style>
</head>
<body>
    <?php include $header_file; ?>

    <div class="main-content">
        <div class="view-switcher no-print">
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'monitor'])) ?>" class="view-btn <?= $view_type === 'monitor' ? 'active' : '' ?>">
                <i class="bi bi-grid-3x3"></i> Class Monitor (Auditing)
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'cards'])) ?>" class="view-btn <?= $view_type === 'cards' ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i> Print Cards (Individual)
            </a>
        </div>

        <div class="portal-card">
            <div class="official-header">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="deped-logo">
                <div class="header-text">
                    <h2>Republic of the Philippines</h2>
                    <h1>Department of Education</h1>
                    <p>LEARNER'S PROGRESS REPORT PORTAL (SF9)</p>
                    <div class="monitoring-badge"><?= $view_type === 'monitor' ? 'Class Audit Mode' : 'Card Printing Mode' ?></div>
                </div>
                <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="deped-logo">
            </div>

            <div style="text-align: center; margin-bottom: 30px;">
                <h3 style="margin: 0; font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 20px; color: #1e293b;">
                    <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?> 
                    <span style="color: #94a3b8; font-weight: 400; margin-left: 10px;">SY: <?= htmlspecialchars($sy) ?></span>
                </h3>
            </div>

            <?php if ($view_type === 'monitor'): ?>
                <!-- MONITOR VIEW: AUDITING TABLE -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th rowspan="2" width="40">No.</th>
                                <th rowspan="2" class="student-name">Student Name</th>
                                <?php foreach ($subjects as $sub): ?>
                                    <th colspan="2" style="font-size: 9px;"><?= htmlspecialchars($sub['subject_name']) ?></th>
                                <?php endforeach; ?>
                                <th rowspan="2">GWA</th>
                            </tr>
                            <tr>
                                <?php foreach ($subjects as $sub): ?>
                                    <th width="35">QG</th><th width="35">Rem</th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $count = 1; foreach ($students as $s): 
                                $gwa_sum = 0; $gwa_count = 0;
                            ?>
                                <tr>
                                    <td><?= $count++ ?></td>
                                    <td class="student-name"><?= htmlspecialchars($s['student_name']) ?></td>
                                    <?php foreach ($subjects as $sub): 
                                        $g = $grade_map[$s['student_id']][$sub['id']] ?? null;
                                        $final = $g ? $g['final_grade'] : null;
                                        if ($final) { $gwa_sum += $final; $gwa_count++; }
                                        $cls = ($final && $final < 75) ? 'failed' : 'passed';
                                    ?>
                                        <td class="grade-cell <?= $cls ?>"><?= $final ?: '-' ?></td>
                                        <td style="font-size: 8px; font-weight: 800; color: #94a3b8;"><?= $g ? ($g['remarks'] === 'PASSED' ? 'P' : 'F') : '-' ?></td>
                                    <?php endforeach; ?>
                                    <td style="font-weight: 800; background: #eff6ff; color: var(--deped-blue);">
                                        <?= $gwa_count > 0 ? round($gwa_sum / $gwa_count) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- CARD VIEW: INDIVIDUAL SELECTION -->
                <div class="student-grid">
                    <?php foreach ($students as $s): 
                        $is_female = in_array(strtoupper(trim($s['sex'] ?? '')), ['F', 'FEMALE', '2', 'G', 'GIRL']);
                    ?>
                        <a href="../../../teacher/reports/sf9_print.php?student_id=<?= urlencode($s['student_id']) ?>" target="_blank" class="student-card <?= $is_female ? 'female' : '' ?>">
                            <div class="avatar"><?= strtoupper(substr($s['student_name'], 0, 1)) ?></div>
                            <div class="info">
                                <h3 style="margin: 0; font-size: 16px; font-weight: 800; font-family: 'Outfit', sans-serif; text-transform: uppercase;"><?= htmlspecialchars($s['student_name']) ?></h3>
                                <p style="margin: 4px 0 0; font-size: 12px; color: #64748b; font-weight: 700;">LRN: <?= htmlspecialchars($s['lrn'] ?: '—') ?></p>
                            </div>
                            <div style="margin-left: auto; color: #cbd5e1; font-size: 20px;"><i class="bi bi-chevron-right"></i></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
