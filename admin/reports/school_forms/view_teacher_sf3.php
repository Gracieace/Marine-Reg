<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = trim($_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');
$sy = trim($_GET['sy'] ?? '');

// Bidirectional normalization for robust matching
$grade_clean = trim(str_ireplace('Grade', '', $grade));
$grade_with_prefix = "Grade " . $grade_clean;

$section_clean = trim(str_ireplace('Section', '', $section));
$section_with_prefix = "Section " . $section_clean;

$sy_clean = str_replace(' ', '', $sy); // "2024-2025"
$sy_with_spaces = str_replace('-', ' - ', $sy_clean); // "2024 - 2025"

// Fetch the SF3 report metadata
$stmt = $pdo->prepare("SELECT * FROM sf3_reports 
    WHERE (grade_level = ? OR grade_level = ? OR grade_level = ?) 
    AND (section = ? OR section = ? OR section = ?) 
    AND (school_year = ? OR school_year = ? OR school_year = ?)
    ORDER BY created_at DESC LIMIT 1");
$stmt->execute([
    $grade, $grade_clean, $grade_with_prefix,
    $section, $section_clean, $section_with_prefix,
    $sy, $sy_clean, $sy_with_spaces
]);
$report = $stmt->fetch();

if (!$report) {
    echo "<div style='padding:40px; font-family:sans-serif; text-align:center;'>
            <h2 style='color:#ef4444;'>No Submission Found</h2>
            <p>The teacher has not yet submitted an SF3 report for $grade - $section ($sy).</p>
            <a href='javascript:history.back()' style='color:#3b82f6;'>← Go Back</a>
          </div>";
    exit;
}

$report_id = $report['id'];

// System Settings
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_id = get_system_setting($pdo, 'school_id', '300000');
$district = get_system_setting($pdo, 'district', 'Malolos');
$division = get_system_setting($pdo, 'division', 'City of Malolos');
$region = get_system_setting($pdo, 'region', 'Region III');
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');

// Fetch Adviser Name from sections table
$stmt = $pdo->prepare("SELECT u.first_name, u.last_name 
    FROM users u JOIN sections s ON u.id = s.adviser_id 
    WHERE (s.grade_level = ? OR s.grade_level = ? OR s.grade_level = ?) 
    AND (s.section_name = ? OR s.section_name = ? OR s.section_name = ?) 
    AND (s.school_year = ? OR s.school_year = ? OR s.school_year = ?) LIMIT 1");
$stmt->execute([
    $grade, $grade_clean, $grade_with_prefix,
    $section, $section_clean, $section_with_prefix,
    $sy, $sy_clean, $sy_with_spaces
]);
$adviser = $stmt->fetch();
$adviser_name = $adviser ? ($adviser['first_name'] . ' ' . $adviser['last_name']) : 'CLASS ADVISER';

// Fetch inventory
$stmt = $pdo->prepare("SELECT * FROM sf3_books_inventory WHERE sf3_report_id = ? ORDER BY subject ASC, title ASC");
$stmt->execute([$report_id]);
$inventory = $stmt->fetchAll();

// Fetch students with gender grouping - Robust match
$stmt = $pdo->prepare("
    SELECT e.student_id as lrn, e.student_name, 
           COALESCE(NULLIF(TRIM(r.sex), ''), 'M') as display_sex, 
           r.last_name, r.first_name, r.middle_name
    FROM enrollments e
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND (r.lrn = e.lrn OR r.lrn = e.student_id)))
    WHERE (e.school_year = ? OR e.school_year = ? OR e.school_year = ?)
    AND (e.grade_level = ? OR e.grade_level = ? OR e.grade_level = ?)
    AND (e.section = ? OR e.section = ? OR e.section = ?)
    ORDER BY CASE WHEN COALESCE(NULLIF(TRIM(r.sex), ''), 'M') IN ('F', 'Female', '2') THEN 2 ELSE 1 END ASC, 
             COALESCE(r.last_name, e.student_name) ASC
");
$stmt->execute([
    $sy, $sy_clean, $sy_with_spaces,
    $grade, $grade_clean, $grade_with_prefix,
    $section, $section_clean, $section_with_prefix
]);
$students = $stmt->fetchAll();

// Get book records
$stmt = $pdo->prepare("SELECT * FROM sf3_student_books WHERE sf3_report_id = ?");
$stmt->execute([$report_id]);
$book_records = $stmt->fetchAll();

$books_by_lrn = [];
foreach ($book_records as $br) {
    $books_by_lrn[$br['student_lrn']][$br['inventory_id']] = $br;
}

function renderSf3Rows($list, $inventory, $books_by_lrn) {
    $count = 1;
    foreach ($list as $s) { ?>
        <tr>
            <td><?= $count++ ?></td>
            <td class="student-name">
                <?= htmlspecialchars(trim(($s['last_name'] ?? '') . ' ' . ($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '')) ?: $s['student_name']) ?>
            </td>
            <?php foreach ($inventory as $book): 
                $rec = $books_by_lrn[$s['lrn']][$book['id']] ?? null;
                ?>
                <td><?= ($rec && $rec['date_issued']) ? date('m/d/y', strtotime($rec['date_issued'])) : '-' ?></td>
                <td>
                    <?php if ($rec && $rec['date_returned']): ?>
                        <span class="status-badge status-<?= $rec['condition_returned'] ?>"><?= $rec['condition_returned'] ?></span>
                        <br><small><?= date('m/d/y', strtotime($rec['date_returned'])) ?></small>
                    <?php else: ?>
                        <span style="color:#cbd5e1">-</span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php }
}

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF3 | <?= htmlspecialchars($section) ?> (<?= htmlspecialchars($sy) ?>)</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --deped-blue: #0d47a1;
            --deped-red: #ef4444;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; overflow-x: hidden; }
        .main-content { padding: 100px 40px 48px; margin-left: 0; transition: all 0.3s ease; }
        #sidebarToggle { display: none !important; }

        .report-card { 
            background: white; border-radius: 20px; padding: 40px; box-shadow: var(--glass-shadow); 
            border: 1px solid rgba(226, 232, 240, 0.8); margin-top: 20px; position: relative; overflow: hidden;
            width: 100%; max-width: 100%; box-sizing: border-box;
        }
        .report-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, var(--deped-blue), #2563eb); }

        .official-header {
            display: flex; align-items: center; justify-content: center; gap: 50px;
            margin-bottom: 30px; padding-bottom: 25px; border-bottom: 2px solid #f1f5f9;
        }
        .deped-logo { width: 80px; height: auto; }
        .header-text { text-align: center; }
        .header-text h2 { margin: 0; font-size: 12px; font-weight: 500; text-transform: uppercase; color: #64748b; }
        .header-text h1 { margin: 8px 0; font-size: 24px; color: var(--deped-blue); font-family: 'Outfit', sans-serif; font-weight: 800; }
        .header-text p { margin: 0; font-size: 13px; color: #475569; font-weight: 600; }

        .form-identity {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; margin: 30px 0; padding: 24px; background: #f8fafc; border-radius: 16px; border: 1px solid #f1f5f9;
        }
        .id-item b { color: var(--deped-blue); text-transform: uppercase; font-size: 10px; display: block; margin-bottom: 4px; font-weight: 800; opacity: 0.7; }
        .id-item span { font-weight: 700; color: #0f172a; font-size: 15px; }

        .table-container { 
            margin-top: 20px; border-radius: 12px; border: 1px solid #e2e8f0; overflow-x: auto; background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            -webkit-overflow-scrolling: touch;
        }
        table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 1200px; color: #1e293b; }
        th { background: #f8fafc; color: #1e293b; font-weight: 700; padding: 12px 8px; border: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 10; text-transform: uppercase; }
        td { padding: 10px 8px; border: 1px solid #e2e8f0; text-align: center; }
        .student-name { text-align: left; font-weight: 700; min-width: 250px; padding-left: 15px; position: sticky; left: 0; background: white; z-index: 5; color: #0f172a; }
        th.student-name { z-index: 15; background: #f8fafc; }

        .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .status-Good { background: #dcfce7; color: #166534; }
        .status-Lost { background: #fee2e2; color: #991b1b; }
        .status-Damaged { background: #fef3c7; color: #92400e; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 40px; }
        .summary-card { background: #f8fafc; padding: 24px; border-radius: 16px; border: 1px solid #f1f5f9; }
        .summary-card h3 { margin: 0 0 15px 0; font-size: 13px; color: var(--deped-blue); text-transform: uppercase; font-weight: 800; }
        
        .signatures { margin-top: 50px; display: flex; justify-content: space-around; padding: 20px 0; }
        .sig-box { text-align: center; min-width: 250px; }
        .sig-line { border-bottom: 2px solid #0f172a; font-weight: 800; text-transform: uppercase; font-size: 15px; margin-bottom: 8px; color: #0f172a; padding-bottom: 5px; }
        .sig-title { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        @media (max-width: 768px) {
            .main-content { padding: 80px 10px 20px; }
            .report-card { padding: 20px 12px; }
            .official-header { flex-direction: column; gap: 15px; text-align: center; }
            .summary-grid { grid-template-columns: 1fr; }
            .signatures { flex-direction: column; gap: 40px; align-items: center; }
        }

        @media print {
            .no-print, .action-bar { display: none !important; }
            .main-content { padding: 0 !important; }
            .report-card { box-shadow: none; border: none; }
            table { font-size: 9px; }
        }
    </style>
</head>
<body>
    <?php include $header_file; ?>

    <div class="main-content">
        <div class="report-card">
            <div class="official-header">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="deped-logo" alt="DepEd">
                <div class="header-text">
                    <h2>Republic of the Philippines</h2>
                    <h1>Department of Education</h1>
                    <p>SCHOOL FORM 3 (SF3) BOOKS ISSUED AND RETURNED</p>
                    <p style="color:var(--deped-red); font-size:11px; margin-top:5px; letter-spacing:1px; font-weight:700;">INSTITUTIONAL SNAPSHOT: TEACHER SUBMITTED VERSION</p>
                </div>
                <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="deped-logo" alt="School">
            </div>

            <div class="form-identity">
                <div class="id-item">
                    <b>Region</b>
                    <span><?= htmlspecialchars($region) ?></span>
                </div>
                <div class="id-item">
                    <b>Division</b>
                    <span><?= htmlspecialchars($division) ?></span>
                </div>
                <div class="id-item">
                    <b>District</b>
                    <span><?= htmlspecialchars($district) ?></span>
                </div>
                <div class="id-item">
                    <b>School ID</b>
                    <span><?= htmlspecialchars($school_id) ?></span>
                </div>
                <div class="id-item">
                    <b>School Year</b>
                    <span><?= htmlspecialchars($sy) ?></span>
                </div>
                <div class="id-item">
                    <b>Grade & Section</b>
                    <span><?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?></span>
                </div>
                <div class="id-item" style="grid-column: span 2;">
                    <b>Class Adviser</b>
                    <span><?= htmlspecialchars($adviser_name) ?></span>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2" width="40">No.</th>
                            <th rowspan="2" class="student-name">Name of Learner</th>
                            <?php foreach ($inventory as $book): ?>
                                <th colspan="2"><?= htmlspecialchars($book['subject']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach ($inventory as $book): ?>
                                <th width="80">Issued</th>
                                <th width="80">Returned</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $males = array_filter($students, function($s) { 
                            $raw = $s['display_sex'] ?? 'M';
                            return !($raw == 'F' || $raw == 'Female' || $raw == '2');
                        });
                        $females = array_filter($students, function($s) { 
                            $raw = $s['display_sex'] ?? 'M';
                            return ($raw == 'F' || $raw == 'Female' || $raw == '2');
                        });
                        
                        if (!empty($males)): ?>
                            <tr>
                                <td colspan="<?= (count($inventory) * 2) + 2 ?>" style="background: #f8fafc; text-align: left; padding-left: 15px; font-weight: 800; color: #475569; text-transform: uppercase; font-size: 10px; letter-spacing: 1px;">Male</td>
                            </tr>
                            <?php renderSf3Rows($males, $inventory, $books_by_lrn); ?>
                        <?php endif; ?>

                        <?php if (!empty($females)): ?>
                            <tr>
                                <td colspan="<?= (count($inventory) * 2) + 2 ?>" style="background: #f8fafc; text-align: left; padding-left: 15px; font-weight: 800; color: #475569; text-transform: uppercase; font-size: 10px; letter-spacing: 1px;">Female</td>
                            </tr>
                            <?php renderSf3Rows($females, $inventory, $books_by_lrn); ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Textbook Inventory Summary</h3>
                    <table style="min-width: 100%; border: none;">
                        <thead>
                            <tr>
                                <th style="text-align: left; background: transparent; border-bottom: 2px solid #e2e8f0; font-size: 10px;">Title</th>
                                <th style="background: transparent; border-bottom: 2px solid #e2e8f0; font-size: 10px;">Rec.</th>
                                <th style="background: transparent; border-bottom: 2px solid #e2e8f0; font-size: 10px;">Good</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $inv): ?>
                                <tr>
                                    <td style="text-align: left; border: none; padding: 8px 0; font-weight: 600;"><?= htmlspecialchars($inv['title']) ?></td>
                                    <td style="border: none;"><?= $inv['total_copies_received'] ?></td>
                                    <td style="border: none;"><?= $inv['copies_in_good_condition'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="summary-card">
                    <h3>Report Guidelines</h3>
                    <div style="font-size: 12px; color: #475569; line-height: 1.6;">
                        <p>• This report summarizes textbook issuance and return status.</p>
                        <p>• <b>Rec.</b>: Total copies received by the class advisor.</p>
                        <p>• <b>Good</b>: Total copies returned in usable condition.</p>
                        <p>• Official snapshots are immutable once submitted by the teacher.</p>
                    </div>
                </div>
            </div>

            <div class="signatures" style="margin-top: 80px;">
                <div class="sig-box">
                    <div class="sig-line"><?= htmlspecialchars($adviser_name) ?></div>
                    <div class="sig-title">Class Adviser / Prepared By</div>
                    <div style="font-size: 9px; color: #94a3b8; margin-top: 5px;"><?= date('F d, Y') ?></div>
                </div>
                <div class="sig-box">
                    <div class="sig-line"><?= strtoupper(htmlspecialchars($principal_name)) ?></div>
                    <div class="sig-title">School Head / Principal</div>
                    <div style="font-size: 9px; color: #94a3b8; margin-top: 5px;">Certified Correct</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
