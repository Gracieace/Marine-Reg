<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';

auth_require_role(['registrar', 'admin']);

$pdo = db_connect();

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$adviser_name = $_GET['adviser'] ?? 'Adviser';
$school_year = $_GET['sy'] ?? '';

if (!$grade || !$section) {
    // ── No parameters: show selection dashboard ──
    $adviser_list = [];
    try {
        $sql = "SELECT DISTINCT e.adviser_name, e.grade, e.section, e.school_year 
                FROM eclass_records e 
                ORDER BY e.school_year DESC, e.grade ASC, e.section ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $adviser_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    if (empty($adviser_list)) {
        try {
            $sql = "SELECT pa.grade_level AS grade, pa.section, pa.school_year,
                           COALESCE(CONCAT(t.first_name, ' ', t.last_name), 'Adviser') AS adviser_name
                    FROM position_assignments pa
                    LEFT JOIN teachers t ON pa.user_id = t.user_id
                    WHERE pa.position_type = 'class_adviser'
                    ORDER BY pa.school_year DESC, pa.grade_level ASC, pa.section ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $adviser_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    $grouped = [];
    foreach ($adviser_list as $item) {
        $grouped[$item['school_year']][] = $item;
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Submissions</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d47a1; --primary-light: #e3f2fd; --text-dark: #1e293b;
            --text-muted: #64748b; --bg-gray: #f8fafc; --white: #ffffff; --border: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1); --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1); --radius-md: 12px; --radius-lg: 16px;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-gray); margin: 0; color: var(--text-dark); }
        .main-content { padding-top: calc(var(--header-height) + 32px) !important; padding-bottom: 40px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { padding-top: calc(var(--header-height) + 20px) !important; padding-left: 16px; padding-right: 16px; } }
        .page-hero {
            background: linear-gradient(135deg, var(--primary) 0%, #1e40af 100%);
            border-radius: var(--radius-lg); padding: 36px 40px; color: white;
            margin-bottom: 32px; box-shadow: var(--shadow-lg); position: relative; overflow: hidden;
        }
        .page-hero::after { content: ""; position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: rgba(255,255,255,0.05); border-radius: 50%; }
        .page-hero h1 { margin: 0 0 6px 0; font-size: 26px; font-weight: 700; position: relative; z-index: 2; }
        .page-hero p { margin: 0; opacity: 0.85; font-size: 14px; position: relative; z-index: 2; }
        .sy-group { margin-bottom: 32px; }
        .sy-label { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-left: 4px; }
        .sy-label h2 { margin: 0; font-size: 17px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; }
        .sy-dash { height: 4px; width: 32px; background: var(--primary); border-radius: 2px; }
        .adviser-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .adviser-card {
            background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-md);
            padding: 20px; text-decoration: none; color: inherit; display: flex; align-items: center;
            gap: 16px; transition: all 0.2s cubic-bezier(0,0,0.2,1); box-shadow: var(--shadow-sm);
        }
        .adviser-card:hover { transform: scale(1.01) translateY(-2px); border-color: var(--primary); box-shadow: var(--shadow-md); }
        .adviser-card .card-icon { width: 48px; height: 48px; background: var(--primary-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .adviser-card:hover .card-icon { background: #bbdefb; }
        .adviser-card .card-info h3 { margin: 0; font-size: 15px; font-weight: 600; }
        .adviser-card .card-info p { margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted); }
        .adviser-card .card-chevron { margin-left: auto; color: var(--border); transition: all 0.2s; }
        .adviser-card:hover .card-chevron { color: var(--primary); transform: translateX(4px); }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state .empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }
        .empty-state p { margin: 0; font-size: 14px; }
    </style>
</head>
<body>
    <?php include '../../../header.php'; ?>
    <?php require_once '../../registrar_side_panel.php'; ?>

    <div class="main-content">
        <div class="page-hero">
            <h1>📋 Adviser Submissions</h1>
            <p>Select a section to view adviser document submissions and monitoring status.</p>
        </div>
        <?php if (empty($grouped)): ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <p>No adviser records found. Add eClass Records first to view submissions.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $sy => $items): ?>
                <div class="sy-group">
                    <div class="sy-label">
                        <div class="sy-dash"></div>
                        <h2>SY <?= htmlspecialchars($sy) ?></h2>
                    </div>
                    <div class="adviser-grid">
                        <?php foreach ($items as $item): ?>
                            <a class="adviser-card" href="?grade=<?= urlencode($item['grade']) ?>&section=<?= urlencode($item['section']) ?>&sy=<?= urlencode($item['school_year']) ?>&adviser=<?= urlencode($item['adviser_name']) ?>">
                                <div class="card-icon">👨‍🏫</div>
                                <div class="card-info">
                                    <h3><?= htmlspecialchars($item['adviser_name']) ?></h3>
                                    <p><?= htmlspecialchars($item['grade']) ?> - <?= htmlspecialchars($item['section']) ?></p>
                                </div>
                                <span class="card-chevron">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
    exit;
}

// Function to check submission status
function getSubmissionStatus($pdo, $table, $params) {
    $where = [];
    foreach ($params as $key => $val) {
        $where[] = "$key = ?";
    }
    $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(" AND ", $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    return $stmt->fetchColumn() > 0;
}

// Get Adviser ID for checking specific documents (AR, etc.)
$stmt = $pdo->prepare("SELECT user_id FROM position_assignments WHERE grade_level = ? AND section = ? AND school_year = ? AND position_type = 'class_adviser' LIMIT 1");
$stmt->execute([$grade, $section, $school_year]);
$assigned_adviser_id = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submission Board | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d47a1;
            --primary-light: #e3f2fd;
            --secondary: #1976d2;
            --accent: #ffca28;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --bg-gray: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-gray);
            margin: 0;
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
        }

        .main-content {
            padding-top: calc(var(--header-height) + 32px) !important;
            padding-bottom: 40px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .main-content {
                padding-top: calc(var(--header-height) + 20px) !important;
                padding-left: 16px;
                padding-right: 16px;
            }
        }

        /* ── Navigation Actions ── */
        .page-actions {
            margin-bottom: 24px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--white);
            color: var(--text-muted);
            padding: 10px 18px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid var(--border);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
        }

        .btn-back:hover {
            color: var(--primary);
            border-color: var(--primary);
            transform: translateX(-4px);
            box-shadow: var(--shadow-md);
        }

        /* ── Hero Header ── */
        .doc-header {
            background: linear-gradient(135deg, var(--primary) 0%, #1e40af 100%);
            border-radius: var(--radius-lg);
            padding: 40px;
            color: white;
            margin-bottom: 40px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .doc-header::after {
            content: "";
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .header-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .adviser-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            backdrop-filter: blur(4px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .header-info h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .header-meta {
            margin-top: 12px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .meta-pill {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Grid Layout ── */
        .submission-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 32px;
        }

        @media (max-width: 992px) {
            .submission-grid {
                grid-template-columns: 1fr;
            }
        }

        .board-section {
            margin-bottom: 24px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-left: 4px;
        }

        .section-title h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .title-dash {
            height: 4px;
            width: 32px;
            background: var(--primary);
            border-radius: 2px;
        }

        /* ── Item Cards ── */
        .item-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .submission-item {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s cubic-bezier(0, 0, 0.2, 1);
            text-decoration: none;
            box-shadow: var(--shadow-sm);
        }

        .submission-item:hover:not(.disabled) {
            transform: scale(1.01) translateY(-2px);
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
        }

        .item-content {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .item-icon {
            width: 44px;
            height: 44px;
            background: var(--bg-gray);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: inherit;
        }

        .submission-item:hover .item-icon {
            background: var(--primary-light);
            color: var(--primary);
        }

        .item-details h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .item-details p {
            margin: 4px 0 0 0;
            font-size: 12px;
            color: var(--text-muted);
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-active {
            background: #dcfce7;
            color: #15803d;
        }

        .status-pending {
            background: #f1f5f9;
            color: #94a3b8;
        }

        .disabled {
            opacity: 0.7;
            cursor: not-allowed;
            background: #fcfcfc;
        }

        .chevron {
            color: var(--border);
            transition: transform 0.2s;
        }

        .submission-item:hover .chevron {
            color: var(--primary);
            transform: translateX(4px);
        }
    </style>
</head>

<body>
    <?php include '../../../header.php'; ?>
    <?php require_once '../../registrar_side_panel.php'; ?>

    <div class="main-content">
        <div class="page-actions">
            <a href="../index.php" class="btn-back">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
                Return to Reports
            </a>
        </div>

        <header class="doc-header">
            <div class="header-content">
                <div class="adviser-avatar">👨‍🏫</div>
                <div class="header-info">
                    <h1><?= htmlspecialchars($adviser_name) ?></h1>
                    <div class="subtitle">Submissions Dashboard & Monitoring</div>
                    <div class="header-meta">
                        <div class="meta-pill">
                            <span>📍</span> <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?>
                        </div>
                        <div class="meta-pill">
                            <span>📅</span> SY <?= htmlspecialchars($school_year) ?>
                        </div>
                        <div class="meta-pill">
                            <span>📊</span> Status: Active
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="submission-grid">
            <!-- 1. Instructional Documents -->
            <div class="board-section">
                <div class="section-title">
                    <div class="title-dash"></div>
                    <h2>Instructional Documents</h2>
                </div>
                <div class="item-list">
                    <?php
                    $instructional = [
                        ['id' => 'AR', 'name' => 'Accomplishment Report', 'icon' => '📈', 'desc' => 'Monthly progress report', 'table' => 'accomplishment_reports', 'link' => '../instructional/view_ar.php'],
                        ['id' => 'TOS', 'name' => 'Table of Specification', 'icon' => '📋', 'desc' => 'Exam design framework', 'table' => 'tos_reports', 'link' => '../instructional/view_tos.php'],
                        ['id' => 'EX', 'name' => 'Examinations', 'icon' => '📝', 'desc' => 'Assessment materials', 'table' => 'exam_papers', 'link' => '../instructional/view_exam.php'],
                        ['id' => 'MPS', 'name' => 'MPS (Mean Percentage Score)', 'icon' => '📊', 'desc' => 'Statistical performance', 'table' => 'exam_scores', 'link' => '../instructional/view_mps.php']
                    ];

                    foreach ($instructional as $item): 
                        $is_submitted = false;
                        $params = ['school_year' => $school_year];
                        
                        try {
                            if ($item['id'] === 'AR') {
                                $params = ['school_year' => $school_year, 'teacher_id' => $assigned_adviser_id ?: 0];
                            } elseif ($item['id'] === 'MPS') {
                                 $check_sql = "SELECT COUNT(*) FROM exam_scores es JOIN exam_papers ep ON es.exam_id = ep.id WHERE ep.grade_level = ? AND ep.school_year = ?";
                                 $check_stmt = $pdo->prepare($check_sql);
                                 $check_stmt->execute([$grade, $school_year]);
                                 $is_submitted = $check_stmt->fetchColumn() > 0;
                            } else {
                                $params['grade_level'] = $grade;
                                $params['section'] = $section;
                            }
                            
                            if ($item['id'] !== 'MPS') {
                                $is_submitted = $assigned_adviser_id ? getSubmissionStatus($pdo, $item['table'], $params) : false;
                            }
                        } catch (PDOException $e) { $is_submitted = false; }
                        
                        if ($is_submitted):
                    ?>
                        <a href="<?= $item['link'] ?>?grade=<?= urlencode($grade) ?>&section=<?= urlencode($section) ?>&sy=<?= urlencode($school_year) ?>&adviser_id=<?= $assigned_adviser_id ?>" class="submission-item" target="_blank">
                            <div class="item-content">
                                <div class="item-icon"><?= $item['icon'] ?></div>
                                <div class="item-details">
                                    <h3><?= $item['name'] ?></h3>
                                    <p><?= $item['desc'] ?></p>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span class="status-badge status-active">Stored</span>
                                <span class="chevron">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                                </span>
                            </div>
                        </a>
                    <?php else: ?>
                        <div class="submission-item disabled" title="Pending Submission">
                            <div class="item-content">
                                <div class="item-icon"><?= $item['icon'] ?></div>
                                <div class="item-details">
                                    <h3><?= $item['name'] ?></h3>
                                    <p><?= $item['desc'] ?></p>
                                </div>
                            </div>
                            <span class="status-badge status-pending">Pending</span>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>

            <!-- 2. Academic Performance & Grading -->
            <div class="board-section">
                <div class="section-title">
                    <div class="title-dash" style="background: #ec4899;"></div>
                    <h2>Grading & Academic Performance</h2>
                </div>
                <div class="item-list">
                    <?php
                    $academic = [
                        ['id' => 'GR', 'name' => 'Grading Sheets', 'icon' => '📊', 'desc' => 'Subject-by-subject grades', 'table' => 'sf9_grades', 'link' => '../grading_sheet/view_grades.php'],
                        ['id' => 'ATT', 'name' => 'Detailed Attendance', 'icon' => '⏰', 'desc' => 'Daily learner attendance', 'table' => 'sf2_daily_attendance', 'link' => '../school_forms/view_teacher_sf2.php'],
                    ];

                    foreach ($academic as $item):
                        $is_submitted = false;
                        try {
                            if ($item['id'] === 'GR') {
                                // Check if any grades are encoded for this section
                                $sql = "SELECT COUNT(*) FROM sf9_grades g JOIN enrollments e ON g.student_id = e.student_id WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$grade, $section, $school_year]);
                                $is_submitted = $stmt->fetchColumn() > 0;
                            } elseif ($item['id'] === 'ATT') {
                                // Check if any SF2 reports exist for this section
                                $sql = "SELECT COUNT(*) FROM sf2_reports WHERE grade_level = ? AND section = ? AND school_year = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$grade, $section, $school_year]);
                                $is_submitted = $stmt->fetchColumn() > 0;
                            } else {
                                $is_submitted = getSubmissionStatus($pdo, $item['table'], ['sf2_report_id' => 0]);
                            }
                        } catch (PDOException $e) { $is_submitted = false; }

                        if ($is_submitted):
                    ?>
                        <a href="<?= $item['link'] ?>?grade=<?= urlencode($grade) ?>&section=<?= urlencode($section) ?>&sy=<?= urlencode($school_year) ?>" class="submission-item" target="_blank">
                            <div class="item-content">
                                <div class="item-icon" style="color: #ec4899; background: #fdf2f8;"><?= $item['icon'] ?></div>
                                <div class="item-details">
                                    <h3><?= $item['name'] ?></h3>
                                    <p><?= $item['desc'] ?></p>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span class="status-badge status-active">Stored</span>
                                <span class="chevron">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                                </span>
                            </div>
                        </a>
                    <?php else: ?>
                        <div class="submission-item disabled">
                            <div class="item-content">
                                <div class="item-icon"><?= $item['icon'] ?></div>
                                <div class="item-details">
                                    <h3><?= $item['name'] ?></h3>
                                    <p><?= $item['desc'] ?></p>
                                </div>
                            </div>
                            <span class="status-badge status-pending">Pending</span>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>

            <!-- 3. Official School Forms -->
            <div class="board-section" style="grid-column: 1 / -1;">
                <div class="section-title">
                    <div class="title-dash" style="background: #f59e0b;"></div>
                    <h2>Official School Forms (SF1 - SF10)</h2>
                </div>
                <div class="item-list" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
                    <?php
                    $forms = [
                        ['sf' => 1, 'name' => 'SF1 - School Register', 'icon' => '📋', 'desc' => 'Master list of students', 'table' => 'sf1_reports'],
                        ['sf' => 2, 'name' => 'SF2 - Attendance Report', 'icon' => '📅', 'desc' => 'Daily attendance summary', 'table' => 'sf2_reports'],
                        ['sf' => 3, 'name' => 'SF3 - Books Issued', 'icon' => '📚', 'desc' => 'Textbook accountability', 'table' => 'sf3_reports'],
                        ['sf' => 5, 'name' => 'SF5 - Promotion Report', 'icon' => '🎓', 'desc' => 'End of year status', 'table' => 'sf5_reports'],
                        ['sf' => 8, 'name' => 'SF8 - Health Profile', 'icon' => '🌡️', 'desc' => 'Nutritional status/BMI', 'table' => 'sf8_health_profile'],
                        ['sf' => 9, 'name' => 'SF9 - Progress Card', 'icon' => '🏅', 'desc' => 'Individual report card', 'table' => 'sf9_grades'],
                        ['sf' => 10, 'name' => 'SF10 - Permanent Record', 'icon' => '📜', 'desc' => 'Academic history', 'table' => 'sf9_grades']
                    ];

                    foreach ($forms as $form):
                        $is_submitted = false;
                        try {
                            if ($form['table'] === 'sf8_health_profile') {
                                $sql = "SELECT COUNT(*) FROM sf8_health_profile s JOIN enrollments e ON s.student_id = e.student_id WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$grade, $section, $school_year]);
                                $is_submitted = $stmt->fetchColumn() > 0;
                            } else {
                                $is_submitted = getSubmissionStatus($pdo, $form['table'], [
                                    'grade_level' => $grade,
                                    'section' => $section,
                                    'school_year' => $school_year
                                ]);
                            }
                        } catch (PDOException $e) { $is_submitted = false; }
                        
                        if ($is_submitted):
                            $viewer_url = ($form['sf'] == 9) ? "../grading_sheet/view_grades.php" : "../school_forms/view_teacher_sf{$form['sf']}.php";
                            ?>
                            <a href="<?= $viewer_url ?>?grade=<?= urlencode($grade) ?>&section=<?= urlencode($section) ?>&sy=<?= urlencode($school_year) ?>"
                                class="submission-item" target="_blank">
                                <div class="item-content">
                                    <div class="item-icon" style="background: #fffbeb; color: #f59e0b;"><?= $form['icon'] ?></div>
                                    <div class="item-details">
                                        <h3><?= $form['name'] ?></h3>
                                        <p><?= $form['desc'] ?></p>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span class="status-badge status-active">Stored</span>
                                    <span class="chevron">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="9 18 15 12 9 6" />
                                        </svg>
                                    </span>
                                </div>
                            </a>
                        <?php else: ?>
                            <div class="submission-item disabled" title="Pending Submission">
                                <div class="item-content">
                                    <div class="item-icon"><?= $form['icon'] ?></div>
                                    <div class="item-details">
                                        <h3><?= $form['name'] ?></h3>
                                        <p><?= $form['desc'] ?></p>
                                    </div>
                                </div>
                                <span class="status-badge status-pending">Pending</span>
                            </div>
                        <?php endif; endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
