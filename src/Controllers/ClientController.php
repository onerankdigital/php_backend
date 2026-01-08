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
            error_log('ClientController::getAll - Starting');
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $filter = isset($_GET['filter']) ? $_GET['filter'] : null;
            $userId = $_SERVER['AUTH_USER_ID'] ?? null;
            
            error_log('ClientController::getAll - User ID: ' . ($userId ?? 'NULL'));
            error_log('ClientController::getAll - Calling service->getAll');
            $clients = $this->service->getAll($limit, $offset, $filter, $userId);
            error_log('ClientController::getAll - Service returned ' . count($clients) . ' clients');
            
            $this->sendResponse([
                'success' => true,
                'data' => $clients
            ]);
        } catch (\Throwable $e) {
            error_log('ClientController::getAll - Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('ClientController::getAll - Stack trace: ' . $e->getTraceAsString());
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
