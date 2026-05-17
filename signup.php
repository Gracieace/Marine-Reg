<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/config/db.php';

$error = '';
$success = '';

// Redirect logged-in users to their dashboard
$currentRole = auth_role();
if ($currentRole) {
	redirect_for_role($currentRole);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF validation for signup POST
	csrf_validate();
	$username = isset($_POST['username']) ? trim($_POST['username']) : '';
	$password = isset($_POST['password']) ? trim($_POST['password']) : '';
	$confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : '';
	$first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
	$last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
	$middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
	$role = isset($_POST['role']) ? trim($_POST['role']) : '';
	$department = isset($_POST['department']) ? trim($_POST['department']) : '';
	$custom_department = isset($_POST['custom_department']) ? trim($_POST['custom_department']) : '';
	$position_title = isset($_POST['position_title']) ? trim($_POST['position_title']) : '';


	// Determine final department value
	$final_department = $department;
	if ($department === 'other') {
		$final_department = $custom_department;
	}

	// Validation
	if ($username === '' || $password === '' || $confirm_password === '' || $first_name === '' || $last_name === '' || $role === '') {
		$error = 'All fields are required.';
	} elseif ($role === 'teacher' && (($department === '' && $custom_department === '') || $position_title === '')) {
		$error = 'Department and position are required for teaching personnel.';
	} elseif ($role === 'teacher' && $department === 'other' && $custom_department === '') {
		$error = 'Please specify a custom department name.';
	} elseif (strlen($username) < 3) {
		$error = 'Username must be at least 3 characters long.';
	} elseif (strlen($password) < 6) {
		$error = 'Password must be at least 6 characters long.';
	} elseif ($password !== $confirm_password) {
		$error = 'Passwords do not match.';
	} elseif (!in_array($role, ['registrar', 'teacher'])) {
		$error = 'Invalid role selected.';
	} else {
		try {
			$pdo = db_connect();

			// Check if username already exists
			$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
			$stmt->execute([$username]);
			if ($stmt->fetch()) {
				$error = 'Username already exists. Please choose a different username.';
			} else {
				// Determine approval status - only 'employee' (if internal) might be auto-approved
				// Registrar and Teacher require approval
				$isAutoApproved = in_array($role, ['employee'], true);
				$approval_status = $isAutoApproved ? 'approved' : 'pending';

				// Create new user
				$password_hash = password_hash($password, PASSWORD_DEFAULT);
				$stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, registered_role, first_name, last_name, middle_name, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
				$stmt->execute([$username, $password_hash, $role, $role, $first_name, $last_name, $middle_name, $approval_status]);

				$user_id = $pdo->lastInsertId();

				// Verify user was created successfully
				if (!$user_id || $user_id <= 0) {
					$error = 'Failed to create user account. Please try again.';
				} else {
					// If teacher, also create teacher record
					if ($role === 'teacher') {
						$teacher_id = 'TCH-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);
						$stmt = $pdo->prepare('INSERT INTO teachers (teacher_id, first_name, last_name, middle_name, department, specialization) VALUES (?, ?, ?, ?, ?, ?)');
						$stmt->execute([$teacher_id, $first_name, $last_name, $middle_name, $final_department, $position_title]);
					}

					// Enforce manual approval for all roles - redirect to login page with pending message
					if (!headers_sent()) {
						header('Location: ' . url_for('index.php') . '?signup=pending');
						exit;
					} else {
						echo '<script>window.location.href = "' . htmlspecialchars(url_for('index.php') . '?signup=pending', ENT_QUOTES) . '";</script>';
						exit;
					}
				}
			}
		} catch (Exception $e) {
			$error = 'Database error: ' . $e->getMessage();
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Sign Up - MMFS</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<style>
		:root {
			--primary: #2563eb;
			--primary-hover: #1d4ed8;
			--bg-gradient: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
			--card-bg: #ffffff;
			--text-main: #0f172a;
			--text-muted: #64748b;
			--border: #e2e8f0;
			--input-bg: #f8fafc;
			--shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
			--error-bg: #fef2f2;
			--error-text: #991b1b;
			--success-bg: #f0fdf4;
			--success-text: #166534;
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			background: var(--bg-gradient);
			color: var(--text-main);
			font-family: 'Inter', sans-serif;
			min-height: 100vh;
			display: grid;
			place-items: center;
			padding: 24px;
		}

		.card {
			width: 100%;
			max-width: 520px;
			background: var(--card-bg);
			border-radius: 20px;
			box-shadow: var(--shadow);
			border: 1px solid rgba(255, 255, 255, 0.8);
			padding: 40px;
		}

		.header {
			text-align: center;
			margin-bottom: 32px;
		}

		.brand-logo {
			width: 80px;
			height: 80px;
			object-fit: contain;
			margin-bottom: 16px;
			filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.05));
		}

		.title {
			margin: 0;
			font-size: 24px;
			font-weight: 700;
			color: var(--text-main);
			letter-spacing: -0.5px;
		}

		.subtitle {
			color: var(--text-muted);
			font-size: 14px;
			margin-top: 8px;
		}

		.form {
			display: grid;
			gap: 20px;
		}

		.field {
			display: grid;
			gap: 8px;
		}

		.label {
			font-size: 14px;
			font-weight: 600;
			color: #334155;
		}

		.input,
		.select {
			width: 100%;
			background: var(--input-bg);
			border: 1px solid var(--border);
			color: var(--text-main);
			padding: 12px 16px;
			border-radius: 10px;
			font-size: 14px;
			outline: none;
			transition: all 0.2s ease;
			font-family: inherit;
		}

		.input:focus,
		.select:focus {
			border-color: var(--primary);
			background: #fff;
			box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
		}

		.password-wrap {
			position: relative;
		}

		.toggle-btn {
			position: absolute;
			top: 50%;
			right: 12px;
			transform: translateY(-50%) translateY(2px);
			/* Adjust for label height */
			background: transparent;
			border: none;
			color: var(--text-muted);
			cursor: pointer;
			padding: 4px;
			border-radius: 4px;
			transition: color 0.2s;
		}

		/* Correction for toggle button position since it's inside grid with label */
		.field.password-wrap .toggle-btn {
			top: 38px;
			/* Approx label height + gap + half input */
			transform: translateY(-50%);
		}

		.toggle-btn:hover {
			color: var(--text-main);
		}

		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 100%;
			padding: 14px;
			border-radius: 10px;
			font-weight: 600;
			font-size: 15px;
			border: none;
			cursor: pointer;
			transition: all 0.2s;
			text-decoration: none;
		}

		.btn-primary {
			background: var(--primary);
			color: white;
			box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
		}

		.btn-primary:hover {
			background: var(--primary-hover);
			transform: translateY(-1px);
		}

		.btn-secondary {
			background: white;
			color: var(--text-muted);
			border: 1px solid var(--border);
			margin-top: 12px;
		}

		.btn-secondary:hover {
			background: #f8fafc;
			color: var(--text-main);
			border-color: #cbd5e1;
		}

		.alert {
			padding: 14px;
			border-radius: 10px;
			font-size: 14px;
			margin-bottom: 24px;
			font-weight: 500;
		}

		.alert-error {
			background: var(--error-bg);
			color: var(--error-text);
			border: 1px solid #fecaca;
		}

		.alert-success {
			background: var(--success-bg);
			color: var(--success-text);
			border: 1px solid #bbf7d0;
		}

		.name-row {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
		}

		.teacher-fields {
			display: grid;
			gap: 16px;
			margin-top: 4px;
			padding: 20px;
			background: #f0f9ff;
			border: 1px solid #bae6fd;
			border-radius: 12px;
		}

		.footer {
			margin-top: 32px;
			text-align: center;
			color: var(--text-muted);
			font-size: 13px;
		}

		.footer a {
			color: var(--primary);
			text-decoration: none;
			font-weight: 500;
		}

		.footer a:hover {
			text-decoration: underline;
		}

		@media (max-width: 480px) {
			.name-row {
				grid-template-columns: 1fr;
			}

			.card {
				padding: 24px;
			}
		}
	</style>
</head>

<body>
	<div class="card">
		<div class="header">
			<img src="assets/images/school_logo.png" alt="School Logo" class="brand-logo">
			<h1 class="title">Create Account</h1>
			<div class="subtitle">Join Malolos Marine Fishery School Portal</div>
		</div>

		<?php if ($error): ?>
			<div class="alert alert-error">
				<?php echo htmlspecialchars($error); ?>
			</div>
		<?php endif; ?>

		<?php if ($success): ?>
			<div class="alert alert-success">
				<?php echo htmlspecialchars($success); ?>
			</div>
		<?php endif; ?>

		<form class="form" method="post"
			action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="on">
			<?php echo csrf_field(); ?>

			<div class="name-row">
				<div class="field">
					<label for="first_name" class="label">First Name</label>
					<input class="input" type="text" id="first_name" name="first_name"
						value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required />
				</div>
				<div class="field">
					<label for="last_name" class="label">Last Name</label>
					<input class="input" type="text" id="last_name" name="last_name"
						value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required />
				</div>
			</div>

			<div class="field">
				<label for="middle_name" class="label">Middle Name <span
						style="font-weight:400; color:#94a3b8">(Optional)</span></label>
				<input class="input" type="text" id="middle_name" name="middle_name"
					value="<?php echo htmlspecialchars($middle_name ?? ''); ?>" />
			</div>

			<div class="field">
				<label for="username" class="label">Username</label>
				<input class="input" type="text" id="username" name="username"
					value="<?php echo htmlspecialchars($username ?? ''); ?>" required autocomplete="username" />
			</div>

			<div class="field">
				<label for="role" class="label">Role</label>
				<select class="select" id="role" name="role" required>
					<option value="">Select your role</option>
					<option value="teacher" <?php echo (isset($role) && $role === 'teacher') ? 'selected' : ''; ?>>Teaching Personnel
					</option>
					<option value="registrar" <?php echo (isset($role) && $role === 'registrar') ? 'selected' : ''; ?>>
						Non-Teaching Personnel</option>
				</select>
			</div>

			<!-- Teaching Personnel-specific fields -->
			<div id="teacher-fields" class="teacher-fields" style="display: none;">
				<div class="field">
					<label for="department" class="label">Department</label>
					<select class="select" id="department" name="department">
						<option value="">Select department</option>
						<option value="JHS Teacher" <?php echo (isset($department) && $department === 'JHS Teacher') ? 'selected' : ''; ?>>JHS Teaching Personnel</option>
						<option value="SHS Teacher" <?php echo (isset($department) && $department === 'SHS Teacher') ? 'selected' : ''; ?>>SHS Teaching Personnel</option>
						<option value="other">Other (specify below)</option>
					</select>
				</div>

				<div class="field" id="custom-department-field" style="display: none;">
					<label for="custom_department" class="label">Custom Department</label>
					<input class="input" type="text" id="custom_department" name="custom_department"
						placeholder="Enter department name"
						value="<?php echo htmlspecialchars($custom_department ?? ''); ?>" />
				</div>

				<div class="field">
					<label for="position_title" class="label">Position</label>
					<select class="select" id="position_title" name="position_title">
						<option value="">Select position</option>
						<optgroup label="Teaching Personnel Positions">
							<option value="Teacher I" <?php echo (isset($position_title) && $position_title === 'Teacher I') ? 'selected' : ''; ?>>Teacher I</option>
							<option value="Teacher II" <?php echo (isset($position_title) && $position_title === 'Teacher II') ? 'selected' : ''; ?>>Teacher II</option>
							<option value="Teacher III" <?php echo (isset($position_title) && $position_title === 'Teacher III') ? 'selected' : ''; ?>>Teacher III</option>
							<option value="Teacher IV" <?php echo (isset($position_title) && $position_title === 'Teacher IV') ? 'selected' : ''; ?>>Teacher IV</option>
							<option value="Teacher V" <?php echo (isset($position_title) && $position_title === 'Teacher V') ? 'selected' : ''; ?>>Teacher V</option>
							<option value="Teacher VI" <?php echo (isset($position_title) && $position_title === 'Teacher VI') ? 'selected' : ''; ?>>Teacher VI</option>
							<option value="Teacher VII" <?php echo (isset($position_title) && $position_title === 'Teacher VII') ? 'selected' : ''; ?>>Teacher VII</option>
						</optgroup>
						<!-- Other optgroups omitted for brevity but preserved in logic if needed, adding simple implementation for now -->
						<optgroup label="Master Teacher Positions">
							<option value="Master Teacher I" <?php echo (isset($position_title) && $position_title === 'Master Teacher I') ? 'selected' : ''; ?>>Master Teacher I</option>
							<option value="Master Teacher II" <?php echo (isset($position_title) && $position_title === 'Master Teacher II') ? 'selected' : ''; ?>>Master Teacher II</option>
						</optgroup>
						<optgroup label="Principal Positions">
							<option value="Principal I" <?php echo (isset($position_title) && $position_title === 'Principal I') ? 'selected' : ''; ?>>Principal I</option>
							<option value="Principal II" <?php echo (isset($position_title) && $position_title === 'Principal II') ? 'selected' : ''; ?>>Principal II</option>
						</optgroup>
					</select>
				</div>
			</div>

			<div class="field password-wrap">
				<label for="password" class="label">Password</label>
				<input class="input" type="password" id="password" name="password" required
					autocomplete="new-password" />
				<button type="button" class="toggle-btn" id="togglePassword">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
						<circle cx="12" cy="12" r="3" />
					</svg>
				</button>
			</div>

			<div class="field password-wrap">
				<label for="confirm_password" class="label">Confirm Password</label>
				<input class="input" type="password" id="confirm_password" name="confirm_password" required
					autocomplete="new-password" />
			</div>

			<div style="margin-top: 12px;">
				<button type="submit" class="btn btn-primary">Create Account</button>
				<a href="<?php echo htmlspecialchars(url_for('index.php'), ENT_QUOTES, 'UTF-8'); ?>"
					class="btn btn-secondary">Back to Sign In</a>
			</div>
		</form>

		<div class="footer">
			By creating an account you agree to our <a href="#">Terms</a> and <a href="#">Privacy Policy</a>.
		</div>
	</div>

	<script>
		// Password toggle
		const toggleBtn = document.getElementById('togglePassword');
		const passwordInput = document.getElementById('password');
		const confirmInput = document.getElementById('confirm_password');

		toggleBtn.addEventListener('click', () => {
			const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
			passwordInput.setAttribute('type', type);
			confirmInput.setAttribute('type', type); // Toggle both for convenience

			// Update icon
			toggleBtn.innerHTML = type === 'text'
				? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20C5 20 1 12 1 12a21.8 21.8 0 0 1 5.06-6.94"/><path d="M10.58 10.58a2 2 0 1 0 2.83 2.83"/><path d="M16.24 7.76A10.94 10.94 0 0 1 23 12s-4 8-11 8"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
				: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
		});

		// Dynamic fields
		const roleSelect = document.getElementById('role');
		const teacherFields = document.getElementById('teacher-fields');
		const deptSelect = document.getElementById('department');
		const customDeptField = document.getElementById('custom-department-field');
		const customDeptInput = document.getElementById('custom_department');
		const posSelect = document.getElementById('position_title');

		function updateFields() {
			if (roleSelect.value === 'teacher') {
				teacherFields.style.display = 'grid';
				deptSelect.required = true;
				posSelect.required = true;
			} else {
				teacherFields.style.display = 'none';
				deptSelect.required = false;
				posSelect.required = false;
			}
		}

		function updateCustomDept() {
			if (deptSelect.value === 'other') {
				customDeptField.style.display = 'grid';
				customDeptInput.required = true;
			} else {
				customDeptField.style.display = 'none';
				customDeptInput.required = false;
			}
		}

		roleSelect.addEventListener('change', updateFields);
		deptSelect.addEventListener('change', updateCustomDept);

		// Init
		updateFields();
		updateCustomDept();
	</script>
</body>

</html>