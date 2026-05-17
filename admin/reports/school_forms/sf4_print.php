<?php
/**
 * SF4 Official Print Layout
 * Optimized for Legal Landscape Printing
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/sf4_logic.php'; // Use the shared generateSF4 function

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();

$school_year = $_GET['school_year'] ?? get_active_school_year($pdo);
$month = $_GET['month'] ?? date('F');
$grade_level = $_GET['grade_level'] ?? '';

// Fetch data using the robust aggregation logic
$reports = generateSF4($pdo, $grade_level, '', $school_year, $month);

// Fetch settings with Standardized Keys
$settings = [
    'school_id' => '300750', 
    'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
    'region' => 'REGION III', 
    'division' => 'MALOLOS CITY', 
    'district' => 'DISTRICT X',
    'school_head' => ''
];

$keys = ['region', 'division', 'district', 'school_name', 'school_id'];
$placeholders = str_repeat('?,', count($keys) - 1) . '?';
$stmt_set = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
$stmt_set->execute($keys);

$db_settings = [];
while ($s = $stmt_set->fetch()) {
    $db_settings[$s['setting_key']] = $s['setting_value'];
}

foreach (['school_id', 'school_name', 'region', 'division', 'district'] as $k) {
    if (!empty($db_settings[$k])) $settings[$k] = $db_settings[$k];
}

$settings['school_head'] = get_system_setting($pdo, 'principal_name', 'DR. MARIA SANTOS');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF4_Print_<?= $month ?>_<?= $school_year ?></title>
    <style>
        @page {
            size: legal landscape;
            margin: 0.3in;
        }
        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            line-height: 1.1;
        }
        .print-container {
            width: 100%;
            max-width: 13.4in;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .header img { height: 50px; }
        .header-text { text-align: center; flex: 1; }
        .header-text h1 { font-size: 14px; margin: 0; font-weight: bold; text-transform: uppercase; }
        .header-text p { font-size: 9px; margin: 1px 0; font-weight: bold; }
        .header-text .sub-caption { font-size: 8px; font-weight: normal; font-style: italic; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 5px;
            font-size: 10px;
            margin-bottom: 10px;
        }
        .info-item { display: flex; gap: 4px; }
        .info-label { font-weight: bold; white-space: nowrap; }
        .info-value { border-bottom: 1px solid #000; flex: 1; padding: 0 4px; min-height: 12px; font-weight: bold; }

        .sf4-table {
            width: 100%;
            border-collapse: collapse;
            border: 1.5px solid #000;
            table-layout: fixed;
        }
        .sf4-table th, .sf4-table td {
            border: 1px solid #000;
            padding: 2px 1px;
            text-align: center;
            font-size: 8.5px;
            word-wrap: break-word;
        }
        .sf4-table th {
            background: #e5e7eb;
            font-weight: bold;
            font-size: 7.5px;
            text-transform: uppercase;
        }
        .text-left { text-align: left !important; padding-left: 3px !important; }
        
        .footer {
            margin-top: 15px;
            display: grid;
            grid-template-columns: 1fr 1fr 1.2fr;
            gap: 30px;
            font-size: 10px;
        }
        .guidelines h3 { font-size: 10px; margin: 0 0 3px 0; text-decoration: underline; font-weight: bold; }
        .guidelines ol { margin: 0; padding-left: 12px; font-size: 9px; }

        .sig-section { text-align: center; }
        .sig-label { text-align: left; margin-bottom: 15px; font-weight: bold; font-size: 9px; }
        .sig-line {
            border-bottom: 1.5px solid #000;
            margin: 0 auto 2px auto;
            font-weight: bold;
            font-size: 11px;
            min-height: 15px;
            width: 85%;
            text-transform: uppercase;
        }
        .sig-title { font-weight: bold; font-size: 9px; text-transform: uppercase; }

        .btn-print-fixed {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            z-index: 1000;
        }
        @media print {
            .btn-print-fixed { display: none; }
            body { -webkit-print-color-adjust: exact; }
            .sf4-table th { background-color: #e5e7eb !important; }
        }
    </style>
</head>
<body>
    <button class="btn-print-fixed" onclick="window.print()">Print This Page</button>

    <div class="print-container">
        <div class="header">
            <img src="<?= url_for('/img/deped_logo.png') ?>" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/thumb/a/af/Department_of_Education_%28DepEd%29_Philippines.svg/1200px-Department_of_Education_%28DepEd%29_Philippines.svg.png'">
            <div class="header-text">
                <p>Republic of the Philippines</p>
                <p>Department of Education</p>
                <h1>School Form 4 (SF4) Monthly Learner's Movement and Attendance</h1>
                <span class="sub-caption">(This replaces Form 3 & STS Form 4-Absenteeism and Dropout Profile)</span>
            </div>
            <img src="<?= url_for('/img/phil_seal.png') ?>" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/thumb/8/84/Coat_of_arms_of_the_Philippines.svg/1200px-Coat_of_arms_of_the_Philippines.svg.png'">
        </div>

        <div class="info-grid">
            <div class="info-item"><span class="info-label">School ID:</span> <span class="info-value"><?= $settings['school_id'] ?></span></div>
            <div class="info-item"><span class="info-label">Region:</span> <span class="info-value"><?= strtoupper($settings['region']) ?></span></div>
            <div class="info-item"><span class="info-label">Division:</span> <span class="info-value"><?= strtoupper($settings['division']) ?></span></div>
            <div class="info-item"><span class="info-label">School Name:</span> <span class="info-value"><?= strtoupper($settings['school_name']) ?></span></div>
            <div class="info-item"><span class="info-label">District:</span> <span class="info-value"><?= strtoupper($settings['district']) ?></span></div>
            <div class="info-item"><span class="info-label">School Year:</span> <span class="info-value"><?= $school_year ?></span></div>
            <div class="info-item" style="grid-column: span 3; justify-content: flex-end;">
                <span class="info-label">Report for the Month of:</span> 
                <span class="info-value" style="flex: 0; min-width: 150px; text-align: center;"><?= strtoupper($month) ?></span>
            </div>
        </div>

        <table class="sf4-table">
            <thead>
                <tr>
                    <th rowspan="3" style="width: 140px;">NAME OF ADVISER</th>
                    <th rowspan="3" style="width: 35px;">GRADE</th>
                    <th rowspan="3" style="width: 75px;">SECTION</th>
                    <th colspan="3">REGISTERED LEARNER</th>
                    <th colspan="6">ATTENDANCE</th>
                    <th colspan="9">DROPPED OUT</th>
                    <th colspan="9">TRANSFERRED OUT</th>
                    <th colspan="9">TRANSFERRED IN</th>
                </tr>
                <tr>
                    <th rowspan="2">M</th><th rowspan="2">F</th><th rowspan="2">T</th>
                    <th colspan="3">Daily Average</th>
                    <th colspan="3">Percentage</th>
                    <th colspan="3">(A) Prev. Month</th>
                    <th colspan="3">(B) For Month</th>
                    <th colspan="3">(A+B) End</th>
                    <th colspan="3">(A) Prev. Month</th>
                    <th colspan="3">(B) For Month</th>
                    <th colspan="3">(A+B) End</th>
                    <th colspan="3">(A) Prev. Month</th>
                    <th colspan="3">(B) For Month</th>
                    <th colspan="3">(A+B) End</th>
                </tr>
                <tr>
                    <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                    <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                    <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                    <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $gt = ['reg_m'=>0,'reg_f'=>0,'reg_t'=>0,'ada_m'=>0,'ada_f'=>0,'ada_t'=>0,'p_drop_m'=>0,'p_drop_f'=>0,'p_drop_t'=>0,'m_drop_m'=>0,'m_drop_f'=>0,'m_drop_t'=>0,'p_tout_m'=>0,'p_tout_f'=>0,'p_tout_t'=>0,'m_tout_m'=>0,'m_tout_f'=>0,'m_tout_t'=>0,'p_tin_m'=>0,'p_tin_f'=>0,'p_tin_t'=>0,'m_tin_m'=>0,'m_tin_f'=>0,'m_tin_t'=>0];
                foreach ($reports as $r): 
                    foreach($gt as $k => $v) if(isset($r[$k])) $gt[$k]+=$r[$k];
                ?>
                <tr>
                    <td class="text-left"><?= strtoupper($r['adviser']) ?></td>
                    <td><?= $r['grade_level'] ?></td>
                    <td class="text-left"><?= $r['section'] ?></td>
                    <td><?= $r['reg_m'] ?></td><td><?= $r['reg_f'] ?></td><td><?= $r['reg_t'] ?></td>
                    <td><?= number_format($r['ada_m'], 1) ?></td><td><?= number_format($r['ada_f'], 1) ?></td><td><?= number_format($r['ada_t'], 1) ?></td>
                    <td><?= number_format($r['perc_m'], 1) ?>%</td><td><?= number_format($r['perc_f'], 1) ?>%</td><td><?= number_format($r['perc_t'], 1) ?>%</td>
                    <td><?= $r['p_drop_m'] ?></td><td><?= $r['p_drop_f'] ?></td><td><?= $r['p_drop_t'] ?></td>
                    <td><?= $r['m_drop_m'] ?></td><td><?= $r['m_drop_f'] ?></td><td><?= $r['m_drop_t'] ?></td>
                    <td><?= $r['p_drop_m']+$r['m_drop_m'] ?></td><td><?= $r['p_drop_f']+$r['m_drop_f'] ?></td><td><?= $r['p_drop_t']+$r['m_drop_t'] ?></td>
                    <td><?= $r['p_tout_m'] ?></td><td><?= $r['p_tout_f'] ?></td><td><?= $r['p_tout_t'] ?></td>
                    <td><?= $r['m_tout_m'] ?></td><td><?= $r['m_tout_f'] ?></td><td><?= $r['m_tout_t'] ?></td>
                    <td><?= $r['p_tout_m']+$r['m_tout_m'] ?></td><td><?= $r['p_tout_f']+$r['m_tout_f'] ?></td><td><?= $r['p_tout_t']+$r['m_tout_t'] ?></td>
                    <td><?= $r['p_tin_m'] ?></td><td><?= $r['p_tin_f'] ?></td><td><?= $r['p_tin_t'] ?></td>
                    <td><?= $r['m_tin_m'] ?></td><td><?= $r['m_tin_f'] ?></td><td><?= $r['m_tin_t'] ?></td>
                    <td><?= $r['p_tin_m']+$r['m_tin_m'] ?></td><td><?= $r['p_tin_f']+$r['m_tin_f'] ?></td><td><?= $r['p_tin_t']+$r['m_tin_t'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($reports)): ?>
                <tr><td colspan="39" style="padding: 20px; font-style: italic;">No data available for the selected parameters.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #e5e7eb; font-weight: bold;">
                    <td colspan="3">TOTAL</td>
                    <td><?= $gt['reg_m'] ?></td><td><?= $gt['reg_f'] ?></td><td><?= $gt['reg_t'] ?></td>
                    <td><?= number_format($gt['ada_m'], 1) ?></td><td><?= number_format($gt['ada_f'], 1) ?></td><td><?= number_format($gt['ada_t'], 1) ?></td>
                    <td colspan="3"><?= $gt['reg_t'] > 0 ? number_format(($gt['ada_t'] / $gt['reg_t']) * 100, 1) : '0.0' ?>%</td>
                    <td><?= $gt['p_drop_m'] ?></td><td><?= $gt['p_drop_f'] ?></td><td><?= $gt['p_drop_t'] ?></td>
                    <td><?= $gt['m_drop_m'] ?></td><td><?= $gt['m_drop_f'] ?></td><td><?= $gt['m_drop_t'] ?></td>
                    <td><?= $gt['p_drop_m']+$gt['m_drop_m'] ?></td><td><?= $gt['p_drop_f']+$gt['m_drop_f'] ?></td><td><?= $gt['p_drop_t']+$gt['m_drop_t'] ?></td>
                    <td><?= $gt['p_tout_m'] ?></td><td><?= $gt['p_tout_f'] ?></td><td><?= $gt['p_tout_t'] ?></td>
                    <td><?= $gt['m_tout_m'] ?></td><td><?= $gt['m_tout_f'] ?></td><td><?= $gt['m_tout_t'] ?></td>
                    <td><?= $gt['p_tout_m']+$gt['m_tout_m'] ?></td><td><?= $gt['p_tout_f']+$gt['m_tout_f'] ?></td><td><?= $gt['p_tout_t']+$gt['m_tout_t'] ?></td>
                    <td><?= $gt['p_tin_m'] ?></td><td><?= $gt['p_tin_f'] ?></td><td><?= $gt['p_tin_t'] ?></td>
                    <td><?= $gt['m_tin_m'] ?></td><td><?= $gt['m_tin_f'] ?></td><td><?= $gt['m_tin_t'] ?></td>
                    <td><?= $gt['p_tin_m']+$gt['m_tin_m'] ?></td><td><?= $gt['p_tin_f']+$gt['m_tin_f'] ?></td><td><?= $gt['p_tin_t']+$gt['m_tin_t'] ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="footer">
            <div class="guidelines">
                <h3>GUIDELINES:</h3>
                <ol>
                    <li>This form should be accomplished every end of the month using the summary of SF2.</li>
                    <li>Only advisory classes shall be reported in this form.</li>
                    <li>Furnish a copy to the Division Office on or before the 10th day of the following month.</li>
                </ol>
            </div>
            
            <div class="sig-section">
                <div class="sig-label">Prepared by:</div>
                <div class="sig-line"><?= strtoupper($settings['school_head'] ?? '') ?></div>
                <div class="sig-title">School Head / Principal</div>
                <div style="font-size: 7px; font-style: italic;">(Signature over Printed Name)</div>
            </div>

            <div class="sig-section">
                <div class="sig-label">Validated by:</div>
                <div class="sig-line"></div>
                <div class="sig-title">Division Representative / Date</div>
            </div>
        </div>
    </div>
</body>
</html>
