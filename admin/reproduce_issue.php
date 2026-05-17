<?php
// Mock server variables to simulate a request to admin_dashboard.php
$_SERVER['SCRIPT_NAME'] = '/SampleWeb/admin/admin_dashboard.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
$_ENV['APP_ENV'] = 'local';

require_once __DIR__ . '/../config/app.php';

echo "Base Path: " . base_path() . "\n";
echo "URL for JS: " . url_for('js/sidebar-toggle.js') . "\n";
echo "URL for Index: " . url_for('index.php') . "\n";
