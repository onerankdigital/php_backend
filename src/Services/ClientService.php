<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\SalesManagerRepository;
use App\Repositories\SalesPersonRepository;
use App\Utils\Crypto;

class ClientService
{
    private ClientRepository $repository;
    private SalesManagerRepository $salesManagerRepository;
    private SalesPersonRepository $salesPersonRepository;
    private Crypto $crypto;

    public function __construct(
        ClientRepository $repository,
        Crypto $crypto,
        ?SalesManagerRepository $salesManagerRepository = null,
        ?SalesPersonRepository $salesPersonRepository = null
    ) {
        $this->repository = $repository;
        $this->crypto = $crypto;
        $this->salesManagerRepository = $salesManagerRepository;
        $this->salesPersonRepository = $salesPersonRepository;
    }

    public function create(array $data): array
    {
        // Validate required fields
        $required = ['package', 'client_name', 'person_name', 'address', 'phone', 'email', 'domains'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }

        // Validate domains (must be JSON array)
        $domains = is_array($data['domains']) ? $data['domains'] : json_decode($data['domains'], true);
        if (!is_array($domains) || empty($domains)) {
            throw new \InvalidArgumentException('Domains must be a non-empty JSON array');
        }

        // Encrypt sensitive fields (package, client_name, person_name, address, phone, email)
        // city, state, pincode remain plaintext
        $encrypted = [
            'package' => $this->crypto->encrypt($data['package']),
            'client_name' => $this->crypto->encrypt($data['client_name']),
            'person_name' => $this->crypto->encrypt($data['person_name']),
            'address' => $this->crypto->encrypt($data['address']),
            'phone' => $this->crypto->encrypt($data['phone']),
            'email' => $this->crypto->encrypt($data['email']),
            'domains' => json_encode($domains), // Plaintext JSON
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'pincode' => $data['pincode'] ?? null,
        ];

        $id = $this->repository->create($encrypted);

        return $this->get($id);
    }

    public function get(int $id, ?string $userRole = null): array
    {
        $client = $this->repository->getById($id);
        if (!$client) {
            throw new \RuntimeException('Client not found');
        }

        // Check if user has access to this client (pass the client record for direct checking)
        $this->checkClientAccess($id, $userRole, $client);

        $decryptedClient = $this->decryptClient($client, $userRole);
        
        // For sales managers, add assignment information (same as in getAll)
        if ($userRole === 'sales_manager') {
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            if ($salesManagerId && $this->salesManagerRepository) {
                // Get all sales persons under this manager with their names
                $salesPersons = $this->salesManagerRepository->getSalesPersonsByManagerId($salesManagerId);
                $salesPersonIds = array_map(fn($sp) => (int)$sp['id'], $salesPersons);
                
                // Create a map of sales person ID to name for quick lookup
                $salesPersonMap = [];
                foreach ($salesPersons as $sp) {
                    try {
                        $salesPersonMap[(int)$sp['id']] = $this->crypto->decrypt($sp['name']);
                    } catch (\Exception $e) {
                        $salesPersonMap[(int)$sp['id']] = 'N/A';
                    }
                }
                
                // Determine if client is direct or from sales person
                if (isset($decryptedClient['sales_manager_id']) && (int)$decryptedClient['sales_manager_id'] === $salesManagerId) {
                    $decryptedClient['assignment_type'] = 'direct';
                    $decryptedClient['assigned_to'] = 'Sales Manager';
                    $decryptedClient['assigned_to_name'] = 'Direct Assignment';
                } elseif (isset($decryptedClient['sales_person_id']) && in_array((int)$decryptedClient['sales_person_id'], $salesPersonIds)) {
                    $decryptedClient['assignment_type'] = 'sales_person';
                    $decryptedClient['assigned_to'] = 'Sales Person';
                    $decryptedClient['sales_person_name'] = $salesPersonMap[(int)$decryptedClient['sales_person_id']] ?? 'N/A';
                    $decryptedClient['assigned_to_name'] = $decryptedClient['sales_person_name'];
                } else {
                    // Fallback - shouldn't happen but just in case
                    $decryptedClient['assignment_type'] = 'unknown';
                    $decryptedClient['assigned_to'] = 'Unknown';
                    $decryptedClient['assigned_to_name'] = 'N/A';
                }
            }
        }
        
        return $decryptedClient;
    }

    private function checkClientAccess(int $clientId, ?string $userRole, ?array $clientRecord = null): void
    {
        if ($userRole === 'admin' || $userRole === 'employee') {
            // Admin and employee have access to all clients
            return;
        }

        // For sales managers, use the same method as getAll() to ensure 100% consistency
        // This is the most reliable way - if it appears in getAll(), it will pass this check
        if ($userRole === 'sales_manager') {
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            if (!$salesManagerId || !$this->salesManagerRepository) {
                throw new \RuntimeException('Access denied to this client');
            }
            
            // Get all accessible client IDs using the exact same method as getAll()
            $allowedClientIds = $this->getClientIdsForRole($userRole, null, null);
            
            // Ensure clientId is an integer for comparison
            $clientId = (int)$clientId;
            
            // Check if client ID is in the allowed list
            if (empty($allowedClientIds)) {
                throw new \RuntimeException('Access denied to this client');
            }
            
            // Convert all IDs to integers for proper comparison
            $allowedClientIds = array_map('intval', $allowedClientIds);
            
            if (!in_array($clientId, $allowedClientIds, true)) {
                throw new \RuntimeException('Access denied to this client');
            }
            
            return; // Access granted
        }

        // For other roles, use the standard method
        $allowedClientIds = $this->getClientIdsForRole($userRole, null, null);
        
        if ($allowedClientIds === null) {
            // null means all clients are allowed
            return;
        }
        
        // Ensure clientId is an integer for comparison
        $clientId = (int)$clientId;
        
        // Check if client ID is in the allowed list
        if (empty($allowedClientIds)) {
            throw new \RuntimeException('Access denied to this client');
        }
        
        // Convert all IDs to integers for proper comparison
        $allowedClientIds = array_map('intval', $allowedClientIds);
        
        if (!in_array($clientId, $allowedClientIds, true)) {
            throw new \RuntimeException('Access denied to this client');
        }
    }

    public function getAll(int $limit = 100, int $offset = 0, ?string $userRole = null, ?string $filter = null, ?int $salesPersonId = null): array
    {
        // Get client IDs based on role and filter
        $clientIds = $this->getClientIdsForRole($userRole, $filter, $salesPersonId);
        
        // Debug logging for sales managers
        if ($userRole === 'sales_manager') {
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            error_log("ClientService::getAll - Sales Manager ID: " . ($salesManagerId ?? 'NULL'));
            error_log("ClientService::getAll - Client IDs: " . (is_array($clientIds) ? (empty($clientIds) ? 'EMPTY ARRAY' : implode(', ', $clientIds)) : ($clientIds === null ? 'NULL (all clients)' : 'NOT ARRAY')));
        }
        
        $clients = $this->repository->getAll($limit, $offset, $clientIds);
        
        // Debug logging
        if ($userRole === 'sales_manager') {
            error_log("ClientService::getAll - Clients returned from repository: " . count($clients));
            if (count($clients) > 0) {
                error_log("ClientService::getAll - First client ID: " . ($clients[0]['id'] ?? 'N/A'));
            }
        }
        
        $decryptedClients = array_map(function($client) use ($userRole) {
            return $this->decryptClient($client, $userRole);
        }, $clients);
        
        // For sales managers, add assignment information
        if ($userRole === 'sales_manager') {
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            if ($salesManagerId && $this->salesManagerRepository) {
                // Get all sales persons under this manager with their names
                $salesPersons = $this->salesManagerRepository->getSalesPersonsByManagerId($salesManagerId);
                $salesPersonIds = array_map(fn($sp) => (int)$sp['id'], $salesPersons);
                
                // Create a map of sales person ID to name for quick lookup
                $salesPersonMap = [];
                foreach ($salesPersons as $sp) {
                    try {
                        $salesPersonMap[(int)$sp['id']] = $this->crypto->decrypt($sp['name']);
                    } catch (\Exception $e) {
                        $salesPersonMap[(int)$sp['id']] = 'N/A';
                    }
                }
                
                // Add assignment information to each client
                foreach ($decryptedClients as &$client) {
                    // Determine if client is direct or from sales person
                    if (isset($client['sales_manager_id']) && (int)$client['sales_manager_id'] === $salesManagerId) {
                        $client['assignment_type'] = 'direct';
                        $client['assigned_to'] = 'Sales Manager';
                        $client['assigned_to_name'] = 'Direct Assignment';
                    } elseif (isset($client['sales_person_id']) && in_array((int)$client['sales_person_id'], $salesPersonIds)) {
                        $client['assignment_type'] = 'sales_person';
                        $client['assigned_to'] = 'Sales Person';
                        $client['sales_person_id'] = (int)$client['sales_person_id'];
                        $client['sales_person_name'] = $salesPersonMap[(int)$client['sales_person_id']] ?? 'N/A';
                        $client['assigned_to_name'] = $client['sales_person_name'];
                    } else {
                        // Fallback - shouldn't happen but just in case
                        $client['assignment_type'] = 'unknown';
                        $client['assigned_to'] = 'Unknown';
                        $client['assigned_to_name'] = 'N/A';
                    }
                }
                unset($client);
            }
        }
        
        // For admins, add assignment information (sales manager and sales person names)
        if ($userRole === 'admin' && $this->salesManagerRepository && $this->salesPersonRepository) {
            // Get all sales managers and create a map
            $allSalesManagers = $this->salesManagerRepository->getAll();
            $salesManagerMap = [];
            foreach ($allSalesManagers as $sm) {
                try {
                    $salesManagerMap[(int)$sm['id']] = $this->crypto->decrypt($sm['name']);
                } catch (\Exception $e) {
                    $salesManagerMap[(int)$sm['id']] = 'N/A';
                }
            }
            
            // Get all sales persons and create a map
            $allSalesPersons = [];
            foreach ($allSalesManagers as $sm) {
                $salesPersons = $this->salesManagerRepository->getSalesPersonsByManagerId((int)$sm['id']);
                foreach ($salesPersons as $sp) {
                    try {
                        $allSalesPersons[(int)$sp['id']] = [
                            'name' => $this->crypto->decrypt($sp['name']),
                            'sales_manager_id' => (int)$sp['sales_manager_id'],
                            'sales_manager_name' => $salesManagerMap[(int)$sp['sales_manager_id']] ?? 'N/A'
                        ];
                    } catch (\Exception $e) {
                        $allSalesPersons[(int)$sp['id']] = [
                            'name' => 'N/A',
                            'sales_manager_id' => (int)$sp['sales_manager_id'],
                            'sales_manager_name' => $salesManagerMap[(int)$sp['sales_manager_id']] ?? 'N/A'
                        ];
                    }
                }
            }
            
            // Add assignment information to each client
            foreach ($decryptedClients as &$client) {
                if (isset($client['sales_manager_id']) && $client['sales_manager_id'] !== null) {
                    $client['assignment_type'] = 'sales_manager';
                    $client['assigned_to'] = 'Sales Manager';
                    $client['sales_manager_name'] = $salesManagerMap[(int)$client['sales_manager_id']] ?? 'N/A';
                    $client['assigned_to_name'] = $client['sales_manager_name'];
                } elseif (isset($client['sales_person_id']) && $client['sales_person_id'] !== null) {
                    $client['assignment_type'] = 'sales_person';
                    $client['assigned_to'] = 'Sales Person';
                    if (isset($allSalesPersons[(int)$client['sales_person_id']])) {
                        $client['sales_person_name'] = $allSalesPersons[(int)$client['sales_person_id']]['name'];
                        $client['sales_manager_name'] = $allSalesPersons[(int)$client['sales_person_id']]['sales_manager_name'];
                        $client['assigned_to_name'] = $client['sales_person_name'] . ' (under ' . $client['sales_manager_name'] . ')';
                    } else {
                        $client['sales_person_name'] = 'N/A';
                        $client['assigned_to_name'] = 'N/A';
                    }
                } else {
                    $client['assignment_type'] = 'unassigned';
                    $client['assigned_to'] = 'Unassigned';
                    $client['assigned_to_name'] = 'Not Assigned';
                }
            }
            unset($client);
        }
        
        return $decryptedClients;
    }

    private function getClientIdsForRole(?string $userRole, ?string $filter = null, ?int $salesPersonId = null): ?array
    {
        if ($userRole === 'admin' || $userRole === 'employee') {
            // Admin and employee see all clients
            return null;
        }
        
        if ($userRole === 'client') {
            // Client sees only their own client
            $clientId = $_SERVER['AUTH_USER_CLIENT_ID'] ?? null;
            return $clientId ? [$clientId] : [];
        }
        
        if ($userRole === 'sales_person') {
            // Sales person sees only their clients
            $salesPersonId = $_SERVER['AUTH_USER_SALES_PERSON_ID'] ?? null;
            if (!$salesPersonId || !$this->salesPersonRepository) {
                return [];
            }
            
            // Get client IDs assigned to this sales person
            return $this->salesPersonRepository->getClientIdsByPersonId($salesPersonId);
        }
        
        if ($userRole === 'sales_manager') {
            // Sales manager sees their clients + sales persons' clients under them
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            
            error_log("getClientIdsForRole - Sales Manager ID: " . ($salesManagerId ?? 'NULL'));
            error_log("getClientIdsForRole - Sales Manager Repository: " . ($this->salesManagerRepository ? 'SET' : 'NULL'));
            
            if (!$salesManagerId || !$this->salesManagerRepository) {
                error_log("getClientIdsForRole - Returning empty array (no manager ID or repository)");
                return [];
            }
            
            // Apply filter if specified
            if ($filter === 'direct') {
                // Only direct clients assigned to manager
                $clientIds = $this->salesManagerRepository->getClientIdsByManagerId($salesManagerId);
                error_log("getClientIdsForRole - Direct filter, client IDs: " . implode(', ', $clientIds));
                return $clientIds;
            } elseif ($filter === 'sales_person') {
                // Only clients from sales persons
                $salesPersons = $this->salesManagerRepository->getSalesPersonsByManagerId($salesManagerId);
                $salesPersonIds = array_map(fn($sp) => (int)$sp['id'], $salesPersons);
                
                error_log("getClientIdsForRole - Sales person filter, sales person IDs: " . implode(', ', $salesPersonIds));
                
                if (empty($salesPersonIds)) {
                    error_log("getClientIdsForRole - No sales persons found, returning empty array");
                    return [];
                }
                
                // If specific sales person ID is provided, filter by that
                if ($salesPersonId !== null && in_array($salesPersonId, $salesPersonIds)) {
                    $clientIds = $this->salesPersonRepository->getClientIdsByPersonId($salesPersonId);
                    error_log("getClientIdsForRole - Specific sales person filter, client IDs: " . implode(', ', $clientIds));
                    return $clientIds;
                }
                
                // Get client IDs assigned to all sales persons
                $clientIds = $this->salesPersonRepository->getClientIdsBySalesPersonIds($salesPersonIds);
                error_log("getClientIdsForRole - All sales persons filter, client IDs: " . implode(', ', $clientIds));
                return $clientIds;
            } else {
                // Get client IDs for manager and all sales persons under them (all)
                $clientIds = $this->salesManagerRepository->getClientIdsByManagerAndPersons($salesManagerId);
                error_log("getClientIdsForRole - All filter (manager + sales persons), client IDs: " . implode(', ', $clientIds));
                return $clientIds;
            }
        }
        
        // Default: no access
        return [];
    }

    public function update(int $id, array $data): array
    {
        $client = $this->repository->getById($id);
        if (!$client) {
            throw new \RuntimeException('Client not found');
        }

        $updateData = [];

        // Encrypt fields that need encryption
        $encryptedFields = ['package', 'client_name', 'person_name', 'address', 'phone', 'email'];
        foreach ($encryptedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $this->crypto->encrypt($data[$field]);
            }
        }

        // Handle domains (plaintext JSON)
        if (isset($data['domains'])) {
            $domains = is_array($data['domains']) ? $data['domains'] : json_decode($data['domains'], true);
            if (!is_array($domains)) {
                throw new \InvalidArgumentException('Domains must be a JSON array');
            }
            $updateData['domains'] = json_encode($domains);
        }

        // Plaintext fields
        if (isset($data['city'])) {
            $updateData['city'] = $data['city'];
        }
        if (isset($data['state'])) {
            $updateData['state'] = $data['state'];
        }
        if (isset($data['pincode'])) {
            $updateData['pincode'] = $data['pincode'];
        }

        $this->repository->update($id, $updateData);

        // Get user role from server context if available
        $userRole = $_SERVER['AUTH_USER_ROLE'] ?? null;
        return $this->get($id, $userRole);
    }

    public function delete(int $id): void
    {
        $client = $this->repository->getById($id);
        if (!$client) {
            throw new \RuntimeException('Client not found');
        }

        $this->repository->delete($id);
    }

    public function getDomains(int $clientId): array
    {
        return $this->repository->getDomains($clientId);
    }

    public function getDomainsByClientIds(array $clientIds): array
    {
        return $this->repository->getDomainsByClientIds($clientIds);
    }

    public function getTotalCount(?string $userRole = null, ?string $filter = null, ?int $salesPersonId = null): int
    {
        $clientIds = $this->getClientIdsForRole($userRole, $filter, $salesPersonId);
        
        if ($clientIds === null) {
            // Count all clients
            return $this->repository->count();
        }
        
        if (empty($clientIds)) {
            return 0;
        }
        
        return $this->repository->countByIds($clientIds);
    }

    private function decryptClient(array $client, ?string $userRole = null): array
    {
        // Decrypt encrypted fields
        $decrypted = [
            'id' => (int)$client['id'],
            'client_name' => $this->crypto->decrypt($client['client_name']),
            'person_name' => $this->crypto->decrypt($client['person_name']),
            'address' => $this->crypto->decrypt($client['address']),
            'phone' => $this->crypto->decrypt($client['phone']),
            'email' => $this->crypto->decrypt($client['email']),
            'domains' => json_decode($client['domains'], true) ?: [],
            'city' => $client['city'] ?? null,
            'state' => $client['state'] ?? null,
            'pincode' => $client['pincode'] ?? null,
            'created_at' => $client['created_at'] ?? null,
            'updated_at' => $client['updated_at'] ?? null,
            'bidx_version' => $client['bidx_version'] ?? 'v1',
            'sales_manager_id' => isset($client['sales_manager_id']) && $client['sales_manager_id'] !== null ? (int)$client['sales_manager_id'] : null,
            'sales_person_id' => isset($client['sales_person_id']) && $client['sales_person_id'] !== null ? (int)$client['sales_person_id'] : null,
        ];

        // Hide package for employees
        if ($userRole !== 'employee') {
            $decrypted['package'] = $this->crypto->decrypt($client['package']);
        }

        return $decrypted;
    }
}

