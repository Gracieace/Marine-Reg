<?php
/**
 * SF4 Reporting Module - Standardized for DepEd Compliance
 * Automatically retrieves, computes, and generates Monthly Learner Movement and Attendance
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../auth/auth.php';

require_once __DIR__ . '/sf4_logic.php';

// Global Initialization
try {
    $pdo = db_connect();
    auth_require_role(['admin', 'registrar', 'teacher']);

    $settings = [
        'school_id' => '300750', 'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
        'region' => 'REGION III', 'division' => 'MALOLOS CITY', 'district' => 'DISTRICT X',
        'school_head' => '' // Default blank
    ];
    
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('region', 'division', 'district', 'school_name', 'school_id', 'school_head', 'principal_name')");
    if ($stmt_set) {
        while ($s = $stmt_set->fetch()) {
            if (!empty($s['setting_value'])) {
                if ($s['setting_key'] === 'principal_name') $settings['school_head'] = $s['setting_value'];
                else $settings[$s['setting_key']] = $s['setting_value'];
            }
        }
    }

    $school_year = $_GET['school_year'] ?? get_active_school_year($pdo);
    $month = $_GET['month'] ?? date('F');
    $grade_level = $_GET['grade_level'] ?? '';
    
    $reports = generateSF4($pdo, $grade_level, '', $school_year, $month);
} catch (Exception $e) {
    die("<h1>SF4 Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF4 - Monthly Learner's Movement and Attendance</title>
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        :root {
            --primary: #4f46e5; --primary-dark: #3730a3; --secondary: #64748b;
            --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0; --text: #1e293b;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; min-height: 100vh; }
        .main-wrapper { display: flex; min-height: 100vh; position: relative; }
        
        /* Responsive Sidebar Integration */
        .page-content { 
            flex: 1; 
            padding: 32px; 
            margin-left: 280px; 
            transition: all 0.3s ease; 
            max-width: calc(100vw - 280px);
        }
        
        @media (max-width: 1024px) {
            .page-content { margin-left: 0; max-width: 100vw; padding: 20px; }
        }

        .report-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
        .report-title h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; }
        
        .filter-card { background: var(--card); padding: 24px; border-radius: 16px; box-shadow: var(--shadow); margin-bottom: 24px; border: 1px solid var(--border); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: flex-end; }
        
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; }
        .form-control { padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); font-size: 14px; background: #f8fafc; transition: all 0.2s; }
        .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }

        .btn-action { height: 42px; padding: 0 24px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; border: none; transition: all 0.2s; }
        .btn-search { background: var(--primary); color: white; }
        .btn-search:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-print { background: white; color: #1e293b; border: 1px solid var(--border); }
        .btn-print:hover { background: #f1f5f9; }

        .report-card { background: white; border-radius: 16px; padding: 32px; box-shadow: var(--shadow); border: 1px solid var(--border); }
        
        .table-responsive { 
            overflow-x: auto; 
            margin: 0 -32px; 
            padding: 0 32px; 
            scrollbar-width: thin;
        }
        .table-responsive::-webkit-scrollbar { height: 8px; }
        .table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .sf4-table { width: 100%; border-collapse: collapse; margin-top: 20px; border: 2px solid #000; min-width: 1300px; }
        .sf4-table th, .sf4-table td { border: 1px solid #000; padding: 8px 4px; text-align: center; font-size: 10px; color: #000; }
        .sf4-table th { background: #f8fafc; font-weight: 800; font-size: 9px; }

        .print-header { border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 20px; }
        .print-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; font-size: 11px; margin-bottom: 20px; }
        .info-value { border-bottom: 1px solid #000; flex: 1; padding: 0 4px; min-height: 15px; font-weight: 700; }

        @media print {
            .no-print, .filter-card, .btn-action, .admin-sidebar, .sidebar, .sidebar-overlay, .admin-header { display: none !important; }
            .page-content { margin-left: 0 !important; padding: 0 !important; max-width: 100vw !important; }
            .report-card { box-shadow: none !important; border: none !important; padding: 0 !important; }
            .sf4-table { font-size: 8px; width: 100%; }
            .table-responsive { overflow: visible; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <?php include '../../admin_header.php'; ?>
    <div class="main-wrapper">
        <?php include '../../admin_sidebar.php'; ?>
        <div class="page-content">
            <div class="report-header no-print">
                <div class="report-title">
                    <h1>School Form 4 (SF4)</h1>
                    <p style="color: var(--secondary); margin-top: 4px;">Monthly Learner's Movement and Attendance Report</p>
                </div>
                <div style="display: flex; gap: 12px;">
                    <?php if (!empty($reports)): ?>
                        <a href="sf4_print.php?school_year=<?= urlencode($school_year) ?>&month=<?= urlencode($month) ?>&grade_level=<?= urlencode($grade_level) ?>" target="_blank" class="btn-action btn-print" style="text-decoration: none;">
                            <span>📄</span> Actual Print Layout
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn-action btn-print" onclick="window.print()">🖨️ Quick Print</button>
                </div>
            </div>

            <form class="filter-card no-print" method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Month</label>
                        <select name="month" class="form-control">
                            <?php foreach(['June','July','August','September','October','November','December','January','February','March','April','May'] as $m) echo "<option value='$m' ".($month==$m?'selected':'').">$m</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>School Year</label>
                        <select name="school_year" class="form-control">
                            <?php 
                            $sys = $pdo->query("SELECT DISTINCT school_year FROM sections ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                            if (empty($sys)) $sys = [$school_year];
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
                            foreach($gls as $gl) echo "<option value='$gl' ".($grade_level==$gl?'selected':'').">$gl</option>";
                            ?>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; flex-direction: row; gap: 12px; grid-column: span 2;">
                        <button type="submit" class="btn-action btn-search" style="flex: 1;">🚀 Generate Report</button>
                        <button type="button" class="btn-action btn-print" style="flex: 1; justify-content: center;" onclick="openPrintLayout()">
                            <span>🖨️</span> Print SF4
                        </button>
                    </div>
                </div>
            </form>

            <script>
                function openPrintLayout() {
                    const month = document.querySelector('select[name="month"]').value;
                    const sy = document.querySelector('select[name="school_year"]').value;
                    const gl = document.querySelector('select[name="grade_level"]').value;
                    const url = `sf4_print.php?month=${encodeURIComponent(month)}&school_year=${encodeURIComponent(sy)}&grade_level=${encodeURIComponent(gl)}`;
                    window.open(url, '_blank');
                }
            </script>

            <div class="report-card">
                <div class="print-header">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <img src="<?= url_for('/img/deped_logo.png') ?>" style="height: 60px;" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/thumb/a/af/Department_of_Education_%28DepEd%29_Philippines.svg/1200px-Department_of_Education_%28DepEd%29_Philippines.svg.png'">
                        <div style="text-align: center;">
                            <h2 style="margin:0; font-weight: 800; font-size: 16px; text-transform: uppercase;">School Form 4 (SF4) Monthly Learner's Movement and Attendance</h2>
                        </div>
                        <img src="<?= url_for('/img/phil_seal.png') ?>" style="height: 60px;" onerror="this.src='https://upload.wikimedia.org/wikipedia/commons/thumb/8/84/Coat_of_arms_of_the_Philippines.svg/1200px-Coat_of_arms_of_the_Philippines.svg.png'">
                    </div>
                </div>

                <div class="print-info-grid">
                    <div>School ID: <span class="info-value"><?= htmlspecialchars($settings['school_id']) ?></span></div>
                    <div>Region: <span class="info-value"><?= htmlspecialchars($settings['region']) ?></span></div>
                    <div>Division: <span class="info-value"><?= htmlspecialchars($settings['division']) ?></span></div>
                    <div>School Name: <span class="info-value"><?= htmlspecialchars($settings['school_name']) ?></span></div>
                    <div>District: <span class="info-value"><?= htmlspecialchars($settings['district']) ?></span></div>
                    <div>School Year: <span class="info-value"><?= htmlspecialchars($school_year) ?></span></div>
                </div>

                <div class="table-responsive">
                    <table class="sf4-table">
                        <thead>
                            <tr>
                                <th rowspan="3" style="width: 150px;">NAME OF ADVISER</th><th rowspan="3" style="width: 50px;">GRADE</th><th rowspan="3" style="width: 100px;">SECTION</th>
                                <th colspan="3">REGISTERED LEARNER</th><th colspan="6">ATTENDANCE</th>
                                <th colspan="9">DROPPED OUT</th><th colspan="9">TRANSFERRED OUT</th><th colspan="9">TRANSFERRED IN</th>
                            </tr>
                            <tr>
                                <th rowspan="2">M</th><th rowspan="2">F</th><th rowspan="2">T</th>
                                <th colspan="3">Daily Avg</th><th colspan="3">Perc.</th>
                                <th colspan="3">Prev.</th><th colspan="3">Month</th><th colspan="3">End</th>
                                <th colspan="3">Prev.</th><th colspan="3">Month</th><th colspan="3">End</th>
                                <th colspan="3">Prev.</th><th colspan="3">Month</th><th colspan="3">End</th>
                            </tr>
                            <tr>
                                <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                                <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reports)): ?>
                                <tr><td colspan="42" style="padding: 40px; color: #64748b; font-style: italic;">No data available for the selected filters.</td></tr>
                            <?php else: ?>
                                <?php foreach($reports as $r): ?>
                                <tr>
                                    <td style="text-align: left; font-size: 9px;"><?= htmlspecialchars(strtoupper($r['adviser'])) ?></td>
                                    <td><?= htmlspecialchars($r['grade_level']) ?></td><td style="text-align: left;"><?= htmlspecialchars($r['section']) ?></td>
                                    <td><?= $r['reg_m'] ?></td><td><?= $r['reg_f'] ?></td><td><?= $r['reg_t'] ?></td>
                                    <td><?= $r['ada_m'] ?></td><td><?= $r['ada_f'] ?></td><td><?= $r['ada_t'] ?></td>
                                    <td><?= $r['perc_m'] ?>%</td><td><?= $r['perc_f'] ?>%</td><td><?= $r['perc_t'] ?>%</td>
                                    <td><?= $r['p_drop_m'] ?></td><td><?= $r['p_drop_f'] ?></td><td><?= $r['p_drop_t'] ?></td>
                                    <td><?= $r['m_drop_m'] ?></td><td><?= $r['m_drop_f'] ?></td><td><?= $r['m_drop_t'] ?></td>
                                    <td style="background: #f8fafc;"><?= $r['p_drop_m']+$r['m_drop_m'] ?></td><td style="background: #f8fafc;"><?= $r['p_drop_f']+$r['m_drop_f'] ?></td><td style="background: #f8fafc;"><?= $r['p_drop_t']+$r['m_drop_t'] ?></td>
                                    <td><?= $r['p_tout_m'] ?></td><td><?= $r['p_tout_f'] ?></td><td><?= $r['p_tout_t'] ?></td>
                                    <td><?= $r['m_tout_m'] ?></td><td><?= $r['m_tout_f'] ?></td><td><?= $r['m_tout_t'] ?></td>
                                    <td style="background: #f8fafc;"><?= $r['p_tout_m']+$r['m_tout_m'] ?></td><td style="background: #f8fafc;"><?= $r['p_tout_f']+$r['m_tout_f'] ?></td><td style="background: #f8fafc;"><?= $r['p_tout_t']+$r['m_tout_t'] ?></td>
                                    <td><?= $r['p_tin_m'] ?></td><td><?= $r['p_tin_f'] ?></td><td><?= $r['p_tin_t'] ?></td>
                                    <td><?= $r['m_tin_m'] ?></td><td><?= $r['m_tin_f'] ?></td><td><?= $r['m_tin_t'] ?></td>
                                    <td style="background: #f8fafc;"><?= $r['p_tin_m']+$r['m_tin_m'] ?></td><td style="background: #f8fafc;"><?= $r['p_tin_f']+$r['m_tin_f'] ?></td><td style="background: #f8fafc;"><?= $r['p_tin_t']+$r['m_tin_t'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 40px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; font-size: 11px;">
                    <div>
                        <p style="font-weight: 700; text-transform: uppercase;">Guidelines:</p>
                        <ol style="padding-left: 20px; line-height: 1.6;">
                            <li>Accomplished every end of the month using SF2.</li>
                            <li>Only advisory classes reported.</li>
                        </ol>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: flex-end;">
                        <div style="text-align: center; min-width: 280px;">
                            <div style="font-size: 10px; text-align: left; margin-bottom: 15px; color: #64748b; font-weight: 600;">Prepared by:</div>
                            <div style="border-bottom: 2px solid #000; padding-bottom: 4px; font-weight: 800; font-size: 14px; min-height: 22px; text-transform: uppercase;">
                                <?= !empty($settings['school_head']) ? htmlspecialchars(strtoupper($settings['school_head'])) : '' ?>
                            </div>
                            <div style="margin-top: 4px; font-weight: 700; font-size: 10px; text-transform: uppercase;">School Head / Principal</div>
                            <div style="font-size: 8px; color: #94a3b8; margin-top: 2px;">(Signature over Printed Name)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
