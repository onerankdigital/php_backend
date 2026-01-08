<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientRepository;
use App\Repositories\UserClientRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;
use App\Services\EmailService;
use App\Utils\Crypto;

class ClientService
{
    private ClientRepository $repository;
    private UserClientRepository $userClientRepository;
    private ServiceRepository $serviceRepository;
    private UserRepository $userRepository;
    private Crypto $crypto;
    private EmailService $emailService;

    public function __construct(
        ClientRepository $repository,
        UserClientRepository $userClientRepository,
        Crypto $crypto,
        EmailService $emailService,
        ServiceRepository $serviceRepository,
        UserRepository $userRepository
    ) {
        $this->repository = $repository;
        $this->userClientRepository = $userClientRepository;
        $this->crypto = $crypto;
        $this->emailService = $emailService;
        $this->serviceRepository = $serviceRepository;
        $this->userRepository = $userRepository;
    }

    /**
     * Create a new client
     * @return array The created client with decrypted data
     */
    public function create(array $data, ?int $createdByUserId = null): array
    {
        // Validate required fields (package is now optional, defaults to package description)
        $required = ['client_name', 'person_name', 'address', 'phone', 'email', 'domains'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }
        
        // Set default package description if not provided
        if (empty($data['package'])) {
            $data['package'] = 'Digital Marketing Package';
        }

        // Validate domains (must be JSON array)
        $domains = is_array($data['domains']) ? $data['domains'] : json_decode($data['domains'], true);
        if (!is_array($domains) || empty($domains)) {
            throw new \InvalidArgumentException('Domains must be a non-empty JSON array');
        }

        // Encrypt sensitive fields
        $encryptedData = [
            'package' => $this->crypto->encrypt($data['package']),
            'client_name' => $this->crypto->encrypt($data['client_name']),
            'person_name' => $this->crypto->encrypt($data['person_name']),
            'address' => $this->crypto->encrypt($data['address']),
            'phone' => $this->crypto->encrypt($data['phone']),
            'email' => $this->crypto->encrypt($data['email']),
            'domains' => json_encode($domains),
            'city' => $data['city'] ?? null, // Not encrypted - for filtering/searching
            'state' => $data['state'] ?? null, // Not encrypted - for filtering/searching
            'pincode' => $data['pincode'] ?? null, // Not encrypted - for filtering/searching
            'bidx_version' => $data['bidx_version'] ?? 'v1',
            'total_amount' => $data['total_amount'] ?? 0.00,
            
            // New order form fields - encrypted sensitive data
            'package' => $this->crypto->encrypt($data['package']), // Now set above with default
            'designation' => isset($data['designation']) ? $this->crypto->encrypt($data['designation']) : null,
            'gstin_no' => isset($data['gstin_no']) ? $this->crypto->encrypt($data['gstin_no']) : null,
            'specific_guidelines' => isset($data['specific_guidelines']) ? $this->crypto->encrypt($data['specific_guidelines']) : null,
            'package_amount' => $data['package_amount'] ?? 0.00,
            'gst_amount' => $data['gst_amount'] ?? 0.00,
            'total_amount' => $data['total_amount'] ?? 0.00, // package_amount + gst_amount
            'payment_mode' => $data['payment_mode'] ?? null, // Not encrypted - generic payment type
            'signature_name' => isset($data['signature_name']) ? $this->crypto->encrypt($data['signature_name']) : null,
            'signature_designation' => isset($data['signature_designation']) ? $this->crypto->encrypt($data['signature_designation']) : null,
            'signature_text' => isset($data['signature_text']) ? $this->crypto->encrypt($data['signature_text']) : null,
            'esignature_data' => isset($data['esignature_data']) ? $this->crypto->encrypt($data['esignature_data']) : null,
            'order_date' => $data['order_date'] ?? date('Y-m-d'),
            
            // SEO details - encrypted (business strategy)
            'seo_keyword_range' => $data['seo_keyword_range'] ?? null, // Not encrypted - generic range
            'seo_location' => $data['seo_location'] ?? null, // Not encrypted - generic location
            'seo_keywords_list' => isset($data['seo_keywords_list']) ? $this->crypto->encrypt($data['seo_keywords_list']) : null,
            
            // Adwords details - encrypted (business strategy)
            'adwords_keywords' => $data['adwords_keywords'] ?? null, // Not encrypted - just a count
            'adwords_period' => $data['adwords_period'] ?? null, // Not encrypted - generic period
            'adwords_location' => isset($data['adwords_location']) ? $this->crypto->encrypt($data['adwords_location']) : null,
            'adwords_keywords_list' => isset($data['adwords_keywords_list']) ? $this->crypto->encrypt($data['adwords_keywords_list']) : null,
            
            'special_guidelines' => isset($data['special_guidelines']) ? $this->crypto->encrypt($data['special_guidelines']) : null,
        ];

        $clientId = $this->repository->create($encryptedData); // Returns VARCHAR client_id
        
        // Automatically assign the creating user to this client
        if ($createdByUserId) {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? null;
            
            // Don't auto-assign if user is admin or employee (they manage all clients)
            if ($userRole && $userRole !== 'admin' && $userRole !== 'employee') {
                $this->userClientRepository->assignUserToClient($createdByUserId, $clientId);
            }
        }
        
        // Send email notifications (non-blocking, errors are logged but don't fail the request)
        // Extract owner_emails from form data if provided
        $ownerEmail = $data['owner_emails'] ?? null;
        if (is_array($ownerEmail)) {
            $ownerEmail = !empty($ownerEmail) ? $ownerEmail[0] : null;
        }
        if (is_string($ownerEmail)) {
            $ownerEmail = trim($ownerEmail);
            if (empty($ownerEmail) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $ownerEmail = null;
            }
        } else {
            $ownerEmail = null;
        }
        
        try {
            // Fetch services and sub-services for this client
            $services = $this->serviceRepository->getClientServices($clientId);
            $subServices = $this->serviceRepository->getClientSubServices($clientId);
            
            // Get user email from users table if user ID is provided
            $userEmail = null;
            if ($createdByUserId) {
                try {
                    $user = $this->userRepository->findById($createdByUserId);
                    if ($user && !empty($user['email'])) {
                        // Decrypt user email
                        $userEmail = $this->crypto->decrypt($user['email']);
                    }
                } catch (\Exception $e) {
                    error_log("Error fetching user email: " . $e->getMessage());
                    // Fall back to client email if user email cannot be retrieved
                    $userEmail = $data['email'] ?? null;
                }
            } else {
                // If no user ID, use client email as fallback
                $userEmail = $data['email'] ?? null;
            }
            
            $this->emailService->sendClientFormNotifications($data, $clientId, $ownerEmail, $services, $subServices, $userEmail);
        } catch (\Exception $e) {
            // Log error but don't fail client creation
            error_log("Error sending client form email notifications: " . $e->getMessage());
        }
        
        return $this->get($clientId);
    }

    public function getAll(int $limit = 100, int $offset = 0, ?string $filter = null, ?int $userId = null): array
    {
        $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        
        // Determine which clients this user can access
        $clientIds = $this->getClientIdsForUser($userId, $userRole);

        // Get clients
        $clients = $this->repository->getAll($limit, $offset, $clientIds);
        
        // Decrypt each client
        $decryptedClients = [];
        foreach ($clients as $client) {
            $decryptedClient = $this->decryptClient($client);
            
            // Add assigned users info
            $assignedUsers = $this->userClientRepository->getUsersByClientId($client['client_id']);
            $decryptedClient['assigned_users'] = $assignedUsers;
            $decryptedClient['assigned_users_count'] = count($assignedUsers);
            
            $decryptedClients[] = $decryptedClient;
        }
        
        return $decryptedClients;
    }

    /**
     * Get client by client_id (VARCHAR)
     */
    public function get(string $clientId, ?string $userRole = null, ?int $userId = null): array
    {
        if ($userRole === null) {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        }
        
        $client = $this->repository->getById($clientId);
        if (!$client) {
            throw new \RuntimeException('Client not found');
        }

        // Check access
        $this->checkClientAccess($clientId, $userRole, $userId);

        $decryptedClient = $this->decryptClient($client);
        
        // Add assigned users info
        $assignedUsers = $this->userClientRepository->getUsersByClientId($clientId);
        $decryptedClient['assigned_users'] = $assignedUsers;
        $decryptedClient['assigned_users_count'] = count($assignedUsers);
        
        return $decryptedClient;
    }

    /**
     * Update client by client_id (VARCHAR)
     */
    public function update(string $clientId, array $data): array
    {
        $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        
        // Check access
        $this->checkClientAccess($clientId, $userRole, $userId);

        $encryptedData = [];
        
        // Fields that need encryption
        $encryptableFields = [
            'package', 'client_name', 'person_name', 'address', 'phone', 'email', 
            'designation', 'gstin_no', 
            'specific_guidelines', 'signature_name', 'signature_designation', 'signature_text',
            'esignature_data', 'seo_keywords_list', 'adwords_location', 'adwords_keywords_list',
            'special_guidelines'
        ];
        
        foreach ($encryptableFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                $encryptedData[$field] = $this->crypto->encrypt($data[$field]);
            }
        }

        // Fields that don't need encryption
        $nonEncryptedFields = [
            'total_amount', 'package_amount', 'gst_amount',
            'payment_mode', 'order_date', 'seo_keyword_range',
            'seo_location', 'adwords_keywords', 'adwords_period',
            'city', 'state', 'pincode' // Location fields - not encrypted for filtering
        ];
        
        foreach ($nonEncryptedFields as $field) {
            if (isset($data[$field])) {
                $encryptedData[$field] = $data[$field];
            }
        }

        if (isset($data['domains'])) {
            $domains = is_array($data['domains']) ? $data['domains'] : json_decode($data['domains'], true);
            if (is_array($domains)) {
                $encryptedData['domains'] = json_encode($domains);
            }
        }

        $this->repository->update($clientId, $encryptedData);
        return $this->get($clientId);
    }

    /**
     * Delete client by client_id (VARCHAR)
     */
    public function delete(string $clientId): bool
    {
        $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        
        // Only admin can delete
        if ($userRole !== 'admin') {
            throw new \RuntimeException('Only administrators can delete clients');
        }

        return $this->repository->delete($clientId);
    }

    /**
     * Get client IDs that a user has access to
     */
    private function getClientIdsForUser(?int $userId, string $userRole): ?array
    {
        // Admin and employee see all clients
        if ($userRole === 'admin' || $userRole === 'employee') {
            return null; // null means all clients
        }

        // Other roles see only their assigned client(s)
        if ($userId) {
            $clientIds = $this->userClientRepository->getClientIdsByUserId($userId);
            return empty($clientIds) ? [] : $clientIds;
        }

        return []; // No access
    }

    /**
     * Check if user has access to a client
     * @param string $clientId VARCHAR client_id
     */
    private function checkClientAccess(string $clientId, string $userRole, ?int $userId): void
    {
        // Admin and employee have access to all clients
        if ($userRole === 'admin' || $userRole === 'employee') {
            return;
        }

        // Get accessible client IDs for this user
        $accessibleClientIds = $this->getClientIdsForUser($userId, $userRole);
        
        // If accessibleClientIds is null, user has access to all (shouldn't happen for non-admin)
        if ($accessibleClientIds === null) {
            return;
        }

        // Check if client ID is in accessible list
        if (!in_array($clientId, $accessibleClientIds, true)) {
            throw new \RuntimeException('Access denied to this client');
        }
    }

    private function decryptClient(array $client): array
    {
        $decryptable = [
            'package', 'client_name', 'person_name', 'address', 'phone', 'email', 
            'designation', 'gstin_no',
            'specific_guidelines', 'signature_name', 'signature_designation', 'signature_text',
            'esignature_data', 'seo_keywords_list', 'adwords_location', 'adwords_keywords_list',
            'special_guidelines'
        ];
        // Note: city, state, pincode are NOT encrypted
        
        foreach ($decryptable as $field) {
            if (isset($client[$field]) && !empty($client[$field])) {
                try {
                    $client[$field] = $this->crypto->decrypt($client[$field]);
                } catch (\Exception $e) {
                    $client[$field] = '[Decryption Error]';
                }
            }
        }

        // Parse domains JSON
        if (isset($client['domains'])) {
            $domains = json_decode($client['domains'], true);
            $client['domains'] = is_array($domains) ? $domains : [];
        }

        return $client;
    }
}
