<?php
/**
 * SF7 – School Personnel Assignment List and Basic Profile
 * Pulls data from users table (Single Source of Truth) and links with assignments.
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';

try {
    $pdo = db_connect();
    initialize_schema($pdo);
    auth_require_role(['admin', 'registrar']);

    $school_year = $_GET['school_year'] ?? get_active_school_year($pdo);
    $action = $_POST['action'] ?? '';
    
    // Fetch Settings for Header with Standardized Keys
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

    // Apply DB values with appropriate fallbacks
    foreach (['school_id', 'school_name', 'district'] as $k) {
        if (!empty($db_settings[$k])) $settings[$k] = $db_settings[$k];
    }

    // Branding Prioritization (DepEd Form Settings)
    $settings['region'] = $db_settings['sf_region'] ?? $db_settings['region'] ?? 'REGION III';
    $settings['division'] = $db_settings['sf_division'] ?? $db_settings['division'] ?? 'MALOLOS CITY';

    // Signatory Mapping: Prioritize standardized 'principal_name' (from DepEd Forms tab)
    $settings['school_head'] = get_system_setting($pdo, 'principal_name', 'DR. MARIA SANTOS');
    $settings['registrar_name'] = $db_settings['signatory_registrar'] ?? 'MS. ANA CRUZ';

    // Initialize/Fetch Report Record
    $stmt = $pdo->prepare("SELECT * FROM sf7_reports WHERE school_year = ?");
    $stmt->execute([$school_year]);
    $report = $stmt->fetch();

    if (!$report) {
        $pdo->prepare("INSERT INTO sf7_reports (school_year, status) VALUES (?, 'Draft')")->execute([$school_year]);
        $stmt->execute([$school_year]);
        $report = $stmt->fetch();
    }
    $report_id = $report['id'];

    // Handle Metadata Save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_meta') {
        $uid = $_POST['user_id'];
        $pdo->prepare("UPDATE users SET 
            sex = ?, appointment_status = ?, educational_degree = ?, 
            major_specialization = ?, tin = ?, fund_source = ?, 
            salary_grade = ?, position_title = ? 
            WHERE id = ?")->execute([
                $_POST['sex'] ?? 'Male', $_POST['status'], $_POST['degree'], 
                $_POST['major'], $_POST['tin'], $_POST['fund_source'], 
                $_POST['salary_grade'], $_POST['position_title'], $uid
            ]);

        $stmt = $pdo->prepare("INSERT INTO sf7_personnel_data (sf7_report_id, user_id, years_in_service, role_function, remarks) 
                               VALUES (?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE 
                               years_in_service = VALUES(years_in_service), role_function = VALUES(role_function), remarks = VALUES(remarks)");
        $stmt->execute([$report_id, $uid, $_POST['years'], $_POST['role_func'], $_POST['remarks']]);
        header("Location: sf7.php?school_year=" . urlencode($school_year) . "&success=1");
        exit;
    }

    // Aggregate Personnel Data
    $personnel = aggregateSF7Data($pdo, $report_id, $school_year);

} catch (Exception $e) { die("SF7 Error: " . $e->getMessage()); }

function aggregateSF7Data($pdo, $report_id, $sy) {
    $stmt = $pdo->prepare("
        SELECT u.*, e.employee_code, pd.years_in_service, pd.role_function, pd.remarks
        FROM users u
        LEFT JOIN employees e ON u.id = e.user_id
        LEFT JOIN sf7_personnel_data pd ON (pd.user_id = u.id AND pd.sf7_report_id = ?)
        WHERE u.approval_status = 'approved' AND u.user_status = 'active'
        ORDER BY FIELD(u.role, 'admin', 'registrar', 'teacher') ASC, u.last_name ASC
    ");
    $stmt->execute([$report_id]);
    $users = $stmt->fetchAll();
    $groups = ['Teaching' => [], 'Non-Teaching' => []];
    foreach ($users as $u) {
        $u['full_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ? substr($u['middle_name'],0,1).'. ' : '') . ($u['last_name'] ?? ''));
        $u['assignments'] = getAssignments($pdo, $u['id'], $sy);
        if ($u['role'] === 'teacher') $groups['Teaching'][] = $u;
        else $groups['Non-Teaching'][] = $u;
    }
    return $groups;
}

function getAssignments($pdo, $user_id, $sy) {
    $assignments = [];
    $stmt = $pdo->prepare("SELECT grade_level, section_name FROM sections WHERE adviser_id = ? AND school_year = ?");
    $stmt->execute([$user_id, $sy]);
    if ($adv = $stmt->fetch()) $assignments[] = ['type' => 'Advisory', 'grade' => $adv['grade_level'], 'section' => $adv['section_name'], 'subject' => 'Advisory'];

    $stmt = $pdo->prepare("SELECT s.subject_name, s.grade_level, sec.section_name FROM subject_teachers st JOIN subjects s ON st.subject_id = s.id LEFT JOIN sections sec ON st.section_id = sec.id WHERE st.teacher_id = ? AND st.school_year = ?");
    $stmt->execute([$user_id, $sy]);
    while ($row = $stmt->fetch()) $assignments[] = ['type' => 'Teaching', 'grade' => $row['grade_level'], 'section' => $row['section_name'] ?: 'N/A', 'subject' => $row['subject_name']];
    return $assignments;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF7 | Strategic Personnel Insight</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --accent-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.5);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #0f172a;
            background-image: radial-gradient(at 0% 0%, hsla(210,100%,98%,1) 0, transparent 50%), 
                              radial-gradient(at 100% 0%, hsla(220,30%,95%,1) 0, transparent 50%);
            background-attachment: fixed;
            margin: 0;
        }

        .main-content {
            padding: calc(var(--header-height) + 40px) 40px 80px;
            margin-left: var(--sidebar-width, 260px);
            max-width: 1600px;
            transition: all 0.4s ease;
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0; padding: calc(var(--header-height) + 20px) 20px 40px; }
        }

        .hub-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 48px;
        }

        .hub-header h1 {
            font-size: 42px;
            font-weight: 900;
            letter-spacing: -2px;
            margin: 0;
            color: #0f172a;
        }

        .hub-header p { margin: 8px 0 0; color: #64748b; font-size: 18px; font-weight: 500; }

        .info-card {
            background: #0f172a;
            color: white;
            border-radius: 32px;
            padding: 32px 48px;
            margin-bottom: 48px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .info-item h5 { margin: 0; font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; }
        .info-item div { font-size: 16px; font-weight: 700; margin-top: 8px; }

        .premium-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 20px 40px rgba(0,0,0,0.04);
            padding: 12px;
            margin-bottom: 48px;
        }

        .category-title {
            padding: 24px;
            font-size: 13px;
            font-weight: 900;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .category-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }

        .sf-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .sf-table th { padding: 16px 24px; text-align: left; font-size: 10px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; }
        .sf-table td { padding: 20px 24px; background: white; font-size: 13px; vertical-align: middle; }
        .sf-table tr td:first-child { border-radius: 20px 0 0 20px; }
        .sf-table tr td:last-child { border-radius: 0 20px 20px 0; }
        .sf-table tr:hover td { background: #f8fafc; transform: scale(1.002); }

        .personnel-name { font-weight: 800; font-size: 15px; color: #0f172a; }
        .personnel-meta { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .assignment-tag {
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 800;
            margin-bottom: 4px;
            display: inline-block;
        }
        .tag-teaching { background: #eff6ff; color: #2563eb; }
        .tag-advisory { background: #fef3c7; color: #92400e; }

        /* Modal styling */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-card { background: white; width: 100%; max-width: 900px; border-radius: 32px; padding: 40px; box-shadow: 0 40px 80px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 24px; }
        .form-group label { display: block; font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.1em; }
        .form-control { width: 100%; padding: 16px 20px; border: 2px solid #f1f5f9; border-radius: 16px; font-size: 15px; font-weight: 600; background: #f8fafc; transition: all 0.3s ease; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: #2563eb; background: white; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../admin_header.php'; ?>
    <?php include __DIR__ . '/../../admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="hub-header no-print">
            <div>
                <h1>SF7 Report Portal</h1>
                <p>Institutional Workforce Analysis & Assignment Roster</p>
            </div>
            <div style="display:flex; gap:16px;">
                <a href="sf7_print.php?school_year=<?= urlencode($school_year) ?>" target="_blank" style="text-decoration:none;">
                    <button class="btn-submit" style="background: var(--accent-gradient); color:white; padding:12px 32px; border-radius:16px; font-weight:800; cursor:pointer; border:none; display:flex; align-items:center; gap:12px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                        Print SF7
                    </button>
                </a>
            </div>
        </div>

        <div class="info-card no-print">
            <div class="info-item"><h5>School Name</h5><div><?= $settings['school_name'] ?></div></div>
            <div class="info-item"><h5>School ID</h5><div><?= $settings['school_id'] ?></div></div>
            <div class="info-item"><h5>Division</h5><div><?= $settings['division'] ?></div></div>
            <div class="info-item"><h5>School Year</h5><div><?= $school_year ?></div></div>
        </div>

        <?php foreach ($personnel as $category => $members): ?>
            <div class="premium-card no-print">
                <div class="category-title"><?= $category ?> Personnel</div>
                <table class="sf-table">
                    <thead>
                        <tr>
                            <th style="width:25%">Personnel Details</th>
                            <th>Position</th>
                            <th>Educational Data</th>
                            <th>Assignments</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $p): ?>
                        <tr>
                            <td>
                                <div class="personnel-name"><?= htmlspecialchars($p['full_name']) ?></div>
                                <div class="personnel-meta">ID: <?= htmlspecialchars($p['tin'] ?: ($p['employee_code'] ?: '--')) ?> • <?= substr($p['sex']??'M', 0, 1) ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 700;"><?= htmlspecialchars($p['position_title'] ?: ucwords($p['role'])) ?></div>
                                <div style="font-size: 11px; color:#2563eb; font-weight:800; margin-top:4px;">SG <?= $p['salary_grade'] ?: '--' ?> • <?= $p['fund_source'] ?: 'National' ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 12px;"><?= htmlspecialchars($p['educational_degree'] ?: '--') ?></div>
                                <div style="font-size: 11px; color:#94a3b8;"><?= htmlspecialchars($p['major_specialization'] ?: '--') ?></div>
                            </td>
                            <td>
                                <?php if (empty($p['assignments'])): ?>
                                    <span style="color:#cbd5e1;">No assignments</span>
                                <?php else: ?>
                                    <?php foreach ($p['assignments'] as $as): ?>
                                        <div class="assignment-tag <?= $as['type']==='Advisory'?'tag-advisory':'tag-teaching' ?>">
                                            <?= htmlspecialchars($as['subject']) ?> (<?= $as['grade'] ?>)
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <button class="btn-action" style="background:#f1f5f9; border:none; padding:10px; border-radius:12px; cursor:pointer;" onclick='openEditModal(<?= json_encode($p) ?>)'>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </main>

    <!-- Meta Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-card">
            <h2 id="modalTitle" style="margin-top:0; font-size:24px; font-weight:900; letter-spacing:-1px;">Refine Personnel Record</h2>
            <form method="POST">
                <input type="hidden" name="action" value="save_meta">
                <input type="hidden" name="user_id" id="edit_uid">
                <div class="form-grid">
                    <div class="form-group"><label>TIN / Employee No.</label><input type="text" name="tin" id="edit_tin" class="form-control"></div>
                    <div class="form-group">
                        <label>Sex</label>
                        <select name="sex" id="edit_sex" class="form-control">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Position Title</label><input type="text" name="position_title" id="edit_pos" class="form-control"></div>
                    <div class="form-group"><label>Salary Grade</label><input type="number" name="salary_grade" id="edit_sg" class="form-control"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Fund Source</label><select name="fund_source" id="edit_fund" class="form-control"><option value="National">National</option><option value="Local">Local</option><option value="SEF">SEF</option></select></div>
                    <div class="form-group"><label>Appt. Status</label><select name="status" id="edit_status" class="form-control"><option value="Permanent">Permanent</option><option value="Provisional">Provisional</option><option value="Substitute">Substitute</option></select></div>
                </div>
                <div class="form-group"><label>Degree / Post Graduate</label><input type="text" name="degree" id="edit_degree" class="form-control"></div>
                <div class="form-grid">
                    <div class="form-group"><label>Major / Spec.</label><input type="text" name="major" id="edit_major" class="form-control"></div>
                    <div class="form-group"><label>Years in Service</label><input type="number" name="years" id="edit_years" class="form-control"></div>
                </div>
                <div style="text-align: right; margin-top: 32px;"><button type="submit" class="btn-submit" style="background:#0f172a; color:white; padding:16px 32px; border-radius:20px; font-weight:800; border:none; cursor:pointer;">Update Personnel Data</button></div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(p) {
            document.getElementById('edit_uid').value = p.id;
            document.getElementById('edit_tin').value = p.tin || '';
            document.getElementById('edit_sex').value = p.sex || 'Male';
            document.getElementById('edit_pos').value = p.position_title || '';
            document.getElementById('edit_sg').value = p.salary_grade || '';
            document.getElementById('edit_fund').value = p.fund_source || 'National';
            document.getElementById('edit_status').value = p.appointment_status || 'Permanent';
            document.getElementById('edit_degree').value = p.educational_degree || '';
            document.getElementById('edit_major').value = p.major_specialization || '';
            document.getElementById('edit_years').value = p.years_in_service || 0;
            document.getElementById('editModal').classList.add('active');
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }
        document.getElementById('editModal').addEventListener('click', (e) => { if(e.target.id === 'editModal') closeEditModal(); });
    </script>
</body>
</html>
