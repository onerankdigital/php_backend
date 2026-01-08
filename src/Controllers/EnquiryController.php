<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EnquiryService;
use App\Services\SecurityService;
use App\Services\PermissionService;

class EnquiryController
{
    private EnquiryService $enquiryService;
    private SecurityService $securityService;
    private PermissionService $permissionService;

    public function __construct(
        EnquiryService $enquiryService,
        SecurityService $securityService,
        PermissionService $permissionService
    ) {
        $this->enquiryService = $enquiryService;
        $this->securityService = $securityService;
        $this->permissionService = $permissionService;
    }

    public function create(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        // Validate anti-spam checks
        $securityErrors = $this->securityService->validateSubmission($data);
        if (!empty($securityErrors)) {
            $this->sendResponse(['error' => implode(', ', $securityErrors)], 400);
            return;
        }

        try {
            // Clean up used tokens after successful validation
            $this->securityService->cleanupTokens($data);
            
            // Increment rate limit counter
            $this->securityService->incrementRateLimit();
            
            // Extract IP address and user agent
            $data['ip_address'] = $this->getClientIP();
            $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $enquiry = $this->enquiryService->create($data);
            $this->sendResponse([
                'success' => true,
                'message' => 'Thank you! Your enquiry has been submitted successfully. We will contact you soon.',
                'enquiry_id' => $enquiry['id']
            ], 201);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function getClientIP(): string
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                   'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function get(int $id): void
    {
        try {
            $enquiry = $this->enquiryService->get($id);
            $this->sendResponse($enquiry);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getAll(): void
    {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            // Validate limits
            $limit = max(1, min(1000, $limit));
            $offset = max(0, $offset);

            // Get user ID from auth
            $userId = $_SERVER['AUTH_USER_ID'] ?? null;
            if (!$userId) {
                $this->sendResponse(['error' => 'Unauthorized'], 401);
                return;
            }

            // Check permission
            if (!$this->permissionService->canAccess((int)$userId, 'enquiries', 'read')) {
                $this->sendResponse(['error' => 'Permission denied'], 403);
                return;
            }

            // Get accessible client IDs (includes hierarchy)
            $clientIds = $this->permissionService->getAccessibleClientIds(
                (int)$userId,
                $this->enquiryService->getUserClientRepository()
            );

            // Convert client IDs to domains for filtering
            $clientDomains = null;
            if ($clientIds !== null && !empty($clientIds)) {
                $clientDomains = $this->enquiryService->getDomainsByClientIds($clientIds);
            } elseif ($clientIds !== null && empty($clientIds)) {
                // User has no clients - return empty
                $clientDomains = [];
            }

            $enquiries = $this->enquiryService->getAll($limit, $offset, $clientDomains);
            
            // Add permission info to response
            $canCreate = $this->permissionService->canAccess((int)$userId, 'enquiries', 'create');
            $canUpdate = $this->permissionService->canAccess((int)$userId, 'enquiries', 'update');
            $canDelete = $this->permissionService->canAccess((int)$userId, 'enquiries', 'delete');
            
            $this->sendResponse([
                'data' => $enquiries,
                'count' => count($enquiries),
                'limit' => $limit,
                'offset' => $offset,
                'permissions' => [
                    'can_create' => $canCreate,
                    'can_update' => $canUpdate,
                    'can_delete' => $canDelete
                ]
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function searchById(int $id): void
    {
        try {
            $enquiry = $this->enquiryService->searchById($id);
            if ($enquiry) {
                $this->sendResponse($enquiry);
            } else {
                $this->sendResponse(['error' => 'Enquiry not found'], 404);
            }
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function searchByDomain(string $domain): void
    {
        try {
            $enquiries = $this->enquiryService->searchByDomain($domain);
            $this->sendResponse([
                'data' => $enquiries,
                'count' => count($enquiries),
                'domain' => $domain,
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function searchByCompanyName(string $companyName): void
    {
        try {
            $enquiries = $this->enquiryService->searchByCompanyName($companyName);
            $this->sendResponse([
                'data' => $enquiries,
                'count' => count($enquiries),
                'company_name' => $companyName,
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

