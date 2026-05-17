<?php
/**
 * Helper utility for SF Report Exports
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export HTML content to PDF
 */
function exportToPDF($html, $filename, $orientation = 'portrait', $paper = 'A4') {
    $dompdf = new Dompdf([
        'isRemoteEnabled' => true,
        'isHtml5ParserEnabled' => true,
        'defaultFont' => 'Arial'
    ]);
    
    $dompdf->setPaper($paper, $orientation);
    $dompdf->loadHtml($html);
    $dompdf->render();
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $dompdf->output();
    exit;
}

/**
 * Export data array to Excel (XLSX)
 */
function exportToExcel($data, $headers, $filename, $title = 'Report') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($title, 0, 31));

    // Add Headers
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $col++;
    }

    // Add Data
    $row = 2;
    foreach ($data as $record) {
        $col = 'A';
        foreach ($record as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }

    // Auto-size columns
    $colCount = count($headers);
    for ($i = 0; $i < $colCount; $i++) {
        $sheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * A generic export handler for simple reports
 */
function handleGenericExport($data, $format, $report_name, $school_year = '', $month = '') {
    $filename = $report_name . ($school_year ? '_' . str_replace('-', '_', $school_year) : '') . ($month ? '_' . $month : '');
    
    if ($format === 'pdf') {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: sans-serif; font-size: 10px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                th { background: #f0f0f0; }
                .header { text-align: center; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2><?= strtoupper($report_name) ?> Report</h2>
                <p>School Year: <?= htmlspecialchars($school_year) ?> <?= $month ? '| Month: ' . htmlspecialchars($month) : '' ?></p>
            </div>
            <table>
                <thead>
                    <tr>
                        <?php 
                        if (!empty($data)) {
                            foreach (array_keys($data[0]) as $key) {
                                echo "<th>" . strtoupper(str_replace('_', ' ', $key)) . "</th>";
                            }
                        }
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <?php foreach ($row as $val): ?>
                                <td><?= htmlspecialchars($val) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </body>
        </html>
        <?php
        $html = ob_get_clean();
        exportToPDF($html, $filename, 'landscape');
    } elseif ($format === 'xlsx' || $format === 'excel') {
        if (empty($data)) return;
        $headers = array_keys($data[0]);
        $headers = array_map(function($h) { return strtoupper(str_replace('_', ' ', $h)); }, $headers);
        exportToExcel($data, $headers, $filename, strtoupper($report_name));
    }
}
