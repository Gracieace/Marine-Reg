<?php
/**
 * Bulk Student ID & QR Code Generator
 * Handles generation for existing students missing IDs
 */

require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin']);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/student_id_utility.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db_connect();
    $action = $_POST['action'] ?? '';

    if ($action === 'check_pending') {
        // Count students in enrollments who need IDs (NULL or legacy format)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id IS NULL OR student_id NOT LIKE '20%'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    if ($action === 'generate_bulk') {
        try {
            $pdo->beginTransaction();

            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
            
            // Fetch students needing IDs
            $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE student_id IS NULL OR student_id NOT LIKE '20%' LIMIT ?");
            $stmt->execute([$limit]);
            $students = $stmt->fetchAll();

            $generated = 0;
            foreach ($students as $student) {
                // Generate new ID
                $newId = generateStudentId($pdo, $student['school_year'] ?? null);
                $qrPath = generateStudentQRCode($newId, $student['student_name']);

                // Update enrollment
                $update = $pdo->prepare("UPDATE enrollments SET student_id = ?, qr_code_path = ? WHERE id = ?");
                $update->execute([$newId, $qrPath, $student['id']]);

                // Sync to students table
                syncToStudentsTable($pdo, [
                    'student_id' => $newId,
                    'first_name' => $student['student_name'],
                    'last_name' => '',
                    'course' => $student['grade_level'],
                    'year_level' => $student['grade_level'],
                    'qr_code_path' => $qrPath
                ]);

                $generated++;
            }

            $pdo->commit();
            echo json_encode([
                'success' => true, 
                'message' => "Successfully generated $generated IDs and QR codes.",
                'remaining' => count($students) == $limit // Hint there might be more
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>
