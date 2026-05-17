<?php
/**
 * Admin Dashboard API for real-time data refresh
 * Returns JSON data for the upper statistics cards
 */

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/dashboard_sync.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
auth_require_role('admin');

try {
    $pdo = db_connect();
    
    // Get fresh dashboard data using existing sync utility
    $dashboard_data = getSynchronizedDashboardData('admin');
    
    if (isset($dashboard_data['error'])) {
        throw new Exception($dashboard_data['error']);
    }
    
    // Additional queries for complete dashboard update
    // Faculty count
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM users WHERE role = "teacher" AND approval_status = "approved"');
    $teaching_count = $stmt->fetchColumn() ?: 0;
    
    // Support staff count
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM employees WHERE is_active = 1 AND lower(position_title) NOT LIKE "%teacher%"');
    $non_teaching_count = $stmt->fetchColumn() ?: 0;
    
    // Student Gender (from registered students)
    $stmt = $pdo->query('SELECT sex, COUNT(*) as total FROM registrations GROUP BY sex');
    $student_gender_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $student_male = (int)($student_gender_raw['Male'] ?? $student_gender_raw['M'] ?? 0);
    $student_female = (int)($student_gender_raw['Female'] ?? $student_gender_raw['F'] ?? 0);
    
    // Faculty Gender (from registered accounts - Teaching staff only)
    $stmt = $pdo->query('SELECT sex, COUNT(*) as total FROM users WHERE role = "teacher" AND approval_status = "approved" GROUP BY sex');
    $faculty_gender_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $faculty_male = (int)($faculty_gender_raw['Male'] ?? $faculty_gender_raw['M'] ?? 0);
    $faculty_female = (int)($faculty_gender_raw['Female'] ?? $faculty_gender_raw['F'] ?? 0);

    // Personnel Distribution (Dynamic roles)
    $stmt = $pdo->query('SELECT role, COUNT(*) as total FROM employees GROUP BY role');
    $personnel_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // Recent Enrollments (HTML strings to replace the grid)
    $stmt = $pdo->query('SELECT student_name, grade_level, section, enrolled_at FROM enrollments ORDER BY enrolled_at DESC LIMIT 5');
    $recent_enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Enrollment by grade (structural)
    $stmt = $pdo->query('SELECT DISTINCT grade_level FROM sections ORDER BY grade_level');
    $all_levels = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    $stmt = $pdo->query('SELECT grade_level, COUNT(*) as count FROM enrollments GROUP BY grade_level');
    $enrolled_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    
    $enrollment_by_grade = [];
    foreach ($all_levels as $lvl) {
        $enrollment_by_grade[] = [
            'grade_level' => $lvl,
            'count' => (int)($enrolled_raw[$lvl] ?? 0)
        ];
    }
    if (empty($enrollment_by_grade)) {
        $enrollment_by_grade = [['grade_level' => 'N/A', 'count' => 0]];
    }

    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'total_enrolled' => (int)($pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn() ?: 0),
            'total_registrations' => (int)($pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn() ?: 0),
            'monthly_enrollments' => (int)($dashboard_data['monthly_enrollments'] ?? 0),
            'total_teachers' => (int)($dashboard_data['total_teachers'] ?? 0),
            'pending_personnel' => (int)($pdo->query('SELECT COUNT(*) FROM users WHERE approval_status = "pending" AND role IN ("teacher", "registrar")')->fetchColumn()),
            'active_levels' => count($all_levels),
            'charts' => [
                'enrollment' => [
                    'labels' => array_column($enrollment_by_grade, 'grade_level'),
                    'data' => array_column($enrollment_by_grade, 'count')
                ],
                'studentGender' => [$student_male, $student_female],
                'facultyGender' => [$faculty_male, $faculty_female],
                'personnel' => [
                    'labels' => array_keys($personnel_raw),
                    'data' => array_values($personnel_raw)
                ]
            ]
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
