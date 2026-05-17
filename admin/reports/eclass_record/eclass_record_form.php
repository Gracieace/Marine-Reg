<?php
require_once __DIR__ . '/../../../auth/auth.php';
auth_require_role(['registrar', 'admin']);
require_once __DIR__ . '/../../../config/db.php';

// Initialize PDO
$pdo = db_connect();

// Ensure schema exists
// Ensure schema exists
initialize_schema($pdo);

// Handle Template Download
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="eclass_record_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ADVISER_NAME', 'GRADE', 'SECTION', 'SCHOOL_YEAR']);
    // Add a sample row
    fputcsv($output, ['Juan Dela Cruz', 'Grade 7', 'Daisy', '2024-2025']);
    fclose($output);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Delete
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = $_POST['id'] ?? '';
        if ($id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM eclass_records WHERE id = ?");
                $stmt->execute([$id]);
                $success_message = "Record deleted successfully!";
            } catch (PDOException $e) {
                $error_message = "Error deleting record: " . $e->getMessage();
            }
        }
    }
    // Handle CSV Import
    elseif (isset($_POST['action']) && $_POST['action'] === 'import_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                $row = 0;
                $imported = 0;
                $errors = 0;

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row++;
                    // Skip header
                    if ($row == 1)
                        continue;

                    // Format: ADVISER_NAME, GRADE, SECTION, SCHOOL_YEAR
                    $adviser_name = $data[0] ?? '';
                    $grade = $data[1] ?? '';
                    $section = $data[2] ?? '';
                    $school_year = $data[3] ?? '';

                    if ($grade && $section && $school_year) {
                        try {
                            $enrollment_data = calculateEnrollmentData($pdo, $grade, $section, $school_year);

                            $sql = "INSERT INTO eclass_records (
                                    adviser_name, grade, section, school_year,
                                    enrollment_bosy_male, enrollment_bosy_female, enrollment_bosy_total,
                                    enrollment_q1_male, enrollment_q1_female, enrollment_q1_total,
                                    enrollment_q2_male, enrollment_q2_female, enrollment_q2_total,
                                    enrollment_q3_male, enrollment_q3_female, enrollment_q3_total,
                                    enrollment_q4_male, enrollment_q4_female, enrollment_q4_total,
                                    dropped_bosy_male, dropped_bosy_female, dropped_bosy_total,
                                    dropped_q1_male, dropped_q1_female, dropped_q1_total,
                                    dropped_q2_male, dropped_q2_female, dropped_q2_total,
                                    dropped_q3_male, dropped_q3_female, dropped_q3_total,
                                    dropped_q4_male, dropped_q4_female, dropped_q4_total,
                                    balik_aral_male, balik_aral_female, balik_aral_total,
                                    transferred_in_male, transferred_in_female, transferred_in_total,
                                    transferred_out_male, transferred_out_female, transferred_out_total,
                                    created_by
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    adviser_name = VALUES(adviser_name),
                                    enrollment_bosy_male = VALUES(enrollment_bosy_male),
                                    enrollment_bosy_female = VALUES(enrollment_bosy_female),
                                    enrollment_bosy_total = VALUES(enrollment_bosy_total),
                                    enrollment_q1_male = VALUES(enrollment_q1_male),
                                    enrollment_q1_female = VALUES(enrollment_q1_female),
                                    enrollment_q1_total = VALUES(enrollment_q1_total),
                                    enrollment_q2_male = VALUES(enrollment_q2_male),
                                    enrollment_q2_female = VALUES(enrollment_q2_female),
                                    enrollment_q2_total = VALUES(enrollment_q2_total),
                                    enrollment_q3_male = VALUES(enrollment_q3_male),
                                    enrollment_q3_female = VALUES(enrollment_q3_female),
                                    enrollment_q3_total = VALUES(enrollment_q3_total),
                                    enrollment_q4_male = VALUES(enrollment_q4_male),
                                    enrollment_q4_female = VALUES(enrollment_q4_female),
                                    enrollment_q4_total = VALUES(enrollment_q4_total),
                                    updated_at = CURRENT_TIMESTAMP";

                            $stmt = $pdo->prepare($sql);
                            // We construct the params array carefully
                            $params = [
                                $adviser_name,
                                $grade,
                                $section,
                                $school_year,
                                $enrollment_data['bosy_male'],
                                $enrollment_data['bosy_female'],
                                $enrollment_data['bosy_total'],
                                $enrollment_data['q1_male'],
                                $enrollment_data['q1_female'],
                                $enrollment_data['q1_total'],
                                $enrollment_data['q2_male'],
                                $enrollment_data['q2_female'],
                                $enrollment_data['q2_total'],
                                $enrollment_data['q3_male'],
                                $enrollment_data['q3_female'],
                                $enrollment_data['q3_total'],
                                $enrollment_data['q4_male'],
                                $enrollment_data['q4_female'],
                                $enrollment_data['q4_total'],
                                $enrollment_data['dropped_bosy_male'],
                                $enrollment_data['dropped_bosy_female'],
                                $enrollment_data['dropped_bosy_total'],
                                $enrollment_data['dropped_q1_male'],
                                $enrollment_data['dropped_q1_female'],
                                $enrollment_data['dropped_q1_total'],
                                $enrollment_data['dropped_q2_male'],
                                $enrollment_data['dropped_q2_female'],
                                $enrollment_data['dropped_q2_total'],
                                $enrollment_data['dropped_q3_male'],
                                $enrollment_data['dropped_q3_female'],
                                $enrollment_data['dropped_q3_total'],
                                $enrollment_data['dropped_q4_male'],
                                $enrollment_data['dropped_q4_female'],
                                $enrollment_data['dropped_q4_total'],
                                $enrollment_data['balik_aral_male'],
                                $enrollment_data['balik_aral_female'],
                                $enrollment_data['balik_aral_total'],
                                $enrollment_data['transferred_in_male'],
                                $enrollment_data['transferred_in_female'],
                                $enrollment_data['transferred_in_total'],
                                $enrollment_data['transferred_out_male'],
                                $enrollment_data['transferred_out_female'],
                                $enrollment_data['transferred_out_total'],
                                $_SESSION['user_name'] ?? 'Admin'
                            ];

                            $stmt->execute($params);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors++;
                        }
                    }
                }
                fclose($handle);
                $success_message = "Import complete. Imported: $imported, Errors: $errors";
            }
        } else {
            $error_message = "Please upload a valid CSV file.";
        }
    }
    // Handle Save/Update
    else {
        $adviser_name = $_POST['adviser_name'] ?? '';
        $grade = $_POST['grade'] ?? '';
        $section = $_POST['section'] ?? '';
        $school_year = $_POST['school_year'] ?? '';

        if ($adviser_name && $grade && $section && $school_year) {
            // Calculate enrollment data from school_forms table
            $enrollment_data = calculateEnrollmentData($pdo, $grade, $section, $school_year);

            // Insert or update eclass record
            $sql = "INSERT INTO eclass_records (
                    adviser_name, grade, section, school_year,
                    enrollment_bosy_male, enrollment_bosy_female, enrollment_bosy_total,
                    enrollment_q1_male, enrollment_q1_female, enrollment_q1_total,
                    enrollment_q2_male, enrollment_q2_female, enrollment_q2_total,
                    enrollment_q3_male, enrollment_q3_female, enrollment_q3_total,
                    enrollment_q4_male, enrollment_q4_female, enrollment_q4_total,
                    dropped_bosy_male, dropped_bosy_female, dropped_bosy_total,
                    dropped_q1_male, dropped_q1_female, dropped_q1_total,
                    dropped_q2_male, dropped_q2_female, dropped_q2_total,
                    dropped_q3_male, dropped_q3_female, dropped_q3_total,
                    dropped_q4_male, dropped_q4_female, dropped_q4_total,
                    balik_aral_male, balik_aral_female, balik_aral_total,
                    transferred_in_male, transferred_in_female, transferred_in_total,
                    transferred_out_male, transferred_out_female, transferred_out_total,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enrollment_bosy_male = VALUES(enrollment_bosy_male),
                    enrollment_bosy_female = VALUES(enrollment_bosy_female),
                    enrollment_bosy_total = VALUES(enrollment_bosy_total),
                    enrollment_q1_male = VALUES(enrollment_q1_male),
                    enrollment_q1_female = VALUES(enrollment_q1_female),
                    enrollment_q1_total = VALUES(enrollment_q1_total),
                    enrollment_q2_male = VALUES(enrollment_q2_male),
                    enrollment_q2_female = VALUES(enrollment_q2_female),
                    enrollment_q2_total = VALUES(enrollment_q2_total),
                    enrollment_q3_male = VALUES(enrollment_q3_male),
                    enrollment_q3_female = VALUES(enrollment_q3_female),
                    enrollment_q3_total = VALUES(enrollment_q3_total),
                    enrollment_q4_male = VALUES(enrollment_q4_male),
                    enrollment_q4_female = VALUES(enrollment_q4_female),
                    enrollment_q4_total = VALUES(enrollment_q4_total),
                    updated_at = CURRENT_TIMESTAMP";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $adviser_name,
                    $grade,
                    $section,
                    $school_year,
                    $enrollment_data['bosy_male'],
                    $enrollment_data['bosy_female'],
                    $enrollment_data['bosy_total'],
                    $enrollment_data['q1_male'],
                    $enrollment_data['q1_female'],
                    $enrollment_data['q1_total'],
                    $enrollment_data['q2_male'],
                    $enrollment_data['q2_female'],
                    $enrollment_data['q2_total'],
                    $enrollment_data['q3_male'],
                    $enrollment_data['q3_female'],
                    $enrollment_data['q3_total'],
                    $enrollment_data['q4_male'],
                    $enrollment_data['q4_female'],
                    $enrollment_data['q4_total'],
                    $enrollment_data['dropped_bosy_male'],
                    $enrollment_data['dropped_bosy_female'],
                    $enrollment_data['dropped_bosy_total'],
                    $enrollment_data['dropped_q1_male'],
                    $enrollment_data['dropped_q1_female'],
                    $enrollment_data['dropped_q1_total'],
                    $enrollment_data['dropped_q2_male'],
                    $enrollment_data['dropped_q2_female'],
                    $enrollment_data['dropped_q2_total'],
                    $enrollment_data['dropped_q3_male'],
                    $enrollment_data['dropped_q3_female'],
                    $enrollment_data['dropped_q3_total'],
                    $enrollment_data['dropped_q4_male'],
                    $enrollment_data['dropped_q4_female'],
                    $enrollment_data['dropped_q4_total'],
                    $enrollment_data['balik_aral_male'],
                    $enrollment_data['balik_aral_female'],
                    $enrollment_data['balik_aral_total'],
                    $enrollment_data['transferred_in_male'],
                    $enrollment_data['transferred_in_female'],
                    $enrollment_data['transferred_in_total'],
                    $enrollment_data['transferred_out_male'],
                    $enrollment_data['transferred_out_female'],
                    $enrollment_data['transferred_out_total'],
                    $_SESSION['user_name'] ?? 'Admin' // Use session user name if available
                ]);

                $success_message = "eClass Record saved successfully!";
            } catch (PDOException $e) {
                $error_message = "Error saving record: " . $e->getMessage();
            }
        } else {
            $error_message = "Please fill in all required fields.";
        }
    }
}

// Get existing records
$records = getEclassRecords($pdo);

// Get list of teachers for dropdown
$teachers = getTeachers($pdo);

// Get list of existing sections for suggestions
$sections = getSections($pdo);


function calculateEnrollmentData($pdo, $grade, $section, $school_year)
{
    // First, sync school_forms with enrollments to ensure we have the latest data
    syncSchoolFormsData($pdo, $grade, $section, $school_year);

    // Get enrollment data from school_forms table
    // Note: This matches the schema found in config/db.php
    $sql = "SELECT 
                COUNT(CASE WHEN sex = 'M' THEN 1 END) as male_count,
                COUNT(CASE WHEN sex = 'F' THEN 1 END) as female_count,
                COUNT(*) as total_count,
                COUNT(CASE WHEN status = 'Dropped' THEN 1 END) as dropped_count,
                COUNT(CASE WHEN status = 'Transferred' THEN 1 END) as transferred_count
            FROM school_forms 
            WHERE grade_level = ? AND section = ? AND school_year = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$grade, $section, $school_year]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    // Default to 0 if no data
    $male = $data['male_count'] ?? 0;
    $female = $data['female_count'] ?? 0;
    $total = $data['total_count'] ?? 0;

    // For now, we populate all quarters with the current count
    // In a real scenario, you might want to snapshot this data or have separate tables for quarterly stats
    return [
        'bosy_male' => $male,
        'bosy_female' => $female,
        'bosy_total' => $total,
        'q1_male' => $male,
        'q1_female' => $female,
        'q1_total' => $total,
        'q2_male' => $male,
        'q2_female' => $female,
        'q2_total' => $total,
        'q3_male' => $male,
        'q3_female' => $female,
        'q3_total' => $total,
        'q4_male' => $male,
        'q4_female' => $female,
        'q4_total' => $total,

        // Dropped / Transferred - logic can be refined if tracking specific dates
        'dropped_bosy_male' => 0,
        'dropped_bosy_female' => 0,
        'dropped_bosy_total' => 0,
        'dropped_q1_male' => 0,
        'dropped_q1_female' => 0,
        'dropped_q1_total' => 0,
        'dropped_q2_male' => 0,
        'dropped_q2_female' => 0,
        'dropped_q2_total' => 0,
        'dropped_q3_male' => 0,
        'dropped_q3_female' => 0,
        'dropped_q3_total' => 0,
        'dropped_q4_male' => 0,
        'dropped_q4_female' => 0,
        'dropped_q4_total' => 0,

        'balik_aral_male' => 0,
        'balik_aral_female' => 0,
        'balik_aral_total' => 0,
        'transferred_in_male' => 0,
        'transferred_in_female' => 0,
        'transferred_in_total' => 0,
        'transferred_out_male' => 0,
        'transferred_out_female' => 0,
        'transferred_out_total' => 0
    ];
}

function syncSchoolFormsData($pdo, $grade, $section, $school_year)
{
    // Check if data exists in school_forms
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM school_forms WHERE grade_level = ? AND section = ? AND school_year = ?");
    $stmt->execute([$grade, $section, $school_year]);
    $count = $stmt->fetchColumn();

    // If no data, populate from enrollments
    if ($count == 0) {
        // We join enrollments and registrations to get sex and name
        $sql = "INSERT INTO school_forms (student_id, lrn, last_name, first_name, sex, grade_level, section, school_year, status)
                SELECT 
                    e.student_id, 
                    e.lrn, 
                    r.last_name, 
                    r.first_name, 
                    r.sex, 
                    e.grade_level, 
                    e.section, 
                    e.school_year, 
                    'Active'
                FROM enrollments e
                JOIN registrations r ON e.registration_id = r.id
                WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$grade, $section, $school_year]);
    }
}

function getEclassRecords($pdo)
{
    // Ensure table exists before querying (double check)
    try {
        $sql = "SELECT * FROM eclass_records ORDER BY grade, section, adviser_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getTeachers($pdo)
{
    try {
        $sql = "SELECT first_name, last_name FROM teachers ORDER BY last_name, first_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback or empty if table doesn't exist
        return [];
    }
}

function getSections($pdo)
{
    try {
        $sql = "SELECT DISTINCT section FROM school_forms ORDER BY section";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Assignment</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d47a1;
            --secondary-color: #1976d2;
            --accent-color: #ffca28;
            --text-color: #333;
            --bg-color: #f4f6f9;
            --border-color: #dee2e6;
            --table-header-bg: #e3f2fd;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
        }

        /* ── Main Layout ── */
        .main-content {
            margin-top: var(--header-height);
            padding: 20px;
            transition: margin-left 0.25s ease;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-top: 88px;
                padding: 15px;
            }
        }

        /* ── Page Header ── */
        .page-header {
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            margin: 0 0 5px 0;
            font-size: 24px;
            font-weight: 700;
        }

        .header-title p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }

        /* ── Cards ── */
        .card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }

        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            color: #1a1a1a;
            font-size: 18px;
            font-weight: 600;
        }

        /* ── Forms ── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 13px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        /* ── Buttons ── */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 4px 6px rgba(13, 71, 161, 0.15);
        }

        .btn-primary:hover {
            background: #0b3d91;
            box-shadow: 0 6px 8px rgba(13, 71, 161, 0.2);
        }

        .btn-secondary {
            background: white;
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #f8f9fa;
            border-color: #c1c9d0;
        }

        /* ── Message ── */
        .status-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .status-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* ── Table ── */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 0 0 12px 12px;
            border: 1px solid var(--border-color);
            border-top: none;
        }

        /* Table Scrollbar styling */
        .table-wrapper::-webkit-scrollbar {
            height: 8px;
        }

        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-wrapper::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .modern-table th,
        .modern-table td {
            border: 1px solid #e0e0e0;
            padding: 10px 12px;
            text-align: center;
            white-space: nowrap;
        }

        .modern-table th {
            background-color: var(--table-header-bg);
            color: var(--primary-color);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .modern-table tbody tr:hover {
            background-color: #f8fbff;
        }

        /* Sticky Columns */
        .modern-table th:first-child,
        .modern-table td:first-child {
            position: sticky;
            left: 0;
            background-color: white;
            z-index: 5;
            border-right: 2px solid #e0e0e0;
        }

        .modern-table th:first-child {
            background-color: var(--table-header-bg);
            z-index: 6;
        }

        /* Header Groups styling */
        .header-group-enrollment {
            background: #e3f2fd;
        }

        .header-group-dropped {
            background: #ffebee;
        }

        .header-group-transferred {
            background: #e8f5e9;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* ── Modern Modal ── */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            transition: opacity 0.3s ease;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 0;
            border: none;
            width: 100%;
            max-width: 450px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            animation: modalSlideIn 0.3s ease;
            position: relative;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .modal-close {
            color: #aaa;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }

        .modal-close:hover {
            color: #555;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            background-color: #f8f9fa;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-radius: 0 0 16px 16px;
        }

        /* Styled File Input */
        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 120px;
            border: 2px dashed #e0e0e0;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: border-color 0.2s;
            background: #fafafa;
        }

        .file-upload-wrapper:hover {
            border-color: var(--secondary-color);
            background: #f0f7ff;
        }

        .file-upload-wrapper input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-upload-icon {
            font-size: 32px;
            color: var(--secondary-color);
            margin-bottom: 8px;
        }

        .file-upload-text {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .file-subtext {
            color: #999;
            font-size: 12px;
            margin-top: 4px;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../../admin_header.php'; ?>
    <?php require_once __DIR__ . '/../../admin_sidebar.php'; ?>

    <div class="content main-content">
        <div class="page-header">
            <div class="header-title">
                <h1>Adviser Assignment</h1>
                <p>Student enrollment and status tracking by adviser</p>
            </div>

        </div>

        <?php if (isset($success_message)): ?>
            <div class="status-message status-success">
                ✅ <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="status-message status-error">
                ⚠️ <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <!-- Entry Form Card -->
        <div class="card">
            <div class="card-header">
                <h3>Add New Record</h3>
                <div style="display: flex; gap: 10px;">
                    <a href="?action=download_template" class="btn btn-secondary" style="font-size: 13px;">
                        📥 Download Template
                    </a>
                    <button type="button" class="btn btn-primary" onclick="openImportModal()" style="font-size: 13px;">
                        📤 Import CSV
                    </button>
                </div>
            </div>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="adviser_name">Adviser's Name</label>
                        <input list="teachers" id="adviser_name" name="adviser_name" class="form-control"
                            placeholder="Type or select adviser..." required autocomplete="off">
                        <datalist id="teachers">
                            <?php foreach ($teachers as $teacher): ?>
                                <option
                                    value="<?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label for="grade">Grade Level</label>
                        <select id="grade" name="grade" class="form-control" required>
                            <option value="">Select Grade</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="section">Section</label>
                        <input list="sections" id="section" name="section" class="form-control"
                            placeholder="Type or select section..." required autocomplete="off">
                        <datalist id="sections">
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="school_year">School Year</label>
                        <input type="text" id="school_year" name="school_year" class="form-control"
                            value="<?= $_SESSION['current_school_year'] ?? date('Y') . '-' . (date('Y') + 1) ?>"
                            required>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            💾 Save Record
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Records Table Card -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="card-header" style="margin: 0; padding: 20px; border-bottom: 1px solid var(--border-color);">
                <h3>Existing Records</h3>
            </div>

            <?php if (!empty($records)): ?>
                <div class="table-wrapper">
                    <table class="modern-table">
                        <thead>
                            <tr>

                                <th rowspan="2" style="min-width: 150px;">ADVISER'S NAME</th>
                                <th rowspan="2">GRADE</th>
                                <th rowspan="2">SECTION</th>
                                <th rowspan="2">SY</th>
                                <th rowspan="2">ACTION</th>
                                <th colspan="15" class="header-group-enrollment">ENROLLMENT</th>
                                <th colspan="15" class="header-group-dropped">DROPPED OUT</th>
                                <th colspan="3" class="header-group-transferred">BALIK ARAL</th>
                                <th colspan="3" class="header-group-transferred">TRANSFERRED IN</th>
                                <th colspan="3" class="header-group-transferred">TRANSFERRED OUT</th>
                            </tr>
                            <tr>
                                <!-- Enrollment headers -->
                                <th colspan="3">BOSY</th>
                                <th colspan="3">Q1</th>
                                <th colspan="3">Q2</th>
                                <th colspan="3">Q3</th>
                                <th colspan="3">Q4</th>
                                <!-- Dropped out headers -->
                                <th colspan="3">BOSY</th>
                                <th colspan="3">Q1</th>
                                <th colspan="3">Q2</th>
                                <th colspan="3">Q3</th>
                                <th colspan="3">Q4</th>
                                <!-- Balik Aral headers -->
                                <th>M</th>
                                <th>F</th>
                                <th>T</th>
                                <!-- Transferred In headers -->
                                <th>M</th>
                                <th>F</th>
                                <th>T</th>
                                <!-- Transferred Out headers -->
                                <th>M</th>
                                <th>F</th>
                                <th>T</th>
                            </tr>
                            <tr>
                                <th colspan="5" style="background: #f5f5f5;"></th>
                                <!-- Enrollment sub-headers -->
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                    <th>M</th>
                                    <th>F</th>
                                    <th>T</th>
                                <?php endfor; ?>
                                <!-- Dropped out sub-headers -->
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                    <th>M</th>
                                    <th>F</th>
                                    <th>T</th>
                                <?php endfor; ?>
                                <!-- Balik Aral, Transferred In, Transferred Out -->
                                <?php for ($i = 0; $i < 3; $i++): ?>
                                    <th>M</th>
                                    <th>F</th>
                                    <th>T</th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <tr>

                                    <td style="font-weight: 500; text-align: left;">
                                        <?= htmlspecialchars($record['adviser_name']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($record['grade']) ?></td>
                                    <td><?= htmlspecialchars($record['section']) ?></td>
                                    <td><?= htmlspecialchars($record['school_year']) ?></td>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <a href="view_adviser_uploads.php?grade=<?= urlencode($record['grade']) ?>&section=<?= urlencode($record['section']) ?>&sy=<?= urlencode($record['school_year']) ?>&adviser=<?= urlencode($record['adviser_name']) ?>"
                                                class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">
                                                📂 View Files
                                            </a>
                                            <form method="POST" style="margin: 0;"
                                                onsubmit="return confirm('Are you sure you want to delete this record?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                                <button type="submit" class="btn btn-danger"
                                                    style="padding: 5px 10px; font-size: 12px;">
                                                    🗑️ Del
                                                </button>
                                            </form>
                                        </div>
                                    </td>

                                    <!-- Enrollment data -->
                                    <td><?= $record['enrollment_bosy_male'] ?></td>
                                    <td><?= $record['enrollment_bosy_female'] ?></td>
                                    <td><strong><?= $record['enrollment_bosy_total'] ?></strong></td>
                                    <td><?= $record['enrollment_q1_male'] ?></td>
                                    <td><?= $record['enrollment_q1_female'] ?></td>
                                    <td><strong><?= $record['enrollment_q1_total'] ?></strong></td>
                                    <td><?= $record['enrollment_q2_male'] ?></td>
                                    <td><?= $record['enrollment_q2_female'] ?></td>
                                    <td><strong><?= $record['enrollment_q2_total'] ?></strong></td>
                                    <td><?= $record['enrollment_q3_male'] ?></td>
                                    <td><?= $record['enrollment_q3_female'] ?></td>
                                    <td><strong><?= $record['enrollment_q3_total'] ?></strong></td>
                                    <td><?= $record['enrollment_q4_male'] ?></td>
                                    <td><?= $record['enrollment_q4_female'] ?></td>
                                    <td><strong><?= $record['enrollment_q4_total'] ?></strong></td>

                                    <!-- Dropped out data -->
                                    <td><?= $record['dropped_bosy_male'] ?></td>
                                    <td><?= $record['dropped_bosy_female'] ?></td>
                                    <td><?= $record['dropped_bosy_total'] ?></td>
                                    <td><?= $record['dropped_q1_male'] ?></td>
                                    <td><?= $record['dropped_q1_female'] ?></td>
                                    <td><?= $record['dropped_q1_total'] ?></td>
                                    <td><?= $record['dropped_q2_male'] ?></td>
                                    <td><?= $record['dropped_q2_female'] ?></td>
                                    <td><?= $record['dropped_q2_total'] ?></td>
                                    <td><?= $record['dropped_q3_male'] ?></td>
                                    <td><?= $record['dropped_q3_female'] ?></td>
                                    <td><?= $record['dropped_q3_total'] ?></td>
                                    <td><?= $record['dropped_q4_male'] ?></td>
                                    <td><?= $record['dropped_q4_female'] ?></td>
                                    <td><?= $record['dropped_q4_total'] ?></td>

                                    <!-- Balik Aral data -->
                                    <td><?= $record['balik_aral_male'] ?></td>
                                    <td><?= $record['balik_aral_female'] ?></td>
                                    <td><?= $record['balik_aral_total'] ?></td>

                                    <!-- Transferred In data -->
                                    <td><?= $record['transferred_in_male'] ?></td>
                                    <td><?= $record['transferred_in_female'] ?></td>
                                    <td><?= $record['transferred_in_total'] ?></td>

                                    <!-- Transferred Out data -->
                                    <td><?= $record['transferred_out_total'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <div class="no-data-icon">📅</div>
                    <h3>No Records Found</h3>
                    <p>No eClass records found. Add a new record using the form above.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Import CSV Records</h3>
                <span class="modal-close" onclick="closeImportModal()">&times;</span>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">

                <div class="modal-body">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">Upload
                            File</label>

                        <div class="file-upload-wrapper">
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                                onchange="updateFileName(this)">
                            <div class="file-upload-icon">📂</div>
                            <div class="file-upload-text" id="file-label">Click to upload CSV file</div>
                            <div class="file-subtext">Supported format: .csv</div>
                        </div>

                        <div
                            style="margin-top: 15px; background: #eef2ff; padding: 12px; border-radius: 8px; border-left: 3px solid var(--primary-color);">
                            <p style="margin: 0; font-size: 12px; color: #444; line-height: 1.5;">
                                <strong>Required Columns:</strong><br>
                                Adviser Name, Grade, Section, School Year
                            </p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeImportModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Start Import</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openImportModal() {
            const modal = document.getElementById('importModal');
            modal.style.display = 'block';
            // Trigger reflow to enable transition if needed, though mostly for opacity
        }

        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
        }

        function updateFileName(input) {
            const label = document.getElementById('file-label');
            if (input.files && input.files.length > 0) {
                label.textContent = input.files[0].name;
                label.style.color = 'var(--primary-color)';
                label.style.fontWeight = '600';
            } else {
                label.textContent = 'Click to upload CSV file';
                label.style.color = '#666';
                label.style.fontWeight = '500';
            }
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            var modal = document.getElementById('importModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>

</html>
