<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php'; // Ensure url_for is available

$pdo = db_connect();
initialize_schema($pdo);

// Handle template download
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    downloadTemplate($pdo, $_GET['grade_level'] ?? '', $_GET['section'] ?? '', $_GET['school_year'] ?? '');
    exit;
}

// Handle import
$import_message = '';
$import_success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $result = handleImport($pdo, $_FILES['import_file'] ?? null, $_POST['school_year'] ?? '', $_POST['grade_level'] ?? '', $_POST['section'] ?? '');
    $import_message = $result['message'];
    $import_success = $result['success'];
}

// Handle report generation
$export_format = $_GET['export'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';

$reports = [];
$filters_applied = isset($_GET['filter']) || !empty($export_format) || $import_success;

if ($filters_applied) {
    try {
        $reports = generateSF5($pdo, $grade_level, $section, $school_year);
    } catch (Exception $e) {
        $error_message = "Error generating report: " . $e->getMessage();
    }
}

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format, $school_year, $grade_level, $section);
    exit;
}

// ===================== BACKEND FUNCTIONS =====================

function generateSF5($pdo, $grade_level, $section, $school_year = '')
{
    // Try to load from DB first
    if ($grade_level && $section && $school_year) {
        $stmt = $pdo->prepare("SELECT id FROM sf5_reports WHERE school_year = ? AND grade_level = ? AND section = ?");
        $stmt->execute([$school_year, $grade_level, $section]);
        $report_id = $stmt->fetchColumn();

        if ($report_id) {
            $stmt = $pdo->prepare("SELECT * FROM sf5_students WHERE sf5_report_id = ? ORDER BY sex, student_name");
            $stmt->execute([$report_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Fall back to enrollment data with empty promotion fields
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

    $sql = "SELECT 
                e.student_id,
                e.student_name,
                e.grade_level,
                e.section,
                r.lrn,
                r.sex,
                0 as general_average,
                '' as action_taken,
                '' as learning_areas_not_met
            FROM enrollments e
            LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
            $where_clause
            ORDER BY r.sex, e.student_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Supplement with live grades if available
    foreach ($students as &$s) {
        // Fetch all subject final grades for this student
        $g_stmt = $pdo->prepare("SELECT subject_id, final_grade, q1, q2, q3, q4
                                 FROM grades 
                                 WHERE student_id = ? AND school_year = ?");
        $g_stmt->execute([$s['student_id'], $school_year]);
        $subject_grades = $g_stmt->fetchAll();

        if ($subject_grades) {
            $total_sum = 0;
            $sub_count = 0;
            $failed_areas = [];

            foreach ($subject_grades as $sg) {
                // Use final_grade if available, otherwise calculate from quarters
                $final = $sg['final_grade'];
                if (!$final) {
                    $qs = array_filter([$sg['q1'], $sg['q2'], $sg['q3'], $sg['q4']], fn($v) => !is_null($v));
                    if (!empty($qs)) $final = array_sum($qs) / count($qs);
                }

                if ($final > 0) {
                    $total_sum += $final;
                    $sub_count++;
                    if ($final < 75) {
                        $sn_stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                        $sn_stmt->execute([$sg['subject_id']]);
                        $failed_areas[] = $sn_stmt->fetchColumn() ?: "Subject ".$sg['subject_id'];
                    }
                }
            }

            if ($sub_count > 0) {
                $s['general_average'] = round($total_sum / $sub_count, 2);
                $s['learning_areas_not_met'] = implode(', ', $failed_areas);
                if ($s['general_average'] >= 75 && empty($failed_areas)) {
                    $s['action_taken'] = 'PROMOTED';
                } elseif ($s['general_average'] >= 75 && !empty($failed_areas)) {
                    $s['action_taken'] = 'CONDITIONAL';
                } else {
                    $s['action_taken'] = 'RETAINED';
                }
            }
        }
    }
    return $students;
}

function downloadTemplate($pdo, $grade_level, $section, $school_year)
{
    $filename = "sf5_template";
    if ($grade_level)
        $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '', $grade_level);
    if ($section)
        $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '', $section);
    $filename .= ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    // Header row
    fputcsv($out, ['LRN', 'Student Name', 'Sex', 'General Average', 'Action Taken', 'Learning Areas Not Met']);

    // Get enrolled students
    $where = [];
    $params = [];
    if ($grade_level) {
        $where[] = "e.grade_level = ?";
        $params[] = $grade_level;
    }
    if ($section) {
        $where[] = "e.section = ?";
        $params[] = $section;
    }
    if ($school_year) {
        $where[] = "e.school_year = ?";
        $params[] = $school_year;
    }

    $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT e.student_id, e.student_name, r.lrn, r.sex
            FROM enrollments e
            LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
            $where_clause
            ORDER BY r.sex, e.student_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($students as $s) {
        fputcsv($out, [
            $s['lrn'] ?? '',
            $s['student_name'],
            $s['sex'] ?? '',
            '',  // General Average — user fills this in
            '',  // Action Taken — user fills this in (PROMOTED/CONDITIONAL/RETAINED)
            ''   // Learning Areas Not Met — user fills this in
        ]);
    }

    fclose($out);
}

function handleImport($pdo, $file, $school_year, $grade_level, $section)
{
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error.'];
    }
    if (!$school_year || !$grade_level || !$section) {
        return ['success' => false, 'message' => 'Please select School Year, Grade Level, and Section before importing.'];
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === FALSE) {
        return ['success' => false, 'message' => 'Cannot open file.'];
    }

    // Skip header row
    fgetcsv($handle);

    $pdo->beginTransaction();
    try {
        // Delete existing report for this combo
        $pdo->prepare("DELETE FROM sf5_reports WHERE school_year = ? AND grade_level = ? AND section = ?")
            ->execute([$school_year, $grade_level, $section]);

        // Create new report
        $pdo->prepare("INSERT INTO sf5_reports (school_year, grade_level, section) VALUES (?, ?, ?)")
            ->execute([$school_year, $grade_level, $section]);
        $report_id = $pdo->lastInsertId();

        $stmt_ins = $pdo->prepare("INSERT INTO sf5_students (
            sf5_report_id, student_id, student_name, lrn, sex, general_average, action_taken, learning_areas_not_met
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $row_count = 0;
        while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
            if (count($data) < 3)
                continue; // Skip empty/malformed rows

            $lrn = trim($data[0] ?? '');
            $name = trim($data[1] ?? '');
            $sex = strtoupper(trim($data[2] ?? 'M'));
            $avg = floatval($data[3] ?? 0);
            $action = strtoupper(trim($data[4] ?? 'PROMOTED'));
            $areas_not_met = trim($data[5] ?? '');

            if (empty($name))
                continue;

            // Validate action
            if (!in_array($action, ['PROMOTED', 'CONDITIONAL', 'RETAINED', ''])) {
                $action = 'PROMOTED';
            }

            // Use LRN as student_id fallback
            $student_id = $lrn ?: ('import_' . $row_count);

            $stmt_ins->execute([
                $report_id,
                $student_id,
                $name,
                $lrn,
                $sex ?: 'M',
                $avg,
                $action,
                $areas_not_met
            ]);
            $row_count++;
        }

        $pdo->commit();
        fclose($handle);
        return ['success' => true, 'message' => "Successfully imported $row_count students for $grade_level - $section."];
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        return ['success' => false, 'message' => 'Import failed: ' . $e->getMessage()];
    }
}

function handleExport($data, $format, $school_year, $grade_level, $section)
{
    $label = "sf5_{$grade_level}_{$section}_{$school_year}";
    $label = preg_replace('/[^a-zA-Z0-9_]/', '', $label);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $label . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['LRN', 'Student Name', 'Sex', 'General Average', 'Action Taken', 'Learning Areas Not Met']);
        foreach ($data as $row) {
            fputcsv($out, [
                $row['lrn'] ?? '',
                $row['student_name'],
                $row['sex'] ?? '',
                $row['general_average'],
                $row['action_taken'],
                $row['learning_areas_not_met'] ?? ''
            ]);
        }
        fclose($out);
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $label . '.xls"');
        echo "<table border='1'>";
        echo "<tr><th>LRN</th><th>Student Name</th><th>Sex</th><th>General Average</th><th>Action Taken</th><th>Learning Areas Not Met</th></tr>";
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['lrn'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['student_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['sex'] ?? '') . "</td>";
            echo "<td>" . $row['general_average'] . "</td>";
            echo "<td>" . htmlspecialchars($row['action_taken']) . "</td>";
            echo "<td>" . htmlspecialchars($row['learning_areas_not_met'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } elseif ($format === 'pdf') {
        // Generate a printable HTML page that auto-triggers browser print dialog
        $sy = htmlspecialchars($school_year);
        $gl = htmlspecialchars($grade_level);
        $sec = htmlspecialchars($section);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>SF5 Report</title>';
        echo '<style>';
        echo 'body{font-family:Arial,sans-serif;font-size:11px;margin:20px;}';
        echo 'h2{text-align:center;margin-bottom:5px;}';
        echo '.subtitle{text-align:center;font-style:italic;margin-bottom:15px;}';
        echo '.info{margin-bottom:15px;font-size:12px;}';
        echo 'table{width:100%;border-collapse:collapse;}';
        echo 'th,td{border:1px solid #000;padding:5px;text-align:center;}';
        echo 'th{background:#e3f2fd;font-weight:bold;}';
        echo '.name-col{text-align:left;}';
        echo '.gender-row td{background:#e8eaf6;font-weight:bold;text-align:left;}';
        echo '.signature{margin-top:40px;text-align:right;}';
        echo '.sig-line{border-bottom:1px solid #000;width:200px;display:inline-block;margin-top:30px;}';
        echo '@media print{@page{size:landscape;margin:10mm;}}';
        echo '</style></head><body>';
        echo "<h2>School Form 5 (SF5) Report on Promotion</h2>";
        echo '<div class="subtitle">(This replaces Form 18 - Report on Promotion)</div>';
        echo '<div class="info">';
        echo "<strong>School:</strong> Malolos Marine Fishery School &amp; Laboratory &nbsp;&nbsp; ";
        echo "<strong>School ID:</strong> 300750 &nbsp;&nbsp; ";
        echo "<strong>School Year:</strong> {$sy} &nbsp;&nbsp; ";
        echo "<strong>Grade:</strong> {$gl} &nbsp;&nbsp; ";
        echo "<strong>Section:</strong> {$sec}";
        echo '</div>';
        echo '<table><thead><tr><th>#</th><th>LRN</th><th>Student Name</th><th>General Average</th><th>Action Taken</th><th>Learning Areas Not Met</th></tr></thead><tbody>';
        $current_sex = '';
        $cnt = 0;
        foreach ($data as $row) {
            $sex = $row['sex'] ?? '';
            if ($current_sex !== $sex) {
                $current_sex = $sex;
                $cnt = 0;
                $lbl = ($sex === 'M') ? 'MALE' : 'FEMALE';
                echo "<tr class=\"gender-row\"><td colspan=\"6\">{$lbl}</td></tr>";
            }
            $cnt++;
            echo '<tr>';
            echo '<td>' . $cnt . '</td>';
            echo '<td>' . htmlspecialchars($row['lrn'] ?? '') . '</td>';
            echo '<td class="name-col">' . htmlspecialchars($row['student_name']) . '</td>';
            echo '<td>' . ($row['general_average'] > 0 ? number_format($row['general_average'], 2) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['action_taken']) . '</td>';
            echo '<td>' . htmlspecialchars($row['learning_areas_not_met'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<div class="signature"><div class="sig-line"></div><br><strong>Class Adviser</strong><br>Date: ' . date('M d, Y') . '</div>';
        echo '<script>window.onload=function(){window.print();}</script>';
        echo '</body></html>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF5 - Report on Promotion</title>
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
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.5;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .main-content {
            margin-top: 120px;
            padding: 20px;
            transition: margin-left 0.25s ease;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-top: 88px;
                margin-left: 0 !important;
                padding: 15px;
            }

            .container {
                padding: 15px;
            }
        }

        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .page-header h1 {
            color: var(--primary-color);
            margin: 0 0 10px 0;
            font-size: 24px;
            font-weight: 700;
        }

        .page-header p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }

        /* Navigation Tabs */
        .nav-tabs {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 25px;
            padding-bottom: 0;
            overflow-x: auto;
            flex-wrap: wrap;
        }

        .nav-link {
            text-decoration: none;
            color: #666;
            padding: 10px 20px;
            border-radius: 6px 6px 0 0;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .nav-link:hover {
            color: var(--primary-color);
            background: #eef4ff;
        }

        .nav-link.active {
            color: var(--primary-color);
            background: white;
            border-color: var(--border-color);
            border-bottom-color: white;
            margin-bottom: -1px;
            font-weight: 600;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--border-color);
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
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
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
            display: flex;
            gap: 20px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-select {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            color: #0f172a;
            background-color: #fff;
            min-width: 160px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            transition: border-color 0.2s;
        }

        .form-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(13, 71, 161, 0.1);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            box-shadow: 0 2px 4px rgba(13, 71, 161, 0.3);
        }

        .btn-primary:hover {
            box-shadow: 0 4px 8px rgba(13, 71, 161, 0.4);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: white;
            color: #64748b;
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #f8fafc;
            color: #475569;
        }

        .btn-warning {
            background: var(--accent-color);
            color: #000;
        }

        .btn-warning:hover {
            background: #ffb300;
        }

        .btn-success {
            background: #2e7d32;
            color: white;
        }

        .btn-success:hover {
            background: #1b5e20;
        }

        .btn-info {
            background: #0288d1;
            color: white;
        }

        .btn-info:hover {
            background: #0277bd;
        }

        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .action-bar-left,
        .action-bar-right {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        /* Status Messages */
        .status-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Form styling */
        .sf5-form {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .sf5-header {
            text-align: center;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e3f2fd);
            border-bottom: 2px solid var(--border-color);
        }

        .sf5-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 5px;
            text-transform: uppercase;
            color: var(--primary-color);
        }

        .sf5-subtitle {
            font-size: 13px;
            font-style: italic;
            color: #666;
            margin-bottom: 15px;
        }

        .sf5-school-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            text-align: left;
            font-size: 13px;
            max-width: 800px;
            margin: 0 auto;
        }

        .table-container {
            overflow-x: auto;
        }

        .sf5-main-table,
        .sf5-summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 800px;
        }

        .sf5-main-table th,
        .sf5-main-table td,
        .sf5-summary-table th,
        .sf5-summary-table td {
            border: 1px solid var(--border-color);
            padding: 10px 8px;
            text-align: center;
            vertical-align: middle;
        }

        .sf5-main-table th,
        .sf5-summary-table th {
            background-color: var(--table-header-bg);
            color: #1565c0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .sf5-main-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .sf5-main-table tbody tr:hover {
            background-color: #f1f8ff;
        }

        .sf5-name-col {
            text-align: left !important;
        }

        .sf5-gender-header td {
            background-color: #e8eaf6;
            font-weight: 700;
            text-align: left;
            padding-left: 15px;
            color: var(--primary-color);
        }

        .sf5-summary-section {
            padding: 25px;
            background: #fafbfc;
            border-top: 2px solid var(--border-color);
        }

        .sf5-summary-section h4 {
            margin: 0 0 15px 0;
            color: var(--primary-color);
        }

        .sf5-summary-table {
            max-width: 700px;
            min-width: auto;
        }

        .sf5-instructions,
        .sf5-certification {
            padding: 15px 25px;
            font-size: 12px;
            border-top: 1px solid var(--border-color);
        }

        .sf5-signature-section {
            padding: 30px 50px;
            display: flex;
            justify-content: flex-end;
            border-top: 1px solid var(--border-color);
        }

        .signature-block {
            text-align: center;
            width: 250px;
        }

        .signature-line {
            border-bottom: 1px solid #000;
            margin: 40px 0 5px 0;
        }

        /* Badge for action taken */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-promoted {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .badge-conditional {
            background: #fff9c4;
            color: #f57f17;
        }

        .badge-retained {
            background: #ffcdd2;
            color: #c62828;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background-color: #fff;
            margin: 8% auto;
            padding: 30px;
            border: none;
            width: 500px;
            max-width: 90%;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-content h2 {
            margin: 0 0 15px 0;
            color: var(--primary-color);
            font-size: 20px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close:hover {
            color: #333;
        }

        .modal-info {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #1565c0;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .file-input-wrapper {
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-bottom: 15px;
            transition: border-color 0.2s;
        }

        .file-input-wrapper:hover {
            border-color: var(--primary-color);
        }

        .file-input-wrapper input[type="file"] {
            width: 100%;
            font-size: 14px;
        }

        @media print {

            .no-print,
            .action-bar,
            .page-header,
            .nav-tabs,
            .filter-card {
                display: none;
            }

            .container {
                box-shadow: none;
                border: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }

            body {
                background: white;
                padding: 0;
            }

            .sf5-form {
                border: 2px solid #000;
            }

            .table-container {
                max-height: none;
                overflow: visible;
            }
        }

        /* Export Dropdown */
        .export-dropdown {
            position: relative;
            display: inline-block;
        }

        .export-dropdown-btn {
            background: #2e7d32;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .export-dropdown-btn:hover {
            background: #1b5e20;
            transform: translateY(-1px);
        }

        .export-dropdown-btn .arrow {
            font-size: 10px;
            transition: transform 0.2s;
        }

        .export-dropdown.open .arrow {
            transform: rotate(180deg);
        }

        .export-dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            background: white;
            min-width: 180px;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            border: 1px solid var(--border-color);
            z-index: 100;
            overflow: hidden;
            animation: fadeIn 0.15s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .export-dropdown.open .export-dropdown-menu {
            display: block;
        }

        .export-dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            text-decoration: none;
            color: #333;
            font-size: 14px;
            transition: background 0.15s;
        }

        .export-dropdown-menu a:hover {
            background: #f1f8ff;
            color: var(--primary-color);
        }

        .export-dropdown-menu a .icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }
    </style>
    <script>
        function openImportModal() { document.getElementById('importModal').style.display = 'block'; }
        function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }

        function toggleExportDropdown(e) {
            e.stopPropagation();
            document.getElementById('exportDropdown').classList.toggle('open');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function () {
            document.getElementById('exportDropdown')?.classList.remove('open');
        });
    </script>
</head>

<body>
    <?php include '../../../header.php'; ?>
    <?php require_once '../../registrar_side_panel.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="page-header no-print">
                <h1>SF5 - Report on Promotion</h1>
                <p>Report on promotion and learning progress</p>
            </div>

            <div class="nav-tabs no-print">
                <a href="sf1.php" class="nav-link">SF1 School Register</a>
                <a href="sf2.php" class="nav-link">SF2 Attendance</a>
                <a href="sf3.php" class="nav-link">SF3 Books</a>
                <a href="sf4.php" class="nav-link">SF4 Movement</a>
                <a href="sf5.php" class="nav-link active">SF5 Promotion</a>
                <a href="sf6.php" class="nav-link">SF6 Summary</a>
            </div>

            <div class="filter-card no-print">
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
                        <label>School Year</label>
                        <select name="school_year" class="form-select">
                            <option value="">All School Years</option>
                            <?php foreach ($school_years_opt as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sy) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Grade Level</label>
                        <select name="grade_level" class="form-select">
                            <option value="">All Grade Levels</option>
                            <?php foreach ($grade_levels_opt as $gl): ?>
                                <option value="<?= htmlspecialchars($gl) ?>" <?= $grade_level === $gl ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Section</label>
                        <select name="section" class="form-select">
                            <option value="">All Sections</option>
                            <?php foreach ($sections_opt as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">🔎 Generate</button>
                        <a href="sf5.php" class="btn btn-secondary">🔄 Reset</a>
                    </div>
                </form>
            </div>

            <!-- Action Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;"
                class="no-print">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn btn-warning" onclick="openImportModal()">📥 Import Data</button>
                    <a href="?action=download_template&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                        class="btn btn-info">📋 Download Template</a>
                </div>
                <div class="export-dropdown" id="exportDropdown">
                    <button class="export-dropdown-btn" onclick="toggleExportDropdown(event)">
                        📤 Export Options <span class="arrow">▼</span>
                    </button>
                    <div class="export-dropdown-menu">
                        <a
                            href="?export=csv&filter=1&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>">
                            <span class="icon">📄</span> Export as CSV
                        </a>
                        <a
                            href="?export=excel&filter=1&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>">
                            <span class="icon">📊</span> Export as Excel
                        </a>
                        <a href="?export=pdf&filter=1&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&school_year=<?= urlencode($school_year) ?>"
                            target="_blank">
                            <span class="icon">📕</span> Export as PDF
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!empty($import_message)): ?>
                <div class="status-message"
                    style="background-color: <?= $import_success ? '#e8f5e9' : '#ffebee' ?>; color: <?= $import_success ? '#2e7d32' : '#c62828' ?>;">
                    <span style="font-size: 18px;"><?= $import_success ? '✅' : '⚠️' ?></span>
                    <?= htmlspecialchars($import_message) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="status-message" style="background-color: #ffebee; color: #c62828;">
                    <span style="font-size: 18px;">⚠️</span>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($filters_applied && !empty($reports)): ?>
                <div class="sf5-form">
                    <div class="sf5-header">
                        <div class="sf5-title">School Form 5 (SF5) Report on Promotion</div>
                        <div class="sf5-subtitle">(This replaces Form 18 - Report on Promotion)</div>
                        <div class="sf5-school-info">
                            <div><strong>School Name:</strong> Malolos Marine Fishery School & Laboratory</div>
                            <div><strong>School ID:</strong> 300750</div>
                            <div><strong>School Year:</strong> <?= htmlspecialchars($school_year ?: 'All') ?></div>
                            <div><strong>Grade Level:</strong> <?= htmlspecialchars($grade_level ?: 'All') ?></div>
                            <div><strong>Section:</strong> <?= htmlspecialchars($section ?: 'All') ?></div>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="sf5-main-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th style="width:120px;">LRN</th>
                                    <th class="sf5-name-col" style="width:250px;">NAME (Last Name, First Name, Middle Name)
                                    </th>
                                    <th style="width:100px;">GENERAL AVERAGE</th>
                                    <th style="width:120px;">ACTION TAKEN</th>
                                    <th style="width:200px;">LEARNING AREAS NOT MET</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $current_gender = '';
                                $counter = 0;
                                foreach ($reports as $student):
                                    $sex = $student['sex'] ?? '';
                                    if ($current_gender !== $sex):
                                        $current_gender = $sex;
                                        $counter = 0;
                                        ?>
                                        <tr class="sf5-gender-header">
                                            <td colspan="6"><?= $sex === 'M' ? '👨 MALE' : '👩 FEMALE' ?></td>
                                        </tr>
                                    <?php endif;
                                    $counter++;
                                    $action = $student['action_taken'] ?? '';
                                    $badge_class = '';
                                    if ($action === 'PROMOTED')
                                        $badge_class = 'badge-promoted';
                                    elseif ($action === 'CONDITIONAL')
                                        $badge_class = 'badge-conditional';
                                    elseif ($action === 'RETAINED')
                                        $badge_class = 'badge-retained';
                                    ?>
                                    <tr>
                                        <td><?= $counter ?></td>
                                        <td><?= htmlspecialchars($student['lrn'] ?? '') ?></td>
                                        <td class="sf5-name-col"><?= htmlspecialchars($student['student_name']) ?></td>
                                        <td><strong><?= $student['general_average'] > 0 ? number_format($student['general_average'], 2) : '-' ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($action): ?>
                                                <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($action) ?></span>
                                            <?php else: ?>
                                                <span style="color:#999;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($student['learning_areas_not_met'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="sf5-summary-section">
                        <h4>📊 Summary</h4>
                        <?php
                        $male_students = array_filter($reports, fn($s) => ($s['sex'] ?? '') === 'M');
                        $female_students = array_filter($reports, fn($s) => ($s['sex'] ?? '') === 'F');
                        $promoted_m = count(array_filter($male_students, fn($s) => ($s['action_taken'] ?? '') === 'PROMOTED'));
                        $promoted_f = count(array_filter($female_students, fn($s) => ($s['action_taken'] ?? '') === 'PROMOTED'));
                        $cond_m = count(array_filter($male_students, fn($s) => ($s['action_taken'] ?? '') === 'CONDITIONAL'));
                        $cond_f = count(array_filter($female_students, fn($s) => ($s['action_taken'] ?? '') === 'CONDITIONAL'));
                        $ret_m = count(array_filter($male_students, fn($s) => ($s['action_taken'] ?? '') === 'RETAINED'));
                        $ret_f = count(array_filter($female_students, fn($s) => ($s['action_taken'] ?? '') === 'RETAINED'));
                        ?>
                        <table class="sf5-summary-table">
                            <thead>
                                <tr>
                                    <th>GENDER</th>
                                    <th>TOTAL</th>
                                    <th>PROMOTED</th>
                                    <th>CONDITIONAL</th>
                                    <th>RETAINED</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>MALE</td>
                                    <td><?= count($male_students) ?></td>
                                    <td><span class="badge badge-promoted"><?= $promoted_m ?></span></td>
                                    <td><span class="badge badge-conditional"><?= $cond_m ?></span></td>
                                    <td><span class="badge badge-retained"><?= $ret_m ?></span></td>
                                </tr>
                                <tr>
                                    <td>FEMALE</td>
                                    <td><?= count($female_students) ?></td>
                                    <td><span class="badge badge-promoted"><?= $promoted_f ?></span></td>
                                    <td><span class="badge badge-conditional"><?= $cond_f ?></span></td>
                                    <td><span class="badge badge-retained"><?= $ret_f ?></span></td>
                                </tr>
                                <tr style="font-weight:700;">
                                    <td><strong>TOTAL</strong></td>
                                    <td><strong><?= count($reports) ?></strong></td>
                                    <td><strong><?= $promoted_m + $promoted_f ?></strong></td>
                                    <td><strong><?= $cond_m + $cond_f ?></strong></td>
                                    <td><strong><?= $ret_m + $ret_f ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="sf5-instructions">
                        <h4>Instructions:</h4>
                        <ol>
                            <li>Download the CSV template using the <strong>"Download Template"</strong> button above</li>
                            <li>Fill in the <strong>General Average</strong>, <strong>Action Taken</strong> (PROMOTED /
                                CONDITIONAL / RETAINED), and <strong>Learning Areas Not Met</strong> columns</li>
                            <li>Import the completed CSV using the <strong>"Import Data"</strong> button</li>
                        </ol>
                    </div>

                    <div class="sf5-certification">
                        <p><strong>I certify that this is a true and correct report</strong></p>
                    </div>

                    <div class="sf5-signature-section">
                        <div class="signature-block">
                            <p>Prepared by:</p>
                            <div class="signature-line"></div>
                            <p><strong>Class Adviser</strong></p>
                            <p>Date: <?= date('M d, Y') ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif ($filters_applied): ?>
                <div class="no-data">
                    <div style="font-size: 48px; margin-bottom: 15px;">📭</div>
                    <h3>No Data Found</h3>
                    <p>No student records found for the specified criteria.<br>
                        Try selecting a different Grade Level, Section, or School Year.</p>
                </div>
            <?php else: ?>
                <div class="no-data" style="margin-top: 20px;">
                    <div style="font-size: 48px; margin-bottom: 15px;">📋</div>
                    <h3>Ready to Generate Report</h3>
                    <p>Select School Year, Grade Level, and Section from the filters above and click "Generate" to view the
                        report.<br>
                        <strong>Tip:</strong> Download the CSV template, fill in grades, then import to populate the report.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeImportModal()">&times;</span>
            <h2>📥 Import SF5 Data</h2>

            <div class="modal-info">
                <strong>How to import:</strong><br>
                1. Download the CSV template first<br>
                2. Fill in General Average, Action Taken, and Learning Areas Not Met<br>
                3. Upload the completed file below
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
                <input type="hidden" name="grade_level" value="<?= htmlspecialchars($grade_level) ?>">
                <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">

                <div style="margin-bottom: 15px;">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 8px;">
                        Importing for: <strong><?= htmlspecialchars($grade_level ?: 'All Grades') ?> -
                            <?= htmlspecialchars($section ?: 'All Sections') ?></strong>
                        (<?= htmlspecialchars($school_year ?: 'All Years') ?>)
                    </label>
                </div>

                <div class="file-input-wrapper">
                    <p style="margin: 0 0 10px 0; color: #666;">📄 Select your completed CSV file</p>
                    <input type="file" name="import_file" accept=".csv" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;">Upload & Import</button>
            </form>
        </div>
    </div>
</body>

</html>
