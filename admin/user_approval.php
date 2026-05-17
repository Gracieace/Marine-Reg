<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['registrar']);

$pdo = db_connect();
initialize_schema($pdo);
$message = '';
$error = '';

// Handle approval/rejection/edit/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$user_id = $_POST['user_id'] ?? '';
	$current_user_id = auth_user()['id'] ?? null;

	try {
		if ($action === 'approve_all') {
			// Get pending teachers
			$stmt = $pdo->prepare('SELECT id, first_name, last_name, role, email FROM users WHERE approval_status = "pending" AND role = "teacher"');
			$stmt->execute();
			$pending = $stmt->fetchAll();
			
			if ($pending) {
				$pdo->beginTransaction();
				try {
					$approved_count = 0;
					foreach ($pending as $u) {
						// Approve user
						$app_stmt = $pdo->prepare('UPDATE users SET approval_status = "approved", approved_by = ?, approved_at = NOW() WHERE id = ?');
						$app_stmt->execute([$current_user_id, $u['id']]);
						
						// Also create employee record if not exists
						$full_name = trim($u['first_name'] . ' ' . $u['last_name']);
						$emp_check = $pdo->prepare('SELECT id FROM employees WHERE email = ? OR full_name = ?');
						$emp_check->execute([$u['email'], $full_name]);
						
						// Sync to employees
						syncEmployeeFromUser($pdo, $u['id']);
						$approved_count++;
					}
					$pdo->commit();
					$message = $approved_count . ' pending personnel approved and synced to employees successfully!';
				} catch (Exception $e) {
					$pdo->rollBack();
					$error = 'Error during bulk approval: ' . $e->getMessage();
				}
			} else {
				$message = 'No pending personnel to approve.';
			}
		} elseif ($action === 'update_user' && $current_user_id) {
			$first_name = trim($_POST['first_name'] ?? '');
			$last_name = trim($_POST['last_name'] ?? '');
			$middle_name = trim($_POST['middle_name'] ?? '');
			$role = trim($_POST['role'] ?? '');
			if ($user_id && $first_name !== '' && $last_name !== '' && in_array($role, ['admin', 'registrar', 'teacher', 'student', 'employee'], true)) {
				$stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, role = ? WHERE id = ?');
				$stmt->execute([$first_name, $last_name, $middle_name !== '' ? $middle_name : null, $role, $user_id]);
				$message = 'User details updated.';
			} else {
				$error = 'Please provide valid user details.';
			}
		} elseif ($action === 'delete') {
			if ($user_id) {
				if ($current_user_id && (int) $user_id === (int) $current_user_id) {
					$error = 'You cannot delete your own account.';
				} else {
					$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
					$stmt->execute([$user_id]);
					$message = 'User account deleted.';
				}
			} else {
				$error = 'Missing user ID.';
			}
		} elseif ($action && $user_id) {
			if ($action === 'approve') {
				$stmt = $pdo->prepare('UPDATE users SET approval_status = "approved", approved_by = ?, approved_at = NOW() WHERE id = ?');
				$stmt->execute([$current_user_id, $user_id]);
				
				// Also ensure they are in the employees table
				$check_user = $pdo->prepare('SELECT first_name, last_name, role, email FROM users WHERE id = ?');
				$check_user->execute([$user_id]);
				$u_data = $check_user->fetch();
				
				if ($u_data) {
					$full_name = trim($u_data['first_name'] . ' ' . $u_data['last_name']);
					$emp_check = $pdo->prepare('SELECT id FROM employees WHERE (email IS NOT NULL AND email = ?) OR full_name = ?');
					$emp_check->execute([$u_data['email'], $full_name]);
					
					// Sync to employees
					syncEmployeeFromUser($pdo, $user_id);
				}

				$message = 'User account approved and synced to employees successfully!';
			} elseif ($action === 'reject') {
				$stmt = $pdo->prepare('UPDATE users SET approval_status = "rejected", approved_by = ?, approved_at = NOW() WHERE id = ?');
				$stmt->execute([$current_user_id, $user_id]);
				$message = 'User account rejected.';
			} else {
				$error = 'Unknown action.';
			}
		}
	} catch (Exception $e) {
		$error = 'Database error: ' . $e->getMessage();
	}
}

// Get pending users
try {
	$stmt = $pdo->query('
		SELECT u.*, 
		       CONCAT(u.first_name, " ", u.last_name) as full_name,
		       approver.username as approved_by_username
		FROM users u 
		LEFT JOIN users approver ON u.approved_by = approver.id
		WHERE u.approval_status = "pending" AND (u.role = "teacher" OR u.registered_role = "teacher") 
		ORDER BY u.created_at ASC
	');
	$pending_users = $stmt->fetchAll();
} catch (Exception $e) {
	$pending_users = [];
	$error = 'Database error: ' . $e->getMessage();
}

// Get recently approved/rejected users
try {
	$stmt = $pdo->query('
		SELECT u.*, 
		       CONCAT(u.first_name, " ", u.last_name) as full_name,
		       approver.username as approved_by_username
		FROM users u 
		LEFT JOIN users approver ON u.approved_by = approver.id
		WHERE u.approval_status IN ("approved", "rejected") 
		ORDER BY u.approved_at DESC
		LIMIT 20
	');
	$recent_users = $stmt->fetchAll();
} catch (Exception $e) {
	$recent_users = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>User Approval</title>
	<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
	<style>
		.approval-card {
			background: white;
			border-radius: 8px;
			padding: 20px;
			margin-bottom: 20px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
			border-left: 4px solid #3b82f6;
		}

		.approved-card {
			border-left-color: #10b981;
		}

		.rejected-card {
			border-left-color: #ef4444;
		}

		.user-info {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
			margin-bottom: 15px;
		}

		.info-group h4 {
			margin: 0 0 5px 0;
			color: #374151;
			font-size: 14px;
			font-weight: 600;
		}

		.info-group p {
			margin: 0;
			color: #6b7280;
			font-size: 14px;
		}

		.action-buttons {
			display: flex;
			gap: 10px;
		}

		.btn-secondary {
			background: #e5e7eb;
			color: #111827;
		}

		.btn-secondary:hover {
			background: #d1d5db;
		}

		.btn {
			padding: 8px 16px;
			border: none;
			border-radius: 6px;
			cursor: pointer;
			font-size: 14px;
			font-weight: 500;
			transition: all 0.2s;
		}

		.btn-approve {
			background: #10b981;
			color: white;
		}

		.btn-approve:hover {
			background: #059669;
		}

		.btn-reject {
			background: #ef4444;
			color: white;
		}

		.btn-reject:hover {
			background: #dc2626;
		}

		.status-badge {
			display: inline-block;
			padding: 4px 8px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 500;
		}

		.status-pending {
			background: #fef3c7;
			color: #92400e;
		}

		.status-approved {
			background: #d1fae5;
			color: #065f46;
		}

		.status-rejected {
			background: #fee2e2;
			color: #991b1b;
		}

		/* Table styles for Recent Actions */
		.table {
			width: 100%;
			border-collapse: collapse;
			background: white;
			border-radius: 8px;
			overflow: hidden;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}

		.table th,
		.table td {
			padding: 12px 14px;
			text-align: left;
			font-size: 14px;
			color: #374151;
		}

		.table thead th {
			background: #f3f4f6;
			font-weight: 600;
			color: #111827;
			border-bottom: 1px solid #e5e7eb;
		}

		.table tbody tr:not(:last-child) td {
			border-bottom: 1px solid #f3f4f6;
		}

		.section-title {
			font-size: 18px;
			font-weight: 600;
			color: #111827;
			margin-bottom: 15px;
		}

		.empty-state {
			text-align: center;
			padding: 40px 20px;
			color: #6b7280;
		}
	</style>
</head>

<body>
	<?php require_once dirname(__DIR__) . '/header.php'; ?>
	<?php require_once dirname(__DIR__) . '/registrar/registrar_side_panel.php'; ?>

	<div class="content">
		<div class="page-header">
			<h1>User Approval</h1>
			<p>Review and approve pending user accounts</p>
		</div>

		<form method="post" style="margin-bottom: 16px;">
			<input type="hidden" name="action" value="approve_all">
			<button type="submit" class="btn btn-approve"
				onclick="return confirm('Approve all pending user accounts?')">Approve All Pending</button>
		</form>

		<?php if ($message): ?>
			<div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
				<?php echo htmlspecialchars($message); ?>
			</div>
		<?php endif; ?>

		<?php if ($error): ?>
			<div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
				<?php echo htmlspecialchars($error); ?>
			</div>
		<?php endif; ?>

		<!-- Pending Users Section -->
		<div class="section-title">Pending Approval (<?php echo count($pending_users); ?>)</div>

		<?php if (empty($pending_users)): ?>
			<div class="empty-state">
				<p>No pending user accounts to review.</p>
			</div>
		<?php else: ?>
			<?php foreach ($pending_users as $user): ?>
				<div class="approval-card">
					<div class="user-info">
						<div class="info-group">
							<h4>User Information</h4>
							<p><strong>Name:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
							<p><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
							<p><strong>Role:</strong> <?php echo ucfirst(htmlspecialchars($user['role'])); ?></p>
						</div>
						<div class="info-group">
							<h4>Account Details</h4>
							<p><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($user['created_at'])); ?></p>
							<p><strong>Status:</strong> <span class="status-badge status-pending">Pending</span></p>
						</div>
					</div>

					<?php if ($user['role'] === 'teacher'): ?>
						<?php
						// Get teacher details
						$teacher_stmt = $pdo->prepare('SELECT department, specialization FROM teachers WHERE teacher_id LIKE ?');
						$teacher_stmt->execute(['TCH-' . str_pad($user['id'], 4, '0', STR_PAD_LEFT)]);
						$teacher = $teacher_stmt->fetch();
						?>
						<?php if ($teacher): ?>
							<div class="info-group" style="margin-bottom: 15px;">
								<h4>Teacher Information</h4>
								<p><strong>Department:</strong> <?php echo htmlspecialchars($teacher['department']); ?></p>
								<p><strong>Position:</strong> <?php echo htmlspecialchars($teacher['specialization']); ?></p>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<div class="action-buttons">
						<form method="post" style="display: inline;">
							<input type="hidden" name="action" value="approve">
							<input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
							<button type="submit" class="btn btn-approve"
								onclick="return confirm('Approve this user account?')">Approve</button>
						</form>
						<form method="post" style="display: inline;">
							<input type="hidden" name="action" value="reject">
							<input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
							<button type="submit" class="btn btn-reject"
								onclick="return confirm('Reject this user account?')">Reject</button>
						</form>
						<button type="button" class="btn btn-secondary"
							onclick="openViewModal(<?php echo (int) $user['id']; ?>)">View</button>
						<button type="button" class="btn" style="background:#3b82f6;color:#fff;"
							onclick="openEditModal(<?php echo (int) $user['id']; ?>,'<?php echo htmlspecialchars($user['first_name'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['last_name'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['middle_name'] ?? '', ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['role'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['registered_role'] ?? $user['role'], ENT_QUOTES); ?>')">Edit</button>
						<form method="post" style="display:inline;"
							onsubmit="return confirm('Delete this user account? This cannot be undone.');">
							<input type="hidden" name="action" value="delete">
							<input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
							<button type="submit" class="btn" style="background:#ef4444;color:#fff;">Delete</button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<!-- Recent Actions Section -->
		<?php if (!empty($recent_users)): ?>
			<div class="section-title" style="margin-top: 40px;">Recent Actions</div>
			<table class="table">
				<thead>
					<tr>
						<th>Name</th>
						<th>Username</th>
						<th>Role</th>
						<th>Status</th>
						<th>Action Date</th>
						<th>Approved By</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($recent_users as $user): ?>
						<tr>
							<td><?php echo htmlspecialchars($user['full_name']); ?></td>
							<td><?php echo htmlspecialchars($user['username']); ?></td>
							<td><?php echo ucfirst(htmlspecialchars($user['role'])); ?></td>
							<td>
								<span class="status-badge status-<?php echo $user['approval_status']; ?>">
									<?php echo ucfirst($user['approval_status']); ?>
								</span>
							</td>
							<td><?php echo date('M j, Y g:i A', strtotime($user['approved_at'])); ?></td>
							<td><?php echo htmlspecialchars($user['approved_by_username'] ?? 'System'); ?></td>
							<td>
								<button type="button" class="btn btn-secondary"
									onclick="openViewModal(<?php echo (int) $user['id']; ?>)">View</button>
								<button type="button" class="btn" style="background:#3b82f6;color:#fff;"
									onclick="openEditModal(<?php echo (int) $user['id']; ?>,'<?php echo htmlspecialchars($user['first_name'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['last_name'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['middle_name'] ?? '', ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['role'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($user['registered_role'] ?? $user['role'], ENT_QUOTES); ?>')">Edit</button>
								<form method="post" style="display:inline;"
									onsubmit="return confirm('Delete this user account? This cannot be undone.');">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
									<button type="submit" class="btn" style="background:#ef4444;color:#fff;">Delete</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</body>

</html>

<!-- Simple View/Edit Modals -->
<div id="viewModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
	<div class="modal">
		<div class="modal-header">User Details</div>
		<div id="viewContent" style="color:#374151; font-size:14px;"></div>
		<div class="modal-actions">
			<button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
		</div>
	</div>
</div>

<div id="editModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
	<div class="modal">
		<div class="modal-header">Edit User</div>
		<form method="post">
			<input type="hidden" name="action" value="update_user">
			<input type="hidden" name="user_id" id="edit_user_id" value="">
			<div class="field">
				<label>First Name</label>
				<input type="text" name="first_name" id="edit_first_name" class="input" required>
			</div>
			<div class="field" style="margin-top:10px;">
				<label>Last Name</label>
				<input type="text" name="last_name" id="edit_last_name" class="input" required>
			</div>
			<div class="field" style="margin-top:10px;">
				<label>Middle Name</label>
				<input type="text" name="middle_name" id="edit_middle_name" class="input">
			</div>
			<div class="field" style="margin-top:10px;">
				<label>Role</label>
				<select name="role" id="edit_role" class="select" required>
					<option value="admin">Admin</option>
					<option value="registrar">Non-Teaching Personnel</option>
					<option value="teacher">Teacher</option>
					<option value="student">Student</option>
					<option value="employee">Employee</option>
				</select>
			</div>
			<div class="modal-actions">
				<button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
				<button type="submit" class="btn btn-approve">Save</button>
			</div>
		</form>
	</div>
</div>

<script>
	function openViewModal(id) {
		var modal = document.getElementById('viewModal');
		var content = document.getElementById('viewContent');
		content.innerHTML = 'User ID: ' + id + '<br/>Use the Edit button to see full editable details.';
		modal.style.display = 'flex';
	}
	function closeViewModal() {
		document.getElementById('viewModal').style.display = 'none';
	}

	function openEditModal(id, first, last, middle, role, registeredRole) {
		document.getElementById('edit_user_id').value = id;
		document.getElementById('edit_first_name').value = first || '';
		document.getElementById('edit_last_name').value = last || '';
		document.getElementById('edit_middle_name').value = middle || '';
		
		const roleSelect = document.getElementById('edit_role');
		// Filter options based on registered role
		for (let i = 0; i < roleSelect.options.length; i++) {
			const opt = roleSelect.options[i];
			if (registeredRole === 'teacher') {
				opt.hidden = (opt.value !== 'teacher');
				opt.disabled = (opt.value !== 'teacher');
			} else if (registeredRole === 'registrar') {
				opt.hidden = !(opt.value === 'registrar' || opt.value === 'admin');
				opt.disabled = !(opt.value === 'registrar' || opt.value === 'admin');
			} else {
				opt.hidden = false;
				opt.disabled = false;
			}
		}
		
		roleSelect.value = role || 'student';
		document.getElementById('editModal').style.display = 'flex';
	}
	function closeEditModal() {
		document.getElementById('editModal').style.display = 'none';
	}
</script>