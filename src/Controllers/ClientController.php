<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ClientService;

class ClientController
{
    private ClientService $service;

    public function __construct(ClientService $service)
    {
        $this->service = $service;
    }

    public function create(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            // Get the user who is creating this client
            $createdByUserId = $_SERVER['AUTH_USER_ID'] ?? null;
            
            // Create client and auto-assign creator
            $client = $this->service->create($data, $createdByUserId);
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Client created successfully and assigned to you',
                'data' => $client
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getAll(): void
    {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $filter = isset($_GET['filter']) ? $_GET['filter'] : null;
            $userId = $_SERVER['AUTH_USER_ID'] ?? null;
            
            $clients = $this->service->getAll($limit, $offset, $filter, $userId);
            
            $this->sendResponse([
                'success' => true,
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get client by client_id (VARCHAR)
     */
    public function get(string $clientId): void
    {
        try {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? 'client';
            $userId = $_SERVER['AUTH_USER_ID'] ?? null;
            
            $client = $this->service->get($clientId, $userRole, $userId);
            $this->sendResponse([
                'success' => true,
                'data' => $client
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update client by client_id (VARCHAR)
     */
    public function update(string $clientId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            $client = $this->service->update($clientId, $data);
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

    /**
     * Delete client by client_id (VARCHAR)
     */
    public function delete(string $clientId): void
    {
        try {
            $this->service->delete($clientId);
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
