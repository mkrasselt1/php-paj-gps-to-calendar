<?php

namespace PajGpsCalendar\Services;

use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private array $config;

    public function __construct(string $configFile = null)
    {
        $configFile = $configFile ?: __DIR__ . '/../../config/config.yaml';
        
        if (!file_exists($configFile)) {
            throw new \Exception("Konfigurationsdatei nicht gefunden: {$configFile}");
        }
        
        $this->config = Yaml::parseFile($configFile);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    public function getAll(): array
    {
        return $this->config;
    }
}
