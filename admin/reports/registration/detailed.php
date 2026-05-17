<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';

// Handle report generation
$export_format = $_GET['export'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';

$pdo = db_connect();
$reports = [];

// Generate registration detailed report
$reports = generateRegistrationDetailed($pdo, $date_from, $date_to, $grade_level);

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format, 'registration_detailed');
    exit;
}

function generateRegistrationDetailed($pdo, $date_from, $date_to, $grade_level)
{
    $where_conditions = [];
    $params = [];

    if ($date_from) {
        $where_conditions[] = "created_at >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $where_conditions[] = "created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    if ($grade_level) {
        $where_conditions[] = "grade_level_to_enroll = ?";
        $params[] = $grade_level;
    }

    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "SELECT 
                id,
                created_at,
                lrn,
                CONCAT(last_name, ', ', first_name, ' ', COALESCE(middle_name, '')) as full_name,
                grade_level_to_enroll,
                birthdate,
                sex,
                age,
                curr_city,
                curr_province,
                father_contact,
                mother_contact,
                is_returning,
                with_lrn,
                is_4ps_beneficiary,
                is_pwd,
                is_ip
            FROM registrations 
            $where_clause
            ORDER BY last_name, first_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function handleExport($data, $format, $report_type)
{
    $filename = $report_type . "_" . date('Y-m-d_H-i-s');

    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');
    } elseif ($format === 'pdf') {
        // Since no PDF library is available, we export a printer-friendly HTML file
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="' . $filename . '.html"');
    }

    // Common HTML styling for both formats
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Detailed Registration Report</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #000; padding: 5px; text-align: left; vertical-align: top; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .header { text-align: center; margin-bottom: 20px; }
            .header h1 { margin: 0; font-size: 18px; }
            .header p { margin: 5px 0; font-size: 12px; }
            .badge { padding: 2px 5px; border-radius: 3px; font-weight: bold; font-size: 10px; }
            .yes { background-color: #d4edda; color: #155724; }
            .no { background-color: #f8d7da; color: #721c24; }
        </style>
    </head>
    <body onload="window.print()">
        <div class="header">
            <h1>Detailed Registration Report</h1>
            <p>Generated on: ' . date('F j, Y g:i A') . '</p>
            <p>Total Records: ' . count($data) . '</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>LRN</th>
                    <th>Full Name</th>
                    <th>Grade</th>
                    <th>Sex</th>
                    <th>Age</th>
                    <th>Returning</th>
                    <th>With LRN</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($data as $row) {
        $birthdate = $row['birthdate'] ? date('M d, Y', strtotime($row['birthdate'])) : '';
        $created_at = date('M d, Y', strtotime($row['created_at']));

        $is_returning = $row['is_returning'] ? 'Yes' : 'No';
        $style_returning = $row['is_returning'] ? 'yes' : 'no';

        $with_lrn = $row['with_lrn'] ? 'Yes' : 'No';
        $style_lrn = $row['with_lrn'] ? 'yes' : 'no';

        $is_4ps = $row['is_4ps_beneficiary'] ? 'Yes' : 'No';
        $style_4ps = $row['is_4ps_beneficiary'] ? 'yes' : 'no';

        $is_pwd = $row['is_pwd'] ? 'Yes' : 'No';
        $style_pwd = $row['is_pwd'] ? 'yes' : 'no';

        $is_ip = $row['is_ip'] ? 'Yes' : 'No';
        $style_ip = $row['is_ip'] ? 'yes' : 'no';

        echo "<tr>
            <td>" . htmlspecialchars($row['id']) . "</td>
            <td>" . htmlspecialchars($row['lrn']) . "</td>
            <td>" . htmlspecialchars($row['full_name']) . "</td>
            <td>" . htmlspecialchars($row['grade_level_to_enroll']) . "</td>
            <td>" . htmlspecialchars($row['sex']) . "</td>
            <td>" . htmlspecialchars($row['age']) . "</td>
            <td><span class='badge {$style_returning}'>{$is_returning}</span></td>
            <td><span class='badge {$style_lrn}'>{$with_lrn}</span></td>
        </tr>";
    }

    echo '</tbody>
        </table>
    </body>
    </html>';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Registration Report</title>
    <!-- Sidebar CSS needed for admin_sidebar.php -->
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">

    <style>
        :root {
            --primary-color: #0f52ba;
            /* Sapphire Blue */
            --primary-hover: #0a3d8f;
            --secondary-color: #6c757d;
            --secondary-hover: #5a6268;
            --bg-color: #f4f6f9;
            --card-bg: #ffffff;
            --text-color: #333333;
            --border-color: #dee2e6;
            --success-bg: #d4edda;
            --success-text: #155724;
            --danger-bg: #f8d7da;
            --danger-text: #721c24;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: var(--bg-color);
            color: var(--text-color);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--card-bg);
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
        }

        .main-content {
            margin-top: var(--header-height);
            /* Height of fixed header */
            padding: 20px;
            transition: margin-left 0.25s ease;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-top: 88px;
                /* Height of mobile header */
                margin-left: 0 !important;
                padding: 10px;
            }

            .container {
                padding: 15px;
            }
        }

        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .page-header h1 {
            color: var(--primary-color);
            margin: 0 0 10px 0;
            font-size: 24px;
            font-weight: 600;
        }

        .page-header p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(15, 82, 186, 0.3);
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: white;
            color: var(--secondary-color);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background-color: #f8f9fa;
            border-color: #c1c9d0;
            color: var(--secondary-hover);
        }

        .report-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            font-size: 13px;
        }

        .report-table th,
        .report-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }

        .report-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
            z-index: 10;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        @media (max-width: 768px) {
            .report-table th {
                top: 0;
            }
        }

        .report-table tr:hover {
            background-color: #f9f9f9;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed var(--border-color);
        }

        .summary {
            background: #eef2f7;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid var(--primary-color);
            color: #495057;
        }

        .summary h3 {
            margin-top: 0;
            color: var(--primary-color);
            font-size: 16px;
        }

        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #555;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background-color: white;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(15, 82, 186, 0.1);
        }

        .export-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .filter-row {
                grid-template-columns: 1fr;
            }

            .export-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                width: 100%;
            }
        }

        .table-container {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-yes {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .status-no {
            background: var(--danger-bg);
            color: var(--danger-text);
        }
    </style>
</head>

<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h1>Detailed Registration Report</h1>
                <p>Complete registration details and statistics</p>
            </div>

            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="date_from">Date From:</label>
                            <input type="date" id="date_from" name="date_from"
                                value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="date_to">Date To:</label>
                            <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="grade_level">Grade Level:</label>
                            <select id="grade_level" name="grade_level">
                                <option value="">All Grades</option>
                                <?php foreach ($grade_levels as $grade): ?>
                                    <option value="<?= htmlspecialchars($grade) ?>" <?= $grade_level === $grade ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($grade) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <a href="detailed.php" class="btn btn-secondary">Clear</a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="export-buttons">
                <button type="button" class="btn btn-primary" onclick="openExportModal()">Export Report</button>
                <a href="../index.php" class="btn btn-secondary">← Back to Reports</a>
            </div>

            <!-- Export Modal -->
            <div id="exportModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeExportModal()">&times;</span>
                    <h2>Select Export Format</h2>
                    <p>Please choose your preferred format:</p>
                    <div class="modal-buttons">
                        <a href="#" onclick="exportReport('pdf'); return false;" class="btn btn-export-option">
                            <span class="icon">📄</span> Download as PDF
                        </a>
                        <a href="#" onclick="exportReport('excel'); return false;" class="btn btn-export-option">
                            <span class="icon">📊</span> Download as Excel
                        </a>
                    </div>
                </div>
            </div>

            <style>
                /* Modal Styles */
                .modal {
                    display: none;
                    position: fixed;
                    z-index: 1001;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0, 0, 0, 0.5);
                    animation: fadeIn 0.3s;
                }

                .modal-content {
                    background-color: #fefefe;
                    margin: 15% auto;
                    padding: 30px;
                    border: 1px solid #888;
                    width: 90%;
                    max-width: 500px;
                    border-radius: 8px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
                    position: relative;
                    text-align: center;
                    animation: slideIn 0.3s;
                }

                .close {
                    color: #aaa;
                    float: right;
                    font-size: 28px;
                    font-weight: bold;
                    cursor: pointer;
                    position: absolute;
                    right: 15px;
                    top: 10px;
                }

                .close:hover,
                .close:focus {
                    color: black;
                    text-decoration: none;
                    cursor: pointer;
                }

                .modal-buttons {
                    display: flex;
                    flex-direction: column;
                    gap: 15px;
                    margin-top: 25px;
                }

                .btn-export-option {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    padding: 15px;
                    font-size: 16px;
                    background-color: #f8f9fa;
                    border: 1px solid #dee2e6;
                    color: #333;
                    transition: all 0.2s;
                }

                .btn-export-option:hover {
                    background-color: #e9ecef;
                    transform: translateY(-2px);
                    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
                    color: #007bff;
                    border-color: #007bff;
                }

                @keyframes fadeIn {
                    from {
                        opacity: 0
                    }

                    to {
                        opacity: 1
                    }
                }

                @keyframes slideIn {
                    from {
                        transform: translateY(-20px);
                        opacity: 0
                    }

                    to {
                        transform: translateY(0);
                        opacity: 1
                    }
                }
            </style>

            <script>
                function openExportModal() {
                    document.getElementById('exportModal').style.display = 'block';
                }

                function closeExportModal() {
                    document.getElementById('exportModal').style.display = 'none';
                }

                function exportReport(format) {
                    if (format === 'pdf') {
                        generatePDF();
                        closeExportModal();
                        return;
                    }

                    // Get current URL parameters
                    const urlParams = new URLSearchParams(window.location.search);

                    // Add export format
                    urlParams.set('export', format);

                    // Construct new URL
                    const exportUrl = '?' + urlParams.toString();

                    // Redirect/Download
                    window.location.href = exportUrl;

                    // Close modal
                    closeExportModal();
                }

                function generatePDF() {
                    // Select the element to be converted
                    const element = document.querySelector('.main-content');

                    // Clone the element to modify it for PDF without affecting the view
                    const clone = element.cloneNode(true);

                    // Remove buttons, modal, filters, and non-printable elements from clone
                    // Explicitly targeting #exportModal and .modal to ensure it is gone
                    const unwanted = clone.querySelectorAll('.export-buttons, .back-button, .btn, .no-print, .modal, .filters, #exportModal');
                    unwanted.forEach(el => el.remove());

                    // Fix table overflow for PDF (ensure full table is visible)
                    const tableContainer = clone.querySelector('.table-container');
                    if (tableContainer) {
                        tableContainer.style.overflow = 'visible';
                        tableContainer.style.maxHeight = 'none';
                        tableContainer.style.width = '100%';
                    }

                    // Ensure table fits nicely
                    const table = clone.querySelector('table');
                    if (table) {
                        table.style.width = '100%';
                        table.style.fontSize = '9px'; // Reduced font size for better fit on legal paper
                    }

                    // Options for PDF generation
                    const opt = {
                        margin: 0.2, // Tighter margin
                        filename: 'detailed_registration_report.pdf',
                        image: {
                            type: 'jpeg',
                            quality: 1
                        },
                        html2canvas: {
                            scale: 4,
                            useCORS: true,
                            scrollY: 0
                        }, // Scale 4 for max clarity
                        jsPDF: {
                            unit: 'in',
                            format: 'legal',
                            orientation: 'landscape'
                        }
                    };

                    // Generate PDF
                    html2pdf().set(opt).from(clone).save();
                }

                // Close modal when clicking outside
                window.onclick = function (event) {
                    const modal = document.getElementById('exportModal');
                    if (event.target == modal) {
                        modal.style.display = "none";
                    }
                }
            </script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

            <?php if (!empty($reports)): ?>
                <div class="summary">
                    <h3>Report Summary</h3>
                    <p><strong>Total Records:</strong> <?= count($reports) ?></p>
                    <?php if ($date_from || $date_to): ?>
                        <p><strong>Date Range:</strong>
                            <?= $date_from ? date('M d, Y', strtotime($date_from)) : 'Start' ?> -
                            <?= $date_to ? date('M d, Y', strtotime($date_to)) : 'End' ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($grade_level): ?>
                        <p><strong>Grade Level:</strong> <?= htmlspecialchars($grade_level) ?></p>
                    <?php endif; ?>
                </div>

                <div class="table-container">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>LRN</th>
                                <th>Full Name</th>
                                <th>Grade Level</th>
                                <th>Sex</th>
                                <th>Age</th>
                                <th>Returning</th>
                                <th>With LRN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?= htmlspecialchars($report['id']) ?></td>
                                    <td><?= htmlspecialchars($report['lrn']) ?></td>
                                    <td><?= htmlspecialchars($report['full_name']) ?></td>
                                    <td><?= htmlspecialchars($report['grade_level_to_enroll']) ?></td>
                                    <td><?= htmlspecialchars($report['sex']) ?></td>
                                    <td><?= htmlspecialchars($report['age']) ?></td>
                                    <td>
                                        <span class="status-badge <?= $report['is_returning'] ? 'status-yes' : 'status-no' ?>">
                                            <?= $report['is_returning'] ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $report['with_lrn'] ? 'status-yes' : 'status-no' ?>">
                                            <?= $report['with_lrn'] ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <h3>No Data Found</h3>
                    <p>No registration records found for the specified criteria.</p>
                    <a href="../index.php" class="btn">← Back to Reports</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
