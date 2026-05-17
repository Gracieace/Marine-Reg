<?php
/**
 * E-Signature Utility Functions
 * Handles copying and managing e-signatures for quick access
 */

/**
 * Copy e-signature to quick access folder
 * @param string $sourcePath - Full path to the source e-signature file
 * @param string $signatureType - Type of signature (principal, class_adviser, employee)
 * @param int|null $employeeId - Employee ID (for employee signatures)
 * @return array - Result array with success status and message
 */
function copyEsignatureToQuickAccess($sourcePath, $signatureType, $employeeId = null) {
    try {
        // Define quick access directory
        $quickAccessDir = __DIR__ . '/../assets/esignatures/quick_access/';
        
        // Create quick access directory if it doesn't exist
        if (!is_dir($quickAccessDir)) {
            if (!mkdir($quickAccessDir, 0755, true)) {
                return [
                    'success' => false,
                    'message' => 'Failed to create quick access directory'
                ];
            }
        }
        
        // Generate filename for quick access
        $fileExtension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        
        if ($signatureType === 'employee' && $employeeId) {
            $quickAccessFileName = 'employee_' . $employeeId . '_signature.' . $fileExtension;
        } elseif ($signatureType === 'user' && $employeeId) {
            $quickAccessFileName = 'user_' . $employeeId . '_signature.' . $fileExtension;
        } else {
            $quickAccessFileName = $signatureType . '_signature.' . $fileExtension;
        }
        
        $quickAccessPath = $quickAccessDir . $quickAccessFileName;
        
        // Copy the file
        if (copy($sourcePath, $quickAccessPath)) {
            // Set proper permissions
            chmod($quickAccessPath, 0644);
            
            return [
                'success' => true,
                'message' => 'E-signature copied to quick access successfully',
                'quick_access_path' => 'assets/esignatures/quick_access/' . $quickAccessFileName
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to copy e-signature to quick access'
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error copying e-signature: ' . $e->getMessage()
        ];
    }
}

/**
 * Get quick access e-signature path
 * @param string $signatureType - Type of signature (principal, class_adviser, employee)
 * @param int|null $employeeId - Employee ID (for employee signatures)
 * @return string|null - Path to quick access signature or null if not found
 */
function getQuickAccessEsignaturePath($signatureType, $employeeId = null) {
    $quickAccessDir = __DIR__ . '/../assets/esignatures/quick_access/';
    
    if ($signatureType === 'employee' && $employeeId) {
        $pattern = $quickAccessDir . 'employee_' . $employeeId . '_signature.*';
    } elseif ($signatureType === 'user' && $employeeId) {
        $pattern = $quickAccessDir . 'user_' . $employeeId . '_signature.*';
    } else {
        $pattern = $quickAccessDir . $signatureType . '_signature.*';
    }
    
    $files = glob($pattern);
    return !empty($files) ? 'assets/esignatures/quick_access/' . basename($files[0]) : null;
}

/**
 * Clean up old quick access signatures
 * @param string $signatureType - Type of signature to clean
 * @param int|null $employeeId - Employee ID (for employee signatures)
 * @return bool - Success status
 */
function cleanupOldQuickAccessSignature($signatureType, $employeeId = null) {
    try {
        $quickAccessDir = __DIR__ . '/../assets/esignatures/quick_access/';
        
        if ($signatureType === 'employee' && $employeeId) {
            $pattern = $quickAccessDir . 'employee_' . $employeeId . '_signature.*';
        } elseif ($signatureType === 'user' && $employeeId) {
            $pattern = $quickAccessDir . 'user_' . $employeeId . '_signature.*';
        } else {
            $pattern = $quickAccessDir . $signatureType . '_signature.*';
        }
        
        $files = glob($pattern);
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get all quick access e-signatures
 * @return array - Array of quick access signature files
 */
function getAllQuickAccessEsignatures() {
    $quickAccessDir = __DIR__ . '/../assets/esignatures/quick_access/';
    $signatures = [];
    
    if (is_dir($quickAccessDir)) {
        $files = glob($quickAccessDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $signatures[] = [
                    'filename' => basename($file),
                    'path' => 'assets/esignatures/quick_access/' . basename($file),
                    'size' => filesize($file),
                    'modified' => filemtime($file)
                ];
            }
        }
    }
    
    return $signatures;
}
?>
