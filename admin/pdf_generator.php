<?php
require_once __DIR__ . '/../config/db.php';

class PDFReportGenerator {
    private $pdo;
    
    public function __construct() {
        $this->pdo = db_connect();
    }
    
    public function generateEnrollmentPDF($date_from = '', $date_to = '', $grade_level = '', $section = '') {
        $where_conditions = [];
        $params = [];
        
        if ($date_from) {
            $where_conditions[] = "e.enrolled_at >= ?";
            $params[] = $date_from;
        }
        if ($date_to) {
            $where_conditions[] = "e.enrolled_at <= ?";
            $params[] = $date_to . ' 23:59:59';
        }
        if ($grade_level) {
            $where_conditions[] = "e.grade_level = ?";
            $params[] = $grade_level;
        }
        if ($section) {
            $where_conditions[] = "e.section = ?";
            $params[] = $section;
        }
        
        $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $sql = "SELECT 
                    e.student_id,
                    e.student_name,
                    e.grade_level,
                    e.section,
                    e.enrolled_at,
                    r.lrn,
                    r.birthdate,
                    r.sex,
                    r.father_contact,
                    r.mother_contact
                FROM enrollments e
                LEFT JOIN registrations r ON e.registration_id = r.id
                $where_clause
                ORDER BY e.grade_level, e.section, e.student_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        return $this->createHTMLTable($data, 'Enrollment Report');
    }
    
    public function generateRegistrationPDF($date_from = '', $date_to = '', $grade_level = '') {
        $where_conditions = [];
        $params = [];
        
        if ($date_from) {
            $where_conditions[] = "created_at >= ?";
            $params[] = $date_from;
        }
        if ($date_to) {
            $where_conditions[] = "created_at <= ?";
            $params[] = $date_to . ' 23:59:59';
        }
        if ($grade_level) {
            $where_conditions[] = "grade_level_to_enroll = ?";
            $params[] = $grade_level;
        }
        
        $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $sql = "SELECT 
                    id,
                    created_at,
                    lrn,
                    CONCAT(last_name, ', ', first_name, ' ', COALESCE(middle_name, '')) as full_name,
                    grade_level_to_enroll,
                    birthdate,
                    sex,
                    age,
                    curr_city,
                    curr_province,
                    father_contact,
                    mother_contact
                FROM registrations 
                $where_clause
                ORDER BY last_name, first_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        return $this->createHTMLTable($data, 'Registration Report');
    }
    
    private function createHTMLTable($data, $title) {
        if (empty($data)) {
            return $this->createEmptyReport($title);
        }
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . $title . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #2c3e50; margin: 0; }
        .header p { color: #666; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
        .summary { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . $title . '</h1>
        <p>Generated on: ' . date('F j, Y \a\t g:i A') . '</p>
        <p>Total Records: ' . count($data) . '</p>
    </div>
    
    <div class="summary">
        <strong>Report Summary:</strong> This report contains ' . count($data) . ' records from the system database.
    </div>
    
    <table>
        <thead>
            <tr>';
        
        // Add table headers
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . ucwords(str_replace('_', ' ', $header)) . '</th>';
        }
        
        $html .= '</tr>
        </thead>
        <tbody>';
        
        // Add table data
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>' . htmlspecialchars($value ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</tbody>
    </table>
    
    <div class="footer">
        <p>This report was generated by the SampleWeb System</p>
        <p>For questions or support, please contact the system administrator</p>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    private function createEmptyReport($title) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . $title . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; text-align: center; }
        .header { margin-bottom: 30px; }
        .header h1 { color: #2c3e50; }
        .no-data { color: #666; font-size: 18px; margin-top: 50px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . $title . '</h1>
        <p>Generated on: ' . date('F j, Y \a\t g:i A') . '</p>
    </div>
    
    <div class="no-data">
        <p>No data found for the selected criteria.</p>
    </div>
</body>
</html>';
    }
}

// Handle PDF generation request
if (isset($_GET['action']) && $_GET['action'] === 'generate_pdf') {
    $generator = new PDFReportGenerator();
    $report_type = $_GET['type'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $grade_level = $_GET['grade_level'] ?? '';
    $section = $_GET['section'] ?? '';
    
    $html = '';
    
    switch ($report_type) {
        case 'enrollment_detailed':
            $html = $generator->generateEnrollmentPDF($date_from, $date_to, $grade_level, $section);
            break;
        case 'registration_detailed':
            $html = $generator->generateRegistrationPDF($date_from, $date_to, $grade_level);
            break;
        default:
            $html = '<html><body><h1>Invalid report type</h1></body></html>';
    }
    
    // Set headers for PDF download
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $report_type . '_' . date('Y-m-d_H-i-s') . '.html"');
    
    echo $html;
    exit;
}
?>
