<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=sampleweb;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // The existing sf3_reports can stay, but let's drop the old sf3_books since we are changing schema
    $pdo->exec("DROP TABLE IF EXISTS sf3_books");

    // 1. Create sf3_books_inventory (Inventory Preparation)
    $sql1 = "CREATE TABLE IF NOT EXISTS sf3_books_inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sf3_report_id INT NOT NULL, /* Link to the specific SF3 report (teacher, yr, grade, section) */
        subject VARCHAR(100) NOT NULL,
        title VARCHAR(200) NOT NULL,
        total_copies_received INT DEFAULT 0,
        copies_in_good_condition INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sf3_report_id) REFERENCES sf3_reports(id) ON DELETE CASCADE,
        KEY idx_sf3_report_id (sf3_report_id)
    ) ENGINE=InnoDB";

    $pdo->exec($sql1);
    echo "Created sf3_books_inventory table.<br>";

    // 2. Create sf3_student_books (Distribution and Collection)
    $sql2 = "CREATE TABLE IF NOT EXISTS sf3_student_books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sf3_report_id INT NOT NULL, /* For convenience and fast querying */
        student_lrn VARCHAR(50) NOT NULL, /* Use LRN to link to student easily */
        inventory_id INT NOT NULL, /* Which book from inventory was issued */
        date_issued DATE NULL,
        condition_issued ENUM('Good', 'Fair', 'Poor') DEFAULT 'Good',
        date_returned DATE NULL,
        condition_returned ENUM('Good', 'Fair', 'Damaged', 'Lost') NULL,
        remarks VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sf3_report_id) REFERENCES sf3_reports(id) ON DELETE CASCADE,
        FOREIGN KEY (inventory_id) REFERENCES sf3_books_inventory(id) ON DELETE CASCADE,
        KEY idx_sf3_report_id (sf3_report_id),
        KEY idx_student_lrn (student_lrn),
        UNIQUE KEY unique_student_book (sf3_report_id, student_lrn, inventory_id)
    ) ENGINE=InnoDB";

    $pdo->exec($sql2);
    echo "Created sf3_student_books table.<br>";

    echo "SF3 Tables Setup Done successfully.<br>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
