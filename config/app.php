<?php
// Application bootstrap for environment, error handling, headers, and sessions

// Load Composer autoload if present (COMMENTED OUT FOR DEBUGGING)
// $composerAutoload = __DIR__ . '/../vendor/autoload.php';
// if (file_exists($composerAutoload)) {
//     try {
//         require_once $composerAutoload;
//     } catch (\Throwable $e) {
//         // Vendor files are incomplete on server - log and continue without autoload
//         error_log('Composer autoload failed: ' . $e->getMessage());
//     }
// }

// Load environment variables from .env if Dotenv is available
if (class_exists('Dotenv\\Dotenv')) {
    $envPath = dirname(__DIR__);
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->safeLoad();
    } catch (Throwable $e) {
        // Ignore dotenv errors in production
    }
}

// Resolve environment with safe defaults
$APP_ENV = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
$APP_DEBUG = filter_var($_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// Timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Manila');

// Error reporting: always display errors for debugging 500 error
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


// Allow hosting-specific overrides (e.g., InfinityFree) without committing secrets
$hostingOverride = __DIR__ . '/hosting.php';
if (file_exists($hostingOverride)) {
    require_once $hostingOverride; // may define DB_* constants
}

// Database constants from env or defaults
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $isLocalEnv = in_array($serverName, ['localhost', '127.0.0.1', '::1']) || php_sapi_name() === 'cli';
}
if (!defined('DB_HOST'))
    define('DB_HOST', $_ENV['DB_HOST'] ?? ($isLocalEnv ? '127.0.0.1' : 'localhost'));
if (!defined('DB_PORT'))
    define('DB_PORT', (int) ($_ENV['DB_PORT'] ?? 3306));
if (!defined('DB_USER'))
    define('DB_USER', $_ENV['DB_USER'] ?? ($isLocalEnv ? 'root' : 'u957255050_marine_reg'));
if (!defined('DB_PASS'))
    define('DB_PASS', $_ENV['DB_PASS'] ?? ($isLocalEnv ? '' : 'M~rphsx7!+/5'));
if (!defined('DB_NAME'))
    define('DB_NAME', $_ENV['DB_NAME'] ?? ($isLocalEnv ? 'sampleweb' : 'u957255050_db_marine_reg'));

// Feature flags
if (!defined('ENABLE_DATA_CLEAR_UI')) {
    // Prevent destructive UI in production unless explicitly enabled
    $enableClear = $_ENV['ENABLE_DATA_CLEAR_UI'] ?? 'false';
    define('ENABLE_DATA_CLEAR_UI', filter_var($enableClear, FILTER_VALIDATE_BOOLEAN));
}

// Basic security headers (conservative to avoid breaking inline assets)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer-when-downgrade');
    header('X-XSS-Protection: 1; mode=block');
    // Allow camera for QR scanner, block unused permissions
    header("Permissions-Policy: geolocation=(), microphone=(), camera=(self)");

    // Force HTTPS redirect on production (not localhost)
    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $isLocal = in_array($serverName, ['localhost', '127.0.0.1', '::1']);
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ($_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
    );
    // HTTPS redirect is handled by InfinityFree's proxy (Cloudflare).
    // Do NOT redirect here — the origin server always sees HTTP,
    // which causes an infinite redirect loop resulting in 404.
    // If you move to a host that supports native HTTPS, uncomment below:
    // if (!$isLocal && !$isHttps) {
    //     $redirectUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    //     header('Location: ' . $redirectUrl, true, 301);
    //     exit;
    // }
    // HSTS header when on HTTPS
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Secure session cookie parameters
if (session_status() === PHP_SESSION_NONE) {
    // Determine if we are on HTTPS
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ($_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
    );

    // Force secure cookies ONLY if we are actually on HTTPS or forced via ENV
    $secure = $isHttps || (($_ENV['FORCE_SECURE_COOKIES'] ?? '') === 'true');

    $cookieParams = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'] ?? 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'] ?? 0,
            ($cookieParams['path'] ?? '/') . '; samesite=Lax',
            $cookieParams['domain'] ?? '',
            $secure,
            true
        );
    }
}

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF helper (opt-in usage per form)
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_token" value="' . $t . '">';
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['_token'] ?? '';
            if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
                http_response_code(419);
                exit('Invalid CSRF token.');
            }
        }
    }
}


// URL helpers for dynamic base paths (works on shared hosts / subfolders)
if (!function_exists('base_path')) {
    function base_path()
    {
        // Calculate relative path from DOCUMENT_ROOT to the project root
        // Assumes this file is at /config/app.php
        $projectRoot = dirname(__DIR__);
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        // Normalize paths
        $projectRoot = str_replace('\\', '/', $projectRoot);
        $docRoot = str_replace('\\', '/', $docRoot);
        $docRoot = rtrim($docRoot, '/');

        if ($docRoot && strpos($projectRoot, $docRoot) === 0) {
            $relativePath = substr($projectRoot, strlen($docRoot));
            return rtrim($relativePath, '/');
        }

        // Fallback or CLI
        return '';
    }
}

if (!function_exists('url_for')) {
    function url_for($path)
    {
        $path = ltrim($path, '/');
        $base = base_path();
        return ($base === '' ? '/' : $base . '/') . $path;
    }
}

if (!function_exists('redirect_for_role')) {
    function redirect_for_role($role, $status = 302)
    {
        static $roleMap = [
        'admin' => 'admin/admin_dashboard.php',
        'registrar' => 'registrar/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/dashboard.php',
        'employee' => 'admin/admin_dashboard.php',
        ];

        $target = $roleMap[$role] ?? '';
        $location = $target !== '' ? url_for($target) : url_for('');

        header('Location: ' . $location, true, (int) $status);
        exit;
    }
}


