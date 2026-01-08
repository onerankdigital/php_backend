<?php
namespace App\Controllers;

use App\Repositories\ServiceRepository;

class ServiceController {
    private $serviceRepo;

    public function __construct($db) {
        $this->serviceRepo = new ServiceRepository($db);
    }

    /**
     * Send JSON response with proper headers
     */
    private function sendResponse(array $data, int $statusCode = 200): void {
        // Clean output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            $json = json_encode([
                'success' => false,
                'error' => 'Internal Server Error - Invalid response data'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        echo $json;
        exit;
    }

    /**
     * Get all services (optionally with sub-services)
     */
    public function getAllServices() {
        try {
            $withSubServices = $_GET['with_sub_services'] ?? false;
            $activeOnly = $_GET['active_only'] ?? false;
            
            if ($withSubServices) {
                $services = $this->serviceRepo->getServicesWithSubServices($activeOnly);
            } else {
                $services = $this->serviceRepo->getAllServices($activeOnly);
            }
            
            $this->sendResponse([
                'success' => true,
                'data' => $services
            ]);
        } catch (\Exception $e) {
            error_log('ServiceController::getAllServices - Error: ' . $e->getMessage());
            $this->sendResponse([
                'success' => false,
                'error' => 'Failed to fetch services'
            ], 500);
        }
    }

    /**
     * Get service by ID
     */
    public function getService($id) {
        try {
            $service = $this->serviceRepo->getServiceById($id);
            
            if (!$service) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Service not found'
                ], 404);
                return;
            }
            
            // Get sub-services
            $service['sub_services'] = $this->serviceRepo->getSubServicesByServiceId($id);
            
            $this->sendResponse([
                'success' => true,
                'data' => $service
            ]);
        } catch (\Exception $e) {
            error_log('ServiceController::getService - Error: ' . $e->getMessage());
            $this->sendResponse([
                'success' => false,
                'error' => 'Failed to fetch service'
            ], 500);
        }
    }

    /**
     * Create new service
     */
    public function createService() {
        try {
            // Validate Content-Type header - only JSON is allowed
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Content-Type must be application/json'
                ]);
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['service_name']) || !isset($data['service_code'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Service name and code are required'
                ]);
                return;
            }
            
            $serviceId = $this->serviceRepo->createService($data);
            
            if ($serviceId) {
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => 'Service created successfully',
                    'data' => ['id' => $serviceId]
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create service'
                ]);
            }
        } catch (\Exception $e) {
            error_log("ServiceController::createService - " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create service: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update service
     */
    public function updateService($id) {
        try {
            // Validate Content-Type header - only JSON is allowed
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Content-Type must be application/json'
                ]);
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['service_name']) || !isset($data['service_code'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Service name and code are required'
                ]);
                return;
            }
            
            $success = $this->serviceRepo->updateService($id, $data);
            
            if ($success) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Service updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update service'
                ]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update service'
            ]);
        }
    }

    /**
     * Delete service
     */
    public function deleteService($id) {
        try {
            $success = $this->serviceRepo->deleteService($id);
            
            if ($success) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Service deleted successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to delete service'
                ]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete service'
            ]);
        }
    }

    /**
     * Get sub-services by service ID
     */
    public function getSubServices($serviceId) {
        try {
            $activeOnly = $_GET['active_only'] ?? false;
            $subServices = $this->serviceRepo->getSubServicesByServiceId($serviceId, $activeOnly);
            
            $this->sendResponse([
                'success' => true,
                'data' => $subServices
            ]);
        } catch (\Exception $e) {
            error_log('ServiceController::getSubServices - Error: ' . $e->getMessage());
            $this->sendResponse([
                'success' => false,
                'error' => 'Failed to fetch sub-services'
            ], 500);
        }
    }

    /**
     * Create sub-service
     */
    public function createSubService() {
        try {
            // Validate Content-Type header - only JSON is allowed
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Content-Type must be application/json'
                ]);
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['service_id']) || !isset($data['sub_service_name']) || !isset($data['sub_service_code'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Service ID, sub-service name and code are required'
                ]);
                return;
            }
            
            $subServiceId = $this->serviceRepo->createSubService($data);
            
            if ($subServiceId) {
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => 'Sub-service created successfully',
                    'data' => ['id' => $subServiceId]
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create sub-service'
                ]);
            }
        } catch (\Exception $e) {
            error_log("ServiceController::createSubService - " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create sub-service: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update sub-service
     */
    public function updateSubService($id) {
        try {
            // Validate Content-Type header - only JSON is allowed
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') === false) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Content-Type must be application/json'
                ]);
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['service_id']) || !isset($data['sub_service_name']) || !isset($data['sub_service_code'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Service ID, sub-service name and code are required'
                ]);
                return;
            }
            
            $success = $this->serviceRepo->updateSubService($id, $data);
            
            if ($success) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Sub-service updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update sub-service'
                ]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update sub-service'
            ]);
        }
    }

    /**
     * Delete sub-service
     */
    public function deleteSubService($id) {
        try {
            $success = $this->serviceRepo->deleteSubService($id);
            
            if ($success) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Sub-service deleted successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to delete sub-service'
                ]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete sub-service'
            ]);
        }
    }

    /**
     * Get services for a specific client
     */
    public function getClientServices($clientId) {
        try {
            $services = $this->serviceRepo->getClientServices($clientId);
            $subServices = $this->serviceRepo->getClientSubServices($clientId);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => [
                    'services' => $services,
                    'sub_services' => $subServices
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch client services'
            ]);
        }
    }

    /**
     * Assign services to a client
     */
    public function assignServicesToClient($clientId) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['services']) && !isset($data['sub_services'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Services or sub-services data required'
                ]);
                return;
            }
            
            // Clear existing services first
            $this->serviceRepo->clearClientServices($clientId);
            
            // Assign main services
            if (isset($data['services']) && is_array($data['services'])) {
                foreach ($data['services'] as $serviceId) {
                    $this->serviceRepo->assignServiceToClient($clientId, $serviceId);
                }
            }
            
            // Assign sub-services with quantities
            if (isset($data['sub_services']) && is_array($data['sub_services'])) {
                foreach ($data['sub_services'] as $subService) {
                    $this->serviceRepo->assignSubServiceToClient(
                        $clientId, 
                        $subService['id'],
                        $subService['quantity'] ?? 1,
                        $subService['notes'] ?? null
                    );
                }
            }
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Services assigned successfully'
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to assign services'
            ]);
        }
    }
}

