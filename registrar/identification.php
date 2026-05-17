<?php require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin']); ?>
<?php
// Suppress undefined array key warnings for better user experience
error_reporting(E_ERROR | E_PARSE);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/student_id_utility.php';

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

        // Auto-generate ID when not provided
        if ($student_id === '') {
            $student_id = generateStudentId($pdo);
        }

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

        // Generate QR code for the new student using shared utility
        $qrCodePath = generateStudentQRCode($student_id, $student_name);

        // Update enrollment record with QR code path
        if ($qrCodePath) {
            $stmt = $pdo->prepare('UPDATE enrollments SET qr_code_path = ? WHERE student_id = ?');
            $stmt->execute([$qrCodePath, $student_id]);
        }

        // Sync to permanent students table for cross-portal consistency
        syncToStudentsTable($pdo, [
            'student_id' => $student_id,
            'first_name' => $student_name,
            'last_name'  => '',
            'course'     => $grade_level,
            'year_level' => $grade_level,
            'qr_code_path' => $qrCodePath
        ]);

        header('Location: identification.php?success=2&id=' . urlencode($student_id) . '&name=' . urlencode($student_name));
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
        $stmt->execute(['/assets/photos/' . $fileName, $student_id]);

        header('Location: identification.php?success=1&id=' . $student_id);
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

        header('Location: identification.php?success=3&id=' . $student_id);
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
            $photoPath = dirname(__DIR__) . $student['photo_path'];
            if (file_exists($photoPath)) {
                unlink($photoPath);
            }
        }

        if ($student['qr_code_path']) {
            $qrPath = dirname(__DIR__) . $student['qr_code_path'];
            if (file_exists($qrPath)) {
                unlink($qrPath);
            }
        }

        header('Location: identification.php?success=4&name=' . urlencode($student['student_name']));
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

        header('Location: identification.php?success=5');
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

// Get current school year for position assignments and enrollment filtering
$current_sy = '2025-2026'; // Default fallback
try {
    $sy_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $sy_stmt->execute(['current_school_year']);
    $sy_val = $sy_stmt->fetchColumn();
    if ($sy_val) {
        $current_sy = $sy_val;
    }
} catch (Exception $e) {
    error_log("Error fetching SY: " . $e->getMessage());
}

if ($mismatch_count > 0) {
    error_log("WARNING: $mismatch_count LRN mismatches still exist after sync attempts");
}

// Generate student IDs for existing students who don't have them (Filtered by current SY)
// Uses shared generateStudentId() + generateStudentQRCode() utilities for consistency
$missing_student_ids = $pdo->prepare('
    SELECT id, student_name, grade_level, school_year
    FROM enrollments
    WHERE (student_id IS NULL OR student_id = "" OR student_id IS NULL)
      AND (school_year = ? OR school_year IS NULL OR school_year = "")
');
$missing_student_ids->execute([$current_sy]);
$missing_students = $missing_student_ids->fetchAll();

if (count($missing_students) > 0) {
    error_log("Found " . count($missing_students) . " students without student IDs for SY $current_sy. Generating IDs...");

    foreach ($missing_students as $student) {
        // Generate unique student ID using centralized utility
        $new_student_id = generateStudentId($pdo, $student['school_year'] ?: $current_sy);

        // Generate QR for this student
        $qrPath = generateStudentQRCode($new_student_id, $student['student_name']);

        // Update the enrollment record
        $update_stmt = $pdo->prepare('UPDATE enrollments SET student_id = ?, qr_code_path = ? WHERE id = ?');
        $update_stmt->execute([$new_student_id, $qrPath, $student['id']]);

        // Sync to students table
        syncToStudentsTable($pdo, [
            'student_id'   => $new_student_id,
            'first_name'   => $student['student_name'],
            'last_name'    => '',
            'course'       => $student['grade_level'],
            'year_level'   => $student['grade_level'],
            'qr_code_path' => $qrPath
        ]);

        error_log("Generated student ID: $new_student_id for enrollment ID: " . $student['id']);
    }
}

// Get enrolled students with all registration data (primarily filtered by current SY,
// but also including legacy rows where school_year is still NULL for backwards compatibility)
$stmt = $pdo->prepare('
    SELECT e.*, r.first_name, r.last_name, r.middle_name, r.lrn as reg_lrn, r.grade_level_to_enroll,
           r.birthdate as reg_birthdate, r.guardian_first as reg_guardian_first, r.guardian_last as reg_guardian_last, r.guardian_middle, r.guardian_contact as reg_guardian_contact,
           r.father_first, r.father_last, r.father_middle, r.father_contact,
           r.mother_first, r.mother_last, r.mother_middle, r.mother_contact,
           r.id_contact_person as reg_id_contact_person, r.curr_house_no, r.curr_street, r.curr_barangay, r.curr_city, r.curr_province, r.curr_zip
    FROM enrollments e 
    LEFT JOIN registrations r ON e.registration_id = r.id 
    WHERE (e.school_year = ? OR e.school_year IS NULL OR e.school_year = "")
    ORDER BY e.enrolled_at DESC
');
$stmt->execute([$current_sy]);
$students = $stmt->fetchAll();

// Fetch sections from section management for current school year
$sections_stmt = $pdo->prepare('
    SELECT grade_level, section_name 
    FROM sections 
    WHERE school_year = ? 
    ORDER BY grade_level, section_name
');
$sections_stmt->execute([$current_sy]);
$sections = $sections_stmt->fetchAll();

// Debug: Check if we have any students with registration data
$studentsWithReg = array_filter($students, function ($s) {
    return !empty($s['registration_id']);
});
error_log("Students with registration data (SY $current_sy): " . count($studentsWithReg) . " out of " . count($students));

// Debug: Check birthdate data specifically
$studentsWithBirthdate = array_filter($students, function ($s) {
    return !empty($s['reg_birthdate']) || !empty($s['birthdate']);
});
error_log("Students with birthdate data (SY $current_sy): " . count($studentsWithBirthdate) . " out of " . count($students));

// Get principal data with e-signature
$principal_data = null;
$advisers_data = [];

try {
    // 1. First choice: Get from position_assignments (explicitly set)
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

    // 2. Second choice (Fallback): Get by "Principal" position title in employees table
    if (!$principal_data) {
        $principal_fallback_stmt = $pdo->prepare('
            SELECT e.id as employee_id, e.full_name, e.employee_code, e.department, e.position_title,
                   es.file_path as esignature_path
            FROM employees e
            LEFT JOIN employee_esignatures es ON e.id = es.employee_id AND es.position_type = "principal"
            WHERE e.position_title LIKE "%Principal%" AND e.is_active = 1
            LIMIT 1
        ');
        $principal_fallback_stmt->execute();
        $principal_data = $principal_fallback_stmt->fetch();
        
        if ($principal_data) {
            error_log("Principal fetched via position title fallback: " . $principal_data['full_name']);
        }
    }

    // 3. Get class advisers from position_assignments
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

    // Generate/refresh QR code if it doesn't exist or if it was generated using the legacy utility path.
    $qrPath = $student['qr_code_path'] ?? '';
    $needsQrRefresh = empty($qrPath) || strpos($qrPath, 'uploads/qrcodes') !== false;
    if ($needsQrRefresh && !empty($student['student_id'])) {
        $qrCodePath = generateStudentQRCode($student['student_id'], $student['display_name']);
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

// Set base URL for JS usage
$base_url = rtrim(url_for('/'), '/');
?>

<script>
    // Pass PHP base URL to JavaScript
    const BASE_URL = '<?php echo $base_url; ?>';
</script>

<?php
// Function to format e-signature path for web access
function formatEsignaturePath($path)
{
    if (empty($path)) {
        return '';
    }

    // If already absolute URL, keep as is
    if (strpos($path, 'http') === 0) {
        return $path;
    }

    // If already root-relative to app, keep as is
    if (strpos($path, '/assets/') === 0) {
        return $path;
    }

    // If path starts with assets/ or /assets/, ensure it uses url_for
    if (strpos($path, 'assets/') === 0) {
        return url_for('/' . $path);
    }
    if (strpos($path, '/assets/') === 0) {
        return url_for($path);
    }

    // If path is relative and missing assets/, prepend then make root-relative
    if (strpos($path, 'esignatures/') !== false) {
        return url_for('/assets/' . ltrim($path, '/'));
    }

    return url_for('/' . ltrim($path, '/'));
}

// Function to validate e-signature file exists and is accessible
function validateEsignatureFile($path)
{
    if (empty($path)) {
        return false;
    }

    // Convert web path to file system path
    $filePath = str_replace('assets/', __DIR__ . '/../assets/', $path);

    // Check if file exists and is readable
    return file_exists($filePath) && is_readable($filePath);
}

// Load principal/class adviser details from position assignments (with sensible defaults)
$principalName = 'Dr. Maria Santos';
$principalStatus = 'Principal';
$principalEsignature = '';

// Use principal data from database if available
if ($principal_data) {
    $principalName = $principal_data['full_name'] ?? 'Dr. Maria Santos';
    $principalStatus = $principal_data['position_title'] ?? 'Principal';
    $rawEsignaturePath = $principal_data['esignature_path'] ?? '';

    // Try to get e-signature from quick access first, then fallback to database path
    require_once __DIR__ . '/../config/esignature_utils.php';
    $quickAccessPath = getQuickAccessEsignaturePath('employee', $principal_data['employee_id']);

    if ($quickAccessPath && validateEsignatureFile($quickAccessPath)) {
        $principalEsignature = formatEsignaturePath($quickAccessPath);
        error_log("Principal E-signature: Using quick access path = '$quickAccessPath' -> formatted = '$principalEsignature'");
    } else {
        $formattedPath = formatEsignaturePath($rawEsignaturePath);
        $principalEsignature = validateEsignatureFile($formattedPath) ? $formattedPath : '';
        error_log("Principal E-signature: Using database path = '$formattedPath', Valid = " . (validateEsignatureFile($formattedPath) ? 'Yes' : 'No'));
    }
}

// Create advisers lookup array for easy access by grade and section
$advisers_lookup = [];
foreach ($advisers_data as $adviser) {
    // Normalize grade/section to ensure consistent lookup
    $normGrade = strtolower(trim((string) ($adviser['grade_level'] ?? '')));
    $normSection = strtolower(trim((string) ($adviser['section'] ?? '')));
    $key = $normGrade . '-' . $normSection;
    $rawEsignaturePath = $adviser['esignature_path'] ?? '';

    // Try to get e-signature from quick access first, then fallback to database path
    require_once __DIR__ . '/../config/esignature_utils.php';
    $quickAccessPath = getQuickAccessEsignaturePath('employee', $adviser['employee_id']);

    if ($quickAccessPath && validateEsignatureFile($quickAccessPath)) {
        $validEsignature = formatEsignaturePath($quickAccessPath);
        error_log("Adviser E-signature ($key): Using quick access path = '$quickAccessPath' -> formatted = '$validEsignature'");
    } else {
        $formattedPath = formatEsignaturePath($rawEsignaturePath);
        $validEsignature = validateEsignatureFile($formattedPath) ? $formattedPath : '';
        error_log("Adviser E-signature ($key): Using database path = '$formattedPath', Valid = " . (validateEsignatureFile($formattedPath) ? 'Yes' : 'No'));
    }

    $advisers_lookup[$key] = [
        'name' => $adviser['full_name'] ?? 'Class Adviser',
        'esignature' => $validEsignature
    ];
}

// Debug logging for principal and advisers data
error_log("Principal Data: " . json_encode($principal_data));
error_log("Advisers Data: " . json_encode($advisers_data));
error_log("Advisers Lookup: " . json_encode($advisers_lookup));

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student ID Generation | <?= $_SESSION['user']['role'] === 'admin' ? 'Admin' : 'Registrar' ?></title>
    
    <!-- CSS Tokens and Global Styles -->
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/identification.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* Shared Dashboard Components */
        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .stat-label {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .stat-value {
            color: #0f172a;
            font-size: 32px;
            font-weight: 700;
        }

        .stat-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
            display: flex;
            gap: 12px;
        }

        .action-bar {
            background: white;
            padding: 16px 24px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        /* ID Card Premium Styles */
        .id-card-common {
            width: 340px;
            height: 215px;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            background: white;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .id-card-front { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
        .id-card-back { background: #ffffff; }

        .id-header {
            background: #1e40af;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            position: relative;
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: radial-gradient(circle at 2px 2px, rgba(255,255,255,0.05) 1px, transparent 0);
            background-size: 8px 8px;
            pointer-events: none;
        }

        .id-logo-container {
            width: 32px; height: 32px;
            background: white;
            border-radius: 50%;
            padding: 2px;
            z-index: 1;
        }

        .id-school-text { flex: 1; text-align: center; z-index: 1; margin: 0 8px; }
        .id-school-name { font-size: 10px; font-weight: 800; letter-spacing: 0.5px; line-height: 1.1; margin-bottom: 2px; }
        .id-school-sub { font-size: 7px; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; }

        .id-divider-band {
            background: #fbbf24;
            padding: 3px 0;
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            color: #1e293b;
            box-shadow: inset 0 -2px 4px rgba(0,0,0,0.05);
            letter-spacing: 0.5px;
        }

        .id-main-body {
            display: flex;
            flex-direction: row;
            padding: 8px 16px;
            flex: 1;
            gap: 12px;
            align-items: center;
        }

        .id-photo-frame {
            width: 65px; height: 65px;
            border: 2px solid #1e40af;
            border-radius: 6px;
            overflow: hidden;
            background: white;
            flex-shrink: 0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .id-student-data { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            justify-content: center;
            min-width: 0;
        }
        
        .id-lrn-pill {
            background: #1e40af;
            color: white;
            font-size: 8px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            margin-bottom: 4px;
            width: fit-content;
        }

        .id-student-name {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .id-student-detail {
            font-size: 9px;
            color: #475569;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .id-qr-wrap {
            width: 55px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            gap: 4px;
        }

        .id-qr-box {
            width: 50px; height: 50px;
            border: 1px solid #e2e8f0;
            padding: 3px;
            background: white;
            border-radius: 4px;
        }

        .id-qr-text {
            font-size: 6px;
            font-weight: 800;
            color: #64748b;
            text-align: center;
            letter-spacing: 0.5px;
        }

        .id-footer-sy {
            background: #1e40af;
            color: white;
            font-size: 9px;
            font-weight: 700;
            text-align: center;
            padding: 4px;
            width: 100%;
            margin-top: auto;
        }

        /* BACK OF CARD */
        .id-back-header-blue {
            background: #1e40af;
            height: 8px;
            width: 100%;
        }

        .id-back-body {
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .id-back-notice-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 7px;
            line-height: 1.3;
            color: #92400e;
            text-align: center;
            font-weight: 500;
        }

        .id-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 12px;
            margin: 2px 0;
        }

        .id-info-item {
            display: flex;
            flex-direction: column;
        }

        .id-info-label {
            font-size: 7px;
            font-weight: 800;
            color: #1e40af;
            text-transform: uppercase;
            margin-bottom: 1px;
        }

        .id-info-val {
            font-size: 9px;
            color: #0f172a;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .id-signature-row {
            display: flex;
            justify-content: space-around;
            margin-top: auto;
            padding-bottom: 4px;
        }

        .id-sig-block {
            text-align: center;
            width: 110px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
        }

        .id-sig-img {
            height: 24px;
            max-width: 90px;
            object-fit: contain;
            margin-bottom: -4px;
            z-index: 2;
        }

        .id-sig-line {
            width: 100%;
            border-bottom: 1px solid #334155;
            margin-bottom: 2px;
        }

        .id-sig-name {
            font-size: 8px;
            font-weight: 800;
            color: #0f172a;
        }

        .id-sig-title {
            font-size: 6px;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .id-back-strip {
            background: #fbbf24;
            font-size: 8px;
            font-weight: 800;
            color: #1e293b;
        }

        /* MODALS */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 10005;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            border-radius: 20px;
            padding: 24px;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-height: 90vh;
            overflow-y: auto;
            margin: 5% auto;
        }

        #idModal .modal-content {
            max-width: 850px;
        }

        .close {
            position: absolute;
            top: 20px; right: 20px;
            font-size: 24px;
            color: #94a3b8;
            cursor: pointer;
            z-index: 10;
        }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; color: #334155; }
        .form-input, .form-select {
            width: 100%; padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
        }

        .content {
            padding: calc(var(--header-height) + 24px) 24px 24px;
            transition: all 0.3s ease;
        }
    </style>
</head>

<body>
    <?php
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'admin') {
        require_once dirname(__DIR__) . '/admin/admin_header.php';
        require_once dirname(__DIR__) . '/admin/admin_sidebar.php';
    } else {
        require_once dirname(__DIR__) . '/header.php';
        require_once dirname(__DIR__) . '/registrar/registrar_side_panel.php';
    }
    ?>

    <div class="content main-content">
        <!-- HEADER -->
        <div class="page-header">
            <div>
                <h1>Student ID Generation</h1>
                <p>Manage student records and generate official ID cards</p>
            </div>
        </div>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Students</div>
                <div class="stat-value"><?= count($students) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">With Photos</div>
                <div class="stat-value"><?= count(array_filter($students, fn($s) => !empty($s['photo_path']))) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Verified LRNs</div>
                <div class="stat-value" style="color:#10b981">
                    <?= count(array_filter($students, fn($s) => !empty($s['lrn']) || !empty($s['reg_lrn']))) ?>
                </div>
                <div class="stat-sub">
                    <span>From Reg: <?= count(array_filter($students, fn($s) => !empty($s['reg_lrn']))) ?></span>
                </div>
            </div>
        </div>

        <!-- ACTION BAR -->
        <div class="action-bar">
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by name, ID, or LRN...">
            </div>
            <select id="gradeFilter" class="form-select" style="width: auto; min-width: 150px;">
                <option value="">All Grades</option>
                <?php
                // Dynamically build grade list from enrolled students so filter always refers to actual enrollments
                $enrolledGrades = [];
                foreach ($students as $s) {
                    $g = $s['grade_level'] ?? $s['grade_level_to_enroll'] ?? '';
                    if ($g !== '' && !in_array($g, $enrolledGrades, true)) {
                        $enrolledGrades[] = $g;
                    }
                }
                sort($enrolledGrades);
                foreach ($enrolledGrades as $g): ?>
                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="sectionFilter" class="form-select" style="width: auto; min-width: 150px;">
                <option value="">All Sections</option>
                <?php if (!empty($sections)): ?>
                    <?php
                    $sectionNames = [];
                    foreach ($sections as $secRow) {
                        $name = $secRow['section_name'] ?? '';
                        if ($name !== '' && !in_array($name, $sectionNames, true)) {
                            $sectionNames[] = $name;
                        }
                    }
                    sort($sectionNames);
                    foreach ($sectionNames as $secName): ?>
                        <option value="<?= htmlspecialchars($secName) ?>"><?= htmlspecialchars($secName) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <button class="btn btn-success" onclick="openNewIDModal()">
                🆔 Generate New ID
            </button>
            <button class="btn btn-primary" onclick="generateAllVisibleIDs()" id="batchGenBtn">
                🆔 Generate All (Filtered)
            </button>
            <button class="btn btn-warning" onclick="bulkGenerateIDs()" id="bulkGenBtn">
                🚀 Bulk Generate Missing IDs
            </button>
            <button class="btn btn-secondary btn-sm" onclick="location.reload()">🔄 Refresh</button>
        </div>

        <!-- ALERTS -->
        <?php if (isset($_GET['success'])): ?>
                <div
                    style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:16px; border-radius:12px; margin-bottom:24px;">
                    <?php
                    if ($_GET['success'] == 1)
                        echo 'Photo uploaded successfully!';
                    elseif ($_GET['success'] == 2)
                        echo 'New Student ID created successfully!';
                    elseif ($_GET['success'] == 3)
                        echo 'Student information updated.';
                    elseif ($_GET['success'] == 4)
                        echo 'Student deleted successfully.';
                    elseif ($_GET['success'] == 5)
                        echo 'LRN data currently synced.';
                    ?>
                </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
                <div
                    style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:12px; margin-bottom:24px;">
                    <?= htmlspecialchars($error) ?>
                </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div id="tableView" class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Student Info</th>
                        <th>LRN Status</th>
                        <th>Grade & Section</th>
                        <th>Date Enrolled</th>
                        <th style="text-align: right">Actions</th>
                    </tr>
                </thead>
                <tbody id="studentTableBody">
                    <?php foreach ($students as $student): ?>
                            <?php
                            $fullName = $student['display_name'] ?? $student['student_name'] ?? 'Unknown';
                            $grade = $student['grade_level'] ?? $student['grade_level_to_enroll'] ?? 'N/A';
                            $section = $student['section'] ?? '';
                            $lrn = $student['lrn'] ?? $student['reg_lrn'] ?? 'N/A';
                            ?>
                            <tr class="student-row" data-name="<?= strtolower(htmlspecialchars($fullName)) ?>"
                                data-student-id="<?= strtolower(htmlspecialchars($student['student_id'])) ?>"
                                data-lrn="<?= strtolower(htmlspecialchars($lrn)) ?>"
                                data-grade="<?= htmlspecialchars($grade) ?>"
                                data-section="<?= htmlspecialchars($section) ?>">
                                <td>
                                    <div style="display: flex; gap: 12px; align-items: center;">
                                        <?php if (!empty($student['photo_path'])): ?>
                                                <img src="<?= htmlspecialchars($student['photo_path']) ?>" class="user-avatar">
                                        <?php else: ?>
                                                <div class="user-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($fullName) ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b;">
                                                <?= htmlspecialchars($student['student_id']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($lrn) ?></div>
                                    <?php if (!empty($student['reg_lrn'])): ?>
                                            <div style="font-size: 10px; color: #10b981;">● From Registration</div>
                                    <?php elseif (!empty($student['lrn'])): ?>
                                            <div style="font-size: 10px; color: #f59e0b;">● Manual Entry</div>
                                    <?php else: ?>
                                            <div style="font-size: 10px; color: #ef4444;">● Missing</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight: 500; color: #334155;"><?= htmlspecialchars($grade) ?></div>
                                    <div style="font-size: 12px; color: #64748b;"><?= htmlspecialchars($section) ?></div>
                                </td>
                                <td><?= date('M d, Y', strtotime($student['enrolled_at'] ?? $student['created_at'])) ?></td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                        <button class="btn btn-secondary btn-sm"
                                            onclick="openPhotoModal('<?= $student['student_id'] ?>')"
                                            title="Upload Photo">📷</button>
                                        <button class="btn btn-secondary btn-sm"
                                            onclick="openEditModal('<?= $student['student_id'] ?>')" title="Edit">✏️</button>
                                        <button class="btn btn-primary btn-sm"
                                            onclick="generateIDCard('<?= $student['student_id'] ?>')" title="Generate ID">🆔 ID
                                            Card</button>
                                        <button class="btn btn-danger btn-sm"
                                            onclick="openDeleteModal('<?= $student['student_id'] ?>', '<?= addslashes($fullName) ?>')">🗑️</button>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- HIDDEN CARD VIEW (Preserved functionality but hidden) -->
        <div id="cardView" style="display: none;"></div>
    </div>

    <!-- Photo Upload Modal -->
    <div id="photoModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closePhotoModal()">&times;</span>
            <h2 style="margin-top:0">Upload Student Photo</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" id="modalStudentId" name="student_id">
                <div class="form-group">
                    <label class="form-label">Select Photo</label>
                    <input type="file" name="photo" accept="image/*" required class="form-input">
                </div>
                <div style="text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-secondary" onclick="closePhotoModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Photo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ID Card Preview Modal -->
    <div id="idModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeIDModal()">&times;</span>
            <h2 style="margin-top:0">Student ID Card Preview</h2>
            <div id="idCardPreview"></div>
            <div style="text-align: center; margin-top: 32px; display: flex; justify-content: center; gap: 16px;">
                <button class="btn btn-secondary" onclick="closeIDModal()">Close</button>
                <button class="btn btn-success" onclick="printPreview()">Print ID Card</button>
            </div>
        </div>
    </div>

    <!-- New Student ID Modal -->
    <div id="newIDModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeNewIDModal()">&times;</span>
            <h2 style="margin-top:0">Generate New Student ID</h2>
            <form id="newIDForm" method="post" action="">
                <div class="form-group">
                    <label class="form-label">Student ID</label>
                    <input type="text" id="newStudentId" name="student_id" required class="form-input"
                        placeholder="e.g., STU-2024001">
                </div>
                <div class="form-group">
                    <label class="form-label">Student Name</label>
                    <input type="text" id="newStudentName" name="student_name" required class="form-input"
                        placeholder="Full Name">
                </div>
                <div class="form-group">
                    <label class="form-label">Grade Level</label>
                    <select id="newGradeLevel" name="grade_level" required class="form-select">
                        <option value="">Select Grade Level</option>
                        <?php
                        // Build unique grade levels from sections table so this ties to Section Management
                        $gradeLevels = [];
                        if (!empty($sections)) {
                            foreach ($sections as $secRow) {
                                $gl = $secRow['grade_level'] ?? '';
                                if ($gl !== '' && !in_array($gl, $gradeLevels, true)) {
                                    $gradeLevels[] = $gl;
                                }
                            }
                        }
                        sort($gradeLevels);
                        foreach ($gradeLevels as $gl): ?>
                            <option value="<?= htmlspecialchars($gl) ?>"><?= htmlspecialchars($gl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Section</label>
                    <select id="newSection" name="section" required class="form-select">
                        <option value="">Select Section</option>
                        <?php if (!empty($sections)): ?>
                            <?php foreach ($sections as $secRow): ?>
                                <option value="<?= htmlspecialchars($secRow['section_name']) ?>"
                                    data-grade="<?= htmlspecialchars($secRow['grade_level']) ?>">
                                    <?= htmlspecialchars($secRow['grade_level'] . ' - ' . $secRow['section_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">LRN (Optional)</label>
                    <input type="text" id="newLRN" name="lrn" class="form-input">
                </div>
                <!-- Extra fields collapsed/simplified if needed -->
                <div class="form-group">
                    <label class="form-label">Guardian Info</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <input type="text" id="newGuardianFirst" name="guardian_first" class="form-input"
                            placeholder="First Name">
                        <input type="text" id="newGuardianLast" name="guardian_last" class="form-input"
                            placeholder="Last Name">
                    </div>
                </div>

                <div style="text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeNewIDModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Create ID</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2 style="margin-top:0">Edit Student Info</h2>
            <form id="editForm" method="post" action="">
                <input type="hidden" id="editStudentId" name="student_id">
                <div class="form-group">
                    <label class="form-label">LRN</label>
                    <input type="text" id="editLRN" name="lrn" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Guardian Name</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <input type="text" id="editGuardianFirst" name="guardian_first" class="form-input"
                            placeholder="First">
                        <input type="text" id="editGuardianLast" name="guardian_last" class="form-input"
                            placeholder="Last">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Guardian Contact</label>
                    <input type="text" id="editGuardianContact" name="guardian_contact" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Home Address</label>
                    <input type="text" id="editAddress" name="address" class="form-input">
                </div>
                <div style="text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2 style="color: #dc2626; margin-top:0">Delete Student</h2>
            <div style="margin: 20px 0;">
                <p>Are you sure you want to delete this student?</p>
                <div
                    style="background: #fef2f2; border: 1px solid #fecaca; padding: 16px; border-radius: 12px; margin-bottom: 20px;">
                    <strong style="color: #991b1b; display:block">Student ID: <span
                            id="deleteStudentId"></span></strong>
                    <span style="color: #991b1b" id="deleteStudentName"></span>
                </div>
                <p style="color: #64748b; font-size: 14px;">This action is permanent and will remove all records.</p>
            </div>
            <div style="text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Forever</button>
            </div>
        </div>
    </div>

    <script>
        const BASE_URL = '<?= url_for('') ?>';
        const students = <?= json_encode($students) ?>;
        const schoolYear = '<?= $schoolYear ?>';
        const principalName = '<?= addslashes($principalName) ?>';
        const principalStatus = '<?= addslashes($principalStatus) ?>';
        const principalEsignature = '<?= addslashes($principalEsignature) ?>';
        const advisersLookup = <?= json_encode($advisers_lookup) ?>;

        // Debug logging
        console.log('E-Signature Debug Info:');
        console.log('Principal Name:', principalName);
        console.log('Principal Status:', principalStatus);
        console.log('Principal E-Signature:', principalEsignature);
        console.log('Advisers Lookup:', advisersLookup);

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

            // Proper path helper
            const getPath = (path) => {
                if (!path) return '';
                if (path.startsWith('http')) return path;
                const cleanPath = path.startsWith('/') ? path : '/' + path;
                // Avoid double BASE_URL if current site is already in path
                if (cleanPath.startsWith(BASE_URL)) return cleanPath;
                return BASE_URL + cleanPath;
            };

            const fullName = student.display_name || `${student.last_name || ''}, ${student.first_name || ''} ${student.middle_name || ''}`.trim();
            const lrn = student.lrn || student.reg_lrn || 'N/A';
            const grade = student.grade_level || student.grade_level_to_enroll || 'N/A';
            const section = student.section || 'N/A';
            const photoPath = student.photo_path;
            const qrCodePath = student.qr_code_path;
            const enrolledSchoolYear = (student.enrolled_at || student.created_at) ? new Date(student.enrolled_at || student.created_at).getFullYear() + '-' + (new Date(student.enrolled_at || student.created_at).getFullYear() + 1) : schoolYear;

            // Birthdate & Guardian Info
            let birthdate = 'N/A';
            const bdaySource = student.reg_birthdate || student.birthdate;
            if (bdaySource) {
                const date = new Date(bdaySource);
                if (!isNaN(date.getTime())) {
                    birthdate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                }
            }

            let guardianName = 'N/A';
            let guardianContact = 'N/A';
            const gFirst = student.reg_guardian_first || student.guardian_first || '';
            const gLast = student.reg_guardian_last || student.guardian_last || '';
            if (gFirst || gLast) guardianName = `${gFirst} ${student.guardian_middle || ''} ${gLast}`.trim().replace(/\s+/g, ' ');
            guardianContact = student.reg_guardian_contact || student.guardian_contact || 'N/A';
            const contactPersonType = (student.reg_id_contact_person || student.id_contact_person || 'guardian').toUpperCase();

            // Address
            const addressParts = [student.curr_house_no, student.curr_street, student.curr_barangay, student.curr_city, student.curr_province].filter(p => !!p);
            const address = addressParts.length > 0 ? addressParts.join(', ') : (student.address || 'N/A');

            // Advisers
            const adviserKey = `${String(grade || '').toLowerCase().trim()}-${String(section || '').toLowerCase().trim()}`;
            const classAdviser = advisersLookup[adviserKey] || { name: 'CLASS ADVISER', esignature: '' };

            const idCardHTML = `
                <div style="display: flex; gap: 32px; justify-content: center; flex-wrap: wrap; padding: 10px;">
                    <!-- Front -->
                    <div class="id-card-common id-card-front">
                        <div class="id-header">
                            <div class="id-logo-container">
                                <img src="${BASE_URL}/assets/images/school_logo.png" alt="" style="width:100%; height:100%; border-radius:50%;" onerror="this.style.display='none'">
                            </div>
                            <div class="id-school-text">
                                <div class="id-school-name">MALOLOS MARINE FISHERY</div>
                                <div class="id-school-name">SCHOOL AND LABORATORY</div>
                                <div class="id-school-sub">City of Malolos, Bulacan</div>
                            </div>
                            <div class="id-logo-container">
                                <img src="${BASE_URL}/assets/images/deped_logo.png" alt="" style="width:100%; height:100%; border-radius:50%;" onerror="this.style.display='none'">
                            </div>
                        </div>
                        <div class="id-divider-band">${grade.includes('Senior') ? 'SENIOR HIGH SCHOOL' : 'JUNIOR HIGH SCHOOL'}</div>
                        <div class="id-main-body">
                            <div class="id-photo-frame" style="display: flex; align-items: center; justify-content: center;">
                                ${photoPath ? `<img src="${getPath(photoPath)}" alt="" style="width:100%; height:100%; object-fit:cover;" onerror="this.parentElement.innerHTML='<span style=\\'font-size:10px; color:#94a3b8; font-weight:700;\\'>NO PHOTO</span>'">` : '<span style="font-size:10px; color:#94a3b8; font-weight:700;">NO PHOTO</span>'}
                            </div>
                            <div class="id-student-data">
                                <div class="id-lrn-pill">LRN: ${lrn}</div>
                                <div class="id-student-name">${fullName}</div>
                                <div class="id-student-detail">${grade} - ${section}</div>
                                <div class="id-student-detail">S.Y. ENROLLED: ${enrolledSchoolYear}</div>
                            </div>
                            <div class="id-qr-wrap">
                                <div class="id-qr-box" style="display:flex; align-items:center; justify-content:center;">
                                    ${qrCodePath ? `<img src="${getPath(qrCodePath)}" alt="" style="width:100%; height:100%;" onerror="this.parentElement.innerHTML='<span style=\\'font-size:10px; color:#94a3b8; font-weight:800;\\'>QR</span>'">` : '<span style="font-size:10px; color:#94a3b8; font-weight:800;">QR</span>'}
                                </div>
                                <div class="id-qr-text">VERIFY IDENTITY</div>
                            </div>
                        </div>
                        <div class="id-footer-sy">S.Y. ${schoolYear}</div>
                    </div>

                    <!-- Back -->
                    <div class="id-card-common id-card-back">
                        <div class="id-back-header-blue"></div>
                        <div class="id-back-body">
                            <div class="id-back-notice-box">
                                This card is non-transferable and must be worn at all times within school premises. If found, please return to Malolos Marine Fishery School and Laboratory.
                            </div>
                            <div class="id-info-grid">
                                <div class="id-info-item"><div class="id-info-label">BIRTHDATE</div><div class="id-info-val">${birthdate}</div></div>
                                <div class="id-info-item"><div class="id-info-label">${contactPersonType}</div><div class="id-info-val">${guardianName}</div></div>
                                <div class="id-info-item"><div class="id-info-label">CONTACT</div><div class="id-info-val">${guardianContact}</div></div>
                                <div class="id-info-item"><div class="id-info-label">ADDRESS</div><div class="id-info-val">${address}</div></div>
                            </div>
                            <div class="id-signature-row">
                                <div class="id-sig-block">
                                    ${classAdviser.esignature ? `<img src="${getPath(classAdviser.esignature)}" class="id-sig-img" style="mix-blend-mode: multiply;">` : '<div style="height:24px;"></div>'}
                                    <div class="id-sig-line"></div>
                                    <div class="id-sig-name">${classAdviser.name}</div>
                                    <div class="id-sig-title">Class Adviser</div>
                                </div>
                                <div class="id-sig-block">
                                    ${principalEsignature ? `<img src="${getPath(principalEsignature)}" class="id-sig-img" style="mix-blend-mode: multiply;">` : '<div style="height:24px;"></div>'}
                                    <div class="id-sig-line"></div>
                                    <div class="id-sig-name">${principalName}</div>
                                    <div class="id-sig-title">${principalStatus}</div>
                                </div>
                            </div>
                        </div>
                        <div class="id-back-strip">VALID UNTIL THE END OF SCHOOL YEAR</div>
                    </div>
                </div>
            `;

            const guardianIndicator = `
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 16px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 14px;">
                    <div style="background: #3b82f6; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 12px; font-weight: bold;">i</div>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 4px; color: #1e40af; font-size: 14px; font-weight: 700;">Student ID Preview</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; font-size: 13px; color: #475569;">
                            <div><strong>LRN:</strong> ${lrn}</div>
                            <div><strong>Contact:</strong> ${guardianName} (${guardianContact})</div>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('idCardPreview').innerHTML = guardianIndicator + idCardHTML;
            document.getElementById('idModal').style.display = 'flex';
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

        // Keep Section options in sync with selected Grade Level using data from Section Management
        (function () {
            const gradeSelect = document.getElementById('newGradeLevel');
            const sectionSelect = document.getElementById('newSection');
            if (!gradeSelect || !sectionSelect) return;

            const allOptions = Array.from(sectionSelect.options);

            function filterSections() {
                const grade = gradeSelect.value;
                sectionSelect.innerHTML = '';

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select Section';
                sectionSelect.appendChild(placeholder);

                allOptions.forEach(opt => {
                    const optGrade = opt.getAttribute('data-grade');
                    if (!optGrade || !grade || optGrade === grade) {
                        if (opt.value !== '') {
                            sectionSelect.appendChild(opt.cloneNode(true));
                        }
                    }
                });
            }

            gradeSelect.addEventListener('change', filterSections);
        })();

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
          async function generateAllVisibleIDs() {
            const rows = Array.from(document.querySelectorAll('.student-row')).filter(r => r.style.display !== 'none');
            if (rows.length === 0) {
                alert('No students found in the current filtered view.');
                return;
            }

            if (!confirm(`Generate and print ID cards for all ${rows.length} students currently shown?`)) {
                return;
            }

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Batch Student ID Cards</title>
                        <style>
                            @media print {
                                .page-break { page-break-after: always; }
                                body { margin: 0; padding: 0; background: white; }
                                .id-batch-item { 
                                    display: flex; 
                                    flex-direction: column; 
                                    align-items: center; 
                                    justify-content: center; 
                                    height: 100vh; 
                                    gap: 20px;
                                }
                                .no-print { display: none !important; }
                            }
                            body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
                            .batch-header { text-align: center; padding: 20px; background: white; border-bottom: 2px solid #1e40af; }
                            .id-batch-item { padding: 40px 0; border-bottom: 1px dashed #cbd5e1; }
                        </style>
                        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap">
                    </head>
                    <body>
                        <div class="batch-header no-print">
                            <h2 style="margin:0; color:#1e40af;">Batch Generation: ${rows.length} Students</h2>
                            <p style="margin:5px 0 0; color:#64748b;">Preparing preview... Press Ctrl+P to print.</p>
                        </div>
            `);

            // Inject all CSS from current page
            const styles = Array.from(document.querySelectorAll('style')).map(s => s.innerHTML).join('\n');
            printWindow.document.write('<style>' + styles + '</style>');

            // Reuse the same helper logic
            const getPath = (path) => {
                if (!path) return '';
                if (path.startsWith('http')) return path;
                const cleanPath = path.startsWith('/') ? path : '/' + path;
                if (cleanPath.startsWith(BASE_URL)) return cleanPath;
                return BASE_URL + cleanPath;
            };

            for (let i = 0; i < rows.length; i++) {
                const studentId = rows[i].getAttribute('data-student-id');
                const student = students.find(s => s.student_id === studentId);
                
                if (student) {
                    const fullName = student.display_name || `${student.last_name || ''}, ${student.first_name || ''} ${student.middle_name || ''}`.trim();
                    const lrn = student.lrn || student.reg_lrn || 'N/A';
                    const grade = student.grade_level || student.grade_level_to_enroll || 'N/A';
                    const section = student.section || 'N/A';
                    const photoPath = student.photo_path;
                    const qrCodePath = student.qr_code_path;
                    const enrolledSchoolYear = (student.enrolled_at || student.created_at) ? new Date(student.enrolled_at || student.created_at).getFullYear() + '-' + (new Date(student.enrolled_at || student.created_at).getFullYear() + 1) : schoolYear;

                    let birthdate = 'N/A';
                    const bdaySource = student.reg_birthdate || student.birthdate;
                    if (bdaySource) {
                        const date = new Date(bdaySource);
                        if (!isNaN(date.getTime())) birthdate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                    }

                    let gName = 'N/A';
                    const gFirst = student.reg_guardian_first || student.guardian_first || '';
                    const gLast = student.reg_guardian_last || student.guardian_last || '';
                    if (gFirst || gLast) gName = `${gFirst} ${student.guardian_middle || ''} ${gLast}`.trim();
                    const gContact = student.reg_guardian_contact || student.guardian_contact || 'N/A';
                    const contactType = (student.reg_id_contact_person || student.id_contact_person || 'guardian').toUpperCase();
                    const addressParts = [student.curr_house_no, student.curr_street, student.curr_barangay, student.curr_city, student.curr_province].filter(p => !!p);
                    const address = addressParts.length > 0 ? addressParts.join(', ') : (student.address || 'N/A');

                    const adviserKey = `${String(grade || '').toLowerCase().trim()}-${String(section || '').toLowerCase().trim()}`;
                    const classAdviser = advisersLookup[adviserKey] || { name: 'CLASS ADVISER', esignature: '' };

                    printWindow.document.write(`
                        <div class="id-batch-item ${i < rows.length - 1 ? 'page-break' : ''}">
                            <div style="display: flex; gap: 32px; justify-content: center; flex-wrap: wrap;">
                                <!-- Front -->
                                <div class="id-card-common id-card-front" style="box-shadow: none; border: 1px solid #cbd5e1;">
                                    <div class="id-header">
                                        <div class="id-logo-container">
                                            <img src="${BASE_URL}/assets/images/school_logo.png" alt="" style="width:100%; height:100%; border-radius:50%;" onerror="this.style.display='none'">
                                        </div>
                                        <div class="id-school-text">
                                            <div class="id-school-name">MALOLOS MARINE FISHERY</div>
                                            <div class="id-school-name">SCHOOL AND LABORATORY</div>
                                            <div class="id-school-sub">City of Malolos, Bulacan</div>
                                        </div>
                                        <div class="id-logo-container">
                                            <img src="${BASE_URL}/assets/images/deped_logo.png" alt="" style="width:100%; height:100%; border-radius:50%;" onerror="this.style.display='none'">
                                        </div>
                                    </div>
                                    <div class="id-divider-band">${grade.includes('Senior') ? 'SENIOR HIGH SCHOOL' : 'JUNIOR HIGH SCHOOL'}</div>
                                    <div class="id-main-body">
                                        <div class="id-photo-frame" style="display: flex; align-items: center; justify-content: center;">
                                            ${photoPath ? `<img src="${getPath(photoPath)}" alt="" style="width:100%; height:100%; object-fit:cover;" onerror="this.parentElement.innerHTML='<span style=\\'font-size:10px; color:#94a3b8; font-weight:700;\\'>NO PHOTO</span>'">` : '<span style="font-size:10px; color:#94a3b8; font-weight:700;">NO PHOTO</span>'}
                                        </div>
                                        <div class="id-student-data">
                                            <div class="id-lrn-pill">LRN: ${lrn}</div>
                                            <div class="id-student-name">${fullName}</div>
                                            <div class="id-student-detail">${grade} - ${section}</div>
                                            <div class="id-student-detail">S.Y. ENROLLED: ${enrolledSchoolYear}</div>
                                        </div>
                                        <div class="id-qr-wrap">
                                            <div class="id-qr-box" style="display:flex; align-items:center; justify-content:center;">
                                                ${qrCodePath ? `<img src="${getPath(qrCodePath)}" alt="" style="width:100%; height:100%;" onerror="this.parentElement.innerHTML='<span style=\\'font-size:10px; color:#94a3b8; font-weight:800;\\'>QR</span>'">` : '<span style="font-size:10px; color:#94a3b8; font-weight:800;">QR</span>'}
                                            </div>
                                            <div class="id-qr-text">VERIFY IDENTITY</div>
                                        </div>
                                    </div>
                                    <div class="id-footer-sy">S.Y. ${schoolYear}</div>
                                </div>

                                <!-- Back -->
                                <div class="id-card-common id-card-back" style="box-shadow: none; border: 1px solid #cbd5e1;">
                                    <div class="id-back-header-blue"></div>
                                    <div class="id-back-body">
                                        <div class="id-back-notice-box">
                                            This card is non-transferable and must be worn at all times within school premises. If found, please return to Malolos Marine Fishery School and Laboratory.
                                        </div>
                                        <div class="id-info-grid">
                                            <div class="id-info-item"><div class="id-info-label">BIRTHDATE</div><div class="id-info-val">${birthdate}</div></div>
                                            <div class="id-info-item"><div class="id-info-label">${contactType}</div><div class="id-info-val">${gName}</div></div>
                                            <div class="id-info-item"><div class="id-info-label">CONTACT</div><div class="id-info-val">${gContact}</div></div>
                                            <div class="id-info-item"><div class="id-info-label">ADDRESS</div><div class="id-info-val">${address}</div></div>
                                        </div>
                                        <div class="id-signature-row">
                                            <div class="id-sig-block">
                                                ${classAdviser.esignature ? `<img src="${getPath(classAdviser.esignature)}" class="id-sig-img" style="mix-blend-mode: multiply;">` : '<div style="height:24px;"></div>'}
                                                <div class="id-sig-line"></div>
                                                <div class="id-sig-name">${classAdviser.name}</div>
                                                <div class="id-sig-title">Class Adviser</div>
                                            </div>
                                            <div class="id-sig-block">
                                                ${principalEsignature ? `<img src="${getPath(principalEsignature)}" class="id-sig-img" style="mix-blend-mode: multiply;">` : '<div style="height:24px;"></div>'}
                                                <div class="id-sig-line"></div>
                                                <div class="id-sig-name">${principalName}</div>
                                                <div class="id-sig-title">${principalStatus}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="id-back-strip">VALID UNTIL THE END OF SCHOOL YEAR</div>
                                </div>
                            </div>
                        </div>
                    `);
                }
            }

            printWindow.document.write('</body></html>');
            printWindow.document.close();
            
            // Auto-print after all images loaded
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            };
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

                const sectionFilter = document.getElementById('sectionFilter').value;
                const section = row.getAttribute('data-section');
                const matchesSection = !sectionFilter || section === sectionFilter;

                if (matchesSearch && matchesGrade && matchesSection) {
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

        // Initialize event listeners (safe even if some controls are missing)
        document.addEventListener('DOMContentLoaded', function () {
            const searchInputEl = document.getElementById('searchInput');
            const gradeFilterEl = document.getElementById('gradeFilter');
            const sectionFilterEl = document.getElementById('sectionFilter');
            const viewToggleEl = document.getElementById('viewToggle');

            if (searchInputEl) {
                searchInputEl.addEventListener('input', filterStudents);
            }

            if (gradeFilterEl) {
                gradeFilterEl.addEventListener('change', filterStudents);
            }

            if (sectionFilterEl) {
                sectionFilterEl.addEventListener('change', filterStudents);
            }

            if (viewToggleEl) {
                viewToggleEl.addEventListener('change', toggleView);
            }
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
                                .id-back-official-signature { text-align: center; margin-bottom: 6px; }
                                .signature-image { mix-blend-mode: multiply; background-color: transparent; border: 0; image-rendering: -webkit-optimize-contrast; }
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
                                    .id-back-official-signature { text-align: center; margin-bottom: 6px; }
                                    .signature-image { mix-blend-mode: multiply; background-color: transparent; border: 0; image-rendering: -webkit-optimize-contrast; }
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
        async function bulkGenerateIDs() {
            if (!confirm('This will generate new YYYY-XXXX IDs and QR codes for all students missing them. Continue?')) return;
            
            const btn = document.getElementById('bulkGenBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Generating...';

            try {
                const resp = await fetch('bulk_id_gen.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({action: 'generate_bulk', limit: 100})
                });
                const res = await resp.json();
                if (res.success) {
                    alert(res.message);
                    location.reload();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch (e) {
                alert('Failed to connect to bulk generator.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    </script>
</body>

</html>