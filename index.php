<?php
/* ===============================
   BOOTSTRAP
=============================== */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

/* ===============================
   AUTO REDIRECT IF LOGGED IN
=============================== */
if (!empty($_SESSION['user']['role'])) {
    redirect_for_role($_SESSION['user']['role']);
}

/* ===============================
   LOGIN ATTEMPT CONFIGURATION
=============================== */
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION_MINUTES', 5);

/* ===============================
   STATE
=============================== */
$error = '';
$username = '';
$lockout_remaining = 0;
$attempts_remaining = MAX_LOGIN_ATTEMPTS;
$signup_pending = isset($_GET['signup']) && $_GET['signup'] === 'pending';

/* ===============================
   HELPER FUNCTIONS
=============================== */
function get_client_ip()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function ensure_login_attempts_table($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        username VARCHAR(100) NOT NULL,
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ip_username (ip_address, username),
        KEY idx_attempt_time (attempt_time)
    ) ENGINE=InnoDB');
}

function get_failed_attempts($pdo, $ip, $username)
{
    $lockout_time = (int) LOCKOUT_DURATION_MINUTES;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as attempts FROM login_attempts 
         WHERE ip_address = ? AND username = ? 
         AND attempt_time > DATE_SUB(NOW(), INTERVAL {$lockout_time} MINUTE)"
    );
    $stmt->execute([$ip, $username]);
    return (int) $stmt->fetchColumn();
}

function get_lockout_remaining($pdo, $ip, $username)
{
    $lockout_time = (int) LOCKOUT_DURATION_MINUTES;
    $stmt = $pdo->prepare(
        "SELECT attempt_time FROM login_attempts 
         WHERE ip_address = ? AND username = ? 
         AND attempt_time > DATE_SUB(NOW(), INTERVAL {$lockout_time} MINUTE)
         ORDER BY attempt_time DESC 
         LIMIT 1"
    );
    $stmt->execute([$ip, $username]);
    $last_attempt = $stmt->fetchColumn();

    if ($last_attempt) {
        $last_time = strtotime($last_attempt);
        $unlock_time = $last_time + ($lockout_time * 60);
        $remaining = $unlock_time - time();
        return max(0, $remaining);
    }
    return 0;
}

function record_failed_attempt($pdo, $ip, $username)
{
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
    $stmt->execute([$ip, $username]);
}

function clear_login_attempts($pdo, $ip, $username)
{
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND username = ?");
    $stmt->execute([$ip, $username]);
}

function cleanup_old_attempts($pdo)
{
    // Clean up attempts older than 24 hours
    $pdo->exec("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

/* ===============================
   LOGIN HANDLER
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $pdo = db_connect();

            // Ensure login_attempts table exists
            ensure_login_attempts_table($pdo);

            // Cleanup old attempts periodically
            cleanup_old_attempts($pdo);

            $client_ip = get_client_ip();
            $failed_attempts = get_failed_attempts($pdo, $client_ip, $username);

            // Check if user is locked out
            if ($failed_attempts >= MAX_LOGIN_ATTEMPTS) {
                $lockout_remaining = get_lockout_remaining($pdo, $client_ip, $username);
                $minutes = ceil($lockout_remaining / 60);
                $seconds = $lockout_remaining % 60;

                if ($lockout_remaining > 60) {
                    $error = "Too many failed attempts. Please wait {$minutes} minute(s) before trying again.";
                } else {
                    $error = "Too many failed attempts. Please wait {$lockout_remaining} second(s) before trying again.";
                }
            } else {

                $stmt = $pdo->prepare(
                    "SELECT id, username, password_hash, role, approval_status
                     FROM users
                     WHERE username = ?
                     LIMIT 1"
                );
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    // Record failed attempt
                    record_failed_attempt($pdo, $client_ip, $username);
                    $failed_attempts++;
                    $attempts_remaining = MAX_LOGIN_ATTEMPTS - $failed_attempts;

                    if ($attempts_remaining > 0) {
                        $error = "Invalid username or password. {$attempts_remaining} attempt(s) remaining.";
                    } else {
                        $lockout_remaining = LOCKOUT_DURATION_MINUTES * 60;
                        $error = "Invalid username or password. Account locked for " . LOCKOUT_DURATION_MINUTES . " minute(s).";
                    }
                } elseif (
                    $user['role'] !== 'admin' &&
                    $user['approval_status'] !== 'approved'
                ) {
                    $error = 'Your account is pending approval.';
                } else {
                    // Clear failed attempts on successful login
                    clear_login_attempts($pdo, $client_ip, $username);

                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role']
                    ];

                    /* Update login + activity */
                    try {
                        $upd = $pdo->prepare(
                            "UPDATE users
                             SET last_activity = NOW()
                             WHERE id = ?"
                        );
                        $upd->execute([$user['id']]);
                    } catch (Exception $ignored) {}

                    /* Redirect */
                    redirect_for_role($user['role']);
                }
            }

        } catch (Throwable $e) {
            $error = 'Server error. Please try again later.';
            error_log('Login error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MMFS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-glow: rgba(37, 99, 235, 0.5);
            --bg-color: #0f172a;
            --card-glass: rgba(255, 255, 255, 0.8);
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        body {
            margin: 0;
            background: #f8fafc;
            background-image: 
                radial-gradient(at 0% 0%, hsla(217, 91%, 60%, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, hsla(217, 91%, 60%, 0.1) 0px, transparent 50%);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            overflow-x: hidden;
        }

        /* Decorative background elements */
        body::before, body::after {
            content: '';
            position: fixed;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            z-index: -1;
            filter: blur(80px);
            opacity: 0.4;
        }
        body::before {
            background: var(--primary);
            top: -100px;
            left: -100px;
            animation: drift 20s infinite alternate linear;
        }
        body::after {
            background: #6366f1;
            bottom: -100px;
            right: -100px;
            animation: drift 25s infinite alternate-reverse linear;
        }

        @keyframes drift {
            from { transform: translate(0, 0); }
            to { transform: translate(100px, 100px); }
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 10;
        }

        .header-section {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-logo {
            width: 90px;
            height: 90px;
            object-fit: contain;
            margin-bottom: 20px;
            filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.1));
            transition: transform 0.3s ease;
        }
        .brand-logo:hover {
            transform: scale(1.05) rotate(5deg);
        }

        .school-name {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }

        .login-subtitle {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            margin: 8px 0 0;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }

        .login-card {
            background: var(--card-glass);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 40px;
            border-radius: 24px;
            box-shadow: 
                0 20px 25px -5px rgba(0, 0, 0, 0.1),
                0 10px 10px -5px rgba(0, 0, 0, 0.04),
                inset 0 0 0 1px rgba(255, 255, 255, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .input-container {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            background: rgba(248, 250, 252, 0.8);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 500;
            color: #1e293b;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            transform: translateY(-1px);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 8px;
            box-shadow: 0 10px 15px -3px var(--primary-glow);
        }

        .btn-submit:hover {
            background: var(--primary-hover, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px var(--primary-glow);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-secondary {
            display: block;
            width: 100%;
            padding: 14px;
            background: transparent;
            color: var(--text-muted);
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 16px;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.5);
            border-color: var(--text-muted);
            color: #0f172a;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.4s ease-out;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .footer-links {
            text-align: center;
            margin-top: 32px;
        }

        .footer-links a {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        /* Mobile Adjustments */
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }
            .school-name {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

    <div class="login-wrapper">
        <div class="header-section">
            <img src="assets/images/school_logo.png" alt="School Logo" class="brand-logo">
            <h1 class="school-name">Malolos Marine Fishery School and Laboratory</h1>
            <p class="login-subtitle">Portal</p>
        </div>

        <div class="login-card">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($signup_pending): ?>
                <div class="alert" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <span>Account created! Please wait for admin approval.</span>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($username) ?>"
                        required autofocus autocomplete="username">
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required
                        autocomplete="current-password">
                </div>

                <button type="submit" class="btn-submit">Sign In</button>
                <a href="signup.php" class="btn-secondary">Create Account</a>
            </form>
        </div>

        <div class="footer-links">
            <a href="#">Forgot password?</a>
        </div>
    </div>

</body>

</html>