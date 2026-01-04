<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AnalyticsRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get enquiry trends (count over time) filtered by domain tokens
     */
    public function getEnquiryTrends(string $period, int $limit = 12, ?array $domainTokens = null): array
    {
        $dateFormat = match($period) {
            'daily' => '%Y-%m-%d',
            'weekly' => '%Y-%u',
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m'
        };

        if ($domainTokens === null || empty($domainTokens)) {
            $sql = "SELECT DATE_FORMAT(submitted_at, ?) as period, COUNT(*) as count 
                    FROM enquiry_form 
                    GROUP BY period 
                    ORDER BY period DESC 
                    LIMIT ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFormat, $limit]);
        } else if (is_array($domainTokens) && count($domainTokens) > 0) {
            $placeholders = implode(',', array_fill(0, count($domainTokens), '?'));
            $sql = "SELECT DATE_FORMAT(e.submitted_at, ?) as period, COUNT(DISTINCT e.id) as count 
                    FROM enquiry_form e
                    INNER JOIN enquiry_search_index esi ON e.id = esi.enquiry_id
                    WHERE esi.field_name = 'domain' AND esi.token_hash IN ($placeholders)
                    GROUP BY period 
                    ORDER BY period DESC 
                    LIMIT ?";
            $params = array_merge([$dateFormat], $domainTokens, [$limit]);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } else {
            // Empty domain tokens array - return empty results
            return [];
        }

        $results = $stmt->fetchAll();
        return array_reverse($results); // Return in chronological order
    }

    /**
     * Get total enquiry count filtered by domain tokens
     */
    public function getTotalEnquiryCount(?array $domainTokens = null): int
    {
        if ($domainTokens === null || empty($domainTokens)) {
            $sql = "SELECT COUNT(*) as total FROM enquiry_form";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        } else if (is_array($domainTokens) && count($domainTokens) > 0) {
            $placeholders = implode(',', array_fill(0, count($domainTokens), '?'));
            $sql = "SELECT COUNT(DISTINCT e.id) as total 
                    FROM enquiry_form e
                    INNER JOIN enquiry_search_index esi ON e.id = esi.enquiry_id
                    WHERE esi.field_name = 'domain' AND esi.token_hash IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($domainTokens);
        } else {
            // Empty domain tokens array - return 0
            return 0;
        }
        
        $result = $stmt->fetch();
        return (int)($result['total'] ?? 0);
    }

    /**
     * Get enquiry counts by period (daily, weekly, monthly, yearly)
     */
    public function getEnquiryCountsByPeriod(string $period, ?array $domainTokens = null): array
    {
        $dateFormat = match($period) {
            'daily' => '%Y-%m-%d',
            'weekly' => '%Y-%u',
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m-%d'
        };

        if ($domainTokens === null || empty($domainTokens)) {
            $sql = "SELECT DATE_FORMAT(submitted_at, ?) as period, COUNT(*) as count 
                    FROM enquiry_form 
                    GROUP BY period 
                    ORDER BY period ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFormat]);
        } else if (is_array($domainTokens) && count($domainTokens) > 0) {
            $placeholders = implode(',', array_fill(0, count($domainTokens), '?'));
            $sql = "SELECT DATE_FORMAT(e.submitted_at, ?) as period, COUNT(DISTINCT e.id) as count 
                    FROM enquiry_form e
                    INNER JOIN enquiry_search_index esi ON e.id = esi.enquiry_id
                    WHERE esi.field_name = 'domain' AND esi.token_hash IN ($placeholders)
                    GROUP BY period 
                    ORDER BY period ASC";
            $params = array_merge([$dateFormat], $domainTokens);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } else {
            // Empty domain tokens array - return empty results
            return [];
        }

        return $stmt->fetchAll();
    }

    /**
     * Get package distribution (for business analytics) - returns encrypted packages
     */
    public function getPackageDistribution(?array $clientIds = null): array
    {
        if ($clientIds === null || empty($clientIds)) {
            $sql = "SELECT package, COUNT(*) as count FROM clients GROUP BY package";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $sql = "SELECT package, COUNT(*) as count 
                    FROM clients 
                    WHERE id IN ($placeholders)
                    GROUP BY package";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($clientIds);
        }

        return $stmt->fetchAll();
    }

    /**
     * Get client count
     */
    public function getClientCount(?array $clientIds = null): int
    {
        if ($clientIds === null || empty($clientIds)) {
            $sql = "SELECT COUNT(*) as total FROM clients";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $sql = "SELECT COUNT(*) as total FROM clients WHERE id IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($clientIds);
        }
        
        $result = $stmt->fetch();
        return (int)($result['total'] ?? 0);
    }
}

