<?php
require_once __DIR__ . '/../../../config/db.php';

/**
 * Generates the SF6 Report data for a specific school year, grade level, or section.
 * Aggregates data from Teacher SF5 reports (finalized), SF9 reports, or live enrollments.
 */
function generateSF6($pdo, $school_year, $grade_level = '', $section_id = '')
{
    // 1. Identify which sections to include
    $sql = "SELECT s.id, s.section_name, s.grade_level, CONCAT(u.first_name, ' ', u.last_name) as adviser 
            FROM sections s 
            LEFT JOIN users u ON s.adviser_id = u.id
            WHERE s.school_year = ?";
    $params = [$school_year];

    if (!empty($grade_level)) {
        $sql .= " AND grade_level = ?";
        $params[] = $grade_level;
    }
    if (!empty($section_id)) {
        $sql .= " AND id = ?";
        $params[] = $section_id;
    }

    $stmt_sections = $pdo->prepare($sql);
    $stmt_sections->execute($params);
    $sections = $stmt_sections->fetchAll(PDO::FETCH_ASSOC);

    $sections_summary = [];
    $school_summary = [
        'counts' => [
            'M' => ['enrolled' => 0, 'promoted' => 0, 'conditional' => 0, 'retained' => 0],
            'F' => ['enrolled' => 0, 'promoted' => 0, 'conditional' => 0, 'retained' => 0],
            'T' => ['enrolled' => 0, 'promoted' => 0, 'conditional' => 0, 'retained' => 0]
        ],
        'student_proficiency' => [
            'M' => ['Advanced' => 0, 'Proficient' => 0, 'Approaching' => 0, 'Developing' => 0, 'Beginning' => 0],
            'F' => ['Advanced' => 0, 'Proficient' => 0, 'Approaching' => 0, 'Developing' => 0, 'Beginning' => 0],
            'T' => ['Advanced' => 0, 'Proficient' => 0, 'Approaching' => 0, 'Developing' => 0, 'Beginning' => 0]
        ]
    ];

    foreach ($sections as $sec) {
        $section_name = $sec['section_name'];
        $grade_level = $sec['grade_level'];
        
        $section_summary = [
            'section_info' => $sec,
            'adviser' => $sec['adviser'] ?: 'Not Assigned',
            'counts' => [
                'M' => ['enrolled' => 0, 'promoted' => 0, 'conditional' => 0, 'retained' => 0],
                'F' => ['enrolled' => 0, 'promoted' => 0, 'conditional' => 0, 'retained' => 0],
                'T' => ['enrolled' => 0, 'promoted' => 0, 'conditional' => 0, 'retained' => 0]
            ],
            'student_proficiency' => [
                'M' => ['Advanced' => 0, 'Proficient' => 0, 'Approaching' => 0, 'Developing' => 0, 'Beginning' => 0],
                'F' => ['Advanced' => 0, 'Proficient' => 0, 'Approaching' => 0, 'Developing' => 0, 'Beginning' => 0],
                'T' => ['Advanced' => 0, 'Proficient' => 0, 'Approaching' => 0, 'Developing' => 0, 'Beginning' => 0]
            ]
        ];

        // 2. Data Retrieval (Dynamically fetched from gradebook to ensure real-time accuracy)
        $students_data = [];
        
        $stmt_live = $pdo->prepare("SELECT e.student_id, COALESCE(NULLIF(TRIM(r.sex), ''), NULLIF(TRIM(s.sex), ''), 'M') as sex, e.lrn,
                                   sf9.promotion_status as sf9_status, sf9.final_rating as sf9_rating
                                   FROM enrollments e 
                                   LEFT JOIN registrations r ON (e.registration_id = r.id OR TRIM(r.lrn) = TRIM(e.lrn))
                                   LEFT JOIN students s ON (e.student_id = s.student_id OR TRIM(e.lrn) = TRIM(s.student_id))
                                   LEFT JOIN sf9_reports sf9 ON (e.student_id = sf9.student_id AND e.school_year = sf9.school_year)
                                   WHERE e.grade_level = ? AND e.section = ? AND e.school_year = ?");
        $stmt_live->execute([$grade_level, $section_name, $school_year]);
        $live_students = $stmt_live->fetchAll(PDO::FETCH_ASSOC);

        // Dynamic grade calculation setup
        $stmt_subs = $pdo->prepare("SELECT id FROM curriculum WHERE grade_level = ? OR grade_level LIKE ?");
        $stmt_subs->execute([$grade_level, "%" . preg_replace('/[^0-9]/', '', $grade_level) . "%"]);
        $total_subs = count($stmt_subs->fetchAll(PDO::FETCH_COLUMN));

        foreach ($live_students as $ls) {
            $status = !empty($ls['sf9_status']) ? $ls['sf9_status'] : '';
            
            // ALWAYS recalculate from gradebook to ensure 1:1 parity with dynamic SF5
            $stmt_grades = $pdo->prepare("SELECT final_grade FROM sf9_grades WHERE student_id = ? AND school_year = ?");
            $stmt_grades->execute([$ls['student_id'], $school_year]);
            $grades = $stmt_grades->fetchAll(PDO::FETCH_COLUMN);

            $sum_final = 0; $count_final = 0;
            $fails = 0; $has_grades = false;
            $gwa = 0;

            foreach($grades as $final_grade) { 
                if ($final_grade > 0) {
                    $sum_final += $final_grade;
                    $count_final++;
                    $has_grades = true;
                    if($final_grade < 75) $fails++;
                }
            }
            
            if ($has_grades) {
                if (empty($status)) {
                    $status = 'PROMOTED';
                    if ($fails >= 3) $status = 'RETAINED';
                    elseif ($fails >= 1) $status = 'CONDITIONAL';
                }
                if ($count_final > 0) {
                    $gwa = round($sum_final / $count_final);
                }
            }

            $students_data[] = [
                'sex' => $ls['sex'],
                'general_average' => $gwa,
                'action_taken' => $status
            ];
        }

        // 3. Process and Aggregate
        foreach ($students_data as $sd) {
            $raw_sex = trim($sd['sex'] ?? 'M');
            $u_sex = strtoupper($raw_sex);
            $sex = ($u_sex === 'F' || $u_sex === 'FEMALE' || $u_sex === '2' || substr($u_sex, 0, 1) === 'F') ? 'F' : 'M';
            
            $raw_status = strtoupper(trim($sd['action_taken'] ?? ''));
            $status = '';
            
            // Comprehensive Status Matching
            if (strpos($raw_status, 'PROMOT') !== false || $raw_status === 'P') {
                $status = 'PROMOTED';
            } elseif (strpos($raw_status, 'RETAIN') !== false || $raw_status === 'R') {
                $status = 'RETAINED';
            } elseif (strpos($raw_status, 'CONDIT') !== false || strpos($raw_status, 'IRREG') !== false || $raw_status === 'C') {
                $status = 'CONDITIONAL';
            }

            // Always count enrollment
            $section_summary['counts'][$sex]['enrolled']++;
            $section_summary['counts']['T']['enrolled']++;
            
            if (!empty($status)) {
                $key = strtolower($status);
                $section_summary['counts'][$sex][$key]++;
                $section_summary['counts']['T'][$key]++;
            }

            // 4. Proficiency Level Mapping (Always count if average exists)
            $avg = round(floatval($sd['general_average'] ?? 0));
            if ($avg > 0) {
                $level = '';
                if ($avg >= 90) $level = 'Advanced';
                elseif ($avg >= 85) $level = 'Proficient';
                elseif ($avg >= 80) $level = 'Approaching';
                elseif ($avg >= 75) $level = 'Developing';
                else $level = 'Beginning';

                $section_summary['student_proficiency'][$sex][$level]++;
                $section_summary['student_proficiency']['T'][$level]++;
            }
        }

        $sections_summary[] = $section_summary;

        // School-Wide Aggregation
        foreach (['M', 'F', 'T'] as $g) {
            foreach (['enrolled', 'promoted', 'conditional', 'retained'] as $k) {
                $school_summary['counts'][$g][$k] += $section_summary['counts'][$g][$k];
            }
            foreach (['Advanced', 'Proficient', 'Approaching', 'Developing', 'Beginning'] as $l) {
                $school_summary['student_proficiency'][$g][$l] += $section_summary['student_proficiency'][$g][$l];
            }
        }
    }

    return [
        'sections' => $sections_summary,
        'school_summary' => $school_summary
    ];
}
/**
 * Dedicated Computation Logic for the SF6 Proficiency Matrix
 * Classifies learners based on final general average per DepED SF6 standards.
 */
function getSF6ProficiencyMatrix($pdo, $school_year, $grade_level = '')
{
    // 1. Proficiency Levels Definition
    $levels = [
        'Advanced' => ['min' => 90, 'max' => 100, 'label' => 'Advanced'],
        'Proficient' => ['min' => 85, 'max' => 89, 'label' => 'Proficient'],
        'Approaching' => ['min' => 80, 'max' => 84, 'label' => 'Approaching Proficiency'],
        'Developing' => ['min' => 75, 'max' => 79, 'label' => 'Developing'],
        'Beginning' => ['min' => 0, 'max' => 74, 'label' => 'Beginning']
    ];

    // 2. Fetch all classified learners (Dynamically computed from gradebook)
    $students = classifyLearnerProficiency($pdo, $school_year, $grade_level);

    // 3. Initialize Matrix
    $matrix = [];
    $grand_total = [];
    foreach ($levels as $key => $info) {
        $grand_total[$key] = ['M' => 0, 'F' => 0, 'T' => 0];
    }

    // 4. Group results by Grade Level and Proficiency
    foreach ($students as $stud) {
        $gl = $stud['grade_level'];
        $assigned_level = $stud['proficiency_level'];
        $sex = $stud['sex'];

        if (!isset($matrix[$gl])) {
            foreach ($levels as $key => $info) {
                $matrix[$gl][$key] = ['M' => 0, 'F' => 0, 'T' => 0];
            }
        }

        if ($assigned_level && isset($matrix[$gl][$assigned_level])) {
            $matrix[$gl][$assigned_level][$sex]++;
            $matrix[$gl][$assigned_level]['T']++;
            
            $grand_total[$assigned_level][$sex]++;
            $grand_total[$assigned_level]['T']++;
        }
    }

    // Sort Grade Levels
    ksort($matrix);

    return [
        'levels' => $levels,
        'matrix' => $matrix,
        'grand_total' => $grand_total,
        'count' => count($students)
    ];
}

/**
 * Classification Engine for Proficiency Levels ONLY
 * Returns student_id, general_average, and proficiency_level per student.
 * Dynamically computes from sf9_grades (Quarterly Grades) as the source of truth.
 */
function classifyLearnerProficiency($pdo, $school_year, $grade_level = '')
{
    $sql = "SELECT e.student_id, e.grade_level,
                   COALESCE(NULLIF(TRIM(r.sex), ''), NULLIF(TRIM(s.sex), ''), 'M') as sex
            FROM enrollments e 
            LEFT JOIN registrations r ON (e.registration_id = r.id OR TRIM(e.lrn) = TRIM(r.lrn))
            LEFT JOIN students s ON (e.student_id = s.student_id OR TRIM(e.lrn) = TRIM(s.student_id))
            WHERE e.school_year = ?";
    
    $params = [$school_year];
    if (!empty($grade_level)) {
        $sql .= " AND e.grade_level = ?";
        $params[] = $grade_level;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $classified_data = [];

    foreach ($enrollments as $ls) {
        $sid = $ls['student_id'];
        
        // Live Path: Always calculate from gradebook to match SF5/SF9 standards
        $stmt_g = $pdo->prepare("SELECT final_grade FROM sf9_grades WHERE student_id = ? AND school_year = ? AND final_grade > 0");
        $stmt_g->execute([$sid, $school_year]);
        $grades = $stmt_g->fetchAll(PDO::FETCH_COLUMN);

        $avg = 0;
        $level = 'Beginning';

        if (count($grades) > 0) {
            $avg = round(array_sum($grades) / count($grades));
            
            // DepEd Classification Rules
            if ($avg <= 74) $level = 'Beginning';
            elseif ($avg >= 75 && $avg <= 79) $level = 'Developing';
            elseif ($avg >= 80 && $avg <= 84) $level = 'Approaching';
            elseif ($avg >= 85 && $avg <= 89) $level = 'Proficient';
            elseif ($avg >= 90) $level = 'Advanced';

            $classified_data[] = [
                'student_id' => $sid,
                'grade_level' => $ls['grade_level'],
                'sex' => ($ls['sex'] == 'F' || $ls['sex'] == 'Female' || $ls['sex'] == '2') ? 'F' : 'M',
                'general_average' => $avg,
                'proficiency_level' => $level
            ];
        }
    }

    return $classified_data;
}

/**
 * Aggregates SF6 data for the entire school summarized by Grade Level
 * Used specifically for the official print layout.
 */
function generateSF6SchoolSummary($pdo, $school_year, $target_grade = '')
{
    if (!empty($target_grade)) {
        $grade_levels = [$target_grade];
    } else {
        // Improved natural sorting for grade levels (handles strings like 'Grade 7', 'Grade 10')
        $stmt_gls = $pdo->prepare("SELECT DISTINCT grade_level FROM sections WHERE school_year = ? ORDER BY LENGTH(grade_level), grade_level");
        $stmt_gls->execute([$school_year]);
        $grade_levels = $stmt_gls->fetchAll(PDO::FETCH_COLUMN);
    }

    $summary_data = [];
    foreach ($grade_levels as $gl) {
        $sec_res = generateSF6($pdo, $school_year, $gl);
        $summary_data[$gl] = $sec_res['school_summary'];
    }

    return [
        'grade_levels' => $grade_levels,
        'data' => $summary_data
    ];
}
?>
