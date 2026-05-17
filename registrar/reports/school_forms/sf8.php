<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/report_export_helper.php';

$pdo = db_connect();

$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';

// Get default school year if not set
if (!$school_year) {
    $sy_stmt = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC LIMIT 1");
    $school_year = $sy_stmt->fetchColumn();
}

// Handle data update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_health') {
    $student_id = $_POST['student_id'];
    $weight = $_POST['weight'];
    $height = $_POST['height'];
    $m_date = $_POST['measurement_date'] ?: date('Y-m-d');

    $stmt = $pdo->prepare("INSERT INTO sf8_health_profile (student_id, weight_kg, height_m, measurement_date, school_year) 
                           VALUES (?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE weight_kg = VALUES(weight_kg), height_m = VALUES(height_m), measurement_date = VALUES(measurement_date)");
    $stmt->execute([$student_id, $weight, $height, $m_date, $school_year]);
    
    header("Location: sf8.php?grade_level=$grade_level&section=$section&school_year=$school_year&success=1");
    exit;
}

// Fetch students for health profile
$sql = "SELECT e.lrn, e.student_id, e.student_name, r.sex, r.birthdate,
               h.weight_kg, h.height_m, h.measurement_date
        FROM enrollments e 
        LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
        LEFT JOIN sf8_health_profile h ON e.student_id = h.student_id AND e.school_year = h.school_year
        WHERE 1=1";
$params = [];
if ($grade_level) { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; }
if ($section) { $sql .= " AND e.section = ?"; $params[] = $section; }
$sql .= " ORDER BY r.sex, e.student_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Handle export
if (isset($_GET['export']) && !empty($students)) {
    handleGenericExport($students, $_GET['export'], 'sf8', $school_year);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF8 - Learner's Basic Health Profile</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 100px; }
        .container { max-width: 1400px; margin: 40px auto; padding: 0 20px; }
        .header-block { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .header-block h1 { color: #1e293b; margin: 0; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; font-size: 11px; }
        th, td { padding: 8px 10px; text-align: left; border: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #475569; font-weight: 600; text-transform: uppercase; }
        .no-print { margin-bottom: 20px; }
        @media print { .no-print { display: none; } .container { margin: 0; padding: 0; } body { padding-top: 0; } }
    </style>
</head>
<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../registrar_side_panel.php'; ?>

    <div class="container main-content">
        <div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end;">
            <div style="display: flex; gap: 10px; align-items: flex-end;">
                <a href="dashboard.php" style="text-decoration: none; color: #64748b; font-weight: 600; padding: 10px 0;">← Back</a>
                <form method="GET" style="display: flex; gap: 10px; align-items: flex-end;">
                    <div>
                        <label style="display:block; font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">School Year</label>
                        <select name="school_year" style="padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                            <?php
                            $sy_list = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($sy_list as $sy) echo "<option value='$sy' " . ($school_year == $sy ? 'selected' : '') . ">$sy</option>";
                            ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Grade</label>
                        <select name="grade_level" style="padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                            <option value="">All</option>
                            <?php
                            $gl_list = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($gl_list as $gl) echo "<option value='$gl' " . ($grade_level == $gl ? 'selected' : '') . ">$gl</option>";
                            ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Section</label>
                        <select name="section" style="padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px;">
                            <option value="">All</option>
                            <?php
                            $sec_list = $pdo->query("SELECT DISTINCT section FROM enrollments ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($sec_list as $sec) echo "<option value='$sec' " . ($section == $sec ? 'selected' : '') . ">$sec</option>";
                            ?>
                        </select>
                    </div>
                    <button type="submit" style="padding: 10px 15px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer;">Generate</button>
                </form>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="?export=pdf&school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" target="_blank" style="padding: 10px 15px; background: #ef4444; color: white; border: none; border-radius: 8px; text-decoration: none; font-size: 13px;">📕 PDF</a>
                <a href="?export=xlsx&school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" style="padding: 10px 15px; background: #22c55e; color: white; border: none; border-radius: 8px; text-decoration: none; font-size: 13px;">📊 Excel</a>
            </div>
        </div>

        <div class="header-block">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1>School Form 8 (SF8)</h1>
                    <p>Learner's Basic Health Profile</p>
                    <div style="margin-top: 10px; font-weight: 600; color: #64748b;">
                        Grade: <?= htmlspecialchars($grade_level ?: 'All') ?> | Section: <?= htmlspecialchars($section ?: 'All') ?>
                    </div>
                </div>
                <input type="text" id="reportSearch" placeholder="🔍 Search learner..." 
                       style="padding: 12px 16px; width: 300px; border: 1px solid #e2e8f0; border-radius: 20px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            </div>
        </div>

        <table id="sf8Table">
            <thead>
                <tr>
                    <th rowspan="2">#</th>
                    <th rowspan="2">LRN</th>
                    <th rowspan="2">Name of Learner</th>
                    <th rowspan="2">Sex</th>
                    <th rowspan="2">Birthdate</th>
                    <th rowspan="2">Age</th>
                    <th colspan="2">Weight (kg)</th>
                    <th colspan="2">Height (m)</th>
                    <th rowspan="2">BMI</th>
                    <th rowspan="2">Nutritional Status</th>
                    <th rowspan="2">Height-for-Age Status</th>
                    <th rowspan="2" class="no-print">Actions</th>
                </tr>
                <tr>
                    <th>Value</th><th>Date</th>
                    <th>Value</th><th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($students): $n=1; foreach ($students as $s): 
                    $birthDate = $s['birthdate'] ?? '2010-01-01';
                    $age = date_diff(date_create($birthDate), date_create('today'))->y;
                    
                    $weight = $s['weight_kg'] ?? 0;
                    $height = $s['height_m'] ?? 0;
                    $bmi = ($height > 0) ? round($weight / ($height * $height), 1) : 0;
                    
                    $status = '-';
                    if ($bmi > 0) {
                        if ($bmi < 18.5) $status = 'Underweight';
                        elseif ($bmi < 25) $status = 'Normal';
                        elseif ($bmi < 30) $status = 'Overweight';
                        else $status = 'Obese';
                    }
                    
                    $height_status = '-';
                    if ($height > 0) {
                        // Simplified Height-for-age
                        if ($height < 1.4) $height_status = 'Stunted';
                        else $height_status = 'Normal';
                    }
                ?>
                    <tr>
                        <td><?= $n++ ?></td>
                        <td><?= htmlspecialchars($s['lrn']) ?></td>
                        <td style="text-align: left; font-weight: 600;"><?= htmlspecialchars($s['student_name']) ?></td>
                        <td><?= htmlspecialchars($s['sex'] ?? 'M') ?></td>
                        <td><?= htmlspecialchars($s['birthdate'] ?? '-') ?></td>
                        <td><?= $age ?></td>
                        <td><?= $weight ?: '-' ?></td>
                        <td><?= $s['measurement_date'] ?? '-' ?></td>
                        <td><?= $height ?: '-' ?></td>
                        <td><?= $s['measurement_date'] ?? '-' ?></td>
                        <td style="font-weight: 700; color: <?= $bmi < 18.5 || $bmi >= 25 ? '#dc2626' : '#16a34a' ?>;"><?= $bmi ?: '-' ?></td>
                        <td><?= $status ?></td>
                        <td><?= $height_status ?></td>
                        <td class="no-print">
                            <button class="btn-edit" onclick="openHealthModal(<?= htmlspecialchars(json_encode([
                                'id' => $s['student_id'],
                                'name' => $s['student_name'],
                                'weight' => $weight,
                                'height' => $height,
                                'date' => $s['measurement_date'] ?? date('Y-m-d')
                            ])) ?>)">Edit</button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="13" style="text-align: center;">No student records found for health profile.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Health Update Modal -->
    <div id="healthModal" class="modal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
        <div style="background:white; margin:10% auto; padding:30px; border-radius:12px; max-width:400px; position:relative;">
            <h2 id="modalStudentName" style="margin-top:0; font-size:18px; color:#1e293b;">Update Health Profile</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_health">
                <input type="hidden" name="student_id" id="modalStudentId">
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Weight (kg)</label>
                    <input type="number" step="0.01" name="weight" id="modalWeight" required style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Height (m)</label>
                    <input type="number" step="0.01" name="height" id="modalHeight" required style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Measurement Date</label>
                    <input type="date" name="measurement_date" id="modalDate" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeHealthModal()" style="padding:10px 20px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; background:#f1f5f9;">Cancel</button>
                    <button type="submit" style="padding:10px 20px; background:#10b981; color:white; border:none; border-radius:8px; cursor:pointer;">Save Profile</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openHealthModal(data) {
            document.getElementById('modalStudentId').value = data.id;
            document.getElementById('modalStudentName').innerText = 'Health Profile: ' + data.name;
            document.getElementById('modalWeight').value = data.weight || '';
            document.getElementById('modalHeight').value = data.height || '';
            document.getElementById('modalDate').value = data.date || '';
            document.getElementById('healthModal').style.display = 'block';
        }
        function closeHealthModal() {
            document.getElementById('healthModal').style.display = 'none';
        }
    </script>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf8Table')) {
                initReportSearch('reportSearch', 'sf8Table');
            }
        });
    </script>
</body>
</html>
