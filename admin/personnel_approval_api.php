<?php
/**
 * Personnel Approval API for real-time data refresh
 * Returns JSON data for pending teacher and registrar accounts
 */

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
auth_require_role(['registrar', 'admin']);

try {
    $pdo = db_connect();
    
    // Get pending users with combined teacher info
    // We pad the user ID to match the TCH-xxxx format used in the teacher_approval.php logic
    $stmt = $pdo->query('
        SELECT u.id, u.username, u.role, u.created_at, u.first_name, u.last_name, u.middle_name,
               CONCAT(u.first_name, " ", u.last_name) as full_name,
               t.department, t.specialization
        FROM users u 
        LEFT JOIN teachers t ON t.teacher_id = CONCAT("TCH-", LPAD(u.id, 4, "0"))
        WHERE u.approval_status = "pending" AND u.role IN ("teacher", "registrar") 
        ORDER BY u.created_at ASC
    ');
    $pending_users = $stmt->fetchAll();
    
    // Format numeric IDs and handle nulls
    foreach ($pending_users as &$user) {
        $user['id'] = (int)$user['id'];
        $user['department'] = $user['department'] ?? 'N/A';
        $user['specialization'] = $user['specialization'] ?? 'N/A';
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'pending_users' => $pending_users,
            'count' => count($pending_users)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
