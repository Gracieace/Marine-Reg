<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once __DIR__ . '/../../config/db.php';

$pdo = db_connect();
$current_user = $_SESSION['user'];

// Get system settings
$school_name = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'")->fetchColumn() ?: 'Malolos Marine Fishery School & Laboratory';
$current_sy = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'")->fetchColumn() ?: '2024-2025';

// Handle filters
$filter_sy = $_GET['sy'] ?? $current_sy;
$filter_grade = $_GET['grade'] ?? '';

// Get all sections for this SY
$query = "SELECT DISTINCT e.grade_level, e.section, u.first_name, u.last_name, u.id as teacher_id
          FROM enrollments e 
          LEFT JOIN position_assignments pa ON e.grade_level = pa.grade_level AND e.section = pa.section AND pa.school_year = e.school_year AND pa.position_type = 'class_adviser'
          LEFT JOIN users u ON pa.user_id = u.id
          WHERE e.school_year = ?";
$params = [$filter_sy];

if ($filter_grade) {
    $query .= " AND e.grade_level = ?";
    $params[] = $filter_grade;
}

$query .= " ORDER BY e.grade_level, e.section";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sections = $stmt->fetchAll();

// Get saved reports for this SY
$stmt = $pdo->prepare("SELECT * FROM sf1_reports WHERE school_year = ?");
$stmt->execute([$filter_sy]);
$saved_reports = $stmt->fetchAll();

$reports_by_section = [];
foreach ($saved_reports as $report) {
    $key = $report['grade_level'] . '|' . $report['section'];
    $reports_by_section[$key] = $report;
}

// Stats
$stats_stmt = $pdo->prepare("SELECT sex, COUNT(*) as count FROM enrollments WHERE school_year = ? AND (status IS NULL OR status = 'Enrolled') GROUP BY sex");
$stats_stmt->execute([$filter_sy]);
$sex_stats = $stats_stmt->fetchAll();

$total_male = 0;
$total_female = 0;
foreach($sex_stats as $ss) {
    if ($ss['sex'] === 'M') $total_male = $ss['count'];
    else if ($ss['sex'] === 'F') $total_female = $ss['count'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin SF1 Register Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            margin: 0;
        }

        .main-content {
            margin-left: 260px;
            padding: 100px 40px 40px;
        }

        .dashboard-header {
            background: white;
            padding: 32px;
            border-radius: 16px;
            border: 1px solid var(--border);
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 { margin: 0; font-size: 24px; color: var(--text-main); }
        .header-title p { margin: 4px 0 0 0; color: var(--text-muted); }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            text-align: center;
        }

        .stat-box .label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .stat-box .value { font-size: 24px; font-weight: 700; color: var(--primary); margin-top: 4px; }

        .filter-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 32px;
            display: flex;
            gap: 20px;
        }

        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .form-group select, .form-group input { 
            padding: 10px 14px; 
            border: 1px solid var(--border); 
            border-radius: 8px;
            min-width: 200px;
        }

        .sections-table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .sections-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sections-table th {
            background: #f1f5f9;
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .sections-table td {
            padding: 16px;
            border-top: 1px solid var(--border);
            font-size: 14px;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-live { background: #dcfce7; color: #166534; }
        .badge-saved { background: #dbeafe; color: #1e40af; }

        .action-btn {
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            background: var(--primary);
            color: white;
            margin-right: 8px;
        }

        .action-btn-secondary {
            background: #f1f5f9;
            color: var(--text-main);
            border: 1px solid var(--border);
        }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 110px 20px 40px; }
        }
    </style>
</head>
<body>
    <?php 
    // Dynamically include the correct sidebar based on role
    if ($current_user['role'] === 'admin') {
        require_once __DIR__ . '/../admin_header.php';
        require_once __DIR__ . '/../admin_side_panel.php';
    } else {
        require_once __DIR__ . '/../registrar/registrar_header.php';
        require_once __DIR__ . '/../registrar/registrar_side_panel.php';
    }
    ?>

    <div class="main-content">
        <div class="dashboard-header">
            <div class="header-title">
                <h1>🏛️ Admin SF1 Report Management</h1>
                <p>Oversee and audit School Form 1 registers across all grade levels.</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <button onclick="window.print()" class="action-btn action-btn-secondary">🖨️ Print View</button>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-box">
                <div class="label">Total Enrollment</div>
                <div class="value"><?= number_format($total_male + $total_female) ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Total Male</div>
                <div class="value"><?= number_format($total_male) ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Total Female</div>
                <div class="value"><?= number_format($total_female) ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Total Sections</div>
                <div class="value"><?= count($sections) ?></div>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 20px; flex: 1;">
                <div class="form-group">
                    <label>School Year</label>
                    <select name="sy" onchange="this.form.submit()">
                        <?php 
                        $sy_list = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                        foreach($sy_list as $sy): ?>
                            <option value="<?= $sy ?>" <?= $filter_sy === $sy ? 'selected' : '' ?>><?= $sy ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Grade Level</label>
                    <select name="grade" onchange="this.form.submit()">
                        <option value="">All Grade Levels</option>
                        <?php 
                        $grades = ["Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"];
                        foreach($grades as $g): ?>
                            <option value="<?= $g ?>" <?= $filter_grade === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Search Section or Adviser</label>
                    <input type="text" id="tableSearch" placeholder="Filter table results..." onkeyup="filterTable()">
                </div>
            </form>
        </div>

        <div class="sections-table-container">
            <table class="sections-table" id="sf1AdminTable">
                <thead>
                    <tr>
                        <th>Grade Level</th>
                        <th>Section</th>
                        <th>Adviser</th>
                        <th>Report Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $s): 
                        $key = $s['grade_level'] . '|' . $s['section'];
                        $has_saved = isset($reports_by_section[$key]);
                        $report_id = $has_saved ? $reports_by_section[$key]['id'] : 'live';
                        $adviser_name = $s['first_name'] ? ($s['first_name'] . ' ' . $s['last_name']) : '<span style="color:red">No Adviser</span>';
                    ?>
                        <tr>
                            <td style="font-weight: 600;"><?= htmlspecialchars($s['grade_level']) ?></td>
                            <td><?= htmlspecialchars($s['section']) ?></td>
                            <td><?= $adviser_name ?></td>
                            <td>
                                <?php if ($has_saved): ?>
                                    <span class="status-badge badge-saved">Snapshot Saved</span>
                                <?php else: ?>
                                    <span class="status-badge badge-live">Live Data Only</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="../../teacher/reports/sf1_view.php?id=<?= $report_id ?>&grade_level=<?= urlencode($s['grade_level']) ?>&section=<?= urlencode($s['section']) ?>&school_year=<?= urlencode($filter_sy) ?>" class="action-btn">View SF1</a>
                                <a href="../../teacher/reports/sf1_view.php?id=<?= $report_id ?>&grade_level=<?= urlencode($s['grade_level']) ?>&section=<?= urlencode($s['section']) ?>&school_year=<?= urlencode($filter_sy) ?>&export=xlsx" class="action-btn action-btn-secondary">Excel</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function filterTable() {
            let input = document.getElementById("tableSearch");
            let filter = input.value.toUpperCase();
            let table = document.getElementById("sf1AdminTable");
            let tr = table.getElementsByTagName("tr");

            for (let i = 1; i < tr.length; i++) {
                let found = false;
                let tds = tr[i].getElementsByTagName("td");
                for (let j = 0; j < tds.length - 1; j++) {
                    if (tds[j].textContent.toUpperCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
                tr[i].style.display = found ? "" : "none";
            }
        }
    </script>
</body>
</html>
