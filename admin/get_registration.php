<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin', 'teacher']);

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid registration ID']);
    exit;
}

$registration_id = intval($_GET['id']);

try {
    $pdo = db_connect();
    $sql = "SELECT * FROM registrations WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $registration_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($registration) {
        echo json_encode(['success' => true, 'registration' => $registration]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registration not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
