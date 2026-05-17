<?php
require_once 'config/db.php';
$pdo = db_connect();
$res = $pdo->query("SELECT sex, COUNT(*) as count FROM registrations GROUP BY sex")->fetchAll();
print_r($res);
