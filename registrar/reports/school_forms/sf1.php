<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php'; // Ensure url_for is available

$pdo = db_connect();

// Handle import
$import_message = '';
$import_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_sf1') {
    $result = handleImport($pdo);
    if ($result['success'])
        $import_message = $result['message'];
    else
        $import_error = $result['message'];
}

// Handle report generation
$export_format = $_GET['export'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';
$reports = [];
$filters_applied = isset($_GET['filter']) || !empty($export_format);

if ($filters_applied) {
    $reports = generateSF1($pdo, $grade_level, $section, $school_year);
}

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format, 'sf1', $grade_level, $section, $school_year);
    exit;
}

function handleImport($pdo)
{
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No file uploaded or upload error.'];
    }

    $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return ['success' => false, 'message' => 'Only CSV files are supported.'];
    }

    $handle = fopen($_FILES['import_file']['tmp_name'], 'r');
    $bom = fread($handle, 3);
    if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF))
        rewind($handle);

    // Skip header
    fgetcsv($handle);

    $success_count = 0;
    while (($data = fgetcsv($handle)) !== FALSE) {
        if (count($data) < 2)
            continue;
        $lrn = $data[0] ?? '';
        $name = $data[1] ?? '';
        if (empty($lrn))
            continue;

        // Upsert logic for registrations
        $stmt = $pdo->prepare("INSERT INTO registrations (lrn, student_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE student_name = VALUES(student_name)");
        $stmt->execute([$lrn, $name]);
        $success_count++;
    }
    fclose($handle);
    return ['success' => true, 'message' => "Import successful: $success_count records processed."];
}

function generateSF1($pdo, $grade_level, $section, $school_year = '')
{
    $where_conditions = [];
    $params = [];
    if ($grade_level) {
        $where_conditions[] = "e.grade_level = ?";
        $params[] = $grade_level;
    }
    if ($section) {
        $where_conditions[] = "e.section = ?";
        $params[] = $section;
    }
    if ($school_year) {
        $where_conditions[] = "e.school_year = ?";
        $params[] = $school_year;
    }
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "SELECT e.*, r.sex, r.birthdate, r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city,
            r.father_first, r.father_last, r.father_middle, r.mother_first, r.mother_last, r.mother_middle,
            r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last,
            (YEAR(CURRENT_DATE) - YEAR(r.birthdate)) - (DATE_FORMAT(CURRENT_DATE, '%m%d') < DATE_FORMAT(r.birthdate, '%m%d')) as age,
            r.last_name, r.first_name, e.status as enrollment_status
            FROM enrollments e 
            LEFT JOIN registrations r ON (r.id = e.registration_id OR (e.registration_id IS NULL AND r.lrn = e.lrn))
            $where_clause
            ORDER BY e.grade_level, e.section, e.student_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function handleExport($reports, $format, $type, $grade, $section, $sy)
{
    if ($format === 'pdf') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>SF1 Export</title>
        <style>
            body { font-family: sans-serif; font-size: 11px; padding: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #333; padding: 8px; text-align: left; }
            th { background: #f1f5f9; }
            .header { text-align: center; margin-bottom: 30px; }
        </style></head><body>';
        echo '<div class="header">
            <h1>School Form 1 (SF1) School Register</h1>
            <p>SY: ' . htmlspecialchars($sy) . ' | Grade: ' . htmlspecialchars($grade) . ' | Section: ' . htmlspecialchars($section ?: 'All') . '</p>
        </div>
        <table><thead><tr><th>LRN</th><th>Name</th><th>Sex</th><th>Birthdate</th><th>Age</th><th>Father\'s Name</th><th>Mother\'s Name</th><th>Guardian</th><th>Address</th><th>Status</th></tr></thead><tbody>';
        foreach ($reports as $r) {
            $address = trim(($r['curr_house_no'] ?? '') . ' ' . ($r['curr_street'] ?? '') . ', ' . ($r['curr_barangay'] ?? '') . ', ' . ($r['curr_city'] ?? ''));
            $father = trim(($r['father_first'] ?? '') . ' ' . ($r['father_last'] ?? ''));
            $mother = trim(($r['mother_first'] ?? '') . ' ' . ($r['mother_last'] ?? ''));
            $guardian = trim(($r['reg_guardian_first'] ?? $r['guardian_first'] ?? '') . ' ' . ($r['reg_guardian_last'] ?? $r['guardian_last'] ?? ''));
            
            echo '<tr>
                <td>' . $r['lrn'] . '</td>
                <td>' . $r['student_name'] . '</td>
                <td>' . $r['sex'] . '</td>
                <td>' . $r['birthdate'] . '</td>
                <td>' . $r['age'] . '</td>
                <td>' . htmlspecialchars($father ?: '-') . '</td>
                <td>' . htmlspecialchars($mother ?: '-') . '</td>
                <td>' . htmlspecialchars($guardian ?: '-') . '</td>
                <td>' . $address . '</td>
                <td>' . ($r['enrollment_status'] ?? 'Enrolled') . '</td>
            </tr>';
        }
        echo '</tbody></table><script>window.onload=function(){window.print();}</script></body></html>';
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sf1_export.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['LRN', 'Name', 'Sex', 'Birthdate', 'Age', 'Father', 'Mother', 'Guardian', 'Address', 'Status']);
        foreach ($reports as $r) {
            $address = trim(($r['curr_house_no'] ?? '') . ' ' . ($r['curr_street'] ?? '') . ', ' . ($r['curr_barangay'] ?? '') . ', ' . ($r['curr_city'] ?? ''));
            $father = trim(($r['father_first'] ?? '') . ' ' . ($r['father_last'] ?? ''));
            $mother = trim(($r['mother_first'] ?? '') . ' ' . ($r['mother_last'] ?? ''));
            $guardian = trim(($r['reg_guardian_first'] ?? $r['guardian_first'] ?? '') . ' ' . ($r['reg_guardian_last'] ?? $r['guardian_last'] ?? ''));
            fputcsv($out, [$r['lrn'], $r['student_name'], $r['sex'], $r['birthdate'], $r['age'], $father, $mother, $guardian, $address, $r['enrollment_status']]);
        }
        fclose($out);
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="sf1_export.xls"');
        echo '<table border="1"><thead><tr><th>LRN</th><th>Name</th><th>Sex</th><th>Birthdate</th><th>Age</th><th>Father</th><th>Mother</th><th>Guardian</th><th>Address</th><th>Status</th></tr></thead><tbody>';
        foreach ($reports as $r) {
            $address = trim(($r['curr_house_no'] ?? '') . ' ' . ($r['curr_street'] ?? '') . ', ' . ($r['curr_barangay'] ?? '') . ', ' . ($r['curr_city'] ?? ''));
            $father = trim(($r['father_first'] ?? '') . ' ' . ($r['father_last'] ?? ''));
            $mother = trim(($r['mother_first'] ?? '') . ' ' . ($r['mother_last'] ?? ''));
            $guardian = trim(($r['reg_guardian_first'] ?? $r['guardian_first'] ?? '') . ' ' . ($r['reg_guardian_last'] ?? $r['guardian_last'] ?? ''));
            echo '<tr><td>' . $r['lrn'] . '</td><td>' . $r['student_name'] . '</td><td>' . $r['sex'] . '</td><td>' . $r['birthdate'] . '</td><td>' . $r['age'] . '</td><td>' . $father . '</td><td>' . $mother . '</td><td>' . $guardian . '</td><td>' . $address . '</td><td>' . $r['enrollment_status'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}

// Get dropdown data
$school_years = $pdo->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>School Form 1 (SF1) - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        :root {
            --bg: #f6f8fc;
            --card: #ffffff;
            --muted: #64748b;
            --border: #d7e0ee;
            --primary: #2563eb;
            --primary-600: #1d4ed8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --text-main: #0f172a;
        }

        body {
            background-color: var(--bg);
            margin: 0;
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
        }

        .content {
            padding: 140px 32px 48px;
            max-width: 1400px;
            margin: 0 auto;
            margin-left: 250px;
            box-sizing: border-box;
        }

        .title-block {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border);
        }

        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-600);
        }

        .btn-outline {
            border: 1px solid var(--border);
            color: var(--muted);
            background: white;
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--muted);
        }

        .badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-male {
            background: #eff6ff;
            color: #1e40af;
        }

        .badge-female {
            background: #fdf2f8;
            color: #9d174d;
        }
    </style>
</head>

<body>
    <?php include '../../../header.php'; ?>
    <?php require_once '../../registrar_side_panel.php'; ?>

    <div class="content">
        <?php if ($import_message): ?>
            <div class="card" style="background:#dcfce7; color:#166534;">
                <?= $import_message ?>
            </div>
        <?php endif; ?>
        <?php if ($import_error): ?>
            <div class="card" style="background:#fee2e2; color:#991b1b;">
                <?= $import_error ?>
            </div>
        <?php endif; ?>

        <div class="title-block">
            <div>
                <h1>School Form 1 (SF1)</h1>
                <p>School Register (Registrar/Admin View)</p>
            </div>
            <a href="../reports.php" class="btn btn-outline">&larr; Back to Reports</a>
        </div>

        <div class="card">
            <h3 style="margin-top:0; margin-bottom:20px;">Filter Records</h3>
            <form method="GET">
                <input type="hidden" name="filter" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>School Year</label>
                        <select name="school_year" required>
                            <option value="">Select SY</option>
                            <?php foreach ($school_years as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sy) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Grade Level</label>
                        <select name="grade_level" required>
                            <option value="">Select Grade</option>
                            <?php
                            $grades = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($grades as $g): ?>
                                <option value="<?= htmlspecialchars($g) ?>" <?= $grade_level === $g ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <select name="section">
                            <option value="">- All Sections -</option>
                            <?php
                            if ($grade_level) {
                                $stmt = $pdo->prepare("SELECT DISTINCT section FROM enrollments WHERE grade_level = ? ORDER BY section");
                                $stmt->execute([$grade_level]);
                                while ($s = $stmt->fetchColumn()) {
                                    echo '<option value="' . htmlspecialchars($s) . '" ' . ($section === $s ? 'selected' : '') . '>' . htmlspecialchars($s) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="width:100%;">Apply Filter</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($filters_applied): ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;">Register Results (
                        <?= count($reports) ?> Students)
                    </h3>
                    <div style="display:flex; gap:10px;">
                        <a href="?export=pdf&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>&filter=1"
                            target="_blank" class="btn btn-outline">🖨️ PDF</a>
                        <a href="?export=csv&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>&filter=1"
                            class="btn btn-outline">📊 CSV</a>
                        <a href="?export=excel&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>&filter=1"
                            class="btn btn-outline">📈 Excel</a>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>LRN</th>
                                <th>Name</th>
                                <th>Sex</th>
                                <th>Birthdate</th>
                                <th>Age</th>
                                <th>Father's Name</th>
                                <th>Mother's Name</th>
                                <th>Guardian</th>
                                <th>Address</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $r):
                                $address = trim(($r['curr_house_no'] ?? '') . ' ' . ($r['curr_street'] ?? '') . ', ' . ($r['curr_barangay'] ?? '') . ', ' . ($r['curr_city'] ?? ''));
                                ?>
                                <tr>
                                    <td style="font-family:monospace;">
                                        <?= htmlspecialchars($r['lrn']) ?>
                                    </td>
                                    <td style="font-weight:600;">
                                        <?= htmlspecialchars($r['student_name']) ?>
                                    </td>
                                    <td><span class="badge <?= $r['sex'] === 'M' ? 'badge-male' : 'badge-female' ?>">
                                            <?= $r['sex'] ?>
                                        </span></td>
                                    <td>
                                        <?= $r['birthdate'] ?>
                                    </td>
                                    <td>
                                        <?= $r['age'] ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(trim(($r['father_first'] ?? '') . ' ' . ($r['father_last'] ?? '')) ?: '-') ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(trim(($r['mother_first'] ?? '') . ' ' . ($r['mother_last'] ?? '')) ?: '-') ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(trim(($r['reg_guardian_first'] ?? $r['guardian_first'] ?? '') . ' ' . ($r['reg_guardian_last'] ?? $r['guardian_last'] ?? '')) ?: '-') ?>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?= htmlspecialchars($address) ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: #f1f5f9; color: #475569;">
                                            <?= htmlspecialchars($r['enrollment_status'] ?? 'Enrolled') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-top:0; margin-bottom:20px;">Bulk Import (CSV)</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_sf1">
                <div style="display:flex; gap:15px; align-items:center;">
                    <input type="file" name="import_file" accept=".csv" class="btn btn-outline" style="flex:1;">
                    <button type="submit" class="btn btn-primary">Start Import</button>
                </div>
            </form>
        </div>
    </div>
</body>

</html>
