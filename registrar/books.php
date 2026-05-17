<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once __DIR__ . '/../config/db.php';

$pdo = db_connect();

function log_textbook_audit($pdo, $user_id, $action, $book_id = null, $old_val = null, $new_val = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO textbook_audit_log (user_id, action, book_id, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $book_id, $old_val, $new_val]);
    } catch (Exception $e) {}
}

function get_textbook_setting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

$textbook_stage = get_textbook_setting($pdo, 'textbook_stage', 'inventory');
$textbook_lock = (int)get_textbook_setting($pdo, 'textbook_lock_status', '0');
$textbook_deadline = get_textbook_setting($pdo, 'textbook_deadline', '');
$current_user_id = $_SESSION['user']['id'];

// Auto-create/Update tables using db.php standard
if (function_exists('initialize_schema')) {
    initialize_schema($pdo);
}

// Fetch Current School Year
$stmt_sy = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'");
$stmt_sy->execute();
$current_school_year = $stmt_sy->fetchColumn() ?: '2024-2025';

if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="master_books_import_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Book Title', 'Subject Area', 'Total School Stock', 'Grade Level']);
    fputcsv($output, ['Sample Science Book', 'Science', '100', 'Grade 7']);
    fputcsv($output, ['Sample Math Book', 'Mathematics', '50', 'All Grades']);
    fclose($output);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'print_sf3_prep') {
    $target_grade = $_GET['grade'] ?? 'All Grades';
    $sql = "SELECT * FROM admin_books WHERE (grade_level = ? OR (grade_level IS NULL AND ? = 'All Grades')) ORDER BY subject, title";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$target_grade, $target_grade]);
    $print_books = $stmt->fetchAll();
    ?>
    <!DOCTYPE html><html><head><title>SF3 Inventory Prep - <?= $target_grade ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h2 { margin: 5px 0; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; font-size: 12px; }
        th { background: #f2f2f2; text-transform: uppercase; }
        .footer { margin-top: 50px; display: flex; justify-content: space-between; }
        .sig { border-top: 1px solid #000; width: 200px; text-align: center; padding-top: 5px; margin-top: 40px; }
        @media print { .no-print { display: none; } }
    </style></head>
    <body onload="window.print()">
        <div class="no-print" style="background:#eee; padding:10px; margin-bottom:20px; text-align:center;"><button onclick="window.print()">Print Report</button> <button onclick="window.close()">Close</button></div>
        <div class="header">
            <h3>Department of Education</h3>
            <h2>School Form 3 (SF3) Inventory Preparation</h2>
            <p>School Year: <?= htmlspecialchars($current_school_year) ?> | Grade Level: <?= htmlspecialchars($target_grade) ?></p>
        </div>
        <table>
            <thead>
                <tr>
                    <th rowspan="2">Subject</th>
                    <th rowspan="2">Title of Textbook</th>
                    <th rowspan="2">Total Registered Enrollment</th>
                    <th colspan="4" style="text-align:center;">Condition Counts (Master Inventory)</th>
                    <th rowspan="2">Net Usable</th>
                </tr>
                <tr>
                    <th>Good</th>
                    <th>Repairable</th>
                    <th>Damaged</th>
                    <th>Lost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($print_books as $pb): 
                    $rep = (int)$pb['condition_repairable'];
                    $total = (int)$pb['total_copies'];
                    $usable = $total - $rep;
                ?>
                <tr>
                    <td><?= htmlspecialchars($pb['subject']) ?></td>
                    <td><?= htmlspecialchars($pb['title']) ?></td>
                    <td>-</td>
                    <td><?= $usable ?></td>
                    <td><?= $rep ?></td>
                    <td>0</td>
                    <td>0</td>
                    <td><?= $usable ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer">
            <div class="sig">Prepared by: (Property Custodian)</div>
            <div class="sig">Certified Correct: (School Head)</div>
        </div>
    </body></html>
    <?php exit;
}

// Handle Forms
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'add' || $action === 'edit') {
        try {
            $titles = $_POST['title'] ?? [];
            $subjects = $_POST['subject'] ?? [];
            $copies = $_POST['total_copies'] ?? [];
            $grades = $_POST['grade_level'] ?? [];
            $repairs = $_POST['condition_repairable'] ?? [];
            $categories = $_POST['category'] ?? [];

            if (!is_array($titles)) {
                $titles = [$titles];
                $subjects = [$_POST['subject'] ?? ''];
                $copies = [$_POST['total_copies'] ?? 0];
                $repairs = [$_POST['condition_repairable'] ?? 0];
                $grades = [$_POST['grade_level'] ?? null];
                $categories = [$_POST['category'] ?? 'Core'];
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO admin_books (title, subject, category, total_copies, condition_repairable, grade_level) VALUES (?, ?, ?, ?, ?, ?)");
                $added = 0;
                for ($i = 0; $i < count($titles); $i++) {
                    $t = trim($titles[$i] ?? '');
                    $s = trim($subjects[$i] ?? '');
                    $cat = trim($categories[$i] ?? 'Core');
                    $c = (int) ($copies[$i] ?? 0);
                    $r = (int) ($repairs[$i] ?? 0);
                    $g = isset($grades[$i]) && $grades[$i] !== '' ? $grades[$i] : null;

                    if ($t && $s) {
                        $stmt->execute([$t, $s, $cat, $c, $r, $g]);
                        $new_id = $pdo->lastInsertId();
                        log_textbook_audit($pdo, $current_user_id, 'add_book', $new_id, null, json_encode(['title' => $t, 'stock' => $c]));
                        $added++;
                    }
                }
                $success_message = "$added Master inventory item(s) added.";
            } else {
                $t = trim($titles[0] ?? '');
                $s = trim($subjects[0] ?? '');
                $cat = trim($categories[0] ?? 'Core');
                $c = (int) ($copies[0] ?? 0);
                $r = (int) ($repairs[0] ?? 0);
                $g = isset($grades[0]) && $grades[0] !== '' ? $grades[0] : null;
                $id = $_POST['book_id'] ?? 0;

                if ($t && $s) {
                    $old_stmt = $pdo->prepare("SELECT * FROM admin_books WHERE id = ?");
                    $old_stmt->execute([$id]);
                    $old_data = $old_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare("UPDATE admin_books SET title=?, subject=?, category=?, total_copies=?, condition_repairable=?, grade_level=? WHERE id=?");
                    $stmt->execute([$t, $s, $cat, $c, $r, $g, $id]);
                    
                    log_textbook_audit($pdo, $current_user_id, 'edit_book', $id, json_encode($old_data), json_encode(['title' => $t, 'stock' => $c]));
                    $success_message = "Master inventory updated.";
                }
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'delete') {
        $id = $_POST['book_id'] ?? 0;
        $pdo->prepare("DELETE FROM admin_books WHERE id=?")->execute([$id]);
        log_textbook_audit($pdo, $current_user_id, 'delete_book', $id);
        $success_message = "Deleted.";
    } elseif ($action === 'export_books') {
        $sql = "SELECT title, subject, total_copies, grade_level, created_at FROM admin_books ORDER BY grade_level, subject, title";
        $stmt = $pdo->query($sql);
        $books_export = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="master_books_export_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Book Title', 'Subject Area', 'Total School Stock', 'Grade Level', 'Date Added']);

        foreach ($books_export as $book) {
            fputcsv($output, [
                $book['title'],
                $book['subject'],
                $book['total_copies'],
                $book['grade_level'] ?: 'All Grades',
                $book['created_at']
            ]);
        }
        fclose($output);
        exit;
    } elseif ($action === 'import_books') {
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['import_file']['tmp_name'];
            $handle = fopen($fileTmpPath, 'r');
            fgetcsv($handle); // skip header
            $success_count = 0;
            while (($data = fgetcsv($handle)) !== FALSE) {
                $b_title = trim($data[0] ?? '');
                $b_subject = trim($data[1] ?? '');
                $b_copies = (int)($data[2] ?? 0);
                $b_grade = trim($data[3] ?? '');
                if ($b_grade === 'All Grades') $b_grade = null;
                if ($b_title && $b_subject) {
                    $stmt = $pdo->prepare("INSERT INTO admin_books (title, subject, total_copies, grade_level, category) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$b_title, $b_subject, $b_copies, $b_grade, 'Core']);
                    $success_count++;
                }
            }
            fclose($handle);
            $success_message = "Imported $success_count books.";
        }
    } elseif ($action === 'add_allocation') {
        $book_id = $_POST['book_id'] ?? 0;
        $grade = $_POST['grade_level'] ?? '';
        $qty = (int)($_POST['allocated_copies'] ?? 0);
        $pdo->prepare("INSERT INTO book_allocations (book_id, grade_level, allocated_copies) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allocated_copies = ?")
            ->execute([$book_id, $grade, $qty, $qty]);
        $success_message = "Allocation updated.";
    } elseif ($action === 'update_workflow') {
        $stage = $_POST['stage'] ?? 'inventory';
        $lock = $_POST['locked'] ?? '0';
        $deadline = $_POST['deadline'] ?? '';
        $upd = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        $upd->execute([$stage, 'textbook_stage']);
        $upd->execute([$lock, 'textbook_lock_status']);
        $upd->execute([$deadline, 'textbook_deadline']);
        $success_message = "Workflow updated.";
    }
}

// Fetch Data
$admin_books = [];
try {
    $admin_books = $pdo->query("SELECT * FROM admin_books ORDER BY LENGTH(grade_level), grade_level, subject, title")->fetchAll();
} catch (PDOException $e) {}

$grouped_books_full = [];
foreach ($admin_books as $book) {
    $g = $book['grade_level'] ?: 'All Grades';
    $grouped_books_full[$g][] = $book;
}

$master_titles = array_unique(array_column($admin_books, 'title'));
sort($master_titles);

$grade_to_books = [];
foreach ($admin_books as $book) {
    $g = $book['grade_level'] ?: 'All Grade Levels';
    $grade_to_books[$g][] = $book['title'];
}
foreach ($grade_to_books as $g => &$titles) {
    $titles = array_unique($titles);
    sort($titles);
}
$grade_books_json = json_encode($grade_to_books);

$audit_logs = [];
try {
    $audit_logs = $pdo->query("SELECT a.*, u.username, u.first_name, u.last_name FROM textbook_audit_log a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}

// Distribution/Monitoring Data (Simplified for Registrar)
$distribution = []; $monitoring = []; $collection = [];
try {
    $stmt_records = $pdo->prepare("
        SELECT r.grade_level, r.section, s.first_name, s.last_name, s.lrn, b.title as book_title, sb.date_issued, sb.condition_issued, sb.date_returned, sb.condition_returned, sb.remarks
        FROM sf3_student_books sb
        JOIN sf3_books_inventory b ON sb.inventory_id = b.id
        JOIN sf3_reports r ON sb.sf3_report_id = r.id
        JOIN students s ON sb.student_lrn = s.lrn
        WHERE r.school_year = ?
        ORDER BY r.grade_level, r.section, s.last_name, b.title
    ");
    $stmt_records->execute([$current_school_year]);
    $all_records = $stmt_records->fetchAll();
    foreach ($all_records as $rec) {
        if (!empty($rec['date_issued'])) $distribution[] = $rec;
        if (!empty($rec['remarks']) || in_array($rec['condition_returned'], ['Damaged', 'Lost'])) $monitoring[] = $rec;
        if (!empty($rec['date_returned'])) $collection[] = $rec;
    }
} catch (PDOException $e) {}

$dist_classes = array_unique(array_map(function($d) { return $d['grade_level'] . ' - ' . $d['section']; }, $distribution));
$dist_books = array_unique(array_column($distribution, 'book_title'));
$mon_classes = array_unique(array_map(function($m) { return $m['grade_level'] . ' - ' . $m['section']; }, $monitoring));
$mon_books = array_unique(array_column($monitoring, 'book_title'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academics Books Lifecycle</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary-color: #0d47a1; --secondary-color: #1976d2; --text-color: #333; --bg-color: #f4f6f9; --border-color: #dee2e6; --success: #28a745; --danger: #dc3545; --warning: #ffc107; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 0; }
        .main-content { margin-top: var(--header-height); padding: 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-header h1 { margin: 0; color: var(--primary-color); font-size: 28px; }
        .tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--border-color); margin-bottom: 24px; overflow-x: auto; }
        .tab { padding: 12px 24px; cursor: pointer; border-radius: 8px 8px 0 0; background: #e9ecef; color: #555; font-weight: 600; white-space: nowrap; }
        .tab.active { background: var(--primary-color); color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); margin-bottom: 24px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: #f8f9fa; font-size: 13px; text-transform: uppercase; color: #666; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .btn { padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; font-weight: 500; font-family: inherit; }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-outline { background: #fff; border: 1px solid #cbd5e1; color: #475569; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
        .modal-content { background: white; margin: 5vh auto; padding: 30px; border-radius: 12px; width: 90%; max-width: 550px; position: relative; }
        .grade-accordion { margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: white; }
        .accordion-header { background: #f8fafc; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .accordion-content { display: none; }
        .grade-accordion.active .accordion-content { display: block; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="content main-content">
        <div class="page-header">
            <div>
                <h1>Academics Textbooks</h1>
                <p style="color: #666;">Track SF3 Textbook Lifecycle for SY <?= htmlspecialchars($current_school_year) ?></p>
            </div>
        </div>

        <?php if ($success_message): ?><div style="padding:15px; background:#d4edda; color:#155724; border-radius:6px; margin-bottom:20px;"><?= $success_message ?></div><?php endif; ?>
        <?php if ($error_message): ?><div style="padding:15px; background:#f8d7da; color:#721c24; border-radius:6px; margin-bottom:20px;"><?= $error_message ?></div><?php endif; ?>

        <div class="tabs">
            <div class="tab active" onclick="switchTab('master')">MASTER LIST</div>
            <div class="tab" onclick="switchTab('allocations')">1. Grade Allocation</div>
            <div class="tab" onclick="switchTab('distribution')">2. Distribution</div>
            <div class="tab" onclick="switchTab('monitoring')">3. Monitoring</div>
            <div class="tab" onclick="switchTab('workflow')">4. Workflow</div>
            <div class="tab" onclick="switchTab('audit')">5. Audit Logs</div>
        </div>

        <div id="master" class="tab-content active">
            <div class="card">
                <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
                    <h3>School Master Textbooks</h3>
                    <div style="display:flex; gap:10px;">
                        <button class="btn btn-outline" onclick="openImportModal()">📥 Import</button>
                        <button class="btn btn-primary" onclick="openBookModal()">+ Add Book</button>
                    </div>
                </div>

                <div id="accordionContainer">
                    <?php foreach ($grouped_books_full as $grade => $books): ?>
                        <div class="grade-accordion" id="accordion-<?= md5($grade) ?>">
                            <div class="accordion-header" onclick="this.parentElement.classList.toggle('active')">
                                <strong><?= htmlspecialchars($grade) ?></strong>
                                <span><?= count($books) ?> Books</span>
                            </div>
                            <div class="accordion-content">
                                <table class="modern-table">
                                    <thead><tr><th>Subject</th><th>Title</th><th>Stock</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($books as $b): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($b['subject']) ?></td>
                                                <td><?= htmlspecialchars($b['title']) ?></td>
                                                <td><?= $b['total_copies'] ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline" onclick='editBook(<?= json_encode($b) ?>)'>Edit</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="allocations" class="tab-content">
            <div class="card">
                <h3>Grade-Level Allocation</h3>
                <table>
                    <thead><tr><th>Title</th><th>Grade</th><th>Qty</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($admin_books as $ab): ?>
                            <tr>
                                <td><?= htmlspecialchars($ab['title']) ?></td>
                                <td><?= htmlspecialchars($ab['grade_level'] ?: 'All') ?></td>
                                <td><?= $ab['total_copies'] ?></td>
                                <td><button class="btn btn-sm btn-primary" onclick="openAllocModal(<?= $ab['id'] ?>, '<?= addslashes($ab['title']) ?>')">Allocate</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="distribution" class="tab-content">
            <div class="card">
                <h3>Latest Distributions</h3>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Class</th><th>Learner</th><th>Book</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach($distribution as $d): ?>
                                <tr>
                                    <td><?= $d['grade_level'].' - '.$d['section'] ?></td>
                                    <td><?= $d['last_name'].', '.$d['first_name'] ?></td>
                                    <td><?= $d['book_title'] ?></td>
                                    <td><?= $d['date_issued'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="monitoring" class="tab-content">
            <div class="card">
                <h3>Flagged / Damaged Books</h3>
                <table>
                    <thead><tr><th>Class</th><th>Learner</th><th>Book</th><th>Status</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <?php foreach($monitoring as $m): ?>
                            <tr>
                                <td><?= $m['grade_level'].' - '.$m['section'] ?></td>
                                <td><?= $m['last_name'].', '.$m['first_name'] ?></td>
                                <td><?= $m['book_title'] ?></td>
                                <td><span class="badge" style="background:#fee2e2; color:#991b1b;"><?= $m['condition_returned'] ?></span></td>
                                <td><?= $m['remarks'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="workflow" class="tab-content">
            <div class="card">
                <h3>System Controls</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_workflow">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div class="form-group">
                            <label>Current Stage</label>
                            <select name="stage" class="form-control">
                                <option value="inventory" <?= $textbook_stage == 'inventory' ? 'selected' : '' ?>>Inventory</option>
                                <option value="distribution" <?= $textbook_stage == 'distribution' ? 'selected' : '' ?>>Distribution</option>
                                <option value="collection" <?= $textbook_stage == 'collection' ? 'selected' : '' ?>>Collection</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Lock Status</label>
                            <select name="locked" class="form-control">
                                <option value="0" <?= !$textbook_lock ? 'selected' : '' ?>>Unlocked</option>
                                <option value="1" <?= $textbook_lock ? 'selected' : '' ?>>Locked</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:20px;">Update Settings</button>
                </form>
            </div>
        </div>

        <div id="audit" class="tab-content">
            <div class="card">
                <h3>Recent Actions</h3>
                <table>
                    <thead><tr><th>Date</th><th>Admin</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($audit_logs as $log): ?>
                            <tr>
                                <td><?= date('M d, H:i', strtotime($log['created_at'])) ?></td>
                                <td><?= $log['first_name'] ?></td>
                                <td><?= str_replace('_', ' ', $log['action']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="bookModal" class="modal"><div class="modal-content">
        <h2 id="modalTitle">Add Book</h2>
        <form method="POST">
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="book_id" id="modalBookId">
            <div class="form-group"><label>Title</label><input type="text" name="title" id="bookTitle" class="form-control" required></div>
            <div class="form-group"><label>Subject</label><input type="text" name="subject" id="bookSubject" class="form-control" required></div>
            <div class="form-group"><label>Grade</label>
                <select name="grade_level" id="bookGrade" class="form-control">
                    <option value="">All Grades</option>
                    <option value="Grade 7">Grade 7</option><option value="Grade 8">Grade 8</option><option value="Grade 9">Grade 9</option><option value="Grade 10">Grade 10</option>
                </select>
            </div>
            <div class="form-group"><label>Stock</label><input type="number" name="total_copies" id="bookStock" class="form-control" value="0"></div>
            <div style="text-align:right; margin-top:20px;"><button type="button" class="btn" onclick="this.closest('.modal').style.display='none'">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div></div>

    <script>
        function switchTab(id) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector(`.tab[onclick*="${id}"]`).classList.add('active');
            document.getElementById(id).classList.add('active');
        }
        function openBookModal() { document.getElementById('bookModal').style.display='block'; }
        function editBook(b) {
            document.getElementById('modalTitle').innerText = 'Edit Book';
            document.getElementById('modalAction').value = 'edit';
            document.getElementById('modalBookId').value = b.id;
            document.getElementById('bookTitle').value = b.title;
            document.getElementById('bookSubject').value = b.subject;
            document.getElementById('bookGrade').value = b.grade_level || '';
            document.getElementById('bookStock').value = b.total_copies;
            openBookModal();
        }
    </script>
</body>
</html>
