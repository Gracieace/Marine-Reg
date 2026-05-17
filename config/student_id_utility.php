<?php
/**
 * Student ID & QR Code Utility
 * Centralized logic for ID generation and QR code creation
 */

require_once __DIR__ . '/db.php';

/**
 * Generate a unique student ID in the format YYYY-XXXX
 * YYYY = school year start year (e.g., 2025 for 2025-2026)
 * XXXX = incremental number starting at 0001
 */
function generateStudentId($pdo, ?string $school_year = null) {
    // Determine prefix year
    $prefixYear = null;
    if (!empty($school_year) && preg_match('/^(\d{4})-\d{4}$/', trim($school_year), $m)) {
        $prefixYear = (int) $m[1];
    } elseif (!empty($school_year) && preg_match('/^(\d{4})-/', trim($school_year), $m)) {
        $prefixYear = (int) $m[1];
    }

    // Fallback: use configured current_school_year start year
    if (!$prefixYear) {
        try {
            $sy = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year' LIMIT 1")->fetchColumn();
            if ($sy && preg_match('/^(\d{4})-/', (string)$sy, $m)) {
                $prefixYear = (int) $m[1];
            }
        } catch (Exception $e) {
            // ignore and fallback to system date
        }
    }

    if (!$prefixYear) {
        $prefixYear = (int) date('Y');
    }

    $prefix = $prefixYear . '-';
    $like = $prefix . '%';

    // Get the maximum sequential number for the current year from students table
    $stmt = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(student_id, 6) AS UNSIGNED)) AS max_seq FROM students WHERE student_id LIKE ?');
    $stmt->execute([$like]);
    $row = $stmt->fetch();
    
    // Also check enrollments table for backwards compatibility/consistency during transition
    $stmt2 = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(student_id, 6) AS UNSIGNED)) AS max_seq FROM enrollments WHERE student_id LIKE ?');
    $stmt2->execute([$like]);
    $row2 = $stmt2->fetch();

    $max_seq = max((int)($row['max_seq'] ?? 0), (int)($row2['max_seq'] ?? 0));
    $next = $max_seq + 1;

    // Ensure uniqueness
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);

        $check = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ? LIMIT 1');
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
        $next++;
    }

    // Fallback if loop fails
    return $prefix . str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Generate QR Code for a student
 * Stores student_id and profile URL
 */
function generateStudentQRCode($studentId, $studentName) {
    // Ensure URL helper exists
    if (!function_exists('url_for')) {
        require_once __DIR__ . '/app.php';
    }

    // Save to the same folder the ID-card templates/UI expect
    $qrDir = dirname(__DIR__) . '/assets/qr_codes/';
    if (!is_dir($qrDir)) {
        mkdir($qrDir, 0755, true);
    }

    $fileName = 'qr_' . $studentId . '.svg';
    $filePath = $qrDir . $fileName;

    // Reuse existing QR if present
    if (file_exists($filePath)) {
        return url_for('/assets/qr_codes/' . $fileName);
    }

    $qrData = json_encode([
        'student_id' => $studentId,
        'student_name' => $studentName,
        'generated_at' => date('Y-m-d H:i:s'),
    ]);

    // SVG Content Generation (Deterministic QR-like pattern)
    $svgContent = generateSimpleQR_SVG($qrData, 1200);
    file_put_contents($filePath, $svgContent);

    return url_for('/assets/qr_codes/' . $fileName);
}

/**
 * Simple SVG "QR" Pattern Generator
 * (Adapted from existing enrollment.php logic)
 */
function generateSimpleQR_SVG($data, $size = 1200) {
    $qrSize = 25; // 25x25 grid
    $cellSize = $size / $qrSize;
    $hash = md5($data);
    $pattern = [];

    // Base pattern
    for ($i = 0; $i < $qrSize; $i++) {
        for ($j = 0; $j < $qrSize; $j++) {
            $index = ($i * $qrSize + $j) % strlen($hash);
            $char = $hash[$index];
            $value = hexdec($char);
            $pattern[$i][$j] = (($value + $i + $j) % 3 == 0) ? 1 : 0;
        }
    }

    // Standard QR Markers
    // Top-left
    for ($i = 0; $i < 7; $i++) for ($j = 0; $j < 7; $j++) 
        $pattern[$i][$j] = (($i == 0 || $i == 6 || $j == 0 || $j == 6) || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)) ? 1 : 0;
    // Top-right
    for ($i = 0; $i < 7; $i++) for ($j = $qrSize - 7; $j < $qrSize; $j++)
        $pattern[$i][$j] = (($i == 0 || $i == 6 || $j == $qrSize - 7 || $j == $qrSize - 1) || ($i >= 2 && $i <= 4 && $j >= $qrSize - 5 && $j <= $qrSize - 3)) ? 1 : 0;
    // Bottom-left
    for ($i = $qrSize - 7; $i < $qrSize; $i++) for ($j = 0; $j < 7; $j++)
        $pattern[$i][$j] = (($i == $qrSize - 7 || $i == $qrSize - 1 || $j == 0 || $j == 6) || ($i >= $qrSize - 5 && $i <= $qrSize - 3 && $j >= 2 && $j <= 4)) ? 1 : 0;

    $svg = '<svg width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="white"/>';
    for ($i = 0; $i < $qrSize; $i++) {
        for ($j = 0; $j < $qrSize; $j++) {
            if (isset($pattern[$i][$j]) && $pattern[$i][$j] == 1) {
                $svg .= '<rect x="' . ($j * $cellSize) . '" y="' . ($i * $cellSize) . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="black"/>';
            }
        }
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * Sync data to the permanent students table
 */
function syncToStudentsTable($pdo, $studentData) {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO students (student_id, first_name, last_name, course, year_level, qr_code_path)
            VALUES (:student_id, :first_name, :last_name, :course, :year_level, :qr_code_path)
            ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            course = VALUES(course),
            year_level = VALUES(year_level),
            qr_code_path = VALUES(qr_code_path)
        ');
        
        $stmt->execute([
            ':student_id' => $studentData['student_id'],
            ':first_name' => $studentData['first_name'],
            ':last_name' => $studentData['last_name'],
            ':course' => $studentData['course'] ?? null,
            ':year_level' => $studentData['year_level'] ?? null,
            ':qr_code_path' => $studentData['qr_code_path'] ?? null
        ]);
        return true;
    } catch (Exception $e) {
        error_log('Sync to students table failed: ' . $e->getMessage());
        return false;
    }
}
?>
