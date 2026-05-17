<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once dirname(__DIR__) . '/config/db.php';

$pdo = db_connect();
initialize_schema($pdo);
$message = '';
$error = '';

// Handle AJAX requests for archived enrollments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_archived_enrollments') {
    $school_year = $_POST['school_year'] ?? '';
    $archived_enrollments = get_archived_enrollments($pdo, $school_year);

    if (empty($archived_enrollments)) {
        echo '<div class="alert alert-info">No archived enrollments found for the selected school year.</div>';
        exit;
    }

    echo '<div class="table-container">';
    echo '<table class="table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Student Name</th>';
    echo '<th>LRN</th>';
    echo '<th>Grade Level</th>';
    echo '<th>Section</th>';
    echo '<th>Enrolled Date</th>';
    echo '<th>Archived Date</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($archived_enrollments as $enrollment) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($enrollment['student_name']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($enrollment['lrn']) . '</td>';
        echo '<td>' . htmlspecialchars($enrollment['grade_level']) . '</td>';
        echo '<td>' . htmlspecialchars($enrollment['section']) . '</td>';
        echo '<td>' . date('M d, Y', strtotime($enrollment['enrolled_at'])) . '</td>';
        echo '<td>' . date('M d, Y', strtotime($enrollment['archived_at'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['transition_school_year'])) {
        $new_school_year = trim($_POST['new_school_year']);
        $start_date = trim($_POST['start_date']);
        $end_date = trim($_POST['end_date']);

        if (empty($new_school_year) || empty($start_date) || empty($end_date)) {
            $error = 'All fields are required for school year transition.';
        } else {
            $result = transition_to_new_school_year($pdo, $new_school_year, $start_date, $end_date, $_SESSION['user_id']);
            if ($result['success']) {
                $message = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif (isset($_POST['archive_school_year'])) {
        $school_year = trim($_POST['archive_school_year']);
        if (empty($school_year)) {
            $error = 'Please select a school year to archive.';
        } else {
            $result = archive_enrollments_for_school_year($pdo, $school_year, $_SESSION['user_id']);
            if ($result['success']) {
                $message = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif (isset($_POST['set_current_school_year'])) {
        $school_year = trim($_POST['school_year_value'] ?? '');
        if (empty($school_year)) {
            $error = 'Please select a school year to set as current.';
        } else {
            if (set_current_school_year($pdo, $school_year)) {
                $message = 'Current school year updated to ' . $school_year;
            } else {
                $error = 'Failed to update current school year.';
            }
        }
    }
}

// Get current school year
$current_sy = get_current_school_year($pdo);

// Get school year list
$school_years = get_school_year_list($pdo);

// Get current enrollments count
$current_enrollments_stmt = $pdo->prepare('SELECT COUNT(*) as count FROM enrollments WHERE school_year = ?');
$current_enrollments_stmt->execute([$current_sy]);
$current_enrollments_count = $current_enrollments_stmt->fetchColumn();

// Get archived enrollments count by school year
$archived_counts = [];
foreach ($school_years as $sy) {
    if ($sy['is_archived']) {
        $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM enrollment_archives WHERE school_year = ?');
        $stmt->execute([$sy['school_year']]);
        $archived_counts[$sy['school_year']] = $stmt->fetchColumn();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Year Management</title>
    <!-- Use shared Curriculum CSS for professional consistency -->
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/curriculum.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Specific overrides for this page */
        .main-content {
            margin-top: var(--header-height);
            /* Desktop header height */
            padding: 20px;
            /* Sidebar margin is handled by sidebar.css */
        }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            font-size: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), #3b82f6);
            color: white;
            padding: 1.5rem;
            border-radius: var(--radius-md);
            text-align: center;
            box-shadow: var(--shadow-md);
        }

        .stat-card h3 {
            font-size: 2rem;
            margin: 0;
            font-weight: 700;
        }

        .stat-card p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-current {
            background: #dcfce7;
            color: #166534;
        }

        .badge-archived {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        .transition-alert {
            background-color: #fff7ed;
            border-left: 4px solid #ea580c;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .transition-alert h4 {
            margin: 0 0 0.5rem;
            color: #9a3412;
        }

        .transition-alert p {
            margin: 0;
            color: #9a3412;
            font-size: 0.95rem;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/registrar_side_panel.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">School Year Management</h1>
            <p class="page-subtitle">Manage curriculum years, transitions, and archives.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Current Status -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <h3><?= htmlspecialchars($current_sy ?: 'Not Set') ?></h3>
                <p>Current School Year</p>
            </div>
            <div class="stat-card">
                <h3><?= number_format($current_enrollments_count) ?></h3>
                <p>Active Enrollments</p>
            </div>
        </div>

        <div class="grid"
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem;">

            <!-- School Year Transition -->
            <div class="card">
                <h2>🎓 Transition to New School Year</h2>

                <div class="transition-alert">
                    <h4>⚠️ Important Notice</h4>
                    <p>This action will <strong>automatically archive all current enrollments</strong> and start a clean
                        slate for the new school year. This cannot be undone.</p>
                </div>

                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_school_year">New School Year</label>
                            <input type="text" id="new_school_year" name="new_school_year" placeholder="e.g., 2026-2027"
                                required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" required>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" name="transition_school_year" class="btn btn-warning"
                            style="background-color: #f59e0b; color: white;"
                            onclick="return confirm('⚠️ Are you ABSOLUTELY sure? \n\nThis will archive ALL current student data. Proceed?')">
                            Start New School Year
                        </button>
                    </div>
                </form>
            </div>

            <!-- Set Current SY -->
            <div class="card" style="height: fit-content;">
                <h2>⚙️ Current Configuration</h2>
                <form method="post" style="display: flex; gap: 10px; align-items: flex-end;">
                    <input type="hidden" name="action_type" value="set_current">
                    <div class="form-group" style="flex: 1;">
                        <label for="school_year_value">Active School Year</label>
                        <select id="school_year_value" name="school_year_value" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($school_years as $sy): ?>
                                <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['is_current'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sy['school_year']) ?>
                                    <?= $sy['is_current'] ? ' (Active)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 0 0 auto;">
                        <button type="submit" name="set_current_school_year" value="1"
                            class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>

        </div>

        <!-- School Year History -->
        <div class="card">
            <h2>📜 School Year History</h2>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>School Year</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Enrollment Data</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($school_years as $sy): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($sy['school_year']) ?></strong>
                                </td>
                                <td>
                                    <span style="color: #64748b; font-size: 0.9em;">
                                        <?= date('M Y', strtotime($sy['start_date'])) ?> -
                                        <?= date('M Y', strtotime($sy['end_date'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($sy['is_current']): ?>
                                        <span class="badge badge-current">Current</span>
                                    <?php elseif ($sy['is_archived']): ?>
                                        <span class="badge badge-archived">Archived</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sy['is_current']): ?>
                                        <strong
                                            style="color: #059669;"><?= number_format($current_enrollments_count) ?></strong>
                                        active students
                                    <?php elseif ($sy['is_archived']): ?>
                                        <strong><?= number_format($archived_counts[$sy['school_year']] ?? 0) ?></strong>
                                        archived records
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">No active data</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$sy['is_current'] && !$sy['is_archived']): ?>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="archive_school_year"
                                                value="<?= htmlspecialchars($sy['school_year']) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Archive all enrollments for <?= htmlspecialchars($sy['school_year']) ?>?')">
                                                Archive Data
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Archived Enrollments Viewer -->
        <div class="card">
            <h2>📂 Archived Records Viewer</h2>
            <div class="form-group" style="max-width: 400px;">
                <label for="view_archived_sy">Select School Year to View</label>
                <select id="view_archived_sy" onchange="viewArchivedEnrollments(this.value)">
                    <option value="">-- Select Archived Year --</option>
                    <?php foreach ($school_years as $sy): ?>
                        <?php if ($sy['is_archived']): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>">
                                <?= htmlspecialchars($sy['school_year']) ?>
                                (<?= number_format($archived_counts[$sy['school_year']] ?? 0) ?> records)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="archived-enrollments-container" style="margin-top: 1.5rem;">
                <div style="text-align: center; color: #9ca3af; padding: 2rem;">
                    Select a school year above to view archived enrollment records.
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewArchivedEnrollments(schoolYear) {
            const container = document.getElementById('archived-enrollments-container');

            if (!schoolYear) {
                container.innerHTML = '<div style="text-align: center; color: #9ca3af; padding: 2rem;">Select a school year above to view archived enrollment records.</div>';
                return;
            }

            container.innerHTML = '<div style="text-align: center; padding: 2rem;"><span style="font-size: 1.5rem;">⏳</span><br>Loading records...</div>';

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_archived_enrollments&school_year=' + encodeURIComponent(schoolYear)
            })
                .then(response => response.text())
                .then(data => {
                    container.innerHTML = data;
                })
                .catch(error => {
                    container.innerHTML = '<div class="alert alert-error">Error loading data: ' + error.message + '</div>';
                });
        }

        // Auto-fill dates for new school year
        document.getElementById('new_school_year').addEventListener('input', function () {
            const value = this.value;
            // Basic matching for YYYY-YYYY format
            const match = value.match(/^(\d{4})-(\d{4})$/);
            if (match) {
                const startYear = match[1];
                const endYear = match[2];
                document.getElementById('start_date').value = startYear + '-06-01'; // Default Ph start
                document.getElementById('end_date').value = endYear + '-05-31';     // Default Ph end
            }
        });
    </script>
</body>

</html>
