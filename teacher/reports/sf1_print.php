<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

auth_require_role(['teacher', 'admin', 'registrar']);

$pdo = db_connect();
$report_id = $_GET['id'] ?? null;
$is_live = ($report_id === 'live');
$current_sy = get_system_setting($pdo, 'current_school_year', '2024-2025');

$report = null;
$students = [];

// Helper function for name formatting (LAST NAME, FIRST NAME MIDDLE NAME)
function format_sf1_name($last, $first, $middle, $ext = '') {
    $last = trim($last ?? '');
    $first = trim($first ?? '');
    $middle = trim($middle ?? '');
    $ext = trim($ext ?? '');
    
    $parts = [];
    if ($first || $middle || $ext) {
        $parts[] = trim("$first $middle $ext");
    }
    
    $name = $last;
    if (!empty($parts[0])) {
        $name .= ($last ? ', ' : '') . $parts[0];
    }
    
    return $name ?: 'N/A';
}

if ($is_live) {
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';
    $school_year = $_GET['school_year'] ?? $current_sy;

    $stmt = $pdo->prepare("
        SELECT r.*, e.grade_level, e.section 
        FROM registrations r 
        INNER JOIN enrollments e ON r.id = e.registration_id 
        WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
        ORDER BY r.sex DESC, r.last_name, r.first_name
    ");
    $stmt->execute([$grade_level, $section, $school_year]);
    $raw_students = $stmt->fetchAll();

    foreach ($raw_students as $s) {
        $age = null;
        if ($s['birthdate']) {
            $birthDate = new DateTime($s['birthdate']);
            $sy_parts = explode('-', $school_year);
            $oct31 = new DateTime($sy_parts[0] . '-10-31');
            $age = $oct31->diff($birthDate)->y;
        }
        $students[] = [
            'lrn' => $s['lrn'],
            'last_name' => $s['last_name'],
            'first_name' => $s['first_name'],
            'middle_name' => $s['middle_name'],
            'ext_name' => $s['ext_name'] ?? '',
            'sex' => $s['sex'],
            'birth_date' => $s['birthdate'],
            'age_as_of_oct31' => $age,
            'mother_tongue' => $s['mother_tongue'],
            'ip_ethnicity' => $s['ip_ethnicity'],
            'religion' => $s['religion'] ?? '',
            'house_no_street' => trim(($s['curr_house_no'] ?? '') . ' ' . ($s['curr_street'] ?? '')),
            'barangay' => $s['curr_barangay'],
            'municipality_city' => $s['curr_city'],
            'province' => $s['curr_province'],
            'father_name' => format_sf1_name(
                $s['father_last'] ?? '',
                $s['father_first'] ?? '',
                $s['father_middle'] ?? ''
            ),
            'mother_name' => format_sf1_name(
                $s['mother_last'] ?? '',
                $s['mother_first'] ?? '',
                $s['mother_middle'] ?? ''
            ),
            'guardian_name' => format_sf1_name(
                $s['guardian_last'] ?? '',
                $s['guardian_first'] ?? '',
                $s['guardian_middle'] ?? ''
            ),
            'guardian_relationship' => $s['guardian_relationship'] ?? '',
            'contact_number' => $s['father_contact'] ?: $s['mother_contact'] ?: $s['guardian_contact'],
            'learning_modality' => $s['preferred_modalities'],
            'remarks' => ''
        ];
        if ($s['sex'] === 'M')
            $report['total_male'] = ($report['total_male'] ?? 0) + 1;
        else
            $report['total_female'] = ($report['total_female'] ?? 0) + 1;
    }
    $report['total_male'] = $report['total_male'] ?? 0;
    $report['total_female'] = $report['total_female'] ?? 0;
    $report['total_combined'] = $report['total_male'] + $report['total_female'];
    $report['registered_male_bosy'] = $report['total_male'];
    $report['registered_female_bosy'] = $report['total_female'];
    $report['registered_total_bosy'] = $report['total_combined'];
    $report['registered_male_eosy'] = $report['total_male'];
    $report['registered_female_eosy'] = $report['total_female'];
    $report['registered_total_eosy'] = $report['total_combined'];

    $report += [
        'school_year' => $school_year,
        'grade_level' => $grade_level,
        'section' => $section,
        'prepared_by_name' => $_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name'],
        'certified_by_name' => get_system_setting($pdo, 'principal_name', 'School Head')
    ];
} else {
    $stmt = $pdo->prepare("SELECT r.*, s.* FROM sf1_reports r LEFT JOIN sf1_summary s ON r.id = s.sf1_report_id WHERE r.id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT * FROM sf1_student_records WHERE sf1_report_id = ? ORDER BY sex DESC, last_name, first_name");
    $stmt->execute([$report_id]);
    $raw_students = $stmt->fetchAll();
    
    foreach ($raw_students as $s) {
        $s['full_name'] = format_sf1_name($s['last_name'] ?? '', $s['first_name'] ?? '', $s['middle_name'] ?? '', $s['ext_name'] ?? '');
        $s['father_name'] = format_sf1_name($s['father_last_name'] ?? '', $s['father_first_name'] ?? '', $s['father_middle_name'] ?? '');
        $s['mother_name'] = format_sf1_name($s['mother_last_name'] ?? '', $s['mother_first_name'] ?? '', $s['mother_middle_name'] ?? '');
        $s['guardian_name'] = format_sf1_name($s['guardian_name'] ?? '', '', '');
        $students[] = $s;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF1 Print - <?= htmlspecialchars($report['section']) ?></title>
    <style>
        @page { size: 13in 8.5in; margin: 0.25in; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 9px; margin: 0; padding: 0; background: #f1f5f9; display: flex; justify-content: center; min-height: 100vh; padding-top: 40px; }
        
        .print-area { 
            width: 12.5in; 
            background: white; 
            padding: 0.4in; 
            box-shadow: 0 15px 50px rgba(0,0,0,0.15); 
            min-height: 8in;
            position: relative;
            border-radius: 8px;
            color: #1e293b;
        }

        .official-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #0038a8; padding-bottom: 12px; margin-bottom: 20px; }
        .logo { height: 75px; width: auto; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1)); }
        .header-center { text-align: center; flex: 1; }
        .header-center h2 { margin: 0; font-size: 13px; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .header-center h1 { margin: 4px 0; font-size: 24px; color: #0038a8; font-weight: 800; }
        .header-center p { margin: 0; font-size: 11px; font-weight: 700; color: #1e293b; text-transform: uppercase; }
        
        .report-title { text-align: center; margin-bottom: 20px; }
        .report-title h2 { margin: 0; font-size: 20px; text-transform: uppercase; color: #0f172a; font-weight: 800; letter-spacing: -0.5px; }
        .report-title p { margin: 4px 0; font-size: 10px; font-style: italic; color: #64748b; }
        
        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); border: 2px solid #0f172a; margin-bottom: 20px; background: #f8fafc; }
        .info-cell { padding: 8px 12px; border: 1px solid #e2e8f0; font-size: 11px; }
        .info-cell strong { text-transform: uppercase; display: block; font-size: 9px; color: #0038a8; font-weight: 800; margin-bottom: 2px; }
        .info-cell span { font-weight: 700; color: #0f172a; }
        
        table { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; border: 2px solid #000; }
        th, td { border: 1px solid #000; padding: 6px 4px; text-align: center; word-wrap: break-word; }
        th { background: #f1f5f9; font-weight: 800; text-transform: uppercase; font-size: 9px; color: #334155; height: 45px; }
        
        .sex-row { background: #0f172a; color: white; font-weight: 800; font-size: 12px; height: 35px; text-align: center; letter-spacing: 4px; }
        .name-cell { text-align: left; padding-left: 10px; font-weight: 800; color: #0f172a; width: 180px; }
        
        .footer-section { display: flex; justify-content: space-between; margin-top: 40px; padding: 0 20px; }
        .sig-box { width: 42%; text-align: center; }
        .sig-line { border-bottom: 2.5px solid #000; margin-top: 40px; margin-bottom: 8px; font-weight: 800; text-transform: uppercase; font-size: 13px; color: #0f172a; }
        .sig-label { font-size: 10px; font-weight: 700; color: #64748b; }
        
        @media print { 
            @page { size: 13in 8.5in; margin: 0.25in; }
            body { background: white; padding: 0; display: block; }
            .print-area { width: 100%; padding: 0; box-shadow: none; border-radius: 0; }
            .no-print { display: none; } 
            .info-grid { background: white; border-color: #000; }
            th { background: #f2f2f2 !important; color: #000 !important; }
            .sex-row { background: #000 !important; color: white !important; -webkit-print-color-adjust: exact; }
        }
        .print-btn-fixed { position: fixed; top: 20px; right: 20px; background: #0038a8; color: white; border: none; padding: 14px 30px; border-radius: 50px; font-weight: 800; cursor: pointer; box-shadow: 0 10px 25px rgba(0,56,168,0.3); z-index: 1000; transition: 0.3s; text-transform: uppercase; letter-spacing: 1px; font-size: 12px; }
        .print-btn-fixed:hover { background: #002d86; transform: scale(1.05); }
        .summary-container { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 30px; gap: 40px; }
        .summary-table { width: 300px; border: 1.5px solid #000; table-layout: auto; }
        .summary-table th, .summary-table td { padding: 4px 8px; font-size: 10px; border: 1px solid #000; }
        .summary-table th { background: #f1f5f9; text-align: center; }
        
        .remarks-legend { margin-top: 30px; padding: 15px; border: 1px solid #000; background: #fff; }
        .remarks-legend h4 { margin: 0 0 10px 0; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .legend-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 9px; }
        .legend-item strong { color: #0038a8; }
        
        .signature-row { display: flex; justify-content: space-between; margin-top: 40px; flex: 1; }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-btn-fixed no-print"><i class="bi bi-printer-fill"></i> Print Official SF1</button>
    
    <div class="print-area">
        <div class="official-header">
            <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="logo">
            <div class="header-center">
                <h2>Republic of the Philippines</h2>
                <h1>Department of Education</h1>
                <p>Region III - Central Luzon</p>
                <p style="font-size:14px; margin-top:4px;">Malolos Marine Fishery School and Laboratory</p>
            </div>
            <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="logo">
        </div>

        <div class="report-title">
            <h2>School Form 1 (SF1) School Register</h2>
            <p>(This replaces Form 1, Master List & STS Form 2-Family Background and Profile)</p>
        </div>

        <div class="info-grid">
            <div class="info-cell"><strong>School ID</strong> <span>300750</span></div>
            <div class="info-cell"><strong>Region</strong> <span>III</span></div>
            <div class="info-cell"><strong>Division</strong> <span>Malolos City</span></div>
            <div class="info-cell"><strong>District</strong> <span>Malolos North</span></div>
            <div class="info-cell"><strong>School Year</strong> <span><?= htmlspecialchars($report['school_year']) ?></span></div>
            <div class="info-cell"><strong>Grade Level</strong> <span><?= htmlspecialchars($report['grade_level']) ?></span></div>
            <div class="info-cell"><strong>Section</strong> <span><?= htmlspecialchars($report['section']) ?></span></div>
            <div class="info-cell"><strong>School Name</strong> <span>Malolos Marine Fishery School</span></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:70px;">LRN</th>
                    <th>NAME (Last, First, Middle)</th>
                    <th style="width:25px;">Sex</th>
                    <th style="width:60px;">Birthdate</th>
                    <th style="width:25px;">Age</th>
                    <th style="width:50px;">M.Tongue</th>
                    <th style="width:40px;">IP</th>
                    <th>Religion</th>
                    <th>Address (St/Brgy/City/Prov)</th>
                    <th>Father (Last Name, First Name, Middle Name)</th>
                    <th>Mother (Last Name, First Name, Middle Name)</th>
                    <th>Guardian (Last Name, First Name, Middle Name)</th>
                    <th style="width:60px;">Contact #</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php $current_sex = ''; foreach ($students as $s): 
                    if ($current_sex !== $s['sex']): 
                        $current_sex = $s['sex'];
                ?>
                    <tr class="sex-row"><td colspan="14"><?= $current_sex === 'M' ? 'MALE' : 'FEMALE' ?></td></tr>
                <?php endif; ?>
                    <tr>
                        <td><?= htmlspecialchars($s['lrn']) ?></td>
                        <td class="name-cell"><?= htmlspecialchars($s['full_name'] ?? format_sf1_name($s['last_name'], $s['first_name'], $s['middle_name'], $s['ext_name'])) ?></td>
                        <td><?= $s['sex'] ?></td>
                        <td><?= date('m/d/Y', strtotime($s['birth_date'])) ?></td>
                        <td><?= $s['age_as_of_oct31'] ?></td>
                        <td><?= htmlspecialchars($s['mother_tongue'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($s['ip_ethnicity'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($s['religion'] ?: '-') ?></td>
                        <td style="font-size:8px; text-align:left;">
                            <?= htmlspecialchars($s['house_no_street'] . ', ' . $s['barangay'] . ', ' . $s['municipality_city']) ?>
                        </td>
                        <td><?= htmlspecialchars($s['father_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($s['mother_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($s['guardian_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($s['contact_number'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($s['remarks'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Summary and Signature Section -->
        <div class="summary-container">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>REGISTERED</th>
                        <th>BoSY</th>
                        <th>EoSY</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>MALE</td>
                        <td><?= $report['registered_male_bosy'] ?? 0 ?></td>
                        <td><?= $report['registered_male_eosy'] ?? 0 ?></td>
                    </tr>
                    <tr>
                        <td>FEMALE</td>
                        <td><?= $report['registered_female_bosy'] ?? 0 ?></td>
                        <td><?= $report['registered_female_eosy'] ?? 0 ?></td>
                    </tr>
                    <tr style="font-weight: bold; background: #eee;">
                        <td>TOTAL</td>
                        <td><?= $report['registered_total_bosy'] ?? 0 ?></td>
                        <td><?= $report['registered_total_eosy'] ?? 0 ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="signature-row">
                <div class="sig-box">
                    <p style="text-align:left; margin-bottom:0;">Prepared by:</p>
                    <div class="sig-line"><?= htmlspecialchars($report['prepared_by_name'] ?? '') ?></div>
                    <p style="font-size:9px;">Signature of Adviser over Printed Name</p>
                    <p style="font-size:8px; margin-top: 5px;">Date: <?= ($report['prepared_bosy_date'] ?? null) ? date('M d, Y', strtotime($report['prepared_bosy_date'])) : '__________' ?></p>
                </div>
                <div class="sig-box">
                    <p style="text-align:left; margin-bottom:0;">Certified Correct:</p>
                    <div class="sig-line"><?= strtoupper(htmlspecialchars($report['certified_by_name'] ?: get_system_setting($pdo, 'principal_name', 'School Head'))) ?></div>
                    <p style="font-size:9px;">Signature of School Head over Printed Name</p>
                    <p style="font-size:8px; margin-top: 5px;">Date: <?= ($report['certified_bosy_date'] ?? null) ? date('M d, Y', strtotime($report['certified_bosy_date'])) : '__________' ?></p>
                </div>
            </div>
        </div>

        <!-- Remarks Legend -->
        <div class="remarks-legend">
            <h4>📝 List and Code of Indicators under REMARKS column</h4>
            <div class="legend-grid">
                <div class="legend-item"><strong>TO</strong> - Transferred Out - Name of Public (P) Private (PR) School & Effectivity Date</div>
                <div class="legend-item"><strong>TI</strong> - Transferred In - Name of Public (P) Private (PR) School & Effectivity Date</div>
                <div class="legend-item"><strong>BRP</strong> - Dropped - Reason and Effectivity Date</div>
                <div class="legend-item"><strong>LE</strong> - Late Enrollment - Reason (Enrollment beyond 1st Friday of SY)</div>
                <div class="legend-item"><strong>CCT</strong> - CCT Recipient - CCT Control/reference number & Effectivity Date</div>
                <div class="legend-item"><strong>B/A</strong> - Balik Aral - Name of school last attended & Year</div>
                <div class="legend-item"><strong>SNE</strong> - Special Needs Education - Specify</div>
                <div class="legend-item"><strong>ACL</strong> - Accelerated - Specify Level & Effectivity Data</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 20px; font-size: 8px; color: #666;">
            Generated thru LIS | Created: <?= date('M d, Y H:i', strtotime($report['created_at'] ?? 'now')) ?>
        </div>
    </div>
    
    <script>window.onload = function() { setTimeout(function() { window.print(); }, 500); }</script>
</body>
</html>
