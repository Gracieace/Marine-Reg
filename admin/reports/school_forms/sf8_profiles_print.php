<?php
/**
 * SECTION PROFILE MASTER LIST - SF1 Registry Integration
 * Printable version for SF8 Reference
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';

try {
    $pdo = db_connect();
    auth_require_role(['admin', 'registrar', 'teacher', 'principal']);
    
    $school_year = $_GET['school_year'] ?? '';
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';

    if (!$school_year || !$grade_level || !$section) {
        die("Missing report parameters.");
    }

    // Fetch Settings
    $settings = [
        'school_id' => '300750', 'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
        'region' => 'REGION III', 'division' => 'MALOLOS CITY', 'district' => 'MALOLOS SOUTH'
    ];
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('region', 'division', 'district', 'school_name', 'school_id')");
    if ($stmt_set) {
        while ($s = $stmt_set->fetch()) {
            if (!empty($s['setting_value'])) $settings[$s['setting_key']] = $s['setting_value'];
        }
    }

    // Fetch Data (Exact same logic as sf8.php)
    $sql = "SELECT e.*, r.sex, r.birthdate, r.last_name, r.first_name, r.middle_name, r.ext_name,
                   r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city,
                   r.guardian_first, r.guardian_last, r.guardian_contact,
                   r.mother_tongue, r.is_ip, r.ip_ethnicity, r.is_4ps_beneficiary,
                   r.father_first, r.father_last, r.mother_first, r.mother_last
            FROM enrollments e 
            LEFT JOIN registrations r ON (r.id = e.registration_id OR (e.registration_id IS NULL AND r.lrn = e.student_id))
            WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ?
            ORDER BY e.student_name ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$school_year, $grade_level, $section]);
    $students = $stmt->fetchAll();
    
    foreach ($students as &$s) {
        $m_initial = $s['middle_name'] ? ' ' . substr($s['middle_name'], 0, 1) . '.' : '';
        $s['formatted_name'] = $s['last_name'] . ', ' . $s['first_name'] . ($s['ext_name'] ? ' ' . $s['ext_name'] : '') . $m_initial;
        $s['full_address'] = trim(($s['curr_house_no'] ?? '') . ' ' . ($s['curr_street'] ?? '') . ', ' . ($s['curr_barangay'] ?? '') . ', ' . ($s['curr_city'] ?? ''));
        $s['father_name'] = trim(($s['father_first'] ?? '') . ' ' . ($s['father_last'] ?? '')) ?: 'N/A';
        $s['mother_name'] = trim(($s['mother_first'] ?? '') . ' ' . ($s['mother_last'] ?? '')) ?: 'N/A';
        $s['ip_status'] = ($s['is_ip'] === 'Yes') ? ($s['ip_ethnicity'] ?: 'Yes') : 'No';
    }

} catch (Exception $e) {
    die("Print Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Section Profile - <?= $section ?></title>
    <style>
        body { font-family: 'Times New Roman', serif; margin: 0; padding: 0.5in; font-size: 10px; color: #000; line-height: 1.2; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h1 { font-size: 16px; margin: 0; text-transform: uppercase; }
        .header p { margin: 2px 0; font-size: 10px; }

        .meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 15px; font-size: 9px; }
        .meta-item { border-bottom: 1px solid #ddd; padding: 2px 0; }
        .meta-item b { text-transform: uppercase; color: #444; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; }
        th { background: #eee; font-weight: bold; font-size: 8px; text-transform: uppercase; text-align: center; }
        
        .center { text-align: center; }
        .bold { font-weight: bold; }
        
        @media print {
            @page { size: landscape; margin: 0.25in; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <p>Republic of the Philippines</p>
        <p>Department of Education</p>
        <h1>SECTION LEARNER PROFILE MASTER LIST</h1>
        <p>Registry Reference (SF1 Integrated)</p>
    </div>

    <div class="meta-grid">
        <div class="meta-item"><b>School:</b> <?= $settings['school_name'] ?></div>
        <div class="meta-item"><b>School ID:</b> <?= $settings['school_id'] ?></div>
        <div class="meta-item"><b>Grade & Section:</b> <?= $grade_level ?> - <?= $section ?></div>
        <div class="meta-item"><b>School Year:</b> <?= $school_year ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2">No.</th>
                <th rowspan="2">LRN</th>
                <th rowspan="2">Learner's Name (Last, First, Ext, Middle)</th>
                <th rowspan="2">Sex</th>
                <th rowspan="2">Birthdate</th>
                <th rowspan="2">Mother Tongue</th>
                <th rowspan="2">Ethnic (IP)</th>
                <th rowspan="2">4Ps</th>
                <th colspan="2">Parents' Name</th>
                <th colspan="2">Guardian Reference</th>
                <th rowspan="2">Address</th>
            </tr>
            <tr>
                <th>Father</th><th>Mother</th>
                <th>Name</th><th>Contact</th>
            </tr>
        </thead>
        <tbody>
            <?php $n=1; foreach($students as $s): ?>
                <tr>
                    <td class="center"><?= $n++ ?></td>
                    <td class="bold"><?= $s['student_id'] ?></td>
                    <td class="bold"><?= $s['formatted_name'] ?></td>
                    <td class="center"><?= strtoupper(substr($s['sex']??'M',0,1)) ?></td>
                    <td class="center"><?= date('m/d/Y', strtotime($s['birthdate'])) ?></td>
                    <td><?= htmlspecialchars($s['mother_tongue'] ?: 'N/A') ?></td>
                    <td class="center"><?= htmlspecialchars($s['ip_status']) ?></td>
                    <td class="center"><?= $s['is_4ps_beneficiary']?'YES':'NO' ?></td>
                    <td><?= htmlspecialchars($s['father_name']) ?></td>
                    <td><?= htmlspecialchars($s['mother_name']) ?></td>
                    <td><?= htmlspecialchars(($s['guardian_first'] ?? '') . ' ' . ($s['guardian_last'] ?? '')) ?></td>
                    <td class="bold"><?= htmlspecialchars($s['guardian_contact'] ?: 'N/A') ?></td>
                    <td style="font-size: 8px;"><?= htmlspecialchars($s['full_address']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end;">
        <div style="text-align: center; width: 250px;">
            <div style="border-bottom: 1.5px solid #000; min-height: 20px; font-weight: bold; font-size: 11px;"></div>
            <div style="font-size: 10px; margin-top: 5px;">Class Adviser / Prepared By</div>
        </div>
        <div style="text-align: center; width: 250px;">
            <div style="border-bottom: 1.5px solid #000; min-height: 20px; font-weight: bold; font-size: 11px; text-transform: uppercase;">
                <?php 
                    $principal_name = get_system_setting($pdo, 'principal_name', 'School Head'); 
                    echo strtoupper($principal_name);
                ?>
            </div>
            <div style="font-size: 10px; margin-top: 5px;">School Head / Principal</div>
        </div>
    </div>

    <div style="margin-top: 30px; font-size: 8px; font-style: italic; color: #666;">
        Generated on <?= date('M d, Y h:i A') ?>. This document contains sensitive personal information and must be handled in accordance with the Data Privacy Act of 2012.
    </div>
</body>
</html>
