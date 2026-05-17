<?php
require_once __DIR__ . '/config/db.php';
$pdo = db_connect();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf4_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(20) NOT NULL,
            report_month VARCHAR(20) NOT NULL,
            status ENUM('Draft', 'Finalized') DEFAULT 'Draft',
            finalized_by INT NULL,
            finalized_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (school_year, report_month),
            FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS sf4_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sf4_report_id INT NOT NULL,
            grade_level VARCHAR(50) NOT NULL,
            section VARCHAR(100) NOT NULL,
            adviser VARCHAR(200) NULL,
            beg_m INT DEFAULT 0,
            beg_f INT DEFAULT 0,
            reg_m INT DEFAULT 0,
            reg_f INT DEFAULT 0,
            num_school_days INT DEFAULT 0,
            total_att_m INT DEFAULT 0,
            total_att_f INT DEFAULT 0,
            daily_att_m_json TEXT NULL,
            daily_att_f_json TEXT NULL,
            avg_m DECIMAL(10,2) DEFAULT 0,
            avg_f DECIMAL(10,2) DEFAULT 0,
            perc_m DECIMAL(10,2) DEFAULT 0,
            perc_f DECIMAL(10,2) DEFAULT 0,
            tin_prev_m INT DEFAULT 0, tin_prev_f INT DEFAULT 0, tin_curr_m INT DEFAULT 0, tin_curr_f INT DEFAULT 0, tin_cum_m INT DEFAULT 0, tin_cum_f INT DEFAULT 0,
            late_prev_m INT DEFAULT 0, late_prev_f INT DEFAULT 0, late_curr_m INT DEFAULT 0, late_curr_f INT DEFAULT 0, late_cum_m INT DEFAULT 0, late_cum_f INT DEFAULT 0,
            tout_prev_m INT DEFAULT 0, tout_prev_f INT DEFAULT 0, tout_curr_m INT DEFAULT 0, tout_curr_f INT DEFAULT 0, tout_cum_m INT DEFAULT 0, tout_cum_f INT DEFAULT 0,
            nlpa_prev_m INT DEFAULT 0, nlpa_prev_f INT DEFAULT 0, nlpa_curr_m INT DEFAULT 0, nlpa_curr_f INT DEFAULT 0, nlpa_cum_m INT DEFAULT 0, nlpa_cum_f INT DEFAULT 0,
            mort_prev_m INT DEFAULT 0, mort_prev_f INT DEFAULT 0, mort_curr_m INT DEFAULT 0, mort_curr_f INT DEFAULT 0, mort_cum_m INT DEFAULT 0, mort_cum_f INT DEFAULT 0,
            ret_curr_m INT DEFAULT 0, ret_curr_f INT DEFAULT 0, ret_cum_m INT DEFAULT 0, ret_cum_f INT DEFAULT 0,
            FOREIGN KEY (sf4_report_id) REFERENCES sf4_reports(id) ON DELETE CASCADE,
            UNIQUE KEY (sf4_report_id, grade_level, section)
        ) ENGINE=InnoDB;
    ");
    echo "SF4 tables initialized successfully!\n";
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
