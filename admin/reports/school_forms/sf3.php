<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['admin', 'registrar']);

$teacher_id = $_SESSION['user']['id'];
$pdo = db_connect();

$message = '';
$error = '';

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$school_year = $_GET['school_year'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';

// AJAX Handle for saving an individual book record for a student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_book_record') {
    header('Content-Type: application/json');
    try {
        $report_id = $_POST['sf3_report_id'] ?? null;
        $inventory_id = $_POST['inventory_id'] ?? null;
        $student_lrn = $_POST['student_lrn'] ?? null;

        $date_issued = !empty($_POST['date_issued']) ? $_POST['date_issued'] : null;
        $condition_issued = $_POST['condition_issued'] ?? 'Good';
        $date_returned = !empty($_POST['date_returned']) ? $_POST['date_returned'] : null;
        $condition_returned = !empty($_POST['condition_returned']) ? $_POST['condition_returned'] : null;
        $remarks = $_POST['remarks'] ?? null;

        if (!$report_id || !$inventory_id || !$student_lrn) {
            throw new Exception("Missing required fields.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO sf3_student_books (sf3_report_id, student_lrn, inventory_id, date_issued, condition_issued, date_returned, condition_returned, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            date_issued = VALUES(date_issued), condition_issued = VALUES(condition_issued),
            date_returned = VALUES(date_returned), condition_returned = VALUES(condition_returned), remarks = VALUES(remarks)
        ");
        $stmt->execute([$report_id, $student_lrn, $inventory_id, $date_issued, $condition_issued, $date_returned, $condition_returned, $remarks]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Form Submission for Inventory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_inventory') {
    $school_year_submit = $_POST['school_year_submit'] ?? '';
    $grade_level_submit = $_POST['grade_level_submit'] ?? '';
    $section_submit = $_POST['section_submit'] ?? '';

    try {
        $pdo->beginTransaction();

        // Find existing SF3 report or create a new one (using a dummy teacher_id 0 for admin changes or linking to section's real adviser if desired. We use 0 for global/admin)
        $stmt = $pdo->prepare("SELECT id FROM sf3_reports WHERE school_year = ? AND grade_level = ? AND section = ?");
        $stmt->execute([$school_year_submit, $grade_level_submit, $section_submit]);
        $report_id = $stmt->fetchColumn();

        if (!$report_id) {
            $stmt = $pdo->prepare("INSERT INTO sf3_reports (teacher_id, school_year, grade_level, section) VALUES (0, ?, ?, ?)");
            $stmt->execute([$school_year_submit, $grade_level_submit, $section_submit]);
            $report_id = $pdo->lastInsertId();
        }

        // Non-destructive sync: Fetch existing items to preserve IDs
        $stmt = $pdo->prepare("SELECT id, subject, title FROM sf3_books_inventory WHERE sf3_report_id = ?");
        $stmt->execute([$report_id]);
        $existing_items = $stmt->fetchAll();
        $existing_map = [];
        foreach ($existing_items as $ei) {
            $key = strtolower(trim($ei['subject'])) . '|' . strtolower(trim($ei['title']));
            $existing_map[$key] = $ei['id'];
        }

        $processed_ids = [];
        if (!empty($_POST['inventory'])) {
            $stmtInsert = $pdo->prepare("INSERT INTO sf3_books_inventory (sf3_report_id, subject, title, total_copies_received, copies_in_good_condition) VALUES (?, ?, ?, ?, ?)");
            $stmtUpdate = $pdo->prepare("UPDATE sf3_books_inventory SET total_copies_received = ?, copies_in_good_condition = ? WHERE id = ?");
            
            foreach ($_POST['inventory'] as $item) {
                if (!empty($item['subject']) && !empty($item['title'])) {
                    $sub = trim($item['subject']);
                    $ttl = trim($item['title']);
                    $key = strtolower($sub) . '|' . strtolower($ttl);
                    $total = $item['total_copies_received'] ?: 0;
                    $good = $item['copies_in_good_condition'] ?: 0;

                    if (isset($existing_map[$key])) {
                        $inv_id = $existing_map[$key];
                        $stmtUpdate->execute([$total, $good, $inv_id]);
                        $processed_ids[] = $inv_id;
                    } else {
                        $stmtInsert->execute([$report_id, $sub, $ttl, $total, $good]);
                        $processed_ids[] = $pdo->lastInsertId();
                    }
                }
            }
        }

        // Remove only items that were actually deleted highlights
        if (!empty($processed_ids)) {
            $placeholders = implode(',', array_fill(0, count($processed_ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM sf3_books_inventory WHERE sf3_report_id = ? AND id NOT IN ($placeholders)");
            $stmt->execute(array_merge([$report_id], $processed_ids));
        } else {
            $stmt = $pdo->prepare("DELETE FROM sf3_books_inventory WHERE sf3_report_id = ?");
            $stmt->execute([$report_id]);
        }

        $pdo->commit();
        $message = "Inventory saved successfully.";
        // Re-assign GET vars so page reloads correctly
        $_GET['school_year'] = $school_year_submit;
        $_GET['grade_level'] = $grade_level_submit;
        $_GET['section'] = $section_submit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Failed to save inventory: " . $e->getMessage();
    }
}

$report = null;
$inventory = [];
$students = [];
$book_records = []; // Initialize book_records

// Fetch available school years for filter dropdown
$school_years = $pdo->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Default school year if not set
if (!$school_year && !empty($school_years)) {
    $school_year = $school_years[0];
}

if ($school_year && $grade_level && $section) {
    // Attempt to fetch existing SF3 Report (not teacher-specific for admin/registrar)
    $stmt = $pdo->prepare("SELECT r.*, u.first_name as teacher_first, u.last_name as teacher_last 
        FROM sf3_reports r LEFT JOIN users u ON r.teacher_id = u.id 
        WHERE r.school_year = ? AND r.grade_level = ? AND r.section = ?");
    $stmt->execute([$school_year, $grade_level, $section]);
    $report = $stmt->fetch();

    if ($report) {
        // Fetch inventory
        $stmt = $pdo->prepare("SELECT * FROM sf3_books_inventory WHERE sf3_report_id = ? ORDER BY id");
        $stmt->execute([$report['id']]);
        $inventory = $stmt->fetchAll();

        // Fetch students along with their book records
        $stmt = $pdo->prepare("
            SELECT e.student_id as lrn, e.student_name, e.student_gender as sex
            FROM enrollments e
            WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ?
            ORDER BY e.student_gender DESC, e.student_name ASC
        ");
        $stmt->execute([$school_year, $grade_level, $section]);
        $students = $stmt->fetchAll();

        // We will inject the book records into the student array
        $stmt = $pdo->prepare("SELECT * FROM sf3_student_books WHERE sf3_report_id = ?");
        $stmt->execute([$report['id']]);
        $book_records = $stmt->fetchAll();

        $books_by_lrn = [];
        foreach ($book_records as $br) {
            $books_by_lrn[$br['student_lrn']][$br['inventory_id']] = $br;
        }

        foreach ($students as &$student) {
            $student['books'] = $books_by_lrn[$student['lrn']] ?? [];
        }

        // Fetch Master Books for lookup
        $stmt = $pdo->query("SELECT id, title, subject, total_copies FROM admin_books");
        $master_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $master_lookup = [];
        foreach ($master_books as $mb) {
            $master_lookup[strtolower(trim($mb['title'])) . '|' . strtolower(trim($mb['subject']))] = $mb;
        }

    } else {
        // If no report exists, but we have filters, we could auto-create it or just let them generate it.
        // For now, fetch students from enrollments to show an empty sheet.
        $stmt = $pdo->prepare("
            SELECT e.student_id as lrn, e.student_name, e.student_gender as sex
            FROM enrollments e
            WHERE e.school_year = ? AND e.grade_level = ? AND e.section = ?
            ORDER BY e.student_gender DESC, e.student_name ASC
        ");
        $stmt->execute([$school_year, $grade_level, $section]);
        $students = $stmt->fetchAll();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Form 3 (SF3) - Books Issued and Returned</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Shared Styles mapped from sf1 / premium UI */
        :root {
            --bg: #f6f8fc;
            --card: #ffffff;
            --muted: #64748b;
            --border: #d7e0ee;
            --primary: #2563eb;
            --primary-600: #1d4ed8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --text-main: #0f172a;
        }

        body {
            background-color: var(--bg);
            margin: 0;
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
        }

        .content {
            padding: 140px 32px 48px;
            max-width: 1400px;
            margin: 0 auto;
            margin-left: 250px;
            box-sizing: border-box;
        }

        .title-block {
            background: #fff;
            padding: 20px 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border);
        }

        .card {
            background: var(--card);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-600);
        }

        .btn-outline {
            border: 1px solid var(--border);
            color: #475569;
            background: white;
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border);
            padding-bottom: 2px;
        }

        .tab {
            padding: 0.5rem 1rem;
            cursor: pointer;
            font-weight: 600;
            color: var(--muted);
            border-bottom: 3px solid transparent;
            margin-bottom: -5px;
            transition: all 0.2s;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th,
        td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            /* Grid feel */
        }

        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #334155;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Distribution specific table styles */
        .distribution-table th.book-col {
            text-align: center;
            background: #eff6ff;
            min-width: 120px;
        }

        .distribution-table td.book-cell {
            text-align: center;
            cursor: pointer;
            transition: background 0.2s;
        }

        .distribution-table td.book-cell:hover {
            background-color: #f1f5f9;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 4px;
        }

        .status-Good {
            background: #dcfce7;
            color: #166534;
        }

        .status-Fair {
            background: #fef9c3;
            color: #854d0e;
        }

        .status-Poor,
        .status-Damaged {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-Lost {
            background: #f3f4f6;
            color: #374151;
        }
        .title-block h1 { margin: 0; font-size: 24px; color: var(--text-main); }
        .title-block p { margin: 5px 0 0 0; color: var(--muted); font-size: 14px; }
    </style>
</head>

<body>
    <?php require_once '../../admin_header.php'; ?>
    <?php require_once '../../admin_sidebar.php'; ?>

    <div class="content main-content">
        <div class="title-block">
            <div>
                <h1>School Form 3 (SF3)</h1>
                <p>Books Issued and Returned (Registrar/Admin View)</p>
                <?php if ($report && !empty($report['teacher_first'])): ?>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #16a34a; font-weight: 600;">
                        📋 Submitted by: <?= htmlspecialchars($report['teacher_last'] . ', ' . $report['teacher_first']) ?>
                    </p>
                <?php endif; ?>
            </div>
            <div>
                <a href="../reports.php" class="btn btn-outline">&larr; Back to Reports</a>
            </div>
        </div>

        <?php if ($message): ?>
                    <div style="background: var(--success); color: white; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                        <?= htmlspecialchars($message) ?>
                    </div>
        <?php endif; ?>
        <?php if ($error): ?>
                    <div style="background: var(--danger); color: white; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                        <?= htmlspecialchars($error) ?>
                    </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="card">
            <form method="GET" action="" id="filterForm">
                <div class="filter-row">
                    <div class="form-group">
                        <label>School Year</label>
                        <select name="school_year" id="school_year" onchange="this.form.submit()">
                            <option value="">Select Year</option>
                            <?php foreach ($school_years as $year): ?>
                                        <option value="<?= htmlspecialchars($year) ?>" <?= $school_year === $year ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($year) ?>
                                        </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Grade Level</label>
                        <select name="grade_level" id="grade_level" onchange="loadSections(this.value)">
                            <option value="">Select Grade</option>
                            <!-- Grade options will be populated by JS, or output here if preferred -->
                            <?php
                            $grades = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($grades as $grade): ?>
                                        <option value="<?= htmlspecialchars($grade) ?>" <?= $grade_level === $grade ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($grade) ?>
                                        </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <select name="section" id="section">
                            <option value="">Select Section</option>
                            <?php if ($grade_level):
                                $sections = $pdo->prepare("SELECT DISTINCT section FROM enrollments WHERE grade_level = ? AND section IS NOT NULL ORDER BY section");
                                $sections->execute([$grade_level]);
                                while ($sec = $sections->fetchColumn()):
                                    ?>
                                                    <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($sec) ?>
                                                    </option>
                                        <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Load SF3</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($school_year && $grade_level && $section): ?>

            <div class="tabs">
                <div class="tab active" onclick="switchTab('inventory')">1. Inventory Preparation</div>
                <?php if (!empty($inventory)): ?>
                    <div class="tab" onclick="switchTab('official-sf3')">2. Official SF3 Form</div>
                <?php endif; ?>
            </div>

            <!-- Inventory Tab -->
            <div id="inventory" class="tab-content active">
                <div class="card">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="save_inventory">
                        <input type="hidden" name="school_year_submit" value="<?= htmlspecialchars($school_year) ?>">
                        <input type="hidden" name="grade_level_submit" value="<?= htmlspecialchars($grade_level) ?>">
                        <input type="hidden" name="section_submit" value="<?= htmlspecialchars($section) ?>">
                        <h3>Available Textbooks</h3>
                                <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: 1rem;">List the textbooks available
                                    for this section before issuing them. Ensure counts represent actual physically available books.
                                </p>

                                <div class="table-responsive">
                                    <table id="inventoryTable">
                                        <thead>
                                            <tr>
                                                <th>Subject Area</th>
                                                <th>Book Title</th>
                                                <th style="width: 150px;">Total Copies</th>
                                                <th style="width: 150px;">Good Condition</th>
                                                <th style="width: 80px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($inventory)): ?>
                                                <?php foreach ($inventory as $index => $book): 
                                                    $key = strtolower(trim($book['title'])) . '|' . strtolower(trim($book['subject']));
                                                    $is_master = isset($master_lookup[$key]);
                                                    $master_book = $is_master ? $master_lookup[$key] : null;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php if ($is_master): ?>
                                                                <div style="font-weight: 500;"><?= htmlspecialchars($book['subject']) ?></div>
                                                                <input type="hidden" name="inventory[<?= $index ?>][subject]" value="<?= htmlspecialchars($book['subject']) ?>">
                                                                <span style="font-size: 0.75rem; background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; border: 1px solid #bbf7d0;">Master List</span>
                                                            <?php else: ?>
                                                                <input type="text" name="inventory[<?= $index ?>][subject]"
                                                                    class="form-group input"
                                                                    style="width: 100%; border: none; outline: none; background: transparent;"
                                                                    value="<?= htmlspecialchars($book['subject']) ?>" required>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($is_master): ?>
                                                                <div style="font-weight: 500;"><?= htmlspecialchars($book['title']) ?></div>
                                                                <input type="hidden" name="inventory[<?= $index ?>][title]" value="<?= htmlspecialchars($book['title']) ?>">
                                                            <?php else: ?>
                                                                <input type="text" name="inventory[<?= $index ?>][title]"
                                                                    style="width: 100%; border: none; outline: none; background: transparent;"
                                                                    value="<?= htmlspecialchars($book['title']) ?>" required>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="inventory[<?= $index ?>][total_copies_received]"
                                                                style="width: 100%; border: none; outline: none; background: transparent;"
                                                                value="<?= $book['total_copies_received'] ?>" min="0" <?= $is_master ? 'readonly' : '' ?>>
                                                            <?php if ($is_master): ?>
                                                                <small style="color: var(--muted); display: block;">Master Stock: <?= $master_book['total_copies'] ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><input type="number" name="inventory[<?= $index ?>][copies_in_good_condition]"
                                                                style="width: 100%; border: none; outline: none; background: transparent;"
                                                                value="<?= $book['copies_in_good_condition'] ?>" min="0"></td>
                                                        <td><button type="button" class="btn btn-outline"
                                                                style="padding: 4px 8px; color: var(--danger); border-color: var(--danger);"
                                                                onclick="this.closest('tr').remove()">Remove</button></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div style="margin-top: 15px; display: flex; justify-content: space-between;">
                                    <button type="button" class="btn btn-outline" onclick="addInventoryRow()">+ Add Book</button>
                                    <button type="submit" class="btn btn-primary">Save Inventory</button>
                                </div>
                            </form>
                        </div>
                    </div>

            <!-- 2. Official SF3 Form Tab -->
            <?php if (!empty($inventory)): ?>
                <div id="official-sf3" class="tab-content">
                    <div class="card" style="margin-bottom: 24px;">
                        <h3>School Form 3 (SF3) - Books Issued and Returned</h3>
                        <p style="color: var(--muted); font-size: 0.9rem; margin-bottom: 1rem;">Click on any learner's cell to
                            enter issuance or return dates. The summary table is located at the bottom of the grid.</p>

                        <div class="table-responsive" style="margin-bottom: 30px;">
                            <table class="distribution-table">
                                <thead>
                                    <tr>
                                        <th rowspan="2" style="width: 40px; text-align: center;">No.</th>
                                        <th rowspan="2" style="min-width: 200px;">Name of Learner</th>
                                        <?php foreach ($inventory as $book): ?>
                                            <th colspan="2" class="book-col">
                                                <?= htmlspecialchars($book['subject']) ?><br>
                                                <span style="font-size: 0.8em; font-weight: normal;">
                                                    <?= htmlspecialchars($book['title']) ?>
                                                </span>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                    <tr>
                                        <?php foreach ($inventory as $book): ?>
                                            <th style="font-size: 0.8rem; text-align: center; background: #f8fafc; min-width: 80px;">Date<br>Issued</th>
                                            <th style="font-size: 0.8rem; text-align: center; background: #f8fafc; min-width: 80px;">Date<br>Returned</th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $num = 1;
                                    $current_sex = '';
                                    foreach ($students as $student):
                                        if ($current_sex !== $student['sex']):
                                            $current_sex = $student['sex'];
                                            $display_sex = $current_sex === 'M' ? 'MALE' : 'FEMALE';
                                            ?>
                                            <tr style="background: #e2e8f0; font-weight: bold;">
                                                <td colspan="<?= 2 + (count($inventory) * 2) ?>">
                                                    <?= $display_sex ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <tr>
                                            <td style="text-align: center;">
                                                <?= $num++ ?>
                                            </td>
                                            <td>
                                                <strong>
                                                    <?= htmlspecialchars($student['student_name']) ?>
                                                </strong><br>
                                                <small style="color: var(--muted);">
                                                    <?= htmlspecialchars($student['lrn']) ?>
                                                </small>
                                            </td>

                                            <?php foreach ($inventory as $book):
                                                $record = $student['books'][$book['id']] ?? null;
                                                ?>
                                                <td class="book-cell"
                                                    onclick="openBookModal('<?= $student['lrn'] ?>', '<?= addslashes($student['student_name']) ?>', <?= $book['id'] ?>, '<?= addslashes($book['title']) ?>', <?= htmlspecialchars(json_encode($record)) ?>)">
                                                    <?php if ($record && $record['date_issued']): ?>
                                                        <?= date('m/d/y', strtotime($record['date_issued'])) ?>
                                                        <?php if($record['condition_issued'] !== 'Good'): ?>
                                                            <br><span style="font-size:0.75rem; color:var(--warning);">(<?= $record['condition_issued'] ?>)</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color: #cbd5e1;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="book-cell" style="border-right: 2px solid var(--border);"
                                                    onclick="openBookModal('<?= $student['lrn'] ?>', '<?= addslashes($student['student_name']) ?>', <?= $book['id'] ?>, '<?= addslashes($book['title']) ?>', <?= htmlspecialchars(json_encode($record)) ?>)">
                                                    <?php if ($record && $record['date_returned']): ?>
                                                        <?= date('m/d/y', strtotime($record['date_returned'])) ?>
                                                        <?php if($record['condition_returned'] === 'Damaged' || $record['condition_returned'] === 'Lost' || !empty($record['remarks'])): ?>
                                                            <br><span style="font-size:0.7rem; color:var(--danger); font-weight:bold;">(<?= $record['condition_returned'] ?>: <?= htmlspecialchars($record['remarks'] ?: '') ?>)</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color: #cbd5e1;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- SF3 Official Summary Table at the Bottom -->
                        <h3 style="border-top: 1px solid var(--border); padding-top: 20px;">Summary Box</h3>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Subject / Title</th>
                                        <th style="text-align: center;">Total Copies (Inventory)</th>
                                        <th style="text-align: center;">Total Issued</th>
                                        <th style="text-align: center;">Returned (Good/Fair)</th>
                                        <th style="text-align: center;">Damaged</th>
                                        <th style="text-align: center;">Lost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory as $book):
                                        // Count stats for this book
                                        $issued = 0;
                                        $returned = 0;
                                        $damaged = 0;
                                        $lost = 0;

                                        foreach ($students as $student) {
                                            if (isset($student['books'][$book['id']])) {
                                                $br = $student['books'][$book['id']];
                                                if ($br['date_issued']) $issued++;
                                                if ($br['date_returned']) {
                                                    if ($br['condition_returned'] === 'Damaged') $damaged++;
                                                    elseif ($br['condition_returned'] === 'Lost') $lost++;
                                                    else $returned++;
                                                }
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($book['subject']) ?></strong><br><small style="color:var(--muted);"><?= htmlspecialchars($book['title']) ?></small></td>
                                            <td style="text-align: center;"><?= $book['total_copies_received'] ?></td>
                                            <td style="text-align: center; color:var(--primary); font-weight:bold;"><?= $issued ?></td>
                                            <td style="text-align: center; color:var(--success); font-weight:bold;"><?= $returned ?></td>
                                            <td style="text-align: center; color:var(--danger);"><?= $damaged ?></td>
                                            <td style="text-align: center; color:#333; font-weight:bold;"><?= $lost ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end;">
                            <div style="text-align: center; width: 250px;">
                                <div style="border-bottom: 1px solid #000; min-height: 20px; font-weight: 700;"></div>
                                <div style="font-size: 11px; margin-top: 5px; color: var(--muted);">Class Adviser</div>
                            </div>
                            <div style="text-align: center; width: 250px;">
                                <div style="border-bottom: 1px solid #000; min-height: 20px; font-weight: 700; text-transform: uppercase;">
                                    <?php $principal_name = get_system_setting($pdo, 'principal_name', 'School Head'); echo strtoupper($principal_name); ?>
                                </div>
                                <div style="font-size: 11px; margin-top: 5px; color: var(--muted);">School Head / Principal</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            event.target.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        // Dependent Dropdown for Grade to Section
        function loadSections(grade) {
            if (!grade) {
                document.getElementById('section').innerHTML = '<option value="">Select Section</option>';
                return;
            }

            fetch(`../reports/sf1_form.php?action=get_sections_by_grade&grade_level=${encodeURIComponent(grade)}`)
                .then(res => res.json())
                .then(data => {
                    const sectionSelect = document.getElementById('section');
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    if (data.success && data.data) {
                        data.data.forEach(sec => {
                            const option = document.createElement('option');
                            option.value = sec;
                            option.textContent = sec;
                            sectionSelect.appendChild(option);
                        });
                    }
                })
                .catch(err => console.error("Error loading sections:", err));
        }

        let inventoryIndex = <?= count($inventory) ?>;
        function addInventoryRow() {
            const tbody = document.querySelector('#inventoryTable tbody');
            const tr = document.createElement('tr');
            tr.innerHTML = `
            <td><input type="text" name="inventory[${inventoryIndex}][subject]" style="width: 100%; border: none; outline: none; background: transparent; padding: 0.6rem; border: 1px solid var(--border); border-radius: 4px;" required placeholder="e.g. Math"></td>
            <td><input type="text" name="inventory[${inventoryIndex}][title]" style="width: 100%; border: none; outline: none; background: transparent; padding: 0.6rem; border: 1px solid var(--border); border-radius: 4px;" required placeholder="Title"></td>
            <td><input type="number" name="inventory[${inventoryIndex}][total_copies_received]" style="width: 100%; border: none; outline: none; background: transparent; padding: 0.6rem; border: 1px solid var(--border); border-radius: 4px;" min="0" value="0"></td>
            <td><input type="number" name="inventory[${inventoryIndex}][copies_in_good_condition]" style="width: 100%; border: none; outline: none; background: transparent; padding: 0.6rem; border: 1px solid var(--border); border-radius: 4px;" min="0" value="0"></td>
            <td><button type="button" class="btn btn-outline" style="padding: 4px 8px; color: var(--danger); border-color: var(--danger);" onclick="this.closest('tr').remove()">Remove</button></td>
        `;
            tbody.appendChild(tr);
            inventoryIndex++;
        }

    <!-- Book Modal -->
<div id="bookModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="margin: 0;" id="modalTitle">Record Book</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="bookForm" onsubmit="saveBookRecord(event)">
                <input type="hidden" id="modal_sf3_report_id" value="<?= $report['id'] ?? '' ?>">
                <input type="hidden" id="modal_student_lrn">
                <input type="hidden" id="modal_inventory_id">

                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label>Date Issued <small style="color:var(--muted); font-weight:normal;">(BoSY)</small></label>
                        <input type="date" id="modal_date_issued" class="input">
                    </div>
                    <div class="form-group">
                        <label>Issue Condition</label>
                        <select id="modal_condition_issued" class="input">
                            <option value="Good">Good (New/Slightly Used)</option>
                            <option value="Fair">Fair (Usable)</option>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; border-top: 1px dashed var(--border); padding-top: 1rem;">
                    <div class="form-group">
                        <label>Date Returned <small style="color:var(--muted); font-weight:normal;">(EoSY)</small></label>
                        <input type="date" id="modal_date_returned" class="input">
                    </div>
                    <div class="form-group">
                        <label>Return Condition / Status</label>
                        <select id="modal_condition_returned" class="input">
                            <option value="">-- Pending Return --</option>
                            <option value="Good">Good (Usable)</option>
                            <option value="Fair">Fair (With minor issues)</option>
                            <option value="Damaged">Damaged (For disposal/repair)</option>
                            <option value="Lost">Lost (Needs replacement)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label>Remarks / Actions Taken</label>
                    <input type="text" id="modal_remarks" class="input" placeholder="e.g. Lost - Replaced by parent, Damaged - For repair, Transferred... ">
                    <small style="color: var(--muted); display: block; margin-top: 4px;">If lost, indicate if parent replaced or paid. If transferred out, note the date.</small>
                </div>

                <div style="text-align: right; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="closeModal()" style="margin-right: 0.5rem;">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Modal Styles */
.modal {
    display: none; position: fixed; z-index: 1000; left: 0; top: 0;
    width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
}
.modal-content {
    background-color: var(--card); margin: 10% auto; padding: 0;
    border: 1px solid var(--border); width: 450px; border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.modal-header {
    padding: 1rem 1.5rem; border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
}
.modal-body { padding: 1.5rem; }
.close { color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; }
.close:hover { color: #000; }
.input { width: 100%; padding: 0.6rem; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; }
</style>

<script>
    function openBookModal(lrn, studentName, bookId, bookTitle, record) {
        document.getElementById('modalTitle').innerText = `${studentName} - ${bookTitle}`;
        document.getElementById('modal_student_lrn').value = lrn;
        document.getElementById('modal_inventory_id').value = bookId;

        if (record) {
            document.getElementById('modal_date_issued').value = record.date_issued || '';
            document.getElementById('modal_condition_issued').value = record.condition_issued || 'Good';
            document.getElementById('modal_date_returned').value = record.date_returned || '';
            document.getElementById('modal_condition_returned').value = record.condition_returned || '';
            document.getElementById('modal_remarks').value = record.remarks || '';
        } else {
            document.getElementById('bookForm').reset();
            document.getElementById('modal_condition_issued').value = 'Good';
        }

        document.getElementById('bookModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('bookModal').style.display = 'none';
    }

    async function saveBookRecord(e) {
        e.preventDefault();
        
        const reportId = document.getElementById('modal_sf3_report_id').value;
        if (!reportId) {
            alert('Error: No SF3 Report ID found. Please save inventory first.');
            return;
        }

        const formData = new FormData();
        formData.append('ajax_action', 'save_book_record');
        formData.append('sf3_report_id', reportId);
        formData.append('student_lrn', document.getElementById('modal_student_lrn').value);
        formData.append('inventory_id', document.getElementById('modal_inventory_id').value);
        formData.append('date_issued', document.getElementById('modal_date_issued').value);
        formData.append('condition_issued', document.getElementById('modal_condition_issued').value);
        formData.append('date_returned', document.getElementById('modal_date_returned').value);
        formData.append('condition_returned', document.getElementById('modal_condition_returned').value);
        formData.append('remarks', document.getElementById('modal_remarks').value);

        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            
            if (data.success) {
                closeModal();
                // Simple reload to refresh table vs complex DOM manipulation
                window.location.reload(); 
            } else {
                alert('Error saving record: ' + (data.message || 'Unknown error'));
            }
        } catch (err) {
            console.error(err);
            alert('Failed to connect to server.');
        }
    }

    window.onclick = function(event) {
        if (event.target == document.getElementById('bookModal')) {
            closeModal();
        }
    }
</script>

</body>

</html>
