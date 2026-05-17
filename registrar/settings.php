<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once __DIR__ . '/../config/db.php';

$pdo = db_connect();
initialize_schema($pdo);
$message = '';
$error = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$sessionUsername = $_SESSION['user']['username'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'batch_update_settings':
                    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
                        $pdo->beginTransaction();
                        try {
                            foreach ($_POST['settings'] as $key => $value) {
                                $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
                                $stmt->execute([$key, $value, $value]);
                            }
                            $pdo->commit();
                            $message = 'Settings updated successfully!';
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error = 'Failed to update settings: ' . $e->getMessage();
                        }
                    }
                    break;
                case 'change_password':
                    if ($_POST['new_password'] !== $_POST['confirm_password']) {
                        $error = 'Passwords do not match!';
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
                        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $stmt->execute([$hash, $sessionUsername]);
                        $message = 'Password changed successfully!';
                    }
                    break;
                case 'upload_logo':
                    if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === 0) {
                        $file_ext = strtolower(pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                        if (in_array($file_ext, $allowed)) {
                            $target_dir = __DIR__ . '/../assets/images/';
                            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                            $target_file = $target_dir . 'school_logo.' . $file_ext;
                            if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $target_file)) {
                                $logo_url = '/assets/images/school_logo.' . $file_ext;
                                $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES ("school_logo", ?) ON DUPLICATE KEY UPDATE setting_value = ?');
                                $stmt->execute([$logo_url, $logo_url]);
                                $message = 'Logo updated!';
                            }
                        }
                    }
                    break;
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Fetch settings
$settings = [];
$stmt = $pdo->query('SELECT * FROM system_settings');
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

function getSetting($key, $settings) { return $settings[$key] ?? ''; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registrar Settings</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #0d47a1; --surface: #ffffff; --background: #f1f5f9; --text-main: #0f172a; --border: #e2e8f0; --radius: 0.5rem; }
        body { background-color: var(--background); color: var(--text-main); font-family: 'Inter', sans-serif; }
        .main-content { margin-top: var(--header-height); padding: 30px; }
        .card { background: white; padding: 24px; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; }
        .tabs { display: flex; gap: 10px; border-bottom: 2px solid var(--border); margin-bottom: 24px; }
        .tab-btn { padding: 10px 20px; cursor: pointer; border: none; background: none; color: #64748b; font-weight: 600; }
        .tab-btn.active { color: var(--primary); border-bottom: 2px solid var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); }
        .btn { padding: 10px 20px; border-radius: var(--radius); border: none; cursor: pointer; font-weight: 600; }
        .btn-primary { background: var(--primary); color: white; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="main-content">
        <h1>Registrar Settings</h1>
        <p>Manage school information and account preferences.</p>

        <?php if ($message): ?><div style="padding:15px; background:#dcfce7; color:#166534; border-radius:6px; margin-bottom:20px;"><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div style="padding:15px; background:#fee2e2; color:#991b1b; border-radius:6px; margin-bottom:20px;"><?= $error ?></div><?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" onclick="showTab('general')">General</button>
            <button class="tab-btn" onclick="showTab('account')">Account</button>
        </div>

        <div id="general" class="tab-content active">
            <div class="card">
                <h3>School Information</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="batch_update_settings">
                    <div class="form-group">
                        <label>School Name</label>
                        <input type="text" name="settings[school_name]" class="form-control" value="<?= htmlspecialchars(getSetting('school_name', $settings)) ?>">
                    </div>
                    <div class="form-group">
                        <label>School ID</label>
                        <input type="text" name="settings[school_id]" class="form-control" value="<?= htmlspecialchars(getSetting('school_id', $settings)) ?>">
                    </div>
                    <div class="form-group">
                        <label>School Address</label>
                        <input type="text" name="settings[school_address]" class="form-control" value="<?= htmlspecialchars(getSetting('school_address', $settings)) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Info</button>
                </form>
            </div>
        </div>

        <div id="account" class="tab-content">
            <div class="card">
                <h3>Change Password</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showTab(id) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector(`[onclick="showTab('${id}')"]`).classList.add('active');
            document.getElementById(id).classList.add('active');
        }
    </script>
</body>
</html>
