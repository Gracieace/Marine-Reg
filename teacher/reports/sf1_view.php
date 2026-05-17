<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/report_export_helper.php';

$pdo = db_connect();
$report_id = $_GET['id'] ?? null;
$is_live = ($report_id === 'live');

// Get current user info
$current_user = $_SESSION['user'];
$teacher_id = $current_user['id'];

// Get system settings
$school_name = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'")->fetchColumn() ?: 'Malolos Marine Fishery School & Laboratory';
$current_sy = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'")->fetchColumn() ?: '2024-2025';

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
    // Live mode: Use parameters from GET or teacher's advisory
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';
    $school_year = $_GET['school_year'] ?? $current_sy;

    if (!$grade_level || !$section) {
        // Fallback to advisory class
        $stmt = $pdo->prepare('SELECT * FROM position_assignments WHERE user_id = ? AND position_type = "class_adviser" AND school_year = ?');
        $stmt->execute([$teacher_id, $school_year]);
        $advisory = $stmt->fetch();
        if ($advisory) {
            $grade_level = $advisory['grade_level'];
            $section = $advisory['section'];
        }
    }

    if ($grade_level && $section) {
        $report = [
            'id' => 'live',
            'school_year' => $school_year,
            'grade_level' => $grade_level,
            'section' => $section,
            'teacher_name' => $current_user['username'],
            'prepared_by_name' => $current_user['first_name'] . ' ' . $current_user['last_name'],
            'certified_by_name' => get_system_setting($pdo, 'principal_name', 'School Head'),
            'created_at' => date('Y-m-d H:i:s'),
            'total_male' => 0,
            'total_female' => 0,
            'total_combined' => 0
        ];

        // Fetch live student records
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
            // Map registration data to SF1 format
            $age = null;
            if ($s['birthdate']) {
                $birthDate = new DateTime($s['birthdate']);
                $sy_parts = explode('-', $school_year);
                $oct31 = new DateTime($sy_parts[0] . '-10-31');
                if ($birthDate > $oct31)
                    $oct31->modify('+1 year');
                $age = $oct31->diff($birthDate)->y;
            }

            $students[] = [
                'lrn' => $s['lrn'],
                'last_name' => $s['last_name'],
                'first_name' => $s['first_name'],
                'middle_name' => $s['middle_name'],
                'ext_name' => $s['ext_name'] ?? '',
                'full_name' => format_sf1_name($s['last_name'] ?? '', $s['first_name'] ?? '', $s['middle_name'] ?? '', $s['ext_name'] ?? ''),
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
                    $s['father_last'] ?: ($s['father_last_name'] ?? ''),
                    $s['father_first'] ?: ($s['father_first_name'] ?? ''),
                    $s['father_middle'] ?: ($s['father_middle_name'] ?? '')
                ),
                'mother_name' => format_sf1_name(
                    $s['mother_last'] ?: ($s['mother_last_name'] ?? ''),
                    $s['mother_first'] ?: ($s['mother_first_name'] ?? ''),
                    $s['mother_middle'] ?: ($s['mother_middle_name'] ?? '')
                ),
                'guardian_name' => format_sf1_name(
                    $s['guardian_last'] ?: ($s['guardian_name'] ?? ''),
                    $s['guardian_first'] ?? '',
                    $s['guardian_middle'] ?? ''
                ),
                'guardian_relationship' => $s['guardian_relationship'] ?? '',
                'contact_number' => ($s['id_contact_person'] === 'father') ? ($s['father_contact'] ?: $s['guardian_contact']) : 
                                   (($s['id_contact_person'] === 'mother') ? ($s['mother_contact'] ?: $s['guardian_contact']) : 
                                   ($s['guardian_contact'] ?: ($s['father_contact'] ?: $s['mother_contact']))),
                'learning_modality' => $s['preferred_modalities'],
                'remarks_code' => '',
                'remarks' => ''
            ];

            if ($s['sex'] === 'M')
                $report['total_male']++;
            else
                $report['total_female']++;
        }
        $report['total_combined'] = $report['total_male'] + $report['total_female'];
        $report['registered_male_bosy'] = $report['total_male'];
        $report['registered_female_bosy'] = $report['total_female'];
        $report['registered_total_bosy'] = $report['total_combined'];
        $report['registered_male_eosy'] = $report['total_male'];
        $report['registered_female_eosy'] = $report['total_female'];
        $report['registered_total_eosy'] = $report['total_combined'];
    }
} else {
    if (!$report_id) {
        header('Location: ../reports.php');
        exit;
    }
    // Get SF1 report details from snapshot
    $stmt = $pdo->prepare("
        SELECT r.*, s.*, u.username as teacher_name
        FROM sf1_reports r 
        LEFT JOIN sf1_summary s ON r.id = s.sf1_report_id 
        LEFT JOIN users u ON r.teacher_id = u.id
        WHERE r.id = ? AND r.teacher_id = ?
    ");
    $stmt->execute([$report_id, $teacher_id]);
    $report = $stmt->fetch();

    if (!$report) {
        header('Location: ../reports.php');
        exit;
    }

    // Get student records from snapshot with live registration data fallback
    try {
        $stmt = $pdo->prepare("
            SELECT sr.*, 
                   r.last_name as reg_last_name, r.first_name as reg_first_name, r.middle_name as reg_middle_name, r.ext_name as reg_ext_name,
                   r.father_last as reg_f_last, r.father_first as reg_f_first, r.father_middle as reg_f_mid,
                   r.mother_last as reg_m_last, r.mother_first as reg_m_first, r.mother_middle as reg_m_mid,
                   r.guardian_last as reg_g_last, r.guardian_first as reg_g_first, r.guardian_middle as reg_g_mid,
                   r.guardian_relationship as reg_g_rel, r.religion as reg_religion,
                   r.father_contact, r.mother_contact, r.guardian_contact, r.id_contact_person,
                   r.preferred_modalities as reg_modality,
                   r.mother_tongue as reg_mt, r.ip_ethnicity as reg_ip,
                   r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province
            FROM sf1_student_records sr 
            LEFT JOIN registrations r ON sr.lrn = r.lrn
            WHERE sr.sf1_report_id = ? 
            ORDER BY sr.sex DESC, sr.last_name, sr.first_name
        ");
        $stmt->execute([$report_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map live registration data over saved snapshot data if available
        foreach ($students as &$s) {
            // Check if registration data exists (using a key from the joined r table that is guaranteed to be non-null if the row exists)
            if (array_key_exists('reg_last_name', $s) && $s['reg_last_name'] !== null) {
                // Student Name from registration
                $s['last_name'] = $s['reg_last_name'];
                $s['first_name'] = $s['reg_first_name'];
                $s['middle_name'] = $s['reg_middle_name'];
                $s['ext_name'] = $s['reg_ext_name'];
                
                // Father Name from registration
                $f_last = $s['reg_f_last'] ?: ($s['father_last_name'] ?? '');
                $f_first = $s['reg_f_first'] ?: ($s['father_first_name'] ?? '');
                $f_mid = $s['reg_f_mid'] ?: ($s['father_middle_name'] ?? '');
                $s['father_name'] = format_sf1_name($f_last, $f_first, $f_mid);
                
                // Mother Name from registration
                $m_last = $s['reg_m_last'] ?: ($s['mother_last_name'] ?? '');
                $m_first = $s['reg_m_first'] ?: ($s['mother_first_name'] ?? '');
                $m_mid = $s['reg_m_mid'] ?: ($s['mother_middle_name'] ?? '');
                $s['mother_name'] = format_sf1_name($m_last, $m_first, $m_mid);

                // Guardian Details from registration
                $g_last = $s['reg_g_last'] ?: ($s['guardian_name'] ?? '');
                $g_first = $s['reg_g_first'] ?: '';
                $g_mid = $s['reg_g_mid'] ?: '';
                $s['guardian_name'] = format_sf1_name($g_last, $g_first, $g_mid);
                $s['guardian_relationship'] = $s['reg_g_rel'] ?? $s['guardian_relationship'];

                // Override contact number based on live preference
                $s['contact_number'] = ($s['id_contact_person'] === 'father') ? ($s['father_contact'] ?: $s['guardian_contact']) : 
                                       (($s['id_contact_person'] === 'mother') ? ($s['mother_contact'] ?: $s['guardian_contact']) : 
                                       ($s['guardian_contact'] ?: ($s['father_contact'] ?: $s['mother_contact'])));

                $s['religion'] = $s['reg_religion'] ?? $s['religion'];
                $s['learning_modality'] = $s['reg_modality'] ?? $s['learning_modality'];
                
                // Override address and other details
                $s['mother_tongue'] = $s['reg_mt'] ?? $s['mother_tongue'];
                $s['ip_ethnicity'] = $s['reg_ip'] ?? $s['ip_ethnicity'];
                $s['house_no_street'] = trim(($s['curr_house_no'] ?? '') . ' ' . ($s['curr_street'] ?? '')) ?: $s['house_no_street'];
                $s['barangay'] = $s['curr_barangay'] ?? $s['barangay'];
                $s['municipality_city'] = $s['curr_city'] ?? $s['municipality_city'];
                $s['province'] = $s['curr_province'] ?? $s['province'];
            } else {
                // Fallback for students without a registration record (use saved snapshot)
                $s['father_name'] = format_sf1_name($s['father_last_name'] ?? '', $s['father_first_name'] ?? '', $s['father_middle_name'] ?? '');
                $s['mother_name'] = format_sf1_name($s['mother_last_name'] ?? '', $s['mother_first_name'] ?? '', $s['mother_middle_name'] ?? '');
                $s['guardian_name'] = format_sf1_name($s['guardian_name'] ?? '', '', '');
            }
            
            $s['full_name'] = format_sf1_name($s['last_name'] ?? '', $s['first_name'] ?? '', $s['middle_name'] ?? '', $s['ext_name'] ?? '');
        }
        unset($s);
    } catch (Exception $e) {
        // Fallback to pure snapshot if join fails (e.g. schema issues)
        $stmt = $pdo->prepare("SELECT * FROM sf1_student_records WHERE sf1_report_id = ? ORDER BY sex DESC, last_name, first_name");
        $stmt->execute([$report_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($students as &$s) {
            $s['full_name'] = format_sf1_name($s['last_name'] ?? '', $s['first_name'] ?? '', $s['middle_name'] ?? '', $s['ext_name'] ?? '');
            $s['father_name'] = format_sf1_name($s['father_last_name'] ?? '', $s['father_first_name'] ?? '', $s['father_middle_name'] ?? '');
            $s['mother_name'] = format_sf1_name($s['mother_last_name'] ?? '', $s['mother_first_name'] ?? '', $s['mother_middle_name'] ?? '');
        }
        unset($s);
    }
}

// Handle Export Requests
$export = $_GET['export'] ?? null;
if ($export && !empty($students)) {
    $filename = "SF1_" . str_replace(' ', '_', $report['grade_level'] . "_" . $report['section']) . "_" . date('Y-m-d');

    if ($export === 'xlsx') {
        $headers = [
            'LRN',
            'Last Name',
            'First Name',
            'Middle Name',
            'Sex',
            'Birth Date',
            'Age',
            'Mother Tongue',
            'IP/Ethnicity',
            'Religion',
            'House No/Street',
            'Barangay',
            'Municipality/City',
            'Province',
            'Father Name',
            'Mother Name',
            'Guardian Name',
            'Relationship',
            'Contact Number',
            'Learning Modality',
            'Remarks'
        ];
        $excelData = [];
        foreach ($students as $s) {

            $excelData[] = [
                $s['lrn'],
                $s['full_name'] ?? '',
                $s['sex'],
                $s['birth_date'],
                $s['age_as_of_oct31'],
                $s['mother_tongue'] ?? '',
                $s['ip_ethnicity'] ?? '',
                $s['religion'] ?? '',
                $s['house_no_street'] ?? '',
                $s['barangay'] ?? '',
                $s['municipality_city'] ?? '',
                $s['province'] ?? '',
                trim($s['father_name'] ?? '', ', '),
                trim($s['mother_name'] ?? '', ', '),
                $s['guardian_name'] ?? '',
                $s['guardian_relationship'] ?? '',
                $s['contact_number'] ?? '',
                $s['learning_modality'] ?? '',
                ($s['remarks_code'] ?? '') . ' ' . ($s['remarks'] ?? '')
            ];
        }
        exportToExcel($excelData, $headers, $filename, "SF1 Report");
    } elseif ($export === 'pdf') {
        ob_start();
        ?>
        <style>
            @page {
                size: legal landscape;
                margin: 0.5in;
            }

            body {
                font-family: sans-serif;
                font-size: 9pt;
            }

            .header-table {
                width: 100%;
                border: none;
                margin-bottom: 10px;
            }

            .header-table td {
                border: none;
                padding: 0;
                vertical-align: middle;
            }

            .logo {
                height: 60px;
            }

            .title-box {
                text-align: center;
            }

            .title-box h1 {
                margin: 0;
                font-size: 16pt;
            }

            .title-box p {
                margin: 0;
                font-size: 9pt;
                font-style: italic;
            }

            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 10px;
                font-size: 8pt;
            }

            .info-table td {
                border: 1px solid #000;
                padding: 3px;
            }

            .students-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 7pt;
            }

            .students-table th,
            .students-table td {
                border: 1px solid #000;
                padding: 2px;
            }

            .students-table th {
                background: #eee;
                text-transform: uppercase;
            }

            .sex-row {
                background: #f0f0f0;
                font-weight: bold;
                text-align: center;
            }

            .sig-table {
                width: 100%;
                margin-top: 20px;
                border: none;
            }

            .sig-table td {
                border: none;
                text-align: center;
                width: 50%;
            }

            .sig-line {
                border-bottom: 1px solid #000;
                margin: 20px 20px 5px;
                font-weight: bold;
                text-transform: uppercase;
            }
        </style>

        <table class="header-table">
            <tr>
                <td style="width: 15%"><img src="assets/images/deped_logo.png" class="logo"></td>
                <td style="width: 70%; text-align: center;">
                    <p style="margin:0">Republic of the Philippines</p>
                    <p style="margin:0; font-size: 12pt; font-weight: bold; color: #0038a8;">Department of Education</p>
                    <p style="margin:0">Region III - Central Luzon</p>
                    <p style="margin:0; font-weight: bold;">Malolos Marine Fishery School and Laboratory</p>
                </td>
                <td style="width: 15%; text-align: right;"><img src="assets/images/school_logo.png" class="logo"></td>
            </tr>
        </table>

        <div class="title-box">
            <h1>School Form 1 (SF1) School Register</h1>
            <p>(This replaces Form 1, Master List & STS Form 2-Family Background and Profile)</p>
        </div>

        <table class="info-table">
            <tr>
                <td><strong>School ID:</strong> 300750</td>
                <td><strong>Region:</strong> III</td>
                <td><strong>Division:</strong> Malolos City</td>
                <td><strong>District:</strong> Malolos North</td>
            </tr>
            <tr>
                <td><strong>School Name:</strong> <?= htmlspecialchars($school_name) ?></td>
                <td><strong>School Year:</strong> <?= htmlspecialchars($report['school_year']) ?></td>
                <td><strong>Grade Level:</strong> <?= htmlspecialchars($report['grade_level']) ?></td>
                <td><strong>Section:</strong> <?= htmlspecialchars($report['section']) ?></td>
            </tr>
        </table>

        <table class="students-table">
            <thead>
                <tr>
                    <th rowspan="2">LRN</th>
                    <th rowspan="2">NAME</th>
                    <th rowspan="2">Sex</th>
                    <th rowspan="2">Birthdate</th>
                    <th rowspan="2">Age</th>
                    <th colspan="4">Address</th>
                    <th colspan="2">Parents</th>
                    <th colspan="2">Guardian</th>
                    <th rowspan="2">Contact</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <th>St/Purok</th>
                    <th>Brgy</th>
                    <th>City</th>
                    <th>Prov</th>
                    <th>Father</th>
                    <th>Mother</th>
                    <th>Name</th>
                    <th>Rel</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $current_sex = '';
                foreach ($students as $student):
                    if ($current_sex !== $student['sex']):
                        $current_sex = $student['sex'];
                        ?>
                        <tr class="sex-row">
                            <td colspan="15"><?= $current_sex === 'M' ? 'MALE' : 'FEMALE' ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><?= htmlspecialchars($student['lrn']) ?></td>
                        <td><?= htmlspecialchars($student['full_name'] ?? '') ?></td>
                        <td style="text-align: center;"><?= $student['sex'] ?></td>
                        <td><?= $student['birth_date'] ?></td>
                        <td style="text-align: center;"><?= $student['age_as_of_oct31'] ?></td>
                        <td><?= htmlspecialchars($student['house_no_street']) ?></td>
                        <td><?= htmlspecialchars($student['barangay']) ?></td>
                        <td><?= htmlspecialchars($student['municipality_city']) ?></td>
                        <td><?= htmlspecialchars($student['province']) ?></td>
                        <td><?= htmlspecialchars($student['father_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['mother_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['guardian_name']) ?></td>
                        <td><?= htmlspecialchars($student['guardian_relationship']) ?></td>
                        <td><?= htmlspecialchars($student['contact_number']) ?></td>
                        <td><?= htmlspecialchars($student['remarks_code']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="sig-table">
            <tr>
                <td>
                    <p style="margin:0; text-align: left;">Prepared by:</p>
                    <div class="sig-line"><?= htmlspecialchars($report['prepared_by_name']) ?></div>
                    <p style="margin:0; font-size: 8pt;">Signature of Adviser over Printed Name</p>
                </td>
                <td>
                    <p style="margin:0; text-align: left;">Certified Correct:</p>
                    <div class="sig-line"><?= htmlspecialchars($report['certified_by_name']) ?></div>
                    <p style="margin:0; font-size: 8pt;">Signature of School Head over Printed Name</p>
                </td>
            </tr>
        </table>
        <?php
        $html = ob_get_clean();
        exportToPDF($html, $filename, 'landscape', 'legal');
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View SF1 Report - <?= htmlspecialchars($report['grade_level']) ?> <?= htmlspecialchars($report['section']) ?>
    </title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --deped-blue: #0038a8;
            --deped-red: #ce1126;
            --sidebar-width: 260px;
            --header-height: 70px;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f1f5f9;
            overflow-x: hidden;
        }

        /* Screen only styles */
        @media screen {
            .main-content {
                margin-left: var(--sidebar-width);
                padding: 130px 40px 60px;
                background: white;
                min-height: 100vh;
                box-sizing: border-box;
                transition: margin-left 0.3s ease;
                box-shadow: -4px 0 15px rgba(0, 0, 0, 0.02);
            }

            .no-print {
                display: block;
            }

            /* Responsive Table Wrapper */
            .table-responsive {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin-bottom: 2rem;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            }

            .students-table {
                min-width: 1500px; /* Ensure table doesn't squish on small screens */
            }
        }

        /* Information Grid */
        .school-info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 35px;
            background: #f8fafc;
            padding: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .info-cell {
            font-size: 13px;
        }

        .info-cell strong {
            color: var(--deped-blue);
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 6px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .info-cell span {
            font-weight: 700;
            color: #0f172a;
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
        }

        /* Table Styling */
        .students-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1.5px solid #000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }

        .students-table th,
        .students-table td {
            border: 1px solid #000;
            padding: 8px 6px;
        }

        .students-table th {
            background: #f8fafc;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 9px;
            color: #1e293b;
            font-family: 'Outfit', sans-serif;
        }

        .sex-separator {
            background: #f1f5f9;
            font-weight: 900;
            text-align: center;
            font-size: 13px;
            letter-spacing: 3px;
            color: #0f172a;
            height: 40px;
        }

        /* Summary & Signatures */
        .summary-container {
            margin-top: 40px;
            display: flex;
            gap: 50px;
            align-items: flex-start;
        }

        .summary-table {
            width: 350px;
            border-collapse: collapse;
            font-size: 12px;
            border: 1.5px solid #000;
        }

        .summary-table th,
        .summary-table td {
            border: 1px solid #000;
            padding: 10px;
        }

        .summary-table th {
            background: #f8fafc;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
        }

        .signature-grid {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }

        .sig-box {
            text-align: center;
        }

        .sig-name {
            border-bottom: 2px solid #000;
            margin: 30px 0 8px;
            font-weight: 800;
            font-size: 15px;
            text-transform: uppercase;
            min-height: 25px;
            font-family: 'Outfit', sans-serif;
        }

        .sig-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
        }

        /* Responsive Sidebar Fix */
        @media screen and (max-width: 1100px) {
            .main-content {
                margin-left: 0;
                padding: 130px 20px 40px;
            }

            .action-bar {
                right: 20px;
                top: 90px;
            }
        }

        /* Print only styles - DepEd SF1 Format */
        @media print {
            @page {
                size: legal landscape;
                margin: 0.5in;
            }

            html,
            body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                box-shadow: none !important;
                background: white !important;
            }

            .no-print,
            .action-bar,
            .teacher-sidebar,
            .header-container,
            .sidebar,
            .sidebar-overlay,
            .teacher-header,
            .topbar {
                display: none !important;
            }

            .official-header {
                display: flex !important;
                justify-content: center !important;
                gap: 50px !important;
            }
        }

        .official-header {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 60px;
            margin-bottom: 30px;
            border-bottom: 3px solid #0f172a;
            padding-bottom: 20px;
        }

        .header-center {
            text-align: center;
        }

        .header-center h2 {
            margin: 0;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 2px;
        }

        .header-center h1 {
            margin: 8px 0;
            font-size: 26px;
            color: var(--deped-blue);
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
        }

        .header-center p {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #1e293b;
        }

        .logo-box img {
            height: 80px;
            width: auto;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
        }

        .report-title-box {
            text-align: center;
            margin-bottom: 30px;
        }

        .report-title-box h1 {
            margin: 0;
            font-size: 24px;
            color: #0f172a;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
        }

        .report-title-box p {
            margin: 6px 0;
            font-style: italic;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }

        /* Professional Action Bar */
        .action-bar {
            position: fixed;
            top: 90px;
            right: 40px;
            z-index: 1001;
            display: flex;
            gap: 12px;
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 20px;
            border-radius: 50px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .btn-action {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            border: none;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-print { background: #2563eb; color: white; }
        .btn-print:hover { background: #1d4ed8; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3); }

        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3); }

        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3); }

        .btn-back { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
        .btn-back:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; transform: translateY(-3px); }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="action-bar no-print">
        <a href="sf1_form.php?grade_level=<?= urlencode($report['grade_level'] ?? $grade_level ?? '') ?>&section=<?= urlencode($report['section'] ?? $section ?? '') ?>"
            class="btn-action btn-back">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Back
        </a>
        <a href="sf1_print.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn-action btn-print">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"></polyline>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                <rect x="6" y="14" width="12" height="8"></rect>
            </svg>
            Print SF1
        </a>
    </div>

    <div class="main-content">
        <!-- Official Header -->
        <div class="official-header">
            <div class="logo-box">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" alt="DepEd Logo">
            </div>
            <div class="header-center">
                <p>Republic of the Philippines</p>
                <h2>Department of Education</h2>
                <p>Region III - Central Luzon</p>
                <h3>Malolos Marine Fishery School and Laboratory</h3>
                <p>City of Malolos, Bulacan</p>
            </div>
            <div class="logo-box">
                <img src="<?= url_for('/assets/images/school_logo.png') ?>" alt="School Logo">
            </div>
        </div>

        <div class="report-title-box">
            <h1>School Form 1 (SF1) School Register</h1>
            <p>(This replaces Form 1, Master List & STS Form 2-Family Background and Profile)</p>
        </div>

        <div class="school-info-grid">
            <div class="info-cell"><strong>School ID:</strong> <span>300750</span></div>
            <div class="info-cell"><strong>Region:</strong> <span>III</span></div>
            <div class="info-cell"><strong>Division:</strong> <span>Malolos City</span></div>
            <div class="info-cell"><strong>District:</strong> <span>Malolos North</span></div>
            <div class="info-cell"><strong>School Name:</strong> <span><?= htmlspecialchars($school_name) ?></span>
            </div>
            <div class="info-cell"><strong>School Year:</strong>
                <span><?= htmlspecialchars($report['school_year']) ?></span></div>
            <div class="info-cell"><strong>Grade Level:</strong>
                <span><?= htmlspecialchars($report['grade_level']) ?></span></div>
            <div class="info-cell"><strong>Section:</strong> <span><?= htmlspecialchars($report['section']) ?></span>
            </div>
        </div>

        <!-- Student Records Table -->
        <div class="table-responsive">
            <table class="students-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 100px;">LRN</th>
                    <th rowspan="2" style="width: 200px;">NAME<br>(Last Name, First Name, Middle Name)</th>
                    <th rowspan="2" style="width: 30px;">Sex</th>
                    <th rowspan="2" style="width: 80px;">BIRTH DATE<br>(mm/dd/yyyy)</th>
                    <th rowspan="2" style="width: 40px;">Age</th>
                    <th rowspan="2" style="width: 80px;">Mother Tongue</th>
                    <th rowspan="2" style="width: 50px;">IP</th>
                    <th rowspan="2" style="width: 80px;">Religion</th>
                    <th colspan="4">ADDRESS</th>
                    <th colspan="2">PARENTS</th>
                    <th colspan="2" style="width: 120px;">GUARDIAN</th>
                    <th rowspan="2" style="width: 90px;">Contact Number</th>
                    <th rowspan="2" style="width: 60px;">Modality</th>
                    <th rowspan="2" style="width: 150px;">REMARKS</th>
                </tr>
                <tr>
                    <th style="font-size: 8px;">Street/Purok</th>
                    <th style="font-size: 8px;">Barangay</th>
                    <th style="font-size: 8px;">City</th>
                    <th style="font-size: 8px;">Province</th>
                    <th style="font-size: 8px;">Father<br>(Last Name, First Name, Middle Name)</th>
                    <th style="font-size: 8px;">Mother<br>(Last Name, First Name, Middle Name)</th>
                    <th style="font-size: 8px;">Name</th>
                    <th style="font-size: 8px;">Relation</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $current_sex = '';
                $counter = 0;
                foreach ($students as $student):
                    if ($current_sex !== $student['sex']):
                        $current_sex = $student['sex'];
                        $sex_label = ($current_sex === 'M') ? 'MALE' : 'FEMALE';
                        $counter = 0;
                        ?>
                        <tr class="sex-separator">
                            <td colspan="19"><?= $sex_label ?></td>
                        </tr>
                    <?php endif;
                    $counter++; ?>
                    <tr>
                        <td style="text-align: center; position: relative;">
                            <span style="position: absolute; left: 4px; font-size: 8px; color: #888;"><?= $counter ?></span>
                            <?= htmlspecialchars($student['lrn'] ?? '') ?>
                        </td>
                        <td><?= htmlspecialchars($student['full_name'] ?? '') ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($student['sex']) ?></td>
                        <td style="text-align: center;"><?= date('m/d/Y', strtotime($student['birth_date'])) ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($student['age_as_of_oct31'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['mother_tongue'] ?? '') ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($student['ip_ethnicity'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['religion'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['house_no_street'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['barangay'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['municipality_city'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['province'] ?? '') ?></td>
                        <td style="font-size: 8px;"><?= htmlspecialchars($student['father_name'] ?? '') ?></td>
                        <td style="font-size: 8px;"><?= htmlspecialchars($student['mother_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($student['guardian_name'] ?? '') ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($student['guardian_relationship'] ?? '') ?>
                        </td>
                        <td><?= htmlspecialchars($student['contact_number'] ?? '') ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($student['learning_modality'] ?? '') ?></td>
                        <td style="font-size: 9px;"><?= htmlspecialchars($student['remarks_code'] ?? '') ?>
                            <?= htmlspecialchars($student['remarks'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Summary Section -->
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

            <div class="signature-grid">
                <div class="sig-box">
                    <p style="font-size: 12px; text-align: left; margin-bottom: 0;">Prepared by:</p>
                    <div class="sig-name"><?= htmlspecialchars($report['prepared_by_name'] ?? '') ?></div>
                    <p class="sig-label">Signature of Adviser over Printed Name</p>
                    <p style="font-size: 10px;">Date:
                        <?= $report['prepared_bosy_date'] ? date('M d, Y', strtotime($report['prepared_bosy_date'])) : '__________' ?>
                    </p>
                </div>
                <div class="sig-box">
                    <p style="font-size: 12px; text-align: left; margin-bottom: 0;">Certified Correct:</p>
                    <div class="sig-name"><?= strtoupper(htmlspecialchars($report['certified_by_name'] ?: get_system_setting($pdo, 'principal_name', 'School Head'))) ?></div>
                    <p class="sig-label">Signature of School Head over Printed Name</p>
                    <p style="font-size: 10px; color: #64748b; margin-top: -5px;">(Signature over Printed Name)</p>
                    <p style="font-size: 10px;">Date:
                        <?= ($report['certified_bosy_date'] ?? null) ? date('M d, Y', strtotime($report['certified_bosy_date'])) : '__________' ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Remarks Legend -->
        <div
            style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin-top: 20px;">
            <h4>📝 List and Code of Indicators under REMARKS column</h4>
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 10px; font-size: 12px;">
                <div><strong>TO</strong> - Transferred Out - Name of Public (P) Private (PR) School & Effectivity Date
                </div>
                <div><strong>TI</strong> - Transferred In - Name of Public (P) Private (PR) School & Effectivity Date
                </div>
                <div><strong>BRP</strong> - Dropped - Reason and Effectivity Date</div>
                <div><strong>LE</strong> - Late Enrollment - Reason (Enrollment beyond 1st Friday of SY)</div>
                <div><strong>CCT</strong> - CCT Recipient - CCT Control/reference number & Effectivity Date</div>
                <div><strong>B/A</strong> - Balik Aral - Name of school last attended & Year</div>
                <div><strong>SNE</strong> - Special Needs Education - Specify</div>
                <div><strong>ACL</strong> - Accelerated - Specify Level & Effectivity Data</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #666;">
            Generated thru LIS | Created: <?= date('M d, Y H:i', strtotime($report['created_at'])) ?>
        </div>

        </div>
    </div>
    <script src="<?= url_for('/js/report_utils.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof initReportSearch === 'function') {
                initReportSearch('reportSearch', 'sf1Table');
            }
        });
    </script>
</body>

</html>