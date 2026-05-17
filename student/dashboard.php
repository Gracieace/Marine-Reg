<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role('student');

// Get current user info
$current_user = $_SESSION['user']['username'];

// Get student data
try {
    $pdo = db_connect();
    
    // Get student enrollment info
    $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE student_id = ? ORDER BY enrolled_at DESC LIMIT 1');
    $stmt->execute([$current_user]);
    $student_info = $stmt->fetch();
    
    // Get student registration details
    $stmt = $pdo->prepare('SELECT * FROM registrations WHERE first_name = ? OR last_name = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$current_user, $current_user]);
    $registration_info = $stmt->fetch();
    
    // If no enrollment found, try to get from registration
    if (!$student_info && $registration_info) {
        $student_info = [
            'student_name' => $registration_info['first_name'] . ' ' . $registration_info['last_name'],
            'student_id' => $current_user,
            'grade_level' => $registration_info['grade_level_to_enroll'] ?? 'Not enrolled',
            'section' => 'TBD',
            'photo_path' => null
        ];
    }
    
    // Default values if no data found
    if (!$student_info) {
        $student_info = [
            'student_name' => 'Student User',
            'student_id' => $current_user,
            'grade_level' => 'Not enrolled',
            'section' => 'TBD',
            'photo_path' => null
        ];
    }
    
} catch (Exception $e) {
    // Fallback values
    $student_info = [
        'student_name' => 'Student User',
        'student_id' => $current_user,
        'grade_level' => 'Not enrolled',
        'section' => 'TBD',
        'photo_path' => null
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Student Dashboard</title>
	<link rel="stylesheet" href="/SampleWeb/css/header.css">
	<link rel="stylesheet" href="/SampleWeb/css/sidebar.css">
</head>
<body>
	<?php require_once __DIR__ . '/../header.php'; ?>
	<?php require_once __DIR__ . '/student_side_panel.php'; ?>

	<div class="content">
		<div class="dashboard-header">
			<h1>Student Portal</h1>
			<p>Welcome back! Here's your academic overview and quick access to important information.</p>
		</div>

		<!-- Student Info Card -->
		<div class="student-info-card">
			<div class="student-avatar">
				<?php if ($student_info['photo_path'] && file_exists($student_info['photo_path'])): ?>
					<img src="<?php echo htmlspecialchars($student_info['photo_path']); ?>" alt="Student Photo">
				<?php else: ?>
					<img src="/SampleWeb/assets/photos/STU-2025001.bmp" alt="Student Photo" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHZpZXdCb3g9IjAgMCA2NCA2NCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMzIiIGZpbGw9IiNFNUU3RUIiLz4KPHBhdGggZD0iTTMyIDMyQzM4LjYyNzQgMzIgNDQgMjYuNjI3NCA0NCAyMEM0NCAxMy4zNzI2IDM4LjYyNzQgOCAzMiA4QzI1LjM3MjYgOCAyMCAxMy4zNzI2IDIwIDIwQzIwIDI2LjYyNzQgMjUuMzcyNiAzMiAzMiAzMloiIGZpbGw9IiM5Q0EzQUYiLz4KPHBhdGggZD0iTTE2IDQ4QzE2IDM5LjE2MzQgMjMuMTYzNCAzMiAzMiAzMkM0MC44MzY2IDMyIDQ4IDM5LjE2MzQgNDggNDhWNTZIMTZWNThaIiBmaWxsPSIjOUNBM0FGIi8+Cjwvc3ZnPgo='">
				<?php endif; ?>
			</div>
			<div class="student-details">
				<h2><?php echo htmlspecialchars($student_info['student_name']); ?></h2>
				<p class="student-id">Student ID: <?php echo htmlspecialchars($student_info['student_id']); ?></p>
				<p class="student-grade"><?php echo htmlspecialchars($student_info['grade_level']); ?></p>
				<p class="student-section">Section: <?php echo htmlspecialchars($student_info['section']); ?></p>
			</div>
			<div class="student-status">
				<span class="status-badge <?php echo ($student_info['grade_level'] !== 'Not enrolled') ? 'enrolled' : 'pending'; ?>">
					<?php echo ($student_info['grade_level'] !== 'Not enrolled') ? 'Enrolled' : 'Pending'; ?>
				</span>
			</div>
		</div>

		<!-- Quick Stats -->
		<div class="stats-grid">
			<div class="stat-card">
				<div class="stat-icon">📚</div>
				<div class="stat-content">
					<h3>Current GPA</h3>
					<div class="stat-number">3.85</div>
					<div class="stat-change positive">+0.15 this semester</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">📝</div>
				<div class="stat-content">
					<h3>Subjects</h3>
					<div class="stat-number">8</div>
					<div class="stat-change">Active this semester</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">⏰</div>
				<div class="stat-content">
					<h3>Attendance</h3>
					<div class="stat-number">95%</div>
					<div class="stat-change positive">Excellent</div>
				</div>
			</div>
			<div class="stat-card">
				<div class="stat-icon">📅</div>
				<div class="stat-content">
					<h3>Days Left</h3>
					<div class="stat-number">45</div>
					<div class="stat-change">Until semester ends</div>
				</div>
			</div>
		</div>

		<!-- Recent Grades -->
		<div class="dashboard-section">
			<h2>Recent Grades</h2>
			<div class="grades-list">
				<div class="grade-item">
					<div class="subject-info">
						<h4>Mathematics</h4>
						<p>Advanced Algebra</p>
					</div>
					<div class="grade-score">A-</div>
					<div class="grade-percentage">92%</div>
				</div>
				<div class="grade-item">
					<div class="subject-info">
						<h4>Science</h4>
						<p>Physics</p>
					</div>
					<div class="grade-score">A</div>
					<div class="grade-percentage">95%</div>
				</div>
				<div class="grade-item">
					<div class="subject-info">
						<h4>English</h4>
						<p>Literature</p>
					</div>
					<div class="grade-score">B+</div>
					<div class="grade-percentage">88%</div>
				</div>
				<div class="grade-item">
					<div class="subject-info">
						<h4>History</h4>
						<p>World History</p>
					</div>
					<div class="grade-score">A-</div>
					<div class="grade-percentage">90%</div>
				</div>
			</div>
		</div>

		<!-- Upcoming Events -->
		<div class="dashboard-section">
			<h2>Upcoming Events</h2>
			<div class="events-list">
				<div class="event-item">
					<div class="event-date">
						<span class="day">15</span>
						<span class="month">Dec</span>
					</div>
					<div class="event-content">
						<h4>Mathematics Exam</h4>
						<p>Advanced Algebra - Room 201</p>
						<span class="event-time">9:00 AM</span>
					</div>
				</div>
				<div class="event-item">
					<div class="event-date">
						<span class="day">18</span>
						<span class="month">Dec</span>
					</div>
					<div class="event-content">
						<h4>Science Project Due</h4>
						<p>Physics Lab Report</p>
						<span class="event-time">11:59 PM</span>
					</div>
				</div>
				<div class="event-item">
					<div class="event-date">
						<span class="day">20</span>
						<span class="month">Dec</span>
					</div>
					<div class="event-content">
						<h4>Semester Break</h4>
						<p>Winter Holiday</p>
						<span class="event-time">All Day</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Quick Actions -->
		<div class="dashboard-section">
			<h2>Quick Actions</h2>
			<div class="quick-actions">
				<a href="#enrollment" class="action-card">
					<div class="action-icon">📝</div>
					<div class="action-content">
						<h3>View Enrollment</h3>
						<p>Check enrollment status</p>
					</div>
				</a>
				<a href="#grades" class="action-card">
					<div class="action-icon">📚</div>
					<div class="action-content">
						<h3>View Grades</h3>
						<p>See all your grades</p>
					</div>
				</a>
				<a href="#profile" class="action-card">
					<div class="action-icon">🧑</div>
					<div class="action-content">
						<h3>Update Profile</h3>
						<p>Manage your information</p>
					</div>
				</a>
				<a href="#settings" class="action-card">
					<div class="action-icon">⚙️</div>
					<div class="action-content">
						<h3>Settings</h3>
						<p>Account preferences</p>
					</div>
				</a>
			</div>
		</div>
	</div>

	<style>
		.dashboard-header {
			margin-bottom: 30px;
		}

		.dashboard-header h1 {
			color: #0f2a44;
			margin-bottom: 8px;
			font-size: 28px;
		}

		.dashboard-header p {
			color: #64748b;
			font-size: 16px;
			margin: 0;
		}

		.student-info-card {
			background: white;
			border-radius: 16px;
			padding: 24px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.1);
			display: flex;
			align-items: center;
			gap: 20px;
			margin-bottom: 30px;
			border-left: 4px solid #3b82f6;
		}

		.student-avatar {
			width: 80px;
			height: 80px;
			border-radius: 50%;
			overflow: hidden;
			box-shadow: 0 4px 8px rgba(0,0,0,0.1);
		}

		.student-avatar img {
			width: 100%;
			height: 100%;
			object-fit: cover;
		}

		.student-details {
			flex: 1;
		}

		.student-details h2 {
			margin: 0 0 8px 0;
			color: #0f2a44;
			font-size: 24px;
		}

		.student-details p {
			margin: 4px 0;
			color: #64748b;
			font-size: 14px;
		}

		.student-id {
			font-weight: 600;
			color: #3b82f6 !important;
		}

		.student-status {
			display: flex;
			align-items: center;
		}

		.status-badge {
			padding: 8px 16px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: 600;
			text-transform: uppercase;
		}

		.status-badge.enrolled {
			background: #dcfce7;
			color: #166534;
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 20px;
			margin-bottom: 40px;
		}

		.stat-card {
			background: white;
			border-radius: 12px;
			padding: 20px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			display: flex;
			align-items: center;
			gap: 16px;
		}

		.stat-icon {
			font-size: 28px;
			width: 50px;
			height: 50px;
			display: flex;
			align-items: center;
			justify-content: center;
			background: #f0f9ff;
			border-radius: 10px;
		}

		.stat-content h3 {
			margin: 0 0 6px 0;
			color: #64748b;
			font-size: 13px;
			font-weight: 500;
		}

		.stat-number {
			font-size: 24px;
			font-weight: 700;
			color: #0f2a44;
			margin-bottom: 4px;
		}

		.stat-change {
			font-size: 11px;
			font-weight: 500;
		}

		.stat-change.positive {
			color: #059669;
		}

		.dashboard-section {
			margin-bottom: 40px;
		}

		.dashboard-section h2 {
			color: #0f2a44;
			margin-bottom: 20px;
			font-size: 20px;
		}

		.grades-list {
			background: white;
			border-radius: 12px;
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
		}

		.grade-item {
			display: flex;
			align-items: center;
			padding: 16px 20px;
			border-bottom: 1px solid #f1f5f9;
			gap: 16px;
		}

		.grade-item:last-child {
			border-bottom: none;
		}

		.subject-info {
			flex: 1;
		}

		.subject-info h4 {
			margin: 0 0 4px 0;
			color: #0f2a44;
			font-size: 16px;
		}

		.subject-info p {
			margin: 0;
			color: #64748b;
			font-size: 14px;
		}

		.grade-score {
			font-size: 20px;
			font-weight: 700;
			color: #059669;
			text-align: center;
			min-width: 40px;
		}

		.grade-percentage {
			font-size: 14px;
			color: #64748b;
			text-align: center;
			min-width: 50px;
		}

		.events-list {
			background: white;
			border-radius: 12px;
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
		}

		.event-item {
			display: flex;
			align-items: center;
			padding: 16px 20px;
			border-bottom: 1px solid #f1f5f9;
			gap: 16px;
		}

		.event-item:last-child {
			border-bottom: none;
		}

		.event-date {
			text-align: center;
			min-width: 60px;
		}

		.event-date .day {
			display: block;
			font-size: 20px;
			font-weight: 700;
			color: #3b82f6;
		}

		.event-date .month {
			display: block;
			font-size: 12px;
			color: #64748b;
			text-transform: uppercase;
		}

		.event-content {
			flex: 1;
		}

		.event-content h4 {
			margin: 0 0 4px 0;
			color: #0f2a44;
			font-size: 16px;
		}

		.event-content p {
			margin: 0 0 4px 0;
			color: #64748b;
			font-size: 14px;
		}

		.event-time {
			font-size: 12px;
			color: #94a3b8;
		}

		.quick-actions {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 16px;
		}

		.action-card {
			background: white;
			border-radius: 12px;
			padding: 20px;
			text-decoration: none;
			color: inherit;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			transition: transform 0.2s, box-shadow 0.2s;
			display: flex;
			flex-direction: column;
			align-items: center;
			text-align: center;
			gap: 12px;
		}

		.action-card:hover {
			transform: translateY(-2px);
			box-shadow: 0 4px 16px rgba(0,0,0,0.15);
		}

		.action-icon {
			font-size: 28px;
			width: 50px;
			height: 50px;
			display: flex;
			align-items: center;
			justify-content: center;
			background: #f0f9ff;
			border-radius: 10px;
		}

		.action-content h3 {
			margin: 0 0 4px 0;
			color: #0f2a44;
			font-size: 14px;
		}

		.action-content p {
			margin: 0;
			color: #64748b;
			font-size: 12px;
		}

		@media (max-width: 768px) {
			.student-info-card {
				flex-direction: column;
				text-align: center;
			}
			
			.stats-grid {
				grid-template-columns: repeat(2, 1fr);
			}
			
			.quick-actions {
				grid-template-columns: repeat(2, 1fr);
			}
		}
	</style>
</body>
</html>

