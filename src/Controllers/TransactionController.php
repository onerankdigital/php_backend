<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TransactionService;
use App\Services\PermissionService;

class TransactionController
{
    private TransactionService $transactionService;
    private PermissionService $permissionService;

    public function __construct(
        TransactionService $transactionService,
        PermissionService $permissionService
    ) {
        $this->transactionService = $transactionService;
        $this->permissionService = $permissionService;
    }

    /**
     * Create a new transaction
     * POST /api/transactions
     */
    public function create(): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'create')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        // Validate Content-Type header - only JSON is allowed
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            $this->sendResponse(['error' => 'Content-Type must be application/json'], 400);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            $transaction = $this->transactionService->create($data, (int)$userId);
            $this->sendResponse([
                'success' => true,
                'message' => 'Transaction created successfully',
                'data' => $transaction
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all transactions
     * GET /api/transactions
     */
    public function getAll(): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'read')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);
            
            // Build filters
            $filters = [];
            if (isset($_GET['client_id'])) {
                $filters['client_id'] = (int)$_GET['client_id'];
            }
            if (isset($_GET['payment_type'])) {
                $filters['payment_type'] = $_GET['payment_type'];
            }
            if (isset($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (isset($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }

            $transactions = $this->transactionService->getAll((int)$userId, $limit, $offset, $filters);
            $totalCount = $this->transactionService->getTotalCount((int)$userId, $filters);

            // Check permissions for UI
            $canCreate = $this->permissionService->canAccess((int)$userId, 'transactions', 'create');
            $canUpdate = $this->permissionService->canAccess((int)$userId, 'transactions', 'update');
            $canDelete = $this->permissionService->canAccess((int)$userId, 'transactions', 'delete');

            $this->sendResponse([
                'success' => true,
                'data' => $transactions,
                'total' => $totalCount,
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

    /**
     * Get a single transaction
     * GET /api/transactions/{id}
     */
    public function get(int $id): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'read')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $transaction = $this->transactionService->get($id, (int)$userId);
            $this->sendResponse([
                'success' => true,
                'data' => $transaction
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get transactions for a client
     * GET /api/clients/{clientId}/transactions
     */
    public function getByClient(string $clientId): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'read')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);

            $transactions = $this->transactionService->getByClient($clientId, (int)$userId, $limit, $offset);
            $summary = $this->transactionService->getClientPaymentSummary($clientId, (int)$userId);

            $this->sendResponse([
                'success' => true,
                'data' => $transactions,
                'summary' => $summary
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get payment summary for a client
     * GET /api/clients/{clientId}/payment-summary
     */
    public function getClientSummary(string $clientId): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'read')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $summary = $this->transactionService->getClientPaymentSummary($clientId, (int)$userId);
            $this->sendResponse([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a transaction
     * PUT /api/transactions/{id}
     */
    public function update(int $id): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'update')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        // Validate Content-Type header - only JSON is allowed
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            $this->sendResponse(['error' => 'Content-Type must be application/json'], 400);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            $this->transactionService->update($id, $data, (int)$userId);
            $this->sendResponse([
                'success' => true,
                'message' => 'Transaction updated successfully'
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a transaction
     * DELETE /api/transactions/{id}
     */
    public function delete(int $id): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check permission
        if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'delete')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $this->transactionService->delete($id, (int)$userId);
            $this->sendResponse([
                'success' => true,
                'message' => 'Transaction deleted successfully'
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get transaction statistics
     * GET /api/transactions/statistics
     */
    public function getStatistics(): void
    {
        try {
            error_log('TransactionController::getStatistics - Starting');
            $userId = $_SERVER['AUTH_USER_ID'] ?? null;
            if (!$userId) {
                error_log('TransactionController::getStatistics - No user ID');
                $this->sendResponse(['error' => 'Unauthorized'], 401);
                return;
            }

            error_log('TransactionController::getStatistics - User ID: ' . $userId);
            // Check permission
            if (!$this->permissionService->canAccess((int)$userId, 'transactions', 'read')) {
                error_log('TransactionController::getStatistics - Permission denied');
                $this->sendResponse(['error' => 'Permission denied'], 403);
                return;
            }

            error_log('TransactionController::getStatistics - Calling service->getStatistics');
            $statistics = $this->transactionService->getStatistics((int)$userId);
            error_log('TransactionController::getStatistics - Service returned statistics');
            
            $this->sendResponse([
                'success' => true,
                'data' => $statistics
            ]);
        } catch (\Throwable $e) {
            error_log('TransactionController::getStatistics - Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('TransactionController::getStatistics - Stack trace: ' . $e->getTraceAsString());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get payment methods
     * GET /api/transactions/payment-methods
     */
    public function getPaymentMethods(): void
    {
        try {
            $paymentMethods = $this->transactionService->getPaymentMethods();
            $this->sendResponse([
                'success' => true,
                'data' => $paymentMethods
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        // Clean any output buffers to prevent JSON corruption
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            // JSON encoding failed, send a simple error
            $json = '{"error":"Internal Server Error - Invalid response data","success":false}';
        }
        
        echo $json;
        exit;
    }
}

