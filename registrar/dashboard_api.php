<?php
/**
 * Dashboard API for real-time data refresh
 * Returns JSON data for AJAX requests
 */

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sync_notifications.php';
require_once __DIR__ . '/../config/dashboard_sync.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
auth_require_role(['registrar', 'admin']);

// Get current user role
$current_user_role = $_SESSION['user']['role'] ?? '';

try {
    // Get fresh dashboard data
    $dashboard_data = getSynchronizedDashboardData($current_user_role);
    $sync_status = getSynchronizationStatus();
    $recent_activities = getSynchronizedRecentActivity($current_user_role, 5);
    
    // Check if there was an error
    if (isset($dashboard_data['error'])) {
        throw new Exception($dashboard_data['error']);
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'total_students' => $dashboard_data['total_students'] ?? 0,
            'monthly_enrollments' => $dashboard_data['monthly_enrollments'] ?? 0,
            'pending_registrations' => $dashboard_data['pending_registrations'] ?? 0,
            'total_registrations' => $dashboard_data['total_registrations'] ?? 0,
            'monthly_registrations' => $dashboard_data['monthly_registrations'] ?? 0,
            'id_cards_generated' => $dashboard_data['id_cards_generated'] ?? 0,
            'students_without_id' => $dashboard_data['students_without_id'] ?? 0,
            'enrollment_by_grade' => $dashboard_data['enrollment_by_grade'] ?? [],
            'recent_enrollments' => $dashboard_data['recent_enrollments'] ?? [],
            'recent_registrations' => $dashboard_data['recent_registrations'] ?? [],
            'enrollment_by_strand' => $dashboard_data['enrollment_by_strand'] ?? [],
            'registration_by_grade' => $dashboard_data['registration_by_grade'] ?? [],
            'students_by_gender' => $dashboard_data['students_by_gender'] ?? []
        ],
        'sync_status' => $sync_status,
        'recent_activities' => $recent_activities,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Return error response
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    http_response_code(500);
    echo json_encode($response);
}
?>
