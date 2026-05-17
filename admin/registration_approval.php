<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['registrar', 'admin']);

$pdo = db_connect();
$message = '';
$error = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $registration_id = $_POST['registration_id'] ?? '';
    $current_user_id = auth_user()['id'] ?? null;

    try {
        if ($action === 'approve_all') {
            $stmt = $pdo->prepare('UPDATE registrations SET approval_status = "approved" WHERE approval_status = "pending"');
            $stmt->execute();
            $affected = $stmt->rowCount();
            $message = $affected . ' pending registration(s) approved successfully!';
        } elseif ($action && $registration_id) {
            if ($action === 'approve') {
                $stmt = $pdo->prepare('UPDATE registrations SET approval_status = "approved" WHERE id = ?');
                $stmt->execute([$registration_id]);
                $message = 'Registration approved successfully!';
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare('UPDATE registrations SET approval_status = "rejected" WHERE id = ?');
                $stmt->execute([$registration_id]);
                $message = 'Registration rejected.';
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM registrations WHERE id = ?');
                $stmt->execute([$registration_id]);
                $message = 'Registration deleted.';
            } else {
                $error = 'Unknown action.';
            }
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get pending registrations
try {
    $stmt = $pdo->query('
        SELECT * FROM registrations 
        WHERE approval_status = "pending" 
        ORDER BY created_at ASC
    ');
    $pending_registrations = $stmt->fetchAll();
} catch (Exception $e) {
    $pending_registrations = [];
    $error = 'Database error: ' . $e->getMessage();
}

// Get recently processed registrations
try {
    $stmt = $pdo->query('
        SELECT * FROM registrations 
        WHERE approval_status IN ("approved", "rejected") 
        ORDER BY created_at DESC 
        LIMIT 20
    ');
    $recent_registrations = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_registrations = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Approval</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-700: #374151;
            --gray-800: #1f2937;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-800);
        }

        .main-content {
            /* Increased top padding to clear fixed header */
            padding: 160px 2rem 2rem;
            max-width: 1400px;
        }

        @media (max-width: 768px) {
            .main-content {
                padding-top: var(--header-height);
            }
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 0.5rem 0;
        }

        .page-header p {
            color: #6b7280;
            font-size: 1rem;
            margin: 0;
        }

        /* Grid Layout for Cards */
        .approvals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .approval-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--gray-200);
            border-left: 4px solid var(--primary);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
        }

        .approval-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .user-info {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            flex: 1;
            margin-bottom: 1.5rem;
        }

        .info-group h4 {
            color: #111827;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 0 0 0.75rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray-100);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.925rem;
        }

        .info-label {
            color: #6b7280;
        }

        .info-value {
            color: #111827;
            font-weight: 500;
            text-align: right;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-100);
        }

        .action-buttons .full-width {
            grid-column: span 2;
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 0.625rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            text-decoration: none;
        }

        .btn-approve {
            background-color: var(--success);
            color: white;
        }
        .btn-approve:hover { background-color: #059669; }

        .btn-reject {
            background-color: white;
            border-color: var(--danger);
            color: var(--danger);
        }
        .btn-reject:hover { background-color: #fef2f2; }

        .btn-secondary {
            background-color: white;
            border-color: var(--gray-200);
            color: var(--gray-700);
        }
        .btn-secondary:hover { background-color: var(--gray-50); border-color: #d1d5db; }

        .btn-danger-text {
            color: var(--danger);
            background: none;
            padding: 0.5rem;
        }
        .btn-danger-text:hover { text-decoration: underline; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending { background-color: #fffbeb; color: #b45309; }
        .status-approved { background-color: #d1fae5; color: #047857; }
        .status-rejected { background-color: #fef2f2; color: #b91c1c; }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background-color: var(--gray-50);
            text-align: left;
            padding: 1rem 1.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--gray-200);
            font-size: 0.875rem;
            color: #111827;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin: 2rem 0 1rem 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Modal */
        .modal-backdrop {
            background: rgba(17, 24, 39, 0.6);
            backdrop-filter: blur(4px);
        }
        .modal {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>

<body>
    <?php require_once dirname(__DIR__) . '/header.php'; ?>
    <?php
    // Determine which sidebar to show
    if (function_exists('auth_user') && auth_user()['role'] === 'admin') {
        require_once __DIR__ . '/admin_sidebar.php';
    } else {
        // Fallback or registrar sidebar if available
        require_once __DIR__ . '/admin_sidebar.php';
    }
    ?>

    <div class="content main-content">
        <div class="page-header">
            <h1>Registration Approval</h1>
            <p>Review and decide on pending student registrations</p>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div class="section-title" style="margin: 0;">
                Pending Approval <span style="font-weight: 400; color: #6b7280; font-size: 1rem; margin-left: 8px;">(<?php echo count($pending_registrations); ?>)</span>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="approve_all">
                <button type="submit" class="btn btn-approve" onclick="return confirm('Are you sure you want to approve ALL pending registrations?')"
                    <?php echo empty($pending_registrations) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                    ✓ Approve All
                </button>
            </form>
        </div>

        <?php if ($message): ?>
                <div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #a7f3d0; font-weight: 500;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
        <?php endif; ?>

        <?php if ($error): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #fecaca; font-weight: 500;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
        <?php endif; ?>

        <?php if (empty($pending_registrations)): ?>
                <div class="empty-state">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">🎉</div>
                    <h3 style="color: #111827; margin: 0 0 0.5rem 0;">All caught up!</h3>
                    <p>No pending registrations to review right now.</p>
                </div>
        <?php else: ?>
                <div class="approvals-grid">
                    <?php foreach ($pending_registrations as $reg): ?>
                            <?php
                            $fullName = trim($reg['first_name'] . ' ' . $reg['middle_name'] . ' ' . $reg['last_name'] . ' ' . $reg['ext_name']);
                            $contact = $reg['father_contact'] ?: $reg['mother_contact'] ?: $reg['guardian_contact'];
                            ?>
                            <div class="approval-card">
                                <div class="user-info">
                                    <div class="info-group">
                                        <h4>Student Details</h4>
                                        <div class="info-row"><span class="info-label">Name</span> <span class="info-value"><?php echo htmlspecialchars($fullName); ?></span></div>
                                        <div class="info-row"><span class="info-label">LRN</span> <span class="info-value"><?php echo htmlspecialchars($reg['lrn'] ?: 'None'); ?></span></div>
                                        <div class="info-row"><span class="info-label">Grade</span> <span class="info-value"><?php echo htmlspecialchars($reg['grade_level_to_enroll']); ?></span></div>
                                        <div class="info-row"><span class="info-label">Type</span> <span class="info-value"><?php echo $reg['is_returning'] ? 'Returning' : 'New'; ?></span></div>
                                    </div>
                                    <div class="info-group" style="margin-top: 0.5rem;">
                                        <h4>Submission</h4>
                                        <div class="info-row"><span class="info-label">Date</span> <span class="info-value"><?php echo date('M j, Y', strtotime($reg['created_at'])); ?></span></div>
                                        <div class="info-row"><span class="info-label">Time</span> <span class="info-value"><?php echo date('g:i A', strtotime($reg['created_at'])); ?></span></div>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button type="button" class="btn btn-secondary full-width" onclick="viewRegistration(<?php echo (int) $reg['id']; ?>)">
                                        👁 View Details
                                    </button>
                            
                                    <form method="post" style="display:contents;">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                        <button type="submit" class="btn btn-approve" onclick="return confirm('Approve this registration?')">Approve</button>
                                    </form>

                                    <form method="post" style="display:contents;">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                        <button type="submit" class="btn btn-reject" onclick="return confirm('Reject this registration?')">Reject</button>
                                    </form>
                            
                                    <div class="full-width" style="display:flex; justify-content:space-between; margin-top:8px;">
                                        <a href="<?= url_for('/admin/edit_registration.php') ?>?id=<?php echo (int) $reg['id']; ?>" class="btn-danger-text" style="color:#6b7280; font-size: 12px; text-decoration:none;">EDIT</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete permanently?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="registration_id" value="<?php echo (int) $reg['id']; ?>">
                                            <button type="submit" class="btn-danger-text" style="font-size: 12px; cursor:pointer; border:none;">DELETE</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                    <?php endforeach; ?>
                </div>
        <?php endif; ?>

        <!-- Recent Actions Section -->
        <?php if (!empty($recent_registrations)): ?>
                <div class="section-title" style="margin-top: 40px;">Recent History</div>
                <div class="table-container" style="overflow-x:auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Grade Level</th>
                                <th>LRN</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_registrations as $reg): ?>
                                    <?php
                                    $fullName = trim($reg['first_name'] . ' ' . $reg['middle_name'] . ' ' . $reg['last_name'] . ' ' . $reg['ext_name']);
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($fullName); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($reg['grade_level_to_enroll']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($reg['lrn'] ?: 'N/A'); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $reg['approval_status']; ?>">
                                                <?php echo ucfirst($reg['approval_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y g:i A', strtotime($reg['created_at'])); ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-secondary"
                                                onclick="viewRegistration(<?php echo (int) $reg['id']; ?>)">View</button>
                                            <?php if ($reg['approval_status'] === 'rejected'): ?>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                        <button type="submit" class="btn btn-approve">Re-Approve</button>
                                                    </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
        <?php endif; ?>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal">
            <div class="modal-header">Registration Details</div>
            <div id="viewContent" style="color:#374151; font-size:14px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function viewRegistration(id) {
            // Fetch registration details via AJAX
            fetch(`<?= url_for('/admin/get_registration.php') ?>?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayRegistrationDetails(data.registration);
                        document.getElementById('viewModal').style.display = 'flex';
                    } else {
                        alert('Error loading registration details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading registration details');
                });
        }

        function displayRegistrationDetails(reg) {
            const content = document.getElementById('viewContent');
            content.innerHTML = `
                <div class="registration-details" style="display:flex; flex-direction:column; gap:16px;">
                    <div>
                        <h4 style="margin:0 0 8px 0; border-bottom:1px solid #eee; padding-bottom:4px;">Basic Information</h4>
                        <p><strong>Name:</strong> ${reg.first_name} ${reg.middle_name} ${reg.last_name} ${reg.ext_name}</p>
                        <p><strong>LRN:</strong> ${reg.lrn || 'N/A'}</p>
                        <p><strong>Birthdate:</strong> ${reg.birthdate || 'N/A'}</p>
                        <p><strong>Sex:</strong> ${reg.sex || 'N/A'}</p>
                    </div>
                    <div>
                        <h4 style="margin:0 0 8px 0; border-bottom:1px solid #eee; padding-bottom:4px;">Enrollment Info</h4>
                        <p><strong>Grade Level:</strong> ${reg.grade_level_to_enroll}</p>
                        <p><strong>School Year:</strong> ${reg.school_year_start}-${reg.school_year_end}</p>
                        <p><strong>Status:</strong> ${reg.is_returning ? 'Returning' : 'New'}</p>
                    </div>
                    <div>
                        <h4 style="margin:0 0 8px 0; border-bottom:1px solid #eee; padding-bottom:4px;">Address</h4>
                        <p><strong>Current:</strong> ${reg.curr_house_no} ${reg.curr_street}, ${reg.curr_barangay}, ${reg.curr_city}, ${reg.curr_province}</p>
                    </div>
                </div>
            `;
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modal = document.getElementById('viewModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>

</html>