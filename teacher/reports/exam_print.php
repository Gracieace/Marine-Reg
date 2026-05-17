<?php
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../config/db.php';
auth_require_role(['teacher', 'admin']);

$pdo = db_connect();
$exam_id = $_GET['id'] ?? '';

if (!$exam_id)
    die("Exam ID required");

// 1. Fetch Exam Meta
$stmt = $pdo->prepare("SELECT e.*, s.subject_name, t.first_name, t.last_name 
                       FROM exam_papers e 
                       JOIN subjects s ON e.subject_id = s.id 
                       JOIN teachers t ON e.teacher_id = t.id
                       WHERE e.id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam)
    die("Exam not found");

// 2. Fetch Questions
$stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
$stmt->execute([$exam_id]);
$questions = $stmt->fetchAll();

// 3. System Settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch())
    $settings[$row['setting_key']] = $row['setting_value'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>
        <?= htmlspecialchars($exam['title']) ?> -
        <?= htmlspecialchars($exam['subject_name']) ?>
    </title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            margin: 0;
            padding: 20px;
            line-height: 1.4;
            color: #000;
        }

        .page {
            width: 216mm;
            min-height: 279mm;
            padding: 20mm;
            margin: auto;
            background: white;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }

        .header h1 {
            font-size: 16pt;
            margin: 0;
        }

        .header h2 {
            font-size: 12pt;
            margin: 3px 0;
            font-weight: normal;
        }

        .student-header {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            border: 1px solid #000;
            padding: 10px;
        }

        .instructions {
            font-style: italic;
            margin-bottom: 20px;
            padding: 10px;
            border-left: 5px solid #000;
            background: #f9f9f9;
        }

        .question {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .question-text {
            font-weight: bold;
            margin-bottom: 8px;
            display: flex;
            gap: 8px;
        }

        .options {
            margin-left: 24px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
        }

        .option {
            margin-bottom: 3px;
        }

        .footer-sig {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 200px;
            text-align: center;
            padding-top: 5px;
            margin-top: 30px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                padding: 0;
            }

            .page {
                border: none;
                padding: 10mm;
            }
        }
    </style>
</head>

<body>
    <div class="no-print" style="text-align:right; margin-bottom:20px;">
        <button onclick="window.print()" style="padding:10px 20px; cursor:pointer;">🖨️ Print Exam Paper</button>
    </div>

    <div class="page">
        <!-- Header -->
        <div class="header">
            <h2>Republic of the Philippines</h2>
            <h1>DEPARTMENT OF EDUCATION</h1>
            <h2>Region III - Central Luzon</h2>
            <h2>Schools Division of Bulacan</h2>
            <h2 style="font-weight:bold;">
                <?= strtoupper(htmlspecialchars($settings['school_name'] ?? 'EduSystem High School')) ?>
            </h2>
            <div style="margin-top:15px; font-weight:bold; font-size:14pt;">
                <?= strtoupper(htmlspecialchars($exam['title'])) ?>
            </div>
            <div>Subject: <strong>
                    <?= htmlspecialchars($exam['subject_name']) ?>
                </strong> | SY:
                <?= htmlspecialchars($exam['school_year']) ?> | Period:
                <?= htmlspecialchars($exam['period']) ?>
            </div>
        </div>

        <!-- Student Area -->
        <div class="student-header">
            <div>
                NAME: _____________________________________________________<br>
                GRADE & SECTION: _____________________________________
            </div>
            <div style="text-align: right;">
                DATE: ___________________<br>
                SCORE: __________________
            </div>
        </div>

        <!-- Instructions -->
        <?php if ($exam['instructions']): ?>
            <div class="instructions">
                <strong>GENERAL DIRECTIONS:</strong>
                <?= htmlspecialchars($exam['instructions']) ?>
            </div>
        <?php endif; ?>

        <!-- Questions -->
        <?php foreach ($questions as $idx => $q): ?>
            <div class="question">
                <div class="question-text">
                    <span>
                        <?= $idx + 1 ?>.
                    </span>
                    <span>
                        <?= htmlspecialchars($q['question_text']) ?>
                    </span>
                </div>
                <div class="options">
                    <div class="option">A.
                        <?= htmlspecialchars($q['option_a']) ?>
                    </div>
                    <div class="option">B.
                        <?= htmlspecialchars($q['option_b']) ?>
                    </div>
                    <div class="option">C.
                        <?= htmlspecialchars($q['option_c']) ?>
                    </div>
                    <div class="option">D.
                        <?= htmlspecialchars($q['option_d']) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Signatures -->
        <div class="footer-sig">
            <div>
                Prepared by:<br>
                <div class="sig-line"><strong>
                        <?= htmlspecialchars($exam['first_name'] . ' ' . $exam['last_name']) ?>
                    </strong><br>Teacher</div>
            </div>
            <div>
                Noted by:<br>
                <div class="sig-line"><strong>
                        <?= htmlspecialchars($settings['principal_name'] ?? 'School Principal') ?>
                    </strong><br>Principal</div>
            </div>
        </div>

        <div
            style="text-align:center; margin-top:50px; font-weight:bold; border-top:1px dashed #000; padding-top:10px;">
            --- GOD BLESS AND GOOD LUCK! ---
        </div>
    </div>
</body>

</html>