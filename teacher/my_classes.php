<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['teacher', 'admin']);

$user_id = $_SESSION['user']['id'];
try {
	$pdo = db_connect();
	initialize_schema($pdo);

	$current_sy = get_active_school_year($pdo);


	// 1. Get Teacher Profile
	$stmt = $pdo->prepare('SELECT * FROM teachers WHERE user_id = ? LIMIT 1');
	$stmt->execute([$user_id]);
	$teacher_profile = $stmt->fetch();
	$teacher_code = $teacher_profile['employee_code'] ?? 'T-' . str_pad($user_id, 4, '0', STR_PAD_LEFT);

	// 2. Fetch Advisory Class (Multi-Source Detection)
	$advisory_class = false;

	// Source 1: position_assignments (The most robust way)
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

	// Source 2: sections.adviser_id (Direct linking)
	if (!$advisory_class) {
		$adviser_ids = [$user_id];
		if ($teacher_profile) $adviser_ids[] = $teacher_profile['id'];
		$placeholders = implode(',', array_fill(0, count($adviser_ids), '?'));
		
		$stmt = $pdo->prepare("SELECT id, grade_level, section_name, school_year FROM sections
			 WHERE adviser_id IN ($placeholders) AND school_year = ?
			 LIMIT 1");
		$stmt->execute(array_merge($adviser_ids, [$current_sy]));
		$advisory_class = $stmt->fetch();
	}

	// Source 3: teachers.grade_level/section (Legacy metadata)
	if (!$advisory_class && $teacher_profile && !empty($teacher_profile['grade_level'])) {
		$stmt = $pdo->prepare("SELECT id, grade_level, section_name, school_year FROM sections 
			WHERE grade_level = ? AND section_name = ? AND school_year = ? LIMIT 1");
		$stmt->execute([$teacher_profile['grade_level'], $teacher_profile['section'], $current_sy]);
		$advisory_class = $stmt->fetch();
	}

	// Get statistics for advisory
	if ($advisory_class) {
		$stmt = $pdo->prepare('SELECT count(*) FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?');
		$stmt->execute([$advisory_class['grade_level'], $advisory_class['section_name'], $advisory_class['school_year']]);
		$advisory_class['student_count'] = $stmt->fetchColumn();
	}
} catch (Exception $e) {
	$error = $e->getMessage();
}

// 3. Fetch Subject Loads (Teaching Assignments) - Using standardized user_id
$subject_loads = [];
$grouped_loads = []; // [section_id] => ['details' => section, 'subjects' => [subjects]]
try {
	$adviser_ids = [$user_id];
	if ($teacher_profile) $adviser_ids[] = $teacher_profile['id'];
	$placeholders = implode(',', array_fill(0, count($adviser_ids), '?'));

	$sql_simple = "SELECT st.*, s.subject_name, s.subject_code, sec.grade_level, sec.section_name 
                    FROM subject_teachers st
                    JOIN curriculum s ON st.subject_id = s.id
                    JOIN sections sec ON st.section_id = sec.id
                    WHERE st.teacher_id IN ($placeholders) AND st.school_year = ?
                    ORDER BY sec.grade_level, sec.section_name, s.subject_name";
	$stmt = $pdo->prepare($sql_simple);
	$stmt->execute(array_merge($adviser_ids, [$current_sy]));
	$subject_loads = $stmt->fetchAll();

    foreach ($subject_loads as $load) {
        $sec_id = $load['section_id'];
        if (!isset($grouped_loads[$sec_id])) {
            $grouped_loads[$sec_id] = [
                'grade_level' => $load['grade_level'],
                'section_name' => $load['section_name'],
                'subjects' => []
            ];
        }
        $grouped_loads[$sec_id]['subjects'][] = $load;
    }
} catch (Exception $e) { }

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Classes | Teacher Portal</title>
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

		.badge {
			padding: 4px 10px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: 600;
			text-transform: capitalize;
			display: inline-block;
		}

		.badge-primary {
			background: #dbeafe;
			color: #1e40af;
		}

		.badge-success {
			background: #dcfce7;
			color: #166534;
		}

		.badge-danger {
			background: #fee2e2;
			color: #991b1b;
		}

		.badge-warning {
			background: #fef3c7;
			color: #92400e;
		}

		.badge-secondary {
			background: #f1f5f9;
			color: #475569;
		}

		/* Component Specific Overrides */
		.classes-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
			gap: 1.5rem;
		}

		/* CARD DESIGN */
		.class-card {
			background: var(--card-bg);
			border-radius: 16px;
			overflow: hidden;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
			transition: transform 0.2s ease, box-shadow 0.2s ease;
			border: 1px solid rgba(226, 232, 240, 0.8);
			display: flex;
			flex-direction: column;
		}

		.class-card:hover {
			transform: translateY(-4px);
			box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
		}

		/* CARD HEADER */
		.card-header {
			padding: 1.5rem;
			background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
			/* default blue */
			color: white;
			position: relative;
		}

		/* Advisory Card Specific */
		.card-header.advisory {
			background: linear-gradient(135deg, #10b981 0%, #059669 100%);
			/* green */
		}

		/* Subject Card Specific Variants (randomized feel) */
		.card-header.variant-1 {
			background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
		}

		/* indigo */
		.card-header.variant-2 {
			background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
		}

		/* violet */
		.card-header.variant-3 {
			background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
		}

		/* amber */

		.class-badge {
			position: absolute;
			top: 1rem;
			right: 1rem;
			background: rgba(255, 255, 255, 0.2);
			backdrop-filter: blur(4px);
			padding: 0.25rem 0.75rem;
			border-radius: 20px;
			font-size: 0.75rem;
			font-weight: 600;
			color: white;
			border: 1px solid rgba(255, 255, 255, 0.3);
		}

		.subject-code {
			font-size: 0.85rem;
			opacity: 0.9;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			margin-bottom: 0.25rem;
			display: block;
		}

		.subject-title {
			font-size: 1.25rem;
			font-weight: 700;
			margin: 0;
			line-height: 1.3;
		}

		/* CARD BODY */
		.card-body {
			padding: 1.5rem;
			flex: 1;
			/* Pushes footer down */
		}

		.info-row {
			display: flex;
			align-items: center;
			gap: 0.75rem;
			margin-bottom: 0.75rem;
			color: var(--muted);
			font-size: 0.95rem;
		}

		/* EMPTY STATE */
		.empty-state {
			grid-column: 1 / -1;
			text-align: center;
			padding: 4rem 2rem;
			background: white;
			border-radius: 16px;
			border: 2px dashed #e2e8f0;
			color: #64748b;
		}

		.empty-icon {
			font-size: 3rem;
			margin-bottom: 1rem;
			opacity: 0.5;
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

		<div class="title-block">
			<div>
				<h1>My Classes</h1>
				<p style="color: var(--muted); margin-top: 5px; font-size: 14px;">Manage your assigned sections and
					subjects for SY
					<?= htmlspecialchars($current_sy) ?>
				</p>
			</div>
			<div>
				<span class="badge badge-primary">ID: <?= htmlspecialchars($teacher_code) ?></span>
			</div>
		</div>

		<div class="classes-grid">

			<!-- 1. ADVISORY CLASS (Priority) -->
			<?php if ($advisory_class): ?>
				<div class="class-card card" style="padding: 0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
					<div class="card-header advisory">
						<div class="class-badge">Advisory Class</div>
						<span class="subject-code"><?= htmlspecialchars($advisory_class['school_year']) ?></span>
						<h3 class="subject-title">Grade
							<?= htmlspecialchars($advisory_class['grade_level'] . ' - ' . $advisory_class['section_name']) ?>
						</h3>
					</div>
					<div class="card-body">
						<div class="info-row">
							<span class="info-icon">👥</span>
							<span><strong><?= number_format($advisory_class['student_count'] ?? 0) ?></strong> Students
								Enrolled</span>
						</div>
						<div class="info-row">
							<span class="info-icon">📍</span>
							<span>Homeroom Section</span>
						</div>
						<div class="info-row">
							<span class="info-icon">📅</span>
							<span>Daily Attendance & Monitoring</span>
						</div>
					</div>
					<div class="card-footer"
						style="padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: space-between;">
						<a href="reports/sf1_form.php?section=<?= urlencode($advisory_class['section_name']) ?>&grade=<?= urlencode($advisory_class['grade_level']) ?>"
							class="btn btn-outline">
							📄 Records
						</a>
						<a href="advisory_list.php" class="btn btn-primary">
							View Students ➔
						</a>
					</div>
				</div>
			<?php endif; ?>

			<!-- 2. SUBJECT LOADS (Grouped by Section) -->
			<?php if (!empty($grouped_loads)): ?>
				<?php foreach ($grouped_loads as $sec_id => $group):
					$index = array_search($sec_id, array_keys($grouped_loads));
					$variant = ($index % 3) + 1;
					?>
					<div class="class-card card" style="padding: 0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
						<div class="card-header variant-<?= $variant ?>">
							<div class="class-badge"><?= count($group['subjects']) ?> Subjects</div>
							<span class="subject-code"><?= htmlspecialchars($group['grade_level']) ?></span>
							<h3 class="subject-title"><?= htmlspecialchars($group['section_name']) ?></h3>
						</div>
						<div class="card-body" style="padding: 0;">
							<div style="background: #f8fafc; padding: 12px 20px; border-bottom: 1px solid #edf2f7; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">
                                Assigned Subjects
                            </div>
                            <div class="subjects-list">
                                <?php foreach ($group['subjects'] as $sub): ?>
                                    <div style="padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-main); font-size: 14px;"><?= htmlspecialchars($sub['subject_name']) ?></div>
                                            <div style="font-size: 11px; color: var(--muted);"><?= htmlspecialchars($sub['subject_code']) ?></div>
                                        </div>
                                        <a href="grades.php?subject=<?= $sub['id'] ?>" class="btn btn-sm btn-outline" style="padding: 6px 12px; font-size: 12px;">
                                            Grades ➔
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<!-- EMPTY STATE -->
			<?php if (!$advisory_class && empty($subject_loads)): ?>
				<div class="empty-state">
					<div class="empty-icon">📭</div>
					<h3>No Classes Assigned</h3>
					<p>You currently don't have an advisory class or any subject loads assigned for this school year.</p>
				</div>
			<?php endif; ?>

		</div>
	</div>
</body>

</html>