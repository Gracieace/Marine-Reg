<?php 
require_once __DIR__ . '/../auth/auth.php'; 
require_once __DIR__ . '/../config/db.php';
auth_require_role(['admin', 'registrar']); 

$pdo = db_connect();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'upload_esignature':
                    $employee_id = $_POST['employee_id'];
                    $position_type = $_POST['position_type'];
                    
                    // Include e-signature utility functions
                    require_once __DIR__ . '/../config/esignature_utils.php';
                    
                    // Validate employee exists
                    $stmt = $pdo->prepare('SELECT id, full_name FROM employees WHERE id = ? AND is_active = 1');
                    $stmt->execute([$employee_id]);
                    $employee = $stmt->fetch();
                    
                    if (!$employee) {
                        $error = 'Selected employee not found or is not active.';
                        break;
                    }
                    
                    // Handle file upload
                    if (isset($_FILES['esignature_file']) && $_FILES['esignature_file']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/../assets/esignatures/';
                        
                        // Create directory if it doesn't exist
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        $fileExtension = strtolower(pathinfo($_FILES['esignature_file']['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                        
                        if (!in_array($fileExtension, $allowedExtensions)) {
                            $error = 'Invalid file type. Please upload PNG, JPG, JPEG, GIF, or SVG files only.';
                            break;
                        }
                        
                        $fileName = 'esignature_' . $employee_id . '_' . time() . '.' . $fileExtension;
                        $filePath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($_FILES['esignature_file']['tmp_name'], $filePath)) {
                            // Store file path in database
                            $relativePath = 'assets/esignatures/' . $fileName;
                            
                            // Check if e-signature already exists for this employee
                            $stmt = $pdo->prepare('SELECT id FROM employee_esignatures WHERE employee_id = ?');
                            $stmt->execute([$employee_id]);
                            $existing = $stmt->fetch();
                            
                            if ($existing) {
                                // Update existing record
                                $stmt = $pdo->prepare('UPDATE employee_esignatures SET file_path = ?, position_type = ?, updated_at = NOW() WHERE employee_id = ?');
                                $stmt->execute([$relativePath, $position_type, $employee_id]);
                            } else {
                                // Insert new record
                                $stmt = $pdo->prepare('INSERT INTO employee_esignatures (employee_id, file_path, position_type, created_at) VALUES (?, ?, ?, NOW())');
                                $stmt->execute([$employee_id, $relativePath, $position_type]);
                            }
                            
                            // Copy to quick access folder
                            cleanupOldQuickAccessSignature('employee', $employee_id);
                            $copyResult = copyEsignatureToQuickAccess($filePath, 'employee', $employee_id);
                            
                            if ($copyResult['success']) {
                                $message = 'E-signature uploaded and copied to quick access successfully for ' . $employee['full_name'];
                            } else {
                                $message = 'E-signature uploaded successfully for ' . $employee['full_name'] . ', but failed to copy to quick access: ' . $copyResult['message'];
                            }
                        } else {
                            $error = 'Failed to upload file. Please try again.';
                        }
                    } else {
                        $error = 'Please select a valid file to upload.';
                    }
                    break;
                    
                case 'set_principal':
                    $principal_id = $_POST['principal_id'];
                    $school_year = $_POST['school_year'];
                    
                    // Validate that the employee exists and is active
                    $stmt = $pdo->prepare('SELECT id, full_name FROM employees WHERE id = ? AND is_active = 1');
                    $stmt->execute([$principal_id]);
                    $employee = $stmt->fetch();
                    
                    if (!$employee) {
                        $error = 'Selected employee not found or is not active. Please select a valid employee.';
                        break;
                    }
                    
                    // Remove existing principal for this school year
                    $stmt = $pdo->prepare('DELETE FROM position_assignments WHERE position_type = "principal" AND school_year = ?');
                    $stmt->execute([$school_year]);
                    
                    // Set new principal using employee_id
                    $stmt = $pdo->prepare('INSERT INTO position_assignments (employee_id, position_type, school_year, created_at) VALUES (?, "principal", ?, NOW())');
                    $stmt->execute([$principal_id, $school_year]);
                    
                    $message = 'Principal "' . $employee['full_name'] . '" has been set successfully for school year ' . $school_year;
                    break;
                    
                case 'set_adviser':
                    $adviser_id = $_POST['adviser_id'];
                    $grade_level = $_POST['grade_level'];
                    $section = $_POST['section'];
                    $school_year = $_POST['school_year'];
                    
                    // Remove existing adviser for this section
                    $stmt = $pdo->prepare('DELETE FROM position_assignments WHERE position_type = "class_adviser" AND grade_level = ? AND section = ? AND school_year = ?');
                    $stmt->execute([$grade_level, $section, $school_year]);
                    
                    // Check if this teacher has an employee record
                    $stmt = $pdo->prepare('SELECT e.id as employee_id FROM employees e JOIN users u ON u.first_name = SUBSTRING_INDEX(e.full_name, " ", 1) AND u.last_name = SUBSTRING_INDEX(e.full_name, " ", -1) WHERE u.id = ?');
                    $stmt->execute([$adviser_id]);
                    $employee = $stmt->fetch();
                    
                    if ($employee) {
                        // Set new adviser using employee_id
                        $stmt = $pdo->prepare('INSERT INTO position_assignments (employee_id, position_type, grade_level, section, school_year, created_at) VALUES (?, "class_adviser", ?, ?, ?, NOW())');
                        $stmt->execute([$employee['employee_id'], $grade_level, $section, $school_year]);
                    } else {
                        // Set new adviser using user_id
                        $stmt = $pdo->prepare('INSERT INTO position_assignments (user_id, position_type, grade_level, section, school_year, created_at) VALUES (?, "class_adviser", ?, ?, ?, NOW())');
                        $stmt->execute([$adviser_id, $grade_level, $section, $school_year]);
                    }
                    
                    $message = 'Class adviser has been set successfully for ' . $grade_level . ' - ' . $section;
                    break;
                    
                case 'edit_assignment':
                    $assignment_id = $_POST['assignment_id'];
                    $position_type = $_POST['position_type'];
                    $school_year = $_POST['school_year'];
                    $grade_level = $_POST['grade_level'] ?? null;
                    $section = $_POST['section'] ?? null;
                    
                    // Update the assignment
                    if ($position_type === 'principal') {
                        $stmt = $pdo->prepare('UPDATE position_assignments SET position_type = ?, school_year = ?, grade_level = NULL, section = NULL, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$position_type, $school_year, $assignment_id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE position_assignments SET position_type = ?, school_year = ?, grade_level = ?, section = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$position_type, $school_year, $grade_level, $section, $assignment_id]);
                    }
                    
                    $message = 'Position assignment has been updated successfully';
                    break;
                    
                case 'remove_assignment':
                    $assignment_id = $_POST['assignment_id'];
                    $stmt = $pdo->prepare('DELETE FROM position_assignments WHERE id = ?');
                    $stmt->execute([$assignment_id]);
                    $message = 'Position assignment has been removed successfully';
                    break;
                    
                case 'edit_esignature':
                    $esignature_id = $_POST['esignature_id'];
                    $position_type = $_POST['position_type'];
                    
                    // Include e-signature utility functions
                    require_once __DIR__ . '/../config/esignature_utils.php';
                    
                    // Get existing e-signature data
                    $stmt = $pdo->prepare('SELECT * FROM employee_esignatures WHERE id = ?');
                    $stmt->execute([$esignature_id]);
                    $existing_esignature = $stmt->fetch();
                    
                    if (!$existing_esignature) {
                        $error = 'E-signature not found.';
                        break;
                    }
                    
                    // Handle file upload
                    if (isset($_FILES['esignature_file']) && $_FILES['esignature_file']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/../assets/esignatures/';
                        
                        // Create directory if it doesn't exist
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        $fileExtension = strtolower(pathinfo($_FILES['esignature_file']['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                        
                        if (!in_array($fileExtension, $allowedExtensions)) {
                            $error = 'Invalid file type. Please upload PNG, JPG, JPEG, GIF, or SVG files only.';
                            break;
                        }
                        
                        // Delete old file
                        $oldFilePath = __DIR__ . '/../' . $existing_esignature['file_path'];
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                        }
                        
                        $fileName = 'esignature_' . $existing_esignature['employee_id'] . '_' . time() . '.' . $fileExtension;
                        $filePath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($_FILES['esignature_file']['tmp_name'], $filePath)) {
                            // Update file path in database
                            $relativePath = 'assets/esignatures/' . $fileName;
                            $stmt = $pdo->prepare('UPDATE employee_esignatures SET file_path = ?, position_type = ?, updated_at = NOW() WHERE id = ?');
                            $stmt->execute([$relativePath, $position_type, $esignature_id]);
                            
                            // Copy to quick access folder
                            cleanupOldQuickAccessSignature('employee', $existing_esignature['employee_id']);
                            $copyResult = copyEsignatureToQuickAccess($filePath, 'employee', $existing_esignature['employee_id']);
                            
                            if ($copyResult['success']) {
                                $message = 'E-signature updated and copied to quick access successfully';
                            } else {
                                $message = 'E-signature updated successfully, but failed to copy to quick access: ' . $copyResult['message'];
                            }
                            
                            // Redirect to refresh the page and show updated image
                            header('Location: ' . $_SERVER['PHP_SELF'] . '?updated=1');
                            exit;
                        } else {
                            $error = 'Failed to upload file. Please try again.';
                        }
                    } else {
                        // Just update position type without changing file
                        $stmt = $pdo->prepare('UPDATE employee_esignatures SET position_type = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$position_type, $esignature_id]);
                        $message = 'E-signature position type updated successfully';
                        
                        // Redirect to refresh the page
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?updated=1');
                        exit;
                    }
                    break;
                    
                case 'delete_esignature':
                    $esignature_id = $_POST['esignature_id'];
                    
                    // Get e-signature data
                    $stmt = $pdo->prepare('SELECT * FROM employee_esignatures WHERE id = ?');
                    $stmt->execute([$esignature_id]);
                    $esignature = $stmt->fetch();
                    
                    if ($esignature) {
                        // Delete file from server
                        $filePath = __DIR__ . '/../' . $esignature['file_path'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                        
                        // Delete from quick access folder
                        require_once __DIR__ . '/../config/esignature_utils.php';
                        cleanupOldQuickAccessSignature('employee', $esignature['employee_id']);
                        
                        // Delete from database
                        $stmt = $pdo->prepare('DELETE FROM employee_esignatures WHERE id = ?');
                        $stmt->execute([$esignature_id]);
                        
                        $message = 'E-signature deleted successfully';
                        
                        // Redirect to refresh the page
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1');
                        exit;
                    } else {
                        $error = 'E-signature not found.';
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get current school year
$current_sy = date('Y') . '-' . (date('Y') + 1);

// Get all teachers for dropdowns (from users table and employees table)
$stmt = $pdo->query('
    SELECT u.id, u.username, u.first_name, u.last_name, u.middle_name,
           e.employee_code, e.department, e.position_title, e.email, e.contact_number
    FROM users u
    LEFT JOIN employees e ON u.first_name = SUBSTRING_INDEX(e.full_name, " ", 1) 
                         AND u.last_name = SUBSTRING_INDEX(e.full_name, " ", -1)
    WHERE u.role = "teacher"
    ORDER BY COALESCE(u.last_name, u.username), COALESCE(u.first_name, u.username)
');
$teachers = $stmt->fetchAll();

// Get all employees for principal selection (from employees table with detailed info)
$stmt = $pdo->query('
    SELECT e.id, e.employee_code, e.full_name, e.department, e.position_title, e.email, e.contact_number, e.is_active
    FROM employees e
    WHERE e.is_active = 1
    ORDER BY e.full_name
');
$employees = $stmt->fetchAll();

// Get current position assignments
$stmt = $pdo->query('
    SELECT pa.*, 
           COALESCE(e.full_name, CONCAT(u.first_name, " ", u.last_name)) as full_name,
           e.employee_code, 
           e.department, 
           e.position_title,
           u.username
    FROM position_assignments pa 
    LEFT JOIN employees e ON pa.employee_id = e.id
    LEFT JOIN users u ON pa.user_id = u.id
    ORDER BY pa.position_type, pa.grade_level, pa.section
');
$assignments = $stmt->fetchAll();

// Get unique grade levels and sections from enrollments
$stmt = $pdo->query('SELECT DISTINCT grade_level, section FROM enrollments ORDER BY grade_level, section');
$sections = $stmt->fetchAll();

// Get e-signatures
$stmt = $pdo->query('
    SELECT es.*, e.full_name, e.employee_code, e.position_title, e.department
    FROM employee_esignatures es
    LEFT JOIN employees e ON es.employee_id = e.id
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
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
</head>
<body>
    <?php require_once dirname(__DIR__) . '/header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="content">
        <div class="page-header">
            <h1>Position Management</h1>
            <p>Set principal and class advisers for each section</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✓</span>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✓</span>
                E-signature updated successfully! The page has been refreshed to show the latest changes.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✓</span>
                E-signature deleted successfully! The page has been refreshed to show the latest changes.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <span class="alert-icon">⚠</span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Set Principal Section -->
        <div class="card">
            <div class="card-header">
                <h2>Set School Principal</h2>
                <p>Assign a principal for the current school year</p>
            </div>
            <div class="card-body">
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="set_principal">
                    
                    <div class="form-group">
                        <label for="principal-search">Select Principal:</label>
                        <?php if (empty($employees)): ?>
                            <div class="alert alert-warning" style="background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                                <strong>No employees found!</strong> Please add employees first before assigning a principal. 
                                <a href="employees.php" style="color: #92400e; text-decoration: underline;">Go to Employee Management</a>
                            </div>
                        <?php else: ?>
                            <div class="search-container">
                                <div class="search-input-group">
                                    <input type="text" id="principal-search" name="principal_search" placeholder="Type employee name to search..." class="search-input" autocomplete="off">
                                    <button type="button" id="search-btn" class="search-btn">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="11" cy="11" r="8"/>
                                            <path d="M21 21l-4.35-4.35"/>
                                        </svg>
                                        Search
                                    </button>
                                </div>
                                <input type="hidden" id="principal_id" name="principal_id" required>
                                <div id="principal-results" class="search-results" style="display: none;"></div>
                            </div>
                            <div id="principal-details" class="employee-details" style="display: none; margin-top: 10px; padding: 12px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                <div class="detail-row"><strong>Employee Code:</strong> <span id="principal-code"></span></div>
                                <div class="detail-row"><strong>Department:</strong> <span id="principal-department"></span></div>
                                <div class="detail-row"><strong>Position:</strong> <span id="principal-position"></span></div>
                                <div class="detail-row"><strong>Email:</strong> <span id="principal-email"></span></div>
                                <div class="detail-row"><strong>Contact:</strong> <span id="principal-contact"></span></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="school_year">School Year:</label>
                        <input type="text" name="school_year" id="school_year" value="<?php echo $current_sy; ?>" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Set Principal</button>
                </form>
            </div>
        </div>

        <!-- Set Class Adviser Section -->
        <div class="card">
            <div class="card-header">
                <h2>Set Class Adviser</h2>
                <p>Assign a class adviser for a specific section</p>
            </div>
            <div class="card-body">
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="set_adviser">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="adviser_id">Select Teacher:</label>
                            <div class="employee-search-container">
                                <input type="text" id="teacher-search" placeholder="Search teachers..." class="employee-search-input">
                                <select name="adviser_id" id="adviser_id" required>
                                    <option value="">Choose a teacher...</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>"
                                                data-employee-code="<?php echo htmlspecialchars($teacher['employee_code'] ?? 'N/A'); ?>"
                                                data-department="<?php echo htmlspecialchars($teacher['department'] ?? 'N/A'); ?>"
                                                data-position="<?php echo htmlspecialchars($teacher['position_title'] ?? 'N/A'); ?>"
                                                data-email="<?php echo htmlspecialchars($teacher['email'] ?? 'N/A'); ?>"
                                                data-contact="<?php echo htmlspecialchars($teacher['contact_number'] ?? 'N/A'); ?>"
                                                data-search-text="<?php 
                                                    $display_name = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
                                                    if (empty($display_name)) {
                                                        $display_name = $teacher['username'];
                                                    }
                                                    echo htmlspecialchars(strtolower($display_name . ' ' . $teacher['username'] . ' ' . ($teacher['employee_code'] ?? '') . ' ' . ($teacher['position_title'] ?? '') . ' ' . ($teacher['department'] ?? '')));
                                                ?>">
                                            <?php 
                                            $display_name = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
                                            if (empty($display_name)) {
                                                $display_name = $teacher['username'];
                                            }
                                            echo htmlspecialchars($display_name);
                                            ?>
                                            (<?php echo htmlspecialchars($teacher['username']); ?>)
                                            <?php if ($teacher['employee_code']): ?>
                                                - <?php echo htmlspecialchars($teacher['employee_code']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="teacher-details" class="employee-details" style="display: none; margin-top: 10px; padding: 12px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #10b981;">
                                <div class="detail-row"><strong>Employee Code:</strong> <span id="teacher-code"></span></div>
                                <div class="detail-row"><strong>Department:</strong> <span id="teacher-department"></span></div>
                                <div class="detail-row"><strong>Position:</strong> <span id="teacher-position"></span></div>
                                <div class="detail-row"><strong>Email:</strong> <span id="teacher-email"></span></div>
                                <div class="detail-row"><strong>Contact:</strong> <span id="teacher-contact"></span></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="grade_level">Grade Level:</label>
                            <select name="grade_level" id="grade_level" required>
                                <option value="">Select grade level...</option>
                                <?php 
                                $grade_levels = array_unique(array_column($sections, 'grade_level'));
                                sort($grade_levels);
                                foreach ($grade_levels as $grade): ?>
                                    <option value="<?php echo htmlspecialchars($grade); ?>"><?php echo htmlspecialchars($grade); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="section">Section:</label>
                            <select name="section" id="section" required>
                                <option value="">Select section...</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="school_year_adviser">School Year:</label>
                            <input type="text" name="school_year" id="school_year_adviser" value="<?php echo $current_sy; ?>" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Set Class Adviser</button>
                </form>
            </div>
        </div>

        <!-- E-Signature Upload Section -->
        <div class="card">
            <div class="card-header">
                <h2>Upload E-Signature</h2>
                <p>Upload digital signatures for employees</p>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="form">
                    <input type="hidden" name="action" value="upload_esignature">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="esignature_employee_search">Select Employee:</label>
                            <?php if (empty($employees)): ?>
                                <div class="alert alert-warning" style="background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                                    <strong>No employees found!</strong> Please add employees first before uploading e-signatures. 
                                    <a href="employees.php" style="color: #92400e; text-decoration: underline;">Go to Employee Management</a>
                                </div>
                            <?php else: ?>
                                <div class="search-container">
                                    <div class="search-input-group">
                                        <input type="text" id="esignature_employee_search" name="esignature_employee_search" placeholder="Type employee name to search..." class="search-input" autocomplete="off">
                                        <button type="button" id="esignature_search_btn" class="search-btn">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="11" cy="11" r="8"/>
                                                <path d="M21 21l-4.35-4.35"/>
                                            </svg>
                                            Search
                                        </button>
                                    </div>
                                    <input type="hidden" id="esignature_employee_id" name="employee_id" required>
                                    <div id="esignature_employee_results" class="search-results" style="display: none;"></div>
                                </div>
                                <div id="esignature_employee_details" class="employee-details" style="display: none; margin-top: 10px; padding: 12px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #8b5cf6;">
                                    <div class="detail-row"><strong>Employee Code:</strong> <span id="esignature_employee_code"></span></div>
                                    <div class="detail-row"><strong>Department:</strong> <span id="esignature_employee_department"></span></div>
                                    <div class="detail-row"><strong>Position:</strong> <span id="esignature_employee_position"></span></div>
                                    <div class="detail-row"><strong>Email:</strong> <span id="esignature_employee_email"></span></div>
                                    <div class="detail-row"><strong>Contact:</strong> <span id="esignature_employee_contact"></span></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="position_type">Position Type:</label>
                            <select name="position_type" id="position_type" required>
                                <option value="">Select position type...</option>
                                <option value="principal">Principal</option>
                                <option value="class_adviser">Class Adviser</option>
                                <option value="teacher">Teacher</option>
                                <option value="staff">Staff</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="esignature_file">E-Signature File:</label>
                        <div class="file-upload-container">
                            <input type="file" name="esignature_file" id="esignature_file" accept="image/*" required>
                            <div class="file-upload-preview" id="esignature_preview" style="display: none;">
                                <img id="esignature_preview_img" src="" alt="E-signature preview" style="max-width: 200px; max-height: 100px; border: 1px solid #d1d5db; border-radius: 4px;">
                                <div class="file-info">
                                    <div id="esignature_file_name"></div>
                                    <div id="esignature_file_size"></div>
                                </div>
                            </div>
                        </div>
                        <small class="file-help-text">Supported formats: PNG, JPG, JPEG, GIF, SVG (Max size: 2MB)</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Upload E-Signature</button>
                </form>
            </div>
        </div>

        <!-- Current E-Signatures -->
        <div class="card">
            <div class="card-header">
                <h2>Current E-Signatures</h2>
                <p>View and manage uploaded e-signatures</p>
            </div>
            <div class="card-body">
                <?php if (empty($esignatures)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">✍️</div>
                        <h3>No E-Signatures</h3>
                        <p>No e-signatures have been uploaded yet.</p>
                    </div>
                <?php else: ?>
                    <div class="esignatures-grid">
                        <?php foreach ($esignatures as $esignature): ?>
                            <div class="esignature-item">
                                <div class="esignature-info">
                                    <div class="esignature-name">
                                        <?php echo htmlspecialchars($esignature['full_name'] ?? 'Unknown Employee'); ?>
                                        <?php if ($esignature['employee_code']): ?>
                                            <span class="esignature-code">(<?php echo htmlspecialchars($esignature['employee_code']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="esignature-details">
                                        <strong>Position:</strong> <?php echo htmlspecialchars($esignature['position_type'] ?? 'N/A'); ?>
                                        <?php if ($esignature['position_title']): ?>
                                            <br><strong>Title:</strong> <?php echo htmlspecialchars($esignature['position_title']); ?>
                                        <?php endif; ?>
                                        <br><strong>Department:</strong> <?php echo htmlspecialchars($esignature['department'] ?? 'N/A'); ?>
                                        <span class="esignature-date">
                                            Uploaded: <?php echo date('M j, Y g:i A', strtotime($esignature['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="esignature-preview">
                                    <?php 
                                    $imagePath = $esignature['file_path'];
                                    $fullPath = __DIR__ . '/../' . $imagePath;
                                    
                                    // Ensure the path is web-accessible with correct base path
                                    if (!str_starts_with($imagePath, '/') && !str_starts_with($imagePath, 'http')) {
                                        $imagePath = url_for('/' . ltrim($imagePath, '/'));
                                    }
                                    
                                    if (file_exists($fullPath)): 
                                        // Add cache busting parameter to prevent browser caching
                                        $cacheBuster = '?v=' . filemtime($fullPath);
                                        $imageUrl = $imagePath . $cacheBuster;
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="E-signature" class="esignature-image">
                                    <?php else: ?>
                                        <div class="esignature-missing">File not found</div>
                                    <?php endif; ?>
                                </div>
                                <div class="esignature-actions">
                                    <button type="button" class="btn btn-warning btn-sm" onclick="editEsignature(<?php echo $esignature['id']; ?>, '<?php echo htmlspecialchars($esignature['position_type']); ?>', '<?php echo htmlspecialchars($esignature['full_name']); ?>')">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                        </svg>
                                        Edit
                                    </button>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this e-signature?')">
                                        <input type="hidden" name="action" value="delete_esignature">
                                        <input type="hidden" name="esignature_id" value="<?php echo $esignature['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="3,6 5,6 21,6"/>
                                                <path d="M19,6v14a2,2 0 0,1 -2,2H7a2,2 0 0,1 -2,-2V6m3,0V4a2,2 0 0,1 2,-2h4a2,2 0 0,1 2,2v2"/>
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Current Assignments Table -->
        <div class="card">
            <div class="card-header">
                <h2>Current Position Assignments</h2>
                <p>View, edit, and manage current position assignments</p>
            </div>
            <div class="card-body">
                <?php if (empty($assignments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">👥</div>
                        <h3>No Position Assignments</h3>
                        <p>No principals or class advisers have been assigned yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="assignments-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Position Type</th>
                                    <th>Grade/Section</th>
                                    <th>School Year</th>
                                    <th>Employee Code</th>
                                    <th>Department</th>
                                    <th>Assigned Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?php echo $assignment['id']; ?></td>
                                        <td>
                                            <div class="assignment-name">
                                                <?php 
                                                $display_name = $assignment['full_name'] ?? '';
                                                if (empty($display_name)) {
                                                    $display_name = $assignment['username'] ?? 'Unknown';
                                                }
                                                echo htmlspecialchars($display_name);
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="position-badge position-<?php echo strtolower(str_replace('_', '-', $assignment['position_type'])); ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $assignment['position_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($assignment['grade_level'] && $assignment['section']): ?>
                                                <?php echo htmlspecialchars($assignment['grade_level'] . ' - ' . $assignment['section']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($assignment['school_year']); ?></td>
                                        <td>
                                            <?php if ($assignment['employee_code']): ?>
                                                <span class="employee-code"><?php echo htmlspecialchars($assignment['employee_code']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($assignment['department']): ?>
                                                <?php echo htmlspecialchars($assignment['department']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="assignment-date">
                                                <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?>
                                                <?php if ($assignment['updated_at'] && $assignment['updated_at'] !== $assignment['created_at']): ?>
                                                    <br><small class="text-muted">Updated: <?php echo date('M j, Y', strtotime($assignment['updated_at'])); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-info btn-sm" onclick="viewAssignment(<?php echo $assignment['id']; ?>)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                                        <circle cx="12" cy="12" r="3"/>
                                                    </svg>
                                                    View
                                                </button>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="editAssignment(<?php echo $assignment['id']; ?>)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                    </svg>
                                                    Edit
                                                </button>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this assignment?')">
                                                    <input type="hidden" name="action" value="remove_assignment">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
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
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Assignment Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Assignment Details</h3>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="assignment-details-grid" id="viewDetails">
                    <!-- Details will be populated by JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Assignment</h3>
                <span class="close" onclick="closeModal('editModal')">&times;</span>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit_assignment">
                <input type="hidden" name="assignment_id" id="edit_assignment_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_position_type">Position Type:</label>
                        <select name="position_type" id="edit_position_type" required>
                            <option value="">Select position type...</option>
                            <option value="principal">Principal</option>
                            <option value="class_adviser">Class Adviser</option>
                            <option value="teacher">Teacher</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_school_year">School Year:</label>
                        <input type="text" name="school_year" id="edit_school_year" required>
                    </div>
                    
                    <div class="form-row" id="edit_grade_section_row" style="display: none;">
                        <div class="form-group">
                            <label for="edit_grade_level">Grade Level:</label>
                            <select name="grade_level" id="edit_grade_level">
                                <option value="">Select grade level...</option>
                                <?php 
                                $grade_levels = array_unique(array_column($sections, 'grade_level'));
                                sort($grade_levels);
                                foreach ($grade_levels as $grade): ?>
                                    <option value="<?php echo htmlspecialchars($grade); ?>"><?php echo htmlspecialchars($grade); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_section">Section:</label>
                            <select name="section" id="edit_section">
                                <option value="">Select section...</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Assignment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit E-Signature Modal -->
    <div id="editEsignatureModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit E-Signature</h3>
                <span class="close" onclick="closeModal('editEsignatureModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editEsignatureForm">
                <input type="hidden" name="action" value="edit_esignature">
                <input type="hidden" name="esignature_id" id="edit_esignature_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_esignature_employee">Employee:</label>
                        <input type="text" id="edit_esignature_employee" readonly class="form-control" style="background: #f8f9fa;">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_esignature_position_type">Position Type:</label>
                        <select name="position_type" id="edit_esignature_position_type" required>
                            <option value="">Select position type...</option>
                            <option value="principal">Principal</option>
                            <option value="class_adviser">Class Adviser</option>
                            <option value="teacher">Teacher</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_esignature_file">New E-Signature File (Optional):</label>
                        <div class="file-upload-container">
                            <input type="file" name="esignature_file" id="edit_esignature_file" accept="image/*">
                            <div class="file-upload-preview" id="edit_esignature_preview" style="display: none;">
                                <img id="edit_esignature_preview_img" src="" alt="E-signature preview" style="max-width: 200px; max-height: 100px; border: 1px solid #d1d5db; border-radius: 4px;">
                                <div class="file-info">
                                    <div id="edit_esignature_file_name"></div>
                                    <div id="edit_esignature_file_size"></div>
                                </div>
                            </div>
                        </div>
                        <small class="file-help-text">Leave empty to keep current file. Supported formats: PNG, JPG, JPEG, GIF, SVG (Max size: 2MB)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editEsignatureModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update E-Signature</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            color: #0f2a44;
            margin-bottom: 8px;
            font-size: 28px;
        }

        .page-header p {
            color: #64748b;
            font-size: 16px;
            margin: 0;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            background: #f8fafc;
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .card-header h2 {
            color: #0f2a44;
            margin: 0 0 8px 0;
            font-size: 20px;
        }

        .card-header p {
            color: #64748b;
            margin: 0;
            font-size: 14px;
        }

        .card-body {
            padding: 24px;
        }

        .form {
            max-width: 600px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .alert-icon {
            font-size: 18px;
        }

        .assignments-grid {
            display: grid;
            gap: 30px;
        }

        .assignment-group h3 {
            color: #0f2a44;
            margin-bottom: 16px;
            font-size: 18px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .assignment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        .assignment-info {
            flex: 1;
        }

        .assignment-name {
            font-weight: 600;
            color: #0f2a44;
            margin-bottom: 4px;
        }

        .assignment-details {
            color: #64748b;
            font-size: 14px;
        }

        .assignment-date {
            color: #94a3b8;
            font-size: 12px;
            margin-left: 12px;
        }

        .assignment-actions {
            margin-left: 16px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            color: #374151;
            margin-bottom: 8px;
        }

        .employee-details {
            font-size: 14px;
            line-height: 1.5;
        }

        .detail-row {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-row strong {
            min-width: 100px;
            color: #374151;
        }

        .detail-row span {
            color: #6b7280;
        }

        .employee-search-container {
            position: relative;
        }

        .employee-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 8px;
            background: #f9fafb;
            transition: all 0.2s ease;
        }

        .employee-search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: white;
        }

        .employee-search-input::placeholder {
            color: #9ca3af;
        }

        .search-container {
            position: relative;
        }

        .search-input-group {
            display: flex;
            gap: 8px;
        }

        .search-input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: white;
        }

        .search-btn {
            padding: 12px 20px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .search-btn:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .search-btn:active {
            transform: translateY(0);
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-input::placeholder {
            color: #9ca3af;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .search-result-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s ease;
        }

        .search-result-item:hover {
            background-color: #f8fafc;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-name {
            font-weight: 600;
            color: #0f2a44;
            margin-bottom: 4px;
        }

        .search-result-details {
            font-size: 12px;
            color: #6b7280;
        }

        .search-result-code {
            color: #3b82f6;
            font-weight: 500;
        }

        .search-result-position {
            color: #059669;
        }

        .no-results {
            padding: 12px 16px;
            color: #6b7280;
            font-style: italic;
            text-align: center;
        }

        /* E-Signature Upload Styles */
        .file-upload-container {
            position: relative;
        }

        .file-upload-container input[type="file"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .file-upload-container input[type="file"]:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .file-upload-container input[type="file"]:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .file-upload-preview {
            margin-top: 12px;
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-info {
            flex: 1;
        }

        .file-info div {
            margin-bottom: 4px;
            font-size: 14px;
        }

        .file-help-text {
            color: #6b7280;
            font-size: 12px;
            margin-top: 4px;
            display: block;
        }

        .esignatures-grid {
            display: grid;
            gap: 20px;
        }

        .esignature-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .esignature-info {
            flex: 1;
        }

        .esignature-name {
            font-weight: 600;
            color: #0f2a44;
            margin-bottom: 8px;
            font-size: 16px;
        }

        .esignature-code {
            color: #6b7280;
            font-size: 14px;
            font-weight: normal;
        }

        .esignature-details {
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }

        .esignature-date {
            color: #94a3b8;
            font-size: 12px;
            margin-left: 12px;
        }

        .esignature-preview {
            margin-left: 16px;
        }

        .esignature-image {
            max-width: 120px;
            max-height: 60px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            object-fit: contain;
        }

        .esignature-missing {
            width: 120px;
            height: 60px;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-size: 12px;
            text-align: center;
        }

        .esignature-actions {
            margin-left: 16px;
            display: flex;
            gap: 6px;
            flex-direction: column;
        }

        .esignature-actions .btn {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .esignature-actions .btn svg {
            flex-shrink: 0;
        }

        /* Assignments Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .assignments-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .assignments-table th {
            background: #f8fafc;
            color: #374151;
            font-weight: 600;
            padding: 16px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
            white-space: nowrap;
        }

        .assignments-table td {
            padding: 16px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }

        .assignments-table tbody tr:hover {
            background: #f8fafc;
        }

        .assignments-table tbody tr:last-child td {
            border-bottom: none;
        }

        .position-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .position-principal {
            background: #dbeafe;
            color: #1e40af;
        }

        .position-class-adviser {
            background: #dcfce7;
            color: #166534;
        }

        .position-teacher {
            background: #fef3c7;
            color: #92400e;
        }

        .position-staff {
            background: #e0e7ff;
            color: #3730a3;
        }

        .employee-code {
            font-family: 'Courier New', monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            color: #475569;
        }

        .text-muted {
            color: #94a3b8;
            font-style: italic;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .action-buttons .btn svg {
            flex-shrink: 0;
        }

        .btn-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .btn-info:hover {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .btn-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .btn-warning:hover {
            background: #fde68a;
            color: #78350f;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            color: #475569;
        }

        .inline-form {
            display: inline;
        }

        /* Modal Styles */
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
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: #0f2a44;
            font-size: 18px;
        }

        .close {
            color: #94a3b8;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .close:hover {
            color: #64748b;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .assignment-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .detail-item {
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }

        .detail-label {
            font-weight: 600;
            color: #374151;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .detail-value {
            color: #0f2a44;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .assignments-table {
                font-size: 12px;
            }
            
            .assignments-table th,
            .assignments-table td {
                padding: 8px 6px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
            
            .action-buttons .btn {
                font-size: 11px;
                padding: 4px 8px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .assignment-details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .search-input-group {
                flex-direction: column;
            }
            
            .search-btn {
                width: 100%;
                justify-content: center;
            }
            
            .assignment-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .assignment-actions {
                margin-left: 0;
                align-self: flex-end;
            }
        }
    </style>

    <script>
        // Update sections based on selected grade level
        document.getElementById('grade_level').addEventListener('change', function() {
            const gradeLevel = this.value;
            const sectionSelect = document.getElementById('section');
            
            // Clear existing options
            sectionSelect.innerHTML = '<option value="">Select section...</option>';
            
            if (gradeLevel) {
                // Get sections for the selected grade level
                const sections = <?php echo json_encode($sections); ?>;
                const gradeSections = sections.filter(section => section.grade_level === gradeLevel);
                
                gradeSections.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.section;
                    option.textContent = section.section;
                    sectionSelect.appendChild(option);
                });
            }
        });

        // Principal search functionality
        const principalSearch = document.getElementById('principal-search');
        const principalResults = document.getElementById('principal-results');
        const principalIdInput = document.getElementById('principal_id');
        const principalDetails = document.getElementById('principal-details');
        const searchBtn = document.getElementById('search-btn');
        
        // Employee data for search
        const employees = <?php echo json_encode($employees); ?>;
        
        let searchTimeout;
        
        function performSearch() {
            const query = principalSearch.value.trim().toLowerCase();
            
            if (query.length < 2) {
                principalResults.style.display = 'none';
                return;
            }
            
            const filteredEmployees = employees.filter(emp => 
                emp.full_name.toLowerCase().includes(query) ||
                (emp.employee_code && emp.employee_code.toLowerCase().includes(query)) ||
                (emp.position_title && emp.position_title.toLowerCase().includes(query)) ||
                (emp.department && emp.department.toLowerCase().includes(query))
            );
            
            displaySearchResults(filteredEmployees, principalResults, selectPrincipal);
        }
        
        principalSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 300);
        });
        
        searchBtn.addEventListener('click', function() {
            performSearch();
        });
        
        principalSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performSearch();
            }
        });
        
        function displaySearchResults(results, container, onSelect) {
            container.innerHTML = '';
            
            if (results.length === 0) {
                container.innerHTML = '<div class="no-results">No employees found</div>';
            } else {
                results.forEach(employee => {
                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.innerHTML = `
                        <div class="search-result-name">${employee.full_name}</div>
                        <div class="search-result-details">
                            ${employee.employee_code ? `<span class="search-result-code">${employee.employee_code}</span>` : ''}
                            ${employee.position_title ? ` • <span class="search-result-position">${employee.position_title}</span>` : ''}
                            ${employee.department ? ` • ${employee.department}` : ''}
                        </div>
                    `;
                    
                    item.addEventListener('click', () => {
                        onSelect(employee);
                        container.style.display = 'none';
                    });
                    
                    container.appendChild(item);
                });
            }
            
            container.style.display = 'block';
        }
        
        function selectPrincipal(employee) {
            principalSearch.value = employee.full_name;
            principalIdInput.value = employee.id;
            
            // Show employee details
            document.getElementById('principal-code').textContent = employee.employee_code || 'N/A';
            document.getElementById('principal-department').textContent = employee.department || 'N/A';
            document.getElementById('principal-position').textContent = employee.position_title || 'N/A';
            document.getElementById('principal-email').textContent = employee.email || 'N/A';
            document.getElementById('principal-contact').textContent = employee.contact_number || 'N/A';
            principalDetails.style.display = 'block';
        }
        
        // Hide results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                principalResults.style.display = 'none';
            }
        });

        // Handle teacher selection and show details
        document.getElementById('adviser_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const detailsDiv = document.getElementById('teacher-details');
            
            if (this.value && selectedOption.dataset.employeeCode !== 'N/A') {
                // Show employee details
                document.getElementById('teacher-code').textContent = selectedOption.dataset.employeeCode;
                document.getElementById('teacher-department').textContent = selectedOption.dataset.department;
                document.getElementById('teacher-position').textContent = selectedOption.dataset.position;
                document.getElementById('teacher-email').textContent = selectedOption.dataset.email;
                document.getElementById('teacher-contact').textContent = selectedOption.dataset.contact;
                detailsDiv.style.display = 'block';
            } else {
                detailsDiv.style.display = 'none';
            }
        });

        // Teacher search functionality (keeping the old dropdown for now)
        function setupEmployeeSearch(searchInputId, selectId) {
            const searchInput = document.getElementById(searchInputId);
            const select = document.getElementById(selectId);
            
            if (!searchInput || !select) return;
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const options = select.getElementsByTagName('option');
                
                for (let i = 1; i < options.length; i++) { // Skip first option (placeholder)
                    const option = options[i];
                    const searchText = option.dataset.searchText || '';
                    
                    if (searchText.includes(searchTerm)) {
                        option.style.display = '';
                    } else {
                        option.style.display = 'none';
                    }
                }
                
                // Reset selection if current selection is hidden
                if (select.value && select.options[select.selectedIndex].style.display === 'none') {
                    select.value = '';
                    // Hide details if they were showing
                    const detailsDiv = document.getElementById(selectId.replace('_id', '-details'));
                    if (detailsDiv) {
                        detailsDiv.style.display = 'none';
                    }
                }
            });
        }

        // Initialize teacher search functionality
        setupEmployeeSearch('teacher-search', 'adviser_id');

        // E-Signature Upload Functionality
        const esignatureFileInput = document.getElementById('esignature_file');
        const esignaturePreview = document.getElementById('esignature_preview');
        const esignaturePreviewImg = document.getElementById('esignature_preview_img');
        const esignatureFileName = document.getElementById('esignature_file_name');
        const esignatureFileSize = document.getElementById('esignature_file_size');

        // File upload preview
        esignatureFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size (2MB max)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size must be less than 2MB');
                    this.value = '';
                    return;
                }

                // Validate file type
                const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/svg+xml'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (PNG, JPG, JPEG, GIF, or SVG)');
                    this.value = '';
                    return;
                }

                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    esignaturePreviewImg.src = e.target.result;
                    esignatureFileName.textContent = file.name;
                    esignatureFileSize.textContent = formatFileSize(file.size);
                    esignaturePreview.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            } else {
                esignaturePreview.style.display = 'none';
            }
        });

        // Format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // E-Signature Employee Search
        const esignatureEmployeeSearch = document.getElementById('esignature_employee_search');
        const esignatureEmployeeResults = document.getElementById('esignature_employee_results');
        const esignatureEmployeeIdInput = document.getElementById('esignature_employee_id');
        const esignatureEmployeeDetails = document.getElementById('esignature_employee_details');
        const esignatureSearchBtn = document.getElementById('esignature_search_btn');
        
        let esignatureSearchTimeout;
        
        function performEsignatureSearch() {
            const query = esignatureEmployeeSearch.value.trim().toLowerCase();
            
            if (query.length < 2) {
                esignatureEmployeeResults.style.display = 'none';
                return;
            }
            
            const filteredEmployees = employees.filter(emp => 
                emp.full_name.toLowerCase().includes(query) ||
                (emp.employee_code && emp.employee_code.toLowerCase().includes(query)) ||
                (emp.position_title && emp.position_title.toLowerCase().includes(query)) ||
                (emp.department && emp.department.toLowerCase().includes(query))
            );
            
            displayEsignatureSearchResults(filteredEmployees, esignatureEmployeeResults, selectEsignatureEmployee);
        }
        
        esignatureEmployeeSearch.addEventListener('input', function() {
            clearTimeout(esignatureSearchTimeout);
            esignatureSearchTimeout = setTimeout(performEsignatureSearch, 300);
        });
        
        esignatureSearchBtn.addEventListener('click', function() {
            performEsignatureSearch();
        });
        
        esignatureEmployeeSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performEsignatureSearch();
            }
        });
        
        function displayEsignatureSearchResults(results, container, onSelect) {
            container.innerHTML = '';
            
            if (results.length === 0) {
                container.innerHTML = '<div class="no-results">No employees found</div>';
            } else {
                results.forEach(employee => {
                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.innerHTML = `
                        <div class="search-result-name">${employee.full_name}</div>
                        <div class="search-result-details">
                            ${employee.employee_code ? `<span class="search-result-code">${employee.employee_code}</span>` : ''}
                            ${employee.position_title ? ` • <span class="search-result-position">${employee.position_title}</span>` : ''}
                            ${employee.department ? ` • ${employee.department}` : ''}
                        </div>
                    `;
                    
                    item.addEventListener('click', () => {
                        onSelect(employee);
                        container.style.display = 'none';
                    });
                    
                    container.appendChild(item);
                });
            }
            
            container.style.display = 'block';
        }
        
        function selectEsignatureEmployee(employee) {
            esignatureEmployeeSearch.value = employee.full_name;
            esignatureEmployeeIdInput.value = employee.id;
            
            // Show employee details
            document.getElementById('esignature_employee_code').textContent = employee.employee_code || 'N/A';
            document.getElementById('esignature_employee_department').textContent = employee.department || 'N/A';
            document.getElementById('esignature_employee_position').textContent = employee.position_title || 'N/A';
            document.getElementById('esignature_employee_email').textContent = employee.email || 'N/A';
            document.getElementById('esignature_employee_contact').textContent = employee.contact_number || 'N/A';
            esignatureEmployeeDetails.style.display = 'block';
        }
        
        // Hide e-signature results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                esignatureEmployeeResults.style.display = 'none';
            }
        });

        // Assignment data for modals
        const assignments = <?php echo json_encode($assignments); ?>;
        const sections = <?php echo json_encode($sections); ?>;

        // View Assignment Function
        function viewAssignment(assignmentId) {
            const assignment = assignments.find(a => a.id == assignmentId);
            if (!assignment) return;

            const detailsContainer = document.getElementById('viewDetails');
            detailsContainer.innerHTML = `
                <div class="detail-item">
                    <div class="detail-label">Assignment ID</div>
                    <div class="detail-value">${assignment.id}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Name</div>
                    <div class="detail-value">${assignment.full_name || assignment.username || 'Unknown'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Position Type</div>
                    <div class="detail-value">
                        <span class="position-badge position-${assignment.position_type.replace('_', '-')}">
                            ${assignment.position_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                        </span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Grade & Section</div>
                    <div class="detail-value">${assignment.grade_level && assignment.section ? 
                        `${assignment.grade_level} - ${assignment.section}` : 'N/A'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">School Year</div>
                    <div class="detail-value">${assignment.school_year}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Employee Code</div>
                    <div class="detail-value">${assignment.employee_code || 'N/A'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Department</div>
                    <div class="detail-value">${assignment.department || 'N/A'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Position Title</div>
                    <div class="detail-value">${assignment.position_title || 'N/A'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Assigned Date</div>
                    <div class="detail-value">${new Date(assignment.created_at).toLocaleDateString('en-US', {
                        year: 'numeric', month: 'short', day: 'numeric'
                    })}</div>
                </div>
                ${assignment.updated_at && assignment.updated_at !== assignment.created_at ? `
                <div class="detail-item">
                    <div class="detail-label">Last Updated</div>
                    <div class="detail-value">${new Date(assignment.updated_at).toLocaleDateString('en-US', {
                        year: 'numeric', month: 'short', day: 'numeric'
                    })}</div>
                </div>
                ` : ''}
            `;

            document.getElementById('viewModal').style.display = 'block';
        }

        // Edit Assignment Function
        function editAssignment(assignmentId) {
            const assignment = assignments.find(a => a.id == assignmentId);
            if (!assignment) return;

            // Populate form fields
            document.getElementById('edit_assignment_id').value = assignment.id;
            document.getElementById('edit_position_type').value = assignment.position_type;
            document.getElementById('edit_school_year').value = assignment.school_year;
            
            // Handle grade level and section
            const gradeSectionRow = document.getElementById('edit_grade_section_row');
            if (assignment.position_type === 'class_adviser') {
                gradeSectionRow.style.display = 'flex';
                document.getElementById('edit_grade_level').value = assignment.grade_level || '';
                updateEditSections(assignment.grade_level);
                document.getElementById('edit_section').value = assignment.section || '';
            } else {
                gradeSectionRow.style.display = 'none';
            }

            document.getElementById('editModal').style.display = 'block';
        }

        // Update sections for edit modal
        function updateEditSections(gradeLevel) {
            const sectionSelect = document.getElementById('edit_section');
            sectionSelect.innerHTML = '<option value="">Select section...</option>';
            
            if (gradeLevel) {
                const gradeSections = sections.filter(section => section.grade_level === gradeLevel);
                gradeSections.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.section;
                    option.textContent = section.section;
                    sectionSelect.appendChild(option);
                });
            }
        }

        // Handle position type change in edit modal
        document.getElementById('edit_position_type').addEventListener('change', function() {
            const gradeSectionRow = document.getElementById('edit_grade_section_row');
            if (this.value === 'class_adviser') {
                gradeSectionRow.style.display = 'flex';
            } else {
                gradeSectionRow.style.display = 'none';
            }
        });

        // Handle grade level change in edit modal
        document.getElementById('edit_grade_level').addEventListener('change', function() {
            updateEditSections(this.value);
        });

        // Close Modal Function
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Edit E-Signature Function
        function editEsignature(esignatureId, positionType, employeeName) {
            document.getElementById('edit_esignature_id').value = esignatureId;
            document.getElementById('edit_esignature_employee').value = employeeName;
            document.getElementById('edit_esignature_position_type').value = positionType;
            
            // Clear file input and preview
            document.getElementById('edit_esignature_file').value = '';
            document.getElementById('edit_esignature_preview').style.display = 'none';
            
            document.getElementById('editEsignatureModal').style.display = 'block';
        }

        // File upload preview for edit modal
        const editEsignatureFileInput = document.getElementById('edit_esignature_file');
        const editEsignaturePreview = document.getElementById('edit_esignature_preview');
        const editEsignaturePreviewImg = document.getElementById('edit_esignature_preview_img');
        const editEsignatureFileName = document.getElementById('edit_esignature_file_name');
        const editEsignatureFileSize = document.getElementById('edit_esignature_file_size');

        editEsignatureFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size (2MB max)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size must be less than 2MB');
                    this.value = '';
                    return;
                }

                // Validate file type
                const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/svg+xml'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (PNG, JPG, JPEG, GIF, or SVG)');
                    this.value = '';
                    return;
                }

                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    editEsignaturePreviewImg.src = e.target.result;
                    editEsignatureFileName.textContent = file.name;
                    editEsignatureFileSize.textContent = formatFileSize(file.size);
                    editEsignaturePreview.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            } else {
                editEsignaturePreview.style.display = 'none';
            }
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewModal');
            const editModal = document.getElementById('editModal');
            const editEsignatureModal = document.getElementById('editEsignatureModal');
            
            if (event.target === viewModal) {
                viewModal.style.display = 'none';
            }
            if (event.target === editModal) {
                editModal.style.display = 'none';
            }
            if (event.target === editEsignatureModal) {
                editEsignatureModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
