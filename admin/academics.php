<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/db.php';
auth_require_role('admin');

// Database connection could be used here for fetching academic data
try {
    $pdo = db_connect();
} catch (Exception $e) {
    // Handle error
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academics - Admin Dashboard</title>
    <!-- Use root-relative paths so this works both on localhost and live hosting -->
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .content {
            padding: 180px 32px 48px;
            max-width: 1600px;
            box-sizing: border-box;
        }

        .page-header {
            margin-bottom: 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            color: #0f172a;
            margin: 0 0 8px 0;
            font-size: 32px;
            font-weight: 700;
        }

        .page-header p {
            color: #64748b;
            font-size: 16px;
            margin: 0;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .content {
                padding: 100px 16px 32px;
            }

            .page-header h1 {
                font-size: 24px;
            }

            .page-header p {
                font-size: 14px;
            }

            .card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>
    <?php require_once dirname(__DIR__) . '/header.php'; ?>
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <div class="content main-content">
        <div class="page-header">
            <div>
                <h1>Academics</h1>
                <p>Manage academic records, curriculum, and subjects</p>
            </div>
        </div>

        <div class="card">
            <h2>Academic Overview</h2>
            <p>Welcome to the Academics management module. This section is under construction.</p>
        </div>
    </div>
</body>

</html>