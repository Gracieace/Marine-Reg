<?php
$s = [
    'father_last' => 'Cabrera',
    'father_first' => 'Nemencio',
    'father_middle' => 'Nicolas',
];
$father_name = trim(trim($s['father_last'] ?? $s['father_last_name'] ?? '') . (trim($s['father_first'] ?? $s['father_first_name'] ?? '') || trim($s['father_middle'] ?? $s['father_middle_name'] ?? '') ? ', ' . trim(trim($s['father_first'] ?? $s['father_first_name'] ?? '') . ' ' . trim($s['father_middle'] ?? $s['father_middle_name'] ?? '')) : '')) ?: 'N/A';
echo "Test 1: " . $father_name . "\n";

$s2 = [
    'father_last' => 'Cabrera',
    'father_first' => '',
    'father_middle' => '',
];
$father_name2 = trim(trim($s2['father_last'] ?? $s2['father_last_name'] ?? '') . (trim($s2['father_first'] ?? $s2['father_first_name'] ?? '') || trim($s2['father_middle'] ?? $s2['father_middle_name'] ?? '') ? ', ' . trim(trim($s2['father_first'] ?? $s2['father_first_name'] ?? '') . ' ' . trim($s2['father_middle'] ?? $s2['father_middle_name'] ?? '')) : '')) ?: 'N/A';
echo "Test 2: " . $father_name2 . "\n";
?>
