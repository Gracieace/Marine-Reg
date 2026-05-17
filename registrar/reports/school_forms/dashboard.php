<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php'; // Ensure url_for is available

$pdo = db_connect();
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';
$active_view = $_GET['view'] ?? 'templates'; // default to templates

// Get default school year if not set
if (!$school_year) {
    $sy_stmt = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC LIMIT 1");
    $school_year = $sy_stmt->fetchColumn();
}

// Get available grade levels, sections, and school years
$grade_levels = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
$school_years = $pdo->query("SELECT DISTINCT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Function to check submission status
function checkSubmission($pdo, $table, $grade, $section, $sy) {
    if ($table === 'grades') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM grades 
                               WHERE school_year = ? AND student_id IN (
                                   SELECT student_id FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?
                               )");
        $stmt->execute([$sy, $grade, $section, $sy]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE grade_level = ? AND section = ? AND school_year = ?");
        $stmt->execute([$grade, $section, $sy]);
    }
    return $stmt->fetchColumn() > 0;
}

$has_filters = (!empty($grade_level) && !empty($section));

// Prepare form data
$teacher_forms = [
    ['id' => 1, 'name' => 'SF1 - School Register', 'desc' => 'Master list of learners with personal data', 'icon' => '📄', 'table' => 'sf1_reports', 'color' => '#3b82f6'],
    ['id' => 2, 'name' => 'SF2 - Daily Attendance', 'desc' => 'Monthly attendance report summaries', 'icon' => '📅', 'table' => 'sf2_reports', 'color' => '#10b981'],
    ['id' => 3, 'name' => 'SF3 - Books Issued', 'desc' => 'Textbook accountability records', 'icon' => '📚', 'table' => 'sf3_reports', 'color' => '#f59e0b'],
    ['id' => 5, 'name' => 'SF5 - Report on Promotion', 'desc' => 'Promotion summary and learning progress', 'icon' => '🎓', 'table' => 'sf5_reports', 'color' => '#8b5cf6'],
];

$template_forms = [
    ['id' => 4, 'name' => 'SF4 - Summary of Enrollment', 'desc' => 'Monthly movement and attendance report', 'icon' => '📈', 'color' => '#3b82f6'],
    ['id' => 6, 'name' => 'SF6 - School Statistics', 'desc' => 'Consolidated demographics and figures', 'icon' => '📊', 'color' => '#10b981'],
    ['id' => 7, 'name' => 'SF7 - Personnel Profile', 'desc' => 'School personnel assignment list', 'icon' => '👨‍🏫', 'color' => '#f59e0b'],
    ['id' => 8, 'name' => 'SF8 - Health Profile', 'desc' => 'Learner basic health and nutrition', 'icon' => '🌡️', 'color' => '#ef4444'],
];

// Calculate Statistics
$stats = [
    'stored' => 0,
    'pending' => 0,
    'total' => count($teacher_forms)
];

if ($has_filters) {
    foreach ($teacher_forms as $f) {
        if (checkSubmission($pdo, $f['table'], $grade_level, $section, $school_year)) {
            $stats['stored']++;
        } else {
            $stats['pending']++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Forms Dashboard | Registrar</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.3);
            --primary-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            --accent-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg-main); 
            margin: 0; 
            color: var(--text-main);
        }

        /* Responsive Main Content Offset */
        .main-content {
            padding-top: 120px !important;
            transition: all 0.3s ease;
            position: relative;
            min-height: 100vh;
        }

        @media screen and (min-width: 769px) {
            .main-content {
                margin-left: 260px; /* Offset for open sidebar */
                width: calc(100% - 260px);
            }
            .sidebar.is-closed ~ .main-content {
                margin-left: 0;
                width: 100%;
            }
        }

        @media screen and (max-width: 768px) {
            .main-content { 
                padding-top: 88px !important; 
                margin-left: 0 !important;
                width: 100%;
            }
            .forms-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .dashboard-header {
                padding: 24px;
            }
            .dashboard-header h1 {
                font-size: 24px;
            }
            .container {
                padding: 16px;
                max-width: 100%;
            }
            .form-card {
                padding: 20px;
                gap: 16px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
                margin-top: -20px;
                padding: 0 16px;
            }
        }

        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 24px; 
            box-sizing: border-box;
        }

        /* Burger Animation Logic for this page */
        #sidebarToggle.active {
            color: #3b82f6;
        }

        /* Dashboard Header */
        .dashboard-header { 
            background: var(--primary-gradient);
            padding: 48px;
            border-radius: 24px;
            color: white;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2);
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .dashboard-header h1 { 
            margin: 0; 
            font-size: 32px; 
            font-weight: 800; 
            display: flex; 
            align-items: center; 
            gap: 16px; 
        }

        .dashboard-header p { 
            margin: 12px 0 0; 
            font-size: 18px; 
            opacity: 0.9;
        }

        /* Stats Section */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-top: -60px;
            padding: 0 20px;
            margin-bottom: 40px;
            position: relative;
            z-index: 10;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            padding: 24px;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        .stat-label { font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-value { font-size: 32px; font-weight: 800; margin-top: 8px; color: #1e293b; }
        .stat-value.success { color: #10b981; }
        .stat-value.pending { color: #f59e0b; }

        /* Filter Section */
        .filters { 
            background: white; 
            padding: 28px; 
            border-radius: 20px; 
            border: 1px solid #e2e8f0; 
            margin-bottom: 40px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; align-items: flex-end; }
        .filter-group label { display: block; margin-bottom: 10px; font-weight: 600; font-size: 14px; color: #475569; }
        .filter-group select { 
            width: 100%; 
            padding: 14px; 
            border: 1.5px solid #e2e8f0; 
            border-radius: 12px; 
            font-size: 15px; 
            background: #f8fafc;
            color: #1e293b;
            transition: 0.2s;
        }
        .filter-group select:focus { border-color: #3b82f6; outline: none; background: white; box-shadow: 0 0 0 4px rgba(59, 131, 246, 0.1); }

        .btn-apply { 
            background: var(--primary-gradient); 
            color: white; 
            border: none; 
            padding: 14px 28px; 
            border-radius: 12px; 
            cursor: pointer; 
            font-weight: 700; 
            font-size: 15px; 
            transition: 0.3s;
            width: 100%;
        }
        .btn-apply:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); }

        /* Tabs */
        .tab-container { display: flex; gap: 12px; margin-bottom: 32px; padding: 6px; background: #f1f5f9; border-radius: 16px; width: fit-content; }
        .tab-btn { 
            padding: 12px 24px; 
            border-radius: 12px; 
            text-decoration: none; 
            font-weight: 700; 
            font-size: 15px; 
            color: #64748b; 
            transition: 0.3s;
        }
        .tab-btn.active { background: white; color: #3b82f6; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .tab-btn:hover:not(.active) { color: #1e293b; background: rgba(255,255,255,0.5); }

        /* Forms Grid */
        .forms-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 28px; }
        
        .form-card { 
            background: white; 
            border: 1px solid #e2e8f0; 
            border-radius: 20px; 
            padding: 28px; 
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            text-decoration: none; 
            display: flex; 
            align-items: flex-start; 
            gap: 24px; 
            position: relative; 
            overflow: hidden; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .form-card:hover:not(.no-filter) { 
            transform: translateY(-8px) scale(1.02); 
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); 
            border-color: #3b82f6;
        }

        .form-icon { 
            width: 64px; 
            height: 64px; 
            border-radius: 16px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 32px; 
            flex-shrink: 0; 
            transition: 0.3s; 
        }

        .form-info { flex: 1; }
        .form-info h3 { margin: 0; font-size: 18px; color: #1e293b; font-weight: 800; }
        .form-info p { margin: 8px 0 0; font-size: 14px; color: #64748b; line-height: 1.6; }
        
        .status-pill { 
            font-size: 11px; 
            font-weight: 800; 
            padding: 6px 12px; 
            border-radius: 20px; 
            margin-top: 16px; 
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase; 
            letter-spacing: 0.05em;
        }
        .status-stored { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .status-pending { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        
        .no-filter-overlay { 
            position: absolute; 
            top: 0; left: 0; right: 0; bottom: 0; 
            background: rgba(248, 250, 252, 0.85); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #ef4444; 
            font-weight: 700; 
            font-size: 13px; 
            backdrop-filter: blur(4px); 
            z-index: 5; 
            border-radius: 20px; 
            text-align: center; 
            padding: 0 30px;
            line-height: 1.5;
        }

        .view-btn {
            margin-top: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #3b82f6;
            transition: 0.2s;
        }
        .form-card:hover .view-btn { color: #2563eb; transform: translateX(4px); }

        @media screen and (max-width: 768px) {
            .dashboard-header { padding: 32px; }
            .stats-grid { margin-top: -30px; }
        }
    </style>
</head>
<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../registrar_side_panel.php'; ?>

    <div class="container main-content">
        <div class="dashboard-header">
            <h1><span>📑</span> School Forms Repository</h1>
            <p>Official DepEd academic repository and reporting hub.</p>
        </div>

        <?php if ($active_view === 'adviser'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Required Forms</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Submissions Stored</div>
                <div class="stat-value success"><?= $has_filters ? $stats['stored'] : '-' ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Review</div>
                <div class="stat-value pending"><?= $has_filters ? $stats['pending'] : '-' ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="filters">
            <form method="GET">
                <input type="hidden" name="view" value="<?= htmlspecialchars($active_view) ?>">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>School Year</label>
                        <select name="school_year">
                            <?php foreach ($school_years as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>><?= htmlspecialchars($sy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Grade Level</label>
                        <select name="grade_level">
                            <option value="">Select Grade</option>
                            <?php foreach ($grade_levels as $gl): ?>
                                <option value="<?= htmlspecialchars($gl) ?>" <?= $grade_level === $gl ? 'selected' : '' ?>><?= htmlspecialchars($gl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Section</label>
                        <select name="section">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-apply">Verify Selection</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="tab-container">
            <a href="?view=templates&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>" 
               class="tab-btn <?= $active_view === 'templates' ? 'active' : '' ?>">System Templates</a>
            <a href="?view=adviser&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>" 
               class="tab-btn <?= $active_view === 'adviser' ? 'active' : '' ?>">Adviser Submissions</a>
        </div>

        <div class="forms-grid">
            <?php
            if ($active_view === 'adviser'):
                foreach ($teacher_forms as $f):
                    $is_stored = false;
                    if ($has_filters) {
                        $is_stored = checkSubmission($pdo, $f['table'], $grade_level, $section, $school_year);
                    }
                    $url = "view_teacher_sf{$f['id']}.php?grade=" . urlencode($grade_level) . "&section=" . urlencode($section) . "&sy=" . urlencode($school_year);
                    if (!$has_filters) $url = "#";
                ?>
                    <div class="form-card <?= !$has_filters ? 'no-filter' : '' ?>" style="display: block; text-decoration: none;">
                        <?php if (!$has_filters): ?>
                            <div class="no-filter-overlay">Select Grade & Section to View Submissions</div>
                        <?php endif; ?>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-icon" style="background: <?= $f['color'] ?>15; color: <?= $f['color'] ?>; flex-shrink: 0;">
                                <?= $f['icon'] ?>
                            </div>
                            <div class="form-info" style="flex-grow: 1;">
                                <h3><?= $f['name'] ?></h3>
                                <p><?= $f['desc'] ?></p>
                                <?php if ($has_filters): ?>
                                    <span class="status-pill <?= $is_stored ? 'status-stored' : 'status-pending' ?>">
                                        <?= $is_stored ? '✔ Stored & Verified' : '❌ Pending Adviser Upload' ?>
                                    </span>
                                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                                        <?php if ($is_stored): ?>
                                            <a href="<?= $url ?>" class="btn-action" style="padding: 6px 12px; font-size: 12px; background: #3b82f6; color: white; border-radius: 4px; text-decoration: none;">👁️ View Detailed</a>
                                        <?php endif; ?>
                                        <?php if (in_array($f['id'], [1, 2, 5])): ?>
                                            <?php 
                                            // Map report table to view page
                                            $view_pages = [1 => 'sf1_view.php', 2 => 'sf2_view_detail.php', 5 => 'sf5_form.php'];
                                            $live_url = url_for('/teacher/reports/' . $view_pages[$f['id']] . '?id=live&grade_level=' . urlencode($grade_level) . '&section=' . urlencode($section) . '&school_year=' . urlencode($school_year));
                                            ?>
                                            <a href="<?= $live_url ?>" class="btn-action" style="padding: 6px 12px; font-size: 12px; background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 4px; text-decoration: none;">🌐 View Live</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: 
                foreach ($template_forms as $f):
                    $url = "sf{$f['id']}.php?grade_level=" . urlencode($grade_level) . "&section=" . urlencode($section) . "&school_year=" . urlencode($school_year);
                ?>
                    <a href="<?= $url ?>" class="form-card">
                        <div class="form-icon" style="background: <?= $f['color'] ?>15; color: <?= $f['color'] ?>;">
                            <?= $f['icon'] ?>
                        </div>
                        <div class="form-info">
                            <h3><?= $f['name'] ?></h3>
                            <p><?= $f['desc'] ?></p>
                            <div class="view-btn">Generate Template →</div>
                        </div>
                    </a>
                <?php endforeach; 
            endif; ?>
        </div>
    </div>
</body>
</html>
