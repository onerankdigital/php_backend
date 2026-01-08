<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

class AuthController
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function register(): void
    {
        error_log('AuthController::register - Starting registration');
        
        $data = json_decode(file_get_contents('php://input'), true);
        error_log('AuthController::register - Data received: ' . json_encode(['has_data' => !empty($data), 'email' => $data['email'] ?? 'missing']));

        if (!$data) {
            error_log('AuthController::register - Invalid JSON');
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            error_log('AuthController::register - Missing email or password');
            $this->sendResponse(['error' => 'Email and password are required'], 400);
            return;
        }

        try {
            error_log('AuthController::register - Calling authService->register');
            $result = $this->authService->register($email, $password);
            error_log('AuthController::register - Registration successful');
            $this->sendResponse([
                'success' => true,
                'message' => $result['message'] ?? 'User registered successfully. Please wait for admin approval.',
                'data' => $result
            ], 201);
        } catch (\InvalidArgumentException $e) {
            error_log('AuthController::register - InvalidArgumentException: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            error_log('AuthController::register - RuntimeException: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE 
                ? $e->getMessage() 
                : 'Registration failed. Please try again.';
            error_log('AuthController::register - Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('AuthController::register - Stack trace: ' . $e->getTraceAsString());
            $this->sendResponse(['error' => $errorMessage], 500);
        }
    }

    public function login(): void
    {
        error_log('AuthController::login - Starting');
        $data = json_decode(file_get_contents('php://input'), true);
        error_log('AuthController::login - Data received: ' . json_encode(['email' => $data['email'] ?? 'missing']));

        if (!$data) {
            error_log('AuthController::login - Invalid JSON');
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            error_log('AuthController::login - Missing email or password');
            $this->sendResponse(['error' => 'Email and password are required'], 400);
            return;
        }

        error_log('AuthController::login - Calling authService->login');
        try {
            $result = $this->authService->login($email, $password);
            error_log('AuthController::login - authService->login returned, type: ' . gettype($result));
            error_log('AuthController::login - Result keys: ' . (is_array($result) ? implode(', ', array_keys($result)) : 'not an array'));
            
            if (!is_array($result)) {
                error_log('AuthController::login - ERROR: Result is not an array!');
                throw new \RuntimeException('Invalid response from login service');
            }
            
            error_log('AuthController::login - Result: ' . json_encode(['has_user' => isset($result['user']), 'has_token' => isset($result['token'])]));
            error_log('AuthController::login - About to call sendResponse');
            
            $responseData = [
                'success' => true,
                'message' => 'Login successful',
                'data' => $result
            ];
            
            error_log('AuthController::login - Response data prepared, calling sendResponse');
            $this->sendResponse($responseData);
            error_log('AuthController::login - sendResponse completed (should not reach here)');
        } catch (\RuntimeException $e) {
            error_log('AuthController::login - RuntimeException: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 401);
        } catch (\Exception $e) {
            $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE 
                ? $e->getMessage() 
                : 'Login failed';
            error_log('Login error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->sendResponse(['error' => $errorMessage], 500);
        }
    }

    public function forgotPassword(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $email = $data['email'] ?? '';

        if (empty($email)) {
            $this->sendResponse(['error' => 'Email is required'], 400);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendResponse(['error' => 'Invalid email address'], 400);
            return;
        }

        try {
            $this->authService->requestPasswordReset($email);
            // Always return success to prevent email enumeration
            $this->sendResponse([
                'success' => true,
                'message' => 'If an account with that email exists, a password reset link has been sent.'
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => 'Failed to process password reset request'], 500);
        }
    }

    public function resetPassword(): void
    {
        // Support both POST (with JSON body) and GET (with token in query string)
        $data = [];
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $this->sendResponse(['error' => 'Invalid JSON'], 400);
                return;
            }
        } else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // For GET requests, get token from query string
            $data['token'] = $_GET['token'] ?? '';
        }

        $token = $data['token'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($token)) {
            $this->sendResponse(['error' => 'Token is required'], 400);
            return;
        }

        // If password is not provided, validate the token (for frontend forms)
        if (empty($password)) {
            $user = $this->authService->validateResetToken($token);
            if ($user) {
                $this->sendResponse([
                    'success' => true,
                    'valid' => true,
                    'message' => 'Token is valid. Please provide a new password.'
                ]);
            } else {
                $this->sendResponse(['error' => 'Invalid or expired reset token'], 400);
            }
            return;
        }

        // Password is provided, proceed with reset
        try {
            $this->authService->resetPassword($token, $password);
            $this->sendResponse([
                'success' => true,
                'message' => 'Password has been reset successfully'
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => 'Failed to reset password'], 500);
        }
    }

    public function me(): void
    {
        // This endpoint should be protected by JWT middleware
        // The user data should be passed from the middleware
        $user = $_SERVER['AUTH_USER'] ?? null;
        
        if (!$user) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $this->sendResponse([
            'success' => true,
            'data' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'client',
                'role_id' => isset($user['role_id']) ? (int)$user['role_id'] : null,
                'client_id' => isset($user['client_id']) ? (int)$user['client_id'] : null,
                'is_approved' => (int)($user['is_approved'] ?? 0)
            ]
        ]);
    }

    public function approveUser(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $roleId = isset($data['role_id']) ? (int)$data['role_id'] : null;
        $clientId = isset($data['client_id']) ? (int)$data['client_id'] : null;

        if ($userId <= 0) {
            $this->sendResponse(['error' => 'Valid user_id is required'], 400);
            return;
        }

        if ($roleId === null || $roleId <= 0) {
            $this->sendResponse(['error' => 'Valid role_id is required'], 400);
            return;
        }

        $approvedBy = $_SERVER['AUTH_USER_ID'] ?? 0;
        if ($approvedBy <= 0) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        try {
            $this->authService->approveUser($userId, $approvedBy, $roleId, $clientId);
            $this->sendResponse([
                'success' => true,
                'message' => 'User approved successfully'
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getPendingUsers(): void
    {
        try {
            $users = $this->authService->getPendingUsers();
            $this->sendResponse([
                'success' => true,
                'data' => $users,
                'count' => count($users)
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getAllUsers(): void
    {
        try {
            $users = $this->authService->getAllUsers();
            $this->sendResponse([
                'success' => true,
                'data' => $users,
                'count' => count($users)
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        error_log('AuthController::sendResponse - Status: ' . $statusCode . ', Data keys: ' . implode(', ', array_keys($data)));
        
        // Disable all output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Ensure headers haven't been sent
        if (headers_sent($file, $line)) {
            error_log('AuthController::sendResponse - WARNING: Headers already sent in ' . $file . ':' . $line);
        }
        
        // Set status code
        http_response_code($statusCode);
        
        // Get CORS origin from request - ALWAYS use HTTP_ORIGIN header
        // This is the standard header browsers send for CORS requests
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Always set CORS headers using the request origin (if present)
        // This is critical for cross-origin requests to work
        // NEVER use HTTP_HOST as it would be the API's own domain, not the frontend
        if ($origin) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true");
            error_log('AuthController::sendResponse - Setting CORS origin: ' . $origin);
        } else {
            // Log warning but don't set CORS header if origin is missing
            // Setting it to the API's own domain would cause CORS errors
            error_log('AuthController::sendResponse - WARNING: HTTP_ORIGIN header not found in request. CORS may fail.');
            error_log('AuthController::sendResponse - Available headers: ' . json_encode(array_keys(array_filter($_SERVER, function($k) { return strpos($k, 'HTTP_') === 0; }, ARRAY_FILTER_USE_KEY))));
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
        header('Access-Control-Expose-Headers: Content-Type, Authorization');
        
        // Set response headers
        header('Content-Type: application/json; charset=utf-8');
        
        // Encode JSON once
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // Validate JSON encoding
        if ($json === false) {
            error_log('AuthController::sendResponse - JSON encoding failed: ' . json_last_error_msg());
            $json = json_encode([
                'error' => 'Response encoding failed',
                'success' => false
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        error_log('AuthController::sendResponse - JSON length: ' . strlen($json));
        
        // Clean any remaining output buffers before sending
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Send response
        error_log('AuthController::sendResponse - About to echo JSON');
        echo $json;
        error_log('AuthController::sendResponse - JSON echoed');
        
        // Flush output
        flush();
        error_log('AuthController::sendResponse - Output flushed');
        
        // FastCGI finish request if available
        if (function_exists('fastcgi_finish_request')) {
            error_log('AuthController::sendResponse - Calling fastcgi_finish_request');
            fastcgi_finish_request();
        }
        
        error_log('AuthController::sendResponse - Response sent, calling exit');
        exit(0);
    }
}

