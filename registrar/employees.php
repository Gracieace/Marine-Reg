<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['registrar']);

$pdo = db_connect();
$message = '';
$error = '';
$import_info = '';

// Function to generate unique employee code
function generateEmployeeCode($pdo) {
	// Get current year
	$year = date('Y');
	
	// Find the highest employee code for this year
	$stmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_code LIKE ? ORDER BY employee_code DESC LIMIT 1");
	$stmt->execute(["EMP-{$year}-%"]);
	$lastCode = $stmt->fetchColumn();
	
	if ($lastCode) {
		// Extract the number from the last code
		preg_match('/EMP-' . $year . '-(\d+)/', $lastCode, $matches);
		$nextNumber = (int)($matches[1] ?? 0) + 1;
	} else {
		// First employee for this year
		$nextNumber = 1;
	}
	
	// Format with leading zeros (3 digits)
	$newCode = sprintf("EMP-%s-%03d", $year, $nextNumber);
	
	// Double-check for uniqueness (safety measure)
	$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code = ?");
	$checkStmt->execute([$newCode]);
	$exists = $checkStmt->fetchColumn();
	
	// If somehow a duplicate exists, increment until we find a unique one
	while ($exists > 0) {
		$nextNumber++;
		$newCode = sprintf("EMP-%s-%03d", $year, $nextNumber);
		$checkStmt->execute([$newCode]);
		$exists = $checkStmt->fetchColumn();
	}
	
	return $newCode;
}

// Function to check for duplicate employees
function checkDuplicateEmployee($pdo, $full_name, $email = null, $contact_number = null, $exclude_id = null) {
	$conditions = [];
	$params = [];
	
	// Check for duplicate email
	if ($email && trim($email) !== '') {
		$conditions[] = "email = ?";
		$params[] = trim($email);
	}
	
	// Check for duplicate contact number
	if ($contact_number && trim($contact_number) !== '') {
		$conditions[] = "contact_number = ?";
		$params[] = trim($contact_number);
	}
	
	// If no unique identifiers provided, return false (no duplicates)
	if (empty($conditions)) {
		return false;
	}
	
	// Build the query
	$sql = "SELECT id, full_name, email, contact_number FROM employees WHERE (" . implode(' OR ', $conditions) . ")";
	
	// Exclude current employee if editing
	if ($exclude_id) {
		$sql .= " AND id != ?";
		$params[] = $exclude_id;
	}
	
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$duplicate = $stmt->fetch();
	
	return $duplicate;
}

// Handle CSV template download
if (isset($_GET['template']) && $_GET['template'] === 'csv') {
	header('Content-Type: text/csv');
	header('Content-Disposition: attachment; filename="employees_template.csv"');
	$headers = [
		'full_name','email','contact_number','department','position_title','date_hired','is_active'
	];
	echo implode(',', $headers) . "\r\n";
	// sample row
	echo 'Juan M. Dela Cruz,juan@school.edu.ph,09XX-XXX-XXXX,Science,Teacher II,2025-06-01,1' . "\r\n";
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? $_POST['action'] : 'add';
	if ($action === 'delete') {
		$empId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
		if ($empId <= 0) {
			$error = 'Invalid employee selected for deletion.';
		} else {
			try {
				$del = $pdo->prepare('DELETE FROM employees WHERE id = ?');
				$del->execute([$empId]);
				$message = 'Employee deleted successfully.';
			} catch (Exception $e) {
				$error = 'Failed to delete employee: ' . $e->getMessage();
			}
		}
	} else if ($action === 'edit') {
		$empId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
		$full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
		$email = isset($_POST['email']) ? trim($_POST['email']) : null;
		$contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
		$department = isset($_POST['department']) ? trim($_POST['department']) : null;
		$position_title = isset($_POST['position_title']) ? trim($_POST['position_title']) : null;
		$date_hired = isset($_POST['date_hired']) ? trim($_POST['date_hired']) : null;
		$is_active = isset($_POST['is_active']) ? 1 : 0;

		if ($full_name === '') {
			$error = 'Full Name is required.';
		} else {
			// Check for duplicates (excluding current employee)
			$duplicate = checkDuplicateEmployee($pdo, $full_name, $email, $contact_number, $empId);
			if ($duplicate) {
				$duplicateInfo = [];
				if ($duplicate['email'] && $email && trim($email) === trim($duplicate['email'])) {
					$duplicateInfo[] = "Email address '{$duplicate['email']}'";
				}
				if ($duplicate['contact_number'] && $contact_number && trim($contact_number) === trim($duplicate['contact_number'])) {
					$duplicateInfo[] = "Contact number '{$duplicate['contact_number']}'";
				}
				$error = 'Duplicate employee found. The following information already exists for employee "' . $duplicate['full_name'] . '": ' . implode(' and ', $duplicateInfo) . '.';
			} else {
				try {
					$stmt = $pdo->prepare('UPDATE employees SET full_name=?, email=?, contact_number=?, department=?, position_title=?, date_hired=?, is_active=? WHERE id=?');
					$stmt->execute([$full_name, $email, $contact_number, $department, $position_title, $date_hired ?: null, $is_active, $empId]);
					$message = 'Employee updated successfully!';
				} catch (PDOException $e) {
					$error = 'Database error: ' . $e->getMessage();
				}
			}
		}
	} else if ($action === 'import') {
		if (!isset($_FILES['import_file']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
			$error = 'No file uploaded.';
		} else {
			$filename = $_FILES['import_file']['name'];
			$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
			if (!in_array($ext, ['csv'])) {
				$error = 'Unsupported file type. Please upload a CSV file.';
			} else {
				$handle = fopen($_FILES['import_file']['tmp_name'], 'r');
				if ($handle === false) {
					$error = 'Failed to open uploaded file.';
				} else {
					$header = fgetcsv($handle);
					if ($header === false) {
						$error = 'CSV appears to be empty.';
					} else {
						$map = [];
						foreach ($header as $i => $h) {
							$key = strtolower(trim($h));
							$map[$key] = $i;
						}
						$required = ['full_name'];
						$missing = [];
						foreach ($required as $r) { if (!array_key_exists($r, $map)) { $missing[] = $r; } }
						if ($missing) {
							$error = 'Missing required columns: ' . implode(', ', $missing);
						} else {
							$insert = $pdo->prepare('INSERT INTO employees (employee_code, full_name, email, contact_number, department, position_title, date_hired, is_active) VALUES (?,?,?,?,?,?,?,?)');
							$added = 0; $duplicates = 0; $failed = 0; $rownum = 1;
							while (($row = fgetcsv($handle)) !== false) {
								$rownum++;
								$full_name = isset($row[$map['full_name']]) ? trim($row[$map['full_name']]) : '';
								$email = isset($map['email']) && isset($row[$map['email']]) ? trim($row[$map['email']]) : null;
								$contact_number = isset($map['contact_number']) && isset($row[$map['contact_number']]) ? trim($row[$map['contact_number']]) : null;
								$department = isset($map['department']) && isset($row[$map['department']]) ? trim($row[$map['department']]) : null;
								$position_title = isset($map['position_title']) && isset($row[$map['position_title']]) ? trim($row[$map['position_title']]) : null;
								$date_hired = isset($map['date_hired']) && isset($row[$map['date_hired']]) ? trim($row[$map['date_hired']]) : null;
								$is_active_val = isset($map['is_active']) && isset($row[$map['is_active']]) ? trim($row[$map['is_active']]) : '';
								$is_active = ($is_active_val === '' ? 1 : (in_array(strtolower($is_active_val), ['1','true','yes','y']) ? 1 : 0));
								if ($full_name === '') { $failed++; continue; }
								
								// Check for duplicates before inserting
								$duplicate = checkDuplicateEmployee($pdo, $full_name, $email, $contact_number);
								if ($duplicate) {
									$duplicates++;
									continue;
								}
								
								try {
									$employee_code = generateEmployeeCode($pdo);
									$insert->execute([$employee_code, $full_name, $email ?: null, $contact_number ?: null, $department ?: null, $position_title ?: null, $date_hired ?: null, $is_active]);
									$added++;
								} catch (PDOException $e) {
									if ((int)$e->getCode() === 23000) { $duplicates++; } else { $failed++; }
								}
							}
							fclose($handle);
							$import_info = "Imported: $added, Duplicates: $duplicates, Failed: $failed.";
							if ($added > 0) { $message = 'Import completed.'; } else if ($duplicates > 0 && $failed === 0) { $message = 'All rows duplicate; no new records.'; } else if ($failed > 0 && $added === 0) { $error = 'Import failed for all rows.'; }
						}
					}
			}
		}
	}
	} else {
		$full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
		$email = isset($_POST['email']) ? trim($_POST['email']) : null;
		$contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
		$department = isset($_POST['department']) ? trim($_POST['department']) : null;
		$position_title = isset($_POST['position_title']) ? trim($_POST['position_title']) : null;
		$date_hired = isset($_POST['date_hired']) ? trim($_POST['date_hired']) : null;
		$is_active = isset($_POST['is_active']) ? 1 : 0;

		if ($full_name === '') {
			$error = 'Full Name is required.';
		} else {
			// Check for duplicates
			$duplicate = checkDuplicateEmployee($pdo, $full_name, $email, $contact_number);
			if ($duplicate) {
				$duplicateInfo = [];
				if ($duplicate['email'] && $email && trim($email) === trim($duplicate['email'])) {
					$duplicateInfo[] = "Email address '{$duplicate['email']}'";
				}
				if ($duplicate['contact_number'] && $contact_number && trim($contact_number) === trim($duplicate['contact_number'])) {
					$duplicateInfo[] = "Contact number '{$duplicate['contact_number']}'";
				}
				$error = 'Duplicate employee found. The following information already exists for employee "' . $duplicate['full_name'] . '": ' . implode(' and ', $duplicateInfo) . '.';
			} else {
				try {
					$employee_code = generateEmployeeCode($pdo);
					$stmt = $pdo->prepare('INSERT INTO employees (employee_code, full_name, email, contact_number, department, position_title, date_hired, is_active) VALUES (?,?,?,?,?,?,?,?)');
					$stmt->execute([$employee_code, $full_name, $email, $contact_number, $department, $position_title, $date_hired ?: null, $is_active]);
					$message = 'Employee added successfully with code: ' . $employee_code;
				} catch (PDOException $e) {
					$error = 'Database error: ' . $e->getMessage();
				}
			}
		}
	}
}

// Handle edit request
$editEmployee = null;
if (isset($_GET['edit'])) {
	$editId = (int)$_GET['edit'];
	try {
		$stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
		$stmt->execute([$editId]);
		$editEmployee = $stmt->fetch();
	} catch (Exception $e) {
		$error = 'Failed to fetch employee data: ' . $e->getMessage();
	}
}

// Fetch employees for listing
try {
	$listStmt = $pdo->query('SELECT id, employee_code, full_name, email, contact_number, department, position_title, is_active, created_at FROM employees ORDER BY employee_code ASC');
	$employees = $listStmt->fetchAll();
} catch (Exception $e) {
	$employees = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Employee Management</title>
	<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
	<style>
		:root {
			--bg: #f6f8fc;
			--card: #ffffff;
			--muted: #64748b;
			--border: #d7e0ee;
			--primary: #2563eb;
			--primary-600: #1d4ed8;
			--ring: #93c5fd;
			--success: #10b981;
			--danger: #ef4444;
		}
		
		.page {
			display: flex;
			min-height: 100vh;
		}
		
		.content { 
			flex: 1;
			padding: 24px; 
			margin-left: 0;
		}
		
		.page-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 24px;
			flex-wrap: wrap;
			gap: 16px;
		}
		
		h1 { 
			margin: 0; 
			font-weight: 700; 
			color: #0f2a44;
			font-size: 28px;
		}
		
		.btn-primary {
			background: var(--primary);
			color: #fff;
			border: none;
			border-radius: 12px;
			padding: 12px 20px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s ease;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}
		
		.btn-primary:hover {
			background: var(--primary-600);
			transform: translateY(-1px);
		}
		
		.btn-success {
			background: var(--success);
			color: #fff;
			border: none;
			border-radius: 12px;
			padding: 12px 20px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s ease;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}
		
		.btn-success:hover {
			background: #059669;
			transform: translateY(-1px);
		}
		
		.btn-secondary {
			background: #6b7280;
			color: #fff;
			border: none;
			border-radius: 12px;
			padding: 12px 20px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s ease;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}
		
		.btn-secondary:hover {
			background: #4b5563;
		}
		
		.btn-danger {
			background: var(--danger);
			color: #fff;
			border: none;
			border-radius: 8px;
			padding: 8px 12px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s ease;
			font-size: 12px;
		}
		
		.btn-danger:hover {
			background: #dc2626;
		}
		
		.card {
			background: var(--card);
			border: 1px solid var(--border);
			border-radius: 16px;
			padding: 24px;
			margin-bottom: 24px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}
		
		.card-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 20px;
			padding: 0 0 16px 0;
			border-bottom: 1px solid var(--border);
			gap: 16px;
		}
		
		.card-title {
			margin: 0;
			font-size: 20px;
			font-weight: 600;
			color: #0f2a44;
		}
		
		.search-box {
			position: relative;
			width: 200px;
			margin-left: auto;
			margin-right: 0;
		}
		
		.search-box input {
			width: 100%;
			padding: 8px 12px 8px 32px;
			border: 1px solid var(--border);
			border-radius: 6px;
			font-size: 13px;
			transition: all 0.2s ease;
			background: #fafafa;
			box-sizing: border-box;
		}
		
		.search-box input:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
			background: white;
		}
		
		.search-box input::placeholder {
			color: var(--muted);
			font-size: 13px;
		}
		
		.filter-group {
			position: relative;
		}
		
		.filter-select {
			padding: 8px 12px;
			border: 1px solid var(--border);
			border-radius: 6px;
			font-size: 13px;
			background: #fff;
			color: var(--text);
			min-width: 140px;
			transition: all 0.2s ease;
		}
		
		.filter-select:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
		}
		
		.filter-select:hover {
			border-color: var(--primary);
		}
		
		.search-icon {
			position: absolute;
			left: 8px;
			top: 50%;
			transform: translateY(-50%);
			color: var(--muted);
			width: 14px;
			height: 14px;
		}
		
		.table-container {
			background: var(--card);
			border: 1px solid var(--border);
			border-radius: 16px;
			overflow: hidden;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
		}
		
		.table {
			width: 100%;
			border-collapse: collapse;
		}
		
		.table th {
			background: #f8fafc;
			padding: 16px;
			text-align: left;
			font-weight: 600;
			color: #374151;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			border-bottom: 1px solid var(--border);
		}
		
		.table td {
			padding: 16px;
			border-bottom: 1px solid var(--border);
			color: #374151;
		}
		
		.table tbody tr:hover {
			background: #f8fafc;
		}
		
		.badge {
			display: inline-block;
			padding: 4px 12px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
		}
		
		.badge-success {
			background: #dcfce7;
			color: #166534;
		}
		
		.badge-secondary {
			background: #f3f4f6;
			color: #6b7280;
		}
		
		.form-grid {
			display: grid;
			grid-template-columns: repeat(2, 1fr);
			gap: 20px;
		}
		
		.form-group {
			display: flex;
			flex-direction: column;
		}
		
		.form-group label {
			font-weight: 600;
			color: #374151;
			margin-bottom: 8px;
			font-size: 14px;
		}
		
		.form-control {
			padding: 12px 16px;
			border: 2px solid var(--border);
			border-radius: 12px;
			font-size: 14px;
			transition: all 0.2s ease;
		}
		
		.form-control:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 3px var(--ring);
		}
		
		.checkbox-group {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		
		.checkbox-group input[type="checkbox"] {
			width: 18px;
			height: 18px;
			accent-color: var(--primary);
		}
		
		.alert {
			padding: 16px;
			border-radius: 12px;
			margin-bottom: 20px;
			font-weight: 500;
		}
		
		.alert-success {
			background: #ecfdf5;
			color: #065f46;
			border: 1px solid #a7f3d0;
		}
		
		.alert-danger {
			background: #fef2f2;
			color: #991b1b;
			border: 1px solid #fecaca;
		}
		
		.alert-info {
			background: #f0f9ff;
			color: #0c4a6e;
			border: 1px solid #bae6fd;
		}
		
		.empty-state {
			text-align: center;
			padding: 60px 20px;
			color: var(--muted);
		}
		
		.empty-state svg {
			width: 64px;
			height: 64px;
			margin-bottom: 16px;
			opacity: 0.5;
		}
		
		.empty-state h3 {
			margin: 0 0 8px 0;
			font-size: 18px;
			font-weight: 600;
		}
		
		.empty-state p {
			margin: 0;
			font-size: 14px;
		}
		
		.hidden {
			display: none;
		}
		
		.action-buttons {
			display: flex;
			gap: 8px;
		}
		
		@media (max-width: 768px) {
			.page {
				flex-direction: column;
			}
			
			.content {
				padding: 16px;
				margin-left: 0;
			}
			
			.page-header {
				flex-direction: column;
				align-items: stretch;
			}
			
			.card-header {
				flex-direction: column;
				align-items: stretch;
				gap: 12px;
			}
			
			.search-box {
				width: 100%;
				margin-left: 0;
				margin-right: 0;
			}
			
			.filter-group {
				width: 100%;
			}
			
			.filter-select {
				width: 100%;
				min-width: auto;
			}
			
			.form-grid {
				grid-template-columns: 1fr;
			}
		}
		
		@media (max-width: 480px) {
			.search-box {
				width: 100%;
			}
			
			.search-box input {
				font-size: 16px; /* Prevent zoom on iOS */
			}
		}
		
		/* Desktop: push content when sidebar is open */
		@media (min-width: 769px) {
			body.sidebar-open .content {
				margin-left: 240px;
			}
		}
	</style>
</head>
<body>
	<?php require_once __DIR__ . '/../header.php'; ?>
	<div class="page">
		<?php require_once __DIR__ . '/registrar_side_panel.php'; ?>
		<div class="content">
		<div class="page-header">
			<h1>Employee Management</h1>
			<div style="display: flex; gap: 12px;">
				<button id="toggleFormBtn" type="button" class="btn-success">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M12 5v14M5 12h14"/>
					</svg>
					<?php echo $editEmployee ? 'Cancel Edit' : 'Add Employee'; ?>
				</button>
				<button id="toggleImportBtn" type="button" class="btn-secondary">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
					</svg>
					Import CSV
				</button>
					</div>
				</div>

		<?php if ($message): ?>
			<div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
		<?php endif; ?>
		
		<?php if ($error): ?>
			<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
		<?php endif; ?>

		<div id="formCard" class="card <?php echo $editEmployee ? '' : 'hidden'; ?>">
			<div class="card-header">
				<h3 class="card-title"><?php echo $editEmployee ? 'Edit Employee' : 'Add New Employee'; ?></h3>
			</div>
			<form method="post" action="">
				<?php if ($editEmployee): ?>
					<input type="hidden" name="action" value="edit" />
					<input type="hidden" name="employee_id" value="<?php echo (int)$editEmployee['id']; ?>" />
				<?php else: ?>
					<input type="hidden" name="action" value="add" />
				<?php endif; ?>
				
				<div class="form-grid">
					<?php if ($editEmployee): ?>
					<div class="form-group">
						<label for="employee_code">Employee Code</label>
						<input class="form-control" type="text" id="employee_code" name="employee_code" 
							value="<?php echo htmlspecialchars($editEmployee['employee_code']); ?>" 
							readonly style="background-color: #f8f9fa; color: #6c757d;" />
						<small style="color: var(--muted); font-size: 12px; margin-top: 4px; display: block;">
							Employee code cannot be changed
						</small>
					</div>
					<?php else: ?>
					<div class="form-group">
						<label for="employee_code">Employee Code</label>
						<input class="form-control" type="text" id="employee_code" name="employee_code" 
							value="Auto-generated" 
							readonly style="background-color: #f8f9fa; color: #6c757d;" />
						<small style="color: var(--muted); font-size: 12px; margin-top: 4px; display: block;">
							Employee code will be automatically generated
						</small>
					</div>
					<?php endif; ?>
					<div class="form-group">
						<label for="full_name">Full Name *</label>
						<input class="form-control" type="text" id="full_name" name="full_name" 
							value="<?php echo htmlspecialchars($editEmployee['full_name'] ?? ''); ?>" 
							placeholder="Enter full name (e.g., Juan M. Dela Cruz)" required />
					</div>
					<div class="form-group">
						<label for="email">Email Address</label>
						<input class="form-control" type="email" id="email" name="email" 
							value="<?php echo htmlspecialchars($editEmployee['email'] ?? ''); ?>" 
							placeholder="employee@company.com" />
					</div>
					<div class="form-group">
						<label for="contact_number">Contact Number</label>
						<input class="form-control" type="text" id="contact_number" name="contact_number" 
							value="<?php echo htmlspecialchars($editEmployee['contact_number'] ?? ''); ?>" 
							placeholder="+63 9XX XXX XXXX" />
					</div>
					<div class="form-group">
						<label for="department">Department</label>
						<input class="form-control" type="text" id="department" name="department" 
							value="<?php echo htmlspecialchars($editEmployee['department'] ?? ''); ?>" 
							placeholder="e.g., Human Resources, IT, Finance" />
					</div>
					<div class="form-group">
						<label for="position_title">Position Title</label>
						<input class="form-control" type="text" id="position_title" name="position_title" 
							value="<?php echo htmlspecialchars($editEmployee['position_title'] ?? ''); ?>" 
							placeholder="e.g., Manager, Specialist, Coordinator" />
					</div>
					<div class="form-group">
						<label for="date_hired">Date Hired</label>
						<input class="form-control" type="date" id="date_hired" name="date_hired" 
							value="<?php echo htmlspecialchars($editEmployee['date_hired'] ?? ''); ?>" />
					</div>
					<div class="form-group">
						<div class="checkbox-group">
							<input type="checkbox" id="is_active" name="is_active" value="1" 
								<?php echo ($editEmployee['is_active'] ?? 1) ? 'checked' : ''; ?> />
							<label for="is_active">Active Employee</label>
						</div>
					</div>
				</div>
				<div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
					<button type="submit" class="btn-success">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M20 6L9 17l-5-5"/>
						</svg>
						<?php echo $editEmployee ? 'Update Employee' : 'Add Employee'; ?>
					</button>
					<?php if ($editEmployee): ?>
						<a href="employees.php" class="btn-secondary">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M18 6L6 18M6 6l12 12"/>
							</svg>
							Cancel
						</a>
					<?php endif; ?>
				</div>
			</form>
				</div>

		<div id="importCard" class="card hidden">
			<div class="card-header">
				<h3 class="card-title">Import Employees from CSV</h3>
			</div>
			
			<div class="alert alert-info">
				<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"/>
						<path d="M12 6v6l4 2"/>
					</svg>
					<strong>Before importing:</strong>
				</div>
				<p style="margin: 0 0 8px 0;">Download our template to ensure proper formatting:</p>
				<a href="?template=csv" style="color: var(--primary); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; padding: 8px 12px; border: 1px solid var(--primary); border-radius: 6px; background: rgba(37, 99, 235, 0.05);">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
					</svg>
					Download CSV Template
				</a>
					</div>
			
					<?php if ($error && isset($_POST['action']) && $_POST['action'] === 'import'): ?>
				<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
					<?php elseif ($message && isset($_POST['action']) && $_POST['action'] === 'import'): ?>
				<div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
				<?php if ($import_info): ?><div class="alert alert-info"><?php echo htmlspecialchars($import_info); ?></div><?php endif; ?>
					<?php endif; ?>
			
					<form method="post" action="" enctype="multipart/form-data">
						<input type="hidden" name="action" value="import" />
				<div class="form-group">
					<label for="import_file">Select CSV File</label>
					<input class="form-control" type="file" id="import_file" name="import_file" accept=".csv" required 
						style="padding: 12px; border: 2px dashed var(--border); background: #fafafa;" />
					<small style="color: var(--muted); font-size: 12px; margin-top: 4px; display: block;">
						Only CSV files are supported. Maximum file size: 10MB
					</small>
				</div>
				<div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
					<button type="submit" class="btn-success">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
						</svg>
						Upload and Import
					</button>
						</div>
					</form>
				</div>

		<div class="card">
			<div class="card-header">
				<h3 class="card-title">Employee List</h3>
				<div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
					<div class="search-box">
						<svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="11" cy="11" r="8"/>
							<path d="M21 21l-4.35-4.35"/>
						</svg>
						<input type="text" id="searchInput" placeholder="Search..." />
					</div>
					
					<div class="filter-group">
						<select id="departmentFilter" class="filter-select">
							<option value="">All Departments</option>
							<?php
							$deptStmt = $pdo->query('SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != "" ORDER BY department');
							$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
							foreach ($departments as $dept) {
								echo '<option value="' . htmlspecialchars($dept) . '">' . htmlspecialchars($dept) . '</option>';
							}
							?>
						</select>
					</div>
					
					<div class="filter-group">
						<select id="statusFilter" class="filter-select">
							<option value="">All Status</option>
							<option value="1">Active</option>
							<option value="0">Inactive</option>
						</select>
					</div>
					
					<div class="filter-group">
						<select id="positionFilter" class="filter-select">
							<option value="">All Positions</option>
							<?php
							$posStmt = $pdo->query('SELECT DISTINCT position_title FROM employees WHERE position_title IS NOT NULL AND position_title != "" ORDER BY position_title');
							$positions = $posStmt->fetchAll(PDO::FETCH_COLUMN);
							foreach ($positions as $pos) {
								echo '<option value="' . htmlspecialchars($pos) . '">' . htmlspecialchars($pos) . '</option>';
							}
							?>
						</select>
					</div>
					
					<button type="button" id="clearFilters" class="btn-secondary" style="padding: 8px 12px; font-size: 13px;">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
							<line x1="10" y1="11" x2="10" y2="17"/>
							<line x1="14" y1="11" x2="14" y2="17"/>
						</svg>
						Clear Filters
					</button>
				</div>
			</div>
			<div class="table-container">
				<table class="table" id="employeeTable">
							<thead>
								<tr>
							<th>Employee Code</th>
									<th>Name</th>
							<th>Department</th>
									<th>Position</th>
									<th>Status</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if (!$employees): ?>
							<tr>
								<td colspan="6" class="empty-state">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
										<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
										<circle cx="12" cy="7" r="4"/>
									</svg>
									<h3>No employees found</h3>
									<p>Get started by adding your first employee or importing from CSV</p>
								</td>
							</tr>
								<?php else: ?>
									<?php foreach ($employees as $emp): ?>
										<tr>
									<td>
										<div style="font-weight: 600; color: var(--primary);">
											<?php echo htmlspecialchars($emp['employee_code']); ?>
										</div>
									</td>
									<td>
										<div style="font-weight: 500;">
											<?php echo htmlspecialchars($emp['full_name']); ?>
										</div>
										<?php if ($emp['email']): ?>
											<div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
												<?php echo htmlspecialchars($emp['email']); ?>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<div style="font-weight: 500;">
											<?php echo htmlspecialchars($emp['department'] ?? 'Not specified'); ?>
										</div>
									</td>
									<td>
										<div style="font-weight: 500;">
											<?php echo htmlspecialchars($emp['position_title'] ?? 'Not specified'); ?>
										</div>
									</td>
									<td>
										<span class="badge <?php echo $emp['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
											<?php echo $emp['is_active'] ? 'Active' : 'Inactive'; ?>
										</span>
											</td>
											<td>
										<div class="action-buttons">
											<a class="btn-secondary" href="employees.php?edit=<?php echo (int)$emp['id']; ?>" style="padding: 8px 12px; font-size: 12px;">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
													<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
													<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
												</svg>
												Edit
											</a>
											<form method="post" action="" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this employee? This action cannot be undone.');">
													<input type="hidden" name="action" value="delete" />
													<input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>" />
												<button type="submit" class="btn-danger">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
														<polyline points="3,6 5,6 21,6"/>
														<path d="M19,6v14a2,2 0 0,1 -2,2H7a2,2 0 0,1 -2,-2V6m3,0V4a2,2 0 0,1 2,-2h4a2,2 0 0,1 2,2v2"/>
													</svg>
													Delete
												</button>
												</form>
										</div>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
	</div>

	<script>
	(function(){
		var formBtn = document.getElementById('toggleFormBtn');
		var formCard = document.getElementById('formCard');
		var importBtn = document.getElementById('toggleImportBtn');
		var importCard = document.getElementById('importCard');
		var searchInput = document.getElementById('searchInput');
		var employeeTable = document.getElementById('employeeTable');
		
		// Toggle form visibility
		if (formBtn && formCard) {
			formBtn.addEventListener('click', function(){
				if (formCard.classList.contains('hidden')) {
					formCard.classList.remove('hidden');
					importCard.classList.add('hidden');
					formBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>Cancel Add';
				} else {
					formCard.classList.add('hidden');
					formBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>Add Employee';
				}
			});
		}
		
		// Toggle import visibility
		if (importBtn && importCard) {
			importBtn.addEventListener('click', function(){
				if (importCard.classList.contains('hidden')) {
					importCard.classList.remove('hidden');
					formCard.classList.add('hidden');
					importBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>Close Import';
				} else {
					importCard.classList.add('hidden');
					importBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>Import CSV';
				}
			});
		}
		
		// Filter functionality
		function applyFilters() {
			var searchInput = document.getElementById('searchInput');
			var departmentFilter = document.getElementById('departmentFilter');
			var statusFilter = document.getElementById('statusFilter');
			var positionFilter = document.getElementById('positionFilter');
			var employeeTable = document.getElementById('employeeTable');
			
			if (!searchInput || !employeeTable) {
				return;
			}
			
			var searchFilter = searchInput.value.toLowerCase();
			var deptFilter = departmentFilter ? departmentFilter.value : '';
			var statFilter = statusFilter ? statusFilter.value : '';
			var posFilter = positionFilter ? positionFilter.value : '';
			
			var tbody = employeeTable.getElementsByTagName('tbody')[0];
			if (!tbody) return;
			
			var rows = tbody.getElementsByTagName('tr');
			
			for (var i = 0; i < rows.length; i++) {
				var cells = rows[i].getElementsByTagName('td');
				var showRow = true;
				
				// Search filter (searches all columns except actions)
				if (searchFilter) {
					var found = false;
					for (var j = 0; j < cells.length - 1; j++) {
						if (cells[j].textContent.toLowerCase().indexOf(searchFilter) > -1) {
							found = true;
							break;
						}
					}
					if (!found) showRow = false;
				}
				
				// Department filter (3rd column)
				if (deptFilter && showRow && cells.length > 2) {
					var department = cells[2].textContent.trim();
					if (department !== deptFilter) {
						showRow = false;
					}
				}
				
				// Position filter (4th column)
				if (posFilter && showRow && cells.length > 3) {
					var position = cells[3].textContent.trim();
					if (position !== posFilter) {
						showRow = false;
					}
				}
				
				// Status filter (5th column)
				if (statFilter !== '' && showRow && cells.length > 4) {
					var statusCell = cells[4];
					var statusBadge = statusCell.querySelector('.badge-success, .badge-secondary');
					var isActive = statusBadge && statusBadge.classList.contains('badge-success');
					
					if ((statFilter === '1' && !isActive) || (statFilter === '0' && isActive)) {
						showRow = false;
					}
				}
				
				rows[i].style.display = showRow ? '' : 'none';
			}
		}
		
		// Add event listeners for all filters
		var searchInput = document.getElementById('searchInput');
		var departmentFilter = document.getElementById('departmentFilter');
		var statusFilter = document.getElementById('statusFilter');
		var positionFilter = document.getElementById('positionFilter');
		var clearFilters = document.getElementById('clearFilters');
		
		if (searchInput) {
			searchInput.addEventListener('input', applyFilters);
		}
		
		if (departmentFilter) {
			departmentFilter.addEventListener('change', applyFilters);
		}
		
		if (statusFilter) {
			statusFilter.addEventListener('change', applyFilters);
		}
		
		if (positionFilter) {
			positionFilter.addEventListener('change', applyFilters);
		}
		
		if (clearFilters) {
			clearFilters.addEventListener('click', function() {
				if (searchInput) searchInput.value = '';
				if (departmentFilter) departmentFilter.value = '';
				if (statusFilter) statusFilter.value = '';
				if (positionFilter) positionFilter.value = '';
				applyFilters();
			});
		}
	})();
	</script>
</body>
</html>
