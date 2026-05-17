<?php
/**
 * SF8 Reporting Module - Premium UI Overhaul
 * Featuring Role-Based Workflow, Responsive Sidebar, and Stealth Inline Editing
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/sf8_engine.php';

try {
    $pdo = db_connect();
    initialize_schema($pdo);
    auth_require_role(['admin', 'registrar', 'teacher', 'principal']);
    
    // Fetch Settings for Header & Signatures
    $settings = [
        'school_id' => '300750', 
        'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
        'region' => 'REGION III', 
        'division' => 'MALOLOS CITY', 
        'district' => 'MALOLOS SOUTH',
        'school_head' => '', 
        'registrar_name' => ''
    ];
    
    $keys = ['region', 'division', 'district', 'school_name', 'school_id', 'signatory_registrar', 'sf_region', 'sf_division'];
    $placeholders = str_repeat('?,', count($keys) - 1) . '?';
    $stmt_set = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt_set->execute($keys);
    
    $db_settings = [];
    while ($s = $stmt_set->fetch()) {
        $db_settings[$s['setting_key']] = $s['setting_value'];
    }

    foreach (['school_id', 'school_name', 'district'] as $k) {
        if (!empty($db_settings[$k])) $settings[$k] = $db_settings[$k];
    }

    $settings['region'] = $db_settings['sf_region'] ?? $db_settings['region'] ?? 'REGION III';
    $settings['division'] = $db_settings['sf_division'] ?? $db_settings['division'] ?? 'MALOLOS CITY';
    $settings['school_head'] = get_system_setting($pdo, 'principal_name', 'DR. MARIA SANTOS');
    $settings['registrar_name'] = $db_settings['signatory_registrar'] ?? 'MS. ANA CRUZ';

    $user_role = auth_role();
    $user_id = $_SESSION['user']['id'];
    
    $is_nurse = ($user_role === 'employee' || $user_role === 'nurse'); // 'employee' is often used for non-teaching staff like nurse
    $is_teacher = ($user_role === 'teacher');
    $is_admin = in_array($user_role, ['admin', 'registrar']);
    $is_principal = ($user_role === 'principal' || $user_role === 'admin');

    $school_year = $_GET['school_year'] ?? get_active_school_year($pdo);
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';
    
    // Initialize data containers to avoid undefined variable errors
    $students = [];
    $males = [];
    $females = [];
    $report = null;
    $status = 'Draft';

    // Fetch Advisory Assignment if user is a teacher
    $advisory_grade = '';
    $advisory_section = '';
    if ($user_role === 'teacher') {
        $stmt_adv = $pdo->prepare("SELECT grade_level, section_name FROM sections WHERE adviser_id = ? AND school_year = ? LIMIT 1");
        $stmt_adv->execute([$user_id, $school_year]);
        $adv = $stmt_adv->fetch();
        if ($adv) {
            $advisory_grade = $adv['grade_level'];
            $advisory_section = $adv['section_name'];
        }
        
        // Lock teacher to their advisory if no filter is set
        if (empty($grade_level)) $grade_level = $advisory_grade;
        if (empty($section)) $section = $advisory_section;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($school_year && $grade_level && $section) {
        // Fetch Students
        $students = getSF8Data($pdo, $school_year, $grade_level, $section);
        
        // Filter students for summary calculation
        $males = array_filter($students, fn($s) => strtoupper(substr($s['sex']??'M',0,1)) !== 'F');
        $females = array_filter($students, fn($s) => strtoupper(substr($s['sex']??'M',0,1)) === 'F');
        
        // Manage Report State
        $stmt = $pdo->prepare("SELECT * FROM sf8_reports WHERE school_year = ? AND grade_level = ? AND section = ?");
        $stmt->execute([$school_year, $grade_level, $section]);
        $report = $stmt->fetch();
        
        if (!$report && ($is_nurse || $is_teacher || $is_admin)) {
            $pdo->prepare("INSERT IGNORE INTO sf8_reports (school_year, grade_level, section, status) VALUES (?, ?, ?, 'Draft')")
                ->execute([$school_year, $grade_level, $section]);
            $stmt->execute([$school_year, $grade_level, $section]);
            $report = $stmt->fetch();
        }
        if ($report) $status = $report['status'];
    }

    $status = $report['status'] ?? 'Draft';
    
    // Permission Logic
    $can_edit = false;
    if ($status !== 'Finalized') {
        if ($is_admin || $is_nurse) {
            $can_edit = true;
        } elseif ($is_teacher && $status === 'Draft') {
            $can_edit = true;
        }
    }

    // Handle Workflow Actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
        $r_id = $report['id'];
        if ($action === 'save_health' && $can_edit) {
            saveHealthRecord($pdo, $_POST['student_id'], $school_year, $_POST['weight'], $_POST['height'], $_POST['hfa'], $_POST['condition_remarks'] ?? '');
            header("Location: sf8.php?school_year=$school_year&grade_level=$grade_level&section=$section&success=Health record updated.");
            exit;
        }
        
        if ($action === 'submit_validation' && $status === 'Draft' && ($is_teacher || $is_nurse || $is_admin)) {
            // Validation: All learners must have height & weight
            $incomplete = array_filter($students, fn($s) => empty($s['weight_kg']) || empty($s['height_m']));
            if (!empty($incomplete)) {
                header("Location: sf8.php?school_year=$school_year&grade_level=$grade_level&section=$section&error=Please complete all health records (height & weight) before submission.");
                exit;
            }
            $pdo->prepare("UPDATE sf8_reports SET status = 'For Validation', submitted_by = ?, submitted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id, $r_id]);
            header("Location: sf8.php?school_year=$school_year&grade_level=$grade_level&section=$section&success=Report submitted for validation.");
            exit;
        }
        
        if ($action === 'approve_validation' && $status === 'For Validation' && $is_admin) {
            $pdo->prepare("UPDATE sf8_reports SET status = 'Validated', validated_by = ?, validated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id, $r_id]);
            header("Location: sf8.php?school_year=$school_year&grade_level=$grade_level&section=$section&success=Report validated.");
            exit;
        }
        
        if ($action === 'finalize' && $status === 'Validated' && $is_principal) {
            $pdo->prepare("UPDATE sf8_reports SET status = 'Finalized', finalized_by = ?, finalized_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id, $r_id]);
            header("Location: sf8.php?school_year=$school_year&grade_level=$grade_level&section=$section&success=Report finalized and locked.");
            exit;
        }
        
        if ($action === 'revert' && $status !== 'Finalized' && $is_admin) {
            $pdo->prepare("UPDATE sf8_reports SET status = 'Draft' WHERE id = ?")->execute([$r_id]);
            header("Location: sf8.php?school_year=$school_year&grade_level=$grade_level&section=$section&success=Report reverted to Draft.");
            exit;
        }
    }
} catch (Exception $e) { die("UI Fix Error: " . $e->getMessage()); }

// All data logic moved to sf8_engine.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF8 Health Dashboard | Premium System</title>
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1; --primary-light: #eef2ff; --primary-hover: #4f46e5;
            --bg: #f8fafc; --surface: #ffffff; --text-main: #0f172a; --text-muted: #475569;
            --border: #e2e8f0; --glass: rgba(255, 255, 255, 0.8);
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; -webkit-font-smoothing: antialiased; }
        .main-content { margin-left: 280px; padding: 40px; min-height: 100vh; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 100px 16px 40px; }
            .banner { padding: 30px 20px; border-radius: 24px; flex-direction: column; text-align: center; gap: 20px; }
            .banner h1 { font-size: 28px; }
            .glass-card { padding: 20px 15px; border-radius: 20px; }
            .detail-grid { grid-template-columns: 1fr; gap: 16px; }
            .modal-content { padding: 25px 15px; width: 95%; margin: 10% auto; border-radius: 24px; }
        }

        /* Premium Glass Cards */
        .glass-card {
            background: var(--glass); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.4); border-radius: 24px; box-shadow: var(--shadow-lg);
            padding: 32px; margin-bottom: 32px; transition: transform 0.3s ease;
        }

        /* Banner Design */
        .banner {
            display: flex; justify-content: space-between; align-items: center;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: white;
            padding: 40px; border-radius: 32px; margin-bottom: 40px;
            box-shadow: 0 25px 50px -12px rgba(79, 70, 229, 0.4);
            position: relative; overflow: hidden;
        }
        .banner::before {
            content: ''; position: absolute; top: -50%; left: -20%; width: 60%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            transform: rotate(35deg); pointer-events: none;
        }
        .banner h1 { margin: 0; font-size: 36px; font-weight: 800; letter-spacing: -0.03em; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }

        /* Search & Filter Inputs */
        .search-input {
            width: 100%; padding: 14px 20px; border-radius: 16px; border: 1px solid var(--border);
            font-size: 15px; font-weight: 600; background: var(--surface); color: var(--text-main);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: var(--shadow-sm);
        }
        .search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15); outline: none; transform: translateY(-1px); }

        /* Professional Table with Sticky Header */
        .table-container { 
            background: var(--surface); border-radius: 32px; border: 1px solid var(--border); 
            box-shadow: var(--shadow-lg); transition: all 0.3s; position: relative;
            max-height: 800px; overflow: auto;
        }
        .sf8-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .sf8-table thead { position: sticky; top: 0; z-index: 10; }
        .sf8-table th { 
            background: #f8fafc; padding: 20px 16px; text-align: left; 
            font-size: 12px; font-weight: 800; color: #1e293b; 
            text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 2px solid var(--border);
            backdrop-filter: blur(8px);
        }
        .sf8-table td { padding: 18px 16px; border-bottom: 1px solid var(--border); font-size: 14px; font-weight: 600; transition: background 0.2s; }
        .student-row { animation: fadeIn 0.5s ease forwards; opacity: 0; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .student-row:hover td { background: #f8fafc; }
        .student-row:hover .data-input { border-color: #cbd5e1; }

        /* Advanced Data Inputs */
        .data-input {
            width: 100%; padding: 10px; border-radius: 12px; border: 1.5px solid #f1f5f9; 
            background: #f8fafc; font-size: 14px; font-weight: 700; text-align: center;
            transition: all 0.2s ease;
        }
        .data-input:focus { border-color: var(--primary); background: #ffffff; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); outline: none; }

        /* Premium Buttons */
        .btn {
            padding: 14px 28px; border-radius: 16px; font-weight: 700; font-size: 14px;
            cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #000000; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); }
        .btn-white { background: rgba(255,255,255,0.15); color: white; backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.25); }
        .btn-white:hover { background: white; color: var(--primary); transform: translateY(-2px); box-shadow: 0 15px 30px -10px rgba(0,0,0,0.2); }
        .btn-update { background: #e0e7ff; color: #4338ca; padding: 8px 16px; border-radius: 10px; font-size: 12px; border: 1px solid transparent; }
        .btn-update:hover { background: #4338ca; color: white; transform: translateY(-1px); }

        /* Segmented Control Tabs */
        .tab-container { 
            display: flex; gap: 4px; margin-bottom: 32px; background: #e2e8f0; padding: 4px; 
            border-radius: 18px; width: fit-content; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .tab-btn {
            padding: 10px 24px; border-radius: 14px; font-weight: 700; font-size: 13px;
            cursor: pointer; background: transparent; border: none; color: #64748b;
            transition: all 0.2s;
        }
        .tab-btn.active { background: white; color: #0f172a; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .tab-btn:hover:not(.active) { color: #0f172a; background: rgba(255,255,255,0.5); }

        /* Health Badges */
        .status-badge {
            padding: 6px 12px; border-radius: 10px; font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.02em; display: inline-flex; align-items: center; gap: 6px;
        }
        .badge-normal { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #ffedd5; color: #9a3412; }
        .badge-danger { background: #fee2e2; color: #991b1b; }

        /* Stat Cards */
        .card-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 40px; }
        .stat-card {
            background: white; padding: 32px; border-radius: 32px; border: 1px solid var(--border);
            box-shadow: var(--shadow-md); transition: all 0.3s; position: relative;
        }
        .stat-card:hover { transform: translateY(-8px); box-shadow: var(--shadow-lg); border-color: var(--primary); }
        .stat-label { font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; }
        .stat-value { font-size: 36px; font-weight: 900; color: #0f172a; letter-spacing: -0.02em; }
        /* Mobile Adjustments */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding: 24px; }
            .banner { flex-direction: column; text-align: center; gap: 32px; padding: 32px; }
            .card-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) { .card-grid { grid-template-columns: 1fr; } }

        /* Timeline Audit */
        .timeline { position: relative; padding-left: 32px; border-left: 2px solid var(--border); margin-left: 8px; }
        .timeline-item { position: relative; margin-bottom: 24px; }
        .timeline-item::before {
            content: ''; position: absolute; left: -41px; top: 4px; width: 16px; height: 16px;
            border-radius: 50%; background: white; border: 3px solid var(--primary);
        }
        .timeline-date { font-size: 10px; color: var(--text-muted); font-weight: 700; margin-bottom: 4px; }
        .timeline-text { font-size: 12px; color: var(--text-main); font-weight: 600; }

        /* Advanced Modal */
        .modal { display: none; position: fixed; z-index: 10001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); backdrop-filter: blur(8px); }
        .modal-content { 
            background: white; margin: 4% auto; padding: 40px; border-radius: 32px; width: 90%; max-width: 800px; 
            box-shadow: 0 50px 100px -20px rgba(0,0,0,0.3); position: relative; animation: modalSlideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        @keyframes modalSlideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .close-modal { position: absolute; right: 30px; top: 25px; font-size: 28px; cursor: pointer; color: var(--text-muted); transition: color 0.2s; }
        .close-modal:hover { color: var(--text-main); }
        
        .detail-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-top: 32px; }
        .detail-item { background: #f8fafc; padding: 16px; border-radius: 16px; border: 1px solid var(--border); }
        .detail-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; font-weight: 800; margin-bottom: 6px; letter-spacing: 0.05em; }
        .detail-value { font-size: 14px; color: var(--text-main); font-weight: 700; }

        /* Profile Summary Table */
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 24px; border: 1px solid var(--border); border-radius: 20px; overflow: hidden; }
        .summary-table th, .summary-table td { border: 1px solid var(--border); padding: 14px; text-align: center; font-size: 12px; }
        .summary-table th { background: #f8fafc; font-weight: 800; color: var(--text-muted); text-transform: uppercase; }
        .summary-table td.bold { font-weight: 800; color: var(--text-main); }
        .summary-table .thick-left { border-left: 3px solid var(--primary); }

        /* Tab Content Control */
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: tabFadeIn 0.4s ease; }
        @keyframes tabFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Hide Spinners */
        input::-webkit-outer-spin-button,
        input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../../../header.php'; ?>
    <?php include __DIR__ . '/../../admin_sidebar.php'; ?>

    <div class="main-content">
        <!-- Status Feedback -->
        <?php if (isset($_GET['success'])): ?>
            <div class="glass-card" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 16px 24px; display: flex; align-items: center; gap: 12px; margin-bottom: 24px; animation: slideInDown 0.4s ease; border-radius: 20px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                <span style="font-weight: 600;"><?= htmlspecialchars($_GET['success'] === '1' ? 'Changes saved successfully.' : $_GET['success']) ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="glass-card" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 16px 24px; display: flex; align-items: center; gap: 12px; margin-bottom: 24px; animation: shake 0.4s ease; border-radius: 20px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span style="font-weight: 600;"><?= htmlspecialchars($_GET['error']) ?></span>
            </div>
        <?php endif; ?>
        <!-- Banner Header -->
        <div class="banner" style="margin-bottom: 24px; border-radius: 24px; padding: 40px; background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);">
            <div>
                <div style="display:flex; align-items:center; gap:15px;">
                    <h1 style="margin:0; font-size: 32px;">School Form 8 (SF8)</h1>
                    <span class="status-badge" style="background:rgba(255,255,255,0.2); color:white; border:1px solid rgba(255,255,255,0.4); font-size:12px; padding:4px 12px; border-radius:30px;">
                        Status: <?= htmlspecialchars($status) ?>
                    </span>
                </div>
                <p style="margin:12px 0 0; opacity:0.9; font-size: 16px; font-weight:500;">Learner's Basic Health Profile & Workflow Management</p>
            </div>
            <div style="display:flex; align-items:center; gap:20px;">
                <?php if ($school_year && $grade_level && $section): ?>
                <a href="sf8_print.php?school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" target="_blank" class="btn btn-white" style="padding: 12px 24px; border-radius: 14px; font-weight: 700;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:8px;"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                    Print SF8 (Health)
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Workflow Controls -->
        <?php if ($report): ?>
        <div class="glass-card" style="margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; border-left: 6px solid <?= ($status === 'Finalized' ? '#10b981' : ($status === 'Validated' ? '#3b82f6' : ($status === 'For Validation' ? '#f59e0b' : '#64748b'))) ?>;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="background: #f1f5f9; padding: 12px; border-radius: 14px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--text-muted);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div>
                    <div style="font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Report Workflow Status</div>
                    <?php 
                    $b_styles = [
                        'Draft' => 'background:#f1f5f9; color:#475569;',
                        'For Validation' => 'background:#fff7ed; color:#9a3412; border:1px solid #fdba74;',
                        'Validated' => 'background:#eff6ff; color:#1e40af; border:1px solid #93c5fd;',
                        'Finalized' => 'background:#f0fdf4; color:#166534; border:1px solid #86efac;'
                    ];
                    $style = $b_styles[$status] ?? '';
                    ?>
                    <span class="status-badge" style="<?= $style ?> padding:6px 14px; font-size:12px; font-weight:900; border-radius:12px;"><?= strtoupper($status) ?></span>
                </div>
            </div>
            
            <form method="POST" style="display: flex; gap: 12px;">
                <?php if ($status === 'Draft' && ($is_teacher || $is_nurse || $is_admin)): ?>
                    <button type="submit" name="action" value="submit_validation" class="btn" style="background:#f59e0b; color:white; padding:12px 24px; border-radius:14px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                        Submit for Validation
                    </button>
                <?php endif; ?>
                
                <?php if ($status === 'For Validation' && $is_admin): ?>
                    <button type="submit" name="action" value="approve_validation" class="btn" style="background:#3b82f6; color:white; padding:12px 24px; border-radius:14px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Approve & Validate
                    </button>
                    <button type="submit" name="action" value="revert" class="btn" style="background:#f1f5f9; color:#ef4444; border:1.5px solid #fee2e2; padding:12px 24px; border-radius:14px;">
                        Return to Draft
                    </button>
                <?php endif; ?>
                
                <?php if ($status === 'Validated' && $is_principal): ?>
                    <button type="submit" name="action" value="finalize" class="btn" style="background:#10b981; color:white; padding:12px 24px; border-radius:14px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Final Approval & Lock
                    </button>
                    <?php if ($is_admin): ?>
                        <button type="submit" name="action" value="revert" class="btn" style="background:#f1f5f9; color:#ef4444; border:1.5px solid #fee2e2; padding:12px 24px; border-radius:14px;">
                            Revert to Draft
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($status === 'Finalized'): ?>
                    <div style="color: #166534; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; background: #f0fdf4; padding: 12px 20px; border-radius: 14px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                        Report Certified & Locked
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <!-- Dashboard Stats -->
        <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:20px; margin-bottom:24px;">
            <?php 
            $stats = computeSF8Summary($males, $females); 
            $total_std = count($students);
            ?>
            <div class="glass-card" style="padding:20px; border-left:4px solid #3b82f6;">
                <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:5px;">Total Learners</div>
                <div style="font-size:24px; font-weight:900; color:var(--primary);"><?= $total_std ?></div>
            </div>
            <div class="glass-card" style="padding:20px; border-left:4px solid #ef4444;">
                <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:5px;">Severely Wasted</div>
                <div style="font-size:24px; font-weight:900; color:#ef4444;"><?= $stats['bmi']['Severely Wasted']['Total'] ?></div>
            </div>
            <div class="glass-card" style="padding:20px; border-left:4px solid #f59e0b;">
                <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:5px;">Stunted (HFA)</div>
                <div style="font-size:24px; font-weight:900; color:#f59e0b;"><?= $stats['hfa']['Stunted']['Total'] ?></div>
            </div>
            <div class="glass-card" style="padding:20px; border-left:4px solid #10b981;">
                <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:5px;">Normal (Health)</div>
                <div style="font-size:24px; font-weight:900; color:#10b981;"><?= $stats['bmi']['Normal']['Total'] ?></div>
            </div>
        </div>

        <!-- Filters & Search -->
        <div class="glass-card" style="margin-bottom:24px; padding: 24px; display: block !important;" id="filters">
            <div style="font-size: 13px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Filter Report Parameters
            </div>
            <form method="GET" style="display:grid; grid-template-columns: repeat(4, 1fr); gap:20px; align-items:flex-end;">
                <div>
                    <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">School Year</label>
                    <select name="school_year" class="search-input" style="padding:14px; margin-top:8px; width:100%;" required>
                        <?php 
                        $sys = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN); 
                        foreach($sys as $sy) echo "<option value='$sy' ".($school_year==$sy?'selected':'').">$sy</option>"; 
                        ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Grade Level</label>
                    <select name="grade_level" class="search-input" style="padding:14px; margin-top:8px; width:100%;" required onchange="this.form.submit()" <?= $user_role === 'teacher' ? 'readonly disabled' : '' ?>>
                        <option value="">Select Grade</option>
                        <?php for($i=7;$i<=12;$i++) echo "<option value='Grade $i' ".($grade_level=="Grade $i"?'selected':'').">Grade $i</option>"; ?>
                    </select>
                    <?php if ($user_role === 'teacher'): ?><input type="hidden" name="grade_level" value="<?= htmlspecialchars($grade_level) ?>"><?php endif; ?>
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Section</label>
                    <?php if ($user_role === 'teacher'): ?>
                        <input type="text" name="section" value="<?= htmlspecialchars($section) ?>" class="search-input" style="padding:14px; margin-top:8px; width:100%;" required readonly>
                    <?php else: ?>
                        <select name="section" class="search-input" style="padding:14px; margin-top:8px; width:100%;" required>
                            <option value="">Select Section</option>
                            <?php 
                            $grade_num_ui = trim(str_ireplace('Grade', '', $grade_level));
                            $grade_full_ui = 'Grade ' . $grade_num_ui;
                            
                            $all_sections = [];
                            if ($grade_level) {
                                // 1. From Sections Table
                                $sec_stmt = $pdo->prepare("SELECT DISTINCT section_name FROM sections WHERE (grade_level = ? OR grade_level = ?) AND school_year = ?");
                                $sec_stmt->execute([$grade_full_ui, $grade_num_ui, $school_year]);
                                $all_sections = array_merge($all_sections, $sec_stmt->fetchAll(PDO::FETCH_COLUMN));
                                
                                // 2. From Enrollments (Backup)
                                $enr_stmt = $pdo->prepare("SELECT DISTINCT section FROM enrollments WHERE (grade_level = ? OR grade_level = ?) AND school_year = ?");
                                $enr_stmt->execute([$grade_full_ui, $grade_num_ui, $school_year]);
                                $all_sections = array_merge($all_sections, $enr_stmt->fetchAll(PDO::FETCH_COLUMN));
                            } else {
                                // Show all sections for the school year if no grade is selected
                                $all_sections = $pdo->prepare("SELECT DISTINCT section_name FROM sections WHERE school_year = ?");
                                $all_sections->execute([$school_year]);
                                $all_sections = $all_sections->fetchAll(PDO::FETCH_COLUMN);
                            }
                            
                            $all_sections = array_unique(array_filter($all_sections));
                            sort($all_sections);
                            foreach ($all_sections as $s_opt) {
                                echo "<option value='" . htmlspecialchars($s_opt) . "' ".($section==$s_opt?'selected':'').">" . htmlspecialchars($s_opt) . "</option>";
                            }
                            ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn" style="background:var(--primary); color:white; padding:14px; border-radius:14px; font-weight:700; width:100%;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:8px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Generate Report
                    </button>
                </div>
            </form>
        </div>

        <?php if (empty($students) && $school_year && $grade_level && $section): ?>
            <div class="glass-card" style="background: #fff1f2; border-color: #fecaca; color: #991b1b; display: flex; align-items: center; gap: 16px; margin-bottom: 24px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span style="font-weight: 700;">No data found. Please check your filters or Sync Students.</span>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($students)): ?>
            <?php $sum = computeSF8Summary($males, $females); ?>
            <div class="card-grid" style="margin-bottom:32px;">
                <div class="stat-card">
                    <div class="stat-label">Total Learners</div>
                    <div class="stat-value"><?= count($students) ?></div>
                    <div class="stat-trend" style="color:var(--primary);">Registry Sync Active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Nutritional Alert</div>
                    <div class="stat-value" style="color:#ef4444;"><?= ($sum['bmi']['Wasted']['Total'] + $sum['bmi']['Severely Wasted']['Total']) ?></div>
                    <div class="stat-trend" style="color:#ef4444;">Wasted / Sev. Wasted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Medical Coverage</div>
                    <div class="stat-value" style="color:#10b981;"><?= round(($sum['medical']['dewormed'] / max(1, count($students))) * 100) ?>%</div>
                    <div class="stat-trend" style="color:#10b981;">Deworming Rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Vision Screening</div>
                    <div class="stat-value" style="color:#6366f1;"><?= $sum['medical']['vision_pass'] ?></div>
                    <div class="stat-trend" style="color:#6366f1;">Learners Passed</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($students)): ?>
            <div class="search-container">
                <span class="search-icon">🔍</span>
                <input type="text" id="studentSearch" class="search-input" placeholder="Identify Learner by Name or LRN..." onkeyup="filterStudents()">
            </div>

            <!-- Tab Navigation -->
            <div class="tab-container">
                <button class="tab-btn active" onclick="switchTab('health', this)">Health Dashboard</button>
                <button class="tab-btn" onclick="switchTab('profiles', this)">Section Profile Summary</button>
            </div>

            <!-- Health Dashboard Tab -->
            <div id="health_tab" class="tab-content active">
                <div class="table-container">
                    <table class="sf8-table">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width: 25px;">No.</th>
                                <th rowspan="2" style="width: 100px;">LRN</th>
                                <th rowspan="2" style="width: 180px;">Learner's Name</th>
                                <th rowspan="2" style="width: 80px;">Birthdate</th>
                                <th rowspan="2" style="width: 30px;">Age</th>
                                <th rowspan="2" style="width: 60px;">Weight (kg)</th>
                                <th rowspan="2" style="width: 60px;">Height (m)</th>
                                <th colspan="2">Nutritional Status</th>
                                <th rowspan="2" style="width: 100px;">Height for Age (HFA)</th>
                                <th style="width: 120px;">Remarks</th>
                                <th style="text-align:right">Action</th>
                            </tr>
                            <tr>
                                <th style="width: 60px;">BMI</th>
                                <th style="width: 100px;">Category</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // $males and $females now filtered at the top
                            
                            $render_rows = function($list, $label) use (&$n, $can_edit) {
                                if (empty($list)) return;
                                echo "<tr><td colspan='13' style='background:#f1f5f9; font-weight:800; text-align:left; padding:8px 15px; color:#1e293b; border-left:4px solid #1e293b; font-size:11px;'>$label</td></tr>";
                                foreach ($list as $s): ?>
                                    <tr class="student-row" data-student-id="<?= $s['student_id'] ?>">
                                        <td><?= $n++ ?></td>
                                        <td style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($s['lrn'] ?: '---') ?></td>
                                        <td style="text-align:left; font-weight:600;"><?= htmlspecialchars($s['formatted_name']) ?></td>
                                        <td><?= !empty($s['birthdate']) ? date('m/d/y', strtotime($s['birthdate'])) : '---' ?></td>
                                        <td style="font-weight:700;"><?= $s['age'] ?></td>

                                        <?php if ($can_edit): ?>
                                            <td style="width:70px;"><input type="number" step="0.1" name="weight" value="<?= $s['weight_kg'] ?>" class="data-input weight-in" oninput="calcBMI(this)"></td>
                                            <td style="width:70px;"><input type="number" step="0.01" name="height" value="<?= $s['height_m'] ?>" class="data-input height-in" oninput="calcBMI(this)"></td>
                                            <td class="bmi-val" style="font-weight:800; color:var(--primary);"><?= $s['bmi'] ?: '---' ?></td>
                                            <td>
                                                <?php 
                                                $ns = $s['nutritional_status'] ?: '---';
                                                $b_cls = 'badge-normal';
                                                if (in_array($ns, ['Wasted', 'Overweight'])) $b_cls = 'badge-warning';
                                                if (in_array($ns, ['Severely Wasted', 'Obese'])) $b_cls = 'badge-danger';
                                                if ($ns === '---') $b_cls = '';
                                                ?>
                                                <span class="status-badge bmi-cat <?= $b_cls ?>"><?= $ns ?></span>
                                            </td>
                                            <td style="width:120px;">
                                                <?php 
                                                $norms = [
                                                    12 => ['M' => [1.60, 1.40], 'F' => [1.57, 1.40]],
                                                    13 => ['M' => [1.65, 1.45], 'F' => [1.60, 1.43]],
                                                    14 => ['M' => [1.70, 1.50], 'F' => [1.62, 1.45]],
                                                    15 => ['M' => [1.75, 1.55], 'F' => [1.65, 1.48]],
                                                    16 => ['M' => [1.78, 1.60], 'F' => [1.67, 1.50]],
                                                    17 => ['M' => [1.80, 1.62], 'F' => [1.68, 1.52]],
                                                    18 => ['M' => [1.81, 1.63], 'F' => [1.69, 1.53]]
                                                ];
                                                $h_m = floatval($s['height_m']);
                                                $age_v = intval($s['age']);
                                                $sex_v = strtoupper(substr($s['sex']??'M',0,1)) !== 'F' ? 'M' : 'F';
                                                $hfa_txt = 'Normal';
                                                $z_val = 0;
                                                $h_cls = 'badge-normal';
                                                
                                                if ($h_m > 0) {
                                                    $lookup = $age_v < 12 ? 12 : ($age_v > 18 ? 18 : $age_v);
                                                    $d = $norms[$lookup][$sex_v];
                                                    $med = ($d[0] + $d[1]) / 2;
                                                    $sd_v = ($d[0] - $med) / 2;
                                                    $z_val = ($h_m - $med) / $sd_v;
                                                    
                                                    if ($z_val < -3) { $hfa_txt = 'Severely Stunted'; $h_cls = 'badge-danger'; }
                                                    else if ($z_val < -2) { $hfa_txt = 'Stunted'; $h_cls = 'badge-warning'; }
                                                    else if ($z_val > 2) { $hfa_txt = 'Tall'; $h_cls = 'badge-warning'; }
                                                }
                                                ?>
                                                <span class="status-badge hfa-badge <?= $h_cls ?>" style="font-size:9px; white-space:nowrap;">
                                                    <?= $hfa_txt ?> <?= ($h_m > 0) ? "(".($z_val > 0 ? '+' : '').round($z_val,1).")" : '' ?>
                                                </span>
                                                <input type="hidden" name="hfa" class="hfa-in" value="<?= $hfa_txt ?>" data-is-auto="true">
                                                <input type="hidden" class="student-sex" value="<?= $s['sex'] ?>">
                                                <input type="hidden" class="student-age" value="<?= $s['age'] ?>">
                                            </td>
                                            <td style="width:150px;"><input type="text" name="condition_remarks" value="<?= htmlspecialchars($s['condition_remarks'] ?? '') ?>" class="data-input remarks-in" placeholder="..." style="font-size:9px; text-align:left;"></td>
                                            <td style="text-align:right"><button type="button" onclick="debouncedSave(this.closest('tr'), true)" class="btn btn-update" style="padding:5px 10px; font-size:10px; font-weight:700;">Save</button></td>
                                        <?php else: ?>
                                            <td><?= $s['weight_kg'] ?: '---' ?></td>
                                            <td><?= $s['height_m'] ?: '---' ?></td>
                                            <td><?= $s['height_sq'] ?: '---' ?></td>
                                            <td style="font-weight:800; color:var(--primary);"><?= $s['bmi'] ?: '---' ?></td>
                                            <td><?= $s['nutritional_status'] ?: '---' ?></td>
                                            <td><?= $s['hfa'] ?></td>
                                            <td><?= htmlspecialchars($s['condition_remarks'] ?: '---') ?></td>
                                            <td style="text-align:right; color:var(--text-muted);">🔒</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach;
                            };
                            
                            $n=1;
                            $render_rows($males, 'MALE');
                            $render_rows($females, 'FEMALE');
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Profile Summary Tab -->
            <div id="profiles_tab" class="tab-content">
                <div class="table-container" style="overflow-x:auto;">
                    <table class="sf8-table" style="min-width:1400px;">
                        <thead>
                            <tr>
                                <th>LRN</th>
                                <th>Full Name</th>
                                <th>Sex</th>
                                <th>Age</th>
                                <th>BMI</th>
                                <th>HFA</th>
                                <th>Birthdate</th>
                                <th>Mother Tongue</th>
                                <th>IP (Ethnic)</th>
                                <th>4Ps</th>
                                <th>Father's Name</th>
                                <th>Mother's Name</th>
                                <th>Guardian</th>
                                <th>Contact</th>
                                <th>Full Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($students as $s): ?>
                                <tr class="student-row">
                                    <td style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($s['student_id']) ?></td>
                                    <td style="font-weight:700; color:var(--text-main);"><?= htmlspecialchars($s['formatted_name']) ?></td>
                                    <td><?= strtoupper(substr($s['sex']??'M',0,1)) ?></td>
                                    <td style="font-weight:700;"><?= $s['age'] ?></td>
                                    <td style="color:var(--primary); font-weight:800;"><?= $s['bmi'] ?: '---' ?></td>
                                    <td><span class="status-badge" style="font-size:9px;"><?= $s['hfa'] ?: 'Normal' ?></span></td>
                                    <td><?= $s['birthdate'] ?></td>
                                    <td><?= htmlspecialchars($s['mother_tongue'] ?: 'N/A') ?></td>
                                    <td><?= htmlspecialchars($s['ip_status']) ?></td>
                                    <td><span class="status-badge" style="background:<?= $s['is_4ps_beneficiary']?'#dcfce7':'#f1f5f9' ?>; color:<?= $s['is_4ps_beneficiary']?'#166534':'#475569' ?>; font-size:9px;"><?= $s['is_4ps_beneficiary']?'Yes':'No' ?></span></td>
                                    <td><?= htmlspecialchars($s['father_name']) ?></td>
                                    <td><?= htmlspecialchars($s['mother_name']) ?></td>
                                    <td><?= htmlspecialchars(($s['guardian_first'] ?? '') . ' ' . ($s['guardian_last'] ?? '')) ?></td>
                                    <td style="font-weight:600; color:var(--primary);"><?= htmlspecialchars($s['guardian_contact'] ?: 'N/A') ?></td>
                                    <td style="font-size:10px; max-width:200px;"><?= htmlspecialchars($s['full_address'] ?: 'Not Set') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- SF8 Official Summary Table (Replicated from Print) -->
                <div class="glass-card" style="margin-top:40px; padding:30px; overflow-x:auto;">
                    <h3 style="margin-bottom:20px; color:var(--primary); display:flex; align-items:center; gap:10px;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                        Official SF8 Summary Tables
                    </h3>
                    
                    <?php // $sum already computed at the top ?>
                    <style>
                        .summary-table { width:100%; border-collapse:collapse; font-size:11px; margin-bottom:30px; }
                        .summary-table th, .summary-table td { border:1px solid #e2e8f0; padding:10px; text-align:center; }
                        .summary-table th { background:#f8fafc; color:#64748b; font-weight:700; text-transform:uppercase; }
                        .summary-table .row-label { text-align:left; font-weight:700; color:#1e293b; background:#f1f5f9; }
                        .summary-table .bold-val { font-weight:800; color:var(--primary); }
                    </style>

                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width: 100px;">SEX</th>
                                <th colspan="5">Nutritional Status Summary</th>
                                <th rowspan="2" style="width: 80px;">BMI TOTAL</th>
                                <th colspan="4" style="background:#fff7ed;">Height for Age (HFA) Summary</th>
                                <th rowspan="2" style="width: 80px; background:#fff7ed;">HFA TOTAL</th>
                            </tr>
                            <tr>
                                <th>Severely Wasted</th><th>Wasted</th><th>Normal</th><th>Overweight</th><th>Obese</th>
                                <th style="background:#fff7ed;">Severely Stunted</th><th style="background:#fff7ed;">Stunted</th><th style="background:#fff7ed;">Normal</th><th style="background:#fff7ed;">Tall</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="row-label">MALE</td>
                                <?php foreach(['Severely Wasted','Wasted','Normal','Overweight','Obese'] as $cat) echo "<td>{$sum['bmi'][$cat]['M']}</td>"; ?>
                                <td class="bold-val"><?= ($sum['bmi']['Normal']['M'] + $sum['bmi']['Wasted']['M'] + $sum['bmi']['Severely Wasted']['M'] + $sum['bmi']['Overweight']['M'] + $sum['bmi']['Obese']['M']) ?></td>
                                
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Severely Stunted']['M'] ?></td>
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Stunted']['M'] ?></td>
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Normal']['M'] ?></td>
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Tall']['M'] ?></td>
                                <td class="bold-val" style="background:#fff7ed;"><?= array_sum(array_column($sum['hfa'], 'M')) ?></td>
                            </tr>
                            <tr>
                                <td class="row-label">FEMALE</td>
                                <?php foreach(['Severely Wasted','Wasted','Normal','Overweight','Obese'] as $cat) echo "<td>{$sum['bmi'][$cat]['F']}</td>"; ?>
                                <td class="bold-val"><?= ($sum['bmi']['Normal']['F'] + $sum['bmi']['Wasted']['F'] + $sum['bmi']['Severely Wasted']['F'] + $sum['bmi']['Overweight']['F'] + $sum['bmi']['Obese']['F']) ?></td>
                                
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Severely Stunted']['F'] ?></td>
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Stunted']['F'] ?></td>
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Normal']['F'] ?></td>
                                <td style="background:#fff7ed;"><?= $sum['hfa']['Tall']['F'] ?></td>
                                <td class="bold-val" style="background:#fff7ed;"><?= array_sum(array_column($sum['hfa'], 'F')) ?></td>
                            </tr>
                            <tr style="background:#f1f5f9;">
                                <td class="row-label" style="background:#1e293b; color:white;">TOTAL</td>
                                <?php foreach(['Severely Wasted','Wasted','Normal','Overweight','Obese'] as $cat) echo "<td style='font-weight:800;'>{$sum['bmi'][$cat]['Total']}</td>"; ?>
                                <td style="font-weight:900; font-size:14px; color:var(--primary);"><?= array_sum(array_column($sum['bmi'], 'Total')) ?></td>
                                
                                <td style="background:#ffedd5; font-weight:800;"><?= $sum['hfa']['Severely Stunted']['Total'] ?></td>
                                <td style="background:#ffedd5; font-weight:800;"><?= $sum['hfa']['Stunted']['Total'] ?></td>
                                <td style="background:#ffedd5; font-weight:800;"><?= $sum['hfa']['Normal']['Total'] ?></td>
                                <td style="background:#ffedd5; font-weight:800;"><?= $sum['hfa']['Tall']['Total'] ?></td>
                                <td style="background:#ffedd5; font-weight:900; font-size:14px; color:var(--primary);"><?= array_sum(array_column($sum['hfa'], 'Total')) ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="display:flex; justify-content:space-between; margin-top:20px; font-size:11px; color:var(--text-muted);">
                        <div style="border-top:1px solid #cbd5e1; padding-top:10px; width:30%; text-align:center;">Conducted/Assessed By:<br><strong style="color:var(--text-main);"><?= strtoupper($_SESSION['full_name'] ?? 'Class Adviser') ?></strong></div>
                        <div style="border-top:1px solid #cbd5e1; padding-top:10px; width:30%; text-align:center;">Certified Correct By:<br><strong style="color:var(--text-main);"><?= strtoupper($settings['school_head']) ?></strong></div>
                        <div style="border-top:1px solid #cbd5e1; padding-top:10px; width:30%; text-align:center;">Reviewed By:<br><strong style="color:var(--text-main);"><?= strtoupper($settings['registrar_name']) ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- Audit Trail Timeline -->
            <?php if ($report): ?>
            <div class="glass-card" style="margin-top:40px;">
                <h4 style="margin:0 0 24px 0; font-size:14px; font-weight:800; color:var(--text-main); text-transform:uppercase; letter-spacing:0.05em;">Audit Trail & Workflow History</h4>
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-date"><?= date('M d, Y h:i A', strtotime($report['created_at'])) ?></div>
                        <div class="timeline-text">Report Generated (Draft Created)</div>
                    </div>
                    <?php if ($report['submitted_at']): ?>
                        <div class="timeline-item">
                            <div class="timeline-date"><?= date('M d, Y h:i A', strtotime($report['submitted_at'])) ?></div>
                            <div class="timeline-text">Submitted for Validation by <strong><?= $report['submitted_by'] ?></strong></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($report['validated_at']): ?>
                        <div class="timeline-item">
                            <div class="timeline-date"><?= date('M d, Y h:i A', strtotime($report['validated_at'])) ?></div>
                            <div class="timeline-text">Validated and Verified by Admin <strong><?= $report['validated_by'] ?></strong></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($report['finalized_at']): ?>
                        <div class="timeline-item">
                            <div class="timeline-date"><?= date('M d, Y h:i A', strtotime($report['finalized_at'])) ?></div>
                            <div class="timeline-text">Final Approval & Record Locked by School Head <strong><?= $report['finalized_by'] ?></strong></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div style="border-bottom: 2px solid var(--primary); padding-bottom: 10px; margin-bottom: 10px;">
                <h2 id="modal_name" style="margin:0; color:var(--primary);">Learner Details</h2>
                <p id="modal_lrn" style="margin:5px 0 0; color:var(--text-muted); font-weight:600;"></p>
            </div>
            
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Birthdate & Age</div>
                    <div class="detail-value"><span id="modal_bday"></span> (<span id="modal_age"></span> yrs)</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Sex</div>
                    <div class="detail-value" id="modal_sex"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Mother Tongue</div>
                    <div class="detail-value" id="modal_mt"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">IP Status</div>
                    <div class="detail-value" id="modal_ip"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">4Ps Beneficiary</div>
                    <div class="detail-value" id="modal_4ps"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Full Address</div>
                    <div class="detail-value" id="modal_address"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Father's Name</div>
                    <div class="detail-value" id="modal_father"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Mother's Name</div>
                    <div class="detail-value" id="modal_mother"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Guardian</div>
                    <div class="detail-value" id="modal_guardian"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Guardian Contact</div>
                    <div class="detail-value" id="modal_contact"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Vision Screening</div>
                    <div class="detail-value" id="modal_vision"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Dewormed</div>
                    <div class="detail-value" id="modal_dewormed"></div>
                </div>
            </div>
            
            <div style="margin-top:25px; text-align:right;">
                <button class="btn" onclick="closeModal()" style="background:var(--text-muted); color:white;">Close</button>
            </div>
        </div>
    </div>

    <script>
        function filterStudents() {
            const query = document.getElementById('studentSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.student-row');
            rows.forEach(row => { row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none'; });
        }

        function switchTab(tab, btn) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tab + '_tab').classList.add('active');
            if (btn) btn.classList.add('active');
        }

        function showDetails(s) {
            document.getElementById('modal_name').innerText = s.formatted_name;
            document.getElementById('modal_lrn').innerText = "LRN: " + (s.student_id || 'N/A');
            document.getElementById('modal_bday').innerText = s.birthdate;
            document.getElementById('modal_age').innerText = s.age || '---';
            document.getElementById('modal_sex').innerText = s.sex || '---';
            document.getElementById('modal_mt').innerText = s.mother_tongue || 'N/A';
            document.getElementById('modal_ip').innerText = s.ip_status || 'No';
            document.getElementById('modal_4ps').innerText = s.is_4ps_beneficiary ? 'Yes' : 'No';
            document.getElementById('modal_address').innerText = s.full_address || 'Not Set';
            document.getElementById('modal_father').innerText = s.father_name || 'N/A';
            document.getElementById('modal_mother').innerText = s.mother_name || 'N/A';
            document.getElementById('modal_guardian').innerText = (s.guardian_first || '') + ' ' + (s.guardian_last || '');
            document.getElementById('modal_contact').innerText = s.guardian_contact || 'N/A';
            document.getElementById('modal_vision').innerText = s.vision_screening || 'Not Screened';
            document.getElementById('modal_dewormed').innerText = s.is_dewormed ? 'Yes' : 'No';
            
            document.getElementById('detailModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            let modal = document.getElementById('detailModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        function calcBMI(input) {
            const row = input.closest('tr');
            const wInput = row.querySelector('.weight-in');
            const hInput = row.querySelector('.height-in');
            const hfaSelect = row.querySelector('.hfa-in');
            const sex = row.querySelector('.student-sex').value;
            const age = parseInt(row.querySelector('.student-age').value) || 0;
            
            const w = parseFloat(wInput.value);
            let h = parseFloat(hInput.value);
            const bmiCell = row.querySelector('.bmi-val');
            const catCell = row.querySelector('.bmi-cat');

            if (h > 3.0) h = h / 100;

            if (h > 0) {
                // Auto-calculate HFA
                if (hfaSelect.dataset.isAuto === "true" || hfaSelect.value === 'Auto') {
                    const norms = {
                        12: {M:[1.60, 1.40], F:[1.57, 1.40]},
                        13: {M:[1.65, 1.45], F:[1.60, 1.43]},
                        14: {M:[1.70, 1.50], F:[1.62, 1.45]},
                        15: {M:[1.75, 1.55], F:[1.65, 1.48]},
                        16: {M:[1.78, 1.60], F:[1.67, 1.50]},
                        17: {M:[1.80, 1.62], F:[1.68, 1.52]},
                        18: {M:[1.81, 1.63], F:[1.69, 1.53]}
                    };
                    const isM = (sex.toUpperCase().charAt(0) !== 'F') ? 'M' : 'F';
                    const lookup = age < 12 ? 12 : (age > 18 ? 18 : age);
                    const data = norms[lookup][isM];
                    
                    const tallLimit = data[0];
                    const stuntedLimit = data[1];
                    const median = (tallLimit + stuntedLimit) / 2;
                    const sd = (tallLimit - median) / 2;
                    const z = (h - median) / sd;

                    let res = 'Normal';
                    if (z < -3) res = 'Severely Stunted';
                    else if (z < -2) res = 'Stunted';
                    else if (z > 2) res = 'Tall';
                    
                    const badge = row.querySelector('.hfa-badge');
                    badge.innerText = `${res} (${z > 0 ? '+' : ''}${z.toFixed(1)})`;
                    badge.className = 'status-badge hfa-badge';
                    if (res === 'Normal') badge.classList.add('badge-normal');
                    else if (['Stunted', 'Tall'].includes(res)) badge.classList.add('badge-warning');
                    else if (res === 'Severely Stunted') badge.classList.add('badge-danger');

                    hfaSelect.value = res;
                    hfaSelect.dataset.isAuto = "true"; // Mark as auto-calculated
                }

                if (w > 0) {
                    const bmi = parseFloat((w / (h * h)).toFixed(2));
                    bmiCell.innerText = bmi;
                    let cat = '---';
                    if (bmi < 16.0) cat = 'Severely Wasted';
                    else if (bmi < 18.5) cat = 'Wasted';
                    else if (bmi < 25.0) cat = 'Normal';
                    else if (bmi < 30.0) cat = 'Overweight';
                    else cat = 'Obese';
                    catCell.innerText = cat;
                    catCell.className = 'status-badge bmi-cat';
                    if (cat === 'Normal') catCell.classList.add('badge-normal');
                    else if (['Wasted', 'Overweight'].includes(cat)) catCell.classList.add('badge-warning');
                    else if (['Severely Wasted', 'Obese'].includes(cat)) catCell.classList.add('badge-danger');
                }
            } else {
                bmiCell.innerText = '---';
                catCell.innerText = '---';
                catCell.className = 'status-badge bmi-cat';
            }

            // Trigger Auto-Save
            debouncedSave(row);
        }

        let saveTimers = {};
        function debouncedSave(row, force = false) {
            const studentId = row.dataset.studentId;
            if (saveTimers[studentId]) clearTimeout(saveTimers[studentId]);
            
            const btn = row.querySelector('.btn-update');
            btn.innerHTML = '<svg class="animate-spin" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:inline; animation: spin 1s linear infinite;"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>';
            btn.style.opacity = '0.7';

            const runSave = () => {
                const formData = new FormData();
                formData.append('action', 'save_health');
                formData.append('student_id', studentId);
                formData.append('weight', row.querySelector('.weight-in').value);
                formData.append('height', row.querySelector('.height-in').value);
                formData.append('hfa', row.querySelector('.hfa-in').value);
                formData.append('condition_remarks', row.querySelector('.remarks-in').value);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.ok ? btn.innerHTML = 'Saved' : btn.innerHTML = 'Error')
                .catch(() => btn.innerHTML = 'Retry')
                .finally(() => {
                    setTimeout(() => { 
                        btn.innerHTML = 'Save'; 
                        btn.style.opacity = '1';
                    }, 1000);
                });
            };

            if (force) runSave();
            else saveTimers[studentId] = setTimeout(runSave, 1500);
        }

        // Add CSS for spinner
        const style = document.createElement('style');
        style.innerHTML = `
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            .animate-spin { animation: spin 1s linear infinite; }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
