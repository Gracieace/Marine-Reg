<?php
/**
 * SF8 Engine - Shared Data Logic for School Form 8
 * Ensures identical data between dashboard and print views
 */

function getSF8Data($pdo, $sy, $grade, $section) {
    // 1. NORMALIZE FILTERS
    $grade_clean = trim(str_ireplace('Grade', '', $grade));
    $grade_full = "Grade " . $grade_clean;
    
    $section_clean = trim(str_ireplace('Section', '', $section));
    $section_full = "Section " . $section_clean;

    // Normalize SY (handle spaces)
    $sy_clean = str_replace(' ', '', $sy); // "2024-2025"
    $sy_with_spaces = str_replace('-', ' - ', $sy_clean); // "2024 - 2025"

    // 2. USE CORRECT QUERY (REQUIRED)
    $sql = "SELECT 
                COALESCE(r.lrn, e.lrn) as lrn, 
                COALESCE(r.last_name, e.student_name) as last_name, 
                r.first_name, 
                r.middle_name, 
                r.birthdate, 
                r.sex,
                CONCAT(COALESCE(r.last_name, ''), ', ', COALESCE(r.first_name, ''), ' ', COALESCE(r.middle_name,'')) as formatted_name,
                e.student_id,
                h.weight_kg, h.height_m, h.bmi, h.nutritional_status, h.hfa, h.measurement_date, h.condition_remarks,
                h.is_dewormed, h.vision_screening
            FROM enrollments e
            LEFT JOIN registrations r ON (r.id = e.registration_id OR (e.registration_id IS NULL AND r.lrn = e.lrn))
            LEFT JOIN sf8_health_profile h ON (e.student_id = h.student_id AND h.school_year = e.school_year)
            WHERE (e.school_year = ? OR e.school_year = ? OR e.school_year = ?) 
              AND (e.grade_level = ? OR e.grade_level = ? OR e.grade_level = ?)
              AND (e.section = ? OR e.section = ? OR e.section = ?)
            ORDER BY r.sex DESC, r.last_name ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $sy, $sy_clean, $sy_with_spaces,
        $grade, $grade_clean, $grade_full,
        $section, $section_clean, $section_full
    ]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. DEBUG FETCH RESULT (CRITICAL)
    if (isset($_GET['debug_sf8'])) {
        echo "<pre>";
        print_r($list);
        echo "</pre>";
    }

    if (empty($list)) {
        echo "<div style='background:#f8fafc; border:2px solid #e2e8f0; padding:20px; border-radius:12px; margin:20px; font-family:monospace; color:#1e293b;'>";
        echo "<h4 style='margin-top:0; color:#4f46e5;'>🔍 No Data Found</h4>";
        echo "<strong>Checked Filters:</strong> SY: $sy | Grade: $grade_full | Section: $section_full<br>";
        echo "Please verify if the 'enrollments' table contains records for this criteria.";
        echo "</div>";
    }

    // 5. DATA NORMALIZATION (Ensuring UI compatibility)
    foreach ($list as &$s) {
        $s['sex'] = !empty($s['sex']) ? strtoupper(substr($s['sex'],0,1)) : 'M';
        $s['age'] = '';
        if (!empty($s['birthdate'])) {
            $s['age'] = date_diff(date_create($s['birthdate']), date_create('today'))->y;
        }
        $s['height_sq'] = !empty($s['height_m']) ? number_format($s['height_m'] * $s['height_m'], 4) : '';
    }
    unset($s);
    
    return $list;
}

function computeSF8Summary($males, $females) {
    $sum = [
        'bmi' => [
            'Severely Wasted' => ['M'=>0,'F'=>0,'Total'=>0],
            'Wasted' => ['M'=>0,'F'=>0,'Total'=>0],
            'Normal' => ['M'=>0,'F'=>0,'Total'=>0],
            'Overweight' => ['M'=>0,'F'=>0,'Total'=>0],
            'Obese' => ['M'=>0,'F'=>0,'Total'=>0]
        ],
        'hfa' => [
            'Severely Stunted' => ['M'=>0,'F'=>0,'Total'=>0],
            'Stunted' => ['M'=>0,'F'=>0,'Total'=>0],
            'Normal' => ['M'=>0,'F'=>0,'Total'=>0],
            'Tall' => ['M'=>0,'F'=>0,'Total'=>0]
        ],
        'medical' => [
            'dewormed' => 0,
            'vision_pass' => 0
        ]
    ];
    
    foreach (array_merge($males, $females) as $s) {
        $ns = $s['nutritional_status'] ?? '';
        $is_male = strtoupper(substr($s['sex']??'M',0,1)) !== 'F';
        $key = $is_male ? 'M' : 'F';
        
        if (isset($sum['bmi'][$ns])) { $sum['bmi'][$ns][$key]++; $sum['bmi'][$ns]['Total']++; }
        $hfa = $s['hfa'] ?? '';
        if (isset($sum['hfa'][$hfa])) { $sum['hfa'][$hfa][$key]++; $sum['hfa'][$hfa]['Total']++; }
        
        if (!empty($s['is_dewormed'])) $sum['medical']['dewormed']++;
        if (!empty($s['vision_screening']) && strtolower($s['vision_screening']) !== 'failed') $sum['medical']['vision_pass']++;
    }
    return $sum;
}

function classifyBMI($bmi) {
    if ($bmi <= 0) return '---';
    if ($bmi < 16.0) return 'Severely Wasted';
    if ($bmi < 18.5) return 'Wasted';
    if ($bmi < 25.0) return 'Normal';
    if ($bmi < 30.0) return 'Overweight';
    return 'Obese';
}

function classifyHFA($h, $age, $sex) {
    if ($h <= 0 || $age <= 0) return 'Normal';
    $is_male = strtoupper(substr($sex??'M',0,1)) !== 'F';
    
    // WHO-aligned Growth Standard Approximations (Adolescents 12-18)
    // Format: [Tall Threshold (m), Stunted Threshold (m)]
    $norms = [
        12 => ['M' => [1.60, 1.40], 'F' => [1.57, 1.40]],
        13 => ['M' => [1.65, 1.45], 'F' => [1.60, 1.43]],
        14 => ['M' => [1.70, 1.50], 'F' => [1.62, 1.45]],
        15 => ['M' => [1.75, 1.55], 'F' => [1.65, 1.48]],
        16 => ['M' => [1.78, 1.60], 'F' => [1.67, 1.50]],
        17 => ['M' => [1.80, 1.62], 'F' => [1.68, 1.52]],
        18 => ['M' => [1.81, 1.63], 'F' => [1.69, 1.53]]
    ];
    
    $lookup = $age < 12 ? 12 : ($age > 18 ? 18 : (int)$age);
    $data = $norms[$lookup][$is_male ? 'M' : 'F'];
    
    $tall_limit = $data[0];
    $stunted_limit = $data[1];
    $median = ($tall_limit + $stunted_limit) / 2;
    $sd = ($tall_limit - $median) / 2;
    
    $z = ($h - $median) / $sd;

    if ($z < -3) return 'Severely Stunted';
    if ($z < -2) return 'Stunted';
    if ($z > 2) return 'Tall';
    return 'Normal';
}

function saveHealthRecord($pdo, $student_id, $school_year, $weight, $height, $hfa, $remarks) {
    $w = (float)$weight;
    $h = (float)$height;
    if ($h > 3.0) $h = $h / 100; // cm to m
    
    // Fetch age and sex for auto-HFA if needed
    $stmt = $pdo->prepare("SELECT birthdate, sex FROM registrations WHERE id = (SELECT registration_id FROM enrollments WHERE student_id = ? AND school_year = ? LIMIT 1)");
    $stmt->execute([$student_id, $school_year]);
    $student = $stmt->fetch();
    if (!$student) {
        $stmt = $pdo->prepare("SELECT birthdate, sex FROM students WHERE id = (SELECT student_id FROM enrollments WHERE student_id = ? AND school_year = ? LIMIT 1)");
        $stmt->execute([$student_id, $school_year]);
        $student = $stmt->fetch();
    }
    
    $age = $student ? date_diff(date_create($student['birthdate']), date_create('today'))->y : 0;
    $sex = $student['sex'] ?? 'M';
    
    $bmi = ($h > 0 && $w > 0) ? round($w / ($h * $h), 2) : 0;
    $cat = classifyBMI($bmi);
    
    // Auto-calculate HFA if it's empty or 'Auto'
    $final_hfa = (!empty($hfa) && $hfa !== 'Auto') ? $hfa : classifyHFA($h, $age, $sex);
    
    $sql = "INSERT INTO sf8_health_profile (student_id, school_year, weight_kg, height_m, bmi, nutritional_status, hfa, measurement_date, condition_remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, ?)
            ON DUPLICATE KEY UPDATE 
            weight_kg = VALUES(weight_kg), 
            height_m = VALUES(height_m), 
            bmi = VALUES(bmi), 
            nutritional_status = VALUES(nutritional_status), 
            hfa = VALUES(hfa), 
            measurement_date = VALUES(measurement_date),
            condition_remarks = VALUES(condition_remarks)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id, $school_year, $w, $h, $bmi, $cat, $final_hfa, $remarks]);
}
