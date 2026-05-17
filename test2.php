<?php
$s = [
    'father_last' => 'Cabrera',
    'father_first' => 'Nemencio',
    'father_middle' => 'Nicolas',
];
$father_name = trim(trim($s['father_last'] ?: ($s['father_last_name'] ?? '')) . (trim($s['father_first'] ?: ($s['father_first_name'] ?? '')) || trim($s['father_middle'] ?: ($s['father_middle_name'] ?? '')) ? ', ' . trim(trim($s['father_first'] ?: ($s['father_first_name'] ?? '')) . ' ' . trim($s['father_middle'] ?: ($s['father_middle_name'] ?? ''))) : '')) ?: 'N/A';
echo "Test 1: " . $father_name . "\n";
?>
