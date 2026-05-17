<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/report_export_helper.php';

$pdo = db_connect();

// SF7 implementation (Template)
$school_year = $_GET['school_year'] ?? '';

// Get personnel data for the selected school year
$personnel = $pdo->prepare("
    SELECT 
        COALESCE(e.full_name, CONCAT(u.first_name, ' ', u.last_name)) as full_name,
        u.role,
        CASE WHEN r.sex IS NOT NULL THEN r.sex ELSE 'M' END as sex,
        COALESCE(e.position_title, pa.position_type, u.role) as position_name,
        '11' as salary_grade, -- Fallback or could be link to a salary table
        'BSEd' as degree, 
        'General Education' as major,
        pa.grade_level,
        pa.section
    FROM position_assignments pa
    LEFT JOIN users u ON pa.user_id = u.id
    LEFT JOIN employees e ON pa.employee_id = e.id
    LEFT JOIN registrations r ON u.username = r.lrn
    WHERE pa.school_year = ?
    ORDER BY full_name ASC
");
$personnel->execute([$school_year]);
$personnel = $personnel->fetchAll();

// Handle export
$export_format = $_GET['export'] ?? '';
if ($export_format && !empty($personnel)) {
    handleGenericExport($personnel, $export_format, 'sf7', $school_year);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF7 - School Personnel Assignment List</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 100px; }
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .header-block { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .header-block h1 { color: #1e293b; margin: 0; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #475569; font-weight: 600; font-size: 13px; text-transform: uppercase; }
        .no-print { margin-bottom: 20px; }
        @media print { .no-print { display: none; } .container { margin: 0; padding: 0; } body { padding-top: 0; } }
    </style>
</head>
<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../registrar_side_panel.php'; ?>

    <div class="container main-content">
        <div class="no-print">
            <a href="dashboard.php" style="text-decoration: none; color: #64748b; font-weight: 600;">← Back to Dashboard</a>
        </div>

        <form method="GET" class="no-print" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: flex-end;">
            <div>
                <label style="font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase;">School Year</label>
                <select name="school_year" style="padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; min-width: 150px;">
                    <option value="">Select SY</option>
                    <?php
                    $sy_list = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($sy_list as $sy)
                        echo "<option value='$sy' " . ($school_year == $sy ? 'selected' : '') . ">$sy</option>";
                    ?>
                </select>
            </div>
            <button type="submit" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer;">Generate</button>
            <div style="flex: 1;"></div>
            <a href="?export=pdf&school_year=<?= urlencode($school_year) ?>" target="_blank" style="padding: 10px 20px; background: #ef4444; color: white; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 14px;">📕 PDF</a>
            <a href="?export=xlsx&school_year=<?= urlencode($school_year) ?>" style="padding: 10px 20px; background: #22c55e; color: white; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 14px;">📊 Excel</a>
        </form>

        <div class="header-block">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1>School Form 7 (SF7)</h1>
                    <p>School Personnel Assignment List and Basic Profile</p>
                </div>
                <input type="text" id="reportSearch" placeholder="🔍 Search personnel..." 
                       style="padding: 12px 16px; width: 300px; border: 1px solid #e2e8f0; border-radius: 20px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            </div>
        </div>

        <table id="sf7Table">
            <thead>
                <tr>
                    <th>Name of Personnel</th>
                    <th>Sex</th>
                    <th>Position</th>
                    <th>Assignment</th>
                    <th>Salary Grade</th>
                    <th>Degree/Educational Qualification</th>
                    <th>Major/Specialization</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($personnel): foreach ($personnel as $p): 
                    $assignment = '-';
                    if ($p['grade_level']) {
                        $assignment = htmlspecialchars($p['grade_level'] . ' - ' . $p['section']);
                    }
                ?>
                    <tr>
                        <td style="text-align: left; font-weight: 600;"><?= htmlspecialchars($p['full_name']) ?></td>
                        <td><?= htmlspecialchars($p['sex'] ?? 'M') ?></td>
                        <td><?= htmlspecialchars($p['position_name'] ?? ucfirst($p['role'] ?? 'Teacher')) ?></td>
                        <td><?= $assignment ?></td>
                        <td><?= htmlspecialchars($p['salary_grade'] ?? '11') ?></td>
                        <td><?= htmlspecialchars($p['degree'] ?? 'BSEd') ?></td>
                        <td><?= htmlspecialchars($p['major'] ?? 'General') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" style="text-align: center;">No personnel records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf7Table')) {
                initReportSearch('reportSearch', 'sf7Table');
            }
        });
    </script>
</body>
</html>
