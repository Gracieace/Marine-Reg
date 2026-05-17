<?php
// Minimal session and role utilities
require_once __DIR__ . '/../config/app.php';

function auth_login($username, $role, $id = null)
{
	$_SESSION['user'] = [
		'id' => $id,
		'username' => $username,
		'role' => $role,
	];
    $_SESSION['last_active'] = time();
    log_activity('User Login', "Logged in as $username");
}

function auth_login_db($username, $role, $id = null)
{
	$_SESSION['user'] = [
		'id' => $id,
		'username' => $username,
		'role' => $role,
	];
    $_SESSION['last_active'] = time();
    log_activity('User Login', "Logged in as $username");
}

function auth_logout()
{
	$_SESSION = [];
	if (ini_get("session.use_cookies")) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', [
			'expires' => time() - 42000,
			'path' => $params['path'],
			'domain' => $params['domain'],
			'secure' => $params['secure'],
			'httponly' => $params['httponly'],
			'samesite' => $params['samesite'] ?? 'Lax'
		]);
	}
	session_destroy();
}

function auth_user()
{
	return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function auth_role()
{
	$user = auth_user();
	return $user ? $user['role'] : null;
}

function auth_require_role($allowedRoles)
{
	if (!is_array($allowedRoles)) {
		$allowedRoles = [$allowedRoles];
	}
	$role = auth_role();
    
    // Check session timeout
    if (isset($_SESSION['last_active'])) {
        try {
            require_once __DIR__ . '/../config/db.php';
            $pdo = db_connect();
            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = "session_timeout"');
            $stmt->execute();
            $timeout_min = (int)($stmt->fetchColumn() ?: 30);
            
            if (time() - $_SESSION['last_active'] > ($timeout_min * 60)) {
                log_activity('Session Timeout', "Session expired after $timeout_min minutes");
                auth_logout();
                header('Location: ' . url_for('index.php?timeout=1'));
                exit;
            }
            $_SESSION['last_active'] = time(); // Update activity
        } catch (Exception $e) {}
    }

	if (!$role || !in_array($role, $allowedRoles, true)) {
		header('Location: ' . url_for('index.php'));
		exit;
	}
}

function auth_is($role)
{
	return auth_role() === $role;
}

/**
 * Log user activity to the audit trail
 */
function log_activity($action, $details = null)
{
    try {
        require_once __DIR__ . '/../config/db.php';
        $pdo = db_connect();
        
        $user = auth_user();
        $user_id = $user ? $user['id'] : null;
        $username = $user ? $user['username'] : 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $pdo->prepare('INSERT INTO audit_trail (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user_id, $username, $action, $details, $ip]);
    } catch (Exception $e) {
        // Silently fail logging to prevent system crash if DB is down
        error_log('Audit Trail Error: ' . $e->getMessage());
    }
}


