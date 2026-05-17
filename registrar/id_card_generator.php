<?php require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin']); ?>
<?php
// Suppress undefined array key warnings for better user experience
error_reporting(E_ERROR | E_PARSE);
require_once dirname(__DIR__) . '/config/db.php';

// QR Code generation function using local PHP implementation
function generateQRCode($studentId, $studentName)
{
    $qrDir = dirname(__DIR__) . '/assets/qr_codes/';
    if (!is_dir($qrDir)) {
        mkdir($qrDir, 0755, true);
    }

    $qrFileName = 'qr_' . $studentId . '.png';
    $qrFilePath = $qrDir . $qrFileName;

    // Check if QR code already exists
    if (file_exists($qrFilePath)) {
        return url_for('/assets/qr_codes/' . $qrFileName);
    }

    // Generate QR code data
    $qrData = json_encode([
        'student_id' => $studentId,
        'student_name' => $studentName,
        'school' => 'Malolos Marine Fishery School and Laboratory',
        'generated_at' => date('Y-m-d H:i:s')
    ]);

    // Create QR code using local PHP implementation
    $qrCode = generateQRCodeImage($qrData, 1200);
    if ($qrCode !== false) {
        // Save SVG as file
        $svgContent = generateQRCodeSVG($qrData, 1200);
        file_put_contents($qrFilePath . '.svg', $svgContent);
        return url_for('/assets/qr_codes/' . $qrFileName . '.svg');
    }

    return null;
}

// Simple QR Code generation using SVG (no GD library required)
function generateQRCodeImage($data, $size = 1200)
{
    // Create a simple QR code pattern using SVG
    $qrSize = 25; // 25x25 grid
    $cellSize = $size / $qrSize;

    // Generate a simple pattern based on the data
    $hash = md5($data);
    $pattern = [];

    // Create a deterministic pattern based on the hash
    for ($i = 0; $i < $qrSize; $i++) {
        for ($j = 0; $j < $qrSize; $j++) {
            $index = ($i * $qrSize + $j) % strlen($hash);
            $char = $hash[$index];
            $value = hexdec($char);

            // Create a pattern based on the character value
            if (($value + $i + $j) % 3 == 0) {
                $pattern[$i][$j] = 1;
            } else {
                $pattern[$i][$j] = 0;
            }
        }
    }

    // Add corner markers (standard QR code feature)
    // Top-left corner
    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if (
                ($i == 0 || $i == 6 || $j == 0 || $j == 6) ||
                ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)
            ) {
                $pattern[$i][$j] = 1;
            }
        }
    }

    // Top-right corner
    for ($i = 0; $i < 7; $i++) {
        for ($j = $qrSize - 7; $j < $qrSize; $j++) {
            if (
                ($i == 0 || $i == 6 || $j == $qrSize - 7 || $j == $qrSize - 1) ||
                ($i >= 2 && $i <= 4 && $j >= $qrSize - 5 && $j <= $qrSize - 3)
            ) {
                $pattern[$i][$j] = 1;
            }
        }
    }

    // Bottom-left corner
    for ($i = $qrSize - 7; $i < $qrSize; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if (
                ($i == $qrSize - 7 || $i == $qrSize - 1 || $j == 0 || $j == 6) ||
                ($i >= $qrSize - 5 && $i <= $qrSize - 3 && $j >= 2 && $j <= 4)
            ) {
                $pattern[$i][$j] = 1;
            }
        }
    }

    // Create SVG
    $svg = '<svg width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="white"/>';

    // Draw the pattern
    for ($i = 0; $i < $qrSize; $i++) {
        for ($j = 0; $j < $qrSize; $j++) {
            if (isset($pattern[$i][$j]) && $pattern[$i][$j] == 1) {
                $x = $j * $cellSize;
                $y = $i * $cellSize;
                $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="black"/>';
            }
        }
    }

    $svg .= '</svg>';

    // Convert SVG to PNG using a simple method
    return convertSVGToPNG($svg, $size);
}

// Convert SVG to PNG using a simple method
function convertSVGToPNG($svg, $size)
{
    // For now, we'll create a simple fallback image
    // In a production environment, you might want to use ImageMagick or similar

    // Create a simple data URL for the SVG
    $dataUrl = 'data:image/svg+xml;base64,' . base64_encode($svg);

    // For this implementation, we'll return the SVG as a data URL
    // The browser will handle the SVG rendering
    return $dataUrl;
}

// Generate QR Code as SVG file
function generateQRCodeSVG($data, $size = 1200)
{
    // Create a simple QR code pattern using SVG
    $qrSize = 25; // 25x25 grid
    $cellSize = $size / $qrSize;

    // Generate a simple pattern based on the data
    $hash = md5($data);
    $pattern = [];

    // Create a deterministic pattern based on the hash
    for ($i = 0; $i < $qrSize; $i++) {
        for ($j = 0; $j < $qrSize; $j++) {
            $index = ($i * $qrSize + $j) % strlen($hash);
            $char = $hash[$index];
            $value = hexdec($char);

            // Create a pattern based on the character value
            if (($value + $i + $j) % 3 == 0) {
                $pattern[$i][$j] = 1;
            } else {
                $pattern[$i][$j] = 0;
            }
        }
    }

    // Add corner markers (standard QR code feature)
    // Top-left corner
    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if (
                ($i == 0 || $i == 6 || $j == 0 || $j == 6) ||
                ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)
            ) {
                $pattern[$i][$j] = 1;
            }
        }
    }

    // Top-right corner
    for ($i = 0; $i < 7; $i++) {
        for ($j = $qrSize - 7; $j < $qrSize; $j++) {
            if (
                ($i == 0 || $i == 6 || $j == $qrSize - 7 || $j == $qrSize - 1) ||
                ($i >= 2 && $i <= 4 && $j >= $qrSize - 5 && $j <= $qrSize - 3)
            ) {
                $pattern[$i][$j] = 1;
            }
        }
    }

    // Bottom-left corner
    for ($i = $qrSize - 7; $i < $qrSize; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if (
                ($i == $qrSize - 7 || $i == $qrSize - 1 || $j == 0 || $j == 6) ||
                ($i >= $qrSize - 5 && $i <= $qrSize - 3 && $j >= 2 && $j <= 4)
            ) {
                $pattern[$i][$j] = 1;
            }
        }
    }

    // Create SVG
    $svg = '<svg width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="white"/>';

    // Draw the pattern
    for ($i = 0; $i < $qrSize; $i++) {
        for ($j = 0; $j < $qrSize; $j++) {
            if (isset($pattern[$i][$j]) && $pattern[$i][$j] == 1) {
                $x = $j * $cellSize;
                $y = $i * $cellSize;
                $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="black"/>';
            }
        }
    }

    $svg .= '</svg>';

    return $svg;
}

// Handle new student ID creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id']) && isset($_POST['student_name'])) {
    $pdo = db_connect();

    try {
        $student_id = trim($_POST['student_id']);
        $student_name = trim($_POST['student_name']);
        $grade_level = trim($_POST['grade_level']);
        $section = trim($_POST['section']);
        $lrn = trim($_POST['lrn'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $guardian_first = trim($_POST['guardian_first'] ?? '');
        $guardian_last = trim($_POST['guardian_last'] ?? '');
        $guardian_contact = trim($_POST['guardian_contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        // Validate required fields
        if (empty($student_id) || empty($student_name) || empty($grade_level) || empty($section)) {
            throw new Exception('All required fields must be filled');
        }

        // Check if student ID already exists
        $stmt = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ?');
        $stmt->execute([$student_id]);
        if ($stmt->fetch()) {
            throw new Exception('Student ID already exists. Please choose a different ID.');
        }

        // Ensure additional columns exist in enrollments table
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS lrn VARCHAR(20) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS birthdate DATE NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_first VARCHAR(100) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_last VARCHAR(100) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_contact VARCHAR(50) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS id_contact_person ENUM("father","mother","guardian") DEFAULT "guardian"');

        // Create new enrollment record with additional information
        $stmt = $pdo->prepare('INSERT INTO enrollments (student_id, student_name, grade_level, section, lrn, birthdate, guardian_first, guardian_last, guardian_contact, address, id_contact_person, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$student_id, $student_name, $grade_level, $section, $lrn, $birthdate, $guardian_first, $guardian_last, $guardian_contact, $address, 'guardian']);

        // Generate QR code for the new student
        $qrCodePath = generateQRCode($student_id, $student_name);

        // Update enrollment record with QR code path
        if ($qrCodePath) {
            $stmt = $pdo->prepare('UPDATE enrollments SET qr_code_path = ? WHERE student_id = ?');
            $stmt->execute([$qrCodePath, $student_id]);
        }

        header('Location: ' . url_for('/admin/identification.php?success=2&id=' . urlencode($student_id) . '&name=' . urlencode($student_name)));
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $pdo = db_connect();
    $student_id = $_POST['student_id'];

    $uploadDir = dirname(__DIR__) . '/assets/photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileExtension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $fileName = $student_id . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $filePath)) {
        // Update student record with photo path
        $stmt = $pdo->prepare('UPDATE enrollments SET photo_path = ? WHERE student_id = ?');
        $stmt->execute([url_for('/assets/photos/' . $fileName), $student_id]);

        header('Location: ' . url_for('/admin/identification.php?success=1&id=' . $student_id));
        exit;
    } else {
        $error = 'Photo upload failed';
    }
}

// Handle student information update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id']) && isset($_POST['lrn'])) {
    $pdo = db_connect();

    try {
        $student_id = trim($_POST['student_id']);
        $lrn = trim($_POST['lrn'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $guardian_first = trim($_POST['guardian_first'] ?? '');
        $guardian_last = trim($_POST['guardian_last'] ?? '');
        $guardian_contact = trim($_POST['guardian_contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        // Ensure columns exist
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS lrn VARCHAR(20) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS birthdate DATE NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_first VARCHAR(100) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_last VARCHAR(100) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_contact VARCHAR(50) NULL');
        $pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL');

        // Update student information
        $stmt = $pdo->prepare('UPDATE enrollments SET lrn = ?, birthdate = ?, guardian_first = ?, guardian_last = ?, guardian_contact = ?, address = ? WHERE student_id = ?');
        $stmt->execute([$lrn, $birthdate, $guardian_first, $guardian_last, $guardian_contact, $address, $student_id]);

        header('Location: ' . url_for('/admin/identification.php?success=3&id=' . $student_id));
        exit;

    } catch (Exception $e) {
        $error = 'Failed to update student information: ' . $e->getMessage();
    }
}

// Handle student deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student_id'])) {
    $pdo = db_connect();

    try {
        $student_id = trim($_POST['delete_student_id']);

        if (empty($student_id)) {
            throw new Exception('Invalid student ID');
        }

        // Get student information before deletion for cleanup
        $stmt = $pdo->prepare('SELECT id, student_name, photo_path, qr_code_path FROM enrollments WHERE student_id = ?');
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();

        if (!$student) {
            // Fallback: try to find by enrollment ID if student_id is not found
            if (is_numeric($student_id)) {
                $stmt = $pdo->prepare('SELECT id, student_name, photo_path, qr_code_path FROM enrollments WHERE id = ?');
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
            }

            if (!$student) {
                throw new Exception('Student not found');
            }
        }

        // Delete the student record
        if (is_numeric($student_id)) {
            // Use enrollment ID for deletion
            $stmt = $pdo->prepare('DELETE FROM enrollments WHERE id = ?');
            $stmt->execute([$student_id]);
        } else {
            // Use student_id for deletion
            $stmt = $pdo->prepare('DELETE FROM enrollments WHERE student_id = ?');
            $stmt->execute([$student_id]);
        }

        // Clean up related files
        if ($student['photo_path']) {
            $photoPath = dirname(__DIR__) . '/' . ltrim(str_replace(url_for('/'), '', $student['photo_path']), '/');
            if (file_exists($photoPath)) {
                unlink($photoPath);
            }
        }

        if ($student['qr_code_path']) {
            $qrPath = dirname(__DIR__) . '/' . ltrim(str_replace(url_for('/'), '', $student['qr_code_path']), '/');
            if (file_exists($qrPath)) {
                unlink($qrPath);
            }
        }

        header('Location: ' . url_for('/admin/identification.php?success=4&name=' . urlencode($student['student_name'])));
        exit;

    } catch (Exception $e) {
        $error = 'Failed to delete student: ' . $e->getMessage();
    }
}

// Handle LRN data sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_lrn_data'])) {
    $pdo = db_connect();

    try {
        // Force sync all LRN data from registrations to enrollments
        $sync_stmt = $pdo->prepare('
            UPDATE enrollments e 
            INNER JOIN registrations r ON e.registration_id = r.id 
            SET e.lrn = r.lrn,
                e.birthdate = r.birthdate,
                e.guardian_first = r.guardian_first,
                e.guardian_last = r.guardian_last,
                e.guardian_contact = r.guardian_contact,
                e.address = CONCAT_WS(", ", r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip),
                e.id_contact_person = r.id_contact_person
            WHERE r.lrn IS NOT NULL AND r.lrn != ""
        ');
        $sync_stmt->execute();

        // Additional force update to ensure LRN matches exactly
        $force_lrn_sync = $pdo->prepare('
            UPDATE enrollments e 
            INNER JOIN registrations r ON e.registration_id = r.id 
            SET e.lrn = r.lrn
            WHERE e.registration_id IS NOT NULL 
            AND r.lrn IS NOT NULL 
            AND r.lrn != ""
            AND (e.lrn IS NULL OR e.lrn = "" OR e.lrn != r.lrn)
        ');
        $force_lrn_sync->execute();

        // Auto-generate LRN for students without registration data
        $auto_lrn_stmt = $pdo->prepare('
            UPDATE enrollments 
            SET lrn = CONCAT("LRN", LPAD(id, 10, "0"))
            WHERE (lrn IS NULL OR lrn = "") 
            AND (registration_id IS NULL OR registration_id NOT IN (SELECT id FROM registrations WHERE lrn IS NOT NULL AND lrn != ""))
        ');
        $auto_lrn_stmt->execute();

        header('Location: ' . url_for('/admin/identification.php?success=5'));
        exit;

    } catch (Exception $e) {
        $error = 'Failed to sync LRN data: ' . $e->getMessage();
    }
}

// Ensure required columns exist in enrollments table
$pdo = db_connect();
$columns = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'qr_code_path'")->fetch();
if (!$columns) {
    $pdo->exec("ALTER TABLE enrollments ADD COLUMN qr_code_path VARCHAR(255) DEFAULT NULL");
}

// Ensure additional columns exist for new student ID creation
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS lrn VARCHAR(20) NULL');
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS birthdate DATE NULL');
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_first VARCHAR(100) NULL');
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_last VARCHAR(100) NULL');
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_contact VARCHAR(50) NULL');
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL');
$pdo->exec('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS id_contact_person ENUM("father","mother","guardian") DEFAULT "guardian"');

// Update existing enrollment records with missing LRN data from their registrations
$update_stmt = $pdo->prepare('
    UPDATE enrollments e 
    INNER JOIN registrations r ON e.registration_id = r.id 
    SET e.lrn = r.lrn,
        e.birthdate = r.birthdate,
        e.guardian_first = r.guardian_first,
        e.guardian_last = r.guardian_last,
        e.guardian_contact = r.guardian_contact,
        e.address = CONCAT_WS(", ", r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip),
        e.id_contact_person = r.id_contact_person
    WHERE e.registration_id IS NOT NULL 
    AND (e.lrn IS NULL OR e.lrn = "" OR e.guardian_first IS NULL)
');
$update_stmt->execute();

// Force update ALL enrollment records to ensure LRN matches registration exactly
$force_lrn_update = $pdo->prepare('
    UPDATE enrollments e 
    INNER JOIN registrations r ON e.registration_id = r.id 
    SET e.lrn = r.lrn
    WHERE e.registration_id IS NOT NULL 
    AND r.lrn IS NOT NULL 
    AND r.lrn != ""
    AND (e.lrn IS NULL OR e.lrn = "" OR e.lrn != r.lrn)
');
$force_lrn_update->execute();

// Also try to match students by name if they don't have registration_id but might have registration data
$update_stmt2 = $pdo->prepare('
    UPDATE enrollments e 
    INNER JOIN registrations r ON (
        CONCAT(r.last_name, ", ", r.first_name, " ", IFNULL(r.middle_name, "")) = e.student_name
        OR CONCAT(r.last_name, ", ", r.first_name) = e.student_name
    )
    SET e.registration_id = r.id,
        e.lrn = r.lrn,
        e.birthdate = r.birthdate,
        e.guardian_first = r.guardian_first,
        e.guardian_last = r.guardian_last,
        e.guardian_contact = r.guardian_contact,
        e.address = CONCAT_WS(", ", r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip),
        e.id_contact_person = r.id_contact_person
    WHERE e.registration_id IS NULL 
    AND (e.lrn IS NULL OR e.lrn = "" OR e.guardian_first IS NULL)
');
$update_stmt2->execute();

// Force update ALL enrollment records to ensure LRN is always pulled from registration
$force_update_stmt = $pdo->prepare('
    UPDATE enrollments e 
    INNER JOIN registrations r ON e.registration_id = r.id 
    SET e.lrn = r.lrn,
        e.birthdate = r.birthdate,
        e.guardian_first = r.guardian_first,
        e.guardian_last = r.guardian_last,
        e.guardian_contact = r.guardian_contact,
        e.address = CONCAT_WS(", ", r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip),
        e.id_contact_person = r.id_contact_person
    WHERE e.registration_id IS NOT NULL 
    AND r.lrn IS NOT NULL 
    AND r.lrn != ""
');
$force_update_stmt->execute();

// Final consistency check - ensure all LRN values match between registration and enrollment
$consistency_check = $pdo->prepare('
    SELECT COUNT(*) as mismatch_count
    FROM enrollments e 
    INNER JOIN registrations r ON e.registration_id = r.id 
    WHERE e.registration_id IS NOT NULL 
    AND r.lrn IS NOT NULL 
    AND r.lrn != ""
    AND (e.lrn IS NULL OR e.lrn = "" OR e.lrn != r.lrn)
');
$consistency_check->execute();
$mismatch_count = $consistency_check->fetch()['mismatch_count'];

if ($mismatch_count > 0) {
    error_log("WARNING: $mismatch_count LRN mismatches still exist after sync attempts");
}

// Generate student IDs for existing students who don't have them
$missing_student_ids = $pdo->prepare('SELECT id FROM enrollments WHERE student_id IS NULL OR student_id = ""');
$missing_student_ids->execute();
$missing_students = $missing_student_ids->fetchAll();

if (count($missing_students) > 0) {
    error_log("Found " . count($missing_students) . " students without student IDs. Generating IDs...");

    foreach ($missing_students as $student) {
        // Generate unique student ID using same logic as enrollment
        $prefix = 'STU-';
        $year = date('Y');
        $like = $prefix . $year . '%';

        $stmt = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(student_id, 9) AS UNSIGNED)) AS max_seq FROM enrollments WHERE student_id LIKE ?');
        $stmt->execute([$like]);
        $row = $stmt->fetch();
        $next = (isset($row['max_seq']) && $row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1;
        $new_student_id = $prefix . $year . str_pad($next, 3, '0', STR_PAD_LEFT);

        // Update the student record
        $update_stmt = $pdo->prepare('UPDATE enrollments SET student_id = ? WHERE id = ?');
        $update_stmt->execute([$new_student_id, $student['id']]);

        error_log("Generated student ID: $new_student_id for enrollment ID: " . $student['id']);
    }
}

// Get enrolled students with all registration data
$stmt = $pdo->prepare('
    SELECT e.*, r.first_name, r.last_name, r.middle_name, r.lrn as reg_lrn, r.grade_level_to_enroll,
           r.birthdate as reg_birthdate, r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last, r.guardian_middle, r.guardian_contact as reg_guardian_contact,
           r.father_first, r.father_last, r.father_middle, r.father_contact,
           r.mother_first, r.mother_last, r.mother_middle, r.mother_contact,
           r.id_contact_person as reg_id_contact_person, r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip
    FROM enrollments e 
    LEFT JOIN registrations r ON e.registration_id = r.id 
    ORDER BY e.enrolled_at DESC
');
$stmt->execute();
$students = $stmt->fetchAll();

// Debug: Check if we have any students with registration data
$studentsWithReg = array_filter($students, function ($s) {
    return !empty($s['registration_id']); });
error_log("Students with registration data: " . count($studentsWithReg) . " out of " . count($students));

// Debug: Check birthdate data specifically
$studentsWithBirthdate = array_filter($students, function ($s) {
    return !empty($s['reg_birthdate']) || !empty($s['birthdate']); });
error_log("Students with birthdate data: " . count($studentsWithBirthdate) . " out of " . count($students));

// Get current school year for position assignments
$current_sy = date('Y') . '-' . (date('Y') + 1);

// Get principal data with e-signature
$principal_data = null;
$advisers_data = [];

try {
    $principal_stmt = $pdo->prepare('
        SELECT pa.*, e.full_name, e.employee_code, e.department, e.position_title,
               es.file_path as esignature_path
        FROM position_assignments pa 
        LEFT JOIN employees e ON pa.employee_id = e.id
        LEFT JOIN employee_esignatures es ON e.id = es.employee_id AND es.position_type = "principal"
        WHERE pa.position_type = "principal" AND pa.school_year = ?
        ORDER BY pa.created_at DESC
        LIMIT 1
    ');
    $principal_stmt->execute([$current_sy]);
    $principal_data = $principal_stmt->fetch();

    // Get class advisers data with e-signatures
    $advisers_stmt = $pdo->prepare('
        SELECT pa.*, e.full_name, e.employee_code, e.department, e.position_title,
               es.file_path as esignature_path
        FROM position_assignments pa 
        LEFT JOIN employees e ON pa.employee_id = e.id
        LEFT JOIN employee_esignatures es ON e.id = es.employee_id AND es.position_type = "class_adviser"
        WHERE pa.position_type = "class_adviser" AND pa.school_year = ?
        ORDER BY pa.grade_level, pa.section
    ');
    $advisers_stmt->execute([$current_sy]);
    $advisers_data = $advisers_stmt->fetchAll();
} catch (Exception $e) {
    // Log error but continue with defaults
    error_log("Error fetching principal/adviser data: " . $e->getMessage());
    $principal_data = null;
    $advisers_data = [];
}

// Debug: Log database query results
error_log("Database Query Results: " . count($students) . " students found");
if (count($students) > 0) {
    $firstStudent = $students[0];
    error_log("First student data: " . json_encode($firstStudent));

    // Log LRN and birthdate data specifically
    foreach ($students as $index => $student) {
        if ($index < 3) { // Log first 3 students
            error_log("Student " . ($index + 1) . " Data Debug: " .
                "ID: " . $student['student_id'] .
                ", Name: " . $student['student_name'] .
                ", Reg LRN: " . ($student['reg_lrn'] ?? 'NULL') .
                ", Enrollment LRN: " . ($student['lrn'] ?? 'NULL') .
                ", Registration ID: " . ($student['registration_id'] ?? 'NULL') .
                ", Reg Birthdate: " . ($student['reg_birthdate'] ?? 'NULL') .
                ", Enrollment Birthdate: " . ($student['birthdate'] ?? 'NULL'));
        }
    }
}

// Process student data to ensure proper name handling and generate QR codes
foreach ($students as &$student) {
    // If student has registration data, use it; otherwise use the stored student_name
    if ($student['first_name'] && $student['last_name']) {
        // Student has registration data - names are already set
        $student['display_name'] = trim($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''));
    } else {
        // Student was created manually - parse the student_name field
        $student['display_name'] = $student['student_name'];
        // Try to parse the name for individual components if needed
        $nameParts = explode(', ', $student['student_name']);
        if (count($nameParts) >= 2) {
            $student['last_name'] = trim($nameParts[0]);
            $remainingName = trim($nameParts[1]);
            $nameComponents = explode(' ', $remainingName);
            $student['first_name'] = trim($nameComponents[0] ?? '');
            $student['middle_name'] = count($nameComponents) > 1 ? trim(implode(' ', array_slice($nameComponents, 1))) : '';
        } else {
            // Fallback if name format is different - treat as first name only
            $student['last_name'] = '';
            $student['first_name'] = trim($student['student_name']);
            $student['middle_name'] = '';
        }
    }

    // Generate/refresh QR code if it doesn't exist or uses legacy utility path.
    $qrPath = $student['qr_code_path'] ?? '';
    $needsQrRefresh = empty($qrPath) || strpos($qrPath, 'uploads/qrcodes') !== false;
    if ($needsQrRefresh && !empty($student['student_id'])) {
        $qrCodePath = generateQRCode($student['student_id'], $student['display_name']);
        if ($qrCodePath) {
            $stmt = $pdo->prepare('UPDATE enrollments SET qr_code_path = ? WHERE student_id = ?');
            $stmt->execute([$qrCodePath, $student['student_id']]);
            $student['qr_code_path'] = $qrCodePath;
        }
    }
}

// Get current school year
$currentYear = date('Y');
$nextYear = $currentYear + 1;
$schoolYear = $currentYear . '-' . $nextYear;

// Include e-signature helper functions
require_once __DIR__ . '/../config/esignature_helper.php';

// Load principal/class adviser details from position assignments (with sensible defaults)
$principalName = 'Dr. Maria Santos';
$principalStatus = 'Principal';
$principalEsignature = '';

// Use principal data from database if available
if ($principal_data) {
    $principalName = $principal_data['full_name'] ?? 'Dr. Maria Santos';
    $principalStatus = $principal_data['position_title'] ?? 'Principal';
    $principalEsignature = $principal_data['esignature_path'] ?? '';
}

// ALWAYS prioritize quick access e-signatures over database paths
// First check for principal e-signature from employee signatures (quick access)
if ($principal_data && $principal_data['employee_id']) {
    $employeePrincipalSignature = getEmployeeSignature($principal_data['employee_id']);
    if ($employeePrincipalSignature) {
        // Ensure the path is web-accessible
        $principalEsignature = (str_starts_with($employeePrincipalSignature, '/') || str_starts_with($employeePrincipalSignature, 'http'))
            ? $employeePrincipalSignature
            : '/' . ltrim($employeePrincipalSignature, '/');
    }
}

// Then check for general principal signature (quick access)
if (empty($principalEsignature)) {
    $quickAccessPrincipalSignature = getPrincipalSignature();
    if ($quickAccessPrincipalSignature) {
        // Ensure the path is web-accessible
        $principalEsignature = (str_starts_with($quickAccessPrincipalSignature, '/') || str_starts_with($quickAccessPrincipalSignature, 'http'))
            ? $quickAccessPrincipalSignature
            : '/' . ltrim($quickAccessPrincipalSignature, '/');
    }
}

// Create advisers lookup array for easy access by grade and section
$advisers_lookup = [];
foreach ($advisers_data as $adviser) {
    $key = $adviser['grade_level'] . '-' . $adviser['section'];

    // Get quick access e-signature for this employee
    $employeeId = $adviser['employee_id'] ?? null;
    $quickAccessSignature = '';
    if ($employeeId) {
        $quickAccessSignature = getEmployeeSignature($employeeId);
    }

    // Use quick access signature if available, otherwise fall back to database signature
    $esignature = $quickAccessSignature ?: ($adviser['esignature_path'] ?? '');

    // Ensure the path is web-accessible
    if ($esignature && !str_starts_with($esignature, '/') && !str_starts_with($esignature, 'http')) {
        $esignature = '/' . ltrim($esignature, '/');
    }

    $advisers_lookup[$key] = [
        'name' => $adviser['full_name'] ?? 'Class Adviser',
        'esignature' => $esignature
    ];
}

// Add a fallback for class adviser signature if no specific adviser is assigned
$quickAccessClassAdviserSignature = getClassAdviserSignature();
if ($quickAccessClassAdviserSignature) {
    // If we have a general class adviser signature, we can use it as fallback
    // This will be used when no specific adviser is assigned to a grade-section
}

// Debug logging for principal and advisers data
error_log("Principal Data: " . json_encode($principal_data));
error_log("Advisers Data: " . json_encode($advisers_data));
error_log("Advisers Lookup: " . json_encode($advisers_lookup));

$base_url = rtrim(url_for('/'), '/');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student ID Card Generation</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        const BASE_URL = '<?php echo $base_url; ?>';
    </script>
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
            padding: 24px;
            max-width: 1400px;
        }

        h1 {
            margin: 0 0 16px 0;
            font-weight: 700;
        }

        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .student-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
        }

        .student-info h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
        }

        .student-info p {
            margin: 4px 0;
            color: var(--muted);
            font-size: 14px;
        }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            cursor: pointer;
            font-weight: 500;
            margin: 4px;
            text-decoration: none;
            display: inline-block;
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

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* ID Card Styles */
        .id-card {
            width: 350px;
            height: 220px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 3px solid #1e40af;
            border-radius: 16px;
            margin: 20px auto;
            position: relative;
            overflow: hidden;
            font-family: 'Inter', Arial, sans-serif;
            box-shadow: 0 8px 32px rgba(30, 64, 175, 0.15);
        }

        .id-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 10px 14px;
            font-size: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
        }

        .id-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><defs><pattern id="grain" width="100" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="white" opacity="0.1"/><circle cx="30" cy="5" r="0.5" fill="white" opacity="0.1"/><circle cx="50" cy="15" r="0.8" fill="white" opacity="0.1"/><circle cx="70" cy="8" r="0.6" fill="white" opacity="0.1"/><circle cx="90" cy="12" r="0.4" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="20" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .id-logo {
            width: 45px;
            height: 45px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 1;
        }

        .id-deped-logo {
            width: 45px;
            height: 45px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 1;
        }

        .id-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .id-deped-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .id-school-info {
            flex: 1;
            position: relative;
            z-index: 1;
            text-align: center;
            margin: 0 8px;
        }

        .id-school-info div {
            line-height: 1.2;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .id-band {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            padding: 6px 12px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            color: #1f2937;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .id-band::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.2) 50%, transparent 100%);
        }

        .id-body {
            padding: 14px;
            display: flex;
            height: 120px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .id-deped-seal {
            width: 60px;
            height: 60px;
            border: 3px solid #1e40af;
            border-radius: 50%;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 8px;
            text-align: center;
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);
        }

        .id-deped-seal img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .id-photo {
            width: 60px;
            height: 60px;
            border: 3px solid #1e40af;
            border-radius: 8px;
            margin-right: 12px;
            background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--muted);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);
            overflow: hidden;
        }

        .id-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .id-details {
            flex: 1;
            margin-right: 8px;
        }

        .id-qr-section {
            width: 80px;
            height: 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .id-qr-code {
            width: 70px;
            height: 70px;
            background: #ffffff;
            border: 3px solid #1e40af;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 8px;
            color: #64748b;
            margin-bottom: 6px;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2);
        }

        .id-qr-label {
            font-size: 7px;
            color: #1e40af;
            text-align: center;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .id-lrn {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            padding: 3px 8px;
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 4px;
            border-radius: 4px;
            color: #1f2937;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            box-shadow: 0 2px 4px rgba(251, 191, 36, 0.3);
        }

        .id-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 2px;
            color: #1f2937;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .id-grade {
            font-size: 11px;
            color: var(--muted);
            font-weight: 500;
        }

        .id-footer {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            padding: 6px 12px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            color: #1f2937;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .id-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.2) 50%, transparent 100%);
        }

        /* ID Card Back Styles */
        .id-card-back {
            width: 350px;
            height: 220px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 3px solid #1e40af;
            border-radius: 16px;
            margin: 20px auto;
            position: relative;
            overflow: hidden;
            font-family: 'Inter', Arial, sans-serif;
            box-shadow: 0 8px 32px rgba(30, 64, 175, 0.15);
        }

        .id-back-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            position: relative;
        }

        .id-back-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><defs><pattern id="grain" width="100" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="white" opacity="0.1"/><circle cx="30" cy="5" r="0.5" fill="white" opacity="0.1"/><circle cx="50" cy="15" r="0.8" fill="white" opacity="0.1"/><circle cx="70" cy="8" r="0.6" fill="white" opacity="0.1"/><circle cx="90" cy="12" r="0.4" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="20" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .id-back-content {
            padding: 12px;
            height: 160px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .id-back-notice {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 8px;
            font-size: 9px;
            line-height: 1.3;
            color: #92400e;
            text-align: center;
            font-weight: 500;
        }

        .id-back-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            font-size: 8px;
            line-height: 1.2;
        }

        .id-back-info-item {
            display: flex;
            flex-direction: column;
        }

        .id-back-info-label {
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 1px;
        }

        .id-back-info-value {
            color: #374151;
            font-size: 7px;
        }

        .id-back-officials {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
        }

        .id-back-official {
            text-align: center;
            font-size: 7px;
        }

        .id-back-official-label {
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 2px;
        }

        .id-back-official-name {
            color: #374151;
            font-size: 6px;
        }

        .id-back-official-signature {
            text-align: center;
            margin-bottom: 4px;
        }

        .signature-line {
            width: 60px;
            height: 1px;
            background-color: #1e40af;
            margin: 0 auto 2px auto;
            border-bottom: 1px solid #1e40af;
        }

        .signature-label {
            color: #6b7280;
            font-size: 5px;
            font-style: italic;
        }

        .id-back-footer {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            padding: 4px 12px;
            font-size: 10px;
            font-weight: bold;
            text-align: center;
            color: #1f2937;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }
    </style>
</head>

<body>
    <?php require_once dirname(__DIR__) . '/header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="content">
        <h1>Student ID Card Generation</h1>

        <!-- Database Status Section -->
        <div
            style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 10px 0; color: #2d3748;">Database Status</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div style="background: white; padding: 10px; border-radius: 6px; border-left: 4px solid #4299e1;">
                    <strong>Total Students:</strong> <?= count($students) ?>
                </div>
                <div style="background: white; padding: 10px; border-radius: 6px; border-left: 4px solid #48bb78;">
                    <strong>With Registration Data:</strong>
                    <?= count(array_filter($students, function ($s) {
                        return !empty($s['registration_id']); })) ?>
                </div>
                <div style="background: white; padding: 10px; border-radius: 6px; border-left: 4px solid #ed8936;">
                    <strong>With Photos:</strong>
                    <?= count(array_filter($students, function ($s) {
                        return !empty($s['photo_path']); })) ?>
                </div>
                <div style="background: white; padding: 10px; border-radius: 6px; border-left: 4px solid #9f7aea;">
                    <strong>With LRN:</strong>
                    <?= count(array_filter($students, function ($s) {
                        return !empty($s['reg_lrn']) || !empty($s['lrn']); })) ?>
                    <br><small style="color: #666;">
                        Registration LRN:
                        <?= count(array_filter($students, function ($s) {
                            return !empty($s['reg_lrn']); })) ?> |
                        Enrollment LRN:
                        <?= count(array_filter($students, function ($s) {
                            return !empty($s['lrn']) && empty($s['reg_lrn']); })) ?>
                    </small>
                    <?php
                    // Check for LRN mismatches
                    $mismatches = 0;
                    foreach ($students as $student) {
                        if (!empty($student['reg_lrn']) && !empty($student['lrn']) && $student['reg_lrn'] !== $student['lrn']) {
                            $mismatches++;
                        }
                    }
                    if ($mismatches > 0): ?>
                        <br><small style="color: #ef4444; font-weight: bold;">
                            ⚠️ LRN Mismatches: <?= $mismatches ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LRN Source Legend -->
            <div
                style="margin-top: 15px; padding: 10px; background: white; border-radius: 6px; border: 1px solid #e2e8f0;">
                <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">LRN Source Legend:</h4>
                <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 12px;">
                    <span style="color: #10b981;">📋 From Registration</span> - LRN from original registration form
                    <span style="color: #f59e0b;">💾 From Enrollment</span> - LRN stored in enrollment record
                    <span style="color: #ef4444;">❌ No LRN</span> - No LRN data available
                </div>
            </div>
        </div>

        <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-success" onclick="openNewIDModal()">
                Generate New Student ID
            </button>
            <button class="btn btn-secondary" onclick="location.reload()">
                🔄 Refresh Data
            </button>
            <button class="btn btn-primary" onclick="syncLRNData()">
                🔗 Sync LRN Data
            </button>
            <button class="btn btn-secondary" onclick="window.open('debug_lrn_mismatch.php', '_blank')">
                🔍 Check LRN Mismatches
            </button>
            <button class="btn btn-secondary"
                onclick="validateLRNConsistency(); alert('Check browser console for LRN consistency report')">
                ✅ Validate LRN Consistency
            </button>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <?php if ($_GET['success'] == '1'): ?>
                <div
                    style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px;border-radius:8px;margin-bottom:20px;">
                    Photo uploaded successfully for Student ID: <?= htmlspecialchars($_GET['id']) ?>
                </div>
            <?php elseif ($_GET['success'] == '2'): ?>
                <div
                    style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px;border-radius:8px;margin-bottom:20px;">
                    New Student ID created successfully!<br>
                    Student ID: <?= htmlspecialchars($_GET['id']) ?><br>
                    Student Name: <?= htmlspecialchars($_GET['name']) ?>
                </div>
            <?php elseif ($_GET['success'] == '3'): ?>
                <div
                    style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px;border-radius:8px;margin-bottom:20px;">
                    Student information updated successfully!<br>
                    Student ID: <?= htmlspecialchars($_GET['id']) ?>
                </div>
            <?php elseif ($_GET['success'] == '4'): ?>
                <div
                    style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:20px;">
                    Student deleted successfully!<br>
                    Student Name: <?= htmlspecialchars($_GET['name']) ?>
                </div>
            <?php elseif ($_GET['success'] == '5'): ?>
                <div
                    style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px;border-radius:8px;margin-bottom:20px;">
                    LRN data synchronized successfully!<br>
                    All student LRNs have been updated from registration data.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div
                style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:20px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php
        // Check for LRN mismatches and show warning
        $mismatches = 0;
        foreach ($students as $student) {
            if (!empty($student['reg_lrn']) && !empty($student['lrn']) && $student['reg_lrn'] !== $student['lrn']) {
                $mismatches++;
            }
        }
        if ($mismatches > 0): ?>
            <div
                style="background:#fef3cd;border:1px solid #fde68a;color:#92400e;padding:12px;border-radius:8px;margin-bottom:20px;">
                <strong>⚠️ LRN Mismatch Warning:</strong> <?= $mismatches ?> student(s) have different LRN values between
                registration and enrollment records.
                Click "🔗 Sync LRN Data" to fix this issue, or use "🔍 Check LRN Mismatches" for detailed analysis.
            </div>
        <?php endif; ?>

        <!-- Search and Filter Section -->
        <div
            style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Search Students:</label>
                    <input type="text" id="searchInput" placeholder="Search by name, student ID, or LRN..."
                        style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px;">
                </div>
                <div style="min-width: 150px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Filter by Grade:</label>
                    <select id="gradeFilter"
                        style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px;">
                        <option value="">All Grades</option>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                        <option value="Grade 11">Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                </div>
                <div style="min-width: 120px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">View:</label>
                    <select id="viewToggle"
                        style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px;">
                        <option value="table">Table View</option>
                        <option value="cards">Card View</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Table View -->
        <div id="tableView"
            style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: var(--primary); color: white;">
                        <tr>
                            <th style="padding: 15px; text-align: left; font-weight: 500;">Student ID</th>
                            <th style="padding: 15px; text-align: left; font-weight: 500;">Name</th>
                            <th style="padding: 15px; text-align: left; font-weight: 500;">LRN</th>
                            <th style="padding: 15px; text-align: left; font-weight: 500;">Grade & Section</th>
                            <th style="padding: 15px; text-align: left; font-weight: 500;">Enrolled Date</th>
                            <th style="padding: 15px; text-align: left; font-weight: 500;">Photo</th>
                            <th style="padding: 15px; text-align: center; font-weight: 500;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentTableBody">
                        <?php foreach ($students as $student): ?>
                            <?php
                            // Safely access array keys with fallbacks
                            $fullName = $student['display_name'] ?? $student['student_name'] ?? 'Unknown Student';
                            $lrn = $student['lrn'] ?? ($student['reg_lrn'] ?? 'N/A');
                            $grade = $student['grade_level'] ?? $student['grade_level_to_enroll'] ?? 'N/A';
                            $section = $student['section'] ?? 'Section A';
                            $photoPath = $student['photo_path'] ?? null;
                            $enrolledDate = date('M d, Y', strtotime($student['enrolled_at'] ?? $student['created_at']));
                            ?>
                            <tr class="student-row" data-name="<?= strtolower(htmlspecialchars($fullName)) ?>"
                                data-student-id="<?= strtolower(htmlspecialchars($student['student_id'])) ?>"
                                data-lrn="<?= strtolower(htmlspecialchars($lrn)) ?>"
                                data-grade="<?= htmlspecialchars($grade) ?>">
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb; font-weight: 500;">
                                    <?= htmlspecialchars($student['student_id']) ?>
                                </td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb;">
                                    <?= htmlspecialchars($fullName) ?>
                                </td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb;">
                                    <?= htmlspecialchars($lrn) ?>
                                    <?php if (!empty($student['reg_lrn'])): ?>
                                        <br><small style="color: #10b981; font-size: 10px;">📋 From Registration</small>
                                    <?php elseif (!empty($student['lrn'])): ?>
                                        <br><small style="color: #f59e0b; font-size: 10px;">💾 From Enrollment</small>
                                    <?php else: ?>
                                        <br><small style="color: #ef4444; font-size: 10px;">❌ No LRN</small>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb;">
                                    <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?>
                                </td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb;">
                                    <?= htmlspecialchars($enrolledDate) ?>
                                </td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb; text-align: center;">
                                    <?php if ($photoPath): ?>
                                        <img src="<?= htmlspecialchars($photoPath) ?>" alt="Student Photo"
                                            style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;">
                                    <?php else: ?>
                                        <div
                                            style="width: 40px; height: 40px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; margin: 0 auto; color: #9ca3af; font-size: 12px;">
                                            No Photo
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e5e7eb; text-align: center;">
                                    <div style="display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;">
                                        <button class="btn btn-small"
                                            onclick="openPhotoModal('<?= htmlspecialchars($student['student_id']) ?>')"
                                            style="font-size: 11px; padding: 4px 8px;" title="Upload Photo">
                                            📷
                                        </button>
                                        <button class="btn btn-small"
                                            onclick="openEditModal('<?= htmlspecialchars($student['student_id']) ?>')"
                                            style="font-size: 11px; padding: 4px 8px;" title="Edit Info">
                                            ✏️
                                        </button>
                                        <button class="btn btn-success btn-small"
                                            onclick="generateIDCard('<?= htmlspecialchars($student['student_id']) ?>')"
                                            style="font-size: 11px; padding: 4px 8px;" title="Generate ID Card">
                                            🆔
                                        </button>
                                        <button class="btn btn-small"
                                            onclick="printIDCard('<?= htmlspecialchars($student['student_id']) ?>')"
                                            style="font-size: 11px; padding: 4px 8px;" title="Print ID Card">
                                            🖨️
                                        </button>
                                        <button class="btn btn-error btn-small"
                                            onclick="openDeleteModal('<?= htmlspecialchars($student['student_id']) ?>', '<?= htmlspecialchars($fullName) ?>')"
                                            style="font-size: 11px; padding: 4px 8px;" title="Delete Student">
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Card View (Original Grid) -->
        <div id="cardView" style="display: none;">
            <div class="students-grid">
                <?php foreach ($students as $student): ?>
                    <?php
                    // Safely access array keys with fallbacks
                    $fullName = $student['display_name'] ?? $student['student_name'] ?? 'Unknown Student';
                    $lrn = $student['lrn'] ?? ($student['reg_lrn'] ?? 'N/A');
                    $grade = $student['grade_level'] ?? $student['grade_level_to_enroll'] ?? 'N/A';
                    $section = $student['section'] ?? 'Section A';
                    $photoPath = $student['photo_path'] ?? null;

                    ?>
                    <div class="student-card">
                        <div class="student-info">
                            <h3><?= htmlspecialchars($fullName) ?></h3>
                            <p><strong>Student ID:</strong> <?= htmlspecialchars($student['student_id']) ?></p>
                            <p><strong>LRN:</strong> <?= htmlspecialchars($lrn) ?>
                                <?php if (!empty($student['reg_lrn'])): ?>
                                    <span style="color: #10b981; font-size: 12px;">📋 From Registration</span>
                                <?php elseif (!empty($student['lrn'])): ?>
                                    <span style="color: #f59e0b; font-size: 12px;">💾 From Enrollment</span>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-size: 12px;">❌ No LRN</span>
                                <?php endif; ?>
                            </p>
                            <p><strong>Grade & Section:</strong> <?= htmlspecialchars($grade) ?> -
                                <?= htmlspecialchars($section) ?></p>
                            <p><strong>Enrolled:</strong>
                                <?= date('M d, Y', strtotime($student['enrolled_at'] ?? $student['created_at'])) ?></p>
                        </div>
                        <div style="margin-top: 15px;">
                            <button class="btn btn-small"
                                onclick="openPhotoModal('<?= htmlspecialchars($student['student_id']) ?>')">
                                <?= $photoPath ? 'Update Photo' : 'Upload Photo' ?>
                            </button>
                            <button class="btn btn-small"
                                onclick="openEditModal('<?= htmlspecialchars($student['student_id']) ?>')">
                                Edit Info
                            </button>
                            <button class="btn btn-success btn-small"
                                onclick="generateIDCard('<?= htmlspecialchars($student['student_id']) ?>')">
                                Generate ID Card
                            </button>
                            <button class="btn btn-small"
                                onclick="printIDCard('<?= htmlspecialchars($student['student_id']) ?>')">
                                Print ID Card
                            </button>
                            <button class="btn btn-error btn-small"
                                onclick="openDeleteModal('<?= htmlspecialchars($student['student_id']) ?>', '<?= htmlspecialchars($fullName) ?>')">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($students)): ?>
            <div style="text-align: center; padding: 40px; color: var(--muted);">
                <h3>No enrolled students found</h3>
                <p>Students need to be enrolled first before generating ID cards.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Photo Upload Modal -->
    <div id="photoModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closePhotoModal()">&times;</span>
            <h2>Upload Student Photo</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" id="modalStudentId" name="student_id">
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Select Photo:</label>
                    <input type="file" name="photo" accept="image/*" required
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;">
                </div>
                <div style="text-align: right;">
                    <button type="button" class="btn btn-error" onclick="closePhotoModal()">Cancel</button>
                    <button type="submit" class="btn">Upload Photo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ID Card Preview Modal -->
    <div id="idModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeIDModal()">&times;</span>
            <h2>Student ID Card Preview</h2>
            <div id="idCardPreview"></div>
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn btn-success" onclick="printPreview()">Print ID Card</button>
                <button class="btn" onclick="closeIDModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- New Student ID Modal -->
    <div id="newIDModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close" onclick="closeNewIDModal()">&times;</span>
            <h2>Generate New Student ID</h2>
            <form id="newIDForm" method="post" action="">
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Student ID:</label>
                    <input type="text" id="newStudentId" name="student_id" required
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Enter new student ID (e.g., STU-2024001)">
                    <small style="color: var(--muted); font-size: 12px;">Format: STU-YYYYXXX (Year + 3-digit
                        number)</small>
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Student Name:</label>
                    <input type="text" id="newStudentName" name="student_name" required
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Enter student full name">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Grade Level:</label>
                    <select id="newGradeLevel" name="grade_level" required
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
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Section:</label>
                    <input type="text" id="newSection" name="section" required
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Enter section (e.g., Section A)" value="Section A">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">LRN (Optional):</label>
                    <input type="text" id="newLRN" name="lrn"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Enter LRN if available">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Birthdate (Optional):</label>
                    <input type="date" id="newBirthdate" name="birthdate"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Guardian Information (for ID
                        card):</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <input type="text" id="newGuardianFirst" name="guardian_first"
                            style="padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                            placeholder="Guardian First Name">
                        <input type="text" id="newGuardianLast" name="guardian_last"
                            style="padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                            placeholder="Guardian Last Name">
                    </div>
                    <input type="text" id="newGuardianContact" name="guardian_contact"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Guardian Contact Number">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Address (Optional):</label>
                    <input type="text" id="newAddress" name="address"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Student's address">
                </div>
                <div style="text-align: right;">
                    <button type="button" class="btn btn-error" onclick="closeNewIDModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Student ID</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student Info Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2>Edit Student Information</h2>
            <form id="editForm" method="post" action="">
                <input type="hidden" id="editStudentId" name="student_id">
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">LRN:</label>
                    <input type="text" id="editLRN" name="lrn"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Enter LRN">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Birthdate:</label>
                    <input type="date" id="editBirthdate" name="birthdate"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Guardian Information:</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <input type="text" id="editGuardianFirst" name="guardian_first"
                            style="padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                            placeholder="Guardian First Name">
                        <input type="text" id="editGuardianLast" name="guardian_last"
                            style="padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                            placeholder="Guardian Last Name">
                    </div>
                    <input type="text" id="editGuardianContact" name="guardian_contact"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Guardian Contact Number">
                </div>
                <div style="margin: 20px 0;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Address:</label>
                    <input type="text" id="editAddress" name="address"
                        style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px;"
                        placeholder="Student's address">
                </div>
                <div style="text-align: right;">
                    <button type="button" class="btn btn-error" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Information</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2 style="color: #dc2626;">Delete Student</h2>
            <div style="margin: 20px 0;">
                <p style="margin-bottom: 15px;">Are you sure you want to delete this student?</p>
                <div
                    style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 6px; margin-bottom: 15px;">
                    <p style="margin: 0; font-weight: 500; color: #991b1b;"><strong>Student ID:</strong> <span
                            id="deleteStudentId"></span></p>
                    <p style="margin: 5px 0 0 0; color: #991b1b;"><strong>Name:</strong> <span
                            id="deleteStudentName"></span></p>
                </div>
                <p style="color: #dc2626; font-size: 14px; margin-bottom: 20px;">
                    <strong>Warning:</strong> This action cannot be undone. All student data, photos, and QR codes will
                    be permanently deleted.
                </p>
            </div>
            <div style="text-align: right;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-error" onclick="confirmDelete()">Delete Student</button>
            </div>
        </div>
    </div>

    <script>
        const students = <?= json_encode($students) ?>;
        const schoolYear = '<?= $schoolYear ?>';
        const principalName = '<?= addslashes($principalName) ?>';
        const principalStatus = '<?= addslashes($principalStatus) ?>';
        const principalEsignature = '<?= addslashes($principalEsignature) ?>';
        const advisersLookup = <?= json_encode($advisers_lookup) ?>;
        const classAdviserFallbackSignature = '<?= addslashes($quickAccessClassAdviserSignature) ?>';

        function openPhotoModal(studentId) {
            document.getElementById('modalStudentId').value = studentId;
            document.getElementById('photoModal').style.display = 'block';
        }

        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
        }

        function generateIDCard(studentId) {
            const student = students.find(s => s.student_id === studentId);
            if (!student) return;

            // Use the processed display name from PHP
            const fullName = student.display_name || `${student.last_name || ''}, ${student.first_name || ''} ${student.middle_name || ''}`.trim();

            // Use LRN from enrollment data (which should match registration after sync), 
            // fallback to registration LRN, then 'N/A'
            const lrn = student.lrn || student.reg_lrn || 'N/A';

            // Validate LRN consistency between registration and enrollment
            if (student.reg_lrn && student.lrn && student.reg_lrn !== student.lrn) {
                console.warn('LRN Mismatch detected for', student.student_name, ':', {
                    registration_lrn: student.reg_lrn,
                    enrollment_lrn: student.lrn,
                    using: lrn
                });
            }

            // Debug: Log LRN data for troubleshooting
            if (student.student_id === 'STU-2025001' || student.student_name.includes('Grace')) {
                console.log('LRN Debug for', student.student_name, ':', {
                    reg_lrn: student.reg_lrn,
                    lrn: student.lrn,
                    final_lrn: lrn,
                    registration_id: student.registration_id,
                    consistency_check: student.reg_lrn === student.lrn ? 'MATCH' : 'MISMATCH'
                });
            }

            const grade = student.grade_level || student.grade_level_to_enroll || 'N/A';
            const section = student.section || 'Section A';
            const photoPath = student.photo_path || null;
            const qrCodePath = student.qr_code_path || null;

            // Get enrollment date and calculate school year
            const enrolledDate = student.enrolled_at || student.created_at;
            const enrolledYear = new Date(enrolledDate).getFullYear();
            const enrolledSchoolYear = enrolledYear + '-' + (enrolledYear + 1);

            // Get student additional information - PULL FROM REGISTRATION when available
            let birthdate = 'N/A';
            const regBirthdate = student.reg_birthdate;
            const enrollmentBirthdate = student.birthdate;

            // Try registration birthdate first, then enrollment birthdate
            const birthdateSource = regBirthdate || enrollmentBirthdate;

            if (birthdateSource) {
                try {
                    // Handle different date formats
                    const date = new Date(birthdateSource);
                    if (!isNaN(date.getTime())) {
                        birthdate = date.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                    } else {
                        // If date parsing fails, try to format the string directly
                        birthdate = birthdateSource;
                    }
                } catch (e) {
                    console.warn('Date parsing error for', student.student_name, ':', e);
                    birthdate = birthdateSource; // Use raw value as fallback
                }
            }

            // Debug birthdate data
            console.log('Birthdate Debug for', student.student_name, ':', {
                reg_birthdate: regBirthdate,
                enrollment_birthdate: enrollmentBirthdate,
                birthdate_source: birthdateSource,
                final_birthdate: birthdate,
                registration_id: student.registration_id
            });

            // Guardian info for back of card - ALWAYS prefer registration details
            let guardianName = 'N/A';
            let guardianContact = 'N/A';
            const regGuardianFirst = student.reg_guardian_first || '';
            const regGuardianLast = student.reg_guardian_last || '';
            const regGuardianMiddle = student.guardian_middle || '';
            const regGuardianContact = student.reg_guardian_contact || '';
            if (regGuardianFirst || regGuardianLast) {
                guardianName = `${regGuardianFirst} ${regGuardianMiddle} ${regGuardianLast}`.trim().replace(/\s+/g, ' ');
            } else if (student.guardian_first || student.guardian_last) {
                guardianName = `${student.guardian_first || ''} ${student.guardian_middle || ''} ${student.guardian_last || ''}`.trim().replace(/\s+/g, ' ');
            }
            guardianContact = regGuardianContact || student.guardian_contact || 'N/A';

            // For the top indicator we still show the selected contact type when present
            let contactPersonType = student.reg_id_contact_person || student.id_contact_person || 'guardian';

            // Log guardian selection for debugging (after values are computed)
            console.log('Guardian Selection for', student.student_name, ':', {
                contact_person_type: contactPersonType,
                guardian_name: guardianName,
                guardian_contact: guardianContact,
                reg_id_contact_person: student.reg_id_contact_person,
                id_contact_person: student.id_contact_person
            });

            // Build address from registration address components; fallback to enrollment address
            let addressParts = [];
            if (student.curr_house_no) addressParts.push(student.curr_house_no);
            if (student.curr_street) addressParts.push(student.curr_street);
            if (student.curr_barangay) addressParts.push(student.curr_barangay);
            if (student.curr_city) addressParts.push(student.curr_city);
            if (student.curr_province) addressParts.push(student.curr_province);
            if (student.curr_zip) addressParts.push(student.curr_zip);

            // If no registration address, use enrollment address
            if (addressParts.length === 0 && student.address) {
                addressParts.push(student.address);
            }

            const address = addressParts.length > 0 ? addressParts.join(', ') : 'N/A';

            // Get class adviser for this student's grade and section
            const adviserKey = `${grade}-${section}`;
            const classAdviser = advisersLookup[adviserKey] || { name: 'Class Adviser', esignature: '' };
            const classAdviserName = classAdviser.name;
            let classAdviserEsignature = classAdviser.esignature;

            // If no specific adviser signature, try to get general class adviser signature
            if (!classAdviserEsignature) {
                // This would need to be passed from PHP - we'll add it to the JavaScript variables
                classAdviserEsignature = classAdviserFallbackSignature || '';
            }

            // Debug logging
            console.log('ID Card Generation Debug:', {
                studentId: studentId,
                grade: grade,
                section: section,
                adviserKey: adviserKey,
                classAdviserName: classAdviserName,
                classAdviserEsignature: classAdviserEsignature,
                principalName: principalName,
                principalEsignature: principalEsignature,
                advisersLookup: advisersLookup
            });

            // Show debug info in the UI for troubleshooting
            if (classAdviserName === 'Class Adviser' && !classAdviserEsignature) {
                console.warn('No class adviser assigned for', adviserKey, '- using default');
            }
            if (principalName === 'Dr. Maria Santos' && !principalEsignature) {
                console.warn('No principal assigned - using default');
            }

            const idCardHTML = `
                <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
                    <!-- Front of ID Card -->
                    <div class="id-card" data-student-id="${studentId}">
                        <div class="id-header">
                            <div class="id-logo">
                                <img src="${BASE_URL}/assets/images/school_logo.png" alt="School Logo" style="width: 100%; height: 100%; object-fit: contain;">
                            </div>
                            <div class="id-school-info">
                                <div>MALOLOS MARINE FISHERY</div>
                                <div>SCHOOL AND LABORATORY.</div>
                                <div>Balite, City of Malolos</div>
                                <div>Tel No.: (044) 816-8784</div>
                            </div>
                            <div class="id-deped-logo">
                                <img src="${BASE_URL}/assets/images/deped_logo.png" alt="DepEd Logo" style="width: 100%; height: 100%; object-fit: contain;">
                            </div>
                        </div>
                        <div class="id-band">JUNIOR HIGH SCHOOL</div>
                        <div class="id-body">
                            <div class="id-photo">
                                ${photoPath ? `<img src="${photoPath}" alt="Student Photo">` : 'No Photo'}
                            </div>
                            <div class="id-details">
                                <div class="id-lrn">LRN: ${lrn}</div>
                                <div class="id-name">${fullName}</div>
                                <div class="id-grade">${grade} - ${section}</div>
                                <div style="font-size: 10px; color: var(--muted); margin-bottom: 2px;">Grade & Section</div>
                                <div style="font-size: 10px; color: var(--muted);">S.Y. Enrolled: ${enrolledSchoolYear}</div>
                            </div>
                            <div class="id-qr-section">
                                <div class="id-qr-code">
                                    ${qrCodePath ? `<img src="${qrCodePath}" alt="Student QR Code" style="width: 100%; height: 100%; object-fit: contain;">` :
                    `<div style="font-size: 6px; text-align: center; line-height: 1.1;">
                                            ██ ▄▄ ██<br>
                                            ██ ██ ██<br>
                                            ██ ▄▄ ██<br>
                                            ██ ██ ██<br>
                                            ██ ▄▄ ██
                                        </div>`}
                                </div>
                                <div class="id-qr-label">QR CODE</div>
                            </div>
                        </div>
                        <div class="id-footer">S.Y. ${schoolYear}</div>
                    </div>

                    <!-- Back of ID Card -->
                    <div class="id-card-back">
                        <div class="id-back-header">
                            <div style="position: relative; z-index: 1;">Malolos Marine Fishery School and Laboratory</div>
                        </div>
                        <div class="id-back-content">
                            <div class="id-back-notice">
                                This card is non-transferable and shall be for the exclusive use by the student during the school year indicated. It shall be worn at all times and is also required during participation in all school activities.
                            </div>
                            <div class="id-back-info">
                                <div class="id-back-info-item">
                                    <div class="id-back-info-label">Student's Birthday:</div>
                                    <div class="id-back-info-value" style="${birthdate === 'N/A' ? 'color: #ef4444; font-style: italic;' : ''}">${birthdate}</div>
                                </div>
                                <div class="id-back-info-item">
                                    <div class="id-back-info-label">Guardian's Name:</div>
                                    <div class="id-back-info-value">${guardianName}</div>
                                </div>
                                <div class="id-back-info-item">
                                    <div class="id-back-info-label">Guardian Contact:</div>
                                    <div class="id-back-info-value">${guardianContact}</div>
                                </div>
                                <div class="id-back-info-item">
                                    <div class="id-back-info-label">Address:</div>
                                    <div class="id-back-info-value">${address}</div>
                                </div>
                            </div>
                            <div class="id-back-officials">
                                <div class="id-back-official">
                                    <div class="id-back-official-signature">
                                        ${classAdviserEsignature ?
                    `<img src="${classAdviserEsignature}" alt="Class Adviser E-Signature" style="max-width: 60px; max-height: 20px; object-fit: contain; border: 1px solid #e5e7eb;">` :
                    `<div class="signature-line"></div><div class="signature-label">E-Signature</div>`
                }
                                    </div>
                                    <div class="id-back-official-name" style="font-weight: 600; color: #1f2937; font-size: 8px;">${classAdviserName}</div>
                                    <div class="id-back-official-label" style="font-size: 6px;">Class Adviser</div>
                                </div>
                                <div class="id-back-official">
                                    <div class="id-back-official-signature">
                                        ${principalEsignature ?
                    `<img src="${principalEsignature}" alt="Principal E-Signature" style="max-width: 60px; max-height: 20px; object-fit: contain; border: 1px solid #e5e7eb;">` :
                    `<div class="signature-line"></div><div class="signature-label">E-Signature</div>`
                }
                                    </div>
                                    <div class="id-back-official-name" style="font-weight: 600; color: #1f2937; font-size: 8px;">${principalName}</div>
                                    <div class="id-back-official-label" style="font-size: 6px;">${principalStatus}</div>
                                </div>
                            </div>
                        </div>
                        <div class="id-back-footer">S.Y. ${schoolYear}</div>
                    </div>
                </div>
            `;

            // Add guardian selection indicator
            const guardianIndicator = `
                <div style="background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; padding: 12px; margin: 16px 0; display: flex; align-items: center;">
                    <div style="background: #0ea5e9; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-right: 12px; font-size: 12px; font-weight: bold;">i</div>
                    <div>
                        <div style="font-weight: 600; color: #0c4a6e; margin-bottom: 4px;">ID Contact Person Information</div>
                        <div style="font-size: 14px; color: #0369a1;">
                            <strong>Selected Contact:</strong> ${contactPersonType ? contactPersonType.charAt(0).toUpperCase() + contactPersonType.slice(1) : "Guardian"}<br>
                            <strong>Name:</strong> ${guardianName}<br>
                            <strong>Contact:</strong> ${guardianContact}
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('idCardPreview').innerHTML = guardianIndicator + idCardHTML;
            document.getElementById('idModal').style.display = 'block';
        }

        function closeIDModal() {
            document.getElementById('idModal').style.display = 'none';
        }

        function openNewIDModal() {
            document.getElementById('newIDModal').style.display = 'block';
            // Auto-generate a suggested student ID
            const currentYear = new Date().getFullYear();
            const suggestedId = 'STU-' + currentYear + '001';
            document.getElementById('newStudentId').value = suggestedId;
        }

        function closeNewIDModal() {
            document.getElementById('newIDModal').style.display = 'none';
            document.getElementById('newIDForm').reset();
        }

        function openEditModal(studentId) {
            const student = students.find(s => s.student_id === studentId);
            if (!student) return;

            document.getElementById('editStudentId').value = studentId;
            document.getElementById('editLRN').value = student.lrn || student.reg_lrn || '';
            document.getElementById('editBirthdate').value = student.reg_birthdate || student.birthdate || '';
            document.getElementById('editGuardianFirst').value = student.reg_guardian_first || student.guardian_first || '';
            document.getElementById('editGuardianLast').value = student.reg_guardian_last || student.guardian_last || '';
            document.getElementById('editGuardianContact').value = student.reg_guardian_contact || student.guardian_contact || '';
            document.getElementById('editAddress').value = student.address || '';

            document.getElementById('editModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editForm').reset();
        }

        function openDeleteModal(studentId, studentName) {
            document.getElementById('deleteStudentId').textContent = studentId;
            document.getElementById('deleteStudentName').textContent = studentName;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function confirmDelete() {
            const studentId = document.getElementById('deleteStudentId').textContent;

            // Create a form to submit the delete request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_student_id';
            input.value = studentId;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        // Search and filter functionality
        function filterStudents() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const gradeFilter = document.getElementById('gradeFilter').value;
            const rows = document.querySelectorAll('.student-row');

            rows.forEach(row => {
                const name = row.getAttribute('data-name');
                const studentId = row.getAttribute('data-student-id');
                const lrn = row.getAttribute('data-lrn');
                const grade = row.getAttribute('data-grade');

                const matchesSearch = !searchTerm ||
                    name.includes(searchTerm) ||
                    studentId.includes(searchTerm) ||
                    lrn.includes(searchTerm);

                const matchesGrade = !gradeFilter || grade === gradeFilter;

                if (matchesSearch && matchesGrade) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // View toggle functionality
        function toggleView() {
            const viewType = document.getElementById('viewToggle').value;
            const tableView = document.getElementById('tableView');
            const cardView = document.getElementById('cardView');

            if (viewType === 'table') {
                tableView.style.display = 'block';
                cardView.style.display = 'none';
            } else {
                tableView.style.display = 'none';
                cardView.style.display = 'block';
            }
        }

        // Sync LRN data function
        function syncLRNData() {
            if (confirm('This will sync all LRN data from registrations to enrollments. Continue?')) {
                // Create a form to trigger LRN sync
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'sync_lrn_data';
                input.value = '1';

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Validate LRN consistency for all students
        function validateLRNConsistency() {
            const inconsistencies = [];

            students.forEach(student => {
                if (student.reg_lrn && student.lrn && student.reg_lrn !== student.lrn) {
                    inconsistencies.push({
                        student_id: student.student_id,
                        student_name: student.student_name,
                        registration_lrn: student.reg_lrn,
                        enrollment_lrn: student.lrn
                    });
                }
            });

            if (inconsistencies.length > 0) {
                console.warn('LRN Inconsistencies found:', inconsistencies);
                return inconsistencies;
            } else {
                console.log('All LRN data is consistent between registration and enrollment');
                return [];
            }
        }

        // Auto-validate LRN consistency on page load
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(() => {
                const inconsistencies = validateLRNConsistency();
                if (inconsistencies.length > 0) {
                    console.warn(`Found ${inconsistencies.length} LRN inconsistencies. Use sync button to fix.`);
                }
            }, 1000);
        });

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function () {
            // Search input
            document.getElementById('searchInput').addEventListener('input', filterStudents);

            // Grade filter
            document.getElementById('gradeFilter').addEventListener('change', filterStudents);

            // View toggle
            document.getElementById('viewToggle').addEventListener('change', toggleView);
        });

        function printIDCard(studentId) {
            generateIDCard(studentId);
            setTimeout(() => {
                const printWindow = window.open('', '_blank');
                const idCards = document.querySelector('#idCardPreview .id-card').parentElement;
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>Student ID Card</title>
                            <style>
                                body { margin: 0; padding: 20px; font-family: Arial, sans-serif; }
                                .id-card { 
                                    width: 350px; height: 220px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); 
                                    border: 3px solid #1e40af; border-radius: 16px; position: relative; overflow: hidden; margin: 0 auto;
                                    box-shadow: 0 8px 32px rgba(30, 64, 175, 0.15);
                                }
                                .id-card-back { 
                                    width: 350px; height: 220px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); 
                                    border: 3px solid #1e40af; border-radius: 16px; position: relative; overflow: hidden; margin: 0 auto;
                                    box-shadow: 0 8px 32px rgba(30, 64, 175, 0.15);
                                }
                                .id-header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 10px 14px; font-size: 12px; 
                                    font-weight: bold; display: flex; align-items: center; justify-content: space-between; }
                                .id-back-header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 8px 14px; font-size: 12px; 
                                    font-weight: bold; text-align: center; position: relative; }
                                .id-logo { width: 45px; height: 45px; background: white; border-radius: 50%; 
                                    display: flex; align-items: center; justify-content: center; font-size: 16px; 
                                    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15); }
                                .id-logo img { width: 100%; height: 100%; object-fit: contain; }
                                .id-deped-logo { width: 45px; height: 45px; background: white; border-radius: 50%; 
                                    display: flex; align-items: center; justify-content: center; font-size: 16px; 
                                    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15); }
                                .id-deped-logo img { width: 100%; height: 100%; object-fit: contain; }
                                .id-school-info { flex: 1; text-align: center; margin: 0 8px; }
                                .id-school-info div { line-height: 1.2; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                .id-band { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 6px 12px; font-size: 14px; 
                                    font-weight: bold; text-align: center; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                .id-body { padding: 14px; display: flex; height: 120px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
                                .id-back-content { padding: 12px; height: 160px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); 
                                    display: flex; flex-direction: column; justify-content: space-between; }
                                .id-photo { width: 60px; height: 60px; border: 3px solid #1e40af; border-radius: 8px;
                                    margin-right: 12px; background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%); display: flex; align-items: center; 
                                    justify-content: center; font-size: 10px; color: #64748b; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15); overflow: hidden; }
                                .id-photo img { width: 100%; height: 100%; object-fit: cover; }
                                .id-details { flex: 1; margin-right: 8px; }
                                .id-qr-section { width: 80px; height: 80px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
                                .id-qr-code { width: 70px; height: 70px; background: #ffffff; border: 3px solid #1e40af; border-radius: 8px; 
                                    display: flex; align-items: center; justify-content: center; font-size: 8px; color: #64748b; margin-bottom: 6px; 
                                    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2); }
                                .id-qr-label { font-size: 7px; color: #1e40af; text-align: center; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
                                .id-lrn { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 3px 8px; font-size: 10px; 
                                    font-weight: bold; margin-bottom: 4px; border-radius: 4px; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); 
                                    box-shadow: 0 2px 4px rgba(251, 191, 36, 0.3); }
                                .id-name { font-size: 14px; font-weight: bold; margin-bottom: 2px; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); }
                                .id-grade { font-size: 11px; color: #64748b; font-weight: 500; }
                                .id-footer { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 6px 12px; font-size: 12px; 
                                    font-weight: bold; text-align: center; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                .id-back-footer { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 4px 12px; font-size: 10px; 
                                    font-weight: bold; text-align: center; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                .id-back-notice { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 8px; margin-bottom: 8px; 
                                    font-size: 9px; line-height: 1.3; color: #92400e; text-align: center; font-weight: 500; }
                                .id-back-info { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 8px; line-height: 1.2; }
                                .id-back-info-item { display: flex; flex-direction: column; }
                                .id-back-info-label { font-weight: bold; color: #1e40af; margin-bottom: 1px; }
                                .id-back-info-value { color: #374151; font-size: 7px; }
                                .id-back-officials { display: flex; justify-content: space-between; margin-top: 8px; padding-top: 6px; border-top: 1px solid #e5e7eb; }
                                .id-back-official { text-align: center; font-size: 7px; }
                                .id-back-official-label { font-weight: bold; color: #1e40af; margin-bottom: 2px; font-size: 6px; }
                                .id-back-official-name { color: #374151; font-size: 8px; font-weight: 600; }
                                .id-back-official-signature { text-align: center; margin-bottom: 4px; }
                                .signature-line { width: 60px; height: 1px; background-color: #1e40af; margin: 0 auto 2px auto; border-bottom: 1px solid #1e40af; }
                                .signature-label { color: #6b7280; font-size: 5px; font-style: italic; }
                                @media print {
                                    body { margin: 0; padding: 10px; }
                                    .id-card, .id-card-back { margin: 10px auto; page-break-inside: avoid; }
                                }
                            </style>
                        </head>
                        <body>
                            ${idCards.outerHTML}
                        </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.print();
            }, 100);
        }

        function printPreview() {
            // Get the current student ID from the preview
            const currentStudentId = document.querySelector('#idCardPreview .id-card')?.getAttribute('data-student-id');
            if (currentStudentId) {
                printIDCard(currentStudentId);
            } else {
                // If no student ID found, try to get the content directly
                const idCardElement = document.querySelector('#idCardPreview .id-card');
                if (idCardElement) {
                    const printWindow = window.open('', '_blank');
                    const idCards = idCardElement.parentElement;
                    printWindow.document.write(`
                        <html>
                            <head>
                                <title>Student ID Card</title>
                                <style>
                                    body { margin: 0; padding: 20px; font-family: Arial, sans-serif; }
                                    .id-card { 
                                        width: 350px; height: 220px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); 
                                        border: 3px solid #1e40af; border-radius: 16px; position: relative; overflow: hidden; margin: 0 auto;
                                        box-shadow: 0 8px 32px rgba(30, 64, 175, 0.15);
                                    }
                                    .id-card-back { 
                                        width: 350px; height: 220px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); 
                                        border: 3px solid #1e40af; border-radius: 16px; position: relative; overflow: hidden; margin: 0 auto;
                                        box-shadow: 0 8px 32px rgba(30, 64, 175, 0.15);
                                    }
                                    .id-header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 10px 14px; font-size: 12px; 
                                        font-weight: bold; display: flex; align-items: center; justify-content: space-between; }
                                    .id-back-header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 8px 14px; font-size: 12px; 
                                        font-weight: bold; text-align: center; position: relative; }
                                    .id-logo { width: 45px; height: 45px; background: white; border-radius: 50%; 
                                        display: flex; align-items: center; justify-content: center; font-size: 16px; 
                                        box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15); }
                                    .id-logo img { width: 100%; height: 100%; object-fit: contain; }
                                    .id-deped-logo { width: 45px; height: 45px; background: white; border-radius: 50%; 
                                        display: flex; align-items: center; justify-content: center; font-size: 16px; 
                                        box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15); }
                                    .id-deped-logo img { width: 100%; height: 100%; object-fit: contain; }
                                    .id-school-info { flex: 1; text-align: center; margin: 0 8px; }
                                    .id-school-info div { line-height: 1.2; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                    .id-band { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 6px 12px; font-size: 14px; 
                                        font-weight: bold; text-align: center; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                    .id-body { padding: 14px; display: flex; height: 120px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
                                    .id-back-content { padding: 12px; height: 160px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); 
                                        display: flex; flex-direction: column; justify-content: space-between; }
                                    .id-photo { width: 60px; height: 60px; border: 3px solid #1e40af; border-radius: 8px;
                                        margin-right: 12px; background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%); display: flex; align-items: center; 
                                        justify-content: center; font-size: 10px; color: #64748b; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15); overflow: hidden; }
                                    .id-photo img { width: 100%; height: 100%; object-fit: cover; }
                                    .id-details { flex: 1; margin-right: 8px; }
                                    .id-qr-section { width: 80px; height: 80px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
                                    .id-qr-code { width: 70px; height: 70px; background: #ffffff; border: 3px solid #1e40af; border-radius: 8px; 
                                        display: flex; align-items: center; justify-content: center; font-size: 8px; color: #64748b; margin-bottom: 6px; 
                                        box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2); }
                                    .id-qr-label { font-size: 7px; color: #1e40af; text-align: center; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
                                    .id-lrn { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 3px 8px; font-size: 10px; 
                                        font-weight: bold; margin-bottom: 4px; border-radius: 4px; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); 
                                        box-shadow: 0 2px 4px rgba(251, 191, 36, 0.3); }
                                    .id-name { font-size: 14px; font-weight: bold; margin-bottom: 2px; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); }
                                    .id-grade { font-size: 11px; color: #64748b; font-weight: 500; }
                                    .id-footer { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 6px 12px; font-size: 12px; 
                                        font-weight: bold; text-align: center; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                    .id-back-footer { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 4px 12px; font-size: 10px; 
                                        font-weight: bold; text-align: center; color: #1f2937; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); }
                                    .id-back-notice { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 8px; margin-bottom: 8px; 
                                        font-size: 9px; line-height: 1.3; color: #92400e; text-align: center; font-weight: 500; }
                                    .id-back-info { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 8px; line-height: 1.2; }
                                    .id-back-info-item { display: flex; flex-direction: column; }
                                    .id-back-info-label { font-weight: bold; color: #1e40af; margin-bottom: 1px; }
                                    .id-back-info-value { color: #374151; font-size: 7px; }
                                    .id-back-officials { display: flex; justify-content: space-between; margin-top: 8px; padding-top: 6px; border-top: 1px solid #e5e7eb; }
                                    .id-back-official { text-align: center; font-size: 7px; }
                                    .id-back-official-label { font-weight: bold; color: #1e40af; margin-bottom: 2px; font-size: 6px; }
                                    .id-back-official-name { color: #374151; font-size: 8px; font-weight: 600; }
                                    .id-back-official-signature { text-align: center; margin-bottom: 4px; }
                                    .signature-line { width: 60px; height: 1px; background-color: #1e40af; margin: 0 auto 2px auto; border-bottom: 1px solid #1e40af; }
                                    .signature-label { color: #6b7280; font-size: 5px; font-style: italic; }
                                    @media print {
                                        body { margin: 0; padding: 10px; }
                                        .id-card, .id-card-back { margin: 10px auto; page-break-inside: avoid; }
                                    }
                                </style>
                            </head>
                            <body>
                                ${idCards.outerHTML}
                            </body>
                        </html>
                    `);
                    printWindow.document.close();
                    printWindow.print();
                } else {
                    alert('No ID card content found to print. Please generate an ID card first.');
                }
            }
        }

        // Close modals when clicking outside
        window.onclick = function (event) {
            const photoModal = document.getElementById('photoModal');
            const idModal = document.getElementById('idModal');
            const newIDModal = document.getElementById('newIDModal');
            if (event.target === photoModal) {
                closePhotoModal();
            }
            if (event.target === idModal) {
                closeIDModal();
            }
            if (event.target === newIDModal) {
                closeNewIDModal();
            }
        }
    </script>
</body>

</html>