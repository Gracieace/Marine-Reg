<?php
require_once '../auth/auth.php';
auth_require_role(['admin', 'registrar']);

require_once '../config/db.php';
require_once '../includes/qr_helper.php';

try {
    $pdo = db_connect();
    initialize_schema($pdo); // Ensure our new tables exist
    $current_sy = get_current_school_year($pdo);
} catch (Exception $e) {
    error_log("DB Init Error: " . $e->getMessage());
    die("Database initialization error. Please contact administrator.");
}
$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $student_id = $_POST['student_id'] ?? '';

    if ($action === 'issue_id') {
        try {
            $pdo->beginTransaction();
            
            // Generate unique ID Number
            $id_number = QRHelper::generateIDNumber($pdo, $current_sy);
            
            // Insert into school_ids
            $stmt = $pdo->prepare("INSERT INTO school_ids (student_id, id_number, status, issued_at) 
                                   VALUES (?, ?, 'Active', NOW())
                                   ON DUPLICATE KEY UPDATE id_number = ?, status = 'Active', issued_at = NOW()");
            $stmt->execute([$student_id, $id_number, $id_number]);
            
            // Generate QR Token
            QRHelper::storeToken($pdo, $student_id);
            
            $pdo->commit();
            $success = "ID Card issued successfully for Student $student_id";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error issuing ID: " . $e->getMessage();
        }
    }

    if ($action === 'mark_lost') {
        $stmt = $pdo->prepare("UPDATE school_ids SET status = 'Lost' WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $success = "ID marked as Lost.";
    }

    if ($action === 'delete_id') {
        try {
            $stmt = $pdo->prepare("DELETE FROM school_ids WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $success = "ID record deleted successfully for student $student_id.";
        } catch (Exception $e) {
            $error = "Delete failed: " . $e->getMessage();
        }
    }

    if ($action === 'upload_photo') {
        try {
            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("No photo uploaded or upload error.");
            }
            
            $file = $_FILES['photo'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array(strtolower($ext), $allowed)) {
                throw new Exception("Invalid file type. Only JPG, PNG, and GIF allowed.");
            }
            
            $filename = 'student_' . $student_id . '_' . time() . '.' . $ext;
            $upload_dir = __DIR__ . '/../uploads/student_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $target_path = $upload_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $db_path = 'uploads/student_photos/' . $filename;
                // Update photo_path in BOTH enrollments and registrations for consistency
                $stmt = $pdo->prepare("UPDATE enrollments SET photo_path = ? WHERE student_id = ?");
                $stmt->execute([$db_path, $student_id]);
                
                $success = "Photo uploaded successfully for student $student_id.";
            } else {
                throw new Exception("Failed to save the file to the server.");
            }
        } catch (Exception $e) {
            $error = "Upload error: " . $e->getMessage();
        }
    }
}

// Fetch Students
$search = $_GET['search'] ?? '';
$grade_filter = $_GET['grade'] ?? '';

        $query = "SELECT e.*, sid.id_number, sid.id_status,
                 r.guardian_first as reg_g_first, r.guardian_last as reg_g_last, 
                 r.guardian_contact as reg_g_contact, 
                 r.curr_barangay as reg_brgy, r.curr_city as reg_city,
                 r.perm_barangay as reg_perm_brgy, r.perm_city as reg_perm_city,
                 r.track, r.strand,
                 adv.first_name as adv_first, adv.last_name as adv_last, adv.e_signature as adv_sig, adv.position_title as adv_title
          FROM enrollments e
          INNER JOIN (
              SELECT student_id, MAX(id) as latest_id
              FROM enrollments
              GROUP BY student_id
          ) latest_enroll ON e.id = latest_enroll.latest_id
          LEFT JOIN (
              SELECT s1.student_id, s1.id_number, s1.status as id_status
              FROM school_ids s1
              INNER JOIN (
                  SELECT student_id, MAX(id) as max_sid_id
                  FROM school_ids
                  GROUP BY student_id
              ) s2 ON s1.id = s2.max_sid_id
          ) sid ON e.student_id = sid.student_id
          LEFT JOIN registrations r ON e.registration_id = r.id
          LEFT JOIN sections sct ON (e.grade_level = sct.grade_level AND e.section = sct.section_name AND e.school_year = sct.school_year)
          LEFT JOIN users adv ON sct.adviser_id = adv.id
          WHERE (e.school_year = ? OR e.school_year IS NULL)";

$params = [$current_sy];

if ($search) {
    $query .= " AND (e.student_name LIKE ? OR e.student_id LIKE ? OR e.lrn LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($grade_filter) {
    $query .= " AND e.grade_level = ?";
    $params[] = $grade_filter;
}

$query .= " ORDER BY e.grade_level, e.student_name ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
    $students = [];
}

// Get stats
$stats = [
    'total' => count($students),
    'issued' => 0,
    'pending' => 0
];
foreach($students as $s) {
    if ($s['id_number']) $stats['issued']++;
    else $stats['pending']++;
}

// Get School Head
$school_head = get_system_setting($pdo, 'principal_name', 'MARILOU D. MARQUEZ, PhD');

// Fetch School Head's signature from position_assignments & users
$head_sig = null;
try {
    $stmt_head = $pdo->query("SELECT u.first_name, u.last_name, u.e_signature 
                              FROM position_assignments pa
                              JOIN users u ON pa.user_id = u.id
                              WHERE pa.position_type = 'principal' 
                              AND pa.school_year = '$current_sy'
                              LIMIT 1");
    $head_data = $stmt_head->fetch();
    if ($head_data) {
        $school_head = ($head_data['first_name'] . ' ' . $head_data['last_name']) ?: $school_head;
        $head_sig = $head_data['e_signature'];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student ID Management | Admin</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/assets/css/id_card_styles.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            background: #f1f5f9; 
            font-family: 'Inter', sans-serif; 
            margin: 0; 
            padding: 0; 
            color: #1e293b;
        }
        
        .content {
            padding: calc(var(--header-height) + 20px) 20px 40px;
            box-sizing: border-box;
            transition: margin-left 0.3s ease;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 { 
            margin: 0; 
            font-size: 28px; 
            font-weight: 800; 
            color: #0f172a; 
        }

        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border-left: 5px solid #cbd5e1;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card h3 { margin: 0; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; }
        .stat-card .value { font-size: 32px; font-weight: 800; margin-top: 8px; color: #0f172a; }

        /* Search & Filters */
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            outline: none;
            transition: 0.2s;
            font-size: 14px;
        }
        .search-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: 0.2s;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
        }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-outline { background: white; border: 1px solid #e2e8f0; color: #64748b; }
        .btn-outline:hover { background: #f8fafc; color: #0f172a; }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 40px;
        }
        
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .student-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 900px; }
        .student-table th { background: #f8fafc; padding: 16px 20px; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .student-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        .student-table tr:hover { background: #f8fafc; }

        .student-info { display: flex; align-items: center; gap: 12px; }
        .student-avatar-wrapper { position: relative; }
        .student-avatar { 
            width: 42px; 
            height: 42px; 
            border-radius: 12px; 
            object-fit: cover; 
            background: #e2e8f0; 
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #475569;
            font-size: 14px;
            flex-shrink: 0;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .upload-badge {
            position: absolute;
            bottom: -5px;
            right: -5px;
            width: 22px;
            height: 22px;
            background: #2563eb;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            border: 2px solid white;
            transition: 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .upload-badge:hover { transform: scale(1.1); background: #1d4ed8; }

        .badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .status-none { background: #f1f5f9; color: #94a3b8; }
        .status-active { background: #dcfce7; color: #15803d; }
        .status-lost { background: #fee2e2; color: #b91c1c; }

        /* Modal */
        .modal {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }
        .modal-content {
            background: #ffffff;
            border-radius: 32px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 40px;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            border: 1px solid #e2e8f0;
        }
        
        .id-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
            justify-content: center;
            margin-top: 30px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 15px;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
        }

        .close-modal {
            width: 36px;
            height: 36px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: 0.2s;
            color: #64748b;
            font-size: 20px;
        }
        .close-modal:hover { background: #e2e8f0; color: #0f172a; transform: rotate(90deg); }

        /* Mobile Overrides */
        @media (max-width: 1024px) {
            .filter-bar { grid-template-columns: 1fr auto; }
        }

        @media (max-width: 768px) {
            .content { padding-top: calc(var(--header-height) + 10px); }
            .page-header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; }
            .header-actions .btn { flex: 1; font-size: 13px; }
            .filter-bar { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; }
            .id-preview-grid { flex-direction: column; align-items: center; }
        }

        .alert { padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 600; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        @media print {
            .sidebar, .header, .filter-bar, .page-header, .table-container, .modal, .content { display: none !important; }
            body { background: white; }
            #previewModal { display: block !important; position: static !important; background: none !important; backdrop-filter: none !important; padding: 0 !important; }
            .modal-content { box-shadow: none !important; padding: 0 !important; max-width: none !important; width: auto !important; }
            .close-modal, .modal-content h2, .modal-content div:last-child { display: none !important; }
            .id-preview-grid { display: block !important; margin: 0 !important; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/admin_header.php'; ?>
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <div class="content main-content">
        <div class="page-header">
            <div>
                <h1>Student ID Management</h1>
                <p>Generate, monitor, and print student school IDs</p>
            </div>
            <div class="header-actions">
                <a href="qr_scanner.php" class="btn btn-primary">
                    <i class="fa fa-qrcode"></i> Re-Enrollment Scanner
                </a>
                <a href="id_print_batch.php" class="btn btn-outline" target="_blank">
                    <i class="fa fa-print"></i> Bulk Print IDs
                </a>
            </div>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fa fa-check-circle"></i> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fa fa-times-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card" style="border-left-color: #3b82f6;">
                <h3>Total Enrolled</h3>
                <div class="value"><?= $stats['total'] ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #10b981;">
                <h3>IDs Issued</h3>
                <div class="value"><?= $stats['issued'] ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #f59e0b;">
                <h3>Pending IDs</h3>
                <div class="value"><?= $stats['pending'] ?></div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search student name, LRN or ID..." class="search-input">
            <select name="grade" class="search-input" style="width: auto;">
                <option value="">All Grades</option>
                <?php 
                $grades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                foreach($grades as $g): ?>
                    <option value="<?= $g ?>" <?= $grade_filter == $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline">
                <i class="fa fa-search"></i> <span class="d-none d-md-inline">Search</span>
            </button>
        </form>

        <div class="table-container">
            <div class="table-responsive">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>LRN</th>
                            <th>Grade & Section</th>
                            <th>ID Number</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $s): 
                            $fullName = $s['student_name'];
                            $id_status = $s['id_status'] ?? 'None';
                        ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar-wrapper">
                                            <div class="student-avatar">
                                                <?php if($s['photo_path']): ?>
                                                    <img src="<?= url_for($s['photo_path']) ?>" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($fullName, 0, 2)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="upload-badge" title="Upload Student Photo" onclick="triggerPhotoUpload('<?= $s['student_id'] ?>')">
                                                <i class="fa fa-camera"></i>
                                            </div>
                                        </div>
                                        <div style="font-weight:700; color: #0f172a;"><?= htmlspecialchars($fullName) ?></div>
                                    </div>
                                </td>
                                <td style="font-family: monospace; font-weight: 600; color: #475569;"><?= htmlspecialchars($s['lrn'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($s['grade_level']) ?> - <?= htmlspecialchars($s['section']) ?></td>
                                <td style="font-family: monospace; font-weight: 700;">
                                    <?= $s['id_number'] ?: '<span style="color:#94a3b8; font-weight: 400;">Not Assigned</span>' ?>
                                </td>
                                <td>
                                    <span class="badge status-<?= strtolower($id_status ?: 'none') ?>">
                                        <?= $id_status ?: 'Pending' ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex; gap:8px;">
                                        <?php if(!$s['id_number']): ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="action" value="issue_id">
                                                <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                                                <button type="submit" class="btn btn-primary" style="padding:8px 16px;">
                                                    <i class="fa fa-plus-circle"></i> Issue ID
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button onclick='previewID(<?= json_encode($s, JSON_UNESCAPED_UNICODE) ?>)' class="btn btn-outline" style="padding:8px 16px;">
                                                <i class="fa fa-eye"></i> Preview
                                            </button>
                                            
                                            <?php if($id_status === 'Lost'): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="action" value="issue_id">
                                                    <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                                                    <button type="submit" class="btn btn-primary" style="padding:8px 16px; background: #059669; border-color: #059669;" title="Re-issue New ID">
                                                        <i class="fa fa-refresh"></i> Re-issue
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <button class="btn btn-outline" style="padding:8px 16px; color:#b91c1c; border-color:#fee2e2;" onclick="markLost('<?= $s['student_id'] ?>')" title="Mark as Lost">
                                                <i class="fa fa-exclamation-triangle"></i>
                                            </button>
                                            <button class="btn btn-outline" style="padding:8px 16px; color:#b91c1c; border-color:#fee2e2;" onclick="deleteID('<?= $s['student_id'] ?>')" title="Delete ID Record">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($students)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                    <i class="fa fa-search" style="font-size: 24px; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                    No students found matching your search.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Digital Student ID</h2>
                <div class="close-modal" onclick="closeModal()">&times;</div>
            </div>
            
            <div class="id-card-container" id="student-id-container">
                <!-- Front Side -->
                <div class="id-card id-front" id="idFront">
                    <!-- Traditional MMFSL Sidebar -->
                    <div class="id-sidebar">
                        <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="sidebar-logo">
                        <div class="sidebar-text">MMFSL</div>
                        <div class="sidebar-serial" id="prevIDNum">2026-000001</div>
                    </div>

                    <!-- Main ID Content -->
                    <div class="id-main">
                        <div class="deped-header">
                            <div class="deped-top">Republic of the Philippines</div>
                            <div class="deped-top">DEPARTMENT OF EDUCATION</div>
                            <div class="school-title">MALOLOS MARINE FISHERY SCHOOL AND LABORATORY</div>
                            <div class="school-address">Balite, City of Malolos, Bulacan</div>
                        </div>

                        <div class="photo-section">
                            <div class="photo-box">
                                <img src="" id="prevPhoto">
                            </div>
                        </div>

                        <div class="student-info">
                            <div class="name-label" id="prevName">STUDENT NAME</div>
                            <div class="lrn-label" id="prevLRN">LRN: 000000000000</div>
                            
                            <div class="grade-label" id="prevGradeSec">GRADE 7 - SECTION A</div>
                            <div class="strand-label" id="prevStrand">Academic Track - GAS</div>
                            <div class="sy-label">School Year: <?= $current_sy ?></div>
                        </div>
                    </div>
                </div>

                <div class="id-card">
                    <div class="id-sidebar"></div>
                    <div class="id-main id-back" style="padding: 15px; flex-grow: 1;">
                        <div class="back-section-label">Emergency Contact</div>
                        
                        <div class="emergency-box">
                            <div class="contact-item">
                                <span class="contact-label">Parent/Guardian</span>
                                <span class="contact-val" id="prevGuardian">RUFINO MONTER LINGO</span>
                            </div>
                            <div class="contact-item">
                                <span class="contact-label">Contact Number</span>
                                <span class="contact-val" id="prevContact">09287770303</span>
                            </div>
                        </div>

                        <div class="rules-divider"></div>
                        <div class="back-section-label" style="font-size:7.5px; margin-bottom:10px;">Rules & Guidelines</div>
                        
                        <div class="rules-list">
                            <p><span>1.</span> This card is non-transferable and must be worn at all times.</p>
                            <p><span>2.</span> Report loss immediately to the Registrar's Office.</p>
                            <p><span>3.</span> Card serves as official verification for school services.</p>
                            <p><span>4.</span> Please return this card if found or upon graduation/transfer.</p>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: auto; padding: 5px 0;">
                            <div class="signature-wrap" style="flex: 1; margin: 0; position: relative; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;">
                                <div style="height: 50px; width: 100%; position: absolute; bottom: 22px; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 1;">
                                    <img src="" id="prevAdviserSig" style="max-height: 100%; max-width: 85px; display: none; mix-blend-mode: multiply;">
                                </div>
                                <div class="sig-line" style="width: 90%; border-top: 1px solid #000; margin-bottom: 2px; position: relative; z-index: 2;"></div>
                                <div class="sig-name" id="prevAdviserName" style="font-size: 8px; font-weight: 800; line-height: 1; margin-bottom: 1px; position: relative; z-index: 2;"></div>
                                <div class="sig-title" style="font-size: 6.5px; font-weight: 600; color: #444;">Class Adviser</div>
                            </div>

                            <div class="signature-wrap" style="flex: 1; margin: 0; position: relative; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;">
                                <div style="height: 50px; width: 100%; position: absolute; bottom: 22px; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 1;">
                                    <?php if($head_sig): ?>
                                        <img src="<?= url_for('/uploads/'.$head_sig) ?>" style="max-height: 100%; max-width: 85px; mix-blend-mode: multiply;">
                                    <?php endif; ?>
                                </div>
                                <div class="sig-line" style="width: 90%; border-top: 1px solid #000; margin-bottom: 2px; position: relative; z-index: 2;"></div>
                                <div class="sig-name" id="prevSchoolHead" style="font-size: 8px; font-weight: 800; line-height: 1; margin-bottom: 1px; position: relative; z-index: 2;"><?= strtoupper($school_head) ?></div>
                                <div class="sig-title" style="font-size: 6.5px; font-weight: 600; color: #444; position: relative; z-index: 2;">School Head</div>
                            </div>
                        </div>

                        <div class="back-qr-wrap">
                            <div class="back-qr-box">
                                <img src="" class="prevBackQR" id="prevBackQR">
                            </div>
                            <div class="back-qr-label">Scan for Verification</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Export Libraries -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

            <div style="margin-top:40px; display:flex; justify-content:center; gap:15px;" class="btn-print-group">
                <button class="btn btn-primary" onclick="exportID('png', this)" style="padding: 14px 24px; border-radius: 12px; background: #0f172a; border-color: #0f172a;">
                    <i class="fa fa-image"></i> Download PNG
                </button>
                <button class="btn btn-primary" onclick="exportID('pdf', this)" style="padding: 14px 24px; border-radius: 12px; background: #b91c1c; border-color: #b91c1c;">
                    <i class="fa fa-file-pdf-o"></i> Download PDF
                </button>
                <button class="btn btn-outline" onclick="closeModal()" style="padding: 14px 24px; border-radius: 12px;">
                    Close Preview
                </button>
            </div>
        </div>
    </div>

    <script>
        const BASE_URL = '<?= rtrim(url_for('/'), '/') ?>';

        let currentStudent = null;

        function previewID(student) {
            currentStudent = student;
            document.getElementById('prevName').innerText = (student.student_name || 'STUDENT NAME').toUpperCase();
            document.getElementById('prevLRN').innerText = 'LRN: ' + (student.lrn || '000000000000');
            document.getElementById('prevGradeSec').innerText = (student.grade_level || 'Grade') + ' - ' + (student.section || 'Section');
            document.getElementById('prevIDNum').innerText = student.id_number || 'NONE';
            
            // Dynamic Curriculum (JHS vs SHS with Track/Strand)
            let curriculum = '';
            const grade = (student.grade_level || '').toLowerCase();
            const isSHS = grade.includes('11') || grade.includes('12');
            
            if (isSHS) {
                curriculum = student.track ? (student.track + ' - ' + (student.strand || '')) : 'Senior High School';
            } else {
                curriculum = 'Junior High School';
            }
            document.getElementById('prevStrand').innerText = curriculum.toUpperCase();
            
            // Robust path joining for student photo
            let photoUrl = '';
            if (student.photo_path) {
                // Ensure no double slashes and correct base
                const cleanPath = student.photo_path.replace(/^\//, '');
                photoUrl = BASE_URL + '/' + cleanPath;
            } else {
                photoUrl = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(student.student_name || 'Student') + '&background=0D8ABC&color=fff&size=256';
            }
            document.getElementById('prevPhoto').src = photoUrl;
            
            // Pull Emergency Contact with fallback (Registration -> Enrollment)
            const gFirst = student.reg_g_first || student.guardian_first || '';
            const gLast = student.reg_g_last || student.guardian_last || '';
            const guardianName = (gFirst + ' ' + gLast).trim();
            document.getElementById('prevGuardian').innerText = guardianName ? guardianName.toUpperCase() : 'N/A';
            
            document.getElementById('prevContact').innerText = student.reg_g_contact || student.guardian_contact || 'N/A';
            
            // Address removed per request
            
            // Generate QR content
            const qrData = JSON.stringify({sid: student.student_id, sy: '<?= $current_sy ?>'});
            const qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" + encodeURIComponent(qrData);
            
            const frontQR = document.getElementById('prevQR');
            if (frontQR) frontQR.src = qrUrl;
            
            const backQR = document.getElementById('prevBackQR');
            if (backQR) backQR.src = qrUrl;

            // Update Adviser Name & Signature
            let advName = (student.adv_first && student.adv_last) ? (student.adv_first + ' ' + student.adv_last) : 'CLASS ADVISER';
            document.getElementById('prevAdviserName').innerText = advName.toUpperCase();
            
            const sigImg = document.getElementById('prevAdviserSig');
            if (student.adv_sig) {
                sigImg.src = BASE_URL + '/uploads/' + student.adv_sig;
                sigImg.style.display = 'block';
            } else {
                sigImg.style.display = 'none';
            }

            document.getElementById('previewModal').style.display = 'flex';
        }

        async function exportID(format, btn) {
            if (!currentStudent) return;
            
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            try {
                const container = document.getElementById('student-id-container');
                if (!container) throw new Error("Unable to generate ID. Please reload preview.");

                // Small delay to ensure all dynamic content/QR codes are fully painted
                await new Promise(resolve => setTimeout(resolve, 600));

                const canvas = await html2canvas(container, {
                    scale: 2, // High resolution for professional PVC output
                    useCORS: true,
                    allowTaint: false,
                    backgroundColor: '#ffffff',
                    scrollX: 0,
                    scrollY: -window.scrollY,
                    onclone: (clonedDoc) => {
                        const el = clonedDoc.getElementById('student-id-container');
                        if (el) {
                            el.style.display = 'flex';
                            el.style.padding = '15mm';
                            el.style.background = '#ffffff';
                        }
                    }
                });

                const filename = `MMFSL_ID_${currentStudent.student_id}`;

                if (format === 'png') {
                    const dataUrl = canvas.toDataURL('image/png');
                    const link = document.createElement('a');
                    link.download = filename + '.png';
                    link.href = dataUrl;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else if (format === 'pdf') {
                    const { jsPDF } = window.jspdf;
                    const pdf = new jsPDF({
                        orientation: 'landscape',
                        unit: 'mm',
                        format: 'a4'
                    });
                    
                    const imgData = canvas.toDataURL('image/png');
                    const imgProps = pdf.getImageProperties(imgData);
                    const pdfWidth = pdf.internal.pageSize.getWidth();
                    const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
                    const yPos = (pdf.internal.pageSize.getHeight() - pdfHeight) / 2;
                    
                    pdf.addImage(imgData, 'PNG', 0, yPos, pdfWidth, pdfHeight);
                    pdf.save(filename + '.pdf');
                }
            } catch (err) {
                console.error('Export Error:', err);
                alert('Export Error: ' + (err.message || "Unknown error occurred. Please refresh and try again."));
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        function closeModal() {
            document.getElementById('previewModal').style.display = 'none';
        }

        function markLost(sid) {
            if(confirm('Are you sure you want to mark this ID as lost?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="mark_lost"><input type="hidden" name="student_id" value="${sid}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteID(sid) {
            if(confirm('Are you sure you want to PERMANENTLY DELETE this student\'s ID record? This will remove the ID number and status.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete_id"><input type="hidden" name="student_id" value="${sid}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function triggerPhotoUpload(studentId) {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = e => {
                const file = e.target.files[0];
                if (file) {
                    const formData = new FormData();
                    formData.append('action', 'upload_photo');
                    formData.append('student_id', studentId);
                    formData.append('photo', file);
                    
                    // Show a simple loading state or just submit a form
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.enctype = 'multipart/form-data';
                    form.style.display = 'none';
                    
                    const actionInput = document.createElement('input');
                    actionInput.name = 'action';
                    actionInput.value = 'upload_photo';
                    
                    const idInput = document.createElement('input');
                    idInput.name = 'student_id';
                    idInput.value = studentId;
                    
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.name = 'photo';
                    
                    // Transfer the file
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    form.appendChild(fileInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            };
            input.click();
        }
    </script>
</body>
</html>