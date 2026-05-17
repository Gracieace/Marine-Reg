<?php
/**
 * SF10 – Learner Permanent Academic Record System
 * Professional UI with Timeline, Academic History, and Registrar Workflow.
 */
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';

auth_require_role(['teacher', 'admin', 'registrar']);

$pdo = db_connect();
$user = auth_user();
$user_id = $user['id'];
$role = $user['role'];

// Initialize Schema
if (function_exists('initialize_schema')) {
    initialize_schema($pdo);
}

// 1. Context Selection
$target_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$target_section = $_GET['section'] ?? '';
$target_sy = $_GET['sy'] ?? $_GET['school_year'] ?? get_active_school_year($pdo);
$selected_student_id = $_GET['student_id'] ?? '';

// 2. Fetch Advisory/Section List (For sidebar/navigation)
$advisory = null;
if ($role === 'teacher') {
    $stmt = $pdo->prepare("SELECT * FROM position_assignments 
         WHERE (user_id = ? OR employee_id IN (SELECT id FROM teachers WHERE email = (SELECT username FROM users WHERE id = ?))) 
           AND position_type = 'class_adviser' 
         ORDER BY school_year DESC LIMIT 1");
    $stmt->execute([$user_id, $user_id]);
    $advisory = $stmt->fetch();
}

// 3. Handle Actions (Verify/Lock/Archive)
$message = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $sid = $_POST['student_id'] ?? '';
    
    try {
        if ($action === 'verify_record') {
            $stmt = $pdo->prepare("INSERT INTO sf10_records (student_id, status, verified_by, verified_at) 
                                   VALUES (?, 'Verified', ?, NOW()) 
                                   ON DUPLICATE KEY UPDATE status='Verified', verified_by=?, verified_at=NOW()");
            $stmt->execute([$sid, $user_id, $user_id]);
            $message = "Learner record verified successfully.";
        } elseif ($action === 'lock_record') {
            $stmt = $pdo->prepare("UPDATE sf10_records SET status='Locked', finalized_by=?, finalized_at=NOW() WHERE student_id = ?");
            $stmt->execute([$user_id, $sid]);
            $message = "Record locked for editing.";
        } elseif ($action === 'save_eligibility') {
            // Ensure columns exist (Simplified dynamic schema update)
            $pdo->exec("ALTER TABLE sf10_records ADD COLUMN IF NOT EXISTS elem_school_name VARCHAR(255), 
                        ADD COLUMN IF NOT EXISTS elem_school_id VARCHAR(50), 
                        ADD COLUMN IF NOT EXISTS elem_school_address TEXT,
                        ADD COLUMN IF NOT EXISTS elem_gen_avg VARCHAR(10),
                        ADD COLUMN IF NOT EXISTS elem_citation VARCHAR(255),
                        ADD COLUMN IF NOT EXISTS pept_rating VARCHAR(50),
                        ADD COLUMN IF NOT EXISTS ae_rating VARCHAR(50),
                        ADD COLUMN IF NOT EXISTS elem_others VARCHAR(255)");
            
            $stmt = $pdo->prepare("INSERT INTO sf10_records (student_id, elem_school_name, elem_school_id, elem_school_address, elem_gen_avg, elem_citation, pept_rating, ae_rating, elem_others) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE elem_school_name=?, elem_school_id=?, elem_school_address=?, elem_gen_avg=?, elem_citation=?, pept_rating=?, ae_rating=?, elem_others=?");
            $stmt->execute([
                $sid, $_POST['elem_school'], $_POST['elem_school_id'], $_POST['elem_address'], $_POST['elem_gen_avg'], $_POST['elem_citation'], $_POST['pept_rating'], $_POST['ae_rating'], $_POST['elem_others'],
                $_POST['elem_school'], $_POST['elem_school_id'], $_POST['elem_address'], $_POST['elem_gen_avg'], $_POST['elem_citation'], $_POST['pept_rating'], $_POST['ae_rating'], $_POST['elem_others']
            ]);
            $message = "Eligibility information updated successfully.";
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

// 4. Fetch Student List for the Section
$students = [];
if ($target_grade && $target_section) {
    $stmt = $pdo->prepare("SELECT e.student_id, e.student_name, e.lrn, e.photo_path 
                           FROM enrollments e 
                           WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ? 
                           ORDER BY e.student_name ASC");
    $stmt->execute([$target_grade, $target_section, $target_sy]);
    $students = $stmt->fetchAll();
} elseif ($advisory) {
    $stmt = $pdo->prepare("SELECT e.student_id, e.student_name, e.lrn, e.photo_path 
                           FROM enrollments e 
                           WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ? 
                           ORDER BY e.student_name ASC");
    $stmt->execute([$advisory['grade_level'], $advisory['section'], $advisory['school_year']]);
    $students = $stmt->fetchAll();
}

// 5. Fetch Selected Student Details
$student = null;
$academic_history = [];
$attendance_history = [];
$sf10_status = 'Draft';

if ($selected_student_id) {
    // Basic Info - Use e.* for the bulk of data as enrollments table is kept up-to-date
    $stmt = $pdo->prepare("SELECT e.*, r.sex as reg_sex, r.birthdate as reg_birthdate
                           FROM enrollments e 
                           LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.lrn))
                           WHERE e.student_id = ? ORDER BY e.school_year DESC LIMIT 1");
    $stmt->execute([$selected_student_id]);
    $student = $stmt->fetch();

    if ($student) {
        // Academic History (Enrollments + Grades)
        $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE student_id = ? ORDER BY grade_level ASC");
        $stmt->execute([$selected_student_id]);
        $academic_history = $stmt->fetchAll();
        
        // Grades per year
        $stmt = $pdo->prepare("SELECT g.*, s.subject_name, s.subject_code 
                               FROM sf9_grades g 
                               JOIN curriculum s ON g.subject_id = s.id 
                               WHERE g.student_id = ? 
                               ORDER BY g.school_year, s.subject_name");
        $stmt->execute([$selected_student_id]);
        $grades = $stmt->fetchAll();
        
        $history_data = [];
        foreach($grades as $g) { $history_data[$g['school_year']][] = $g; }

        // Attendance (Aggregated from SF2 Reports)
        $stmt = $pdo->prepare("SELECT r.school_year, SUM(s.total_present) as days_present, SUM(s.total_absent) as days_absent, SUM(r2.days_of_classes) as total_days
                               FROM sf2_student_records s
                               JOIN sf2_reports r ON s.sf2_report_id = r.id
                               LEFT JOIN sf2_monthly_summary r2 ON r.id = r2.sf2_report_id
                               WHERE s.student_id = ?
                               GROUP BY r.school_year
                               ORDER BY r.school_year DESC");
        $stmt->execute([$selected_student_id]);
        $attendance_history = $stmt->fetchAll();
        
        // SF10 Meta
        $stmt = $pdo->prepare("SELECT * FROM sf10_records WHERE student_id = ?");
        $stmt->execute([$selected_student_id]);
        $sf10_data = $stmt->fetch();
        $sf10_status = $sf10_data['status'] ?? 'Draft';

        // Awards & Conduct
        $stmt = $pdo->prepare("SELECT * FROM awards WHERE student_id = ? ORDER BY school_year DESC");
        $stmt->execute([$selected_student_id]);
        $awards = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM conduct_records WHERE student_id = ? ORDER BY school_year DESC");
        $stmt->execute([$selected_student_id]);
        $conduct = $stmt->fetchAll();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF10 | Learner Permanent Record</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0f172a;
            --accent: #2563eb;
            --accent-soft: rgba(37, 99, 235, 0.1);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --text-muted: #64748b;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --glass: rgba(255, 255, 255, 0.7);
        }

        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text); margin: 0; line-height: 1.5; }
        
        .main-content { 
            padding: 32px; 
            display: grid; 
            grid-template-columns: 350px 1fr; 
            gap: 32px; 
            min-height: 100vh; 
            max-width: 1600px;
        }

        /* Responsive Layout Adjustments */
        @media (max-width: 1200px) {
            .main-content {
                grid-template-columns: 300px 1fr;
                gap: 24px;
                padding: 24px;
            }
            .main-content.sidebar-collapsed {
                grid-template-columns: 60px 1fr;
            }
        }

        @media (max-width: 992px) {
            .main-content {
                grid-template-columns: 1fr;
                display: flex;
                flex-direction: column;
                margin-left: 0 !important; /* Force zero margin on mobile */
            }
            .learner-sidebar {
                position: relative;
                top: 0;
                height: 400px;
                width: 100%;
            }
        }
        .learner-sidebar { 
            background: var(--surface); 
            border-radius: var(--radius-lg); 
            border: 1px solid var(--border); 
            height: calc(100vh - 148px); 
            display: flex; 
            flex-direction: column; 
            position: sticky; 
            top: 100px; 
            box-shadow: var(--shadow-sm);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
            overflow: visible;
        }

        .sidebar-collapsed .learner-sidebar {
            width: 60px;
        }
        
        .sidebar-collapsed .sidebar-header h2,
        .sidebar-collapsed .sidebar-header p,
        .sidebar-collapsed .search-box,
        .sidebar-collapsed .learner-info {
            display: none;
        }
        
        .sidebar-collapsed .sidebar-header {
            padding: 12px;
            text-align: center;
        }
        
        .sidebar-collapsed .learner-item {
            justify-content: center;
            padding: 12px 0;
        }

        
        .sidebar-header { 
            padding: 24px; 
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-bottom: 1px solid var(--border); 
            position: relative;
        }

        .sidebar-header h2 { font-size: 18px; font-weight: 800; margin: 0; color: var(--primary); letter-spacing: -0.02em; }
        
        .search-box { margin-top: 16px; position: relative; }
        .search-box input { 
            width: 100%; 
            padding: 12px 12px 12px 42px; 
            border-radius: var(--radius-md); 
            border: 1px solid var(--border); 
            font-size: 14px; 
            background: #f1f5f9;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-sizing: border-box;
        }
        .search-box input:focus { 
            outline: none; 
            border-color: var(--accent); 
            background: white; 
            box-shadow: 0 0 0 4px var(--accent-soft); 
        }
        .search-box i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 14px; }
        
        .learner-list { flex: 1; overflow-y: auto; padding: 12px; scrollbar-width: thin; }
        .learner-item { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            padding: 14px; 
            border-radius: var(--radius-md); 
            text-decoration: none; 
            color: inherit; 
            transition: all 0.2s ease; 
            margin-bottom: 4px; 
            border: 1px solid transparent;
        }
        .learner-item:hover { background: #f1f5f9; transform: translateX(4px); }
        .learner-item.active { background: var(--accent-soft); border-color: rgba(37, 99, 235, 0.2); }
        
        .learner-avatar { 
            width: 44px; 
            height: 44px; 
            border-radius: 12px; 
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); 
            color: #4338ca; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 700; 
            font-size: 16px;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        }
        .learner-avatar img { width: 100%; height: 100%; border-radius: 12px; object-fit: cover; }
        
        .learner-info h4 { margin: 0; font-size: 14px; font-weight: 700; color: var(--primary); }
        .learner-info p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted); font-weight: 500; }

        /* Main View */
        .sf10-view { display: flex; flex-direction: column; gap: 32px; }
        
        .glass-card { 
            background: var(--surface); 
            border-radius: var(--radius-lg); 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow); 
            padding: 32px;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        
        /* Learner Profile Header */
        .profile-header { display: flex; align-items: flex-start; gap: 40px; position: relative; }
        .profile-photo-container { position: relative; }
        .profile-photo { 
            width: 140px; 
            height: 140px; 
            border-radius: 20px; 
            object-fit: cover; 
            border: 4px solid white; 
            box-shadow: var(--shadow); 
            background: #f1f5f9; 
        }
        
        .official-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 24px;
            margin-bottom: 32px;
        }
        .header-logo { height: 70px; width: auto; object-fit: contain; }
        .header-text { text-align: center; flex: 1; }
        .header-text h2 { margin: 0; font-size: 14px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; }
        .header-text h1 { margin: 4px 0; font-size: 22px; font-weight: 800; color: var(--primary); }
        .header-text p { margin: 0; font-size: 12px; font-weight: 700; color: var(--accent); }
        
        .profile-details { flex: 1; padding-top: 12px; }
        .profile-details h1 { margin: 0; font-size: 36px; font-weight: 800; color: var(--primary); letter-spacing: -0.03em; line-height: 1.1; }
        
        .profile-meta { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .meta-tag { 
            background: #f1f5f9; 
            padding: 8px 16px; 
            border-radius: 12px; 
            font-size: 13px; 
            font-weight: 600; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            color: var(--text);
            border: 1px solid var(--border);
        }
        .meta-tag i { color: var(--accent); font-size: 14px; }
        
        .status-badge { 
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px; 
            border-radius: 30px; 
            font-size: 11px; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 0.05em;
            margin-bottom: 12px;
        }
        .st-draft { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
        .st-verified { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .st-locked { background: #1e3a8a; color: white; border: 1px solid #1e3a8a; }

        /* Timeline */
        .timeline { position: relative; padding-left: 40px; margin-top: 32px; }
        .timeline::before { content: ''; position: absolute; left: 9px; top: 0; bottom: 0; width: 2px; background: linear-gradient(to bottom, var(--accent) 0%, var(--border) 100%); opacity: 0.2; }
        
        .timeline-item { position: relative; margin-bottom: 48px; transition: transform 0.3s ease; }
        .timeline-dot { 
            position: absolute; 
            left: -40px; 
            top: 0; 
            width: 20px; 
            height: 20px; 
            border-radius: 50%; 
            background: white; 
            border: 4px solid var(--accent); 
            z-index: 2; 
            box-shadow: 0 0 0 4px var(--accent-soft);
        }
        
        .timeline-content { 
            background: white; 
            border-radius: var(--radius-lg); 
            border: 1px solid var(--border); 
            padding: 28px; 
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }
        .timeline-item:hover .timeline-content { box-shadow: var(--shadow); transform: translateY(-4px); border-color: var(--accent-soft); }
        
        .timeline-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            margin-bottom: 20px; 
            padding-bottom: 16px; 
            border-bottom: 1px solid #f1f5f9; 
        }
        .timeline-header h3 { margin: 0; font-size: 20px; font-weight: 800; color: var(--primary); letter-spacing: -0.01em; }
        
        .sy-badge { background: var(--accent-soft); color: var(--accent); padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; }
        
        /* Tables */
        .data-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; margin-top: 8px; }
        .data-table th { 
            text-align: left; 
            background: #f8fafc; 
            padding: 14px 16px; 
            color: var(--text-muted); 
            font-weight: 700; 
            text-transform: uppercase; 
            font-size: 11px; 
            letter-spacing: 0.05em;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .data-table th:first-child { border-left: 1px solid var(--border); border-radius: 12px 0 0 0; }
        .data-table th:last-child { border-right: 1px solid var(--border); border-radius: 0 12px 0 0; }
        
        .data-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: var(--text); }
        .data-table tr:hover td { background: #fcfdfe; }
        
        .avg-row { background: #f8fafc !important; }
        .avg-row td { font-weight: 800; color: var(--primary); font-size: 14px; border-top: 1px solid var(--border); }
        
        .remarks-badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        .passed { background: #dcfce7; color: #15803d; }
        .failed { background: #fee2e2; color: #b91c1c; }

        /* Attendance */
        .attendance-summary { 
            margin-top: 24px; 
            padding: 20px; 
            background: #f8fafc; 
            border-radius: var(--radius-md); 
            display: flex; 
            gap: 32px;
            border: 1px solid var(--border);
        }
        .att-item { display: flex; flex-direction: column; gap: 4px; }
        .att-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .att-value { font-size: 18px; font-weight: 800; color: var(--primary); }

        /* Buttons */
        .btn { 
            padding: 12px 24px; 
            border-radius: var(--radius-md); 
            font-weight: 700; 
            font-size: 14px; 
            cursor: pointer; 
            border: 1px solid transparent; 
            display: inline-flex; 
            align-items: center; 
            gap: 10px; 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            text-decoration: none; 
        }
        .btn:active { transform: scale(0.96); }
        
        .btn-primary { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); }
        .btn-primary:hover { background: #1d4ed8; box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3); transform: translateY(-2px); }
        
        .btn-outline { background: white; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-2px); }
        
        .action-grid { display: flex; gap: 12px; margin-top: 32px; flex-wrap: wrap; }
        
        .empty-state { text-align: center; padding: 120px 40px; color: var(--text-muted); }
        .empty-state i { font-size: 64px; margin-bottom: 24px; color: var(--accent); opacity: 0.1; }
        .empty-state h2 { font-size: 24px; font-weight: 800; color: var(--primary); margin-bottom: 12px; }

        /* Eligibility Modal Styles */
        .modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.8); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
        .modal-content { background: white; width: 600px; border-radius: 24px; padding: 40px; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.5); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .form-group input { padding: 12px 16px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 14px; transition: all 0.2s; }
        .form-group input:focus { border-color: var(--accent); ring: 2px solid var(--accent-soft); outline: none; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <main class="main-content">
        <!-- Sidebar Navigation -->
        <aside class="learner-sidebar">
            <div class="sidebar-header">
                <h2>Advisory Class</h2>
                <p style="font-size:11px; color:var(--text-muted); margin:4px 0 0;"><?= $target_grade ?> - <?= $target_section ?> (<?= $target_sy ?>)</p>
                <div class="search-box">
                    <i class="fa fa-search"></i>
                    <input type="text" placeholder="Search learner..." onkeyup="filterLearners(this.value)">
                </div>
            </div>
            <div class="learner-list" id="learnerList" style="overflow-y: auto; flex: 1; border-radius: 0 0 var(--radius-lg) var(--radius-lg);">
                <?php foreach($students as $s): ?>
                    <a href="?student_id=<?= urlencode($s['student_id']) ?>&grade=<?= urlencode($target_grade) ?>&section=<?= urlencode($target_section) ?>&sy=<?= urlencode($target_sy) ?>" 
                       class="learner-item <?= $selected_student_id == $s['student_id'] ? 'active' : '' ?>">
                        <div class="learner-avatar">
                            <?php if(!empty($s['photo_path'])): ?>
                                <img src="<?= url_for($s['photo_path']) ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <?= strtoupper(substr($s['student_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <div class="learner-info" style="flex: 1;">
                            <h4><?= htmlspecialchars($s['student_name']) ?></h4>
                            <p>LRN: <?= htmlspecialchars($s['lrn']) ?></p>
                        </div>
                        <i class="fa fa-chevron-right" style="font-size: 10px; color: var(--text-muted); opacity: 0.5;"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- Main View Area -->
        <div class="sf10-view">
            <?php if($student): ?>
                <div class="glass-card">
                    <div class="official-header">
                        <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="header-logo">
                        <div class="header-text">
                            <h2>Republic of the Philippines</h2>
                            <h1>Department of Education</h1>
                            <p>LEARNER PERMANENT ACADEMIC RECORD (SF10-JHS)</p>
                        </div>
                        <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="header-logo">
                    </div>

                    <div class="profile-header">
                        <div class="profile-photo-container">
                            <img src="<?= $student['photo_path'] ? url_for($student['photo_path']) : 'https://ui-avatars.com/api/?name='.urlencode($student['student_name']).'&size=120' ?>" class="profile-photo">
                        </div>
                        <div class="profile-details">
                            <div class="status-badge st-<?= strtolower($sf10_status) ?>">
                                <i class="fa <?= $sf10_status === 'Locked' ? 'fa-lock' : ($sf10_status === 'Verified' ? 'fa-check-circle' : 'fa-edit') ?>"></i>
                                <?= $sf10_status ?>
                            </div>
                            <h1><?= strtoupper($student['student_name']) ?></h1>
                            <div class="profile-meta">
                                <div class="meta-tag"><i class="fa fa-id-card"></i> LRN: <?= $student['lrn'] ?></div>
                                <div class="meta-tag"><i class="fa fa-venus-mars"></i> <?= $student['reg_sex'] ?? 'N/A' ?></div>
                                <div class="meta-tag"><i class="fa fa-birthday-cake"></i> <?= date('M d, Y', strtotime($student['birthdate'])) ?></div>
                                <div class="meta-tag"><i class="fa fa-map-marker-alt"></i> <?= htmlspecialchars($student['address'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="action-grid">
                        <button onclick="document.getElementById('eligibilityModal').style.display='flex'" class="btn btn-outline">
                            <i class="fa fa-edit"></i> Edit Eligibility
                        </button>
                        <a href="sf10_print.php?student_id=<?= $selected_student_id ?>" target="_blank" class="btn btn-primary"><i class="fa fa-print"></i> Print Official SF10</a>
                        <?php if($sf10_status === 'Draft'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                                <button type="submit" name="action" value="verify_record" class="btn btn-primary" style="background:var(--success);"><i class="fa fa-check-double"></i> Verify Record</button>
                            </form>
                        <?php else: ?>
                            <div class="btn" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; cursor:default; pointer-events:none;">
                                <i class="fa fa-check-circle"></i> VERIFIED
                            </div>
                        <?php endif; ?>
                        
                        <?php if(($role === 'registrar' || $role === 'admin') && $sf10_status !== 'Locked'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                                <button type="submit" name="action" value="lock_record" class="btn btn-outline" style="color:var(--primary);border-color:var(--primary);"><i class="fa fa-lock"></i> Lock Record</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Eligibility Modal -->
                <div id="eligibilityModal" class="modal">
                    <div class="modal-content">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
                            <h2 style="margin:0; font-weight:800; color:var(--primary);">JHS Enrolment Eligibility</h2>
                            <button onclick="this.closest('.modal').style.display='none'" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8;">&times;</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_eligibility">
                            <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                            <div class="form-grid">
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>Elementary School Name</label>
                                    <input type="text" name="elem_school" value="<?= htmlspecialchars($sf10_data['elem_school_name'] ?? $student['last_school_attended'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>School ID</label>
                                    <input type="text" name="elem_school_id" value="<?= htmlspecialchars($sf10_data['elem_school_id'] ?? $student['last_school_id'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>General Average</label>
                                    <input type="text" name="elem_gen_avg" value="<?= htmlspecialchars($sf10_data['elem_gen_avg'] ?? '') ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>School Address</label>
                                    <input type="text" name="elem_address" value="<?= htmlspecialchars($sf10_data['elem_school_address'] ?? '') ?>" placeholder="Enter school address...">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>Citation / Diploma Presented</label>
                                    <input type="text" name="elem_citation" value="<?= htmlspecialchars($sf10_data['elem_citation'] ?? 'Elementary School Diploma') ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>Other (Specify):</label>
                                    <input type="text" name="elem_others" value="<?= htmlspecialchars($sf10_data['elem_others'] ?? '') ?>" placeholder="Any other credentials...">
                                </div>
                                <div class="form-group">
                                    <label>PEPT Rating (if any)</label>
                                    <input type="text" name="pept_rating" value="<?= htmlspecialchars($sf10_data['pept_rating'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>ALS A&E Rating (if any)</label>
                                    <input type="text" name="ae_rating" value="<?= htmlspecialchars($sf10_data['ae_rating'] ?? '') ?>">
                                </div>
                            </div>
                            <div style="margin-top:40px; display:flex; gap:12px; justify-content:flex-end;">
                                <button type="button" onclick="this.closest('.modal').style.display='none'" class="btn btn-outline">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="timeline">
                    <?php foreach($academic_history as $h): 
                        $sy = $h['school_year'];
                        $sy_grades = $history_data[$sy] ?? [];
                        $total = 0; $count = 0;
                    ?>
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                            <div class="timeline-header">
                                    <h3>Grade <?= $h['grade_level'] ?> - <?= $h['section'] ?></h3>
                                    <div class="sy-badge">SY <?= $sy ?></div>
                                </div>
                                
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width:50%;">Learning Area</th>
                                            <th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th>
                                            <th>Final</th><th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                            usort($sy_grades, function($a, $b) {
                                                $mapeh_list = ['MAPEH', 'Music', 'Arts', 'Physical Education', 'Health', 'P.E.'];
                                                $a_is_m = in_array($a['subject_name'], $mapeh_list);
                                                $b_is_m = in_array($b['subject_name'], $mapeh_list);
                                                
                                                if ($a_is_m && !$b_is_m) return 1;
                                                if (!$a_is_m && $b_is_m) return -1;
                                                
                                                if ($a_is_m && $b_is_m) {
                                                    if ($a['subject_name'] === 'MAPEH') return -1;
                                                    if ($b['subject_name'] === 'MAPEH') return 1;
                                                }
                                                return strcmp($a['subject_name'], $b['subject_name']);
                                            });

                                            foreach($sy_grades as $g): 
                                                if($g['final_grade']) { $total += $g['final_grade']; $count++; }
                                                $is_comp = in_array($g['subject_name'], ['Music', 'Arts', 'Physical Education', 'Health', 'P.E.']);
                                        ?>
                                            <tr <?= $is_comp ? 'style="background: #fcfdfe; font-size: 0.95em;"' : ($g['subject_name'] === 'MAPEH' ? 'style="background: #f8fafc;"' : '') ?>>
                                                <td style="font-weight:<?= $g['subject_name'] === 'MAPEH' ? '800' : '600' ?>; padding-left: <?= $is_comp ? '40px' : '16px' ?>;">
                                                    <?= htmlspecialchars($g['subject_name']) ?>
                                                </td>
                                                <td style="text-align:center;"><?= round($g['q1']) ?></td>
                                                <td style="text-align:center;"><?= round($g['q2']) ?></td>
                                                <td style="text-align:center;"><?= round($g['q3']) ?></td>
                                                <td style="text-align:center;"><?= round($g['q4']) ?></td>
                                                <td style="font-weight:800; color:var(--accent); text-align:center; font-size:14px;"><?= round($g['final_grade']) ?></td>
                                                <td style="text-align:center;"><span class="remarks-badge <?= $g['final_grade'] >= 75 ? 'passed' : 'failed' ?>"><?= $g['final_grade'] >= 75 ? 'PASSED' : 'FAILED' ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if($count > 0): 
                                            $gen_avg = round($total / $count);
                                        ?>
                                            <tr class="avg-row">
                                                <td colspan="5" style="text-align:right; font-size:11px; letter-spacing:0.05em; font-weight:700;">GENERAL AVERAGE</td>
                                                <td style="text-align:center; font-size:16px;"><?= $gen_avg ?></td>
                                                <td style="text-align:center;"><span class="remarks-badge <?= $gen_avg >= 75 ? 'passed' : 'failed' ?>"><?= $gen_avg >= 75 ? 'PASSED' : 'FAILED' ?></span></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                                <!-- Attendance Mini-Table -->
                                <?php 
                                    $att = array_filter($attendance_history, function($a) use ($sy) { return $a['school_year'] == $sy; });
                                    $att = reset($att);
                                    if($att):
                                ?>
                                    <div class="attendance-summary">
                                        <div class="att-item">
                                            <div class="att-label">Days of School</div>
                                            <div class="att-value"><?= $att['total_days'] ?? 200 ?></div>
                                        </div>
                                        <div class="att-item">
                                            <div class="att-label">Days Present</div>
                                            <div class="att-value" style="color:var(--success);"><?= $att['days_present'] ?? 0 ?></div>
                                        </div>
                                        <div class="att-item">
                                            <div class="att-label">Days Absent</div>
                                            <div class="att-value" style="color:var(--danger);"><?= $att['days_absent'] ?? 0 ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Awards & Honors -->
                                <?php 
                                    $sy_awards = array_filter($awards, function($a) use ($sy) { return $a['school_year'] == $sy; });
                                    if(!empty($sy_awards)):
                                ?>
                                    <div style="margin-top:20px; display:flex; gap:8px; flex-wrap:wrap;">
                                        <?php foreach($sy_awards as $aw): ?>
                                            <div class="meta-tag" style="background:#fef3c7; color:#92400e; border-color:#fde68a;">
                                                <i class="fa fa-trophy"></i> <?= htmlspecialchars($aw['award_name']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="glass-card empty-state">
                    <i class="fa fa-user-graduate"></i>
                    <h2>No Learner Selected</h2>
                    <p>Please select a student from the left sidebar to view their full academic timeline and permanent record.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function toggleInternalSidebar() {
            document.querySelector('.main-content').classList.toggle('sidebar-collapsed');
        }

        function filterLearners(query) {
            const list = document.getElementById('learnerList');
            const items = list.getElementsByClassName('learner-item');
            query = query.toLowerCase();
            
            for(let i=0; i<items.length; i++) {
                const name = items[i].getElementsByTagName('h4')[0].innerText.toLowerCase();
                const lrn = items[i].getElementsByTagName('p')[0].innerText.toLowerCase();
                if(name.includes(query) || lrn.includes(query)) {
                    items[i].style.display = 'flex';
                } else {
                    items[i].style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>