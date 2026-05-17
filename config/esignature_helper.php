<?php
/**
 * E-Signature Helper Functions
 * Easy-to-use functions for accessing e-signatures throughout the system
 */

require_once __DIR__ . '/esignature_utils.php';

/**
 * Get principal e-signature from quick access
 * @return string|null - Path to principal signature or null if not found
 */
function getPrincipalSignature() {
    return getQuickAccessEsignaturePath('principal');
}

/**
 * Get class adviser e-signature from quick access
 * @return string|null - Path to class adviser signature or null if not found
 */
function getClassAdviserSignature() {
    return getQuickAccessEsignaturePath('class_adviser');
}

/**
 * Get employee e-signature from quick access
 * @param int $employeeId - Employee ID
 * @return string|null - Path to employee signature or null if not found
 */
function getEmployeeSignature($employeeId) {
    return getQuickAccessEsignaturePath('employee', $employeeId);
}

/**
 * Display e-signature image with fallback
 * @param string $signatureType - Type of signature (principal, class_adviser, employee)
 * @param int|null $employeeId - Employee ID (for employee signatures)
 * @param array $attributes - HTML attributes for the img tag
 * @return string - HTML img tag or empty string if not found
 */
function displayEsignature($signatureType, $employeeId = null, $attributes = []) {
    $signaturePath = getQuickAccessEsignaturePath($signatureType, $employeeId);
    
    if (!$signaturePath) {
        return '';
    }
    
    $defaultAttributes = [
        'src' => $signaturePath,
        'alt' => ucfirst($signatureType) . ' E-Signature',
        'style' => 'max-width: 120px; max-height: 40px; border: 1px solid #d1d5db; border-radius: 4px;'
    ];
    
    $attributes = array_merge($defaultAttributes, $attributes);
    
    $html = '<img';
    foreach ($attributes as $key => $value) {
        $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
    }
    $html .= '>';
    
    return $html;
}

/**
 * Check if e-signature exists in quick access
 * @param string $signatureType - Type of signature
 * @param int|null $employeeId - Employee ID (for employee signatures)
 * @return bool - True if signature exists, false otherwise
 */
function hasEsignature($signatureType, $employeeId = null) {
    $signaturePath = getQuickAccessEsignaturePath($signatureType, $employeeId);
    return !empty($signaturePath);
}

/**
 * Get all available e-signature types for an employee
 * @param int $employeeId - Employee ID
 * @return array - Array of available signature types
 */
function getAvailableEmployeeSignatures($employeeId) {
    $signatures = [];
    
    if (hasEsignature('principal')) {
        $signatures['principal'] = getPrincipalSignature();
    }
    
    if (hasEsignature('class_adviser')) {
        $signatures['class_adviser'] = getClassAdviserSignature();
    }
    
    if (hasEsignature('employee', $employeeId)) {
        $signatures['employee'] = getEmployeeSignature($employeeId);
    }
    
    return $signatures;
}
?>
