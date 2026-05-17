<?php
/**
 * Enhanced Dashboard with Real-time Synchronization Status
 * Shows live updates between registrar and admin modules
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync_notifications.php';

function getSynchronizedDashboardData($user_role = null) {
    $pdo = db_connect();
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    
    try {
        // Get current school year
        $current_sy = get_current_school_year($pdo);
        
        // If no current school year, set a default or handle gracefully
        if (!$current_sy) {
            error_log('No current school year found, using default');
            $current_sy = date('Y') . '-' . (date('Y') + 1); // Default to current academic year
        }
        
        // Get comprehensive statistics that both registrar and admin can see
        $stats = [
            'total_students' => 0,
            'total_registrations' => 0,
            'pending_registrations' => 0,
            'total_teachers' => 0,
            'recent_enrollments' => [],
            'recent_registrations' => [],
            'recent_actions' => [],
            'sync_status' => 'active',
            'last_sync' => date('Y-m-d H:i:s')
        ];
        
        // Total students (enrolled) - System-wide count as requested
        $stmt = $pdo->query('SELECT COUNT(*) as total_students FROM enrollments');
        $stats['total_students'] = $stmt->fetch()['total_students'];
        
        // Total registrations
        $stmt = $pdo->query('SELECT COUNT(*) as total_registrations FROM registrations');
        $stats['total_registrations'] = $stmt->fetch()['total_registrations'];
        
        // Pending Personnel (Awaiting approval) - teaching and non-teaching
        $stmt = $pdo->query('
            SELECT COUNT(*) as pending_personnel 
            FROM users 
            WHERE approval_status = "pending" 
            AND role IN ("teacher", "registrar", "employee")
        ');
        $stats['pending_registrations'] = $stmt->fetch()['pending_personnel'] ?? 0;
        
        // Total teachers
        $stmt = $pdo->query('SELECT COUNT(*) as total_teachers FROM users WHERE role = "teacher"');
        $stats['total_teachers'] = $stmt->fetch()['total_teachers'];
        
        // ID cards generated (all time)
        $stmt = $pdo->query('
            SELECT COUNT(*) as id_cards_generated 
            FROM enrollments 
            WHERE qr_code_path IS NOT NULL AND qr_code_path != ""
        ');
        $stats['id_cards_generated'] = $stmt->fetch()['id_cards_generated'];
        
        $stats['students_without_id'] = max(0, $stats['total_students'] - $stats['id_cards_generated']);
        
        // Recent enrollments (last 5)
        $stmt = $pdo->prepare('
            SELECT e.student_name, e.grade_level, e.section, e.enrolled_at, e.student_id,
                   r.first_name, r.last_name, r.middle_name
            FROM enrollments e 
            LEFT JOIN registrations r ON e.registration_id = r.id 
            WHERE e.school_year = ?
            ORDER BY e.enrolled_at DESC 
            LIMIT 5
        ');
        $stmt->execute([$current_sy]);
        $stats['recent_enrollments'] = $stmt->fetchAll();
        
        // Recent registrations (last 5)
        $stmt = $pdo->query('
            SELECT id, CONCAT(first_name, " ", last_name) as student_name, 
                   grade_level_to_enroll, created_at, lrn
            FROM registrations 
            ORDER BY created_at DESC 
            LIMIT 5
        ');
        $stats['recent_registrations'] = $stmt->fetchAll();
        
        // Recent synchronization actions
        $stats['recent_actions'] = getRecentSyncActions(5);
        
        // Get ALL defined grade levels from sections for a consistent structural overview
        $stmt = $pdo->query('SELECT DISTINCT grade_level FROM sections ORDER BY grade_level');
        $all_levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Enrollment by grade level (merged with all_levels to include zeros)
        $stmt = $pdo->query('
            SELECT grade_level, COUNT(*) as count 
            FROM enrollments 
            GROUP BY grade_level
        ');
        $enrolled_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stats['enrollment_by_grade'] = [];
        foreach ($all_levels as $lvl) {
            $stats['enrollment_by_grade'][] = [
                'grade_level' => $lvl,
                'count' => (int)($enrolled_raw[$lvl] ?? 0)
            ];
        }
        
        // Final fallback if no sections defined
        if (empty($stats['enrollment_by_grade'])) {
             $stats['enrollment_by_grade'] = [['grade_level' => 'N/A', 'count' => 0]];
        }
        
        // Registration by grade level
        $stmt = $pdo->query('
            SELECT grade_level_to_enroll as grade_level, COUNT(*) as count 
            FROM registrations 
            GROUP BY grade_level_to_enroll 
            ORDER BY grade_level_to_enroll
        ');
        $stats['registration_by_grade'] = $stmt->fetchAll();
        
        // Students by gender
        $stmt = $pdo->query('
            SELECT r.sex, COUNT(*) as count 
            FROM registrations r 
            JOIN enrollments e ON r.id = e.registration_id 
            WHERE r.sex IS NOT NULL
            GROUP BY r.sex
        ');
        $stats['students_by_gender'] = $stmt->fetchAll();
        
        // Enrollment by strand (for senior high)
        $stmt = $pdo->query('
            SELECT r.strand, COUNT(*) as count 
            FROM registrations r 
            JOIN enrollments e ON r.id = e.registration_id 
            WHERE r.strand IS NOT NULL
            GROUP BY r.strand 
            ORDER BY count DESC
        ');
        $stats['enrollment_by_strand'] = $stmt->fetchAll();
        
        // Monthly statistics
        $stmt = $pdo->query('
            SELECT COUNT(*) as monthly_enrollments 
            FROM enrollments 
            WHERE MONTH(enrolled_at) = MONTH(CURRENT_DATE()) 
            AND YEAR(enrolled_at) = YEAR(CURRENT_DATE())
        ');
        $stats['monthly_enrollments'] = $stmt->fetch()['monthly_enrollments'];
        
        $stmt = $pdo->query('
            SELECT COUNT(*) as monthly_registrations 
            FROM registrations 
            WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
            AND YEAR(created_at) = YEAR(CURRENT_DATE())
        ');
        $stats['monthly_registrations'] = $stmt->fetch()['monthly_registrations'];
        
        
        // Log successful data retrieval for debugging
        error_log('Dashboard data retrieved successfully - Total students: ' . $stats['total_students'] . ', Total registrations: ' . $stats['total_registrations']);
        
        return $stats;
        
    } catch (Exception $e) {
        error_log('Dashboard sync data error: ' . $e->getMessage());
        return [
            'error' => 'Failed to load dashboard data',
            'sync_status' => 'error',
            'last_sync' => date('Y-m-d H:i:s')
        ];
    }
}

function getSynchronizedRecentActivity($user_role = null, $limit = 10) {
    $pdo = db_connect();
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    
    try {
        $activities = [];
        
        // Get recent sync actions
        $recent_actions = getRecentSyncActions($limit);
        
        foreach ($recent_actions as $action) {
            $details = json_decode($action['details'], true);
            $user_name = $action['first_name'] . ' ' . $action['last_name'];
            
            $activity = [
                'type' => $action['action_type'],
                'user' => $user_name,
                'user_role' => $action['user_role'],
                'timestamp' => $action['created_at'],
                'details' => $details
            ];
            
            // Format activity description based on type
            switch ($action['action_type']) {
                case 'registration':
                    $activity['description'] = "New registration created for student ID: " . ($details['registration_id'] ?? 'N/A');
                    $activity['icon'] = '📋';
                    break;
                case 'enrollment':
                    $activity['description'] = "Student enrolled: " . ($details['student_name'] ?? 'Unknown');
                    $activity['icon'] = '📝';
                    break;
                case 'student_update':
                    $activity['description'] = "Student record updated: " . ($details['field_updated'] ?? 'Unknown field');
                    $activity['icon'] = '✏️';
                    break;
                case 'id_card':
                    $activity['description'] = "ID card generated for student: " . ($details['student_id'] ?? 'Unknown');
                    $activity['icon'] = '🪪';
                    break;
                default:
                    $activity['description'] = "System action performed";
                    $activity['icon'] = '⚙️';
            }
            
            $activities[] = $activity;
        }
        
        return $activities;
        
    } catch (Exception $e) {
        error_log('Recent activity sync error: ' . $e->getMessage());
        return [];
    }
}

function getSynchronizationStatus() {
    $pdo = db_connect();
    
    try {
        // Check database connectivity
        $pdo->query('SELECT 1');
        
        // Check if sync table exists and is accessible
        $sync = new SyncNotifications();
        $sync->initializeSyncTable();
        
        // Get last sync timestamp
        $stmt = $pdo->query('SELECT MAX(created_at) as last_sync FROM sync_logs');
        $last_sync = $stmt->fetch()['last_sync'];
        
        return [
            'status' => 'active',
            'database_connected' => true,
            'sync_table_ready' => true,
            'last_sync' => $last_sync ?: 'Never',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'database_connected' => false,
            'sync_table_ready' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

?>
