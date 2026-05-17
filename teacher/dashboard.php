<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['teacher', 'admin']);

// Get current user info
$user_id = $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$teacher_code = 'TCH-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);

// Time of day greeting
$hour = date('H');
$greeting = 'Good Morning';
if ($hour >= 12 && $hour < 17) {
	$greeting = 'Good Afternoon';
} elseif ($hour >= 17) {
	$greeting = 'Good Evening';
}

try {
	$pdo = db_connect();
	initialize_schema($pdo);


	$current_sy = get_active_school_year($pdo);

	// 1. Get Teacher Profile Info
	$stmt = $pdo->prepare('SELECT * FROM teachers WHERE user_id = ? LIMIT 1');
	$stmt->execute([$user_id]);
	$teacher_profile = $stmt->fetch();
	$teacher_code = $teacher_profile['employee_code'] ?? 'T-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);

	// 2. Get Advisory Class (Multi-Source)
	$advisory_class = false;
	
	// Method A: position_assignments
	$stmt = $pdo->prepare("SELECT grade_level, section FROM position_assignments 
		WHERE (user_id = ? OR employee_id = (SELECT id FROM employees WHERE email = (SELECT email FROM users WHERE id = ?))) 
		AND position_type = 'class_adviser' AND school_year = ? LIMIT 1");
	$stmt->execute([$user_id, $user_id, $current_sy]);
	$pos = $stmt->fetch();
	if ($pos) {
		$stmt = $pdo->prepare("SELECT id, grade_level, section_name, school_year FROM sections 
			WHERE grade_level = ? AND section_name = ? AND school_year = ? LIMIT 1");
		$stmt->execute([$pos['grade_level'], $pos['section'], $current_sy]);
		$advisory_class = $stmt->fetch();
	}

	// Method B: Fallback to sections
	if (!$advisory_class) {
		$adviser_ids = [$user_id];
		if ($teacher_profile) $adviser_ids[] = $teacher_profile['id'];
		$placeholders = implode(',', array_fill(0, count($adviser_ids), '?'));
		$stmt = $pdo->prepare("SELECT id, grade_level, section_name, school_year FROM sections 
			 WHERE adviser_id IN ($placeholders) AND school_year = ? LIMIT 1");
		$stmt->execute(array_merge($adviser_ids, [$current_sy]));
		$advisory_class = $stmt->fetch();
	}

	// 3. Stats & Class Data
	$total_students = 0;
	$class_data = [];

	if ($advisory_class) {
		$stmt = $pdo->prepare('SELECT count(*) FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?');
		$stmt->execute([$advisory_class['grade_level'], $advisory_class['section_name'], $advisory_class['school_year']]);
		$total_students = $stmt->fetchColumn();

		$class_data[] = [
			'grade_level' => $advisory_class['grade_level'],
			'section' => $advisory_class['section_name'],
			'count' => $total_students,
			'type' => 'Advisory Class',
			'is_advisory' => true
		];
	}

	// 4. Get Subject Loads (Teaching Assignments)
	$adviser_ids = [$user_id];
	if ($teacher_profile) $adviser_ids[] = $teacher_profile['id'];
	$placeholders = implode(',', array_fill(0, count($adviser_ids), '?'));

	$stmt = $pdo->prepare("SELECT st.*, s.subject_name, sec.grade_level, sec.section_name 
		FROM subject_teachers st 
		JOIN curriculum s ON st.subject_id = s.id 
		JOIN sections sec ON st.section_id = sec.id
		WHERE st.teacher_id IN ($placeholders) AND st.school_year = ?");
	$stmt->execute(array_merge($adviser_ids, [$current_sy]));
	$loads = $stmt->fetchAll();

	foreach ($loads as $load) {
		$stmt = $pdo->prepare('SELECT count(*) FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?');
		$stmt->execute([$load['grade_level'], $load['section_name'], $load['school_year']]);
		$s_count = $stmt->fetchColumn();

		$class_data[] = [
			'grade_level' => $load['grade_level'],
			'section' => $load['section_name'],
			'subject_name' => $load['subject_name'],
			'count' => $s_count,
			'type' => 'Subject: ' . $load['subject_name'],
			'is_advisory' => false
		];
	}

} catch (Exception $e) {
	$teacher_profile = null;
	$advisory_class = null;
	$total_students = 0;
	$class_data = [];
	$db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Teacher Dashboard | EduSystem</title>
	<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/teacher_dashboard.css') ?>">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>

<body>
	<?php require_once __DIR__ . '/teacher_header.php'; ?>
	<?php require_once __DIR__ . '/teacher_side_panel.php'; ?>

	<div class="content main-content dashboard-container">
		<!-- Header Section -->
		<div class="welcome-header">
			<h1 class="welcome-title"><?= $greeting ?>,
				<?= htmlspecialchars($teacher_profile['first_name'] ?? $username) ?>!</h1>
			<p class="welcome-subtitle">Here's what's happening in your classes today.</p>
		</div>

		<!-- Teacher Profile Card -->
		<div class="profile-card">
			<div class="profile-avatar">
				<?= !empty($teacher_profile) ? strtoupper(substr($teacher_profile['first_name'], 0, 1) . substr($teacher_profile['last_name'], 0, 1)) : strtoupper(substr($username, 0, 1)) ?>
			</div>
			<div class="profile-info">
				<?php if ($teacher_profile): ?>
					<h2><?= htmlspecialchars($teacher_profile['first_name'] . ' ' . $teacher_profile['last_name']) ?></h2>
					<p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">
						<?= htmlspecialchars($teacher_profile['department'] ?? 'Department Not Set') ?> •
						<?= htmlspecialchars($teacher_profile['specialization'] ?? 'Generalist') ?>
					</p>
				<?php else: ?>
					<h2><?= htmlspecialchars($username) ?></h2>
					<p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">Profile Incomplete</p>
				<?php endif; ?>
				<div class="profile-badges">
					<span class="badge primary">ID: <?= htmlspecialchars($teacher_code) ?></span>
					<span class="badge success">Active Faculty</span>
				</div>
			</div>
		</div>

		<!-- Stats Grid -->
		<div class="stats-grid">
			<div class="stat-card">
				<div class="stat-header">
					<div class="stat-icon" style="background: #eff6ff; color: var(--primary-color);">👥</div>
				</div>
				<div>
					<div class="stat-value"><?php echo number_format($total_students); ?></div>
					<div class="stat-title">Advisory Students</div>
					<div class="stat-desc">
						<?= $advisory_class ? htmlspecialchars($advisory_class['grade_level'] . ' - ' . $advisory_class['section']) : 'None assigned' ?>
					</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-header">
					<div class="stat-icon" style="background: #f0fdf4; color: var(--accent-color);">📚</div>
				</div>
				<div>
					<div class="stat-value"><?= count($class_data) ?></div>
					<div class="stat-title">Active Classes</div>
					<div class="stat-desc">Your assigned subjects</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-header">
					<div class="stat-icon" style="background: #fef2f2; color: #ef4444;">⏱️</div>
				</div>
				<div>
					<div class="stat-value">0</div>
					<div class="stat-title">Pending Tasks</div>
					<div class="stat-desc">Forms to review</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-header">
					<div class="stat-icon" style="background: #f5f3ff; color: #8b5cf6;">📊</div>
				</div>
				<div>
					<div class="stat-value">0</div>
					<div class="stat-title">Reports Generated</div>
					<div class="stat-desc">This semester</div>
				</div>
			</div>
		</div>

		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2.5rem;">
			<!-- Quick Actions -->
			<div class="actions-section">
				<h2 class="section-title">✨ Quick Actions</h2>
				<div class="actions-grid">
					<a href="<?= url_for('/teacher/my_classes.php') ?>" class="action-tile">
						<div class="action-tile-icon">📚</div>
						<div>
							<h3>My Classes</h3>
							<p>View lists & schedules</p>
						</div>
					</a>
					<a href="<?= url_for('/teacher/advisory_list.php') ?>" class="action-tile">
						<div class="action-tile-icon">🧑‍🏫</div>
						<div>
							<h3>Advisory List</h3>
							<p>Manage your advisory</p>
						</div>
					</a>
					<a href="<?= url_for('/teacher/reports.php') ?>" class="action-tile">
						<div class="action-tile-icon">📈</div>
						<div>
							<h3>Reports</h3>
							<p>Generate summaries</p>
						</div>
					</a>
				</div>
			</div>

			<!-- Class Overview Section -->
			<div class="class-overview">
				<h2 class="section-title">🏫 Class Overview</h2>
				<div class="class-grid">
					<?php if (!empty($class_data)): ?>
						<?php foreach ($class_data as $class): ?>
							<div class="class-overview-card">
								<div class="class-header-bg">
									<h3><?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['section']); ?></h3>
									<p><?= htmlspecialchars($class['type']) ?> • SY <?= htmlspecialchars($current_sy) ?></p>
								</div>
								<div class="class-body">
									<div class="class-stat-row">
										<span class="stat-lbl">Total Students</span>
										<span class="stat-val"><?php echo $class['count']; ?></span>
									</div>
									<div class="class-stat-row">
										<span class="stat-lbl">Attendance Rate</span>
										<span class="stat-val">--</span>
									</div>
									<div class="class-stat-row">
										<span class="stat-lbl">Status</span>
										<span class="badge success" style="font-size: 0.75rem;">Active</span>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<div
							style="padding: 3rem 2rem; text-align: center; border-radius: var(--radius-lg); border: 2px dashed #cbd5e1; background: #f8fafc; grid-column: 1 / -1;">
							<div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.4;">📭</div>
							<h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; color: var(--text-primary);">No tagged records available</h3>
							<p style="margin: 0; font-size: 0.9rem; color: var(--text-secondary);">
								<?= isset($db_error) ? 'Database Error: ' . htmlspecialchars($db_error) : 'Contact the administrator to get your class assignments for SY ' . htmlspecialchars($current_sy) . '.' ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</body>

</html>