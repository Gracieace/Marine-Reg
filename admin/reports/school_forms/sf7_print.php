<?php
/**
 * SF7 PRINT VERSION - Official DepEd Template (High Fidelity Actual Layout)
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';

try {
    $pdo = db_connect();
    auth_require_role(['admin', 'registrar', 'teacher']);
    
    $school_year = $_GET['school_year'] ?? get_active_school_year($pdo);

    // Official Assets - Verified High Resolution
    $assets = [
        'deped_seal' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/f3/Seal_of_the_Department_of_Education_of_the_Philippines.svg/1200px-Seal_of_the_Department_of_Education_of_the_Philippines.svg.png',
        'deped_logo' => 'https://www.deped.gov.ph/wp-content/uploads/2019/01/deped-logo.png' // Direct official site logo
    ];

    // Fetch Settings with Standardized Keys
    $settings = [
        'school_id' => '300750', 
        'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
        'region' => 'REGION III', 
        'division' => 'MALOLOS CITY', 
        'district' => 'MALOLOS SOUTH',
        'school_head' => '', 
        'registrar_name' => ''
    ];
    
    $keys = ['region', 'division', 'district', 'school_name', 'school_id', 'signatory_registrar', 'sf_region', 'sf_division'];
    $placeholders = str_repeat('?,', count($keys) - 1) . '?';
    $stmt_set = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt_set->execute($keys);
    
    $db_settings = [];
    while ($s = $stmt_set->fetch()) {
        $db_settings[$s['setting_key']] = $s['setting_value'];
    }

    // Apply DB values with appropriate fallbacks
    foreach (['school_id', 'school_name', 'district'] as $k) {
        if (!empty($db_settings[$k])) $settings[$k] = $db_settings[$k];
    }

    // Branding Prioritization (DepEd Form Settings)
    $settings['region'] = $db_settings['sf_region'] ?? $db_settings['region'] ?? 'REGION III';
    $settings['division'] = $db_settings['sf_division'] ?? $db_settings['division'] ?? 'MALOLOS CITY';

    // Signatory Mapping: Prioritize standardized 'principal_name'
    $settings['school_head'] = get_system_setting($pdo, 'principal_name', 'DR. MARIA SANTOS');
    $settings['registrar_name'] = $db_settings['signatory_registrar'] ?? 'MS. ANA CRUZ';

    // Aggregate Data
    $stmt = $pdo->prepare("
        SELECT u.*, pd.years_in_service, pd.role_function, pd.remarks
        FROM users u
        LEFT JOIN sf7_personnel_data pd ON (pd.user_id = u.id)
        WHERE u.approval_status = 'approved' AND u.user_status = 'active'
        ORDER BY FIELD(u.role, 'admin', 'registrar', 'teacher') ASC, u.last_name ASC
    ");
    $stmt->execute();
    $all_personnel = $stmt->fetchAll();

    // Summary Calculations
    $summary_a = []; $summary_b = []; $summary_c = [];
    foreach ($all_personnel as $p) {
        $title = $p['position_title'] ?: ucwords($p['role']);
        $fund = $p['fund_source'] ?: 'National';
        if ($fund === 'National') {
            if ($p['role'] === 'teacher') $summary_a[$title] = ($summary_a[$title] ?? 0) + 1;
            else $summary_b[$title] = ($summary_b[$title] ?? 0) + 1;
        } else {
            $summary_c[$title][] = ['appt' => $p['appointment_status'] ?: 'Permanent', 'fund' => $fund, 'is_teaching' => ($p['role'] === 'teacher')];
        }
    }

} catch (Exception $e) { die("Print Error: " . $e->getMessage()); }

function getTeacherAssignments($pdo, $uid, $sy) {
    $list = [];
    $stmt = $pdo->prepare("SELECT grade_level, section_name FROM sections WHERE adviser_id = ? AND school_year = ?");
    $stmt->execute([$uid, $sy]);
    if ($adv = $stmt->fetch()) $list[] = ['sub' => 'Class Advisory', 'gs' => $adv['grade_level'].'-'.$adv['section_name']];

    $stmt = $pdo->prepare("SELECT s.subject_name, s.grade_level, sec.section_name FROM subject_teachers st JOIN subjects s ON st.subject_id = s.id LEFT JOIN sections sec ON st.section_id = sec.id WHERE st.teacher_id = ? AND st.school_year = ?");
    $stmt->execute([$uid, $sy]);
    while ($r = $stmt->fetch()) $list[] = ['sub' => $r['subject_name'], 'gs' => $r['grade_level'].'-'.$r['section_name']];
    return $list;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official SF7 - Branding Calibrated</title>
    <style>
        @page { size: 13in 8.5in landscape; margin: 0; }
        
        body { 
            font-family: 'Arial Narrow', Arial, sans-serif; 
            margin: 0; 
            padding: 0; 
            background: #e5e7eb; 
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        /* Executive Toolset */
        .toolbar {
            position: sticky; top: 0; width: 100%; background: #111827; color: white; padding: 12px 40px; 
            display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.4); z-index: 2000;
        }
        .btn-action { 
            background: #1f2937; color: white; border: 1px solid #374151; padding: 10px 24px; border-radius: 6px; 
            font-size: 10pt; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 10px; transition: all 0.2s; font-weight: bold;
        }
        .btn-action:hover { background: #374151; transform: translateY(-1px); }
        .btn-print { background: #3b82f6; border-color: #60a5fa; }
        .btn-print:hover { background: #2563eb; }

        .paper-view { 
            width: 13.2in; 
            padding: 0.4in; 
            box-sizing: border-box; 
            position: relative; 
            background: white;
            margin: 30px 0;
            box-shadow: 0 15px 70px rgba(0,0,0,0.15);
        }

        @media print {
            body { background: white; padding: 0; }
            .toolbar { display: none !important; }
            .paper-view { width: 100%; padding: 0.2in; margin: 0; box-shadow: none; }
        }

        /* Branding UI Correction */
        .header-top-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; }
        .seal-left-side { width: 90px; padding-top: 5px; }
        .logo-right-side { width: 160px; text-align: right; }
        .branding-img { width: 100%; height: auto; object-fit: contain; }
        
        .title-container { text-align: center; flex: 1; padding-top: 15px; }
        .title-container h1 { font-size: 16pt; font-weight: 900; margin: 0; letter-spacing: -0.2px; }
        .title-container p { font-size: 8.5pt; font-style: italic; margin: 2px 0 10px; width: 68%; margin-left: 16%; line-height: 1.1; }

        .meta-table { width: 100%; border-collapse: collapse; font-size: 10.5pt; margin-bottom: 12px; }
        .meta-td-label { text-align: right; padding-right: 8px; width: 95px; }
        .meta-td-val { border: 1px solid #000; text-align: center; font-weight: bold; width: 230px; height: 22px; background: #fff; }

        /* Summaries */
        .sum-row { display: grid; grid-template-columns: 1fr 1fr 1.5fr; gap: 10px; margin-bottom: 15px; }
        .sum-container { border: 1.5px solid #000; font-size: 8pt; }
        .sum-container h3 { margin: 0; padding: 4px; font-size: 8.5pt; border-bottom: 1.5px solid #000; text-align: center; height: 35px; display: flex; align-items: center; justify-content: center; font-weight: 900; }
        .summary-tbl { width: 100%; border-collapse: collapse; }
        .summary-tbl th, .summary-tbl td { border: 1px solid #000; padding: 2.5px; text-align: center; }
        .summary-tbl th { font-weight: normal; font-size: 7.2pt; height: 42px; line-height: 1.2; }

        /* Detail Matrix */
        .personnel-table { width: 100%; border-collapse: collapse; font-size: 7.5pt; table-layout: fixed; border: 2.5px solid #000; }
        .personnel-table th, .personnel-table td { border: 1px solid #000; padding: 0; text-align: center; vertical-align: middle; }
        .personnel-table th { height: 50px; font-weight: 900; padding: 2px; }

        .col-tin { width: 55px; }
        .col-name { width: 145px; }
        .col-sex { width: 25px; }
        .col-fund { width: 48px; }
        .col-pos { width: 95px; }
        .col-appt { width: 78px; }
        .col-educ { width: 90px; }
        .col-sub { width: 180px; }
        .col-prog { width: 160px; }
        .col-rem { width: 100px; }

        /* Row Sync */
        .load-block { display: flex; flex-direction: column; width: 100%; height: 100%; }
        .load-item { border-bottom: 1px solid #000; min-height: 18px; padding: 2.5px 5px; display: flex; align-items: center; text-align: left; flex: 1; box-sizing: border-box; font-size: 7pt; }
        .load-item:last-child { border-bottom: none; }

        .prog-grid-sync { display: grid; grid-template-columns: 35px 40px 40px 1fr; height: 100%; width: 100%; border-left: 2px solid #000; }
        .prog-unit-box { border-right: 1px solid #000; border-bottom: 1px solid #000; display: flex; align-items: center; justify-content: center; min-height: 18px; font-size: 7pt; }
        .prog-unit-box:last-child { border-right: none; }
        .ave-minutes-footer { border-top: 2px solid #000; height: 20px; display: flex; align-items: center; justify-content: flex-end; padding-right: 40px; font-weight: 900; font-size: 7.5pt; }

        .footer-wrap { display: flex; justify-content: space-between; margin-top: 20px; font-size: 8.5pt; align-items: flex-start; }
        .guidelines-col { width: 65%; line-height: 1.4; }
        .signature-col { width: 340px; text-align: center; }
        .signature-line-name { border-bottom: 2px solid #000; margin-top: 30px; font-weight: 900; font-size: 12pt; text-transform: uppercase; }

    </style>
</head>
<body>
    <div class="toolbar no-print">
        <div style="display:flex; align-items:center; gap:25px;">
            <a href="sf7.php" class="btn-action">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                EXIT TO DASHBOARD
            </a>
            <div style="font-size:13pt; font-weight:900; letter-spacing:0.5px; color: #60a5fa;">SF7 PRINTOUT GENERATOR</div>
        </div>
        <button class="btn-action btn-print" onclick="window.print()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
            PRINT OFFICIAL SF7 FORM
        </button>
    </div>

    <div class="paper-view">
        <div class="header-top-row">
            <div class="seal-left-side"><img src="<?= $assets['deped_seal'] ?>" class="branding-img" alt="Official Seal"></div>
            <div class="title-container">
                <h1>School Form 7 (SF7) School Personnel Assignment List and Basic Profile</h1>
                <p>(This replaced Form 12-Monthly Status Report for Teachers, Form 19-Assignment List, Form 29-Teacher Program and Form 31-Summary Information of Teachers)</p>
            </div>
            <div class="logo-right-side"><img src="<?= $assets['deped_logo'] ?>" class="branding-img" alt="Official Logo"></div>
        </div>

        <table class="meta-table">
            <tr>
                <td class="meta-td-label">School ID</td><td class="meta-td-val"><?= $settings['school_id'] ?></td>
                <td class="meta-td-label">Region</td><td class="meta-td-val"><?= $settings['region'] ?></td>
                <td class="meta-td-label">Division</td><td class="meta-td-val"><?= $settings['division'] ?></td>
            </tr>
            <tr>
                <td class="meta-td-label">School Name</td><td class="meta-td-val" style="width:300px;"><?= $settings['school_name'] ?></td>
                <td class="meta-td-label">District</td><td class="meta-td-val"><?= $settings['district'] ?></td>
                <td class="meta-td-label">School Year</td><td class="meta-td-val"><?= $school_year ?></td>
            </tr>
        </table>

        <div class="sum-row">
            <div class="sum-container">
                <h3>(A) Nationally-Funded Teaching & Teaching Related Items</h3>
                <table class="summary-tbl">
                    <tr><th style="width:72%;">Title of Plantilla Position<br>(as appeared in the appointment document/PSIPOP)</th><th>Number of<br>Incumbent</th></tr>
                    <?php foreach ($summary_a as $t => $q): ?>
                        <tr><td><?= $t ?></td><td><?= $q ?></td></tr>
                    <?php endforeach; for($i=count($summary_a); $i<3; $i++) echo "<tr><td>&nbsp;</td><td>&nbsp;</td></tr>"; ?>
                </table>
            </div>
            <div class="sum-container">
                <h3>(B) Nationally-Funded Non Teaching Items</h3>
                <table class="summary-tbl">
                    <tr><th style="width:72%;">Title of Plantilla Position<br>(as appeared in the appointment document/PSIPOP)</th><th>Number of<br>Incumbent</th></tr>
                    <?php foreach ($summary_b as $t => $q): ?>
                        <tr><td><?= $t ?></td><td><?= $q ?></td></tr>
                    <?php endforeach; for($i=count($summary_b); $i<3; $i++) echo "<tr><td>&nbsp;</td><td>&nbsp;</td></tr>"; ?>
                </table>
            </div>
            <div class="sum-container">
                <h3>(C ) Other Appointments and Funding Sources</h3>
                <table class="summary-tbl">
                    <tr>
                        <th style="width:115px;">Title of Designation<br>(Teacher, Clerk, etc.)</th>
                        <th>Appointment<br>(Contractual, etc.)</th>
                        <th>Fund Source<br>(SEF, etc.)</th>
                        <th colspan="2">Number of Incumbent<br><small>T | NT</small></th>
                    </tr>
                    <?php foreach ($summary_c as $t => $its): 
                        $tc=0; $ntc=0; foreach($its as $it) if($it['is_teaching']) $tc++; else $ntc++; ?>
                        <tr><td><?= $t ?></td><td><?= $its[0]['appt'] ?></td><td><?= $its[0]['fund'] ?></td><td><?= $tc?:'' ?></td><td><?= $ntc?:'' ?></td></tr>
                    <?php endforeach; for($i=count($summary_c); $i<3; $i++) echo "<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>"; ?>
                </table>
            </div>
        </div>

        <table class="personnel-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-tin">Employee No.<br>(or T.I.N.)</th>
                    <th rowspan="2" class="col-name">Name of School Personnel<br>(Arrange by Position, Descending)</th>
                    <th rowspan="2" class="col-sex">Sex</th>
                    <th rowspan="2" class="col-fund">Fund Source</th>
                    <th rowspan="2" class="col-pos">Position / Designation</th>
                    <th rowspan="2" class="col-appt">Nature of Appt / Employment Status</th>
                    <th colspan="3">EDUCATIONAL QUALIFICATION</th>
                    <th rowspan="2" class="col-sub">Subject Taught (include Grade & Section), Advisory Class & Other Ancillary Assignment</th>
                    <th colspan="4">* Daily Program (time duration)</th>
                    <th rowspan="2" class="col-rem">Remark/s</th>
                </tr>
                <tr>
                    <th style="width:75px;">Degree / Post Graduate</th>
                    <th style="width:75px;">Major Specialization</th>
                    <th style="width:65px;">Minor</th>
                    <th style="width:35px;">DAY<br>(M/T/W/TH/F)</th>
                    <th style="width:40px;">From<br>(00:00)</th>
                    <th style="width:40px;">To<br>(00:00)</th>
                    <th style="width:45px;">Total Minutes<br>per Week</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_personnel as $p): 
                    $assigns = getTeacherAssignments($pdo, $p['id'], $school_year);
                    $row_count = max(count($assigns), 8);
                    
                    $sex_code = '';
                    if(!empty($p['sex'])){
                        $s=strtoupper($p['sex']);
                        if($s==='MALE') $sex_code='M'; elseif($s==='FEMALE') $sex_code='F'; else $sex_code=substr($s,0,1);
                    }
                ?>
                <tr>
                    <td style="padding:4px;"><?= $p['tin'] ?: '--' ?></td>
                    <td style="text-align:left; font-weight:bold; padding:4px;"><?= strtoupper($p['last_name'].', '.$p['first_name'].' '.substr($p['middle_name'],0,1).'.') ?></td>
                    <td><?= $sex_code ?></td>
                    <td><?= $p['fund_source'] ?: 'Natl' ?></td>
                    <td><?= $p['position_title'] ?: ucwords($p['role']) ?></td>
                    <td><?= $p['appointment_status'] ?: 'Perm' ?></td>
                    <td><?= $p['educational_degree'] ?: '--' ?></td>
                    <td><?= $p['major_specialization'] ?: '--' ?></td>
                    <td><?= $p['minor_specialization'] ?: '--' ?></td>
                    
                    <td colspan="5" style="border: none; vertical-align: top;">
                        <div style="display: grid; grid-template-columns: 180px 1fr; height: 100%;">
                            <div class="load-block">
                                <?php for($i=0; $i<$row_count; $i++): ?>
                                    <div class="load-item">
                                        <?= isset($assigns[$i]) ? $assigns[$i]['sub'].' ('.$assigns[$i]['gs'].')' : '&nbsp;' ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <div class="load-block">
                                <?php for($i=0; $i<$row_count; $i++): ?>
                                    <div class="prog-grid-sync">
                                        <div class="prog-unit-box"><?= isset($assigns[$i])?'M-F':'' ?>&nbsp;</div>
                                        <div class="prog-unit-box">&nbsp;</div>
                                        <div class="prog-unit-box">&nbsp;</div>
                                        <div class="prog-unit-box" style="border-right: none;">&nbsp;</div>
                                    </div>
                                <?php endfor; ?>
                                <div class="ave-minutes-footer">Ave. Minutes per Day</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding:4px; font-size:7pt; text-align: left; vertical-align: top;"><?= $p['remarks'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer-wrap">
            <div class="guidelines-col">
                <strong>GUIDELINES:</strong><br>
                1. This form shall be accomplished at the beginning of the school year by the school head. In case of movement of teachers and other personnel during SY, updated Form 19 must be submitted to the Division Office.<br>
                2. All school personnel, regardless of position/nature of appointment should be included in this form and should be listed from the highest rank down to the lowest. This form shall also serve as inventory list of school personnel.<br>
                3. Please reflect subjects being taught and if teacher handling advisory class or Ancillary Assignment. Other administrative duties must also reported.<br>
                4. * Daily Program Column is for teaching personnel only.
            </div>
            <div class="signature-col">
                <div style="text-align:left; font-size:9pt; margin-bottom:5px;">Submitted by:</div>
                <div class="signature-line-name"><?= strtoupper($settings['school_head']) ?></div>
                <div style="font-size:8.5pt;">(Signature of School Head over Printed Name)</div>
                <div style="margin-top:20px; font-size:9pt; text-align:left;">Updated as of: <span style="text-decoration:underline;"><?= date('F d, Y') ?></span></div>
                <div style="margin-top:12px; font-size:8pt; text-align:right;">School Form 7, Page 2 of ____</div>
            </div>
        </div>
    </div>
</body>
</html>
