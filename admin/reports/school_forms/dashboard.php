<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['admin']);
require_once __DIR__ . '/../../../config/db.php';

$pdo = db_connect();
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';

// Get default school year if not set
if (!$school_year) {
    $sy_stmt = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC LIMIT 1");
    $school_year = $sy_stmt->fetchColumn();
}

// Get available grade levels, sections, and school years
$grade_levels = $pdo->query("SELECT DISTINCT grade_level FROM enrollments ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
$school_years = $pdo->query("SELECT DISTINCT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Forms Repository | Admin Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-rgb: 37, 99, 235;
            --glass-bg: rgba(255, 255, 255, 0.9);
            --glass-border: rgba(255, 255, 255, 0.5);
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --accent-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            background-image: radial-gradient(at 0% 0%, hsla(210,100%,98%,1) 0, transparent 50%), 
                              radial-gradient(at 100% 0%, hsla(220,30%,95%,1) 0, transparent 50%);
            background-attachment: fixed;
            background-size: cover;
            margin: 0;
            padding: 0;
        }

        .main-content {
            padding: calc(var(--header-height) + 32px) 24px 64px;
            max-width: 1200px;
            margin-left: var(--sidebar-width, 260px);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0 !important; padding: calc(var(--header-height) + 20px) 16px 40px; }
        }

        .page-header {
            background: var(--primary-gradient);
            border-radius: 32px;
            padding: 48px;
            color: white;
            margin-bottom: 32px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: var(--accent-gradient);
            filter: blur(80px);
            opacity: 0.2;
            border-radius: 50%;
        }

        .page-header h1 { font-size: 36px; font-weight: 900; margin: 0; letter-spacing: -1.5px; display: flex; align-items: center; gap: 16px; }
        .page-header p { margin: 12px 0 0; opacity: 0.8; font-weight: 500; font-size: 16px; }

        .filter-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.03);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: flex-end;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 900;
            color: #94a3b8;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .form-control {
            width: 100%;
            padding: 14px 20px;
            border: 2px solid #f1f5f9;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            background: #f8fafc;
            box-sizing: border-box;
            color: #1e293b;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
            box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.1);
        }

        .btn-primary {
            background: #0f172a;
            color: white;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 900;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .section-header h2 {
            font-size: 14px;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin: 0;
            white-space: nowrap;
        }

        .section-line {
            height: 1px;
            background: #e2e8f0;
            flex-grow: 1;
        }

        .forms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 24px;
        }

        .form-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 28px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 24px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .form-card:hover {
            transform: translateY(-6px);
            border-color: #2563eb;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.1);
        }

        .form-card::after {
            content: '→';
            position: absolute;
            right: 28px;
            font-size: 20px;
            font-weight: 900;
            color: #2563eb;
            opacity: 0;
            transform: translateX(-10px);
            transition: all 0.3s ease;
        }

        .form-card:hover::after {
            opacity: 1;
            transform: translateX(0);
        }

        .icon-box {
            width: 64px;
            height: 64px;
            background: #f1f5f9;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .form-card:hover .icon-box {
            background: #2563eb;
            color: white;
            transform: scale(1.1) rotate(-5deg);
        }

        .form-content h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.5px;
        }

        .form-content p {
            margin: 6px 0 0;
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            line-height: 1.5;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
        }
    </style>
</head>
<body>
    <?php include '../../admin_header.php'; ?>
    <?php include '../../admin_sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1>📑 School Forms Repository</h1>
            <p>Access official DepEd reports and system-generated school documents.</p>
        </div>

        <div class="filter-card">
            <form method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>School Year</label>
                        <select name="school_year" class="form-control">
                            <?php foreach ($school_years as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= $school_year === $sy ? 'selected' : '' ?>><?= htmlspecialchars($sy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Grade Level</label>
                        <select name="grade_level" class="form-control">
                            <option value="">Select Grade</option>
                            <?php foreach ($grade_levels as $gl): ?>
                                <option value="<?= htmlspecialchars($gl) ?>" <?= $grade_level === $gl ? 'selected' : '' ?>><?= htmlspecialchars($gl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Section</label>
                        <select name="section" class="form-control">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= $section === $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn-primary clickable">Generate Reports</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="section-header">
            <h2>System Generated SF Forms</h2>
            <div class="section-line"></div>
        </div>

        <div class="forms-grid">
            <?php
            $forms = [
                ['name' => 'SF4 - Enrollment Summary', 'desc' => 'Monthly movement and attendance report', 'icon' => '📈', 'url' => 'sf4.php'],
                ['name' => 'SF6 - School Statistics', 'desc' => 'Consolidated demographics and figures', 'icon' => '📊', 'url' => 'sf6.php'],
                ['name' => 'SF7 - Personnel Profile', 'desc' => 'School personnel assignment list', 'icon' => '👨‍🏫', 'url' => 'sf7.php'],
                ['name' => 'SF8 - Health Profile', 'desc' => 'Learner basic health and nutrition', 'icon' => '🌡️', 'url' => 'sf8.php'],
            ];

            foreach ($forms as $f):
                $url = $f['url'] . "?grade_level=" . urlencode($grade_level) . "&section=" . urlencode($section) . "&school_year=" . urlencode($school_year);
            ?>
                <a href="<?= $url ?>" class="form-card clickable">
                    <div class="icon-box"><?= $f['icon'] ?></div>
                    <div class="form-content">
                        <h3><?= $f['name'] ?></h3>
                        <p><?= $f['desc'] ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
