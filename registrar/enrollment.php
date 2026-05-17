<?php
// Start output buffering to catch any unwanted output
ob_start();

require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin', 'teacher']);

require_once dirname(__DIR__) . '/config/db.php';

// Set error reporting to prevent warnings from interfering with JSON
error_reporting(E_ERROR | E_PARSE);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/student_id_utility.php';

// Helper function to get guardian name from database data
function getGuardianNameFromData($data)
{
    $contactPerson = $data['id_contact_person'] ?? 'guardian';

    if ($contactPerson === 'father') {
        $name = trim(($data['father_first'] ?? '') . ' ' . ($data['father_middle'] ?? '') . ' ' . ($data['father_last'] ?? ''));
        return $name ?: 'N/A';
    } else if ($contactPerson === 'mother') {
        $name = trim(($data['mother_first'] ?? '') . ' ' . ($data['mother_middle'] ?? '') . ' ' . ($data['mother_last'] ?? ''));
        return $name ?: 'N/A';
    } else {
        // Use enrollment guardian data first, then registration guardian data
        $guardianFirst = $data['guardian_first'] ?? $data['reg_guardian_first'] ?? '';
        $guardianLast = $data['guardian_last'] ?? $data['reg_guardian_last'] ?? '';
        $name = trim($guardianFirst . ' ' . $guardianLast);
        return $name ?: 'N/A';
    }
}

// Helper function to get guardian contact from database data
function getGuardianContactFromData($data)
{
    $contactPerson = $data['id_contact_person'] ?? 'guardian';

    if ($contactPerson === 'father') {
        return $data['father_contact'] ?? 'N/A';
    } else if ($contactPerson === 'mother') {
        return $data['mother_contact'] ?? 'N/A';
    } else {
        // Use enrollment guardian contact first, then registration guardian contact
        return $data['guardian_contact'] ?? $data['reg_guardian_contact'] ?? 'N/A';
    }
}

// Handle manual enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_name']) && !isset($_POST['qr_data']) && !isset($_POST['search_student'])) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }

    // Set proper headers for JSON response
    header('Content-Type: application/json');

    $pdo = db_connect();

    try {
        $student_name = trim($_POST['student_name']);
        $grade_level = trim($_POST['grade_level']);
        $section = trim($_POST['section']);
        $lrn = trim($_POST['lrn'] ?? '');
        $birthday = trim($_POST['birthday'] ?? '');

        // Validate required fields
        if (empty($student_name) || empty($grade_level) || empty($section) || empty($lrn) || empty($birthday)) {
            throw new Exception('All required fields must be filled');
        }

        // Validate LRN format (12 digits)
        if (!preg_match('/^\d{12}$/', $lrn)) {
            throw new Exception('LRN must be exactly 12 digits');
        }

        // Check if LRN already exists in current school year
        $current_sy = get_current_school_year($pdo);
        $stmt = $pdo->prepare('
            SELECT e.id, e.student_id, e.student_name, e.lrn, e.grade_level, e.section, 
                   e.guardian_first, e.guardian_last, e.guardian_contact, e.address, 
                   e.enrolled_at, e.created_at, e.birthdate, e.id_contact_person, e.school_year,
                   r.father_first, r.father_last, r.father_contact,
                   r.mother_first, r.mother_last, r.mother_contact,
                   r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last, r.guardian_contact as reg_guardian_contact
            FROM enrollments e 
            LEFT JOIN registrations r ON e.registration_id = r.id 
            WHERE e.lrn = ? AND e.school_year = ?
        ');
        $stmt->execute([$lrn, $current_sy]);
        $existing_lrn = $stmt->fetch();
        if ($existing_lrn) {
            $guardian_name = getGuardianNameFromData($existing_lrn);
            $guardian_contact = getGuardianContactFromData($existing_lrn);
            throw new Exception('Student with LRN ' . $lrn . ' is already enrolled in ' . $current_sy . '. Student: ' . $existing_lrn['student_name'] . ' (Grade ' . $existing_lrn['grade_level'] . ', Section ' . $existing_lrn['section'] . '). Please check existing enrollments or contact the admin if this is an error.');
        }

        // Check if student name already exists in current school year (case-insensitive)
        $stmt = $pdo->prepare('
            SELECT e.id, e.student_id, e.student_name, e.lrn, e.grade_level, e.section, 
                   e.guardian_first, e.guardian_last, e.guardian_contact, e.address, 
                   e.enrolled_at, e.created_at, e.birthdate, e.id_contact_person, e.school_year,
                   r.father_first, r.father_last, r.father_contact,
                   r.mother_first, r.mother_last, r.mother_contact,
                   r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last, r.guardian_contact as reg_guardian_contact
            FROM enrollments e 
            LEFT JOIN registrations r ON e.registration_id = r.id 
            WHERE LOWER(TRIM(e.student_name)) = LOWER(TRIM(?)) AND e.school_year = ?
        ');
        $stmt->execute([$student_name, $current_sy]);
        $existing_name = $stmt->fetch();
        if ($existing_name) {
            $guardian_name = getGuardianNameFromData($existing_name);
            $guardian_contact = getGuardianContactFromData($existing_name);
            throw new Exception('Student "' . $student_name . '" is already enrolled in ' . $current_sy . ' with LRN: ' . $existing_name['lrn'] . ' (Grade ' . $existing_name['grade_level'] . ', Section ' . $existing_name['section'] . '). Please check existing enrollments or contact the admin if this is an error.');
        }

        // Ensure additional columns exist in enrollments table
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS lrn VARCHAR(20) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS birthdate DATE NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_first VARCHAR(100) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_last VARCHAR(100) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_contact VARCHAR(50) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS id_contact_person ENUM("father","mother","guardian") DEFAULT "guardian"');

        // Get guardian details from form
        $guardian_full_name = trim($_POST['guardian_full_name'] ?? '');
        $guardian_contact = trim($_POST['guardian_contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        // Generate unique student ID
        $student_id = generateStudentId($pdo);

        // Get current school year
        $current_sy = get_current_school_year($pdo);

        // Create enrollment record
        $registration_id = isset($_POST['registration_id']) && !empty($_POST['registration_id']) ? (int)$_POST['registration_id'] : null;
        $stmt = $pdo->prepare('INSERT INTO enrollments (student_id, student_name, grade_level, section, lrn, birthdate, guardian_first, guardian_last, guardian_contact, address, id_contact_person, school_year, registration_id, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$student_id, $student_name, $grade_level, $section, $lrn, $birthday, $guardian_full_name, '', $guardian_contact, $address, 'guardian', $current_sy, $registration_id]);

        $enrollment_id = $pdo->lastInsertId();

        // Generate QR code for the new student
        $qrCodePath = generateStudentQRCode($student_id, $student_name);

        // Update enrollment record with QR code path
        if ($qrCodePath) {
            $stmt = $pdo->prepare('UPDATE enrollments SET qr_code_path = ? WHERE student_id = ?');
            $stmt->execute([$qrCodePath, $student_id]);
        }

        // Sync to students table
        syncToStudentsTable($pdo, [
            'student_id' => $student_id,
            'first_name' => $student_name,
            'last_name' => '',
            'course' => $grade_level,
            'year_level' => $grade_level,
            'qr_code_path' => $qrCodePath
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Student enrolled successfully',
            'enrollment_id' => $enrollment_id,
            'lrn' => $lrn,
            'student_name' => $student_name
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle duplicate enrollment check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_duplicate'])) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }

    // Set proper headers for JSON response
    header('Content-Type: application/json');

    $pdo = db_connect();

    try {
        $lrn = trim($_POST['lrn'] ?? '');
        $student_name = trim($_POST['student_name'] ?? '');
        $is_duplicate = false;
        $existing_student = null;

        // Check by LRN in current school year
        if (!empty($lrn)) {
            $current_sy = get_current_school_year($pdo);
            $stmt = $pdo->prepare('
                SELECT e.id, e.student_id, e.student_name, e.lrn, e.grade_level, e.section, 
                       e.guardian_first, e.guardian_last, e.guardian_contact, e.address, 
                       e.enrolled_at, e.created_at, e.birthdate, e.id_contact_person, e.school_year,
                       r.father_first, r.father_last, r.father_contact,
                       r.mother_first, r.mother_last, r.mother_contact,
                       r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last, r.guardian_contact as reg_guardian_contact,
                       r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip
                FROM enrollments e 
                LEFT JOIN registrations r ON e.registration_id = r.id 
                WHERE e.lrn = ? AND e.school_year = ?
            ');
            $stmt->execute([$lrn, $current_sy]);
            $existing_student = $stmt->fetch();
            if ($existing_student) {
                $is_duplicate = true;
            }
        }

        // Check by student name in current school year if not found by LRN
        if (!$is_duplicate && !empty($student_name)) {
            $current_sy = get_current_school_year($pdo);
            $stmt = $pdo->prepare('
                SELECT e.id, e.student_id, e.student_name, e.lrn, e.grade_level, e.section, 
                       e.guardian_first, e.guardian_last, e.guardian_contact, e.address, 
                       e.enrolled_at, e.created_at, e.birthdate, e.id_contact_person, e.school_year,
                       r.father_first, r.father_last, r.father_contact,
                       r.mother_first, r.mother_last, r.mother_contact,
                       r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last, r.guardian_contact as reg_guardian_contact,
                       r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip
                FROM enrollments e 
                LEFT JOIN registrations r ON e.registration_id = r.id 
                WHERE LOWER(TRIM(e.student_name)) = LOWER(TRIM(?)) AND e.school_year = ?
            ');
            $stmt->execute([$student_name, $current_sy]);
            $existing_student = $stmt->fetch();
            if ($existing_student) {
                $is_duplicate = true;
            }
        }

        echo json_encode([
            'is_duplicate' => $is_duplicate,
            'existing_student' => $existing_student
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'is_duplicate' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle student search by name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_student'])) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }

    // Set proper headers for JSON response
    header('Content-Type: application/json');

    $pdo = db_connect();

    try {
        $student_name = trim($_POST['student_name']);

        if (empty($student_name)) {
            throw new Exception('Student name is required');
        }

        // Search in registrations table for multiple matches
        $stmt = $pdo->prepare('SELECT *, 
            CASE 
                WHEN id_contact_person = "father" THEN CONCAT(father_first, " ", father_middle, " ", father_last)
                WHEN id_contact_person = "mother" THEN CONCAT(mother_first, " ", mother_middle, " ", mother_last)
                WHEN id_contact_person = "guardian" THEN CONCAT(guardian_first, " ", guardian_middle, " ", guardian_last)
                ELSE CONCAT(guardian_first, " ", guardian_middle, " ", guardian_last)
            END as selected_guardian_name,
            CASE 
                WHEN id_contact_person = "father" THEN father_contact
                WHEN id_contact_person = "mother" THEN mother_contact
                WHEN id_contact_person = "guardian" THEN guardian_contact
                ELSE guardian_contact
            END as selected_guardian_contact,
            CONCAT(last_name, ", ", first_name, " ", middle_name) as full_name
            FROM registrations WHERE CONCAT(last_name, ", ", first_name, " ", middle_name) LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ? ORDER BY created_at DESC LIMIT 10');
        $stmt->execute(['%' . $student_name . '%', '%' . $student_name . '%']);
        $registrations = $stmt->fetchAll();

        // Debug: Check if query executed successfully
        if ($stmt->errorCode() !== '00000') {
            throw new Exception('Database query error: ' . implode(', ', $stmt->errorInfo()));
        }

        if (!empty($registrations)) {
            // Prepare students array
            $students = [];
            foreach ($registrations as $registration) {
                $students[] = [
                    'id' => $registration['id'],
                    'lrn' => $registration['lrn'],
                    'first_name' => $registration['first_name'],
                    'last_name' => $registration['last_name'],
                    'middle_name' => $registration['middle_name'],
                    'full_name' => $registration['full_name'],
                    'birthdate' => $registration['birthdate'],
                    'guardian_full_name' => trim($registration['selected_guardian_name']),
                    'guardian_contact' => $registration['selected_guardian_contact'],
                    'grade_level_to_enroll' => $registration['grade_level_to_enroll'],
                    'id_contact_person' => $registration['id_contact_person'],
                    'address' => implode(', ', array_filter([
                        $registration['curr_house_no'],
                        $registration['curr_street'],
                        $registration['curr_barangay'],
                        $registration['curr_city'],
                        $registration['curr_province'],
                        $registration['curr_zip']
                    ]))
                ];
            }

            echo json_encode([
                'success' => true,
                'multiple' => count($students) > 1,
                'students' => $students,
                'count' => count($students)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Student not found in registration records'
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle QR code enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_enroll'])) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }

    // Set proper headers for JSON response
    header('Content-Type: application/json');

    $pdo = db_connect();

    try {
        $student_name = trim($_POST['student_name'] ?? '');
        $lrn = trim($_POST['lrn'] ?? '');
        $grade_level = trim($_POST['grade_level'] ?? '');
        $section = trim($_POST['section'] ?? 'Section A');
        $birthday = trim($_POST['birthday'] ?? '');
        $guardian_full_name = trim($_POST['guardian_full_name'] ?? '');
        $guardian_contact = trim($_POST['guardian_contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        // Validate required fields
        if (empty($student_name) || empty($lrn) || empty($grade_level)) {
            throw new Exception('Required fields (name, LRN, grade level) are missing from QR data');
        }

        // Validate LRN format (12 digits)
        if (!preg_match('/^\d{12}$/', $lrn)) {
            throw new Exception('LRN must be exactly 12 digits');
        }

        // Check if LRN already exists in current school year
        $current_sy = get_current_school_year($pdo);
        $stmt = $pdo->prepare('SELECT id, student_name FROM enrollments WHERE lrn = ? AND school_year = ?');
        $stmt->execute([$lrn, $current_sy]);
        $existing = $stmt->fetch();
        if ($existing) {
            throw new Exception('Student with LRN ' . $lrn . ' is already enrolled in ' . $current_sy . '. Existing student: ' . $existing['student_name']);
        }

        // Parse guardian name into first and last
        $guardian_parts = explode(' ', $guardian_full_name, 2);
        $guardian_first = $guardian_parts[0] ?? '';
        $guardian_last = $guardian_parts[1] ?? '';

        // Generate student ID
        $student_id = generateStudentId($pdo);

        // Insert enrollment
        $stmt = $pdo->prepare('
            INSERT INTO enrollments (
                student_id, student_name, lrn, grade_level, section, birthdate,
                guardian_first, guardian_last, guardian_contact, address,
                school_year, enrolled_at, created_at, qr_code_path
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
        ');

        $qr_code_url = generateStudentQRCode($student_id, $student_name);

        $stmt->execute([
            $student_id,
            $student_name,
            $lrn,
            $grade_level,
            $section,
            $birthday ?: null,
            $guardian_first,
            $guardian_last,
            $guardian_contact,
            $address,
            $current_sy,
            $qr_code_url
        ]);

        // Sync to students table
        syncToStudentsTable($pdo, [
            'student_id' => $student_id,
            'first_name' => $student_name,
            'last_name' => '',
            'course' => $grade_level,
            'year_level' => $grade_level,
            'qr_code_path' => $qr_code_url
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Student enrolled successfully via QR code',
            'student_id' => $student_id,
            'lrn' => $lrn
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle LRN lookup (for QR codes containing just an LRN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_lrn'])) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }

    // Set proper headers for JSON response
    header('Content-Type: application/json');

    $pdo = db_connect();

    try {
        $lrn = trim($_POST['lrn'] ?? '');

        if (empty($lrn)) {
            throw new Exception('LRN is required');
        }

        // Look up student in registrations table
        $stmt = $pdo->prepare('
            SELECT 
                r.id,
                r.lrn,
                CONCAT(COALESCE(r.last_name, ""), ", ", COALESCE(r.first_name, ""), " ", COALESCE(r.middle_name, "")) as full_name,
                r.first_name, r.last_name, r.middle_name,
                r.grade_level_to_enroll,
                r.birthdate,
                r.guardian_first, r.guardian_last, r.guardian_contact,
                r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province
            FROM registrations r
            WHERE r.lrn = ?
            ORDER BY r.created_at DESC
            LIMIT 1
        ');
        $stmt->execute([$lrn]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            echo json_encode([
                'success' => false,
                'message' => 'No student found with LRN: ' . $lrn
            ]);
            exit;
        }

        // Build address
        $address_parts = array_filter([
            $student['curr_house_no'],
            $student['curr_street'],
            $student['curr_barangay'],
            $student['curr_city'],
            $student['curr_province']
        ]);
        $address = implode(', ', $address_parts);

        // Build guardian name
        $guardian_name = trim(($student['guardian_first'] ?? '') . ' ' . ($student['guardian_last'] ?? ''));

        echo json_encode([
            'success' => true,
            'student' => [
                'lrn' => $student['lrn'],
                'student_name' => $student['full_name'],
                'full_name' => $student['full_name'],
                'grade_level' => $student['grade_level_to_enroll'],
                'birthdate' => $student['birthdate'],
                'guardian_full_name' => $guardian_name,
                'guardian_contact' => $student['guardian_contact'] ?? '',
                'address' => $address
            ]
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Get recent enrollments for current school year
$pdo = db_connect();

// Check if enrolled_at column exists, if not use created_at
$columns = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'enrolled_at'")->fetch();
$orderColumn = $columns ? 'enrolled_at' : 'created_at';

// Get current school year
$current_sy = get_current_school_year($pdo);

$stmt = $pdo->prepare("
    SELECT
        e.id,
        e.student_id,
        e.student_name,
        COALESCE(e.lrn, r.lrn) AS lrn,
        COALESCE(e.birthdate, r.birthdate) AS birthdate,
        e.grade_level,
        e.section,
        e.enrolled_at,
        e.created_at,
        e.school_year,
        r.first_name, r.last_name, r.middle_name, r.grade_level_to_enroll
    FROM enrollments e 
    LEFT JOIN registrations r ON e.registration_id = r.id 
    WHERE e.school_year = ?
    ORDER BY e.{$orderColumn} DESC 
    LIMIT 10
");
$stmt->execute([$current_sy]);
$recent_enrollments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Enrollment</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root {
            --bg: #f6f8fc;
            --card: #ffffff;
            --muted: #64748b;
            --border: #d7e0ee;
            --primary: #2563eb;
            --success: #10b981;
            --error: #ef4444;
        }

        .content {
            padding: 160px 24px 24px;
            max-width: 1200px;
        }

        @media (max-width: 768px) {
            .content {
                padding-top: 140px;
            }
        }

        h1 {
            margin: 0 0 16px 0;
            font-weight: 700;
        }

        .scanner-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
        }

        .scanner-area {
            width: 100%;
            max-width: 500px;
            height: 300px;
            background: #f8fafc;
            border: 2px dashed var(--border);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            position: relative;
            overflow: hidden;
        }

        #scanner {
            width: 100%;
            height: 100%;
        }

        .scanner-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            border: 2px solid var(--primary);
            border-radius: 12px;
            pointer-events: none;
        }

        .scanner-overlay::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border: 2px solid rgba(37, 99, 235, 0.3);
            border-radius: 12px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 600;
            margin: 8px;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .btn-success {
            background: var(--success);
        }

        .btn-error {
            background: var(--error);
        }

        .status-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin: 16px 0;
            font-weight: 500;
        }

        .status-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .status-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .enrollments-list {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
        }

        .enrollments-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .enrollments-table th {
            background: #f8fafc;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            color: #374151;
            border-bottom: 2px solid var(--border);
        }

        .enrollments-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .enrollments-table tr:last-child td {
            border-bottom: none;
        }

        .enrollments-table tr:hover {
            background: #f8fafc;
        }

        .student-name {
            font-weight: 600;
            color: #1f2937;
        }

        .lrn-cell {
            font-family: 'Courier New', monospace;
            font-weight: 500;
            color: var(--primary);
        }

        .date-cell {
            color: var(--muted);
            font-size: 13px;
        }
    </style>
</head>

<body>
    <?php 
    $user_role = $_SESSION['user']['role'] ?? $_SESSION['user_role'] ?? '';
    if ($user_role === 'admin') {
        require_once dirname(__DIR__) . '/admin/admin_header.php';
        require_once dirname(__DIR__) . '/admin/admin_sidebar.php';
    } elseif ($user_role === 'registrar') {
        require_once dirname(__DIR__) . '/header.php';
        require_once __DIR__ . '/registrar_side_panel.php';
    } elseif ($user_role === 'teacher') {
        require_once dirname(__DIR__) . '/teacher/teacher_header.php';
        require_once dirname(__DIR__) . '/teacher/teacher_side_panel.php';
    }
    ?>

    <div class="content main-content">
        <a href="../registration_final.php" class="btn btn-outline" style="margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; background: #fff; color: #64748b; border: 1px solid #d7e0ee; padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 14px; transition: all 0.2s;">
            <i class="fas fa-arrow-left"></i> Back to Registration
        </a>
        <h1>QR Code Enrollment System</h1>

        <div class="scanner-container">
            <h2>Scan Student QR Code</h2>

            <div id="qr-reader-container" style="width: 100%; max-width: 600px; margin: 0 auto 24px;">
                <div id="qr-reader"
                    style="width: 100%; min-height: 300px; border: 2px dashed var(--border); border-radius: 12px; background: #f8fafc; display: flex; align-items: center; justify-content: center;">
                    <div id="camera-placeholder" style="text-align: center; padding: 40px; color: var(--muted);">
                        <div style="font-size: 48px; margin-bottom: 16px;">📷</div>
                        <div style="font-size: 14px;">Click "Start Camera" to begin scanning</div>
                    </div>
                </div>
            </div>
            <div style="text-align: center;">
                <button class="btn" id="startBtn" onclick="startScanner()">Start Camera</button>
                <button class="btn btn-error" id="stopBtn" onclick="stopScanner()" style="display: none;">Stop Camera</button>
            </div>
            <div id="status-message"></div>
            <div id="student-details"
                style="display: none; margin-top: 20px; padding: 16px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
                <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #374151;">Student Details Found</h3>
                <div id="student-info-display"></div>
                <div style="margin-top: 16px;">
                    <button class="btn btn-success" onclick="confirmEnrollment()">Confirm Enrollment</button>
                    <button class="btn btn-error" onclick="cancelEnrollment()">Cancel</button>
                </div>
            </div>
        </div>

        <div class="scanner-container">
            <h2>Manual Student Enrollment</h2>
            <form id="manualEnrollmentForm" method="post" action="">
                <input type="hidden" name="registration_id" id="registration_id">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Student Name:</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="student_name" id="student_name" required
                                style="flex: 1; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                                placeholder="Enter student full name">
                            <button type="button" id="searchStudentBtn" class="btn"
                                style="padding: 8px 16px; white-space: nowrap;">Search</button>
                        </div>
                        <div id="searchResult"
                            style="margin-top: 8px; padding: 8px; border-radius: 6px; display: none;"></div>
                        <small style="color: var(--muted); font-size: 12px;">Enter student name and click Search to
                            auto-fill details</small>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">LRN (Learner Reference
                            Number):</label>
                        <input type="text" name="lrn" id="lrn" required
                            style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                            placeholder="Enter 12-digit LRN" maxlength="12" pattern="[0-9]{12}">
                        <small style="color: var(--muted); font-size: 12px;">Must be exactly 12 digits</small>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Grade Level:</label>
                        <select name="grade_level" id="grade_level" required
                            style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;">
                            <option value="">Select Grade Level</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Section:</label>
                        <input type="text" name="section" id="section" required
                            style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                            placeholder="Enter section" value="Section A">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Birthday:</label>
                        <input type="date" name="birthday" id="birthday" required
                            style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;">
                        <small style="color: var(--muted); font-size: 12px;">Student's date of birth</small>
                    </div>
                    <div>
                        <!-- Empty div for grid layout -->
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <h3 style="margin: 0 0 16px 0; font-size: 16px; color: #374151;">Guardian Details for Student ID
                    </h3>
                    <div
                        style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; margin-bottom: 12px;">
                            <div
                                style="background: #3b82f6; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-right: 12px; font-size: 12px; font-weight: bold;">
                                i</div>
                            <div style="font-weight: 600; color: #1f2937;">ID Contact Person Information</div>
                        </div>
                        <div style="font-size: 14px; color: #6b7280; line-height: 1.5;">
                            The information below will be displayed on the student's ID card for emergency contact
                            purposes.
                            This should match the person selected during registration as the primary contact for the
                            student.
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Guardian Full
                                Name:</label>
                            <input type="text" name="guardian_full_name" id="guardian_full_name"
                                style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                                placeholder="Enter guardian full name">
                            <small style="color: var(--muted); font-size: 12px;">This name will appear on the student's
                                ID card</small>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Contact Number:</label>
                            <input type="text" name="guardian_contact" id="guardian_contact"
                                style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                                placeholder="Enter contact number">
                            <small style="color: var(--muted); font-size: 12px;">This number will appear on the
                                student's ID card</small>
                        </div>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Full Address:</label>
                        <textarea name="address" id="address" rows="3"
                            style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; resize: vertical;"
                            placeholder="Enter complete address"></textarea>
                        <small style="color: var(--muted); font-size: 12px;">This address will appear on the student's
                            ID card</small>
                    </div>
                </div>
                <div style="text-align: center;">
                    <button type="submit" class="btn btn-success">Enroll Student Manually</button>
                </div>
            </form>
        </div>

        <div class="enrollments-list">
            <h2>Recent Enrollments</h2>
            <?php if (empty($recent_enrollments)): ?>
                <p style="color: var(--muted); text-align: center; padding: 40px;">No enrollments yet. Scan a QR code to
                    enroll a student.</p>
            <?php else: ?>
                <table class="enrollments-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>LRN</th>
                            <th>Birthday</th>
                            <th>Grade Level</th>
                            <th>Section</th>
                            <th>Enrollment Date</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_enrollments as $enrollment): ?>
                            <tr>
                                <td class="student-name"><?= htmlspecialchars($enrollment['student_name']) ?></td>
                                <td class="lrn-cell"><?= htmlspecialchars($enrollment['lrn']) ?></td>
                                <td class="date-cell">
                                    <?php
                                    if ($enrollment['birthdate']) {
                                        echo date('M d, Y', strtotime($enrollment['birthdate']));
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($enrollment['grade_level']) ?></td>
                                <td><?= htmlspecialchars($enrollment['section']) ?></td>
                                <td class="date-cell">
                                    <?php
                                    $dateField = isset($enrollment['enrolled_at']) ? $enrollment['enrolled_at'] : $enrollment['created_at'];
                                    echo date('M d, Y', strtotime($dateField));
                                    ?>
                                </td>
                                <td class="date-cell">
                                    <?php
                                    $dateField = isset($enrollment['enrolled_at']) ? $enrollment['enrolled_at'] : $enrollment['created_at'];
                                    echo date('h:i A', strtotime($dateField));
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // QR Scanner using html5-qrcode library
        let html5QrCode = null;
        let scannerActive = false;
        let currentQRData = null;

        async function startScanner() {
            if (scannerActive) return;

            showMessage('Requesting camera access...', 'success');

            // Hide placeholder, show loading
            const placeholder = document.getElementById('camera-placeholder');
            if (placeholder) {
                placeholder.innerHTML = '<div style="font-size: 24px;">⏳</div><div>Starting camera...</div>';
            }

            // Hide start button, show stop button
            document.getElementById('startBtn').style.display = 'none';
            document.getElementById('stopBtn').style.display = 'inline-flex';

            // Check if library is loaded
            if (typeof Html5Qrcode === 'undefined') {
                showMessage('Error: QR scanning library failed to load. Please refresh the page.', 'error');
                resetButtons();
                return;
            }

            try {
                // Clear the qr-reader div content before starting
                const qrReader = document.getElementById('qr-reader');
                qrReader.innerHTML = '';
                qrReader.style.display = 'block';

                // Initialize scanner
                html5QrCode = new Html5Qrcode("qr-reader");

                const config = {
                    fps: 10,
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.0
                };

                // Enumerate all available cameras and try each one
                let cameras = [];
                try {
                    cameras = await Html5Qrcode.getCameras();
                } catch (camErr) {
                    console.warn('Could not enumerate cameras:', camErr);
                }

                let started = false;

                // Try each camera by device ID
                if (cameras.length > 0) {
                    for (let i = 0; i < cameras.length; i++) {
                        try {
                            await html5QrCode.start(
                                cameras[i].id,
                                config,
                                onScanSuccess,
                                onScanFailure
                            );
                            started = true;
                            break;
                        } catch (e) {
                            console.warn('Camera ' + (cameras[i].label || cameras[i].id) + ' failed:', e.message);
                        }
                    }
                }

                // Fallback: try facingMode environment
                if (!started) {
                    try {
                        await html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure);
                        started = true;
                    } catch (e) {
                        try {
                            await html5QrCode.start({ facingMode: "user" }, config, onScanSuccess, onScanFailure);
                            started = true;
                        } catch (e2) {
                            await html5QrCode.start(true, config, onScanSuccess, onScanFailure);
                            started = true;
                        }
                    }
                }

                scannerActive = true;
                showMessage('Camera started. Point at a QR code to scan automatically.', 'success');

            } catch (err) {
                console.error('Camera error:', err);
                showMessage('Camera error: ' + err.message, 'error');
                resetButtons();
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            try {
                const qrData = JSON.parse(decodedText);
                stopScanner();
                enrollStudent(qrData);
            } catch (e) {
                // Not JSON, might be LRN
                if (/^\d{12}$/.test(decodedText)) {
                    stopScanner();
                    lookupStudentByLRN(decodedText);
                } else {
                    showMessage('Invalid QR code format. Expected student data or 12-digit LRN.', 'error');
                }
            }
        }

        function onScanFailure(error) {
            // Ignore continuous scan failures
        }

        function resetButtons() {
            document.getElementById('startBtn').style.display = 'inline-flex';
            document.getElementById('stopBtn').style.display = 'none';

            const placeholder = document.getElementById('camera-placeholder');
            if (placeholder) {
                placeholder.innerHTML = '<div style="font-size: 48px; margin-bottom: 16px;">📷</div><div style="font-size: 14px;">Click "Start Camera" to begin scanning</div>';
            }
        }

        async function stopScanner() {
            if (html5QrCode && scannerActive) {
                try {
                    await html5QrCode.stop();
                    scannerActive = false;
                    html5QrCode = null;
                } catch (err) {
                    console.error('Error stopping scanner:', err);
                }
            }

            resetButtons();

            // Restore placeholder
            const qrReader = document.getElementById('qr-reader');
            if (qrReader) {
                qrReader.innerHTML = `
                    <div id="camera-placeholder" style="text-align: center; padding: 40px; color: var(--muted);">
                        <div style="font-size: 48px; margin-bottom: 16px;">📷</div>
                        <div style="font-size: 14px;">Click "Start Camera" to begin scanning</div>
                    </div>
                `;
            }

            showMessage('Camera stopped', 'success');
        }

        function lookupStudentByLRN(lrn) {
            showMessage('Looking up student with LRN: ' + lrn + '...', 'success');

            const formData = new FormData();
            formData.append('lookup_lrn', '1');
            formData.append('lrn', lrn);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.student) {
                        enrollStudent(data.student);
                    } else {
                        showMessage('Student not found with LRN: ' + lrn, 'error');
                    }
                })
                .catch(error => {
                    console.error('Lookup error:', error);
                    showMessage('Error looking up student: ' + error.message, 'error');
                });
        }

        function enrollStudent(qrData) {
            currentQRData = qrData;
            showMessage('QR code detected! Review student details below.', 'success');
            displayStudentDetails(qrData);
        }

        function displayStudentDetails(student) {
            const studentInfoDiv = document.getElementById('student-info-display');
            
            const name = student.student_name || student.full_name || student.name || 'N/A';
            const lrn = student.lrn || 'N/A';
            const gradeLevel = student.grade_level || student.grade_level_to_enroll || 'N/A';
            const section = student.section || 'Section A';
            const birthdate = student.birthdate || student.birthday || 'N/A';
            const guardianName = student.guardian_full_name || student.guardian_name || 'N/A';
            const guardianContact = student.guardian_contact || student.contact || 'N/A';
            const address = student.address || 'N/A';

            studentInfoDiv.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div><strong>Name:</strong> ${name}</div>
                    <div><strong>LRN:</strong> <span style="font-family: monospace; background: #eff6ff; padding: 2px 8px; border-radius: 4px; color: #2563eb;">${lrn}</span></div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div><strong>Grade Level:</strong> ${gradeLevel}</div>
                    <div><strong>Section:</strong> ${section}</div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div><strong>Birthdate:</strong> ${birthdate}</div>
                    <div><strong>Guardian:</strong> ${guardianName}</div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div><strong>Contact:</strong> ${guardianContact}</div>
                    <div><strong>Address:</strong> ${address}</div>
                </div>
            `;

            document.getElementById('student-details').style.display = 'block';
        }

        function confirmEnrollment() {
            if (!currentQRData) return;
            showMessage('Enrolling student...', 'success');

            const formData = new FormData();
            formData.append('qr_enroll', '1');
            formData.append('student_name', currentQRData.student_name || currentQRData.full_name || currentQRData.name || '');
            formData.append('lrn', currentQRData.lrn || '');
            formData.append('grade_level', currentQRData.grade_level || currentQRData.grade_level_to_enroll || '');
            formData.append('section', currentQRData.section || 'Section A');
            formData.append('birthday', currentQRData.birthdate || currentQRData.birthday || '');
            formData.append('guardian_full_name', currentQRData.guardian_full_name || currentQRData.guardian_name || '');
            formData.append('guardian_contact', currentQRData.guardian_contact || currentQRData.contact || '');
            formData.append('address', currentQRData.address || '');

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(`Student enrolled successfully! LRN: ${data.lrn}`, 'success');
                        document.getElementById('student-details').style.display = 'none';
                        currentQRData = null;
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        if (data.message && data.message.includes('already enrolled')) {
                            showEnhancedErrorMessage(data.message);
                        } else {
                            showMessage('Enrollment failed: ' + data.message, 'error');
                        }
                    }
                })
                .catch(error => {
                    showMessage('Network error during enrollment: ' + error.message, 'error');
                });
        }

        function cancelEnrollment() {
            document.getElementById('student-details').style.display = 'none';
            currentQRData = null;
            showMessage('Enrollment cancelled', 'success');
        }

        function showMessage(message, type) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.innerHTML = `<div class="status-message status-${type}">${message}</div>`;

            const timeout = type === 'error' ? 8000 : 5000;
            setTimeout(() => {
                statusDiv.innerHTML = '';
            }, timeout);
        }

        // Enhanced error message for duplicate enrollment
        function showEnhancedErrorMessage(message) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.innerHTML = `
                <div class="status-message status-error" style="padding: 16px; border-radius: 8px;">
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <span style="font-size: 18px;">⚠️</span>
                                <strong>Duplicate Enrollment Detected</strong>
                            </div>
                            <div style="font-size: 14px; line-height: 1.4; margin-bottom: 12px;">
                                ${message}
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button type="button" onclick="clearForm()" 
                                        style="padding: 6px 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    Clear Form
                                </button>
                                <button type="button" onclick="viewEnrollments()" 
                                        style="padding: 6px 12px; background: #2196f3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    View Enrollments
                                </button>
                                <button type="button" onclick="hideMessage()" 
                                        style="padding: 6px 12px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    Dismiss
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // Helper functions for enhanced error message
        function clearForm() {
            document.getElementById('manualEnrollmentForm').reset();
            document.getElementById('searchResult').style.display = 'none';
            document.getElementById('searchResult').innerHTML = '';
            hideMessage();
            showMessage('Form cleared. You can now search for a different student.', 'success');
        }

        function viewEnrollments() {
            // Redirect to enrollments list or open in new tab
            window.open('enrollment_list.php', '_blank');
        }

        function hideMessage() {
            document.getElementById('status-message').innerHTML = '';
        }

        function checkDuplicateEnrollment(lrn, studentName) {
            return new Promise((resolve, reject) => {
                if (!lrn && !studentName) {
                    resolve(false);
                    return;
                }

                const formData = new FormData();
                formData.append('check_duplicate', '1');
                if (lrn) formData.append('lrn', lrn);
                if (studentName) formData.append('student_name', studentName);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        resolve(data);
                    })
                    .catch(error => {
                        console.error('Error checking duplicate:', error);
                        reject(error);
                    });
            });
        }

        // Function to select a student from search results
        window.selectStudent = function (index, student) {
            const studentFullName = student.full_name;
            const searchResultDiv = document.getElementById('searchResult');

            // Show confirmation dialog
            searchResultDiv.innerHTML = `
                <div style="background: #fff3e0; border: 1px solid #ff9800; color: #e65100; padding: 16px; border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <span style="font-size: 18px;">❓</span>
                        <strong>Confirm Student Selection</strong>
                    </div>
                    <div style="font-size: 14px; line-height: 1.5; margin-bottom: 16px;">
                        <div style="background: #f5f5f5; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                            <div style="font-weight: 600; color: #333; margin-bottom: 8px;">Selected Student Details:</div>
                            <div><strong>Name:</strong> ${studentFullName}</div>
                            <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                            <div><strong>Grade Level:</strong> ${student.grade_level_to_enroll || 'N/A'}</div>
                            <div><strong>Birthdate:</strong> ${student.birthdate || 'N/A'}</div>
                            <div><strong>Guardian:</strong> ${student.guardian_full_name || 'N/A'}</div>
                        </div>
                        <div style="color: #666; font-size: 13px;">
                            Please confirm this is the correct student before proceeding with enrollment.
                        </div>
                    </div>
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" onclick="cancelStudentSelection()" 
                                style="padding: 8px 16px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 14px;">
                            Cancel
                        </button>
                        <button type="button" onclick="confirmStudentSelection(${JSON.stringify(student).replace(/"/g, '&quot;')})" 
                                style="padding: 8px 16px; background: #4caf50; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600;">
                            Confirm & Load Data
                        </button>
                    </div>
                </div>
            `;
            searchResultDiv.style.display = 'block';
        };

        // Function to cancel student selection
        window.cancelStudentSelection = function () {
            const searchResultDiv = document.getElementById('searchResult');
            searchResultDiv.style.display = 'none';
            searchResultDiv.innerHTML = '';
        };

        // Function to confirm student selection and load data
        window.confirmStudentSelection = function (student) {
            const studentFullName = student.full_name;

            // Check if student is already enrolled
            checkDuplicateEnrollment(student.lrn, student.first_name + ' ' + student.last_name)
                .then(result => {
                    const searchResultDiv = document.getElementById('searchResult');

                    if (result.is_duplicate) {
                        searchResultDiv.innerHTML = `
                            <div style="background: #ffebee; border: 1px solid #f44336; color: #c62828; padding: 12px; border-radius: 6px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span style="font-size: 16px;">⚠️</span>
                                    <strong>Student Already Enrolled:</strong>
                                </div>
                                <div style="font-size: 14px; line-height: 1.4;">
                                    <div><strong>Name:</strong> ${studentFullName}</div>
                                    <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                                    <div style="margin-top: 8px; color: #d32f2f;">
                                        This student is already enrolled in the current school year.
                                    </div>
                                </div>
                            </div>
                        `;
                        showMessage('This student is already enrolled. Please check the existing enrollment details.', 'error');
                        return;
                    }

                    // Auto-fill form with student details
                    document.getElementById('student_name').value = student.full_name; // Fill search bar with student's full name
                    document.getElementById('lrn').value = student.lrn || '';
                    document.getElementById('grade_level').value = student.grade_level_to_enroll || '';
                    document.getElementById('birthday').value = student.birthdate || '';
                    document.getElementById('guardian_full_name').value = student.guardian_full_name || '';
                    document.getElementById('guardian_contact').value = student.guardian_contact || '';
                    document.getElementById('address').value = student.address || '';
                    document.getElementById('registration_id').value = student.id || '';

                    // Update search result to show selected student
                    searchResultDiv.innerHTML = `
                        <div style="background: #e8f5e8; border: 1px solid #4caf50; color: #2e7d32; padding: 12px; border-radius: 6px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <span style="font-size: 16px;">✅</span>
                                <strong>Student Data Loaded Successfully:</strong>
                            </div>
                            <div style="font-size: 14px; line-height: 1.4;">
                                <div><strong>Name:</strong> ${studentFullName}</div>
                                <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                                <div><strong>Grade Level:</strong> ${student.grade_level_to_enroll || 'N/A'}</div>
                                <div><strong>Birthdate:</strong> ${student.birthdate || 'N/A'}</div>
                            </div>
                            <div style="margin-top: 8px; padding: 8px; background: #f0f8f0; border-radius: 4px; font-size: 12px; color: #2e7d32;">
                                ✓ Search bar and form fields have been auto-filled with student data
                            </div>
                        </div>
                    `;

                    const contactPerson = student.id_contact_person || 'guardian';
                    const contactPersonText = contactPerson.charAt(0).toUpperCase() + contactPerson.slice(1);
                    showMessage(`Student details loaded successfully! Selected contact person for ID: ${contactPersonText}`, 'success');
                })
                .catch(error => {
                    console.error('Error checking duplicate enrollment:', error);
                    // Continue with form filling even if duplicate check fails
                    document.getElementById('student_name').value = student.full_name; // Fill search bar with student's full name
                    document.getElementById('lrn').value = student.lrn || '';
                    document.getElementById('grade_level').value = student.grade_level_to_enroll || '';
                    document.getElementById('birthday').value = student.birthdate || '';
                    document.getElementById('guardian_full_name').value = student.guardian_full_name || '';
                    document.getElementById('guardian_contact').value = student.guardian_contact || '';
                    document.getElementById('address').value = student.address || '';
                    document.getElementById('registration_id').value = student.id || '';

                    const contactPerson = student.id_contact_person || 'guardian';
                    const contactPersonText = contactPerson.charAt(0).toUpperCase() + contactPerson.slice(1);
                    showMessage(`Student details loaded successfully! Selected contact person for ID: ${contactPersonText}`, 'success');
                });
        };

        // Clear search result when user types in student name field
        document.getElementById('student_name').addEventListener('input', function () {
            const searchResultDiv = document.getElementById('searchResult');
            if (searchResultDiv.style.display !== 'none') {
                searchResultDiv.style.display = 'none';
                searchResultDiv.innerHTML = '';
            }
        });

        // Handle student search
        document.getElementById('searchStudentBtn').addEventListener('click', function () {
            const studentName = document.getElementById('student_name').value.trim();

            if (!studentName) {
                showMessage('Please enter a student name to search', 'error');
                return;
            }

            // Show loading state
            const searchResultDiv = document.getElementById('searchResult');
            searchResultDiv.innerHTML = `
                <div style="background: #e3f2fd; border: 1px solid #2196f3; color: #1565c0; padding: 12px; border-radius: 6px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 16px;">🔍</span>
                        <strong>Searching for student...</strong>
                    </div>
                    <div style="font-size: 14px; margin-top: 4px;">
                        Please wait while we search for "${studentName}"
                    </div>
                </div>
            `;
            searchResultDiv.style.display = 'block';

            showMessage('Searching for student...', 'success');

            const formData = new FormData();
            formData.append('search_student', '1');
            formData.append('student_name', studentName);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.text();
                })
                .then(text => {
                    console.log('Raw response:', text);

                    try {
                        const data = JSON.parse(text);

                        if (data.success) {
                            const searchResultDiv = document.getElementById('searchResult');

                            if (data.multiple) {
                                // Multiple students found - show dropdown
                                let dropdownHTML = `
                                <div style="background: #e3f2fd; border: 1px solid #2196f3; color: #1565c0; padding: 12px; border-radius: 6px;">
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <span style="font-size: 16px;">👥</span>
                                        <strong>Multiple Students Found (${data.count}):</strong>
                                    </div>
                                    <div style="font-size: 14px; margin-bottom: 12px;">
                                        Please select the correct student from the list below:
                                    </div>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                            `;

                                data.students.forEach((student, index) => {
                                    const studentFullName = student.full_name;
                                    dropdownHTML += `
                                    <div class="student-option" data-student-index="${index}" style="padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; transition: background-color 0.2s;" 
                                         onmouseover="this.style.backgroundColor='#f5f5f5'" 
                                         onmouseout="this.style.backgroundColor='white'"
                                         onclick="selectStudent(${index}, ${JSON.stringify(student).replace(/"/g, '&quot;')})">
                                        <div style="font-weight: 600; color: #333; margin-bottom: 4px;">${studentFullName}</div>
                                        <div style="font-size: 12px; color: #666;">
                                            <span>LRN: ${student.lrn || 'N/A'}</span> • 
                                            <span>Grade: ${student.grade_level_to_enroll || 'N/A'}</span> • 
                                            <span>Birthdate: ${student.birthdate || 'N/A'}</span>
                                        </div>
                                    </div>
                                `;
                                });

                                dropdownHTML += `
                                    </div>
                                </div>
                            `;

                                searchResultDiv.innerHTML = dropdownHTML;
                            } else {
                                // Single student found
                                const student = data.students[0];
                                const studentFullName = student.full_name;
                                searchResultDiv.innerHTML = `
                                <div style="background: #e8f5e8; border: 1px solid #4caf50; color: #2e7d32; padding: 12px; border-radius: 6px;">
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                        <span style="font-size: 16px;">✅</span>
                                        <strong>Student Found:</strong>
                                    </div>
                                    <div style="font-size: 14px; line-height: 1.4;">
                                        <div><strong>Name:</strong> ${studentFullName}</div>
                                        <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                                        <div><strong>Grade Level:</strong> ${student.grade_level_to_enroll || 'N/A'}</div>
                                        <div><strong>Birthdate:</strong> ${student.birthdate || 'N/A'}</div>
                                    </div>
                                </div>
                            `;

                                // Show confirmation for single student
                                selectStudent(0, student);
                            }

                            searchResultDiv.style.display = 'block';
                        } else {
                            // Display not found message
                            const searchResultDiv = document.getElementById('searchResult');
                            searchResultDiv.innerHTML = `
                            <div style="background: #fff3e0; border: 1px solid #ff9800; color: #e65100; padding: 12px; border-radius: 6px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 16px;">🔍</span>
                                    <strong>Student Not Found</strong>
                                </div>
                                <div style="font-size: 14px; margin-top: 4px;">
                                    No student found with the name "${studentName}". Please check the spelling or try a different search term.
                                </div>
                                <div style="margin-top: 12px;">
                                    <a href="<?= url_for('/registration_final.php') ?>?first_name=${encodeURIComponent(studentName)}" 
                                       class="btn btn-success" style="padding: 8px 16px; font-size: 13px; text-decoration: none; display: inline-block;">
                                       Register "${studentName}" Now
                                    </a>
                                </div>
                            </div>
                        `;
                            searchResultDiv.style.display = 'block';
                            showMessage('Student not found: ' + (data.message || 'Unknown error'), 'error');
                        }
                    } catch (parseError) {
                        console.error('JSON parse error:', parseError);
                        console.error('Response text:', text);

                        // Display error in search result
                        const searchResultDiv = document.getElementById('searchResult');
                        searchResultDiv.innerHTML = `
                        <div style="background: #ffebee; border: 1px solid #f44336; color: #c62828; padding: 12px; border-radius: 6px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 16px;">❌</span>
                                <strong>Search Error</strong>
                            </div>
                            <div style="font-size: 14px; margin-top: 4px;">
                                Invalid response from server. Please try again.
                            </div>
                        </div>
                    `;
                        searchResultDiv.style.display = 'block';

                        showMessage('Invalid response from server. Please check console for details.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);

                    // Display error in search result
                    const searchResultDiv = document.getElementById('searchResult');
                    searchResultDiv.innerHTML = `
                    <div style="background: #ffebee; border: 1px solid #f44336; color: #c62828; padding: 12px; border-radius: 6px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 16px;">❌</span>
                            <strong>Search Error</strong>
                        </div>
                        <div style="font-size: 14px; margin-top: 4px;">
                            Network error: ${error.message}
                        </div>
                    </div>
                `;
                    searchResultDiv.style.display = 'block';

                    showMessage('Search error: ' + error.message, 'error');
                });
        });

        // Handle manual enrollment form
        document.getElementById('manualEnrollmentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            // Validate guardian information
            const guardianName = document.getElementById('guardian_full_name').value.trim();
            const guardianContact = document.getElementById('guardian_contact').value.trim();
            const studentName = document.getElementById('student_name').value.trim();
            const lrn = document.getElementById('lrn').value.trim();

            if (!guardianName) {
                showMessage('Please enter the guardian\'s full name for the ID card.', 'error');
                return;
            }

            if (!guardianContact) {
                showMessage('Please enter the guardian\'s contact number for the ID card.', 'error');
                return;
            }

            // Check for duplicates before submitting
            checkDuplicateEnrollment(lrn, studentName)
                .then(result => {
                    if (result.is_duplicate) {
                        showMessage('This student is already enrolled. Please check the existing enrollment details.', 'error');
                        return;
                    }

                    // Proceed with enrollment if no duplicates
                    submitEnrollmentForm();
                })
                .catch(error => {
                    console.error('Error checking duplicate:', error);
                    // Proceed with enrollment if duplicate check fails
                    submitEnrollmentForm();
                });
        });

        function submitEnrollmentForm() {
            const form = document.getElementById('manualEnrollmentForm');
            const formData = new FormData(form);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(`Student enrolled successfully! LRN: ${data.lrn}`, 'success');
                        form.reset();
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        // Enhanced error display for duplicate enrollment
                        if (data.message && data.message.includes('already enrolled')) {
                            showEnhancedErrorMessage(data.message);
                        } else {
                            showMessage('Enrollment failed: ' + data.message, 'error');
                        }
                    }
                })
                .catch(error => {
                    showMessage('Network error: ' + error.message, 'error');
                });
        }

        // Initialize page
        window.addEventListener('load', function () {
            showMessage('Click "Start Camera" to begin QR code scanning or use the manual enrollment form below.', 'success');
        });
    </script>
</body>

</html>