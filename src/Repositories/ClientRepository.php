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

    public function create(array $data): int
    {
        $sql = "INSERT INTO clients (
            package, client_name, person_name, address, phone, email,
            domains, city, state, pincode, bidx_version
        ) VALUES (
            :package, :client_name, :person_name, :address, :phone, :email,
            :domains, :city, :state, :pincode, :bidx_version
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
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

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
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE id IN ($placeholders) ORDER BY created_at DESC LIMIT ? OFFSET ?");
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

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['package', 'client_name', 'person_name', 'address', 'phone', 'email', 'domains', 'city', 'state', 'pincode'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE clients SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM clients WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function getDomains(int $clientId): array
    {
        $client = $this->getById($clientId);
        if (!$client || empty($client['domains'])) {
            return [];
        }
        
        $domains = json_decode($client['domains'], true);
        return is_array($domains) ? $domains : [];
    }

    public function getDomainsByClientIds(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $this->db->prepare("SELECT domains FROM clients WHERE id IN ($placeholders)");
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
        return (int)($result['count'] ?? 0);
    }

    public function countByIds(array $clientIds): int
    {
        if (empty($clientIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM clients WHERE id IN ($placeholders)");
        $stmt->execute($clientIds);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }
}

