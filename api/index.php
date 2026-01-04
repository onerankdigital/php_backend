<?php
/**
 * API Entry Point
 * 
 * This file handles all API requests
 */

// Error reporting (disable in production)
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Don't display, but log
    ini_set('log_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

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
$allowedOrigins = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : null;

if ($allowedOrigins) {
    // Parse comma-separated list of allowed origins
    $allowedOriginsList = array_map('trim', explode(',', $allowedOrigins));
    
    if ($origin && in_array($origin, $allowedOriginsList)) {
        $allowedOrigin = $origin;
        $logEntry .= "  Allowed origin (from config): $allowedOrigin\n";
    } elseif ($origin) {
        // Check if origin matches any pattern in allowed list (supports wildcards)
        foreach ($allowedOriginsList as $allowed) {
            if ($allowed === '*' || $origin === $allowed) {
                $allowedOrigin = $origin;
                $logEntry .= "  Allowed origin (matched pattern): $allowedOrigin\n";
                break;
            }
        }
    }
}

// If no specific config or origin not in allowed list, use dynamic origin detection
if (!$allowedOrigin) {
    if ($origin) {
        $parsedOrigin = parse_url($origin);
        if ($parsedOrigin && isset($parsedOrigin['host'])) {
            $host = $parsedOrigin['host'];
            // Allow any localhost or 127.0.0.1 origin for development (with any port)
            if ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost') !== false) {
                $allowedOrigin = $origin; // Echo back the exact origin (including port)
                $logEntry .= "  Allowed origin (localhost match): $allowedOrigin\n";
            } else {
                // For production: use the request origin if no restrictions configured
                // This allows cross-domain requests when ALLOWED_ORIGINS is not set
                $allowedOrigin = $origin;
                $logEntry .= "  Allowed origin (dynamic - production mode): $allowedOrigin\n";
            }
        } else {
            $logEntry .= "  Failed to parse origin: $origin\n";
        }
    } else {
        $logEntry .= "  No Origin header present - this might be a direct request\n";
    }
}

// Fallback: use referer or host if origin not available
if (!$allowedOrigin) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer) {
        $parsedReferer = parse_url($referer);
        if ($parsedReferer && isset($parsedReferer['scheme']) && isset($parsedReferer['host'])) {
            $allowedOrigin = $parsedReferer['scheme'] . '://' . $parsedReferer['host'];
            if (isset($parsedReferer['port'])) {
                $allowedOrigin .= ':' . $parsedReferer['port'];
            }
            $logEntry .= "  Using referer as allowed origin: $allowedOrigin\n";
        }
    }
    if (!$allowedOrigin) {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = explode('/', $host)[0];
        $allowedOrigin = $scheme . '://' . $host;
        $logEntry .= "  Using fallback origin: $allowedOrigin\n";
    }
}

// Set CORS headers - MUST be set before any output
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 3600');
header('Access-Control-Expose-Headers: Content-Type, Authorization');

$logEntry .= "  Set CORS headers - Access-Control-Allow-Origin: $allowedOrigin\n";
$logEntry .= "  Response headers will be sent\n";

// Handle preflight OPTIONS requests - MUST be after CORS headers are set
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $logEntry .= "  OPTIONS preflight request - returning 200 OK\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    http_response_code(200);
    // Ensure no output before exit
    if (ob_get_level()) {
        ob_end_clean();
    }
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
    session_start();
}

// Register shutdown handler to catch fatal errors
register_shutdown_function(function() use ($logFile, $allowedOrigin) {
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
        
        // Ensure CORS headers and JSON response
        if (!headers_sent()) {
            http_response_code(500);
            header("Access-Control-Allow-Origin: $allowedOrigin");
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
            header('Access-Control-Allow-Credentials: true');
            header('Content-Type: application/json');
        }
        
        $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE
            ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
            : 'Internal Server Error';
        
        echo json_encode([
            'error' => $errorMessage,
            'success' => false
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

// Load autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $errorMsg = "Composer autoloader not found. Please run 'composer install' in the backend directory.";
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: $errorMsg\n", FILE_APPEND);
    
    if (!headers_sent()) {
        http_response_code(500);
        header("Access-Control-Allow-Origin: $allowedOrigin");
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
    
    if (!headers_sent()) {
        http_response_code(500);
        header("Access-Control-Allow-Origin: $allowedOrigin");
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
    // Log the error
    $errorLog = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    $errorLog .= "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    $errorLog .= "  Stack trace: " . $e->getTraceAsString() . "\n";
    $errorLog .= str_repeat('-', 80) . "\n";
    @file_put_contents($logFile, $errorLog, FILE_APPEND);
    
    // Ensure CORS headers are set even on error
    if (!headers_sent()) {
        http_response_code(500);
        header("Access-Control-Allow-Origin: $allowedOrigin");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Content-Type: application/json');
    }
    
    $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE 
        ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        : 'Internal Server Error';
    
    echo json_encode([
        'error' => $errorMessage,
        'success' => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

