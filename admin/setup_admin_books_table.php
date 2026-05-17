<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=sampleweb;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $sql = "CREATE TABLE IF NOT EXISTS admin_books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        subject VARCHAR(100) NOT NULL,
        total_copies INT DEFAULT 0,
        grade_level VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB";

    $pdo->exec($sql);
    echo "Created admin_books table successfully.<br>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
