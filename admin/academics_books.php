<?php
require_once '../auth/auth.php';
auth_require_role(['admin', 'registrar', 'property_custodian']);
require_once '../config/db.php';

$pdo = db_connect();
$current_user_id = $_SESSION['user']['id'];

// --- DATABASE SCHEMA SELF-CORRECTION ---
try {
    $pdo->exec("ALTER TABLE admin_books ADD COLUMN IF NOT EXISTS isbn VARCHAR(50) AFTER title");
    $pdo->exec("ALTER TABLE admin_books ADD COLUMN IF NOT EXISTS author VARCHAR(255) AFTER isbn");
    $pdo->exec("ALTER TABLE admin_books ADD COLUMN IF NOT EXISTS publisher VARCHAR(255) AFTER author");
    $pdo->exec("ALTER TABLE admin_books ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Archived') DEFAULT 'Active'");
    $pdo->exec("ALTER TABLE admin_books ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT 'Textbook' AFTER subject");
    $pdo->exec("ALTER TABLE textbook_inventory ADD COLUMN IF NOT EXISTS damaged_stock INT DEFAULT 0 AFTER distributed_stock");
    $pdo->exec("ALTER TABLE textbook_distributions ADD COLUMN IF NOT EXISTS due_date DATE AFTER date_issued");
    $pdo->exec("ALTER TABLE textbook_distributions ADD COLUMN IF NOT EXISTS accession_no VARCHAR(100) AFTER textbook_id");
    
    // AUTO-SYNC: Ensure all admin_books have an inventory record
    $pdo->exec("INSERT IGNORE INTO textbook_inventory (textbook_id, total_stock, available_stock) 
                SELECT id, total_copies, total_copies FROM admin_books");
                
} catch (Exception $e) {}

// --- HELPER FUNCTIONS ---
function get_textbook_summary($pdo) {
    $summary = [
        'total_books' => 0,
        'total_stocks' => 0,
        'available' => 0,
        'distributed' => 0,
        'returned' => 0,
        'missing' => 0,
        'damaged' => 0,
        'overdue' => 0
    ];
    
    $summary['total_books'] = (int)$pdo->query("SELECT COUNT(*) FROM admin_books WHERE status = 'Active'")->fetchColumn();
    $stocks = $pdo->query("SELECT 
        IFNULL(SUM(total_stock), 0), 
        IFNULL(SUM(available_stock), 0), 
        IFNULL(SUM(distributed_stock), 0), 
        IFNULL(SUM(missing_stock), 0), 
        IFNULL(SUM(damaged_stock), 0) 
        FROM textbook_inventory i 
        JOIN admin_books b ON i.textbook_id = b.id 
        WHERE b.status = 'Active'")->fetch(PDO::FETCH_NUM);
    
    $summary['total_stocks'] = (int)($stocks[0] ?? 0);
    $summary['available'] = (int)($stocks[1] ?? 0);
    $summary['distributed'] = (int)($stocks[2] ?? 0);
    $summary['missing'] = (int)($stocks[3] ?? 0);
    $summary['damaged'] = (int)($stocks[4] ?? 0);
    
    $summary['returned'] = (int)$pdo->query("SELECT COUNT(*) FROM textbook_distributions WHERE status = 'Returned'")->fetchColumn();
    $summary['overdue'] = (int)$pdo->query("SELECT COUNT(*) FROM textbook_distributions WHERE (status = 'Overdue' OR (status = 'Active' AND due_date < CURRENT_DATE))")->fetchColumn();
    
    return $summary;
}

// --- AJAX HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    try {
        if ($action === 'get_stats') {
            echo json_encode(['success' => true, 'stats' => get_textbook_summary($pdo)]);
            exit;
        }
        
        if ($action === 'save_book') {
            $id = $_POST['id'] ?? null;
            $title = $_POST['title'];
            $subject = $_POST['subject'];
            $category = $_POST['category'] ?? 'Textbook';
            $isbn = $_POST['isbn'] ?? '';
            $author = $_POST['author'] ?? '';
            $publisher = $_POST['publisher'] ?? '';
            $grade = $_POST['grade_level'] ?? '';
            $total_copies = (int)$_POST['total_copies'];

            if ($id) {
                // Update
                $stmt = $pdo->prepare("UPDATE admin_books SET title=?, subject=?, category=?, isbn=?, author=?, publisher=?, grade_level=?, total_copies=? WHERE id=?");
                $stmt->execute([$title, $subject, $category, $isbn, $author, $publisher, $grade, $total_copies, $id]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO admin_books (title, subject, category, isbn, author, publisher, grade_level, total_copies) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $subject, $category, $isbn, $author, $publisher, $grade, $total_copies]);
                $id = $pdo->lastInsertId();
            }
            
            // Sync Inventory entry
            $pdo->prepare("INSERT INTO textbook_inventory (textbook_id, total_stock, available_stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE total_stock = ?")->execute([$id, $total_copies, $total_copies, $total_copies]);
            
            echo json_encode(['success' => true]);
        }
        
        elseif ($action === 'delete_book') {
            $id = $_POST['id'];
            $pdo->prepare("UPDATE admin_books SET status = 'Archived' WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        }

        elseif ($action === 'collect_book') {
            $dist_id = $_POST['distribution_id'];
            $cond = $_POST['condition_returned'];
            $remarks = $_POST['remarks'] ?? '';
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT textbook_id FROM textbook_distributions WHERE id = ?");
            $stmt->execute([$dist_id]);
            $textbook_id = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("UPDATE textbook_distributions SET status = 'Returned', date_returned = CURRENT_DATE, condition_returned = ? WHERE id = ?");
            $stmt->execute([$cond, $dist_id]);
            
            // Update inventory
            if ($cond === 'Damaged') {
                $pdo->prepare("UPDATE textbook_inventory SET distributed_stock = distributed_stock - 1, damaged_stock = damaged_stock + 1 WHERE textbook_id = ?")->execute([$textbook_id]);
            } else {
                $pdo->prepare("UPDATE textbook_inventory SET distributed_stock = distributed_stock - 1, available_stock = available_stock + 1 WHERE textbook_id = ?")->execute([$textbook_id]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        }

        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- DATA FETCHING ---
$summary = get_textbook_summary($pdo);
$master_books = $pdo->query("SELECT * FROM admin_books WHERE status = 'Active' ORDER BY subject, title")->fetchAll();
$inventory = $pdo->query("SELECT i.*, b.title, b.subject, b.grade_level FROM textbook_inventory i JOIN admin_books b ON i.textbook_id = b.id WHERE b.status = 'Active'")->fetchAll();
$distributions = $pdo->query("SELECT d.*, b.title, b.subject, s.student_name, u.first_name as adv_first, u.last_name as adv_last, sec.section_name, sec.grade_level as sec_grade 
    FROM textbook_distributions d 
    JOIN admin_books b ON d.textbook_id = b.id 
    JOIN enrollments s ON d.student_id = s.student_id 
    JOIN users u ON d.adviser_id = u.id
    JOIN sections sec ON d.section_id = sec.id
    ORDER BY d.date_issued DESC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Textbook Management System | Admin Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary-blue: #0d6efd; --dark-blue: #0a58ca; --light-bg: #f0f2f5; --card-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        body { font-family: 'Outfit', sans-serif; background-color: var(--light-bg); color: #2d3436; }
        
        .main-content { 
            padding: 40px; 
            margin-top: var(--header-height); 
            margin-left: var(--sidebar-width); 
            transition: 0.3s; 
            min-height: calc(100vh - var(--header-height));
        }

        .card { border: none; border-radius: 16px; box-shadow: var(--card-shadow); transition: transform 0.2s; background: white; margin-bottom: 24px; }
        .nav-pills .nav-link { border-radius: 10px; font-weight: 500; padding: 12px 20px; color: #636e72; cursor: pointer; }
        .nav-pills .nav-link.active { background: linear-gradient(135deg, #0d6efd, #0056b3); box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3); color: white; }
        .stat-card { padding: 24px; text-align: center; border-bottom: 4px solid var(--primary-blue); }
        .stat-val { font-size: 32px; font-weight: 700; display: block; color: #2d3436; }
        .stat-label { font-size: 13px; color: #b2bec3; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .badge-status { padding: 6px 12px; border-radius: 8px; font-weight: 600; font-size: 11px; }
        .bg-active { background: #e3f2fd; color: #0d6efd; }
        .bg-returned { background: #e8f5e9; color: #2e7d32; }
        .bg-missing { background: #ffebee; color: #c62828; }
        .bg-overdue { background: #fff3e0; color: #ef6c00; }
        .table thead th { background: #f8f9fa; border-top: none; font-size: 12px; text-transform: uppercase; color: #636e72; }
        .btn-action { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; border: 1px solid #eee; background: white; }
        .chart-container { height: 300px; position: relative; }
        
        @media (max-width: 768px) { 
            .main-content { 
                margin-left: 0; 
                padding: 20px; 
                margin-top: var(--header-height);
            } 
        }
    </style>
</head>
<body>
    <?php require_once 'admin_header.php'; ?>
    <?php require_once 'admin_sidebar.php'; ?>

    <div class="main-content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold m-0 text-primary">Textbook Management System</h2>
                    <p class="text-muted small mb-0">Complete lifecycle oversight for school textbooks</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary shadow-sm" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    <button class="btn btn-primary shadow-sm" onclick="addBook()"><i class="bi bi-plus-lg"></i> Add Book</button>
                </div>
            </div>

            <!-- Dashboard Summary -->
            <div class="row g-4 mb-4">
                <div class="col-md-3 col-sm-6"><div class="card stat-card"><span class="stat-val"><?= $summary['total_books'] ?></span><span class="stat-label">Total Titles</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#6c5ce7"><span class="stat-val text-purple"><?= $summary['total_stocks'] ?></span><span class="stat-label">Total Stocks</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#00b894"><span class="stat-val text-success"><?= $summary['available'] ?></span><span class="stat-label">Available</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#0984e3"><span class="stat-val text-primary"><?= $summary['distributed'] ?></span><span class="stat-label">Distributed</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#fdcb6e"><span class="stat-val text-warning"><?= $summary['overdue'] ?></span><span class="stat-label">Overdue</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#d63031"><span class="stat-val text-danger"><?= $summary['missing'] ?></span><span class="stat-label">Missing</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#e17055"><span class="stat-val text-dark"><?= $summary['damaged'] ?></span><span class="stat-label">Damaged</span></div></div>
                <div class="col-md-3 col-sm-6"><div class="card stat-card" style="border-color:#b2bec3"><span class="stat-val"><?= $summary['returned'] ?></span><span class="stat-label">Collected</span></div></div>
            </div>

            <!-- Tab Navigation -->
            <div class="card p-2 mb-4 sticky-top shadow-sm" style="top: 80px; z-index: 100;">
                <ul class="nav nav-pills gap-2 flex-nowrap overflow-auto" id="mainTabs">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-dash">Dashboard</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-master">Master List</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-inv">Inventory</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-dist">Distributions</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-mon">Monitoring</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-coll">Collection</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-rep">Reports</button></li>
                </ul>
            </div>

            <div class="tab-content">
                <!-- Dashboard -->
                <div class="tab-pane fade show active" id="tab-dash">
                    <div class="row g-4">
                        <div class="col-lg-12">
                            <div class="card p-4">
                                <h5 class="fw-bold mb-4"><i class="bi bi-graph-up"></i> Inventory Analysis</h5>
                                <div class="chart-container">
                                    <canvas id="inventoryChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Master List -->
                <div class="tab-pane fade" id="tab-master">
                    <div class="card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold m-0">Master Book List</h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Export</button>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-file-earmark-arrow-up"></i> Import</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table datatable w-100">
                                <thead><tr><th>Title</th><th>Subject</th><th>Grade</th><th>ISBN</th><th>Author</th><th>Stock</th><th>Action</th></tr></thead>
                                <tbody>
                                    <?php foreach($master_books as $book): ?>
                                        <tr>
                                            <td><div class="fw-bold text-primary"><?= htmlspecialchars($book['title']) ?></div><div class="text-muted small"><?= htmlspecialchars($book['publisher'] ?: 'N/A') ?></div></td>
                                            <td><?= htmlspecialchars($book['subject']) ?></td>
                                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars($book['grade_level'] ?: 'All') ?></span></td>
                                            <td class="small"><?= htmlspecialchars($book['isbn'] ?: '-') ?></td>
                                            <td class="small"><?= htmlspecialchars($book['author'] ?: '-') ?></td>
                                            <td class="fw-bold"><?= $book['total_copies'] ?></td>
                                            <td>
                                                <button class="btn btn-action btn-light me-1" onclick="editBook(<?= htmlspecialchars(json_encode($book)) ?>)"><i class="bi bi-pencil-square"></i></button>
                                                <button class="btn btn-action btn-light text-danger" onclick="deleteBook(<?= $book['id'] ?>)"><i class="bi bi-archive"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="tab-pane fade" id="tab-inv">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4">Physical Inventory Status</h5>
                        <table class="table datatable w-100">
                            <thead><tr><th>Book Title</th><th>Total</th><th>Available</th><th>Distributed</th><th>Missing</th><th>Damaged</th></tr></thead>
                            <tbody>
                                <?php foreach($inventory as $inv): ?>
                                    <tr>
                                        <td><div class="fw-bold"><?= htmlspecialchars($inv['title']) ?></div><div class="text-muted small"><?= htmlspecialchars($inv['subject']) ?></div></td>
                                        <td class="fw-bold"><?= $inv['total_stock'] ?></td>
                                        <td class="text-success fw-bold"><?= $inv['available_stock'] ?></td>
                                        <td class="text-primary"><?= $inv['distributed_stock'] ?></td>
                                        <td class="text-danger"><?= $inv['missing_stock'] ?></td>
                                        <td class="text-dark"><?= $inv['damaged_stock'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Distributions -->
                <div class="tab-pane fade" id="tab-dist">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4">System-wide Book Assignments</h5>
                        <table class="table datatable w-100">
                            <thead><tr><th>Student</th><th>Book</th><th>Adviser / Section</th><th>Date Issued</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach($distributions as $d): ?>
                                    <tr>
                                        <td><div class="fw-bold"><?= htmlspecialchars($d['student_name']) ?></div><div class="text-muted small"><?= $d['lrn'] ?></div></td>
                                        <td><div class="fw-bold"><?= htmlspecialchars($d['title']) ?></div><div class="text-muted small">Acc: <?= $d['accession_no'] ?: 'N/A' ?></div></td>
                                        <td><div class="small fw-bold"><?= $d['sec_grade'] ?> - <?= $d['section_name'] ?></div><div class="text-muted small">Adv: <?= $d['adv_last'] ?></div></td>
                                        <td class="small"><?= date('M d, Y', strtotime($d['date_issued'])) ?></td>
                                        <td><span class="badge badge-status bg-<?= strtolower($d['status']) ?>"><?= $d['status'] ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Monitoring -->
                <div class="tab-pane fade" id="tab-mon">
                    <div class="card p-5 text-center">
                        <div class="display-1 text-muted mb-3"><i class="bi bi-speedometer2"></i></div>
                        <h5>Lifecycle & Condition Monitoring</h5>
                        <p class="text-muted mx-auto" style="max-width: 500px;">Track the wear and tear of each individual book copy as it moves through different students and years.</p>
                        <div class="alert alert-info d-inline-block">This module aggregates condition history from all teacher return logs.</div>
                    </div>
                </div>

                <!-- Collection -->
                <div class="tab-pane fade" id="tab-coll">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4">Admin Return Processing</h5>
                        <table class="table datatable w-100">
                            <thead><tr><th>Student</th><th>Book Title</th><th>Issued To</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach($distributions as $d): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($d['student_name']) ?></td>
                                        <td><?= htmlspecialchars($d['title']) ?></td>
                                        <td><?= htmlspecialchars($d['adv_last']) ?></td>
                                        <td>
                                            <?php if($d['status'] == 'Returned'): ?>
                                                <span class="badge bg-success">Collected</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Not Collected</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>


                <!-- Reports -->
                <div class="tab-pane fade" id="tab-rep">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="card p-4 h-100 shadow-sm border-0 border-top border-primary border-4">
                                <h6 class="fw-bold mb-3">Inventory Reports</h6>
                                <div class="list-group list-group-flush">
                                    <button class="list-group-item list-group-item-action border-0 small"><i class="bi bi-file-pdf text-danger me-2"></i> Master Inventory PDF</button>
                                    <button class="list-group-item list-group-item-action border-0 small"><i class="bi bi-file-excel text-success me-2"></i> Stock Shortage List</button>
                                    <button class="list-group-item list-group-item-action border-0 small"><i class="bi bi-file-text text-primary me-2"></i> Damaged Book Log</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card p-4 h-100 shadow-sm border-0 border-top border-success border-4">
                                <h6 class="fw-bold mb-3">Student Accountability</h6>
                                <div class="list-group list-group-flush">
                                    <button class="list-group-item list-group-item-action border-0 small"><i class="bi bi-person-check me-2"></i> Clearance Report</button>
                                    <button class="list-group-item list-group-item-action border-0 small"><i class="bi bi-exclamation-triangle text-warning me-2"></i> Overdue Notifications</button>
                                    <button class="list-group-item list-group-item-action border-0 small"><i class="bi bi-printer me-2"></i> Accountability Forms</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card p-4 h-100 shadow-sm border-0 border-top border-dark border-4">
                                <h6 class="fw-bold mb-3">Official Forms</h6>
                                <p class="small text-muted mb-3">Generate DepEd School Form 3 (SF3) Inventory Preparation reports.</p>
                                <div class="input-group mb-3">
                                    <select class="form-select form-select-sm" id="sf3-grade">
                                        <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
                                        <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
                                    </select>
                                    <button class="btn btn-sm btn-dark" onclick="window.open('?action=print_sf3_prep&grade=' + document.getElementById('sf3-grade').value)">Print SF3</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Book Modal -->
    <div class="modal fade" id="bookModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="bookForm" class="modal-content border-0 shadow-lg">
                <input type="hidden" name="ajax_action" value="save_book">
                <input type="hidden" name="id" id="book_id">
                <div class="modal-header bg-primary text-white"><h5 class="modal-title">Book Entry</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small fw-bold">Title</label><input type="text" name="title" id="f_title" class="form-control shadow-sm" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Subject Area</label><input type="text" name="subject" id="f_subject" class="form-control shadow-sm" required></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">ISBN</label><input type="text" name="isbn" id="f_isbn" class="form-control shadow-sm"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Author</label><input type="text" name="author" id="f_author" class="form-control shadow-sm"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Publisher</label><input type="text" name="publisher" id="f_publisher" class="form-control shadow-sm"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Grade Level</label><select name="grade_level" id="f_grade" class="form-select shadow-sm"><option value="">All Grades</option><option>Grade 7</option><option>Grade 8</option><option>Grade 9</option><option>Grade 10</option><option>Grade 11</option><option>Grade 12</option></select></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Total Stock</label><input type="number" name="total_copies" id="f_stock" class="form-control shadow-sm" required></div>
                    </div>
                </div>
                <div class="modal-footer bg-light"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary px-4 shadow-sm">Save Book</button></div>
            </form>
        </div>
    </div>

    <!-- Collection Modal -->
    <div class="modal fade" id="collectModal" tabindex="-1">
        <div class="modal-dialog">
            <form id="collectForm" class="modal-content border-0 shadow-lg">
                <input type="hidden" name="ajax_action" value="collect_book">
                <input type="hidden" name="distribution_id" id="coll_id">
                <div class="modal-header bg-success text-white"><h5 class="modal-title">Admin Book Collection</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Condition on Return</label>
                        <select name="condition_returned" class="form-select shadow-sm">
                            <option value="Good">Good (Perfect condition)</option>
                            <option value="Fair">Fair (Normal wear)</option>
                            <option value="Damaged">Damaged (Requires repair/charge)</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label small fw-bold">Return Remarks</label><textarea name="remarks" class="form-control shadow-sm" rows="3" placeholder="Notes about missing pages, damage, etc."></textarea></div>
                </div>
                <div class="modal-footer bg-light"><button type="submit" class="btn btn-success w-100 shadow-sm">Process Return & Update Stock</button></div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('.datatable').DataTable({ pageLength: 10, ordering: true, responsive: true });
            initCharts();
            updateDashboardStats(); // Initial fetch
            setInterval(updateDashboardStats, 30000); // Auto-refresh every 30s
        });

        function updateDashboardStats() {
            $.post('', { ajax_action: 'get_stats' }, function(res) {
                console.log('Dashboard Stats Sync:', res);
                if(res.success) {
                    const s = res.stats;
                    const vals = $('.stat-val');
                    vals.eq(0).text(s.total_books);
                    vals.eq(1).text(s.total_stocks);
                    vals.eq(2).text(s.available);
                    vals.eq(3).text(s.distributed);
                    vals.eq(4).text(s.overdue);
                    vals.eq(5).text(s.missing);
                    vals.eq(6).text(s.damaged);
                    vals.eq(7).text(s.returned);

                    if(window.inventoryChart) {
                        window.inventoryChart.data.datasets[0].data = [s.available, s.distributed, s.missing, s.damaged];
                        window.inventoryChart.update();
                    }
                }
            }).fail(function(err) {
                console.error('Stats sync failed:', err);
            });
        }

        function initCharts() {
            const invCtx = document.getElementById('inventoryChart').getContext('2d');
            window.inventoryChart = new Chart(invCtx, {
                type: 'bar',
                data: {
                    labels: ['Available', 'Distributed', 'Missing', 'Damaged'],
                    datasets: [{
                        label: 'Copies',
                        data: [0, 0, 0, 0],
                        backgroundColor: ['#00b894', '#0984e3', '#d63031', '#e17055'],
                        borderRadius: 10
                    }]
                },
                options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [5, 5] } }, x: { grid: { display: false } } } }
            });
        }

        function addBook() {
            $('#bookForm')[0].reset();
            $('#book_id').val('');
            $('#bookModal .modal-title').text('Add New Textbook');
            new bootstrap.Modal(document.getElementById('bookModal')).show();
        }

        function editBook(book) {
            $('#book_id').val(book.id);
            $('#f_title').val(book.title);
            $('#f_subject').val(book.subject);
            $('#f_isbn').val(book.isbn);
            $('#f_author').val(book.author);
            $('#f_publisher').val(book.publisher);
            $('#f_grade').val(book.grade_level);
            $('#f_stock').val(book.total_copies);
            $('#bookModal .modal-title').text('Edit Textbook Details');
            new bootstrap.Modal(document.getElementById('bookModal')).show();
        }

        function deleteBook(id) {
            Swal.fire({
                title: 'Archive this book?',
                text: "This title will be moved to archives and hidden from distribution.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, archive it'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', { ajax_action: 'delete_book', id: id }, function(res) {
                        if(res.success) location.reload();
                    });
                }
            });
        }

        function collectBook(id, title, student) {
            $('#coll_id').val(id);
            Swal.fire({
                title: 'Collect Book?',
                text: `Process return for "${title}" from ${student}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed'
            }).then((result) => {
                if (result.isConfirmed) {
                    new bootstrap.Modal(document.getElementById('collectModal')).show();
                }
            });
        }

        $('#bookForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', $(this).serialize(), function(res) {
                if(res.success) {
                    Swal.fire('Success!', 'Textbook data has been saved.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        });

        $('#collectForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', $(this).serialize(), function(res) {
                if(res.success) {
                    Swal.fire('Confirmed!', 'Stock inventory has been restored.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        });
    </script>
</body>
</html>