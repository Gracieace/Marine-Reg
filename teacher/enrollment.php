<?php
// Start output buffering to catch any unwanted output
ob_start();

require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin', 'teacher']);

// Set error reporting to prevent warnings from interfering with JSON
error_reporting(E_ERROR | E_PARSE);

require_once dirname(__DIR__) . '/config/db.php';
$pdo = db_connect();
initialize_schema($pdo);

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
        $current_sy = get_active_school_year($pdo);
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
        $current_sy = get_active_school_year($pdo);

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
            'first_name' => $student_name, // Simplified for manual enrollment
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
            $current_sy = get_active_school_year($pdo);
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
            $current_sy = get_active_school_year($pdo);
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
        $current_sy = get_active_school_year($pdo);
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
                school_year, enrolled_at, created_at, qr_code_url
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
                CONCAT(COALESCE(r.last, ""), ", ", COALESCE(r.first, ""), " ", COALESCE(r.middle, "")) as full_name,
                r.first, r.last, r.middle,
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

$current_sy = get_active_school_year($pdo);

// Check if enrolled_at column exists, if not use created_at
$columns = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'enrolled_at'")->fetch();
$orderColumn = $columns ? 'enrolled_at' : 'created_at';

// Fetch all available sections for the dropdown (mapped by grade level)
$section_stmt = $pdo->prepare("SELECT DISTINCT section_name, grade_level FROM sections WHERE school_year = ? ORDER BY grade_level, section_name");
$section_stmt->execute([$current_sy]);
$all_assigned_sections = $section_stmt->fetchAll(PDO::FETCH_ASSOC);

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

    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root {
            --bg: #f6f8fc;
            --card: #ffffff;
            --muted: #64748b;
            --border: #e2e8f0;
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --error: #ef4444;
            --text-main: #0f172a;
        }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 0;
        }

        .content {
            padding: 140px 24px 40px;
            /* Base padding */
            transition: all 0.3s ease;
            max-width: none;
            /* Remove constraint */
        }

        /* Adjust for sidebar if present */
        @media (min-width: 769px) {
            .content {
                /* margin-left handled by sidebar.css via .sidebar.is-open ~ .main-content */
                padding: 140px 40px 40px;
            }
        }

        h1 {
            margin: 0 0 24px 0;
            font-weight: 800;
            font-size: 28px;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 16px;
        }

        /* Enhanced Card Style */
        .scanner-container,
        .enrollments-list {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px;
            margin-bottom: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: box-shadow 0.3s ease;
        }

        .scanner-container:hover,
        .enrollments-list:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
        }

        /* Scanner Specifics */
        .scanner-area {
            width: 100%;
            max-width: 600px;
            height: 400px;
            background: #f8fafc;
            border: 2px dashed var(--border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 32px;
            position: relative;
            overflow: hidden;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        #scanner {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .scanner-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 240px;
            height: 240px;
            border: 2px solid var(--primary);
            border-radius: 20px;
            pointer-events: none;
            box-shadow: 0 0 0 4000px rgba(0, 0, 0, 0.5);
            /* Dim effect */
            z-index: 10;
        }

        .scanner-overlay::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border: 2px solid rgba(59, 130, 246, 0.5);
            border-radius: 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.5;
                transform: scale(1.02);
            }
        }

        /* Premium Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.025em;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: none;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 1px;
            margin: 8px;
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }

        .btn-success:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
        }

        .btn-error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2);
        }

        .btn-error:hover:not(:disabled) {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3);
        }

        /* Inputs */
        input[type="text"],
        input[type="date"],
        input:not([type]), /* Handle inputs without explicit type */
        select,
        textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 15px;
            color: #1e293b;
            background-color: #f8fafc;
            transition: all 0.2s ease;
            outline: none;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #3b82f6;
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        /* Custom Searchable Dropdown Styles */
        .dropdown-container {
            position: relative;
            width: 100%;
        }

        .dropdown-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-top: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            display: none;
            backdrop-filter: blur(8px);
        }

        .dropdown-results.is-active {
            display: block;
            animation: dropdownSlide 0.2s ease-out;
        }

        @keyframes dropdownSlide {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dropdown-item {
            padding: 12px 16px;
            cursor: pointer;
            font-size: 14px;
            color: #475569;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dropdown-item:hover {
            background-color: #f1f5f9;
            color: #3b82f6;
            padding-left: 20px;
        }

        .dropdown-item i {
            font-size: 12px;
            opacity: 0.5;
        }

        .dropdown-no-results {
            padding: 12px 16px;
            font-size: 13px;
            color: #94a3b8;
            text-align: center;
            font-style: italic;
        }

        /* Custom Arrow for Datalist Input */
        .input-with-icon {
            position: relative;
        }

        .input-with-icon .dropdown-arrow {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #94a3b8;
            font-size: 10px;
            transition: transform 0.2s ease;
        }

        .dropdown-container.is-open .dropdown-arrow {
            transform: translateY(-50%) rotate(180deg);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        label {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
            display: block;
        }

        /* Table Styles */
        .enrollments-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 0;
        }

        .enrollments-table th {
            background: #f8fafc;
            padding: 16px 24px;
            text-align: left;
            font-weight: 700;
            font-size: 12px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
        }

        .enrollments-table td {
            padding: 16px 24px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #334155;
            transition: background-color 0.2s;
        }

        .enrollments-table tr:hover td {
            background-color: #f8fafc;
        }

        .enrollments-table tr:last-child td {
            border-bottom: none;
        }

        .student-name {
            font-weight: 600;
            color: #0f172a;
        }

        .lrn-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--primary);
            background: #eff6ff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
        }

        .date-cell {
            color: var(--muted);
            font-size: 13px;
        }

        /* Grid Helpers */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        @media (min-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr 1fr;
            }
        }

        .status-message {
            padding: 16px;
            border-radius: 12px;
            margin: 24px 0;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
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
        require_once dirname(__DIR__) . '/registrar/registrar_side_panel.php';
    } elseif ($user_role === 'teacher') {
        require_once __DIR__ . '/teacher_header.php';
        require_once __DIR__ . '/teacher_side_panel.php';
    }
    ?>

    <div class="content main-content">
        <a href="<?= url_for('/registration_final.php') ?>" class="btn"
            style="background: #64748b; margin-bottom: 24px; text-decoration: none; padding-left: 20px; padding-right: 20px;">
            <span style="margin-right: 8px;">←</span> Back to Registration
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
                <button class="btn btn-error" id="stopBtn" onclick="stopScanner()" style="display: none;">Stop
                    Camera</button>
            </div>
            <div id="status-message"></div>
            <div id="student-details"
                style="display: none; margin-top: 24px; padding: 24px; background: #f8fafc; border: 1px solid var(--border); border-radius: 12px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--text-main); font-weight: 700;">Student
                    Details Found</h3>
                <div id="student-info-display"></div>
                <div style="margin-top: 24px; display: flex; gap: 12px;">
                    <button class="btn btn-success" onclick="confirmEnrollment()">Confirm Enrollment</button>
                    <button class="btn btn-error" onclick="cancelEnrollment()">Cancel</button>
                </div>
            </div>
        </div>

        <div class="scanner-container">
            <h2>Manual Student Enrollment</h2>
            <form id="manualEnrollmentForm" method="post" action="">
                <div class="grid-2">
                    <div class="form-group">
                        <label>Student Name:</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="student_name" id="student_name" required
                                placeholder="Enter student full name" style="flex: 1;">
                            <button type="button" id="searchStudentBtn" class="btn"
                                style="margin: 0; padding: 0 24px; white-space: nowrap;">Search</button>
                        </div>
                        <div id="searchResult"
                            style="margin-top: 12px; padding: 12px; border-radius: 8px; display: none;"></div>
                        <small style="color: var(--muted); font-size: 12px; display: block; margin-top: 4px;">Enter
                            student name and click Search to auto-fill details</small>
                    </div>
                    <div class="form-group">
                        <label>LRN (Learner Reference Number):</label>
                        <input type="text" name="lrn" id="lrn" required placeholder="Enter 12-digit LRN" maxlength="12"
                            pattern="[0-9]{12}">
                        <small style="color: var(--muted); font-size: 12px; display: block; margin-top: 4px;">Must be
                            exactly 12 digits</small>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Grade Level:</label>
                        <select name="grade_level" id="grade_level" required>
                            <option value="">Select Grade Level</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Section:</label>
                        <div class="dropdown-container" id="section-dropdown">
                            <div class="input-with-icon">
                                <input type="text" name="section" id="section" required 
                                    placeholder="Type or select section" autocomplete="off">
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="dropdown-results" id="section_results"></div>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Birthday:</label>
                        <input type="date" name="birthday" id="birthday" required>
                        <small style="color: var(--muted); font-size: 12px; display: block; margin-top: 4px;">Student's
                            date of birth</small>
                    </div>
                    <div class="form-group">
                        <!-- Spacer -->
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <h3 style="margin: 0 0 16px 0; font-size: 16px; color: var(--text-main); font-weight: 700;">Guardian
                        Details for Student ID</h3>
                    <div
                        style="background: #eff6ff; border: 1px solid #dbeafe; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <div
                                style="background: var(--primary); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-right: 12px; font-size: 14px; font-weight: bold;">
                                i</div>
                            <div style="font-weight: 600; color: #1e40af;">ID Contact Person Information</div>
                        </div>
                        <div style="font-size: 14px; color: #1e3a8a; line-height: 1.5; margin-left: 36px;">
                            The information below will be displayed on the student's ID card for emergency contact
                            purposes.
                            This should match the person selected during registration as the primary contact for the
                            student.
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>Guardian Full Name:</label>
                            <input type="text" name="guardian_full_name" id="guardian_full_name"
                                placeholder="Enter guardian full name">
                            <small style="color: var(--muted); font-size: 12px; display: block; margin-top: 4px;">This
                                name will appear on the student's ID card</small>
                        </div>
                        <div class="form-group">
                            <label>Contact Number:</label>
                            <input type="text" name="guardian_contact" id="guardian_contact"
                                placeholder="Enter contact number">
                            <small style="color: var(--muted); font-size: 12px; display: block; margin-top: 4px;">This
                                number will appear on the student's ID card</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Full Address:</label>
                        <textarea name="address" id="address" rows="3" placeholder="Enter complete address"
                            style="resize: vertical;"></textarea>
                        <small style="color: var(--muted); font-size: 12px; display: block; margin-top: 4px;">This
                            address will appear on the student's ID card</small>
                    </div>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn btn-success" style="padding: 14px 32px; font-size: 15px;">Enroll
                        Student Manually</button>
                </div>
            </form>
        </div>

        <div class="enrollments-list">
            <h2>Recent Enrollments</h2>
            <?php if (empty($recent_enrollments)): ?>
                <div style="text-align: center; padding: 60px 0; color: var(--muted);">
                    <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">📋</div>
                    <p style="font-size: 16px;">No enrollments yet. Scan a QR code or enroll manually.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
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
                </div>
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

                // Try each camera by device ID (works best for external/USB cameras)
                if (cameras.length > 0) {
                    for (let i = 0; i < cameras.length; i++) {
                        try {
                            await html5QrCode.start(
                                cameras[i].id,
                                config,
                                onScanSuccess,
                                onScanFailure
                            );
                            console.log('Camera started:', cameras[i].label || cameras[i].id);
                            started = true;
                            break;
                        } catch (e) {
                            console.warn('Camera ' + (cameras[i].label || cameras[i].id) + ' failed:', e.message);
                        }
                    }
                }

                // Fallback: try facingMode environment, then user, then any
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

                let errorMsg = 'Camera error: ';
                if (err.message.includes('NotAllowed') || err.message.includes('Permission') || err.message.includes('denied')) {
                    errorMsg = 'Camera permission denied. Please: 1) Click the lock icon in the address bar, 2) Set Camera to "Allow", 3) Refresh the page completely (Ctrl+Shift+R).';
                } else if (err.message.includes('NotFound') || err.message.includes('no cameras')) {
                    errorMsg = 'No camera found on this device.';
                } else if (err.message.includes('NotReadable') || err.message.includes('in use')) {
                    errorMsg = 'Camera is in use by another application. Please close other apps and try again.';
                } else {
                    errorMsg += err.message;
                }

                showMessage(errorMsg, 'error');
                resetButtons();
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            console.log('QR Code detected:', decodedText.substring(0, 50));

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
            // Ignore - this fires continuously when no QR code is in view
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

            // Display the student details
            displayStudentDetails(qrData);
        }

        function displayStudentDetails(student) {
            const studentInfoDiv = document.getElementById('student-info-display');

            // Handle different possible field names from QR data
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
            if (!currentQRData) {
                showMessage('No student data to enroll.', 'error');
                return;
            }

            showMessage('Enrolling student...', 'success');

            // Prepare form data for enrollment
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

                        // Reload page after 2 seconds to show updated enrollments
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        if (data.message && data.message.includes('already enrolled')) {
                            showEnhancedErrorMessage(data.message);
                        } else {
                            showMessage('Enrollment failed: ' + (data.message || 'Unknown error'), 'error');
                        }
                    }
                })
                .catch(error => {
                    console.error('Enrollment error:', error);
                    showMessage('Network error during enrollment: ' + error.message, 'error');
                });
        }

        function cancelEnrollment() {
            document.getElementById('student-details').style.display = 'none';
            currentQRData = null;
            showMessage('Enrollment cancelled. You can scan another QR code.', 'success');
        }

        function showMessage(message, type) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.innerHTML = `<div class="status-message status-${type}">${message}</div>`;

            // For error messages, show longer
            const timeout = type === 'error' ? 8000 : 5000;
            setTimeout(() => {
                statusDiv.innerHTML = '';
            }, timeout);
        }

        // Enhanced error message for duplicate enrollment
        function showEnhancedErrorMessage(message) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.innerHTML = `
                <div class="status-message status-error" style="padding: 24px; border-radius: 12px; border: 1px solid #fecaca; background: #fef2f2;">
                    <div style="display: flex; align-items: flex-start; gap: 16px;">
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                <span style="font-size: 20px;">⚠️</span>
                                <strong style="font-size: 16px; color: #991b1b;">Duplicate Enrollment Detected</strong>
                            </div>
                            <div style="font-size: 14px; line-height: 1.6; margin-bottom: 16px; color: #7f1d1d;">
                                ${message}
                            </div>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <button type="button" onclick="clearForm()" 
                                        style="padding: 8px 16px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; font-size: 13px; color: #374151; font-weight: 500; transition: all 0.2s;">
                                    Clear Form
                                </button>
                                <button type="button" onclick="viewEnrollments()" 
                                        style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s;">
                                    View Enrollments
                                </button>
                                <button type="button" onclick="hideMessage()" 
                                        style="padding: 8px 16px; background: transparent; color: #6b7280; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500;">
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
                <div style="background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 24px; border-radius: 12px; margin-top: 16px;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <span style="font-size: 20px;">❓</span>
                        <strong style="font-size: 16px;">Confirm Student Selection</strong>
                    </div>
                    <div style="font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
                        <div style="background: #ffffff; padding: 16px; border-radius: 8px; border: 1px solid #fed7aa; margin-bottom: 16px;">
                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 8px;">Selected Student Details:</div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <div><strong>Name:</strong> ${studentFullName}</div>
                                <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                                <div><strong>Grade:</strong> ${student.grade_level_to_enroll || 'N/A'}</div>
                                <div><strong>DOB:</strong> ${student.birthdate || 'N/A'}</div>
                            </div>
                            <div style="margin-top: 8px;"><strong>Guardian:</strong> ${student.guardian_full_name || 'N/A'}</div>
                        </div>
                        <div style="color: #6b7280; font-size: 13px;">
                            Please confirm this is the correct student before proceeding with enrollment.
                        </div>
                    </div>
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" onclick="cancelStudentSelection()" 
                                style="padding: 10px 20px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s;">
                            Cancel
                        </button>
                        <button type="button" onclick="confirmStudentSelection(${JSON.stringify(student).replace(/"/g, '&quot;')})" 
                                style="padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2); transition: all 0.2s;">
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
                            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 20px; border-radius: 12px; margin-top: 16px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <span style="font-size: 18px;">⚠️</span>
                                    <strong style="font-size: 16px;">Student Already Enrolled</strong>
                                </div>
                                <div style="font-size: 14px; line-height: 1.6;">
                                    <div><strong>Name:</strong> ${studentFullName}</div>
                                    <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                                    <div style="margin-top: 12px; padding: 12px; background: #fee2e2; border-radius: 8px; color: #7f1d1d; border: 1px solid #fca5a5;">
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

                    // Update search result to show selected student
                    searchResultDiv.innerHTML = `
                        <div style="background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; padding: 20px; border-radius: 12px; margin-top: 16px;">
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                <span style="font-size: 18px;">✅</span>
                                <strong style="font-size: 16px;">Student Data Loaded Successfully</strong>
                            </div>
                            <div style="font-size: 14px; line-height: 1.6;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <div><strong>Name:</strong> ${studentFullName}</div>
                                    <div><strong>LRN:</strong> ${student.lrn || 'N/A'}</div>
                                    <div><strong>Grade:</strong> ${student.grade_level_to_enroll || 'N/A'}</div>
                                    <div><strong>DOB:</strong> ${student.birthdate || 'N/A'}</div>
                                </div>
                            </div>
                            <div style="margin-top: 12px; padding: 12px; background: #d1fae5; border-radius: 8px; font-size: 13px; color: #047857; border: 1px solid #a7f3d0; display: flex; align-items: center; gap: 8px;">
                                <span>✓</span> Search bar and form fields have been auto-filled with student data
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
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 16px; border-radius: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-size: 20px;">🔍</span>
                        <strong style="font-size: 16px;">Searching for student...</strong>
                    </div>
                    <div style="font-size: 14px; margin-top: 8px;">
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
                                <div style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 24px; border-radius: 12px; margin-top: 16px;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                                        <span style="font-size: 20px;">👥</span>
                                        <strong style="font-size: 16px;">Multiple Students Found (${data.count}):</strong>
                                    </div>
                                    <div style="font-size: 14px; margin-bottom: 16px;">
                                        Please select the correct student from the list below:
                                    </div>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #bfdbfe; border-radius: 8px; background: white;">
                            `;

                                data.students.forEach((student, index) => {
                                    const studentFullName = student.full_name;
                                    dropdownHTML += `
                                    <div class="student-option" data-student-index="${index}" style="padding: 12px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background-color 0.2s;" 
                                         onmouseover="this.style.backgroundColor='#f8fafc'" 
                                         onmouseout="this.style.backgroundColor='white'"
                                         onclick="selectStudent(${index}, ${JSON.stringify(student).replace(/"/g, '&quot;')})">
                                        <div style="font-weight: 600; color: #0f172a; margin-bottom: 4px;">${studentFullName}</div>
                                        <div style="font-size: 13px; color: #64748b;">
                                            <span style="display: inline-block; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-weight: 500;">LRN: ${student.lrn || 'N/A'}</span>
                                            <span style="margin: 0 4px;">•</span>
                                            <span>Grade: ${student.grade_level_to_enroll || 'N/A'}</span>
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
                                <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 16px; border-radius: 12px;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                        <span style="font-size: 20px;">✅</span>
                                        <strong style="font-size: 16px;">Student Found:</strong>
                                    </div>
                                    <div style="font-size: 14px; line-height: 1.6;">
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
                            <div style="background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; padding: 16px; border-radius: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <span style="font-size: 20px;">🔍</span>
                                    <strong style="font-size: 16px;">Student Not Found</strong>
                                </div>
                                <div style="font-size: 14px; margin-top: 8px;">
                                    No student found with the name "${studentName}". Please check the spelling or try a different search term.
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
                        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 16px; border-radius: 12px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span style="font-size: 20px;">❌</span>
                                <strong style="font-size: 16px;">Search Error</strong>
                            </div>
                            <div style="font-size: 14px; margin-top: 8px;">
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
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 16px; border-radius: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span style="font-size: 20px;">❌</span>
                            <strong style="font-size: 16px;">Search Error</strong>
                        </div>
                        <div style="font-size: 14px; margin-top: 8px;">
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

        // Custom Searchable Dropdown Controller
        (function() {
            const dropdownContainer = document.getElementById('section-dropdown');
            const sectionInput = document.getElementById('section');
            const resultsContainer = document.getElementById('section_results');
            const gradeSelect = document.getElementById('grade_level');
            
            // Master sections data from PHP
            const masterSections = <?php echo json_encode(array_map(function($sec) {
                return ['value' => $sec['section_name'], 'grade' => $sec['grade_level']];
            }, $all_assigned_sections)); ?>;

            function updateDropdown() {
                const selectedGrade = gradeSelect.value;
                const filterText = sectionInput.value.toLowerCase();
                
                resultsContainer.innerHTML = '';
                
                const filtered = masterSections.filter(sec => {
                    const matchesGrade = !selectedGrade || sec.grade === selectedGrade;
                    const matchesSearch = sec.value.toLowerCase().includes(filterText);
                    return matchesGrade && matchesSearch;
                });

                if (filtered.length > 0) {
                    filtered.forEach(sec => {
                        const div = document.createElement('div');
                        div.className = 'dropdown-item';
                        div.innerHTML = `<span>${sec.value}</span> <i style="font-size: 11px; color: #94a3b8; margin-left: auto;">${sec.grade}</i>`;
                        div.onclick = () => selectOption(sec.value);
                        resultsContainer.appendChild(div);
                    });
                } else {
                    const noResults = document.createElement('div');
                    noResults.className = 'dropdown-no-results';
                    noResults.textContent = filterText ? 'No matching sections found' : 'No sections available';
                    resultsContainer.appendChild(noResults);
                }
            }

            function selectOption(value) {
                sectionInput.value = value;
                closeDropdown();
                // Trigger change event if needed
                sectionInput.dispatchEvent(new Event('change'));
            }

            function openDropdown() {
                updateDropdown();
                resultsContainer.classList.add('is-active');
                dropdownContainer.classList.add('is-open');
            }

            function closeDropdown() {
                resultsContainer.classList.remove('is-active');
                dropdownContainer.classList.remove('is-open');
            }

            // Events
            sectionInput.addEventListener('focus', openDropdown);
            sectionInput.addEventListener('input', () => {
                if (!resultsContainer.classList.contains('is-active')) openDropdown();
                updateDropdown();
            });

            // Toggle on arrow click (container click)
            dropdownContainer.addEventListener('click', (e) => {
                if (e.target.closest('.dropdown-arrow')) {
                    if (resultsContainer.classList.contains('is-active')) {
                        closeDropdown();
                    } else {
                        sectionInput.focus();
                        openDropdown();
                    }
                }
            });

            // Re-filter when grade level changes
            gradeSelect.addEventListener('change', () => {
                if (resultsContainer.classList.contains('is-active')) {
                    updateDropdown();
                }
            });

            // Close when clicking outside
            document.addEventListener('click', (e) => {
                if (!dropdownContainer.contains(e.target)) {
                    closeDropdown();
                }
            });
        })();
    </script>
</body>

</html>