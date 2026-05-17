<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user_id = $_SESSION['user']['id'];

// 1. Initialize TOS Tables
$pdo->exec("CREATE TABLE IF NOT EXISTS tos_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    grade_level VARCHAR(50) NOT NULL,
    section VARCHAR(100) NOT NULL,
    school_year VARCHAR(20) NOT NULL,
    period VARCHAR(50) NOT NULL,
    total_days INT DEFAULT 0,
    total_items INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tos (subject_id, section, school_year, period)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS tos_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tos_id INT NOT NULL,
    objective TEXT,
    days_taught INT DEFAULT 0,
    num_items INT DEFAULT 0,
    remembering VARCHAR(50) DEFAULT '',
    understanding VARCHAR(50) DEFAULT '',
    applying VARCHAR(50) DEFAULT '',
    analyzing VARCHAR(50) DEFAULT '',
    evaluating VARCHAR(50) DEFAULT '',
    creating VARCHAR(50) DEFAULT '',
    FOREIGN KEY (tos_id) REFERENCES tos_reports(id) ON DELETE CASCADE
)");

// --- 1. Parameter Handling (Dashboard Sync) ---
$selected_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$selected_section = $_GET['section'] ?? '';
$selected_sy = $_GET['sy'] ?? $_GET['school_year'] ?? '';
$selected_sub = $_GET['subject_id'] ?? '';
$period = $_GET['period'] ?? '1st Quarter';

// --- 2. Fetch Teacher's Assigned Subjects (Official Source of Truth) ---
$stmt = $pdo->prepare("
    SELECT st.*, c.subject_name, s.section_name 
    FROM subject_teachers st
    JOIN curriculum c ON st.curriculum_id = c.id
    JOIN sections s ON st.section_id = s.id
    WHERE st.teacher_id = (SELECT id FROM teachers WHERE email = (SELECT username FROM users WHERE id = ?))
    AND st.school_year = ?
    ORDER BY s.section_name, c.subject_name
");
$stmt->execute([$user_id, $selected_sy ?: '2024-2025']); // Fallback SY handled
$assigned_loads = $stmt->fetchAll();

// If no selection but we have loads, pick first for UI consistency
if (!$selected_grade && !empty($assigned_loads)) {
    $selected_grade = $assigned_loads[0]['grade_level'];
    $selected_section = $assigned_loads[0]['section_name'];
    $selected_sub = $assigned_loads[0]['curriculum_id'];
    $selected_sy = $assigned_loads[0]['school_year'];
}

// --- 3. Fetch TOS Data for Selected Context ---
$tos = null;
$items = [];
if ($selected_sub && $selected_section && $selected_sy) {
    $stmt = $pdo->prepare("SELECT * FROM tos_reports WHERE subject_id = ? AND section = ? AND school_year = ? AND period = ?");
    $stmt->execute([$selected_sub, $selected_section, $selected_sy, $period]);
    $tos = $stmt->fetch();

    if ($tos) {
        $stmt = $pdo->prepare("SELECT * FROM tos_items WHERE tos_id = ?");
        $stmt->execute([$tos['id']]);
        $items = $stmt->fetchAll();
    }
}

// 3. Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $total_days = $_POST['total_days'] ?? 0;
    $total_items = $_POST['total_items'] ?? 0;

    if (!$tos) {
        $stmt = $pdo->prepare("INSERT INTO tos_reports (teacher_id, subject_id, grade_level, section, school_year, period, total_days, total_items) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $selected_sub, $class['grade_level'], $class['section'], $class['school_year'], $period, $total_days, $total_items]);
        $tos_id = $pdo->lastInsertId();
    } else {
        $tos_id = $tos['id'];
        $stmt = $pdo->prepare("UPDATE tos_reports SET total_days=?, total_items=? WHERE id=?");
        $stmt->execute([$total_days, $total_items, $tos_id]);
    }

    // Sync Items
    $pdo->prepare("DELETE FROM tos_items WHERE tos_id = ?")->execute([$tos_id]);
    $new_items = $_POST['items'] ?? [];
    foreach ($new_items as $ni) {
        if (empty($ni['objective']))
            continue;
        $stmt = $pdo->prepare("INSERT INTO tos_items (tos_id, objective, days_taught, num_items, remembering, understanding, applying, analyzing, evaluating, creating) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tos_id, $ni['objective'], $ni['days_taught'], $ni['num_items'], $ni['rem'], $ni['und'], $ni['app'], $ni['ana'], $ni['eva'], $ni['cre']]);
    }
    header("Location: ?subject_id=$selected_sub&period=$period&success=1");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TOS - Table of Specification</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --bg: #f8fafc;
            --border: #e2e8f0;
        }

        body {
            background: var(--bg);
            font-family: 'Inter', sans-serif;
            margin: 0;
        }

        .content {
            padding: 120px 32px 48px;
            max-width: 1400px;
            margin: auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid var(--border);
            padding: 8px;
            text-align: center;
        }

        th {
            background: #f1f5f9;
            font-size: 11px;
            text-transform: uppercase;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            border: 0;
            padding: 4px;
            text-align: center;
            font-family: inherit;
        }

        .text-left {
            text-align: left !important;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-add {
            background: #10b981;
            color: white;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="content main-content">
        <div class="card">
            <h1 style="margin:0; font-size:24px; color:#1e293b;">Table of Specification (TOS)</h1>
            <p style="color:#64748b;">Plan your periodic examinations and learning objectives.</p>

            <form method="GET" style="display:flex; gap:15px; margin-top:20px;">
                <?php // Preserve dashboard params in hidden fields
                foreach(['grade','section','sy','period'] as $p) {
                   if(isset($_GET[$p]) && $p != 'period') echo '<input type="hidden" name="'.$p.'" value="'.htmlspecialchars($_GET[$p]).'">';
                }
                ?>
                <select name="subject_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Select Subject</option>
                    <?php foreach ($assigned_loads as $s): ?>
                        <option value="<?= $s['curriculum_id'] ?>" <?= $selected_sub == $s['curriculum_id'] ? 'selected' : '' ?>>
                            <?= $s['subject_name'] ?> (<?= htmlspecialchars($s['section_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <select class="form-control" style="width:200px; margin:0;"
                onchange="const url = new URL(window.location); url.searchParams.set('period', this.value); window.location.href = url.href;">
                    <?php foreach (['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'] as $p): ?>
                        <option value="<?= $p ?>" <?= $period == $p ? 'selected' : '' ?>>
                            <?= $p ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selected_sub): ?>
            <div class="card">
                <form method="POST">
                    <div style="display:flex; justify-content: space-between; align-items: center;">
                        <div style="display:flex; gap:20px;">
                            <div><label>Total Days Taught:</label> <input type="number" name="total_days"
                                    value="<?= $tos['total_days'] ?? 0 ?>" style="width:50px; border-bottom:1px solid #000;">
                            </div>
                            <div><label>Total Item Count:</label> <input type="number" name="total_items"
                                    value="<?= $tos['total_items'] ?? 0 ?>" style="width:50px; border-bottom:1px solid #000;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">💾 Save TOS</button>
                    </div>

                    <table id="tos-table">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width:30%;">Learning Competencies / Objectives</th>
                                <th rowspan="2">Days Taught</th>
                                <th rowspan="2">Weight (%)</th>
                                <th rowspan="2">No. of Items</th>
                                <th colspan="6">Cognitive Process Dimensions (Item Placement)</th>
                            </tr>
                            <tr>
                                <th style="background:#fff7ed;">REM</th>
                                <th style="background:#f0fdf4;">UND</th>
                                <th style="background:#eff6ff;">APP</th>
                                <th style="background:#faf5ff;">ANA</th>
                                <th style="background:#fff1f2;">EVA</th>
                                <th style="background:#fdf4ff;">CRE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $idx = 0;
                            foreach ($items as $item): ?>
                                <tr>
                                    <td class="text-left"><input type="text" name="items[<?= $idx ?>][objective]"
                                            value="<?= htmlspecialchars($item['objective']) ?>" style="text-align:left;"></td>
                                    <td><input type="number" name="items[<?= $idx ?>][days_taught]"
                                            value="<?= $item['days_taught'] ?>" class="days-taught"></td>
                                    <td class="weight">0%</td>
                                    <td><input type="number" name="items[<?= $idx ?>][num_items]" value="<?= $item['num_items'] ?>"
                                            class="num-items"></td>
                                    <td><input type="text" name="items[<?= $idx ?>][rem]" value="<?= $item['remembering'] ?>"></td>
                                    <td><input type="text" name="items[<?= $idx ?>][und]" value="<?= $item['understanding'] ?>">
                                    </td>
                                    <td><input type="text" name="items[<?= $idx ?>][app]" value="<?= $item['applying'] ?>"></td>
                                    <td><input type="text" name="items[<?= $idx ?>][ana]" value="<?= $item['analyzing'] ?>"></td>
                                    <td><input type="text" name="items[<?= $idx ?>][eva]" value="<?= $item['evaluating'] ?>"></td>
                                    <td><input type="text" name="items[<?= $idx ?>][cre]" value="<?= $item['creating'] ?>"></td>
                                </tr>
                                <?php $idx++; endforeach; ?>
                            <!-- Empty rows if new -->
                            <?php if (empty($items)):
                                for ($i = 0; $i < 5; $i++): ?>
                                    <tr>
                                        <td class="text-left"><input type="text" name="items[<?= $idx ?>][objective]"
                                                style="text-align:left;"></td>
                                        <td><input type="number" name="items[<?= $idx ?>][days_taught]" class="days-taught"></td>
                                        <td class="weight">0%</td>
                                        <td><input type="number" name="items[<?= $idx ?>][num_items]" class="num-items"></td>
                                        <td><input type="text" name="items[<?= $idx ?>][rem]"></td>
                                        <td><input type="text" name="items[<?= $idx ?>][und]"></td>
                                        <td><input type="text" name="items[<?= $idx ?>][app]"></td>
                                        <td><input type="text" name="items[<?= $idx ?>][ana]"></td>
                                        <td><input type="text" name="items[<?= $idx ?>][eva]"></td>
                                        <td><input type="text" name="items[<?= $idx ?>][cre]"></td>
                                    </tr>
                                    <?php $idx++; endfor; endif; ?>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-add" onclick="addRow()">➕ Add Row</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let counter = <?= $idx ?>;
        function addRow() {
            const table = document.getElementById('tos-table').getElementsByTagName('tbody')[0];
            const newRow = table.insertRow();
            newRow.innerHTML = `
                <td class="text-left"><input type="text" name="items[${counter}][objective]" style="text-align:left;"></td>
                <td><input type="number" name="items[${counter}][days_taught]" class="days-taught"></td>
                <td class="weight">0%</td>
                <td><input type="number" name="items[${counter}][num_items]" class="num-items"></td>
                <td><input type="text" name="items[${counter}][rem]"></td>
                <td><input type="text" name="items[${counter}][und]"></td>
                <td><input type="text" name="items[${counter}][app]"></td>
                <td><input type="text" name="items[${counter}][ana]"></td>
                <td><input type="text" name="items[${counter}][eva]"></td>
                <td><input type="text" name="items[${counter}][cre]"></td>
            `;
            counter++;
        }
    </script>
</body>

</html>