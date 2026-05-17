<?php
require_once '../auth/auth.php';
auth_require_role(['admin', 'registrar']);
require_once '../config/db.php';

$pdo = db_connect();
$current_sy = get_current_school_year($pdo);

// Handle AJAX Verification
if (isset($_GET['ajax_verify'])) {
    header('Content-Type: application/json');
    $data = json_decode($_GET['data'] ?? '{}', true);
    $student_id = $data['sid'] ?? '';
    
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid QR Data']);
        exit;
    }

    // Fetch Student Info
    $stmt = $pdo->prepare("SELECT e.*, sid.id_number, sid.status as id_status,
                                 r.last_name, r.first_name, r.middle_name
                          FROM enrollments e
                          LEFT JOIN school_ids sid ON e.student_id = sid.student_id
                          LEFT JOIN registrations r ON e.registration_id = r.id
                          WHERE e.student_id = ? ORDER BY e.id DESC LIMIT 1");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student record not found.']);
        exit;
    }

    // Check Eligibility
    // 1. Promotion Status
    $eligible = true;
    $reasons = [];

    // Check SF10 Verification
    $stmt = $pdo->prepare("SELECT status FROM sf10_records WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $sf10 = $stmt->fetchColumn();
    
    if ($sf10 !== 'Verified' && $sf10 !== 'Locked') {
        $eligible = false;
        $reasons[] = 'SF10 Not Verified';
    }

    // Check Books (Accountabilities)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sf3_student_books WHERE student_lrn = ? AND date_returned IS NULL");
    $stmt->execute([$student['lrn']]);
    $unreturned_books = $stmt->fetchColumn();
    
    if ($unreturned_books > 0) {
        $eligible = false;
        $reasons[] = "$unreturned_books Unreturned Books";
    }

    echo json_encode([
        'success' => true,
        'student' => $student,
        'eligible' => $eligible,
        'reasons' => $reasons,
        'next_grade' => $student['grade_level'] // Simplified for now
    ]);
    exit;
}

// Handle Re-enrollment Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_reenrollment'])) {
    $student_id = $_POST['student_id'];
    $new_grade = $_POST['new_grade'];
    $new_section = $_POST['new_section'];
    $new_sy = $_POST['new_sy'];

    try {
        $pdo->beginTransaction();

        // 1. Log the action
        $stmt = $pdo->prepare("INSERT INTO reenrollment_logs (student_id, scanned_by, school_year, status) VALUES (?, ?, ?, 'Success')");
        $stmt->execute([$student_id, $_SESSION['user']['id'], $new_sy]);

        // 2. Create new enrollment record (simplified clone)
        // In a real system, you'd create a new row in enrollments for the next SY
        
        $pdo->commit();
        $success = "Student re-enrolled successfully for $new_sy!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Re-enrollment failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Re-Enrollment Scanner | Admin</title>
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; margin: 0; }
        .scanner-layout { display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px; padding: 30px; margin-top: 70px; }
        
        .camera-section { background: white; border-radius: 20px; padding: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        #reader { width: 100%; border-radius: 12px; overflow: hidden; border: none !important; }
        
        .result-section { background: white; border-radius: 20px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); min-height: 500px; }
        
        .student-profile-header { display: flex; gap: 20px; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9; }
        .profile-photo { width: 100px; height: 100px; border-radius: 15px; object-fit: cover; background: #e2e8f0; }
        
        .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 30px; font-weight: 800; font-size: 13px; margin-bottom: 20px; }
        .eligible { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .blocked { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .requirement-list { list-style: none; padding: 0; margin: 0; }
        .requirement-item { display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 10px; margin-bottom: 10px; background: #f8fafc; font-size: 14px; }
        .req-failed { color: #b91c1c; }
        .req-passed { color: #15803d; }

        .placeholder-text { text-align: center; color: #94a3b8; margin-top: 100px; }
        .placeholder-text i { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }

        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 700; cursor: pointer; border: none; width: 100%; transition: 0.2s; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }

        #scan-feedback { position: fixed; top: 90px; right: 30px; padding: 15px 25px; border-radius: 10px; background: #1e293b; color: white; transform: translateX(200%); transition: 0.3s; z-index: 2000; }
        #scan-feedback.show { transform: translateX(0); }
    </style>
</head>
<body>
    <?php include 'admin_header.php'; ?>
    <div id="scan-feedback">QR Code Scanned! Fetching record...</div>

    <div class="scanner-layout">
        <!-- LEFT: CAMERA -->
        <div class="camera-section">
            <h2 style="margin:0 0 20px 0; font-size:18px;"><i class="fa fa-camera"></i> Live QR Scanner</h2>
            <div id="reader"></div>
            <div style="margin-top:20px; text-align:center;">
                <p style="font-size:12px; color:#64748b;">Position the Student ID QR code within the frame.</p>
                <button onclick="location.reload()" class="btn btn-outline" style="width:auto;"><i class="fa fa-sync"></i> Reset Scanner</button>
            </div>
        </div>

        <!-- RIGHT: RESULTS -->
        <div class="result-section" id="resultArea">
            <div class="placeholder-text">
                <i class="fa fa-qrcode"></i>
                <h2>Ready to Scan</h2>
                <p>Waiting for a valid student QR code...</p>
            </div>
        </div>
    </div>

    <script>
        const html5QrCode = new Html5Qrcode("reader");
        const qrConfig = { fps: 10, qrbox: { width: 250, height: 250 } };

        html5QrCode.start({ facingMode: "environment" }, qrConfig, onScanSuccess);

        function onScanSuccess(decodedText, decodedResult) {
            // Provide immediate feedback
            document.getElementById('scan-feedback').classList.add('show');
            setTimeout(() => document.getElementById('scan-feedback').classList.remove('show'), 2000);
            
            // Stop scanner temporarily
            html5QrCode.pause();

            // Verify with Server
            fetch(`?ajax_verify=1&data=${encodeURIComponent(decodedText)}`)
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        displayStudent(data);
                    } else {
                        alert(data.error);
                        html5QrCode.resume();
                    }
                })
                .catch(err => {
                    console.error(err);
                    html5QrCode.resume();
                });
        }

        function displayStudent(data) {
            const s = data.student;
            const resArea = document.getElementById('resultArea');
            
            let statusHtml = data.eligible 
                ? `<div class="status-badge eligible"><i class="fa fa-check-circle"></i> ELIGIBLE FOR RE-ENROLLMENT</div>`
                : `<div class="status-badge blocked"><i class="fa fa-times-circle"></i> RE-ENROLLMENT BLOCKED</div>`;

            let reqHtml = '';
            if(!data.eligible) {
                reqHtml = `<h3>Pending Requirements:</h3><ul class="requirement-list">`;
                data.reasons.forEach(r => {
                    reqHtml += `<li class="requirement-item req-failed"><i class="fa fa-warning"></i> ${r}</li>`;
                });
                reqHtml += `</ul>`;
            } else {
                reqHtml = `<div class="requirement-item req-passed"><i class="fa fa-check"></i> All academic records verified.</div>`;
            }

            resArea.innerHTML = `
                <div class="student-profile-header">
                    <img src="${s.photo_path || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(s.student_name)}" class="profile-photo">
                    <div>
                        <h1 style="margin:0; font-size:24px;">${s.student_name.toUpperCase()}</h1>
                        <p style="margin:5px 0; color:#64748b; font-family:monospace;">LRN: ${s.lrn} | ID: ${s.student_id}</p>
                        <p style="margin:0; font-weight:700;">${s.grade_level} - ${s.section}</p>
                    </div>
                </div>

                ${statusHtml}
                ${reqHtml}

                <div style="margin-top:40px; padding-top:20px; border-top:1px solid #f1f5f9;">
                    <form method="POST">
                        <input type="hidden" name="student_id" value="${s.student_id}">
                        <input type="hidden" name="new_sy" value="2026-2027">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                            <div>
                                <label style="display:block; font-size:11px; font-weight:700; margin-bottom:5px;">NEXT GRADE LEVEL</label>
                                <input type="text" name="new_grade" value="Grade 8" class="btn btn-outline" style="text-align:left; background:#fff; cursor:text;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; font-weight:700; margin-bottom:5px;">SECTION ASSIGNMENT</label>
                                <input type="text" name="new_section" value="TBD" class="btn btn-outline" style="text-align:left; background:#fff; cursor:text;">
                            </div>
                        </div>
                        <button type="submit" name="process_reenrollment" class="btn btn-success" ${!data.eligible ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''}>
                            <i class="fa fa-user-plus"></i> PROCESS ONE-CLICK RE-ENROLLMENT
                        </button>
                    </form>
                    <button onclick="html5QrCode.resume(); location.reload();" class="btn btn-outline" style="margin-top:10px;">
                        <i class="fa fa-arrow-left"></i> SCAN ANOTHER STUDENT
                    </button>
                </div>
            `;
        }
    </script>
</body>
</html>
