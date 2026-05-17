<?php
$c = @new mysqli('localhost', 'root', '', 'sampleweb');
if ($c->connect_error) {
    $c = @new mysqli('127.0.0.1', 'root', '', 'sampleweb');
}
if ($c->connect_error) {
    die("Connection failed: " . $c->connect_error);
}
echo "Connected successfully\n";

$res = $c->query("DESCRIBE enrollments");
while($row = $res->fetch_assoc()) {
    print_r($row);
}

$res = $c->query("SELECT sex, COUNT(*) as count FROM registrations GROUP BY sex");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
