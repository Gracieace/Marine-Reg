<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$user_id = $_SESSION['user']['id'];

// 1. Initialize Tables
$pdo->exec("CREATE TABLE IF NOT EXISTS accomplishment_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    month VARCHAR(20) NOT NULL,
    school_year VARCHAR(20) NOT NULL,
    activities TEXT,
    outcomes TEXT,
    challenges TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_report (teacher_id, month, school_year)
)");

// Get teacher info
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE email = (SELECT username FROM users WHERE id = ?)");
$stmt->execute([$user_id]);
$teacher = $stmt->fetch();

$month = $_GET['month'] ?? date('F');
$sy = $_GET['sy'] ?? ($settings['current_school_year'] ?? '2024-2025');

// 2. Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activities = $_POST['activities'] ?? '';
    $outcomes = $_POST['outcomes'] ?? '';
    $challenges = $_POST['challenges'] ?? '';

    $stmt = $pdo->prepare("INSERT INTO accomplishment_reports (teacher_id, month, school_year, activities, outcomes, challenges) 
                           VALUES (?, ?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE activities=VALUES(activities), outcomes=VALUES(outcomes), challenges=VALUES(challenges)");
    $stmt->execute([$teacher['id'], $month, $sy, $activities, $outcomes, $challenges]);
    $success = "Report saved successfully!";
}

// 3. Fetch current report
$stmt = $pdo->prepare("SELECT * FROM accomplishment_reports WHERE teacher_id = ? AND month = ? AND school_year = ?");
$stmt->execute([$teacher['id'], $month, $sy]);
$report = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Accomplishment Report</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10b981;
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
            max-width: 900px;
            margin: auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
        }

        textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
            box-sizing: border-box;
        }

        select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-family: inherit;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: var(--primary);
            color: white;
            transition: 0.2s;
        }

        .btn:hover {
            background: #059669;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div class="content main-content">
        <div class="header">
            <h1 style="margin:0; font-size:24px; color:#1e293b;">Teacher's Accomplishment Report</h1>
            <div style="display:flex; gap:10px;">
                <select onchange="window.location.href='?month='+this.value">
                    <?php foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $m): ?>
                        <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>>
                            <?= $m ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="window.print()" class="btn" style="background:#6366f1;">🖨️ Print PDF</button>
            </div>
        </div>

        <div class="card">
            <?php if (isset($success))
                echo "<p style='color:#065f46; background:#d1fae5; padding:12px; border-radius:8px;'>$success</p>"; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Key Activities (Conducted Classes, Meetings, Trainings)</label>
                    <textarea name="activities"
                        placeholder="List your activities for this month..."><?= htmlspecialchars($report['activities'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Learning Outcomes / Achievements</label>
                    <textarea name="outcomes"
                        placeholder="What were the results of your activities?"><?= htmlspecialchars($report['outcomes'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Challenges Encountered & Actions Taken</label>
                    <textarea name="challenges"
                        placeholder="Specify any issues faced and how you resolved them..."><?= htmlspecialchars($report['challenges'] ?? '') ?></textarea>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn">💾 Save Monthly Report</button>
                </div>
            </form>
        </div>

        <!-- Printable View hidden in browser -->
        <div id="print-area" style="display:none;">
            <div style="text-align:center;">
                <h2>MONTHLY ACCOMPLISHMENT REPORT</h2>
                <p>For the Month of:
                    <?= $month ?>
                    <?= $sy ?>
                </p>
            </div>
            <div style="margin-top:20px;">
                <strong>Teacher:</strong>
                <?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?><br>
                <strong>Date:</strong>
                <?= date('F d, Y') ?>
            </div>
            <table style="width:100%; border-collapse:collapse; margin-top:20px;">
                <tr>
                    <th style="border:1px solid #000; padding:10px; text-align:left; background:#eee;">Activities</th>
                </tr>
                <tr>
                    <td style="border:1px solid #000; padding:10px;">
                        <?= nl2br(htmlspecialchars($report['activities'] ?? '')) ?>
                    </td>
                </tr>
                <tr>
                    <th style="border:1px solid #000; padding:10px; text-align:left; background:#eee;">Outcomes</th>
                </tr>
                <tr>
                    <td style="border:1px solid #000; padding:10px;">
                        <?= nl2br(htmlspecialchars($report['outcomes'] ?? '')) ?>
                    </td>
                </tr>
                <tr>
                    <th style="border:1px solid #000; padding:10px; text-align:left; background:#eee;">Challenges</th>
                </tr>
                <tr>
                    <td style="border:1px solid #000; padding:10px;">
                        <?= nl2br(htmlspecialchars($report['challenges'] ?? '')) ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <style type="text/css">
        @media print {

            .card,
            .header,
            .sidebar,
            .header-panel,
            .teacher-sidebar {
                display: none !important;
            }

            #print-area {
                display: block !important;
            }
        }
    </style>
</body>

</html>