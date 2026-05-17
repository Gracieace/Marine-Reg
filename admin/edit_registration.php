<?php
/**
 * Edit Registration Form
 * 
 * This page allows administrators and registrars to edit existing student registrations.
 * It loads the registration data, displays it in a form, and handles updates.
 * 
 * Features:
 * - Loads existing registration data by ID
 * - Pre-fills all form fields with current values
 * - Handles form submission and database updates
 * - Includes validation and error handling
 * - Shows success/error messages
 * - Includes the ID contact person selection feature
 */

require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin', 'teacher']);
?>
<?php
require_once dirname(__DIR__) . '/config/db.php';

$registration_id = null;
$registration = null;
$error_message = '';
$success_message = '';

// Get registration ID from URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $registration_id = intval($_GET['id']);

    // Fetch existing registration data
    try {
        $pdo = db_connect();
        $sql = "SELECT * FROM registrations WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $registration_id, PDO::PARAM_INT);
        $stmt->execute();
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$registration) {
            $error_message = 'Registration not found.';
        }
    } catch (Exception $e) {
        $error_message = 'Error loading registration: ' . $e->getMessage();
    }
} else {
    $error_message = 'Invalid registration ID.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registration) {
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
        'religion',
        'is_4ps_beneficiary',
        'four_ps_household_id',
        'is_pwd',
        'disability_types',
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

    // Build UPDATE query
    $update_fields = [];
    $update_values = [];
    foreach ($data as $field => $value) {
        $update_fields[] = "`$field` = :$field";
        $update_values[$field] = $value;
    }
    $update_values['id'] = $registration_id;

    $sql = 'UPDATE registrations SET ' . implode(', ', $update_fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($update_values);
        $success_message = 'Registration updated successfully!';

        // Refresh registration data
        $sql = "SELECT * FROM registrations WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $registration_id, PDO::PARAM_INT);
        $stmt->execute();
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = 'Error updating registration: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Registration</title>
    <link rel="stylesheet" href="<?= url_for('/css/header.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f6f8fc;
            --card: #ffffff;
            --muted: #64748b;
            --border: #d7e0ee;
            --primary: #2563eb;
            --primary-600: #1d4ed8;
            --ring: #93c5fd;
            --success: #10b981;
            --error: #ef4444;
        }

        .content {
            padding: 24px;
            max-width: 1200px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        h1 {
            margin: 0;
            font-weight: 700;
        }

        .btn-secondary {
            background: #6b7280;
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 10px 18px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        form {
            display: block;
        }

        .form-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
        }

        .form-card h2 {
            margin: 0 0 14px 0;
            font-size: 16px;
            letter-spacing: .2px;
            color: #0f172a;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 12px;
        }

        .col-2 {
            grid-column: span 2;
        }

        .col-3 {
            grid-column: span 3;
        }

        .col-4 {
            grid-column: span 4;
        }

        .col-6 {
            grid-column: span 6;
        }

        .col-8 {
            grid-column: span 8;
        }

        .col-12 {
            grid-column: span 12;
        }

        .field {
            min-width: 0;
        }

        .field label {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
            font-weight: 500;
        }

        .field input[type="text"],
        .field input[type="date"],
        .field input[type="number"],
        .field select,
        .field textarea {
            width: 100%;
            max-width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            transition: box-shadow .15s ease, border-color .2s ease, background-color .2s ease;
            box-sizing: border-box;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: none;
            border-color: var(--ring);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .inline {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        label.inline {
            border: 1px solid var(--border);
            padding: 6px 10px;
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
            white-space: nowrap;
        }

        label.inline input {
            margin-right: 6px;
        }

        .actions {
            display: flex;
            justify-content: space-between;
            position: sticky;
            bottom: 0;
            padding-top: 6px;
        }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 10px 18px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn:hover {
            background: var(--primary-600);
        }

        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            background: #059669;
        }

        .note {
            font-size: 12px;
            color: #475569;
        }

        .hr {
            height: 1px;
            background: #e2e8f0;
            margin: 8px 0 16px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .error-page {
            text-align: center;
            padding: 60px 20px;
        }

        .error-page h1 {
            color: var(--error);
            margin-bottom: 16px;
        }

        .error-page p {
            color: var(--muted);
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .grid {
                grid-template-columns: repeat(8, 1fr);
            }

            .col-8 {
                grid-column: span 8;
            }

            .col-6 {
                grid-column: span 8;
            }

            .col-4 {
                grid-column: span 4;
            }

            .col-3 {
                grid-column: span 4;
            }

            .col-2 {
                grid-column: span 4;
            }
        }

        @media (max-width: 640px) {
            .grid {
                grid-template-columns: repeat(1, 1fr);
            }

            .col-12,
            .col-8,
            .col-6,
            .col-4,
            .col-3,
            .col-2 {
                grid-column: span 1;
            }

            .actions {
                position: static;
                flex-direction: column;
                gap: 12px;
            }
        }
    </style>
</head>

<body>
    <?php require_once dirname(__DIR__) . '/header.php'; ?>
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <div class="content">
        <div class="page-header">
            <h1>Edit Registration #<?= $registration_id ?></h1>
            <a href="<?= url_for('/registration_final.php') ?>" class="btn-secondary">Back to Registrations</a>
        </div>

        <?php if ($error_message && !$registration): ?>
            <div class="error-page">
                <h1>Error</h1>
                <p><?= htmlspecialchars($error_message) ?></p>
                <a href="<?= url_for('/registration_final.php') ?>" class="btn-secondary">Back to Registrations</a>
            </div>
        <?php else: ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($registration): ?>
                <form method="post" id="edit-registration-form" onsubmit="return confirmUpdate()">
                    <div class="form-card">
                        <h2>School Year and Enrollment</h2>
                        <div class="grid">
                            <div class="field col-3"><label>School Year (Start)</label><input type="number" id="sy_start"
                                    name="school_year_start" min="2000" max="2100"
                                    value="<?= htmlspecialchars($registration['school_year_start'] ?? '') ?>" required></div>
                            <div class="field col-3"><label>School Year (End)</label><input type="number" id="sy_end"
                                    name="school_year_end" min="2000" max="2100"
                                    value="<?= htmlspecialchars($registration['school_year_end'] ?? '') ?>" readonly></div>
                            <div class="field col-3"><label>Grade Level to Enroll</label>
                                <select name="grade_level_to_enroll" required>
                                    <option value="">-- Select Grade --</option>
                                    <option value="Grade 7" <?= ($registration['grade_level_to_enroll'] ?? '') === 'Grade 7' ? 'selected' : '' ?>>Grade 7</option>
                                    <option value="Grade 8" <?= ($registration['grade_level_to_enroll'] ?? '') === 'Grade 8' ? 'selected' : '' ?>>Grade 8</option>
                                    <option value="Grade 9" <?= ($registration['grade_level_to_enroll'] ?? '') === 'Grade 9' ? 'selected' : '' ?>>Grade 9</option>
                                    <option value="Grade 10" <?= ($registration['grade_level_to_enroll'] ?? '') === 'Grade 10' ? 'selected' : '' ?>>Grade 10</option>
                                    <option value="Grade 11" <?= ($registration['grade_level_to_enroll'] ?? '') === 'Grade 11' ? 'selected' : '' ?>>Grade 11</option>
                                    <option value="Grade 12" <?= ($registration['grade_level_to_enroll'] ?? '') === 'Grade 12' ? 'selected' : '' ?>>Grade 12</option>
                                </select>
                            </div>
                            <div class="field col-3">
                                <label>Options</label>
                                <div class="inline">
                                    <label class="inline"><input type="checkbox" name="with_lrn" <?= $registration['with_lrn'] ? 'checked' : '' ?>> With LRN</label>
                                    <label class="inline"><input type="checkbox" name="is_returning"
                                            <?= $registration['is_returning'] ? 'checked' : '' ?>> Returning (Balik-Aral)</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h2>Learner's Information</h2>
                        <div class="grid">
                            <div class="field col-4"><label>PSA Birth Cert No.(if available upon registration)</label><input
                                    type="text" name="psa_birth_cert_no"
                                    value="<?= htmlspecialchars($registration['psa_birth_cert_no'] ?? '') ?>"></div>
                            <div class="field col-4"><label>Learner Reference No. (LRN)</label><input type="text" name="lrn"
                                    value="<?= htmlspecialchars($registration['lrn'] ?? '') ?>"></div>
                            <div class="field col-4"><label>Date of Birth</label><input type="date" name="birthdate"
                                    value="<?= htmlspecialchars($registration['birthdate'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Last Name</label><input type="text" name="last_name"
                                    value="<?= htmlspecialchars($registration['last_name'] ?? '') ?>" required></div>
                            <div class="field col-3"><label>First Name</label><input type="text" name="first_name"
                                    value="<?= htmlspecialchars($registration['first_name'] ?? '') ?>" required></div>
                            <div class="field col-3"><label>Middle Name</label><input type="text" name="middle_name"
                                    value="<?= htmlspecialchars($registration['middle_name'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Extension Name</label><input type="text" name="ext_name"
                                    value="<?= htmlspecialchars($registration['ext_name'] ?? '') ?>"></div>
                            <div class="field col-2"><label>Sex</label>
                                <select name="sex">
                                    <option value="">--</option>
                                    <option value="Male" <?= ($registration['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male
                                    </option>
                                    <option value="Female" <?= ($registration['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>
                                        Female</option>
                                </select>
                            </div>
                            <div class="field col-2"><label>Age</label><input type="number" name="age" min="1"
                                    value="<?= htmlspecialchars($registration['age'] ?? '') ?>"></div>
                            <div class="field col-4"><label>Birthplace - Municipality/City</label><input type="text"
                                    name="birthplace_city"
                                    value="<?= htmlspecialchars($registration['birthplace_city'] ?? '') ?>"></div>
                            <div class="field col-4"><label>Birthplace - Province</label><input type="text"
                                    name="birthplace_province"
                                    value="<?= htmlspecialchars($registration['birthplace_province'] ?? '') ?>"></div>
                            <div class="field col-4"><label>Mother Tongue</label><input type="text" name="mother_tongue"
                                    value="<?= htmlspecialchars($registration['mother_tongue'] ?? '') ?>"></div>
                            <div class="field col-4">
                                <label>Belonging to any Indigenous People (IP) Community/Indigenous Culture Community</label>
                                <div class="inline">
                                    <label class="inline"><input type="radio" name="is_ip" value="yes"
                                            <?= ($registration['is_ip'] ?? '') === 'yes' ? 'checked' : '' ?>> Yes</label>
                                    <label class="inline"><input type="radio" name="is_ip" value="no" <?= ($registration['is_ip'] ?? '') === 'no' ? 'checked' : '' ?>> No</label>
                                </div>
                                <input type="text" name="ip_ethnicity" placeholder="If Yes, please specify:"
                                    value="<?= htmlspecialchars($registration['ip_ethnicity'] ?? '') ?>"
                                    style="margin-top: 8px; width: 100%;">
                            </div>
                            <div class="field col-4"><label>Religion</label><input type="text" name="religion" value="<?= htmlspecialchars($registration['religion'] ?? '') ?>"></div>
                            <div class="field col-4">
                                <label>Is your family a beneficiary of 4Ps?</label>
                                <div class="inline">
                                    <label class="inline"><input type="radio" name="is_4ps_beneficiary" value="yes"
                                            <?= $registration['is_4ps_beneficiary'] ? 'checked' : '' ?>> Yes</label>
                                    <label class="inline"><input type="radio" name="is_4ps_beneficiary" value="no"
                                            <?= !$registration['is_4ps_beneficiary'] ? 'checked' : '' ?>> No</label>
                                </div>
                                <input type="text" name="four_ps_household_id"
                                    placeholder="If Yes, write the 4Ps Household ID Number:"
                                    value="<?= htmlspecialchars($registration['four_ps_household_id'] ?? '') ?>"
                                    style="margin-top: 8px; width: 100%;">
                            </div>
                        </div>
                        <div class="hr"></div>
                        <div class="grid">
                            <div class="field col-12"><label class="inline"><input type="checkbox" name="is_pwd"
                                        <?= $registration['is_pwd'] ? 'checked' : '' ?>> Is the child a Learner with Disability
                                    (LWD)?</label></div>
                            <div class="field col-12"><label>If yes, specify the type(s) of disability</label><input type="text"
                                    name="disability_types" placeholder="e.g., Hearing Impairment, ADHD"
                                    value="<?= htmlspecialchars($registration['disability_types'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h2>Current Address</h2>
                        <div class="grid">
                            <div class="field col-3"><label>House No.</label><input type="text" name="curr_house_no"
                                    value="<?= htmlspecialchars($registration['curr_house_no'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Street</label><input type="text" name="curr_street"
                                    value="<?= htmlspecialchars($registration['curr_street'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Barangay</label><input type="text" name="curr_barangay"
                                    value="<?= htmlspecialchars($registration['curr_barangay'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Municipality/City</label><input type="text" name="curr_city"
                                    value="<?= htmlspecialchars($registration['curr_city'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Province</label><input type="text" name="curr_province"
                                    value="<?= htmlspecialchars($registration['curr_province'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Country</label><input type="text" name="curr_country"
                                    value="<?= htmlspecialchars($registration['curr_country'] ?? 'Philippines') ?>"></div>
                            <div class="field col-3"><label>Zip Code</label><input type="text" name="curr_zip"
                                    value="<?= htmlspecialchars($registration['curr_zip'] ?? '') ?>"></div>
                        </div>
                        <div class="hr"></div>
                        <h2>Permanent Address</h2>
                        <div class="grid">
                            <div class="field col-12"><label class="inline"><input type="checkbox" name="perm_same_as_current"
                                        <?= $registration['perm_same_as_current'] ? 'checked' : '' ?>> Same with your current
                                    address?</label></div>
                            <div class="field col-3"><label>House No.</label><input type="text" name="perm_house_no"
                                    value="<?= htmlspecialchars($registration['perm_house_no'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Street</label><input type="text" name="perm_street"
                                    value="<?= htmlspecialchars($registration['perm_street'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Barangay</label><input type="text" name="perm_barangay"
                                    value="<?= htmlspecialchars($registration['perm_barangay'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Municipality/City</label><input type="text" name="perm_city"
                                    value="<?= htmlspecialchars($registration['perm_city'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Province</label><input type="text" name="perm_province"
                                    value="<?= htmlspecialchars($registration['perm_province'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Country</label><input type="text" name="perm_country"
                                    value="<?= htmlspecialchars($registration['perm_country'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Zip Code</label><input type="text" name="perm_zip"
                                    value="<?= htmlspecialchars($registration['perm_zip'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h2>Parents / Guardian Information</h2>
                        <div class="grid">
                            <div class="field col-12">
                                <label>Who will be put on the student's ID?</label>
                                <div class="inline">
                                    <label class="inline"><input type="radio" name="id_contact_person" value="father"
                                            <?= ($registration['id_contact_person'] ?? '') === 'father' ? 'checked' : '' ?>>
                                        Father</label>
                                    <label class="inline"><input type="radio" name="id_contact_person" value="mother"
                                            <?= ($registration['id_contact_person'] ?? '') === 'mother' ? 'checked' : '' ?>>
                                        Mother</label>
                                    <label class="inline"><input type="radio" name="id_contact_person" value="guardian"
                                            <?= ($registration['id_contact_person'] ?? '') === 'guardian' ? 'checked' : '' ?>>
                                        Guardian</label>
                                </div>
                                <div class="note">Select the person whose information will appear on the student's ID card.
                                </div>
                            </div>
                        </div>
                        <div class="hr"></div>
                        <div class="grid">
                            <div class="field col-3"><label>Father's Last Name</label><input type="text" name="father_last"
                                    value="<?= htmlspecialchars($registration['father_last'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Father's First Name</label><input type="text" name="father_first"
                                    value="<?= htmlspecialchars($registration['father_first'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Father's Middle Name</label><input type="text" name="father_middle"
                                    value="<?= htmlspecialchars($registration['father_middle'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Father Contact No.</label><input type="text" name="father_contact"
                                    value="<?= htmlspecialchars($registration['father_contact'] ?? '') ?>"></div>

                            <div class="field col-3"><label>Mother's Maiden Last Name</label><input type="text"
                                    name="mother_last" value="<?= htmlspecialchars($registration['mother_last'] ?? '') ?>">
                            </div>
                            <div class="field col-3"><label>Mother's Maiden First Name</label><input type="text"
                                    name="mother_first" value="<?= htmlspecialchars($registration['mother_first'] ?? '') ?>">
                            </div>
                            <div class="field col-3"><label>Mother's Maiden Middle Name</label><input type="text"
                                    name="mother_middle" value="<?= htmlspecialchars($registration['mother_middle'] ?? '') ?>">
                            </div>
                            <div class="field col-3"><label>Mother Contact No.</label><input type="text" name="mother_contact"
                                    value="<?= htmlspecialchars($registration['mother_contact'] ?? '') ?>"></div>

                            <div class="field col-3"><label>Legal Guardian Last Name</label><input type="text"
                                    name="guardian_last" value="<?= htmlspecialchars($registration['guardian_last'] ?? '') ?>">
                            </div>
                            <div class="field col-3"><label>Legal Guardian First Name</label><input type="text"
                                    name="guardian_first"
                                    value="<?= htmlspecialchars($registration['guardian_first'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Legal Guardian Middle Name</label><input type="text"
                                    name="guardian_middle"
                                    value="<?= htmlspecialchars($registration['guardian_middle'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Guardian Relationship</label><input type="text" name="guardian_relationship" value="<?= htmlspecialchars($registration['guardian_relationship'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Guardian Contact No.</label><input type="text"
                                    name="guardian_contact"
                                    value="<?= htmlspecialchars($registration['guardian_contact'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h2>For Returnees and Transferees</h2>
                        <div class="grid">
                            <div class="field col-3"><label>Last Grade Level Completed</label><input type="text"
                                    name="last_grade_completed"
                                    value="<?= htmlspecialchars($registration['last_grade_completed'] ?? '') ?>"></div>
                            <div class="field col-3"><label>Last School Year Completed</label><input type="text"
                                    name="last_sy_completed"
                                    value="<?= htmlspecialchars($registration['last_sy_completed'] ?? '') ?>"></div>
                            <div class="field col-4"><label>Last School Attended</label><input type="text"
                                    name="last_school_attended"
                                    value="<?= htmlspecialchars($registration['last_school_attended'] ?? '') ?>"></div>
                            <div class="field col-2"><label>School ID</label><input type="text" name="last_school_id"
                                    value="<?= htmlspecialchars($registration['last_school_id'] ?? '') ?>"></div>
                        </div>
                        <div class="hr"></div>
                        <h2>For Learners in Senior High School</h2>
                        <div class="grid">
                            <div class="field col-2"><label>Semester</label>
                                <select name="semester">
                                    <option value="">--</option>
                                    <option value="1st" <?= ($registration['semester'] ?? '') === '1st' ? 'selected' : '' ?>>1st
                                    </option>
                                    <option value="2nd" <?= ($registration['semester'] ?? '') === '2nd' ? 'selected' : '' ?>>2nd
                                    </option>
                                </select>
                            </div>
                            <div class="field col-4"><label>Track</label><input type="text" name="track"
                                    value="<?= htmlspecialchars($registration['track'] ?? '') ?>"></div>
                            <div class="field col-6"><label>Strand</label><input type="text" name="strand"
                                    value="<?= htmlspecialchars($registration['strand'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h2>Distance Learning Preferences</h2>
                        <div class="grid">
                            <div class="field col-12"><label>If the school will implement other distance learning modalities
                                    aside from face-to-face instruction, what would you prefer for your child? (Choose all that
                                    apply)</label>
                                <input type="text" name="preferred_modalities"
                                    placeholder="e.g., Modular (Print), Online, Radio-Based, Blended"
                                    value="<?= htmlspecialchars($registration['preferred_modalities'] ?? '') ?>">
                                <div class="note">Tip: Separate multiple options with commas.</div>
                            </div>
                        </div>
                    </div>

                    <div class="actions">
                        <a href="<?= url_for('/registration_final.php') ?>" class="btn-secondary">Cancel</a>
                        <button class="btn btn-success" type="submit">Update Registration</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Auto-sync school year end with start
        (function () {
            var startEl = document.getElementById('sy_start');
            var endEl = document.getElementById('sy_end');

            function syncEnd() {
                var s = parseInt(startEl.value, 10);
                if (!isNaN(s)) endEl.value = s + 1;
            }

            startEl.addEventListener('input', syncEnd);
        })();

        // Handle permanent address same as current
        (function () {
            var checkbox = document.querySelector('input[name="perm_same_as_current"]');
            if (!checkbox) return;

            var fields = ['house_no', 'street', 'barangay', 'city', 'province', 'country', 'zip'];

            function copyCurrentToPermanent() {
                fields.forEach(function (f) {
                    var curr = document.querySelector('input[name="curr_' + f + '"]');
                    var perm = document.querySelector('input[name="perm_' + f + '"]');
                    if (curr && perm) {
                        perm.value = curr.value;
                        perm.readOnly = true;
                        perm.style.background = '#f1f5f9';
                    }
                });
            }

            function clearPermanent() {
                fields.forEach(function (f) {
                    var perm = document.querySelector('input[name="perm_' + f + '"]');
                    if (perm) {
                        perm.value = '';
                        perm.readOnly = false;
                        perm.style.background = '';
                    }
                });
            }

            checkbox.addEventListener('change', function () {
                if (this.checked) {
                    copyCurrentToPermanent();
                } else {
                    clearPermanent();
                }
            });

            // Live-sync current address changes to permanent when checkbox is checked
            fields.forEach(function (f) {
                var curr = document.querySelector('input[name="curr_' + f + '"]');
                if (curr) {
                    curr.addEventListener('input', function () {
                        if (checkbox.checked) {
                            var perm = document.querySelector('input[name="perm_' + f + '"]');
                            if (perm) perm.value = curr.value;
                        }
                    });
                }
            });

            // If checkbox is already checked on page load, lock permanent fields
            if (checkbox.checked) {
                copyCurrentToPermanent();
            }
        })();

        // Confirmation dialog before updating
        function confirmUpdate() {
            return confirm('Are you sure you want to update this registration? This action cannot be undone.');
        }
    </script>
</body>

</html>