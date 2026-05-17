<?php
/**
 * SF4 Logic - Shared Aggregation Engine
 */

function generateSF4($pdo, $grade_level_filter = '', $section_filter = '', $school_year = '', $month = 'June')
{
    // 1. Report Header Data from settings
    $settings = [
        'school_id' => '300750',
        'school_name' => 'MALOLOS MARINE FISHERY SCHOOL AND LABORATORY',
        'region' => 'REGION III',
        'division' => 'MALOLOS CITY',
        'district' => 'DISTRICT X'
    ];
    
    try {
        $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('region', 'division', 'district', 'school_name', 'school_id')");
        if ($stmt_set) {
            while ($s = $stmt_set->fetch()) $settings[$s['setting_key']] = $s['setting_value'];
        }
    } catch (Exception $e) { /* keep defaults */ }

    $month_names = ['January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12];
    $month_num = $month_names[$month] ?? date('n');
    
    $sy_parts = explode('-', $school_year);
    $sy_start_year = (int)($sy_parts[0] ?? date('Y'));
    $report_year = ($month_num >= 6) ? $sy_start_year : ($sy_start_year + 1);
    
    $days_in_month = date('t', strtotime("$report_year-$month_num-01"));
    $start_date = "$report_year-" . str_pad($month_num, 2, '0', STR_PAD_LEFT) . "-01";
    $end_date = "$report_year-" . str_pad($month_num, 2, '0', STR_PAD_LEFT) . "-" . $days_in_month;

    $stmt_cal = $pdo->prepare("SELECT num_days FROM school_calendar WHERE school_year = ? AND month = ?");
    $stmt_cal->execute([$school_year, $month]);
    $num_school_days = (int)$stmt_cal->fetchColumn() ?: 20;

    // Fetch Sections
    $gl_match = $grade_level_filter ? "$grade_level_filter" : "%";
    $sec_match = $section_filter ? "$section_filter" : "%";

    $stmt_sects = $pdo->prepare("SELECT s.id as section_id, s.grade_level, s.section_name as section, 
            COALESCE(
                NULLIF(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name), ''),
                NULLIF(CONCAT_WS(' ', t_u.first_name, t_u.middle_name, t_u.last_name), ''),
                'TBA'
            ) as adviser
            FROM sections s
            LEFT JOIN users u ON s.adviser_id = u.id
            LEFT JOIN teachers t ON s.adviser_id = t.id
            LEFT JOIN users t_u ON t.user_id = t_u.id
            WHERE s.grade_level LIKE ? AND s.section_name LIKE ? AND s.school_year = ?
            ORDER BY s.grade_level ASC, s.section_name ASC");
    $stmt_sects->execute([$gl_match, $sec_match, $school_year]);
    $sections_list = $stmt_sects->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sections_list)) return [];

    // Get Official SF2 Monthly Summaries
    try {
        $stmt_sf2 = $pdo->prepare("SELECT r.grade_level, r.section, 
                s.registered_male_eom as reg_m, s.registered_female_eom as reg_f,
                s.average_daily_attendance as ada, s.percentage_attendance as perc,
                s.days_of_classes,
                COALESCE(s.ada_male, 0) as ada_male, COALESCE(s.ada_female, 0) as ada_female, 
                COALESCE(s.perc_male, 0) as perc_male, COALESCE(s.perc_female, 0) as perc_female,
                COALESCE(s.transferred_in, 0) as tin, COALESCE(s.transferred_out, 0) as tout, COALESCE(s.nls_count, 0) as drop_out,
                s.enrolment_male_bosy as beg_m, s.enrolment_female_bosy as beg_f,
                r.id as report_id
                FROM sf2_monthly_summary s
                JOIN sf2_reports r ON s.sf2_report_id = r.id
                WHERE r.school_year = ? AND r.report_month = ?");
        $stmt_sf2->execute([$school_year, $month]);
    } catch (Exception $e) {
        $stmt_sf2 = $pdo->prepare("SELECT r.grade_level, r.section, 
                s.registered_male_eom as reg_m, s.registered_female_eom as reg_f,
                s.average_daily_attendance as ada, s.percentage_attendance as perc,
                s.days_of_classes,
                s.enrolment_male_bosy as beg_m, s.enrolment_female_bosy as beg_f,
                r.id as report_id
                FROM sf2_monthly_summary s
                JOIN sf2_reports r ON s.sf2_report_id = r.id
                WHERE r.school_year = ? AND r.report_month = ?");
        $stmt_sf2->execute([$school_year, $month]);
    }
    $sf2_summaries = [];
    while ($r = $stmt_sf2->fetch()) {
        if (!isset($r['ada_male']) || ($r['ada_male'] == 0 && $r['ada_female'] == 0)) {
            $stmt_studs = $pdo->prepare("SELECT sex, SUM(total_present) as present FROM sf2_student_records WHERE sf2_report_id = ? GROUP BY sex");
            $stmt_studs->execute([$r['report_id']]);
            $days = (int)$r['days_of_classes'] ?: $num_school_days;
            while($st = $stmt_studs->fetch()) {
                $sex = strtoupper(substr($st['sex'] ?? 'M', 0, 1));
                $val = ($days > 0) ? round($st['present'] / $days, 2) : 0;
                if ($sex === 'M') $r['ada_male'] = $val;
                else $r['ada_female'] = $val;
            }
            if ($r['reg_m'] > 0) $r['perc_male'] = round(($r['ada_male'] / $r['reg_m']) * 100, 2);
            if ($r['reg_f'] > 0) $r['perc_female'] = round(($r['ada_female'] / $r['reg_f']) * 100, 2);
        }
        $sf2_summaries[$r['grade_level']][$r['section']] = $r;
    }

    // SF1 Summaries
    $stmt_sf1 = $pdo->prepare("SELECT r.grade_level, r.section, 
            s.total_male as reg_m, s.total_female as reg_f, s.total_combined as reg_t
            FROM sf1_summary s
            JOIN sf1_reports r ON s.sf1_report_id = r.id
            WHERE r.school_year = ?");
    $stmt_sf1->execute([$school_year]);
    $sf1_summaries = [];
    while ($r = $stmt_sf1->fetch()) $sf1_summaries[$r['grade_level']][$r['section']] = $r;

    // Movement Data (Defensive wrapper for missing tables)
    $mov_data = [];
    $cum_data = [];
    try {
        $stmt_mov = $pdo->prepare("SELECT e.grade_level, e.section, m.movement_type, r.sex, COUNT(*) as count
                FROM student_movements m
                JOIN enrollments e ON m.student_id = e.student_id AND m.school_year = e.school_year
                LEFT JOIN registrations r ON e.registration_id = r.id
                WHERE m.movement_date BETWEEN ? AND ? AND m.school_year = ?
                GROUP BY e.grade_level, e.section, m.movement_type, r.sex");
        $stmt_mov->execute([$start_date, $end_date, $school_year]);
        while ($r = $stmt_mov->fetch()) $mov_data[$r['grade_level']][$r['section']][$r['movement_type']][$r['sex']] = $r['count'];

        $stmt_cum = $pdo->prepare("SELECT e.grade_level, e.section, m.movement_type, r.sex, COUNT(*) as count
                FROM student_movements m
                JOIN enrollments e ON m.student_id = e.student_id AND m.school_year = e.school_year
                LEFT JOIN registrations r ON e.registration_id = r.id
                WHERE m.movement_date < ? AND m.school_year = ?
                GROUP BY e.grade_level, e.section, m.movement_type, r.sex");
        $stmt_cum->execute([$start_date, $school_year]);
        while ($r = $stmt_cum->fetch()) $cum_data[$r['grade_level']][$r['section']][$r['movement_type']][$r['sex']] = $r['count'];
    } catch (Exception $e) { /* student_movements table might not exist yet */ }

    $final_rows = [];
    foreach ($sections_list as $s) {
        $gl = $s['grade_level'];
        $sec = $s['section'];
        $sec_suffix = preg_replace('/^Grade\s+\d+\s*-\s*/i', '', $sec);
        
        $sum = $sf2_summaries[$gl][$sec] ?? ($sf2_summaries[$gl][$sec_suffix] ?? null);
        $sf1_sum = $sf1_summaries[$gl][$sec] ?? ($sf1_summaries[$gl][$sec_suffix] ?? null);

        $reg_m = $sf1_sum['reg_m'] ?? 0;
        $reg_f = $sf1_sum['reg_f'] ?? 0;
        $reg_t = $sf1_sum['reg_t'] ?? ($reg_m + $reg_f);

        // Baseline from student_movements logs (with safe array access)
        $m_in_m = $mov_data[$gl][$sec]['Transferred In']['M'] ?? 0;
        $m_in_f = $mov_data[$gl][$sec]['Transferred In']['F'] ?? 0;
        $m_out_m = $mov_data[$gl][$sec]['Transferred Out']['M'] ?? 0;
        $m_out_f = $mov_data[$gl][$sec]['Transferred Out']['F'] ?? 0;
        $m_drop_m = $mov_data[$gl][$sec]['Dropped Out']['M'] ?? 0;
        $m_drop_f = $mov_data[$gl][$sec]['Dropped Out']['F'] ?? 0;
        $m_mort_m = $mov_data[$gl][$sec]['Mortality']['M'] ?? 0;
        $m_mort_f = $mov_data[$gl][$sec]['Mortality']['F'] ?? 0;
        $m_late_m = $mov_data[$gl][$sec]['Late Enrollment']['M'] ?? 0;
        $m_late_f = $mov_data[$gl][$sec]['Late Enrollment']['F'] ?? 0;

        $p_in_m = $cum_data[$gl][$sec]['Transferred In']['M'] ?? 0;
        $p_in_f = $cum_data[$gl][$sec]['Transferred In']['F'] ?? 0;
        $p_out_m = $cum_data[$gl][$sec]['Transferred Out']['M'] ?? 0;
        $p_out_f = $cum_data[$gl][$sec]['Transferred Out']['F'] ?? 0;
        $p_drop_m = $cum_data[$gl][$sec]['Dropped Out']['M'] ?? 0;
        $p_drop_f = $cum_data[$gl][$sec]['Dropped Out']['F'] ?? 0;
        $p_mort_m = $cum_data[$gl][$sec]['Mortality']['M'] ?? 0;
        $p_mort_f = $cum_data[$gl][$sec]['Mortality']['F'] ?? 0;
        $p_late_m = $cum_data[$gl][$sec]['Late Enrollment']['M'] ?? 0;
        $p_late_f = $cum_data[$gl][$sec]['Late Enrollment']['F'] ?? 0;

        // SF1 Remarks Data (Supplementing movement logs)
        $stmt_sf1_rem = $pdo->prepare("SELECT r.sex, r.remarks, r.remarks_code
                FROM sf1_student_records r
                JOIN sf1_reports rep ON r.sf1_report_id = rep.id
                WHERE rep.grade_level = ? AND rep.section = ? AND rep.school_year = ?
                AND (r.remarks_code IS NOT NULL OR r.remarks IS NOT NULL)");
        $stmt_sf1_rem->execute([$gl, $sec, $school_year]);
        while($rem = $stmt_sf1_rem->fetch()) {
            $code = strtoupper($rem['remarks_code'] ?? '');
            $text = strtoupper($rem['remarks'] ?? '');
            $sex = strtoupper(substr($rem['sex'] ?? 'M', 0, 1));
            
            // Identify Movement Type using provided mappings
            $type = '';
            if ($code === 'TO' || $code === 'T/O' || strpos($text, 'TRANSFERRED OUT') !== false || strpos($text, 'T/O') !== false || strpos($text, 'TO') !== false) $type = 'Transferred Out';
            elseif ($code === 'TI' || $code === 'T/I' || strpos($text, 'TRANSFERRED IN') !== false || strpos($text, 'T/I') !== false || strpos($text, 'TI') !== false) $type = 'Transferred In';
            elseif ($code === 'BRP' || $code === 'D/O' || strpos($text, 'DROPPED OUT') !== false || strpos($text, 'D/O') !== false || strpos($text, 'BRP') !== false) $type = 'Dropped Out';
            elseif ($code === 'M' || strpos($text, 'MORTALITY') !== false || strpos($text, 'DECEASED') !== false) $type = 'Mortality';

            if ($type) {
                // Determine if it belongs to the current Month or Previous
                $is_current = (strpos($text, strtoupper($month)) !== false);
                
                if ($type === 'Transferred Out') {
                    if ($is_current) $m_out_f += ($sex==='F'?1:0); else $p_out_f += ($sex==='F'?1:0);
                    if ($is_current) $m_out_m += ($sex==='M'?1:0); else $p_out_m += ($sex==='M'?1:0);
                } elseif ($type === 'Transferred In') {
                    if ($is_current) $m_in_f += ($sex==='F'?1:0); else $p_in_f += ($sex==='F'?1:0);
                    if ($is_current) $m_in_m += ($sex==='M'?1:0); else $p_in_m += ($sex==='M'?1:0);
                } elseif ($type === 'Dropped Out') {
                    if ($is_current) $m_drop_f += ($sex==='F'?1:0); else $p_drop_f += ($sex==='F'?1:0);
                    if ($is_current) $m_drop_m += ($sex==='M'?1:0); else $p_drop_m += ($sex==='M'?1:0);
                } elseif ($type === 'Mortality') {
                    if ($is_current) $m_mort_f += ($sex==='F'?1:0); else $p_mort_f += ($sex==='F'?1:0);
                    if ($is_current) $m_mort_m += ($sex==='M'?1:0); else $p_mort_m += ($sex==='M'?1:0);
                } elseif ($type === 'Late Enrollment') {
                    if ($is_current) $m_late_f += ($sex==='F'?1:0); else $p_late_f += ($sex==='F'?1:0);
                    if ($is_current) $m_late_m += ($sex==='M'?1:0); else $p_late_m += ($sex==='M'?1:0);
                }
            }
        }

        $final_rows[] = [
            'grade_level' => $gl, 'section' => $sec, 'adviser' => $s['adviser'],
            'reg_m' => $reg_m, 'reg_f' => $reg_f, 'reg_t' => $reg_t,
            'ada_m' => $sum['ada_male'] ?? 0, 'ada_f' => $sum['ada_female'] ?? 0, 'ada_t' => $sum['ada'] ?? 0,
            'perc_m' => $sum['perc_male'] ?? 0, 'perc_f' => $sum['perc_female'] ?? 0, 'perc_t' => $sum['perc'] ?? 0,
            'p_drop_m' => $p_drop_m, 'p_drop_f' => $p_drop_f, 'p_drop_t' => ($p_drop_m + $p_drop_f),
            'm_drop_m' => $m_drop_m, 'm_drop_f' => $m_drop_f, 'm_drop_t' => ($m_drop_m + $m_drop_f),
            'p_tout_m' => $p_out_m, 'p_tout_f' => $p_out_f, 'p_tout_t' => ($p_out_m + $p_out_f),
            'm_tout_m' => $m_out_m, 'm_tout_f' => $m_out_f, 'm_tout_t' => ($m_out_m + $m_out_f),
            'p_tin_m' => $p_in_m, 'p_tin_f' => $p_in_f, 'p_tin_t' => ($p_in_m + $p_in_f),
            'm_tin_m' => $m_in_m, 'm_tin_f' => $m_in_f, 'm_tin_t' => ($m_in_m + $m_in_f),
            'p_mort_m' => $p_mort_m, 'p_mort_f' => $p_mort_f, 'p_mort_t' => ($p_mort_m + $p_mort_f),
            'm_mort_m' => $m_mort_m, 'm_mort_f' => $m_mort_f, 'm_mort_t' => ($m_mort_m + $m_mort_f),
            'p_late_m' => $p_late_m, 'p_late_f' => $p_late_f, 'p_late_t' => ($p_late_m + $p_late_f),
            'm_late_m' => $m_late_m, 'm_late_f' => $m_late_f, 'm_late_t' => ($m_late_m + $m_late_f)
        ];
    }
    return $final_rows;
}
