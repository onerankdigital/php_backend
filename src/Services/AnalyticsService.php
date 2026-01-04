<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnalyticsRepository;
use App\Repositories\ClientRepository;
use App\Repositories\SalesManagerRepository;
use App\Repositories\SalesPersonRepository;
use App\Utils\Crypto;
use App\Utils\Tokenizer;

class AnalyticsService
{
    private AnalyticsRepository $repository;
    private ClientRepository $clientRepository;
    private SalesManagerRepository $salesManagerRepository;
    private SalesPersonRepository $salesPersonRepository;
    private Crypto $crypto;
    private Tokenizer $tokenizer;

    public function __construct(
        AnalyticsRepository $repository,
        ClientRepository $clientRepository,
        Crypto $crypto,
        Tokenizer $tokenizer,
        ?SalesManagerRepository $salesManagerRepository = null,
        ?SalesPersonRepository $salesPersonRepository = null
    ) {
        $this->repository = $repository;
        $this->clientRepository = $clientRepository;
        $this->crypto = $crypto;
        $this->tokenizer = $tokenizer;
        $this->salesManagerRepository = $salesManagerRepository;
        $this->salesPersonRepository = $salesPersonRepository;
    }

    /**
     * Get analytics data based on user role
     */
    public function getAnalytics(?string $userRole = null): array
    {
        // Get client IDs and domain tokens based on role
        $clientIds = $this->getClientIdsForRole($userRole);
        $domainTokens = $this->getDomainTokensForClients($clientIds);

        // Get enquiry trends
        $dailyTrends = $this->repository->getEnquiryTrends('daily', 30, $domainTokens);
        $weeklyTrends = $this->repository->getEnquiryTrends('weekly', 12, $domainTokens);
        $monthlyTrends = $this->repository->getEnquiryTrends('monthly', 12, $domainTokens);
        $yearlyTrends = $this->repository->getEnquiryTrends('yearly', 5, $domainTokens);

        // Get totals
        $totalEnquiries = $this->repository->getTotalEnquiryCount($domainTokens);
        $totalClients = $this->repository->getClientCount($clientIds);

        // Get package distribution (only for admin and sales roles)
        $packageDistribution = null;
        $revenueEstimate = null;
        if ($userRole === 'admin' || $userRole === 'sales_manager' || $userRole === 'sales_person') {
            $packageDistribution = $this->getPackageDistribution($clientIds);
            $revenueEstimate = $this->calculateRevenueEstimate($packageDistribution);
        }

        return [
            'enquiries' => [
                'total' => $totalEnquiries,
                'daily' => $this->formatTrends($dailyTrends),
                'weekly' => $this->formatTrends($weeklyTrends),
                'monthly' => $this->formatTrends($monthlyTrends),
                'yearly' => $this->formatTrends($yearlyTrends),
            ],
            'clients' => [
                'total' => $totalClients,
            ],
            'packages' => $packageDistribution,
            'revenue' => $revenueEstimate,
        ];
    }

    private function getClientIdsForRole(?string $userRole): ?array
    {
        if ($userRole === 'admin' || $userRole === 'employee') {
            return null; // All clients
        }

        if ($userRole === 'client') {
            $clientId = $_SERVER['AUTH_USER_CLIENT_ID'] ?? null;
            return $clientId ? [$clientId] : [];
        }

        if ($userRole === 'sales_person') {
            $salesPersonId = $_SERVER['AUTH_USER_SALES_PERSON_ID'] ?? null;
            if (!$salesPersonId || !$this->salesPersonRepository) {
                return [];
            }
            return $this->salesPersonRepository->getClientIdsByPersonId($salesPersonId);
        }

        if ($userRole === 'sales_manager') {
            $salesManagerId = $_SERVER['AUTH_USER_SALES_MANAGER_ID'] ?? null;
            if (!$salesManagerId || !$this->salesManagerRepository) {
                return [];
            }
            return $this->salesManagerRepository->getClientIdsByManagerAndPersons($salesManagerId);
        }

        return [];
    }

    private function getDomainTokensForClients(?array $clientIds): ?array
    {
        if ($clientIds === null) {
            return null; // All domains
        }

        if (empty($clientIds)) {
            return []; // No clients = no domains
        }

        $domains = $this->clientRepository->getDomainsByClientIds($clientIds);
        if (empty($domains)) {
            return []; // No domains = no tokens
        }

        $allTokens = [];
        foreach ($domains as $domain) {
            $normalizedDomain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
            $normalizedDomain = preg_replace('/\/.*$/', '', $normalizedDomain);
            if (empty($normalizedDomain)) {
                continue;
            }
            $domainTokens = $this->tokenizer->edgeNgrams($normalizedDomain);
            $tokenHashes = array_map(fn($t) => $this->crypto->token($t), $domainTokens);
            $allTokens = array_merge($allTokens, $tokenHashes);
        }

        $uniqueTokens = array_unique($allTokens);
        return empty($uniqueTokens) ? [] : $uniqueTokens;
    }

    private function getPackageDistribution(?array $clientIds): ?array
    {
        $packages = $this->repository->getPackageDistribution($clientIds);
        
        if (empty($packages)) {
            return null;
        }

        // Decrypt packages and group
        $decryptedPackages = [];
        foreach ($packages as $row) {
            try {
                $package = $this->crypto->decrypt($row['package']);
                $count = (int)$row['count'];
                
                if (!isset($decryptedPackages[$package])) {
                    $decryptedPackages[$package] = 0;
                }
                $decryptedPackages[$package] += $count;
            } catch (\Exception $e) {
                // Skip if decryption fails
                continue;
            }
        }

        // Format for frontend
        $result = [];
        foreach ($decryptedPackages as $package => $count) {
            $result[] = [
                'package' => $package,
                'count' => $count
            ];
        }

        return $result;
    }

    private function calculateRevenueEstimate(?array $packageDistribution): ?array
    {
        if (!$packageDistribution) {
            return null;
        }

        // Package monthly values (adjust as needed)
        $packageValues = [
            'Basic' => 100,
            'Standard' => 250,
            'Premium' => 500
        ];

        $totalRevenue = 0;
        $breakdown = [];

        foreach ($packageDistribution as $item) {
            $package = $item['package'];
            $count = $item['count'];
            $monthlyValue = $packageValues[$package] ?? 0;
            $revenue = $monthlyValue * $count;
            $totalRevenue += $revenue;

            $breakdown[] = [
                'package' => $package,
                'count' => $count,
                'monthly_value' => $monthlyValue,
                'total_monthly' => $revenue
            ];
        }

        return [
            'total_monthly' => $totalRevenue,
            'total_yearly' => $totalRevenue * 12,
            'breakdown' => $breakdown
        ];
    }

    private function formatTrends(array $trends): array
    {
        return array_map(function($trend) {
            return [
                'period' => $trend['period'],
                'count' => (int)$trend['count']
            ];
        }, $trends);
    }
}

