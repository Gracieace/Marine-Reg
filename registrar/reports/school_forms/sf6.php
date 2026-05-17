<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php'; // Ensure url_for is available
require_once __DIR__ . '/../../../includes/report_export_helper.php';

$pdo = db_connect();
initialize_schema($pdo);

// Handle report generation
$export_format = $_GET['export'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';

$reports = [];
$summary = [];
$filters_applied = isset($_GET['filter']) || !empty($export_format);

if ($filters_applied) {
    try {
        $result = generateSF6($pdo, $school_year, $grade_level, $section);
        $reports = $result['grades'];
        $summary = $result;
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($summary, $export_format, $school_year, $grade_level, $section);
    exit;
}

// ===================== BACKEND FUNCTIONS =====================

function generateSF6($pdo, $school_year = '', $grade_level = '', $section = '')
{
    // Build enrollment conditions
    $where = [];
    $params = [];
    if ($school_year) {
        $where[] = "e.school_year = ?";
        $params[] = $school_year;
    }
    if ($grade_level) {
        $where[] = "e.grade_level = ?";
        $params[] = $grade_level;
    }
    if ($section) {
        $where[] = "e.section = ?";
        $params[] = $section;
    }
    $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get enrollment counts per grade level
    $sql = "SELECT 
                e.grade_level,
                COUNT(CASE WHEN r.sex = 'M' THEN 1 END) as enrolled_m,
                COUNT(CASE WHEN r.sex = 'F' THEN 1 END) as enrolled_f
            FROM enrollments e
            LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
            $where_clause
            GROUP BY e.grade_level
            ORDER BY e.grade_level";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $enrollment_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get promotion data from sf5_students (joined via sf5_reports)
    $promo_where = [];
    $promo_params = [];
    if ($school_year) {
        $promo_where[] = "r.school_year = ?";
        $promo_params[] = $school_year;
    }
    if ($grade_level) {
        $promo_where[] = "r.grade_level = ?";
        $promo_params[] = $grade_level;
    }
    if ($section) {
        $promo_where[] = "r.section = ?";
        $promo_params[] = $section;
    }
    $promo_clause = $promo_where ? 'WHERE ' . implode(' AND ', $promo_where) : '';

    // Aggregation from live grades
    $promo_sql = "SELECT 
                e.grade_level,
                COUNT(CASE WHEN r.sex = 'M' AND stats.action_taken = 'PROMOTED' THEN 1 END) as promoted_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.action_taken = 'PROMOTED' THEN 1 END) as promoted_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.action_taken = 'CONDITIONAL' THEN 1 END) as conditional_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.action_taken = 'CONDITIONAL' THEN 1 END) as conditional_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.action_taken = 'RETAINED' THEN 1 END) as retained_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.action_taken = 'RETAINED' THEN 1 END) as retained_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.gen_avg < 75 THEN 1 END) as did_not_meet_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.gen_avg < 75 THEN 1 END) as did_not_meet_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.gen_avg >= 75 AND stats.gen_avg < 80 THEN 1 END) as fairly_sat_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.gen_avg >= 75 AND stats.gen_avg < 80 THEN 1 END) as fairly_sat_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.gen_avg >= 80 AND stats.gen_avg < 85 THEN 1 END) as satisfactory_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.gen_avg >= 80 AND stats.gen_avg < 85 THEN 1 END) as satisfactory_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.gen_avg >= 85 AND stats.gen_avg < 90 THEN 1 END) as very_sat_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.gen_avg >= 85 AND stats.gen_avg < 90 THEN 1 END) as very_sat_f,
                COUNT(CASE WHEN r.sex = 'M' AND stats.gen_avg >= 90 THEN 1 END) as outstanding_m,
                COUNT(CASE WHEN r.sex = 'F' AND stats.gen_avg >= 90 THEN 1 END) as outstanding_f
            FROM enrollments e
            LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
            LEFT JOIN (
                SELECT student_id, 
                       AVG(final_val) as gen_avg,
                       CASE 
                         WHEN AVG(final_val) >= 75 AND SUM(CASE WHEN final_val < 75 THEN 1 ELSE 0 END) = 0 THEN 'PROMOTED'
                         WHEN AVG(final_val) >= 75 AND SUM(CASE WHEN final_val < 75 THEN 1 ELSE 0 END) > 0 THEN 'CONDITIONAL'
                         ELSE 'RETAINED'
                       END as action_taken
                FROM (
                    SELECT student_id, subject_id, 
                           COALESCE(final_grade, (COALESCE(q1,0)+COALESCE(q2,0)+COALESCE(q3,0)+COALESCE(q4,0))/NULLIF((CASE WHEN q1 IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN q2 IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN q3 IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN q4 IS NOT NULL THEN 1 ELSE 0 END),0)) as final_val
                    FROM grades
                    WHERE school_year = ?
                ) as sub_grades
                GROUP BY student_id
            ) as stats ON e.student_id = stats.student_id
            $where_clause
            GROUP BY e.grade_level
            ORDER BY e.grade_level";

    try {
        $promo_stmt = $pdo->prepare($promo_sql);
        // We need to add school_year as the first param for the subquery
        $promo_params_live = array_merge([$school_year], $params);
        $promo_stmt->execute($promo_params_live);
        $promo_data = $promo_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $promo_data = [];
    }

    // Index promo data by grade
    $promo_by_grade = [];
    foreach ($promo_data as $p) {
        $promo_by_grade[$p['grade_level']] = $p;
    }

    // Collect all grade levels
    $all_grades = [];
    foreach ($enrollment_data as $e) {
        $all_grades[$e['grade_level']] = true;
    }
    foreach ($promo_data as $p) {
        $all_grades[$p['grade_level']] = true;
    }
    ksort($all_grades);
    $grade_levels = array_keys($all_grades);

    // Build per-grade summary
    $grades = [];
    $totals = [
        'enrolled_m' => 0,
        'enrolled_f' => 0,
        'promoted_m' => 0,
        'promoted_f' => 0,
        'conditional_m' => 0,
        'conditional_f' => 0,
        'retained_m' => 0,
        'retained_f' => 0,
        'did_not_meet_m' => 0,
        'did_not_meet_f' => 0,
        'fairly_sat_m' => 0,
        'fairly_sat_f' => 0,
        'satisfactory_m' => 0,
        'satisfactory_f' => 0,
        'very_sat_m' => 0,
        'very_sat_f' => 0,
        'outstanding_m' => 0,
        'outstanding_f' => 0,
    ];

    // Index enrollment by grade
    $enroll_by_grade = [];
    foreach ($enrollment_data as $e) {
        $enroll_by_grade[$e['grade_level']] = $e;
    }

    foreach ($grade_levels as $gl) {
        $e = $enroll_by_grade[$gl] ?? ['enrolled_m' => 0, 'enrolled_f' => 0];
        $p = $promo_by_grade[$gl] ?? [];

        $row = [
            'grade_level' => $gl,
            'enrolled_m' => (int) ($e['enrolled_m'] ?? 0),
            'enrolled_f' => (int) ($e['enrolled_f'] ?? 0),
            'promoted_m' => (int) ($p['promoted_m'] ?? 0),
            'promoted_f' => (int) ($p['promoted_f'] ?? 0),
            'conditional_m' => (int) ($p['conditional_m'] ?? 0),
            'conditional_f' => (int) ($p['conditional_f'] ?? 0),
            'retained_m' => (int) ($p['retained_m'] ?? 0),
            'retained_f' => (int) ($p['retained_f'] ?? 0),
            'did_not_meet_m' => (int) ($p['did_not_meet_m'] ?? 0),
            'did_not_meet_f' => (int) ($p['did_not_meet_f'] ?? 0),
            'fairly_sat_m' => (int) ($p['fairly_sat_m'] ?? 0),
            'fairly_sat_f' => (int) ($p['fairly_sat_f'] ?? 0),
            'satisfactory_m' => (int) ($p['satisfactory_m'] ?? 0),
            'satisfactory_f' => (int) ($p['satisfactory_f'] ?? 0),
            'very_sat_m' => (int) ($p['very_sat_m'] ?? 0),
            'very_sat_f' => (int) ($p['very_sat_f'] ?? 0),
            'outstanding_m' => (int) ($p['outstanding_m'] ?? 0),
            'outstanding_f' => (int) ($p['outstanding_f'] ?? 0),
        ];

        foreach ($totals as $key => &$val) {
            $val += $row[$key];
        }
        unset($val);

        $grades[] = $row;
    }

    return [
        'grades' => $grades,
        'totals' => $totals,
        'grade_levels' => $grade_levels,
    ];
}

function handleExport($summary, $format, $school_year, $grade_level, $section)
{
    $label = "sf6_summary_{$school_year}";
    $label = preg_replace('/[^a-zA-Z0-9_]/', '', $label);
    $grades = $summary['grades'];
    $totals = $summary['totals'];
    $grade_levels = $summary['grade_levels'];

    $rows_meta = [
        ['label' => 'Enrollment', 'key_m' => 'enrolled_m', 'key_f' => 'enrolled_f'],
        ['label' => 'Promoted', 'key_m' => 'promoted_m', 'key_f' => 'promoted_f'],
        ['label' => 'Conditional', 'key_m' => 'conditional_m', 'key_f' => 'conditional_f'],
        ['label' => 'Retained', 'key_m' => 'retained_m', 'key_f' => 'retained_f'],
        ['label' => 'Did Not Meet (Below 75)', 'key_m' => 'did_not_meet_m', 'key_f' => 'did_not_meet_f'],
        ['label' => 'Fairly Satisfactory (75-79)', 'key_m' => 'fairly_sat_m', 'key_f' => 'fairly_sat_f'],
        ['label' => 'Satisfactory (80-84)', 'key_m' => 'satisfactory_m', 'key_f' => 'satisfactory_f'],
        ['label' => 'Very Satisfactory (85-89)', 'key_m' => 'very_sat_m', 'key_f' => 'very_sat_f'],
        ['label' => 'Outstanding (90+)', 'key_m' => 'outstanding_m', 'key_f' => 'outstanding_f'],
    ];

    // Index grades by grade_level for lookup
    $grades_by_level = [];
    foreach ($grades as $g) {
        $grades_by_level[$g['grade_level']] = $g;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $label . '.csv"');
        $out = fopen('php://output', 'w');
        // Header
        $header = ['Summary'];
        foreach ($grade_levels as $gl) {
            $header[] = "$gl (M)";
            $header[] = "$gl (F)";
        }
        $header[] = 'Total (M)';
        $header[] = 'Total (F)';
        fputcsv($out, $header);
        // Data rows
        foreach ($rows_meta as $rm) {
            $row = [$rm['label']];
            foreach ($grade_levels as $gl) {
                $g = $grades_by_level[$gl] ?? [];
                $row[] = $g[$rm['key_m']] ?? 0;
                $row[] = $g[$rm['key_f']] ?? 0;
            }
            $row[] = $totals[$rm['key_m']];
            $row[] = $totals[$rm['key_f']];
            fputcsv($out, $row);
        }
        fclose($out);
    } elseif ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $label . '.xls"');
        echo "<table border='1'><thead><tr><th>Summary</th>";
        foreach ($grade_levels as $gl) {
            echo "<th>{$gl} (M)</th><th>{$gl} (F)</th>";
        }
        echo "<th>Total (M)</th><th>Total (F)</th></tr></thead><tbody>";
        foreach ($rows_meta as $rm) {
            echo "<tr><td><strong>" . htmlspecialchars($rm['label']) . "</strong></td>";
            foreach ($grade_levels as $gl) {
                $g = $grades_by_level[$gl] ?? [];
                echo "<td>" . ($g[$rm['key_m']] ?? 0) . "</td>";
                echo "<td>" . ($g[$rm['key_f']] ?? 0) . "</td>";
            }
            echo "<td><strong>" . $totals[$rm['key_m']] . "</strong></td>";
            echo "<td><strong>" . $totals[$rm['key_f']] . "</strong></td></tr>";
        }
        echo "</tbody></table>";
    } elseif ($format === 'pdf') {
        $sy = htmlspecialchars($school_year ?: 'All');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>SF6 Summary</title>';
        echo '<style>';
        echo 'body{font-family:Arial,sans-serif;font-size:10px;margin:15px;}';
        echo 'h2{text-align:center;margin-bottom:5px;}';
        echo '.sub{text-align:center;font-style:italic;margin-bottom:10px;}';
        echo '.info{margin-bottom:10px;font-size:11px;}';
        echo 'table{width:100%;border-collapse:collapse;}';
        echo 'th,td{border:1px solid #000;padding:4px 6px;text-align:center;}';
        echo 'th{background:#e3f2fd;font-weight:bold;}';
        echo 'td:first-child{text-align:left;font-weight:bold;}';
        echo '.sig{margin-top:30px;text-align:right;}';
        echo '.sigline{border-bottom:1px solid #000;width:200px;display:inline-block;margin-top:25px;}';
        echo '@media print{@page{size:landscape;margin:10mm;}}';
        echo '</style></head><body>';
        echo "<h2>School Form 6 (SF6) Summary of School Statistics</h2>";
        echo '<div class="sub">(This replaces Form 19 - Summary of School Statistics)</div>';
        echo "<div class='info'><strong>School:</strong> Malolos Marine Fishery School &amp; Laboratory &nbsp; <strong>School ID:</strong> 300750 &nbsp; <strong>School Year:</strong> {$sy}</div>";
        echo '<table><thead><tr><th rowspan="2">Summary</th>';
        foreach ($grade_levels as $gl) {
            echo "<th colspan='2'>" . htmlspecialchars($gl) . "</th>";
        }
        echo '<th colspan="2">TOTAL</th></tr><tr>';
        foreach ($grade_levels as $gl) {
            echo '<th>M</th><th>F</th>';
        }
        echo '<th>M</th><th>F</th></tr></thead><tbody>';
        foreach ($rows_meta as $rm) {
            echo '<tr><td>' . htmlspecialchars($rm['label']) . '</td>';
            foreach ($grade_levels as $gl) {
                $g = $grades_by_level[$gl] ?? [];
                echo '<td>' . ($g[$rm['key_m']] ?? 0) . '</td>';
                echo '<td>' . ($g[$rm['key_f']] ?? 0) . '</td>';
            }
            echo '<td><strong>' . $totals[$rm['key_m']] . '</strong></td>';
            echo '<td><strong>' . $totals[$rm['key_f']] . '</strong></td></tr>';
        }
        echo '</tbody></table>';
        echo '<div class="sig"><div class="sigline"></div><br><strong>School Registrar</strong><br>Date: ' . date('M d, Y') . '</div>';
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
    <title>SF6 - Summary of School Statistics</title>
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

        /* Nav Tabs */
        .nav-tabs {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 25px;
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
        }

        .btn-primary:hover {
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: white;
            color: #64748b;
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #f8fafc;
        }

        /* Table */
        .sf6-form {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .sf6-header {
            text-align: center;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e3f2fd);
            border-bottom: 2px solid var(--border-color);
        }

        .sf6-title {
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .sf6-subtitle {
            font-size: 13px;
            font-style: italic;
            color: #666;
            margin-bottom: 15px;
        }

        .sf6-school-info {
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

        .sf6-main-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 800px;
        }

        .sf6-main-table th,
        .sf6-main-table td {
            border: 1px solid var(--border-color);
            padding: 10px 8px;
            text-align: center;
            vertical-align: middle;
        }

        .sf6-main-table th {
            background-color: var(--table-header-bg);
            color: #1565c0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .sf6-main-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .sf6-main-table tbody tr:hover {
            background-color: #f1f8ff;
        }

        .sf6-main-table td:first-child {
            text-align: left;
            font-weight: 600;
            min-width: 200px;
        }

        .section-divider td {
            background: #e8eaf6 !important;
            font-weight: 700;
            color: var(--primary-color);
            text-align: left !important;
            padding-left: 15px;
        }

        .sf6-footer {
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

        @media print {

            .no-print,
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

            .sf6-form {
                border: 2px solid #000;
            }
        }
    </style>
    <script>
        function toggleExportDropdown(e) {
            e.stopPropagation();
            document.getElementById('exportDropdown').classList.toggle('open');
        }
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
                <h1>SF6 - Summary of School Statistics</h1>
                <p>Summary of school statistics and demographics</p>
            </div>

            <div class="nav-tabs no-print">
                <a href="sf1.php" class="nav-link">SF1 School Register</a>
                <a href="sf2.php" class="nav-link">SF2 Attendance</a>
                <a href="sf3.php" class="nav-link">SF3 Books</a>
                <a href="sf4.php" class="nav-link">SF4 Movement</a>
                <a href="sf5.php" class="nav-link">SF5 Promotion</a>
                <a href="sf6.php" class="nav-link active">SF6 Summary</a>
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
                    $grade_levels_opt = $pdo->query("SELECT DISTINCT grade_level FROM enrollments WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                    $sections_opt = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL AND section != '' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
                    $school_years_opt = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                }
                if (empty($school_year) && !$filters_applied && !empty($school_years_opt)) {
                    try {
                        $current_sy = $pdo->query("SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1")->fetchColumn();
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
                        <a href="sf6.php" class="btn btn-secondary">🔄 Reset</a>
                    </div>
                </form>
            </div>

            <!-- Action Bar -->
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;" class="no-print">
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

            <?php if (isset($error_message)): ?>
                <div
                    style="padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; background: #ffebee; color: #c62828; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 18px;">⚠️</span> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($filters_applied && !empty($reports)): ?>
                <?php
                $grade_levels_list = $summary['grade_levels'];
                $totals = $summary['totals'];

                // Index by grade level for easy lookup
                $grades_by_level = [];
                foreach ($reports as $g) {
                    $grades_by_level[$g['grade_level']] = $g;
                }

                // Define summary rows
                $rows_meta = [
                    [
                        'section' => 'Enrollment & Promotion',
                        'rows' => [
                            ['label' => 'Enrollment', 'key_m' => 'enrolled_m', 'key_f' => 'enrolled_f'],
                            ['label' => 'Promoted', 'key_m' => 'promoted_m', 'key_f' => 'promoted_f'],
                            ['label' => 'Conditional', 'key_m' => 'conditional_m', 'key_f' => 'conditional_f'],
                            ['label' => 'Retained', 'key_m' => 'retained_m', 'key_f' => 'retained_f'],
                        ]
                    ],
                    [
                        'section' => 'Performance Level',
                        'rows' => [
                            ['label' => 'Did Not Meet Expectations (Below 75)', 'key_m' => 'did_not_meet_m', 'key_f' => 'did_not_meet_f'],
                            ['label' => 'Fairly Satisfactory (75-79)', 'key_m' => 'fairly_sat_m', 'key_f' => 'fairly_sat_f'],
                            ['label' => 'Satisfactory (80-84)', 'key_m' => 'satisfactory_m', 'key_f' => 'satisfactory_f'],
                            ['label' => 'Very Satisfactory (85-89)', 'key_m' => 'very_sat_m', 'key_f' => 'very_sat_f'],
                            ['label' => 'Outstanding (90+)', 'key_m' => 'outstanding_m', 'key_f' => 'outstanding_f'],
                        ]
                    ],
                ];
                ?>
                <div class="sf6-form">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div class="sf6-header" style="text-align: left; margin: 0;">
                            <div class="sf6-title">School Form 6 (SF6) Summary of School Statistics</div>
                            <div class="sf6-subtitle">(This replaces Form 19 - Summary of School Statistics)</div>
                        </div>
                        <input type="text" id="reportSearch" placeholder="🔍 Search summary item..." 
                               style="padding: 10px 15px; width: 300px; border: 1px solid #ddd; border-radius: 20px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    </div>
                        <div class="sf6-school-info">
                            <div><strong>School Name:</strong> Malolos Marine Fishery School & Laboratory</div>
                            <div><strong>School ID:</strong> 300750</div>
                            <div><strong>School Year:</strong> <?= htmlspecialchars($school_year ?: 'All') ?></div>
                            <div><strong>Grade Level:</strong> <?= htmlspecialchars($grade_level ?: 'All') ?></div>
                            <div><strong>Section:</strong> <?= htmlspecialchars($section ?: 'All') ?></div>
                        </div>
                    </div>

                    <div class="table-container">
                        <table id="sf6Table" class="sf6-main-table">
                            <thead>
                                <tr>
                                    <th rowspan="2">SUMMARY</th>
                                    <?php foreach ($grade_levels_list as $gl): ?>
                                        <th colspan="2"><?= htmlspecialchars($gl) ?></th>
                                    <?php endforeach; ?>
                                    <th colspan="2">TOTAL</th>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_levels_list as $gl): ?>
                                        <th>M</th>
                                        <th>F</th>
                                    <?php endforeach; ?>
                                    <th>M</th>
                                    <th>F</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows_meta as $section_group): ?>
                                    <tr class="section-divider">
                                        <td colspan="<?= (count($grade_levels_list) * 2) + 3 ?>">
                                            <?= $section_group['section'] ?>
                                        </td>
                                    </tr>
                                    <?php foreach ($section_group['rows'] as $rm): ?>
                                        <tr>
                                            <td><?= $rm['label'] ?></td>
                                            <?php foreach ($grade_levels_list as $gl):
                                                $g = $grades_by_level[$gl] ?? [];
                                                ?>
                                                <td><?= $g[$rm['key_m']] ?? 0 ?></td>
                                                <td><?= $g[$rm['key_f']] ?? 0 ?></td>
                                            <?php endforeach; ?>
                                            <td><strong><?= $totals[$rm['key_m']] ?></strong></td>
                                            <td><strong><?= $totals[$rm['key_f']] ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="sf6-footer">
                        <div class="signature-block">
                            <p>Prepared by:</p>
                            <div class="signature-line"></div>
                            <p><strong>School Registrar</strong></p>
                            <p>Date: <?= date('M d, Y') ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif ($filters_applied): ?>
                <div class="no-data">
                    <div style="font-size: 48px; margin-bottom: 15px;">📭</div>
                    <h3>No Data Found</h3>
                    <p>No school statistics found for the specified criteria.</p>
                </div>
            <?php else: ?>
                <div class="no-data" style="margin-top: 20px;">
                    <div style="font-size: 48px; margin-bottom: 15px;">📋</div>
                    <h3>Ready to Generate Report</h3>
                    <p>Select School Year, Grade Level, or Section from the filters above and click "Generate" to view the
                        report.<br>
                        <strong>Note:</strong> Promotion and performance data is pulled from SF5 reports. Make sure SF5 data
                        is imported first.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf6Table')) {
                initReportSearch('reportSearch', 'sf6Table');
            }
        });
    </script>
</body>

</html>
