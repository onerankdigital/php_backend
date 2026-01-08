<?php
namespace App\Repositories;

use PDO;
use PDOException;

class ServiceRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Get all services
     */
    public function getAllServices($activeOnly = false) {
        try {
            $sql = "SELECT * FROM services";
            if ($activeOnly) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY display_order ASC, service_name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getAllServices - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get service by ID
     */
    public function getServiceById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM services WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getServiceById - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get service by code
     */
    public function getServiceByCode($code) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM services WHERE service_code = ?");
            $stmt->execute([$code]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getServiceByCode - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get services with their sub-services
     */
    public function getServicesWithSubServices($activeOnly = false) {
        try {
            $services = $this->getAllServices($activeOnly);
            
            foreach ($services as &$service) {
                $service['sub_services'] = $this->getSubServicesByServiceId($service['id'], $activeOnly);
            }
            
            return $services;
        } catch (PDOException $e) {
            error_log("ServiceRepository::getServicesWithSubServices - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sub-services by service ID
     */
    public function getSubServicesByServiceId($serviceId, $activeOnly = false) {
        try {
            $sql = "SELECT * FROM sub_services WHERE service_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY display_order ASC, sub_service_name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$serviceId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getSubServicesByServiceId - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sub-service by ID
     */
    public function getSubServiceById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM sub_services WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getSubServiceById - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new service
     */
    public function createService($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO services (service_name, service_code, description, category, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            // Convert boolean values to integers for MySQL
            $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
            
            $stmt->execute([
                $data['service_name'],
                $data['service_code'],
                $data['description'] ?? null,
                $data['category'] ?? null,
                $isActive,
                isset($data['display_order']) ? (int)$data['display_order'] : 0
            ]);
            
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("ServiceRepository::createService - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new sub-service
     */
    public function createSubService($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sub_services 
                (service_id, sub_service_name, sub_service_code, description, has_quantity, 
                 quantity_label, default_quantity, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Convert boolean values to integers for MySQL
            $hasQuantity = isset($data['has_quantity']) ? (int)(bool)$data['has_quantity'] : 0;
            $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
            
            $stmt->execute([
                (int)$data['service_id'],
                $data['sub_service_name'],
                $data['sub_service_code'],
                $data['description'] ?? null,
                $hasQuantity,
                $data['quantity_label'] ?? null,
                isset($data['default_quantity']) ? (int)$data['default_quantity'] : 1,
                $isActive,
                isset($data['display_order']) ? (int)$data['display_order'] : 0
            ]);
            
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("ServiceRepository::createSubService - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update service
     */
    public function updateService($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE services 
                SET service_name = ?, service_code = ?, description = ?, 
                    category = ?, is_active = ?, display_order = ?
                WHERE id = ?
            ");
            
            // Convert boolean values to integers for MySQL
            $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
            
            return $stmt->execute([
                $data['service_name'],
                $data['service_code'],
                $data['description'] ?? null,
                $data['category'] ?? null,
                $isActive,
                isset($data['display_order']) ? (int)$data['display_order'] : 0,
                (int)$id
            ]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::updateService - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update sub-service
     */
    public function updateSubService($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE sub_services 
                SET service_id = ?, sub_service_name = ?, sub_service_code = ?, 
                    description = ?, has_quantity = ?, quantity_label = ?, 
                    default_quantity = ?, is_active = ?, display_order = ?
                WHERE id = ?
            ");
            
            // Convert boolean values to integers for MySQL
            $hasQuantity = isset($data['has_quantity']) ? (int)(bool)$data['has_quantity'] : 0;
            $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
            
            return $stmt->execute([
                (int)$data['service_id'],
                $data['sub_service_name'],
                $data['sub_service_code'],
                $data['description'] ?? null,
                $hasQuantity,
                $data['quantity_label'] ?? null,
                isset($data['default_quantity']) ? (int)$data['default_quantity'] : 1,
                $isActive,
                isset($data['display_order']) ? (int)$data['display_order'] : 0,
                (int)$id
            ]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::updateSubService - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete service
     */
    public function deleteService($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM services WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::deleteService - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete sub-service
     */
    public function deleteSubService($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM sub_services WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::deleteSubService - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get services for a client
     */
    public function getClientServices($clientId) {
        try {
            $stmt = $this->db->prepare("
                SELECT s.* 
                FROM services s
                INNER JOIN client_services cs ON s.id = cs.service_id
                WHERE cs.client_id = ?
                ORDER BY s.display_order ASC
            ");
            $stmt->execute([$clientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getClientServices - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sub-services for a client
     */
    public function getClientSubServices($clientId) {
        try {
            $stmt = $this->db->prepare("
                SELECT ss.*, css.quantity, css.notes 
                FROM sub_services ss
                INNER JOIN client_sub_services css ON ss.id = css.sub_service_id
                WHERE css.client_id = ?
                ORDER BY ss.display_order ASC
            ");
            $stmt->execute([$clientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ServiceRepository::getClientSubServices - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Assign service to client
     */
    public function assignServiceToClient($clientId, $serviceId) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_services (client_id, service_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE service_id = service_id
            ");
            return $stmt->execute([$clientId, $serviceId]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::assignServiceToClient - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Assign sub-service to client
     */
    public function assignSubServiceToClient($clientId, $subServiceId, $quantity = 1, $notes = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_sub_services (client_id, sub_service_id, quantity, notes)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?, notes = ?
            ");
            return $stmt->execute([$clientId, $subServiceId, $quantity, $notes, $quantity, $notes]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::assignSubServiceToClient - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove service from client
     */
    public function removeServiceFromClient($clientId, $serviceId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM client_services WHERE client_id = ? AND service_id = ?");
            return $stmt->execute([$clientId, $serviceId]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::removeServiceFromClient - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove sub-service from client
     */
    public function removeSubServiceFromClient($clientId, $subServiceId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM client_sub_services WHERE client_id = ? AND sub_service_id = ?");
            return $stmt->execute([$clientId, $subServiceId]);
        } catch (PDOException $e) {
            error_log("ServiceRepository::removeSubServiceFromClient - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all services for a client
     */
    public function clearClientServices($clientId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM client_services WHERE client_id = ?");
            $stmt->execute([$clientId]);
            
            $stmt = $this->db->prepare("DELETE FROM client_sub_services WHERE client_id = ?");
            $stmt->execute([$clientId]);
            
            return true;
        } catch (PDOException $e) {
            error_log("ServiceRepository::clearClientServices - " . $e->getMessage());
            return false;
        }
    }
}

