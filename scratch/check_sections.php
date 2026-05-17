<?php
require_once 'config/db.php';
$pdo = db_connect();
$row = $pdo->query("SHOW CREATE TABLE sections")->fetch();
echo $row[1];
