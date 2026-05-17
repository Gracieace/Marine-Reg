<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once __DIR__ . '/../config/db.php';

$pdo = db_connect();
$userId = $_SESSION['user']['id'];

// Initialize messages
$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_info') {
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$first_name, $middle_name, $last_name, $email, $userId]);
            
            // Update session data
            $_SESSION['user']['first_name'] = $first_name;
            $_SESSION['user']['last_name'] = $last_name;
            
            $success = "Profile information updated successfully!";
        } 
        elseif ($action === 'update_password') {
            $current_pw = $_POST['current_password'] ?? '';
            $new_pw = $_POST['new_password'] ?? '';
            $confirm_pw = $_POST['confirm_password'] ?? '';
            
            // Fetch current hash for verification
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $current_hash = $stmt->fetchColumn();
            
            if (password_verify($current_pw, $current_hash)) {
                if ($new_pw === $confirm_pw) {
                    if (strlen($new_pw) >= 8) {
                        $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        $upd->execute([$new_hash, $userId]);
                        $success = "Password changed successfully!";
                    } else {
                        $error = "New password must be at least 8 characters long.";
                    }
                } else {
                    $error = "New passwords do not match.";
                }
            } else {
                $error = "Incorrect current password.";
            }
        }
    } catch (Exception $e) {
        $error = "An error occurred: " . $e->getMessage();
    }
}

// Fetch fresh user data for display
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Profile - Registrar Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --profile-bg: #f8fafc; --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        body { background-color: var(--profile-bg); font-family: 'Inter', sans-serif; color: #1e293b; }
        .main-content { padding: 2rem; max-width: 1000px; margin: 0 auto; margin-top: 70px; }
        .card { background: white; border-radius: 1rem; box-shadow: var(--card-shadow); border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; background: #fafafa; }
        .card-header h2 { font-size: 1.125rem; font-weight: 600; margin: 0; }
        .card-body { padding: 1.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; }
        .form-control { width: 100%; padding: 0.625rem 0.875rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-sizing: border-box; }
        .btn { padding: 0.625rem 1.25rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer; border: none; }
        .btn-primary { background: #0f172a; color: white; }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>
    
    <div class="main-content">
        <h1>Account Settings</h1>
        <p style="color: #64748b;">Manage your profile information and account security.</p>
        
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><h2>Personal Information</h2></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_info">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group"><label>First Name</label><input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"></div>
                        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"></div>
                    </div>
                    <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>"></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>"></div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Security</h2></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_password">
                    <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control" required></div>
                        <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
