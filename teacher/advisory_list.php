<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['teacher', 'admin']);

$user_id = $_SESSION['user']['id'];
$pdo = db_connect();

// 1. Fetch Advisory Class Info
// Check BOTH user_id (direct login) AND employee_id (admin-assigned via HR link)
$advisory_class = null;
$students = [];
$stats = ['total' => 0, 'male' => 0, 'female' => 0];
$message = '';
$msg_type = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $enrollment_id = (int) ($_POST['enrollment_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    $new_date = $_POST['status_date'] ?? null;
    if ($enrollment_id && $new_status) {
        $stmt = $pdo->prepare("UPDATE enrollments SET status = ?, status_date = ? WHERE id = ?");
        $stmt->execute([$new_status, $new_date, $enrollment_id]);
        $message = 'Student status updated successfully.';
        $msg_type = 'success';
    }
}

// Get active school year from centralized helper
$current_sy = get_active_school_year($pdo);

try {
    // 1. Identify all possible IDs for this teacher
    $stmt = $pdo->prepare('SELECT id FROM teachers WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $teacher_profile_id = $stmt->fetchColumn();
    
    $adviser_ids = [$user_id];
    if ($teacher_profile_id) $adviser_ids[] = $teacher_profile_id;
    $placeholders = implode(',', array_fill(0, count($adviser_ids), '?'));

    // 2. Multi-Source Detection for Advisory Class
    $advisory_class = false;

    // Strategy A: position_assignments (The most robust way)
    $stmt = $pdo->prepare("SELECT grade_level, section FROM position_assignments 
        WHERE (user_id = ? OR employee_id = ?) 
        AND position_type = 'class_adviser' AND school_year = ? LIMIT 1");
    $stmt->execute([$user_id, $teacher_profile_id, $current_sy]);
    $pos = $stmt->fetch();
    
    if ($pos) {
        $stmt = $pdo->prepare("SELECT id as section_id, grade_level, section_name AS section, school_year 
            FROM sections WHERE grade_level = ? AND section_name = ? AND school_year = ? LIMIT 1");
        $stmt->execute([$pos['grade_level'], $pos['section'], $current_sy]);
        $advisory_class = $stmt->fetch();
    }

    // Strategy B: Fallback to sections.adviser_id
    if (!$advisory_class) {
        $stmt = $pdo->prepare(
            "SELECT id as section_id, grade_level, section_name AS section, school_year
             FROM sections 
             WHERE adviser_id IN ($placeholders) AND school_year = ? 
             LIMIT 1"
        );
        $stmt->execute(array_merge($adviser_ids, [$current_sy]));
        $advisory_class = $stmt->fetch();
    }

	if ($advisory_class) {
		// 2. Fetch Enrolled Student Details — pull all fields from registrations
		$sql = "SELECT
				e.id,
				e.student_id,
				e.student_name,
				e.grade_level,
				e.section,
				e.school_year,
				e.status AS enrollment_status,
				e.status_date,
				COALESCE(r.lrn, e.lrn) AS lrn,
				r.sex,
				r.birthdate,
				COALESCE(
					CONCAT_WS(', ', NULLIF(r.curr_house_no,''), NULLIF(r.curr_street,''), NULLIF(r.curr_barangay,''), NULLIF(r.curr_city,''), NULLIF(r.curr_province,'')),
					e.address
				) AS address,
				CONCAT_WS(' ', NULLIF(r.father_first,''), NULLIF(r.father_last,'')) AS father_name,
				r.father_contact,
				CONCAT_WS(' ', NULLIF(r.mother_first,''), NULLIF(r.mother_last,'')) AS mother_name,
				r.mother_contact,
				COALESCE(
					CONCAT_WS(' ', NULLIF(r.guardian_first,''), NULLIF(r.guardian_last,'')),
					CONCAT_WS(' ', NULLIF(e.guardian_first,''), NULLIF(e.guardian_last,''))
				) AS guardian_name,
				COALESCE(r.guardian_contact, e.guardian_contact) AS guardian_contact,
				COALESCE(r.id_contact_person, e.id_contact_person) AS id_contact_person
			FROM enrollments e
			LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.lrn))
			WHERE e.grade_level = ? AND e.section = ?
			  AND (? = '' OR e.school_year = ?)
			GROUP BY e.id
			ORDER BY FIELD(r.sex, 'Male', 'M', 'Female', 'F'), e.student_name ASC";

		$sy_filter = $advisory_class['school_year'] ?? '';
		$stmt = $pdo->prepare($sql);
		$stmt->execute([$advisory_class['grade_level'], $advisory_class['section'], $sy_filter, $sy_filter]);
		$students = $stmt->fetchAll();

		$stats['total'] = count($students);
		foreach ($students as $s) {
			$sex = strtoupper($s['sex'] ?? '');
			if ($sex === 'M' || $sex === 'MALE')
				$stats['male']++;
			elseif ($sex === 'F' || $sex === 'FEMALE')
				$stats['female']++;
		}
	}

} catch (Exception $e) {
	// silent fail — helps debug in dev:
	$debug_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Advisory List | Teacher Portal</title>
	<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

	<style>
		/* Premium Admin UI Standard Classes adopted for Teacher Portal */
		:root {
			--bg: #f6f8fc;
			--card: #ffffff;
			--muted: #64748b;
			--border: #d7e0ee;
			--primary: #2563eb;
			--primary-600: #1d4ed8;
			--success: #10b981;
			--danger: #ef4444;
			--warning: #f59e0b;
			--text-main: #0f172a;
		}

		body {
			background-color: var(--bg);
			margin: 0;
			font-family: 'Inter', -apple-system, sans-serif;
		}

		.content {
			padding: 140px 32px 48px;
			max-width: 1400px;
			box-sizing: border-box;
		}

		.title-block {
			background: #fff;
			padding: 20px 24px;
			border-radius: 16px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
			margin-bottom: 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			border: 1px solid var(--border);
			flex-wrap: wrap;
			gap: 16px;
		}

		h1 {
			margin: 0;
			font-size: 24px;
			color: #1e293b;
		}

		.card {
			background: var(--card);
			border-radius: 16px;
			padding: 24px;
			margin-bottom: 24px;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
			border: 1px solid var(--border);
		}

		.btn {
			padding: 10px 16px;
			border: none;
			border-radius: 8px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 14px;
			text-decoration: none;
		}

		.btn-primary {
			background: var(--primary);
			color: white;
		}

		.btn-primary:hover {
			background: var(--primary-600);
		}

		.btn-outline {
			border: 1px solid #cbd5e1;
			color: #475569;
			background: white;
		}

		.btn-outline:hover {
			border-color: var(--primary);
			color: var(--primary);
		}

		.stats-row {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 1.5rem;
			margin-bottom: 1.5rem;
		}

		.stat-card {
			background: white;
			padding: 1.5rem;
			border-radius: 16px;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
			border: 1px solid var(--border);
			display: flex;
			align-items: center;
			gap: 1rem;
		}

		.stat-icon {
			width: 48px;
			height: 48px;
			border-radius: 10px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 1.5rem;
		}

		.stat-info h3 {
			font-size: 1.5rem;
			font-weight: 700;
			margin: 0;
		}

		.stat-info p {
			margin: 0;
			color: var(--muted);
			font-size: 0.875rem;
		}

		/* TABLE */
		.table-container {
			background: white;
			border-radius: 16px;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
			border: 1px solid var(--border);
			overflow: hidden;
		}

		.table-header {
			padding: 20px 24px;
			border-bottom: 1px solid var(--border);
			background: #f8fafc;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.table-title {
			font-weight: 700;
			color: #334155;
			margin: 0;
			font-size: 18px;
		}

		.table-responsive {
			overflow-x: auto;
		}

		.table {
			width: 100%;
			border-collapse: collapse;
			background: white;
		}

		.table th,
		.table td {
			padding: 16px 24px;
			text-align: left;
			border-bottom: 1px solid var(--border);
		}

		.table th {
			background: #f8fafc;
			color: #64748b;
			font-weight: 600;
			font-size: 13px;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}

		.table tbody tr:hover {
			background-color: #f8fafc;
		}

		.student-name {
			font-weight: 600;
			color: var(--text-main);
		}

		.badge {
			display: inline-block;
			padding: 0.25rem 0.6rem;
			border-radius: 999px;
			font-size: 0.75rem;
			font-weight: 600;
		}

		.badge-male {
			background: #eff6ff;
			color: #3b82f6;
		}

		.badge-female {
			background: #fdf2f8;
			color: #db2777;
		}

		.empty-state {
			text-align: center;
			padding: 4rem 2rem;
			color: var(--muted);
		}

		@media (max-width: 768px) {
			.content {
				padding: 110px 16px 24px;
			}

			.title-block {
				flex-direction: column;
				align-items: flex-start;
				gap: 16px;
			}
		}

		@media (max-width: 480px) {
			.content {
				padding: 100px 12px 20px;
			}
		}
	</style>
</head>

<body>
	<?php require_once __DIR__ . '/teacher_header.php'; ?>
	<?php require_once __DIR__ . '/teacher_side_panel.php'; ?>

	<div class="content main-content dashboard-container">
		<?php if ($message): ?>
			<div class="card" style="background: <?= $msg_type === 'success' ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $msg_type === 'success' ? '#065f46' : '#991b1b' ?>; border-color: <?= $msg_type === 'success' ? '#a7f3d0' : '#fecaca' ?>; padding: 12px 20px; margin-bottom: 20px;">
				<?= htmlspecialchars($message) ?>
			</div>
		<?php endif; ?>

		<?php if ($advisory_class): ?>
			<!-- HEADLINE -->
			<div class="title-block">
				<div>
					<h1>Advisory Class Masterlist</h1>
					<p style="color: var(--muted); margin-top: 5px; font-size: 14px;">
						Grade <?= htmlspecialchars($advisory_class['grade_level'] . ' - ' . $advisory_class['section']) ?>
						• SY <?= htmlspecialchars($current_sy) ?>
					</p>
				</div>
				<div style="display: flex; gap: 12px;">
					<a href="reports/sf1_form.php?section=<?= urlencode($advisory_class['section']) ?>&grade=<?= urlencode($advisory_class['grade_level']) ?>"
						class="btn btn-outline">
						📄 View School Form 1
					</a>
					<button onclick="window.print()" class="btn btn-primary">
						🖨️ Print List
					</button>
				</div>
			</div>

			<!-- STATS -->
			<div class="stats-row">
				<div class="stat-card">
					<div class="stat-icon" style="background: #ecfdf5; color: #10b981;">👥</div>
					<div class="stat-info">
						<h3><?= $stats['total'] ?></h3>
						<p>Total Students</p>
					</div>
				</div>
				<div class="stat-card">
					<div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">👦</div>
					<div class="stat-info">
						<h3><?= $stats['male'] ?></h3>
						<p>Male</p>
					</div>
				</div>
				<div class="stat-card">
					<div class="stat-icon" style="background: #fdf2f8; color: #db2777;">👧</div>
					<div class="stat-info">
						<h3><?= $stats['female'] ?></h3>
						<p>Female</p>
					</div>
				</div>
			</div>

			<!-- TABLE -->
			<div class="table-container">
				<div class="table-header">
					<h3 class="table-title">Student Class List</h3>
				</div>
				<div class="table-responsive">
					<table class="table">
						<thead>
							<tr>
								<th style="width: 50px;">#</th>
								<th>Student Name</th>
								<th>Student ID</th>
								<th>LRN</th>
								<th>Sex</th>
								<th>Birthdate</th>
								<th>Address</th>
								<th>Contact (Parent / Guardian)</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($students)): ?>
								<tr>
									<td colspan="8" class="empty-state">
										No students found in this section.
									</td>
								</tr>
							<?php else: ?>
								<?php foreach ($students as $index => $student): ?>
									<tr>
										<td><?= $index + 1 ?></td>
										<td>
											<div class="student-name"><?= htmlspecialchars($student['student_name']) ?></div>
										</td>
										<td style="font-family: monospace; color:#64748b; white-space:nowrap;">
											<?= htmlspecialchars($student['student_id'] ?? '—') ?>
										</td>
										<td style="font-family: monospace; color:#64748b; white-space:nowrap;">
											<?= htmlspecialchars($student['lrn'] ?? '—') ?>
										</td>
										<td>
											<?php
											$s = strtoupper($student['sex'] ?? '');
											$badgeClass = ($s == 'M' || $s == 'MALE') ? 'badge-male' : (($s == 'F' || $s == 'FEMALE') ? 'badge-female' : '');
											$sexLabel = ($s == 'M') ? 'Male' : (($s == 'F') ? 'Female' : ($s ?: '—'));
											?>
											<span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($sexLabel) ?></span>
										</td>
										<td style="font-size:0.85rem; color:var(--muted); white-space:nowrap;">
											<?= !empty($student['birthdate']) ? date('M d, Y', strtotime($student['birthdate'])) : '—' ?>
										</td>
										<td style="font-size:0.8rem; max-width:200px; line-height:1.4;">
											<?= htmlspecialchars($student['address'] ?? '—') ?>
										</td>
										<td style="font-size:0.85rem; white-space:nowrap;">
											<?php
											$primary = strtolower($student['id_contact_person'] ?? '');
											if ($primary === 'father' && !empty($student['father_contact'])) {
												echo '<div style="font-weight:500;">' . htmlspecialchars($student['father_name'] ?? 'Father') . '</div>';
												echo '<div>📞 ' . htmlspecialchars($student['father_contact']) . '</div>';
											} elseif ($primary === 'mother' && !empty($student['mother_contact'])) {
												echo '<div style="font-weight:500;">' . htmlspecialchars($student['mother_name'] ?? 'Mother') . '</div>';
												echo '<div>📞 ' . htmlspecialchars($student['mother_contact']) . '</div>';
											} elseif (!empty($student['guardian_name']) || !empty($student['guardian_contact'])) {
												if (!empty($student['guardian_name'])) echo '<div style="font-weight:500;">' . htmlspecialchars($student['guardian_name']) . '</div>';
												if (!empty($student['guardian_contact'])) echo '<div>📞 ' . htmlspecialchars($student['guardian_contact']) . '</div>';
											} elseif (!empty($student['father_contact'])) {
												echo '<div>📞 ' . htmlspecialchars($student['father_contact']) . ' <span style="color:#94a3b8;">(F)</span></div>';
												if (!empty($student['mother_contact'])) echo '<div>📞 ' . htmlspecialchars($student['mother_contact']) . ' <span style="color:#94a3b8;">(M)</span></div>';
											} else {
												echo '<span style="color:#cbd5e1">—</span>';
											}
											?>
										</td>
										<td>
											<div style="display:flex; align-items:center; gap:8px;">
												<span class="badge" style="background:#f0fdf4; color:#16a34a;"><?= htmlspecialchars($student['enrollment_status'] ?: 'Enrolled') ?></span>
												<button type="button" class="btn btn-outline" style="padding:4px 8px; font-size:11px;" 
													onclick="editStatus(<?= $student['id'] ?>, '<?= htmlspecialchars(addslashes($student['student_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($student['enrollment_status'] ?: 'Enrolled') ?>', '<?= !empty($student['status_date']) ? $student['status_date'] : '' ?>')">
													📝 Edit
												</button>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

		<?php else: ?>
			<!-- EMPTY STATE -->
			<div class="card" style="text-align: center; padding: 4rem; color: #94a3b8; border-style: dashed;">
				<div style="font-size: 4rem; opacity: 0.3;">📋</div>
				<h2 style="margin-top: 1rem; color: var(--text-main);">No Advisory Class Assigned</h2>
				<p>You have not been assigned as a class adviser for the current school year.</p>
			<p>Contact the administrator for assignments.</p>
			</div>
		<?php endif; ?>

	</div>

	<!-- ── Edit Status Modal ── -->
	<div id="statusModal" class="modal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4);">
		<div style="background-color:#fefefe; margin:10% auto; padding:20px; border:1px solid #888; width:400px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
			<h2 style="margin-top:0; color:var(--primary); font-size: 1.25rem;">Update Student Status</h2>
			<form method="POST">
				<input type="hidden" name="action" value="update_status">
				<input type="hidden" name="enrollment_id" id="statusEnrollId">
				<p style="font-size: 14px; color: #4b5563;">Updating status for: <strong id="statusStudentName"></strong></p>
				
				<div style="margin-bottom:15px;">
					<label style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px;">New Status</label>
					<select name="status" id="statusSelect" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:8px;">
						<option value="Enrolled">Enrolled</option>
						<option value="Transferred In">Transferred In</option>
						<option value="Transferred Out">Transferred Out</option>
						<option value="Dropped">Dropped</option>
						<option value="Mortality">Mortality</option>
					</select>
				</div>
				
				<div style="margin-bottom:20px;">
					<label style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px;">Status Date (Effective Date)</label>
					<input type="date" name="status_date" id="statusDate" style="width:100%; padding:10px; border:1px solid var(--border); border-radius:8px; box-sizing: border-box;">
					<small style="color:#94a3b8; font-size: 11px;">Select the date when this status took effect.</small>
				</div>
				
				<div style="display:flex; justify-content:flex-end; gap:10px;">
					<button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancel</button>
					<button type="submit" class="btn btn-primary">Save Changes</button>
				</div>
			</form>
		</div>
	</div>

	<script>
		function editStatus(id, name, currentStatus, currentDate) {
			document.getElementById('statusEnrollId').value = id;
			document.getElementById('statusStudentName').textContent = name;
			document.getElementById('statusSelect').value = currentStatus || 'Enrolled';
			document.getElementById('statusDate').value = currentDate || '';
			document.getElementById('statusModal').style.display = 'block';
		}

		function closeStatusModal() {
			document.getElementById('statusModal').style.display = 'none';
		}

		// Close modal when clicking outside
		window.onclick = function(event) {
			const modal = document.getElementById('statusModal');
			if (event.target === modal) {
				modal.style.display = 'none';
			}
		}
	</script>
</body>

</html>