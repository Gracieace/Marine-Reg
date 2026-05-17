<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['teacher', 'admin']);

$user_id = $_SESSION['user']['id'];
$teacher_code = 'TCH-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);
$current_sy = get_active_school_year($pdo);

$pdo = db_connect();

// 1. Fetch Subject Loads (Teaching Assignments) - Using standardized user_id
$subject_loads = [];
try {
	$sql = "SELECT st.*, s.subject_name, s.subject_code, sec.grade_level, sec.section_name 
            FROM subject_teachers st
            JOIN curriculum s ON st.subject_id = s.id
            JOIN sections sec ON st.section_id = sec.id
            WHERE st.teacher_id = ? AND st.school_year = ?
            ORDER BY sec.grade_level, sec.section_name";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([$user_id, $current_sy]);
	$subject_loads = $stmt->fetchAll();
} catch (Exception $e) { }

// Handle Form Submission (Save Grades)
$toast_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
	$subject_id = $_POST['subject_id'];
	$quarter = $_POST['quarter'];
	$grades = $_POST['grades'] ?? [];

	$stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, quarter, grade, school_year) 
                           VALUES (?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE grade = VALUES(grade)");

	foreach ($grades as $student_id => $grade_val) {
		if ($grade_val !== '') {
			$stmt->execute([$student_id, $subject_id, $quarter, $grade_val, $current_sy]);
		}
	}
	$toast_message = "Grades saved successfully!";
}

// Determine Selected Subject
$selected_subject_id = $_GET['subject'] ?? null;
$selected_subject = null;
$students = [];
$existing_grades = [];

if ($selected_subject_id) {
	// Validate that this subject belongs to the teacher
	foreach ($subject_loads as $sub) {
		if ($sub['subject_id'] == $selected_subject_id || $sub['id'] == $selected_subject_id) { // loose check
			$selected_subject = $sub;
			break;
		}
	}

	if ($selected_subject) {
		// Fetch Students in this section
		// Note: We need to match grade_level and section
		$s_stmt = $pdo->prepare("SELECT * FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ? ORDER BY student_name");
		$s_stmt->execute([$selected_subject['grade_level'], $selected_subject['section_name'], $current_sy]);
		$students = $s_stmt->fetchAll();

		// Fetch Existing Grades for this subject
		$g_stmt = $pdo->prepare("SELECT * FROM grades WHERE subject_id = ? AND school_year = ?");
		$g_stmt->execute([$selected_subject['subject_id'], $current_sy]); // Use actual subject_id
		$raw_grades = $g_stmt->fetchAll();

		// Map grades: [student_id][quarter] = grade
		foreach ($raw_grades as $rg) {
			$existing_grades[$rg['student_id']][$rg['quarter']] = $rg['grade'];
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Enter Grades | Teacher Portal</title>
	<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

	<style>
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
			padding: 100px 32px 48px;
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

		/* SUBJECT SELECTOR */
		.subject-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
			gap: 1rem;
			margin-bottom: 2rem;
		}

		.subject-card {
			background: white;
			padding: 1.5rem;
			border-radius: 12px;
			border: 1px solid var(--border);
			cursor: pointer;
			transition: all 0.2s;
			text-decoration: none;
			color: inherit;
			display: block;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
		}

		.subject-card:hover,
		.subject-card.active {
			border-color: var(--primary);
			box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1);
		}

		.subject-card.active {
			background: #eff6ff;
		}

		.subj-code {
			font-size: 0.8rem;
			color: #64748b;
			font-weight: 600;
			text-transform: uppercase;
		}

		.subj-name {
			font-size: 1.1rem;
			font-weight: 700;
			margin: 0.2rem 0;
			color: var(--text-main);
		}

		.subj-sec {
			font-size: 0.9rem;
			color: var(--text-main);
		}

		/* GRADING TABLE using Admin .table standard */
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

		input.grade-input {
			width: 60px;
			padding: 0.5rem;
			border: 1px solid #cbd5e1;
			border-radius: 6px;
			text-align: center;
			font-weight: 600;
		}

		input.grade-input:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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

		/* Toast notification */
		.toast {
			position: fixed;
			bottom: 20px;
			right: 20px;
			background: #10b981;
			color: white;
			padding: 1rem 2rem;
			border-radius: 8px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
			animation: slideIn 0.3s ease-out;
			z-index: 2000;
		}

		@keyframes slideIn {
			from {
				transform: translateY(100%);
				opacity: 0;
			}

			to {
				transform: translateY(0);
				opacity: 1;
			}
		}
	</style>
</head>

<body>
	<?php require_once __DIR__ . '/teacher_header.php'; ?>
	<?php require_once __DIR__ . '/teacher_side_panel.php'; ?>

	<?php if ($toast_message): ?>
		<div class="toast"><?= htmlspecialchars($toast_message) ?></div>
		<script>
			setTimeout(function () {
				document.querySelector('.toast').style.display = 'none';
			}, 3000);
		</script>
	<?php endif; ?>

	<div class="content main-content dashboard-container">

		<div class="title-block">
			<div>
				<h1>Enter Grades</h1>
				<p style="color: var(--muted); margin-top: 5px; font-size: 14px;">Select a subject to start grading.</p>
			</div>
			<div>
				<span class="badge badge-primary">ID: <?= htmlspecialchars($teacher_code) ?></span>
			</div>
		</div>

		<!-- 1. Subject Selector -->
		<div class="subject-grid">
			<?php foreach ($subject_loads as $sub): ?>
				<a href="?subject=<?= $sub['subject_id'] // Use actual subject id logic ?>"
					class="subject-card <?= ($selected_subject_id == $sub['subject_id']) ? 'active' : '' ?>">
					<div class="subj-code"><?= htmlspecialchars($sub['subject_code']) ?></div>
					<div class="subj-name"><?= htmlspecialchars($sub['subject_name']) ?></div>
					<div class="subj-sec"><?= htmlspecialchars($sub['grade_level'] . ' - ' . $sub['section_name']) ?></div>
				</a>
			<?php endforeach; ?>
			<?php if (empty($subject_loads)): ?>
				<div class="subject-card"
					style="grid-column: 1/-1; cursor: default; text-align: center; border-style: dashed;">
					Select a subject to view. If none appear, contact admin.
				</div>
			<?php endif; ?>
		</div>

		<!-- 2. Grading Interface (Only if subject selected) -->
		<?php if ($selected_subject): ?>
			<div class="table-container" style="margin-top: 2rem;">
				<div class="table-header">
					<div>
						<h2 style="margin: 0; font-size: 1.25rem;">Grading Sheet:
							<?= htmlspecialchars($selected_subject['subject_name']) ?>
						</h2>
						<span style="font-size: 0.9rem; color: #64748b;">
							<?= htmlspecialchars($selected_subject['grade_level'] . ' - ' . $selected_subject['section_name']) ?>
						</span>
					</div>

					<!-- Quarter Selector (Simple JS Reload or Form) -->
					<form method="GET" style="display:flex; gap: 10px; align-items: center;">
						<input type="hidden" name="subject" value="<?= htmlspecialchars($selected_subject_id) ?>">
						<select name="quarter_view" onchange="this.form.submit()"
							style="padding: 0.5rem; border-radius: 6px; border: 1px solid #cbd5e1;">
							<?php $q_view = $_GET['quarter_view'] ?? '1st'; ?>
							<option value="1st" <?= $q_view == '1st' ? 'selected' : '' ?>>1st Quarter</option>
							<option value="2nd" <?= $q_view == '2nd' ? 'selected' : '' ?>>2nd Quarter</option>
							<option value="3rd" <?= $q_view == '3rd' ? 'selected' : '' ?>>3rd Quarter</option>
							<option value="4th" <?= $q_view == '4th' ? 'selected' : '' ?>>4th Quarter</option>
						</select>
					</form>
				</div>

				<form method="POST">
					<input type="hidden" name="subject_id" value="<?= htmlspecialchars($selected_subject['subject_id']) ?>">
					<input type="hidden" name="quarter" value="<?= htmlspecialchars($q_view) ?>">

					<div class="table-responsive">
						<table class="table">
							<thead>
								<tr>
									<th style="width: 50px;">#</th>
									<th>Student ID</th>
									<th>Student Name</th>
									<th style="width: 150px; text-align: center;"><?= htmlspecialchars($q_view) ?> Grade
									</th>
									<th>Remarks</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($students)): ?>
									<tr>
										<td colspan="4" style="text-align: center; padding: 2rem;">No students found in this
											section.</td>
									</tr>
								<?php endif; ?>

								<?php foreach ($students as $index => $student): ?>
									<tr>
										<td><?= $index + 1 ?></td>
										<td style="font-family: monospace; color:#64748b; white-space:nowrap;">
											<?= htmlspecialchars($student['student_id']) ?>
										</td>
										<td style="font-weight: 500;"><?= htmlspecialchars($student['student_name']) ?></td>
										<td style="text-align: center;">
											<input type="number" name="grades[<?= $student['student_id'] ?>]"
												class="grade-input" min="60" max="100" step="0.01"
												value="<?= htmlspecialchars($existing_grades[$student['student_id']][$q_view] ?? '') ?>"
												placeholder="--">
										</td>
										<td>
											<!-- Optional remarks input could go here -->
											<span style="color: #cbd5e1; font-size: 0.8rem;">Saved automatically</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<div
						style="padding: 1.5rem 1.5rem 1.5rem 1.5rem; border-top: 1px solid var(--border); text-align: right; background: #fff;">
						<button type="submit" name="save_grades" class="btn btn-primary">Save Changes</button>
					</div>
				</form>
			</div>
		<?php else: ?>
			<div class="card" style="text-align: center; padding: 4rem; color: #94a3b8; border-style: dashed;">
				<div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;">👈</div>
				<h3 style="color: var(--text-main); margin:0;">Select a subject above to start grading</h3>
			</div>
		<?php endif; ?>
	</div>

</body>

</html>