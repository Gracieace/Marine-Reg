<?php
require_once '../auth/auth.php';
auth_require_role('admin');
require_once '../config/db.php';

// Enable error reporting
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

$pdo = db_connect();

// Ensure tables exist
try {
    initialize_schema($pdo);
} catch (Exception $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// Auto-Migration: Match legacy adviser_id/teacher_id (teachers.id) to user_id (users.id)
try {
    // 1. Get mapping of all teachers who have a user_id
    $stmt = $pdo->query("SELECT id, user_id FROM teachers WHERE user_id IS NOT NULL");
    $teacher_map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($teacher_map)) {
        // 2. Update sections table
        $update_sec = $pdo->prepare("UPDATE sections SET adviser_id = ? WHERE adviser_id = ?");
        foreach ($teacher_map as $t_id => $u_id) {
            $update_sec->execute([$u_id, $t_id]);
        }

        // 3. Update subject_teachers table
        $update_st = $pdo->prepare("UPDATE subject_teachers SET teacher_id = ? WHERE teacher_id = ?");
        foreach ($teacher_map as $t_id => $u_id) {
            $update_st->execute([$u_id, $t_id]);
        }
    }
} catch (PDOException $e) { /* ignore migration errors */ }
$current_school_year = get_active_school_year($pdo);
$selected_sy = $_GET['sy_filter'] ?? $current_school_year;

// Auto-Sync Sections from Enrollments (Import missing sections to management table)
try {
    // Check if enrollments table exists before query
    $check_table = $pdo->query("SHOW TABLES LIKE 'enrollments'")->fetch();
    if ($check_table) {
        $stmt = $pdo->query("SELECT DISTINCT grade_level, section, school_year FROM enrollments");
        $enrollment_sections = $stmt->fetchAll();
        
        $insert_stmt = $pdo->prepare("INSERT IGNORE INTO sections (grade_level, section_name, school_year) VALUES (?, ?, ?)");
        foreach ($enrollment_sections as $es) {
            if (!empty($es['grade_level']) && !empty($es['section']) && !empty($es['school_year'])) {
                $insert_stmt->execute([$es['grade_level'], $es['section'], $es['school_year']]);
            }
        }
    }
} catch (PDOException $e) {
    // Ignore sync errors during initialization
}

// Handle Form Submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $grade_level = $_POST['grade_level'] ?? '';
        $section_name = $_POST['section_name'] ?? '';
        $adviser_id = !empty($_POST['adviser_id']) ? $_POST['adviser_id'] : null;
        $room_number = $_POST['room_number'] ?? '';
        $selected_subjects = $_POST['subjects'] ?? [];

        $school_year = $_POST['school_year'] ?? $current_school_year;

        if ($grade_level && $section_name && $school_year) {
            try {
                $pdo->beginTransaction();
                if ($action === 'add') {
                    // Check if exists first for better error message
                    $check = $pdo->prepare("SELECT id FROM sections WHERE grade_level = ? AND section_name = ? AND school_year = ?");
                    $check->execute([$grade_level, $section_name, $school_year]);
                    if ($check->fetch()) {
                        throw new PDOException("The section '$section_name' for $grade_level already exists in SY $school_year.", 23000);
                    }

                    $stmt = $pdo->prepare("INSERT INTO sections (grade_level, section_name, adviser_id, room_number, school_year) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$grade_level, $section_name, $adviser_id, $room_number, $school_year]);
                    $section_id = $pdo->lastInsertId();
                    $success_message = "Section added successfully!";
                } else {
                    $section_id = $_POST['section_id'] ?? 0;
                    // Get old state for cleanup
                    $stmt = $pdo->prepare("SELECT grade_level, section_name, school_year FROM sections WHERE id = ?");
                    $stmt->execute([$section_id]);
                    $old = $stmt->fetch();

                    $stmt = $pdo->prepare("UPDATE sections SET grade_level = ?, section_name = ?, adviser_id = ?, room_number = ?, school_year = ? WHERE id = ?");
                    $stmt->execute([$grade_level, $section_name, $adviser_id, $room_number, $school_year, $section_id]);
                    $success_message = "Section updated successfully!";

                    if ($old) {
                        $pdo->prepare("DELETE FROM position_assignments WHERE position_type = 'class_adviser' AND grade_level = ? AND section = ? AND school_year = ?")
                            ->execute([$old['grade_level'], $old['section_name'], $old['school_year']]);
                    }
                }

                // Sync position_assignments
                $pdo->prepare("DELETE FROM position_assignments WHERE position_type = 'class_adviser' AND grade_level = ? AND section = ? AND school_year = ?")
                    ->execute([$grade_level, $section_name, $school_year]);

                if ($adviser_id) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO position_assignments (user_id, position_type, grade_level, section, school_year) VALUES (?, 'class_adviser', ?, ?, ?)");
                    $stmt->execute([$adviser_id, $grade_level, $section_name, $school_year]);
                }

                // Save section-subject assignments
                if ($section_id) {
                    try {
                        // Delete existing assignments
                        $stmt = $pdo->prepare("DELETE FROM section_subjects WHERE section_id = ?");
                        $stmt->execute([$section_id]);
                        // Insert new assignments
                        if (!empty($selected_subjects)) {
                            $ins = $pdo->prepare("INSERT INTO section_subjects (section_id, curriculum_id) VALUES (?, ?)");
                            foreach ($selected_subjects as $curriculum_id) {
                                $ins->execute([$section_id, $curriculum_id]);
                            }
                        }
                    } catch (PDOException $ignored) {
                        // section_subjects table may not exist yet
                    }
                }
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    $error_message = "Error: Section already exists for this school year.";
                } else {
                    $error_message = "Database Error: " . $e->getMessage();
                }
            }
        } else {
            $error_message = "Grade Level and Section Name are required.";
        }
    } elseif ($action === 'delete') {
        $id = $_POST['section_id'] ?? 0;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT grade_level, section_name, school_year FROM sections WHERE id = ?");
            $stmt->execute([$id]);
            $details = $stmt->fetch();

            $stmt = $pdo->prepare("DELETE FROM sections WHERE id = ?");
            $stmt->execute([$id]);

            if ($details) {
                $pdo->prepare("DELETE FROM position_assignments WHERE position_type = 'class_adviser' AND grade_level = ? AND section = ? AND school_year = ?")
                    ->execute([$details['grade_level'], $details['section_name'], $details['school_year']]);
            }
            $pdo->commit();
            $success_message = "Section deleted successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = "Error deleting section: " . $e->getMessage();
        }
    } elseif ($action === 'assign_subject_teachers') {
        $section_id = $_POST['section_id'] ?? 0;
        $subject_teachers = $_POST['subject_teachers'] ?? []; // Array of subject_id => teacher_user_id
        $school_year = $_POST['school_year'] ?? $current_school_year;

        if ($section_id) {
            try {
                $pdo->beginTransaction();
                // We don't delete all, we only update/insert specific ones or we can delete and re-insert for this section
                $stmt = $pdo->prepare("DELETE FROM subject_teachers WHERE section_id = ? AND school_year = ?");
                $stmt->execute([$section_id, $school_year]);

                if (!empty($subject_teachers)) {
                    $ins = $pdo->prepare("INSERT INTO subject_teachers (subject_id, teacher_id, section_id, school_year) VALUES (?, ?, ?, ?)");
                    foreach ($subject_teachers as $subject_id => $teacher_user_id) {
                        if ($teacher_user_id) {
                            $ins->execute([$subject_id, $teacher_user_id, $section_id, $school_year]);
                        }
                    }
                }
                $pdo->commit();
                $success_message = "Subject teachers assigned successfully!";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error_message = "Error assigning subject teachers: " . $e->getMessage();
            }
        }
    }
}

// Fetch Sections - Ordered by SY DESC then Grade
$where_clause = "";
$params = [];
if ($selected_sy && $selected_sy !== 'all') {
    $where_clause = " WHERE s.school_year = ? ";
    $params[] = $selected_sy;
}

// Refactored query to use concat for first/last name
$stmt = $pdo->prepare("SELECT s.*, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as adviser_name 
                      FROM sections s 
                      LEFT JOIN users u ON s.adviser_id = u.id 
                      $where_clause
                      ORDER BY s.school_year DESC, s.grade_level ASC, s.section_name ASC");
$stmt->execute($params);
$sections = $stmt->fetchAll();

// Get unique school years for filter dropdown
$sy_stmt = $pdo->query("SELECT DISTINCT school_year FROM sections ORDER BY school_year DESC");
$all_school_years = $sy_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch assigned subjects for each section
$section_subjects_map = [];
try {
    foreach ($sections as $section) {
        $stmt = $pdo->prepare("SELECT ss.curriculum_id, c.subject_name, c.subject_code 
                               FROM section_subjects ss 
                               JOIN curriculum c ON ss.curriculum_id = c.id 
                               WHERE ss.section_id = ?");
        $stmt->execute([$section['id']]);
        $section_subjects_map[$section['id']] = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // section_subjects or curriculum table may not exist yet
    foreach ($sections as $section) {
        $section_subjects_map[$section['id']] = [];
    }
}

// Fetch Teachers for Dropdown (Strictly from users table to guarantee portal access & only Teachers)
$stmt = $pdo->query("SELECT id, CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name FROM users WHERE role = 'teacher' ORDER BY first_name, last_name");
$teachers = $stmt->fetchAll();

// Fetch existing subject-teacher assignments (fetch all to support multi-year view)
$subject_teacher_assignments = [];
try {
    $stmt = $pdo->query("SELECT * FROM subject_teachers");
    while ($row = $stmt->fetch()) {
        $subject_teacher_assignments[$row['section_id']][$row['subject_id']] = $row['teacher_id'];
    }
} catch (PDOException $e) {}

// Fetch all curriculum subjects for the modal checkboxes
$all_subjects = [];
try {
    $stmt = $pdo->query("SELECT id, subject_code, subject_name, grade_level FROM curriculum ORDER BY grade_level, subject_name");
    $all_subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    // curriculum table may not exist yet
}



?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Section Management</title>
    <!-- Include Sidebar CSS -->
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d47a1;
            --secondary-color: #1976d2;
            --text-color: #333;
            --bg-color: #f4f6f9;
            --border-color: #dee2e6;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-top: var(--header-height);
            /* Adjusted to prevent header overlap */
            padding: 20px;
            /* Sidebar margin is handled by sidebar.css */
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-header h1 {
            margin: 0;
            color: var(--primary-color);
            font-size: 24px;
        }

        .btn {
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .modern-table,
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050; /* Below header (1100) but above content */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding-top: var(--header-height);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 24px;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 71, 161, 0.1);
        }

        .status-message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-success {
            background: #d1fae5;
            color: #065f46;
        }

        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: #666;
        }

        .subject-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1565c0;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            margin: 2px 3px;
            font-weight: 500;
        }

        .subjects-checkbox-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            background: #fafbfc;
        }

        .subject-checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s;
            font-weight: normal;
        }

        .subject-checkbox-item:hover {
            background: #e3f2fd;
        }

        .subject-checkbox-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }

        .subject-checkbox-label {
            font-size: 13px;
            color: #333;
        }

        .subjects-hint {
            color: #999;
            font-style: italic;
            font-size: 13px;
            margin: 0;
            padding: 4px 0;
        }
    </style>
</head>

<body>
    <?php require_once 'admin_header.php'; ?>
    <?php require_once 'admin_sidebar.php'; ?>

    <div class="content main-content">
        <div class="page-header">
            <div>
                <h1>Section Management</h1>
                <p style="color: #666; margin-top: 5px;">
                    Manage sections, advisers, and room assignments 
                    <span style="margin-left: 10px; font-weight: 600; color: var(--primary-color);">
                        (<?= $selected_sy === 'all' ? 'All Years' : ($selected_sy . ' Only') ?>: <?= count($sections) ?>)
                    </span>
                </p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <form method="GET" id="syFilterForm" style="display: flex; align-items: center; gap: 8px;">
                    <label style="margin: 0; white-space: nowrap;">Filter Year:</label>
                    <select name="sy_filter" onchange="this.form.submit()" style="padding: 8px; border-radius: 6px; border: 1px solid var(--border-color);">
                        <option value="all" <?= $selected_sy === 'all' ? 'selected' : '' ?>>All Years</option>
                        <?php foreach ($all_school_years as $sy): ?>
                            <option value="<?= $sy ?>" <?= $selected_sy === $sy ? 'selected' : '' ?>><?= $sy ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    ➕ Add New Section
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="status-message status-success">✅ <?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="status-message status-error">⚠️ <?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Grade Level</th>
                            <th>Section Name</th>
                            <th>Subjects</th>
                            <th>Adviser</th>
                            <th>Room</th>
                            <th>School Year</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sections)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    No sections found. Click "Add New Section" to create one.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sections as $section): ?>
                                <tr>
                                    <td><?= htmlspecialchars($section['grade_level']) ?></td>
                                    <td><strong><?= htmlspecialchars($section['section_name']) ?></strong></td>
                                    <td>
                                        <?php
                                        $subjects = $section_subjects_map[$section['id']] ?? [];
                                        if (!empty($subjects)):
                                            foreach ($subjects as $subj): ?>
                                                <span class="subject-badge"><?= htmlspecialchars($subj['subject_name']) ?></span>
                                            <?php endforeach;
                                        else: ?>
                                            <span style="color: #999; font-style: italic;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty(trim($section['adviser_name']))): ?>
                                            <?= htmlspecialchars(trim($section['adviser_name'])) ?>
                                        <?php else: ?>
                                            <span style="color: #999; font-style: italic;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($section['room_number'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($section['school_year']) ?></td>
                                    <td style="text-align: right;">
                                        <?php
                                        // Build section data with subject IDs for the edit modal
                                        $section_data = $section;
                                        $section_data['subject_ids'] = array_map(function ($s) {
                                            return $s['curriculum_id'];
                                        }, $section_subjects_map[$section['id']] ?? []);
                                        ?>
                                        <button class="btn btn-sm btn-primary"
                                            style="background: white; color: var(--primary-color); border: 1px solid var(--border-color);"
                                            onclick='editSection(<?= json_encode($section_data) ?>)'>
                                            ✏️ Edit
                                        </button>
                                        <button class="btn btn-sm btn-primary"
                                            style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;"
                                            onclick='manageSubjectTeachers(<?= json_encode($section_data) ?>, <?= json_encode($subject_teacher_assignments[$section['id']] ?? (object)[]) ?>)'>
                                            👨‍🏫 Teachers
                                        </button>
                                        <form method="POST" style="display: inline-block;"
                                            onsubmit="return confirm('Are you sure you want to delete this section?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="section_id" value="<?= $section['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="sectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Section</h3>
                <button class="close-modal" onclick="closeModal('sectionModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="section_id" id="sectionId">

                <div class="form-group">
                    <label>Grade Level</label>
                    <select name="grade_level" id="gradeLevel" class="form-control" required
                        onchange="filterSubjectsByGrade()">
                        <option value="">Select Grade</option>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                        <option value="Grade 11">Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Section Name</label>
                    <input type="text" name="section_name" id="sectionName" class="form-control" required
                        placeholder="e.g. Rizal">
                </div>

                <div class="form-group">
                    <label>Adviser (Optional)</label>
                    <select name="adviser_id" id="adviserId" class="form-control">
                        <option value="">-- Select Teacher --</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= $teacher['id'] ?>">
                                <?= htmlspecialchars($teacher['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Subjects</label>
                    <div id="subjectsContainer" class="subjects-checkbox-list">
                        <p class="subjects-hint">Select a grade level first to see available subjects.</p>
                        <?php foreach ($all_subjects as $subj): ?>
                            <label class="subject-checkbox-item" data-grade="<?= htmlspecialchars($subj['grade_level']) ?>"
                                style="display: none;">
                                <input type="checkbox" name="subjects[]" value="<?= $subj['id'] ?>">
                                <span class="subject-checkbox-label">
                                    <strong><?= htmlspecialchars($subj['subject_code']) ?></strong> —
                                    <?= htmlspecialchars($subj['subject_name']) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Room Number</label>
                    <input type="text" name="room_number" id="roomNumber" class="form-control"
                        placeholder="e.g. Bldg A - 101">
                </div>

                <div class="form-group">
                    <label>School Year</label>
                    <input type="text" name="school_year" id="schoolYear" class="form-control"
                        value="<?= htmlspecialchars($current_school_year) ?>" 
                        style="background-color: #f3f4f6; color: #6b7280; pointer-events: none;" readonly>
                    <p class="subjects-hint" style="margin-top: 5px;">Linked to the currently active school year in settings.</p>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background: #eee;"
                        onclick="closeModal('sectionModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Section</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Subject Teachers Modal -->
    <div id="subjectTeacherModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Assign Subject Teachers</h3>
                <button class="close-modal" onclick="closeModal('subjectTeacherModal')">&times;</button>
            </div>
            <p id="stModalSub" style="color: #666; margin-bottom: 20px; font-size: 14px;"></p>
            <form method="POST">
                <input type="hidden" name="action" value="assign_subject_teachers">
                <input type="hidden" name="section_id" id="stSectionId">
                <input type="hidden" name="school_year" value="<?= htmlspecialchars($current_school_year) ?>">

                <div id="subjectTeacherList" style="max-height: 400px; overflow-y: auto;">
                    <!-- Dynamically populated -->
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background: #eee;"
                        onclick="closeModal('subjectTeacherModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Assignments</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById('sectionModal').classList.add('active');
            // Reset form for add
            document.getElementById('modalTitle').textContent = 'Add New Section';
            document.getElementById('formAction').value = 'add';
            document.getElementById('sectionId').value = '';
            document.getElementById('gradeLevel').value = '';
            document.getElementById('sectionName').value = '';
            document.getElementById('roomNumber').value = '';
            
            // Mark form state intentionally as an 'add' explicitly for filtering function
            document.getElementById('sectionModal').dataset.mode = 'add';
            
            resetSubjectCheckboxes();
            filterSubjectsByGrade();
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function editSection(data) {
            document.getElementById('sectionModal').classList.add('active');
            document.getElementById('modalTitle').textContent = 'Edit Section';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('sectionId').value = data.id;
            document.getElementById('gradeLevel').value = data.grade_level;
            document.getElementById('sectionName').value = data.section_name;
            document.getElementById('adviserId').value = data.adviser_id || '';
            document.getElementById('schoolYear').value = data.school_year;
            
            document.getElementById('sectionModal').dataset.mode = 'edit';
            // Filter subjects, then pre-check assigned ones
            filterSubjectsByGrade();
            if (data.subject_ids && data.subject_ids.length > 0) {
                var checkboxes = document.querySelectorAll('#subjectsContainer input[type="checkbox"]');
                checkboxes.forEach(function (cb) {
                    cb.checked = data.subject_ids.includes(parseInt(cb.value));
                });
            }
        }

        function filterSubjectsByGrade() {
            var grade = document.getElementById('gradeLevel').value;
            var items = document.querySelectorAll('.subject-checkbox-item');
            var hint = document.querySelector('.subjects-hint');
            var anyVisible = false;
            var isAddMode = document.getElementById('sectionModal').dataset.mode === 'add';

            items.forEach(function (item) {
                if (grade && item.getAttribute('data-grade') === grade) {
                    item.style.display = 'flex';
                    anyVisible = true;
                    if (isAddMode) {
                        item.querySelector('input[type="checkbox"]').checked = true;
                    }
                } else {
                    item.style.display = 'none';
                    // Uncheck hidden subjects
                    item.querySelector('input[type="checkbox"]').checked = false;
                }
            });

            if (hint) {
                hint.style.display = (grade && anyVisible) ? 'none' : 'block';
                if (grade && !anyVisible) {
                    hint.textContent = 'No subjects found for ' + grade + '. Add subjects in Curriculum first.';
                } else if (!grade) {
                    hint.textContent = 'Select a grade level first to see available subjects.';
                }
            }
        }

        function resetSubjectCheckboxes() {
            var checkboxes = document.querySelectorAll('#subjectsContainer input[type="checkbox"]');
            checkboxes.forEach(function (cb) { cb.checked = false; });
        }

        function manageSubjectTeachers(sectionData, assignments) {
            const modal = document.getElementById('subjectTeacherModal');
            const subTitle = document.getElementById('stModalSub');
            const sectionIdInput = document.getElementById('stSectionId');
            const listContainer = document.getElementById('subjectTeacherList');
            
            subTitle.textContent = sectionData.grade_level + ' - ' + sectionData.section_name;
            sectionIdInput.value = sectionData.id;
            listContainer.innerHTML = '';
            
            const subjects = <?= json_encode($all_subjects) ?>;
            const sectionSubjectIds = sectionData.subject_ids || [];
            const teachers = <?= json_encode($teachers) ?>;
            
            if (sectionSubjectIds.length === 0) {
                listContainer.innerHTML = '<p style="padding: 20px; text-align: center; color: #999;">No subjects assigned to this section yet. Add subjects in the Section Edit modal first.</p>';
                modal.classList.add('active');
                return;
            }
            
            sectionSubjectIds.forEach(id => {
                const subj = subjects.find(s => parseInt(s.id) === parseInt(id));
                if (!subj) return;
                
                const div = document.createElement('div');
                div.className = 'form-group';
                div.style.padding = '10px';
                div.style.background = '#f9fafb';
                div.style.borderRadius = '8px';
                div.style.border = '1px solid #eee';
                
                const label = document.createElement('label');
                label.innerHTML = `<strong>${subj.subject_code}</strong> - ${subj.subject_name}`;
                div.appendChild(label);
                
                const select = document.createElement('select');
                select.name = `subject_teachers[${subj.id}]`;
                select.className = 'form-control';
                
                const optNone = document.createElement('option');
                optNone.value = '';
                optNone.textContent = '-- Select Teacher --';
                select.appendChild(optNone);
                
                teachers.forEach(t => {
                    const opt = document.createElement('option');
                    // Use id directly since it's users.id
                    opt.value = t.id; 
                    opt.textContent = t.full_name;
                    
                    if (assignments[subj.id] && parseInt(assignments[subj.id]) === parseInt(t.id)) {
                        opt.selected = true;
                    }
                    select.appendChild(opt);
                });
                
                div.appendChild(select);
                listContainer.appendChild(div);
            });
            
            modal.classList.add('active');
        }

        // Close on click outside
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>

</html>