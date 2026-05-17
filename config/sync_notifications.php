<?php
/**
 * Real-time Synchronization and Notification System
 * Ensures registrar and admin actions are immediately reflected across all modules
 */

require_once __DIR__ . '/db.php';

class SyncNotifications {
    private $pdo;
    
    public function __construct() {
        $this->pdo = db_connect();
    }
    
    /**
     * Log an action for real-time synchronization
     */
    public function logAction($action_type, $user_id, $user_role, $details = []) {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO sync_logs (action_type, user_id, user_role, details, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $action_type,
                $user_id,
                $user_role,
                json_encode($details)
            ]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('Sync notification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get recent actions for dashboard updates
     */
    public function getRecentActions($limit = 10) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT sl.*, u.username, u.first_name, u.last_name 
                FROM sync_logs sl 
                LEFT JOIN users u ON sl.user_id = u.id 
                ORDER BY sl.created_at DESC 
                LIMIT ?
            ');
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Get recent actions error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get actions by type for specific notifications
     */
    public function getActionsByType($action_type, $since = null) {
        try {
            $sql = 'SELECT * FROM sync_logs WHERE action_type = ?';
            $params = [$action_type];
            
            if ($since) {
                $sql .= ' AND created_at > ?';
                $params[] = $since;
            }
            
            $sql .= ' ORDER BY created_at DESC';
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Get actions by type error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create sync logs table if it doesn't exist
     */
    public function initializeSyncTable() {
        try {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS sync_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    action_type VARCHAR(50) NOT NULL,
                    user_id INT NULL,
                    user_role VARCHAR(20) NOT NULL,
                    details LONGTEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_action_type (action_type),
                    KEY idx_user_role (user_role),
                    KEY idx_created_at (created_at),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB
            ');
            return true;
        } catch (Exception $e) {
            error_log('Initialize sync table error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Helper functions for common synchronization actions
 */

function logRegistrationAction($user_id, $user_role, $registration_id, $action = 'created') {
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    
    $details = [
        'registration_id' => $registration_id,
        'action' => $action,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return $sync->logAction('registration', $user_id, $user_role, $details);
}

function logEnrollmentAction($user_id, $user_role, $enrollment_id, $student_name, $action = 'created') {
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    
    $details = [
        'enrollment_id' => $enrollment_id,
        'student_name' => $student_name,
        'action' => $action,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return $sync->logAction('enrollment', $user_id, $user_role, $details);
}

function logStudentUpdateAction($user_id, $user_role, $student_id, $field_updated, $action = 'updated') {
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    
    $details = [
        'student_id' => $student_id,
        'field_updated' => $field_updated,
        'action' => $action,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return $sync->logAction('student_update', $user_id, $user_role, $details);
}

function logIDCardAction($user_id, $user_role, $student_id, $action = 'generated') {
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    
    $details = [
        'student_id' => $student_id,
        'action' => $action,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return $sync->logAction('id_card', $user_id, $user_role, $details);
}

function getRecentSyncActions($limit = 5) {
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    return $sync->getRecentActions($limit);
}

function getSyncActionsByType($action_type, $since = null) {
    $sync = new SyncNotifications();
    $sync->initializeSyncTable();
    return $sync->getActionsByType($action_type, $since);
}
?>
