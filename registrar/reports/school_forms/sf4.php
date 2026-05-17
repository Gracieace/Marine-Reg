<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php'; // Ensure url_for is available
require_once __DIR__ . '/../../../includes/report_export_helper.php';

$pdo = db_connect();
// Ensure tables exist
initialize_schema($pdo);

// Handle report generation
$action = $_POST['action'] ?? '';
$export_format = $_GET['export'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';
$month = $_GET['month'] ?? date('F');

// Handle Import
if ($action === 'import' && isset($_FILES['import_file'])) {
    $result = handleImport($pdo, $_FILES['import_file'], $school_year, $month);
    $import_message = $result['message'];
    $import_success = $result['success'];
}

$reports = [];
$filters_applied = isset($_GET['filter']) || !empty($export_format) || isset($import_success);

// Generate/Load SF4 report
if ($filters_applied || isset($import_success)) {
    try {
        $reports = generateSF4($pdo, $grade_level, $section, $school_year, $month);
    } catch (Exception $e) {
        $error_message = "Error generating report: " . $e->getMessage();
    }
}

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format, 'sf4', $school_year, $month);
    exit;
}

function generateSF4($pdo, $grade_level_filter, $section_filter, $school_year = '', $month = '')
{
    // 1. Determine Month Range for "Current" calculations
    $months_order = ['June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March', 'April', 'May'];
    $m_index = array_search($month, $months_order);
    
    $sy_parts = explode('-', $school_year);
    $start_year = (int)$sy_parts[0];
    $end_year = (isset($sy_parts[1]) ? (int)$sy_parts[1] : $start_year + 1);
    
    $month_num = ($m_index !== false) ? (($m_index + 6) % 12 ?: 12) : date('n');
    $year = ($month_num >= 6) ? $start_year : $end_year;
    
    $start_date = "$year-" . str_pad($month_num, 2, '0', STR_PAD_LEFT) . "-01";
    $end_date = date("Y-m-t", strtotime($start_date));

    // Try to load existing report
    $stmt_check = $pdo->prepare("SELECT id FROM sf4_reports WHERE school_year = ? AND report_month = ?");
    $stmt_check->execute([$school_year, $month]);
    $report_id = $stmt_check->fetchColumn();

    if ($report_id) {
        $sql = "SELECT * FROM sf4_rows WHERE sf4_report_id = ?";
        $params = [$report_id];
        if ($grade_level_filter) { $sql .= " AND grade_level = ?"; $params[] = $grade_level_filter; }
        if ($section_filter) { $sql .= " AND section = ?"; $params[] = $section_filter; }
        $sql .= " ORDER BY grade_level, section";
        $stmt_rows = $pdo->prepare($sql);
        $stmt_rows->execute($params);
        $rows = $stmt_rows->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($rows as $r) {
            $r['reg_t'] = $r['reg_m'] + $r['reg_f'];
            $r['avg_t'] = $r['avg_m'] + $r['avg_f'];
            $r['perc_t'] = ($r['reg_t'] > 0) ? round(($r['avg_t'] / $r['reg_t']) * 100, 2) : 0;
            $r['tin_cum_m'] = $r['tin_prev_m'] + $r['tin_curr_m'];
            $r['tin_cum_f'] = $r['tin_prev_f'] + $r['tin_curr_f'];
            $r['tin_cum_t'] = $r['tin_cum_m'] + $r['tin_cum_f'];
            $r['tout_cum_m'] = $r['tout_prev_m'] + $r['tout_curr_m'];
            $r['tout_cum_f'] = $r['tout_prev_f'] + $r['tout_curr_f'];
            $r['tout_cum_t'] = $r['tout_cum_m'] + $r['tout_cum_f'];
            $r['nlpa_cum_m'] = $r['nlpa_prev_m'] + $r['nlpa_curr_m'];
            $r['nlpa_cum_f'] = $r['nlpa_prev_f'] + $r['nlpa_curr_f'];
            $r['nlpa_cum_t'] = $r['nlpa_cum_m'] + $r['nlpa_cum_f'];
            $r['mort_cum_m'] = $r['mort_prev_m'] + $r['mort_curr_m'];
            $r['mort_cum_f'] = $r['mort_prev_f'] + $r['mort_curr_f'];
            $r['mort_cum_t'] = $r['mort_cum_m'] + $r['mort_cum_f'];
            $data[] = $r;
        }
        return $data;
    }

    // GENERATE NEW DATA
    $sql_sections = "SELECT DISTINCT grade_level, section FROM enrollments WHERE school_year = ? ORDER BY grade_level, section";
    $stmt = $pdo->prepare($sql_sections);
    $stmt->execute([$school_year]);
    $sections = $stmt->fetchAll();

    $pdo->prepare("INSERT INTO sf4_reports (school_year, report_month) VALUES (?, ?)")->execute([$school_year, $month]);
    $new_report_id = $pdo->lastInsertId();

    $stmt_insert = $pdo->prepare("INSERT INTO sf4_rows (
        sf4_report_id, grade_level, section, adviser,
        reg_m, reg_f, avg_m, avg_f, perc_m, perc_f,
        tin_prev_m, tin_prev_f, tin_curr_m, tin_curr_f,
        tout_prev_m, tout_prev_f, tout_curr_m, tout_curr_f,
        nlpa_prev_m, nlpa_prev_f, nlpa_curr_m, nlpa_curr_f,
        mort_prev_m, mort_prev_f, mort_curr_m, mort_curr_f
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    // Fetch Previous Month's Report for Cumulative base
    $prev_month = ($m_index > 0) ? $months_order[$m_index - 1] : null;
    $prev_report_id = null;
    if ($prev_month) {
        $stmt_prev = $pdo->prepare("SELECT id FROM sf4_reports WHERE school_year = ? AND report_month = ?");
        $stmt_prev->execute([$school_year, $prev_month]);
        $prev_report_id = $stmt_prev->fetchColumn();
    }

    $report_data = [];
    foreach ($sections as $sec) {
        $gl = $sec['grade_level'];
        $sect = $sec['section'];

        // Registered count (Beginning of Month)
        // Students enrolled before start_date who were still active or left during/after this month
        $stmt_reg = $pdo->prepare("SELECT COUNT(CASE WHEN r.sex='M' THEN 1 END) as m, COUNT(CASE WHEN r.sex='F' THEN 1 END) as f 
            FROM enrollments e LEFT JOIN registrations r ON e.student_id = r.lrn
            WHERE e.grade_level=? AND e.section=? AND e.school_year=?
            AND (e.enrolled_at < ?)
            AND (e.status = 'Enrolled' OR e.status_date >= ? OR e.status_date IS NULL)");
        $stmt_reg->execute([$gl, $sect, $school_year, $start_date, $start_date]);
        $reg = $stmt_reg->fetch();

        // Attendance (simplified daily average from SF2)
        $stmt_sf2 = $pdo->prepare("SELECT id FROM sf2_reports WHERE grade_level=? AND section=? AND school_year=? AND report_month=?");
        $stmt_sf2->execute([$gl, $sect, $school_year, $month]);
        $sf2_id = $stmt_sf2->fetchColumn();
        $avg_m = 0; $avg_f = 0; $pm = 0; $pf = 0;
        if ($sf2_id) {
            $num_days = $pdo->query("SELECT COUNT(DISTINCT attendance_date) FROM sf2_daily_attendance WHERE sf2_report_id=$sf2_id")->fetchColumn() ?: 1;
            $stmt_pres = $pdo->prepare("SELECT sex, COUNT(*) as cnt FROM sf2_daily_attendance WHERE sf2_report_id=? AND attendance_status='present' GROUP BY sex");
            $stmt_pres->execute([$sf2_id]);
            $presents = $stmt_pres->fetchAll(PDO::FETCH_KEY_PAIR);
            $avg_m = round(($presents['M'] ?? 0) / $num_days, 2);
            $avg_f = round(($presents['F'] ?? 0) / $num_days, 2);
            if ($reg['m'] > 0) $pm = round(($avg_m / $reg['m']) * 100, 2);
            if ($reg['f'] > 0) $pf = round(($avg_f / $reg['f']) * 100, 2);
        }

        // Current Month Movements
        $stmt_mov = $pdo->prepare("SELECT 
            COUNT(CASE WHEN r.sex='M' AND e.status='Transferred In' AND e.status_date BETWEEN ? AND ? THEN 1 END) as tin_m,
            COUNT(CASE WHEN r.sex='F' AND e.status='Transferred In' AND e.status_date BETWEEN ? AND ? THEN 1 END) as tin_f,
            COUNT(CASE WHEN r.sex='M' AND e.status='Transferred Out' AND e.status_date BETWEEN ? AND ? THEN 1 END) as tout_m,
            COUNT(CASE WHEN r.sex='F' AND e.status='Transferred Out' AND e.status_date BETWEEN ? AND ? THEN 1 END) as tout_f,
            COUNT(CASE WHEN r.sex='M' AND e.status='Dropped' AND e.status_date BETWEEN ? AND ? THEN 1 END) as nlpa_m,
            COUNT(CASE WHEN r.sex='F' AND e.status='Dropped' AND e.status_date BETWEEN ? AND ? THEN 1 END) as nlpa_f,
            COUNT(CASE WHEN r.sex='M' AND e.status='Mortality' AND e.status_date BETWEEN ? AND ? THEN 1 END) as mort_m,
            COUNT(CASE WHEN r.sex='F' AND e.status='Mortality' AND e.status_date BETWEEN ? AND ? THEN 1 END) as mort_f
            FROM enrollments e LEFT JOIN registrations r ON e.student_id = r.lrn
            WHERE e.grade_level=? AND e.section=? AND e.school_year=?");
        $stmt_mov->execute([
            $start_date, $end_date, $start_date, $end_date, 
            $start_date, $end_date, $start_date, $end_date, 
            $start_date, $end_date, $start_date, $end_date,
            $start_date, $end_date, $start_date, $end_date,
            $gl, $sect, $school_year
        ]);
        $curr = $stmt_mov->fetch();

        // Previous Month Cumulative
        $pm_tm = 0; $pm_tf = 0; $pm_om = 0; $pm_of = 0; $pm_nm = 0; $pm_nf = 0; $pm_mm = 0; $pm_mf = 0;
        if ($prev_report_id) {
            $stmt_pm = $pdo->prepare("SELECT 
                (tin_prev_m + tin_curr_m) as tm, (tin_prev_f + tin_curr_f) as tf, 
                (tout_prev_m + tout_curr_m) as om, (tout_prev_f + tout_curr_f) as of, 
                (nlpa_prev_m + nlpa_curr_m) as nm, (nlpa_prev_f + nlpa_curr_f) as nf,
                (mort_prev_m + mort_curr_m) as mm, (mort_prev_f + mort_curr_f) as mf 
                FROM sf4_rows WHERE sf4_report_id=? AND grade_level=? AND section=?");
            $stmt_pm->execute([$prev_report_id, $gl, $sect]);
            $pm_data = $stmt_pm->fetch();
            if ($pm_data) {
                $pm_tm = $pm_data['tm']; $pm_tf = $pm_data['tf']; 
                $pm_om = $pm_data['om']; $pm_of = $pm_data['of']; 
                $pm_nm = $pm_data['nm']; $pm_nf = $pm_data['nf'];
                $pm_mm = $pm_data['mm']; $pm_mf = $pm_data['mf'];
            }
        }

        $stmt_adv = $pdo->prepare("SELECT COALESCE(e.full_name, CONCAT(u.first_name, ' ', u.last_name)) FROM position_assignments pa LEFT JOIN employees e ON pa.employee_id=e.id LEFT JOIN users u ON pa.user_id=u.id WHERE pa.grade_level=? AND pa.section=? AND pa.school_year=? AND pa.position_type='class_adviser' LIMIT 1");
        $stmt_adv->execute([$gl, $sect, $school_year]);
        $adviser = $stmt_adv->fetchColumn() ?: "TBA";

        $stmt_insert->execute([
            $new_report_id, $gl, $sect, $adviser,
            $reg['m'], $reg['f'], $avg_m, $avg_f, $pm, $pf,
            $pm_tm, $pm_tf, $curr['tin_m'], $curr['tin_f'],
            $pm_om, $pm_of, $curr['tout_m'], $curr['tout_f'],
            $pm_nm, $pm_nf, $curr['nlpa_m'], $curr['nlpa_f'],
            $pm_mm, $pm_mf, $curr['mort_m'], $curr['mort_f']
        ]);

        if ((!$grade_level_filter || $grade_level_filter == $gl) && (!$section_filter || $section_filter == $sect)) {
            $report_data[] = [
                'grade_level' => $gl, 'section' => $sect, 'adviser' => $adviser,
                'reg_m' => $reg['m'], 'reg_f' => $reg['f'], 'reg_t' => $reg['m']+$reg['f'],
                'avg_m' => $avg_m, 'avg_f' => $avg_f, 'avg_t' => $avg_m+$avg_f,
                'perc_m' => $pm, 'perc_f' => $pf, 'perc_t' => (($reg['m']+$reg['f']) > 0) ? round((($avg_m+$avg_f)/($reg['m']+$reg['f']))*100, 2) : 0,
                'tin_prev_m' => $pm_tm, 'tin_prev_f' => $pm_tf, 'tin_curr_m' => $curr['tin_m'], 'tin_curr_f' => $curr['tin_f'], 'tin_cum_m' => $pm_tm+$curr['tin_m'], 'tin_cum_f' => $pm_tf+$curr['tin_f'],
                'tout_prev_m' => $pm_om, 'tout_prev_f' => $pm_of, 'tout_curr_m' => $curr['tout_m'], 'tout_curr_f' => $curr['tout_f'], 'tout_cum_m' => $pm_om+$curr['tout_m'], 'tout_cum_f' => $pm_of+$curr['tout_f'],
                'nlpa_prev_m' => $pm_nm, 'nlpa_prev_f' => $pm_nf, 'nlpa_curr_m' => $curr['nlpa_m'], 'nlpa_curr_f' => $curr['nlpa_f'], 'nlpa_cum_m' => $pm_nm+$curr['nlpa_m'], 'nlpa_cum_f' => $pm_nf+$curr['nlpa_f'],
                'mort_prev_m' => $pm_mm, 'mort_prev_f' => $pm_mf, 'mort_curr_m' => $curr['mort_m'], 'mort_curr_f' => $curr['mort_f'], 'mort_cum_m' => $pm_mm+$curr['mort_m'], 'mort_cum_f' => $pm_mf+$curr['mort_f']
            ];
        }
    }
    return $report_data;
}

function handleImport($pdo, $file, $school_year, $month)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error.'];
    }

    $handle = fopen($file['tmp_name'], "r");
    if ($handle === FALSE) {
        return ['success' => false, 'message' => 'Cannot open file.'];
    }

    // Skip header row
    fgetcsv($handle);

    // Clear existing data for this month
    $pdo->beginTransaction();
    try {
        $stmt_del = $pdo->prepare("DELETE FROM sf4_reports WHERE school_year = ? AND report_month = ?");
        $stmt_del->execute([$school_year, $month]);

        $pdo->prepare("INSERT INTO sf4_reports (school_year, report_month) VALUES (?, ?)")
            ->execute([$school_year, $month]);
        $report_id = $pdo->lastInsertId();

        $stmt_ins = $pdo->prepare("INSERT INTO sf4_rows (
            sf4_report_id, grade_level, section, 
            reg_m, reg_f,
            avg_m, avg_f, perc_m, perc_f,
            nlpa_curr_m, nlpa_curr_f,
            tout_curr_m, tout_curr_f,
            tin_curr_m, tin_curr_f
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Mapping Logic (adjust indices based on CSV format)
            // Expecting: Grade, Section, Reg M, Reg F, Avg M, Avg F, % M, % F, NLPA M, NLPA F, ...
            $grade = $data[0] ?? '';
            $sect = $data[1] ?? '';
            $reg_m = $data[2] ?? 0;
            $reg_f = $data[3] ?? 0;
            $avg_m = $data[5] ?? 0;
            $avg_f = $data[6] ?? 0;
            $perc_m = rtrim($data[8] ?? 0, '%');
            $perc_f = rtrim($data[9] ?? 0, '%');

            // Simple import of basic stats for now
            $stmt_ins->execute([
                $report_id,
                $grade,
                $sect,
                $reg_m,
                $reg_f,
                $avg_m,
                $avg_f,
                $perc_m,
                $perc_f,
                0,
                0,
                0,
                0,
                0,
                0
            ]);
        }

        $pdo->commit();
        fclose($handle);
        return ['success' => true, 'message' => 'Import successful for ' . $month];
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        return ['success' => false, 'message' => 'Import failed: ' . $e->getMessage()];
    }
}

function handleExport($data, $format, $report_type, $school_year, $month)
{
    if ($format === 'pdf') {
        require_once __DIR__ . '/../../../vendor/autoload.php';
        $html = buildSf4ExportHtml($data, $school_year, $month);

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->setPaper('legal', 'landscape');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="sf4_' . $month . '_' . $school_year . '.pdf"');
        echo $dompdf->output();
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sf4_report_' . $month . '_' . $school_year . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Grade', 'Section', 'Registered M', 'Registered F', 'Registered T', 'Avg M', 'Avg F', 'Avg T', '% M', '% F', '% T']);
        foreach ($data as $row) {
            fputcsv($out, [
                $row['grade_level'],
                $row['section'],
                $row['reg_m'],
                $row['reg_f'],
                $row['reg_t'],
                $row['avg_m'],
                $row['avg_f'],
                $row['avg_t'],
                $row['perc_m'] . '%',
                $row['perc_f'] . '%',
                $row['perc_t'] . '%'
            ]);
        }
        fclose($out);
    } elseif ($format === 'xlsx') {
        require_once __DIR__ . '/../../../vendor/autoload.php';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SF4');

        $sheet->setCellValue('A1', 'School Form 4 (SF4) Monthly Learner\'s Movement and Attendance');
        $sheet->setCellValue('A2', 'School Year: ' . $school_year . ' | Month: ' . $month);
        $sheet->mergeCells('A1:AG1');
        $sheet->mergeCells('A2:AG2');

        $headers = [
            'Grade', 'Section', 'Adviser',
            'Registered M', 'Registered F', 'Registered T',
            'Avg Attendance M', 'Avg Attendance F', 'Avg Attendance T',
            '% Attendance M', '% Attendance F', '% Attendance T',
            'TIN Prev M', 'TIN Prev F', 'TIN Prev T', 'TIN Curr M', 'TIN Curr F', 'TIN Curr T', 'TIN Cum M', 'TIN Cum F', 'TIN Cum T',
            'TOUT Prev M', 'TOUT Prev F', 'TOUT Prev T', 'TOUT Curr M', 'TOUT Curr F', 'TOUT Curr T', 'TOUT Cum M', 'TOUT Cum F', 'TOUT Cum T',
            'NLPA Prev M', 'NLPA Prev F', 'NLPA Prev T', 'NLPA Curr M', 'NLPA Curr F', 'NLPA Curr T', 'NLPA Cum M', 'NLPA Cum F', 'NLPA Cum T',
            'Mort Prev M', 'Mort Prev F', 'Mort Prev T', 'Mort Curr M', 'Mort Curr F', 'Mort Curr T', 'Mort Cum M', 'Mort Cum F', 'Mort Cum T',
        ];

        $sheet->fromArray($headers, null, 'A4');

        $rowNum = 5;
        foreach ($data as $r) {
            $sheet->fromArray([
                $r['grade_level'],
                $r['section'],
                $r['adviser'],
                $r['reg_m'],
                $r['reg_f'],
                $r['reg_t'],
                $r['avg_m'],
                $r['avg_f'],
                $r['avg_t'],
                $r['perc_m'],
                $r['perc_f'],
                $r['perc_t'],
                $r['tin_prev_m'],
                $r['tin_prev_f'],
                ($r['tin_prev_m'] + $r['tin_prev_f']),
                $r['tin_curr_m'],
                $r['tin_curr_f'],
                ($r['tin_curr_m'] + $r['tin_curr_f']),
                $r['tin_cum_m'],
                $r['tin_cum_f'],
                $r['tin_cum_t'],
                $r['tout_prev_m'],
                $r['tout_prev_f'],
                ($r['tout_prev_m'] + $r['tout_prev_f']),
                $r['tout_curr_m'],
                $r['tout_curr_f'],
                ($r['tout_curr_m'] + $r['tout_curr_f']),
                $r['tout_cum_m'],
                $r['tout_cum_f'],
                $r['tout_cum_t'],
                $r['nlpa_prev_m'],
                $r['nlpa_prev_f'],
                ($r['nlpa_prev_m'] + $r['nlpa_prev_f']),
                $r['nlpa_curr_m'],
                $r['nlpa_curr_f'],
                ($r['nlpa_curr_m'] + $r['nlpa_curr_f']),
                $r['nlpa_cum_m'],
                $r['nlpa_cum_f'],
                $r['nlpa_cum_t'],
                $r['mort_prev_m'],
                $r['mort_prev_f'],
                ($r['mort_prev_m'] + $r['mort_prev_f']),
                $r['mort_curr_m'],
                $r['mort_curr_f'],
                ($r['mort_curr_m'] + $r['mort_curr_f']),
                $r['mort_cum_m'],
                $r['mort_cum_f'],
                $r['mort_cum_t'],
            ], null, 'A' . $rowNum);
            $rowNum++;
        }

        $sheet->getStyle('A4:AG4')->getFont()->setBold(true);
        $sheet->freezePane('A5');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="sf4_' . $month . '_' . $school_year . '.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
    }
}

function buildSf4ExportHtml($data, $school_year, $month): string
{
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>SF4 Export</title>
        <style>
            @page { size: legal landscape; margin: 10mm; }
            body { font-family: Arial, Helvetica, sans-serif; font-size: 8px; padding: 0; margin: 0; }
            .container { padding: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
            th, td { border: 1px solid #000; padding: 2px; text-align: center; overflow: hidden; }
            th { background: #f1f5f9; font-weight: bold; }
            .header { text-align: center; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div style="font-size:12px; font-weight:bold;">School Form 4 (SF4) Monthly Learner's Movement and Attendance</div>
                <div style="margin-top:3px; font-size:9px;">School Year: <?= htmlspecialchars($school_year) ?> | Month: <?= htmlspecialchars($month) ?></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th rowspan="3" style="width:100px;">Grade & Section</th>
                        <th rowspan="3" style="width:120px;">Name of Adviser</th>
                        <th colspan="3">Registered Learners</th>
                        <th colspan="3">Attendance</th>
                        <th colspan="9">Transferred IN</th>
                        <th colspan="9">Transferred OUT</th>
                        <th colspan="9">Dropped (NLPA)</th>
                        <th colspan="9">Mortality</th>
                    </tr>
                    <tr>
                        <th rowspan="2">M</th><th rowspan="2">F</th><th rowspan="2">T</th>
                        <th rowspan="2">Avg</th><th rowspan="2">%</th><th rowspan="2">T</th>
                        <th colspan="3">Prev</th><th colspan="3">Curr</th><th colspan="3">Cum</th>
                        <th colspan="3">Prev</th><th colspan="3">Curr</th><th colspan="3">Cum</th>
                        <th colspan="3">Prev</th><th colspan="3">Curr</th><th colspan="3">Cum</th>
                        <th colspan="3">Prev</th><th colspan="3">Curr</th><th colspan="3">Cum</th>
                    </tr>
                    <tr>
                        <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                        <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                        <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                        <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $r): ?>
                        <tr>
                            <td style="text-align:left;"><?= htmlspecialchars($r['grade_level'] . ' - ' . $r['section']) ?></td>
                            <td style="text-align:left;"><?= htmlspecialchars($r['adviser']) ?></td>
                            <td><?= (int) $r['reg_m'] ?></td><td><?= (int) $r['reg_f'] ?></td><td><?= (int) $r['reg_t'] ?></td>
                            <td><?= round((float) $r['avg_t'], 1) ?></td><td><?= round((float) $r['perc_t'], 1) ?></td><td><?= (int) $r['reg_t'] ?></td>

                            <td><?= (int) $r['tin_prev_m'] ?></td><td><?= (int) $r['tin_prev_f'] ?></td><td><?= (int) ($r['tin_prev_m'] + $r['tin_prev_f']) ?></td>
                            <td><?= (int) $r['tin_curr_m'] ?></td><td><?= (int) $r['tin_curr_f'] ?></td><td><?= (int) ($r['tin_curr_m'] + $r['tin_curr_f']) ?></td>
                            <td><?= (int) $r['tin_cum_m'] ?></td><td><?= (int) $r['tin_cum_f'] ?></td><td><?= (int) $r['tin_cum_t'] ?></td>

                            <td><?= (int) $r['tout_prev_m'] ?></td><td><?= (int) $r['tout_prev_f'] ?></td><td><?= (int) ($r['tout_prev_m'] + $r['tout_prev_f']) ?></td>
                            <td><?= (int) $r['tout_curr_m'] ?></td><td><?= (int) $r['tout_curr_f'] ?></td><td><?= (int) ($r['tout_curr_m'] + $r['tout_curr_f']) ?></td>
                            <td><?= (int) $r['tout_cum_m'] ?></td><td><?= (int) $r['tout_cum_f'] ?></td><td><?= (int) $r['tout_cum_t'] ?></td>

                            <td><?= (int) $r['nlpa_prev_m'] ?></td><td><?= (int) $r['nlpa_prev_f'] ?></td><td><?= (int) ($r['nlpa_prev_m'] + $r['nlpa_prev_f']) ?></td>
                            <td><?= (int) $r['nlpa_curr_m'] ?></td><td><?= (int) $r['nlpa_curr_f'] ?></td><td><?= (int) ($r['nlpa_curr_m'] + $r['nlpa_curr_f']) ?></td>
                            <td><?= (int) $r['nlpa_cum_m'] ?></td><td><?= (int) $r['nlpa_cum_f'] ?></td><td><?= (int) $r['nlpa_cum_t'] ?></td>

                            <td><?= (int) $r['mort_prev_m'] ?></td><td><?= (int) $r['mort_prev_f'] ?></td><td><?= (int) ($r['mort_prev_m'] + $r['mort_prev_f']) ?></td>
                            <td><?= (int) $r['mort_curr_m'] ?></td><td><?= (int) $r['mort_curr_f'] ?></td><td><?= (int) ($r['mort_curr_m'] + $r['mort_curr_f']) ?></td>
                            <td><?= (int) $r['mort_cum_m'] ?></td><td><?= (int) $r['mort_cum_f'] ?></td><td><?= (int) $r['mort_cum_t'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
    return (string) ob_get_clean();
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF4 - Monthly Learner's Movement and Attendance</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #111; }
        .page { background: #fff; margin: 0 auto; padding: 16px; }
        .no-print { display: block; }

        .top-actions {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between;
            padding: 12px 16px; border-bottom: 1px solid #e5e7eb; background: #f8fafc;
        }
        .top-actions .left, .top-actions .right { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .btn {
            appearance: none; border: 1px solid #cbd5e1; background: #fff; color: #0f172a;
            padding: 8px 12px; border-radius: 6px; cursor: pointer; text-decoration: none;
            font-size: 13px; line-height: 1;
        }
        .btn-primary { background: #0b5ed7; border-color: #0b5ed7; color: #fff; }
        .btn-success { background: #198754; border-color: #198754; color: #fff; }
        .btn-muted { background: #64748b; border-color: #64748b; color: #fff; }

        .filters {
            display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
            margin: 12px 16px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff;
        }
        .filters label { display: block; font-size: 11px; font-weight: bold; letter-spacing: .02em; color: #334155; margin-bottom: 6px; text-transform: uppercase; }
        .filters select { min-width: 160px; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; }

        .sf-header { text-align: center; margin: 12px 0 8px 0; }
        .sf-header .rp { font-size: 11px; margin: 0; }
        .sf-header .title { font-size: 14px; font-weight: bold; margin: 2px 0 0 0; }
        .sf-header .sub { font-size: 11px; margin: 2px 0 0 0; }

        .meta-grid {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            gap: 6px 10px; font-size: 11px; margin-top: 10px; border: 1px solid #111; padding: 8px;
        }
        .meta-item { display: flex; gap: 6px; }
        .meta-item .label { white-space: nowrap; font-weight: bold; }
        .meta-item .value { flex: 1; border-bottom: 1px solid #111; min-height: 14px; }

        .status-message { margin: 12px 16px; padding: 10px 12px; border-radius: 8px; border: 1px solid #e5e7eb; font-size: 13px; }

        .table-wrap { margin-top: 10px; overflow-x: auto; }
        table.sf4 { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
        table.sf4 th, table.sf4 td { border: 1px solid #111; padding: 3px 2px; text-align: center; vertical-align: middle; }
        table.sf4 th { font-weight: bold; }
        .t-left { text-align: left !important; }

        .signatures { margin-top: 16px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; font-size: 11px; }
        .sig .label { margin-bottom: 26px; }
        .sig .line { border-bottom: 1px solid #111; height: 16px; }
        .sig .role { margin-top: 4px; font-weight: bold; text-transform: uppercase; font-size: 10px; }

        /* Modal (kept) */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
            overflow: auto; background-color: rgba(0, 0, 0, 0.5); backdrop-filter: blur(2px);
        }
        .modal-content {
            background-color: #fefefe; margin: 10% auto; padding: 25px; border: none; width: 450px;
            border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        .close { color: #aaa; float: right; font-size: 24px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #333; }

        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .page { padding: 0; }
            @page { size: legal landscape; margin: 10mm; }
        }
    </style>
    <script>
        function openImportModal() { document.getElementById('importModal').style.display = 'block'; }
        function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }
    </script>
</head>
<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../registrar_side_panel.php'; ?>

    <div class="main-content">
        <div class="page">
            <div class="top-actions no-print">
                <div class="left">
                    <a href="sf1.php" class="btn">SF1</a>
                    <a href="sf2.php" class="btn">SF2</a>
                    <a href="sf3.php" class="btn">SF3</a>
                    <a href="sf4.php" class="btn btn-muted">SF4</a>
                    <a href="sf5.php" class="btn">SF5</a>
                    <a href="sf6.php" class="btn">SF6</a>
                </div>
                <div class="right">
                    <button type="button" class="btn" onclick="window.print()">Print</button>
                    <button type="button" class="btn" onclick="openImportModal()">Import</button>
                    <a class="btn btn-primary" href="?export=pdf&school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($month) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">Export PDF</a>
                    <a class="btn btn-success" href="?export=csv&school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($month) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">Export CSV</a>
                    <a class="btn btn-success" href="?export=xlsx&school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($month) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">Export Excel (XLSX)</a>
                </div>
            </div>

            <form method="GET" action="sf4.php" class="filters no-print">
                <input type="hidden" name="filter" value="1">
                <div>
                    <label>School Year</label>
                    <select name="school_year">
                        <option value="">All Years</option>
                        <?php
                        $sys = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                        if (empty($school_year) && count($sys) > 0) {
                            $school_year = $sys[0];
                        }
                        foreach ($sys as $sy) {
                            echo "<option value='$sy' " . ($school_year == $sy ? 'selected' : '') . ">$sy</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Month</label>
                    <select name="month">
                        <?php
                        $months = ['June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March', 'April', 'May'];
                        foreach ($months as $m) {
                            echo "<option value='$m' " . ($month == $m ? 'selected' : '') . ">$m</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Grade Level</label>
                    <select name="grade_level">
                        <option value="">All Grades</option>
                        <?php
                        $gls = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($gls as $gl) {
                            echo "<option value='$gl' " . ($grade_level == $gl ? 'selected' : '') . ">$gl</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Section</label>
                    <select name="section">
                        <option value="">All Sections</option>
                        <?php
                        $secs = $pdo->query("SELECT DISTINCT section FROM enrollments ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($secs as $sec) {
                            echo "<option value='$sec' " . ($section == $sec ? 'selected' : '') . ">$sec</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Generate</button>
                    <a href="sf4.php" class="btn">Reset</a>
                </div>
            </form>

            <?php if (isset($import_message)): ?>
                <div class="status-message" style="background: <?= $import_success ? '#ecfdf5' : '#fef2f2' ?>; border-color: <?= $import_success ? '#86efac' : '#fecaca' ?>;">
                    <?= htmlspecialchars($import_message) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="status-message" style="background:#fef2f2; border-color:#fecaca;">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="sf-header" style="text-align: left; margin: 0;">
                    <p class="rp">Republic of the Philippines</p>
                    <p class="rp">Department of Education</p>
                    <p class="title">SCHOOL FORM 4 (SF4) - MONTHLY LEARNER'S MOVEMENT AND ATTENDANCE</p>
                    <p class="sub">DepEd standard layout template (legal size, landscape)</p>
                </div>
                <input type="text" id="reportSearch" placeholder="🔍 Search section or adviser..." 
                       style="padding: 10px 15px; width: 300px; border: 1px solid #ddd; border-radius: 20px; outline: none; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            </div>

            <?php
            // Template placeholders (replace with your own school profile table if available)
            $meta = [
                'Region' => '________________',
                'Division' => '________________',
                'District' => '________________',
                'School ID' => '________________',
                'School Name' => '__________________________________________________',
                'School Year' => $school_year ?: '________________',
                'Month' => $month ?: '________________',
            ];
            ?>

            <div class="meta-grid">
                <div class="meta-item"><div class="label">Region:</div><div class="value"><?= htmlspecialchars($meta['Region']) ?></div></div>
                <div class="meta-item"><div class="label">Division:</div><div class="value"><?= htmlspecialchars($meta['Division']) ?></div></div>
                <div class="meta-item"><div class="label">District:</div><div class="value"><?= htmlspecialchars($meta['District']) ?></div></div>
                <div class="meta-item"><div class="label">School ID:</div><div class="value"><?= htmlspecialchars($meta['School ID']) ?></div></div>
                <div class="meta-item" style="grid-column: span 2;"><div class="label">School Name:</div><div class="value"><?= htmlspecialchars($meta['School Name']) ?></div></div>
                <div class="meta-item"><div class="label">School Year:</div><div class="value"><?= htmlspecialchars($meta['School Year']) ?></div></div>
                <div class="meta-item"><div class="label">Month:</div><div class="value"><?= htmlspecialchars($meta['Month']) ?></div></div>
                <div class="meta-item"><div class="label">Grade Level:</div><div class="value"><?= htmlspecialchars($grade_level ?: 'All') ?></div></div>
                <div class="meta-item"><div class="label">Section:</div><div class="value"><?= htmlspecialchars($section ?: 'All') ?></div></div>
            </div>

            <?php if (!empty($reports)): ?>
                <div class="table-wrap">
                    <table class="sf4" id="sf4Table">
                        <thead>
                            <tr>
                                <th rowspan="3" style="width:70px;">GRADE LEVEL</th>
                                <th rowspan="3" style="width:90px;">SECTION</th>
                                <th rowspan="3" style="width:160px;">NAME OF ADVISER</th>
                                <th colspan="3">REGISTERED LEARNERS<br>(As of End of the Month)</th>
                                <th colspan="6">ATTENDANCE</th>
                                <th colspan="9">TRANSFERRED IN</th>
                                <th colspan="9">TRANSFERRED OUT</th>
                                <th colspan="9">DROPPED (NLPA)</th>
                                <th colspan="9">MORTALITY</th>
                            </tr>
                            <tr>
                                <th rowspan="2">M</th><th rowspan="2">F</th><th rowspan="2">T</th>
                                <th colspan="3">Daily Average</th>
                                <th colspan="3">Percentage for the Month</th>
                                <th colspan="3">(A) Prev</th><th colspan="3">(B) This Month</th><th colspan="3">(A+B) Cum</th>
                                <th colspan="3">(A) Prev</th><th colspan="3">(B) This Month</th><th colspan="3">(A+B) Cum</th>
                                <th colspan="3">(A) Prev</th><th colspan="3">(B) This Month</th><th colspan="3">(A+B) Cum</th>
                                <th colspan="3">(A) Prev</th><th colspan="3">(B) This Month</th><th colspan="3">(A+B) Cum</th>
                            </tr>
                            <tr>
                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>

                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>

                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>

                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>

                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['grade_level']) ?></td>
                                    <td><?= htmlspecialchars($row['section']) ?></td>
                                    <td class="t-left"><?= htmlspecialchars($row['adviser'] ?? 'TBA') ?></td>

                                    <td><?= (int)$row['reg_m'] ?></td>
                                    <td><?= (int)$row['reg_f'] ?></td>
                                    <td><?= (int)$row['reg_t'] ?></td>

                                    <td><?= htmlspecialchars($row['avg_m']) ?></td>
                                    <td><?= htmlspecialchars($row['avg_f']) ?></td>
                                    <td><?= htmlspecialchars($row['avg_t']) ?></td>
                                    <td><?= htmlspecialchars($row['perc_m']) ?></td>
                                    <td><?= htmlspecialchars($row['perc_f']) ?></td>
                                    <td><?= htmlspecialchars($row['perc_t']) ?></td>

                                    <td><?= (int)$row['tin_prev_m'] ?></td>
                                    <td><?= (int)$row['tin_prev_f'] ?></td>
                                    <td><?= (int)($row['tin_prev_m'] + $row['tin_prev_f']) ?></td>
                                    <td><?= (int)$row['tin_curr_m'] ?></td>
                                    <td><?= (int)$row['tin_curr_f'] ?></td>
                                    <td><?= (int)($row['tin_curr_m'] + $row['tin_curr_f']) ?></td>
                                    <td><?= (int)$row['tin_cum_m'] ?></td>
                                    <td><?= (int)$row['tin_cum_f'] ?></td>
                                    <td><?= (int)$row['tin_cum_t'] ?></td>

                                    <td><?= (int)$row['tout_prev_m'] ?></td>
                                    <td><?= (int)$row['tout_prev_f'] ?></td>
                                    <td><?= (int)($row['tout_prev_m'] + $row['tout_prev_f']) ?></td>
                                    <td><?= (int)$row['tout_curr_m'] ?></td>
                                    <td><?= (int)$row['tout_curr_f'] ?></td>
                                    <td><?= (int)($row['tout_curr_m'] + $row['tout_curr_f']) ?></td>
                                    <td><?= (int)$row['tout_cum_m'] ?></td>
                                    <td><?= (int)$row['tout_cum_f'] ?></td>
                                    <td><?= (int)$row['tout_cum_t'] ?></td>

                                    <td><?= (int)$row['nlpa_prev_m'] ?></td>
                                    <td><?= (int)$row['nlpa_prev_f'] ?></td>
                                    <td><?= (int)($row['nlpa_prev_m'] + $row['nlpa_prev_f']) ?></td>
                                    <td><?= (int)$row['nlpa_curr_m'] ?></td>
                                    <td><?= (int)$row['nlpa_curr_f'] ?></td>
                                    <td><?= (int)($row['nlpa_curr_m'] + $row['nlpa_curr_f']) ?></td>
                                    <td><?= (int)$row['nlpa_cum_m'] ?></td>
                                    <td><?= (int)$row['nlpa_cum_f'] ?></td>
                                    <td><?= (int)$row['nlpa_cum_t'] ?></td>

                                    <td><?= (int)$row['mort_prev_m'] ?></td>
                                    <td><?= (int)$row['mort_prev_f'] ?></td>
                                    <td><?= (int)($row['mort_prev_m'] + $row['mort_prev_f']) ?></td>
                                    <td><?= (int)$row['mort_curr_m'] ?></td>
                                    <td><?= (int)$row['mort_curr_f'] ?></td>
                                    <td><?= (int)($row['mort_curr_m'] + $row['mort_curr_f']) ?></td>
                                    <td><?= (int)$row['mort_cum_m'] ?></td>
                                    <td><?= (int)$row['mort_cum_f'] ?></td>
                                    <td><?= (int)$row['mort_cum_t'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="signatures">
                    <div class="sig">
                        <div class="label">Prepared by:</div>
                        <div class="line"></div>
                        <div class="role">School Registrar / Class Adviser</div>
                    </div>
                    <div class="sig">
                        <div class="label">Certified Correct:</div>
                        <div class="line"></div>
                        <div class="role">School Head / Principal</div>
                    </div>
                    <div class="sig">
                        <div class="label">Noted by:</div>
                        <div class="line"></div>
                        <div class="role">Division / District Representative</div>
                    </div>
                </div>
            <?php elseif ($filters_applied): ?>
                <div class="status-message" style="background:#fff;">
                    No records found for the selected criteria.
                </div>
            <?php else: ?>
                <div class="status-message" style="background:#fff;">
                    Select filters above and click Generate.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeImportModal()">&times;</span>
            <h2>Import SF4 Data</h2>
            <p>Upload a CSV file to update/overwrite the report for <strong><?= htmlspecialchars($month) ?> <?= htmlspecialchars($school_year) ?></strong>.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
                <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">

                <input type="file" name="import_file" accept=".csv" required style="margin-bottom:15px; width:100%;">
                <button type="submit" class="btn btn-primary" style="width:100%;">Upload & Import</button>
            </form>
            <div style="margin-top:10px; font-size:12px; color:gray;">
                Format: Grade, Section, Reg M, Reg F, Total. Matches export format.
            </div>
        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('reportSearch') && document.getElementById('sf4Table')) {
                initReportSearch('reportSearch', 'sf4Table');
            }
        });
    </script>
</body>
</html>
