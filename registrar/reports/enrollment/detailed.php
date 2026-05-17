<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php'; // Ensure url_for is available

// Handle report generation
$export_format = $_GET['export'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$search = $_GET['search'] ?? '';

$pdo = db_connect();
$reports = [];

// Get available grade levels and sections for filters
$grade_levels_opts = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$sections_opts = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

// Generate enrollment detailed report
$reports = generateEnrollmentDetailed($pdo, $date_from, $date_to, $grade_level, $section, $search);

// Handle export
if ($export_format && !empty($reports)) {
    handleExport($reports, $export_format, 'enrollment_detailed');
    exit;
}

function generateEnrollmentDetailed($pdo, $date_from, $date_to, $grade_level, $section, $search)
{
    $where_conditions = [];
    $params = [];

    if ($date_from) {
        $where_conditions[] = "e.enrolled_at >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $where_conditions[] = "e.enrolled_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    if ($grade_level) {
        $where_conditions[] = "e.grade_level = ?";
        $params[] = $grade_level;
    }
    if ($section) {
        $where_conditions[] = "e.section = ?";
        $params[] = $section;
    }
    if ($search) {
        $where_conditions[] = "(e.student_name LIKE ? OR COALESCE(r.lrn, e.lrn) LIKE ? OR e.student_id LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "SELECT
                e.id as enroll_id,
                e.student_id,
                e.student_name,
                e.grade_level,
                e.section,
                e.school_year,
                e.enrolled_at,
                /* LRN - prefer registrations, fall back to enrollments column */
                COALESCE(r.lrn, e.lrn) as lrn,
                /* Personal Info */
                COALESCE(r.birthdate, e.birthdate) as birthdate,
                r.age,
                r.sex,
                r.birthplace_city,
                r.birthplace_province,
                r.mother_tongue,
                /* Current Address */
                COALESCE(
                    CONCAT_WS(', ',
                        NULLIF(r.curr_house_no,''), NULLIF(r.curr_street,''),
                        NULLIF(r.curr_barangay,''), NULLIF(r.curr_city,''),
                        NULLIF(r.curr_province,'')
                    ),
                    e.address
                ) as address,
                /* Parents / Guardian */
                CONCAT_WS(' ', NULLIF(r.father_first,''), NULLIF(r.father_last,'')) as father_name,
                r.father_contact,
                CONCAT_WS(' ', NULLIF(r.mother_first,''), NULLIF(r.mother_last,'')) as mother_name,
                r.mother_contact,
                COALESCE(
                    CONCAT_WS(' ', NULLIF(r.guardian_first,''), NULLIF(r.guardian_last,'')),
                    CONCAT_WS(' ', NULLIF(e.guardian_first,''), NULLIF(e.guardian_last,''))
                ) as guardian_name,
                COALESCE(r.guardian_contact, e.guardian_contact) as guardian_contact,
                COALESCE(r.id_contact_person, e.id_contact_person) as id_contact_person,
                /* Special flags */
                r.is_4ps_beneficiary,
                r.four_ps_household_id,
                r.is_pwd,
                r.disability_types,
                r.is_ip,
                r.ip_ethnicity,
                /* Previous school */
                r.last_grade_completed,
                r.last_sy_completed,
                r.last_school_attended
            FROM enrollments e
            LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.lrn))
            $where_clause
            GROUP BY e.id
            ORDER BY e.grade_level, e.section, e.student_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function handleExport($data, $format, $report_type)
{
    if ($format === 'pdf') {
        // PDF export logic would go here
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="enrollment_detailed_report.pdf"');
        // PDF generation code
        echo "PDF export not implemented yet";
    } elseif ($format === 'excel') {
        // Excel export logic would go here
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="enrollment_detailed_report.xls"');
        // Excel generation code
        echo "Excel export not implemented yet";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Enrollment Report</title>
    <!-- Relative paths for CSS -->
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --surface: #ffffff;
            --background: #f8fafc;
            /* Slightly lighter background */
            --text-main: #1e293b;
            /* Darker slate/gray */
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --radius: 0.75rem;
            /* Increased radius */
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-main);
            margin: 0;
            padding-top: 180px;
            padding-bottom: 40px;
        }

        @media (max-width: 768px) {
            body {
                padding-top: 120px;
            }
        }

        .main-content {
            padding: 0 3rem;
            max-width: 1600px;
            /* Wider canvas */
            /* Margin auto removed to respect sidebar offset */
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 0 1.5rem;
            }
        }

        /* Header Section */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            /* Center vertically */
            margin-bottom: 2.5rem;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 800;
            /* Bolder */
            color: var(--text-main);
            margin: 0 0 0.5rem 0;
            letter-spacing: -0.025em;
        }

        .page-title p {
            color: var(--text-secondary);
            font-size: 1rem;
            font-weight: 400;
            margin: 0;
            max-width: 600px;
        }

        /* Buttons */
        .actions-group {
            display: flex;
            gap: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            cursor: pointer;
            gap: 0.5rem;
            line-height: 1;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:hover {
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.25);
            transform: translateY(-1px);
        }

        .btn-outline {
            background-color: white;
            border-color: var(--border);
            color: var(--text-main);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        .btn-outline:hover {
            background-color: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        /* Filter Section */
        .filter-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            align-items: end;
        }

        .search-span {
            grid-column: span 4;
        }

        @media (max-width: 1024px) {
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }

            .search-span {
                grid-column: span 2;
            }
        }

        @media (max-width: 640px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .search-span {
                grid-column: span 1;
            }
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Input Wrapper for positioning icons */
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            color: #94a3b8;
            pointer-events: none;
            width: 20px;
            height: 20px;
            transition: color 0.2s;
        }

        .form-control {
            width: 100%;
            height: 50px;
            /* Slightly taller for modern look */
            padding: 0 1rem;
            font-size: 0.95rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            /* More rounded */
            background-color: #ffffff;
            color: var(--text-main);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
            box-sizing: border-box;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            /* Subtle depth */
        }

        .form-control.has-icon {
            padding-left: 3rem;
            /* Space for icon */
        }

        .form-control::placeholder {
            color: #cbd5e1;
        }

        .form-control:hover {
            border-color: #cbd5e1;
            background-color: #fcfcfc;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
            /* Beautiful focus ring */
        }

        .form-control:focus+.input-icon,
        .input-wrapper:focus-within .input-icon {
            color: var(--primary);
        }

        /* Custom Select Styling */
        select.form-control {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
            padding-right: 2.5rem;
            cursor: pointer;
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Card Container - Table */
        .card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2rem;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-weight: 700;
            font-size: 1.125rem;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title::before {
            content: '';
            display: block;
            width: 4px;
            height: 1.5rem;
            background: var(--primary);
            border-radius: 2px;
        }

        .card-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 500;
            background: #f1f5f9;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
        }

        /* Table */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            /* Required for border-radius on rows if desired, but standard is fine */
            border-spacing: 0;
            font-size: 0.95rem;
        }

        th {
            background: #f8fafc;
            text-align: left;
            padding: 1rem 2rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        td {
            padding: 1.25rem 2rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            vertical-align: middle;
        }

        tbody tr {
            transition: background-color 0.1s ease;
        }

        tbody tr:hover {
            background-color: #f8fafc;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        /* Empty State */
        .empty-state {
            padding: 6rem 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
            background: #f1f5f9;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0 0 0.5rem 0;
        }

        .empty-state p {
            color: var(--text-secondary);
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.5;
        }
    </style>
</head>

<body>
    <?php include '../../../header.php'; ?>
    <?php include '../../registrar_side_panel.php'; ?>

    <main class="main-content">
        <div class="content-header">
            <div class="page-title">
                <h1>Detailed Enrollment Report</h1>
                <p>Track student enrollment data, monitor trends, and export comprehensive reports.</p>
            </div>

            <div class="actions-group">
                <?php if (!empty($reports)): ?>
                    <a href="?export=excel&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&search=<?= urlencode($search) ?>"
                        class="btn btn-primary" title="Export as Excel">
                        <span style="font-size: 1.2em">📊</span> Export Excel
                    </a>
                    <a href="?export=pdf&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&search=<?= urlencode($search) ?>"
                        class="btn btn-outline" title="Export as PDF">
                        <span style="font-size: 1.2em">📄</span> Export PDF
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-card">
            <form method="GET" class="filter-form">
                <div class="form-group search-span">
                    <label for="search">Find Student</label>
                    <div class="input-wrapper">
                        <!-- Search Icon -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input type="text" id="search" name="search" class="form-control has-icon"
                            placeholder="Search by Name, LRN, or Student ID..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="date_from">Enrolled From</label>
                    <input type="date" id="date_from" name="date_from" class="form-control"
                        value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div class="form-group">
                    <label for="date_to">Enrolled To</label>
                    <input type="date" id="date_to" name="date_to" class="form-control"
                        value="<?= htmlspecialchars($date_to) ?>">
                </div>

                <div class="form-group">
                    <label for="grade_level">Grade Level</label>
                    <select id="grade_level" name="grade_level" class="form-control">
                        <option value="">All Grades</option>
                        <?php foreach ($grade_levels_opts as $grade): ?>
                            <option value="<?= htmlspecialchars($grade) ?>" <?= $grade === $grade_level ? 'selected' : '' ?>>
                                <?= htmlspecialchars($grade) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="section">Section</label>
                    <select id="section" name="section" class="form-control">
                        <option value="">All Sections</option>
                        <?php foreach ($sections_opts as $sec): ?>
                            <option value="<?= htmlspecialchars($sec) ?>" <?= $sec === $section ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sec) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group"
                    style="grid-column: span 4; display: flex; justify-content: flex-end; gap: 1rem; border-top: 1px solid var(--border); padding-top: 1.5rem; margin-top: 0.5rem;">
                    <a href="detailed.php" class="btn btn-outline" style="min-width: 120px;">Clear Filters</a>
                    <button type="submit" class="btn btn-primary" style="min-width: 120px;">Apply Filters</button>
                </div>
            </form>
        </div>

        <?php if (!empty($reports)): ?>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Enrollment Records</h2>
                    <div class="card-meta">
                        <?= count($reports) ?> Result<?= count($reports) !== 1 ? 's' : '' ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>LRN</th>
                                <th>Grade & Section</th>
                                <th>School Year</th>
                                <th>Birthdate</th>
                                <th>Age</th>
                                <th>Sex</th>
                                <th>Address</th>
                                <th>Father</th>
                                <th>Mother</th>
                                <th>Guardian</th>
                                <th>4Ps</th>
                                <th>PWD</th>
                                <th>Previous School</th>
                                <th>Enrolled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $row_num = 1; foreach ($reports as $report): ?>
                                <tr>
                                    <td style="color: var(--text-secondary); font-size:0.8rem;"><?= $row_num++ ?></td>
                                    <td style="font-weight: 600; font-family: 'Monaco','Consolas',monospace; color: var(--primary); white-space:nowrap;">
                                        <?= htmlspecialchars($report['student_id']) ?>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <div style="font-weight: 600;"><?= htmlspecialchars($report['student_name']) ?></div>
                                    </td>
                                    <td style="color: var(--text-secondary); font-family: monospace; font-size:0.9rem; white-space:nowrap;">
                                        <?= $report['lrn'] ? htmlspecialchars($report['lrn']) : '<span style="color:#cbd5e1">—</span>' ?>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <div style="font-weight: 600;"><?= htmlspecialchars($report['grade_level']) ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($report['section']) ?></div>
                                    </td>
                                    <td style="white-space:nowrap; color: var(--text-secondary);">
                                        <?= $report['school_year'] ? htmlspecialchars($report['school_year']) : '—' ?>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <?= $report['birthdate'] ? date('M d, Y', strtotime($report['birthdate'])) : '<span style="color:#cbd5e1">—</span>' ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?= $report['age'] ?? '<span style="color:#cbd5e1">—</span>' ?>
                                    </td>
                                    <td>
                                        <?php if ($report['sex']): ?>
                                            <span style="display:inline-block; padding:2px 8px; border-radius:4px;
                                                background:<?= ($report['sex'] === 'Male' || $report['sex'] === 'M') ? '#eff6ff' : '#fdf2f8' ?>;
                                                color:<?= ($report['sex'] === 'Male' || $report['sex'] === 'M') ? '#1d4ed8' : '#be185d' ?>;
                                                font-weight:600; font-size:0.75rem; white-space:nowrap;">
                                                <?= htmlspecialchars($report['sex']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:180px; font-size:0.85rem; line-height:1.4;">
                                        <?= $report['address'] ? htmlspecialchars($report['address']) : '<span style="color:#cbd5e1">—</span>' ?>
                                    </td>
                                    <td style="font-size:0.85rem; white-space:nowrap;">
                                        <?php if ($report['father_name'] || $report['father_contact']): ?>
                                            <?php if ($report['father_name']): ?>
                                                <div style="font-weight:500;"><?= htmlspecialchars($report['father_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($report['father_contact']): ?>
                                                <div style="color:var(--text-secondary); font-size:0.8rem;">📞 <?= htmlspecialchars($report['father_contact']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.85rem; white-space:nowrap;">
                                        <?php if ($report['mother_name'] || $report['mother_contact']): ?>
                                            <?php if ($report['mother_name']): ?>
                                                <div style="font-weight:500;"><?= htmlspecialchars($report['mother_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($report['mother_contact']): ?>
                                                <div style="color:var(--text-secondary); font-size:0.8rem;">📞 <?= htmlspecialchars($report['mother_contact']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.85rem; white-space:nowrap;">
                                        <?php if ($report['guardian_name'] || $report['guardian_contact']): ?>
                                            <?php if ($report['guardian_name']): ?>
                                                <div style="font-weight:500;"><?= htmlspecialchars($report['guardian_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($report['guardian_contact']): ?>
                                                <div style="color:var(--text-secondary); font-size:0.8rem;">📞 <?= htmlspecialchars($report['guardian_contact']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($report['id_contact_person']): ?>
                                                <div style="font-size:0.75rem; color:#94a3b8;">Contact: <?= ucfirst($report['id_contact_person']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($report['is_4ps_beneficiary']): ?>
                                            <span style="background:#fef9c3; color:#92400e; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:600;">4Ps</span>
                                            <?php if ($report['four_ps_household_id']): ?>
                                                <div style="font-size:0.75rem; color:var(--text-secondary);"><?= htmlspecialchars($report['four_ps_household_id']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($report['is_pwd']): ?>
                                            <span style="background:#fae8ff; color:#86198f; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:600;">PWD</span>
                                            <?php if ($report['disability_types']): ?>
                                                <div style="font-size:0.75rem; color:var(--text-secondary); max-width:100px;"><?= htmlspecialchars($report['disability_types']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.85rem; white-space:nowrap;">
                                        <?php if ($report['last_school_attended']): ?>
                                            <div style="font-weight:500; max-width:160px; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($report['last_school_attended']) ?></div>
                                            <?php if ($report['last_grade_completed']): ?>
                                                <div style="color:var(--text-secondary); font-size:0.8rem;"><?= htmlspecialchars($report['last_grade_completed']) ?> | <?= htmlspecialchars($report['last_sy_completed'] ?? '') ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap; color:var(--text-secondary); font-size:0.9rem;">
                                        <?= date('M d, Y', strtotime($report['enrolled_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="card empty-state">
                <div class="empty-icon">
                    <span style="font-size: 1.5em;">🔍</span>
                </div>
                <h3>No records found</h3>
                <p>We couldn't find any enrollment records matching your filters. Try adjusting your search criteria or
                    clear your filters.</p>
                <div style="margin-top: 2rem;">
                    <a href="detailed.php" class="btn btn-outline">Clear All Filters</a>
                </div>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>
