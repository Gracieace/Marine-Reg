<?php
/**
 * SF10 – Official DepED Print Template (Legal Size)
 * Includes Profile, Scholastic Record, Attendance, Conduct, and Eligibility.
 */
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin', 'registrar']);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = db_connect();
$student_id = $_GET['student_id'] ?? '';

if (!$student_id) die("Student ID required");

// 1. Fetch Comprehensive Data
// Profile
$stmt = $pdo->prepare("SELECT e.*, r.sex, r.last_name, r.first_name, r.middle_name, r.birthdate as reg_birthdate, r.birthplace_city, r.mother_tongue, r.ip_ethnicity, r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last
                       FROM enrollments e 
                       LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.lrn))
                       WHERE e.student_id = ? ORDER BY e.school_year DESC LIMIT 1");
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if (!$student) die("Student record not found in enrollments.");

// History with Advisers
$stmt = $pdo->prepare("SELECT e.*, 
                              COALESCE(
                                CONCAT(t.first_name, ' ', t.last_name), 
                                CONCAT(u.first_name, ' ', u.last_name),
                                CONCAT(u2.first_name, ' ', u2.last_name),
                                'N/A'
                              ) as adviser_name 
                       FROM enrollments e 
                       LEFT JOIN position_assignments pa ON (e.grade_level = pa.grade_level AND e.section = pa.section AND e.school_year = pa.school_year AND pa.position_type = 'class_adviser')
                       LEFT JOIN teachers t ON pa.employee_id = t.id
                       LEFT JOIN users u ON pa.user_id = u.id
                       LEFT JOIN sections s ON (e.grade_level = s.grade_level AND e.section = s.section_name AND e.school_year = s.school_year)
                       LEFT JOIN users u2 ON s.adviser_id = u2.id
                       WHERE e.student_id = ? ORDER BY e.grade_level ASC");
$stmt->execute([$student_id]);
$history = $stmt->fetchAll();

// Grades (Historical)
$stmt = $pdo->prepare("SELECT g.*, s.subject_name 
                       FROM sf9_grades g 
                       JOIN curriculum s ON g.subject_id = s.id 
                       WHERE g.student_id = ? 
                       ORDER BY g.school_year, s.subject_name");
$stmt->execute([$student_id]);
$all_grades = $stmt->fetchAll();

$grades_by_year = [];
foreach ($all_grades as $g) { $grades_by_year[$g['school_year']][] = $g; }

// Attendance (Aggregated from SF2 Reports) - Broadened matching
$stmt = $pdo->prepare("SELECT r.school_year, SUM(s.total_present) as days_present, SUM(s.total_absent) as days_absent, SUM(r2.days_of_classes) as total_days
                       FROM sf2_student_records s
                       JOIN sf2_reports r ON s.sf2_report_id = r.id
                       LEFT JOIN sf2_monthly_summary r2 ON r.id = r2.sf2_report_id
                       WHERE (s.student_id = ? OR s.student_id = ? OR s.student_name = ?)
                       GROUP BY r.school_year
                       ORDER BY r.school_year ASC");
$stmt->execute([$student_id, $student['lrn'] ?? '', ($student['first_name'] . ' ' . $student['last_name'])]);
$attendance = $stmt->fetchAll();
$att_by_year = [];
foreach ($attendance as $a) { $att_by_year[$a['school_year']] = $a; }

// Conduct
$stmt = $pdo->prepare("SELECT * FROM conduct_records WHERE student_id = ? ORDER BY school_year ASC");
$stmt->execute([$student_id]);
$conduct = $stmt->fetchAll();
$conduct_by_year = [];
foreach ($conduct as $c) { $conduct_by_year[$c['school_year']] = $c; }

// Transfer History
$stmt = $pdo->prepare("SELECT * FROM transfer_records WHERE student_id = ? ORDER BY date_of_transfer ASC");
$stmt->execute([$student_id]);
$transfers = $stmt->fetchAll();

// SF10 Meta & Eligibility
$stmt = $pdo->prepare("SELECT s.*, 
                              COALESCE(CONCAT(t.first_name, ' ', t.last_name), u.username) as verifier_name
                       FROM sf10_records s
                       LEFT JOIN users u ON s.verified_by = u.id
                       LEFT JOIN teachers t ON u.id = t.user_id
                       WHERE s.student_id = ?");
$stmt->execute([$student_id]);
$sf10_meta = $stmt->fetch();

// Settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch()) $settings[$row['setting_key']] = $row['setting_value'];

// Export Logic (PDF)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // ... PDF generation logic (simplified here for brevity, usually uses a helper)
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF10 | <?= htmlspecialchars($student['student_name']) ?></title>
    <style>
        @page { size: 8.5in 13in; margin: 0.4in; }
        
        /* SCREEN STYLES (Document Viewer) */
        body { 
            font-family: 'Arial', sans-serif; 
            font-size: 10px; 
            line-height: 1.2; 
            background: #f1f5f9; /* Soft gray background for screen */
            color: #000; 
            margin: 0; 
            padding: 0; 
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .no-print-toolbar { 
            position: sticky; 
            top: 0; 
            width: 100%;
            background: rgba(255, 255, 255, 0.95); 
            padding: 14px 40px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); 
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            box-sizing: border-box;
        }
        
        .toolbar-brand { display: flex; align-items: center; gap: 14px; font-weight: 800; color: #1e293b; font-size: 16px; letter-spacing: -0.02em; }
        .toolbar-brand i { 
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: white; 
            padding: 10px;
            border-radius: 12px;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        
        .toolbar-actions { display: flex; gap: 16px; }
        .btn-print { 
            background: linear-gradient(135deg, #2563eb, #1d4ed8); 
            color: white; 
            padding: 10px 24px; 
            border-radius: 10px; 
            font-weight: 700; 
            border: none; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 10px;
            font-size: 13px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .btn-print:hover { 
            background: linear-gradient(135deg, #1d4ed8, #1e40af); 
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
        }
        .btn-print:active { transform: translateY(0); }
        
        .btn-back { 
            background: white; 
            color: #475569; 
            padding: 10px 20px; 
            border-radius: 10px; 
            font-weight: 600; 
            border: 1px solid #e2e8f0; 
            text-decoration: none;
            display: flex; 
            align-items: center; 
            gap: 10px;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        .btn-back:hover { 
            background: #f8fafc; 
            border-color: #cbd5e1;
            color: #1e293b;
            transform: translateY(-2px);
        }

        .paper-container { 
            background: white; 
            width: 8.5in; 
            min-height: 13in; 
            padding: 0.5in; 
            margin: 40px auto; 
            box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            position: relative;
            box-sizing: border-box;
        }

        /* PRINT STYLES */
        @media print { 
            body { background: #fff !important; padding: 0 !important; display: block !important; }
            .no-print-toolbar { display: none !important; } 
            .paper-container { 
                margin: 0 !important; 
                padding: 0 !important; 
                box-shadow: none !important; 
                width: 100% !important; 
                min-height: auto !important;
            }
            .watermark { opacity: 0.1 !important; }
        }

        /* OFFICIAL SF10 STYLING (IMAGE-MATCHED) */
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; }
        .sf-label { font-size: 8px; font-weight: bold; }
        
        .main-header { text-align: center; flex: 1; }
        .main-header p { margin: 0; font-size: 8px; }
        .main-header h1 { margin: 2px 0; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        
        .header-logo { height: 45px; width: auto; }

        .form-section { border: 1px solid #000; margin-top: 10px; }
        .section-header { background: #d1d5db; color: #000; text-align: center; font-weight: 800; font-size: 9px; padding: 2px; border-bottom: 1px solid #000; text-transform: uppercase; }
        
        .data-row { display: flex; border-bottom: 1px solid #000; }
        .data-row:last-child { border-bottom: none; }
        .data-cell { padding: 3px 5px; border-right: 1px solid #000; flex: 1; }
        .data-cell:last-child { border-right: none; }
        
        .label-sm { font-size: 7px; font-weight: bold; display: block; text-transform: uppercase; margin-bottom: 1px; }
        .val-sm { font-size: 9px; font-weight: 700; }

        .eligibility-grid { display: grid; grid-template-columns: repeat(4, 1fr); border-top: 1px solid #000; }
        .checkbox-item { display: flex; align-items: center; gap: 5px; font-size: 8px; font-weight: bold; }
        .box { width: 10px; height: 10px; border: 1px solid #000; flex-shrink: 0; }

        .scholastic-table { width: 100%; border-collapse: collapse; margin-top: -1px; }
        .scholastic-table th, .scholastic-table td { border: 1px solid #000; padding: 2px 4px; font-size: 8px; text-align: center; }
        .scholastic-table .text-left { text-align: left; }
        .scholastic-table .bg-gray { background: #f3f4f6; }
        
        .remedial-section { margin-top: 5px; border: 1px solid #000; }
        .remedial-header { display: flex; font-size: 7px; font-weight: bold; padding: 2px; border-bottom: 1px solid #000; gap: 20px; }

        .cert-footer { margin-top: 20px; padding: 10px; border: 1px solid #000; }
        .cert-main-area { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-top: 20px; }
        .footer-sigs { flex: 1; display: grid; grid-template-columns: 1fr 2fr; gap: 40px; text-align: center; }
        .seal-box { width: 90px; height: 90px; border: 1px dashed #999; display: flex; align-items: center; justify-content: center; font-size: 7px; color: #999; text-align: center; padding: 5px; }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="no-print-toolbar">
        <div class="toolbar-brand">
            <i class="fa fa-file-invoice"></i>
            <span>SF10 Print Preview</span>
        </div>
        <div class="toolbar-actions">
            <a href="sf10_form.php?student_id=<?= $student_id ?>" class="btn-back">
                <i class="fa fa-arrow-left"></i> Back to Form
            </a>
            <button onclick="window.print()" class="btn-print">
                <i class="fa fa-print"></i> Print Document
            </button>
        </div>
    </div>    <div class="paper-container">
        <div class="watermark">OFFICIAL DepED SF10</div>

        <div class="page-header">
            <div class="sf-label">SF10-JHS</div>
            <div class="main-header">
                <p>Republic of the Philippines</p>
                <p>Department of Education</p>
                <h1>Learner's Permanent Academic Record for Junior High School (SF10-JHS)</h1>
                <p style="font-style:italic;">(Formerly Form 137)</p>
            </div>
            <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="header-logo" style="margin-left: -50px;">
        </div>

        <div class="form-section">
            <div class="section-header">Learner's Information</div>
            <div class="data-row">
                <div class="data-cell" style="flex: 1.5;"><span class="label-sm">LRN:</span><span class="val-sm"><?= $student['lrn'] ?></span></div>
                <div class="data-cell" style="flex: 2;"><span class="label-sm">Last Name:</span><span class="val-sm"><?= strtoupper($student['last_name']) ?></span></div>
                <div class="data-cell" style="flex: 2;"><span class="label-sm">First Name:</span><span class="val-sm"><?= strtoupper($student['first_name']) ?></span></div>
                <div class="data-cell" style="flex: 2;"><span class="label-sm">Middle Name:</span><span class="val-sm"><?= strtoupper($student['middle_name']) ?></span></div>
            </div>
            <div class="data-row">
                <div class="data-cell"><span class="label-sm">Date of Birth (MM/DD/YYYY):</span><span class="val-sm"><?= date('m/d/Y', strtotime($student['birthdate'])) ?></span></div>
                <div class="data-cell"><span class="label-sm">Sex:</span><span class="val-sm"><?= strtoupper($student['sex']) ?></span></div>
                <div class="data-cell" style="flex: 2;"><span class="label-sm">Birthplace:</span><span class="val-sm"><?= strtoupper($student['place_of_birth'] ?? 'N/A') ?></span></div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-header">Eligibility for JHS Enrolment</div>
            <div class="data-row">
                <div class="data-cell" style="flex: 1.5;">
                    <div class="checkbox-item"><div class="box" style="<?= !empty($sf10_meta['elem_school_name']) ? 'background:#000;' : '' ?>"></div> Elementary School Completer</div>
                </div>
                <div class="data-cell"><span class="label-sm">Gen. Average:</span><span class="val-sm"><?= $sf10_meta['elem_gen_avg'] ?? 'N/A' ?></span></div>
                <div class="data-cell" style="flex: 2;"><span class="label-sm">Citation: (if any)</span><span class="val-sm"><?= strtoupper($sf10_meta['elem_citation'] ?? 'Elementary School Diploma') ?></span></div>
            </div>
            <div class="data-row">
                <div class="data-cell" style="flex: 2;"><span class="label-sm">Name of Elementary School:</span><span class="val-sm"><?= strtoupper($sf10_meta['elem_school_name'] ?? $student['last_school_attended'] ?? 'N/A') ?></span></div>
                <div class="data-cell"><span class="label-sm">School ID:</span><span class="val-sm"><?= $sf10_meta['elem_school_id'] ?? $student['last_school_id'] ?? 'N/A' ?></span></div>
                <div class="data-cell" style="flex: 2;"><span class="label-sm">Address of School:</span><span class="val-sm"><?= strtoupper($sf10_meta['elem_school_address'] ?? 'N/A') ?></span></div>
            </div>
            <div class="data-row" style="border-top: 1px solid #000;">
                <div class="data-cell">
                    <div class="checkbox-item"><div class="box" style="<?= !empty($sf10_meta['pept_rating']) ? 'background:#000;' : '' ?>"></div> PEPT Passer</div>
                    <span class="label-sm">Rating: <?= $sf10_meta['pept_rating'] ?? '_____' ?></span>
                </div>
                <div class="data-cell">
                    <div class="checkbox-item"><div class="box" style="<?= !empty($sf10_meta['ae_rating']) ? 'background:#000;' : '' ?>"></div> ALS A&E Passer</div>
                    <span class="label-sm">Rating: <?= $sf10_meta['ae_rating'] ?? '_____' ?></span>
                </div>
                <div class="data-cell" style="flex: 1.5;"><span class="label-sm">Others (Specify):</span><span class="val-sm"><?= strtoupper($sf10_meta['elem_others'] ?? '__________') ?></span></div>
            </div>
        </div>

        <div class="form-section" style="border:none;">
            <div class="section-header">Scholastic Record</div>
            <?php foreach ($history as $h): 
                $sy = $h['school_year'];
                $sy_grades = $grades_by_year[$sy] ?? [];
            ?>
                <div class="scholastic-block" style="border: 1px solid #000; margin-bottom: 10px;">
                    <div class="data-row bg-gray">
                        <div class="data-cell"><span class="label-sm">School:</span><span class="val-sm"><?= strtoupper($settings['school_name']) ?></span></div>
                        <div class="data-cell"><span class="label-sm">School ID:</span><span class="val-sm"><?= $settings['school_id'] ?? '300750' ?></span></div>
                        <div class="data-cell"><span class="label-sm">District:</span><span class="val-sm">MALOLOS CITY</span></div>
                        <div class="data-cell"><span class="label-sm">Division:</span><span class="val-sm">MALOLOS CITY</span></div>
                        <div class="data-cell"><span class="label-sm">Region:</span><span class="val-sm">REGION III</span></div>
                    </div>
                    <div class="data-row">
                        <div class="data-cell"><span class="label-sm">Classified as Grade:</span><span class="val-sm"><?= $h['grade_level'] ?></span></div>
                        <div class="data-cell"><span class="label-sm">Section:</span><span class="val-sm"><?= $h['section'] ?></span></div>
                        <div class="data-cell"><span class="label-sm">School Year:</span><span class="val-sm"><?= $sy ?></span></div>
                        <div class="data-cell" style="flex: 2;"><span class="label-sm">Name of Adviser/Teacher:</span><span class="val-sm"><?= strtoupper($h['adviser_name'] ?? 'N/A') ?></span></div>
                        <div class="data-cell"><span class="label-sm">Signature:</span><span class="val-sm">__________</span></div>
                    </div>
                    
                    <table class="scholastic-table">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width: 40%;">LEARNING AREAS</th>
                                <th colspan="4">Quarterly Rating</th>
                                <th rowspan="2">Final Rating</th>
                                <th rowspan="2">Remarks</th>
                            </tr>
                            <tr>
                                <th>1</th><th>2</th><th>3</th><th>4</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $subjects = ['Filipino', 'English', 'Mathematics', 'Science', 'Araling Panlipunan (AP)', 'Edukasyon sa Pagpapakatao (EsP)', 'Technology and Livelihood Education (TLE)'];
                            foreach($subjects as $subj):
                                $g = array_values(array_filter($sy_grades, function($gr) use ($subj) { return strpos($gr['subject_name'], $subj) !== false; }))[0] ?? null;
                            ?>
                                <tr>
                                    <td class="text-left"><?= $subj ?></td>
                                    <td><?= $g ? round($g['q1']) : '' ?></td><td><?= $g ? round($g['q2']) : '' ?></td>
                                    <td><?= $g ? round($g['q3']) : '' ?></td><td><?= $g ? round($g['q4']) : '' ?></td>
                                    <td><?= $g ? round($g['final_grade']) : '' ?></td>
                                    <td><?= $g ? ($g['final_grade'] >= 75 ? 'PASSED' : 'FAILED') : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-gray"><td class="text-left" colspan="7">MAPEH</td></tr>
                            <?php foreach(['Music', 'Arts', 'Physical Education', 'Health'] as $mapeh): 
                                $g = array_values(array_filter($sy_grades, function($gr) use ($mapeh) { return strpos($gr['subject_name'], $mapeh) !== false; }))[0] ?? null;
                            ?>
                                <tr>
                                    <td class="text-left" style="padding-left: 15px;"><?= $mapeh ?></td>
                                    <td><?= $g ? round($g['q1']) : '' ?></td><td><?= $g ? round($g['q2']) : '' ?></td>
                                    <td><?= $g ? round($g['q3']) : '' ?></td><td><?= $g ? round($g['q4']) : '' ?></td>
                                    <td><?= $g ? round($g['final_grade']) : '' ?></td>
                                    <td><?= $g ? ($g['final_grade'] >= 75 ? 'PASSED' : 'FAILED') : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: bold;">
                                <td colspan="5" style="text-align: right;">General Average</td>
                                <td></td><td></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="remedial-section">
                        <div class="remedial-header">
                            <span>Remedial Classes</span>
                            <span>Conducted from: __________ to __________</span>
                        </div>
                        <table class="scholastic-table" style="border:none;">
                            <tr>
                                <th>Learning Areas</th><th>Final Rating</th><th>Remedial Class Mark</th><th>Recomputed Final Grade</th><th>Remarks</th>
                            </tr>
                            <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cert-footer">
            <div class="section-header" style="background:none; border-bottom:none;">Certification</div>
            <?php 
                $latest_h = end($history);
                $grade_val = $latest_h['grade_level'] ?? '';
                preg_match('/\d+/', $grade_val, $matches);
                $current_grade_num = isset($matches[0]) ? (int)$matches[0] : 0;
                $next_grade = $current_grade_num > 0 ? ($current_grade_num + 1) : '___';
                $last_sy = $latest_h ? $latest_h['school_year'] : '__________';
            ?>
            <p style="font-size: 10px; line-height: 1.8; margin: 0; text-align: justify;">
                I certify that this is a true record of <strong><?= strtoupper($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']) ?></strong>, with LRN <strong><?= $student['lrn'] ?></strong> and that he/she is eligible for admission to Grade <strong><?= $next_grade ?></strong>.<br>
                Name of School: <strong><?= strtoupper($settings['school_name'] ?? 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY') ?></strong> &nbsp;&nbsp;&nbsp;&nbsp; School ID: <strong><?= $settings['school_id'] ?? '300750' ?></strong> &nbsp;&nbsp;&nbsp;&nbsp; Last School Year Attended: <strong><?= $last_sy ?></strong>
            </p>
            <div class="cert-main-area">
                <div class="footer-sigs" style="grid-template-columns: 1fr 2fr; gap: 40px;">
                    <div class="sig-box">
                        <div class="sig-line"><?= $sf10_meta['verified_at'] ? date('m/d/Y', strtotime($sf10_meta['verified_at'])) : date('m/d/Y') ?></div>
                        <div class="sig-sub">Date</div>
                    </div>
                    <div class="sig-box">
                        <div class="sig-line" style="border-bottom: 1px solid #000; font-weight: bold; text-transform: uppercase;">
                            <?php 
                                $principal_name = get_system_setting($pdo, 'principal_name', 'School Head'); 
                                echo strtoupper($principal_name);
                            ?>
                        </div>
                        <div class="sig-sub">Name of Principal/School Head</div>
                    </div>
                </div>
                <div class="seal-box">(Official Seal Here)</div>
            </div>
        </div>
</body>
</html>