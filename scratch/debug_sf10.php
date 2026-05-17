<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/../config/db.php';
    $pdo = db_connect();
    echo "DB Connected Successfully.<br>";
    
    $student_id = $_GET['student_id'] ?? '';
    if ($student_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sf9_grades WHERE student_id = ?");
        $stmt->execute([$student_id]);
        echo "Grades Count: " . $stmt->fetchColumn() . "<br>";
    }
    
    echo "Testing Join with Curriculum...<br>";
    $stmt = $pdo->query("SELECT g.*, s.subject_name FROM sf9_grades g JOIN curriculum s ON g.subject_id = s.id LIMIT 1");
    $res = $stmt->fetch();
    echo "Join Result: " . ($res ? 'Success' : 'Empty') . "<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
