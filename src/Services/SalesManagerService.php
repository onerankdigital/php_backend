<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesManagerRepository;
use App\Utils\Crypto;

class SalesManagerService
{
    private SalesManagerRepository $repository;
    private Crypto $crypto;

    public function __construct(SalesManagerRepository $repository, Crypto $crypto)
    {
        $this->repository = $repository;
        $this->crypto = $crypto;
    }

    public function get(int $id): ?array
    {
        $manager = $this->repository->findById($id);
        if (!$manager) {
            return null;
        }
        return $this->decryptManager($manager);
    }

    public function getAll(): array
    {
        $managers = $this->repository->getAll();
        return array_map([$this, 'decryptManager'], $managers);
    }

    public function create(array $data): array
    {
        // Validate required fields
        if (empty($data['name']) || empty($data['email'])) {
            throw new \InvalidArgumentException('Name and email are required');
        }

        $encrypted = [
            'name' => $this->crypto->encrypt($data['name']),
            'email' => $this->crypto->encrypt($data['email']),
            'phone' => isset($data['phone']) && !empty($data['phone']) ? $this->crypto->encrypt($data['phone']) : null
        ];

        $id = $this->repository->create($encrypted);
        return $this->get($id);
    }

    private function decryptManager(array $manager): array
    {
        return [
            'id' => (int)$manager['id'],
            'name' => $this->crypto->decrypt($manager['name']),
            'email' => $this->crypto->decrypt($manager['email']),
            'phone' => $manager['phone'] ? $this->crypto->decrypt($manager['phone']) : null,
            'created_at' => $manager['created_at'],
            'updated_at' => $manager['updated_at'] ?? null
        ];
    }
}

