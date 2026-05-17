<?php
/**
 * SF8 PRINT VERSION - Official DepEd Template
 * Strictly for physical printing. No sidebars, no buttons.
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/sf8_engine.php';

try {
    $pdo = db_connect();
    auth_require_role(['admin', 'registrar', 'teacher', 'principal']);
    
    $school_year = $_GET['school_year'] ?? '';
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';

    if (!$school_year || !$grade_level || !$section) {
        die("Missing report parameters.");
    }

    // Fetch Settings with Standardized Keys
    $settings = [
        'school_id' => '300750', 
        'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
        'region' => 'REGION III', 
        'division' => 'MALOLOS CITY', 
        'district' => 'MALOLOS SOUTH',
        'school_head' => '', 
        'health_coordinator' => 'MS. JENNY LYN CRUZ',
        'reviewed_by' => 'DR. ROBERTO NAVAL'
    ];
    
    $keys = ['region', 'division', 'district', 'school_name', 'school_id', 'signatory_registrar', 'sf_region', 'sf_division'];
    $placeholders = str_repeat('?,', count($keys) - 1) . '?';
    $stmt_set = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt_set->execute($keys);
    
    $db_settings = [];
    while ($s = $stmt_set->fetch()) {
        $db_settings[$s['setting_key']] = $s['setting_value'];
    }

    foreach (['school_id', 'school_name', 'district'] as $k) {
        if (!empty($db_settings[$k])) $settings[$k] = $db_settings[$k];
    }

    $settings['region'] = $db_settings['sf_region'] ?? $db_settings['region'] ?? 'REGION III';
    $settings['division'] = $db_settings['sf_division'] ?? $db_settings['division'] ?? 'MALOLOS CITY';
    $settings['school_head'] = get_system_setting($pdo, 'principal_name', 'DR. MARIA SANTOS');
    $settings['registrar_name'] = $db_settings['signatory_registrar'] ?? 'MS. ANA CRUZ';

    // Fetch Class Adviser for this specific section
    $stmt_adv = $pdo->prepare("SELECT u.first_name, u.last_name FROM sections s JOIN users u ON s.adviser_id = u.id WHERE (s.grade_level = ? OR s.grade_level = ?) AND s.section_name = ? AND s.school_year = ? LIMIT 1");
    $stmt_adv->execute([$grade_level, str_replace('Grade ', '', $grade_level), $section, $school_year]);
    $adv_user = $stmt_adv->fetch();
    $adviser_name = $adv_user ? strtoupper($adv_user['first_name'] . ' ' . $adv_user['last_name']) : 'CLASS ADVISER';

    // Fetch Data
    $students = getSF8Data($pdo, $school_year, $grade_level, $section);
    $males = array_filter($students, fn($s) => strtoupper(substr($s['sex']??'M',0,1)) !== 'F');
    $females = array_filter($students, fn($s) => strtoupper(substr($s['sex']??'M',0,1)) === 'F');

} catch (Exception $e) {
    die("Print Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF8 Official Print - <?= $section ?></title>
    <style>
        @page { size: 13in 8.5in landscape; margin: 0.3in; }
        body { font-family: 'Arial Narrow', Arial, sans-serif; margin: 0; padding: 0; font-size: 10px; color: #000; background: #f0f2f5; }
        
        /* Print Area Preview */
        .print-container { 
            width: 12.4in; margin: 20px auto; padding: 0.3in; 
            background: white; box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: relative; border-radius: 4px;
        }
        @media print {
            body { background: white; }
            .print-container { width: 100%; margin: 0; padding: 0; box-shadow: none; border: none; }
        }
        
        /* Header */
        .deped-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; position: relative; }
        .seal-box { width: 90px; text-align: center; }
        .seal-box img { width: 80px; height: 80px; object-fit: contain; }
        .center-header { text-align: center; flex: 1; line-height: 1.1; }
        .center-header p { font-size: 11px; margin: 0; font-weight: bold; text-transform: uppercase; }
        .center-header h2 { font-size: 13px; margin: 2px 0; font-weight: normal; }
        .center-header h1 { font-size: 20px; margin: 5px 0; font-weight: 900; letter-spacing: -0.5px; }

        /* Metadata */
        .meta-table { width: 100%; margin-bottom: 8px; border-collapse: collapse; }
        .meta-table td { padding: 3px 2px; border: none; vertical-align: bottom; }
        .meta-label { font-size: 9px; text-align: right; padding-right: 6px; white-space: nowrap; font-weight: bold; color: #444; }
        .meta-box { border-bottom: 1.5px solid #000; font-size: 12px; font-weight: 800; text-align: center; height: 20px; color: #000; }
        
        /* Official Table Styling */
        table.main-table { width: 100%; border-collapse: collapse; border: 2.5px solid #000; margin-bottom: 15px; }
        table.main-table th { background: #f1f5f9; padding: 6px 2px; font-size: 8.5px; text-transform: uppercase; border: 1px solid #000; }
        table.main-table td { border: 1px solid #000; padding: 4px 3px; text-align: center; font-size: 10px; font-weight: 600; }
        table.main-table .name-col { text-align: left; width: 250px; font-weight: 700; text-transform: uppercase; font-size: 9px; }
        table.main-table .group-row { background: #e2e8f0; font-weight: 900; text-align: left; padding: 6px 12px; font-size: 11px; letter-spacing: 1px; }

        /* Summary */
        .summary-title { font-weight: 900; font-size: 12px; margin-bottom: 8px; text-align: center; text-decoration: underline; }
        table.summary-table { width: 100%; border-collapse: collapse; border: 2px solid #000; }
        table.summary-table th, table.summary-table td { border: 1px solid #000; padding: 6px; font-size: 10px; }
        table.summary-table th { background: #f8fafc; font-weight: 800; }
        table.summary-table .thick-left { border-left: 2.5px solid #000; }

        /* Signatures */
        .sig-section { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 25px; }
        .sig-box { text-align: center; }
        .sig-line { border-bottom: 1.5px solid #000; margin-bottom: 5px; font-weight: 900; font-size: 12px; padding-bottom: 3px; height: 35px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-label { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555; }
        
        .form-id { position: absolute; top: 15px; left: 15px; font-weight: 900; font-size: 12px; }
    </style>
</head>
<body onload="window.print()">
    <div class="print-container">
        <div class="form-id">SF 8</div>
        <div class="deped-header">
            <div class="seal-box">
                <img src="../../../assets/images/deped_logo.png" alt="DepEd Seal">
            </div>
            <div class="center-header">
                <p>Republic of the Philippines</p>
                <p>Department of Education</p>
                <h1 style="margin:8px 0;">Learner's Basic Health and Nutrition Report (SF8)</h1>
                <h2>(For All Grade Levels)</h2>
            </div>
            <div class="seal-box">
                <img src="../../../assets/images/school_logo.png" alt="School Seal">
            </div>
        </div>

        <table class="meta-table">
            <tr>
                <td class="meta-label">School Name</td>
                <td class="meta-box" style="width: 25%;"><?= htmlspecialchars($settings['school_name']) ?></td>
                <td class="meta-label">District</td>
                <td class="meta-box" style="width: 15%;"><?= htmlspecialchars($settings['district']) ?></td>
                <td class="meta-label">Division</td>
                <td class="meta-box" style="width: 15%;"><?= htmlspecialchars($settings['division']) ?></td>
                <td class="meta-label">Region</td>
                <td class="meta-box" style="width: 10%;"><?= htmlspecialchars($settings['region']) ?></td>
            </tr>
        </table>
        <table class="meta-table">
            <tr>
                <td class="meta-label">School ID</td>
                <td class="meta-box" style="width: 12%;"><?= htmlspecialchars($settings['school_id']) ?></td>
                <td class="meta-label">Grade</td>
                <td class="meta-box" style="width: 8%;"><?= htmlspecialchars(str_replace('Grade ', '', $grade_level)) ?></td>
                <td class="meta-label">Section</td>
                <td class="meta-box" style="width: 15%;"><?= htmlspecialchars($section) ?></td>
                <td class="meta-label">Track/Strand (SHS)</td>
                <td class="meta-box" style="width: 15%;"></td>
                <td class="meta-label">School Year</td>
                <td class="meta-box" style="width: 12%;"><?= htmlspecialchars($school_year) ?></td>
            </tr>
        </table>

        <table class="main-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 25px;">No.</th>
                    <th rowspan="2" style="width: 80px;">LRN</th>
                    <th rowspan="2" class="name-col">Learner's Name<br><span style="font-weight: normal; font-size: 8px;">(Last Name, First Name, Name Extension, Middle Name)</span></th>
                    <th rowspan="2" style="width: 70px;">Birthdate<br><span style="font-weight: normal; font-size: 8px;">(MM/DD/YYYY)</span></th>
                    <th rowspan="2" style="width: 30px;">Age</th>
                    <th rowspan="2" style="width: 40px;">Weight<br><span style="font-weight: normal; font-size: 8px;">(kg)</span></th>
                    <th rowspan="2" style="width: 40px;">Height<br><span style="font-weight: normal; font-size: 8px;">(m)</span></th>
                    <th rowspan="2" style="width: 40px;">Height²<br><span style="font-weight: normal; font-size: 8px;">(m²)</span></th>
                    <th colspan="2">Nutritional Status</th>
                    <th rowspan="2" style="width: 60px;">Height for<br>Age (HFA)</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <th style="width: 50px;">BMI<br><span style="font-weight: normal; font-size: 8px;">(kg/m²)</span></th>
                    <th style="width: 60px;">BMI<br>Category</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="12" class="group-row">MALE</td></tr>
                <?php $n=1; foreach($males as $s): ?>
                    <tr>
                        <td><?= $n++ ?></td>
                        <td><?= htmlspecialchars($s['student_id']) ?></td>
                        <td class="name-col"><?= htmlspecialchars($s['formatted_name']) ?></td>
                        <td><?= date('m/d/Y', strtotime($s['birthdate'])) ?></td>
                        <td><?= htmlspecialchars($s['age']) ?></td>
                        <td><?= $s['weight_kg'] ?: '' ?></td>
                        <td><?= $s['height_m'] ?: '' ?></td>
                        <td><?= $s['height_sq'] ?: '' ?></td>
                        <td><?= $s['bmi'] ?: '' ?></td>
                        <td><?= htmlspecialchars($s['nutritional_status']) ?: '' ?></td>
                        <td>
                            <?php 
                            $h_m = floatval($s['height_m']);
                            if ($h_m > 0) {
                                $age_v = intval($s['age']);
                                $sex_v = strtoupper(substr($s['sex']??'M',0,1)) !== 'F' ? 'M' : 'F';
                                $norms = [12=>[1.60,1.40],13=>[1.65,1.45],14=>[1.70,1.50],15=>[1.75,1.55],16=>[1.78,1.60],17=>[1.80,1.62],18=>[1.81,1.63]];
                                $d = $norms[$age_v<12?12:($age_v>18?18:$age_v)];
                                $med = ($d[0] + $d[1]) / 2;
                                $sd_v = ($d[0] - $med) / 2;
                                $z_val = ($h_m - $med) / $sd_v;
                                if ($z_val < -3) $hfa_v = 'Severely Stunted';
                                else if ($z_val < -2) $hfa_v = 'Stunted';
                                else if ($z_val > 2) $hfa_v = 'Tall';
                                else $hfa_v = 'Normal';
                                echo $hfa_v . " (".($z_val > 0 ? '+' : '').round($z_val,1).")";
                            } else { echo $s['hfa'] ?: ''; }
                            ?>
                        </td>
                        <td><?= htmlspecialchars($s['condition_remarks'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for($i=0; $i < max(2, 5 - count($males)); $i++): ?>
                    <tr><td></td><td></td><td class="name-col"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <?php endfor; ?>

                <tr><td colspan="12" class="group-row">FEMALE</td></tr>
                <?php $n=1; foreach($females as $s): ?>
                    <tr>
                        <td><?= $n++ ?></td>
                        <td><?= htmlspecialchars($s['student_id']) ?></td>
                        <td class="name-col"><?= htmlspecialchars($s['formatted_name']) ?></td>
                        <td><?= !empty($s['birthdate']) ? date('m/d/Y', strtotime($s['birthdate'])) : '---' ?></td>
                        <td><?= htmlspecialchars($s['age']) ?></td>
                        <td><?= $s['weight_kg'] ?: '' ?></td>
                        <td><?= $s['height_m'] ?: '' ?></td>
                        <td><?= $s['height_sq'] ?: '' ?></td>
                        <td><?= $s['bmi'] ?: '' ?></td>
                        <td><?= htmlspecialchars($s['nutritional_status']) ?: '' ?></td>
                        <td>
                            <?php 
                            $h_m = floatval($s['height_m']);
                            if ($h_m > 0) {
                                $age_v = intval($s['age']);
                                $sex_v = strtoupper(substr($s['sex']??'M',0,1)) !== 'F' ? 'M' : 'F';
                                $norms = [12=>[1.60,1.40],13=>[1.65,1.45],14=>[1.70,1.50],15=>[1.75,1.55],16=>[1.78,1.60],17=>[1.80,1.62],18=>[1.81,1.63]];
                                $d = $norms[$age_v<12?12:($age_v>18?18:$age_v)];
                                $med = ($d[0] + $d[1]) / 2;
                                $sd_v = ($d[0] - $med) / 2;
                                $z_val = ($h_m - $med) / $sd_v;
                                if ($z_val < -3) $hfa_v = 'Severely Stunted';
                                else if ($z_val < -2) $hfa_v = 'Stunted';
                                else if ($z_val > 2) $hfa_v = 'Tall';
                                else $hfa_v = 'Normal';
                                echo $hfa_v . " (".($z_val > 0 ? '+' : '').round($z_val,1).")";
                            } else { echo $s['hfa'] ?: ''; }
                            ?>
                        </td>
                        <td><?= htmlspecialchars($s['condition_remarks'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for($i=0; $i < max(2, 5 - count($females)); $i++): ?>
                    <tr><td></td><td></td><td class="name-col"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="summary-title">SUMMARY TABLE</div>
        <table class="summary-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 100px;">SEX</th>
                    <th colspan="5">Nutritional Status<br><span style="font-weight: normal; font-size: 9px;">Summary Table</span></th>
                    <th rowspan="2" style="width: 50px;">TOTAL</th>
                    <th colspan="4" class="thick-left">Height for Age (HFA)<br><span style="font-weight: normal; font-size: 9px;">Summary Table</span></th>
                    <th rowspan="2" style="width: 50px;">Total</th>
                </tr>
                <tr>
                    <th>Severely Wasted</th><th>Wasted</th><th>Normal</th><th>Overweight</th><th>Obese</th>
                    <th class="thick-left">Severely<br>Stunted</th><th>Stunted</th><th>Normal</th><th>Tall</th>
                </tr>
            </thead>
            <tbody>
                <?php $sum = computeSF8Summary($males, $females); ?>
                <tr>
                    <td class="bold" style="text-align: left; padding-left: 10px;">MALE</td>
                    <?php foreach(['Severely Wasted','Wasted','Normal','Overweight','Obese'] as $cat) echo "<td>{$sum['bmi'][$cat]['M']}</td>"; ?>
                    <td class="bold"><?= $sum['bmi']['Normal']['M'] + $sum['bmi']['Wasted']['M'] + $sum['bmi']['Severely Wasted']['M'] + $sum['bmi']['Overweight']['M'] + $sum['bmi']['Obese']['M'] ?></td>
                    
                    <td class="thick-left"><?= $sum['hfa']['Severely Stunted']['M'] ?></td>
                    <td><?= $sum['hfa']['Stunted']['M'] ?></td>
                    <td><?= $sum['hfa']['Normal']['M'] ?></td>
                    <td><?= $sum['hfa']['Tall']['M'] ?></td>
                    <td class="bold"><?= array_sum(array_column($sum['hfa'], 'M')) ?></td>
                </tr>
                <tr>
                    <td class="bold" style="text-align: left; padding-left: 10px;">FEMALE</td>
                    <?php foreach(['Severely Wasted','Wasted','Normal','Overweight','Obese'] as $cat) echo "<td>{$sum['bmi'][$cat]['F']}</td>"; ?>
                    <td class="bold"><?= $sum['bmi']['Normal']['F'] + $sum['bmi']['Wasted']['F'] + $sum['bmi']['Severely Wasted']['F'] + $sum['bmi']['Overweight']['F'] + $sum['bmi']['Obese']['F'] ?></td>
                    
                    <td class="thick-left"><?= $sum['hfa']['Severely Stunted']['F'] ?></td>
                    <td><?= $sum['hfa']['Stunted']['F'] ?></td>
                    <td><?= $sum['hfa']['Normal']['F'] ?></td>
                    <td><?= $sum['hfa']['Tall']['F'] ?></td>
                    <td class="bold"><?= array_sum(array_column($sum['hfa'], 'F')) ?></td>
                </tr>
                <tr>
                    <td class="bold" style="text-align: left; padding-left: 10px;">TOTAL</td>
                    <?php foreach(['Severely Wasted','Wasted','Normal','Overweight','Obese'] as $cat) echo "<td class='bold'>{$sum['bmi'][$cat]['Total']}</td>"; ?>
                    <td class="bold"><?= array_sum(array_column($sum['bmi'], 'Total')) ?></td>
                    
                    <td class="thick-left bold"><?= $sum['hfa']['Severely Stunted']['Total'] ?></td>
                    <td class="bold"><?= $sum['hfa']['Stunted']['Total'] ?></td>
                    <td class="bold"><?= $sum['hfa']['Normal']['Total'] ?></td>
                    <td class="bold"><?= $sum['hfa']['Tall']['Total'] ?></td>
                    <td class="bold"><?= array_sum(array_column($sum['hfa'], 'Total')) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="sig-section">
            <div class="sig-box">
                <div class="sig-line"><?= date('m/d/Y') ?></div>
                <div class="sig-label">Date of Assessment</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"><?= $adviser_name ?></div>
                <div class="sig-label">Conducted/Assessed By</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"><?= strtoupper($settings['school_head']) ?></div>
                <div class="sig-label">Certified Correct By<br>(School Head)</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"><?= strtoupper($settings['registrar_name']) ?></div>
                <div class="sig-label">Reviewed By</div>
            </div>
        </div>
        
        <div style="margin-top: 15px; font-size: 8px; font-weight: bold; text-align: right;">
            SFRT 2017
        </div>
    </div>
</body>
</html>
