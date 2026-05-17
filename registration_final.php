<?php require_once __DIR__ . '/auth/auth.php';
auth_require_role(['registrar', 'admin', 'teacher']);

// Determine enrollment URL based on role
$role = $_SESSION['user']['role'] ?? $_SESSION['user_role'] ?? '';
$enrollmentUrl = url_for('/admin/enrollment.php'); // Default to admin
if ($role === 'registrar') {
    $enrollmentUrl = url_for('/registrar/enrollment.php');
} else if ($role === 'teacher')
    $enrollmentUrl = url_for('/teacher/enrollment.php');
?>
<?php
require_once __DIR__ . '/config/db.php';
$pdo = db_connect();
require_once __DIR__ . '/config/student_id_utility.php';

// Fetch current school year from settings
$stmt_sy = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'");
$stmt_sy->execute();
$current_sy = $stmt_sy->fetchColumn() ?: (date('Y') . '-' . (date('Y') + 1));
$sy_parts = explode('-', $current_sy);
$sy_start = $sy_parts[0] ?? date('Y');
$sy_end = $sy_parts[1] ?? (date('Y') + 1);

// Ensure registrations table has the missing column (Self-healing check)
try {
    $stmt_check = $pdo->query("SHOW COLUMNS FROM registrations LIKE 'id_contact_person'");
    if (!$stmt_check->fetch()) {
        $pdo->exec("ALTER TABLE registrations ADD COLUMN id_contact_person ENUM('father','mother','guardian') DEFAULT 'guardian' AFTER guardian_contact");
    }
} catch (Exception $e) { /* Already exists or background issue */ }

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db_connect();

    $fields = [
        // Header
        'school_year_start',
        'school_year_end',
        'grade_level_to_enroll',
        'with_lrn',
        'is_returning',
        // Learner information
        'psa_birth_cert_no',
        'lrn',
        'last_name',
        'first_name',
        'middle_name',
        'ext_name',
        'birthdate',
        'sex',
        'age',
        'birthplace_city',
        'birthplace_province',
        'mother_tongue',
        'is_ip',
        'ip_ethnicity',
        'is_4ps_beneficiary',
        'four_ps_household_id',
        'is_pwd',
        'disability_types',
        'religion',
        // Current address
        'curr_house_no',
        'curr_street',
        'curr_barangay',
        'curr_city',
        'curr_province',
        'curr_country',
        'curr_zip',
        // Permanent address
        'perm_same_as_current',
        'perm_house_no',
        'perm_street',
        'perm_barangay',
        'perm_city',
        'perm_province',
        'perm_country',
        'perm_zip',
        // Parents / Guardians
        'father_last',
        'father_first',
        'father_middle',
        'father_contact',
        'mother_last',
        'mother_first',
        'mother_middle',
        'mother_contact',
        'guardian_last',
        'guardian_first',
        'guardian_middle',
        'guardian_contact',
        'guardian_relationship',
        'id_contact_person',
        // Returnees / Transferees
        'last_grade_completed',
        'last_sy_completed',
        'last_school_attended',
        'last_school_id',
        // Senior High
        'semester',
        'track',
        'strand',
        // Learning modalities
        'preferred_modalities'
    ];

    $data = [];
    foreach ($fields as $f) {
        $data[$f] = isset($_POST[$f]) ? trim(is_array($_POST[$f]) ? implode(', ', $_POST[$f]) : (string) $_POST[$f]) : null;
    }

    // Coerce numeric/boolean flags
    $data['with_lrn'] = isset($_POST['with_lrn']) ? 1 : 0;
    $data['is_returning'] = isset($_POST['is_returning']) ? 1 : 0;
    $data['is_4ps_beneficiary'] = isset($_POST['is_4ps_beneficiary']) && $_POST['is_4ps_beneficiary'] === 'yes' ? 1 : 0;
    $data['is_pwd'] = isset($_POST['is_pwd']) ? 1 : 0;
    $data['perm_same_as_current'] = isset($_POST['perm_same_as_current']) ? 1 : 0;

    // Set approval status to approved by default (Automated Student Approval)
    $data['approval_status'] = 'approved';

    // If permanent same as current, copy values
    if ($data['perm_same_as_current']) {
        $data['perm_house_no'] = $data['curr_house_no'];
        $data['perm_street'] = $data['curr_street'];
        $data['perm_barangay'] = $data['curr_barangay'];
        $data['perm_city'] = $data['curr_city'];
        $data['perm_province'] = $data['curr_province'];
        $data['perm_country'] = $data['curr_country'];
        $data['perm_zip'] = $data['curr_zip'];
    }

    // Validate at least one parent/guardian is filled
    $hasParent = !empty($data['father_last']) || !empty($data['father_first'])
        || !empty($data['mother_last']) || !empty($data['mother_first'])
        || !empty($data['guardian_last']) || !empty($data['guardian_first']);
    if (!$hasParent) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('At least one parent/guardian information is required.'));
        exit;
    }

    // Validate LRN: must be exactly 12 digits if provided
    if (!empty($data['lrn']) && !preg_match('/^\d{12}$/', $data['lrn'])) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('LRN must be exactly 12 digits.'));
        exit;
    }

    // Validate Contact Numbers (Father, Mother, Guardian)
    $contactFields = ['father_contact', 'mother_contact', 'guardian_contact'];
    foreach ($contactFields as $cf) {
        if (!empty($data[$cf]) && !preg_match('/^09\d{9}$/', $data[$cf])) {
            $label = str_replace('_', ' ', ucwords($cf, '_'));
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode($label . ' must be exactly 11 digits and start with 09 (e.g., 09123456789).'));
            exit;
        }
    }

    $columns = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $columns);
    $sql = 'INSERT INTO registrations (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($data);
        $registration_id = $pdo->lastInsertId();
        header('Location: ' . url_for('/registration_final.php?success=1&id=' . $registration_id));
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate LRN)
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('A student with this LRN (' . $data['lrn'] . ') is already registered.'));
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode('Database error: ' . $e->getMessage()));
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration | Portal</title>
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
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
            color: #0f172a;
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

        .type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .type-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 40px;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            border: 1px solid transparent;
        }

        .type-card:hover {
            transform: translateY(-8px);
            border-color: #2563eb;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.1);
        }

        .type-icon {
            font-size: 48px;
            margin-bottom: 24px;
            background: #f1f5f9;
            width: 96px;
            height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 24px;
            transition: all 0.3s ease;
        }

        .type-card:hover .type-icon {
            background: #2563eb;
            color: white;
            transform: scale(1.1) rotate(-5deg);
        }

        .type-title { font-size: 22px; font-weight: 900; color: #1e293b; margin-bottom: 8px; }
        .type-desc { font-size: 14px; color: #64748b; font-weight: 500; line-height: 1.6; }

        .form-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 40px;
            margin-bottom: 32px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.02);
        }

        .form-card h2 {
            font-size: 14px;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin: 0 0 32px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-card h2::after { content: ''; height: 2px; background: #f1f5f9; flex: 1; border-radius: 2px; }

        .grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 24px; }
        .col-12 { grid-column: span 12; }
        .col-6 { grid-column: span 6; }
        .col-4 { grid-column: span 4; }
        .col-3 { grid-column: span 3; }
        .col-2 { grid-column: span 2; }

        .field { display: flex; flex-direction: column; gap: 10px; }
        .field label { font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
        
        .form-control {
            width: 100%;
            padding: 14px 20px;
            border: 2px solid #f1f5f9;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            background: #f8fafc;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
            box-shadow: 0 0 0 6px rgba(37,99,235,0.05);
        }

        .btn {
            background: #0f172a;
            color: white;
            padding: 16px 32px;
            border-radius: 18px;
            font-weight: 900;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); }
        .btn-primary { background: #2563eb; }
        .btn-secondary { background: #f1f5f9; color: #64748b; }

        .radio-grid { display: flex; gap: 12px; flex-wrap: wrap; }
        .radio-card {
            flex: 1;
            min-width: 120px;
            background: #f8fafc;
            border: 2px solid #f1f5f9;
            padding: 14px 20px;
            border-radius: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
        }
        .radio-card:hover { border-color: #cbd5e1; }
        .radio-card input { margin: 0; width: 18px; height: 18px; accent-color: #2563eb; }
        .radio-card span { font-size: 14px; font-weight: 700; color: #475569; }
        .radio-card.active { background: #eff6ff; border-color: #2563eb; }
        .radio-card.active span { color: #1d4ed8; }

        .alert { padding: 24px; border-radius: 20px; margin-bottom: 32px; font-weight: 600; display: flex; align-items: center; gap: 16px; }
        .alert-error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #dcfce7; color: #166534; }

        .table-card { padding: 0; overflow: hidden; }
        .table-header { padding: 32px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f8fafc; padding: 16px 24px; text-align: left; font-size: 11px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid #f1f5f9; }
        .data-table td { padding: 18px 24px; font-size: 14px; font-weight: 600; color: #1e293b; border-bottom: 1px solid #f1f5f9; }
        .data-table tr:hover td { background: rgba(37,99,235,0.02); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(8px); z-index: 10000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: white; border-radius: 32px; width: 100%; max-width: 800px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; animation: modalIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes modalIn { from { opacity: 0; transform: scale(0.95) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        
        .modal-header { padding: 32px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 20px; font-weight: 900; }
        .modal-body { padding: 32px; overflow-y: auto; }
    </style>
</head>
<body>
    <?php
    if ($role === 'admin') {
        require_once __DIR__ . '/admin/admin_header.php';
        require_once __DIR__ . '/admin/admin_sidebar.php';
    } elseif ($role === 'registrar') {
        require_once __DIR__ . '/header.php';
        require_once __DIR__ . '/registrar/registrar_side_panel.php';
    } elseif ($role === 'teacher') {
        require_once __DIR__ . '/teacher/teacher_header.php';
        require_once __DIR__ . '/teacher/teacher_side_panel.php';
    }
    ?>

    <div class="main-content">
        <div class="page-header">
            <h1>Learner Registration</h1>
        </div>

        <div id="registration-type-selection">
            <div class="type-grid">
                <div onclick="selectRegistrationType('new')" class="type-card">
                    <div class="type-icon">🆕</div>
                    <div class="type-title">New Learner</div>
                    <div class="type-desc">Fresh enrollment or transferee from another school.</div>
                </div>

                <a href="<?= $enrollmentUrl ?>" class="type-card">
                    <div class="type-icon">🎓</div>
                    <div class="type-title">Returning Student</div>
                    <div class="type-desc">For students who previously attended this institution.</div>
                </a>
            </div>
        </div>

        <div id="back-to-selection-container" style="display: none; margin-bottom: 32px;">
            <button onclick="showSelectionScreen()" class="btn btn-secondary">
                <span>←</span> Change Registration Type
            </button>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <span>⚠️</span> <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="form-card alert-success">
                <div style="flex: 1;">
                    <h3 style="margin: 0; font-size: 20px; font-weight: 900;">Registration Successful!</h3>
                    <p style="margin: 8px 0 0; font-weight: 600; opacity: 0.8;">The learner has been registered. You can now proceed to enrollment.</p>
                </div>
                <?php if (isset($_GET['id'])): ?>
                    <div style="margin-top: 32px; display: flex; flex-direction: column; align-items: center; gap: 24px;">
                        <div id="qrcode" style="padding: 24px; background: white; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
                        <div style="display: flex; gap: 16px;">
                            <a href="<?= $enrollmentUrl ?>" class="btn btn-primary">Proceed to Enrollment</a>
                            <a href="<?= url_for('/registration_final.php') ?>" class="btn btn-secondary">New Registration</a>
                        </div>
                    </div>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode/1.5.3/qrcode.min.js"></script>
                    <script>
                        const qrData = { registration_id: <?= intval($_GET['id']) ?>, timestamp: Date.now() };
                        QRCode.toCanvas(document.getElementById('qrcode'), JSON.stringify(qrData), {
                            width: 200, height: 200, colorDark: '#0f172a', colorLight: '#ffffff'
                        });
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div id="registrationFormContainer" style="display: none;">
            <form method="post" id="registration-form">
                <div class="form-card">
                    <h2>Academic Background</h2>
                    <div class="grid">
                        <div class="field col-3">
                            <label>SY (Start)</label>
                            <input type="number" class="form-control" name="school_year_start" value="<?= htmlspecialchars($sy_start) ?>" readonly>
                        </div>
                        <div class="field col-3">
                            <label>SY (End)</label>
                            <input type="number" class="form-control" name="school_year_end" value="<?= htmlspecialchars($sy_end) ?>" readonly>
                        </div>
                        <div class="field col-3">
                            <label>Grade Level</label>
                            <select class="form-control" name="grade_level_to_enroll" required>
                                <option value="">Select Grade</option>
                                <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
                                <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
                            </select>
                        </div>
                        <div class="field col-3">
                            <label>Registration Options</label>
                            <div class="radio-grid">
                                <label class="radio-card"><input type="checkbox" name="with_lrn"> <span>With LRN</span></label>
                                <label class="radio-card"><input type="checkbox" name="is_returning"> <span>Returning</span></label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <h2>Learner Information</h2>
                    <div class="grid">
                        <div class="field col-4"><label>PSA Birth Cert No.</label><input type="text" class="form-control" name="psa_birth_cert_no" required></div>
                        <div class="field col-4"><label>LRN (12 Digits)</label><input type="text" class="form-control" name="lrn" maxlength="12" pattern="\d{12}" required></div>
                        <div class="field col-4"><label>Date of Birth</label><input type="date" class="form-control" name="birthdate" required onchange="computeAge(this)"></div>
                        
                        <div class="field col-3"><label>Last Name</label><input type="text" class="form-control" name="last_name" required></div>
                        <div class="field col-3"><label>First Name</label><input type="text" class="form-control" name="first_name" required></div>
                        <div class="field col-3"><label>Middle Name</label><input type="text" class="form-control" name="middle_name"></div>
                        <div class="field col-3"><label>Ext. Name</label><input type="text" class="form-control" name="ext_name"></div>

                        <div class="field col-2">
                            <label>Sex</label>
                            <select class="form-control" name="sex" required>
                                <option value="">--</option><option>Male</option><option>Female</option>
                            </select>
                        </div>
                        <div class="field col-2"><label>Age</label><input type="number" id="age_field" class="form-control" name="age" readonly style="opacity: 0.6;"></div>
                        <div class="field col-4"><label>Birthplace (City)</label><input type="text" class="form-control" name="birthplace_city" required></div>
                        <div class="field col-4"><label>Birthplace (Province)</label><input type="text" class="form-control" name="birthplace_province" required></div>
                        
                        <div class="field col-4"><label>Mother Tongue</label><input type="text" class="form-control" name="mother_tongue" required></div>
                        <div class="field col-4"><label>Religion</label><input type="text" class="form-control" name="religion"></div>
                        <div class="field col-4">
                            <label>IP Community</label>
                            <div class="radio-grid">
                                <label class="radio-card"><input type="radio" name="is_ip" value="yes"> <span>Yes</span></label>
                                <label class="radio-card"><input type="radio" name="is_ip" value="no" checked> <span>No</span></label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <h2>Address Details</h2>
                    <div class="grid">
                        <div class="field col-12"><label class="radio-card" style="display:inline-flex; width:auto;"><input type="checkbox" name="perm_same_as_current"> <span>Permanent same as Current Address</span></label></div>
                        
                        <div class="field col-3"><label>Current House No.</label><input type="text" class="form-control" name="curr_house_no" required></div>
                        <div class="field col-3"><label>Street</label><input type="text" class="form-control" name="curr_street" required></div>
                        <div class="field col-3"><label>Barangay</label><input type="text" class="form-control" name="curr_barangay" required></div>
                        <div class="field col-3"><label>City</label><input type="text" class="form-control" name="curr_city" required></div>

                        <div class="field col-3"><label>Perm House No.</label><input type="text" class="form-control" name="perm_house_no"></div>
                        <div class="field col-3"><label>Perm Street</label><input type="text" class="form-control" name="perm_street"></div>
                        <div class="field col-3"><label>Perm Barangay</label><input type="text" class="form-control" name="perm_barangay"></div>
                        <div class="field col-3"><label>Perm City</label><input type="text" class="form-control" name="perm_city"></div>
                    </div>
                </div>

                <div class="form-card">
                    <h2>Guardian Information</h2>
                    <div class="grid">
                        <div class="field col-12">
                            <label>Primary Contact for ID</label>
                            <div class="radio-grid">
                                <label class="radio-card"><input type="radio" name="id_contact_person" value="father" required> <span>Father</span></label>
                                <label class="radio-card"><input type="radio" name="id_contact_person" value="mother"> <span>Mother</span></label>
                                <label class="radio-card"><input type="radio" name="id_contact_person" value="guardian"> <span>Guardian</span></label>
                            </div>
                        </div>
                        
                        <div class="field col-4"><label>Father's Last Name</label><input type="text" class="form-control parent-field" name="father_last"></div>
                        <div class="field col-4"><label>Father's First Name</label><input type="text" class="form-control parent-field" name="father_first"></div>
                        <div class="field col-4"><label>Contact No.</label><input type="tel" class="form-control" name="father_contact" placeholder="09XXXXXXXXX"></div>
                        
                        <div class="field col-4"><label>Mother's Maiden Last</label><input type="text" class="form-control parent-field" name="mother_last"></div>
                        <div class="field col-4"><label>Mother's Maiden First</label><input type="text" class="form-control parent-field" name="mother_first"></div>
                        <div class="field col-4"><label>Contact No.</label><input type="tel" class="form-control" name="mother_contact" placeholder="09XXXXXXXXX"></div>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 48px;">
                    <button type="submit" class="btn btn-primary">Complete Registration</button>
                </div>
            </form>
        </div>

        <div class="form-card table-card" style="margin-top: 48px;">
            <div class="table-header">
                <h2 style="margin:0;">Recent Registrations</h2>
                <div style="display:flex; gap:12px;">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search learners..." style="width:260px;">
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Learner Name</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="registrationTableBody">
                        <?php
                        $records = $pdo->query("SELECT * FROM registrations ORDER BY created_at DESC LIMIT 20")->fetchAll();
                        foreach($records as $r):
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($r['lrn']) ?></td>
                            <td><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?></td>
                            <td><?= htmlspecialchars($r['grade_level_to_enroll']) ?></td>
                            <td><span class="status-pill status-active">Registered</span></td>
                            <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                            <td><button class="btn btn-secondary" style="padding:6px 12px; font-size:11px;" onclick="viewRegistration(<?= $r['id'] ?>)">View</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Registration Details Modal -->
    <div id="viewModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Learner Registration Details</h3>
                <button onclick="closeModal()" style="background:none; border:none; color:#94a3b8; cursor:pointer;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Data injected here -->
            </div>
            <div style="padding: 24px; border-top: 1px solid #f1f5f9; text-align: right;">
                <button onclick="closeModal()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <script>
        function selectRegistrationType(type) {
            document.getElementById('registration-type-selection').style.display = 'none';
            document.getElementById('registrationFormContainer').style.display = 'block';
            document.getElementById('back-to-selection-container').style.display = 'block';
        }

        function showSelectionScreen() {
            document.getElementById('registration-type-selection').style.display = 'grid';
            document.getElementById('registrationFormContainer').style.display = 'none';
            document.getElementById('back-to-selection-container').style.display = 'none';
        }

        function computeAge(dateInput) {
            const ageField = document.getElementById('age_field');
            if (!dateInput.value) { ageField.value = ''; return; }
            const bday = new Date(dateInput.value);
            const today = new Date();
            let age = today.getFullYear() - bday.getFullYear();
            const m = today.getMonth() - bday.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < bday.getDate())) age--;
            ageField.value = age > 0 ? age : 0;
        }

        function viewRegistration(id) {
            const modal = document.getElementById('viewModal');
            const body = document.getElementById('modalBody');
            
            body.innerHTML = '<div style="text-align:center; padding:40px;"><div class="spinner"></div><p>Loading learner profile...</p></div>';
            modal.style.display = 'flex';

            fetch(`admin/get_registration.php?id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const r = data.registration;
                        document.getElementById('modalTitle').innerText = `${r.first_name} ${r.last_name}'s Registration`;
                        
                        body.innerHTML = `
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 32px;">
                                <div>
                                    <h4 style="margin:0 0 16px; font-size:12px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.05em;">Basic Information</h4>
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <div style="font-size:14px;"><span style="color:#64748b;">LRN:</span> <strong>${r.lrn}</strong></div>
                                        <div style="font-size:14px;"><span style="color:#64748b;">Grade Level:</span> <strong>${r.grade_level_to_enroll}</strong></div>
                                        <div style="font-size:14px;"><span style="color:#64748b;">Date of Birth:</span> <strong>${r.birthdate}</strong></div>
                                        <div style="font-size:14px;"><span style="color:#64748b;">Sex:</span> <strong>${r.sex}</strong></div>
                                        <div style="font-size:14px;"><span style="color:#64748b;">Address:</span> <strong>${r.curr_house_no} ${r.curr_street}, ${r.curr_barangay}, ${r.curr_city}</strong></div>
                                    </div>
                                </div>
                                <div>
                                    <h4 style="margin:0 0 16px; font-size:12px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.05em;">Guardian Details</h4>
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <div style="font-size:14px;"><span style="color:#64748b;">Father:</span> <strong>${r.father_first} ${r.father_last}</strong></div>
                                        <div style="font-size:14px;"><span style="color:#64748b;">Mother:</span> <strong>${r.mother_first} ${r.mother_last}</strong></div>
                                        <div style="font-size:14px;"><span style="color:#64748b;">Emergency Contact:</span> <strong>${r.id_contact_person === 'father' ? r.father_contact : (r.id_contact_person === 'mother' ? r.mother_contact : r.guardian_contact)}</strong></div>
                                    </div>
                                </div>
                            </div>
                            <div style="margin-top:32px; padding:20px; background:#f8fafc; border-radius:16px;">
                                <div style="font-size:12px; color:#64748b;">Registered on: <strong>${new Date(r.created_at).toLocaleString()}</strong></div>
                            </div>
                        `;
                    } else {
                        body.innerHTML = `<div class="alert alert-error">${data.message}</div>`;
                    }
                })
                .catch(err => {
                    body.innerHTML = `<div class="alert alert-error">Failed to fetch registration data.</div>`;
                });
        }

        function closeModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const q = e.target.value.toLowerCase();
            document.querySelectorAll('#registrationTableBody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        // Toggle Active Class for Radio Cards
        document.querySelectorAll('.radio-card input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const name = this.name;
                document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                    r.parentElement.classList.toggle('active', r.checked);
                });
            });
        });
    </script>
</body>
</html>