<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';

$pdo = db_connect();

// Get aggregated academic records
function getAcademicReport($pdo, $grade_filter = null, $section_filter = null)
{
    $sql = "SELECT 
                adviser_id,
                MAX(adviser_name) as adviser_name,
                grade_level,
                section,
                COUNT(*) as student_count,
                MAX(updated_at) as last_update
            FROM academic_info";

    $params = [];
    $whereClauses = [];

    if ($grade_filter) {
        $whereClauses[] = "grade_level = ?";
        $params[] = $grade_filter;
    }

    if ($section_filter) {
        $whereClauses[] = "section = ?";
        $params[] = $section_filter;
    }

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }

    $sql .= " GROUP BY adviser_id, grade_level, section
              ORDER BY grade_level, section";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$start_grade = $_GET['grade_level'] ?? null;
$start_section = $_GET['section'] ?? null;

$reports = getAcademicReport($pdo, $start_grade, $start_section);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Info Submission Report</title>
    <link rel="stylesheet" href="../../../css/header.css">
    <link rel="stylesheet" href="../../../css/sidebar.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 1600px;
            /* margin: 0; */
            margin-bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .main-content {
            margin-top: var(--header-height);
            padding: 20px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-top: 88px;
            }

            .container {
                margin-left: 0;
                padding: 15px;
            }
        }

        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }

        .page-header h1 {
            color: #007bff;
            margin: 0;
            font-size: 2.5em;
        }

        .page-header .subtitle {
            color: #666;
            font-size: 1.1em;
            margin-top: 10px;
        }

        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #ddd;
            padding: 12px 15px;
            text-align: left;
        }

        .report-table th {
            background-color: #007bff;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
        }

        .report-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .report-table tr:hover {
            background-color: #f0f8ff;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }

        .badge-count {
            background-color: #17a2b8;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 0.9em;
        }
    </style>
</head>

<body>
    <?php include '../../admin_header.php'; ?>
    <?php include '../../admin_sidebar.php'; ?>

    <div class="container main-content">
        <div class="page-header">
            <h1>Teacher Submission Monitor</h1>
            <p class="subtitle">Overview of Academic Information Submissions by Section</p>
        </div>

        <?php if (!empty($reports)): ?>
            <div class="table-container">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Grade & Section</th>
                            <th>Adviser Name</th>
                            <th>Students Submitted</th>
                            <th>Last Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($row['grade_level']) ?></strong> -
                                    <?= htmlspecialchars($row['section']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['adviser_name'] ?: 'N/A') ?></td>
                                <td>
                                    <span class="badge-count"><?= $row['student_count'] ?> Students</span>
                                </td>
                                <td>
                                    <?= $row['last_update'] ? date('M d, Y h:i A', strtotime($row['last_update'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <!-- Placeholder for future "View Details" functionality -->
                                    <button class="btn btn-sm btn-info"
                                        style="background:#6c757d; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:default;"
                                        disabled>View Details</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <h3>No Academic Information Found</h3>
                <p>No academic records found. Add a new record using the form above.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateAdviserName() {
            const select = document.getElementById('adviser_id');
            const hiddenInput = document.getElementById('adviser_name');
            const selectedOption = select.options[select.selectedIndex];

            if (selectedOption.value) {
                hiddenInput.value = selectedOption.getAttribute('data-name');
            } else {
                hiddenInput.value = '';
            }
        }
    </script>
</body>

</html>
