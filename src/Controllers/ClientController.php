<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ClientService;

class ClientController
{
    private ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function create(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            $client = $this->clientService->create($data);
            $this->sendResponse([
                'success' => true,
                'message' => 'Client created successfully',
                'data' => $client
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function get(int $id): void
    {
        try {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? null;
            $client = $this->clientService->get($id, $userRole);
            $this->sendResponse([
                'success' => true,
                'data' => $client
            ]);
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
            $filter = $_GET['filter'] ?? null; // 'direct', 'sales_person', or null for all
            $salesPersonId = isset($_GET['sales_person_id']) ? (int)$_GET['sales_person_id'] : null; // Filter by specific sales person

            $limit = max(1, min(1000, $limit));
            $offset = max(0, $offset);

            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? null;
            
            // Debug logging for sales managers
            if ($userRole === 'sales_manager') {
                $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
                error_log("ClientController::getAll - User Role: sales_manager");
                error_log("ClientController::getAll - Sales Manager ID: " . ($salesManagerId ?? 'NULL'));
            }
            
            $clients = $this->clientService->getAll($limit, $offset, $userRole, $filter, $salesPersonId);
            
            // Debug logging
            if ($userRole === 'sales_manager') {
                error_log("ClientController::getAll - Clients returned: " . count($clients));
            }
            
            // Get total count for pagination
            $totalCount = $this->clientService->getTotalCount($userRole, $filter, $salesPersonId);
            
            $this->sendResponse([
                'success' => true,
                'data' => $clients,
                'count' => count($clients),
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'filter' => $filter,
                'sales_person_id' => $salesPersonId
            ]);
        } catch (\Exception $e) {
            error_log("ClientController::getAll - Exception: " . $e->getMessage());
            error_log("ClientController::getAll - Stack trace: " . $e->getTraceAsString());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function update(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            $client = $this->clientService->update($id, $data);
            $this->sendResponse([
                'success' => true,
                'message' => 'Client updated successfully',
                'data' => $client
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 404);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function delete(int $id): void
    {
        try {
            $this->clientService->delete($id);
            $this->sendResponse([
                'success' => true,
                'message' => 'Client deleted successfully'
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 404);
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

