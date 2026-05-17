<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['registrar', 'admin']);

$pdo = db_connect();
try {
    initialize_schema($pdo);
} catch (Exception $e) {
    $error = "Database initialization error: " . $e->getMessage();
}
if (!isset($message)) $message = '';
if (!isset($error)) $error = '';

// Handle approval/rejection/edit/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    $current_user_id = auth_user()['id'] ?? null;

    try {
        if ($action === 'approve_all') {
            $stmt = $pdo->prepare('SELECT id, first_name, last_name, role, email FROM users WHERE approval_status = "pending" AND role IN ("teacher", "registrar")');
            $stmt->execute();
            $pending = $stmt->fetchAll();

            if ($pending) {
                $pdo->beginTransaction();
                try {
                    $approved_count = 0;
                    foreach ($pending as $u) {
                        $app_stmt = $pdo->prepare('UPDATE users SET approval_status = "approved", approved_by = ?, approved_at = NOW() WHERE id = ?');
                        $app_stmt->execute([$current_user_id, $u['id']]);
                        syncEmployeeFromUser($pdo, $u['id']);
                        $approved_count++;
                    }
                    $pdo->commit();
                    $message = $approved_count . ' pending personnel approved successfully!';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'update_user' && $current_user_id) {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $role = trim($_POST['role'] ?? '');
            if ($user_id && $first_name && $last_name) {
                $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, role = ? WHERE id = ?');
                $stmt->execute([$first_name, $last_name, $role, $user_id]);
                $message = 'User details updated.';
            }
        } elseif ($action === 'delete' && $user_id) {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            $message = 'User account deleted.';
        } elseif ($action === 'approve' && $user_id) {
            $new_role = $_POST['new_role'] ?? null;
            $teacher_category = $_POST['teacher_category'] ?? null;

            $stmt = $pdo->prepare('UPDATE users SET approval_status = "approved", role = COALESCE(?, role), approved_by = ?, approved_at = NOW() WHERE id = ?');
            $stmt->execute([$new_role, $current_user_id, $user_id]);

            if ($teacher_category) {
                $check = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
                $check->execute([$user_id]);
                $u = $check->fetch();
                if ($u) {
                    $t_id = 'TCH-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);
                    $t_stmt = $pdo->prepare('INSERT INTO teachers (user_id, teacher_id, first_name, last_name, specialization) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE specialization = ?');
                    $t_stmt->execute([$user_id, $t_id, $u['first_name'], $u['last_name'], $teacher_category, $teacher_category]);
                }
            }
            syncEmployeeFromUser($pdo, $user_id);
            $message = 'User approved successfully!';
        } elseif ($action === 'reject' && $user_id) {
            $stmt = $pdo->prepare('UPDATE users SET approval_status = "rejected", approved_by = ?, approved_at = NOW() WHERE id = ?');
            $stmt->execute([$current_user_id, $user_id]);
            $message = 'User account rejected.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch data with error handling
$pending_users = [];
$recent_users = [];
try {
    $pending_users = $pdo->query('SELECT u.*, CONCAT(u.first_name, " ", u.last_name) as full_name FROM users u WHERE u.approval_status = "pending" AND (u.role IN ("teacher", "registrar") OR u.registered_role IN ("teacher", "registrar")) ORDER BY u.created_at ASC')->fetchAll();
    $recent_users = $pdo->query('SELECT u.*, CONCAT(u.first_name, " ", u.last_name) as full_name, app.username as approver FROM users u LEFT JOIN users app ON u.approved_by = app.id WHERE u.approval_status IN ("approved", "rejected") ORDER BY u.approved_at DESC LIMIT 15')->fetchAll();
} catch (Exception $e) {
    $error = "Error fetching data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personnel Approval | Admin Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary-rgb: 37, 99, 235;
            --glass-bg: rgba(255, 255, 255, 0.9);
            --glass-border: rgba(255, 255, 255, 0.5);
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            background-image: radial-gradient(at 0% 0%, hsla(210, 100%, 98%, 1) 0, transparent 50%),
                radial-gradient(at 100% 0%, hsla(220, 30%, 95%, 1) 0, transparent 50%);
            background-attachment: fixed;
            margin: 0;
            color: #0f172a;
        }

        .main-content {
            padding: calc(var(--header-height) + 32px) 24px 64px;
            max-width: 1400px;
            margin-left: var(--sidebar-width, 260px);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0 !important;
            }
        }

        .page-header {
            background: var(--primary-gradient);
            border-radius: 32px;
            padding: 48px;
            color: white;
            margin-bottom: 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
        }

        .page-header h1 {
            font-size: 36px;
            font-weight: 900;
            margin: 0;
            letter-spacing: -1.5px;
        }

        .page-header p {
            margin: 12px 0 0;
            opacity: 0.8;
            font-weight: 500;
        }

        .btn {
            background: #0f172a;
            color: white;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 900;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background: #10b981;
        }

        .btn-danger {
            background: #ef4444;
        }

        .btn-white {
            background: white;
            color: #0f172a;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            margin-top: 48px;
        }

        .section-header h2 {
            font-size: 14px;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin: 0;
            white-space: nowrap;
        }

        .section-line {
            height: 2px;
            background: #e2e8f0;
            flex: 1;
            border-radius: 2px;
        }

        .pending-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 24px;
        }

        .personnel-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 32px;
            display: flex;
            flex-direction: column;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .personnel-card:hover {
            transform: translateY(-6px);
            border-color: #2563eb;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.05);
        }

        .card-top {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
        }

        .avatar-box {
            width: 64px;
            height: 64px;
            background: #f1f5f9;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 900;
            color: #2563eb;
        }

        .name-info h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.5px;
        }

        .name-info p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
        }

        .card-details {
            background: #f8fafc;
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            color: #94a3b8;
            font-weight: 800;
            text-transform: uppercase;
        }

        .detail-value {
            color: #1e293b;
            font-weight: 700;
        }

        .card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: auto;
        }

        .form-select {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            border: 2px solid #f1f5f9;
            background: white;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #1e293b;
            cursor: pointer;
        }

        .table-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 0;
            overflow: hidden;
            margin-top: 32px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: #f8fafc;
            padding: 16px 24px;
            text-align: left;
            font-size: 11px;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .data-table td {
            padding: 18px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
        }

        .status-pill {
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-rejected {
            background: #fef2f2;
            color: #991b1b;
        }

        .alert {
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 32px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #dcfce7;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/admin_header.php'; ?>
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1>Personnel Approval</h1>
                <p>Manage access requests for teachers and staff members.</p>
            </div>
            <?php if ($pending_users): ?>
                <form method="post">
                    <input type="hidden" name="action" value="approve_all">
                    <button type="submit" class="btn btn-white clickable">Approve All
                        (<?= count($pending_users) ?>)</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><span>✅</span> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2;">
                <span>❌</span> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2>Pending Requests</h2>
            <div class="section-line"></div>
        </div>

        <?php if (!$pending_users): ?>
            <div class="personnel-card" style="text-align: center; padding: 64px;">
                <div style="font-size: 48px; margin-bottom: 16px;">✨</div>
                <h3 style="margin: 0; font-size: 20px; font-weight: 900;">All Clear!</h3>
                <p style="color: #64748b; font-weight: 500; margin-top: 8px;">No pending personnel requests at this moment.
                </p>
            </div>
        <?php else: ?>
            <div class="pending-grid">
                <?php foreach ($pending_users as $user): ?>
                    <div class="personnel-card">
                        <div class="card-top">
                            <div class="avatar-box">
                                <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?></div>
                            <div class="name-info">
                                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                                <p>@<?= htmlspecialchars($user['username']) ?></p>
                            </div>
                        </div>

                        <div class="card-details">
                            <div class="detail-row"><span class="detail-label">Request Date</span><span
                                    class="detail-value"><?= date('M d, Y', strtotime($user['created_at'])) ?></span></div>
                            <div class="detail-row"><span class="detail-label">Registering As</span><span
                                    class="detail-value"><?= ucfirst($user['registered_role'] ?: $user['role']) ?></span></div>
                        </div>

                        <form method="post">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                            <?php if ($user['role'] === 'registrar' || $user['registered_role'] === 'registrar'): ?>
                                <label class="detail-label" style="display: block; margin-bottom: 8px;">Select Portal Role</label>
                                <select name="new_role" class="form-select">
                                    <option value="registrar">Registrar Portal</option>
                                    <option value="admin">Administrator Portal</option>
                                </select>
                            <?php elseif ($user['role'] === 'teacher' || $user['registered_role'] === 'teacher'): ?>
                                <label class="detail-label" style="display: block; margin-bottom: 8px;">Select Teaching Load</label>
                                <select name="teacher_category" class="form-select">
                                    <option value="Subject Teacher">Subject Teacher</option>
                                    <option value="Adviser">Class Adviser (Advisory)</option>
                                </select>
                                <input type="hidden" name="new_role" value="teacher">
                            <?php endif; ?>

                            <div class="card-actions">
                                <button type="submit" name="action" value="approve"
                                    class="btn btn-success clickable">Approve</button>
                                <button type="submit" name="action" value="reject"
                                    class="btn btn-danger clickable">Reject</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2>Recent Activity</h2>
            <div class="section-line"></div>
        </div>

        <div class="table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Personnel Name</th>
                        <th>Role Assigned</th>
                        <th>Action Date</th>
                        <th>Status</th>
                        <th>Processed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recent_users): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 32px; color: #94a3b8;">No recent activity
                                found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_users as $user): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 800;"><?= htmlspecialchars($user['full_name']) ?></div>
                                    <div style="font-size: 11px; opacity: 0.6;">@<?= htmlspecialchars($user['username']) ?>
                                    </div>
                                </td>
                                <td><?= ucfirst($user['role']) ?></td>
                                <td><?= date('M d, Y • g:i A', strtotime($user['approved_at'])) ?></td>
                                <td><span
                                        class="status-pill status-<?= $user['approval_status'] ?>"><?= $user['approval_status'] ?></span>
                                </td>
                                <td><?= htmlspecialchars($user['approver'] ?: 'System') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>