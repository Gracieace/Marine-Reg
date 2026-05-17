<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';

auth_require_role(['registrar']);

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Registration ID required']);
    exit;
}

$registration_id = intval($_GET['id']);

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM registrations WHERE id = ?');
    $stmt->execute([$registration_id]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        echo json_encode(['success' => false, 'message' => 'Registration not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'registration' => $registration]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
