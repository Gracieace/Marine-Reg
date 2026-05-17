<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once __DIR__ . '/../config/db.php';

$pdo = db_connect();

// Ensure tables exist
try {
    initialize_schema($pdo);
} catch (Exception $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// Fetch Current School Year
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'");
$stmt->execute();
$current_school_year = $stmt->fetchColumn() ?: '2024-2025';

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

        $school_year = $_POST['school_year'] ?? $current_school_year; // Default or from settings

        if ($grade_level && $section_name) {
            try {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO sections (grade_level, section_name, adviser_id, room_number, school_year) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$grade_level, $section_name, $adviser_id, $room_number, $school_year]);
                    $section_id = $pdo->lastInsertId();
                    $success_message = "Section added successfully!";
                } else {
                    $section_id = $_POST['section_id'] ?? 0;
                    $stmt = $pdo->prepare("UPDATE sections SET grade_level = ?, section_name = ?, adviser_id = ?, room_number = ?, school_year = ? WHERE id = ?");
                    $stmt->execute([$grade_level, $section_name, $adviser_id, $room_number, $school_year, $section_id]);
                    $success_message = "Section updated successfully!";
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
            } catch (PDOException $e) {
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
            $stmt = $pdo->prepare("DELETE FROM sections WHERE id = ?");
            $stmt->execute([$id]);
            $success_message = "Section deleted successfully!";
        } catch (PDOException $e) {
            $error_message = "Error deleting section: " . $e->getMessage();
        }
    }
}

// Fetch Sections
$stmt = $pdo->query("SELECT s.*, t.first_name, t.last_name 
                     FROM sections s 
                     LEFT JOIN teachers t ON s.adviser_id = t.id 
                     ORDER BY s.grade_level, s.section_name");
$sections = $stmt->fetchAll();

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

// Fetch Teachers for Dropdown
$stmt = $pdo->query("SELECT id, first_name, last_name FROM teachers ORDER BY last_name, first_name");
$teachers = $stmt->fetchAll();

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
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
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
            padding: 20px;
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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
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
    <?php require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="content main-content">
        <div class="page-header">
            <div>
                <h1>Section Management</h1>
                <p style="color: #666; margin-top: 5px;">Manage sections, advisers, and room assignments</p>
            </div>
            <button class="btn btn-primary" onclick="openModal('addModal')">
                ➕ Add New Section
            </button>
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
                                        <?php if ($section['first_name']): ?>
                                            <?= htmlspecialchars($section['first_name'] . ' ' . $section['last_name']) ?>
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
                                <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>
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
                        value="<?= htmlspecialchars($current_school_year) ?>" style="background-color: #f3f4f6; color: #6b7280; pointer-events: none;" readonly>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" style="background: #eee;"
                        onclick="closeModal('sectionModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Section</button>
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

        // Close on click outside
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>

</html>
