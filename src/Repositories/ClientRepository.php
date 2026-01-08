<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ClientRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    /**
     * Create a new client
     * @return string The generated client_id (VARCHAR)
     */
    public function create(array $data): string
    {
        $sql = "INSERT INTO clients (
            package, client_name, person_name, address, phone, email,
            domains, city, state, pincode, bidx_version, total_amount,
            designation, gstin_no, specific_guidelines,
            package_amount, gst_amount, payment_mode,
            signature_name, signature_designation, signature_text, esignature_data,
            order_date, seo_keyword_range, seo_location, seo_keywords_list,
            adwords_keywords, adwords_period, adwords_location, adwords_keywords_list,
            special_guidelines
        ) VALUES (
            :package, :client_name, :person_name, :address, :phone, :email,
            :domains, :city, :state, :pincode, :bidx_version, :total_amount,
            :designation, :gstin_no, :specific_guidelines,
            :package_amount, :gst_amount, :payment_mode,
            :signature_name, :signature_designation, :signature_text, :esignature_data,
            :order_date, :seo_keyword_range, :seo_location, :seo_keywords_list,
            :adwords_keywords, :adwords_period, :adwords_location, :adwords_keywords_list,
            :special_guidelines
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':package' => $data['package'],
            ':client_name' => $data['client_name'],
            ':person_name' => $data['person_name'],
            ':address' => $data['address'],
            ':phone' => $data['phone'],
            ':email' => $data['email'],
            ':domains' => $data['domains'],
            ':city' => $data['city'] ?? null,
            ':state' => $data['state'] ?? null,
            ':pincode' => $data['pincode'] ?? null,
            ':bidx_version' => $data['bidx_version'] ?? 'v1',
            ':total_amount' => $data['total_amount'] ?? 0.00, // package_amount + gst_amount
            ':designation' => $data['designation'] ?? null,
            ':gstin_no' => $data['gstin_no'] ?? null,
            ':specific_guidelines' => $data['specific_guidelines'] ?? null,
            ':package_amount' => $data['package_amount'] ?? 0.00,
            ':gst_amount' => $data['gst_amount'] ?? 0.00,
            ':payment_mode' => $data['payment_mode'] ?? null,
            ':signature_name' => $data['signature_name'] ?? null,
            ':signature_designation' => $data['signature_designation'] ?? null,
            ':signature_text' => $data['signature_text'] ?? null,
            ':esignature_data' => $data['esignature_data'] ?? null,
            ':order_date' => $data['order_date'] ?? date('Y-m-d'),
            ':seo_keyword_range' => $data['seo_keyword_range'] ?? null,
            ':seo_location' => $data['seo_location'] ?? null,
            ':seo_keywords_list' => $data['seo_keywords_list'] ?? null,
            ':adwords_keywords' => $data['adwords_keywords'] ?? null,
            ':adwords_period' => $data['adwords_period'] ?? null,
            ':adwords_location' => $data['adwords_location'] ?? null,
            ':adwords_keywords_list' => $data['adwords_keywords_list'] ?? null,
            ':special_guidelines' => $data['special_guidelines'] ?? null,
        ]);

        // Get the auto-generated client_id
        $stmt = $this->db->prepare('SELECT client_id FROM clients WHERE id = LAST_INSERT_ID()');
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /**
     * Get client by client_id (VARCHAR)
     */
    public function getById(string $clientId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE client_id = :client_id');
        $stmt->execute([':client_id' => $clientId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get client by numeric id (for backward compatibility)
     */
    public function getByNumericId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all clients with optional filtering by client_ids
     * @param array|null $clientIds Array of client_id VARCHAR values
     */
    public function getAll(int $limit = 100, int $offset = 0, ?array $clientIds = null): array
    {
        // If clientIds is provided (even if empty), filter by those IDs
        // null means no filter (show all), empty array means show nothing
        if ($clientIds !== null) {
            if (empty($clientIds)) {
                // Empty array means no clients should be returned
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE client_id IN ($placeholders) ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $params = array_merge($clientIds, [$limit, $offset]);
            $stmt->execute($params);
        } else {
            // null means no filter - show all clients (for admin/employee only)
            $stmt = $this->db->prepare('SELECT * FROM clients ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    /**
     * Update client by client_id (VARCHAR)
     */
    public function update(string $clientId, array $data): bool
    {
        $fields = [];
        $params = [':client_id' => $clientId];

        $allowedFields = [
            'package', 'client_name', 'person_name', 'address', 'phone', 'email', 'domains', 
            'city', 'state', 'pincode', 'total_amount', 'designation', 
            'gstin_no', 'specific_guidelines', 'package_amount', 'gst_amount',
            'payment_mode', 'signature_name', 'signature_designation', 'signature_text', 
            'esignature_data', 'order_date', 'seo_keyword_range', 'seo_location', 'seo_keywords_list',
            'adwords_keywords', 'adwords_period', 'adwords_location', 'adwords_keywords_list',
            'special_guidelines'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE clients SET " . implode(', ', $fields) . " WHERE client_id = :client_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete client by client_id (VARCHAR)
     */
    public function delete(string $clientId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM clients WHERE client_id = :client_id');
        return $stmt->execute([':client_id' => $clientId]);
    }

    /**
     * Get domains for a client
     */
    public function getDomains(string $clientId): array
    {
        $client = $this->getById($clientId);
        if (!$client || empty($client['domains'])) {
            return [];
        }
        
        $domains = json_decode($client['domains'], true);
        return is_array($domains) ? $domains : [];
    }

    /**
     * Get domains for multiple clients
     */
    public function getDomainsByClientIds(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $this->db->prepare("SELECT domains FROM clients WHERE client_id IN ($placeholders)");
        $stmt->execute($clientIds);
        $results = $stmt->fetchAll();

        $allDomains = [];
        foreach ($results as $row) {
            if (!empty($row['domains'])) {
                $domains = json_decode($row['domains'], true);
                if (is_array($domains)) {
                    $allDomains = array_merge($allDomains, $domains);
                }
            }
        }

        return array_unique($allDomains);
    }

    public function count(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM clients");
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Get total count with optional filtering
     */
    public function getTotalCount(?array $clientIds = null): int
    {
        if ($clientIds !== null) {
            if (empty($clientIds)) {
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM clients WHERE client_id IN ($placeholders)");
            $stmt->execute($clientIds);
        } else {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM clients");
        }
        
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Search clients (for backward compatibility - uses old search method)
     */
    public function searchClients(string $query, int $limit = 10): array
    {
        // Simple search across non-encrypted fields
        $stmt = $this->db->prepare("
            SELECT * FROM clients 
            WHERE city LIKE :query 
            OR state LIKE :query 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        
        $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
