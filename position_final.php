<?php 
require_once __DIR__ . '/auth/auth.php'; 
require_once __DIR__ . '/config/db.php';
auth_require_role('admin'); 

$pdo = db_connect();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'upload_esignature':
                    $owner_raw = $_POST['signature_owner'];
                    $position_type = $_POST['position_type'];
                    
                    $user_id = null;
                    $employee_id = null;
                    
                    if (strpos($owner_raw, 'user:') === 0) {
                        $user_id = substr($owner_raw, 5);
                    } else {
                        $employee_id = substr($owner_raw, 4);
                    }
                    
                    require_once __DIR__ . '/config/esignature_utils.php';
                    
                    // Validate owner
                    if ($user_id) {
                        $stmt = $pdo->prepare('SELECT id, CONCAT(COALESCE(first_name,""), " ", COALESCE(last_name,"")) as full_name, username FROM users WHERE id = ?');
                        $stmt->execute([$user_id]);
                        $owner = $stmt->fetch();
                        $displayName = trim($owner['full_name']) ?: $owner['username'];
                    } else {
                        $stmt = $pdo->prepare('SELECT id, full_name FROM employees WHERE id = ? AND is_active = 1');
                        $stmt->execute([$employee_id]);
                        $owner = $stmt->fetch();
                        $displayName = $owner['full_name'];
                    }
                    
                    if (!$owner) {
                        $error = 'Selected person not found.';
                        break;
                    }
                    
                    if (isset($_FILES['esignature_file']) && $_FILES['esignature_file']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/assets/esignatures/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                        
                        $fileExtension = strtolower(pathinfo($_FILES['esignature_file']['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                        
                        if (!in_array($fileExtension, $allowedExtensions)) {
                            $error = 'Invalid file type. Please upload PNG, JPG, JPEG, GIF, or SVG.';
                            break;
                        }
                        
                        $prefix = $user_id ? 'user_' . $user_id : 'emp_' . $employee_id;
                        $fileName = 'esignature_' . $prefix . '_' . time() . '.' . $fileExtension;
                        $filePath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($_FILES['esignature_file']['tmp_name'], $filePath)) {
                            $relativePath = 'assets/esignatures/' . $fileName;
                            
                            // Check existing
                            if ($user_id) {
                                $stmt = $pdo->prepare('SELECT id FROM employee_esignatures WHERE user_id = ?');
                                $stmt->execute([$user_id]);
                            } else {
                                $stmt = $pdo->prepare('SELECT id FROM employee_esignatures WHERE employee_id = ?');
                                $stmt->execute([$employee_id]);
                            }
                            $existing = $stmt->fetch();
                            
                            if ($existing) {
                                if ($user_id) {
                                    $stmt = $pdo->prepare('UPDATE employee_esignatures SET file_path = ?, position_type = ?, updated_at = NOW() WHERE user_id = ?');
                                    $stmt->execute([$relativePath, $position_type, $user_id]);
                                } else {
                                    $stmt = $pdo->prepare('UPDATE employee_esignatures SET file_path = ?, position_type = ?, updated_at = NOW() WHERE employee_id = ?');
                                    $stmt->execute([$relativePath, $position_type, $employee_id]);
                                }
                            } else {
                                $stmt = $pdo->prepare('INSERT INTO employee_esignatures (user_id, employee_id, file_path, position_type, created_at) VALUES (?, ?, ?, ?, NOW())');
                                $stmt->execute([$user_id, $employee_id, $relativePath, $position_type]);
                            }
                            
                            $type = $user_id ? 'user' : 'employee';
                            $id = $user_id ?: $employee_id;
                            cleanupOldQuickAccessSignature($type, $id);
                            $copyResult = copyEsignatureToQuickAccess($filePath, $type, $id);
                            
                            $message = 'E-signature uploaded successfully for ' . $displayName;
                        } else {
                            $error = 'Failed to upload file.';
                        }
                    } else {
                        $error = 'Please select a valid file.';
                    }
                    break;
                    
                case 'set_principal':
                    $principal_id = $_POST['principal_id'];
                    $school_year = $_POST['school_year'];
                    
                    $stmt = $pdo->prepare('SELECT id, full_name FROM employees WHERE id = ? AND is_active = 1');
                    $stmt->execute([$principal_id]);
                    $employee = $stmt->fetch();
                    
                    if (!$employee) {
                        $error = 'Selected employee not found.';
                        break;
                    }
                    
                    $stmt = $pdo->prepare('DELETE FROM position_assignments WHERE position_type = "principal" AND school_year = ?');
                    $stmt->execute([$school_year]);
                    
                    $stmt = $pdo->prepare('INSERT INTO position_assignments (employee_id, position_type, school_year, created_at) VALUES (?, "principal", ?, NOW())');
                    $stmt->execute([$principal_id, $school_year]);
                    
                    $message = 'Principal set successfully for school year ' . $school_year;
                    break;
                    
                case 'set_adviser':
                    $adviser_id = $_POST['adviser_id'];
                    $grade_level = $_POST['grade_level'];
                    $section = $_POST['section'];
                    $school_year = $_POST['school_year'];
                    
                    $stmt = $pdo->prepare('DELETE FROM position_assignments WHERE position_type = "class_adviser" AND grade_level = ? AND section = ? AND school_year = ?');
                    $stmt->execute([$grade_level, $section, $school_year]);
                    
                    // Check if teacher user has employee record
                    $stmt = $pdo->prepare('SELECT e.id as employee_id FROM employees e JOIN users u ON u.first_name = SUBSTRING_INDEX(e.full_name, " ", 1) AND u.last_name = SUBSTRING_INDEX(e.full_name, " ", -1) WHERE u.id = ?');
                    $stmt->execute([$adviser_id]);
                    $employee = $stmt->fetch();
                    
                    if ($employee) {
                        $stmt = $pdo->prepare('INSERT INTO position_assignments (employee_id, position_type, grade_level, section, school_year, created_at) VALUES (?, "class_adviser", ?, ?, ?, NOW())');
                        $stmt->execute([$employee['employee_id'], $grade_level, $section, $school_year]);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO position_assignments (user_id, position_type, grade_level, section, school_year, created_at) VALUES (?, "class_adviser", ?, ?, ?, NOW())');
                        $stmt->execute([$adviser_id, $grade_level, $section, $school_year]);
                    }
                    
                    $message = 'Class adviser set for ' . $grade_level . ' - ' . $section;
                    break;
                    
                case 'remove_assignment':
                    $assignment_id = $_POST['assignment_id'];
                    $stmt = $pdo->prepare('DELETE FROM position_assignments WHERE id = ?');
                    $stmt->execute([$assignment_id]);
                    $message = 'Assignment removed successfully';
                    break;
                    
                case 'delete_esignature':
                    $esignature_id = $_POST['esignature_id'];
                    $stmt = $pdo->prepare('SELECT * FROM employee_esignatures WHERE id = ?');
                    $stmt->execute([$esignature_id]);
                    $esignature = $stmt->fetch();
                    
                    if ($esignature) {
                        $filePath = __DIR__ . '/' . $esignature['file_path'];
                        if (file_exists($filePath)) unlink($filePath);
                        
                        require_once __DIR__ . '/config/esignature_utils.php';
                        $type = $esignature['user_id'] ? 'user' : 'employee';
                        $id = $esignature['user_id'] ?: $esignature['employee_id'];
                        cleanupOldQuickAccessSignature($type, $id);
                        
                        $stmt = $pdo->prepare('DELETE FROM employee_esignatures WHERE id = ?');
                        $stmt->execute([$esignature_id]);
                        $message = 'E-signature deleted successfully';
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Data fetching
$current_sy = date('Y') . '-' . (date('Y') + 1);

// Teachers
$stmt = $pdo->query('
    SELECT u.id, u.username, u.first_name, u.last_name 
    FROM users u WHERE u.role = "teacher" 
    ORDER BY u.last_name, u.first_name
');
$teachers = $stmt->fetchAll();

// Employees
$stmt = $pdo->query('SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name');
$employees = $stmt->fetchAll();

// All Users
$stmt = $pdo->query('SELECT id, username, first_name, last_name, role FROM users ORDER BY last_name, first_name');
$users_list = $stmt->fetchAll();

// Assignments
$stmt = $pdo->query('
    SELECT pa.*, 
           COALESCE(e.full_name, CONCAT(u.first_name, " ", u.last_name), u.username) as full_name,
           e.position_title
    FROM position_assignments pa 
    LEFT JOIN employees e ON pa.employee_id = e.id
    LEFT JOIN users u ON pa.user_id = u.id
    ORDER BY pa.position_type, pa.grade_level, pa.section
');
$assignments = $stmt->fetchAll();

// Sections
$stmt = $pdo->query('SELECT DISTINCT grade_level, section FROM enrollments ORDER BY grade_level, section');
$sections = $stmt->fetchAll();

// E-Signatures
$stmt = $pdo->query('
    SELECT es.*, 
           COALESCE(e.full_name, CONCAT(u.first_name, " ", u.last_name), u.username) as full_name,
           e.position_title
    FROM employee_esignatures es
    LEFT JOIN employees e ON es.employee_id = e.id
    LEFT JOIN users u ON es.user_id = u.id
    ORDER BY es.created_at DESC
');
$esignatures = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Position Management</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-700: #334155;
            --gray-900: #0f172a;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-700);
            margin: 0;
            padding: 0;
        }

        .main-content {
            padding: calc(var(--header-height) + 40px) 32px 32px;
            max-width: 1400px;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0 0 8px 0;
        }

        .page-header p {
            color: #64748b;
            margin: 0;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--gray-200);
            padding: 24px;
            height: fit-content;
        }

        .card-header {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--gray-100);
            padding-bottom: 12px;
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0 0 4px 0;
        }

        .card-header p {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="number"],
        select,
        input[type="file"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover { background-color: var(--primary-dark); }

        .btn-danger {
            background-color: #fee2e2;
            color: var(--danger);
        }
        .btn-danger:hover { background-color: #fca5a5; }

        .btn-outline {
            background: white;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }
        .btn-outline:hover { background: var(--gray-50); }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th {
            background: var(--gray-50);
            text-align: left;
            padding: 12px 16px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid var(--gray-200);
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-100);
            color: var(--gray-900);
        }

        tr:last-child td { border-bottom: none; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-principal { background: #e0e7ff; color: #4338ca; }
        .badge-adviser { background: #f0fdf4; color: #166534; }
        .badge-staff { background: #f3f4f6; color: #4b5563; }

        .file-preview {
            width: 80px;
            height: 40px;
            object-fit: contain;
            border: 1px solid var(--gray-200);
            border-radius: 4px;
            background: #fff;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/admin/admin_header.php'; ?>
    <?php require_once __DIR__ . '/admin/admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1>Position Management</h1>
            <p>Manage principals, class advisers, and e-signatures.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="grid">
            <!-- Set Principal -->
            <div class="card">
                <div class="card-header">
                    <h2>Set School Principal</h2>
                    <p>Assign for the current school year</p>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="set_principal">
                    <div class="form-group">
                        <label>Select Principal</label>
                        <select name="principal_id" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>School Year</label>
                        <input type="text" name="school_year" value="<?= $current_sy ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Assignment</button>
                </form>
            </div>

            <!-- Set Class Adviser -->
            <div class="card">
                <div class="card-header">
                    <h2>Set Class Adviser</h2>
                    <p>Assign a teacher to a section</p>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="set_adviser">
                    <div class="form-group">
                        <label>Select Teacher</label>
                        <select name="adviser_id" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>">
                                    <?= htmlspecialchars($teacher['last_name'] . ', ' . $teacher['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label>Grade Level</label>
                            <select name="grade_level" id="grade_level" required onchange="filterSections()">
                                <option value="">Level</option>
                                <?php 
                                $grades = array_unique(array_column($sections, 'grade_level'));
                                sort($grades);
                                foreach ($grades as $g): ?>
                                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Section</label>
                            <select name="section" id="section_select" required>
                                <option value="">Section</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>School Year</label>
                        <input type="text" name="school_year" value="<?= $current_sy ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Assignment</button>
                </form>
            </div>
        </div>

        <!-- E-Signatures -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2>Upload E-Signature</h2>
                <p>Digital signatures for official documents</p>
            </div>
            <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                <input type="hidden" name="action" value="upload_esignature">
                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                    <label>E-Signature Owner</label>
                    <select name="signature_owner" required>
                        <option value="">-- Select Person --</option>
                        <optgroup label="Registered Users">
                            <?php foreach ($users_list as $u): 
                                $displayName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username'];
                            ?>
                                <option value="user:<?= $u['id'] ?>"><?= htmlspecialchars($displayName) ?> (<?= htmlspecialchars(ucfirst($u['role'])) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Employees">
                            <?php foreach ($employees as $emp): ?>
                                <option value="emp:<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                    <label>Role Category</label>
                    <select name="position_type" required>
                        <option value="principal">Principal</option>
                        <option value="class_adviser">Class Adviser</option>
                        <option value="teacher">Teacher</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                    <label>Signature File (Image)</label>
                    <input type="file" name="esignature_file" accept="image/*" required>
                </div>
                <button type="submit" class="btn btn-primary" style="height: 42px;">Upload</button>
            </form>

            <?php if (!empty($esignatures)): ?>
                <h3 style="margin-top: 24px; font-size: 16px; color: var(--gray-900);">Existing Signatures</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Preview</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($esignatures as $sig): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sig['full_name']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= strpos($sig['position_type'], 'principal') !== false ? 'principal' : 'staff' ?>">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sig['position_type']))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (file_exists(__DIR__ . '/' . $sig['file_path'])): ?>
                                            <img src="<?= url_for('/' . $sig['file_path']) ?>" alt="Sig" class="file-preview">
                                        <?php else: ?>
                                            <span style="color: #ef4444; font-size: 12px;">File missing</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Delete this signature?');" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_esignature">
                                            <input type="hidden" name="esignature_id" value="<?= $sig['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Current Assignments List -->
        <div class="card">
            <div class="card-header">
                <h2>Current Assignments</h2>
                <p>Principals and Class Advisers</p>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Assigned To</th>
                            <th>Details</th>
                            <th>School Year</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assign): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-<?= strpos($assign['position_type'], 'principal') !== false ? 'principal' : 'adviser' ?>">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $assign['position_type']))) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($assign['full_name']) ?></td>
                                <td>
                                    <?php if ($assign['position_type'] === 'class_adviser'): ?>
                                        <?= htmlspecialchars($assign['grade_level'] . ' - ' . $assign['section']) ?>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($assign['school_year']) ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Remove this assignment?');" style="display: inline;">
                                        <input type="hidden" name="action" value="remove_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= $assign['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const sections = <?= json_encode($sections) ?>;
        
        function filterSections() {
            const grade = document.getElementById('grade_level').value;
            const sectionSelect = document.getElementById('section_select');
            
            sectionSelect.innerHTML = '<option value="">Section</option>';
            
            if (grade) {
                const filtered = sections.filter(s => s.grade_level === grade);
                filtered.forEach(s => {
                    const option = document.createElement('option');
                    option.value = s.section;
                    option.textContent = s.section;
                    sectionSelect.appendChild(option);
                });
            }
        }
    </script>
</body>
</html>
