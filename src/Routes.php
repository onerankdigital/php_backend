<?php

declare(strict_types=1);

namespace App;

use App\Controllers\EnquiryController;
use App\Controllers\KeyRotationController;
use App\Controllers\SecurityController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\SalesPersonController;
use App\Controllers\SalesManagerController;
use App\Controllers\AnalyticsController;
use App\Controllers\FileUploadController;
use App\Controllers\FormConfigController;
use App\Services\JwtService;
use App\Services\AuthService;

class Routes
{
    private EnquiryController $enquiryController;
    private SecurityController $securityController;
    private KeyRotationController $keyRotationController;
    private AuthController $authController;
    private ClientController $clientController;
    private SalesPersonController $salesPersonController;
    private SalesManagerController $salesManagerController;
    private AnalyticsController $analyticsController;
    private FileUploadController $fileUploadController;
    private FormConfigController $formConfigController;
    private JwtService $jwtService;
    private AuthService $authService;

    public function __construct(
        EnquiryController $enquiryController,
        SecurityController $securityController,
        KeyRotationController $keyRotationController,
        AuthController $authController,
        ClientController $clientController,
        SalesPersonController $salesPersonController,
        SalesManagerController $salesManagerController,
        AnalyticsController $analyticsController,
        FileUploadController $fileUploadController,
        FormConfigController $formConfigController,
        JwtService $jwtService,
        AuthService $authService
    ) {
        $this->enquiryController = $enquiryController;
        $this->securityController = $securityController;
        $this->keyRotationController = $keyRotationController;
        $this->authController = $authController;
        $this->clientController = $clientController;
        $this->salesPersonController = $salesPersonController;
        $this->salesManagerController = $salesManagerController;
        $this->analyticsController = $analyticsController;
        $this->fileUploadController = $fileUploadController;
        $this->formConfigController = $formConfigController;
        $this->jwtService = $jwtService;
        $this->authService = $authService;
    }

    public function dispatch(string $method, string $uri): void
    {
        error_log('Routes::dispatch - Method: ' . $method . ', URI: ' . $uri);
        // Remove leading slash
        $uri = ltrim($uri, '/');
        error_log('Routes::dispatch - Processed URI: ' . $uri);

        // API routes
        if (strpos($uri, 'api/') === 0) {
            error_log('Routes::dispatch - Calling handleApiRoutes');
            $this->handleApiRoutes($method, $uri);
            error_log('Routes::dispatch - handleApiRoutes completed');
            return;
        }

        // Not found
        error_log('Routes::dispatch - Route not found, sending 404');
        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleApiRoutes(string $method, string $uri): void
    {
        error_log('Routes::handleApiRoutes - Method: ' . $method . ', URI: ' . $uri);
        $parts = explode('/', $uri);
        array_shift($parts); // Remove 'api'
        error_log('Routes::handleApiRoutes - Parts: ' . json_encode($parts));

        // Auth routes (public)
        if ($parts[0] === 'auth') {
            error_log('Routes::handleApiRoutes - Calling handleAuthRoutes');
            $this->handleAuthRoutes($method, $parts);
            error_log('Routes::handleApiRoutes - handleAuthRoutes completed');
            return;
        }

        // File upload routes (public)
        if ($parts[0] === 'upload') {
            $this->handleUploadRoutes($method, $parts);
            return;
        }

        // Enquiries routes
        if ($parts[0] === 'enquiries') {
            $this->handleEnquiryRoutes($method, $parts);
            return;
        }

        // Clients routes (admin, sales_manager, sales_person, employee can view)
        if ($parts[0] === 'clients') {
            $this->requireAuth();
            $this->handleClientRoutes($method, $parts);
            return;
        }

        // Security routes (for token generation) - PUBLIC (needed for enquiry form)
        if ($parts[0] === 'security') {
            $this->handleSecurityRoutes($method, $parts);
            return;
        }

        // Admin routes (for key rotation) - protected
        if ($parts[0] === 'admin') {
            $this->requireAuth();
            $this->handleAdminRoutes($method, $parts);
            return;
        }

        // Sales routes (for sales managers to view their sales persons) - protected
        if ($parts[0] === 'sales') {
            $this->requireAuth();
            $this->handleSalesRoutes($method, $parts);
            return;
        }

        // Analytics routes - protected
        if ($parts[0] === 'analytics') {
            $this->requireAuth();
            $this->handleAnalyticsRoutes($method, $parts);
            return;
        }

        // Form configurations routes
        if ($parts[0] === 'form-configs') {
            $this->handleFormConfigRoutes($method, $parts);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleAuthRoutes(string $method, array $parts): void
    {
        error_log('Routes::handleAuthRoutes - Method: ' . $method . ', Parts: ' . json_encode($parts));
        
        // POST /api/auth/register
        if (count($parts) === 2 && $parts[1] === 'register' && $method === 'POST') {
            error_log('Routes::handleAuthRoutes - Calling register');
            $this->authController->register();
            error_log('Routes::handleAuthRoutes - register completed');
            return;
        }

        // POST /api/auth/login
        if (count($parts) === 2 && $parts[1] === 'login' && $method === 'POST') {
            error_log('Routes::handleAuthRoutes - Calling login');
            $this->authController->login();
            error_log('Routes::handleAuthRoutes - login completed');
            return;
        }

        // POST /api/auth/forgot-password
        if (count($parts) === 2 && $parts[1] === 'forgot-password' && $method === 'POST') {
            $this->authController->forgotPassword();
            return;
        }

        // POST /api/auth/reset-password
        if (count($parts) === 2 && $parts[1] === 'reset-password' && ($method === 'POST' || $method === 'GET')) {
            $this->authController->resetPassword();
            return;
        }

        // GET /api/auth/me (protected)
        if (count($parts) === 2 && $parts[1] === 'me' && $method === 'GET') {
            $this->requireAuth();
            $this->authController->me();
            return;
        }

        // POST /api/auth/approve (admin only)
        if (count($parts) === 2 && $parts[1] === 'approve' && $method === 'POST') {
            $this->requireAuth();
            $this->requireAdmin();
            $this->authController->approveUser();
            return;
        }

        // GET /api/auth/pending (admin only)
        if (count($parts) === 2 && $parts[1] === 'pending' && $method === 'GET') {
            $this->requireAuth();
            $this->requireAdmin();
            $this->authController->getPendingUsers();
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleEnquiryRoutes(string $method, array $parts): void
    {
        // POST /api/enquiries (PUBLIC - no auth required)
        if (count($parts) === 1 && $method === 'POST') {
            $this->enquiryController->create();
            return;
        }

        // All other enquiry routes require authentication
        $this->requireAuth();

        // GET /api/enquiries
        if (count($parts) === 1 && $method === 'GET') {
            $this->enquiryController->getAll();
            return;
        }

        // GET /api/enquiries/company/{companyName}
        if (count($parts) === 3 && $parts[1] === 'company' && $method === 'GET') {
            $companyName = urldecode($parts[2]);
            $this->enquiryController->searchByCompanyName($companyName);
            return;
        }

        // GET /api/enquiries/domain/{domain}
        if (count($parts) === 3 && $parts[1] === 'domain' && $method === 'GET') {
            $domain = urldecode($parts[2]);
            $this->enquiryController->searchByDomain($domain);
            return;
        }

        // GET /api/enquiries/search/{id}
        if (count($parts) === 3 && $parts[1] === 'search' && $method === 'GET' && is_numeric($parts[2])) {
            $id = (int)$parts[2];
            $this->enquiryController->searchById($id);
            return;
        }

        // GET /api/enquiries/{id}
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $id = (int)$parts[1];
            $this->enquiryController->get($id);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleSecurityRoutes(string $method, array $parts): void
    {
        // GET /api/security/csrf
        if (count($parts) === 2 && $parts[1] === 'csrf' && $method === 'GET') {
            $this->securityController->getCsrfToken();
            return;
        }

        // GET /api/security/token
        if (count($parts) === 2 && $parts[1] === 'token' && $method === 'GET') {
            $this->securityController->getJsToken();
            return;
        }

        // GET /api/security/captcha
        if (count($parts) === 2 && $parts[1] === 'captcha' && $method === 'GET') {
            $this->securityController->getCaptcha();
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleAdminRoutes(string $method, array $parts): void
    {
        // POST /api/admin/rotate-keys
        if (count($parts) === 2 && $parts[1] === 'rotate-keys' && $method === 'POST') {
            $this->keyRotationController->rotateKeys();
            return;
        }

        // GET /api/admin/rotation-status
        if (count($parts) === 2 && $parts[1] === 'rotation-status' && $method === 'GET') {
            $this->keyRotationController->getStatus();
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function requireAuth(): void
    {
        $token = $this->jwtService->getTokenFromRequest();
        
        if (!$token) {
            $this->sendResponse(['error' => 'Unauthorized - Token required'], 401);
            return;
        }

        $tokenData = $this->jwtService->validateToken($token);
        
        if (!$tokenData) {
            $this->sendResponse(['error' => 'Unauthorized - Invalid or expired token'], 401);
            return;
        }

        // Get user data and store in $_SERVER for controllers to access
        $user = $this->authService->getCurrentUser($token);
        if (!$user) {
            $this->sendResponse(['error' => 'Unauthorized - User not found'], 401);
            return;
        }

        // Store authenticated user data in $_SERVER for controllers
        $_SERVER['AUTH_USER'] = $user;
        $_SERVER['AUTH_USER_ID'] = (int)$user['id'];
        $_SERVER['AUTH_USER_ROLE'] = $user['role'] ?? 'client';
        $_SERVER['AUTH_USER_CLIENT_ID'] = isset($user['client_id']) ? (int)$user['client_id'] : null;
        $_SERVER['AUTH_USER_SALES_MANAGER_ID'] = isset($user['sales_manager_id']) ? (int)$user['sales_manager_id'] : null;
        $_SERVER['AUTH_USER_SALES_PERSON_ID'] = isset($user['sales_person_id']) ? (int)$user['sales_person_id'] : null;
        $_SERVER['AUTH_USER_EMPLOYEE_ID'] = isset($user['employee_id']) ? (int)$user['employee_id'] : null;
    }

    private function requireAdmin(): void
    {
        $role = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        if ($role !== 'admin') {
            $this->sendResponse(['error' => 'Forbidden - Admin access required'], 403);
            return;
        }
    }

    private function handleClientRoutes(string $method, array $parts): void
    {
        $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        
        // POST /api/clients (admin only)
        if (count($parts) === 1 && $method === 'POST') {
            $this->requireAdmin();
            $this->clientController->create();
            return;
        }

        // GET /api/clients (admin, sales_manager, sales_person, employee)
        if (count($parts) === 1 && $method === 'GET') {
            $this->clientController->getAll();
            return;
        }

        // GET /api/clients/{id} (admin, sales_manager, sales_person, employee)
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $id = (int)$parts[1];
            $this->clientController->get($id);
            return;
        }

        // PUT /api/clients/{id} (admin only)
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'PUT') {
            $this->requireAdmin();
            $id = (int)$parts[1];
            $this->clientController->update($id);
            return;
        }

        // DELETE /api/clients/{id} (admin only)
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
            $this->requireAdmin();
            $id = (int)$parts[1];
            $this->clientController->delete($id);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleSalesRoutes(string $method, array $parts): void
    {
        // GET /api/sales/managers (admin only)
        if (count($parts) === 2 && $parts[1] === 'managers' && $method === 'GET') {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
            
            // Only admin can access this
            if ($userRole !== 'admin') {
                $this->sendResponse(['error' => 'Forbidden - Admin access required'], 403);
                return;
            }
            
            $this->salesManagerController->getAll();
            return;
        }

        // GET /api/sales/persons (sales_manager can view their own sales persons)
        if (count($parts) === 2 && $parts[1] === 'persons' && $method === 'GET') {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            
            // Only sales managers can access this
            if ($userRole !== 'sales_manager' || !$salesManagerId) {
                $this->sendResponse(['error' => 'Forbidden - Sales manager access required'], 403);
                return;
            }
            
            // Sales manager can only view their own sales persons
            $this->salesPersonController->getByManager($salesManagerId);
            return;
        }

        // GET /api/sales/persons/{managerId} (for sales managers to get their sales persons)
        if (count($parts) === 3 && $parts[1] === 'persons' && is_numeric($parts[2]) && $method === 'GET') {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            $requestedManagerId = (int)$parts[2];
            
            // Only sales managers can access this, and only their own sales persons
            if ($userRole !== 'sales_manager' || !$salesManagerId || $salesManagerId !== $requestedManagerId) {
                $this->sendResponse(['error' => 'Forbidden - You can only view your own sales persons'], 403);
                return;
            }
            
            $this->salesPersonController->getByManager($requestedManagerId);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleAnalyticsRoutes(string $method, array $parts): void
    {
        // GET /api/analytics
        if (count($parts) === 1 && $method === 'GET') {
            $this->analyticsController->getAnalytics();
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleUploadRoutes(string $method, array $parts): void
    {
        // POST /api/upload (PUBLIC - no auth required)
        if (count($parts) === 1 && $method === 'POST') {
            $this->fileUploadController->upload();
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleFormConfigRoutes(string $method, array $parts): void
    {
        // GET /api/form-configs/key/{config_key} - Public endpoint for iframe form
        if (count($parts) === 3 && $parts[1] === 'key' && $method === 'GET') {
            $configKey = urldecode($parts[2]);
            $this->formConfigController->getByKey($configKey);
            return;
        }

        // All other form-config routes require authentication
        $this->requireAuth();

        // GET /api/form-configs - Get all configurations
        if (count($parts) === 1 && $method === 'GET') {
            $this->formConfigController->getAll();
            return;
        }

        // POST /api/form-configs - Create new configuration
        if (count($parts) === 1 && $method === 'POST') {
            $this->formConfigController->create();
            return;
        }

        // POST /api/form-configs/{id}/duplicate - Duplicate configuration
        if (count($parts) === 3 && $parts[2] === 'duplicate' && is_numeric($parts[1]) && $method === 'POST') {
            $id = (int)$parts[1];
            $this->formConfigController->duplicate($id);
            return;
        }

        // GET /api/form-configs/{id} - Get configuration by ID
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $id = (int)$parts[1];
            $this->formConfigController->getById($id);
            return;
        }

        // PUT /api/form-configs/{id} - Update configuration
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'PUT') {
            $id = (int)$parts[1];
            $this->formConfigController->update($id);
            return;
        }

        // DELETE /api/form-configs/{id} - Delete configuration
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
            $id = (int)$parts[1];
            $this->formConfigController->delete($id);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

