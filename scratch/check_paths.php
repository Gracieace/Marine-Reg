<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'sampleweb');
define('DB_USER', 'root');
define('DB_PASS', '');

require 'config/app.php';
require 'config/db.php';
$pdo = db_connect();
$res = $pdo->query('SELECT photo_path FROM school_ids WHERE photo_path IS NOT NULL LIMIT 5');
while($row = $res->fetch()) {
    echo "[" . $row['photo_path'] . "]" . PHP_EOL;
}
