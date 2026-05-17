<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/report_export_helper.php';

$pdo = db_connect();
$user_id = $_SESSION['user']['id'];

$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['sy'] ?? $_GET['school_year'] ?? '';

// Get default school year if not set
if (!$school_year) {
    $school_year = get_active_school_year($pdo);
}

// ── SECURITY CHECK: Ensure teacher is assigned to this section ──
$assigned = false;
$check_stmt = $pdo->prepare("SELECT COUNT(*) FROM position_assignments WHERE user_id = ? AND grade_level = ? AND section = ? AND school_year = ?");
$check_stmt->execute([$user_id, $grade_level, $section, $school_year]);
if ($check_stmt->fetchColumn() > 0) $assigned = true;

if (!$assigned && $_SESSION['user']['role'] !== 'admin') {
    // Also check subject teaching loads
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_teachers WHERE teacher_id = ? AND section_id IN (SELECT id FROM sections WHERE grade_level = ? AND section_name = ?) AND school_year = ?");
    $check_stmt->execute([$user_id, $grade_level, $section, $school_year]);
    if ($check_stmt->fetchColumn() > 0) $assigned = true;
}

if (!$assigned && $_SESSION['user']['role'] !== 'admin') {
    die("Error: You are not assigned to this section/grade for this school year.");
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
    
    header("Location: sf8.php?grade_level=$grade_level&section=$section&sy=$school_year&success=1");
    exit;
}

// Fetch students for health profile
$sql = "SELECT e.lrn, e.student_id, e.student_name, r.sex, r.birthdate,
               h.weight_kg, h.height_m, h.measurement_date
        FROM enrollments e 
        LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
        LEFT JOIN sf8_health_profile h ON e.student_id = h.student_id AND e.school_year = h.school_year
        WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
        ORDER BY r.sex, e.student_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$grade_level, $section, $school_year]);
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
    <title>SF8 - Learner's Health Profile | Teacher Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; padding-top: 100px; }
        .container { max-width: 1400px; margin: 40px auto; padding: 0 20px; }
        .header-block { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .header-block h1 { color: #1e293b; margin: 0; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; font-size: 11px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #475569; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .btn-edit { background: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .btn-edit:hover { background: #3b82f6; color: white; }
        @media print { .no-print { display: none; } .container { margin: 0; padding: 0; } body { padding-top: 0; } }
    </style>
</head>
<body>
    <?php include '../teacher_header.php'; ?>
    <?php include '../teacher_side_panel.php'; ?>

    <div class="container main-content">
        <div class="no-print" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
            <a href="../reports.php" style="text-decoration: none; color: #64748b; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Dashboard
            </a>
            <div style="display: flex; gap: 12px;">
                <a href="?export=pdf&sy=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" target="_blank" style="padding: 10px 18px; background: #fee2e2; color: #dc2626; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600;">📕 Export PDF</a>
                <a href="?export=xlsx&sy=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" style="padding: 10px 18px; background: #dcfce7; color: #16a34a; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600;">📊 Export Excel</a>
            </div>
        </div>

        <div class="header-block">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="display:flex; align-items:center; gap:12px;">🩺 SF8 - Health Profile</h1>
                    <p style="margin-top:4px; color:var(--text-muted);">Manage and track student BMI and nutritional status.</p>
                    <div style="margin-top: 12px; display: flex; gap: 10px;">
                        <span style="background:#e3f2fd; color:#0d47a1; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700;"><?= htmlspecialchars($grade_level) ?> - <?= htmlspecialchars($section) ?></span>
                        <span style="background:#f1f5f9; color:#475569; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700;">SY <?= htmlspecialchars($school_year) ?></span>
                    </div>
                </div>
                <input type="text" id="reportSearch" placeholder="🔍 Search student name..." 
                       style="padding: 14px 20px; width: 320px; border: 1px solid #e2e8f0; border-radius: 12px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
            </div>
        </div>

        <table id="sf8Table">
            <thead>
                <tr>
                    <th rowspan="2">#</th>
                    <th rowspan="2">LRN</th>
                    <th rowspan="2">Learner Name</th>
                    <th rowspan="2">Sex</th>
                    <th rowspan="2">Age</th>
                    <th colspan="2">Weight (kg)</th>
                    <th colspan="2">Height (m)</th>
                    <th rowspan="2">BMI</th>
                    <th rowspan="2">Nutritional Status</th>
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
                ?>
                    <tr>
                        <td><?= $n++ ?></td>
                        <td style="color:#64748b;"><?= htmlspecialchars($s['lrn']) ?></td>
                        <td style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($s['student_name']) ?></td>
                        <td style="text-align:center;"><?= htmlspecialchars($s['sex'] ?? 'M') ?></td>
                        <td style="text-align:center;"><?= $age ?></td>
                        <td style="background:#fdf2f8;"><?= $weight ?: '-' ?></td>
                        <td style="font-size:10px; color:#94a3b8;"><?= $s['measurement_date'] ?? '-' ?></td>
                        <td style="background:#f0f9ff;"><?= $height ?: '-' ?></td>
                        <td style="font-size:10px; color:#94a3b8;"><?= $s['measurement_date'] ?? '-' ?></td>
                        <td style="font-weight: 800; color: <?= $bmi < 18.5 || $bmi >= 25 ? '#dc2626' : '#16a34a' ?>; text-align:center;"><?= $bmi ?: '-' ?></td>
                        <td><span style="font-weight:600;"><?= $status ?></span></td>
                        <td class="no-print">
                            <button class="btn-edit" onclick="openHealthModal(<?= htmlspecialchars(json_encode([
                                'id' => $s['student_id'],
                                'name' => $s['student_name'],
                                'weight' => $weight,
                                'height' => $height,
                                'date' => $s['measurement_date'] ?? date('Y-m-d')
                            ])) ?>)">Update</button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="12" style="text-align: center; padding: 40px; color:#94a3b8;">No learners found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Health Update Modal -->
    <div id="healthModal" class="modal" style="display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px);">
        <div style="background:white; margin:8% auto; padding:0; border-radius:16px; max-width:440px; position:relative; overflow:hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <div style="background:var(--primary); padding:24px; color:white;">
                <h2 id="modalStudentName" style="margin:0; font-size:18px;">Update Health Profile</h2>
            </div>
            <form method="POST" style="padding:32px;">
                <input type="hidden" name="action" value="update_health">
                <input type="hidden" name="student_id" id="modalStudentId">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.025em;">Weight (kg)</label>
                    <input type="number" step="0.01" name="weight" id="modalWeight" required style="width:100%; padding:14px; border:1px solid #e2e8f0; border-radius:10px; font-size:15px; box-sizing:border-box;">
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.025em;">Height (meters)</label>
                    <input type="number" step="0.01" name="height" id="modalHeight" required style="width:100%; padding:14px; border:1px solid #e2e8f0; border-radius:10px; font-size:15px; box-sizing:border-box;" placeholder="e.g. 1.65">
                </div>
                <div style="margin-bottom:32px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.025em;">Date of Measurement</label>
                    <input type="date" name="measurement_date" id="modalDate" style="width:100%; padding:14px; border:1px solid #e2e8f0; border-radius:10px; font-size:15px; box-sizing:border-box;">
                </div>
                <div style="display:flex; gap:12px;">
                    <button type="button" onclick="closeHealthModal()" style="flex:1; padding:12px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; background:#f8fafc; font-weight:600; color:#475569;">Cancel</button>
                    <button type="submit" style="flex:2; padding:12px; background:#2563eb; color:white; border:none; border-radius:10px; cursor:pointer; font-weight:700; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openHealthModal(data) {
            document.getElementById('modalStudentId').value = data.id;
            document.getElementById('modalStudentName').innerText = data.name;
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
            const searchInput = document.getElementById('reportSearch');
            const table = document.getElementById('sf8Table');
            if (searchInput && table) {
                searchInput.addEventListener('keyup', function() {
                    const term = this.value.toLowerCase();
                    const rows = table.querySelector('tbody').rows;
                    for (let row of rows) {
                        const name = row.cells[2].textContent.toLowerCase();
                        row.style.display = name.includes(term) ? '' : 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>
