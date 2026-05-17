<?php
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/config/db.php';

$pdo = db_connect();

// Get active school year correctly
$current_school_year = get_active_school_year($pdo);
$current_school_year_id = get_system_setting($pdo, 'active_school_year_id', '');

auth_require_role(['teacher', 'admin']);

// Ensure required tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS textbook_inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        textbook_id INT NOT NULL,
        total_stock INT DEFAULT 0,
        available_stock INT DEFAULT 0,
        distributed_stock INT DEFAULT 0,
        missing_stock INT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_textbook_id (textbook_id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS textbook_distributions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        textbook_id INT NOT NULL,
        accession_no VARCHAR(100),
        date_issued DATE NOT NULL,
        due_date DATE,
        status ENUM('Active', 'Returned', 'Missing', 'Damaged', 'Overdue') DEFAULT 'Active',
        condition_issued VARCHAR(50) DEFAULT 'Good',
        adviser_id INT NOT NULL,
        section_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS textbook_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        distribution_id INT NOT NULL,
        return_date DATE NOT NULL,
        condition_returned VARCHAR(50) NOT NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_dist (distribution_id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS textbook_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        distribution_id INT,
        action VARCHAR(255) NOT NULL,
        date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        performed_by INT NOT NULL,
        remarks TEXT
    ) ENGINE=InnoDB");

} catch (PDOException $e) {}

// Helper: Log Textbook Transaction
function logTextbookTransaction($pdo, $distribution_id, $action, $performed_by, $remarks = '') {
    $stmt = $pdo->prepare("INSERT INTO textbook_history (distribution_id, action, performed_by, remarks) VALUES (?, ?, ?, ?)");
    $stmt->execute([$distribution_id, $action, $performed_by, $remarks]);
}

// Helper: Update Stock
function updateTextbookStock($pdo, $textbook_id) {
    $stmt = $pdo->prepare("SELECT total_copies FROM admin_books WHERE id = ?");
    $stmt->execute([$textbook_id]);
    $total_copies = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT 
        COUNT(CASE WHEN status = 'Active' OR status = 'Overdue' THEN 1 END) as distributed,
        COUNT(CASE WHEN status = 'Missing' OR status = 'Damaged' THEN 1 END) as missing
        FROM textbook_distributions WHERE textbook_id = ?");
    $stmt->execute([$textbook_id]);
    $stats = $stmt->fetch();

    $stmt = $pdo->prepare("INSERT INTO textbook_inventory (textbook_id, total_stock, distributed_stock, missing_stock, available_stock) 
                           VALUES (?, ?, ?, ?, ? - ? - ?)
                           ON DUPLICATE KEY UPDATE 
                           total_stock = VALUES(total_stock),
                           distributed_stock = VALUES(distributed_stock),
                           missing_stock = VALUES(missing_stock),
                           available_stock = VALUES(available_stock)");
    $stmt->execute([
        $textbook_id, $total_copies, (int)$stats['distributed'], (int)$stats['missing'],
        $total_copies, (int)$stats['distributed'], (int)$stats['missing']
    ]);
}

// AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $user_id = $_SESSION['user']['id'];

    try {
        if ($action === 'distribute_book') {
            $student_id = $_POST['student_lrn'] ?? null;
            $book_ids = $_POST['book_ids'] ?? [];
            $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
            $due_date = $_POST['due_date'] ?? null;
            $accession_no = $_POST['accession_no'] ?? null;
            $condition_issued = $_POST['condition_issued'] ?? 'Good';
            $section_id = $_POST['section_id'] ?? 0;

            if (!$student_id || empty($book_ids)) throw new Exception('Missing student or book selection.');

            $pdo->beginTransaction();
            foreach ($book_ids as $textbook_id) {
                // Check stock
                $stmt = $pdo->prepare("SELECT available_stock FROM textbook_inventory WHERE textbook_id = ?");
                $stmt->execute([$textbook_id]);
                $stock = $stmt->fetchColumn();
                if ($stock <= 0) continue;

                $stmt = $pdo->prepare("INSERT INTO textbook_distributions (student_id, textbook_id, date_issued, due_date, accession_no, status, condition_issued, adviser_id, section_id) VALUES (?, ?, ?, ?, ?, 'Active', ?, ?, ?)");
                $stmt->execute([$student_id, $textbook_id, $date_issued, $due_date, $accession_no, $condition_issued, $user_id, $section_id]);
                $dist_id = $pdo->lastInsertId();
                
                updateTextbookStock($pdo, $textbook_id);
                logTextbookTransaction($pdo, $dist_id, 'Distribution', $user_id, "Issued to $student_id (Accession: $accession_no)");
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } 
        elseif ($action === 'bulk_distribute') {
            $student_ids = $_POST['student_ids'] ?? [];
            $book_ids = $_POST['book_ids'] ?? [];
            $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
            $section_id = $_POST['section_id'] ?? 0;

            if (empty($student_ids) || empty($book_ids)) throw new Exception('Select students and books.');

            $pdo->beginTransaction();
            foreach ($student_ids as $sid) {
                foreach ($book_ids as $bid) {
                    $check = $pdo->prepare("SELECT id FROM textbook_distributions WHERE student_id = ? AND textbook_id = ? AND status = 'Active'");
                    $check->execute([$sid, $bid]);
                    if ($check->fetch()) continue;

                    $stmt = $pdo->prepare("SELECT available_stock FROM textbook_inventory WHERE textbook_id = ?");
                    $stmt->execute([$bid]);
                    if ($stmt->fetchColumn() <= 0) continue;

                    $stmt = $pdo->prepare("INSERT INTO textbook_distributions (student_id, textbook_id, date_issued, status, adviser_id, section_id) VALUES (?, ?, ?, 'Active', ?, ?)");
                    $stmt->execute([$sid, $bid, $date_issued, $user_id, $section_id]);
                    updateTextbookStock($pdo, $bid);
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        }
        elseif ($action === 'collect_book') {
            $dist_id = $_POST['distribution_id'] ?? null;
            $return_date = $_POST['return_date'] ?? date('Y-m-d');
            $condition_returned = $_POST['condition_returned'] ?? 'Good';
            $remarks = $_POST['remarks'] ?? '';

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT textbook_id FROM textbook_distributions WHERE id = ?");
            $stmt->execute([$dist_id]);
            $textbook_id = $stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE textbook_distributions SET status = 'Returned' WHERE id = ?");
            $stmt->execute([$dist_id]);

            $stmt = $pdo->prepare("INSERT INTO textbook_returns (distribution_id, return_date, condition_returned, remarks) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE return_date=VALUES(return_date), condition_returned=VALUES(condition_returned), remarks=VALUES(remarks)");
            $stmt->execute([$dist_id, $return_date, $condition_returned, $remarks]);

            updateTextbookStock($pdo, $textbook_id);
            logTextbookTransaction($pdo, $dist_id, 'Return', $user_id, "Returned ($condition_returned)");
            $pdo->commit();
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Data Fetching
$teacher_id = $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT id, grade_level, section_name, school_year FROM sections WHERE adviser_id = ? ORDER BY school_year DESC LIMIT 1");
$stmt->execute([$teacher_id]);
$section_info = $stmt->fetch();

$section_id = $section_info['id'] ?? 0;
$teacher_grade = $section_info['grade_level'] ?? 'N/A';
$teacher_section = $section_info['section_name'] ?? 'N/A';
$teacher_sy = $section_info['school_year'] ?? 'N/A';

// Correct grade mapping for inventory
$numeric_grade = trim(str_ireplace('Grade ', '', (string)$teacher_grade));
$string_grade = (strtolower($numeric_grade) === 'kindergarten') ? 'Kindergarten' : 'Grade ' . $numeric_grade;

// Inventory
$stmt = $pdo->prepare("
    SELECT t.*, i.total_stock, i.available_stock, i.distributed_stock, i.missing_stock
    FROM admin_books t
    LEFT JOIN textbook_inventory i ON t.id = i.textbook_id
    WHERE t.grade_level = ? OR t.grade_level = 'All Grades'
    ORDER BY t.subject, t.title
");
$stmt->execute([$string_grade]);
$class_inventory = $stmt->fetchAll();

// Students with Books Received Count
$enrolled_students = [];
if ($section_id) {
    $stmt = $pdo->prepare("
        SELECT e.student_id as lrn, e.student_name, e.grade_level, e.section,
               (SELECT COUNT(*) FROM textbook_distributions d WHERE d.student_id = e.student_id AND d.status = 'Active') as books_count
        FROM enrollments e 
        WHERE e.section = ? AND e.grade_level = ? AND e.school_year = ? 
        ORDER BY e.student_name ASC
    ");
    $stmt->execute([$teacher_section, $teacher_grade, $teacher_sy]);
    $enrolled_students = $stmt->fetchAll();
}

// Distributions for Monitoring - Robust Fetch
$distributions = [];
$grouped_dists = [];
if (!empty($enrolled_students)) {
    $lrns = array_column($enrolled_students, 'lrn');
    $placeholders = str_repeat('?,', count($lrns) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT d.*, t.title, t.subject, ret.return_date, ret.condition_returned as cond_ret
        FROM textbook_distributions d
        JOIN admin_books t ON d.textbook_id = t.id
        LEFT JOIN textbook_returns ret ON d.id = ret.distribution_id
        WHERE d.student_id IN ($placeholders)
        ORDER BY d.date_issued DESC
    ");
    $stmt->execute($lrns);
    $raw_dist_list = $stmt->fetchAll();
    
    // Map names from roster
    $name_map = array_column($enrolled_students, 'student_name', 'lrn');
    foreach ($raw_dist_list as $d) {
        $lrn = $d['student_id'];
        $d['student_name'] = $name_map[$lrn] ?? 'Unknown Student';
        $distributions[] = $d;
        
        if (!isset($grouped_dists[$lrn])) {
            $grouped_dists[$lrn] = ['name' => $d['student_name'], 'books' => []];
        }
        $grouped_dists[$lrn]['books'][] = $d;
    }
}

// Summary Stats - DYNAMIC & SYNCED
$summary = ['total_inv' => 0, 'available' => 0, 'distributed' => 0, 'missing' => 0];
foreach ($class_inventory as $item) {
    $summary['total_inv'] += $item['total_stock'] ?? 0;
}
$summary['distributed'] = array_sum(array_column($enrolled_students, 'books_count'));
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM textbook_distributions d 
    JOIN enrollments e ON d.student_id = e.student_id 
    WHERE e.section = ? AND e.grade_level = ? AND e.school_year = ? AND d.status IN ('Missing', 'Damaged')
");
$stmt->execute([$teacher_section, $teacher_grade, $teacher_sy]);
$summary['missing'] = (int)$stmt->fetchColumn();
$summary['available'] = $summary['total_inv'] - $summary['distributed'] - $summary['missing'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Textbook Lifecycle & SF3 | MMFSL</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .main-content { padding: 25px; padding-top: 100px; transition: all 0.3s ease; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .nav-pills .nav-link { border-radius: 8px; font-weight: 600; padding: 10px 20px; color: #555; }
        .nav-pills .nav-link.active { background-color: #0d6efd; color: #fff; box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2); }
        .summary-card { padding: 20px; border-left: 5px solid #0d6efd; }
        .summary-val { font-size: 24px; font-weight: 700; display: block; }
        .summary-label { font-size: 11px; color: #777; font-weight: 600; text-transform: uppercase; }
        .badge { padding: 6px 10px; border-radius: 6px; font-size: 10px; font-weight: 600; }
        .status-active { background-color: #e0f2fe; color: #0369a1; }
        .status-returned { background-color: #dcfce7; color: #15803d; }
        .status-missing { background-color: #fee2e2; color: #b91c1c; }
        .status-overdue { background-color: #fef3c7; color: #92400e; }
        .cond-good { background-color: #dcfce7; color: #15803d; }
        .cond-fair { background-color: #fef3c7; color: #92400e; }
        .cond-damaged { background-color: #fee2e2; color: #b91c1c; }
        .table th { font-size: 11px; text-transform: uppercase; color: #6b7280; }
        .modal { z-index: 2000 !important; }
        .modal-backdrop { z-index: 1990 !important; }
        .tab-content { margin-top: 20px; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/teacher_header.php'; ?>
    <?php require_once __DIR__ . '/teacher_side_panel.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold m-0 text-primary">Textbook Lifecycle & SF3</h2>
                <p class="text-muted small mb-0"><?= htmlspecialchars($teacher_grade) ?> - <?= htmlspecialchars($teacher_section) ?> (SY <?= htmlspecialchars($teacher_sy) ?>)</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary btn-sm px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#bulkDistModal">
                    <i class="fas fa-layer-group me-1"></i> Bulk Distribute
                </button>
                <a href="<?= url_for('/teacher/reports/sf3_form.php') ?>?grade=<?=urlencode($teacher_grade)?>&section=<?=urlencode($teacher_section)?>&sy=<?=urlencode($teacher_sy)?>" class="btn btn-outline-success btn-sm px-3">
                    <i class="fas fa-file-export me-1"></i> Generate SF3 Report
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row g-4 mb-4 text-center">
            <div class="col-md-3"><div class="card summary-card" style="border-color:#3b82f6"><span class="summary-val text-primary"><?= $summary['total_inv'] ?></span><span class="summary-label">Total Inventory</span></div></div>
            <div class="col-md-3"><div class="card summary-card" style="border-color:#10b981"><span class="summary-val text-success"><?= $summary['available'] ?></span><span class="summary-label">Available</span></div></div>
            <div class="col-md-3"><div class="card summary-card" style="border-color:#f59e0b"><span class="summary-val text-warning"><?= $summary['distributed'] ?></span><span class="summary-label">Distributed</span></div></div>
            <div class="col-md-3"><div class="card summary-card" style="border-color:#ef4444"><span class="summary-val text-danger"><?= $summary['missing'] ?></span><span class="summary-label">Missing / Damaged</span></div></div>
        </div>

        <!-- Tabs -->
        <div class="card p-2 mb-4 border-0 shadow-sm bg-white">
            <ul class="nav nav-pills nav-fill" id="textbookTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-dist">
                        <i class="fas fa-users-viewfinder me-2"></i> 1. Distribution Roster
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-mon">
                        <i class="fas fa-clipboard-list me-2"></i> 2. Monitoring & Collection
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content" id="textbookTabContent">
            <!-- TAB 1: DISTRIBUTION -->
            <div class="tab-pane fade show active" id="tab-dist">
                <div class="card border-0 shadow-sm p-4">
                    <?php if (empty($enrolled_students)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users-slash fa-4x text-muted opacity-25 mb-3"></i>
                            <h5 class="fw-bold">No Students Enrolled</h5>
                            <p class="text-muted">Please contact registrar to finalize your advisory roster.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table datatable w-100 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student Name</th>
                                        <th>LRN</th>
                                        <th>Books Count</th>
                                        <th>Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_students as $s): ?>
                                        <tr>
                                            <td class="fw-bold"><?= htmlspecialchars($s['student_name']) ?></td>
                                            <td><code><?= htmlspecialchars($s['lrn']) ?></code></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-bold small"><?= $s['books_count'] ?></span>
                                                    <div class="progress flex-grow-1" style="height:4px; width:50px;">
                                                        <div class="progress-bar" style="width: <?= min(100, ($s['books_count'] / 8) * 100) ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($s['books_count'] == 0): ?>
                                                    <span class="badge bg-light text-muted border">No Books</span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" onclick="openDistModal('<?= $s['lrn'] ?>', '<?= addslashes($s['student_name']) ?>')">
                                                    <i class="fas fa-plus-circle me-1"></i> Distribute
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 2: MONITORING -->
            <div class="tab-pane fade" id="tab-mon">
                <div class="card border-0 shadow-sm p-4">
                    <?php if (empty($grouped_dists)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-book-open fa-4x text-muted opacity-25 mb-3"></i>
                            <h5 class="fw-bold">No Distribution Records</h5>
                            <p class="text-muted">Start by issuing books in the Distribution tab.</p>
                        </div>
                    <?php else: ?>
                        <div class="accordion accordion-flush" id="monitoringAccordion">
                            <?php foreach ($grouped_dists as $lrn => $data): ?>
                                <div class="accordion-item border rounded mb-3 overflow-hidden shadow-sm">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?= $lrn ?>">
                                            <div class="d-flex align-items-center w-100">
                                                <div class="avatar-sm bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width:35px; height:35px;">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($data['name']) ?></div>
                                                    <div class="small text-muted"><?= count($data['books']) ?> Books Issued</div>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse_<?= $lrn ?>" class="accordion-collapse collapse" data-bs-parent="#monitoringAccordion">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                                            <th class="ps-4">Textbook Title</th>
                                                            <th>Issued</th>
                                                            <th>Returned</th>
                                                            <th>Status</th>
                                                            <th class="text-center pe-4">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($data['books'] as $d): ?>
                                                            <tr>
                                                                <td class="ps-4">
                                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($d['title']) ?></div>
                                                                    <div class="small text-muted"><?= htmlspecialchars($d['subject']) ?></div>
                                                                </td>
                                                                <td><div class="small fw-bold"><?= date('n/j/Y', strtotime($d['date_issued'])) ?></div></td>
                                                                <td>
                                                                    <?php if ($d['return_date']): ?>
                                                                        <div class="small fw-bold text-success"><i class="fas fa-calendar-check me-1"></i><?= date('n/j/Y', strtotime($d['return_date'])) ?></div>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small italic">--</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><span class="badge status-<?= strtolower($d['status']) ?>"><?= $d['status'] ?></span></td>
                                                                <td class="text-center pe-4">
                                                                    <div class="btn-group btn-group-sm">
                                                                        <?php if ($d['status'] !== 'Returned'): ?>
                                                                            <button class="btn btn-success" onclick="collectBookModal(<?= $d['id'] ?>, '<?= addslashes($d['title']) ?>', '<?= addslashes($data['name']) ?>')">
                                                                                <i class="fas fa-undo me-1"></i> Return
                                                                            </button>
                                                                        <?php else: ?>
                                                                            <button class="btn btn-outline-secondary disabled"><i class="fas fa-check-circle"></i></button>
                                                                        <?php endif; ?>
                                                                        <button class="btn btn-outline-primary" onclick="updateStatusModal(<?= $d['id'] ?>, '<?= $d['status'] ?>', '<?= $d['condition_issued'] ?>')"><i class="fas fa-edit"></i></button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODALS -->
    <div class="modal fade" id="distModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="distForm" class="modal-content">
                <input type="hidden" name="ajax_action" value="distribute_book">
                <input type="hidden" name="student_lrn" id="dist_student_lrn">
                <input type="hidden" name="section_id" value="<?= $section_id ?>">
                <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Distribute Textbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 d-flex align-items-center mb-4"><i class="fas fa-user-graduate me-3 fa-lg"></i><div><div class="small fw-bold">Student</div><h6 class="mb-0" id="dist_student_name"></h6></div></div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold">Select Available Books</label>
                            <div class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach ($class_inventory as $b): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="book_ids[]" value="<?= $b['id'] ?>" id="b_<?= $b['id'] ?>" <?= $b['available_stock'] <= 0 ? 'disabled' : '' ?>>
                                        <label class="form-check-label d-flex justify-content-between w-100" for="b_<?= $b['id'] ?>">
                                            <span class="small fw-bold"><?= htmlspecialchars($b['title']) ?></span>
                                            <span class="badge <?= $b['available_stock'] > 0 ? 'bg-success' : 'bg-danger' ?>"><?= $b['available_stock'] ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="mb-3"><label class="form-label small fw-bold">Date Issued</label><input type="date" name="date_issued" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="submit" class="btn btn-primary px-4 shadow-sm">Confirm Distribution</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="collectModal" tabindex="-1">
        <div class="modal-dialog">
            <form id="collectForm" class="modal-content">
                <input type="hidden" name="ajax_action" value="collect_book">
                <input type="hidden" name="distribution_id" id="coll_dist_id">
                <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Return Textbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="bg-light p-3 rounded mb-3"><div class="small text-muted mb-1">Book</div><h6 class="fw-bold mb-2" id="coll_book_title"></h6><div class="small text-muted mb-1">Student</div><h6 class="fw-bold m-0" id="coll_student_name"></h6></div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small fw-bold">Date Returned</label><input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Condition</label><select name="condition_returned" class="form-select"><option value="Good">Good</option><option value="Fair">Fair</option><option value="Damaged">Damaged</option><option value="Lost">Lost</option></select></div>
                        <div class="col-12"><label class="form-label small fw-bold">Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="submit" class="btn btn-success px-4 shadow-sm">Confirm Return</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="bulkDistModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="bulkDistForm" class="modal-content">
                <input type="hidden" name="ajax_action" value="bulk_distribute">
                <input type="hidden" name="section_id" value="<?= $section_id ?>">
                <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Bulk Distribution</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Select Students</label>
                            <div class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($enrolled_students as $s): ?>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" name="student_ids[]" value="<?= $s['lrn'] ?>" id="s_<?= $s['lrn'] ?>">
                                        <label class="form-check-label small" for="s_<?= $s['lrn'] ?>"><?= htmlspecialchars($s['student_name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Select Books</label>
                            <div class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($class_inventory as $b): ?>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" name="book_ids[]" value="<?= $b['id'] ?>" id="bulk_b_<?= $b['id'] ?>" <?= $b['available_stock'] <= 0 ? 'disabled' : '' ?>>
                                        <label class="form-check-label small" for="bulk_b_<?= $b['id'] ?>"><?= htmlspecialchars($b['title']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="submit" class="btn btn-primary px-4 shadow-sm">Process Bulk Distribution</button></div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Tab Persistence
            const activeTab = localStorage.getItem('activeTextbookTab');
            if (activeTab) {
                const tabEl = document.querySelector(`button[data-bs-target="${activeTab}"]`);
                if (tabEl) {
                    const tab = new bootstrap.Tab(tabEl);
                    tab.show();
                }
            }

            $('button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                localStorage.setItem('activeTextbookTab', $(e.target).data('bs-target'));
            });

            $('.datatable').DataTable({ pageLength: 10, language: { search: "_INPUT_", searchPlaceholder: "Search records..." } });
            $('#distForm, #bulkDistForm, #collectForm').on('submit', function(e) {
                e.preventDefault();
                const $form = $(this);
                const $btn = $form.find('button[type="submit"]');
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Processing...');
                
                $.post('books.php', $form.serialize(), function(res) {
                    if (res.success) { 
                        // Hide modal immediately
                        $('.modal.show').each(function() {
                            const instance = bootstrap.Modal.getInstance(this);
                            if (instance) instance.hide();
                        });
                        Swal.fire({ title: 'Success', text: 'Action completed successfully', icon: 'success', confirmButtonColor: '#2563eb' }).then(() => location.reload()); 
                    } else { 
                        $btn.prop('disabled', false).text('Confirm Action');
                        Swal.fire('Error', res.message || 'Action failed', 'error'); 
                    }
                }, 'json').fail(function() {
                    $btn.prop('disabled', false).text('Confirm Action');
                    Swal.fire('Error', 'Server connection failed', 'error');
                });
            });
        });

        function getOrInitModal(id) {
            const el = document.getElementById(id);
            let instance = bootstrap.Modal.getInstance(el);
            if (!instance) instance = new bootstrap.Modal(el);
            return instance;
        }

        function openDistModal(lrn, name) { 
            $('#dist_student_lrn').val(lrn); 
            $('#dist_student_name').text(name); 
            getOrInitModal('distModal').show(); 
        }

        function collectBookModal(id, title, student) { 
            $('#coll_dist_id').val(id); 
            $('#coll_book_title').text(title); 
            $('#coll_student_name').text(student); 
            getOrInitModal('collectModal').show(); 
        }

        function viewHistory(id) { Swal.fire('History', 'Audit logs are recorded in the database.', 'info'); }
    </script>
</body>
</html>