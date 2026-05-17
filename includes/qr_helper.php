<?php
/**
 * QR Helper Utility for Student ID and Re-Enrollment System
 */

class QRHelper {
    private static $salt = "MMFSL_SECURE_QR_2026"; // Secret salt for token generation

    /**
     * Generate a unique student ID number in format: [SchoolYear]-[SequentialNumber]
     * Example: 2026-000145
     */
    public static function generateIDNumber($pdo, $school_year) {
        $year_prefix = substr($school_year, 0, 4);
        
        // Find the latest number for this school year
        $stmt = $pdo->prepare("SELECT id_number FROM school_ids WHERE id_number LIKE ? ORDER BY id_number DESC LIMIT 1");
        $stmt->execute([$year_prefix . '-%']);
        $latest = $stmt->fetchColumn();

        if ($latest) {
            $parts = explode('-', $latest);
            $next_number = intval($parts[1]) + 1;
        } else {
            $next_number = 1;
        }

        return sprintf("%s-%06d", $year_prefix, $next_number);
    }

    /**
     * Generate a secure verification token for a student
     */
    public static function generateToken($student_id) {
        return hash_hmac('sha256', $student_id . time(), self::$salt);
    }

    /**
     * Get QR Code Image URL (using GoQR.me API for zero-dependency generation)
     */
    public static function getQRUrl($data, $size = 200) {
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data);
    }

    /**
     * Store or refresh a student's QR token
     */
    public static function storeToken($pdo, $student_id) {
        $token = self::generateToken($student_id);
        $stmt = $pdo->prepare("INSERT INTO qr_tokens (student_id, token) 
                               VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE token = ?, created_at = CURRENT_TIMESTAMP");
        $stmt->execute([$student_id, $token, $token]);
        return $token;
    }

    /**
     * Get full verification payload for QR content
     */
    public static function getVerificationPayload($pdo, $student_id, $school_year) {
        $stmt = $pdo->prepare("SELECT token FROM qr_tokens WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $token = $stmt->fetchColumn();
        
        if (!$token) {
            $token = self::storeToken($pdo, $student_id);
        }

        $payload = [
            'sid' => $student_id,
            'token' => $token,
            'sy' => $school_year,
            'v' => '1.0'
        ];

        return json_encode($payload);
    }
}
