<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmployeeRepository;
use App\Utils\Crypto;

class EmployeeService
{
    private EmployeeRepository $repository;
    private Crypto $crypto;

    public function __construct(EmployeeRepository $repository, Crypto $crypto)
    {
        $this->repository = $repository;
        $this->crypto = $crypto;
    }

    public function get(int $id): ?array
    {
        $employee = $this->repository->findById($id);
        if (!$employee) {
            return null;
        }
        return $this->decryptEmployee($employee);
    }

    public function getAll(): array
    {
        $employees = $this->repository->getAll();
        return array_map([$this, 'decryptEmployee'], $employees);
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
            'phone' => isset($data['phone']) && !empty($data['phone']) ? $this->crypto->encrypt($data['phone']) : null,
            'department' => $data['department'] ?? null,
            'position' => $data['position'] ?? null
        ];

        $id = $this->repository->create($encrypted);
        return $this->get($id);
    }

    private function decryptEmployee(array $employee): array
    {
        return [
            'id' => (int)$employee['id'],
            'name' => $this->crypto->decrypt($employee['name']),
            'email' => $this->crypto->decrypt($employee['email']),
            'phone' => $employee['phone'] ? $this->crypto->decrypt($employee['phone']) : null,
            'department' => $employee['department'],
            'position' => $employee['position'],
            'created_at' => $employee['created_at'],
            'updated_at' => $employee['updated_at'] ?? null
        ];
    }
}

