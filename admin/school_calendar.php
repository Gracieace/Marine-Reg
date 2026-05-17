<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['admin']);
require_once __DIR__ . '/../config/db.php';

$pdo = db_connect();
initialize_schema($pdo);

$message = '';
$error = '';

$current_sy = get_current_school_year($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_calendar'])) {
    try {
        $pdo->beginTransaction();
        $days = $_POST['days'] ?? [];
        $stmt = $pdo->prepare("INSERT INTO school_calendar (school_year, month, num_days) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE num_days = VALUES(num_days)");
        
        foreach ($days as $month => $num_days) {
            $stmt->execute([$current_sy, $month, $num_days]);
        }
        
        $pdo->commit();
        $message = "School calendar updated successfully for SY $current_sy.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error updating calendar: " . $e->getMessage();
    }
}

// Fetch calendar for current SY
$stmt = $pdo->prepare("SELECT month, num_days FROM school_calendar WHERE school_year = ?");
$stmt->execute([$current_sy]);
$calendar_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$months = ['June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March', 'April', 'May'];
$calendar = [];
foreach ($months as $m) {
    $calendar[$m] = $calendar_raw[$m] ?? 20; // Default to 20 if not set
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Calendar Management - SF4</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --primary: #2563eb;
            --border: #e2e8f0;
            --text: #1e293b;
        }
        body { background: var(--bg); font-family: 'Inter', sans-serif; color: var(--text); margin: 0; }
        .content { margin-left: 260px; padding: 120px 40px 40px; max-width: 1000px; }
        .page-header { margin-bottom: 30px; }
        .page-title { font-size: 28px; font-weight: 700; margin: 0; color: #0f172a; }
        .card { background: var(--card); border-radius: 16px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid var(--border); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
        .month-group { background: #f1f5f9; padding: 15px; border-radius: 12px; border: 1px solid var(--border); }
        .month-name { font-weight: 600; margin-bottom: 10px; display: block; color: #475569; }
        .input-group { display: flex; align-items: center; gap: 10px; }
        input[type="number"] { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 16px; text-align: center; }
        .btn-save { background: var(--primary); color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 30px; width: 100%; transition: background 0.2s; }
        .btn-save:hover { background: #1d4ed8; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <?php include 'admin_header.php'; ?>
    <?php include 'admin_sidebar.php'; ?>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">School Calendar Management</h1>
            <p style="color: #64748b; margin-top: 5px;">Set the number of school days per month for SF4 Attendance Percentages (SY <?= htmlspecialchars($current_sy) ?>)</p>
        </div>

        <?php if($message): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="card">
            <div class="grid">
                <?php foreach ($calendar as $month => $days): ?>
                    <div class="month-group">
                        <label class="month-name"><?= $month ?></label>
                        <div class="input-group">
                            <input type="number" name="days[<?= $month ?>]" value="<?= $days ?>" min="0" max="31" required>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="save_calendar" class="btn-save">💾 Save School Calendar</button>
        </form>
    </div>
</body>
</html>
