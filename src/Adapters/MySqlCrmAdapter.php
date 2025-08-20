<?php

namespace PajGpsCalendar\Adapters;

use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\GeoService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;

/**
 * MySQL CRM Adapter - Standardadapter für MySQL-basierte CRM-Systeme
 * 
 * Dieser Adapter kann durch Anpassung der SQL-Queries an verschiedene
 * MySQL-basierte CRM-Systeme angepasst werden.
 */
class MySqlCrmAdapter implements CrmAdapterInterface
{
    private ConfigService $config;
    private Logger $logger;
    private GeoService $geoService;
    private Connection $connection;

    public function __construct(ConfigService $config, Logger $logger, GeoService $geoService)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->geoService = $geoService;
        $this->initConnection();
    }

    private function initConnection(): void
    {
        $connectionParams = [
            'dbname' => $this->config->get('crm.database.database'),
            'user' => $this->config->get('crm.database.username'),
            'password' => $this->config->get('crm.database.password'),
            'host' => $this->config->get('crm.database.host'),
            'port' => $this->config->get('crm.database.port', 3306),
            'driver' => 'pdo_mysql',
            'charset' => 'utf8mb4',
        ];

        $this->connection = DriverManager::getConnection($connectionParams);
    }

    public function findCustomersInRadius(float $latitude, float $longitude, int $radiusMeters): array
    {
        try {
            // SQL Query - kann an verschiedene CRM-Systeme angepasst werden
            $sql = $this->config->get('crm.queries.find_customers_in_radius', $this->getDefaultRadiusQuery());
            
            // Berechnung der Bounding Box für bessere Performance
            $boundingBox = $this->calculateBoundingBox($latitude, $longitude, $radiusMeters);
            
            $result = $this->connection->executeQuery($sql, [
                'center_lat' => $latitude,
                'center_lng' => $longitude,
                'radius_meters' => $radiusMeters,
                'min_lat' => $boundingBox['min_lat'],
                'max_lat' => $boundingBox['max_lat'],
                'min_lng' => $boundingBox['min_lng'],
                'max_lng' => $boundingBox['max_lng']
            ]);

            $customers = $result->fetchAllAssociative();
            
            // Exakte Distanzberechnung und Filterung
            $filteredCustomers = [];
            foreach ($customers as $customer) {
                $distance = $this->geoService->calculateDistance(
                    $latitude, 
                    $longitude,
                    $customer['latitude'], 
                    $customer['longitude']
                );
                
                if ($distance <= $radiusMeters) {
                    $customer['distance_meters'] = round($distance, 2);
                    $customer = $this->normalizeCustomerData($customer);
                    $filteredCustomers[] = $customer;
                }
            }

            // Sortierung nach Entfernung
            usort($filteredCustomers, function($a, $b) {
                return $a['distance_meters'] <=> $b['distance_meters'];
            });

            $this->logger->info('Kunden im Radius gefunden', [
                'center' => [$latitude, $longitude],
                'radius_meters' => $radiusMeters,
                'found_count' => count($filteredCustomers)
            ]);

            return $filteredCustomers;

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei Kunden-Radius-Suche', [
                'error' => $e->getMessage(),
                'center' => [$latitude, $longitude],
                'radius' => $radiusMeters
            ]);
            return [];
        }
    }

    public function getCustomerById($customerId): ?array
    {
        try {
            $sql = $this->config->get('crm.queries.get_customer_by_id', $this->getDefaultCustomerByIdQuery());
            
            $result = $this->connection->executeQuery($sql, ['customer_id' => $customerId]);
            $customer = $result->fetchAssociative();
            
            if ($customer) {
                return $this->normalizeCustomerData($customer);
            }
            
            return null;

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Laden von Kunde', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function findCustomersByName(string $customerName): array
    {
        try {
            $sql = $this->config->get('crm.queries.find_customers_by_name', $this->getDefaultCustomersByNameQuery());
            
            $result = $this->connection->executeQuery($sql, [
                'customer_name' => '%' . $customerName . '%'
            ]);
            
            $customers = $result->fetchAllAssociative();
            
            return array_map([$this, 'normalizeCustomerData'], $customers);

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei Kunden-Namenssuche', [
                'customer_name' => $customerName,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function testConnection(): bool
    {
        try {
            $this->connection->connect();
            $result = $this->connection->executeQuery('SELECT 1');
            return $result->fetchOne() === 1;
        } catch (\Exception $e) {
            $this->logger->error('CRM-Datenbankverbindung fehlgeschlagen', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getSystemName(): string
    {
        return $this->config->get('crm.system_name', 'MySQL CRM');
    }

    /**
     * Normalisiert Kundendaten in ein einheitliches Format
     */
    private function normalizeCustomerData(array $customer): array
    {
        // Mapping der Feldnamen basierend auf Konfiguration
        $fieldMapping = $this->config->get('crm.field_mapping', $this->getDefaultFieldMapping());
        
        $normalized = [];
        foreach ($fieldMapping as $standardField => $crmField) {
            $normalized[$standardField] = $customer[$crmField] ?? null;
        }
        
        // Zusätzliche Berechnungen/Formatierungen
        if (isset($customer['distance_meters'])) {
            $normalized['distance_meters'] = $customer['distance_meters'];
        }
        
        // Vollständige Adresse zusammenbauen falls nicht vorhanden
        if (empty($normalized['full_address']) && !empty($normalized['street'])) {
            $normalized['full_address'] = trim(sprintf('%s, %s %s', 
                $normalized['street'] ?? '',
                $normalized['postal_code'] ?? '',
                $normalized['city'] ?? ''
            ));
        }
        
        return $normalized;
    }

    /**
     * Standard SQL Query für Radius-Suche
     */
    private function getDefaultRadiusQuery(): string
    {
        return "
            SELECT 
                id as customer_id,
                customer_name,
                address as full_address,
                street,
                city,
                postal_code,
                country,
                latitude,
                longitude,
                phone,
                email,
                contact_person,
                notes
            FROM customer_locations 
            WHERE latitude BETWEEN :min_lat AND :max_lat
            AND longitude BETWEEN :min_lng AND :max_lng
            AND latitude IS NOT NULL 
            AND longitude IS NOT NULL
            AND (is_active IS NULL OR is_active = 1)
        ";
    }

    /**
     * Standard SQL Query für Kunden nach ID
     */
    private function getDefaultCustomerByIdQuery(): string
    {
        return "
            SELECT 
                id as customer_id,
                customer_name,
                address as full_address,
                street,
                city,
                postal_code,
                country,
                latitude,
                longitude,
                phone,
                email,
                contact_person,
                notes
            FROM customer_locations 
            WHERE id = :customer_id
        ";
    }

    /**
     * Standard SQL Query für Kunden nach Name
     */
    private function getDefaultCustomersByNameQuery(): string
    {
        return "
            SELECT 
                id as customer_id,
                customer_name,
                address as full_address,
                street,
                city,
                postal_code,
                country,
                latitude,
                longitude,
                phone,
                email,
                contact_person,
                notes
            FROM customer_locations 
            WHERE customer_name LIKE :customer_name
            AND latitude IS NOT NULL 
            AND longitude IS NOT NULL
            AND (is_active IS NULL OR is_active = 1)
            ORDER BY customer_name
        ";
    }

    /**
     * Standard Feldmapping
     */
    private function getDefaultFieldMapping(): array
    {
        return [
            'customer_id' => 'customer_id',
            'customer_name' => 'customer_name',
            'full_address' => 'full_address',
            'street' => 'street',
            'city' => 'city',
            'postal_code' => 'postal_code',
            'country' => 'country',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'phone' => 'phone',
            'email' => 'email',
            'contact_person' => 'contact_person',
            'notes' => 'notes'
        ];
    }

    /**
     * Berechnet eine Bounding Box für bessere SQL Performance
     */
    private function calculateBoundingBox(float $latitude, float $longitude, int $radiusMeters): array
    {
        // Ungefähre Grad-pro-Meter Konvertierung
        $latDegreesPerMeter = 1 / 111320;
        $lngDegreesPerMeter = 1 / (111320 * cos(deg2rad($latitude)));
        
        $latRadius = $radiusMeters * $latDegreesPerMeter;
        $lngRadius = $radiusMeters * $lngDegreesPerMeter;
        
        return [
            'min_lat' => $latitude - $latRadius,
            'max_lat' => $latitude + $latRadius,
            'min_lng' => $longitude - $lngRadius,
            'max_lng' => $longitude + $lngRadius
        ];
    }
}
