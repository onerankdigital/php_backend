<?php

declare(strict_types=1);

namespace App;

use App\Controllers\EnquiryController;
use App\Controllers\KeyRotationController;
use App\Controllers\SecurityController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\AnalyticsController;
use App\Controllers\FileUploadController;
use App\Controllers\FormConfigController;
use App\Controllers\RoleController;
use App\Controllers\ServiceController;
use App\Services\JwtService;
use App\Services\AuthService;

class Routes
{
    private EnquiryController $enquiryController;
    private SecurityController $securityController;
    private KeyRotationController $keyRotationController;
    private AuthController $authController;
    private ClientController $clientController;
    private AnalyticsController $analyticsController;
    private FileUploadController $fileUploadController;
    private FormConfigController $formConfigController;
    private RoleController $roleController;
    private \App\Controllers\TransactionController $transactionController;
    private ServiceController $serviceController;
    private JwtService $jwtService;
    private AuthService $authService;

    public function __construct(
        EnquiryController $enquiryController,
        SecurityController $securityController,
        KeyRotationController $keyRotationController,
        AuthController $authController,
        ClientController $clientController,
        AnalyticsController $analyticsController,
        FileUploadController $fileUploadController,
        FormConfigController $formConfigController,
        RoleController $roleController,
        \App\Controllers\TransactionController $transactionController,
        ServiceController $serviceController,
        JwtService $jwtService,
        AuthService $authService
    ) {
        $this->enquiryController = $enquiryController;
        $this->securityController = $securityController;
        $this->keyRotationController = $keyRotationController;
        $this->authController = $authController;
        $this->clientController = $clientController;
        $this->analyticsController = $analyticsController;
        $this->fileUploadController = $fileUploadController;
        $this->formConfigController = $formConfigController;
        $this->roleController = $roleController;
        $this->transactionController = $transactionController;
        $this->serviceController = $serviceController;
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

        // Transaction routes - protected
        if ($parts[0] === 'transactions') {
            $this->requireAuth();
            $this->handleTransactionRoutes($method, $parts);
            return;
        }

        // Form configurations routes
        if ($parts[0] === 'form-configs') {
            $this->handleFormConfigRoutes($method, $parts);
            return;
        }

        // Roles and permissions routes (admin only)
        if ($parts[0] === 'roles') {
            $this->requireAuth();
            $this->requireAdmin();
            $this->handleRoleRoutes($method, $parts);
            return;
        }

        // Permissions routes (admin only)
        if ($parts[0] === 'permissions') {
            $this->requireAuth();
            $this->requireAdmin();
            $this->handlePermissionRoutes($method, $parts);
            return;
        }

        // Services routes (admin can manage, others can view)
        if ($parts[0] === 'services') {
            $this->requireAuth();
            $this->handleServiceRoutes($method, $parts);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleAuthRoutes(string $method, array $parts): void
    {
        error_log('Routes::handleAuthRoutes - Method: ' . $method . ', Parts: ' . json_encode($parts));
        
        // POST /api/auth/register
        if (count($parts) === 2 && $parts[1] === 'register') {
            if ($method !== 'POST') {
                $this->sendResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
                return;
            }
            error_log('Routes::handleAuthRoutes - Calling register');
            $this->authController->register();
            error_log('Routes::handleAuthRoutes - register completed');
            return;
        }

        // POST /api/auth/login
        if (count($parts) === 2 && $parts[1] === 'login') {
            if ($method !== 'POST') {
                $this->sendResponse(['error' => 'Method Not Allowed. Use POST.'], 405);
                return;
            }
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

        // GET /api/auth/users (protected - all authenticated users)
        if (count($parts) === 2 && $parts[1] === 'users' && $method === 'GET') {
            $this->requireAuth();
            $this->authController->getAllUsers();
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
        error_log('Routes::handleEnquiryRoutes - Calling requireAuth');
        $this->requireAuth();
        error_log('Routes::handleEnquiryRoutes - requireAuth completed');

        // GET /api/enquiries
        if (count($parts) === 1 && $method === 'GET') {
            error_log('Routes::handleEnquiryRoutes - Calling getAll');
            $this->enquiryController->getAll();
            error_log('Routes::handleEnquiryRoutes - getAll completed');
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
        try {
            error_log('Routes::requireAuth - Starting authentication');
            $token = $this->jwtService->getTokenFromRequest();
            error_log('Routes::requireAuth - Token retrieved: ' . ($token ? 'YES' : 'NO'));
            
            if (!$token) {
                error_log('Routes::requireAuth - No token found, sending 401');
                $this->sendResponse(['error' => 'Unauthorized - Token required'], 401);
                return;
            }

            error_log('Routes::requireAuth - Validating token');
            $tokenData = $this->jwtService->validateToken($token);
            error_log('Routes::requireAuth - Token validation result: ' . ($tokenData ? 'VALID' : 'INVALID'));
            
            if (!$tokenData) {
                error_log('Routes::requireAuth - Invalid token, sending 401');
                $this->sendResponse(['error' => 'Unauthorized - Invalid or expired token'], 401);
                return;
            }

            error_log('Routes::requireAuth - Getting current user');
            // Get user data and store in $_SERVER for controllers to access
            $user = $this->authService->getCurrentUser($token);
            error_log('Routes::requireAuth - User retrieved: ' . ($user ? 'YES (ID: ' . ($user['id'] ?? 'N/A') . ')' : 'NO'));
            
            if (!$user) {
                error_log('Routes::requireAuth - User not found, sending 401');
                $this->sendResponse(['error' => 'Unauthorized - User not found'], 401);
                return;
            }

            // Store authenticated user data in $_SERVER for controllers
            $_SERVER['AUTH_USER'] = $user;
            $_SERVER['AUTH_USER_ID'] = (int)$user['id'];
            $_SERVER['AUTH_USER_ROLE'] = $user['role'] ?? 'client';
            $_SERVER['AUTH_USER_ROLE_ID'] = isset($user['role_id']) ? (int)$user['role_id'] : null;
            $_SERVER['AUTH_USER_CLIENT_ID'] = isset($user['client_id']) ? (int)$user['client_id'] : null;
            error_log('Routes::requireAuth - Authentication successful for user ID: ' . $_SERVER['AUTH_USER_ID']);
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('Routes::requireAuth - Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('Routes::requireAuth - Stack trace: ' . $e->getTraceAsString());
            $this->sendResponse(['error' => 'Unauthorized - Authentication failed'], 401);
            return;
        }
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
        error_log('Routes::handleClientRoutes - Method: ' . $method . ', Parts count: ' . count($parts));
        $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        error_log('Routes::handleClientRoutes - User role: ' . $userRole);
        
        // POST /api/clients (admin only)
        if (count($parts) === 1 && $method === 'POST') {
            $this->requireAdmin();
            error_log('Routes::handleClientRoutes - Calling create');
            $this->clientController->create();
            error_log('Routes::handleClientRoutes - create completed');
            return;
        }

        // GET /api/clients (admin, sales_manager, sales_person, employee)
        if (count($parts) === 1 && $method === 'GET') {
            error_log('Routes::handleClientRoutes - Calling getAll');
            $this->clientController->getAll();
            error_log('Routes::handleClientRoutes - getAll completed');
            return;
        }

        // GET /api/clients/{id}/transactions - Get client transactions
        if (count($parts) === 3 && $parts[2] === 'transactions' && $method === 'GET') {
            $clientId = $parts[1];
            $this->transactionController->getByClient($clientId);
            return;
        }

        // GET /api/clients/{id}/payment-summary - Get payment summary
        if (count($parts) === 3 && $parts[2] === 'payment-summary' && $method === 'GET') {
            $clientId = $parts[1];
            $this->transactionController->getClientSummary($clientId);
            return;
        }

        // GET /api/clients/{client_id}/services - Get client services
        if (count($parts) === 3 && $parts[2] === 'services' && $method === 'GET') {
            $clientId = $parts[1];
            $this->serviceController->getClientServices($clientId);
            return;
        }

        // POST /api/clients/{client_id}/services - Assign services to client (admin only)
        if (count($parts) === 3 && $parts[2] === 'services' && $method === 'POST') {
            $this->requireAdmin();
            $clientId = $parts[1];
            $this->serviceController->assignServicesToClient($clientId);
            return;
        }

        // PUT /api/clients/{id} (admin only)
        if (count($parts) === 2 && $method === 'PUT') {
            $this->requireAdmin();
            $clientId = $parts[1];
            $this->clientController->update($clientId);
            return;
        }

        // DELETE /api/clients/{id} (admin only)
        if (count($parts) === 2 && $method === 'DELETE') {
            $this->requireAdmin();
            $clientId = $parts[1];
            $this->clientController->delete($clientId);
            return;
        }

        // GET /api/clients/{id} (admin, sales_manager, sales_person, employee)
        // This must come LAST to avoid matching sub-routes like /transactions or /services
        if (count($parts) === 2 && $method === 'GET') {
            $clientId = $parts[1];
            $this->clientController->get($clientId);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleSalesRoutes(string $method, array $parts): void
    {
        // Sales routes have been deprecated - using dynamic role management instead
        $this->sendResponse([
            'error' => 'Sales routes deprecated. Please use dynamic role management and user endpoints instead.'
        ], 410);
    }

    private function handleAnalyticsRoutes(string $method, array $parts): void
    {
        error_log('Routes::handleAnalyticsRoutes - Method: ' . $method . ', Parts count: ' . count($parts));
        // GET /api/analytics
        if (count($parts) === 1 && $method === 'GET') {
            error_log('Routes::handleAnalyticsRoutes - Calling getAnalytics');
            $this->analyticsController->getAnalytics();
            error_log('Routes::handleAnalyticsRoutes - getAnalytics completed');
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleTransactionRoutes(string $method, array $parts): void
    {
        // GET /api/transactions - Get all transactions
        if (count($parts) === 1 && $method === 'GET') {
            $this->transactionController->getAll();
            return;
        }

        // POST /api/transactions - Create transaction
        if (count($parts) === 1 && $method === 'POST') {
            $this->transactionController->create();
            return;
        }

        // GET /api/transactions/payment-methods - Get payment methods (no auth required)
        if (count($parts) === 2 && $parts[1] === 'payment-methods' && $method === 'GET') {
            $this->transactionController->getPaymentMethods();
            return;
        }

        // GET /api/transactions/statistics - Get statistics
        if (count($parts) === 2 && $parts[1] === 'statistics' && $method === 'GET') {
            $this->transactionController->getStatistics();
            return;
        }

        // GET /api/transactions/{id} - Get single transaction
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $this->transactionController->get((int)$parts[1]);
            return;
        }

        // PUT /api/transactions/{id} - Update transaction
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'PUT') {
            $this->transactionController->update((int)$parts[1]);
            return;
        }

        // DELETE /api/transactions/{id} - Delete transaction
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
            $this->transactionController->delete((int)$parts[1]);
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

    private function handleRoleRoutes(string $method, array $parts): void
    {
        // GET /api/roles - Get all roles
        if (count($parts) === 1 && $method === 'GET') {
            $this->roleController->getAllRoles();
            return;
        }

        // GET /api/roles/hierarchy - Get role hierarchy
        if (count($parts) === 2 && $parts[1] === 'hierarchy' && $method === 'GET') {
            $this->roleController->getRoleHierarchy();
            return;
        }

        // POST /api/roles - Create role
        if (count($parts) === 1 && $method === 'POST') {
            $this->roleController->createRole();
            return;
        }

        // GET /api/roles/{id} - Get role by ID
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $id = (int)$parts[1];
            $this->roleController->getRole($id);
            return;
        }

        // PUT /api/roles/{id} - Update role
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'PUT') {
            $id = (int)$parts[1];
            $this->roleController->updateRole($id);
            return;
        }

        // DELETE /api/roles/{id} - Delete role
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
            $id = (int)$parts[1];
            $this->roleController->deleteRole($id);
            return;
        }

        // GET /api/roles/{id}/permissions - Get permissions for a role
        if (count($parts) === 3 && is_numeric($parts[1]) && $parts[2] === 'permissions' && $method === 'GET') {
            $id = (int)$parts[1];
            $this->roleController->getRolePermissions($id);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handlePermissionRoutes(string $method, array $parts): void
    {
        // GET /api/permissions - Get all permissions
        if (count($parts) === 1 && $method === 'GET') {
            $this->roleController->getAllPermissions();
            return;
        }

        // GET /api/permissions/resources - Get all resources
        if (count($parts) === 2 && $parts[1] === 'resources' && $method === 'GET') {
            $this->roleController->getResources();
            return;
        }

        // POST /api/permissions - Create permission
        if (count($parts) === 1 && $method === 'POST') {
            $this->roleController->createPermission();
            return;
        }

        // GET /api/permissions/{id} - Get permission by ID
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $id = (int)$parts[1];
            $this->roleController->getPermission($id);
            return;
        }

        // PUT /api/permissions/{id} - Update permission
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'PUT') {
            $id = (int)$parts[1];
            $this->roleController->updatePermission($id);
            return;
        }

        // DELETE /api/permissions/{id} - Delete permission
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
            $id = (int)$parts[1];
            $this->roleController->deletePermission($id);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function handleServiceRoutes(string $method, array $parts): void
    {
        // GET /api/services - Get all services
        if (count($parts) === 1 && $method === 'GET') {
            $this->serviceController->getAllServices();
            return;
        }

        // POST /api/services - Create service (admin only)
        if (count($parts) === 1 && $method === 'POST') {
            $this->requireAdmin();
            $this->serviceController->createService();
            return;
        }

        // GET /api/services/{id} - Get service by ID
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'GET') {
            $id = (int)$parts[1];
            $this->serviceController->getService($id);
            return;
        }

        // PUT /api/services/{id} - Update service (admin only)
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'PUT') {
            $this->requireAdmin();
            $id = (int)$parts[1];
            $this->serviceController->updateService($id);
            return;
        }

        // DELETE /api/services/{id} - Delete service (admin only)
        if (count($parts) === 2 && is_numeric($parts[1]) && $method === 'DELETE') {
            $this->requireAdmin();
            $id = (int)$parts[1];
            $this->serviceController->deleteService($id);
            return;
        }

        // GET /api/services/{id}/sub-services - Get sub-services by service ID
        if (count($parts) === 3 && is_numeric($parts[1]) && $parts[2] === 'sub-services' && $method === 'GET') {
            $serviceId = (int)$parts[1];
            $this->serviceController->getSubServices($serviceId);
            return;
        }

        // POST /api/services/sub-services - Create sub-service (admin only)
        if (count($parts) === 2 && $parts[1] === 'sub-services' && $method === 'POST') {
            $this->requireAdmin();
            $this->serviceController->createSubService();
            return;
        }

        // PUT /api/services/sub-services/{id} - Update sub-service (admin only)
        if (count($parts) === 3 && $parts[1] === 'sub-services' && is_numeric($parts[2]) && $method === 'PUT') {
            $this->requireAdmin();
            $id = (int)$parts[2];
            $this->serviceController->updateSubService($id);
            return;
        }

        // DELETE /api/services/sub-services/{id} - Delete sub-service (admin only)
        if (count($parts) === 3 && $parts[1] === 'sub-services' && is_numeric($parts[2]) && $method === 'DELETE') {
            $this->requireAdmin();
            $id = (int)$parts[2];
            $this->serviceController->deleteSubService($id);
            return;
        }

        $this->sendResponse(['error' => 'Not Found'], 404);
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        error_log('Routes::sendResponse - Status: ' . $statusCode . ', Data keys: ' . implode(', ', array_keys($data)));
        
        // Clean any output buffers to prevent JSON corruption
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Set CORS headers if origin is present
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true");
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Admin-Token');
        
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            // JSON encoding failed, send a simple error
            error_log('Routes::sendResponse - JSON encoding failed: ' . json_last_error_msg());
            $json = '{"error":"Internal Server Error - Invalid response data","success":false}';
        }
        
        error_log('Routes::sendResponse - About to echo JSON (length: ' . strlen($json) . ')');
        echo $json;
        error_log('Routes::sendResponse - JSON echoed, calling exit');
        exit;
    }
}

