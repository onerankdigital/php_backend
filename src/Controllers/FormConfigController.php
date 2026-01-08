<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FormConfigService;

class FormConfigController
{
    private FormConfigService $service;

    public function __construct(FormConfigService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/form-configs - Get all configurations
     */
    public function getAll(): void
    {
        try {
            $activeOnly = isset($_GET['active_only']) ? (bool)$_GET['active_only'] : true;
            $configs = $this->service->getAll($activeOnly);
            
            $this->sendResponse([
                'success' => true,
                'data' => $configs,
                'count' => count($configs)
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/form-configs/key/{config_key} - Get config by key (public endpoint for iframe)
     */
    public function getByKey(string $configKey): void
    {
        try {
            $config = $this->service->getByKey($configKey);
            
            if (!$config) {
                $this->sendResponse(['error' => 'Configuration not found'], 404);
                return;
            }
            
            $this->sendResponse([
                'success' => true,
                'data' => $config
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/form-configs/{id} - Get config by ID
     */
    public function getById(int $id): void
    {
        try {
            $config = $this->service->getById($id);
            
            if (!$config) {
                $this->sendResponse(['error' => 'Configuration not found'], 404);
                return;
            }
            
            $this->sendResponse([
                'success' => true,
                'data' => $config
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/form-configs - Create new configuration
     */
    public function create(): void
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                $this->sendResponse(['error' => 'Invalid JSON'], 400);
                return;
            }
            
            // Add created_by from authenticated user
            $data['created_by'] = $_SERVER['AUTH_USER_ID'] ?? null;
            
            $config = $this->service->create($data);
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Configuration created successfully',
                'data' => $config
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/form-configs/{id} - Update configuration
     */
    public function update(int $id): void
    {
        try {
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
            
            $config = $this->service->update($id, $data);
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Configuration updated successfully',
                'data' => $config
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/form-configs/{id} - Delete configuration
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->service->delete($id);
            
            if (!$success) {
                $this->sendResponse(['error' => 'Failed to delete configuration'], 500);
                return;
            }
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Configuration deleted successfully'
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/form-configs/{id}/duplicate - Duplicate configuration
     */
    public function duplicate(int $id): void
    {
        try {
            $config = $this->service->duplicate($id);
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Configuration duplicated successfully',
                'data' => $config
            ], 201);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send JSON response
     */
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

