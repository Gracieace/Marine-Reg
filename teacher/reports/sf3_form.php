<?php
/**
 * SF3 - Textbook Monitoring and Accountability
 * UI Overhaul: Professional, Premium Design, and Robust Data Logic.
 */
require_once '../../config/db.php';
require_once '../../auth/auth.php';

auth_require_role('teacher');

$conn = db_connect();
$teacher_id = $_SESSION['user']['id'] ?? 0;
$message = $error = '';
$inventory = $students = $school_years = $grade_levels = $sections = [];
$report = null;
$adviser_full_name = '';

// Fetch Adviser Full Name from users table
if ($teacher_id) {
    $u_stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $u_stmt->execute([$teacher_id]);
    $user_data = $u_stmt->fetch();
    if ($user_data && !empty($user_data['first_name'])) {
        $adviser_full_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
    }
}
if (empty($adviser_full_name)) {
    $adviser_full_name = ($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '');
    if (trim($adviser_full_name) == '') $adviser_full_name = $_SESSION['user']['username'] ?? 'Teacher';
}

// Inputs
$grade_level = $_GET['grade_level'] ?? $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? $_GET['sy'] ?? get_active_school_year($conn);

try {
    // 1. Load Filter Options
    $school_years = $conn->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $grade_levels = $conn->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level ASC")->fetchAll(PDO::FETCH_COLUMN);
    if ($grade_level) {
        $stmt = $conn->prepare("SELECT DISTINCT section FROM enrollments WHERE grade_level = ? ORDER BY section ASC");
        $stmt->execute([$grade_level]);
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 2. Handle Submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $sy = $_POST['school_year_submit'] ?? '';
        $gl = $_POST['grade_level_submit'] ?? '';
        $sec = $_POST['section_submit'] ?? '';

        if ($action === 'save_inventory') {
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT id FROM sf3_reports WHERE school_year = ? AND grade_level = ? AND section = ? AND teacher_id = ?");
            $stmt->execute([$sy, $gl, $sec, $teacher_id]);
            $existing = $stmt->fetch();
            
            $bosy = $_POST['bosy_date'] ?: null;
            $eosy = $_POST['eosy_date'] ?: null;
            
            if ($existing) {
                $report_id = $existing['id'];
                $stmt = $conn->prepare("UPDATE sf3_reports SET bosy_date=?, eosy_date=?, prepared_by=?, property_custodian=?, school_head=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$bosy, $eosy, $_POST['prepared_by'], $_POST['property_custodian'], $_POST['school_head'], $report_id]);
            } else {
                $stmt = $conn->prepare("INSERT INTO sf3_reports (teacher_id, school_year, grade_level, section, bosy_date, eosy_date, prepared_by, property_custodian, school_head) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$teacher_id, $sy, $gl, $sec, $bosy, $eosy, $_POST['prepared_by'], $_POST['property_custodian'], $_POST['school_head']]);
                $report_id = $conn->lastInsertId();
            }
            $conn->commit();
            $message = "Report configuration saved successfully.";
        }
    }

    // 3. Load Report Data
    if ($school_year && $grade_level && $section) {
        $stmt = $conn->prepare("SELECT * FROM sf3_reports WHERE school_year=? AND grade_level=? AND section=? AND teacher_id=?");
        $stmt->execute([$school_year, $grade_level, $section, $teacher_id]);
        $report = $stmt->fetch();
        if ($report) {
            $stmt = $conn->prepare("SELECT * FROM sf3_books_inventory WHERE sf3_report_id=? ORDER BY subject, title");
            $stmt->execute([$report['id']]);
            $inventory = $stmt->fetchAll();
        }

        // Auto-sync: If inventory is empty, populate it from the books actually distributed to this section
        if (empty($inventory)) {
            $stmt = $conn->prepare("
                SELECT DISTINCT b.subject, b.title 
                FROM textbook_distributions d 
                JOIN admin_books b ON d.textbook_id = b.id 
                JOIN enrollments e ON d.student_id = e.student_id 
                WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ?
            ");
            $stmt->execute([$school_year, $grade_level, $section]);
            $found_books = $stmt->fetchAll();
            
            if (!empty($found_books)) {
                // If we have a report record, save these for next time
                if ($report) {
                    $ins = $conn->prepare("INSERT INTO sf3_books_inventory (sf3_report_id, subject, title) VALUES (?,?,?)");
                    foreach ($found_books as $fb) {
                        $ins->execute([$report['id'], $fb['subject'], $fb['title']]);
                    }
                    // Re-fetch to get IDs
                    $stmt = $conn->prepare("SELECT * FROM sf3_books_inventory WHERE sf3_report_id=? ORDER BY subject, title");
                    $stmt->execute([$report['id']]);
                    $inventory = $stmt->fetchAll();
                } else {
                    // Just use them for preview if no report record exists yet
                    foreach ($found_books as $idx => $fb) {
                        $inventory[] = [
                            'id' => 'temp_' . $idx,
                            'subject' => $fb['subject'],
                            'title' => $fb['title']
                        ];
                    }
                }
            }
        }

        $grade_col = 'grade_level';
        try {
            $cols = $conn->query("DESCRIBE enrollments")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('grade_level', $cols) && in_array('year_level', $cols)) $grade_col = 'year_level';
        } catch (Exception $e) {}

        $stmt = $conn->prepare("SELECT e.student_id, e.lrn, e.student_name, r.sex FROM enrollments e LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND (r.lrn = e.lrn OR r.lrn = e.student_id))) WHERE e.school_year=? AND e.$grade_col=? AND e.section=? AND (e.status IS NULL OR e.status IN ('Enrolled','Active')) ORDER BY r.sex DESC, e.student_name ASC");
        $stmt->execute([$school_year, $grade_level, $section]);
        $raw_students = $stmt->fetchAll();
        
        $sids = array_column($raw_students, 'student_id');
        $raw_dist = [];
        if (!empty($sids)) {
            $qs = str_repeat('?,', count($sids)-1) . '?';
            $stmt = $conn->prepare("SELECT d.*, b.title as book_title, b.subject as book_subject, d.student_id, ret.return_date as date_returned FROM textbook_distributions d JOIN admin_books b ON d.textbook_id = b.id LEFT JOIN textbook_returns ret ON d.id = ret.distribution_id WHERE d.student_id IN ($qs)");
            $stmt->execute($sids);
            $raw_dist = $stmt->fetchAll();
        }

        $dist_by_sid = []; foreach($raw_dist as $d) { $dist_by_sid[trim(strtoupper($d['student_id']))][] = $d; }
        foreach ($raw_students as $s) {
            $s_data = $s; $s_data['books'] = [];
            $sid_key = trim(strtoupper($s['student_id']));
            $my_dists = $dist_by_sid[$sid_key] ?? [];
            foreach ($inventory as $ib) {
                $match = null; $isub = strtolower(trim($ib['subject'])); $itit = strtolower(trim($ib['title']));
                foreach ($my_dists as $d) {
                    if (strtolower(trim($d['book_subject'])) === $isub) {
                        $match = $d; if (strtolower(trim($d['book_title'])) === $itit) break;
                    }
                }
                $s_data['books'][$ib['id']] = $match;
            }
            $students[] = $s_data;
        }
    }
} catch (Exception $e) { $error = $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF3 | Textbook Accountability</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5; --primary-light: #eef2ff; --primary-dark: #3730a3;
            --secondary: #64748b; --success: #10b981; --danger: #ef4444; --warning: #f59e0b;
            --surface: #ffffff; --background: #f8fafc; --border: #e2e8f0;
            --text-main: #1e293b; --text-muted: #64748b;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        * { box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--background); color: var(--text-main); margin: 0; line-height: 1.5; }
        
        .main-content { padding: 2.5rem; transition: all 0.3s ease; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; }
        .header-info h1 { font-size: 1.875rem; font-weight: 800; margin: 0; letter-spacing: -0.025em; }
        .header-info p { color: var(--text-muted); margin: 0.25rem 0 0; }
        
        .glass-card { background: var(--surface); border-radius: 1rem; border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 1.5rem; margin-bottom: 1.5rem; }
        
        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; align-items: flex-end; }
        .form-group label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
        .form-control { width: 100%; padding: 0.75rem 1rem; border-radius: 0.75rem; border: 1px solid var(--border); background: var(--background); transition: all 0.2s; font-size: 0.875rem; font-family: inherit; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        
        .nav-pills { display: inline-flex; background: var(--primary-light); padding: 0.4rem; border-radius: 1rem; gap: 0.4rem; margin-bottom: 2rem; }
        .pill-item { padding: 0.6rem 1.25rem; border-radius: 0.75rem; font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: all 0.2s; color: var(--primary); border: none; background: transparent; }
        .pill-item.active { background: var(--surface); color: var(--primary-dark); box-shadow: var(--shadow-sm); }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.75rem 1.5rem; border-radius: 0.75rem; font-weight: 700; font-size: 0.875rem; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-outline { background: white; border-color: var(--border); color: var(--text-main); }
        .btn-outline:hover { background: var(--background); }
        
        .sf3-container { padding: 0.5rem; background: #fff; border-radius: 0.5rem; overflow-x: auto; }
        .sf3-table { border-collapse: collapse; width: 100%; border: 2px solid #000; table-layout: fixed; }
        .sf3-table th, .sf3-table td { border: 1px solid #000; padding: 4px; font-size: 9px; vertical-align: middle; position: relative; }
        
        .vertical-header { writing-mode: vertical-rl; transform: rotate(180deg); height: 160px; text-align: center; white-space: nowrap; width: 35px; }
        .sf3-table th.sub-header { font-size: 8px; padding: 2px; height: 90px; writing-mode: vertical-rl; transform: rotate(180deg); width: 40px !important; min-width: 40px !important; }
        .sf3-table td { padding: 4px 2px; font-size: 10px; vertical-align: middle; height: 35px; }
        .date-cell { font-size: 8.5px; font-weight: 700; white-space: nowrap; letter-spacing: -0.2px; text-align: center; width: 40px; }
        .status-badge { padding: 2px 4px; border-radius: 4px; font-size: 8px; font-weight: 800; text-transform: uppercase; }
        .st-active { background: #ffedd5; color: #9a3412; }
        .st-returned { background: #dcfce7; color: #166534; }
        
        .config-table { width: 100%; border-collapse: collapse; }
        .config-table th { text-align: left; background: var(--background); padding: 1rem; font-size: 0.75rem; color: var(--text-muted); }
        .config-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); }
        
        .alert { padding: 1rem; border-radius: 0.75rem; margin-bottom: 1.5rem; font-weight: 600; font-size: 0.875rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        @media print { .sidebar, .dashboard-header, .filter-bar, .nav-pills, .btn { display: none !important; } .main-content { padding: 0; } }
    </style>
</head>
<body>
    <?php require_once '../../teacher/teacher_header.php'; ?>
    <?php require_once '../../teacher/teacher_side_panel.php'; ?>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-info">
                <h1><i class="fas fa-file-invoice text-primary me-2"></i>School Form 3</h1>
                <p>Textbook Monitoring and Accountability Report (SF3)</p>
            </div>
            <div class="header-actions">
                <a href="../dashboard.php" class="btn btn-outline"><i class="fas fa-chevron-left"></i> Dashboard</a>
                <?php if ($school_year && $grade_level && $section): ?>
                    <a href="sf3_print.php?sy=<?= urlencode($school_year) ?>&grade=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Print Preview</a>
                <?php else: ?>
                    <button class="btn btn-primary" disabled><i class="fas fa-print"></i> Print Preview</button>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle me-2"></i><?= $error ?></div><?php endif; ?>

        <div class="glass-card">
            <form method="GET" class="filter-bar">
                <div class="form-group">
                    <label>School Year</label>
                    <select name="sy" class="form-control">
                        <?php foreach($school_years as $sy_opt): ?><option value="<?= $sy_opt ?>" <?= $school_year == $sy_opt ? 'selected' : '' ?>><?= $sy_opt ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Grade Level</label>
                    <select name="grade" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Grade --</option>
                        <?php foreach($grade_levels as $gl_opt): ?><option value="<?= $gl_opt ?>" <?= $grade_level == $gl_opt ? 'selected' : '' ?>><?= $gl_opt ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <select name="section" class="form-control">
                        <option value="">-- Section --</option>
                        <?php foreach($sections as $sec_opt): ?><option value="<?= $sec_opt ?>" <?= $section == $sec_opt ? 'selected' : '' ?>><?= $sec_opt ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Load Records</button>
            </form>
        </div>

        <?php if ($school_year && $grade_level && $section): ?>
            <div class="nav-pills">
                <button class="pill-item <?= empty($students) ? 'active' : '' ?>" onclick="switchTab('config', this)">1. Report Configuration</button>
                <button class="pill-item <?= !empty($students) ? 'active' : '' ?>" onclick="switchTab('preview', this)">2. SF3 Preview</button>
            </div>

            <div id="tab-config" class="tab-pane <?= empty($students) ? 'active' : '' ?>" style="<?= !empty($students) ? 'display:none;' : '' ?>">
                <form method="POST">
                    <input type="hidden" name="action" value="save_inventory">
                    <input type="hidden" name="school_year_submit" value="<?= $school_year ?>">
                    <input type="hidden" name="grade_level_submit" value="<?= $grade_level ?>">
                    <input type="hidden" name="section_submit" value="<?= $section ?>">

                    <?php 
                    $school_head = get_system_setting($conn, 'principal_name', 'School Head');
                    ?>
                    <div class="glass-card">
                        <div class="filter-bar">
                            <div class="form-group"><label>BoSY Date</label><input type="date" name="bosy_date" class="form-control" value="<?= $report['bosy_date'] ?? '' ?>"></div>
                            <div class="form-group"><label>EoSY Date</label><input type="date" name="eosy_date" class="form-control" value="<?= $report['eosy_date'] ?? '' ?>"></div>
                            <?php 
                            $saved_prepared = $report['prepared_by'] ?? '';
                            if (empty($saved_prepared) || $saved_prepared === ($_SESSION['user']['username'] ?? '')) $saved_prepared = $adviser_full_name;
                            
                            $saved_custodian = $report['property_custodian'] ?? '';
                            if (empty($saved_custodian) || $saved_custodian === ($_SESSION['user']['username'] ?? '')) $saved_custodian = $adviser_full_name;
                            ?>
                            <div class="form-group"><label>Adviser</label><input type="text" name="prepared_by" class="form-control" value="<?= htmlspecialchars($saved_prepared) ?>"></div>
                        </div>
                        <div class="filter-bar mt-4" style="margin-top:1.5rem;">
                            <div class="form-group"><label>Property Custodian</label><input type="text" name="property_custodian" class="form-control" value="<?= htmlspecialchars($saved_custodian) ?>"></div>
                            <div class="form-group"><label>School Head</label><input type="text" name="school_head" class="form-control" value="<?= htmlspecialchars($report['school_head'] ?? $school_head) ?>"></div>
                        </div>
                        <div style="text-align:right; margin-top: 1.5rem;"><button type="submit" class="btn btn-primary px-5 shadow">Save Report Configuration</button></div>
                    </div>

                </form>
            </div>

            <div id="tab-preview" class="tab-pane <?= !empty($students) ? 'active' : '' ?>" style="<?= empty($students) ? 'display:none;' : '' ?>">
                <div class="sf3-container">
                    <table class="sf3-table">
                        <thead>
                            <tr>
                                <th rowspan="3" style="width:30px;">NO.</th>
                                <th rowspan="3" style="width:180px;">LEARNER'S NAME</th>
                                <?php foreach($inventory as $b): ?><th colspan="2"><?= htmlspecialchars($b['subject']) ?></th><?php endforeach; ?>
                                <th rowspan="3" style="width:40px;">TOTAL</th>
                            </tr>
                            <tr><?php foreach($inventory as $b): ?><th colspan="2" class="vertical-header"><?= htmlspecialchars($b['title']) ?></th><?php endforeach; ?></tr>
                            <tr><?php foreach($inventory as $b): ?><th class="sub-header">Date Issued</th><th class="sub-header">Date Returned</th><?php endforeach; ?></tr>
                        </thead>
                        <tbody>
                            <?php
                            $males = array_filter($students, function($s) {
                                $sex = trim(strtoupper($s['sex'] ?? ''));
                                return !empty($sex) && $sex[0] === 'M';
                            });
                            $females = array_filter($students, function($s) {
                                $sex = trim(strtoupper($s['sex'] ?? ''));
                                return !empty($sex) && $sex[0] === 'F';
                            });
                            $unknown = array_filter($students, function($s) {
                                $sex = trim(strtoupper($s['sex'] ?? ''));
                                return empty($sex) || !in_array($sex[0], ['M','F']);
                            });
                            
                            function renderSF3($list, &$idx, $inventory) {
                                foreach($list as $s) {
                                    echo "<tr><td style='text-align:center;'>".$idx++."</td><td style='text-transform:uppercase; font-weight:700;'>".htmlspecialchars($s['student_name'])."</td>";
                                    $row_total = 0;
                                    foreach($inventory as $ib) {
                                        $rec = $s['books'][$ib['id']] ?? null;
                                        $issued = $rec && $rec['date_issued'] ? date('m/d/y', strtotime($rec['date_issued'])) : '—';
                                        $ret = '—'; $cls = ''; $tip = '';
                                        if ($rec && $rec['date_issued']) {
                                            $row_total++;
                                            $tip = "Book: {$rec['book_title']} | Acc: " . ($rec['accession_no'] ?? 'N/A');
                                            if (($rec['status'] ?? '') === 'Returned' && $rec['date_returned']) {
                                                $ret = date('m/d/y', strtotime($rec['date_returned']));
                                                $cls = 'background:#dcfce7;';
                                            } else {
                                                $ret = '<span class="status-badge st-active">Active</span>';
                                                $cls = 'background:#fff7ed;';
                                            }
                                        }
                                        echo "<td style='text-align:center;' class='date-cell' title='$tip'>$issued</td>";
                                        echo "<td style='text-align:center; $cls' class='date-cell' title='$tip'>$ret</td>";
                                    }
                                    echo "<td style='text-align:center; font-weight:800; background:#f1f5f9;'>$row_total</td></tr>";
                                }
                            }
                            $c=1; echo "<tr><td colspan='".(count($inventory)*2+3)."' style='background:#f8fafc; font-weight:900; font-size:10px;'>MALE</td></tr>"; renderSF3($males, $c, $inventory);
                            $c=1; echo "<tr><td colspan='".(count($inventory)*2+3)."' style='background:#f8fafc; font-weight:900; font-size:10px;'>FEMALE</td></tr>"; renderSF3($females, $c, $inventory);
                            if (!empty($unknown)) {
                                $c=1; echo "<tr><td colspan='".(count($inventory)*2+3)."' style='background:#fee2e2; font-weight:900; font-size:10px;'>UNKNOWN GENDER / PENDING REGISTRATION</td></tr>"; renderSF3($unknown, $c, $inventory);
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <div class="glass-card" style="text-align:center; padding: 5rem 2rem;">
                <div style="font-size: 4rem; color: var(--border); margin-bottom: 1.5rem;"><i class="fas fa-folder-open"></i></div>
                <h3 style="font-weight: 800; margin:0;">No Report Selected</h3>
                <p style="color: var(--text-muted);">Please select a School Year, Grade, and Section above to generate the SF3 report.</p>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function switchTab(id, el) {
            document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
            document.querySelectorAll('.pill-item').forEach(i => i.classList.remove('active'));
            document.getElementById('tab-' + id).style.display = 'block';
            el.classList.add('active');
        }
    </script>
</body>
</html>