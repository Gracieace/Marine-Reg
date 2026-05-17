<?php
/**
 * Quick Access E-Signature API
 * Provides easy access to e-signatures stored in the quick access folder
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/esignature_utils.php';

// Set content type to JSON
header('Content-Type: application/json');

try {
    $signatureType = $_GET['type'] ?? '';
    $employeeId = $_GET['employee_id'] ?? null;
    
    if (empty($signatureType)) {
        throw new Exception('Signature type is required');
    }
    
    // Validate signature type
    $allowedTypes = ['principal', 'class_adviser', 'employee'];
    if (!in_array($signatureType, $allowedTypes)) {
        throw new Exception('Invalid signature type');
    }
    
    // For employee signatures, employee_id is required
    if ($signatureType === 'employee' && empty($employeeId)) {
        throw new Exception('Employee ID is required for employee signatures');
    }
    
    // Get the quick access signature path
    $signaturePath = getQuickAccessEsignaturePath($signatureType, $employeeId);
    
    if ($signaturePath) {
        $fullPath = __DIR__ . '/../' . $signaturePath;
        
        if (file_exists($fullPath)) {
            $response = [
                'success' => true,
                'signature_path' => $signaturePath,
                'full_path' => $fullPath,
                'file_exists' => true,
                'file_size' => filesize($fullPath),
                'last_modified' => filemtime($fullPath)
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'Signature file not found in quick access',
                'signature_path' => $signaturePath,
                'file_exists' => false
            ];
        }
    } else {
        $response = [
            'success' => false,
            'message' => 'No signature found in quick access for the specified type',
            'signature_type' => $signatureType,
            'employee_id' => $employeeId
        ];
    }
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
