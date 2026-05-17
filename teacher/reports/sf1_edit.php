<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';

$pdo = db_connect();
$report_id = $_GET['id'] ?? null;

if (!$report_id) {
    header('Location: ../reports.php');
    exit;
}

// Get current user info
$current_user = $_SESSION['user'];
$teacher_id = $current_user['id'];

// Get SF1 report details
$stmt = $pdo->prepare("
    SELECT r.*, s.*
    FROM sf1_reports r 
    LEFT JOIN sf1_summary s ON r.id = s.sf1_report_id 
    WHERE r.id = ? AND r.teacher_id = ?
");
$stmt->execute([$report_id, $teacher_id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: ../reports.php');
    exit;
}

// Get student records
$stmt = $pdo->prepare("SELECT * FROM sf1_student_records WHERE sf1_report_id = ? ORDER BY last_name, first_name");
$stmt->execute([$report_id]);
$students = $stmt->fetchAll();

// Get available grade levels and sections
$grade_levels = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

// Get system settings
$school_name = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'")->fetchColumn() ?: 'Malolos Marine Fishery School & Laboratory';
$current_sy = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'")->fetchColumn() ?: '2024-2025';
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');

// Get current teacher's name
$stmt_teacher = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt_teacher->execute([$teacher_id]);
$t_info = $stmt_teacher->fetch();
$adviser_name = trim(($t_info['first_name'] ?? '') . ' ' . ($t_info['last_name'] ?? ''));

$message = '';
$error = '';

// Handle AJAX request for student data by LRN
if (isset($_GET['action']) && $_GET['action'] === 'get_student_by_lrn') {
    $lrn = $_GET['lrn'] ?? '';

    if ($lrn) {
        // First try to get from registrations table (most complete data)
        $stmt = $pdo->prepare("
            SELECT r.*, e.grade_level, e.section 
            FROM registrations r 
            LEFT JOIN enrollments e ON r.id = e.registration_id 
            WHERE r.lrn = ?
            ORDER BY r.created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$lrn]);
        $student = $stmt->fetch();

        if ($student) {
            // Calculate age as of October 31
            $age = null;
            if ($student['birthdate']) {
                $birthDate = new DateTime($student['birthdate']);
                $oct31 = new DateTime($birthDate->format('Y') . '-10-31');

                if ($birthDate > $oct31) {
                    $oct31->modify('+1 year');
                }

                $age = $oct31->diff($birthDate)->y;
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => [
                    'lrn' => $student['lrn'],
                    'last_name' => $student['last_name'],
                    'first_name' => $student['first_name'],
                    'middle_name' => $student['middle_name'],
                    'sex' => (strtoupper(substr($student['sex'] ?? 'M', 0, 1)) === 'M') ? 'M' : 'F',
                    'birth_date' => $student['birthdate'],
                    'age_as_of_oct31' => $age,
                    'mother_tongue' => $student['mother_tongue'],
                    'ip_ethnicity' => $student['ip_ethnicity'],
                    'religion' => null, // Not in registration table
                    'house_no_street' => trim(($student['curr_house_no'] ?? '') . ' ' . ($student['curr_street'] ?? '')),
                    'barangay' => $student['curr_barangay'],
                    'municipality_city' => $student['curr_city'],
                    'province' => $student['curr_province'],
                    'father_last_name' => trim(($student['father_last'] ?? '') . (($student['father_first'] ?? '') || ($student['father_middle'] ?? '') ? ', ' . trim(($student['father_first'] ?? '') . ' ' . ($student['father_middle'] ?? '')) : '')),
                    'father_first_name' => '',
                    'father_middle_name' => '',
                    'mother_last_name' => trim(($student['mother_last'] ?? '') . (($student['mother_first'] ?? '') || ($student['mother_middle'] ?? '') ? ', ' . trim(($student['mother_first'] ?? '') . ' ' . ($student['mother_middle'] ?? '')) : '')),
                    'mother_first_name' => '',
                    'mother_middle_name' => '',
                    'guardian_name' => trim(($student['guardian_last'] ?? '') . (($student['guardian_first'] ?? '') || ($student['guardian_middle'] ?? '') ? ', ' . trim(($student['guardian_first'] ?? '') . ' ' . ($student['guardian_middle'] ?? '')) : '')),
                    'guardian_relationship' => null, // Not in registration table
                    'contact_number' => $student['father_contact'] ?: ($student['mother_contact'] ?: $student['guardian_contact']),
                    'learning_modality' => $student['preferred_modalities'] ? trim(explode(',', $student['preferred_modalities'])[0]) : '',
                    'remarks' => null,
                    'remarks_code' => null
                ]
            ]);
            exit;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_students_by_grade_section') {
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';
    $sy = $_GET['sy'] ?? $current_sy;

    if ($grade_level && $section) {
        $stmt = $pdo->prepare("
            SELECT 
                e.grade_level, e.section,
                COALESCE(r.lrn, e.lrn) AS lrn,
                COALESCE(r.last_name, SUBSTRING_INDEX(e.student_name, ',', 1)) AS last_name,
                COALESCE(r.first_name, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.student_name, ',', -1), ' ', 1))) AS first_name,
                r.middle_name, r.sex, r.birthdate, r.mother_tongue, r.ip_ethnicity,
                r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province,
                r.father_last, r.father_first, r.father_middle, r.father_contact,
                r.mother_last, r.mother_first, r.mother_middle, r.mother_contact,
                r.guardian_last, r.guardian_first, r.guardian_middle, r.guardian_contact,
                r.preferred_modalities
            FROM enrollments e
            LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.lrn)) 
            WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ? AND (e.status IS NULL OR e.status = 'Enrolled')
            ORDER BY r.sex DESC, r.last_name, r.first_name, e.student_name
        ");
        $stmt->execute([$grade_level, $section, $sy]);
        $students_raw = $stmt->fetchAll();

        $formatted = [];
        foreach ($students_raw as $s) {
            $age = null;
            if ($s['birthdate']) {
                $birthDate = new DateTime($s['birthdate']);
                $base_year = (int) explode('-', $sy)[0];
                $oct31 = new DateTime($base_year . '-10-31');
                $age = $oct31->diff($birthDate)->y;
                if ($age < 0) $age = 0;
            }

            $formatted[] = [
                'lrn' => $s['lrn'],
                'last_name' => $s['last_name'],
                'first_name' => $s['first_name'],
                'middle_name' => $s['middle_name'],
                'sex' => (strtoupper(substr($s['sex'] ?? 'M', 0, 1)) === 'M') ? 'M' : 'F',
                'birth_date' => $s['birthdate'],
                'age_as_of_oct31' => $age,
                'mother_tongue' => $s['mother_tongue'],
                'ip_ethnicity' => $s['ip_ethnicity'],
                'house_no_street' => trim(($s['curr_house_no'] ?? '') . ' ' . ($s['curr_street'] ?? '')),
                'barangay' => $s['curr_barangay'],
                'municipality_city' => $s['curr_city'],
                'province' => $s['curr_province'],
                'father_last_name' => trim(($s['father_last'] ?? '') . (($s['father_first'] ?? '') || ($s['father_middle'] ?? '') ? ', ' . trim(($s['father_first'] ?? '') . ' ' . ($s['father_middle'] ?? '')) : '')),
                'father_first_name' => '',
                'father_middle_name' => '',
                'mother_last_name' => trim(($s['mother_last'] ?? '') . (($s['mother_first'] ?? '') || ($s['mother_middle'] ?? '') ? ', ' . trim(($s['mother_first'] ?? '') . ' ' . ($s['mother_middle'] ?? '')) : '')),
                'mother_first_name' => '',
                'mother_middle_name' => '',
                'guardian_name' => trim(($s['guardian_last'] ?? '') . (($s['guardian_first'] ?? '') || ($s['guardian_middle'] ?? '') ? ', ' . trim(($s['guardian_first'] ?? '') . ' ' . ($s['guardian_middle'] ?? '')) : '')),
                'contact_number' => $s['father_contact'] ?: ($s['mother_contact'] ?: $s['guardian_contact']),
                'learning_modality' => $s['preferred_modalities'] ? trim(explode(',', $s['preferred_modalities'])[0]) : ''
            ];
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $formatted]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Grade and section required']);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $school_year = $_POST['school_year'];
        $grade_level = $_POST['grade_level'];
        $section = $_POST['grade_section'];

        // Update SF1 report record
        $stmt = $pdo->prepare("UPDATE sf1_reports SET school_year = ?, grade_level = ?, section = ? WHERE id = ?");
        $stmt->execute([$school_year, $grade_level, $section, $report_id]);

        // Delete existing student records
        $pdo->prepare("DELETE FROM sf1_student_records WHERE sf1_report_id = ?")->execute([$report_id]);

        // Insert updated student records
        if (isset($_POST['students']) && is_array($_POST['students'])) {
            // Validate LRNs before insertion
            $lrn_list = [];
            foreach ($_POST['students'] as $student) {
                if (!empty($student['last_name']) && !empty($student['first_name'])) {
                    $lrn = $student['lrn'] ?? '';

                    if (empty($lrn) || !preg_match('/^[0-9]{12}$/', $lrn)) {
                        throw new Exception("All registered students must have a valid 12-digit LRN. Invalid LRN: " . htmlspecialchars($lrn));
                    }
                    if (in_array($lrn, $lrn_list)) {
                        throw new Exception("Duplicate LRN found in the form: " . htmlspecialchars($lrn));
                    }
                    $lrn_list[] = $lrn;

                    // Check if LRN already exists for this school year in other SF1 reports (exclude current report)
                    $stmt_dup = $pdo->prepare("
                        SELECT r.grade_level, r.section 
                        FROM sf1_student_records sr 
                        JOIN sf1_reports r ON sr.sf1_report_id = r.id 
                        WHERE sr.lrn = ? AND r.school_year = ? AND r.id != ?
                    ");
                    $stmt_dup->execute([$lrn, $school_year, $report_id]);
                    $existing = $stmt_dup->fetch();

                    if ($existing) {
                        throw new Exception("LRN " . htmlspecialchars($lrn) . " is already registered in " . $existing['grade_level'] . " - " . $existing['section'] . " for School Year " . htmlspecialchars($school_year));
                    }
                }
            }

            $student_stmt = $pdo->prepare("
                INSERT INTO sf1_student_records (
                    sf1_report_id, lrn, last_name, first_name, middle_name, sex, birth_date, 
                    age_as_of_oct31, mother_tongue, ip_ethnicity, religion, house_no_street, 
                    barangay, municipality_city, province, father_last_name, father_first_name, 
                    father_middle_name, mother_last_name, mother_first_name, mother_middle_name, 
                    guardian_name, guardian_relationship, contact_number, learning_modality, 
                    remarks, remarks_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($_POST['students'] as $student) {
                if (!empty($student['last_name']) && !empty($student['first_name'])) {
                    $student_stmt->execute([
                        $report_id,
                        $student['lrn'] ?? null,
                        $student['last_name'],
                        $student['first_name'],
                        $student['middle_name'] ?? null,
                        $student['sex'],
                        $student['birth_date'],
                        $student['age_as_of_oct31'] ?? null,
                        $student['mother_tongue'] ?? null,
                        $student['ip_ethnicity'] ?? null,
                        $student['religion'] ?? null,
                        $student['house_no_street'] ?? null,
                        $student['barangay'] ?? null,
                        $student['municipality_city'] ?? null,
                        $student['province'] ?? null,
                        $student['father_last_name'] ?? null,
                        $student['father_first_name'] ?? null,
                        $student['father_middle_name'] ?? null,
                        $student['mother_last_name'] ?? null,
                        $student['mother_first_name'] ?? null,
                        $student['mother_middle_name'] ?? null,
                        $student['guardian_name'] ?? null,
                        $student['guardian_relationship'] ?? null,
                        $student['contact_number'] ?? null,
                        $student['learning_modality'] ?? null,
                        $student['remarks'] ?? null,
                        $student['remarks_code'] ?? null
                    ]);
                }
            }
        }

        // Update summary record
        $summary_stmt = $pdo->prepare("
            UPDATE sf1_summary SET 
                total_male = ?, total_female = ?, total_combined = ?,
                registered_male_bosy = ?, registered_female_bosy = ?, registered_total_bosy = ?,
                registered_male_eosy = ?, registered_female_eosy = ?, registered_total_eosy = ?,
                prepared_by_name = ?, prepared_bosy_date = ?, prepared_eosy_date = ?,
                certified_by_name = ?, certified_bosy_date = ?, certified_eosy_date = ?
            WHERE sf1_report_id = ?
        ");

        $summary_stmt->execute([
            $_POST['summary']['total_male'] ?? 0,
            $_POST['summary']['total_female'] ?? 0,
            $_POST['summary']['total_combined'] ?? 0,
            $_POST['summary']['registered_male_bosy'] ?? 0,
            $_POST['summary']['registered_female_bosy'] ?? 0,
            $_POST['summary']['registered_total_bosy'] ?? 0,
            $_POST['summary']['registered_male_eosy'] ?? 0,
            $_POST['summary']['registered_female_eosy'] ?? 0,
            $_POST['summary']['registered_total_eosy'] ?? 0,
            $_POST['summary']['prepared_by_name'] ?? null,
            $_POST['summary']['prepared_bosy_date'] ?? null,
            $_POST['summary']['prepared_eosy_date'] ?? null,
            $_POST['summary']['certified_by_name'] ?? null,
            $_POST['summary']['certified_bosy_date'] ?? null,
            $_POST['summary']['certified_eosy_date'] ?? null,
            $report_id
        ]);

        $pdo->commit();
        $message = "SF1 report updated successfully!";

        // Refresh data
        $stmt = $pdo->prepare("
            SELECT r.*, s.*
            FROM sf1_reports r 
            LEFT JOIN sf1_summary s ON r.id = s.sf1_report_id 
            WHERE r.id = ? AND r.teacher_id = ?
        ");
        $stmt->execute([$report_id, $teacher_id]);
        $report = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM sf1_student_records WHERE sf1_report_id = ? ORDER BY last_name, first_name");
        $stmt->execute([$report_id]);
        $students = $stmt->fetchAll();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error updating SF1 report: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit SF1 Report - <?= htmlspecialchars($report['grade_level']) ?> <?= htmlspecialchars($report['section']) ?>
    </title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            margin-left: 250px;
            background: white;
            padding: 100px 32px 32px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }

        .header h1 {
            color: #007bff;
            margin: 0;
            font-size: 24px;
        }

        .header p {
            margin: 5px 0 0 0;
            color: #666;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .form-section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .form-section h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
        }

        .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }

        .btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            transition: background-color 0.3s;
            margin-right: 10px;
        }

        .btn:hover {
            background: #0056b3;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .btn-success {
            background: #28a745;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-danger {
            background: #dc3545;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .student-row {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .student-row h4 {
            margin: 0 0 15px 0;
            color: #007bff;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }

        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .student-grid .form-group {
            margin-bottom: 10px;
        }

        .add-student-btn {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
        }

        .add-student-btn:hover {
            background: #1e7e34;
        }

        .remove-student-btn {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            float: right;
        }

        .remove-student-btn:hover {
            background: #c82333;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .remarks-legend {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }

        .remarks-legend h4 {
            margin-top: 0;
            color: #333;
        }

        .legend-item {
            display: flex;
            margin-bottom: 5px;
        }

        .legend-code {
            font-weight: bold;
            width: 30px;
            color: #007bff;
        }

        .legend-desc {
            flex: 1;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 110px 16px 24px;
            }
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="main-content dashboard-container">
        <div class="header">
            <h1>✏️ Edit School Form 1 (SF1) - School Register</h1>
            <p>Edit your SF1 report for <?= htmlspecialchars($report['grade_level']) ?> -
                <?= htmlspecialchars($report['section']) ?>
            </p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- School Information Section -->
            <div class="form-section">
                <h3>🏫 School Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="school_id">School ID:</label>
                        <input type="text" id="school_id" name="school_id" value="300750" readonly>
                    </div>
                    <div class="form-group">
                        <label for="region">Region:</label>
                        <input type="text" id="region" name="region" value="Region III" readonly>
                    </div>
                    <div class="form-group">
                        <label for="division">Division:</label>
                        <input type="text" id="division" name="division" value="Malolos City" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="school_name">School Name:</label>
                        <input type="text" id="school_name" name="school_name"
                            value="<?= htmlspecialchars($school_name) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="school_year">School Year:</label>
                        <input type="text" id="school_year" name="school_year"
                            value="<?= htmlspecialchars($report['school_year']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="grade_level">Grade Level:</label>
                        <select id="grade_level" name="grade_level" required>
                            <option value="">Select Grade Level</option>
                            <?php foreach ($grade_levels as $level): ?>
                                <option value="<?= htmlspecialchars($level) ?>" <?= $report['grade_level'] === $level ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($level) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="grade_section">Section:</label>
                        <select id="grade_section" name="grade_section" required>
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= htmlspecialchars($section) ?>" <?= $report['section'] === $section ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($section) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Student Records Section -->
            <div class="form-section">
                <!-- Bulk Load + Individual Student Action Panel -->
                <div style="background: #eef2ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c7d2fe;">
                    <h4 style="margin-top: 0; margin-bottom: 12px; color: #4338ca;">📥 Update Current Roster</h4>
                    <p style="font-size: 13px; color: #64748b; margin: 0 0 12px 0;">Synchronize this report with the latest enrollment data from the master list.</p>
                    <button type="button" class="btn" onclick="loadEnrolledStudents()" style="background: #4338ca; color: white;">📋 Load All Enrolled Students</button>
                    <span id="bulk-load-status" style="margin-left: 12px; font-size: 14px;"></span>
                </div>

                <div
                    style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0;">
                    <h4 style="margin-top: 0; margin-bottom: 15px; color: var(--text-main);">➕ Add Individual Student
                    </h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end;">
                        <div style="flex: 1; min-width: 250px;">
                            <label for="single_search_lrn"
                                style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px;">Search
                                Existing Student (by LRN):</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="single_search_lrn" placeholder="Enter LRN..."
                                    style="flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <button type="button" class="btn btn-primary" onclick="searchAndAddSingleStudent()"
                                    style="background: var(--primary); color: white;">🔍 Search & Add</button>
                            </div>
                        </div>
                        <div
                            style="display: flex; align-items: center; gap: 15px; padding-left: 20px; border-left: 1px solid #cbd5e1;">
                            <span style="font-size: 14px; font-weight: bold; color: var(--muted);">OR</span>
                            <button type="button" class="btn" onclick="addStudentRow()"
                                style="background: var(--success); color: white;">📝 Add Manual Student</button>
                        </div>
                    </div>
                    <div id="single-search-status" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
                <div id="students-container">
                    <!-- Student rows will be populated here -->
                </div>
            </div>

            <!-- Summary Section -->
            <div class="form-section">
                <h3>📊 Summary</h3>
                <div class="summary-grid">
                    <div class="form-group">
                        <label for="total_male">Total Male:</label>
                        <input type="number" id="total_male" name="summary[total_male]" min="0"
                            value="<?= $report['total_male'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="total_female">Total Female:</label>
                        <input type="number" id="total_female" name="summary[total_female]" min="0"
                            value="<?= $report['total_female'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="total_combined">Total Combined:</label>
                        <input type="number" id="total_combined" name="summary[total_combined]" min="0"
                            value="<?= $report['total_combined'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                </div>

                <h4>Registered Counts</h4>
                <div class="summary-grid">
                    <div class="form-group">
                        <label for="registered_male_bosy">Male (BoSY):</label>
                        <input type="number" id="registered_male_bosy" name="summary[registered_male_bosy]" min="0"
                            value="<?= $report['registered_male_bosy'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_female_bosy">Female (BoSY):</label>
                        <input type="number" id="registered_female_bosy" name="summary[registered_female_bosy]" min="0"
                            value="<?= $report['registered_female_bosy'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_total_bosy">Total (BoSY):</label>
                        <input type="number" id="registered_total_bosy" name="summary[registered_total_bosy]" min="0"
                            value="<?= $report['registered_total_bosy'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_male_eosy">Male (EoSY):</label>
                        <input type="number" id="registered_male_eosy" name="summary[registered_male_eosy]" min="0"
                            value="<?= $report['registered_male_eosy'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_female_eosy">Female (EoSY):</label>
                        <input type="number" id="registered_female_eosy" name="summary[registered_female_eosy]" min="0"
                            value="<?= $report['registered_female_eosy'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_total_eosy">Total (EoSY):</label>
                        <input type="number" id="registered_total_eosy" name="summary[registered_total_eosy]" min="0"
                            value="<?= $report['registered_total_eosy'] ?? 0 ?>" readonly tabindex="-1">
                    </div>
                </div>

                <h4>Signatures</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="prepared_by_name">Prepared by (Name):</label>
                        <input type="text" id="prepared_by_name" name="summary[prepared_by_name]"
                            value="<?= htmlspecialchars($report['prepared_by_name'] ?: $adviser_name) ?>" readonly style="background-color: #f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label for="prepared_bosy_date">BoSY Date:</label>
                        <input type="date" id="prepared_bosy_date" name="summary[prepared_bosy_date]"
                            value="<?= $report['prepared_bosy_date'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <label for="prepared_eosy_date">EoSY Date:</label>
                        <input type="date" id="prepared_eosy_date" name="summary[prepared_eosy_date]"
                            value="<?= $report['prepared_eosy_date'] ?? '' ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="certified_by_name">Certified by (Name):</label>
                        <input type="text" id="certified_by_name" name="summary[certified_by_name]"
                            value="<?= htmlspecialchars($report['certified_by_name'] ?: $principal_name) ?>" readonly style="background-color: #f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label for="certified_bosy_date">BoSY Date:</label>
                        <input type="date" id="certified_bosy_date" name="summary[certified_bosy_date]"
                            value="<?= $report['certified_bosy_date'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <label for="certified_eosy_date">EoSY Date:</label>
                        <input type="date" id="certified_eosy_date" name="summary[certified_eosy_date]"
                            value="<?= $report['certified_eosy_date'] ?? '' ?>">
                    </div>
                </div>
            </div>

            <!-- Remarks Legend -->
            <div class="remarks-legend">
                <h4>📝 List and Code of Indicators under REMARKS column</h4>
                <div class="legend-item">
                    <span class="legend-code">TO</span>
                    <span class="legend-desc">Transferred Out - Name of Public (P) Private (PR) School & Effectivity
                        Date</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">TI</span>
                    <span class="legend-desc">Transferred In - Name of Public (P) Private (PR) School & Effectivity
                        Date</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">BRP</span>
                    <span class="legend-desc">Dropped - Reason and Effectivity Date</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">LE</span>
                    <span class="legend-desc">Late Enrollment - Reason (Enrollment beyond 1st Friday of SY)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">CCT</span>
                    <span class="legend-desc">CCT Recipient - CCT Control/reference number & Effectivity Date</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">B/A</span>
                    <span class="legend-desc">Balik Aral - Name of school last attended & Year</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">SNE</span>
                    <span class="legend-desc">Special Needs Education - Specify</span>
                </div>
                <div class="legend-item">
                    <span class="legend-code">ACL</span>
                    <span class="legend-desc">Accelerated - Specify Level & Effectivity Data</span>
                </div>
            </div>

            <div style="text-align: center; margin-top: 30px;">
                <button type="submit" class="btn btn-success">💾 Update SF1 Report</button>
                <a href="sf1_view.php?id=<?= $report['id'] ?>" class="btn">👁️ View Report</a>
                <a href="../reports.php" class="btn btn-secondary">← Back to Reports</a>
            </div>
        </form>
    </div>

    <script>
        let studentCount = 0;

        function addStudentRow(studentData = null) {
            studentCount++;
            const container = document.getElementById('students-container');
            const studentRow = document.createElement('div');
            studentRow.className = 'student-row';
            const data = studentData || {};
            studentRow.innerHTML = `
                <h4>Student ${studentCount} <button type="button" class="remove-student-btn" onclick="removeStudentRow(this)">Remove</button></h4>
                <div class="student-grid">
                    <div class="form-group">
                        <label>LRN *:</label>
                        <input type="text" name="students[${studentCount}][lrn]" placeholder="12-digit LRN" required pattern="[0-9]{12}" minlength="12" maxlength="12" oninput="this.value = this.value.replace(/[^0-9]/g, '')" value="${data.lrn || ''}">
                    </div>
                    <div class="form-group">
                        <label>Last Name *:</label>
                        <input type="text" name="students[${studentCount}][last_name]" value="${data.last_name || ''}" required>
                    </div>
                    <div class="form-group">
                        <label>First Name *:</label>
                        <input type="text" name="students[${studentCount}][first_name]" value="${data.first_name || ''}" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name:</label>
                        <input type="text" name="students[${studentCount}][middle_name]" value="${data.middle_name || ''}">
                    </div>
                    <div class="form-group">
                        <label>Sex *:</label>
                        <select name="students[${studentCount}][sex]" required onchange="updateSummaryTotals()">
                            <option value="">Select</option>
                            <option value="M" ${data.sex === 'M' || data.sex === 'Male' ? 'selected' : ''}>Male</option>
                            <option value="F" ${data.sex === 'F' || data.sex === 'Female' ? 'selected' : ''}>Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Birth Date *:</label>
                        <input type="date" name="students[${studentCount}][birth_date]" value="${data.birth_date || ''}" required onchange="calculateAge(this, ${studentCount})">
                    </div>
                    <div class="form-group">
                        <label>Age as of Oct 31:</label>
                        <input type="number" name="students[${studentCount}][age_as_of_oct31]" readonly tabindex="-1" value="${data.age_as_of_oct31 || ''}">
                    </div>
                    <div class="form-group">
                        <label>Mother Tongue:</label>
                        <input type="text" name="students[${studentCount}][mother_tongue]" value="${data.mother_tongue || ''}">
                    </div>
                    <div class="form-group">
                        <label>IP (Ethnic Group):</label>
                        <input type="text" name="students[${studentCount}][ip_ethnicity]" value="${data.ip_ethnicity || ''}">
                    </div>
                    <div class="form-group">
                        <label>Religion:</label>
                        <input type="text" name="students[${studentCount}][religion]" value="${data.religion || ''}">
                    </div>
                    <div class="form-group">
                        <label>Address:</label>
                        <input type="text" name="students[${studentCount}][house_no_street]" value="${data.house_no_street || ''}" placeholder="House#/Street">
                    </div>
                    <div class="form-group">
                        <label>Barangay:</label>
                        <input type="text" name="students[${studentCount}][barangay]" value="${data.barangay || ''}">
                    </div>
                    <div class="form-group">
                        <label>Municipality/City:</label>
                        <input type="text" name="students[${studentCount}][municipality_city]" value="${data.municipality_city || ''}">
                    </div>
                    <div class="form-group">
                        <label>Province:</label>
                        <input type="text" name="students[${studentCount}][province]" value="${data.province || ''}">
                    </div>
                    <div class="form-group">
                        <label>Father's Name:</label>
                        <input type="text" name="students[${studentCount}][father_last_name]" value="${data.father_last_name || ''}" placeholder="Last, First Middle">
                    </div>
                    <div class="form-group">
                        <label>Mother's Name:</label>
                        <input type="text" name="students[${studentCount}][mother_last_name]" value="${data.mother_last_name || ''}" placeholder="Last, First Middle">
                    </div>
                    <div class="form-group">
                        <label>Guardian:</label>
                        <input type="text" name="students[${studentCount}][guardian_name]" value="${data.guardian_name || ''}">
                    </div>
                    <div class="form-group">
                        <label>Contact #:</label>
                        <input type="text" name="students[${studentCount}][contact_number]" value="${data.contact_number || ''}">
                    </div>
                    <div class="form-group">
                        <label>Learning Modality:</label>
                        <input type="text" name="students[${studentCount}][learning_modality]" value="${data.learning_modality || ''}">
                    </div>
                    <div class="form-group">
                        <label>Remarks Code:</label>
                        <select name="students[${studentCount}][remarks_code]" onchange="updateSummaryTotals()">
                            <option value="">None</option>
                            <option value="TO" ${data.remarks_code === 'TO' ? 'selected' : ''}>TO</option>
                            <option value="TI" ${data.remarks_code === 'TI' ? 'selected' : ''}>TI</option>
                            <option value="BRP" ${data.remarks_code === 'BRP' ? 'selected' : ''}>BRP</option>
                            <option value="LE" ${data.remarks_code === 'LE' ? 'selected' : ''}>LE</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Remarks:</label>
                        <textarea name="students[${studentCount}][remarks]" placeholder="Additional remarks">${data.remarks || ''}</textarea>
                    </div>
                </div>
            `;
            container.appendChild(studentRow);
        }

        function loadEnrolledStudents() {
            const grade = document.getElementById('grade_level').value;
            const section = document.getElementById('grade_section').value;
            const sy = document.getElementById('school_year').value;
            const statusSpan = document.getElementById('bulk-load-status');

            if (!grade || !section) {
                statusSpan.innerHTML = '<span style="color: #ef4444;">⚠️ Select Grade/Section first.</span>';
                return;
            }

            if (!confirm('This will refresh the roster with the latest data from the master list. Continue?')) return;

            statusSpan.innerHTML = '⏳ Loading...';
            fetch(`?id=<?= $report_id ?>&action=get_students_by_grade_section&grade_level=${encodeURIComponent(grade)}&section=${encodeURIComponent(section)}&sy=${encodeURIComponent(sy)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data) {
                        document.getElementById('students-container').innerHTML = '';
                        studentCount = 0;
                        data.data.forEach(s => addStudentRow(s));
                        updateSummaryTotals();
                        statusSpan.innerHTML = `✅ Loaded ${data.data.length} students!`;
                    } else {
                        statusSpan.innerHTML = '❌ No students found.';
                    }
                });
        }

        function removeStudentRow(btn) {
            btn.closest('.student-row').remove();
            updateSummaryTotals();
        }

        function calculateAge(input, idx) {
            const bday = new Date(input.value);
            if (isNaN(bday)) return;
            const sy = document.getElementById('school_year').value;
            const baseYear = parseInt(sy.split('-')[0]);
            const cutOff = new Date(baseYear, 9, 31);
            let age = cutOff.getFullYear() - bday.getFullYear();
            if (cutOff < new Date(cutOff.getFullYear(), bday.getMonth(), bday.getDate())) age--;
            const ageInput = document.querySelector(`input[name="students[${idx}][age_as_of_oct31]"]`);
            if (ageInput) ageInput.value = age > 0 ? age : 0;
        }

        function updateSummaryTotals() {
            const rows = document.querySelectorAll('.student-row');
            let m = 0, f = 0;
            rows.forEach(row => {
                const sex = row.querySelector('select[name*="[sex]"]').value;
                if (sex === 'M') m++; else if (sex === 'F') f++;
            });
            document.getElementById('total_male').value = m;
            document.getElementById('total_female').value = f;
            document.getElementById('total_combined').value = m + f;
            document.getElementById('registered_male_bosy').value = m;
            document.getElementById('registered_female_bosy').value = f;
            document.getElementById('registered_total_bosy').value = m + f;
            document.getElementById('registered_male_eosy').value = m;
            document.getElementById('registered_female_eosy').value = f;
            document.getElementById('registered_total_eosy').value = m + f;
        }

        function searchAndAddSingleStudent() {
            const lrn = document.getElementById('single_search_lrn').value.trim();
            if (!lrn) return;
            fetch(`?id=<?= $report_id ?>&action=get_student_by_lrn&lrn=${encodeURIComponent(lrn)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        addStudentRow(data.data);
                        updateSummaryTotals();
                        document.getElementById('single_search_lrn').value = '';
                    } else alert('Student not found');
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if (empty($students)): ?>
                addStudentRow();
            <?php else: ?>
                <?php foreach ($students as $s): ?>
                    addStudentRow(<?= json_encode($s) ?>);
                <?php endforeach; ?>
            <?php endif; ?>
            updateSummaryTotals();
        });
    </script>
</body>

</html>