<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TransactionRepository;
use App\Repositories\ClientRepository;
use App\Services\PermissionService;

class TransactionService
{
    private TransactionRepository $transactionRepository;
    private ClientRepository $clientRepository;
    private PermissionService $permissionService;

    public function __construct(
        TransactionRepository $transactionRepository,
        ClientRepository $clientRepository,
        PermissionService $permissionService
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->clientRepository = $clientRepository;
        $this->permissionService = $permissionService;
    }

    /**
     * Create a new transaction
     */
    public function create(array $data, int $userId): array
    {
        // Validate required fields
        $this->validateTransactionData($data);

        // Check if user has access to this client
        $clientId = (int)$data['client_id'];
        if (!$this->userCanAccessClient($userId, $clientId)) {
            throw new \RuntimeException('You do not have access to this client');
        }

        // Add recorded_by_user_id
        $data['recorded_by_user_id'] = $userId;

        // Create transaction
        $transactionId = $this->transactionRepository->create($data);

        // Return created transaction
        return $this->transactionRepository->findById($transactionId);
    }

    /**
     * Get transaction by ID
     */
    public function get(int $id, int $userId): array
    {
        $transaction = $this->transactionRepository->findById($id);
        
        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        // Check if user has access to this client
        if (!$this->userCanAccessClient($userId, $transaction['client_id'])) {
            throw new \RuntimeException('You do not have access to this transaction');
        }

        return $transaction;
    }

    /**
     * Get all transactions with filtering
     */
    public function getAll(int $userId, int $limit = 50, int $offset = 0, ?array $filters = null): array
    {
        // Get accessible client IDs for the user
        $accessibleClientIds = $this->getAccessibleClientIds($userId);

        // If user has limited access, filter by accessible clients
        if ($accessibleClientIds !== null) {
            $filters['client_ids'] = $accessibleClientIds;
        }

        return $this->transactionRepository->getAll($limit, $offset, $filters);
    }

    /**
     * Get total count
     */
    public function getTotalCount(int $userId, ?array $filters = null): int
    {
        // Get accessible client IDs for the user
        $accessibleClientIds = $this->getAccessibleClientIds($userId);

        // If user has limited access, filter by accessible clients
        if ($accessibleClientIds !== null) {
            $filters['client_ids'] = $accessibleClientIds;
        }

        return $this->transactionRepository->getTotalCount($filters);
    }

    /**
     * Get transactions for a specific client
     */
    public function getByClient(string $clientId, int $userId, int $limit = 50, int $offset = 0): array
    {
        // Check if user has access to this client
        if (!$this->userCanAccessClient($userId, $clientId)) {
            throw new \RuntimeException('You do not have access to this client');
        }

        return $this->transactionRepository->getByClientId($clientId, $limit, $offset);
    }

    /**
     * Get payment summary for a client
     */
    public function getClientPaymentSummary(string $clientId, int $userId): array
    {
        // Check if user has access to this client
        if (!$this->userCanAccessClient($userId, $clientId)) {
            throw new \RuntimeException('You do not have access to this client');
        }

        return $this->transactionRepository->getClientPaymentSummary($clientId);
    }

    /**
     * Update transaction
     */
    public function update(int $id, array $data, int $userId): bool
    {
        $transaction = $this->transactionRepository->findById($id);
        
        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        // Check if user has access to this client
        if (!$this->userCanAccessClient($userId, $transaction['client_id'])) {
            throw new \RuntimeException('You do not have access to this transaction');
        }

        return $this->transactionRepository->update($id, $data);
    }

    /**
     * Delete transaction (soft delete)
     */
    public function delete(int $id, int $userId): bool
    {
        $transaction = $this->transactionRepository->findById($id);
        
        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        // Check if user has access to this client
        if (!$this->userCanAccessClient($userId, $transaction['client_id'])) {
            throw new \RuntimeException('You do not have access to this transaction');
        }

        return $this->transactionRepository->softDelete($id, $userId);
    }

    /**
     * Get transaction statistics
     */
    public function getStatistics(int $userId): array
    {
        // Get accessible client IDs for the user
        $accessibleClientIds = $this->getAccessibleClientIds($userId);

        $filters = [];
        if ($accessibleClientIds !== null) {
            $filters['client_ids'] = $accessibleClientIds;
        }

        return $this->transactionRepository->getStatistics($filters);
    }

    /**
     * Validate transaction data
     */
    private function validateTransactionData(array $data): void
    {
        if (empty($data['client_id'])) {
            throw new \InvalidArgumentException('Client ID is required');
        }

        if (empty($data['amount']) || $data['amount'] <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        if (isset($data['payment_type']) && !in_array($data['payment_type'], ['cash', 'card', 'bank_transfer', 'cheque', 'upi', 'other'])) {
            throw new \InvalidArgumentException('Invalid payment type');
        }
    }

    /**
     * Check if user can access a client
     */
    private function userCanAccessClient(int $userId, string $clientId): bool
    {
        $role = $this->permissionService->getUserRole($userId);
        
        // Admin and employee can access all clients
        if ($role === 'admin' || $role === 'employee') {
            return true;
        }

        // Get accessible client IDs
        $accessibleClientIds = $this->getAccessibleClientIds($userId);
        
        return $accessibleClientIds === null || in_array($clientId, $accessibleClientIds, true);
    }

    /**
     * Get accessible client IDs for a user
     */
    private function getAccessibleClientIds(int $userId): ?array
    {
        $role = $this->permissionService->getUserRole($userId);
        
        // Admin and employee see all
        if ($role === 'admin' || $role === 'employee') {
            return null; // null means all clients
        }

        // Get client IDs through PermissionService (includes hierarchy)
        return $this->permissionService->getAccessibleClientIds(
            $userId,
            new \App\Repositories\UserClientRepository($this->clientRepository->getDb())
        );
    }

    /**
     * Get payment methods
     */
    public function getPaymentMethods(): array
    {
        return $this->transactionRepository->getAllPaymentMethods();
    }
}

