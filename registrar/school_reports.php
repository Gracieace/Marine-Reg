<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php'; // Ensure url_for is available

$pdo = db_connect();

// 1. Logic for School Reports/Dashboard Tab
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';

$grade_levels = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

// 2. Logic for Adviser Tracker Tab
$stmt_sy = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'");
$stmt_sy->execute();
$current_school_year = $stmt_sy->fetchColumn() ?: '2024-2025';

$sql_advisers = "
    SELECT 
        pa.grade_level, 
        pa.section, 
        u.first_name, 
        u.last_name,
        u.id as teacher_id,
        (SELECT id FROM sf1_reports WHERE teacher_id = u.id AND school_year = pa.school_year AND grade_level = pa.grade_level AND section = pa.section LIMIT 1) as sf1_id,
        (SELECT COUNT(*) FROM sf2_reports WHERE teacher_id = u.id AND school_year = pa.school_year AND grade_level = pa.grade_level AND section = pa.section) as sf2_count,
        (SELECT id FROM sf3_reports WHERE teacher_id = u.id AND school_year = pa.school_year AND grade_level = pa.grade_level AND section = pa.section LIMIT 1) as sf3_id,
        (SELECT id FROM sf5_reports WHERE school_year = pa.school_year AND grade_level = pa.grade_level AND section = pa.section LIMIT 1) as sf5_id
    FROM position_assignments pa
    JOIN users u ON pa.user_id = u.id
    WHERE pa.position_type = 'class_adviser' 
      AND pa.school_year = ?
    ORDER BY pa.grade_level, pa.section
";
$stmt_adv = $pdo->prepare($sql_advisers);
$stmt_adv->execute([$current_school_year]);
$submissions = $stmt_adv->fetchAll();

$active_tab = $_GET['tab'] ?? 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Submissions - Registrar Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg-light: #f8fafc;
            --border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-main);
            margin: 0;
            padding: 0;
        }

        .main-content {
            padding: 100px 30px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            color: var(--text-main);
        }

        /* Tabs Interface */
        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 2px;
        }

        .tab-item {
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 3px solid transparent;
            margin-bottom: -5px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .tab-item:hover {
            color: var(--primary);
        }

        .tab-item.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
            padding: 24px;
            margin-bottom: 24px;
        }

        /* Dashboard Styles */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            align-items: end;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-main);
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            text-decoration: none;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }

        .btn-outline { background: white; border: 1px solid var(--border); color: var(--text-main); }
        .btn-outline:hover { background: var(--bg-light); border-color: var(--text-muted); }

        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
        }

        .report-section h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-main);
        }

        .report-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-main);
            transition: all 0.2s;
            margin-bottom: 12px;
        }

        .report-link:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .report-info h4 { margin: 0 0 4px 0; font-size: 15px; font-weight: 600; }
        .report-info p { margin: 0; font-size: 12px; color: var(--text-muted); line-height: 1.4; }

        /* Tracker Styles */
        .table-container { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f1f5f9; padding: 16px; font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 14px; }
        
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #f1f5f9; color: #475569; }

        .status-dot { height: 8px; width: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }

        .tracker-cell { display: flex; flex-direction: column; gap: 4px; }
        .tracker-cell small { font-size: 10px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }

        @media (max-width: 768px) {
            .reports-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <?php include '../header.php'; ?>
    <?php include 'registrar_side_panel.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1>Reports & Submissions</h1>
                <p style="color: var(--text-muted); margin-top: 5px;">Comprehensive hub for all official school forms and advisory tracking.</p>
            </div>
            <div class="badge badge-secondary" style="padding: 10px 16px; font-size: 13px;">
                SY <?= htmlspecialchars($current_school_year) ?>
            </div>
        </div>

        <nav class="tab-nav">
            <a href="?tab=dashboard" class="tab-item <?= $active_tab === 'dashboard' ? 'active' : '' ?>">Reports Dashboard</a>
            <a href="?tab=tracker" class="tab-item <?= $active_tab === 'tracker' ? 'active' : '' ?>">Adviser Submittal Tracker</a>
        </nav>

        <?php if ($active_tab === 'dashboard'): ?>
            <div id="tab-dashboard">
                <div class="card">
                    <form method="GET" class="filters">
                        <input type="hidden" name="tab" value="dashboard">
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="form-group">
                            <label>Grade Level</label>
                            <select name="grade_level" class="form-control">
                                <option value="">All Grades</option>
                                <?php foreach ($grade_levels as $grade): ?>
                                    <option value="<?= htmlspecialchars($grade) ?>" <?= $grade_level === $grade ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($grade) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Section</label>
                            <select name="section" class="form-control">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sec) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="?tab=dashboard" class="btn btn-outline">Clear</a>
                        </div>
                    </form>
                </div>

                <div class="reports-grid">
                    <div class="report-section">
                        <h2><i class="fas fa-file-invoice" style="color: var(--primary);"></i> DepEd School Forms (SF)</h2>
                        <?php
                        $sf_forms = [
                            ['id' => 'sf1', 'name' => 'SF1 - School Register', 'desc' => 'Master list of learners with personal details and profile.'],
                            ['id' => 'sf2', 'name' => 'SF2 - Daily Attendance', 'desc' => 'Monthly attendance recording and movement tracking.'],
                            ['id' => 'sf3', 'name' => 'SF3 - Books Issued', 'desc' => 'Inventory and tracking of textbooks issued to learners.'],
                            ['id' => 'sf4', 'name' => 'SF4 - Monthly Movement', 'desc' => 'Consolidated enrollment and attendance summary.'],
                            ['id' => 'sf5', 'name' => 'SF5 - Report on Promotion', 'desc' => 'End-of-year summary of learner performance and promotion status.'],
                            ['id' => 'sf6', 'name' => 'SF6 - School Statistics', 'desc' => 'Summarized profile of promoted and retained learners.'],
                            ['id' => 'sf7', 'name' => 'SF7 - Personnel List', 'desc' => 'List of school personnel with profiles and assignments.'],
                            ['id' => 'sf8', 'name' => 'SF8 - Health Profile', 'desc' => 'Learner health and nutritional status summary.'],
                        ];
                        foreach ($sf_forms as $report):
                        ?>
                            <a href="reports/school_forms/<?= $report['id'] ?>.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($current_school_year) ?>" class="report-link">
                                <div class="report-info">
                                    <h4><?= $report['name'] ?></h4>
                                    <p><?= $report['desc'] ?></p>
                                </div>
                                <div class="report-icon"><i class="fas fa-arrow-right"></i></div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="report-section">
                        <h2><i class="fas fa-id-card" style="color: var(--success);"></i> Learner Records & Analytics</h2>
                        <a href="reports/school_forms/sf9.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" class="report-link">
                            <div class="report-info">
                                <h4>SF9 - Progress Report Card</h4>
                                <p>Generate quarterly report cards for individual learners.</p>
                            </div>
                            <div class="report-icon"><i class="fas fa-file-pdf"></i></div>
                        </a>
                        <a href="reports/school_forms/sf10.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" class="report-link">
                            <div class="report-info">
                                <h4>SF10 - Permanent Record</h4>
                                <p>Comprehensive academic record of learners (Form 137).</p>
                            </div>
                            <div class="report-icon"><i class="fas fa-folder-open"></i></div>
                        </a>
                        <a href="reports/enrollment/detailed.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" class="report-link">
                            <div class="report-info">
                                <h4>Enrollment Analytics</h4>
                                <p>Advanced enrollment tracking and demographic breakdowns.</p>
                            </div>
                            <div class="report-icon"><i class="fas fa-chart-line"></i></div>
                        </a>
                        <a href="reports/eclass_record/school_register_database.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" class="report-link">
                            <div class="report-info">
                                <h4>Master Database Export</h4>
                                <p>Raw data export for all students in the eClass record system.</p>
                            </div>
                            <div class="report-icon"><i class="fas fa-file-csv"></i></div>
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div id="tab-tracker">
                <div class="card" style="padding: 0; overflow: hidden;">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Grade & Section</th>
                                    <th>Class Adviser</th>
                                    <th>SF1 Register</th>
                                    <th>SF2 Attendance</th>
                                    <th>SF3 Textbooks</th>
                                    <th>SF5 Promotion</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $sub): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($sub['grade_level'] . ' - ' . $sub['section']) ?></strong></td>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($sub['last_name'] . ', ' . $sub['first_name']) ?></td>
                                        
                                        <td>
                                            <?php if ($sub['sf1_id']): ?>
                                                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Done</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger"><i class="fas fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($sub['sf2_count'] > 0): ?>
                                                <span class="badge badge-success">
                                                    <i class="fas fa-calendar-check"></i> <?= $sub['sf2_count'] ?> Months
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-danger"><i class="fas fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($sub['sf3_id']): ?>
                                                <span class="badge badge-success"><i class="fas fa-book"></i> Recorded</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Incomplete</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($sub['sf5_id']): ?>
                                                <span class="badge badge-success"><i class="fas fa-graduation-cap"></i> Published</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary"><i class="fas fa-lock"></i> Not Found</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div style="display: flex; gap: 4px;">
                                                <?php if ($sub['sf1_id']): ?>
                                                    <a href="reports/school_forms/view_teacher_sf1.php?grade=<?= urlencode($sub['grade_level']) ?>&section=<?= urlencode($sub['section']) ?>&sy=<?= urlencode($current_school_year) ?>" class="btn btn-outline" style="padding: 6px 10px; font-size: 11px;" title="View SF1">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="reports/school_forms/sf3.php?grade_level=<?= urlencode($sub['grade_level']) ?>&section=<?= urlencode($sub['section']) ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 11px;">
                                                    Inspect Section
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($submissions)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 60px; color: var(--text-muted);">
                                            <i class="fas fa-user-slash" style="font-size: 40px; margin-bottom: 20px; display: block;"></i>
                                            No class adviser assignments found for SY <?= htmlspecialchars($current_school_year) ?>.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px;">
                    <i class="fas fa-info-circle"></i> Tracker monitors teacher submittals only. Status reflects the latest data recorded in the school form submittal tables.
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
