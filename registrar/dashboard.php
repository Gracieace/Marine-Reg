<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sync_notifications.php';
require_once __DIR__ . '/../config/dashboard_sync.php';
auth_require_role(['registrar', 'admin']);

// Get current user info
$current_user = $_SESSION['user']['username'] ?? '';
$current_user_id = $_SESSION['user']['id'] ?? 0;
$current_user_role = $_SESSION['user']['role'] ?? '';

// Get synchronized dashboard data
$dashboard_data = getSynchronizedDashboardData($current_user_role);
// Get current user info
$user_id = $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];

// Time of day greeting
$hour = date('H');
$greeting = 'Good Morning';
if ($hour >= 12 && $hour < 17) {
	$greeting = 'Good Afternoon';
} elseif ($hour >= 17) {
	$greeting = 'Good Evening';
}

$sync_status = getSynchronizationStatus();
$recent_activities = getSynchronizedRecentActivity($current_user_role, 5);

// Check if there was an error getting dashboard data
if (isset($dashboard_data['error'])) {
	// Log the error and set default values
	error_log('Dashboard data error: ' . $dashboard_data['error']);
	$dashboard_data = [
		'total_students' => 0,
		'monthly_enrollments' => 0,
		'pending_registrations' => 0,
		'total_registrations' => 0,
		'monthly_registrations' => 0,
		'id_cards_generated' => 0,
		'students_without_id' => 0,
		'enrollment_by_grade' => [],
		'recent_enrollments' => [],
		'recent_registrations' => [],
		'enrollment_by_strand' => [],
		'registration_by_grade' => [],
		'students_by_gender' => []
	];
}

// Extract data for backward compatibility with null checks and default values
$total_enrollments = $dashboard_data['total_students'] ?? 0;
$monthly_enrollments = $dashboard_data['monthly_enrollments'] ?? 0;
$pending_registrations = $dashboard_data['pending_registrations'] ?? 0;
$total_registrations = $dashboard_data['total_registrations'] ?? 0;
$monthly_registrations = $dashboard_data['monthly_registrations'] ?? 0;
$id_cards_generated = $dashboard_data['id_cards_generated'] ?? 0;
$students_without_id = $dashboard_data['students_without_id'] ?? 0;
$enrollment_by_grade = $dashboard_data['enrollment_by_grade'] ?? [];
$recent_enrollments = $dashboard_data['recent_enrollments'] ?? [];
$recent_registrations = $dashboard_data['recent_registrations'] ?? [];
$enrollment_by_strand = $dashboard_data['enrollment_by_strand'] ?? [];
$registration_by_grade = $dashboard_data['registration_by_grade'] ?? [];
$students_by_gender = $dashboard_data['students_by_gender'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Registrar Dashboard</title>
	<link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
	<link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>
	<?php require_once __DIR__ . '/../header.php'; ?>
	<?php require_once __DIR__ . '/registrar_side_panel.php'; ?>
	<div class="content main-content">
		<div class="dashboard-header" style="flex-direction: column; align-items: flex-start; gap: 8px;">
			<div class="header-text">
				<h1 style="font-size: 2.2rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 4px;">
					<?= $greeting ?>, <?= htmlspecialchars($username) ?>!
				</h1>
				<p style="color: #64748b; font-size: 1.1rem;">Manage enrollments, registrations, and student records efficiently.</p>
			</div>
			<div class="header-status" style="display: flex; align-items: center; gap: 16px; margin-top: 8px;">
				<div class="current-date" style="background: white; padding: 6px 16px; border-radius: 50px; font-weight: 600; color: var(--primary); font-size: 13px; box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
					<?= date('l, F j, Y') ?>
				</div>
				<!-- Synchronization Status -->
				<div class="sync-status <?php echo $sync_status['status']; ?>" style="font-size: 13px; display: flex; align-items: center; gap: 6px; font-weight: 500; color: #64748b;">
					<span class="sync-icon" style="color: <?php echo $sync_status['status'] === 'active' ? '#10b981' : '#f59e0b'; ?>;">●</span>
					<span><?php echo $sync_status['status'] === 'active' ? 'System Synchronized' : 'Sync: ' . ucfirst($sync_status['status']); ?></span>
				</div>
			</div>
		</div>



		<!-- Quick Stats -->
		<div class="stats-grid">
			<div class="stat-card">
				<div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">📝</div>
				<div class="stat-content">
					<h3>Current Enrollments</h3>
					<div class="stat-number" id="stat-enrollments"><?php echo number_format($total_enrollments); ?></div>
					<div class="stat-change positive" id="stat-enrollments-meta">+<?php echo $monthly_enrollments; ?> new this month</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon" style="background: #eef2ff; color: #6366f1;">📋</div>
				<div class="stat-content">
					<h3>Total Registrations</h3>
					<div class="stat-number" id="stat-registrations"><?php echo number_format($total_registrations); ?></div>
					<div class="stat-change positive" id="stat-registrations-meta">+<?php echo $monthly_registrations; ?> new this month</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon" style="background: #fffbeb; color: #f59e0b;">⏳</div>
				<div class="stat-content">
					<h3>Pending Personnel</h3>
					<div class="stat-number" id="stat-pending"><?php echo $pending_registrations; ?></div>
					<div class="stat-change warning" id="stat-pending-meta">Review required</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon" style="background: #ecfdf5; color: #10b981;">🪪</div>
				<div class="stat-content">
					<h3>Generated IDs</h3>
					<div class="stat-number" id="stat-ids"><?php echo number_format($id_cards_generated); ?></div>
					<div class="stat-change <?php echo $students_without_id > 0 ? 'warning' : 'positive'; ?>" id="stat-ids-meta">
						<?php echo $students_without_id > 0 ? $students_without_id . ' remaining' : 'Completed'; ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Enrollment Overview -->
		<div class="dashboard-section">
			<h2>Enrollment by Grade Level</h2>
			<div class="enrollment-grid">
				<?php if (!empty($enrollment_by_grade)): ?>
					<?php foreach ($enrollment_by_grade as $grade): ?>
						<div class="enrollment-card">
							<div class="enrollment-header">
								<h3><?php echo htmlspecialchars($grade['grade_level']); ?></h3>
								<span class="enrollment-count"><?php echo $grade['count']; ?> students</span>
							</div>
							<div class="enrollment-breakdown">
								<div class="breakdown-item">
									<span class="breakdown-label">Total:</span>
									<span class="breakdown-value"><?php echo $grade['count']; ?></span>
								</div>
								<div class="breakdown-item">
									<span class="breakdown-label">Status:</span>
									<span class="breakdown-value">Active</span>
								</div>
							</div>
							<div class="enrollment-status">
								<span class="status-indicator completed">Enrolled</span>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<div class="enrollment-card">
						<div class="enrollment-header">
							<h3>No Enrollments</h3>
							<span class="enrollment-count">0 students</span>
						</div>
						<div class="enrollment-breakdown">
							<div class="breakdown-item">
								<span class="breakdown-label">Total:</span>
								<span class="breakdown-value">0</span>
							</div>
							<div class="breakdown-item">
								<span class="breakdown-label">Status:</span>
								<span class="breakdown-value">No Data</span>
							</div>
						</div>
						<div class="enrollment-status">
							<span class="status-indicator pending">No Data</span>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Registration Overview -->
		<?php if (!empty($registration_by_grade)): ?>
			<div class="dashboard-section">
				<h2>Registration by Grade Level</h2>
				<div class="enrollment-grid">
					<?php foreach ($registration_by_grade as $grade): ?>
						<div class="enrollment-card">
							<div class="enrollment-header">
								<h3><?php echo htmlspecialchars($grade['grade_level']); ?></h3>
								<span class="enrollment-count"><?php echo $grade['count']; ?> applications</span>
							</div>
							<div class="enrollment-breakdown">
								<div class="breakdown-item">
									<span class="breakdown-label">Total:</span>
									<span class="breakdown-value"><?php echo $grade['count']; ?></span>
								</div>
								<div class="breakdown-item">
									<span class="breakdown-label">Status:</span>
									<span class="breakdown-value">Registered</span>
								</div>
							</div>
							<div class="enrollment-status">
								<span class="status-indicator completed">Registered</span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<!-- Enrollment by Strand -->
		<?php if (!empty($enrollment_by_strand)): ?>
			<div class="dashboard-section">
				<h2>Enrollment by Strand</h2>
				<div class="strand-breakdown">
					<?php foreach ($enrollment_by_strand as $strand): ?>
						<div class="strand-item">
							<div class="strand-name"><?php echo htmlspecialchars($strand['strand']); ?></div>
							<div class="strand-count"><?php echo $strand['count']; ?> students</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<!-- Student Demographics -->
		<?php if (!empty($students_by_gender)): ?>
			<div class="dashboard-section">
				<h2>Student Demographics</h2>
				<div class="demographics-grid">
					<?php foreach ($students_by_gender as $gender): ?>
						<div class="demographic-card">
							<div class="demographic-icon"><?php echo $gender['sex'] === 'Male' ? '👨' : '👩'; ?></div>
							<div class="demographic-content">
								<h3><?php echo htmlspecialchars($gender['sex']); ?></h3>
								<div class="demographic-count"><?php echo $gender['count']; ?> students</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>


		<!-- Recent Activities -->
		<div class="dashboard-section">
			<h2>Recent Activities</h2>
			<div class="activity-tabs">
				<button class="tab-button active" onclick="showTab(event, 'enrollments')">Recent Enrollments</button>
				<button class="tab-button" onclick="showTab(event, 'registrations')">Recent Registrations</button>
				<button class="tab-button" onclick="showTab(event, 'sync_activities')">Live Updates</button>
			</div>

			<div id="enrollments-tab" class="tab-content active">
				<div class="activity-list">
					<?php if (!empty($recent_enrollments)): ?>
						<?php foreach ($recent_enrollments as $enrollment): ?>
							<div class="activity-item">
								<div class="activity-icon">📝</div>
								<div class="activity-content">
									<div class="activity-title">New enrollment</div>
									<div class="activity-desc"><?php echo htmlspecialchars($enrollment['student_name']); ?>
										enrolled in <?php echo htmlspecialchars($enrollment['grade_level']); ?> -
										<?php echo htmlspecialchars($enrollment['section']); ?></div>
									<div class="activity-time">
										<?php echo date('M j, Y g:i A', strtotime($enrollment['enrolled_at'])); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<div class="activity-item">
							<div class="activity-icon">📝</div>
							<div class="activity-content">
								<div class="activity-title">No recent enrollments</div>
								<div class="activity-desc">No students have enrolled recently</div>
								<div class="activity-time">-</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div id="registrations-tab" class="tab-content">
				<div class="activity-list">
					<?php if (!empty($recent_registrations)): ?>
						<?php foreach ($recent_registrations as $registration): ?>
							<div class="activity-item">
								<div class="activity-icon">📋</div>
								<div class="activity-content">
									<div class="activity-title">New registration</div>
									<div class="activity-desc"><?php echo htmlspecialchars($registration['student_name']); ?>
										registered for <?php echo htmlspecialchars($registration['grade_level_to_enroll']); ?>
									</div>
									<div class="activity-time">
										<?php echo date('M j, Y g:i A', strtotime($registration['created_at'])); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<div class="activity-item">
							<div class="activity-icon">📋</div>
							<div class="activity-content">
								<div class="activity-title">No recent registrations</div>
								<div class="activity-desc">No students have registered recently</div>
								<div class="activity-time">-</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div id="sync_activities-tab" class="tab-content">
				<div class="activity-list">
					<?php if (!empty($recent_activities)): ?>
						<?php foreach ($recent_activities as $activity): ?>
							<div class="activity-item sync-activity">
								<div class="activity-icon"><?php echo $activity['icon']; ?></div>
								<div class="activity-content">
									<div class="activity-title"><?php echo htmlspecialchars($activity['description']); ?></div>
									<div class="activity-desc">
										<span
											class="user-badge <?php echo $activity['user_role']; ?>"><?php echo ucfirst($activity['user_role']); ?></span>
										<?php echo htmlspecialchars($activity['user']); ?>
									</div>
									<div class="activity-time">
										<?php echo date('M j, Y g:i A', strtotime($activity['timestamp'])); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<div class="activity-item">
							<div class="activity-icon">⚙️</div>
							<div class="activity-content">
								<div class="activity-title">No recent system activities</div>
								<div class="activity-desc">System activities will appear here in real-time</div>
								<div class="activity-time">-</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<style>
		body {
			font-family: 'Inter', sans-serif;
			background: #f1f5f9;
			color: #1e293b;
		}

		.main-content {
			padding: 180px 32px 48px;
			max-width: 1600px;
			box-sizing: border-box;
		}

		.dashboard-header {
			margin-bottom: 40px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.header-text h1 {
			color: #0f172a;
			margin: 0 0 8px 0;
			font-size: 32px;
			font-weight: 700;
		}

		.header-text p {
			color: #64748b;
			font-size: 16px;
			margin: 0;
		}

		/* Stats Grid */
		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
			gap: 24px;
			margin-bottom: 40px;
		}

		.stat-card {
			background: var(--bg-card);
			border-radius: 16px;
			padding: 24px;
			box-shadow: var(--shadow-sm);
			border: 1px solid var(--border);
			display: flex;
			align-items: center;
			gap: 20px;
			transition: all 0.2s ease;
			user-select: none;
			cursor: default;
		}

		.stat-icon {
			font-size: 28px;
			width: 56px;
			height: 56px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 12px;
			background: #f1f5f9;
			transition: transform 0.2s;
		}

		.stat-content h3 {
			margin: 0 0 6px 0;
			color: #64748b;
			font-size: 13px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			user-select: none;
		}

		.stat-number {
			font-size: 32px;
			font-weight: 700;
			color: #0f172a;
			margin: 0;
			line-height: 1.1;
			user-select: none;
			-webkit-user-select: none;
			-moz-user-select: none;
			-ms-user-select: none;
		}

		.stat-change {
			margin-top: 6px;
			font-size: 13px;
			font-weight: 500;
		}

		.stat-change.positive { color: #10b981; }
		.stat-change.warning { color: #f59e0b; }

		/* Dashboard Sections */
		.dashboard-section {
			background: white;
			border-radius: 16px;
			padding: 24px;
			box-shadow: var(--shadow-sm);
			border: 1px solid var(--border);
			margin-bottom: 24px;
			transition: box-shadow 0.2s;
		}

		.dashboard-section:hover {
			box-shadow: var(--shadow-md);
		}

		.dashboard-section h2 {
			margin: 0 0 24px 0;
			font-size: 18px;
			color: #0f172a;
			font-weight: 600;
		}

		.enrollment-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
			gap: 20px;
		}

		.enrollment-card {
			padding: 20px;
			border-radius: 12px;
			background: #f8fafc;
			border: 1px solid #e2e8f0;
			transition: all 0.2s;
		}

		.enrollment-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 12px;
		}

		.enrollment-header h3 { margin: 0; font-size: 16px; color: #0f172a; }
		.enrollment-count { font-size: 14px; font-weight: 700; color: var(--primary); }

		.breakdown-item {
			display: flex;
			justify-content: space-between;
			font-size: 13px;
			margin-bottom: 4px;
			color: #64748b;
		}

		.breakdown-value { color: #0f172a; font-weight: 600; }

		/* Activity List */
		.activity-list { display: flex; flex-direction: column; gap: 16px; }
		.activity-item {
			display: flex;
			gap: 16px;
			padding-bottom: 16px;
			border-bottom: 1px solid #f1f5f9;
		}
		.activity-item:last-child { border-bottom: none; padding-bottom: 0; }
		.activity-icon {
			width: 40px;
			height: 40px;
			background: #f1f5f9;
			border-radius: 10px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 20px;
		}
		.activity-title { font-weight: 600; color: #0f172a; font-size: 14px; margin-bottom: 2px; }
		.activity-desc { color: #64748b; font-size: 13px; }
		.activity-time { color: #94a3b8; font-size: 12px; margin-top: 4px; }

		/* Quick Actions */
		.quick-actions {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 20px;
		}
		.action-card {
			background: white;
			border-radius: 16px;
			padding: 24px;
			text-decoration: none;
			border: 1px solid var(--border);
			transition: all 0.2s;
			display: flex;
			flex-direction: column;
			align-items: center;
			text-align: center;
			gap: 16px;
		}
		.action-card:hover { transform: translateY(-4px); border-color: var(--primary); box-shadow: var(--shadow-md); }
		.action-icon {
			width: 64px;
			height: 64px;
			background: #eff6ff;
			color: var(--primary);
			border-radius: 16px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 28px;
		}
		.action-content h3 { margin: 0 0 4px 0; color: #0f172a; font-size: 15px; font-weight: 600; }
		.action-content p { margin: 0; color: #64748b; font-size: 13px; }

		/* Tabs */
		.activity-tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; }
		.tab-button {
			padding: 12px 20px;
			border: none;
			background: none;
			color: #64748b;
			font-weight: 600;
			font-size: 14px;
			border-bottom: 2px solid transparent;
			transition: all 0.2s;
		}
		.tab-button.active { color: var(--primary); border-bottom-color: var(--primary); }
		.tab-button:hover { color: var(--primary); }
		.tab-content { display: none; }
		.tab-content.active { display: block; }

		@media (max-width: 768px) {
			.main-content { padding: 120px 16px 24px; }
			.dashboard-header { flex-direction: column; align-items: flex-start; gap: 20px; }
			.header-actions { align-items: flex-start; width: 100%; }
			.stats-grid { grid-template-columns: 1fr 1fr; }
		}
		@media (max-width: 480px) {
			.stats-grid { grid-template-columns: 1fr; }
		}
	</style>

	<script>
		function showTab(event, tabName) {
			// Hide all tab contents
			const tabContents = document.querySelectorAll('.tab-content');
			tabContents.forEach(content => {
				content.classList.remove('active');
			});

			// Remove active class from all tab buttons
			const tabButtons = document.querySelectorAll('.tab-button');
			tabButtons.forEach(button => {
				button.classList.remove('active');
			});

			// Show selected tab content
			document.getElementById(tabName + '-tab').classList.add('active');

			// Add active class to clicked button
			if (event && event.currentTarget) {
				event.currentTarget.classList.add('active');
			}
		}

		// Real-time Dashboard Polling to stay connected with admin/database portal
		async function refreshDashboardStats() {
			try {
				const response = await fetch('<?= url_for('/registrar/dashboard_api.php') ?>');
				if (!response.ok) return;
				
				const result = await response.json();
				
				if (result.success) {
					const data = result.data;
					
					updateValue('stat-enrollments', data.total_students.toLocaleString());
					updateValue('stat-enrollments-meta', `+${data.monthly_enrollments} new this month`);
					
					updateValue('stat-registrations', data.total_registrations.toLocaleString());
					updateValue('stat-registrations-meta', `+${data.monthly_registrations} new this month`);
					
					updateValue('stat-pending', data.pending_registrations);
					
					updateValue('stat-ids', data.id_cards_generated.toLocaleString());
					const idsMeta = document.getElementById('stat-ids-meta');
					if (idsMeta) {
						if (data.students_without_id > 0) {
							idsMeta.innerText = `${data.students_without_id} remaining`;
							idsMeta.className = 'stat-change warning';
						} else {
							idsMeta.innerText = 'Completed';
							idsMeta.className = 'stat-change positive';
						}
					}

					// Optional: Refresh sync status if UI updated to show it
					const syncIcon = document.querySelector('.sync-icon');
					const syncText = document.querySelector('.sync-status span:last-child');
					if (syncIcon && result.sync_status) {
						syncIcon.style.color = result.sync_status.status === 'active' ? '#10b981' : '#f59e0b';
						if (syncText) {
							syncText.innerText = result.sync_status.status === 'active' ? 'System Synchronized' : 'Sync: ' + result.sync_status.status.charAt(0).toUpperCase() + result.sync_status.status.slice(1);
						}
					}
				}
			} catch (error) {
				console.error('Failed to refresh dashboard stats:', error);
			}
		}

		function updateValue(id, newValue) {
			const element = document.getElementById(id);
			if (element && element.innerText != newValue) {
				element.innerText = newValue;
				element.style.transition = 'color 0.3s ease';
				element.style.color = 'var(--primary)';
				
				setTimeout(() => {
					element.style.color = '';
				}, 2000);
			}
		}

		// Initial poll and set interval every 3 seconds for real-time connection
		setInterval(refreshDashboardStats, 3000);

	</script>
</body>

</html>