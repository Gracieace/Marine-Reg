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

// Verify the report belongs to the current teacher
$stmt = $pdo->prepare("SELECT * FROM sf1_reports WHERE id = ? AND teacher_id = ?");
$stmt->execute([$report_id, $teacher_id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: ../reports.php');
    exit;
}

// Delete the report (cascade will handle related records)
try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("DELETE FROM sf1_reports WHERE id = ?");
    $stmt->execute([$report_id]);
    
    $pdo->commit();
    
    // Redirect with success message
    $redirect_url = 'sf1_form.php?deleted=1&grade_level=' . urlencode($report['grade_level']) . '&section=' . urlencode($report['section']);
    header('Location: ' . $redirect_url);
    exit;
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    // Redirect with error message
    $error_url = 'sf1_form.php?error=' . urlencode('Error deleting report: ' . $e->getMessage()) . '&grade_level=' . urlencode($report['grade_level']) . '&section=' . urlencode($report['section']);
    header('Location: ' . $error_url);
    exit;
}
?>
