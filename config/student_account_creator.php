<?php
/**
 * Student Account Creator
 * Automatically creates user accounts and enrollment records when registrations are approved
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/student_id_utility.php';

/**
 * Create student account and enrollment when registration is approved
 */
function createStudentAccount($pdo, $registration_id, $approved_by) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get registration details
        $stmt = $pdo->prepare('SELECT * FROM registrations WHERE id = ?');
        $stmt->execute([$registration_id]);
        $registration = $stmt->fetch();
        
        if (!$registration) {
            throw new Exception('Registration not found');
        }
        
        // Generate student ID
        $student_id = generateStudentId($pdo);
        
        // Create student name
        $student_name = trim($registration['first_name'] . ' ' . $registration['last_name']);
        if (!empty($registration['middle_name'])) {
            $student_name = trim($registration['first_name'] . ' ' . $registration['middle_name'] . ' ' . $registration['last_name']);
        }
        
        // Generate default password (first 4 characters of last name + birth year)
        $birth_year = $registration['birthdate'] ? date('Y', strtotime($registration['birthdate'])) : date('Y');
        $default_password = strtolower(substr($registration['last_name'], 0, 4)) . $birth_year;
        
        // Create user account
        $user_stmt = $pdo->prepare('
            INSERT INTO users (username, password_hash, role, first_name, last_name, middle_name, approval_status, approved_by, approved_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $password_hash = password_hash($default_password, PASSWORD_DEFAULT);
        $user_stmt->execute([
            $student_id,
            $password_hash,
            'student',
            $registration['first_name'],
            $registration['last_name'],
            $registration['middle_name'],
            'approved',
            $approved_by
        ]);
        
        $user_id = $pdo->lastInsertId();
        
        // Get current school year
        $current_sy = getCurrentSchoolYear($pdo);
        if (!$current_sy) {
            $current_sy = date('Y') . '-' . (date('Y') + 1);
        }
        
        // Create enrollment record
        $enrollment_stmt = $pdo->prepare('
            INSERT INTO enrollments (
                registration_id, student_id, student_name, grade_level, section, 
                school_year, lrn, birthdate, guardian_first, guardian_last, 
                guardian_contact, address, id_contact_person, enrolled_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        
        // Prepare enrollment data
        $section = 'TBD'; // To be determined by admin/registrar later
        $address = trim(($registration['curr_house_no'] ?: '') . ' ' . 
                       ($registration['curr_street'] ?: '') . ', ' . 
                       ($registration['curr_barangay'] ?: '') . ', ' . 
                       ($registration['curr_city'] ?: '') . ', ' . 
                       ($registration['curr_province'] ?: ''));
        
        $enrollment_stmt->execute([
            $registration_id,
            $student_id,
            $student_name,
            $registration['grade_level_to_enroll'],
            $section,
            $current_sy,
            $registration['lrn'],
            $registration['birthdate'],
            $registration['guardian_first'],
            $registration['guardian_last'],
            $registration['guardian_contact'],
            $address,
            $registration['id_contact_person'] ?: 'guardian'
        ]);
        
        // Generate QR code
        $qr_code_path = generateStudentQRCode($student_id, $student_name);
        
        // Sync to permanent students table
        syncToStudentsTable($pdo, [
            'student_id' => $student_id,
            'first_name' => $registration['first_name'],
            'last_name' => $registration['last_name'],
            'course' => $registration['strand'] ?: $registration['track'] ?: 'N/A', // Assuming strand/track as course
            'year_level' => $registration['grade_level_to_enroll'],
            'qr_code_path' => $qr_code_path
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        return [
            'success' => true,
            'student_id' => $student_id,
            'student_name' => $student_name,
            'default_password' => $default_password,
            'user_id' => $user_id,
            'qr_code_path' => $qr_code_path
        ];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get current school year
 */
function getCurrentSchoolYear($pdo) {
    try {
        $stmt = $pdo->query('SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1');
        $result = $stmt->fetch();
        return $result ? $result['school_year'] : null;
    } catch (Exception $e) {
        return null;
    }
}
?>
