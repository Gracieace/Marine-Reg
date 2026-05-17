<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$teacher_id = $_SESSION['user']['id'];
$pdo = db_connect();

$school_year = trim($_GET['school_year'] ?? $_GET['sy'] ?? '');
$grade_level = trim($_GET['grade_level'] ?? $_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');

if (!$school_year || !$grade_level || !$section) {
    die('<p style="font:13px Arial;padding:40px;color:red;">Missing parameters.</p>');
}

// System Settings
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$division = get_system_setting($pdo, 'division', 'City of Malolos');
$region = get_system_setting($pdo, 'region', 'Region III');
$school_id = get_system_setting($pdo, 'school_id', '300764');
$district = get_system_setting($pdo, 'district', 'Malolos City');

// Dynamic Column Detection
$grade_col = 'grade_level';
try {
    $check_cols = $pdo->query("DESCRIBE enrollments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('grade_level', $check_cols) && in_array('year_level', $check_cols)) { $grade_col = 'year_level'; }
} catch (Exception $e) {}

// 1. Fetch SF3 report
$stmt = $pdo->prepare("SELECT * FROM sf3_reports WHERE school_year=? AND grade_level=? AND section=? LIMIT 1");
$stmt->execute([$school_year, $grade_level, $section]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die('<p style="font:13px Arial;padding:40px;color:#b91c1c;text-align:center;">
        <i class="fas fa-exclamation-triangle" style="font-size:3rem;display:block;margin-bottom:1rem;"></i>
        No SF3 report found. Please save the report in the SF3 form first.
    </p>');
}

// 2. Inventory (books list)
$stmt = $pdo->prepare("SELECT * FROM sf3_books_inventory WHERE sf3_report_id=? ORDER BY subject ASC, title ASC");
$stmt->execute([$report['id']]);
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Students
$stmt = $pdo->prepare("
    SELECT e.student_id, e.lrn, e.student_name, r.sex 
    FROM enrollments e 
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND (r.lrn = e.lrn OR r.lrn = e.student_id)))
    WHERE e.school_year = ? AND e.$grade_col = ? AND e.section = ?
    AND (e.status IS NULL OR e.status IN ('Enrolled','Active'))
    ORDER BY r.sex DESC, e.student_name ASC
");
$stmt->execute([$school_year, $grade_level, $section]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Live Distributions
$sids = array_column($students, 'student_id');
$dist_by_sid = [];
if (!empty($sids)) {
    $placeholders = str_repeat('?,', count($sids) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT d.*, b.title as book_title, b.subject as book_subject, ret.return_date as date_returned
        FROM textbook_distributions d
        JOIN admin_books b ON d.textbook_id = b.id
        LEFT JOIN textbook_returns ret ON d.id = ret.distribution_id
        WHERE d.student_id IN ($placeholders)
    ");
    $stmt->execute($sids);
    $raw_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_dist as $d) { $dist_by_sid[trim(strtoupper($d['student_id']))][] = $d; }
}

// Grouping
$males = array_filter($students, fn($s) => trim(strtoupper($s['sex'] ?? ''))[0] === 'M');
$females = array_filter($students, fn($s) => trim(strtoupper($s['sex'] ?? ''))[0] === 'F');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF3 - <?= htmlspecialchars($grade_level) ?> - <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --paper-width: 13in; --paper-height: 8.5in; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; }
        
        .no-print-header { background: #1e293b; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .btn { padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 700; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; font-size: 14px; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-light { background: #f8fafc; color: #1e293b; }

        .paper-container { padding: 40px 20px; display: flex; justify-content: center; min-height: 100vh; }
        .paper { background: white; width: var(--paper-width); height: auto; min-height: var(--paper-height); padding: 0.5in; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); position: relative; overflow: hidden; }

        @media print {
            body { background: white; }
            .no-print-header, .paper-container { padding: 0; margin: 0; }
            .paper { width: 100%; height: auto; box-shadow: none; padding: 0; margin: 0; }
            @page { size: legal landscape; margin: 0.3in; }
            .no-print { display: none !important; }
        }

        .deped-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .logo-box { width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; }
        .logo-box img { max-width: 100%; max-height: 100%; }
        .header-center { text-align: center; flex: 1; }
        .header-center h1 { font-size: 16px; margin: 0; font-weight: 800; text-transform: uppercase; }
        .header-center p { font-size: 11px; margin: 2px 0; }

        .form-title { text-align: center; margin: 15px 0; }
        .form-title h2 { font-size: 18px; margin: 0; font-weight: 900; }
        .form-title p { font-size: 12px; margin: 5px 0; font-style: italic; }

        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 15px; font-size: 10px; }
        .info-item { border-bottom: 1px solid #000; padding: 2px 5px; }
        .info-label { font-weight: 800; font-size: 9px; color: #444; }

        table { width: 100%; border-collapse: collapse; font-size: 8.5px; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 3px 2px; }
        th { background: #f1f5f9; font-weight: 800; text-transform: uppercase; }

        .vertical-text { writing-mode: vertical-rl; transform: rotate(180deg); height: 140px; text-align: center; font-size: 7.5px; line-height: 1; }
        .date-col { width: 40px; text-align: center; font-size: 7px; white-space: nowrap; }
        
        .gender-row { background: #e2e8f0; font-weight: 900; font-size: 9px; padding-left: 10px !important; text-align: left !important; }
        .signature-section { margin-top: 40px; display: grid; grid-template-columns: 1fr 1.5fr 1fr; gap: 50px; font-size: 10px; }
        .sig-box { text-align: center; }
        .sig-line { border-top: 1.5px solid #000; margin-top: 40px; font-weight: 800; padding-top: 5px; text-transform: uppercase; }
    </style>
</head>
<body>

    <div class="no-print-header no-print">
        <div style="display:flex; align-items:center; gap:15px;">
            <button class="btn btn-light" onclick="window.history.back()"><i class="fas fa-arrow-left"></i> Back</button>
            <h3 style="margin:0; font-size:18px;">School Form 3 (SF3) Print Preview</h3>
        </div>
        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
    </div>

    <div class="paper-container">
        <div class="paper">
            <div class="deped-header">
                <div class="logo-box"><img src="<?= url_for('/assets/images/deped_logo.png') ?>" alt="DepEd"></div>
                <div class="header-center">
                    <p>Republic of the Philippines</p>
                    <p>Department of Education</p>
                    <h1><?= htmlspecialchars($school_name) ?></h1>
                    <p><?= htmlspecialchars($district) ?> District, <?= htmlspecialchars($division) ?>, <?= htmlspecialchars($region) ?></p>
                </div>
                <div class="logo-box"><img src="<?= url_for('/assets/images/school_logo.png') ?>" alt="School Logo"></div>
            </div>

            <div class="form-title">
                <h2>School Form 3 (SF3) Books Issued and Returned</h2>
                <p>(This form replaces Form 1 & Inventory of Textbooks)</p>
            </div>

            <div class="info-grid">
                <div class="info-item"><span class="info-label">School ID:</span> <?= htmlspecialchars($school_id) ?></div>
                <div class="info-item"><span class="info-label">School Year:</span> <?= htmlspecialchars($school_year) ?></div>
                <div class="info-item"><span class="info-label">Grade Level:</span> <?= htmlspecialchars($grade_level) ?></div>
                <div class="info-item"><span class="info-label">Section:</span> <?= htmlspecialchars($section) ?></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th rowspan="3" style="width:25px;">No.</th>
                        <th rowspan="3" style="width:180px;">NAME OF LEARNERS</th>
                        <?php foreach($inventory as $b): ?><th colspan="2" style="font-size:7px;"><?= htmlspecialchars($b['subject']) ?></th><?php endforeach; ?>
                        <th rowspan="3" style="width:40px;">TOTAL</th>
                    </tr>
                    <tr>
                        <?php foreach($inventory as $b): ?><th colspan="2" class="vertical-text"><?= htmlspecialchars($b['title']) ?></th><?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach($inventory as $b): ?>
                            <th class="date-col">Issued</th>
                            <th class="date-col">Returned</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    function renderSF3Print($list, &$idx, $inventory, $dist_by_sid) {
                        foreach($list as $s) {
                            echo "<tr><td style='text-align:center;'>".$idx++."</td><td style='text-transform:uppercase; font-weight:700;'>".htmlspecialchars($s['student_name'])."</td>";
                            $row_total = 0;
                            $my_dists = $dist_by_sid[trim(strtoupper($s['student_id']))] ?? [];

                            foreach($inventory as $ib) {
                                $match = null; $isub = strtolower(trim($ib['subject'])); $itit = strtolower(trim($ib['title']));
                                foreach($my_dists as $d) {
                                    if (strtolower(trim($d['book_subject'])) === $isub) {
                                        $match = $d; if (strtolower(trim($d['book_title'])) === $itit) break;
                                    }
                                }
                                
                                $issued = '—'; $ret = '—';
                                if ($match && $match['date_issued']) {
                                    $row_total++;
                                    $issued = date('m/d/y', strtotime($match['date_issued']));
                                    if (($match['status'] ?? '') === 'Returned' && $match['date_returned']) {
                                        $ret = date('m/d/y', strtotime($match['date_returned']));
                                    } elseif ($match['status'] && $match['status'] !== 'Active') {
                                        $ret = $match['status'];
                                    }
                                }
                                echo "<td class='date-col'>$issued</td><td class='date-col'>$ret</td>";
                            }
                            echo "<td style='text-align:center; font-weight:bold; background:#f8fafc;'>$row_total</td></tr>";
                        }
                    }

                    $c=1; echo "<tr><td colspan='".(count($inventory)*2+3)."' class='gender-row'>MALE</td></tr>";
                    renderSF3Print($males, $c, $inventory, $dist_by_sid);
                    $c=1; echo "<tr><td colspan='".(count($inventory)*2+3)."' class='gender-row'>FEMALE</td></tr>";
                    renderSF3Print($females, $c, $inventory, $dist_by_sid);
                    ?>
                </tbody>
            </table>

            <div class="signature-section">
                <div class="sig-box">
                    <p>Prepared by:</p>
                    <div class="sig-line"><?= htmlspecialchars($report['prepared_by'] ?? '') ?></div>
                    <p>Class Adviser</p>
                </div>
                <div class="sig-box" style="visibility:hidden;"></div>
                <div class="sig-box">
                    <p>Certified Correct:</p>
                    <div class="sig-line">
                        <?php 
                            $principal_name = get_system_setting($pdo, 'principal_name', $report['school_head'] ?? 'School Head'); 
                            echo strtoupper($principal_name);
                        ?>
                    </div>
                    <p>School Head</p>
                </div>
            </div>

            <div style="margin-top: 20px; font-size: 8px; color: #666; display: flex; justify-content: space-between;">
                <span>Generated via MMFSL School Management System</span>
                <span>Date Printed: <?= date('F d, Y') ?></span>
            </div>
        </div>
    </div>

</body>
</html>