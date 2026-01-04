<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class EnquiryRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO enquiry_form (
            company_name, full_name, email, mobile, address, 
            enquiry_details, domain, ip_address, user_agent, 
            submitted_at, captcha_verified, file_link, extra_fields
        ) VALUES (
            :company_name, :full_name, :email, :mobile, :address,
            :enquiry_details, :domain, :ip_address, :user_agent,
            :submitted_at, :captcha_verified, :file_link, :extra_fields
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':company_name' => $data['company_name'],
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':mobile' => $data['mobile'],
            ':address' => $data['address'],
            ':enquiry_details' => $data['enquiry_details'],
            ':domain' => $data['domain'],
            ':ip_address' => $data['ip_address'] ?? null,
            ':user_agent' => $data['user_agent'] ?? null,
            ':submitted_at' => $data['submitted_at'] ?? date('Y-m-d H:i:s'),
            ':captcha_verified' => $data['captcha_verified'] ?? 0,
            ':file_link' => $data['file_link'] ?? null,
            ':extra_fields' => $data['extra_fields'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM enquiry_form WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function getAll(int $limit = 100, int $offset = 0, ?array $domainTokens = null): array
    {
        if ($domainTokens !== null && !empty($domainTokens)) {
            // Filter by domains - get enquiry IDs that match any of the domain tokens
            $placeholders = implode(',', array_fill(0, count($domainTokens), '?'));
            $sql = "SELECT DISTINCT e.* 
                    FROM enquiry_form e
                    INNER JOIN enquiry_search_index esi ON e.id = esi.enquiry_id
                    WHERE esi.field_name = 'domain' AND esi.token_hash IN ($placeholders)
                    ORDER BY e.submitted_at DESC 
                    LIMIT ? OFFSET ?";
            $params = array_merge($domainTokens, [$limit, $offset]);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } else {
            $sql = "SELECT * FROM enquiry_form ORDER BY submitted_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }

    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM enquiry_form WHERE id IN ($placeholders) ORDER BY submitted_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) as count FROM enquiry_form";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        return (int)($result['count'] ?? 0);
    }

    /**
     * Store search index tokens in MySQL (replaces Redis)
     */
    public function storeSearchTokens(int $enquiryId, array $tokens, string $fieldName): void
    {
        // Delete existing tokens for this enquiry and field
        $this->deleteSearchTokens($enquiryId, $fieldName);

        // Insert new tokens
        $sql = "INSERT INTO enquiry_search_index (enquiry_id, field_name, token_hash) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($tokens as $token) {
            $stmt->execute([$enquiryId, $fieldName, $token]);
        }
    }

    /**
     * Delete search tokens for an enquiry and field
     */
    public function deleteSearchTokens(int $enquiryId, string $fieldName): void
    {
        $sql = "DELETE FROM enquiry_search_index WHERE enquiry_id = ? AND field_name = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$enquiryId, $fieldName]);
    }

    /**
     * Search enquiries by token hashes (MySQL-based search)
     */
    public function searchByTokens(array $tokenHashes, string $fieldName, int $limit = 100): array
    {
        if (empty($tokenHashes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tokenHashes), '?'));
        $sql = "SELECT DISTINCT enquiry_id 
                FROM enquiry_search_index 
                WHERE field_name = ? AND token_hash IN ($placeholders)
                LIMIT ?";
        
        $params = array_merge([$fieldName], $tokenHashes, [$limit]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll();
        return array_map(function($row) {
            return (int)$row['enquiry_id'];
        }, $results);
    }
}

