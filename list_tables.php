<?php
require_once 'config/db.php';
$pdo = db_connect();
var_dump($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
