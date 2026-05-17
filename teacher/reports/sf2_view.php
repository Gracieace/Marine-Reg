<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';

$pdo = db_connect();
$current_user = $_SESSION['user_id'] ?? 1;

// Handle report deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM sf2_reports WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$_GET['delete'], $current_user]);
        $message = "SF2 report deleted successfully!";
    } catch (Exception $e) {
        $error = "Error deleting report: " . $e->getMessage();
    }
}

// Get all SF2 reports for the current teacher
$stmt = $pdo->prepare("
    SELECT r.*, s.adviser_name, s.adviser_signature, s.attested_by_name, s.attested_by_signature
    FROM sf2_reports r
    LEFT JOIN sf2_monthly_summary s ON r.id = s.sf2_report_id
    WHERE r.teacher_id = ?
    ORDER BY r.report_year DESC, r.report_month DESC, r.created_at DESC
");
$stmt->execute([$current_user]);
$reports = $stmt->fetchAll();

// Get month names for display
$months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December'
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF2 Reports - View</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            margin-left: 250px;
            background: white;
            padding: 100px 32px 32px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }

        .header h1 {
            color: #007bff;
            margin: 0;
        }

        .btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            transition: background-color 0.3s;
            margin: 5px;
        }

        .btn:hover {
            background: #0056b3;
        }

        .btn-success {
            background: #28a745;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-danger {
            background: #dc3545;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .reports-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .reports-table th,
        .reports-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }

        .reports-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }

        .reports-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .reports-table tr:hover {
            background-color: #e9ecef;
        }

        .actions {
            white-space: nowrap;
        }

        .actions .btn {
            padding: 5px 10px;
            font-size: 12px;
            margin: 2px;
        }

        .no-reports {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #dee2e6;
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #007bff;
            font-size: 24px;
        }

        .stat-card p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 110px 16px 24px;
            }
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="main-content dashboard-container">
        <div class="header">
            <h1>📊 SF2 Daily Attendance Reports</h1>
            <p>View and manage your School Form 2 reports</p>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?= count($reports) ?></h3>
                <p>Total Reports</p>
            </div>
            <div class="stat-card">
                <h3><?= count(array_filter($reports, function ($r) {
                    return $r['adviser_signature'] !== null;
                })) ?></h3>
                <p>Completed Reports</p>
            </div>
            <div class="stat-card">
                <h3><?= count(array_filter($reports, function ($r) {
                    return $r['adviser_signature'] === null;
                })) ?></h3>
                <p>Draft Reports</p>
            </div>
        </div>

        <div style="text-align: center; margin-bottom: 20px;">
            <a href="sf2_form.php" class="btn btn-success">➕ Create New SF2 Report</a>
            <a href="../reports.php" class="btn btn-secondary">← Back to Reports</a>
        </div>

        <?php if (empty($reports)): ?>
            <div class="no-reports">
                <h3>No SF2 Reports Found</h3>
                <p>You haven't created any SF2 Daily Attendance Reports yet.</p>
                <a href="sf2_form.php" class="btn btn-success">Create Your First Report</a>
            </div>
        <?php else: ?>
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>School Year</th>
                        <th>Month</th>
                        <th>Grade Level</th>
                        <th>Section</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?= htmlspecialchars($report['school_year']) ?></td>
                            <td><?= htmlspecialchars($report['report_month']) ?></td>
                            <td><?= htmlspecialchars($report['grade_level']) ?></td>
                            <td><?= htmlspecialchars($report['section']) ?></td>
                            <td>
                                <?php if ($report['adviser_signature']): ?>
                                    <span style="color: #28a745; font-weight: bold;">✓ Completed</span>
                                <?php else: ?>
                                    <span style="color: #ffc107; font-weight: bold;">📝 Draft</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($report['created_at'])) ?></td>
                            <td><?= date('M d, Y H:i', strtotime($report['updated_at'])) ?></td>
                            <td class="actions">
                                <a href="sf2_view_detail.php?id=<?= $report['id'] ?>" class="btn">👁️ View</a>
                                <a href="sf2_form.php?edit=<?= $report['id'] ?>" class="btn btn-secondary">✏️ Edit</a>
                                <a href="sf2_print.php?id=<?= $report['id'] ?>" class="btn btn-secondary" target="_blank">🖨️
                                    Print</a>
                                <a href="?delete=<?= $report['id'] ?>" class="btn btn-danger"
                                    onclick="return confirm('Are you sure you want to delete this report?')">🗑️ Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>

</html>