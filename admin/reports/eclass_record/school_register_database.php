<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';

$pdo = db_connect();

// ── Handle POST actions (Delete / Bulk Delete) ──
$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_student') {
        $id = (int) ($_POST['registration_id'] ?? 0);
        if ($id) {
            // Delete related enrollments first, then the registration
            $pdo->prepare("DELETE FROM enrollments WHERE registration_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM registrations WHERE id = ?")->execute([$id]);
            $message = 'Student record deleted.';
            $msg_type = 'success';
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $intIds = array_map('intval', $ids);
            $pdo->prepare("DELETE FROM enrollments WHERE registration_id IN ($placeholders)")->execute($intIds);
            $pdo->prepare("DELETE FROM registrations WHERE id IN ($placeholders)")->execute($intIds);
            $message = count($ids) . ' record(s) deleted.';
            $msg_type = 'success';
        }
    }

    // PRG redirect
    $params = array_filter($_GET, fn($k) => !in_array($k, ['msg', 'msg_type']), ARRAY_FILTER_USE_KEY);
    header('Location: ?' . http_build_query($params));
    exit;
} elseif ($action === 'update_status') {
    $reg_id = (int) ($_POST['registration_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    $new_date = $_POST['status_date'] ?? date('Y-m-d');
    if ($reg_id && $new_status) {
        // Get current student and school year info
        $stmt_info = $pdo->prepare("SELECT student_id, school_year FROM enrollments WHERE registration_id = ?");
        $stmt_info->execute([$reg_id]);
        $info = $stmt_info->fetch();

        if ($info) {
            $stmt = $pdo->prepare("UPDATE enrollments SET status = ?, status_date = ? WHERE registration_id = ?");
            $stmt->execute([$new_status, $new_date, $reg_id]);

            // Map UI status to movement types
            $movement_type = '';
            if ($new_status === 'Transferred Out') $movement_type = 'Transferred Out';
            elseif ($new_status === 'Transferred In') $movement_type = 'Transferred In';
            elseif ($new_status === 'Dropped') $movement_type = 'Dropped Out';
            elseif ($new_status === 'Mortality') $movement_type = 'Mortality';
            elseif ($new_status === 'Late Enrollment') $movement_type = 'Late Enrollment';

            if ($movement_type) {
                $stmt_move = $pdo->prepare("INSERT INTO student_movements (student_id, movement_type, movement_date, school_year, remarks) VALUES (?, ?, ?, ?, ?)");
                $stmt_move->execute([$info['student_id'], $movement_type, $new_date, $info['school_year'], 'Status updated via Admin Register Database']);
            }

            $message = 'Student status updated.';
            $msg_type = 'success';
        }
    }
    // PRG redirect
    $params = array_filter($_GET, fn($k) => !in_array($k, ['msg', 'msg_type']), ARRAY_FILTER_USE_KEY);
    if ($message) {
        $params['msg'] = $message;
        $params['msg_type'] = $msg_type;
    }
    header('Location: ?' . http_build_query($params));
    exit;
}

// Flash message from redirect
$flash_msg = $_GET['msg'] ?? '';
$flash_type = $_GET['msg_type'] ?? '';

// ── Filters ──
$export_format = $_GET['export'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$school_year = $_GET['school_year'] ?? '';

// Fetch dynamic filter options from registrations + enrollments
$grade_options = $pdo->query("SELECT DISTINCT COALESCE(e.grade_level, r.grade_level_to_enroll) as gl
    FROM registrations r LEFT JOIN enrollments e ON e.registration_id = r.id
    WHERE COALESCE(e.grade_level, r.grade_level_to_enroll) IS NOT NULL
    ORDER BY gl")->fetchAll(PDO::FETCH_COLUMN);
$section_options = $pdo->query("SELECT DISTINCT e.section FROM enrollments e WHERE e.section IS NOT NULL AND e.section != '' ORDER BY e.section")->fetchAll(PDO::FETCH_COLUMN);
$sy_options = $pdo->query("SELECT DISTINCT e.school_year FROM enrollments e WHERE e.school_year IS NOT NULL AND e.school_year != '' ORDER BY e.school_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// ── Generate report from registrations (primary) + enrollments ──
function generateSchoolRegisterDatabase($pdo, $grade_level, $section, $status, $search, $school_year)
{
    $where_conditions = [];
    $params = [];

    if ($grade_level) {
        $where_conditions[] = "COALESCE(e.grade_level, r.grade_level_to_enroll) = ?";
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
    if ($status) {
        $where_conditions[] = "r.approval_status = ?";
        $params[] = $status;
    }
    if ($search) {
        $where_conditions[] = "(r.last_name LIKE ? OR r.first_name LIKE ? OR r.lrn LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s, $s, $s]);
    }

    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "SELECT 
                r.id as registration_id,
                r.lrn,
                r.last_name,
                r.first_name,
                COALESCE(r.middle_name, '') as middle_name,
                COALESCE(r.sex, '') as sex,
                r.birthdate as date_of_birth,
                COALESCE(r.age,
                    CASE WHEN r.birthdate IS NOT NULL
                         THEN TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE())
                         ELSE NULL END
                ) as age,
                COALESCE(e.grade_level, r.grade_level_to_enroll) as grade_level,
                COALESCE(e.section, '') as section,
                COALESCE(e.school_year, CONCAT(r.school_year_start, '-', r.school_year_end)) as school_year,
                COALESCE(e.enrolled_at, r.created_at) as date_enrolled,
                r.approval_status as status,
                TRIM(CONCAT_WS(' ', COALESCE(r.father_first,''), COALESCE(r.father_last,''))) as father_name,
                TRIM(CONCAT_WS(' ', COALESCE(r.mother_first,''), COALESCE(r.mother_last,''))) as mother_name,
                TRIM(CONCAT_WS(' ', COALESCE(r.guardian_first,''), COALESCE(r.guardian_last,''))) as guardian_name,
                COALESCE(r.guardian_contact, r.father_contact, r.mother_contact, '') as parent_contact_no
            FROM registrations r
            LEFT JOIN enrollments e ON e.registration_id = r.id
            $where_clause
            ORDER BY r.last_name, r.first_name, r.middle_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$reports = generateSchoolRegisterDatabase($pdo, $grade_level, $section, $status, $search, $school_year);

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format);
    exit;
}

function handleExport($data, $format)
{
    $filename = 'school_register_database_' . date('Y-m-d');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
        $out = fopen('php://output', 'w');
        fputcsv($out, ['No', 'LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Date of Birth', 'Age', 'Grade Level', 'Section', 'School Year', 'Date Enrolled', 'Status', 'Father', 'Mother', 'Guardian', 'Contact']);
        foreach ($data as $i => $r) {
            fputcsv($out, [
                $i + 1,
                $r['lrn'],
                $r['last_name'],
                $r['first_name'],
                $r['middle_name'],
                $r['sex'],
                $r['date_of_birth'],
                $r['age'],
                $r['grade_level'],
                $r['section'],
                $r['school_year'],
                $r['date_enrolled'],
                $r['status'],
                $r['father_name'],
                $r['mother_name'],
                $r['guardian_name'],
                $r['parent_contact_no']
            ]);
        }
        fclose($out);
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment; filename=\"{$filename}.xls\"");
        echo "<table border='1'>";
        echo "<tr><th>No</th><th>LRN</th><th>Last Name</th><th>First Name</th><th>Middle Name</th><th>Sex</th><th>DOB</th><th>Age</th><th>Grade</th><th>Section</th><th>SY</th><th>Date Enrolled</th><th>Status</th><th>Father</th><th>Mother</th><th>Guardian</th><th>Contact</th></tr>";
        foreach ($data as $i => $r) {
            echo "<tr>";
            echo "<td>" . ($i + 1) . "</td>";
            foreach (['lrn', 'last_name', 'first_name', 'middle_name', 'sex', 'date_of_birth', 'age', 'grade_level', 'section', 'school_year', 'date_enrolled', 'status', 'father_name', 'mother_name', 'guardian_name', 'parent_contact_no'] as $col) {
                echo "<td>" . htmlspecialchars($r[$col] ?? '') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registered Students</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d47a1;
            --secondary-color: #1976d2;
            --accent-color: #ffca28;
            --text-color: #333;
            --bg-color: #f4f6f9;
            --border-color: #dee2e6;
            --table-header-bg: #e3f2fd;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-top: var(--header-height);
            padding: 20px;
            transition: margin-left 0.25s ease;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-top: 88px;
                padding: 15px;
            }
        }

        .page-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .page-header h1 {
            color: var(--primary-color);
            margin: 0 0 5px 0;
            font-size: 24px;
            font-weight: 700;
        }

        .page-header p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }

        /* ── Flash Messages ── */
        .flash {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .flash-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .flash-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* ── Action Bar ── */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .action-bar-left,
        .action-bar-right {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* ── Buttons ── */
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #0b3d91;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-secondary {
            background: white;
            color: var(--secondary-color);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #f8f9fa;
            border-color: #c1c9d0;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        .btn-icon {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 16px;
            line-height: 1;
        }

        /* ── Filter Card ── */
        .filter-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }

        .filter-card h3 {
            margin: 0 0 15px 0;
            color: var(--primary-color);
            font-size: 15px;
            font-weight: 600;
        }

        .filter-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 140px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        /* ── Summary ── */
        .summary-bar {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            align-items: center;
        }

        .summary-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-item .label {
            font-size: 12px;
            color: #888;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .summary-item .value {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-color);
        }

        /* ── Table ── */
        .table-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }

        .table-container {
            overflow-x: auto;
            max-height: 65vh;
            overflow-y: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1200px;
        }

        .report-table th,
        .report-table td {
            border: 1px solid var(--border-color);
            padding: 8px 10px;
            text-align: left;
            vertical-align: middle;
        }

        .report-table th {
            background: var(--table-header-bg);
            color: #1565c0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .report-table tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .report-table tbody tr:hover {
            background: #f1f8ff;
        }

        .report-table td.actions {
            text-align: center;
            white-space: nowrap;
        }

        /* ── Checkbox ── */
        .report-table input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        /* ── Status Badges ── */
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        /* ── No Data ── */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }

        .no-data .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .no-data h3 {
            margin: 0 0 8px;
            color: #555;
        }

        .no-data p {
            margin: 0;
            font-size: 14px;
        }

        /* ── Print ── */
        @media print {

            .action-bar,
            .filter-card,
            .flash,
            .btn-icon {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }

            .table-container {
                max-height: none;
                overflow: visible;
            }
        }
    </style>
</head>

<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1>📋 Registered Students</h1>
            <p>View and manage enrolled students from the database</p>
        </div>

        <?php if ($flash_msg): ?>
            <div class="flash flash-<?= $flash_type === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($flash_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="action-bar-left">
                <button class="btn btn-danger" id="bulkDeleteBtn" style="display:none;" onclick="bulkDelete()">🗑️
                    Delete Selected</button>
            </div>
            <div class="action-bar-right">
                <?php if (!empty($reports)): ?>
                    <a href="?export=csv&<?= http_build_query(array_filter(['grade_level' => $grade_level, 'section' => $section, 'status' => $status, 'search' => $search, 'school_year' => $school_year])) ?>"
                        class="btn btn-secondary" target="_blank">📄 Export CSV</a>
                    <a href="?export=excel&<?= http_build_query(array_filter(['grade_level' => $grade_level, 'section' => $section, 'status' => $status, 'search' => $search, 'school_year' => $school_year])) ?>"
                        class="btn btn-secondary" target="_blank">📊 Export Excel</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <h3>🔍 Filter Records</h3>
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group" style="flex:2;">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" placeholder="Name, LRN, or Student ID..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="school_year">School Year</label>
                        <select id="school_year" name="school_year">
                            <option value="">All</option>
                            <?php foreach ($sy_options as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sy) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="grade_level">Grade Level</label>
                        <select id="grade_level" name="grade_level">
                            <option value="">All Grades</option>
                            <?php foreach ($grade_options as $g): ?>
                                <option value="<?= htmlspecialchars($g) ?>" <?= $grade_level === $g ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="section">Section</label>
                        <select id="section" name="section">
                            <option value="">All Sections</option>
                            <?php foreach ($section_options as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>" <?= $section === $s ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <label>&nbsp;</label>
                        <div style="display:flex;gap:6px;">
                            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            <a href="?" class="btn btn-secondary btn-sm">Clear</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Summary Bar -->
        <?php
        $total = count($reports);
        $male_count = count(array_filter($reports, function ($r) {
            $s = strtoupper(trim($r['sex'] ?? ''));
            return $s === 'M' || $s === 'MALE';
        }));
        $female_count = count(array_filter($reports, function ($r) {
            $s = strtoupper(trim($r['sex'] ?? ''));
            return $s === 'F' || $s === 'FEMALE';
        }));
        ?>
        <div class="summary-bar">
            <div class="summary-item">
                <span class="label">Total</span>
                <span class="value"><?= $total ?></span>
            </div>
            <div class="summary-item">
                <span class="label">👦 Male</span>
                <span class="value"><?= $male_count ?></span>
            </div>
            <div class="summary-item">
                <span class="label">👧 Female</span>
                <span class="value"><?= $female_count ?></span>
            </div>
        </div>

        <!-- Data Table -->
        <?php if (!empty($reports)): ?>
            <form id="bulkForm" method="POST"
                action="?<?= http_build_query(array_filter(['grade_level' => $grade_level, 'section' => $section, 'status' => $status, 'search' => $search, 'school_year' => $school_year])) ?>">
                <input type="hidden" name="action" value="bulk_delete">
                <div class="table-card">
                    <div class="table-container">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th style="width:35px;text-align:center;">
                                        <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                                    </th>
                                    <th>NO.</th>
                                    <th>LRN</th>
                                    <th>LAST NAME</th>
                                    <th>FIRST NAME</th>
                                    <th>MIDDLE NAME</th>
                                    <th>SEX</th>
                                    <th>DATE OF BIRTH</th>
                                    <th>AGE</th>
                                    <th>GRADE</th>
                                    <th>SECTION</th>
                                    <th>SCHOOL YEAR</th>
                                    <th>DATE ENROLLED</th>
                                    <th>STATUS</th>
                                    <th>FATHER</th>
                                    <th>MOTHER</th>
                                    <th>GUARDIAN</th>
                                    <th>CONTACT</th>
                                    <th style="width:50px;">ACTION</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $index => $student): ?>
                                    <tr>
                                        <td style="text-align:center;">
                                            <input type="checkbox" name="selected_ids[]"
                                                value="<?= $student['registration_id'] ?>" class="row-check"
                                                onchange="updateBulkBtn()">
                                        </td>
                                        <td><?= $index + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($student['lrn'] ?? '') ?></strong></td>
                                        <td><strong><?= htmlspecialchars($student['last_name'] ?? '') ?></strong></td>
                                        <td><?= htmlspecialchars($student['first_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($student['middle_name'] ?? '') ?></td>
                                        <td style="text-align:center;"><?= htmlspecialchars($student['sex'] ?? '') ?></td>
                                        <td style="text-align:center;">
                                            <?= !empty($student['date_of_birth']) ? date('m/d/Y', strtotime($student['date_of_birth'])) : '' ?>
                                        </td>
                                        <td style="text-align:center;"><?= htmlspecialchars($student['age'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($student['grade_level'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($student['section'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($student['school_year'] ?? '') ?></td>
                                        <td style="text-align:center;">
                                            <?= !empty($student['date_enrolled']) ? date('m/d/Y', strtotime($student['date_enrolled'])) : '' ?>
                                        </td>
                                        <td>
                                            <?php
                                            $st = strtolower($student['status'] ?? 'approved');
                                            ?>
                                            <span class="status-badge status-<?= $st ?>">
                                                <?= ucfirst(htmlspecialchars($student['status'] ?? '')) ?>
                                            </span>
                                        </td>
                                        <td style="font-size:11px;"><?= htmlspecialchars($student['father_name'] ?? '') ?></td>
                                        <td style="font-size:11px;"><?= htmlspecialchars($student['mother_name'] ?? '') ?></td>
                                        <td style="font-size:11px;"><?= htmlspecialchars($student['guardian_name'] ?? '') ?>
                                        </td>
                                        <td><?= htmlspecialchars($student['parent_contact_no'] ?? '') ?></td>
                                        <td class="actions">
                                            <button type="button" class="btn btn-warning btn-icon" title="Update Status"
                                                onclick="openStatusModal(<?= $student['registration_id'] ?>, '<?= htmlspecialchars(addslashes(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '')), ENT_QUOTES) ?>', '<?= $student['status'] ?? '' ?>')">📝</button>
                                            <button type="button" class="btn btn-danger btn-icon" title="Delete"
                                                onclick="deleteStudent(<?= $student['registration_id'] ?>, '<?= htmlspecialchars(addslashes(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '')), ENT_QUOTES) ?>')">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="table-card">
                <div class="no-data">
                    <div class="icon">📋</div>
                    <h3>No Records Found</h3>
                    <p>No enrolled students match the selected filters. Try adjusting your criteria or enroll students
                        first.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Delete Confirmation (hidden form) ── -->
    <form id="deleteForm" method="POST"
        action="?<?= http_build_query(array_filter(['grade_level' => $grade_level, 'section' => $section, 'status' => $status, 'search' => $search, 'school_year' => $school_year])) ?>"
        style="display:none;">
        <input type="hidden" name="action" value="delete_student">
        <input type="hidden" name="registration_id" id="deleteRecordId">
    </form>

    <script>
        // ── Delete ──
        function deleteStudent(id, name) {
            if (confirm('Are you sure you want to delete "' + name + '"?\nThis action cannot be undone.')) {
                document.getElementById('deleteRecordId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // ── Checkbox / Bulk ──
        function toggleAll(source) {
            var checkboxes = document.querySelectorAll('.row-check');
            checkboxes.forEach(function (cb) { cb.checked = source.checked; });
            updateBulkBtn();
        }

        function updateBulkBtn() {
            var checked = document.querySelectorAll('.row-check:checked').length;
            var btn = document.getElementById('bulkDeleteBtn');
            if (btn) {
                btn.style.display = checked > 0 ? 'inline-flex' : 'none';
                btn.textContent = '🗑️ Delete Selected (' + checked + ')';
            }
        }

        function bulkDelete() {
            var checked = document.querySelectorAll('.row-check:checked').length;
            if (checked === 0) return;
            if (confirm('Delete ' + checked + ' selected record(s)?\nThis action cannot be undone.')) {
                document.getElementById('bulkForm').submit();
            }
        }

        // Auto-hide flash message
        setTimeout(function () {
            var flash = document.querySelector('.flash');
            if (flash) {
                flash.style.opacity = '0';
                flash.style.transition = 'opacity 0.5s';
                setTimeout(function () { flash.remove(); }, 500);
            }
        }, 4000);
        }, 4000);

        // ── Status Modal ──
        function openStatusModal(id, name, currentStatus) {
            document.getElementById('statusModal').style.display = 'block';
            document.getElementById('statusRegId').value = id;
            document.getElementById('statusStudentName').textContent = name;
            document.getElementById('newStatus').value = currentStatus;
            document.getElementById('statusDate').value = new Date().toISOString().split('T')[0];
        }

        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        // Close on outside click
        window.onclick = function (event) {
            var modal = document.getElementById('statusModal');
            if (event.target == modal) closeStatusModal();
        }
    </script>

    <!-- ── Status Update Modal ── -->
    <div id="statusModal" class="modal"
        style="display:none; position:fixed; z-index:100; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
        <div class="modal-content"
            style="background:#fff; margin:10% auto; padding:20px; border-radius:12px; width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.1);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size:1.25rem;">Update Student Status</h3>
                <span onclick="closeStatusModal()" style="cursor:pointer; font-size:1.5rem;">&times;</span>
            </div>
            <form method="POST"
                action="?<?= http_build_query(array_filter(['grade_level' => $grade_level, 'section' => $section, 'status' => $status, 'search' => $search, 'school_year' => $school_year])) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="registration_id" id="statusRegId">

                <div style="margin-bottom:15px;">
                    <p style="margin:0; font-size:0.9rem; color:#666;">Student:</p>
                    <p id="statusStudentName" style="margin:0; font-weight:600; font-size:1.1rem;"></p>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:block; font-size:0.9rem; color:#666; margin-bottom:5px;">New Status</label>
                    <select name="status" id="newStatus" required
                        style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; background:#fff;">
                        <option value="Enrolled">Enrolled</option>
                        <option value="Late Enrollment">Late Enrollment</option>
                        <option value="Transferred In">Transferred In</option>
                        <option value="Transferred Out">Transferred Out</option>
                        <option value="Dropped">Dropped</option>
                        <option value="Mortality">Mortality</option>
                    </select>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:0.9rem; color:#666; margin-bottom:5px;">Effective Date</label>
                    <input type="date" name="status_date" id="statusDate" required
                        style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>

                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeStatusModal()" class="btn btn-secondary"
                        style="background:#f4f4f4; color:#333; border:none; padding:10px 15px; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" class="btn btn-primary"
                        style="background:#0d47a1; color:#fff; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-weight:600;">Update
                        Status</button>
                </div>
            </form>
        </div>
    </div>
</body>

</html>
