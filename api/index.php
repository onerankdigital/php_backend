<?php
/**
 * API Entry Point
 * Clean, CORS-safe, SaaS-ready
 */

/* -------------------------------------------------
 | BASIC PHP SETTINGS
 ------------------------------------------------- */
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_time_limit(30);
ini_set('max_execution_time', 30);

/* -------------------------------------------------
 | LOGGING
 ------------------------------------------------- */
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

/* -------------------------------------------------
 | CORS (UNIVERSAL – THOUSANDS OF DOMAINS)
 ------------------------------------------------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;

if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token");
    header("Access-Control-Expose-Headers: Content-Type, Authorization");
    header("Vary: Origin");
}

/* Preflight request */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* -------------------------------------------------
 | SESSION (CROSS-DOMAIN SAFE)
 ------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '.onerankdigital.com',   // API domain only
        'secure' => true,                    // HTTPS required
        'httponly' => true,
        'samesite' => 'None'                 // Required for cross-site cookies
    ]);

    ini_set('session.use_strict_mode', '1');
    session_start();
}

/* -------------------------------------------------
 | AUTOLOADER
 ------------------------------------------------- */
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Application not installed'
    ]);
    exit;
}
require_once $autoloadPath;

/* -------------------------------------------------
 | CONFIG
 ------------------------------------------------- */
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Configuration missing'
    ]);
    exit;
}
require_once $configPath;

/* -------------------------------------------------
 | RUN APPLICATION
 ------------------------------------------------- */
try {
    $app = new \App\App();
    $app->run();
} catch (\Throwable $e) {
    error_log($e);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error'
    ]);
    exit;
}
