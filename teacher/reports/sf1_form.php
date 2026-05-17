<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';

$pdo = db_connect();
$message = '';
$error = '';

// Get current user info
$current_user = $_SESSION['user'];
$teacher_id = $current_user['id'];

// Robust name fetching
$stmt_teacher = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt_teacher->execute([$teacher_id]);
$t_info = $stmt_teacher->fetch();
$adviser_name = trim(($t_info['first_name'] ?? '') . ' ' . ($t_info['last_name'] ?? ''));

// Get system settings
$school_name = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'")->fetchColumn() ?: 'Malolos Marine Fishery School & Laboratory';
$current_sy = get_active_school_year($pdo);
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');

// Get Advisory Class (if any)
$stmt = $pdo->prepare('SELECT * FROM position_assignments WHERE user_id = ? AND position_type = "class_adviser" AND school_year = ?');
$stmt->execute([$teacher_id, $current_sy]);
$advisory_class = $stmt->fetch();

$grade_levels = [];
$sections = [];

if ($advisory_class) {
    if (!empty($advisory_class['grade_level'])) {
        $grade_levels[] = $advisory_class['grade_level'];
    }
    if (!empty($advisory_class['section'])) {
        $sections[] = $advisory_class['section'];
    }
}

// Support for pre-selection via URL parameters (from dashboard)
$target_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$target_section = $_GET['section'] ?? '';

if ($target_grade && !in_array($target_grade, $grade_levels)) $grade_levels[] = $target_grade;
if ($target_section && !in_array($target_section, $sections)) $sections[] = $target_section;

$message = '';
$error = '';

// Handle delete success/error messages
if (isset($_GET['deleted'])) {
    $message = "SF1 report deleted successfully!";
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'get_student_by_lrn') {
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
                $age = null;
                if ($student['birthdate']) {
                    $birthDate = new DateTime($student['birthdate']);
                    // Parse base year from $sy or current year
                    $sy_param = $_GET['sy'] ?? $current_sy;
                    $base_year = (int) explode('-', $sy_param)[0];
                    $oct31 = new DateTime($base_year . '-10-31');

                    $age = $oct31->diff($birthDate)->y;
                    if ($age < 0) $age = 0;
                }

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
                        'father_last_name' => $student['father_last'],
                        'father_first_name' => $student['father_first'],
                        'father_middle_name' => $student['father_middle'],
                        'mother_last_name' => $student['mother_last'],
                        'mother_first_name' => $student['mother_first'],
                        'mother_middle_name' => $student['mother_middle'],
                        'guardian_name' => trim(($student['guardian_last'] ?? '') . ' ' . ($student['guardian_first'] ?? '') . ' ' . ($student['guardian_middle'] ?? '')),
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

        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    if ($_GET['action'] === 'get_students_by_grade_section') {
        $grade_level = $_GET['grade_level'] ?? '';
        $section = $_GET['section'] ?? '';
        $sy = $_GET['sy'] ?? $current_sy; // Use passed sy or fallback to system sy

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
            $students = $stmt->fetchAll();

            $formatted_students = [];
            foreach ($students as $student) {
                $age = null;
                if ($student['birthdate']) {
                    $birthDate = new DateTime($student['birthdate']);
                    // Use $sy which was already defined above
                    $base_year = (int) explode('-', $sy)[0];
                    $oct31 = new DateTime($base_year . '-10-31');

                    $age = $oct31->diff($birthDate)->y;
                    if ($age < 0) $age = 0;
                }

                $is_incomplete = (empty($student['lrn']) || empty($student['birthdate']) || empty($student['sex']));

                $formatted_students[] = [
                    'lrn' => $student['lrn'],
                    'last_name' => $student['last_name'],
                    'first_name' => $student['first_name'],
                    'middle_name' => $student['middle_name'],
                    'sex' => (strtoupper(substr($student['sex'] ?? 'M', 0, 1)) === 'M') ? 'M' : 'F',
                    'birth_date' => $student['birthdate'],
                    'age_as_of_oct31' => $age,
                    'mother_tongue' => $student['mother_tongue'],
                    'ip_ethnicity' => $student['ip_ethnicity'],
                    'religion' => null,
                    'house_no_street' => trim(($student['curr_house_no'] ?? '') . ' ' . ($student['curr_street'] ?? '')),
                    'barangay' => $student['curr_barangay'],
                    'municipality_city' => $student['curr_city'],
                    'province' => $student['curr_province'],
                    'father_last_name' => $student['father_last'],
                    'father_first_name' => $student['father_first'],
                    'father_middle_name' => $student['father_middle'],
                    'mother_last_name' => $student['mother_last'],
                    'mother_first_name' => $student['mother_first'],
                    'mother_middle_name' => $student['mother_middle'],
                    'guardian_name' => trim(($student['guardian_last'] ?? '') . ' ' . ($student['guardian_first'] ?? '') . ' ' . ($student['guardian_middle'] ?? '')),
                    'guardian_relationship' => null,
                    'contact_number' => $student['father_contact'] ?: ($student['mother_contact'] ?: $student['guardian_contact']),
                    'learning_modality' => $student['preferred_modalities'] ? trim(explode(',', $student['preferred_modalities'])[0]) : '',
                    'remarks' => null,
                    'remarks_code' => null,
                    'is_incomplete' => $is_incomplete
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => $formatted_students,
                'count' => count($formatted_students)
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Grade level and section required']);
        exit;
    }

    // Handle getting sections for a grade level
    if ($_GET['action'] === 'get_sections_by_grade') {
        $grade_level = $_GET['grade_level'] ?? '';

        if ($grade_level) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT section 
                FROM enrollments 
                WHERE grade_level = ? AND section IS NOT NULL 
                ORDER BY section
            ");
            $stmt->execute([$grade_level]);
            $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success' => true,
                'data' => $sections
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Grade level required']);
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $school_year = $_POST['school_year'];
        $grade_level = $_POST['grade_level'];
        $section = $_POST['grade_section'];

        // Create SF1 report record
        $stmt = $pdo->prepare("INSERT INTO sf1_reports (teacher_id, school_year, grade_level, section) VALUES (?, ?, ?, ?)");
        $stmt->execute([$teacher_id, $school_year, $grade_level, $section]);
        $sf1_report_id = $pdo->lastInsertId();

        // Process student records
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

                    // Check if LRN already exists for this school year in other SF1 reports
                    $stmt_dup = $pdo->prepare("
                        SELECT r.grade_level, r.section 
                        FROM sf1_student_records sr 
                        JOIN sf1_reports r ON sr.sf1_report_id = r.id 
                        WHERE sr.lrn = ? AND r.school_year = ?
                    ");
                    $stmt_dup->execute([$lrn, $school_year]);
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
                        $sf1_report_id,
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

        // Create summary record
        $summary_stmt = $pdo->prepare("
            INSERT INTO sf1_summary (
                sf1_report_id, total_male, total_female, total_combined,
                registered_male_bosy, registered_female_bosy, registered_total_bosy,
                registered_male_eosy, registered_female_eosy, registered_total_eosy,
                prepared_by_name, prepared_bosy_date, prepared_eosy_date,
                certified_by_name, certified_bosy_date, certified_eosy_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $summary_stmt->execute([
            $sf1_report_id,
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
            $_POST['summary']['certified_eosy_date'] ?? null
        ]);

        $pdo->commit();
        $message = "SF1 report saved successfully!";

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error saving SF1 report: " . $e->getMessage();
    }
}

// Get existing SF1 reports for this teacher
$existing_reports = $pdo->prepare("
    SELECT r.*, s.total_male, s.total_female, s.total_combined 
    FROM sf1_reports r 
    LEFT JOIN sf1_summary s ON r.id = s.sf1_report_id 
    WHERE r.teacher_id = ? 
    ORDER BY r.created_at DESC
");
$existing_reports->execute([$teacher_id]);
$reports = $existing_reports->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Form 1 (SF1) - School Register</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Premium Admin UI Standard Classes */
        :root {
            --bg: #f6f8fc;
            --card: #ffffff;
            --muted: #64748b;
            --border: #d7e0ee;
            --primary: #2563eb;
            --primary-600: #1d4ed8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --text-main: #0f172a;
        }

        body {
            background-color: var(--bg);
            margin: 0;
            font-family: 'Inter', -apple-system, sans-serif;
        }

        .content {
            padding: 140px 32px 48px;
            max-width: 1400px;
            box-sizing: border-box;
            transition: padding 0.25s ease;
        }

        @media (max-width: 768px) {
            .content {
                padding: 110px 16px 24px;
            }
        }

        .title-block {
            background: #fff;
            padding: 20px 24px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
            border: 1px solid var(--border);
        }

        .title-block h1 {
            color: var(--text-main);
            margin: 0;
            font-size: 24px;
        }

        .title-block p {
            margin: 5px 0 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .card h3 {
            margin-top: 0;
            color: var(--text-main);
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            font-size: 1.1rem;
        }

        .alert {
            padding: 15px;
            margin-bottom: 24px;
            border-radius: 8px;
            font-size: 14px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--text-main);
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-group input[readonly] {
            background: #f1f5f9;
            color: var(--muted);
            cursor: not-allowed;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            gap: 8px;
        }

        .btn:hover {
            background: var(--primary-600);
        }

        .btn-secondary {
            background: #64748b;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: var(--danger);
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .student-row {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
        }

        .student-row h4 {
            margin: 0 0 20px 0;
            color: var(--text-main);
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }

        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .remove-student-btn {
            background: transparent;
            color: var(--danger);
            padding: 5px 10px;
            border: 1px solid var(--danger);
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            position: absolute;
            top: 20px;
            right: 20px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .remove-student-btn:hover {
            background: var(--danger);
            color: white;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }

        .report-item {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }

        .report-item:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border-color: var(--primary);
        }

        .report-info h4 {
            margin: 0 0 5px 0;
            color: var(--text-main);
            font-size: 16px;
        }

        .report-info p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .report-actions {
            display: flex;
            gap: 10px;
        }

        .remarks-legend {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }

        .remarks-legend h4 {
            margin-top: 0;
            color: var(--text-main);
            margin-bottom: 15px;
        }

        .legend-item {
            display: flex;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .legend-code {
            font-weight: bold;
            width: 40px;
            color: var(--primary);
        }

        .legend-desc {
            flex: 1;
            color: var(--text-main);
        }

        /* Sticky submit bar */
        .sticky-submit-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            border-top: 2px solid var(--border);
            padding: 14px 32px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            z-index: 999;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
        }

        .sticky-submit-bar .btn {
            font-size: 16px;
            padding: 12px 32px;
        }

        /* Add padding at bottom so content isn't hidden behind sticky bar */
        .content {
            padding-bottom: 80px !important;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="content main-content dashboard-container">
        <div class="title-block">
            <div>
                <h1>📋 School Form 1 (SF1) - School Register</h1>
                <p>Complete list of students in your class with personal information</p>
            </div>
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
                            value="<?= htmlspecialchars($current_sy) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="grade_level">Grade Level:</label>
                        <select id="grade_level" name="grade_level" required>
                            <option value="">Select Grade Level</option>
                            <?php foreach ($grade_levels as $level): 
                                $is_selected = ($target_grade == $level) || (empty($target_grade) && count($grade_levels) === 1) || (empty($target_grade) && $advisory_class && $advisory_class['grade_level'] == $level);
                            ?>
                                <option value="<?= htmlspecialchars($level) ?>" <?= $is_selected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($level) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="grade_section">Section:</label>
                        <select id="grade_section" name="grade_section" required>
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $section): 
                                $is_selected = ($target_section == $section) || (empty($target_section) && count($sections) === 1) || (empty($target_section) && $advisory_class && $advisory_class['section'] == $section);
                            ?>
                                <option value="<?= htmlspecialchars($section) ?>" <?= $is_selected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($section) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Student Records Section -->
            <div class="card">
                <h3>👥 Student Records</h3>


                <!-- Bulk Load + Individual Student Action Panel -->
                <div
                    style="background: #eef2ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c7d2fe;">
                    <h4 style="margin-top: 0; margin-bottom: 12px; color: var(--primary);">📥 Load Enrolled Students
                    </h4>
                    <p style="font-size: 13px; color: var(--muted); margin: 0 0 12px 0;">Automatically populate the form
                        with all students enrolled in the selected Grade Level and Section.</p>
                    <button type="button" class="btn" onclick="loadEnrolledStudents()"
                        style="background: var(--primary); color: white;">📋 Load All Enrolled Students</button>
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
                            <button type="button" class="btn btn-secondary" onclick="clearAllStudents()"
                                style="background: var(--danger); color: white;">🗑️ Clear All Students</button>
                        </div>
                    </div>
                    <div id="single-search-status" style="margin-top: 10px; font-size: 14px;"></div>
                </div>

                <div id="students-container">
                    <!-- Student rows will be added here dynamically -->
                </div>
            </div>

            <!-- Summary Section -->
            <div class="card">
                <h3>📊 Summary</h3>
                <div class="summary-grid">
                    <div class="form-group">
                        <label for="total_male">Total Male:</label>
                        <input type="number" id="total_male" name="summary[total_male]" min="0" readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="total_female">Total Female:</label>
                        <input type="number" id="total_female" name="summary[total_female]" min="0" readonly
                            tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="total_combined">Total Combined:</label>
                        <input type="number" id="total_combined" name="summary[total_combined]" min="0" readonly
                            tabindex="-1">
                    </div>
                </div>

                <h4>Registered Counts</h4>
                <div class="summary-grid">
                    <div class="form-group">
                        <label for="registered_male_bosy">Male (BoSY):</label>
                        <input type="number" id="registered_male_bosy" name="summary[registered_male_bosy]" min="0"
                            readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_female_bosy">Female (BoSY):</label>
                        <input type="number" id="registered_female_bosy" name="summary[registered_female_bosy]" min="0"
                            readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_total_bosy">Total (BoSY):</label>
                        <input type="number" id="registered_total_bosy" name="summary[registered_total_bosy]" min="0"
                            readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_male_eosy">Male (EoSY):</label>
                        <input type="number" id="registered_male_eosy" name="summary[registered_male_eosy]" min="0"
                            readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_female_eosy">Female (EoSY):</label>
                        <input type="number" id="registered_female_eosy" name="summary[registered_female_eosy]" min="0"
                            readonly tabindex="-1">
                    </div>
                    <div class="form-group">
                        <label for="registered_total_eosy">Total (EoSY):</label>
                        <input type="number" id="registered_total_eosy" name="summary[registered_total_eosy]" min="0"
                            readonly tabindex="-1">
                    </div>
                </div>

                <h4>Signatures</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="prepared_by_name">Prepared by (Name):</label>
                        <input type="text" id="prepared_by_name" name="summary[prepared_by_name]" value="<?= htmlspecialchars($adviser_name) ?>" readonly style="background-color: #f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label for="prepared_bosy_date">BoSY Date:</label>
                        <input type="date" id="prepared_bosy_date" name="summary[prepared_bosy_date]" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label for="prepared_eosy_date">EoSY Date:</label>
                        <input type="date" id="prepared_eosy_date" name="summary[prepared_eosy_date]" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="certified_by_name">Certified by (Name):</label>
                        <input type="text" id="certified_by_name" name="summary[certified_by_name]" value="<?= htmlspecialchars($principal_name) ?>" readonly style="background-color: #f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label for="certified_bosy_date">BoSY Date:</label>
                        <input type="date" id="certified_bosy_date" name="summary[certified_bosy_date]" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label for="certified_eosy_date">EoSY Date:</label>
                        <input type="date" id="certified_eosy_date" name="summary[certified_eosy_date]" value="<?= date('Y-m-d') ?>">
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

            <!-- Sticky Submit Bar (always visible at bottom) -->
            <div class="sticky-submit-bar">
                <a href="../reports.php" class="btn btn-secondary">← Back to Reports</a>
                <button type="submit" class="btn btn-success" style="background: var(--success);">💾 Save SF1
                    Report</button>
            </div>
        </form>

        <!-- Existing Reports Section -->
        <?php if (!empty($reports)): ?>
            <div class="existing-reports">
                <h3>📋 Your Saved SF1 Reports</h3>
                <?php foreach ($reports as $report): ?>
                    <div class="report-item">
                        <div class="report-info">
                            <h4><?= htmlspecialchars($report['grade_level']) ?> - <?= htmlspecialchars($report['section']) ?>
                            </h4>
                            <p>School Year: <?= htmlspecialchars($report['school_year']) ?> |
                                Students: <?= ($report['total_male'] ?? 0) + ($report['total_female'] ?? 0) ?>
                                (M: <?= $report['total_male'] ?? 0 ?>, F: <?= $report['total_female'] ?? 0 ?>) |
                                Created: <?= date('M d, Y', strtotime($report['created_at'])) ?></p>
                        </div>
                        <div class="report-actions">
                            <a href="sf1_view.php?id=<?= $report['id'] ?>" class="btn">👁️ View</a>
                            <a href="sf1_view.php?id=<?= $report['id'] ?>&export=pdf" class="btn" style="background: #8b5cf6; color: white;" target="_blank">🖨️ Print</a>
                            <a href="sf1_edit.php?id=<?= $report['id'] ?>" class="btn btn-secondary">✏️ Edit</a>
                            <a href="sf1_delete.php?id=<?= $report['id'] ?>" class="btn btn-danger"
                                onclick="return confirm('Are you sure you want to delete this report?')">🗑️ Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let studentCount = 0;

        function addStudentRow(studentData = null) {
            studentCount++;
            const container = document.getElementById('students-container');
            const studentRow = document.createElement('div');
            studentRow.className = 'student-row';
            studentRow.innerHTML = `
                <h4>Student ${studentCount} <button type="button" class="remove-student-btn" onclick="removeStudentRow(this)">Remove</button></h4>
                <div class="student-grid">
                    <div class="form-group">
                        <label>LRN *:</label>
                        <input type="text" name="students[${studentCount}][lrn]" placeholder="12-digit LRN" required pattern="[0-9]{12}" minlength="12" maxlength="12" title="LRN must be exactly 12 digits" oninput="this.value = this.value.replace(/[^0-9]/g, '')" value="${studentData ? (studentData.lrn || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Last Name *:</label>
                        <input type="text" name="students[${studentCount}][last_name]" required value="${studentData ? (studentData.last_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>First Name *:</label>
                        <input type="text" name="students[${studentCount}][first_name]" required value="${studentData ? (studentData.first_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Middle Name:</label>
                        <input type="text" name="students[${studentCount}][middle_name]" value="${studentData ? (studentData.middle_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Sex *:</label>
                        <select name="students[${studentCount}][sex]" required onchange="updateSummaryTotals()">
                            <option value="">Select</option>
                            <option value="M" ${studentData && studentData.sex === 'M' ? 'selected' : ''}>Male</option>
                            <option value="F" ${studentData && studentData.sex === 'F' ? 'selected' : ''}>Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Birth Date *:</label>
                        <input type="date" name="students[${studentCount}][birth_date]" required value="${studentData ? (studentData.birth_date || '') : ''}" onchange="calculateAge(this, ${studentCount})">
                    </div>
                    <div class="form-group">
                        <label>Age as of Oct 31:</label>
                        <input type="number" name="students[${studentCount}][age_as_of_oct31]" readonly tabindex="-1" value="${studentData ? (studentData.age_as_of_oct31 || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Mother Tongue:</label>
                        <input type="text" name="students[${studentCount}][mother_tongue]" placeholder="Grade 1-3 only" value="${studentData ? (studentData.mother_tongue || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>IP (Ethnic Group):</label>
                        <input type="text" name="students[${studentCount}][ip_ethnicity]" value="${studentData ? (studentData.ip_ethnicity || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Religion:</label>
                        <input type="text" name="students[${studentCount}][religion]" value="${studentData ? (studentData.religion || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>House #/Street/Sitio/Purok:</label>
                        <input type="text" name="students[${studentCount}][house_no_street]" value="${studentData ? (studentData.house_no_street || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Barangay:</label>
                        <input type="text" name="students[${studentCount}][barangay]" value="${studentData ? (studentData.barangay || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Municipality/City:</label>
                        <input type="text" name="students[${studentCount}][municipality_city]" value="${studentData ? (studentData.municipality_city || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Province:</label>
                        <input type="text" name="students[${studentCount}][province]" value="${studentData ? (studentData.province || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Father's Last Name:</label>
                        <input type="text" name="students[${studentCount}][father_last_name]" value="${studentData ? (studentData.father_last_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Father's First Name:</label>
                        <input type="text" name="students[${studentCount}][father_first_name]" value="${studentData ? (studentData.father_first_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Father's Middle Name:</label>
                        <input type="text" name="students[${studentCount}][father_middle_name]" value="${studentData ? (studentData.father_middle_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Mother's Last Name:</label>
                        <input type="text" name="students[${studentCount}][mother_last_name]" value="${studentData ? (studentData.mother_last_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Mother's First Name:</label>
                        <input type="text" name="students[${studentCount}][mother_first_name]" value="${studentData ? (studentData.mother_first_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Mother's Middle Name:</label>
                        <input type="text" name="students[${studentCount}][mother_middle_name]" value="${studentData ? (studentData.mother_middle_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Guardian Name (if not parent):</label>
                        <input type="text" name="students[${studentCount}][guardian_name]" value="${studentData ? (studentData.guardian_name || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Guardian Relationship:</label>
                        <input type="text" name="students[${studentCount}][guardian_relationship]" value="${studentData ? (studentData.guardian_relationship || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Contact Number:</label>
                        <input type="text" name="students[${studentCount}][contact_number]" value="${studentData ? (studentData.contact_number || '') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Learning Modality:</label>
                        <select name="students[${studentCount}][learning_modality]">
                            <option value="">Select</option>
                            <option value="Face-to-Face" ${studentData && studentData.learning_modality === 'Face-to-Face' ? 'selected' : ''}>Face-to-Face</option>
                            <option value="Distance Learning" ${studentData && studentData.learning_modality === 'Distance Learning' ? 'selected' : ''}>Distance Learning</option>
                            <option value="Blended" ${studentData && studentData.learning_modality === 'Blended' ? 'selected' : ''}>Blended</option>
                            <option value="Homeschooling" ${studentData && studentData.learning_modality === 'Homeschooling' ? 'selected' : ''}>Homeschooling</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Remarks Code:</label>
                        <select name="students[${studentCount}][remarks_code]" onchange="updateSummaryTotals()">
                            <option value="">None</option>
                            <option value="TO" ${studentData && studentData.remarks_code === 'TO' ? 'selected' : ''}>TO - Transferred Out</option>
                            <option value="TI" ${studentData && studentData.remarks_code === 'TI' ? 'selected' : ''}>TI - Transferred In</option>
                            <option value="BRP" ${studentData && studentData.remarks_code === 'BRP' ? 'selected' : ''}>BRP - Dropped</option>
                            <option value="LE" ${studentData && studentData.remarks_code === 'LE' ? 'selected' : ''}>LE - Late Enrollment</option>
                            <option value="CCT" ${studentData && studentData.remarks_code === 'CCT' ? 'selected' : ''}>CCT - CCT Recipient</option>
                            <option value="B/A" ${studentData && studentData.remarks_code === 'B/A' ? 'selected' : ''}>B/A - Balik Aral</option>
                            <option value="SNE" ${studentData && studentData.remarks_code === 'SNE' ? 'selected' : ''}>SNE - Special Needs Education</option>
                            <option value="ACL" ${studentData && studentData.remarks_code === 'ACL' ? 'selected' : ''}>ACL - Accelerated</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Remarks:</label>
                        <textarea name="students[${studentCount}][remarks]" placeholder="Additional remarks or details">${studentData ? (studentData.remarks || '') : ''}</textarea>
                    </div>
                </div>
            `;
            container.appendChild(studentRow);
        }

        function removeStudentRow(button) {
            button.closest('.student-row').remove();
            updateSummaryTotals();
        }

        function clearAllStudents() {
            if (confirm('Are you sure you want to clear all students? This action cannot be undone.')) {
                document.getElementById('students-container').innerHTML = '';
                studentCount = 0;
                updateSummaryTotals();
            }
        }


        function calculateAge(birthDateInput, studentIndex) {
            const birthDate = new Date(birthDateInput.value);
            if (isNaN(birthDate.getTime())) return;

            // Get the selected school year to base the age calculation on
            const sySelect = document.getElementById('school_year');
            if (!sySelect || !sySelect.value) return;

            // The school year format is YYYY-YYYY. We use the first year as the base year.
            const baseYearStr = sySelect.value.split('-')[0];
            const baseYear = parseInt(baseYearStr, 10);

            // DepEd age cut-off is typically October 31st of the school year's start year
            const cutOffDate = new Date(baseYear, 9, 31); // Month is 0-indexed (9 = October)

            let age = cutOffDate.getFullYear() - birthDate.getFullYear();
            const monthDiff = cutOffDate.getMonth() - birthDate.getMonth();

            if (monthDiff < 0 || (monthDiff === 0 && cutOffDate.getDate() < birthDate.getDate())) {
                age--;
            }

            // Cannot be negative age
            if (age < 0) age = 0;

            const ageInput = document.querySelector(`input[name="students[${studentIndex}][age_as_of_oct31]"]`);
            if (ageInput) ageInput.value = age;
        }

        function updateTotals() {
            const male = parseInt(document.getElementById('total_male').value) || 0;
            const female = parseInt(document.getElementById('total_female').value) || 0;
            document.getElementById('total_combined').value = male + female;
        }

        function updateSummaryTotals() {
            const studentRows = document.querySelectorAll('.student-row');
            let maleCount = 0;
            let femaleCount = 0;

            studentRows.forEach(row => {
                const sexSelect = row.querySelector('select[name*="[sex]"]');
                if (sexSelect && sexSelect.value === 'M') {
                    maleCount++;
                } else if (sexSelect && sexSelect.value === 'F') {
                    femaleCount++;
                }
            });

            // Overall Totals
            const totalCombined = maleCount + femaleCount;
            document.getElementById('total_male').value = maleCount;
            document.getElementById('total_female').value = femaleCount;
            document.getElementById('total_combined').value = totalCombined;

            // BoSY (Beginning of School Year) Counts
            document.getElementById('registered_male_bosy').value = maleCount;
            document.getElementById('registered_female_bosy').value = femaleCount;
            document.getElementById('registered_total_bosy').value = totalCombined;

            // EoSY (End of School Year) Counts
            // In a basic setup, EoSY equals BoSY unless students drop out or transfer
            // For automation, we'll sync them by default. Advanced logic would subtract Dropouts/Transfers Out.
            let eosyMale = 0;
            let eosyFemale = 0;

            studentRows.forEach(row => {
                const sexSelect = row.querySelector('select[name*="[sex]"]');
                const remarksSelect = row.querySelector('select[name*="[remarks_code]"]');

                // If they transferred out (TO) or dropped (BRP), they are NOT counted in EoSY
                const isInactive = remarksSelect && (remarksSelect.value === 'TO' || remarksSelect.value === 'BRP');

                if (!isInactive && sexSelect) {
                    if (sexSelect.value === 'M') eosyMale++;
                    else if (sexSelect.value === 'F') eosyFemale++;
                }
            });

            document.getElementById('registered_male_eosy').value = eosyMale;
            document.getElementById('registered_female_eosy').value = eosyFemale;
            document.getElementById('registered_total_eosy').value = eosyMale + eosyFemale;
        }

        function searchAndAddSingleStudent() {
            const lrn = document.getElementById('single_search_lrn').value.trim();
            const statusDiv = document.getElementById('single-search-status');

            if (!lrn) {
                statusDiv.innerHTML = '<span style="color: var(--danger);">Please enter an LRN to search.</span>';
                return;
            }

            statusDiv.innerHTML = '<span style="color: var(--primary);">Searching...</span>';

            fetch(`?action=get_student_by_lrn&lrn=${encodeURIComponent(lrn)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        addStudentRow(data.data);
                        updateSummaryTotals();
                        statusDiv.innerHTML = `<span style="color: var(--success);">✅ Student found and added!</span>`;
                        document.getElementById('single_search_lrn').value = '';
                        setTimeout(() => statusDiv.innerHTML = '', 3000);
                    } else {
                        statusDiv.innerHTML = '<span style="color: var(--danger);">❌ Student not found with this LRN. Use "Add Blank Row (Manual)".</span>';
                    }
                })
                .catch(error => {
                    console.error('Error fetching student data:', error);
                    statusDiv.innerHTML = '<span style="color: var(--danger);">Error searching for student.</span>';
                });
        }

        function loadEnrolledStudents() {
            const gradeSelect = document.getElementById('grade_level');
            const sectionSelect = document.getElementById('grade_section');
            const sySelect = document.getElementById('school_year');
            const statusSpan = document.getElementById('bulk-load-status');

            const grade = gradeSelect ? gradeSelect.value : '';
            const section = sectionSelect ? sectionSelect.value : '';
            const sy = sySelect ? sySelect.value : '';

            if (!grade || !section) {
                statusSpan.innerHTML = '<span style="color: var(--danger);">⚠️ Please select a Grade Level and Section first.</span>';
                return;
            }

            if (!confirm('This will clear any existing student rows and load all enrolled students for ' + grade + ' - ' + section + '. Continue?')) {
                return;
            }

            statusSpan.innerHTML = '<span style="color: var(--primary);">⏳ Loading students...</span>';

            fetch(`?action=get_students_by_grade_section&grade_level=${encodeURIComponent(grade)}&section=${encodeURIComponent(section)}&sy=${encodeURIComponent(sy)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        // Clear existing
                        document.getElementById('students-container').innerHTML = '';
                        studentCount = 0;

                        data.data.forEach(student => {
                            addStudentRow(student);
                        });

                        updateSummaryTotals();
                        statusSpan.innerHTML = `<span style="color: var(--success);">✅ Loaded ${data.data.length} students!</span>`;
                        setTimeout(() => statusSpan.innerHTML = '', 5000);
                    } else {
                        statusSpan.innerHTML = '<span style="color: var(--warning);">⚠️ No enrolled students found for this grade/section.</span>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    statusSpan.innerHTML = '<span style="color: var(--danger);">❌ Error loading students.</span>';
                });
        }

        // Add event listeners for sex changes to update totals
        document.addEventListener('change', function (e) {
            if (e.target.name && e.target.name.includes('[sex]')) {
                updateSummaryTotals();
            }
        });

        // Add initial student row OR auto-load enrolled students
        document.addEventListener('DOMContentLoaded', function () {
            const gradeSelect = document.getElementById('grade_level');
            const sectionSelect = document.getElementById('grade_section');
            const sySelect = document.getElementById('school_year');

            // If both grade and section are pre-selected, auto-load enrolled students
            if (gradeSelect && sectionSelect && gradeSelect.value && sectionSelect.value) {
                const sy = sySelect ? sySelect.value : '';
                autoLoadEnrolledStudents(gradeSelect.value, sectionSelect.value, sy);
            } else {
                addStudentRow(); // fallback: show one blank row
            }

            // Recalculate all ages when School Year changes
            if (sySelect) {
                sySelect.addEventListener('change', function () {
                    const birthDateInputs = document.querySelectorAll('input[name*="[birth_date]"]');
                    birthDateInputs.forEach(input => {
                        if (input.value) {
                            const match = input.name.match(/students\[(\d+)\]/);
                            if (match && match[1]) {
                                calculateAge(input, match[1]);
                            }
                        }
                    });

                    // Also reload students for the new school year if grade/section are selected
                    if (gradeSelect && gradeSelect.value && sectionSelect && sectionSelect.value) {
                         autoLoadEnrolledStudents(gradeSelect.value, sectionSelect.value, this.value);
                    }
                });
            }

            // Auto-reload students when grade or section dropdown changes
            if (gradeSelect) {
                gradeSelect.addEventListener('change', function() {
                    if (this.value && sectionSelect && sectionSelect.value) {
                        const sy = sySelect ? sySelect.value : '';
                        autoLoadEnrolledStudents(this.value, sectionSelect.value, sy);
                    }
                });
            }
            if (sectionSelect) {
                sectionSelect.addEventListener('change', function() {
                    if (this.value && gradeSelect && gradeSelect.value) {
                        const sy = sySelect ? sySelect.value : '';
                        autoLoadEnrolledStudents(gradeSelect.value, this.value, sy);
                    }
                });
            }
        });

        // Silent auto-load (no confirmation dialog)
        function autoLoadEnrolledStudents(grade, section, sy) {
            const statusSpan = document.getElementById('bulk-load-status');
            statusSpan.innerHTML = '<span style="color: var(--primary);">⏳ Auto-loading enrolled students...</span>';

            fetch(`?action=get_students_by_grade_section&grade_level=${encodeURIComponent(grade)}&section=${encodeURIComponent(section)}&sy=${encodeURIComponent(sy)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        document.getElementById('students-container').innerHTML = '';
                        studentCount = 0;
                        data.data.forEach(student => addStudentRow(student));
                        updateSummaryTotals();
                        statusSpan.innerHTML = `<span style="color: var(--success);">✅ Auto-loaded ${data.data.length} enrolled students.</span>`;
                        setTimeout(() => statusSpan.innerHTML = '', 5000);
                    } else {
                        statusSpan.innerHTML = '<span style="color: var(--warning);">⚠️ No enrolled students found for this section. Add students manually.</span>';
                        if (document.querySelectorAll('.student-row').length === 0) {
                            addStudentRow(); // Add one blank row as fallback
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    statusSpan.innerHTML = '<span style="color: var(--danger);">❌ Error auto-loading students.</span>';
                    if (document.querySelectorAll('.student-row').length === 0) {
                        addStudentRow();
                    }
                });
        }
    </script>
</body>

</html>