<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/config/db.php';
auth_require_role(['admin']);

$pdo = db_connect();
$message = '';
$error = '';

// Ensure SF7 columns exist in users table (Auto-Migration)
$required_columns = [
    'tin' => 'VARCHAR(50)',
    'fund_source' => 'VARCHAR(100)',
    'appointment_status' => 'VARCHAR(100)',
    'educational_degree' => 'VARCHAR(255)',
    'major_specialization' => 'VARCHAR(255)',
    'minor_specialization' => 'VARCHAR(255)',
    'salary_grade' => 'INT',
    'position_title' => 'VARCHAR(150)',
    'user_status' => 'ENUM("active","inactive") DEFAULT "active"'
];
foreach ($required_columns as $col => $type) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN $col $type NULL"); } catch (Exception $e) {}
}

// Helper function to generate dynamic employee code
function getDynamicEmployeeCode($userId) {
    $year = date('Y');
    return sprintf("EMP-%s-%03d", $year, $userId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $userId = (int)($_POST['employee_id'] ?? 0);
        try {
            $stmt = $pdo->prepare('UPDATE users SET user_status = "inactive" WHERE id = ?');
            $stmt->execute([$userId]);
            $message = 'Personnel record deactivated successfully.';
        } catch (Exception $e) { $error = 'Failed: ' . $e->getMessage(); }
    } 
    elseif ($action === 'set_active' || $action === 'set_inactive') {
        $userId = (int)($_POST['employee_id'] ?? 0);
        $status = ($action === 'set_active') ? 'active' : 'inactive';
        try {
            $stmt = $pdo->prepare('UPDATE users SET user_status = ? WHERE id = ?');
            $stmt->execute([$status, $userId]);
            $message = 'Status updated.';
        } catch (Exception $e) { $error = 'Failed: ' . $e->getMessage(); }
    } 
    elseif ($action === 'edit' || $action === 'add') {
        $userId = (int)($_POST['employee_id'] ?? 0);
        $fn = trim($_POST['first_name'] ?? '');
        $ln = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? '';
        $dept = $_POST['department'] ?? '';
        $tin = trim($_POST['tin'] ?? '');
        $pos = $_POST['position_title'] ?? '';
        $sg = !empty($_POST['salary_grade']) ? intval($_POST['salary_grade']) : null;
        $fund = $_POST['fund_source'] ?? '';
        $app_status = $_POST['appointment_status'] ?? '';
        $degree = trim($_POST['educational_degree'] ?? '');
        $major = trim($_POST['major_specialization'] ?? '');
        
        if (empty($fn) || empty($ln) || empty($role)) {
            $error = 'First name, last name, and role are required.';
        } else {
            try {
                if ($action === 'edit') {
                    $stmt = $pdo->prepare("UPDATE users SET 
                        first_name = ?, last_name = ?, email = ?, role = ?, department = ?,
                        tin = ?, position_title = ?, salary_grade = ?, fund_source = ?,
                        appointment_status = ?, educational_degree = ?, major_specialization = ?
                        WHERE id = ?");
                    $stmt->execute([$fn, $ln, $email, $role, $dept, $tin, $pos, $sg, $fund, $app_status, $degree, $major, $userId]);
                    $message = 'Personnel record updated.';
                } else {
                    $username = trim($_POST['username'] ?? '');
                    $password = $_POST['password'] ?? 'Pass123!';
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, first_name, last_name, email, role, department, tin, position_title, salary_grade, fund_source, appointment_status, educational_degree, major_specialization, approval_status, user_status, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 'active', NOW())");
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fn, $ln, $email, $role, $dept, $tin, $pos, $sg, $fund, $app_status, $degree, $major]);
                    $message = 'New personnel added successfully.';
                }
            } catch (Exception $e) { $error = 'Database error: ' . $e->getMessage(); }
        }
    }
}

// Fetch employees
$employees = $pdo->query('SELECT * FROM users WHERE approval_status = "approved" ORDER BY last_name ASC')->fetchAll();

// Stats
$total = count($employees);
$active = 0;
$teachers = 0;
foreach ($employees as $e) {
    if (($e['user_status'] ?? 'active') === 'active') $active++;
    if ($e['role'] === 'teacher') $teachers++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personnel Hub | Professional Edition</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
            max-width: 1600px;
            transition: all 0.4s ease;
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding: calc(var(--header-height) + 20px) 20px 40px; }
        }

        /* Institutional Header */
        .hub-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 48px;
        }

        .hub-header h1 {
            font-size: 42px;
            font-weight: 900;
            letter-spacing: -2px;
            margin: 0;
            color: #0f172a;
        }

        .hub-header p { margin: 8px 0 0; color: #64748b; font-size: 18px; font-weight: 500; }

        /* Modern Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 48px;
        }

        .stat-card {
            background: white;
            padding: 32px;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
        }

        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); }

        .stat-card h4 { margin: 0; font-size: 12px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; }
        .stat-card .value { font-size: 36px; font-weight: 900; color: #0f172a; margin-top: 4px; letter-spacing: -1px; }

        /* Professional Table */
        .table-container {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 20px 40px rgba(0,0,0,0.04);
            overflow: hidden;
            padding: 12px;
        }

        .hub-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .hub-table th { padding: 20px 24px; text-align: left; font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; }
        .hub-table td { padding: 20px 24px; background: white; transition: all 0.2s ease; }
        .hub-table tr td:first-child { border-radius: 20px 0 0 20px; }
        .hub-table tr td:last-child { border-radius: 0 20px 20px 0; }
        .hub-table tr:hover td { background: #f8fafc; transform: scale(1.002); }

        /* Personnel Avatar */
        .personnel-cell { display: flex; align-items: center; gap: 16px; }
        .avatar {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #2563eb;
            font-size: 18px;
            border: 2px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }

        /* Status Badges */
        .badge {
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-active { background: #ecfdf5; color: #059669; }
        .badge-inactive { background: #fef2f2; color: #dc2626; }
        .badge-role { background: #eff6ff; color: #2563eb; }

        /* Action Buttons */
        .btn-action {
            width: 40px;
            height: 40px;
            border-radius: 12px;
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

        /* Modal Overlays */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }

        .modal-card {
            background: white;
            width: 100%;
            max-width: 900px;
            border-radius: 32px;
            box-shadow: 0 40px 80px rgba(0,0,0,0.2);
            overflow: hidden;
            animation: modalPop 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalPop { from { transform: scale(0.95) translateY(20px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }

        .modal-header { padding: 32px; background: #0f172a; color: white; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 40px; max-height: 80vh; overflow-y: auto; }

        /* Professional Forms */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 24px; }
        .form-group label { display: block; font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.1em; }
        .form-control {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #f1f5f9;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 600;
            background: #f8fafc;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        .form-control:focus { outline: none; border-color: #2563eb; background: white; box-shadow: 0 0 0 5px rgba(37, 99, 235, 0.1); }

        .btn-submit {
            background: #0f172a;
            color: white;
            padding: 16px 32px;
            border-radius: 20px;
            font-weight: 800;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
    </style>
</head>
<body>
    <?php include 'admin/admin_header.php'; ?>
    <?php include 'admin/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="hub-header">
            <div>
                <h1>Personnel Hub</h1>
                <p>Strategic management of institutional staff and academic faculty.</p>
            </div>
            <button class="btn-submit" style="background: var(--accent-gradient);" onclick="openModal('addModal')">
                Add New Personnel
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Workforce</h4>
                <div class="value"><?= $total ?></div>
            </div>
            <div class="stat-card">
                <h4>Active Accounts</h4>
                <div class="value"><?= $active ?></div>
            </div>
            <div class="stat-card">
                <h4>Teaching Staff</h4>
                <div class="value"><?= $teachers ?></div>
            </div>
            <div class="stat-card">
                <h4>Support & Admin</h4>
                <div class="value"><?= $total - $teachers ?></div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="padding: 20px; border-radius: 20px; background: #ecfdf5; color: #059669; margin-bottom: 32px; font-weight: 600;">
                ✅ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger" style="padding: 20px; border-radius: 20px; background: #fef2f2; color: #dc2626; margin-bottom: 32px; font-weight: 600;">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table class="hub-table">
                <thead>
                    <tr>
                        <th>Employee Details</th>
                        <th>Position / Designation</th>
                        <th>Dept</th>
                        <th>Salary Grade</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): 
                        $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1));
                    ?>
                    <tr>
                        <td>
                            <div class="personnel-cell">
                                <div class="avatar">
                                    <?php if ($emp['profile_photo']): ?>
                                        <img src="<?= url_for('/uploads/' . $emp['profile_photo']) ?>" alt="">
                                    <?php else: ?>
                                        <?= $initials ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div style="font-weight: 800; font-size: 15px;"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></div>
                                    <div style="font-size: 12px; color: #94a3b8;"><?= htmlspecialchars($emp['email'] ?: '@'.$emp['username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="badge badge-role"><?= htmlspecialchars($emp['position_title'] ?: ucfirst($emp['role'])) ?></div>
                        </td>
                        <td><span style="font-weight: 600; color: #64748b;"><?= htmlspecialchars($emp['department'] ?: '---') ?></span></td>
                        <td>
                            <div style="font-weight: 800; color: #2563eb;">SG <?= htmlspecialchars($emp['salary_grade'] ?: '--') ?></div>
                        </td>
                        <td>
                            <span class="badge <?= ($emp['user_status'] ?? 'active') === 'active' ? 'badge-active' : 'badge-inactive' ?>">
                                <?= ucfirst($emp['user_status'] ?? 'active') ?>
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                <button class="btn-action" onclick='openEditModal(<?= json_encode($emp) ?>)' title="Edit Profile">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                    <?php if (($emp['user_status'] ?? 'active') === 'active'): ?>
                                        <input type="hidden" name="action" value="set_inactive">
                                        <button type="submit" class="btn-action" onclick="return confirm('Deactivate this user?')" title="Deactivate User">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #ef4444;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="set_active">
                                        <button type="submit" class="btn-action" onclick="return confirm('Reactivate this user?')" title="Activate User">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #10b981;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modals -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="margin:0;">Edit Personnel Profile</h3>
                <button onclick="closeModal('editModal')" style="background:none; border:none; color:white; cursor:pointer;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="employee_id" id="edit_id">
                    <div class="form-grid">
                        <div class="form-group"><label>First Name</label><input type="text" name="first_name" id="edit_fn" class="form-control" required></div>
                        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" id="edit_ln" class="form-control" required></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                        <div class="form-group">
                            <label>System Role</label>
                            <select name="role" id="edit_role" class="form-control">
                                <option value="teacher">Teacher</option>
                                <option value="admin">Administrator</option>
                                <option value="registrar">Registrar</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Position / Title (SF7)</label><input type="text" name="position_title" id="edit_pos" class="form-control"></div>
                        <div class="form-group"><label>Salary Grade</label><input type="number" name="salary_grade" id="edit_sg" class="form-control"></div>
                        <div class="form-group"><label>Department</label><input type="text" name="department" id="edit_dept" class="form-control"></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>TIN / ID</label><input type="text" name="tin" id="edit_tin" class="form-control"></div>
                        <div class="form-group"><label>Fund Source</label><input type="text" name="fund_source" id="edit_fund" class="form-control"></div>
                    </div>
                    <div style="text-align: right;"><button type="submit" class="btn-submit">Save Changes</button></div>
                </form>
            </div>
        </div>
    </div>

    <div id="addModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="margin:0;">Add New Personnel</h3>
                <button onclick="closeModal('addModal')" style="background:none; border:none; color:white; cursor:pointer;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-grid">
                        <div class="form-group"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                        <div class="form-group"><label>Initial Password</label><input type="password" name="password" class="form-control" placeholder="Default: Pass123!"></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>First Name</label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" class="form-control" required></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>System Role</label>
                            <select name="role" class="form-control">
                                <option value="teacher">Teacher</option>
                                <option value="admin">Administrator</option>
                                <option value="registrar">Registrar</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Department</label><input type="text" name="department" class="form-control"></div>
                    </div>
                    <div style="text-align: right;"><button type="submit" class="btn-submit">Register Personnel</button></div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function openEditModal(emp) {
            document.getElementById('edit_id').value = emp.id;
            document.getElementById('edit_fn').value = emp.first_name;
            document.getElementById('edit_ln').value = emp.last_name;
            document.getElementById('edit_email').value = emp.email || '';
            document.getElementById('edit_role').value = emp.role;
            document.getElementById('edit_pos').value = emp.position_title || '';
            document.getElementById('edit_sg').value = emp.salary_grade || '';
            document.getElementById('edit_dept').value = emp.department || '';
            document.getElementById('edit_tin').value = emp.tin || '';
            document.getElementById('edit_fund').value = emp.fund_source || '';
            openModal('editModal');
        }
    </script>
</body>
</html>