<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_logo = trim(get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png'));
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');
$display_logo = (strpos($school_logo, 'http') === 0) ? $school_logo : url_for('/' . ltrim($school_logo, '/'));

$role = $_SESSION['user']['role'];

$grade = trim($_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');
$sy = trim($_GET['sy'] ?? '');

// Bidirectional normalization for robust matching
$grade_clean = trim(str_ireplace('Grade', '', $grade));
$grade_with_prefix = "Grade " . $grade_clean;

$section_clean = trim(str_ireplace('Section', '', $section));
$section_with_prefix = "Section " . $section_clean;

// Normalize SY (handle spaces)
$sy_clean = str_replace(' ', '', $sy); // "2024-2025"
$sy_with_spaces = str_replace('-', ' - ', $sy_clean); // "2024 - 2025"

// Fetch the SF1 report metadata - Robust matching
$stmt = $pdo->prepare("
    SELECT * FROM sf1_reports 
    WHERE (grade_level = ? OR grade_level = ? OR grade_level = ?) 
    AND (section = ? OR section = ? OR section = ?) 
    AND (school_year = ? OR school_year = ? OR school_year = ?)
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([
    $grade, $grade_clean, $grade_with_prefix,
    $section, $section_clean, $section_with_prefix,
    $sy, $sy_clean, $sy_with_spaces
]);
$report = $stmt->fetch();

if (!$report) {
    echo "<div style='padding: 30px; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); margin: 40px auto; max-width: 600px; text-align: center; font-family: system-ui, -apple-system, sans-serif;'>";
    echo "<div style='color: #ef4444; font-size: 48px; margin-bottom: 20px;'><i class='bi bi-exclamation-circle'></i></div>";
    echo "<h2 style='color: #1e293b; margin: 0 0 10px 0; font-weight: 700;'>No SF1 Report Found</h2>";
    echo "<p style='color: #64748b; margin-bottom: 20px;'>We couldn't find a teacher-submitted SF1 report snapshot for the selected criteria. This usually means the class adviser hasn't finalized or saved their school register report for this period yet.</p>";
    echo "<div style='background: #f8fafc; padding: 15px; border-radius: 8px; text-align: left; font-size: 14px; color: #475569;'>";
    echo "<b>Criteria:</b><br>";
    echo "• Grade: $grade<br>";
    echo "• Section: $section<br>";
    echo "• School Year: $sy<br>";
    echo "</div>";
    echo "<a href='dashboard.php' style='display: inline-block; margin-top: 25px; padding: 10px 20px; background: #0038a8; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;'>Return to Dashboard</a>";
    echo "</div>";
    exit;
}

// Fetch the student records for this report
$stmt = $pdo->prepare("SELECT * FROM sf1_student_records WHERE sf1_report_id = ? ORDER BY sex DESC, last_name ASC, first_name ASC");
$stmt->execute([$report['id']]);
$students = $stmt->fetchAll();

// Fetch summary
$stmt = $pdo->prepare("SELECT * FROM sf1_summary WHERE sf1_report_id = ?");
$stmt->execute([$report['id']]);
$summary = $stmt->fetch();

// PDF Export Logic (No changes needed here)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    function imgToBase64($path) {
        if (strpos($path, 'http') === 0) {
            $data = @file_get_contents($path);
        } else {
            $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/');
            $data = @file_get_contents($fullPath);
        }
        if ($data === false) return '';
        $type = pathinfo($path, PATHINFO_EXTENSION);
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    $deped_base64 = imgToBase64('/assets/images/deped_logo.png');
    $school_base64 = imgToBase64($school_logo);

    ob_start();
    ?>
    <style>
        @page { size: legal landscape; margin: 0.5in; }
        body { font-family: Arial, sans-serif; font-size: 10px; color: #333; }

        .header-text { text-align: center; }
        .header-text h1 { margin: 0; font-size: 18px; color: #0d47a1; }
        .form-identity { display: table; width: 100%; margin: 15px 0; background: #f8fafc; padding: 10px; }
        .id-item { display: table-cell; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 6px; font-size: 9px; }
        th { background: #f1f5f9; }
        .report-footer { margin-top: 30px; width: 100%; }
        .footer-table { width: 100%; }
        .sig-line { border-bottom: 1px solid #000; font-weight: bold; padding-top: 20px; }
    </style>
    <table class="official-header" style="width: 100%; border-bottom: 2px solid #0d47a1; margin-bottom: 20px;">
        <tr>
            <td width="15%" align="left" style="border:none">
                <?php if ($deped_base64): ?>
                    <img src="<?= $deped_base64 ?>" style="width: 70px; height: auto;">
                <?php endif; ?>
            </td>
            <td width="70%" align="center" style="border:none">
                <div class="header-text">
                    <h2 style="margin:0; font-size:12px; font-weight:normal;">Republic of the Philippines</h2>
                    <h1 style="margin:5px 0; font-size:18px; color:#0d47a1;">Department of Education</h1>
                    <p style="margin:0; font-size:10px;">OFFICIAL SCHOOL REGISTER (SF1) - TEACHER SUBMITTED Snapshot</p>
                </div>
            </td>
            <td width="15%" align="right" style="border:none">
                <?php if ($school_base64): ?>
                    <img src="<?= $school_base64 ?>" style="width: 70px; height: auto;">
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <div class="form-identity">
        <div class="id-item"><b>SY:</b> <?= htmlspecialchars($sy) ?></div>
        <div class="id-item"><b>Grade:</b> <?= htmlspecialchars($grade) ?></div>
        <div class="id-item"><b>Section:</b> <?= htmlspecialchars($section) ?></div>
        <div class="id-item"><b>Submitted:</b> <?= date('M d, Y', strtotime($report['created_at'])) ?></div>
    </div>
    <table>
        <thead>
            <tr>
                <th>LRN</th>
                <th>Learner Name</th>
                <th>Sex</th>
                <th>Birth Date</th>
                <th>Age</th>
                <th>Mother Tongue</th>
                <th>Ethnicity</th>
                <th>Religion</th>
                <th>Address</th>
                <th>Guardian</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['lrn']) ?></td>
                    <td><b><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' ' . $s['middle_name']) ?></b></td>
                    <td align="center"><?= $s['sex'] ?></td>
                    <td align="center"><?= date('m/d/Y', strtotime($s['birth_date'])) ?></td>
                    <td align="center"><?= $s['age_as_of_oct31'] ?></td>
                    <td><?= htmlspecialchars($s['mother_tongue']) ?></td>
                    <td><?= htmlspecialchars($s['ip_ethnicity']) ?></td>
                    <td><?= htmlspecialchars($s['religion']) ?></td>
                    <td><?= htmlspecialchars($s['house_no_street'] . ', ' . $s['barangay']) ?></td>
                    <td><?= htmlspecialchars($s['guardian_name']) ?></td>
                    <td><?= htmlspecialchars($s['remarks']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="report-footer">
        <table class="footer-table">
            <tr>
                <td width="30%" style="border:none">
                    <b>Summary Statistics</b><br>
                    Male: <?= $summary['total_male'] ?? 0 ?><br>
                    Female: <?= $summary['total_female'] ?? 0 ?><br>
                    Total: <?= $summary['total_combined'] ?? 0 ?>
                </td>
                <td width="35%" style="border:none; text-align:center">
                    <div class="sig-line"><?= htmlspecialchars($summary['prepared_by_name'] ?? 'Class Adviser') ?></div>
                    Prepared By (Class Adviser)
                </td>
                <td width="35%" style="border:none; text-align:center">
                    <div class="sig-line"><?= htmlspecialchars($report['school_head'] ?: $principal_name) ?></div>
                    Certified Correct (School Head)
                </td>
            </tr>
        </table>
    </div>
    <?php
    $html = ob_get_clean();
    require_once __DIR__ . '/../../../includes/report_export_helper.php';
    exportToPDF($html, 'SF1_' . $section . '_' . str_replace(' ', '_', $sy), 'landscape', 'legal');
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../admin_header.php';
$sidebar_file = ($role === 'registrar') ? '../../../registrar/registrar_side_panel.php' : '../../admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher SF1 View | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --deped-blue: #0d47a1;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background: #f1f5f9; 
            margin: 0; 
            color: #1e293b;
            scroll-behavior: smooth;
            overflow-x: hidden;
        }

        .main-content { 
            padding: 100px 40px 48px; 
            margin-left: 0; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }

        /* Hide sidebar toggle since sidebar is removed */
        #sidebarToggle { display: none !important; }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0 !important; padding: 90px 15px 40px; }
            .action-bar { right: 20px; top: 15px; }
        }

        .action-bar {
            position: fixed; top: 20px; right: 40px; z-index: 1001; display: flex; gap: 12px; align-items: center;
            background: rgba(255, 255, 255, 0.9); padding: 8px 16px; border-radius: 50px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15); backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .btn-action {
            display: flex; align-items: center; gap: 8px; padding: 10px 22px; border-radius: 30px;
            font-size: 14px; font-weight: 700; text-decoration: none; transition: all 0.3s;
            cursor: pointer; border: none;
        }

        .btn-print { background: #0f172a; color: white; }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }

        .report-card { 
            background: white; border-radius: 20px; padding: 40px; box-shadow: var(--glass-shadow); 
            border: 1px solid rgba(226, 232, 240, 0.8); margin-top: 20px; position: relative; overflow: hidden;
            width: 100%; max-width: 100%; box-sizing: border-box;
        }

        @media (max-width: 768px) {
            .main-content { padding: 80px 10px 20px; }
            .report-card { padding: 20px 12px; border-radius: 12px; }
            .official-header { flex-direction: column; gap: 15px; text-align: center; }
            .header-text h1 { font-size: 18px; }
            .form-identity { grid-template-columns: 1fr 1fr; gap: 15px; padding: 15px; }
            .id-item span { font-size: 14px; }
            .report-footer { flex-direction: column; gap: 30px; }
            .summary-box { width: 100%; box-sizing: border-box; }
            .action-bar { position: relative; top: 0; right: 0; margin-bottom: 20px; width: 100%; border-radius: 12px; justify-content: center; }
        }
        
        .report-card::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px;
            background: linear-gradient(90deg, var(--deped-blue), #2563eb);
        }

        .official-header {
            display: flex; align-items: center; justify-content: center; gap: 50px;
            margin-bottom: 40px; padding-bottom: 25px; border-bottom: 2px solid #f1f5f9;
        }

        .deped-logo { width: 80px; height: auto; }
        .header-text { text-align: center; }
        .header-text h2 { margin: 0; font-size: 12px; font-weight: 500; text-transform: uppercase; color: #64748b; }
        .header-text h1 { margin: 8px 0; font-size: 24px; color: var(--deped-blue); font-family: 'Outfit', sans-serif; font-weight: 800; }
        .header-text p { margin: 0; font-size: 13px; color: #475569; font-weight: 600; }

        .form-identity {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px; margin: 30px 0; padding: 24px; background: #f8fafc; border-radius: 16px; border: 1px solid #f1f5f9;
        }

        .id-item b { color: var(--deped-blue); text-transform: uppercase; font-size: 11px; display: block; margin-bottom: 4px; font-weight: 800; }
        .id-item span { font-weight: 700; color: #0f172a; font-size: 16px; }

        .table-container { 
            margin-top: 30px; border-radius: 12px; border: 1px solid #e2e8f0; overflow-x: auto; background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            -webkit-overflow-scrolling: touch;
        }
        
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1200px; color: #1e293b; }
        th { 
            background: #f8fafc; color: #1e293b; font-weight: 700; padding: 12px 10px; border: 1px solid #e2e8f0;
            position: sticky; top: 0; z-index: 10; text-transform: uppercase;
        }
        td { padding: 10px; border: 1px solid #e2e8f0; vertical-align: middle; }
        
        .student-name { 
            text-align: left; font-weight: 700; min-width: 250px; padding-left: 15px; 
            position: sticky; left: 0; background: white; z-index: 5; 
            box-shadow: 4px 0 10px rgba(0,0,0,0.03); color: #0f172a;
        }
        th.student-name { z-index: 15; background: #f8fafc; }

        .male-row { background: #f0f9ff; }
        .female-row { background: #fff1f2; }
        
        .lrn { font-family: 'Outfit', monospace; font-weight: 600; color: #2563eb; }

        .sex-badge {
            display: inline-block; padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 10px;
        }
        .sex-m { background: #dbeafe; color: #1d4ed8; }
        .sex-f { background: #ffe4e6; color: #be123c; }

        .report-footer {
            margin-top: 50px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px; align-items: flex-start;
        }

        .summary-box { background: white; padding: 25px; border-radius: 16px; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .summary-box h3 { margin: 0 0 15px 0; font-size: 13px; color: var(--deped-blue); text-transform: uppercase; font-weight: 800; }
        .stat-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 15px; color: #1e293b; }
        .stat-row b { color: #0f172a; font-size: 18px; }

        .signature-section { text-align: center; }
        .sig-line { 
            margin: 40px auto 8px; border-bottom: 2px solid #0f172a; font-weight: 800; text-transform: uppercase;
            font-size: 15px; display: inline-block; min-width: 250px; color: #0f172a;
        }
        .sig-title { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        @media print {
            body { background: white !important; }
            .sidebar, .header-panel, .no-print, .action-bar { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .report-card { border: none; box-shadow: none; padding: 0; }
            .report-card::before { display: none; }
        }
    </style>
</head>
<body>
    <?php include $header_file; ?>

    <div class="main-content">
        <div class="action-bar no-print">
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="bi bi-printer-fill"></i> Print
            </button>

            <a href="?grade=<?= urlencode($grade) ?>&section=<?= urlencode($section) ?>&sy=<?= urlencode($sy) ?>&export=pdf" class="btn-action btn-pdf">
                <i class="bi bi-file-earmark-pdf-fill"></i> PDF Export
            </a>
        </div>

        <div class="report-card">
            <div class="official-header">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" alt="DepEd Logo" class="deped-logo">
                <div class="header-text">
                    <h2>Republic of the Philippines</h2>
                    <h1>Department of Education</h1>
                    <p>SCHOOL FORM 1 (SF1) SCHOOL REGISTER</p>
                    <p style="font-weight: 800; color: #ef4444; margin-top: 8px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">Institutional Snapshot: Teacher Submitted Version</p>
                </div>
                <img src="<?= $display_logo ?>" alt="School Logo" class="deped-logo" onerror="this.src='/favicon.ico'">
            </div>

            <div class="form-identity">
                <div class="id-item">
                    <b>School Year</b>
                    <span><?= htmlspecialchars($sy) ?></span>
                </div>
                <div class="id-item">
                    <b>Grade Level</b>
                    <span><?= htmlspecialchars($grade) ?></span>
                </div>
                <div class="id-item">
                    <b>Section</b>
                    <span><?= htmlspecialchars($section) ?></span>
                </div>
                <div class="id-item">
                    <b>Submission Date</b>
                    <span><?= date('M d, Y', strtotime($report['created_at'])) ?></span>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">LRN</th>
                            <th rowspan="2" class="student-name">NAME OF LEARNER</th>
                            <th rowspan="2">SEX</th>
                            <th rowspan="2">BIRTH DATE</th>
                            <th rowspan="2">AGE</th>
                            <th rowspan="2">MOTHER<br>TONGUE</th>
                            <th rowspan="2">IP<br>(Ethnicity)</th>
                            <th rowspan="2">RELIGION</th>
                            <th colspan="3">ADDRESS</th>
                            <th colspan="2">PARENTS</th>
                            <th rowspan="2">GUARDIAN</th>
                            <th rowspan="2">REMARKS</th>
                        </tr>
                        <tr>
                            <th>House #/Street</th>
                            <th>Barangay</th>
                            <th>Municipality</th>
                            <th>Father's Name</th>
                            <th>Mother's Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $males = array_filter($students, function($s) { return $s['sex'] === 'M'; });
                        $females = array_filter($students, function($s) { return $s['sex'] === 'F'; });
                        
                        if (!empty($males)): ?>
                            <tr>
                                <td colspan="15" style="background: #f1f5f9; text-align: left; padding-left: 15px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 1px;">Male</td>
                            </tr>
                            <?php foreach ($males as $s): ?>
                                <tr class="male-row">
                                    <td class="lrn"><?= htmlspecialchars($s['lrn']) ?></td>
                                    <td class="student-name"><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' ' . $s['middle_name']) ?></td>
                                    <td align="center"><span class="sex-badge sex-m"><?= $s['sex'] ?></span></td>
                                    <td align="center"><?= date('m/d/Y', strtotime($s['birth_date'])) ?></td>
                                    <td align="center"><?= $s['age_as_of_oct31'] ?></td>
                                    <td><?= htmlspecialchars($s['mother_tongue']) ?></td>
                                    <td><?= htmlspecialchars($s['ip_ethnicity']) ?></td>
                                    <td><?= htmlspecialchars($s['religion']) ?></td>
                                    <td><?= htmlspecialchars($s['house_no_street']) ?></td>
                                    <td><?= htmlspecialchars($s['barangay']) ?></td>
                                    <td><?= htmlspecialchars($s['municipality_city']) ?></td>
                                    <td><?= htmlspecialchars($s['father_first_name'] . ' ' . $s['father_last_name']) ?></td>
                                    <td><?= htmlspecialchars($s['mother_first_name'] . ' ' . $s['mother_last_name']) ?></td>
                                    <td><?= htmlspecialchars($s['guardian_name']) ?></td>
                                    <td><?= htmlspecialchars($s['remarks']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($females)): ?>
                            <tr>
                                <td colspan="15" style="background: #f1f5f9; text-align: left; padding-left: 15px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 1px;">Female</td>
                            </tr>
                            <?php foreach ($females as $s): ?>
                                <tr class="female-row">
                                    <td class="lrn"><?= htmlspecialchars($s['lrn']) ?></td>
                                    <td class="student-name"><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' ' . $s['middle_name']) ?></td>
                                    <td align="center"><span class="sex-badge sex-f"><?= $s['sex'] ?></span></td>
                                    <td align="center"><?= date('m/d/Y', strtotime($s['birth_date'])) ?></td>
                                    <td align="center"><?= $s['age_as_of_oct31'] ?></td>
                                    <td><?= htmlspecialchars($s['mother_tongue']) ?></td>
                                    <td><?= htmlspecialchars($s['ip_ethnicity']) ?></td>
                                    <td><?= htmlspecialchars($s['religion']) ?></td>
                                    <td><?= htmlspecialchars($s['house_no_street']) ?></td>
                                    <td><?= htmlspecialchars($s['barangay']) ?></td>
                                    <td><?= htmlspecialchars($s['municipality_city']) ?></td>
                                    <td><?= htmlspecialchars($s['father_first_name'] . ' ' . $s['father_last_name']) ?></td>
                                    <td><?= htmlspecialchars($s['mother_first_name'] . ' ' . $s['mother_last_name']) ?></td>
                                    <td><?= htmlspecialchars($s['guardian_name']) ?></td>
                                    <td><?= htmlspecialchars($s['remarks']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="report-footer">
                <div class="summary-box">
                    <h3>Enrollment Summary</h3>
                    <div class="stat-row"><span>Male:</span> <b><?= $summary['total_male'] ?? 0 ?></b></div>
                    <div class="stat-row"><span>Female:</span> <b><?= $summary['total_female'] ?? 0 ?></b></div>
                    <div class="stat-row" style="border-top: 1px dashed #e2e8f0; padding-top: 10px; margin-top: 10px;">
                        <span>Total:</span> <b><?= $summary['total_combined'] ?? 0 ?></b>
                    </div>
                </div>

                <div class="signature-section">
                    <div class="sig-line"><?= htmlspecialchars($summary['prepared_by_name'] ?? 'Class Adviser') ?></div>
                    <div class="sig-title">Class Adviser Signature</div>
                </div>

                <div class="signature-section">
                    <div class="sig-line"><?= htmlspecialchars($report['school_head'] ?: $principal_name) ?></div>
                    <div class="sig-title">School Head / Principal</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
