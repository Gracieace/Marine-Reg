<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();
$role = $_SESSION['user']['role'];

$grade = trim($_GET['grade'] ?? '');
$section = trim($_GET['section'] ?? '');
$sy = trim($_GET['sy'] ?? '');

// Bidirectional normalization
$grade_clean = trim(str_ireplace('Grade', '', $grade));
$section_clean = trim(str_ireplace('Section', '', $section));
$sy_clean = str_replace(' ', '', $sy);
$sy_with_spaces = str_replace('-', ' - ', $sy_clean);

// Fetch enrolled students
$stmt = $pdo->prepare("
    SELECT e.student_id, e.student_name, e.lrn, r.sex 
    FROM enrollments e 
    LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.lrn = r.lrn AND e.lrn != '' AND e.lrn IS NOT NULL))
    WHERE (e.grade_level = ? OR e.grade_level = ? OR e.grade_level LIKE ?)
    AND (e.section = ? OR e.section = ? OR e.section LIKE ?)
    AND (e.school_year = ? OR e.school_year LIKE ? OR e.school_year = ?)
    ORDER BY CASE WHEN COALESCE(r.sex, 'M') IN ('F', 'Female', '2') THEN 2 ELSE 1 END ASC, 
             COALESCE(r.last_name, e.student_name) ASC
");
$stmt->execute([
    $grade, $grade_clean, "%$grade_clean%", 
    $section, $section_clean, "%$section_clean%", 
    $sy, "%$sy_clean%", $sy_with_spaces
]);
$students = $stmt->fetchAll();

$header_file = ($role === 'registrar') ? '../../../header.php' : '../../../admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF10 Portal | <?= htmlspecialchars($section) ?></title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --deped-blue: #0d47a1; --deped-red: #ef4444; --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.15); }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        .main-content { padding: 100px 40px 48px; margin-left: 0; }
        .portal-card { 
            background: white; border-radius: 24px; padding: 40px; box-shadow: var(--glass-shadow); 
            border: 1px solid rgba(226, 232, 240, 0.8); margin-top: 20px; position: relative; overflow: hidden;
            width: 100%; max-width: 1300px; margin-left: auto; margin-right: auto;
        }
        .portal-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, #1e293b, var(--deped-blue)); }
        .official-header { display: flex; align-items: center; justify-content: center; gap: 50px; margin-bottom: 40px; padding-bottom: 30px; border-bottom: 2px solid #f1f5f9; }
        .deped-logo { width: 80px; height: auto; }
        .header-text { text-align: center; }
        .header-text h2 { margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 2px; }
        .header-text h1 { margin: 8px 0; font-size: 26px; color: #0f172a; font-family: 'Outfit', sans-serif; font-weight: 800; }
        .header-text p { margin: 0; font-size: 13px; color: var(--deped-blue); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .form-badge { display: inline-block; padding: 6px 20px; background: #f8fafc; color: #475569; border-radius: 50px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; border: 1px solid #e2e8f0; margin-top: 15px; }
        .student-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px; margin-top: 40px; }
        .student-card { background: #fff; border: 1px solid #e2e8f0; padding: 28px; border-radius: 20px; display: flex; align-items: center; gap: 24px; text-decoration: none; color: inherit; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); position: relative; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .student-card::after { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: #0f172a; transform: scaleY(0); transition: 0.3s; }
        .student-card:hover { transform: translateY(-8px); border-color: #0f172a; box-shadow: 0 20px 40px rgba(0,0,0,0.08); }
        .student-card:hover::after { transform: scaleY(1); }
        .avatar { width: 70px; height: 70px; border-radius: 18px; background: #f1f5f9; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 800; font-family: 'Outfit', sans-serif; font-size: 28px; border: 3px solid #fff; box-shadow: 0 8px 16px rgba(0,0,0,0.05); transition: 0.3s; }
        .student-card:hover .avatar { transform: rotate(-5deg) scale(1.1); background: #0f172a; color: white; }
        .female .avatar { color: #e11d48; }
        .female:hover .avatar { background: #e11d48; color: white; }
        .info { flex: 1; }
        .info h3 { margin: 0; font-size: 17px; color: #0f172a; font-weight: 800; font-family: 'Outfit', sans-serif; text-transform: uppercase; }
        .lrn-box { margin-top: 8px; display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: #f8fafc; border-radius: 6px; border: 1px solid #f1f5f9; }
        .lrn-label { font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
        .lrn-value { font-size: 11px; font-weight: 700; color: #475569; font-family: 'Outfit', sans-serif; }
        .meta { margin-top: 15px; display: flex; gap: 12px; }
        .meta-tag { font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 50px; background: #eff6ff; color: #1d4ed8; text-transform: uppercase; }
        .female .meta-tag { background: #fff1f2; color: #e11d48; }
        .btn-open { margin-left: auto; width: 44px; height: 44px; border-radius: 12px; background: #f8fafc; color: #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 18px; transition: 0.3s; }
        .student-card:hover .btn-open { background: #0f172a; color: white; transform: translateX(5px); }
        @media print { .no-print, .sidebar, .header-panel { display: none !important; } .main-content { padding: 0 !important; } }
    </style>
</head>
<body>
    <?php include $header_file; ?>

    <div class="main-content">
        <div class="portal-card">
            <div class="official-header">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" class="deped-logo">
                <div class="header-text">
                    <h2>Republic of the Philippines</h2>
                    <h1>Learner's Permanent Record (SF10)</h1>
                    <p>OFFICIAL ARCHIVAL & MONITORING PORTAL</p>
                    <div class="form-badge">Audit & Record Access Mode</div>
                </div>
                <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="deped-logo">
            </div>

            <div style="text-align: center; margin-bottom: 30px;">
                <h3 style="margin: 0; font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 18px; color: #475569;">
                    <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?> 
                    <span style="color: #cbd5e1; margin: 0 10px;">|</span>
                    <span style="color: #94a3b8; font-weight: 500;">SY: <?= htmlspecialchars($sy) ?></span>
                </h3>
            </div>

            <?php if (empty($students)): ?>
                <div style="text-align: center; padding: 100px; color: #94a3b8;">
                    <i class="bi bi-archive" style="font-size: 64px; opacity: 0.2; display: block; margin-bottom: 20px;"></i>
                    <p style="font-weight: 600;">No permanent records available for this section.</p>
                </div>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($students as $s): 
                        $is_female = in_array(strtoupper(trim($s['sex'] ?? '')), ['F', 'FEMALE', '2', 'G', 'GIRL']);
                    ?>
                        <a href="../../../teacher/reports/sf10_print.php?student_id=<?= urlencode($s['student_id']) ?>" target="_blank" class="student-card <?= $is_female ? 'female' : '' ?>">
                            <div class="avatar"><?= strtoupper(substr($s['student_name'], 0, 1)) ?></div>
                            <div class="info">
                                <h3><?= htmlspecialchars($s['student_name']) ?></h3>
                                <div class="lrn-box">
                                    <span class="lrn-label">LRN</span>
                                    <span class="lrn-value"><?= htmlspecialchars($s['lrn'] ?: '—') ?></span>
                                </div>
                                <div class="meta">
                                    <span class="meta-tag"><?= $is_female ? 'Female' : 'Male' ?></span>
                                    <span class="meta-tag" style="background: #f1f5f9; color: #475569;">Permanent Record</span>
                                </div>
                            </div>
                            <div class="btn-open">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 60px; padding-top: 30px; border-top: 1px solid #f1f5f9; text-align: center;">
                <p style="font-size: 10px; font-weight: 700; color: #cbd5e1; letter-spacing: 2px; text-transform: uppercase;">
                    OFFICIAL SF10 ARCHIVE PORTAL | Malolos Marine Fishery School | <?= date('Y') ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
