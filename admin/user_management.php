<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['admin']);

$pdo = db_connect();
initialize_schema($pdo);
$message = '';
$error = '';

// Handle actions (add, edit, delete, reset_password)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? '';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $approval_status = $_POST['approval_status'] ?? 'approved';
        $password = $_POST['password'] ?? 'changeme123';

        if (empty($username) || empty($role)) {
            $error = 'Username and Role are required.';
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already exists.';
            } else {
                try {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $approved_by = ($approval_status === 'approved') ? $_SESSION['user']['id'] : null;
                    $approved_at = ($approval_status === 'approved') ? date('Y-m-d H:i:s') : null;

                    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, registered_role, first_name, last_name, middle_name, approval_status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$username, $password_hash, $role, $role, $first_name ?: null, $last_name ?: null, $middle_name ?: null, $approval_status, $approved_by, $approved_at]);
                    
                    $new_user_id = $pdo->lastInsertId();
                    syncEmployeeFromUser($pdo, $new_user_id);

                    $message = 'User added successfully and synced to employees!';
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit') {
        $user_id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? '';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $approval_status = $_POST['approval_status'] ?? 'approved';

        if ($user_id <= 0 || empty($username) || empty($role)) {
            $error = 'Invalid data submitted.';
        } else {
             // Check if username exists for OTHER users
             $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
             $stmt->execute([$username, $user_id]);
             if ($stmt->fetchColumn() > 0) {
                 $error = 'Username already exists.';
             } else {
                 try {
                     // Check if approval status changed to approved
                     $stmtCurrent = $pdo->prepare("SELECT approval_status FROM users WHERE id = ?");
                     $stmtCurrent->execute([$user_id]);
                     $currentStatus = $stmtCurrent->fetchColumn();

                     $approved_by = null;
                     $approved_at = null;

                     if ($currentStatus !== 'approved' && $approval_status === 'approved') {
                         $approved_by = $_SESSION['user']['id'];
                         $approved_at = date('Y-m-d H:i:s');
                         $stmt = $pdo->prepare('UPDATE users SET username=?, role=?, first_name=?, last_name=?, middle_name=?, approval_status=?, approved_by=?, approved_at=? WHERE id=?');
                         $stmt->execute([$username, $role, $first_name ?: null, $last_name ?: null, $middle_name ?: null, $approval_status, $approved_by, $approved_at, $user_id]);
                     } else {
                         // Normal update without changing approval metadata
                         $stmt = $pdo->prepare('UPDATE users SET username=?, role=?, first_name=?, last_name=?, middle_name=?, approval_status=? WHERE id=?');
                         $stmt->execute([$username, $role, $first_name ?: null, $last_name ?: null, $middle_name ?: null, $approval_status, $user_id]);
                     }
                     
                     // Sync to employees
                     syncEmployeeFromUser($pdo, $user_id);

                     $message = 'User updated successfully and synced to employees!';
                 } catch (PDOException $e) {
                     $error = 'Database error: ' . $e->getMessage();
                 }
             }
        }
    } elseif ($action === 'delete') {
        $user_id = (int)($_POST['id'] ?? 0);
        if ($user_id > 0) {
            if ($user_id === (int)$_SESSION['user']['id']) {
                $error = "You cannot delete your own account.";
            } else {
                try {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$user_id]);
                    $message = 'User deleted successfully!';
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $user_id = (int)($_POST['id'] ?? 0);
        $new_password = $_POST['new_password'] ?? 'changeme123';
        
        if ($user_id > 0) {
            try {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([$password_hash, $user_id]);
                $message = "Password reset successfully (Default: changeme123).";
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle edit request formatting
$editUser = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$editId]);
        $editUser = $stmt->fetch();
    } catch (Exception $e) {
        $error = 'Failed to fetch user data: ' . $e->getMessage();
    }
}

// Fetch users for listing
try {
    // Left join to get the name of the person who approved them
    $query = "
        SELECT u.*, a.username as approver_username 
        FROM users u 
        LEFT JOIN users a ON u.approved_by = a.id 
        ORDER BY u.id DESC
    ";
    $listStmt = $pdo->query($query);
    $users = $listStmt->fetchAll();
} catch (Exception $e) {
    $users = [];
    $error = "Failed to load users: " . $e->getMessage();
}

// Roles mapping for display
$roleColors = [
    'admin' => 'danger',        // Red
    'registrar' => 'primary',   // Blue
    'teacher' => 'success',     // Green
    'student' => 'warning',     // Yellow/Orange
    'employee' => 'secondary'   // Gray
];
$statusColors = [
    'approved' => 'success',
    'pending' => 'warning',
    'rejected' => 'danger'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --accent-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.5);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #0f172a;
            background-image: radial-gradient(at 0% 0%, hsla(210,100%,98%,1) 0, transparent 50%), 
                              radial-gradient(at 100% 0%, hsla(220,30%,95%,1) 0, transparent 50%);
            background-attachment: fixed;
            margin: 0;
        }

        .main-content {
            padding: calc(var(--header-height) + 40px) 40px 80px;
            margin-left: var(--sidebar-width, 260px);
            max-width: 1400px;
            transition: all 0.4s ease;
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding: calc(var(--header-height) + 20px) 20px 40px; }
        }

        .hub-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        .hub-header h1 {
            font-size: 36px;
            font-weight: 800;
            letter-spacing: -1.5px;
            margin: 0;
            color: #0f172a;
        }

        .hub-header p { margin: 4px 0 0; color: #64748b; font-size: 16px; font-weight: 500; }

        /* Modern Table Card */
        .table-container {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 20px 40px rgba(0,0,0,0.04);
            overflow: hidden;
            padding: 8px;
        }

        .modern-table { width: 100%; border-collapse: separate; border-spacing: 0 4px; }
        .modern-table th { padding: 16px 24px; text-align: left; font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; }
        .modern-table td { padding: 16px 24px; background: white; transition: all 0.2s ease; border-top: 1px solid #f8fafc; border-bottom: 1px solid #f8fafc; }
        .modern-table tr td:first-child { border-radius: 16px 0 0 16px; border-left: 1px solid #f8fafc; }
        .modern-table tr td:last-child { border-radius: 0 16px 16px 0; border-right: 1px solid #f8fafc; }
        .modern-table tr:hover td { background: #f8fafc; transform: translateY(-1px); }

        /* User Profile in Table */
        .user-cell { display: flex; align-items: center; gap: 12px; }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #2563eb;
            border: 2px solid white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        /* Status Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; display: inline-block; }

        .badge-success { background: #ecfdf5; color: #059669; }
        .badge-success::before { background: #059669; }
        
        .badge-warning { background: #fffbeb; color: #d97706; }
        .badge-warning::before { background: #d97706; }
        
        .badge-danger { background: #fef2f2; color: #dc2626; }
        .badge-danger::before { background: #dc2626; }
        
        .badge-primary { background: #eff6ff; color: #2563eb; }
        .badge-primary::before { background: #2563eb; }

        .badge-secondary { background: #f8fafc; color: #64748b; }
        .badge-secondary::before { background: #64748b; }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
            animation: slideDown 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }
        .form-group label { display: block; font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em; }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            background: #f8fafc;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        .form-control:focus { outline: none; border-color: #2563eb; background: white; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }

        /* Action Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: var(--accent-gradient); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2); }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            color: #64748b;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-action:hover { background: #0f172a; color: white; transform: translateY(-2px); }
        .btn-action.btn-danger:hover { background: #dc2626; }
        .btn-action.btn-warning:hover { background: #f59e0b; }

        .hidden { display: none; }
    </style>
    </style>
</head>
<body>
    <?php include 'admin_header.php'; ?>
    <?php include 'admin_sidebar.php'; ?>
    
    <main class="main-content">
        <div class="hub-header">
            <div>
                <h1>User Management</h1>
                <p>Manage system access, roles, and administrative permissions.</p>
            </div>
            <button id="toggleFormBtn" class="btn btn-primary" onclick="toggleForm()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                <?= $editUser ? 'Cancel Edit' : 'Add New User' ?>
            </button>
        </div>

        <?php if ($message): ?>
            <div style="padding: 16px 24px; border-radius: 16px; background: #ecfdf5; color: #059669; margin-bottom: 32px; font-weight: 600; border: 1px solid rgba(5, 150, 105, 0.2);">
                ✅ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="padding: 16px 24px; border-radius: 16px; background: #fef2f2; color: #dc2626; margin-bottom: 32px; font-weight: 600; border: 1px solid rgba(220, 38, 38, 0.2);">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Add/Edit Form Card -->
        <div id="formCard" class="form-card <?= $editUser ? '' : 'hidden' ?>">
            <h3 style="margin-top:0; margin-bottom: 24px; font-size: 18px; font-weight: 800;">
                <?= $editUser ? 'Edit User: ' . htmlspecialchars($editUser['username']) : 'Register New User' ?>
            </h3>
            
            <form method="POST">
                <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'add' ?>">
                <?php if ($editUser): ?>
                    <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" class="form-control" required 
                               value="<?= htmlspecialchars($editUser['username'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" class="form-control" required>
                            <option value="">Select Role</option>
                            <option value="admin" <?= ($editUser['role']??'') === 'admin' ? 'selected' : '' ?>>Administrator</option>
                            <option value="registrar" <?= ($editUser['role']??'') === 'registrar' ? 'selected' : '' ?>>Registrar</option>
                            <option value="teacher" <?= ($editUser['role']??'') === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                            <option value="student" <?= ($editUser['role']??'') === 'student' ? 'selected' : '' ?>>Student</option>
                            <option value="employee" <?= ($editUser['role']??'') === 'employee' ? 'selected' : '' ?>>General Employee</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" 
                               value="<?= htmlspecialchars($editUser['first_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" 
                               value="<?= htmlspecialchars($editUser['last_name'] ?? '') ?>">
                    </div>

                    <?php if (!$editUser): ?>
                    <div class="form-group">
                        <label>Initial Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Default: changeme123">
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Approval Status</label>
                        <select name="approval_status" class="form-control">
                            <option value="approved" <?= ($editUser['approval_status']??'') === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="pending" <?= ($editUser['approval_status']??'approved') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="rejected" <?= ($editUser['approval_status']??'') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 32px; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn" style="background:#f1f5f9; color:#64748b;" onclick="toggleForm()">Discard</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save User Account
                    </button>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="table-container">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>User Identity</th>
                        <th>Access Role</th>
                        <th>Account Status</th>
                        <th>Registered On</th>
                        <th style="text-align: right;">Management</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" style="text-align:center; padding: 60px; color: #94a3b8; font-weight: 600;">No institutional users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): 
                            $letter = strtoupper(substr($u['first_name'] ?: $u['username'], 0, 1));
                            $roleBadge = $roleColors[$u['role']] ?? 'secondary';
                            $statusBadge = $statusColors[$u['approval_status']] ?? 'secondary';
                            $fullName = trim(($u['first_name']??'') . ' ' . ($u['last_name']??''));
                        ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar"><?= $letter ?></div>
                                    <div>
                                        <div style="font-weight: 800; font-size: 15px; color: #0f172a;"><?= htmlspecialchars($u['username']) ?></div>
                                        <div style="font-size: 12px; color: #94a3b8; font-weight: 500;"><?= htmlspecialchars($fullName ?: 'No profile name') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?= $roleBadge ?>"><?= htmlspecialchars($u['role']) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $statusBadge ?>"><?= htmlspecialchars($u['approval_status']) ?></span>
                            </td>
                            <td>
                                <div style="font-size:13px; font-weight: 700; color: #1e293b;"><?= date('M d, Y', strtotime($u['created_at'])) ?></div>
                                <div style="font-size:11px; color:#94a3b8; font-weight: 600;"><?= date('h:i A', strtotime($u['created_at'])) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                    <a href="?edit=<?= $u['id'] ?>" class="btn-action" title="Edit Profile">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>

                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Reset password to default (changeme123)?');">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn-action btn-warning" title="Reset Password">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        </button>
                                    </form>

                                    <?php if ($u['id'] !== $_SESSION['user']['id']): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Permanently delete this user account?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn-action btn-danger" title="Delete Account">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        function toggleForm() {
            const form = document.getElementById('formCard');
            const btn = document.getElementById('toggleFormBtn');
            const isHidden = form.classList.contains('hidden');
            
            if (isHidden) {
                // We're opening the form. If we were in edit mode, clear the URL to switch to add mode
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('edit')) {
                    window.location.href = window.location.pathname;
                    return; // Let the page reload
                }
                
                form.classList.remove('hidden');
                btn.innerHTML = `<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg> Cancel`;
                document.querySelector('input[name="username"]').focus();
            } else {
                form.classList.add('hidden');
                btn.innerHTML = `<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg> Add New User`;
            }
        }
    </script>
</body>
</html>
