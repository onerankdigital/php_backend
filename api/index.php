<?php
/**
 * API Entry Point
 * 
 * This file handles all API requests
 */

// Start output buffering immediately to catch any unwanted output
ob_start();

// Suppress all output of warnings/notices to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0); // Never display errors, only log
ini_set('log_errors', 1);

// Set execution time limit for API requests
set_time_limit(30); // 30 seconds should be enough
ini_set('max_execution_time', 30);

// Set error log location
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

// CORS Headers - Allow all origins (frontend on different domain)
// For development: be permissive with localhost
// For production: restrict to specific frontend domain

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = null;
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Log CORS debugging information
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cors_debug.log';
$logEntry = date('Y-m-d H:i:s') . " - Method: $requestMethod, URI: $requestUri, Origin: " . ($origin ?: 'NOT SET') . "\n";
$logEntry .= "  HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";

// Get all headers (getallheaders() may not be available in all PHP configs)
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = str_replace('_', '-', substr($key, 5));
            $headers[$headerName] = $value;
        }
    }
}
$logEntry .= "  All Headers: " . print_r($headers, true) . "\n";

// CORS Configuration - Support for separate frontend/backend domains
// Check if specific allowed origins are configured in config.php
// ======================
// CORS – WITH CREDENTIALS
// ======================

// CORS Configuration - Handle origin properly
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowedOrigin = null;

// Determine allowed origin based on config
if (defined('ALLOWED_ORIGINS')) {
    $allowedOriginsConfig = ALLOWED_ORIGINS;
    
    // If '*' is set, allow all origins (use the request origin)
    if ($allowedOriginsConfig === '*' || $allowedOriginsConfig === null) {
        $allowedOrigin = $origin ?: null;
    } else {
        // Parse comma-separated list or array
        $allowedList = is_array($allowedOriginsConfig) 
            ? $allowedOriginsConfig 
            : explode(',', $allowedOriginsConfig);
        
        // Check if the request origin is in the allowed list
        if ($origin) {
            foreach ($allowedList as $allowed) {
                $allowed = trim($allowed);
                if ($origin === $allowed || $allowed === '*') {
                    $allowedOrigin = $origin;
                    break;
                }
            }
        }
    }
} else {
    // No config, allow the request origin if present
    $allowedOrigin = $origin ?: null;
}

// Log CORS decision
$logEntry .= "  Allowed Origin: " . ($allowedOrigin ?: 'NONE') . "\n";
$logEntry .= "  ALLOWED_ORIGINS config: " . (defined('ALLOWED_ORIGINS') ? (is_array(ALLOWED_ORIGINS) ? json_encode(ALLOWED_ORIGINS) : ALLOWED_ORIGINS) : 'NOT DEFINED') . "\n";

// Set CORS headers - always set the origin if provided (even if not in config, for development)
// In production, you might want to reject requests with origins not in the allowed list
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    if (!$allowedOrigin) {
        $logEntry .= "  WARNING: Origin not in allowed list, but allowing anyway\n";
    }
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token");
header("Access-Control-Expose-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 3600");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$logEntry .= str_repeat('-', 80) . "\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND);

// Start session for CSRF tokens (if available)
// Configure session for cross-origin requests
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters for cross-origin support
    // For localhost HTTP, use Lax. For HTTPS or cross-domain, use None
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $isLocalhost = isset($_SERVER['HTTP_HOST']) && 
                  (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
                   strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps, // Only secure on HTTPS
        'httponly' => true,
        'samesite' => ($isHttps && !$isLocalhost) ? 'None' : 'Lax' // None requires HTTPS
    ]);
    
    ini_set('session.use_strict_mode', '1');
    @session_start(); // Suppress any session warnings
}

// Register shutdown handler to catch fatal errors
register_shutdown_function(function() use ($logFile) {
    // Clean any output buffers first
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Only handle fatal errors, not normal exits
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Check if response was already sent (normal exit)
        if (connection_status() !== CONNECTION_NORMAL || headers_sent()) {
            // Response already sent, just log the error
            $errorLog = date('Y-m-d H:i:s') . " - FATAL ERROR (after response): " . $error['message'] . "\n";
            $errorLog .= "  File: " . $error['file'] . ":" . $error['line'] . "\n";
            $errorLog .= str_repeat('-', 80) . "\n";
            @file_put_contents($logFile, $errorLog, FILE_APPEND);
            return;
        }
        
        // Log fatal error
        $errorLog = date('Y-m-d H:i:s') . " - FATAL ERROR: " . $error['message'] . "\n";
        $errorLog .= "  File: " . $error['file'] . ":" . $error['line'] . "\n";
        $errorLog .= str_repeat('-', 80) . "\n";
        @file_put_contents($logFile, $errorLog, FILE_APPEND);
        
        // Get origin for CORS
        $fatalOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Ensure CORS headers and JSON response
        if (!headers_sent()) {
            http_response_code(500);
            if ($fatalOrigin) {
                header("Access-Control-Allow-Origin: $fatalOrigin");
            }
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
            header('Access-Control-Allow-Credentials: true');
            header('Content-Type: application/json; charset=utf-8');
        }
        
        $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE
            ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
            : 'Internal Server Error';
        
        $response = json_encode([
            'error' => $errorMessage,
            'success' => false
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($response === false) {
            // JSON encoding failed, send a simple error
            $response = '{"error":"Internal Server Error","success":false}';
        }
        
        echo $response;
    }
});

// Load autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $errorMsg = "Composer autoloader not found. Please run 'composer install' in the backend directory.";
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: $errorMsg\n", FILE_APPEND);
    
    $autoloadOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!headers_sent()) {
        http_response_code(500);
        if ($autoloadOrigin) {
            header("Access-Control-Allow-Origin: $autoloadOrigin");
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Content-Type: application/json');
    }
    
    echo json_encode([
        'error' => defined('DEBUG_MODE') && DEBUG_MODE ? $errorMsg : 'Internal Server Error',
        'success' => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $autoloadPath;

// Load configuration
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    $errorMsg = "Configuration file not found. Please copy config.example.php to config.php and configure it.";
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: $errorMsg\n", FILE_APPEND);
    
    $configOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!headers_sent()) {
        http_response_code(500);
        if ($configOrigin) {
            header("Access-Control-Allow-Origin: $configOrigin");
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Content-Type: application/json');
    }
    
    echo json_encode([
        'error' => defined('DEBUG_MODE') && DEBUG_MODE ? $errorMsg : 'Internal Server Error',
        'success' => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $configPath;

// Initialize and run application
try {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Attempting to create App instance...\n", FILE_APPEND);
    $app = new \App\App();
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - App instance created, calling run()...\n", FILE_APPEND);
    $app->run();
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - App run() completed\n", FILE_APPEND);
} catch (\Throwable $e) {
    // Clean any output that might have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Log the error
    $errorLog = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    $errorLog .= "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    $errorLog .= "  Stack trace: " . $e->getTraceAsString() . "\n";
    $errorLog .= str_repeat('-', 80) . "\n";
    @file_put_contents($logFile, $errorLog, FILE_APPEND);
    
    // Get origin for CORS
    $errorOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Ensure CORS headers are set even on error
    if (!headers_sent()) {
        http_response_code(500);
        if ($errorOrigin) {
            header("Access-Control-Allow-Origin: $errorOrigin");
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Content-Type: application/json; charset=utf-8');
    }
    
    $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE 
        ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        : 'Internal Server Error';
    
    $response = json_encode([
        'error' => $errorMessage,
        'success' => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($response === false) {
        // JSON encoding failed, send a simple error
        $response = '{"error":"Internal Server Error","success":false}';
    }
    
    echo $response;
    exit;
}

