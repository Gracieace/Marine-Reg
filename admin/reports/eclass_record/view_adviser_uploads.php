<?php
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../../../config/db.php';
auth_require_role(['registrar', 'admin']);

$pdo = db_connect();

$grade = $_GET['grade'] ?? '';
$section = $_GET['section'] ?? '';
$adviser_name = $_GET['adviser'] ?? 'Adviser';
$school_year = $_GET['sy'] ?? '';

if (!$grade || !$section) {
    // ══════════════════════════════════════════════════════
    // ── DASHBOARD VIEW: Show all teachers from Section Management ──
    // ══════════════════════════════════════════════════════
    try {
        $sy_stmt = $pdo->query("SELECT school_year FROM school_years WHERE is_current = 1 LIMIT 1");
        $active_sy = $sy_stmt ? $sy_stmt->fetchColumn() : date('Y') . '-' . (date('Y') + 1);
    } catch (PDOException $e) {
        $active_sy = date('Y') . '-' . (date('Y') + 1);
    }
    if (!$active_sy) $active_sy = date('Y') . '-' . (date('Y') + 1);

    $adviser_list = [];
    $stats = ['total_teachers' => 0, 'active_advisers' => 0, 'subject_teachers' => 0, 'total_uploads' => 0];
    $query_error = '';

    try {
        // Step 1: Get ALL teachers from users table
        $stmt = $pdo->query("SELECT id, 
            TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name)) as full_name,
            approval_status, role
            FROM users 
            WHERE LOWER(role) = 'teacher' 
            ORDER BY first_name, last_name");
        $all_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Step 2: Fetch advisory assignments from sections table
        $advisory_map = []; 
        try {
            $stmt = $pdo->query("SELECT adviser_id, grade_level, section_name, school_year 
                FROM sections WHERE adviser_id IS NOT NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $advisory_map[$row['adviser_id']] = $row;
            }
        } catch (PDOException $e) { }

        // Step 3: Fetch subject teacher assignments
        $subject_map = []; 
        try {
            $stmt = $pdo->query("SELECT st.teacher_id, 
                GROUP_CONCAT(DISTINCT CONCAT(COALESCE(sec.section_name,'?'), ': ', COALESCE(c.subject_name, s.subject_name, '?')) SEPARATOR ', ') as subjects,
                MAX(st.school_year) as sy
                FROM subject_teachers st 
                LEFT JOIN curriculum c ON st.subject_id = c.id
                LEFT JOIN subjects s ON st.subject_id = s.id
                LEFT JOIN sections sec ON st.section_id = sec.id
                GROUP BY st.teacher_id");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $subject_map[$row['teacher_id']] = ['subjects' => $row['subjects'], 'sy' => $row['sy']];
            }
        } catch (PDOException $e) { }

        // Step 4: Build the list
        foreach ($all_teachers as $t) {
            $uid = $t['id'];
            $adv = $advisory_map[$uid] ?? null;
            $subj = $subject_map[$uid] ?? null;

            $item = [
                'user_id' => $uid,
                'adviser_name' => $t['full_name'] ?: ('Teacher #' . $uid),
                'approval_status' => $t['approval_status'] ?? 'approved',
                'role' => $t['role'],
                'advisory_section' => $adv ? ($adv['grade_level'] . ' - ' . $adv['section_name']) : '',
                'advisory_grade' => $adv['grade_level'] ?? '',
                'advisory_sect' => $adv['section_name'] ?? '',
                'subjects' => $subj['subjects'] ?? '',
                'school_year' => $adv['school_year'] ?? ($subj['sy'] ?? $active_sy),
                'upload_count' => 0
            ];

            try {
                if ($item['advisory_grade'] && $item['advisory_sect']) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM eclass_records 
                                        WHERE TRIM(adviser_name) = ? 
                                        AND grade = ? 
                                        AND section = ? 
                                        AND school_year = ?");
                    $st->execute([
                        trim($item['adviser_name']), 
                        $item['advisory_grade'], 
                        $item['advisory_sect'], 
                        $item['school_year']
                    ]);
                    $item['upload_count'] = (int)$st->fetchColumn();
                }
            } catch (PDOException $e) { }

            $adviser_list[] = $item;
        }

        foreach ($adviser_list as $item) {
            $stats['total_teachers']++;
            if ($item['advisory_section']) $stats['active_advisers']++;
            if ($item['subjects']) $stats['subject_teachers']++;
            $stats['total_uploads'] += $item['upload_count'];
        }

    } catch (PDOException $e) { $query_error = $e->getMessage(); }

    $grouped = [];
    foreach ($adviser_list as $item) {
        $sy = $item['school_year'] ?: $active_sy;
        $grouped[$sy][] = $item;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Teacher Submissions Monitoring | Admin</title>
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
                background-image: radial-gradient(at 0% 0%, hsla(210,100%,98%,1) 0, transparent 50%), 
                                  radial-gradient(at 100% 0%, hsla(220,30%,95%,1) 0, transparent 50%);
                background-attachment: fixed;
                margin: 0;
            }

            .main-content {
                padding: calc(var(--header-height) + 32px) 24px 64px;
                max-width: 1400px;
                margin-left: var(--sidebar-width, 260px);
                transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            }

            @media (max-width: 992px) { .main-content { margin-left: 0 !important; } }

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

            .page-header h1 { font-size: 36px; font-weight: 900; margin: 0; letter-spacing: -1.5px; }
            .page-header p { margin: 12px 0 0; opacity: 0.8; font-weight: 500; }

            .stats-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 20px;
                margin-bottom: 32px;
            }

            .stat-card {
                background: var(--glass-bg);
                backdrop-filter: blur(12px);
                border-radius: 24px;
                border: 1px solid var(--glass-border);
                padding: 28px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.02);
                transition: all 0.3s ease;
            }

            .stat-card:hover { transform: translateY(-5px); }
            .stat-label { font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; }
            .stat-value { font-size: 32px; font-weight: 900; color: #1e293b; margin-top: 8px; }

            .action-bar {
                background: var(--glass-bg);
                backdrop-filter: blur(12px);
                border-radius: 24px;
                border: 1px solid var(--glass-border);
                padding: 24px;
                margin-bottom: 32px;
                display: flex;
                gap: 20px;
                align-items: center;
                flex-wrap: wrap;
            }

            .search-box { flex: 1; min-width: 300px; position: relative; }
            .search-box input {
                width: 100%;
                padding: 14px 20px 14px 48px;
                border: 2px solid #f1f5f9;
                border-radius: 16px;
                font-size: 14px;
                font-weight: 600;
                background: #f8fafc;
                transition: all 0.3s ease;
                box-sizing: border-box;
            }
            .search-box input:focus { outline: none; border-color: #2563eb; background: white; box-shadow: 0 0 0 4px rgba(37,99,235,0.05); }
            .search-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #94a3b8; }

            .sy-group { margin-bottom: 48px; }
            .sy-title { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
            .sy-title h2 { font-size: 18px; font-weight: 900; color: #1e293b; margin: 0; }
            .sy-line { height: 2px; background: #e2e8f0; flex: 1; border-radius: 2px; }

            .teacher-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
                gap: 24px;
            }

            .teacher-card {
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

            .teacher-card:hover {
                transform: translateY(-6px) scale(1.01);
                border-color: #2563eb;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.08);
            }

            .avatar-box {
                width: 72px;
                height: 72px;
                background: #f1f5f9;
                border-radius: 22px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 32px;
                flex-shrink: 0;
                transition: all 0.3s ease;
            }

            .teacher-card:hover .avatar-box { background: #2563eb; color: white; transform: rotate(-5deg) scale(1.1); }

            .card-body { flex: 1; min-width: 0; }
            .card-body h3 { margin: 0; font-size: 18px; font-weight: 800; color: #1e293b; letter-spacing: -0.5px; }
            
            .badges { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
            .badge {
                padding: 4px 10px;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 900;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .badge-adviser { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
            .badge-subject { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
            .badge-unassigned { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }

            .card-meta { font-size: 12px; color: #64748b; font-weight: 600; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 8px; }

            .submission-status {
                margin-top: 14px;
                padding-top: 14px;
                border-top: 1px solid #f1f5f9;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .status-pill {
                padding: 6px 12px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 800;
            }
            .status-active { background: #dcfce7; color: #166534; }
            .status-pending { background: #f1f5f9; color: #64748b; }
        </style>
    </head>
    <body>
        <?php include __DIR__ . '/../../admin_header.php'; ?>
        <?php include __DIR__ . '/../../admin_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <h1>📋 Submission Monitoring</h1>
                <p>Real-time oversight of teacher document uploads and section compliance.</p>
            </div>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Faculty</div>
                    <div class="stat-value"><?= $stats['total_teachers'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Class Advisers</div>
                    <div class="stat-value"><?= $stats['active_advisers'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Subject Teachers</div>
                    <div class="stat-value"><?= $stats['subject_teachers'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Submissions</div>
                    <div class="stat-value"><?= $stats['total_uploads'] ?></div>
                </div>
            </div>

            <div class="action-bar">
                <div class="search-box">
                    <span class="search-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                    <input type="text" id="teacherSearch" placeholder="Search by name, section, or subject load...">
                </div>
            </div>

            <?php foreach ($grouped as $sy => $items): ?>
                <div class="sy-group">
                    <div class="sy-title">
                        <h2>School Year <?= htmlspecialchars($sy) ?></h2>
                        <div class="sy-line"></div>
                    </div>
                    <div class="teacher-grid">
                        <?php foreach ($items as $item): 
                            $is_assigned = !empty($item['advisory_section']) || !empty($item['subjects']);
                            $clickable = !empty($item['advisory_grade']);
                            $card_link = $clickable 
                                ? "?grade=" . urlencode($item['advisory_grade']) . "&section=" . urlencode($item['advisory_sect']) . "&sy=" . urlencode($item['school_year']) . "&adviser=" . urlencode($item['adviser_name'])
                                : "javascript:void(0)";
                        ?>
                            <a href="<?= $card_link ?>" class="teacher-card clickable" style="opacity: <?= $is_assigned ? '1' : '0.7' ?>; cursor: <?= $clickable ? 'pointer' : 'default' ?>">
                                <div class="avatar-box">
                                    <?= $is_assigned ? '👨‍🏫' : '👤' ?>
                                </div>
                                <div class="card-body">
                                    <h3><?= htmlspecialchars($item['adviser_name']) ?></h3>
                                    <div class="badges">
                                        <?php if ($item['advisory_section']): ?>
                                            <span class="badge badge-adviser">Adviser</span>
                                        <?php endif; ?>
                                        <?php if ($item['subjects']): ?>
                                            <span class="badge badge-subject">Subject Tr.</span>
                                        <?php endif; ?>
                                        <?php if (!$item['advisory_section'] && !$item['subjects']): ?>
                                            <span class="badge badge-unassigned">No Load</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-meta">
                                        <?= $item['advisory_section'] ? "Advisory: " . htmlspecialchars($item['advisory_section']) : "No Advisory assigned" ?>
                                    </div>
                                    <div class="submission-status">
                                        <?php if ($item['upload_count'] > 0): ?>
                                            <span class="status-pill status-active">Stored (<?= $item['upload_count'] ?>)</span>
                                        <?php else: ?>
                                            <span class="status-pill status-pending">Pending Submission</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
            document.getElementById('teacherSearch').addEventListener('input', function(e) {
                const query = e.target.value.toLowerCase();
                document.querySelectorAll('.teacher-card').forEach(card => {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(query) ? 'flex' : 'none';
                });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ══════════════════════════════════════════════════════
// ── DETAIL VIEW: Show specific teacher's submissions ──
// ══════════════════════════════════════════════════════

function getSubmissionStatus($pdo, $table, $params) {
    if (!$table) return false;
    try {
        $where = [];
        foreach ($params as $key => $val) { $where[] = "$key = ?"; }
        $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(" AND ", $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) { return false; }
}

$assigned_adviser_id = null;
try {
    $stmt = $pdo->prepare("SELECT adviser_id FROM sections WHERE grade_level = ? AND section_name = ? AND school_year = ? LIMIT 1");
    $stmt->execute([$grade, $section, $school_year]);
    $assigned_adviser_id = $stmt->fetchColumn();
} catch (PDOException $e) {}

$subject_loads = [];
if ($assigned_adviser_id) {
    try {
        $stmt = $pdo->prepare("SELECT st.*, c.subject_name, s.grade_level as s_grade, s.section_name as s_section 
                              FROM subject_teachers st 
                              JOIN curriculum c ON st.subject_id = c.id 
                              JOIN sections s ON st.section_id = s.id
                              WHERE st.teacher_id = ? AND st.school_year = ?");
        $stmt->execute([$assigned_adviser_id, $school_year]);
        $subject_loads = $stmt->fetchAll();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submission Details | <?= htmlspecialchars($section) ?></title>
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
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            background-image: radial-gradient(at 0% 0%, hsla(210,100%,98%,1) 0, transparent 50%), 
                              radial-gradient(at 100% 0%, hsla(220,30%,95%,1) 0, transparent 50%);
            background-attachment: fixed;
            margin: 0;
        }

        .main-content {
            padding: calc(var(--header-height) + 32px) 24px 64px;
            max-width: 1200px;
            margin-left: var(--sidebar-width, 260px);
        }

        @media (max-width: 992px) { .main-content { margin-left: 0 !important; } }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--glass-bg);
            backdrop-filter: blur(8px);
            padding: 12px 20px;
            border-radius: 16px;
            color: #64748b;
            text-decoration: none;
            font-weight: 800;
            font-size: 13px;
            border: 1px solid var(--glass-border);
            margin-bottom: 32px;
            transition: all 0.3s ease;
        }
        .btn-back:hover { color: #1e293b; transform: translateX(-5px); border-color: #94a3b8; }

        .detail-hero {
            background: var(--primary-gradient);
            border-radius: 32px;
            padding: 48px;
            color: white;
            margin-bottom: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .hero-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .hero-text h1 { margin: 0; font-size: 32px; font-weight: 900; letter-spacing: -1px; }
        .hero-meta { display: flex; gap: 12px; margin-top: 14px; }
        .meta-tag { background: rgba(255,255,255,0.1); padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }

        .section-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; margin-top: 48px; }
        .section-header h2 { font-size: 14px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.15em; margin: 0; }
        .section-line { height: 1px; background: #e2e8f0; flex: 1; }

        .submission-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; }

        .submission-item {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .submission-item:hover:not(.disabled) { transform: translateY(-4px); border-color: #2563eb; box-shadow: 0 15px 30px rgba(0,0,0,0.05); }
        .submission-item.disabled { opacity: 0.6; cursor: not-allowed; }

        .item-icon {
            width: 52px;
            height: 52px;
            background: #f1f5f9;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .item-info { flex: 1; }
        .item-info h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1e293b; }
        .item-info p { margin: 4px 0 0; font-size: 12px; color: #64748b; font-weight: 600; }

        .status-pill {
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }
        .status-active { background: #dcfce7; color: #15803d; }
        .status-pending { background: #f1f5f9; color: #94a3b8; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../admin_header.php'; ?>
    <?php include __DIR__ . '/../../admin_sidebar.php'; ?>

    <div class="main-content">
        <a href="view_adviser_uploads.php" class="btn-back">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            BACK TO MONITORING
        </a>

        <div class="detail-hero">
            <div class="hero-avatar">👨‍🏫</div>
            <div class="hero-text">
                <h1><?= htmlspecialchars($adviser_name) ?></h1>
                <div class="hero-meta">
                    <div class="meta-tag">📍 <?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($section) ?></div>
                    <div class="meta-tag">📅 SY <?= htmlspecialchars($school_year) ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($subject_loads)): ?>
            <div class="section-header">
                <h2>Subject Load Reports (SF3)</h2>
                <div class="section-line"></div>
            </div>
            <div class="submission-list">
                <?php foreach($subject_loads as $sl): 
                    $sub = getSubmissionStatus($pdo, 'sf3_reports', [
                        'grade_level' => $sl['s_grade'],
                        'section' => $sl['s_section'],
                        'school_year' => $school_year,
                        'teacher_id' => $assigned_adviser_id
                    ]);
                ?>
                    <div class="submission-item <?= !$sub ? 'disabled' : '' ?>">
                        <div class="item-icon">📚</div>
                        <div class="item-info">
                            <h3>SF 3: Books - <?= htmlspecialchars($sl['subject_name']) ?></h3>
                            <p><?= htmlspecialchars($sl['s_grade']) ?> - <?= htmlspecialchars($sl['s_section']) ?></p>
                        </div>
                        <span class="status-pill <?= $sub ? 'status-active' : 'status-pending' ?>"><?= $sub ? 'Stored' : 'Pending' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2>Core School Forms (Tier 2)</h2>
            <div class="section-line"></div>
        </div>
        <div class="submission-list">
            <?php
            $t2 = [
                ['id'=>'SF1','name'=>'SF 1: Register','table'=>'sf1_reports','link'=>'../school_forms/view_teacher_sf1.php','icon'=>'📁'],
                ['id'=>'SF2','name'=>'SF 2: Attendance','table'=>'sf2_reports','link'=>'../school_forms/view_teacher_sf2.php','icon'=>'⏰'],
                ['id'=>'SF3','name'=>'SF 3: Books','table'=>'sf3_reports','link'=>'../school_forms/view_teacher_sf3.php','icon'=>'📚'],
                ['id'=>'SF5','name'=>'SF 5: Promotion','table'=>'sf5_reports','link'=>'../school_forms/view_teacher_sf5.php','icon'=>'🎓']
            ];
            foreach($t2 as $i):
                $sub_params = ['grade_level'=>$grade, 'section'=>$section, 'school_year'=>$school_year];
                if ($i['id'] == 'SF3' && $assigned_adviser_id) $sub_params['teacher_id'] = $assigned_adviser_id;
                $sub = getSubmissionStatus($pdo, $i['table'], $sub_params);
            ?>
                <?php if($sub): ?>
                    <a href="<?= $i['link'] ?>?grade=<?=urlencode($grade)?>&section=<?=urlencode($section)?>&sy=<?=urlencode($school_year)?>" class="submission-item clickable" target="_blank">
                        <div class="item-icon"><?= $i['icon']?></div>
                        <div class="item-info"><h3><?= $i['name']?></h3><p>Official DepEd Form</p></div>
                        <span class="status-pill status-active">Stored</span>
                    </a>
                <?php else: ?>
                    <div class="submission-item disabled">
                        <div class="item-icon"><?= $i['icon']?></div>
                        <div class="item-info"><h3><?= $i['name']?></h3><p>Not yet submitted</p></div>
                        <span class="status-pill status-pending">Pending</span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="section-header">
            <h2>Learner Progress (Tier 3)</h2>
            <div class="section-line"></div>
        </div>
        <div class="submission-list">
            <?php
            $t3 = [
                ['id'=>'SF9','name'=>'SF 9: Progress','table'=>'sf9_grades','link'=>'../school_forms/view_teacher_sf9.php','icon'=>'📋'],
                ['id'=>'SF10','name'=>'SF 10: Permanent','table'=>'enrollments','link'=>'../school_forms/view_teacher_sf10.php','icon'=>'🗄️']
            ];
            foreach($t3 as $i):
                $sub = false;
                try {
                    if($i['id']=='SF9') {
                        $st = $pdo->prepare("SELECT COUNT(*) FROM sf9_grades h JOIN enrollments e ON h.student_id = e.student_id WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?");
                        $st->execute([$grade, $section, $school_year]); $sub = $st->fetchColumn() > 0;
                    } else { $sub = true; }
                } catch (Exception $e) { $sub = false; }
            ?>
                <?php if($sub): ?>
                    <a href="<?= $i['link'] ?>?grade=<?=urlencode($grade)?>&section=<?=urlencode($section)?>&sy=<?=urlencode($school_year)?>" class="submission-item clickable" target="_blank">
                        <div class="item-icon"><?= $i['icon']?></div>
                        <div class="item-info"><h3><?= $i['name']?></h3><p>Academic Records</p></div>
                        <span class="status-pill status-active">Stored</span>
                    </a>
                <?php else: ?>
                    <div class="submission-item disabled">
                        <div class="item-icon"><?= $i['icon']?></div>
                        <div class="item-info"><h3><?= $i['name']?></h3><p>Not yet available</p></div>
                        <span class="status-pill status-pending">Pending</span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>