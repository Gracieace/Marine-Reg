<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php'; // Ensure url_for is available

$pdo = db_connect();
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';

// Get available grade levels and sections
$grade_levels = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 140px 20px 20px;
            background-color: #f5f5f5;
        }

        @media (max-width: 768px) {
            body {
                padding-top: 110px;
            }
        }

        .container {
            max-width: 1200px;
            margin-bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }

        .header h1 {
            color: #007bff;
            margin: 0;
        }

        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
        }

        .filter-group {
            flex: 1;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
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
        }

        .btn:hover {
            background: #0056b3;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .report-category {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .report-category h3 {
            color: #007bff;
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .report-links {
            list-style: none;
            padding: 0;
        }

        .report-links li {
            margin-bottom: 10px;
        }

        .report-links a {
            display: block;
            padding: 10px 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }

        .report-links a:hover {
            background: #e9ecef;
            border-color: #007bff;
            color: #007bff;
        }

        .description {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>

<body>
    <?php include '../../header.php'; ?>
    <?php include '../registrar_side_panel.php'; ?>

    <div class="container main-content">
        <div class="page-header">
            <h1>Reports Dashboard</h1>
            <p>Generate and view various school reports</p>
        </div>

        <div class="filters">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="date_from">Date From:</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
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
                        <label for="section">Section:</label>
                        <select id="section" name="section">
                            <option value="">All Sections</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="btn">Apply Filters</button>
                        <a href="?" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="reports-grid">
            <div class="report-category">
                <h3>📊 Enrollment Reports</h3>
                <ul class="report-links">
                    <li>
                        <a
                            href="enrollment/detailed.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            Detailed Enrollment Report
                            <div class="description">Complete enrollment details with student information</div>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="report-category">
                <h3>📝 Registration Reports</h3>
                <ul class="report-links">
                    <li>
                        <a
                            href="registration/detailed.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&grade_level=<?= urlencode($grade_level) ?>">
                            Detailed Registration Report
                            <div class="description">Complete registration details and statistics</div>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="report-category">
                <h3>📋 School Forms (DepEd)</h3>
                <ul class="report-links">
                    <li>
                        <a
                            href="school_forms/sf1.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            SF1 - School Register
                            <div class="description">School register of learners</div>
                        </a>
                    </li>
                    <li>
                        <a
                            href="school_forms/sf2.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            SF2 - Daily Attendance Report
                            <div class="description">Daily attendance report of learners</div>
                        </a>
                    </li>
                    <li>
                        <a
                            href="school_forms/sf3.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            SF3 - Books Issued and Returned
                            <div class="description">Books issued and returned to learners</div>
                        </a>
                    </li>
                    <li>
                        <a
                            href="school_forms/sf4.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            SF4 - Summary of Enrollment
                            <div class="description">Summary of enrollment by grade level and section</div>
                        </a>
                    </li>
                    <li>
                        <a
                            href="school_forms/sf5.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            SF5 - Report on Promotion
                            <div class="description">Report on promotion and learning progress</div>
                        </a>
                    </li>
                    <li>
                        <a
                            href="school_forms/sf6.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            SF6 - Summary of School Statistics
                            <div class="description">Summary of school statistics and demographics</div>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="report-category">
                <h3>📖 Student Records</h3>
                <ul class="report-links">
                    <li>
                        <a href="eclass_record/school_register_database.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>">
                            School Register Database
                            <div class="description">Comprehensive student database with academic and personal information</div>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</body>

</html>
