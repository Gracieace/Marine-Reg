<?php
/**
 * SF6 Official DepEd Print Layout
 * Replicated exactly based on DepEd template
 * Orientation: Landscape
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/sf6_logic.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();

$school_year = $_GET['school_year'] ?? get_active_school_year($pdo);
$target_grade = $_GET['grade_level'] ?? '';
$summary = generateSF6SchoolSummary($pdo, $school_year, $target_grade);
$grade_levels = $summary['grade_levels'];
$data = $summary['data'];

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
    <title>SF6_Official_<?= $school_year ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        
        @page { size: legal landscape; margin: 0.2in; }
        
        body { 
            font-family: 'Arial Narrow', Arial, sans-serif; 
            margin: 0; 
            padding: 0; 
            background: #525659; 
            color: #000; 
            line-height: 1.1; 
            display: flex;
            justify-content: center;
        }

        .paper {
            background: white;
            width: 13.5in;
            min-height: 8.5in;
            padding: 0.15in;
            box-shadow: 0 0 20px rgba(0,0,0,0.3);
            margin: 20px;
            box-sizing: border-box;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        .header { position: relative; text-align: center; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 3px solid #000; }
        .logo-left { position: absolute; left: 0; top: 0; height: 85px; }
        .logo-right { position: absolute; right: 0; top: 0; height: 85px; }
        .header h1 { font-size: 24px; margin: 2px 0; font-weight: 900; text-transform: uppercase; }
        .header p { font-size: 15px; margin: 1px 0; font-weight: bold; }

        .meta-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 10px; 
            font-size: 14px; 
            margin-bottom: 10px;
            padding: 5px;
            border: 2px solid #000;
        }
        .meta-item { display: flex; gap: 8px; align-items: baseline; }
        .meta-label { font-weight: bold; text-transform: uppercase; font-size: 11px; }
        .meta-value { border-bottom: 2px solid #000; flex: 1; padding: 0 5px; font-weight: 900; text-transform: uppercase; font-size: 15px; }

        .sf6-table { width: 100%; border-collapse: collapse; border: 2.5px solid #000; margin-bottom: 10px; table-layout: fixed; }
        .sf6-table th, .sf6-table td { border: 1.5px solid #000; padding: 6px 3px; text-align: center; font-size: 13px; line-height: 1.2; }
        .sf6-table th { background: #fff; font-weight: 900; text-transform: uppercase; }
        .sf6-table th:first-child, .sf6-table td:first-child { width: 32%; }
        .text-left { text-align: left !important; padding-left: 8px !important; }
        
        .row-separator { background: #fff; font-weight: 900; text-transform: uppercase; font-size: 11px; }
        .footer-container { margin-top: auto; padding-top: 20px; }
        .footer { display: grid; grid-template-columns: repeat(3, 1fr); gap: 80px; margin-top: 20px; }
        .sig-box { text-align: center; font-size: 13px; }
        .sig-line { border-bottom: 2.5px solid #000; margin: 30px auto 5px; width: 95%; font-weight: 900; text-transform: uppercase; min-height: 25px; font-size: 16px; }
        .sig-label { font-size: 11px; font-weight: bold; }

        .btn-print { 
            position: fixed; top: 20px; right: 20px; background: #000; color: #fff; border: none; 
            padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: bold; z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5); font-family: 'Inter', sans-serif;
            display: flex; align-items: center; gap: 10px;
        }

        @media print { 
            body { 
                background: white !important; 
                display: block !important; 
                padding: 0 !important; 
                margin: 0 !important; 
                width: auto !important;
                height: auto !important;
            }
            .paper { 
                margin: 0 !important; 
                padding: 0 !important; 
                box-shadow: none !important; 
                width: 100% !important; 
                min-height: auto !important; 
                border: none !important;
            }
            .btn-print, .no-print { display: none !important; } 
            
            /* Ensure background colors for headers print correctly if enabled */
            th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>

    <div class="paper">
        <div class="header" style="border:none; margin-bottom: 30px;">
            <img src="<?= url_for('/assets/images/deped_logo.png') ?>" style="position: absolute; right: 0; top: 0; height: 90px;" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/thumb/a/af/Department_of_Education_%28DepEd%29_Philippines.svg/1200px-Department_of_Education_%28DepEd%29_Philippines.svg.png'">
            <img src="<?= url_for('/assets/images/school_logo.png') ?>" style="position: absolute; left: 0; top: 0; height: 90px;">
            <div style="text-align: center; margin-top: 10px;">
                <h1 style="font-size: 26px; margin: 0;">School Form 6 (SF6) Summarized Report on Promotion</h1>
                <h1 style="font-size: 26px; margin: 0;">and Level of Proficiency</h1>
                <p style="font-weight: normal; font-style: italic; font-size: 11px; margin-top: 5px;">(This replaced Form 20)</p>
            </div>
        </div>

        <!-- Meta Info Section with Boxes -->
        <div style="font-size: 13px; margin-bottom: 25px;">
            <div style="display: flex; gap: 40px; margin-bottom: 12px; align-items: center;">
                <div style="display: flex; align-items: center; gap: 5px;">
                    <span>School ID</span>
                    <div style="border: 1.5px solid #000; padding: 4px 15px; min-width: 150px; font-weight: 900; height: 22px; display: flex; align-items: center; justify-content: center;"><?= $settings['school_id'] ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 5px; margin-left: auto;">
                    <span>Region</span>
                    <div style="border: 1.5px solid #000; padding: 4px 15px; min-width: 120px; font-weight: 900; height: 22px; display: flex; align-items: center; justify-content: center;"><?= $settings['region'] ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 5px; margin-left: auto;">
                    <span>Division</span>
                    <div style="border: 1.5px solid #000; padding: 4px 15px; min-width: 250px; font-weight: 900; height: 22px; display: flex; align-items: center; justify-content: center;"><?= $settings['division'] ?></div>
                </div>
            </div>
            <div style="display: flex; gap: 40px; align-items: center;">
                <div style="display: flex; align-items: center; gap: 5px;">
                    <span>School Name</span>
                    <div style="border: 1.5px solid #000; padding: 4px 15px; min-width: 480px; font-weight: 900; height: 22px; display: flex; align-items: center; justify-content: center;"><?= $settings['school_name'] ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 5px; margin-left: auto;">
                    <span>District</span>
                    <div style="border: 1.5px solid #000; padding: 4px 15px; min-width: 200px; font-weight: 900; height: 22px; display: flex; align-items: center; justify-content: center;"><?= $settings['district'] ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 5px; margin-left: auto;">
                    <span>School Year</span>
                    <div style="border: 1.5px solid #000; padding: 4px 15px; min-width: 120px; font-weight: 900; height: 22px; display: flex; align-items: center; justify-content: center;"><?= $school_year ?></div>
                </div>
            </div>
        </div>

        <table class="sf6-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 280px;">SUMMARY TABLE</th>
                    <?php foreach ($grade_levels as $gl): ?>
                        <th colspan="3"><?= strtoupper($gl) ?></th>
                    <?php endforeach; ?>
                    <th colspan="3">TOTAL</th>
                </tr>
                <tr>
                    <?php foreach ($grade_levels as $gl): ?>
                        <th>M</th><th>F</th><th>T</th>
                    <?php endforeach; ?>
                    <th>M</th><th>F</th><th>T</th>
                </tr>
            </thead>
            <tbody>
                <!-- PROMOTION STATUS -->
                <?php 
                $status_rows = ['promoted' => 'PROMOTED', 'conditional' => 'IRREGULAR', 'retained' => 'RETAINED'];
                foreach ($status_rows as $key => $label): 
                    $gt_m = 0; $gt_f = 0;
                ?>
                <tr>
                    <td class="text-left" style="font-weight:bold;"><?= $label ?></td>
                    <?php foreach ($grade_levels as $gl): 
                        $m = $data[$gl]['counts']['M'][$key] ?? 0; 
                        $f = $data[$gl]['counts']['F'][$key] ?? 0;
                        $gt_m += $m; $gt_f += $f;
                    ?>
                        <td><?= $m ?></td><td><?= $f ?></td><td style="background: #f1f5f9; font-weight:bold;"><?= $m + $f ?></td>
                    <?php endforeach; ?>
                    <td style="font-weight:bold"><?= $gt_m ?></td><td style="font-weight:bold"><?= $gt_f ?></td><td style="font-weight:bold; background: #eee;"><?= $gt_m + $gt_f ?></td>
                </tr>
                <?php endforeach; ?>
                
                <!-- LEVEL OF PROFICIENCY SECTION -->
                <tr class="row-separator">
                    <td class="text-left">LEVEL OF PROFICIENCY</td>
                    <?php foreach ($grade_levels as $gl): ?>
                        <td>M</td><td>F</td><td>T</td>
                    <?php endforeach; ?>
                    <td>M</td><td>F</td><td>T</td>
                </tr>

                <?php 
                $prof_mapping = [
                    'Beginning' => 'Nos. of BEGINNING (B: 74% and below)',
                    'Developing' => 'Nos. of DEVELOPING (D: 75%-79%)',
                    'Approaching' => 'Nos. of APPROACHING PROFICIENCY (AP: 80%-84%)',
                    'Proficient' => 'Nos. of PROFICIENT (P: 85%-89%)',
                    'Advanced' => 'Nos. of ADVANCED (A: 90% and above)'
                ];
                foreach ($prof_mapping as $key => $label): 
                    $gt_m = 0; $gt_f = 0;
                ?>
                <tr>
                    <td class="text-left"><?= $label ?></td>
                    <?php foreach ($grade_levels as $gl): 
                        $m = $data[$gl]['student_proficiency']['M'][$key] ?? 0; 
                        $f = $data[$gl]['student_proficiency']['F'][$key] ?? 0;
                        $gt_m += $m; $gt_f += $f;
                    ?>
                        <td><?= $m ?></td><td><?= $f ?></td><td style="background: #f1f5f9; font-weight:bold;"><?= $m + $f ?></td>
                    <?php endforeach; ?>
                    <td style="font-weight:bold"><?= $gt_m ?></td><td style="font-weight:bold"><?= $gt_f ?></td><td style="font-weight:bold; background: #eee;"><?= $gt_m + $gt_f ?></td>
                </tr>
                <?php endforeach; ?>

                <!-- TOTAL ROW -->
                <tr style="background: #f1f5f9; font-weight: 900; border-top: 2px solid #000;">
                    <td class="text-left">TOTAL</td>
                    <?php 
                    $st_m = 0; $st_f = 0;
                    foreach ($grade_levels as $gl): 
                        $m = array_sum($data[$gl]['student_proficiency']['M'] ?? []);
                        $f = array_sum($data[$gl]['student_proficiency']['F'] ?? []);
                        $st_m += $m; $st_f += $f;
                    ?>
                        <td><?= $m ?></td><td><?= $f ?></td><td style="background: #e2e8f0;"><?= $m + $f ?></td>
                    <?php endforeach; ?>
                    <td><?= $st_m ?></td><td><?= $st_f ?></td><td style="background: #000; color: #fff;"><?= $st_m + $st_f ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-container">
            <!-- SIGNATORIES ABOVE GUIDELINES -->
            <div class="footer" style="margin-bottom: 25px;">
                <div class="sig-box" style="display: flex; align-items: baseline; gap: 5px; text-align: left;">
                    <span style="font-size: 11px; white-space: nowrap;">Prepared and Submitted by:</span>
                    <div style="flex: 1; text-align: center;">
                        <div class="sig-line" style="margin: 0; width: 100%;"><?= strtoupper($settings['school_head'] ?? '') ?></div>
                        <div class="sig-label">SCHOOL HEAD</div>
                    </div>
                </div>
                <div class="sig-box" style="display: flex; align-items: baseline; gap: 5px; text-align: left;">
                    <span style="font-size: 11px; white-space: nowrap;">Reviewed & Validated by:</span>
                    <div style="flex: 1; text-align: center;">
                        <div class="sig-line" style="margin: 0; width: 100%;"></div>
                        <div class="sig-label">DIVISION REPRESENTATIVE</div>
                    </div>
                </div>
                <div class="sig-box" style="display: flex; align-items: baseline; gap: 5px; text-align: left;">
                    <span style="font-size: 11px; white-space: nowrap;">Noted by:</span>
                    <div style="flex: 1; text-align: center;">
                        <div class="sig-line" style="margin: 0; width: 100%;"></div>
                        <div class="sig-label">SCHOOLS DIVISION SUPERINTENDENT</div>
                    </div>
                </div>
            </div>

            <!-- GUIDELINES BELOW SIGNATORIES -->
            <div style="font-size: 11px; line-height: 1.3; border-top: 1px solid #000; padding-top: 10px;">
                <p style="font-weight: 900; margin-bottom: 5px;">GUIDELINES:</p>
                <p>1. After receiving and validating the Report for Promotion submitted by the class adviser, the School Head shall compute the Total for Grade Level in order to reflect the result in each data field.</p>
                <p>2. This report together with the copy of Report for Promotion submitted by the class adviser shall be forwarded to the Division Office by the end of the school year.</p>
                <p>3. The Report on Promotion per Grade Level is reflected in the End of School Year Report of GESP/GSSP</p>
            </div>
        </div>
    </div>
</body>
</html>
