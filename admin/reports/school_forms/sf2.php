<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';

$pdo = db_connect();
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');
initialize_schema($pdo); // Ensure tables exist

// Handle import
$import_message = '';
$import_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_sf2') {
    $result = handleImport($pdo);
    if ($result['success'])
        $import_message = $result['message'];
    else
        $import_error = $result['message'];
}

if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';
    $school_year = $_GET['school_year'] ?? '';
    downloadTemplate($pdo, $grade_level, $section, $school_year);
    exit;
}

// Handle report generation
$export_format = $_GET['export'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';

$report_month = $_GET['month'] ?? date('F');
$reports = [];
$filters_applied = isset($_GET['filter']) || !empty($export_format);

if ($filters_applied) {
    // If month is numeric (1-12), convert to full month name
    if (is_numeric($report_month)) {
        $report_month = date('F', mktime(0, 0, 0, (int) $report_month, 10));
    }
    $reports = generateSF2($pdo, $grade_level, $section, $school_year, $report_month);
}

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format, 'sf2', $grade_level, $section, $school_year);
    exit;
}

function handleImport($pdo)
{
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No file uploaded or upload error.'];
    }

    // Retrieve context from POST
    $grade_level = $_POST['grade_level'] ?? '';
    $section = $_POST['section'] ?? '';
    $school_year = $_POST['school_year'] ?? '';
    $month = $_POST['month'] ?? date('F');

    if (empty($grade_level) || empty($section) || empty($school_year)) {
        return ['success' => false, 'message' => 'Missing Grade Level, Section, or School Year. Please select filters first.'];
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

    // Calculate dates for the 20 columns based on the month and school year
    // Determine year part of the month
    $sy_parts = explode('-', $school_year);
    if (count($sy_parts) !== 2)
        return ['success' => false, 'message' => 'Invalid School Year format.'];

    $start_year = (int) $sy_parts[0];
    $end_year = (int) $sy_parts[1];

    // School Year usually starts June (Year 1) to May (Year 2)
    // If month is Jun-Dec, use Year 1. If Jan-May, use Year 2.
    $month_num = date('n', strtotime("$month 1"));
    $year = ($month_num >= 6) ? $start_year : $end_year;

    // Get first 20 weekdays of the month
    $dates = [];
    $d = 1;
    while (count($dates) < 20 && checkdate($month_num, $d, $year)) {
        $timestamp = mktime(0, 0, 0, $month_num, $d, $year);
        $weekday = date('N', $timestamp); // 1 (Mon) to 7 (Sun)
        if ($weekday <= 5) { // Mon-Fri
            $dates[] = date('Y-m-d', $timestamp);
        }
        $d++;
    }

    // Find or Create Report Header
    // For simplicity, we assume teacher_id is current user (admin/registrar might need selection, but let's stick to context)
    // We'll use a dummy teacher_id for now or fetch if logged in user is teacher. 
    // Since this is admin/registrar, let's look for an existing teacher or use ID 1 (admin).
    // Better: use position_assignments if possible, but safe fallback is admin user ID.
    $teacher_id = $_SESSION['user']['id'] ?? 1;

    // Create unique report identifier
    $stmt = $pdo->prepare("SELECT id FROM sf2_reports WHERE school_year = ? AND grade_level = ? AND section = ? AND report_month = ?");
    $stmt->execute([$school_year, $grade_level, $section, $month]);
    $report_id = $stmt->fetchColumn();

    if (!$report_id) {
        $stmt = $pdo->prepare("INSERT INTO sf2_reports (teacher_id, school_year, grade_level, section, report_month, report_year) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$teacher_id, $school_year, $grade_level, $section, $month, $year]);
        $report_id = $pdo->lastInsertId();
    }

    $success_count = 0;
    $errors = 0;

    $stmt_insert = $pdo->prepare("INSERT INTO sf2_daily_attendance (sf2_report_id, student_id, student_name, sex, attendance_date, attendance_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE attendance_status = VALUES(attendance_status), remarks = VALUES(remarks)");

    while (($data = fgetcsv($handle)) !== FALSE) {
        if (count($data) < 5)
            continue;

        $name = $data[0] ?? '';
        $lrn = $data[1] ?? ''; // We use LRN or Name to identify student? ideally LRN but Name is distinct enough in section usually.
        // Let's try to get student_id from enrollments based on Name + Section + Grade
        // If not found, skip? Or trust the CSV?
        // Let's trust CSV Name for now, but we need STUDENT ID for the DB schema.
        // Let's look up student ID.

        $stmt_find = $pdo->prepare("SELECT student_id, COALESCE(e.lrn, r.lrn, '') as lrn, COALESCE(r.sex, 'M') as sex FROM enrollments e LEFT JOIN registrations r ON e.registration_id = r.id WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ? AND e.student_name = ? LIMIT 1");
        $stmt_find->execute([$school_year, $grade_level, $section, $name]);
        $student = $stmt_find->fetch();

        if (!$student) {
            // Try looser match or skip? 
            // If we can't search by name, maybe LRN?
            if (!empty($lrn)) {
                $stmt_find = $pdo->prepare("SELECT student_id, COALESCE(e.lrn, r.lrn, '') as lrn, COALESCE(r.sex, 'M') as sex FROM enrollments e LEFT JOIN registrations r ON e.registration_id = r.id WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ? AND (e.lrn = ? OR r.lrn = ?) LIMIT 1");
                $stmt_find->execute([$school_year, $grade_level, $section, $lrn, $lrn]);
                $student = $stmt_find->fetch();
            }
        }

        if (!$student) {
            $errors++; // Student not found in enrollment
            continue;
        }

        $sid = $student['student_id'];
        $sex = $student['sex'];

        // Process 20 day columns (Indices 5 to 24)
        for ($i = 0; $i < 20; $i++) {
            $col_idx = 5 + $i;
            if (!isset($dates[$i]))
                break; // No more Valid dates

            $val = trim($data[$col_idx] ?? '');
            $status = 'present'; // Default assumption if checkmark/empty in some contexts, but request says "NO DETAIL...".

            // Logic: 
            // If val is empty, it means NO DATA (or Present? Template was blank).
            // Request: "NO DETAIL AS LONG THERE IS NO UPLOADED FILE".
            // Since we Are Uploading file:
            // Empty cell = Present? Or Absent?
            // Standard: Blank usually means present. X/A means absent.
            // But if user wants to upload attendance, they might leave days blank.
            // Let's map: 'X', 'A', 'Absent', 'absent' -> absent
            // 'T', 'L' -> tardy
            // Everything else (including checkmark, P, empty) -> present

            if (in_array(strtoupper($val), ['X', 'A', 'ABSENT'])) {
                $status = 'absent';
            } elseif (in_array(strtoupper($val), ['T', 'L', 'LATE'])) {
                $status = 'tardy_late'; // Simplified
            }
            // Insert
            $stmt_insert->execute([$report_id, $sid, $name, $sex, $dates[$i], $status, '']);
        }
        $success_count++;
    }
    fclose($handle);
    return ['success' => true, 'message' => "Import processed: $success_count students updated."];
}

function generateSF2($pdo, $grade_level, $section, $school_year = '', $month = '')
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

    // 1. Get Enrolled Students
    $sql = "SELECT e.student_id, e.student_name, e.grade_level, e.section,
                COALESCE(r.sex, '') as sex, COALESCE(e.lrn, r.lrn, '') as lrn,
                COALESCE(r.father_contact, '') as father_contact,
                COALESCE(r.mother_contact, '') as mother_contact,
                e.enrolled_at, 'Present' as attendance_status,
                0 as total_absent, 0 as total_present, 0 as total_days, '' as remarks
            FROM enrollments e
            LEFT JOIN registrations r ON (r.id = e.registration_id
                OR (e.registration_id IS NULL AND r.lrn IS NOT NULL AND r.lrn != '' AND r.lrn = e.lrn))
            $where_clause
            ORDER BY e.grade_level, e.section, e.student_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $students = [];
    $seen = [];

    foreach ($results as $row) {
        $sid = $row['student_id'];
        if (!isset($seen[$sid])) {
            $seen[$sid] = true;
            $row['days'] = []; // Placeholder for attendance
            $students[$sid] = $row;
        }
    }

    // 2. Fetch Attendance Data if Month is provided
    if ($month) {
        // Find Report ID
        $stmt_rep = $pdo->prepare("SELECT id FROM sf2_reports WHERE school_year = ? AND grade_level = ? AND section = ? AND report_month = ?");
        $stmt_rep->execute([$school_year, $grade_level, $section, $month]);
        $report_id = $stmt_rep->fetchColumn();

        if ($report_id) {
            $stmt_att = $pdo->prepare("SELECT student_id, attendance_date, attendance_status FROM sf2_daily_attendance WHERE sf2_report_id = ?");
            $stmt_att->execute([$report_id]);
            $attendance = $stmt_att->fetchAll();

            // Map attendance to students
            foreach ($attendance as $att) {
                if (isset($students[$att['student_id']])) {
                    $students[$att['student_id']]['days'][$att['attendance_date']] = $att['attendance_status'];
                }
            }

            // Calculate Totals based on DB data
            foreach ($students as &$student) {
                $absent = 0;
                $present = 0;
                foreach ($student['days'] as $status) {
                    if ($status === 'absent')
                        $absent++;
                    else
                        $present++;
                }
                $student['total_absent'] = $absent;
                $student['total_present'] = $present;
                $student['total_days'] = $absent + $present;
            }
        }
    }

    return array_values($students);
}

function downloadTemplate($pdo, $grade_level = '', $section = '', $school_year = '')
{
    $headers = [
        'Student Name',
        'LRN',
        'Sex',
        'Grade Level',
        'Section',
        'W1-M',
        'W1-T',
        'W1-W',
        'W1-TH',
        'W1-F',
        'W2-M',
        'W2-T',
        'W2-W',
        'W2-TH',
        'W2-F',
        'W3-M',
        'W3-T',
        'W3-W',
        'W3-TH',
        'W3-F',
        'W4-M',
        'W4-T',
        'W4-W',
        'W4-TH',
        'W4-F',
        'Total Absent',
        'Total Present',
        'Total Days',
        'Remarks'
    ];

    $data = [];
    if (!empty($grade_level) && !empty($section)) {
        // Reuse generateSF2 logic to get students
        $data = generateSF2($pdo, $grade_level, $section, $school_year);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sf2_import_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
    fputcsv($out, $headers);

    if (!empty($data)) {
        foreach ($data as $row) {
            // Pre-fill student info, leave attendance blank
            fputcsv($out, [
                $row['student_name'] ?? '',
                $row['lrn'] ?? '',
                $row['sex'] ?? '',
                $row['grade_level'] ?? '',
                $row['section'] ?? '',
                '',
                '',
                '',
                '',
                '', // W1
                '',
                '',
                '',
                '',
                '', // W2
                '',
                '',
                '',
                '',
                '', // W3
                '',
                '',
                '',
                '',
                '', // W4
                '', // Total Absent
                '', // Total Present
                '', // Total Days
                ''  // Remarks
            ]);
        }
    }

    fclose($out);
}

function handleExport($data, $format, $report_type, $grade_level = '', $section = '', $school_year = '')
{
    if ($format === 'csv') {
        $headers = [
            'Student Name',
            'LRN',
            'Sex',
            'Grade Level',
            'Section',
            'W1-M',
            'W1-T',
            'W1-W',
            'W1-TH',
            'W1-F',
            'W2-M',
            'W2-T',
            'W2-W',
            'W2-TH',
            'W2-F',
            'W3-M',
            'W3-T',
            'W3-W',
            'W3-TH',
            'W3-F',
            'W4-M',
            'W4-T',
            'W4-W',
            'W4-TH',
            'W4-F',
            'Total Absent',
            'Total Present',
            'Total Days',
            'Remarks'
        ];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sf2_attendance_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $headers);
        foreach ($data as $row) {
            $days = array_fill(0, 20, '✓');
            fputcsv($out, [
                $row['student_name'] ?? '',
                $row['lrn'] ?? '',
                $row['sex'] ?? '',
                $row['grade_level'] ?? '',
                $row['section'] ?? '',
                ...$days,
                $row['total_absent'] ?? 0,
                $row['total_present'] ?? 0,
                $row['total_days'] ?? 0,
                $row['remarks'] ?? ''
            ]);
        }
        fclose($out);
    } elseif ($format === 'pdf') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>SF2 Attendance</title><style>
            @page{size:legal landscape;margin:10mm}body{font-family:Arial,sans-serif;font-size:9px;margin:0;padding:10px}
            h2{text-align:center;margin-bottom:5px;font-size:14px}.subtitle{text-align:center;font-size:11px;margin-bottom:10px;color:#555}
            .info{font-size:11px;margin-bottom:10px}table{width:100%;border-collapse:collapse;font-size:8px}
            th,td{border:1px solid #000;padding:2px 3px;text-align:center}th{background:#e9e9e9;font-weight:bold;font-size:7px}
            .name-col{text-align:left!important;min-width:120px}.summary{margin-top:15px;font-size:11px}
            .signature{margin-top:40px;text-align:right}.signature-line{border-bottom:1px solid #000;width:250px;display:inline-block;margin-top:30px}
            @media print{.no-print{display:none}}</style></head><body>';
        echo '<div class="no-print" style="text-align:center;margin:20px 0"><button onclick="window.print()" style="padding:10px 30px;font-size:14px;cursor:pointer;background:#0f52ba;color:white;border:none;border-radius:6px">🖨️ Print / Save as PDF</button> <button onclick="window.close()" style="padding:10px 30px;font-size:14px;cursor:pointer;background:#6c757d;color:white;border:none;border-radius:6px;margin-left:10px">Close</button></div>';
        echo '<h2>School Form 2 (SF2) Daily Attendance Report</h2>';
        echo '<p class="subtitle">(This replaces Form 1, Form 2 &amp; STS Form 4)</p>';
        echo '<div class="info"><strong>School Year:</strong> ' . htmlspecialchars($school_year ?: 'All') . ' | <strong>Grade Level:</strong> ' . htmlspecialchars($grade_level ?: 'All') . ' | <strong>Section:</strong> ' . htmlspecialchars($section ?: 'All') . ' | <strong>Month:</strong> ' . date('F Y') . '</div>';
        echo '<table><thead><tr><th rowspan="2" class="name-col">NAME</th>';
        foreach (['Week 1', 'Week 2', 'Week 3', 'Week 4'] as $w)
            echo '<th colspan="5">' . $w . '</th>';
        echo '<th rowspan="2">ABSENT</th><th rowspan="2">PRESENT</th><th rowspan="2">TOTAL</th><th rowspan="2">REMARKS</th></tr><tr>';
        for ($i = 0; $i < 4; $i++)
            foreach (['M', 'T', 'W', 'TH', 'F'] as $d)
                echo '<th>' . $d . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($data as $row) {
            echo '<tr><td class="name-col">' . htmlspecialchars($row['student_name']) . '</td>';
            for ($i = 0; $i < 20; $i++)
                echo '<td>✓</td>';
            echo '<td>' . ($row['total_absent'] ?? 0) . '</td><td>' . ($row['total_present'] ?? 0) . '</td><td>' . ($row['total_days'] ?? 0) . '</td><td>' . htmlspecialchars($row['remarks'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table><div class="summary"><strong>Total Students:</strong> ' . count($data) . '</div>';
        global $principal_name;
        echo '<div style="display:flex; justify-content:space-between; margin-top:40px;">';
        echo '<div style="text-align:center;"><div class="signature-line"></div><br><strong>Class Adviser</strong><br>Date: ' . date('M d, Y') . '</div>';
        echo '<div style="text-align:center;"><div class="signature-line">' . strtoupper($principal_name) . '</div><br><strong>School Head / Principal</strong><br>Certified Correct</div>';
        echo '</div>';
        echo '<script>window.onload=function(){window.print()}</script></body></html>';
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="sf2_attendance_' . date('Y-m-d') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $headers = [
            'Student Name',
            'LRN',
            'Sex',
            'Grade Level',
            'Section',
            'W1-M',
            'W1-T',
            'W1-W',
            'W1-TH',
            'W1-F',
            'W2-M',
            'W2-T',
            'W2-W',
            'W2-TH',
            'W2-F',
            'W3-M',
            'W3-T',
            'W3-W',
            'W3-TH',
            'W3-F',
            'W4-M',
            'W4-T',
            'W4-W',
            'W4-TH',
            'W4-F',
            'Total Absent',
            'Total Present',
            'Total Days',
            'Remarks'
        ];
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><style>td,th{mso-number-format:"\@"}</style></head><body><table border="1"><thead><tr>';
        foreach ($headers as $h)
            echo '<th style="background:#e9e9e9;font-weight:bold;text-align:center">' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($data as $row) {
            echo '<tr><td>' . htmlspecialchars($row['student_name'] ?? '') . '</td><td>' . htmlspecialchars($row['lrn'] ?? '') . '</td><td>' . htmlspecialchars($row['sex'] ?? '') . '</td><td>' . htmlspecialchars($row['grade_level'] ?? '') . '</td><td>' . htmlspecialchars($row['section'] ?? '') . '</td>';
            for ($i = 0; $i < 20; $i++)
                echo '<td>✓</td>';
            echo '<td>' . ($row['total_absent'] ?? 0) . '</td><td>' . ($row['total_present'] ?? 0) . '</td><td>' . ($row['total_days'] ?? 0) . '</td><td>' . htmlspecialchars($row['remarks'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table></body></html>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

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
        font-family: 'Inter', -apple-system, sans-serif;
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
        border: none;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
        gap: 8px;
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
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
        align-items: end;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: var(--text-main);
        font-size: 14px;
    }

    .form-group input,
    .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
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

    .status-badge {
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
    }

    .status-present {
        background: #dcfce7;
        color: #166534;
    }

    .status-absent {
        background: #fee2e2;
        color: #991b1b;
    }

    .status-tardy {
        background: #fef9c3;
        color: #854d0e;
    }

    @media print {
        .no-print {
            display: none !important;
        }

        .content {
            padding: 0 !important;
            margin: 0 !important;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
        }
    }
</style>
</head>

<body>
    <?php require_once '../../admin_header.php'; ?>
    <?php require_once '../../admin_sidebar.php'; ?>

    <div class="content">
        <div class="title-block no-print">
            <div>
                <h1 style="margin: 0; font-size: 24px; color: var(--text-main);">School Form 2 (SF2)</h1>
                <p style="margin: 5px 0 0 0; color: var(--muted); font-size: 14px;">Daily Attendance Report
                    (Registrar/Admin View)</p>
            </div>
            <div>
                <a href="../reports.php" class="btn btn-outline">&larr; Back to Reports</a>
            </div>
        </div>

        <div class="card no-print">
            <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 18px;">Filter Records</h3>
            <form method="GET" action="">
                <input type="hidden" name="filter" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>School Year</label>
                        <select name="school_year" required>
                            <option value="">Select SY</option>
                            <?php foreach ($school_years as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sy) ?></option>
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
                                    <?= htmlspecialchars($g) ?></option>
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
                        <label>Month</label>
                        <select name="month">
                            <?php
                            $months = ['June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March', 'April', 'May'];
                            foreach ($months as $m) {
                                echo '<option value="' . $m . '" ' . ($report_month === $m ? 'selected' : '') . '>' . $m . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Apply Filter</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($filters_applied): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 18px;">Attendance Results (<?= count($reports) ?> Students)</h3>
                    <div class="report-actions no-print">
                        <a href="?export=pdf&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($report_month) ?>&filter=1"
                            target="_blank" class="btn btn-outline">🖨️ PDF</a>
                        <a href="?export=csv&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($report_month) ?>&filter=1"
                            class="btn btn-outline">📊 CSV</a>
                        <a href="?export=excel&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($report_month) ?>&filter=1"
                            class="btn btn-outline">📈 Excel</a>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Name of Learner</th>
                                <th>LRN</th>
                                <th>Sex</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Total Days</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reports)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--muted);">No records found for the
                                        selected filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reports as $r): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($r['student_name']) ?></td>
                                        <td style="font-family: monospace;"><?= htmlspecialchars($r['lrn']) ?></td>
                                        <td><?= $r['sex'] ?></td>
                                        <td style="color: var(--success); font-weight: 600;"><?= $r['total_present'] ?></td>
                                        <td style="color: var(--danger); font-weight: 600;"><?= $r['total_absent'] ?></td>
                                        <td><?= $r['total_days'] ?></td>
                                        <td><span
                                                style="font-style: italic; color: var(--muted);"><?= htmlspecialchars($r['remarks'] ?: '') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card no-print">
            <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 18px;">Bulk Import (CSV)</h3>
            <p style="color: var(--muted); font-size: 14px; margin-bottom: 20px;">Upload an attendance CSV file to sync
                records. Ensure the format matches the template.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_sf2">
                <input type="hidden" name="grade_level" value="<?= htmlspecialchars($grade_level) ?>">
                <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
                <input type="hidden" name="month" value="<?= htmlspecialchars($report_month) ?>">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="flex: 1;">
                        <input type="file" name="import_file" accept=".csv" class="btn btn-outline"
                            style="width: 100%; padding: 8px;">
                    </div>
                    <button type="submit" class="btn btn-primary">Start Import</button>
                    <a href="?action=download_template&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                        class="btn btn-outline">📥 Download Template</a>
                </div>
            </form>
        </div>
    </div>
    margin-top: 10px;
    max-width: 600px;
    }

    .sf2-summary-table th,
    .sf2-summary-table td {
    border: 1px solid var(--border-color);
    padding: 8px;
    text-align: center;
    }

    .sf2-summary-table th {
    background-color: var(--table-header-bg);
    color: #1565c0;
    font-weight: 600;
    }

    .sf2-guidelines,
    .sf2-codes {
    padding: 10px 20px;
    font-size: 11px;
    border-top: 1px solid var(--border-color);
    }

    .sf2-certification {
    padding: 30px 50px;
    display: flex;
    flex-direction: column;
    align-items: center;
    border-top: 1px solid var(--border-color);
    }

    .signature-block {
    text-align: center;
    width: 250px;
    margin-top: 30px;
    }

    .signature-line {
    border-bottom: 1px solid #000;
    margin: 40px 0 5px 0;
    }

    .no-data {
    text-align: center;
    padding: 60px 40px;
    color: #666;
    background: #fafbfc;
    border: 1px dashed var(--border-color);
    border-radius: 12px;
    }

    .no-data h3 {
    color: #444;
    margin-bottom: 10px;
    }

    @media print {

    .no-print,
    .export-buttons,
    .page-header,
    .nav-tabs {
    display: none;
    }

    .container {
    box-shadow: none;
    border: none;
    padding: 0;
    margin: 0;
    max-width: 100%;
    }

    .sf2-form {
    margin: 0;
    border: 2px solid #000;
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
            <div class="container">
                <div class="page-header">
                    <h1>SF2 - Daily Attendance Report</h1>
                    <p>Daily attendance report of learners</p>
                </div>

                <?php if (!empty($import_message)): ?>
                    <div class="alert alert-success"
                        style="background:#d4edda;color:#155724;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #c3e6cb;">
                        <?= htmlspecialchars($import_message) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($import_error)): ?>
                    <div class="alert alert-danger"
                        style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #f5c6cb;">
                        <?= htmlspecialchars($import_error) ?>
                    </div>
                <?php endif; ?>


                <div class="filter-card">
                    <div class="filter-header">
                        <div class="filter-icon">🔍</div>
                        <div class="filter-title">Filter Records</div>
                    </div>
                    <?php
                    $grade_levels_opt = [];
                    $sections_opt = [];
                    $school_years_opt = [];
                    try {
                        $stmt = $pdo->query("SELECT DISTINCT grade_level FROM enrollments WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level");
                        $grade_levels_opt = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        $stmt = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL AND section != '' ORDER BY section");
                        $sections_opt = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        $stmt = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC");
                        $school_years_opt = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e) {
                    }
                    if (empty($school_year) && !$filters_applied && !empty($school_years_opt)) {
                        try {
                            $stmt = $pdo->query("SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1");
                            $current_sy = $stmt->fetchColumn();
                            if ($current_sy)
                                $school_year = $current_sy;
                        } catch (Exception $e) {
                        }
                    }
                    ?>
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="filter" value="1">
                        <div class="filter-group">
                            <label for="school_year">School Year</label>
                            <select name="school_year" id="school_year" class="form-select">
                                <option value="">All School Years</option>
                                <?php foreach ($school_years_opt as $sy): ?>
                                    <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sy) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="month">Month</label>
                            <select name="month" id="month" class="form-select">
                                <?php
                                $months = [
                                    6 => 'June',
                                    7 => 'July',
                                    8 => 'August',
                                    9 => 'September',
                                    10 => 'October',
                                    11 => 'November',
                                    12 => 'December',
                                    1 => 'January',
                                    2 => 'February',
                                    3 => 'March',
                                    4 => 'April',
                                    5 => 'May'
                                ];
                                $selected_month = $_GET['month'] ?? date('n');
                                foreach ($months as $m_num => $m_name): ?>
                                    <option value="<?= $m_num ?>" <?= ($selected_month == $m_num) ? 'selected' : '' ?>>
                                        <?= $m_name ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="grade_level">Grade Level</label>
                            <select name="grade_level" id="grade_level" class="form-select">
                                <option value="">All Grade Levels</option>
                                <?php foreach ($grade_levels_opt as $gl): ?>
                                    <option value="<?= htmlspecialchars($gl) ?>" <?= $grade_level === $gl ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gl) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="section">Section</label>
                            <select name="section" id="section" class="form-select">
                                <option value="">All Sections</option>
                                <?php foreach ($sections_opt as $sec): ?>
                                    <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sec) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary"><span class="icon">🔎</span> Generate</button>
                            <a href="sf2.php" class="btn btn-secondary"><span class="icon">🔄</span> Reset</a>
                        </div>
                    </form>
                </div>

                <style>
                    .filter-card {
                        background: white;
                        border-radius: 12px;
                        padding: 24px;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                        border: 1px solid #e2e8f0;
                        margin-bottom: 24px;
                    }

                    .filter-header {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        margin-bottom: 20px;
                        padding-bottom: 16px;
                        border-bottom: 1px solid #f1f5f9;
                    }

                    .filter-icon {
                        width: 36px;
                        height: 36px;
                        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                        color: white;
                        border-radius: 8px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 16px;
                    }

                    .filter-title {
                        font-size: 16px;
                        font-weight: 600;
                        color: #0f172a;
                    }

                    .filter-form {
                        display: grid;
                        grid-template-columns: 1fr 1fr 1fr auto;
                        gap: 20px;
                        align-items: end;
                    }

                    .filter-group {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                    }

                    .filter-group label {
                        font-size: 13px;
                        font-weight: 600;
                        color: #64748b;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                    }

                    .form-select {
                        width: 100%;
                        padding: 10px 14px;
                        border: 1px solid #cbd5e1;
                        border-radius: 8px;
                        font-size: 14px;
                        color: #0f172a;
                        background-color: #fff;
                        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
                        background-position: right 0.5rem center;
                        background-repeat: no-repeat;
                        background-size: 1.5em 1.5em;
                        appearance: none;
                        transition: all 0.2s;
                        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                    }

                    .form-select:focus {
                        border-color: #3b82f6;
                        outline: none;
                        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
                    }

                    .filter-actions {
                        display: flex;
                        gap: 12px;
                    }

                    .filter-actions .btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 8px;
                        padding: 10px 20px;
                        border-radius: 8px;
                        font-weight: 500;
                        font-size: 14px;
                        text-decoration: none;
                        transition: all 0.2s;
                        border: none;
                        cursor: pointer;
                        height: 42px;
                    }

                    .filter-actions .btn-primary {
                        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                        color: white;
                        box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
                    }

                    .filter-actions .btn-primary:hover {
                        box-shadow: 0 4px 8px rgba(59, 130, 246, 0.4);
                        transform: translateY(-1px);
                    }

                    .filter-actions .btn-secondary {
                        background: white;
                        color: #64748b;
                        border: 1px solid #e2e8f0;
                    }

                    .filter-actions .btn-secondary:hover {
                        background: #f8fafc;
                        color: #475569;
                    }

                    @media (max-width: 768px) {
                        .filter-form {
                            grid-template-columns: 1fr;
                        }

                        .filter-actions {
                            flex-direction: column;
                        }

                        .filter-actions .btn {
                            width: 100%;
                            justify-content: center;
                        }
                    }
                </style>

                <div class="export-buttons">
                    <!-- Import Button -->
                    <button type="button" class="btn btn-primary" onclick="openImportModal()">
                        <span class="icon">📥</span> Import Data
                    </button>

                    <!-- Export Options Dropdown -->
                    <div class="dropdown" style="position:relative;display:inline-block;">
                        <button class="btn btn-primary" onclick="toggleExportMenu()"
                            style="display:flex;align-items:center;gap:5px;">
                            <span class="icon">📤</span> Export Options ▾
                        </button>
                        <div id="exportMenu" class="dropdown-content"
                            style="display:none;position:absolute;right:0;background-color:#f9f9f9;min-width:160px;box-shadow:0 8px 16px 0 rgba(0,0,0,0.2);z-index:100;border-radius:8px;overflow:hidden;border:1px solid #ddd;">
                            <a href="?export=pdf&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                                style="color:black;padding:12px 16px;text-decoration:none;display:block;font-size:14px;"
                                onmouseover="this.style.backgroundColor='#f1f1f1'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                📄 Export as PDF
                            </a>
                            <a href="?export=excel&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                                style="color:black;padding:12px 16px;text-decoration:none;display:block;font-size:14px;"
                                onmouseover="this.style.backgroundColor='#f1f1f1'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                📊 Export as Excel
                            </a>
                            <a href="?export=csv&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                                style="color:black;padding:12px 16px;text-decoration:none;display:block;font-size:14px;"
                                onmouseover="this.style.backgroundColor='#f1f1f1'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                📝 Export as CSV
                            </a>
                        </div>
                    </div>

                    <a href="../index.php" class="btn btn-secondary">← Back to Reports</a>
                </div>

                <!-- Import Modal -->
                <div id="importModal" class="modal"
                    style="display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,0.5);backdrop-filter:blur(4px);">
                    <div class="modal-content"
                        style="background-color:#fefefe;margin:10% auto;padding:0;border:none;width:500px;border-radius:12px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);">
                        <div class="modal-header"
                            style="background:#f8f9fa;padding:20px;border-bottom:1px solid #e9ecef;display:flex;justify-content:space-between;align-items:center;border-radius:12px 12px 0 0;">
                            <h2 style="margin:0;font-size:1.25rem;color:#333;">Import SF2 Data</h2>
                            <span class="close" onclick="closeImportModal()"
                                style="color:#aaa;font-size:28px;font-weight:bold;cursor:pointer;line-height:1;">&times;</span>
                        </div>
                        <form method="POST" enctype="multipart/form-data" style="margin:0;">
                            <div class="modal-body" style="padding:24px;">
                                <input type="hidden" name="action" value="import_sf2">
                                <!-- Hidden inputs to pass context -->
                                <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
                                <input type="hidden" name="grade_level" value="<?= htmlspecialchars($grade_level) ?>">
                                <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                                <input type="hidden" name="month"
                                    value="<?= htmlspecialchars($report_month ?? date('F')) ?>">
                                <div class="form-group" style="margin-bottom:20px;">
                                    <label for="import_file"
                                        style="display:block;margin-bottom:8px;font-weight:600;color:#555;">Select CSV
                                        File:</label>
                                    <input type="file" name="import_file" id="import_file" accept=".csv" required
                                        style="width:100%;padding:10px;border:2px dashed #ddd;border-radius:8px;background:#fafafa;">
                                    <div
                                        style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                                        <small class="form-text text-muted" style="color:#888;">
                                            Please ensure the file follows the standard SF2 CSV format.
                                        </small>
                                        <a href="?action=download_template&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                                            class="btn btn-sm btn-secondary" style="font-size:12px;padding:4px 8px;">
                                            📥 Download Template
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer"
                                style="padding:20px;background:#f8f9fa;border-top:1px solid #e9ecef;text-align:right;border-radius:0 0 12px 12px;">
                                <button type="button" class="btn btn-secondary" onclick="closeImportModal()"
                                    style="margin-right:10px;">Cancel</button>
                                <button type="submit" class="btn btn-primary">Upload & Import</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    // Dropdown Logic
                    function toggleExportMenu() {
                        var menu = document.getElementById("exportMenu");
                        if (menu.style.display === "block") {
                            menu.style.display = "none";
                        } else {
                            menu.style.display = "block";
                        }
                    }

                    // Close dropdown when clicking outside
                    window.onclick = function (event) {
                        if (!event.target.matches('.btn-primary') && !event.target.matches('.icon') && !event.target.innerText.includes('Export Options')) {
                            var dropdowns = document.getElementsByClassName("dropdown-content");
                            for (var i = 0; i < dropdowns.length; i++) {
                                var openDropdown = dropdowns[i];
                                if (openDropdown.style.display === "block") {
                                    openDropdown.style.display = "none";
                                }
                            }
                        }
                        if (event.target == document.getElementById('importModal')) {
                            closeImportModal();
                        }
                    }

                    // Modal Logic
                    function openImportModal() {
                        document.getElementById('importModal').style.display = "block";
                    }

                    function closeImportModal() {
                        document.getElementById('importModal').style.display = "none";
                    }
                </script>

                <?php if ($filters_applied && !empty($reports)): ?>
                    <div class="sf2-form">
                        <div class="sf2-header">
                            <div class="sf2-title">School Form 2 (SF2) Daily Attendance Report of Learners</div>
                            <div class="sf2-subtitle">(This replaces Form 1, Form 2 & STS Form 4 - Absenteeism and Dropout
                                Profile)
                            </div>
                            <div class="sf2-school-info">
                                <strong>School Name:</strong> [School Name]<br>
                                <strong>School ID:</strong> [School ID]<br>
                                <strong>School Year:</strong> <?= htmlspecialchars($school_year ?: 'All') ?><br>
                                <strong>Grade Level:</strong> <?= htmlspecialchars($grade_level ?: 'All') ?><br>
                                <strong>Section:</strong> <?= htmlspecialchars($section ?: 'All') ?><br>
                                <strong>Month:</strong> <?= htmlspecialchars($report_month ?? date('F')) ?>
                            </div>
                        </div>

                        <div class="table-container">
                            <table class="sf2-attendance-table">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="sf2-name-col">NAME (Last Name, First Name, Middle Name)</th>
                                        <th colspan="5" class="sf2-week-header">Week 1</th>
                                        <th colspan="5" class="sf2-week-header">Week 2</th>
                                        <th colspan="5" class="sf2-week-header">Week 3</th>
                                        <th colspan="5" class="sf2-week-header">Week 4</th>
                                        <th rowspan="2" class="sf2-total-col">ABSENT</th>
                                        <th rowspan="2" class="sf2-total-col">PRESENT</th>
                                        <th rowspan="2" class="sf2-total-col">TOTAL</th>
                                        <th rowspan="2" class="sf2-remarks-col">REMARKS (1. NLS, state reason, please refer
                                            to
                                            legend
                                            number 2. TRANSFERRED IN/OUT, write the name of School)</th>
                                    </tr>
                                    <tr>
                                        <th class="sf2-day-col">M</th>
                                        <th class="sf2-day-col">T</th>
                                        <th class="sf2-day-col">W</th>
                                        <th class="sf2-day-col">TH</th>
                                        <th class="sf2-day-col">F</th>
                                        <th class="sf2-day-col">M</th>
                                        <th class="sf2-day-col">T</th>
                                        <th class="sf2-day-col">W</th>
                                        <th class="sf2-day-col">TH</th>
                                        <th class="sf2-day-col">F</th>
                                        <th class="sf2-day-col">M</th>
                                        <th class="sf2-day-col">T</th>
                                        <th class="sf2-day-col">W</th>
                                        <th class="sf2-day-col">TH</th>
                                        <th class="sf2-day-col">F</th>
                                        <th class="sf2-day-col">M</th>
                                        <th class="sf2-day-col">T</th>
                                        <th class="sf2-day-col">W</th>
                                        <th class="sf2-day-col">TH</th>
                                        <th class="sf2-day-col">F</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Calculate working days for the report month
                                    $m_num = date('n', strtotime($report_month ?? date('F')));
                                    // Year logic same as import
                                    $sy_parts = explode('-', $school_year);
                                    $start_year = (int) $sy_parts[0];
                                    $end_year = (int) ($sy_parts[1] ?? $start_year + 1);
                                    $year = ($m_num >= 6) ? $start_year : $end_year;

                                    $valid_dates = [];
                                    $d = 1;
                                    while (count($valid_dates) < 20 && checkdate($m_num, $d, $year)) {
                                        $timestamp = mktime(0, 0, 0, $m_num, $d, $year);
                                        $weekday = date('N', $timestamp);
                                        if ($weekday <= 5)
                                            $valid_dates[] = date('Y-m-d', $timestamp);
                                        $d++;
                                    }

                                    foreach ($reports as $student):
                                        ?>
                                        <tr>
                                            <td class="sf2-name-col"><?= htmlspecialchars($student['student_name']) ?></td>
                                            <?php for ($i = 0; $i < 20; $i++):
                                                $date = $valid_dates[$i] ?? null;
                                                $status = '';
                                                $display = '';
                                                if ($date && isset($student['days'][$date])) {
                                                    $status = $student['days'][$date];
                                                    if ($status == 'present')
                                                        $display = '✓';
                                                    elseif ($status == 'absent')
                                                        $display = 'X';
                                                    elseif ($status == 'tardy_late')
                                                        $display = 'L';
                                                }
                                                // If no date or no status, empty cell (no checkmark/cross)
                                                ?>
                                                <td class="sf2-day-col"><?= $display ?></td>
                                            <?php endfor; ?>

                                            <td class="sf2-total-col"><?= $student['total_absent'] ?></td>
                                            <td class="sf2-total-col"><?= $student['total_present'] ?></td>
                                            <td class="sf2-total-col"><?= $student['total_days'] ?></td>
                                            <td class="sf2-remarks-col"><?= htmlspecialchars($student['remarks']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="sf2-monthly-summary">
                            <h4>Monthly Summary</h4>
                            <table class="sf2-summary-table">
                                <thead>
                                    <tr>
                                        <th>Total Students</th>
                                        <th>Total Present</th>
                                        <th>Total Absent</th>
                                        <th>Attendance Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?= count($reports) ?></td>
                                        <td><?= array_sum(array_column($reports, 'total_present')) ?></td>
                                        <td><?= array_sum(array_column($reports, 'total_absent')) ?></td>
                                        <td><?= count($reports) > 0 ? round((array_sum(array_column($reports, 'total_present')) / (count($reports) * 20)) * 100, 2) : 0 ?>%
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="sf2-guidelines">
                            <h4>Guidelines:</h4>
                            <p>1. Mark ✓ for present, X for absent</p>
                            <p>2. NLS - No Longer in School</p>
                            <p>3. Transfer - Transferred to another school</p>
                        </div>

                        <div class="sf2-codes">
                            <h4>Legend:</h4>
                            <p>✓ - Present</p>
                            <p>X - Absent</p>
                            <p>NLS - No Longer in School</p>
                        </div>

                        <div class="sf2-certification">
                            <p><strong>I certify that this is a true and correct report</strong></p>
                            <div style="display:flex; justify-content:space-between; margin-top:20px;">
                                <div class="signature-block">
                                    <div class="signature-line"></div>
                                    <p><strong>Class Adviser</strong></p>
                                    <p>Date: <?= date('M d, Y') ?></p>
                                </div>
                                <div class="signature-block">
                                    <div class="signature-line"><?= strtoupper($principal_name) ?></div>
                                    <p><strong>School Head / Principal</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($filters_applied): ?>
                    <div class="no-data">
                        <h3>No Data Found</h3>
                        <p>No student records found for the specified criteria.</p>
                        <a href="../index.php" class="btn">← Back to Reports</a>
                    </div>
                <?php else: ?>
                    <div class="no-data" style="margin-top: 50px;">
                        <div style="font-size: 48px; margin-bottom: 20px;">📋</div>
                        <h3>Ready to Generate Report</h3>
                        <p>Select School Year, Grade Level, or Section from the filters above and click "Generate" to view
                            the
                            report.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>

</html>
