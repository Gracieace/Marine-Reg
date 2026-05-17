<?php
function try_db($host) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=sampleweb;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

$pdo = try_db('localhost') ?: try_db('127.0.0.1');

if (!$pdo) {
    echo "Could not connect to database.\n";
    exit;
}

echo "--- sf3_books_inventory ---\n";
try {
    $old_books = $pdo->query("SELECT * FROM sf3_books_inventory LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($old_books);
} catch (Exception $e) {
    echo "Table sf3_books_inventory not found or error: " . $e->getMessage() . "\n";
}

echo "\n--- textbooks ---\n";
try {
    $new_books = $pdo->query("SELECT * FROM textbooks LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($new_books);
} catch (Exception $e) {
    echo "Table textbooks not found or error: " . $e->getMessage() . "\n";
}
?>
