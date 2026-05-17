<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role(['teacher', 'admin']);

$user_id = $_SESSION['user']['id'];
$pdo = db_connect();

// Get active school year
$current_sy = get_active_school_year($pdo);

// 1. Fetch Teacher Assignments
// 1a. Advisory Assignment
$stmt = $pdo->prepare("SELECT grade_level, section_name FROM sections WHERE adviser_id = ? AND school_year = ? LIMIT 1");
$stmt->execute([$user_id, $current_sy]);
$advisory = $stmt->fetch();

// Helper for status check
function checkSubmission($pdo, $table, $params) {
    if (!$table) return false;
    $where = [];
    foreach ($params as $key => $val) { $where[] = "$key = ?"; }
    $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(" AND ", $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    return $stmt->fetchColumn() > 0;
}

// Custom check for SF9 (checks if all students have grades)
function checkSF9Status($pdo, $grade, $section, $sy) {
    // Total students in section
    $sql_total = "SELECT COUNT(*) FROM enrollments WHERE grade_level = ? AND section = ? AND school_year = ?";
    $st_total = $pdo->prepare($sql_total); 
    $st_total->execute([$grade, $section, $sy]);
    $total_count = $st_total->fetchColumn();

    if ($total_count == 0) return false;

    // Students with records in sf9_reports (Final Rating/Promotion)
    $sql_records = "SELECT COUNT(DISTINCT h.student_id) FROM sf9_reports h 
                    JOIN enrollments e ON h.student_id = e.student_id 
                    WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?";
    $st_records = $pdo->prepare($sql_records); 
    $st_records->execute([$grade, $section, $sy]);
    $records_count = $st_records->fetchColumn();

    return ($records_count >= $total_count);
}

// Custom check for SF8 (Health Profile)
function checkSF8Status($pdo, $grade, $section, $sy) {
    $sql = "SELECT COUNT(*) FROM sf8_health_profile h
            JOIN enrollments e ON h.student_id = e.student_id
            WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$grade, $section, $sy]);
    return $stmt->fetchColumn() > 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard | Teacher Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-soft: rgba(37, 99, 235, 0.1);
            --secondary: #64748b;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --radius: 16px;
        }

        body { font-family: 'Outfit', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; }
        
        .main-content { 
            padding: calc(var(--header-height) + 40px) 40px 60px !important; 
            max-width: 1400px; 
            margin-right: auto;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 24px;
            padding: 48px;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 48px;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1);
        }
        .hero::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.2) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero h1 { margin: 0; font-size: 36px; font-weight: 800; letter-spacing: -0.02em; }
        .hero p { margin: 12px 0 0; opacity: 0.8; font-size: 16px; max-width: 600px; }
        
        .sy-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 16px;
            border-radius: 100px;
            font-weight: 600;
            font-size: 14px;
            margin-top: 24px;
            backdrop-filter: blur(4px);
        }

        /* Section Styling */
        .section-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        .section-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .section-line {
            height: 4px;
            flex: 1;
            background: var(--border);
            border-radius: 2px;
        }

        /* Grid Layout */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 24px;
            margin-bottom: 56px;
        }

        /* Card Styling */
        .report-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 20px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .report-card:hover:not(.disabled) {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1);
        }
        .report-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: #f1f5f9;
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .icon-box {
            width: 56px;
            height: 56px;
            background: var(--primary-soft);
            color: var(--primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .status-pill {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-stored { background: #dcfce7; color: #15803d; }
        .status-pending { background: #fff7ed; color: #9a3412; }

        .card-body h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
        }
        .card-body p {
            margin: 8px 0 0;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .card-footer {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
        }

        /* Advisory Info Bar */
        .advisory-bar {
            background: var(--primary-soft);
            border: 1px solid rgba(37, 99, 235, 0.2);
            padding: 16px 24px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 32px;
            color: var(--primary);
            font-weight: 600;
        }

        @media (max-width: 1024px) {
            .main-content { padding: calc(var(--header-height) + 30px) 30px 40px !important; }
            .reports-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
            .hero { padding: 40px; }
        }

        @media (max-width: 768px) {
            .main-content { padding: calc(var(--header-height) + 20px) 20px 40px !important; }
            .hero { padding: 32px; margin-bottom: 32px; }
            .hero h1 { font-size: 28px; }
            .hero p { font-size: 14px; }
            .reports-grid { grid-template-columns: 1fr; gap: 16px; }
            .section-header h2 { font-size: 16px; }
            .advisory-bar { padding: 12px 16px; font-size: 14px; flex-wrap: wrap; }
        }

        @media (max-width: 480px) {
            .hero { padding: 24px; border-radius: 16px; }
            .hero h1 { font-size: 22px; }
            .sy-badge { font-size: 11px; padding: 6px 12px; flex-wrap: wrap; }
            .report-card { padding: 20px; }
            .icon-box { width: 44px; height: 44px; font-size: 18px; }
            .card-body h3 { font-size: 16px; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/teacher_header.php'; ?>
    <?php require_once __DIR__ . '/teacher_side_panel.php'; ?>

    <div class="main-content">
        <div class="hero">
            <h1>Reports & Submissions</h1>
            <p>Access and manage official DepEd school forms, learner progress reports, and classroom records in one centralized location.</p>
            <div class="sy-badge">
                <i class="fa-regular fa-calendar"></i>
                Active School Year: <?= htmlspecialchars($current_sy) ?>
            </div>
        </div>

        <?php if ($advisory): ?>
            <div class="advisory-bar">
                <i class="fa-solid fa-chalkboard-user"></i>
                Advisory Assignment: <?= htmlspecialchars($advisory['grade_level'] . ' - ' . $advisory['section_name']) ?>
            </div>

            <!-- 1. DepEd School Forms -->
            <div class="section-header">
                <h2>DepEd School Forms</h2>
                <div class="section-line"></div>
            </div>

            <div class="reports-grid">
                <?php
                $advisory_forms = [
                    ['id'=>'SF1','name'=>'SF 1: School Register','table'=>'sf1_reports','link'=>'reports/sf1_form.php','icon'=>'fa-id-card','desc'=>'Master list of learners containing basic profile and metadata.'],
                    ['id'=>'SF2','name'=>'SF 2: Daily Attendance','table'=>'sf2_reports','link'=>'reports/sf2_form.php','icon'=>'fa-calendar-check','desc'=>'Daily monitoring of learner attendance and monthly summary.'],
                    ['id'=>'SF3','name'=>'SF 3: Books Issued','table'=>'sf3_reports','link'=>'reports/sf3_form.php','icon'=>'fa-book','desc'=>'Inventory of textbooks and other learning materials issued to learners.'],
                    ['id'=>'SF5','name'=>'SF 5: Report on Promotion','table'=>'sf5_reports','link'=>'reports/sf5_form.php','icon'=>'fa-user-graduate','desc'=>'Official list of promoted, retained, and conditional learners.']
                ];
                foreach($advisory_forms as $f): 
                    $stored = checkSubmission($pdo, $f['table'], ['grade_level'=>$advisory['grade_level'], 'section'=>$advisory['section_name'], 'school_year'=>$current_sy]);
                ?>
                    <a href="<?= $f['link'] ?>?grade=<?=urlencode($advisory['grade_level'])?>&section=<?=urlencode($advisory['section_name'])?>&sy=<?=urlencode($current_sy)?>" class="report-card">
                        <div class="card-top">
                            <div class="icon-box"><i class="fa-solid <?= $f['icon'] ?>"></i></div>
                            <span class="status-pill <?= $stored ? 'status-stored' : 'status-pending' ?>">
                                <?= $stored ? 'Finalized' : 'Pending' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <h3><?= $f['name'] ?></h3>
                            <p><?= $f['desc'] ?></p>
                        </div>
                        <div class="card-footer">
                            <span>Open Module</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- 2. Learner Progress -->
            <div class="section-header">
                <h2>Learner Progress</h2>
                <div class="section-line"></div>
            </div>

            <div class="reports-grid">
                <?php
                $progress_forms = [
                    ['id'=>'SF9','name'=>'SF 9: Progress Report','type'=>'SF9','link'=>'reports/sf9_form.php','icon'=>'fa-file-invoice','desc'=>'The official learner report card containing quarterly grades.'],
                    ['id'=>'SF10','name'=>'SF 10: Permanent Record','type'=>'SF10','link'=>'reports/sf10_form.php','icon'=>'fa-folder-open','desc'=>'Full academic history of the learner throughout JHS/SHS.']
                ];
                foreach($progress_forms as $f):
                    $stored = false;
                    if($f['id']=='SF9') {
                        $stored = checkSF9Status($pdo, $advisory['grade_level'], $advisory['section_name'], $current_sy);
                    } else { 
                        $stored = true; // SF10 is usually always available to view
                    }
                ?>
                    <a href="<?= $f['link'] ?>?grade=<?=urlencode($advisory['grade_level'])?>&section=<?=urlencode($advisory['section_name'])?>&sy=<?=urlencode($current_sy)?>" class="report-card">
                        <div class="card-top">
                            <div class="icon-box"><i class="fa-solid <?= $f['icon'] ?>"></i></div>
                            <span class="status-pill <?= $stored ? 'status-stored' : 'status-pending' ?>">
                                <?= $stored ? 'Finalized' : 'In Progress' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <h3><?= $f['name'] ?></h3>
                            <p><?= $f['desc'] ?></p>
                        </div>
                        <div class="card-footer">
                            <span>Manage Records</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="report-card disabled" style="max-width: 600px; margin: 0 auto;">
                <div class="card-top">
                    <div class="icon-box" style="background: #fee2e2; color: #ef4444;"><i class="fa-solid fa-triangle-exclamation"></i></div>
                </div>
                <div class="card-body">
                    <h3>No Advisory Assignment Found</h3>
                    <p>Access to official DepEd school forms (SF1-SF10) is restricted to class advisers. Please contact the administrator if you are assigned as an adviser for this school year.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>