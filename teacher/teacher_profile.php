<?php
require_once dirname(__DIR__) . '/auth/auth.php';
auth_require_role(['teacher', 'admin']);

require_once dirname(__DIR__) . '/config/db.php';
$pdo = db_connect();
$userId = $_SESSION['user']['id'];

// Ensure new columns exist for SF7 data
$required_columns = [
    'tin' => 'VARCHAR(50)',
    'fund_source' => 'VARCHAR(100)',
    'appointment_status' => 'VARCHAR(100)',
    'educational_degree' => 'VARCHAR(255)',
    'major_specialization' => 'VARCHAR(255)',
    'minor_specialization' => 'VARCHAR(255)',
    'salary_grade' => 'INT',
    'position_title' => 'VARCHAR(150)',
    'e_signature' => 'VARCHAR(255)'
];

foreach ($required_columns as $col => $type) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN $col $type NULL");
    } catch (Exception $e) { /* Column already exists or error ignored */ }
}

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
            $sex = $_POST['sex'] ?? '';
            $tin = trim($_POST['tin'] ?? '');
            $fund_source = $_POST['fund_source'] ?? '';
            $position_title = $_POST['position_title'] ?? '';
            $appointment_status = $_POST['appointment_status'] ?? '';
            $salary_grade = !empty($_POST['salary_grade']) ? intval($_POST['salary_grade']) : null;
            $educational_degree = trim($_POST['educational_degree'] ?? '');
            $major_specialization = trim($_POST['major_specialization'] ?? '');
            $minor_specialization = trim($_POST['minor_specialization'] ?? '');
            
            // Map common SG values for DepEd Government Positions
            $sgMap = [
                'Teacher I' => 11, 'Teacher II' => 12, 'Teacher III' => 13,
                'Special Education Teacher I' => 14, 'Special Education Teacher II' => 15, 'Special Education Teacher III' => 16, 'Special Education Teacher IV' => 17,
                'Master Teacher I' => 18, 'Master Teacher II' => 19, 'Master Teacher III' => 20, 'Master Teacher IV' => 21,
                'Head Teacher I' => 14, 'Head Teacher II' => 15, 'Head Teacher III' => 16, 'Head Teacher IV' => 17, 'Head Teacher V' => 18, 'Head Teacher VI' => 19,
                'Principal I' => 19, 'Principal II' => 20, 'Principal III' => 21, 'Principal IV' => 22,
                'Administrative Assistant I' => 7, 'Administrative Assistant II' => 8, 'Administrative Assistant III' => 9,
                'Administrative Officer I' => 10, 'Administrative Officer II' => 11, 'Administrative Officer III' => 14, 'Administrative Officer IV' => 15, 'Administrative Officer V' => 18,
                'Registrar I' => 11, 'Registrar II' => 15,
                'Guidance Counselor I' => 11, 'Guidance Counselor II' => 12, 'Guidance Counselor III' => 13,
                'Librarian I' => 11, 'Librarian II' => 12,
                'Nurse I' => 15, 'Nurse II' => 16
            ];
            if (!$salary_grade && isset($sgMap[$position_title])) {
                $salary_grade = $sgMap[$position_title];
            }

            $stmt = $pdo->prepare("UPDATE users SET 
                first_name = ?, middle_name = ?, last_name = ?, email = ?, 
                sex = ?, tin = ?, fund_source = ?, position_title = ?, 
                appointment_status = ?, salary_grade = ?, educational_degree = ?, 
                major_specialization = ?, minor_specialization = ? 
                WHERE id = ?");
            
            $stmt->execute([
                $first_name, $middle_name, $last_name, $email, 
                $sex, $tin, $fund_source, $position_title, 
                $appointment_status, $salary_grade, $educational_degree, 
                $major_specialization, $minor_specialization, $userId
            ]);
            
            // Update session data
            $_SESSION['user']['first_name'] = $first_name;
            $_SESSION['user']['last_name'] = $last_name;
            
            $success = "Profile information updated successfully!";
        } 
        elseif ($action === 'update_password') {
            $current_pw = $_POST['current_password'] ?? '';
            $new_pw = $_POST['new_password'] ?? '';
            $confirm_pw = $_POST['confirm_password'] ?? '';
            
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
        elseif ($action === 'update_photo' && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $uploadDir = dirname(__DIR__) . '/uploads';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $filename = "teacher_" . $userId . "_" . time() . ".$ext";
                $targetPath = $uploadDir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $oldPhoto = $stmt->fetchColumn();
                    if ($oldPhoto && $oldPhoto !== 'default.png' && file_exists($uploadDir . '/' . $oldPhoto)) {
                        @unlink($uploadDir . '/' . $oldPhoto);
                    }
                    
                    $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                    $stmt->execute([$filename, $userId]);
                    $_SESSION['user']['profile_photo'] = $filename;
                    $success = "Profile photo updated successfully!";
                }
            }
        }
        elseif ($action === 'update_signature' && isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['signature'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'webp'])) { // PNG or WebP preferred for transparency
                $uploadDir = dirname(__DIR__) . '/uploads';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $filename = "sig_" . $userId . "_" . time() . ".$ext";
                $targetPath = $uploadDir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $stmt = $pdo->prepare("SELECT e_signature FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $oldSig = $stmt->fetchColumn();
                    if ($oldSig && file_exists($uploadDir . '/' . $oldSig)) {
                        @unlink($uploadDir . '/' . $oldSig);
                    }
                    
                    $stmt = $pdo->prepare("UPDATE users SET e_signature = ? WHERE id = ?");
                    $stmt->execute([$filename, $userId]);
                    $success = "E-Signature uploaded successfully!";
                }
            } else {
                $error = "Please upload a transparent PNG or WebP for best results.";
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
    <title>My Profile - Teacher Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-rgb: 37, 99, 235;
            --success-rgb: 16, 185, 129;
            --danger-rgb: 239, 68, 68;
            --glass-bg: rgba(255, 255, 255, 0.9);
            --glass-border: rgba(255, 255, 255, 0.5);
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --accent-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            background-image: radial-gradient(at 0% 0%, hsla(210,100%,98%,1) 0, transparent 50%), 
                              radial-gradient(at 100% 0%, hsla(220,30%,95%,1) 0, transparent 50%);
            background-attachment: fixed;
            background-size: cover;
            margin: 0;
            padding: 0;
        }
        
        .main-content {
            padding: calc(var(--header-height) + 32px) 24px 64px;
            max-width: 1100px;
            margin-left: var(--sidebar-width, 260px);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @media (max-width: 992px) {
            .main-content { margin-left: 0 !important; padding: calc(var(--header-height) + 20px) 16px 40px; }
        }

        .profile-header-card {
            background: var(--primary-gradient);
            border-radius: 32px;
            padding: 40px;
            color: white;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 32px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .profile-header-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: var(--accent-gradient);
            filter: blur(80px);
            opacity: 0.2;
            border-radius: 50%;
        }

        .header-avatar-wrapper {
            width: 130px;
            height: 130px;
            border-radius: 36px;
            border: 4px solid rgba(255,255,255,0.1);
            overflow: hidden;
            background: #1e293b;
            flex-shrink: 0;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .header-avatar-wrapper img { width: 100%; height: 100%; object-fit: cover; }

        .header-info h1 { font-size: 36px; font-weight: 900; margin: 0; letter-spacing: -1.5px; }
        .header-info p { margin: 8px 0 0; opacity: 0.8; font-weight: 500; font-size: 16px; display: flex; align-items: center; gap: 8px; }

        .settings-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 32px;
            background: rgba(255,255,255,0.6);
            padding: 8px;
            border-radius: 24px;
            width: fit-content;
            border: 1px solid rgba(226, 232, 240, 0.8);
            backdrop-filter: blur(12px);
        }

        .tab-btn {
            padding: 14px 28px;
            border-radius: 18px;
            font-size: 14px;
            font-weight: 800;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            align-items: center;
            gap: 12px;
            border: none;
            background: transparent;
        }

        .tab-btn.active {
            background: #0f172a;
            color: white;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .tab-content { display: none; animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
        .tab-content.active { display: block; }

        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 20px 40px rgba(0,0,0,0.03);
            overflow: hidden;
            margin-bottom: 32px;
        }

        .card-header { padding: 32px; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .card-header h2 { font-size: 20px; font-weight: 900; color: #1e293b; margin: 0; letter-spacing: -0.5px; }
        .card-body { padding: 40px; }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 900;
            color: #94a3b8;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        
        .form-control {
            width: 100%;
            padding: 16px 24px;
            border: 2px solid #f1f5f9;
            border-radius: 18px;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            background: #f8fafc;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
            box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.1);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 16px 32px;
            border-radius: 20px;
            font-weight: 900;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            border: none;
            gap: 12px;
        }
        
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }

        .alert {
            padding: 18px 28px;
            border-radius: 24px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-weight: 700;
        }

        .alert-success { background: #ecfdf5; color: #10b981; border: 1px solid #d1fae5; }
        .alert-error { background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .helper-card {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
            display: flex;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 32px;
            border: 1px solid #e2e8f0;
        }

        .helper-card svg { color: #2563eb; flex-shrink: 0; }
        .helper-card p { margin: 0; font-size: 14px; color: #64748b; line-height: 1.6; font-weight: 500; }
        
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 20px center; background-size: 16px; }
    </style>
</head>
<body>
    <?php include 'teacher_header.php'; ?>
    <?php include 'teacher_side_panel.php'; ?>
    
    <div class="main-content">
        <div class="profile-header-card">
            <div class="header-avatar-wrapper" onclick="document.getElementById('photoInput').click()">
                <?php if ($user['profile_photo'] && file_exists(dirname(__DIR__) . '/uploads/' . $user['profile_photo'])): ?>
                    <img src="<?= url_for('/uploads/' . $user['profile_photo']) ?>" alt="Profile">
                <?php else: ?>
                    <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 42px; font-weight: 900; color: #475569;">
                        <?= strtoupper(substr($user['username'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-info">
                <h1><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h1>
                <p><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Account ID: @<?= htmlspecialchars($user['username']) ?></p>
                <p><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?= htmlspecialchars($user['position_title'] ?? 'Position Not Set') ?> (SG <?= htmlspecialchars($user['salary_grade'] ?? 'N/A') ?>)</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="photoForm" style="display: none;">
                <input type="hidden" name="action" value="update_photo">
                <input type="file" name="photo" id="photoInput" accept="image/*" onchange="this.form.submit()">
            </form>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="settings-tabs">
            <button class="tab-btn active" onclick="switchTab(event, 'general')">Personal Profile</button>
            <button class="tab-btn" onclick="switchTab(event, 'professional')">Professional (SF7)</button>
            <button class="tab-btn" onclick="switchTab(event, 'security')">Security</button>
        </div>

        <!-- Tab: General Info -->
        <div id="general" class="tab-content active">
            <div class="card">
                <div class="card-header"><h2>Institutional Records</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_info">
                        
                        <!-- Hidden fields to preserve professional data during personal info updates -->
                        <input type="hidden" name="position_title" value="<?= htmlspecialchars($user['position_title'] ?? '') ?>">
                        <input type="hidden" name="salary_grade" value="<?= htmlspecialchars($user['salary_grade'] ?? '') ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Sex</label>
                                <select name="sex" class="form-control">
                                    <option value="">Select Sex</option>
                                    <option value="Male" <?= ($user['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= ($user['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">TIN / Employee No.</label>
                                <input type="text" name="tin" class="form-control" value="<?= htmlspecialchars($user['tin'] ?? '') ?>" placeholder="Tax Identification Number">
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary">Save Profile Updates</button>
                        </div>

                        <!-- Hidden fields to preserve other data during partial POST if needed, though we use full POST here -->
                        <input type="hidden" name="fund_source" value="<?= htmlspecialchars($user['fund_source'] ?? '') ?>">
                        <input type="hidden" name="appointment_status" value="<?= htmlspecialchars($user['appointment_status'] ?? '') ?>">
                        <input type="hidden" name="educational_degree" value="<?= htmlspecialchars($user['educational_degree'] ?? '') ?>">
                        <input type="hidden" name="major_specialization" value="<?= htmlspecialchars($user['major_specialization'] ?? '') ?>">
                        <input type="hidden" name="minor_specialization" value="<?= htmlspecialchars($user['minor_specialization'] ?? '') ?>">
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab: Professional Details (SF7) -->
        <div id="professional" class="tab-content">
            <div class="helper-card">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <p>These details are required for **School Form 7 (SF7)**. Positions will automatically calculate your Salary Grade.</p>
            </div>
            <div class="card">
                <div class="card-header"><h2>SF7 Personnel Data</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_info">
                        <!-- Re-include general fields -->
                        <input type="hidden" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>">
                        <input type="hidden" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
                        <input type="hidden" name="middle_name" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        <input type="hidden" name="sex" value="<?= htmlspecialchars($user['sex'] ?? '') ?>">
                        <input type="hidden" name="tin" value="<?= htmlspecialchars($user['tin'] ?? '') ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Position / Designation</label>
                                <select name="position_title" id="position_title" class="form-control" onchange="autoSalaryGrade()">
                                    <option value="">Select Position</option>
                                    <option value="Teacher I" <?= ($user['position_title'] ?? '') === 'Teacher I' ? 'selected' : '' ?>>Teacher I</option>
                                    <option value="Teacher II" <?= ($user['position_title'] ?? '') === 'Teacher II' ? 'selected' : '' ?>>Teacher II</option>
                                    <option value="Teacher III" <?= ($user['position_title'] ?? '') === 'Teacher III' ? 'selected' : '' ?>>Teacher III</option>
                                    <option value="Master Teacher I" <?= ($user['position_title'] ?? '') === 'Master Teacher I' ? 'selected' : '' ?>>Master Teacher I</option>
                                    <option value="Master Teacher II" <?= ($user['position_title'] ?? '') === 'Master Teacher II' ? 'selected' : '' ?>>Master Teacher II</option>
                                    <option value="Master Teacher III" <?= ($user['position_title'] ?? '') === 'Master Teacher III' ? 'selected' : '' ?>>Master Teacher III</option>
                                    <option value="Head Teacher I" <?= ($user['position_title'] ?? '') === 'Head Teacher I' ? 'selected' : '' ?>>Head Teacher I</option>
                                    <option value="Head Teacher II" <?= ($user['position_title'] ?? '') === 'Head Teacher II' ? 'selected' : '' ?>>Head Teacher II</option>
                                    <option value="Head Teacher III" <?= ($user['position_title'] ?? '') === 'Head Teacher III' ? 'selected' : '' ?>>Head Teacher III</option>
                                    <option value="Principal I" <?= ($user['position_title'] ?? '') === 'Principal I' ? 'selected' : '' ?>>Principal I</option>
                                    <option value="Principal II" <?= ($user['position_title'] ?? '') === 'Principal II' ? 'selected' : '' ?>>Principal II</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Salary Grade (Auto)</label>
                                <input type="number" name="salary_grade" id="salary_grade" class="form-control" value="<?= htmlspecialchars($user['salary_grade'] ?? '') ?>" placeholder="SG No.">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fund Source</label>
                                <select name="fund_source" class="form-control">
                                    <option value="">Select Source</option>
                                    <option value="National" <?= ($user['fund_source'] ?? '') === 'National' ? 'selected' : '' ?>>National</option>
                                    <option value="Local" <?= ($user['fund_source'] ?? '') === 'Local' ? 'selected' : '' ?>>Local</option>
                                    <option value="SEF" <?= ($user['fund_source'] ?? '') === 'SEF' ? 'selected' : '' ?>>SEF (Special Education Fund)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Employment Status</label>
                                <select name="appointment_status" class="form-control">
                                    <option value="">Select Status</option>
                                    <option value="Permanent" <?= ($user['appointment_status'] ?? '') === 'Permanent' ? 'selected' : '' ?>>Permanent</option>
                                    <option value="Provisional" <?= ($user['appointment_status'] ?? '') === 'Provisional' ? 'selected' : '' ?>>Provisional</option>
                                    <option value="Substitute" <?= ($user['appointment_status'] ?? '') === 'Substitute' ? 'selected' : '' ?>>Substitute</option>
                                    <option value="Contractual" <?= ($user['appointment_status'] ?? '') === 'Contractual' ? 'selected' : '' ?>>Contractual</option>
                                </select>
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Educational Qualification (Degree)</label>
                                <input type="text" name="educational_degree" class="form-control" value="<?= htmlspecialchars($user['educational_degree'] ?? '') ?>" placeholder="e.g. Bachelor of Secondary Education">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Major / Specialization</label>
                                <input type="text" name="major_specialization" class="form-control" value="<?= htmlspecialchars($user['major_specialization'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Minor</label>
                                <input type="text" name="minor_specialization" class="form-control" value="<?= htmlspecialchars($user['minor_specialization'] ?? '') ?>">
                            </div>
                        </div>

                        <div style="border-top: 1px dashed #e2e8f0; margin: 32px 0; padding-top: 32px;">
                            <label class="form-label">Digital E-Signature (For School IDs/Reports)</label>
                            <div style="display: flex; gap: 24px; align-items: center; background: #f8fafc; padding: 24px; border-radius: 20px; border: 2px dashed #cbd5e1;">
                                <div style="width: 150px; height: 80px; background: white; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;">
                                    <?php if($user['e_signature']): ?>
                                        <img src="<?= url_for('/uploads/'.$user['e_signature']) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <span style="font-size: 10px; color: #94a3b8; font-weight: 700;">NO SIGNATURE</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p style="font-size: 13px; font-weight: 600; color: #475569; margin: 0 0 12px 0;">Upload a clear, transparent PNG signature for official forms.</p>
                                    <button type="button" class="btn btn-outline" onclick="document.getElementById('sigInput').click()" style="background: white; border: 1px solid #cbd5e1;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        <?= $user['e_signature'] ? 'Change Signature' : 'Upload Signature' ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary">Update Professional Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab: Security -->
        <div id="security" class="tab-content">
            <div class="card">
                <div class="card-header"><h2>Password & Authentication</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_password">
                        <div class="form-group" style="margin-bottom: 24px;">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary">Secure New Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Hidden Signature Form -->
        <form method="POST" enctype="multipart/form-data" id="sigForm" style="display: none;">
            <input type="hidden" name="action" value="update_signature">
            <input type="file" name="signature" id="sigInput" accept="image/png,image/webp" onchange="this.form.submit()">
        </form>
    </div>

    <script>
        const sgMap = {
            'Teacher I': 11, 'Teacher II': 12, 'Teacher III': 13,
            'Special Education Teacher I': 14, 'Special Education Teacher II': 15, 'Special Education Teacher III': 16, 'Special Education Teacher IV': 17,
            'Master Teacher I': 18, 'Master Teacher II': 19, 'Master Teacher III': 20, 'Master Teacher IV': 21,
            'Head Teacher I': 14, 'Head Teacher II': 15, 'Head Teacher III': 16, 'Head Teacher IV': 17, 'Head Teacher V': 18, 'Head Teacher VI': 19,
            'Principal I': 19, 'Principal II': 20, 'Principal III': 21, 'Principal IV': 22,
            'Administrative Assistant I': 7, 'Administrative Assistant II': 8, 'Administrative Assistant III': 9,
            'Administrative Officer I': 10, 'Administrative Officer II': 11, 'Administrative Officer III': 14, 'Administrative Officer IV': 15, 'Administrative Officer V': 18,
            'Registrar I': 11, 'Registrar II': 15,
            'Guidance Counselor I': 11, 'Guidance Counselor II': 12, 'Guidance Counselor III': 13,
            'Librarian I': 11, 'Librarian II': 12,
            'Nurse I': 15, 'Nurse II': 16
        };

        function autoSalaryGrade(event) {
            const sgMap = {
                'Teacher I': 11, 'Teacher II': 12, 'Teacher III': 13,
                'Special Education Teacher I': 14, 'Special Education Teacher II': 15, 'Special Education Teacher III': 16, 'Special Education Teacher IV': 17,
                'Master Teacher I': 18, 'Master Teacher II': 19, 'Master Teacher III': 20, 'Master Teacher IV': 21,
                'Head Teacher I': 14, 'Head Teacher II': 15, 'Head Teacher III': 16, 'Head Teacher IV': 17, 'Head Teacher V': 18, 'Head Teacher VI': 19,
                'Principal I': 19, 'Principal II': 20, 'Principal III': 21, 'Principal IV': 22,
                'Administrative Assistant I': 7, 'Administrative Assistant II': 8, 'Administrative Assistant III': 9,
                'Administrative Officer I': 10, 'Administrative Officer II': 11, 'Administrative Officer III': 14, 'Administrative Officer IV': 15, 'Administrative Officer V': 18,
                'Registrar I': 11, 'Registrar II': 15,
                'Guidance Counselor I': 11, 'Guidance Counselor II': 12, 'Guidance Counselor III': 13,
                'Librarian I': 11, 'Librarian II': 12,
                'Nurse I': 15, 'Nurse II': 16
            };

            const posSelector = document.getElementById('position_title');
            const sgInput = document.getElementById('salary_grade');
            
            if (event && event.target.name === 'position_title') {
                const newValue = event.target.value;
                if (sgMap[newValue]) {
                    sgInput.value = sgMap[newValue];
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            autoSalaryGrade();
        });

        function switchTab(evt, tabName) {
            document.querySelectorAll(".tab-content").forEach(t => t.classList.remove("active"));
            document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
        }
        
        // Initial run
        window.onload = autoSalaryGrade;
    </script>
</body>
</html>
