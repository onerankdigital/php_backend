<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FormConfigRepository;

class FormConfigService
{
    private FormConfigRepository $repository;

    public function __construct(FormConfigRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get all configurations
     */
    public function getAll(bool $activeOnly = true): array
    {
        $configs = $this->repository->getAll($activeOnly);
        
        return array_map(function ($config) {
            return $this->formatConfig($config);
        }, $configs);
    }

    /**
     * Get configuration by key (for iframe form)
     */
    public function getByKey(string $configKey): ?array
    {
        $config = $this->repository->getByKey($configKey);
        
        if (!$config) {
            return null;
        }
        
        return $this->formatConfig($config);
    }

    /**
     * Get configuration by ID
     */
    public function getById(int $id): ?array
    {
        $config = $this->repository->getById($id);
        
        if (!$config) {
            return null;
        }
        
        return $this->formatConfig($config);
    }

    /**
     * Create new configuration
     */
    public function create(array $data): array
    {
        // Validate required fields
        $this->validate($data);
        
        // Check if config_key already exists
        if ($this->repository->keyExists($data['config_key'])) {
            throw new \RuntimeException('Configuration key already exists');
        }
        
        $config = $this->repository->create($data);
        return $this->formatConfig($config);
    }

    /**
     * Update configuration
     */
    public function update(int $id, array $data): array
    {
        // Check if configuration exists
        $existing = $this->repository->getById($id);
        if (!$existing) {
            throw new \RuntimeException('Configuration not found');
        }
        
        // Validate
        $this->validate($data, false);
        
        $success = $this->repository->update($id, $data);
        
        if (!$success) {
            throw new \RuntimeException('Failed to update configuration');
        }
        
        $config = $this->repository->getById($id);
        return $this->formatConfig($config);
    }

    /**
     * Delete configuration
     */
    public function delete(int $id): bool
    {
        // Don't allow deleting default config
        $config = $this->repository->getById($id);
        if ($config && $config['config_key'] === 'default') {
            throw new \RuntimeException('Cannot delete default configuration');
        }
        
        return $this->repository->delete($id);
    }

    /**
     * Duplicate configuration
     */
    public function duplicate(int $id): array
    {
        $config = $this->repository->getById($id);
        
        if (!$config) {
            throw new \RuntimeException('Configuration not found');
        }
        
        // Create new config_key
        $baseKey = $config['config_key'];
        $newKey = $baseKey . '-copy';
        $counter = 1;
        
        while ($this->repository->keyExists($newKey)) {
            $newKey = $baseKey . '-copy-' . $counter;
            $counter++;
        }
        
        // Create duplicate
        $newData = [
            'config_key' => $newKey,
            'config_name' => $config['config_name'] . ' (Copy)',
            'title' => $config['title'],
            'subtitle' => $config['subtitle'],
            'submit_button_text' => $config['submit_button_text'],
            'success_message' => $config['success_message'],
            'primary_color' => $config['primary_color'],
            'button_color' => $config['button_color'],
            'background_color' => $config['background_color'],
            'fields' => $config['fields'],
            'enable_file_upload' => $config['enable_file_upload'],
            'file_upload_label' => $config['file_upload_label'],
            'file_upload_accept' => $config['file_upload_accept'],
            'file_upload_max_size' => $config['file_upload_max_size'],
            'is_active' => true
        ];
        
        return $this->create($newData);
    }

    /**
     * Validate configuration data
     */
    private function validate(array $data, bool $requireKey = true): void
    {
        if ($requireKey && empty($data['config_key'])) {
            throw new \InvalidArgumentException('Configuration key is required');
        }
        
        if (empty($data['config_name'])) {
            throw new \InvalidArgumentException('Configuration name is required');
        }
        
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('Form title is required');
        }
        
        if (empty($data['fields']) || !is_array($data['fields'])) {
            throw new \InvalidArgumentException('At least one field is required');
        }
        
        // Validate config_key format (alphanumeric, dash, underscore, dot)
        if ($requireKey && !preg_match('/^[a-zA-Z0-9._-]+$/', $data['config_key'])) {
            throw new \InvalidArgumentException('Configuration key can only contain letters, numbers, dash, underscore, and dot');
        }
    }

    /**
     * Format configuration for output
     */
    private function formatConfig(array $config): array
    {
        // Decode JSON fields
        if (is_string($config['fields'])) {
            $config['fields'] = json_decode($config['fields'], true);
        }
        
        // Convert boolean fields
        $config['enable_file_upload'] = (bool)$config['enable_file_upload'];
        $config['is_active'] = (bool)$config['is_active'];
        $config['file_upload_max_size'] = (int)$config['file_upload_max_size'];
        
        return $config;
    }
}

