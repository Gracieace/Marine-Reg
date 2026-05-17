<?php
require_once __DIR__ . '/../../auth/auth.php';
auth_require_role(['teacher', 'admin']);
require_once __DIR__ . '/../../config/db.php';

$pdo = db_connect();
$message = '';
$error = '';

// Get current user info
$current_user = $_SESSION['user'];
$teacher_id = $current_user['id'];

// Handle Delete
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    // Delete cascading records first if foreign keys aren't set to cascade
    $pdo->prepare("DELETE FROM sf2_daily_attendance WHERE sf2_report_id = ?")->execute([$delete_id]);
    $pdo->prepare("DELETE FROM sf2_student_records WHERE sf2_report_id = ?")->execute([$delete_id]);
    $pdo->prepare("DELETE FROM sf2_monthly_summary WHERE sf2_report_id = ?")->execute([$delete_id]);
    
    $stmt = $pdo->prepare("DELETE FROM sf2_reports WHERE id = ? AND teacher_id = ?");
    if ($stmt->execute([$delete_id, $teacher_id])) {
        header("Location: sf2_form.php?msg=deleted");
        exit;
    } else {
        $error = "Error deleting report.";
    }
}

// Handle Messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'success') $message = "SF2 Daily Attendance Report saved successfully!";
    if ($_GET['msg'] === 'deleted') $message = "SF2 Report deleted successfully!";
}

// Special check for schema issues
$stmt = $pdo->query("DESCRIBE sf2_student_records student_id");
$sid_type = $stmt->fetch();
if ($sid_type && strpos(strtolower($sid_type['Type']), 'int') !== false && strpos(strtolower($sid_type['Type']), 'bigint') === false) {
    $error = "Warning: Database uses 'INT' for LRNs which may cause data loss. <a href='fix_schema.php' style='color: white; text-decoration: underline;'>Click here to fix the database schema</a>.";
}

// Check for missing summary columns and add them if possible
$has_disaggregated_cols = false;
try {
    $check_stmt = $pdo->query("SHOW COLUMNS FROM sf2_monthly_summary LIKE 'perc_male_enrollment'");
    if ($check_stmt->fetch()) {
        $has_disaggregated_cols = true;
    } else {
        // Try to add them once
        $pdo->exec("ALTER TABLE sf2_monthly_summary ADD COLUMN ada_male DECIMAL(10,2) DEFAULT 0, ADD COLUMN ada_female DECIMAL(10,2) DEFAULT 0, ADD COLUMN perc_male DECIMAL(10,2) DEFAULT 0, ADD COLUMN perc_female DECIMAL(10,2) DEFAULT 0, ADD COLUMN perc_male_enrollment DECIMAL(10,2) DEFAULT 0, ADD COLUMN perc_female_enrollment DECIMAL(10,2) DEFAULT 0");
        $has_disaggregated_cols = true;
    }
} catch (Exception $e) { 
    // If ALTER fails, we'll just skip saving to these columns
    $has_disaggregated_cols = false;
}
// Robust name fetching
$stmt_teacher = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt_teacher->execute([$teacher_id]);
$t_info = $stmt_teacher->fetch();
$adviser_name = trim(($t_info['first_name'] ?? '') . ' ' . ($t_info['last_name'] ?? ''));

$current_sy = get_active_school_year($pdo);
$school_name = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'")->fetchColumn() ?: 'Malolos Marine Fishery School & Laboratory';
$principal_name = get_system_setting($pdo, 'principal_name', 'School Head');

// Get all available school years
$all_sys = $pdo->query("SELECT school_year FROM school_years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($all_sys)) $all_sys = [$current_sy];

$target_sy = $_GET['sy'] ?? $_GET['school_year'] ?? $current_sy;

// Get Advisory Class (if any)
$stmt = $pdo->prepare('SELECT * FROM position_assignments WHERE user_id = ? AND position_type = "class_adviser" AND school_year = ?');
$stmt->execute([$teacher_id, $target_sy]);
$advisory_class = $stmt->fetch();

$grade_levels = [];
$sections = [];

if ($advisory_class) {
    $grade_levels[] = $advisory_class['grade_level'];
    $sections[] = $advisory_class['section'];
}

// If admin/registrar, allow seeing all grades/sections
if (in_array($current_user['role'], ['admin', 'registrar', 'staff'])) {
    $stmt = $pdo->prepare("SELECT DISTINCT grade_level FROM sections WHERE school_year = ? ORDER BY grade_level ASC");
    $stmt->execute([$target_sy]);
    $grade_levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if ($target_grade) {
        $stmt = $pdo->prepare("SELECT DISTINCT section_name FROM sections WHERE grade_level = ? AND school_year = ? ORDER BY section_name ASC");
        $stmt->execute([$target_grade, $target_sy]);
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    // For teachers, also check if they have multiple assignments
    $stmt = $pdo->prepare("SELECT DISTINCT grade_level FROM sections WHERE adviser_id = ? AND school_year = ?");
    $stmt->execute([$teacher_id, $target_sy]);
    $assigned_gl = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($assigned_gl as $gl) {
        if (!in_array($gl, $grade_levels)) $grade_levels[] = $gl;
    }
    
    if ($target_grade) {
        $stmt = $pdo->prepare("SELECT DISTINCT section_name FROM sections WHERE grade_level = ? AND adviser_id = ? AND school_year = ?");
        $stmt->execute([$target_grade, $teacher_id, $target_sy]);
        $assigned_sec = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($assigned_sec as $sec) {
            if (!in_array($sec, $sections)) $sections[] = $sec;
        }
    }
}
sort($grade_levels);
sort($sections);

// Support for pre-selection via URL parameters (from dashboard or edit)
$target_id = $_GET['id'] ?? '';
$target_grade = $_GET['grade'] ?? $_GET['grade_level'] ?? '';
$target_section = $_GET['section'] ?? '';
$target_month = $_GET['month'] ?? '';
$target_year = $_GET['year'] ?? '';

$current_year = date('Y');

// If ID is provided, fetch metadata AND full report data for pre-filling
$preloaded_report_data = null;
if ($target_id) {
    $stmt = $pdo->prepare("SELECT * FROM sf2_reports WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$target_id, $teacher_id]);
    $report_data = $stmt->fetch();
    if ($report_data) {
        $target_grade = $report_data['grade_level'];
        $target_section = $report_data['section'];
        $target_month = $report_data['report_month'];
        $target_year = $report_data['report_year'];

        // Pre-fetch all report data so JS doesn't need a second AJAX call
        $stmt = $pdo->prepare("SELECT * FROM sf2_student_records WHERE sf2_report_id = ?");
        $stmt->execute([$target_id]);
        $preloaded_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM sf2_daily_attendance WHERE sf2_report_id = ?");
        $stmt->execute([$target_id]);
        $preloaded_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM sf2_monthly_summary WHERE sf2_report_id = ?");
        $stmt->execute([$target_id]);
        $preloaded_summary = $stmt->fetch(PDO::FETCH_ASSOC);

        $preloaded_report_data = [
            'success' => true,
            'students' => $preloaded_students,
            'attendance' => $preloaded_attendance,
            'summary' => $preloaded_summary
        ];
    }
}

if ($target_grade && !in_array($target_grade, $grade_levels)) $grade_levels[] = $target_grade;
if ($target_section && !in_array($target_section, $sections)) $sections[] = $target_section;

// Fetch all saved reports for this teacher for the history list
$stmt = $pdo->prepare("SELECT * FROM sf2_reports WHERE teacher_id = ? ORDER BY report_year DESC, report_month DESC, created_at DESC");
$stmt->execute([$teacher_id]);
$all_reports = $stmt->fetchAll();

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Find a report ID based on selection and return related metadata (SY ID, Section ID)
    if ($_GET['action'] === 'find_report_id') {
        $month = $_GET['report_month'] ?? '';
        $year = $_GET['report_year'] ?? '';
        $gl = $_GET['grade_level'] ?? '';
        $sec = $_GET['section'] ?? '';
        $sy = $_GET['sy'] ?? $current_sy;

        $stmt = $pdo->prepare("SELECT id FROM sf2_reports WHERE teacher_id = ? AND grade_level = ? AND section = ? AND report_month = ? AND report_year = ? AND school_year = ?");
        $stmt->execute([$teacher_id, $gl, $sec, $month, $year, $sy]);
        $report = $stmt->fetch();

        // Get IDs for new attendance system
        $stmt = $pdo->prepare("SELECT id FROM school_years WHERE school_year = ?");
        $stmt->execute([$sy]);
        $sy_id = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT id FROM sections WHERE grade_level = ? AND section_name = ? AND school_year = ?");
        $stmt->execute([$gl, $sec, $sy]);
        $section_id = $stmt->fetchColumn();
        
        echo json_encode([
            'success' => true, 
            'id' => $report ? $report['id'] : null,
            'sy_id' => $sy_id,
            'section_id' => $section_id
        ]);
        exit;
    }

    // New action to get valid school days
    if ($_GET['action'] === 'get_calendar_days') {
        $month = $_GET['month'] ?? '';
        $year = $_GET['year'] ?? date('Y');
        
        $monthsArr = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $mNum = array_search($month, $monthsArr) + 1;
        $mStr = str_pad($mNum, 2, '0', STR_PAD_LEFT);
        
        // Try to get from school_calendar
        $stmt = $pdo->prepare("SELECT event_date, event_name, event_type FROM school_calendar 
                               WHERE DATE_FORMAT(event_date, '%Y-%m') = ? AND is_school_day = 1 
                               ORDER BY event_date ASC");
        $stmt->execute(["$year-$mStr"]);
        $days = $stmt->fetchAll();

        if (empty($days)) {
            // Fallback: Generate weekdays
            $daysCount = cal_days_in_month(CAL_GREGORIAN, $mNum, $year);
            for ($d = 1; $d <= $daysCount; $d++) {
                $time = mktime(0, 0, 0, $mNum, $d, $year);
                if (date('N', $time) < 6) {
                    $days[] = ['event_date' => date('Y-m-d', $time)];
                }
            }
        }
        echo json_encode(['success' => true, 'days' => $days]);
        exit;
    }

    // New action to get sections for a grade level
    if ($_GET['action'] === 'get_sections') {
        $gl = $_GET['grade_level'] ?? '';
        $sy = $_GET['sy'] ?? $current_sy;
        
        $sql = "SELECT DISTINCT section_name FROM sections WHERE grade_level = ? AND school_year = ? ";
        $params = [$gl, $sy];
        
        if (!in_array($current_user['role'], ['admin', 'registrar', 'staff'])) {
            $sql .= " AND adviser_id = ? ";
            $params[] = $teacher_id;
        }
        $sql .= " ORDER BY section_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'sections' => $sections]);
        exit;
    }

    // New action to save single attendance record
    if ($_GET['action'] === 'save_single_attendance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sid = $data['student_id'] ?? '';
        $date = $data['date'] ?? '';
        $status = $data['status'] ?? '';
        $sy_id = $data['sy_id'] ?? 0;
        $section_id = $data['section_id'] ?? 0;

        if ($sid && $date && $status && $sy_id && $section_id) {
            $stmt = $pdo->prepare("INSERT INTO attendance_records (student_id, attendance_date, status, school_year_id, section_id) 
                                   VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE status = VALUES(status)");
            $stmt->execute([$sid, $date, $status, $sy_id, $section_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Missing data']);
        }
        exit;
    }

    // New action to get automated summary stats
    if ($_GET['action'] === 'get_summary_stats') {
        $gl = $_GET['grade_level'] ?? '';
        $sec = $_GET['section'] ?? '';
        $month = $_GET['month'] ?? '';
        $year = $_GET['year'] ?? '';
        $sy = $_GET['sy'] ?? $current_sy;

        // 1. Calculate First Friday of SY (Assumption: Starts in August)
        $sy_start_year = (int)explode('-', $sy)[0];
        $first_aug = strtotime("first Friday of August $sy_start_year");
        $first_friday = date('Y-m-d', $first_aug);

        // 2. Enrollment as of 1st Friday (BOSY)
        $stmt = $pdo->prepare("SELECT 
            SUM(CASE WHEN r.sex IN ('M', 'Male') THEN 1 ELSE 0 END) as m_bosy,
            SUM(CASE WHEN r.sex IN ('F', 'Female') THEN 1 ELSE 0 END) as f_bosy
            FROM enrollments e
            JOIN registrations r ON e.registration_id = r.id
            WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
            AND e.enrolled_at <= ?");
        $stmt->execute([$gl, $sec, $sy, "$first_friday 23:59:59"]);
        $bosy = $stmt->fetch();

        // 3. Movements for the month
        $monthsArr = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $mNum = array_search($month, $monthsArr) + 1;
        $mStr = str_pad($mNum, 2, '0', STR_PAD_LEFT);
        $date_prefix = "$year-$mStr";

        $movements = [
            'late_enrolment' => ['M' => 0, 'F' => 0],
            'transferred_in' => ['M' => 0, 'F' => 0],
            'returned' => ['M' => 0, 'F' => 0],
            'transferred_out' => ['M' => 0, 'F' => 0],
            'dropped_out' => ['M' => 0, 'F' => 0],
            'mortality' => ['M' => 0, 'F' => 0]
        ];

        $stmt = $pdo->prepare("SELECT m.movement_type, r.sex, COUNT(*) as count
            FROM student_movements m
            JOIN enrollments e ON m.student_id = e.student_id AND m.school_year = e.school_year
            JOIN registrations r ON e.registration_id = r.id
            WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?
            AND DATE_FORMAT(m.movement_date, '%Y-%m') = ?
            GROUP BY m.movement_type, r.sex");
        $stmt->execute([$gl, $sec, $sy, $date_prefix]);
        while ($row = $stmt->fetch()) {
            $type = strtolower(str_replace(' ', '_', $row['movement_type']));
            // Standardize spelling for JS mapping
            if ($type === 'late_enrollment') $type = 'late_enrolment';
            if ($type === 'transferred_in') $type = 'transferred_in';
            
            $sex = (in_array($row['sex'], ['M', 'Male'])) ? 'M' : 'F';
            if (isset($movements[$type])) {
                $movements[$type][$sex] = (int)$row['count'];
            }
        }

        echo json_encode([
            'success' => true,
            'bosy' => $bosy,
            'movements' => $movements,
            'first_friday' => $first_friday
        ]);
        exit;
    }

    if ($_GET['action'] === 'get_enrolled_students') {
        $grade_level = $_GET['grade_level'] ?? '';
        $section = $_GET['section'] ?? '';
        $sy = $_GET['sy'] ?? $current_sy;

        if ($grade_level && $section) {
            $stmt = $pdo->prepare("
                SELECT 
                    e.grade_level, e.section, e.school_year,
                    COALESCE(r.lrn, e.lrn) AS lrn,
                    COALESCE(r.last_name, SUBSTRING_INDEX(e.student_name, ',', 1)) AS last_name,
                    COALESCE(r.first_name, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.student_name, ',', -1), ' ', 1))) AS first_name,
                    r.middle_name, r.sex, r.birthdate
                FROM enrollments e
                LEFT JOIN registrations r ON (e.registration_id = r.id OR (e.registration_id IS NULL AND r.lrn = e.lrn)) 
                WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ? AND (e.status IS NULL OR e.status = 'Enrolled')
                ORDER BY r.last_name, r.first_name, e.student_name
            ");
            $stmt->execute([$grade_level, $section, $sy]);
            $students = $stmt->fetchAll();

            $formatted_students = [];
            
            // Get all attendance for these students in this month
            $month = $_GET['month'] ?? '';
            $year = $_GET['year'] ?? date('Y');
            $monthsArr = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            $mNum = array_search($month, $monthsArr) + 1;
            $mStr = str_pad($mNum, 2, '0', STR_PAD_LEFT);
            
            $lrns = array_column($students, 'lrn');
            $attendance_map = [];
            if (!empty($lrns)) {
                $placeholders = implode(',', array_fill(0, count($lrns), '?'));
                $stmt_att = $pdo->prepare("SELECT student_id, attendance_date, status FROM attendance_records 
                                           WHERE student_id IN ($placeholders) AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
                $params = array_merge($lrns, ["$year-$mStr"]);
                $stmt_att->execute($params);
                while ($row = $stmt_att->fetch()) {
                    $attendance_map[$row['student_id']][$row['attendance_date']] = $row['status'];
                }
            }

            // Check if there's a saved SF2 report to load remarks/movements from
            $stmt_report = $pdo->prepare("SELECT id FROM sf2_reports WHERE grade_level = ? AND section = ? AND report_month = ? AND report_year = ? AND school_year = ?");
            $stmt_report->execute([$grade_level, $section, $month, $year, $sy]);
            $saved_report_id = $stmt_report->fetchColumn();
            
            $saved_data_map = [];
            if ($saved_report_id) {
                $stmt_data = $pdo->prepare("SELECT student_id, remarks, total_absent, total_present FROM sf2_student_records WHERE sf2_report_id = ?");
                $stmt_data->execute([$saved_report_id]);
                while ($row = $stmt_data->fetch()) {
                    $saved_data_map[$row['student_id']] = $row;
                }
            }

            foreach ($students as $student) {
                $lrn = $student['lrn'];
                $saved = $saved_data_map[$lrn] ?? null;

                // Determine if late enrollee
                $is_late_enrollee = false; 
                
                // Determine if transferred out
                $is_transferred_out = false; 

                // Calculate age
                $age = null;
                if ($student['birthdate']) {
                    $birthDate = new DateTime($student['birthdate']);
                    $base_year = (int) explode('-', $sy)[0];
                    $oct31 = new DateTime($base_year . '-10-31');

                    $age = $oct31->diff($birthDate)->y;
                    if ($age < 0) $age = 0;
                }

                $formatted_students[] = [
                    'id' => $lrn,
                    'name' => trim($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']),
                    'sex' => (strtoupper(substr($student['sex'] ?? 'M', 0, 1)) === 'M') ? 'M' : 'F',
                    'age' => $age,
                    'is_late_enrollee' => $is_late_enrollee,
                    'is_transferred_out' => $is_transferred_out,
                    'attendance' => $attendance_map[$lrn] ?? (object)[],
                    'remarks' => $saved['remarks'] ?? ''
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => $formatted_students
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Grade level and Section are required']);
        exit;
    }

    if ($_GET['action'] === 'get_report_data') {
        $id = $_GET['id'] ?? '';
        if ($id) {
            // Get student records
            $stmt = $pdo->prepare("SELECT * FROM sf2_student_records WHERE sf2_report_id = ?");
            $stmt->execute([$id]);
            $students = $stmt->fetchAll();

            // Get attendance
            $stmt = $pdo->prepare("SELECT * FROM sf2_daily_attendance WHERE sf2_report_id = ?");
            $stmt->execute([$id]);
            $attendance = $stmt->fetchAll();

            // Get summary
            $stmt = $pdo->prepare("SELECT * FROM sf2_monthly_summary WHERE sf2_report_id = ?");
            $stmt->execute([$id]);
            $summary = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'students' => $students,
                'attendance' => $attendance,
                'summary' => $summary
            ]);
            exit;
        }
    }
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $school_year = $_POST['school_year'] ?? '';
        $grade_level = $_POST['grade_level'] ?? '';
        $section = $_POST['section'] ?? '';
        $report_month = $_POST['report_month'] ?? '';
        $report_year = $_POST['report_year'] ?? '';

        // Check if SF2 report already exists
        $stmt = $pdo->prepare("SELECT id FROM sf2_reports WHERE teacher_id = ? AND school_year = ? AND grade_level = ? AND section = ? AND report_month = ? AND report_year = ?");
        $stmt->execute([$teacher_id, $school_year, $grade_level, $section, $report_month, $report_year]);
        $existing_report = $stmt->fetch();

        if ($existing_report) {
            $sf2_report_id = $existing_report['id'];
        } else {
            // Create new SF2 report
            $stmt = $pdo->prepare("INSERT INTO sf2_reports (teacher_id, school_year, grade_level, section, report_month, report_year) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$teacher_id, $school_year, $grade_level, $section, $report_month, $report_year]);
            $sf2_report_id = $pdo->lastInsertId();
        }

        // Process student records
        if (isset($_POST['students'])) {
            // Clear existing student records
            $stmt = $pdo->prepare("DELETE FROM sf2_student_records WHERE sf2_report_id = ?");
            $stmt->execute([$sf2_report_id]);

            // Clear existing daily attendance
            $stmt = $pdo->prepare("DELETE FROM sf2_daily_attendance WHERE sf2_report_id = ?");
            $stmt->execute([$sf2_report_id]);

            foreach ($_POST['students'] as $student) {
                if (!empty($student['student_name'])) {
                    // Insert student record
                    $stmt = $pdo->prepare("INSERT INTO sf2_student_records (sf2_report_id, student_id, student_name, sex, total_absent, total_present, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $sf2_report_id,
                        $student['student_id'] ?? '',
                        $student['student_name'],
                        $student['sex'] ?? 'M',
                        $student['total_absent'] ?? 0,
                        $student['total_present'] ?? 0,
                        $student['remarks'] ?? ''
                    ]);

                    // Process daily attendance
                    if (isset($student['attendance'])) {
                        foreach ($student['attendance'] as $date => $status) {
                            if (!empty($status) && $status !== '') {
                                $stmt = $pdo->prepare("INSERT INTO sf2_daily_attendance (sf2_report_id, student_id, student_name, sex, attendance_date, attendance_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    $sf2_report_id,
                                    $student['student_id'] ?? '',
                                    $student['student_name'],
                                    $student['sex'] ?? 'M',
                                    $date,
                                    $status,
                                    $student['remarks'] ?? ''
                                ]);
                            }
                        }
                    }
                }
            }
        }

        // Process monthly summary
        if (isset($_POST['monthly_summary'])) {
            $summary = $_POST['monthly_summary'];

            // Check if summary exists
            $stmt = $pdo->prepare("SELECT id FROM sf2_monthly_summary WHERE sf2_report_id = ?");
            $stmt->execute([$sf2_report_id]);
            $existing_summary = $stmt->fetch();

            if ($existing_summary) {
                // Update existing summary
                $sql = "UPDATE sf2_monthly_summary SET 
                    month = ?, days_of_classes = ?, 
                    enrolment_male_bosy = ?, enrolment_female_bosy = ?, enrolment_total_bosy = ?,
                    late_enrolment_male = ?, late_enrolment_female = ?, late_enrolment_total = ?,
                    registered_male_eom = ?, registered_female_eom = ?, registered_total_eom = ?,
                    percentage_enrolment = ?, average_daily_attendance = ?, percentage_attendance = ?,
                    absent_5_consecutive_days = ?, nls_count = ?, transferred_out = ?, transferred_in = ?,
                    adviser_signature = ?, adviser_name = ?, attested_by_signature = ?, attested_by_name = ?";
                
                $params = [
                    $summary['month'] ?? $report_month,
                    $summary['days_of_classes'] ?? 0,
                    $summary['enrolment_male_bosy'] ?? 0,
                    $summary['enrolment_female_bosy'] ?? 0,
                    $summary['enrolment_total_bosy'] ?? 0,
                    $summary['late_enrolment_male'] ?? 0,
                    $summary['late_enrolment_female'] ?? 0,
                    $summary['late_enrolment_total'] ?? 0,
                    $summary['registered_male_eom'] ?? 0,
                    $summary['registered_female_eom'] ?? 0,
                    $summary['registered_total_eom'] ?? 0,
                    $summary['percentage_enrolment'] ?? 0,
                    $summary['average_daily_attendance'] ?? 0,
                    $summary['percentage_attendance'] ?? 0,
                    $summary['absent_5_consecutive_days'] ?? 0,
                    $summary['nls_count'] ?? 0,
                    $summary['transferred_out'] ?? 0,
                    $summary['transferred_in'] ?? 0,
                    $summary['adviser_signature'] ?? '',
                    $summary['adviser_name'] ?? '',
                    $summary['attested_by_signature'] ?? '',
                    $summary['attested_by_name'] ?? ''
                ];

                if ($GLOBALS['has_disaggregated_cols']) {
                    $sql .= ", ada_male = ?, ada_female = ?, perc_male = ?, perc_female = ?, perc_male_enrollment = ?, perc_female_enrollment = ?";
                    $params[] = $summary['ada_male'] ?? 0;
                    $params[] = $summary['ada_female'] ?? 0;
                    $params[] = $summary['perc_male'] ?? 0;
                    $params[] = $summary['perc_female'] ?? 0;
                    $params[] = $summary['perc_male_enrollment'] ?? 0;
                    $params[] = $summary['perc_female_enrollment'] ?? 0;
                }

                $sql .= " WHERE sf2_report_id = ?";
                $params[] = $sf2_report_id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                // Insert new summary
                $cols = "sf2_report_id, month, days_of_classes, enrolment_male_bosy, enrolment_female_bosy, enrolment_total_bosy, late_enrolment_male, late_enrolment_female, late_enrolment_total, registered_male_eom, registered_female_eom, registered_total_eom, percentage_enrolment, average_daily_attendance, percentage_attendance, absent_5_consecutive_days, nls_count, transferred_out, transferred_in, adviser_signature, adviser_name, attested_by_signature, attested_by_name";
                $vals = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
                
                $params = [
                    $sf2_report_id,
                    $summary['month'] ?? $report_month,
                    $summary['days_of_classes'] ?? 0,
                    $summary['enrolment_male_bosy'] ?? 0,
                    $summary['enrolment_female_bosy'] ?? 0,
                    $summary['enrolment_total_bosy'] ?? 0,
                    $summary['late_enrolment_male'] ?? 0,
                    $summary['late_enrolment_female'] ?? 0,
                    $summary['late_enrolment_total'] ?? 0,
                    $summary['registered_male_eom'] ?? 0,
                    $summary['registered_female_eom'] ?? 0,
                    $summary['registered_total_eom'] ?? 0,
                    $summary['percentage_enrolment'] ?? 0,
                    $summary['average_daily_attendance'] ?? 0,
                    $summary['percentage_attendance'] ?? 0,
                    $summary['absent_5_consecutive_days'] ?? 0,
                    $summary['nls_count'] ?? 0,
                    $summary['transferred_out'] ?? 0,
                    $summary['transferred_in'] ?? 0,
                    $summary['adviser_signature'] ?? '',
                    $summary['adviser_name'] ?? '',
                    $summary['attested_by_signature'] ?? '',
                    $summary['attested_by_name'] ?? ''
                ];

                if ($GLOBALS['has_disaggregated_cols']) {
                    $cols .= ", ada_male, ada_female, perc_male, perc_female, perc_male_enrollment, perc_female_enrollment";
                    $vals .= ", ?, ?, ?, ?, ?, ?";
                    $params[] = $summary['ada_male'] ?? 0;
                    $params[] = $summary['ada_female'] ?? 0;
                    $params[] = $summary['perc_male'] ?? 0;
                    $params[] = $summary['perc_female'] ?? 0;
                    $params[] = $summary['perc_male_enrollment'] ?? 0;
                    $params[] = $summary['perc_female_enrollment'] ?? 0;
                }

                $stmt = $pdo->prepare("INSERT INTO sf2_monthly_summary ($cols) VALUES ($vals)");
                $stmt->execute($params);
            }
        }

        $pdo->commit();
        // Redirect to same page with ID to retain data
        header("Location: sf2_form.php?id=" . $sf2_report_id . "&msg=success");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error saving report: " . $e->getMessage();
    }
}



// Get current school year from settings
$current_sy = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'")->fetchColumn() ?: '2024-2025';

// Get months for dropdown
$months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December'
];

// Get current year
$current_year = date('Y');

// Fetch all reports for this teacher
$stmt_reports = $pdo->prepare("SELECT * FROM sf2_reports WHERE teacher_id = ? ORDER BY report_year DESC, report_month DESC");
$stmt_reports->execute([$teacher_id]);
$all_reports = $stmt_reports->fetchAll();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF2 Daily Attendance Report | MMSFL</title>
    <link rel="stylesheet" href="<?= url_for('/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= url_for('/css/ui-tokens.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --deped-blue: #0038a8;
            --deped-red: #ce1126;
            --sidebar-width: 250px;
            --header-height: 70px;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        .main-content {
            margin-left: var(--sidebar-width, 260px);
            padding: 120px 40px 100px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            box-sizing: border-box;
        }

        /* Sidebar Toggle Interactions */
        .sidebar.is-closed ~ .main-content {
            margin-left: 0 !important;
        }

        .sidebar.is-closed ~ .main-content .sticky-submit-bar {
            left: 0 !important;
        }

        /* Responsive Behavior */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                padding: 110px 16px 120px;
            }
            .sticky-submit-bar {
                left: 0 !important;
                padding: 12px 16px;
                flex-direction: column;
                gap: 10px;
                background: white;
            }

            .sticky-submit-bar .btn {
                width: 100%;
                justify-content: center;
            }
            .official-header {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            .header-center { order: 2; }
            .logo-box:first-child { order: 1; }
            .logo-box:last-child { order: 3; }
        }

        /* Institutional Header Style */
        .official-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .header-center { text-align: center; flex: 1; }
        .header-center h2 { margin: 0; font-size: 18px; color: var(--deped-blue); font-weight: 700; }
        .header-center h3 { margin: 2px 0; font-size: 16px; font-weight: 600; }
        .header-center p { margin: 0; font-size: 12px; color: #475569; }
        .logo-box img { height: 75px; width: auto; object-fit: contain; }

        .report-title-box {
            text-align: center;
            margin-bottom: 30px;
        }
        .report-title-box h1 { margin: 0; font-size: 24px; color: #0f172a; font-weight: 800; text-transform: uppercase; }
        .report-title-box p { margin: 5px 0; font-style: italic; color: #64748b; font-size: 13px; }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .input, select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            background-color: #fff;
            color: #1e293b;
            box-sizing: border-box;
        }

        .input:focus, select:focus {
            outline: none;
            border-color: var(--deped-blue);
            box-shadow: 0 0 0 3px rgba(0, 56, 168, 0.1);
        }

        /* Attendance Table */
        .attendance-table-container {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .attendance-table th {
            background: #f8fafc;
            padding: 12px 8px;
            border-bottom: 2px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            font-weight: 700;
            color: #475569;
            text-align: center;
        }

        .attendance-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            text-align: center;
        }

        .student-name {
            text-align: left !important;
            font-weight: 600;
            padding-left: 12px !important;
            color: #0f172a;
            white-space: nowrap;
        }

        .attendance-input {
            width: 28px;
            height: 28px;
            text-align: center;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            transition: all 0.2s;
        }

        .attendance-input:focus {
            border-color: var(--deped-blue);
            background-color: #eff6ff;
            outline: none;
        }

        .total-row {
            background: #f1f5f9;
            font-weight: 700;
        }

        .male-header { background: #eff6ff !important; color: #1e40af !important; }
        .female-header { background: #fff1f2 !important; color: #9f1239 !important; }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: none;
            font-size: 14px;
            text-decoration: none;
        }

        .btn-primary { background: var(--deped-blue); color: #fff; }
        .btn-primary:hover { background: #002d87; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 56, 168, 0.2); }
        
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-secondary:hover { background: #475569; }

        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }

        .btn-outline { background: #fff; border: 1px solid #e2e8f0; color: #475569; }
        .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

        /* Sticky Bar */
        .sticky-submit-bar {
            position: fixed;
            bottom: 0;
            right: 0;
            left: var(--sidebar-width, 260px);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            padding: 16px 40px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.05);
            border-top: 1px solid #e2e8f0;
            z-index: 100;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        @media print {
            .sidebar, .action-bar, .sticky-submit-bar, .no-print, button, .teacher-header { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; background: white !important; }
            .card { box-shadow: none !important; border: none !important; padding: 0 !important; margin-bottom: 30px !important; }
            .attendance-table-container { border: 1px solid #000 !important; overflow: visible !important; }
            .attendance-table th, .attendance-table td { border: 1px solid #000 !important; color: black !important; }
            .attendance-input, .input, select { 
                border: none !important; 
                background: transparent !important; 
                padding: 0 !important; 
                appearance: none; 
                -webkit-appearance: none; 
                color: black !important;
                font-weight: 700 !important;
            }
            .total-row { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; }
            .male-header { background: #eff6ff !important; -webkit-print-color-adjust: exact; }
            .female-header { background: #fff1f2 !important; -webkit-print-color-adjust: exact; }
            h1, h2, h3 { color: black !important; }
        }

        /* Attendance Status Colors */
        .attendance-input.status-P { background-color: #dcfce7 !important; color: #166534 !important; font-weight: bold; border-color: #86efac !important; }
        .attendance-input.status-A { background-color: #fee2e2 !important; color: #991b1b !important; font-weight: bold; border-color: #fca5a5 !important; }
        .attendance-input.status-L { background-color: #fef9c3 !important; color: #854d0e !important; font-weight: bold; border-color: #fde047 !important; }
        .attendance-input.status-E { background-color: #f1f5f9 !important; color: #475569 !important; font-weight: bold; border-color: #cbd5e1 !important; }
        
        .saving-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #1e293b;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            z-index: 9999;
            display: none;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            align-items: center;
            gap: 8px;
        }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spinner { border: 2px solid #334155; border-top: 2px solid white; border-radius: 50%; width: 12px; height: 12px; animation: spin 0.6s linear infinite; }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../teacher_header.php'; ?>
    <?php require_once __DIR__ . '/../teacher_side_panel.php'; ?>

    <div id="savingIndicator" class="saving-indicator">
        <div class="spinner"></div>
        <span>Saving changes...</span>
    </div>

    <div class="main-content dashboard-container">
        <!-- Official Header -->
        <div class="official-header">
            <div class="logo-box">
                <img src="<?= url_for('/assets/images/deped_logo.png') ?>" alt="DepEd Logo">
            </div>
            <div class="header-center">
                <p>Republic of the Philippines</p>
                <h2>Department of Education</h2>
                <p>Region III - Central Luzon</p>
                <h3>Malolos Marine Fishery School and Laboratory</h3>
                <p>City of Malolos, Bulacan</p>
            </div>
            <div class="logo-box">
                <img src="<?= url_for('/assets/images/school_logo.png') ?>" alt="School Logo">
            </div>
        </div>

        <div class="report-title-box">
            <h1>School Form 2 (SF2) Daily Attendance Report</h1>
            <p>(This replaces Form 1, Form 2 & STS Form 4 - Absenteeism and Dropout Profile)</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="sf2Form">
            <div class="card">
                <div class="form-row">
                    <div class="form-group">
                        <label>School Year</label>
                        <select name="school_year" id="school_year" class="input">
                            <?php foreach ($all_sys as $sy): ?>
                                <option value="<?= htmlspecialchars($sy) ?>" <?= ($target_sy == $sy) ? 'selected' : '' ?>><?= htmlspecialchars($sy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Grade Level</label>
                        <select name="grade_level" id="grade_level" required class="input">
                            <option value="">Select Grade</option>
                            <?php foreach ($grade_levels as $gl): ?>
                                <option value="<?= htmlspecialchars($gl) ?>" <?= ($target_grade == $gl || (count($grade_levels) == 1)) ? 'selected' : '' ?>><?= htmlspecialchars($gl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <select name="section" id="section" required class="input">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= ($target_section == $sec || (count($sections) == 1)) ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Month</label>
                        <select name="report_month" id="report_month" required class="input">
                            <?php foreach ($months as $m): ?>
                                <option value="<?= $m ?>" <?= ($target_month ?: date('F')) === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <select name="report_year" id="report_year" required class="input">
                            <?php for ($y = $current_year - 1; $y <= $current_year + 1; $y++): ?>
                                <option value="<?= $y ?>" <?= ($target_year ?: $current_year) == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-primary" style="width: 100%; justify-content: center;" onclick="loadEnrolledStudents()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            Load Students
                        </button>
                    </div>
                </div>
            </div>

            <div class="card no-print" style="background: #f8fafc; border-left: 4px solid var(--deped-blue); padding: 15px 24px; margin-bottom: 24px;">
                <h4 style="margin: 0 0 12px 0; font-size: 14px; display: flex; align-items: center; gap: 8px; color: var(--deped-blue);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    Attendance Recording Instructions
                </h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 20px; font-size: 13px; color: #475569;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: white; border: 1px solid #cbd5e1; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-weight: 800; border-radius: 6px; color: #10b981;">P</span>
                            <span>= <b>Present</b></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: white; border: 1px solid #cbd5e1; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-weight: 800; border-radius: 6px; color: #ef4444;">A</span>
                            <span>= <b>Absent</b></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: white; border: 1px solid #cbd5e1; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-weight: 800; border-radius: 6px; color: #f59e0b;">L</span>
                            <span>= <b>Late</b></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: white; border: 1px solid #cbd5e1; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-weight: 800; border-radius: 6px; color: #6366f1;">E</span>
                            <span>= <b>Excused</b></span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; font-style: italic; color: #64748b;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <span>Monthly totals will automatically recalculate as you type.</span>
                    </div>
                </div>


            <div id="attendanceGridContainer">

                <!-- Data will be loaded via JS -->
            </div>

            <div class="card" style="margin-top: 24px;">
                <h3 style="margin-bottom: 20px; font-weight: 800; display: flex; align-items: center; gap: 10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    Monthly Summary
                </h3>
                <div class="attendance-table-container">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding-left: 20px;">Summary Item</th>
                                <th style="width: 100px;">M</th>
                                <th style="width: 100px;">F</th>
                                <th style="width: 120px;">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align: left; padding-left: 20px; font-weight: 500;">Enrolment as of 1st Friday of SY</td>
                                <td><input type="number" name="monthly_summary[enrolment_male_bosy]" class="input" style="text-align: center;"></td>
                                <td><input type="number" name="monthly_summary[enrolment_female_bosy]" class="input" style="text-align: center;"></td>
                                <td><input type="number" name="monthly_summary[enrolment_total_bosy]" class="input" readonly style="text-align: center; background: #f8fafc; font-weight: 700;"></td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding-left: 20px; font-weight: 500;">Late Enrolment during the month</td>
                                <td><input type="number" name="monthly_summary[late_enrolment_male]" class="input" style="text-align: center;"></td>
                                <td><input type="number" name="monthly_summary[late_enrolment_female]" class="input" style="text-align: center;"></td>
                                <td><input type="number" name="monthly_summary[late_enrolment_total]" class="input" readonly style="text-align: center; background: #f8fafc; font-weight: 700;"></td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding-left: 20px; font-weight: 500;">Registered Learners (End of Month)</td>
                                <td><input type="number" name="monthly_summary[registered_male_eom]" class="input" style="text-align: center;"></td>
                                <td><input type="number" name="monthly_summary[registered_female_eom]" class="input" style="text-align: center;"></td>
                                <td><input type="number" name="monthly_summary[registered_total_eom]" class="input" readonly style="text-align: center; background: #f8fafc; font-weight: 700;"></td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding-left: 20px; font-weight: 500;">Average Daily Attendance</td>
                                <td><input type="number" step="0.01" name="monthly_summary[ada_male]" class="input" readonly style="text-align: center; background: #f8fafc;"></td>
                                <td><input type="number" step="0.01" name="monthly_summary[ada_female]" class="input" readonly style="text-align: center; background: #f8fafc;"></td>
                                <td><input type="number" step="0.01" name="monthly_summary[average_daily_attendance]" class="input" readonly style="text-align: center; background: #f8fafc; font-weight: 700;"></td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding-left: 20px; font-weight: 500;">Percentage of Enrolment for the Month</td>
                                <td><input type="number" step="0.01" name="monthly_summary[perc_male_enrollment]" class="input" readonly style="text-align: center; background: #f8fafc;"></td>
                                <td><input type="number" step="0.01" name="monthly_summary[perc_female_enrollment]" class="input" readonly style="text-align: center; background: #f8fafc;"></td>
                                <td><input type="number" step="0.01" name="monthly_summary[percentage_enrolment]" class="input" readonly style="text-align: center; background: #f8fafc; font-weight: 700;"></td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding-left: 20px; font-weight: 500;">Percentage of Attendance for the Month</td>
                                <td><input type="number" step="0.01" name="monthly_summary[perc_male]" class="input" readonly style="text-align: center; background: #f8fafc;"></td>
                                <td><input type="number" step="0.01" name="monthly_summary[perc_female]" class="input" readonly style="text-align: center; background: #f8fafc;"></td>
                                <td><input type="number" step="0.01" name="monthly_summary[percentage_attendance]" class="input" readonly style="text-align: center; background: #f8fafc; font-weight: 700;"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="form-row" style="margin-top: 24px;">
                    <div class="form-group">
                        <label>Avg. Daily Attendance</label>
                        <input type="number" step="0.01" name="monthly_summary[average_daily_attendance]" class="input" readonly style="background: #f8fafc; font-weight: 700; color: var(--deped-blue);">
                    </div>
                    <div class="form-group">
                        <label>% Attendance (Month)</label>
                        <input type="number" step="0.01" name="monthly_summary[percentage_attendance]" class="input" readonly style="background: #f8fafc; font-weight: 700; color: #10b981;">
                    </div>
                    <div class="form-group">
                        <label>Days of Classes</label>
                        <input type="number" name="monthly_summary[days_of_classes]" class="input" readonly style="background: #f8fafc; font-weight: 700;">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Adviser Name</label>
                        <input type="text" name="monthly_summary[adviser_name]" class="input" value="<?= htmlspecialchars($adviser_name ?: ($current_user['full_name'] ?? $current_user['username'])) ?>" readonly style="background: #f8fafc;">
                    </div>
                    <div class="form-group">
                        <label>Attested By</label>
                        <input type="text" name="monthly_summary[attested_by_name]" class="input" value="<?= htmlspecialchars($principal_name) ?>" readonly style="background: #f8fafc;">
                    </div>
                </div>
            </div>

            <!-- Sticky Submit Bar -->
            <div class="sticky-submit-bar no-print">
                <a href="../reports.php" class="btn btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Back
                </a>
                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print Form
                    </button>
                    <button type="submit" class="btn btn-success">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        Save SF2 Report
                    </button>
                </div>
            </div>
        </form>

        <!-- Saved Reports List -->
        <div class="card" style="margin-top: 40px;">
            <h3 style="margin-bottom: 20px; font-weight: 800; display: flex; align-items: center; gap: 10px; color: #0f172a;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                Saved SF2 Reports
            </h3>
            
            <div class="attendance-table-container">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th style="text-align: left; padding-left: 20px;">Month/Year</th>
                            <th>Grade & Section</th>
                            <th>School Year</th>
                            <th>Date Created</th>
                            <th style="width: 250px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_reports)): ?>
                            <tr>
                                <td colspan="5" style="padding: 30px; color: #64748b;">No saved reports found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_reports as $report): ?>
                                <tr>
                                    <td style="text-align: left; padding-left: 20px; font-weight: 600;">
                                        <?= htmlspecialchars($report['report_month']) ?> <?= htmlspecialchars($report['report_year']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($report['grade_level']) ?> - <?= htmlspecialchars($report['section']) ?></td>
                                    <td><?= htmlspecialchars($report['school_year']) ?></td>
                                    <td style="color: #64748b;"><?= date('M d, Y', strtotime($report['created_at'])) ?></td>
                                    <td>
                                        <div style="display: flex; gap: 8px; justify-content: center;">
                                            <a href="sf2_print.php?id=<?= $report['id'] ?>" class="btn btn-outline" style="padding: 6px 12px; font-size: 12px;" target="_blank">
                                                View/Print
                                            </a>
                                            <a href="?id=<?= $report['id'] ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px; background: #6366f1;">
                                                Edit
                                            </a>
                                            <a href="?delete_id=<?= $report['id'] ?>" class="btn btn-pdf" style="padding: 6px 12px; font-size: 12px; background: #ef4444; color: white;" onclick="return confirm('Are you sure you want to delete this report?')">
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            let allStudents = [];
            let attendanceDates = [];
            let currentSyId = null;
            let currentSectionId = null;
            // Pre-loaded report data embedded by PHP (null if no report is being viewed)
            const preloadedReportData = <?= $preloaded_report_data ? json_encode($preloaded_report_data) : 'null' ?>;
            let preloadedUsed = false;

            async function generateAttendanceDates() {
                const month = document.getElementById('report_month').value;
                const year = document.getElementById('report_year').value;
                if (!month || !year) return [];

                try {
                    const res = await fetch(`sf2_form.php?action=get_calendar_days&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`);
                    const result = await res.json();
                    if (result.success && result.days && result.days.length > 0) {
                        const dates = result.days.map(d => {
                            const dateObj = new Date(d.event_date);
                            return {
                                dateString: d.event_date,
                                dayNum: dateObj.getDate()
                            };
                        });
                        
                        const daysInput = document.querySelector('input[name="monthly_summary[days_of_classes]"]');
                        if (daysInput) daysInput.value = dates.length;
                        return dates;
                    }
                } catch (e) {
                    console.error("Error fetching calendar:", e);
                }

                // JS-side Fallback: Generate weekdays if server fails or returns empty
                console.warn("Using JS fallback for attendance dates");
                const monthsArr = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                const mIndex = monthsArr.indexOf(month);
                if (mIndex === -1) return [];
                
                const dates = [];
                const daysInMonth = new Date(year, mIndex + 1, 0).getDate();
                for (let d = 1; d <= daysInMonth; d++) {
                    const date = new Date(year, mIndex, d);
                    if (date.getDay() >= 1 && date.getDay() <= 5) {
                        const mStr = String(mIndex + 1).padStart(2, '0');
                        const dStr = String(d).padStart(2, '0');
                        dates.push({
                            dateString: `${year}-${mStr}-${dStr}`,
                            dayNum: d
                        });
                    }
                }
                const daysInput = document.querySelector('input[name="monthly_summary[days_of_classes]"]');
                if (daysInput) daysInput.value = dates.length;
                return dates;
            }

            async function loadEnrolledStudents() {
                const gl = document.getElementById('grade_level').value;
                const sec = document.getElementById('section').value;
                const month = document.getElementById('report_month').value;
                const year = document.getElementById('report_year').value;
                const syInput = document.querySelector('[name="school_year"]');
                const sy = syInput ? syInput.value : '';

                if (!gl || !sec || !sy || !month || !year) return;

                const container = document.getElementById('attendanceGridContainer');
                container.innerHTML = '<div class="card" style="text-align:center; padding: 40px;"><p>Loading students and attendance records...</p></div>';

                try {
                    // Get IDs first
                    const findRes = await fetch(`sf2_form.php?action=find_report_id&grade_level=${encodeURIComponent(gl)}&section=${encodeURIComponent(sec)}&report_month=${encodeURIComponent(month)}&report_year=${encodeURIComponent(year)}&sy=${encodeURIComponent(sy)}`);
                    const findResult = await findRes.json();
                    currentSyId = findResult.sy_id;
                    currentSectionId = findResult.section_id;

                    const res = await fetch(`sf2_form.php?action=get_enrolled_students&grade_level=${encodeURIComponent(gl)}&section=${encodeURIComponent(sec)}&sy=${encodeURIComponent(sy)}&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`);
                    const result = await res.json();
                    
                    if (result.success) {
                        allStudents = result.data;
                        attendanceDates = await generateAttendanceDates();
                        renderAttendanceGrid();
                        await calculateTotals(); // New: wait for totals

                        if (findResult.id) {
                            const dataRes = await fetch(`sf2_form.php?action=get_report_data&id=${findResult.id}`);
                            const reportData = await dataRes.json();
                            if (reportData.success) {
                                populateReportData(reportData);
                            }
                        }
                    } else {
                        container.innerHTML = `<div class="alert alert-warning">${result.message}</div>`;
                    }
                } catch (e) { 
                    console.error(e);
                    container.innerHTML = '<div class="alert alert-danger">Error loading data. Please try again.</div>';
                }
            }

            async function loadSections() {
                const gl = document.getElementById('grade_level').value;
                const syInput = document.querySelector('[name="school_year"]');
                const sy = syInput ? syInput.value : '';
                const sectionSelect = document.getElementById('section');
                
                if (!gl) {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    return;
                }

                try {
                    const res = await fetch(`sf2_form.php?action=get_sections&grade_level=${encodeURIComponent(gl)}&sy=${encodeURIComponent(sy)}`);
                    const result = await res.json();
                    if (result.success) {
                        let html = '<option value="">Select Section</option>';
                        result.sections.forEach(sec => {
                            // If there is only one section, or it matches target, select it
                            const isTarget = (sec === '<?= $target_section ?>');
                            const shouldSelect = isTarget || (result.sections.length === 1);
                            html += `<option value="${sec}" ${shouldSelect ? 'selected' : ''}>${sec}</option>`;
                        });
                        sectionSelect.innerHTML = html;
                        
                        // Auto-load if a section is selected
                        if (sectionSelect.value) {
                            loadEnrolledStudents();
                        } else {
                            document.getElementById('attendanceGridContainer').innerHTML = '<div class="card" style="text-align:center; padding: 40px; color: #64748b;">Please select a section to continue.</div>';
                        }
                    }
                } catch (e) {
                    console.error("Error loading sections:", e);
                }
            }

        function populateReportData(data) {
            if (data.summary) {
                const s = data.summary;
                const fields = {
                    'enrolment_male_bosy': s.enrolment_male_bosy,
                    'enrolment_female_bosy': s.enrolment_female_bosy,
                    'late_enrolment_male': s.late_enrolment_male,
                    'late_enrolment_female': s.late_enrolment_female,
                    'registered_male_eom': s.registered_male_eom,
                    'registered_female_eom': s.registered_female_eom,
                    'average_daily_attendance': s.average_daily_attendance,
                    'ada_male': s.ada_male,
                    'ada_female': s.ada_female,
                    'percentage_attendance': s.percentage_attendance,
                    'perc_male': s.perc_male,
                    'perc_female': s.perc_female
                };
                for (let key in fields) {
                    const input = document.querySelector(`input[name="monthly_summary[${key}]"]`);
                    if (input) input.value = fields[key];
                }
            }

            if (data.students) {
                const rows = document.querySelectorAll('.student-record-row');
                const rowMap = new Map();
                rows.forEach(row => rowMap.set(row.querySelector('input[name*="[student_id]"]').value, row));
                
                data.students.forEach(stud => {
                    const row = rowMap.get(stud.student_id);
                    if (row) {
                        const sel = row.querySelector('select[name*="[remarks]"]');
                        if (sel) {
                            sel.value = stud.remarks || "";
                            handleRemarkChange(sel);
                        }
                    }
                });
            }

            if (data.attendance) {
                data.attendance.forEach(att => {
                    const input = document.querySelector(`input[name^="students"][name*="[attendance][${att.attendance_date}]"][value]`);
                    // We need to find the specific student row
                    const rows = document.querySelectorAll('.student-record-row');
                    rows.forEach(row => {
                        const sid = row.querySelector('input[name*="[student_id]"]').value;
                        if (sid === att.student_id) {
                            const inp = row.querySelector(`input[name*="[attendance][${att.attendance_date}]"]`);
                            if (inp) {
                                inp.value = att.attendance_status || "";
                                validateInput(inp);
                            }
                        }
                    });
                });
            }

            calculateTotals();
        }

            function renderAttendanceGrid() {
                const container = document.getElementById('attendanceGridContainer');
                if (!allStudents.length) {
                    container.innerHTML = '<div class="card" style="text-align:center; padding: 40px; color: #64748b;">No students found for ' + document.getElementById('grade_level').value + ' ' + document.getElementById('section').value + '</div>';
                    return;
                }

                const males = allStudents.filter(s => s.sex === 'M');
                const females = allStudents.filter(s => s.sex === 'F');

                let html = `
                    <div class="card" style="margin-top: 24px;">
                        <h3 style="margin-bottom: 20px;">Daily Attendance Register</h3>
                        <div class="attendance-table-container">
                            <table class="attendance-table">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="student-name">Learner's Name</th>
                                        <th colspan="${attendanceDates.length}">Monthly Dates</th>
                                        <th colspan="2">Totals</th>
                                        <th rowspan="2" style="width: 140px;">Remarks</th>
                                    </tr>
                                    <tr>
                                        ${attendanceDates.map(d => `<th style="width: 32px; font-size: 11px;">${d.dayNum}</th>`).join('')}
                                        <th style="width: 45px; font-size: 11px;">ABS</th>
                                        <th style="width: 45px; font-size: 11px;">PRE</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;

                const renderRow = (s, i) => {
                    const att = s.attendance || {};
                    const rem = s.remarks || "";
                    return `
                    <tr class="student-record-row">
                        <td class="student-name">
                            <input type="hidden" name="students[${i}][student_id]" value="${s.id}">
                            <input type="hidden" name="students[${i}][student_name]" value="${s.name}">
                            <input type="hidden" name="students[${i}][sex]" value="${s.sex}">
                            ${s.name}
                        </td>
                        ${attendanceDates.map(d => {
                            const status = att[d.dateString] || '';
                            const statusClass = status ? `status-${status}` : '';
                            return `<td><input type="text" class="day-input attendance-input ${statusClass}" 
                                        name="students[${i}][attendance][${d.dateString}]" 
                                        maxlength="1" value="${status}" 
                                        oninput="validateInput(this);" 
                                        onchange="saveSingleAttendance('${s.id}', '${d.dateString}', this.value)"></td>`;
                        }).join('')}
                        <td><input type="number" class="input total-absent-display" name="students[${i}][total_absent]" readonly style="width: 40px; text-align: center; border:none; background:transparent; font-weight:bold;"></td>
                        <td><input type="number" class="input total-present-display" name="students[${i}][total_present]" readonly style="width: 40px; text-align: center; border:none; background:transparent; font-weight:bold;"></td>
                        <td>
                            <select name="students[${i}][remarks]" class="input" style="padding: 4px 8px; font-size: 11px; height: auto;" onchange="handleRemarkChange(this)">
                                <option value="" ${rem === '' ? 'selected' : ''}>-</option>
                                <option value="Late Enrollee" ${rem === 'Late Enrollee' ? 'selected' : ''}>Late Enrollee</option>
                                <option value="Transferred Out" ${rem === 'Transferred Out' ? 'selected' : ''}>Transferred Out</option>
                                <option value="Dropped Out" ${rem === 'Dropped Out' ? 'selected' : ''}>Dropped Out</option>
                            </select>
                        </td>
                    </tr>
                `;};

                if (males.length > 0) {
                    html += '<tr class="total-row male-header"><td colspan="' + (attendanceDates.length + 4) + '" class="student-name" style="text-align: left; padding: 10px 15px; font-weight: 700;">MALE</td></tr>';
                    males.forEach((s, i) => html += renderRow(s, i));
                }

                if (females.length > 0) {
                    html += '<tr class="total-row female-header"><td colspan="' + (attendanceDates.length + 4) + '" class="student-name" style="text-align: left; padding: 10px 15px; font-weight: 700;">FEMALE</td></tr>';
                    females.forEach((s, i) => html += renderRow(s, i + males.length));
                }

                html += `</tbody></table></div>
                </div>`;
                container.innerHTML = html;
                calculateTotals();
            }

            async function saveSingleAttendance(studentId, date, status) {
                status = status.toUpperCase();
                if (!/[PALE]/.test(status) && status !== '') return;
                
                const indicator = document.getElementById('savingIndicator');
                indicator.style.display = 'flex';

                try {
                    const res = await fetch('sf2_form.php?action=save_single_attendance', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            student_id: studentId,
                            date: date,
                            status: status,
                            sy_id: currentSyId,
                            section_id: currentSectionId
                        })
                    });
                    const result = await res.json();
                    if (!result.success) {
                        alert('Failed to save attendance: ' + result.message);
                    }
                } catch (e) {
                    console.error(e);
                } finally {
                    setTimeout(() => { indicator.style.display = 'none'; }, 500);
                    await calculateTotals();
                }
            }

            function validateInput(inp) {
                inp.value = inp.value.toUpperCase();
                // Remove existing status classes
                inp.classList.remove('status-P', 'status-A', 'status-L', 'status-E');
                
                if (!/[PALE]/.test(inp.value)) {
                    if (inp.value !== '') {
                        inp.value = '';
                    }
                } else {
                    inp.classList.add(`status-${inp.value}`);
                }
            }

            function handleRemarkChange(sel) {
                const tr = sel.closest('tr');
                tr.style.opacity = (sel.value === 'Transferred Out' || sel.value === 'Dropped Out') ? '0.5' : '1';
                calculateTotals();
            }

            async function calculateTotals() {
                const totalDays = attendanceDates.length;
                if (!totalDays) return;

                const rows = document.querySelectorAll('.student-record-row');
                let mPresent = 0, fPresent = 0, mCount = 0, fCount = 0;

                rows.forEach(row => {
                    const sex = row.querySelector('input[name*="[sex]"]').value;
                    const active = !['Transferred Out', 'Dropped Out'].includes(row.querySelector('select').value);
                    const inputs = row.querySelectorAll('.day-input');
                    let abs = 0;

                    inputs.forEach(inp => { 
                        const val = inp.value.toUpperCase();
                        if (val === 'A') abs++; 
                    });
                    const pre = totalDays - abs;
                    row.querySelector('.total-absent-display').value = abs;
                    row.querySelector('.total-present-display').value = pre;

                    if (active) {
                        if (sex === 'M') { mPresent += pre; mCount++; } else { fPresent += pre; fCount++; }
                    }
                });

                const totalActive = mCount + fCount;
                const ada = totalActive ? ((mPresent + fPresent) / totalDays).toFixed(2) : "0.00";
                const adaM = mCount ? (mPresent / totalDays).toFixed(2) : "0.00";
                const adaF = fCount ? (fPresent / totalDays).toFixed(2) : "0.00";

                // Update Summary Fields with latest table counts
                const setVal = (name, val) => {
                    const inp = document.querySelector(`input[name="monthly_summary[${name}]"]`);
                    if (inp) { inp.value = val; inp.readOnly = true; }
                };
                
                setVal('ada_male', adaM);
                setVal('ada_female', adaF);
                setVal('average_daily_attendance', ada);
                
                // Registered Learners (EOM) is basically the count of active students in the grid
                setVal('registered_male_eom', mCount);
                setVal('registered_female_eom', fCount);

                await fetchSummaryStats();
                updateSummaryTotals();
            }

            async function fetchSummaryStats() {
                const gl = document.getElementById('grade_level').value;
                const sec = document.getElementById('section').value;
                const month = document.getElementById('report_month').value;
                const year = document.getElementById('report_year').value;
                const syInput = document.querySelector('[name="school_year"]');
                const sy = syInput ? syInput.value : '';

                if (!gl || !sec || !month || !year || !sy) return;

                try {
                    const res = await fetch(`sf2_form.php?action=get_summary_stats&grade_level=${encodeURIComponent(gl)}&section=${encodeURIComponent(sec)}&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}&sy=${encodeURIComponent(sy)}`);
                    const stats = await res.json();
                    if (stats.success) {
                        const b = stats.bosy;
                        const m = stats.movements;

                        // Apply to inputs
                        const setVal = (name, val) => {
                            const inp = document.querySelector(`input[name="monthly_summary[${name}]"]`);
                            if (inp) { inp.value = val; inp.readOnly = true; }
                        };

                        setVal('enrolment_male_bosy', b.m_bosy || 0);
                        setVal('enrolment_female_bosy', b.f_bosy || 0);
                        setVal('late_enrolment_male', m.late_enrolment.M || 0);
                        setVal('late_enrolment_female', m.late_enrolment.F || 0);

                        // Calculate EOM
                        // EOM = BOSY + Late + In + Ret - Out - Drop - Mort
                        const calcEOM = (sex) => {
                            const bosy = parseInt(b[sex.toLowerCase() + '_bosy']) || 0;
                            const mv = m;
                            return bosy + (mv.late_enrolment[sex] || 0) + (mv.transferred_in[sex] || 0) + (mv.returned[sex] || 0) 
                                 - (mv.transferred_out[sex] || 0) - (mv.dropped_out[sex] || 0) - (mv.mortality[sex] || 0);
                        };

                        const eomM = calcEOM('M');
                        const eomF = calcEOM('F');
                        // EOM is now handled dynamically in calculateTotals based on grid state
                        // setVal('registered_male_eom', eomM);
                        // setVal('registered_female_eom', eomF);

                        updateSummaryTotals();
                    }
                } catch (e) {
                    console.error("Error fetching summary stats:", e);
                }
            }

            function updateSummaryTotals() {
                const rows = [
                    ['enrolment_male_bosy', 'enrolment_female_bosy', 'enrolment_total_bosy'],
                    ['late_enrolment_male', 'late_enrolment_female', 'late_enrolment_total'],
                    ['registered_male_eom', 'registered_female_eom', 'registered_total_eom'],
                    ['ada_male', 'ada_female', 'average_daily_attendance'],
                    ['perc_male_enrollment', 'perc_female_enrollment', 'percentage_enrolment'],
                    ['perc_male', 'perc_female', 'percentage_attendance']
                ];

                rows.forEach(row => {
                    const mInp = document.querySelector(`input[name="monthly_summary[${row[0]}]"]`);
                    const fInp = document.querySelector(`input[name="monthly_summary[${row[1]}]"]`);
                    const tInp = document.querySelector(`input[name="monthly_summary[${row[2]}]"]`);

                    if (mInp && fInp && tInp) {
                        const m = parseFloat(mInp.value) || 0;
                        const f = parseFloat(fInp.value) || 0;
                        
                        // Special handling for Percentage rows
                        if (row[0] === 'perc_male' || row[0] === 'perc_male_enrollment') {
                            const regM = parseFloat(document.querySelector('input[name="monthly_summary[registered_male_eom]"]').value) || 0;
                            const regF = parseFloat(document.querySelector('input[name="monthly_summary[registered_female_eom]"]').value) || 0;
                            const adaM = parseFloat(document.querySelector('input[name="monthly_summary[ada_male]"]').value) || 0;
                            const adaF = parseFloat(document.querySelector('input[name="monthly_summary[ada_female]"]').value) || 0;
                            
                            const pM = regM ? ((adaM / regM) * 100).toFixed(2) : "0.00";
                            const pF = regF ? ((adaF / regF) * 100).toFixed(2) : "0.00";
                            const pT = (regM + regF) ? (((adaM + adaF) / (regM + regF)) * 100).toFixed(2) : "0.00";
                            
                            mInp.value = pM;
                            fInp.value = pF;
                            tInp.value = pT;
                        } else {
                            // Sum for other rows
                            const sum = m + f;
                            tInp.value = (row[0].includes('ada')) ? sum.toFixed(2) : sum;
                        }
                    }
                });
                
                // Sync bottom inputs
                const adaTotal = document.querySelector('input[name="monthly_summary[average_daily_attendance]"]').value;
                const pctTotal = document.querySelector('input[name="monthly_summary[percentage_attendance]"]').value;
                
                const bottomAda = document.querySelectorAll('input[name="monthly_summary[average_daily_attendance]"]')[1];
                const bottomPct = document.querySelectorAll('input[name="monthly_summary[percentage_attendance]"]')[1];
                
                if (bottomAda) bottomAda.value = adaTotal;
                if (bottomPct) bottomPct.value = pctTotal;
            }

            document.addEventListener('input', e => { if(e.target.name && e.target.name.includes('monthly_summary')) updateSummaryTotals(); });
            document.getElementById('grade_level').addEventListener('change', () => {
                loadSections();
            });
            document.getElementById('section').addEventListener('change', loadEnrolledStudents);
            document.getElementById('school_year').addEventListener('change', loadEnrolledStudents);
            document.getElementById('report_month').addEventListener('change', loadEnrolledStudents);
            document.getElementById('report_year').addEventListener('change', loadEnrolledStudents);

            window.addEventListener('DOMContentLoaded', () => { 
                const gl = document.getElementById('grade_level').value;
                const sec = document.getElementById('section').value;
                if (gl && !sec) {
                    loadSections(); 
                } else if (gl && sec) {
                    loadEnrolledStudents();
                }
            });
        </script>
</body>

</html>