<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../auth/auth.php';
auth_logout();
header('Location: ' . url_for('/index.php'));
exit;
