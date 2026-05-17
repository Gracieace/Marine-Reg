<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/sf6_logic.php';

auth_require_role(['admin', 'registrar']);

$pdo = db_connect();

$school_year = $_GET['school_year'] ?? $_GET['sy'] ?? get_active_school_year($pdo);
$grade_level = $_GET['grade_level'] ?? '';
$section_id = $_GET['section_id'] ?? '';

// Global Proficiency Mapping (Aligned with SF5/SF9 Standards)
$PROF_LABELS = [
    'Advanced' => ['label' => 'A: ADVANCED', 'range' => '90 & Above', 'color' => '#10b981', 'bg' => '#f0fdf4'],
    'Proficient' => ['label' => 'P: PROFICIENT', 'range' => '85 - 89', 'color' => '#3b82f6', 'bg' => '#eff6ff'],
    'Approaching' => ['label' => 'AP: APPROACHING PROFICIENCY', 'range' => '80 - 84', 'color' => '#8b5cf6', 'bg' => '#f5f3ff'],
    'Developing' => ['label' => 'D: DEVELOPING', 'range' => '75 - 79', 'color' => '#f59e0b', 'bg' => '#fffbeb'],
    'Beginning' => ['label' => 'B: BEGINNING', 'range' => '74 & Below', 'color' => '#ef4444', 'bg' => '#fef2f2']
];

try {
    $response = generateSF6($pdo, $school_year, $grade_level, $section_id);
    $sections_summary = $response['sections'] ?? [];
    $school_summary = $response['school_summary'] ?? null;

} catch (Throwable $e) {
    die("<div style='padding:40px; background:#fff1f2; border:1px solid #fda4af; color:#9f1239; border-radius:16px; margin:40px; font-family:Inter, sans-serif; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);'>
        <h2 style='margin-top:0; display:flex; align-items:center; gap:12px;'>⚠️ SF6 Generation Error</h2>
        <p style='font-size:1.1rem; line-height:1.6;'>An error occurred while generating the report. This is usually caused by fragmented student data.</p>
        <div style='background:white; padding:16px; border-radius:8px; border:1px solid #fecdd3; font-family:monospace; margin-top:20px;'>
            " . htmlspecialchars($e->getMessage()) . "
        </div>
    </div>");
}

$settings = [
    'school_id' => '300750', 'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
    'region' => 'REGION III', 'division' => 'MALOLOS CITY', 'district' => 'DISTRICT X'
];
$stmt_set = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('region', 'division', 'district', 'school_name', 'school_id')");
if ($stmt_set) {
    while ($s = $stmt_set->fetch()) {
        if (!empty($s['setting_value'])) $settings[$s['setting_key']] = $s['setting_value'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF6 | Institutional Promotion Report</title>
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap');
        
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --accent-gradient: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.5);
            --shadow-premium: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background: #f1f5f9; 
            color: #1e293b; 
            margin: 0; 
            min-height: 100vh;
            background-image: radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.05) 0px, transparent 50%),
                              radial-gradient(at 100% 100%, rgba(37, 99, 235, 0.05) 0px, transparent 50%);
        }

        .main-wrapper { display: flex; min-height: 100vh; padding-top: var(--header-height); }
        .page-content { flex: 1; padding: 40px; margin-left: var(--sidebar-width); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); width: calc(100% - var(--sidebar-width)); }
        
        .hero-banner {
            background: var(--primary-gradient);
            padding: 48px;
            border-radius: 32px;
            color: white;
            margin-bottom: 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-premium);
            position: relative;
            overflow: hidden;
        }

        .hero-banner::after {
            content: '';
            position: absolute;
            top: -50%; right: -10%; width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.2) 0%, transparent 70%);
            z-index: 1;
        }

        .hero-content { position: relative; z-index: 2; }
        .hero-title { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 36px; font-weight: 800; margin: 0; letter-spacing: -0.02em; }
        .hero-subtitle { font-size: 16px; color: #94a3b8; margin-top: 8px; font-weight: 500; }

        .btn-print-hero {
            position: relative; z-index: 2;
            background: var(--accent-gradient);
            color: white;
            padding: 16px 32px;
            border-radius: 16px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.4);
        }

        .btn-print-hero:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(37, 99, 235, 0.5); }

        .filter-glass {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            padding: 32px;
            border-radius: 24px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-md);
        }

        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; align-items: flex-end; }
        .form-group label { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; display: block; }
        .form-control { width: 100%; padding: 14px 18px; border-radius: 14px; border: 1px solid #e2e8f0; font-size: 14px; background: white; font-weight: 600; color: #1e293b; transition: 0.2s; box-sizing: border-box; }
        .form-control:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }

        .btn-generate { background: #0f172a; color: white; border: none; height: 48px; border-radius: 14px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-generate:hover { background: #1e293b; transform: scale(1.02); }

        .stat-card-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: white; padding: 32px; border-radius: 24px; border: 1px solid #e2e8f0; text-align: center; transition: 0.3s; box-shadow: var(--shadow-sm); }
        .stat-card:hover { transform: translateY(-8px); box-shadow: var(--shadow-premium); border-color: #3b82f6; }
        .stat-val { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 32px; font-weight: 800; color: #0f172a; line-height: 1; }
        .stat-lbl { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-top: 12px; letter-spacing: 0.05em; }

        .section-card { 
            background: white; 
            border-radius: 32px; 
            border: 1px solid #e2e8f0; 
            margin-bottom: 48px; 
            overflow: hidden; 
            box-shadow: var(--shadow-md); 
            transition: all 0.4s;
        }

        .section-card-header {
            padding: 32px 40px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-info h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 24px; font-weight: 800; margin: 0; color: #0f172a; }
        .section-info p { color: #64748b; margin-top: 4px; font-weight: 500; font-size: 14px; }

        .table-premium { width: 100%; border-collapse: separate; border-spacing: 0; }
        .table-premium th { background: #f1f5f9; padding: 18px 24px; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        .table-premium td { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; font-weight: 500; font-size: 14px; text-align: center; }
        .table-premium tr:last-child td { border-bottom: none; }
        .table-premium .text-left { text-align: left; font-weight: 600; color: #1e293b; }
        .table-premium .val-bold { font-weight: 800; color: #3b82f6; }
        
        .grand-summary-section {
            background: var(--primary-gradient);
            border-radius: 40px;
            padding: 64px;
            color: white;
            margin-bottom: 80px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
        }

        .grand-summary-section h2 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 32px; font-weight: 800; margin-bottom: 40px; text-align: center; }

        .matrix-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; }
        .matrix-card { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 32px; }
        .matrix-card h3 { font-size: 16px; font-weight: 700; margin-top: 0; margin-bottom: 24px; color: #94a3b8; text-transform: uppercase; border-left: 4px solid #3b82f6; padding-left: 12px; }

        .matrix-table { width: 100%; border-collapse: collapse; }
        .matrix-table th { text-align: center; padding: 12px; font-size: 11px; color: #64748b; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .matrix-table td { padding: 16px 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); text-align: center; font-weight: 600; }
        .matrix-table .text-left { text-align: left; font-weight: 500; color: white; }
        .matrix-table tr:hover { background: rgba(255, 255, 255, 0.03); }

        .badge-source {
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-block;
            margin-bottom: 12px;
        }

        .summary-card { background: white; border-radius: 32px; border: 1px solid #e2e8f0; margin-bottom: 48px; box-shadow: var(--shadow-md); overflow: hidden; }
        .summary-header { background: #f8fafc; padding: 24px 40px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px; font-weight: 800; font-family: 'Plus Jakarta Sans', sans-serif; color: #0f172a; font-size: 18px; }
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .summary-table th { background: #f1f5f9; padding: 15px 12px; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; text-align: center; }
        .summary-table td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 500; text-align: center; }
        .summary-table .text-left { text-align: left; padding-left: 40px; }
        .summary-table tfoot td { border-top: 2px solid #0f172a; background: #f8fafc; font-weight: 800; }
        
        .badge-prof { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 100px; font-size: 11px; font-weight: 700; }
        
        @media (max-width: 1024px) { 
            .page-content { margin-left: 0; width: 100%; padding: 24px; } 
            .matrix-grid { grid-template-columns: 1fr; }
            .stat-card-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media print { .no-print { display: none !important; } .main-wrapper { padding-top: 0; } .page-content { margin: 0; width: 100%; } }
    </style>
</head>
<body>
    <?php include '../../admin_header.php'; ?>
    <div class="main-wrapper">
        <?php include '../../admin_sidebar.php'; ?>
        <div class="page-content">
            
            <div class="hero-banner no-print">
                <div class="hero-content">
                    <div class="badge-source">Institutional Source: Registered Profiles</div>
                    <h1 class="hero-title">School Form 6 (SF6)</h1>
                    <p class="hero-subtitle">Summarized Report on Promotion and Level of Proficiency | S.Y. <?= $school_year ?></p>
                </div>
                <a href="sf6_print.php?school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&section_id=<?= urlencode($section_id) ?>" target="_blank" class="btn-print-hero">
                    <span>🖨️</span> Print Institutional SF6
                </a>
            </div>

            <form class="filter-glass no-print" method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="school_year" class="form-control">
                            <?php 
                            $sys = $pdo->query("SELECT DISTINCT school_year FROM sections ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                            foreach($sys as $sy) echo "<option value='$sy' ".($school_year==$sy?'selected':'').">$sy</option>";
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Grade Level</label>
                        <select name="grade_level" class="form-control">
                            <option value="">All Grade Levels</option>
                            <?php 
                            $gls = $pdo->query("SELECT DISTINCT grade_level FROM sections ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
                            foreach($gls as $gl) echo "<option value='$gl' ".($grade_level==$gl?'selected':'').">Grade $gl</option>";
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specific Section</label>
                        <select name="section_id" class="form-control">
                            <option value="">All Class Sections</option>
                            <?php 
                            $secs = $pdo->query("SELECT id, section_name, grade_level FROM sections ORDER BY grade_level, section_name")->fetchAll(PDO::FETCH_ASSOC);
                            foreach($secs as $s) echo "<option value='{$s['id']}' ".($section_id==$s['id']?'selected':'').">{$s['grade_level']} - {$s['section_name']}</option>";
                            ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-generate">📊 Update Dashboard</button>
                </div>
            </form>

            <?php if (empty($sections_summary)): ?>
                <div class="section-card" style="text-align: center; padding: 120px;">
                    <div style="font-size: 80px; margin-bottom: 32px; filter: grayscale(1);">🔍</div>
                    <h2 style="color: #64748b;">No Reports Detected</h2>
                    <p style="color: #94a3b8; max-width: 400px; margin: 16px auto;">Ensure all advisers have submitted their SF5 Class Promotion reports for the selected academic year.</p>
                </div>
            <?php else: ?>
                
                <div class="stat-card-row no-print">
                    <div class="stat-card">
                        <div class="stat-val"><?= $school_summary['counts']['T']['enrolled'] ?></div>
                        <div class="stat-lbl">Total Enrolled</div>
                    </div>
                    <div class="stat-card" style="border-bottom: 4px solid #10b981;">
                        <div class="stat-val" style="color: #10b981;"><?= $school_summary['counts']['T']['promoted'] ?></div>
                        <div class="stat-lbl">Total Promoted</div>
                    </div>
                    <div class="stat-card" style="border-bottom: 4px solid #f59e0b;">
                        <div class="stat-val" style="color: #f59e0b;"><?= $school_summary['counts']['T']['conditional'] ?></div>
                        <div class="stat-lbl">Conditional</div>
                    </div>
                    <div class="stat-card" style="border-bottom: 4px solid #ef4444;">
                        <div class="stat-val" style="color: #ef4444;"><?= $school_summary['counts']['T']['retained'] ?></div>
                        <div class="stat-lbl">Retained</div>
                    </div>
                </div>

                <!-- CONSOLIDATED GRADE LEVEL GRID -->
                <div class="section-card">
                    <div class="section-card-header">
                        <div class="section-info">
                            <h2>Consolidated Grade-Level Summary</h2>
                            <p>Aggregated performance metrics across all sections</p>
                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table-premium">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="text-left">Grade Level</th>
                                    <th colspan="3" style="background: #ecfdf5; color: #065f46; border-bottom: 2px solid #10b981;">PROMOTED</th>
                                    <th colspan="3" style="background: #fffbeb; color: #92400e; border-bottom: 2px solid #f59e0b;">CONDITIONAL</th>
                                    <th colspan="3" style="background: #fef2f2; color: #991b1b; border-bottom: 2px solid #ef4444;">RETAINED</th>
                                    <th colspan="3">TOTAL ENROLLED</th>
                                </tr>
                                <tr>
                                    <th style="background: #f0fdf4;">M</th><th style="background: #f0fdf4;">F</th><th style="background: #f0fdf4;">T</th>
                                    <th style="background: #fffcf0;">M</th><th style="background: #fffcf0;">F</th><th style="background: #fffcf0;">T</th>
                                    <th style="background: #fff5f5;">M</th><th style="background: #fff5f5;">F</th><th style="background: #fff5f5;">T</th>
                                    <th>M</th><th>F</th><th>T</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $grade_groups = [];
                                foreach ($sections_summary as $s) {
                                    $gl = $s['section_info']['grade_level'];
                                    if (!isset($grade_groups[$gl])) {
                                        $grade_groups[$gl] = [
                                            'M' => ['promoted'=>0, 'conditional'=>0, 'retained'=>0, 'enrolled'=>0],
                                            'F' => ['promoted'=>0, 'conditional'=>0, 'retained'=>0, 'enrolled'=>0],
                                            'T' => ['promoted'=>0, 'conditional'=>0, 'retained'=>0, 'enrolled'=>0]
                                        ];
                                    }
                                    foreach (['M', 'F', 'T'] as $g) {
                                        foreach (['promoted', 'conditional', 'retained', 'enrolled'] as $k) {
                                            $grade_groups[$gl][$g][$k] += $s['counts'][$g][$k];
                                        }
                                    }
                                }
                                ksort($grade_groups);
                                foreach ($grade_groups as $gl => $counts): ?>
                                    <tr>
                                        <td class="text-left">Grade <?= $gl ?></td>
                                        <td style="background: #f0fdf4;"><?= $counts['M']['promoted'] ?></td><td style="background: #f0fdf4;"><?= $counts['F']['promoted'] ?></td><td style="background: #f0fdf4; font-weight: 800; color: #10b981;"><?= $counts['T']['promoted'] ?></td>
                                        <td style="background: #fffcf0;"><?= $counts['M']['conditional'] ?></td><td style="background: #fffcf0;"><?= $counts['F']['conditional'] ?></td><td style="background: #fffcf0; font-weight: 800; color: #f59e0b;"><?= $counts['T']['conditional'] ?></td>
                                        <td style="background: #fff5f5;"><?= $counts['M']['retained'] ?></td><td style="background: #fff5f5;"><?= $counts['F']['retained'] ?></td><td style="background: #fff5f5; font-weight: 800; color: #ef4444;"><?= $counts['T']['retained'] ?></td>
                                        <td><?= $counts['M']['enrolled'] ?></td><td><?= $counts['F']['enrolled'] ?></td><td class="val-bold"><?= $counts['T']['enrolled'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="margin: 64px 0 32px;">
                    <h2 style="margin: 0; font-size: 14px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.2em; display: flex; align-items: center; gap: 16px;">
                        Section Breakdowns <span style="flex:1; height: 2px; background: #e2e8f0;"></span>
                    </h2>
                </div>

                <?php 
                // Always fetch ALL grade levels for the Institutional Matrix, regardless of filter
                $prof_data = getSF6ProficiencyMatrix($pdo, $school_year, ''); 
                $matrix = $prof_data['matrix'];
                $levels = $prof_data['levels'];
                $grand_total = $prof_data['grand_total'];
                $grade_list = array_keys($matrix);
                ?>

                <!-- Institutional Proficiency Matrix (DepED SF6 Format) -->
                <div class="summary-card" style="margin-top: 30px; overflow-x: auto;">
                    <div class="summary-header">
                        <span>📊</span>
                        Institutional Proficiency Matrix (All Grade Levels)
                    </div>
                    <table class="summary-table" style="min-width: 1200px;">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width: 200px; vertical-align: middle; background: #f1f5f9;">Proficiency Level</th>
                                <?php foreach ($grade_list as $gl): ?>
                                    <th colspan="3" style="background: #eff6ff; color: #1e40af; border-bottom: 2px solid #3b82f6;"><?= htmlspecialchars($gl) ?></th>
                                <?php endforeach; ?>
                                <th colspan="3" style="background: #0f172a; color: white;">GRAND TOTAL</th>
                            </tr>
                            <tr>
                                <?php foreach ($grade_list as $gl): ?>
                                    <th style="font-size: 10px; background: #f8fafc;">M</th>
                                    <th style="font-size: 10px; background: #f8fafc;">F</th>
                                    <th style="font-size: 10px; background: #f8fafc; font-weight: 800;">T</th>
                                <?php endforeach; ?>
                                <th style="background: #1e293b; color: white; font-size: 11px;">M</th>
                                <th style="background: #1e293b; color: white; font-size: 11px;">F</th>
                                <th style="background: #1e293b; color: #60a5fa; font-size: 11px; font-weight: 800;">T</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($PROF_LABELS as $key => $info): ?>
                                <tr style="background: <?= $info['bg'] ?>05;">
                                    <td class="text-left" style="font-weight: 700; background: <?= $info['bg'] ?>;">
                                        <div style="color: <?= $info['color'] ?>; display: flex; align-items: center; gap: 8px;">
                                            <span style="width: 8px; height: 8px; border-radius: 50%; background: <?= $info['color'] ?>;"></span>
                                            <?= $info['label'] ?>
                                        </div>
                                        <div style="font-size: 10px; font-weight: 400; color: #64748b; margin-top: 2px; margin-left: 16px;">(<?= $info['range'] ?>)</div>
                                    </td>
                                    <?php foreach ($grade_list as $gl): 
                                        $m = $matrix[$gl][$key]['M'];
                                        $f = $matrix[$gl][$key]['F'];
                                        $t = $matrix[$gl][$key]['T'];
                                    ?>
                                        <td><?= $m ?: '0' ?></td>
                                        <td><?= $f ?: '0' ?></td>
                                        <td style="font-weight: 700; background: #f1f5f9;"><?= $t ?: '0' ?></td>
                                    <?php endforeach; ?>
                                    
                                    <!-- Grand Totals per Level -->
                                    <td style="background: #f8fafc; font-weight: 600;"><?= $grand_total[$key]['M'] ?></td>
                                    <td style="background: #f8fafc; font-weight: 600;"><?= $grand_total[$key]['F'] ?></td>
                                    <td style="background: #0f172a; color: #60a5fa; font-weight: 800;"><?= $grand_total[$key]['T'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="border-top: 3px solid #0f172a;">
                            <tr style="background: #f1f5f9; font-weight: 800;">
                                <td class="text-left">TOTAL PER GRADE</td>
                                <?php 
                                $inst_m = 0; $inst_f = 0; $inst_t = 0;
                                foreach ($grade_list as $gl): 
                                    $gm = 0; $gf = 0; $gt = 0;
                                    foreach ($levels as $key => $i) {
                                        $gm += $matrix[$gl][$key]['M'];
                                        $gf += $matrix[$gl][$key]['F'];
                                        $gt += $matrix[$gl][$key]['T'];
                                    }
                                    $inst_m += $gm; $inst_f += $gf; $inst_t += $gt;
                                ?>
                                    <td><?= $gm ?></td>
                                    <td><?= $gf ?></td>
                                    <td style="background: #3b82f6; color: white;"><?= $gt ?></td>
                                <?php endforeach; ?>
                                <td style="background: #0f172a; color: white;"><?= $inst_m ?></td>
                                <td style="background: #0f172a; color: white;"><?= $inst_f ?></td>
                                <td style="background: #3b82f6; color: white; font-size: 1.1rem;"><?= $inst_t ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (empty($matrix)): ?>
                    <div class="card" style="padding: 50px; text-align: center; color: #64748b; margin-top: 20px;">
                        <i class="fas fa-exclamation-circle" style="font-size: 2rem; margin-bottom: 15px;"></i>
                        <p>No proficiency data available for the selected criteria.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($sections_summary as $section): ?>
                    <div class="section-card">
                        <div class="section-card-header">
                            <div class="section-info">
                                <div class="badge-source">Class Identifier: <?= $section['section_info']['id'] ?></div>
                                <h2>Grade <?= $section['section_info']['grade_level'] ?> - <?= $section['section_info']['section_name'] ?></h2>
                                <p>Adviser: <strong><?= $section['adviser'] ?></strong></p>
                            </div>
                        </div>

                        <div style="padding: 32px 40px; display: grid; grid-template-columns: 1fr 1.5fr; gap: 40px;">
                            <div>
                                <h3 style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 16px; letter-spacing: 0.1em;">Gender Allocation</h3>
                                <div style="border: 1px solid #f1f5f9; border-radius: 16px; overflow: hidden;">
                                    <table class="table-premium">
                                        <thead>
                                            <tr>
                                                <th class="text-left" style="padding: 12px 15px;">Category</th>
                                                <th style="padding: 12px 15px;">M</th>
                                                <th style="padding: 12px 15px;">F</th>
                                                <th style="padding: 12px 15px;">T</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $rows = ['promoted'=>'Promoted', 'conditional'=>'Conditional', 'retained'=>'Retained', 'enrolled'=>'Total Enrolled'];
                                            foreach ($rows as $key => $label): ?>
                                                <tr style="<?= $key === 'enrolled' ? 'background: #f8fafc; font-weight: 800;' : '' ?>">
                                                    <td class="text-left" style="padding: 12px 15px;"><?= $label ?></td>
                                                    <td style="padding: 12px 15px;"><?= $section['counts']['M'][$key] ?></td>
                                                    <td style="padding: 12px 15px;"><?= $section['counts']['F'][$key] ?></td>
                                                    <td class="val-bold" style="padding: 12px 15px;"><?= $section['counts']['T'][$key] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div>
                                <h3 style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 16px; letter-spacing: 0.1em; display: flex; align-items: center; gap: 8px;">
                                    <span>📈</span> Proficiency Matrix
                                </h3>
                                <div style="border: 1px solid #f1f5f9; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                                    <table class="table-premium">
                                        <thead>
                                            <tr>
                                                <th class="text-left" style="padding: 12px 15px; background: #f8fafc;">Level of Proficiency</th>
                                                <th style="padding: 12px 15px; background: #f8fafc;">Range</th>
                                                <th style="padding: 12px 15px; background: #f8fafc;">M</th>
                                                <th style="padding: 12px 15px; background: #f8fafc;">F</th>
                                                <th style="padding: 12px 15px; background: #f8fafc;">T</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($PROF_LABELS as $key => $info): ?>
                                                <tr style="background: <?= $info['bg'] ?>40;">
                                                    <td class="text-left" style="font-weight: 700; color: <?= $info['color'] ?>; padding: 12px 15px; display: flex; align-items: center; gap: 8px;">
                                                        <span style="width: 8px; height: 8px; border-radius: 50%; background: <?= $info['color'] ?>;"></span>
                                                        <?= $info['label'] ?>
                                                    </td>
                                                    <td style="padding: 12px 15px; color: #64748b; font-weight: 600; font-size: 11px;"><?= $info['range'] ?></td>
                                                    <td style="padding: 12px 15px; font-weight: 600;"><?= $section['student_proficiency']['M'][$key] ?></td>
                                                    <td style="padding: 12px 15px; font-weight: 600;"><?= $section['student_proficiency']['F'][$key] ?></td>
                                                    <td class="val-bold" style="padding: 12px 15px; color: <?= $info['color'] ?>; background: <?= $info['bg'] ?>;"><?= $section['student_proficiency']['T'][$key] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background: #f1f5f9; font-weight: 800;">
                                                <td class="text-left" colspan="2" style="padding: 12px 15px;">TOTAL</td>
                                                <td style="padding: 12px 15px;"><?= array_sum($section['student_proficiency']['M']) ?></td>
                                                <td style="padding: 12px 15px;"><?= array_sum($section['student_proficiency']['F']) ?></td>
                                                <td style="padding: 12px 15px; background: #3b82f6; color: white;"><?= array_sum($section['student_proficiency']['T']) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>
</body>

</html>
