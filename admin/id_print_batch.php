<?php
require_once '../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once '../config/db.php';
require_once '../includes/qr_helper.php';

$pdo = db_connect();
$current_sy = get_current_school_year($pdo);

$ids = $_GET['ids'] ?? '';
if (!$ids) die("No IDs selected.");

$id_array = explode(',', $ids);
$placeholders = implode(',', array_fill(0, count($id_array), '?'));

$stmt = $pdo->prepare("SELECT e.*, sid.id_number, r.guardian_first, r.guardian_last, r.guardian_contact, r.curr_barangay, r.curr_city,
                              r.track, r.strand,
                              adv.first_name as adv_first, adv.last_name as adv_last, adv.e_signature as adv_sig, adv.position_title as adv_title
                      FROM enrollments e
                      JOIN school_ids sid ON e.student_id = sid.student_id
                      LEFT JOIN registrations r ON e.registration_id = r.id
                      LEFT JOIN sections sct ON (e.grade_level = sct.grade_level AND e.section = sct.section_name AND e.school_year = sct.school_year)
                      LEFT JOIN users adv ON sct.adviser_id = adv.id
                      WHERE e.student_id IN ($placeholders)");
try {
    $stmt->execute($id_array);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$school_head = get_system_setting($pdo, 'principal_name', 'MARILOU D. MARQUEZ, PhD');

// Fetch School Head's signature
$head_sig = null;
try {
    $stmt_head = $pdo->query("SELECT u.first_name, u.last_name, u.e_signature 
                              FROM position_assignments pa
                              JOIN users u ON pa.user_id = u.id
                              WHERE pa.position_type = 'principal' 
                              AND pa.school_year = '$current_sy'
                              LIMIT 1");
    $head_data = $stmt_head->fetch();
    if ($head_data) {
        $school_head = ($head_data['first_name'] . ' ' . $head_data['last_name']) ?: $school_head;
        $head_sig = $head_data['e_signature'];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Batch ID Printing</title>
    <link rel="stylesheet" href="<?= url_for('/assets/css/id_card_styles.css') ?>">
    <style>
        body { 
            background: #f1f5f9; 
            margin: 0; 
            padding: 40px; 
            font-family: 'Inter', sans-serif;
        }
        .print-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, 680px); /* Pair of front/back */
            gap: 40px; 
            justify-content: center;
        }
        .id-pair {
            display: flex;
            gap: 20px;
            background: white;
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        /* Force printing colors */
        @media print {
            body { background: white; padding: 0; }
            .print-grid { display: block; gap: 0; }
            .id-pair { 
                box-shadow: none; 
                padding: 10mm; 
                margin-bottom: 20mm;
                break-inside: avoid;
                justify-content: center;
            }
            .no-print { display: none !important; }
        }

        .btn-print {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 16px 32px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4);
            z-index: 1000;
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">
        🖨️ Print Batch (<?= count($students) ?> Students)
    </button>

    <div class="print-grid">
        <?php foreach($students as $s): 
            $qrData = QRHelper::getVerificationPayload($pdo, $s['student_id'], $current_sy);
            $qrUrl = QRHelper::getQRUrl($qrData, 150);
            $photoUrl = $s['photo_path'] ? url_for($s['photo_path']) : 'https://ui-avatars.com/api/?name='.urlencode($s['student_name']).'&background=0D8ABC&color=fff&size=256';
            
            // Handle Adviser Signature
            $adv_sig_url = $s['adv_sig'] ? url_for('/uploads/'.$s['adv_sig']) : null;
        ?>
            <div class="id-pair">
                <!-- Front Side -->
                <div class="id-card">
                    <div class="id-sidebar">
                        <img src="<?= url_for('/assets/images/school_logo.png') ?>" class="sidebar-logo">
                        <div class="sidebar-text">MMFSL</div>
                        <div class="sidebar-serial"><?= htmlspecialchars($s['id_number']) ?></div>
                    </div>
                    <div class="id-main">
                        <div class="deped-header">
                            <div class="deped-top">Republic of the Philippines</div>
                            <div class="deped-top">DEPARTMENT OF EDUCATION</div>
                            <div class="school-title">MALOLOS MARINE FISHERY SCHOOL AND LABORATORY</div>
                            <div class="school-address">Balite, City of Malolos, Bulacan</div>
                        </div>
                        <div class="photo-section">
                            <div class="photo-box">
                                <img src="<?= $photoUrl ?>" alt="Student Photo">
                            </div>
                        </div>
                        <div class="student-info">
                            <div class="name-label"><?= strtoupper($s['student_name']) ?></div>
                            <div class="lrn-label">LRN: <?= $s['lrn'] ?></div>
                            <div class="grade-label"><?= strtoupper($s['grade_level'] . ' - ' . $s['section']) ?></div>
                            <div class="strand-label">
                                <?php 
                                    $grade = strtolower($s['grade_level']);
                                    if (strpos($grade, '11') !== false || strpos($grade, '12') !== false) {
                                        echo strtoupper(($s['track'] ?? 'SHS') . ' - ' . ($s['strand'] ?? ''));
                                    } else {
                                        echo 'JUNIOR HIGH SCHOOL';
                                    }
                                ?>
                            </div>
                            <div class="sy-label">School Year: <?= $current_sy ?></div>
                        </div>
                    </div>
                </div>

                <!-- Back Side -->
                <div class="id-card">
                    <div class="id-sidebar"></div>
                    <div class="id-main id-back">
                        <div class="back-section-label">Emergency Contact</div>
                        <div class="emergency-box">
                            <div class="contact-item">
                                <span class="contact-label">Parent/Guardian</span>
                                <span class="contact-val"><?= strtoupper($s['guardian_first'] . ' ' . $s['guardian_last']) ?></span>
                            </div>
                            <div class="contact-item">
                                <span class="contact-label">Contact Number</span>
                                <span class="contact-val"><?= $s['guardian_contact'] ?: 'N/A' ?></span>
                            </div>
                        </div>

                        <div class="rules-divider"></div>
                        <div class="back-section-label" style="font-size:7.5px; margin-bottom:8px;">Rules & Guidelines</div>
                        <div class="rules-list">
                            <p><span>1.</span> This card is non-transferable and must be worn at all times.</p>
                            <p><span>2.</span> Report loss immediately to the Registrar's Office.</p>
                            <p><span>3.</span> Card serves as official verification for school services.</p>
                            <p><span>4.</span> Please return this card if found or upon graduation/transfer.</p>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: auto; padding: 5px 0;">
                            <!-- Adviser -->
                            <div class="signature-wrap" style="flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;">
                                <div style="height: 45px; width: 100%; position: absolute; bottom: 20px; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 1;">
                                    <?php if($adv_sig_url): ?>
                                        <img src="<?= $adv_sig_url ?>" style="max-height: 100%; max-width: 80px; mix-blend-mode: multiply;">
                                    <?php endif; ?>
                                </div>
                                <div class="sig-line" style="width: 90%;"></div>
                                <div class="sig-name" style="font-size: 8px;"><?= strtoupper($s['adv_first'] . ' ' . $s['adv_last']) ?: 'CLASS ADVISER' ?></div>
                                <div class="sig-title" style="font-size: 6.5px;">Class Adviser</div>
                            </div>

                            <!-- Principal -->
                            <div class="signature-wrap" style="flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;">
                                <div style="height: 45px; width: 100%; position: absolute; bottom: 20px; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 1;">
                                    <?php if($head_sig): ?>
                                        <img src="<?= url_for('/uploads/'.$head_sig) ?>" style="max-height: 100%; max-width: 80px; mix-blend-mode: multiply;">
                                    <?php endif; ?>
                                </div>
                                <div class="sig-line" style="width: 90%;"></div>
                                <div class="sig-name" style="font-size: 8px;"><?= strtoupper($school_head) ?></div>
                                <div class="sig-title" style="font-size: 6.5px;">School Head</div>
                            </div>
                        </div>

                        <div class="back-qr-wrap">
                            <div class="back-qr-box">
                                <img src="<?= $qrUrl ?>" alt="Verification QR">
                            </div>
                            <div class="back-qr-label">Scan for Verification</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
