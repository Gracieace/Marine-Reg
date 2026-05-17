<?php
require_once __DIR__ . '/../auth/auth.php';
auth_require_role(['registrar', 'admin']);

// Redirect to the new organized reports structure
header('Location: reports/index.php');
exit;
