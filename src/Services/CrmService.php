<?php

namespace PajGpsCalendar\Services;

use PajGpsCalendar\Adapters\CrmAdapterInterface;
use PajGpsCalendar\Adapters\MySqlCrmAdapter;
use PajGpsCalendar\Adapters\GenericSqlCrmAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;

/**
 * CRM Service - Abstrakte Schicht für verschiedene CRM-Systeme
 * 
 * Verwendet Adapter-Pattern um verschiedene CRM-Systeme anzubinden
 */
class CrmService
{
    private ConfigService $config;
    private Logger $logger;
    private GeoService $geoService;
    private CrmAdapterInterface $adapter;
    private ?Connection $trackingConnection = null;

    public function __construct(ConfigService $config, Logger $logger, GeoService $geoService)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->geoService = $geoService;
        $this->initializeAdapter();
        $this->initTrackingDatabase();
    }

    private function initializeAdapter(): void
    {
        $adapterType = $this->config->get('crm.adapter_type', 'mysql');
        
        switch (strtolower($adapterType)) {
            case 'mysql':
            case 'standard':
                $this->adapter = new MySqlCrmAdapter($this->config, $this->logger, $this->geoService);
                break;
                
            case 'generic':
            case 'custom':
                $this->adapter = new GenericSqlCrmAdapter($this->config, $this->logger, $this->geoService);
                break;
                
            // Weitere Adapter können hier hinzugefügt werden
            // case 'salesforce':
            //     $this->adapter = new SalesForceCrmAdapter($this->config, $this->logger, $this->geoService);
            //     break;
                
            default:
                throw new \Exception("Unbekannter CRM-Adapter: {$adapterType}");
        }
        
        $this->logger->info('CRM-Adapter initialisiert', ['adapter' => $this->adapter->getSystemName()]);
    }

    private function initTrackingDatabase(): void
    {
        // Separate kleine Datenbank nur für das Tracking der Besuche
        $trackingDbConfig = $this->config->get('tracking_database');
        
        if ($trackingDbConfig) {
            $connectionParams = [
                'dbname' => $trackingDbConfig['database'],
                'user' => $trackingDbConfig['username'],
                'password' => $trackingDbConfig['password'],
                'host' => $trackingDbConfig['host'],
                'port' => $trackingDbConfig['port'] ?? 3306,
                'driver' => 'pdo_mysql',
                'charset' => 'utf8mb4',
            ];

            $this->trackingConnection = DriverManager::getConnection($connectionParams);
        }
    }

    /**
     * Findet Kunden in der Nähe einer GPS-Position
     */
    public function findCustomersNearLocation(float $latitude, float $longitude, int $radiusMeters = null): array
    {
        $radius = $radiusMeters ?? $this->config->get('settings.proximity_threshold_meters', 500);
        
        return $this->adapter->findCustomersInRadius($latitude, $longitude, $radius);
    }

    /**
     * Holt Kundendetails
     */
    public function getCustomerById($customerId): ?array
    {
        return $this->adapter->getCustomerById($customerId);
    }

    /**
     * Sucht Kunden nach Name
     */
    public function searchCustomers(string $customerName): array
    {
        return $this->adapter->findCustomersByName($customerName);
    }

    /**
     * Testet die CRM-Verbindung
     */
    public function testCrmConnection(): bool
    {
        return $this->adapter->testConnection();
    }

    /**
     * Gibt den Namen des verwendeten CRM-Systems zurück
     */
    public function getCrmSystemName(): string
    {
        return $this->adapter->getSystemName();
    }

    /**
     * Speichert einen Besuchseintrag in der Tracking-Datenbank
     */
    public function saveVisitEntry(array $vehicle, array $customer, float $distance, string $calendarEventId): bool
    {
        if (!$this->trackingConnection) {
            $this->logger->warning('Tracking-Datenbank nicht konfiguriert, Besuch wird nicht gespeichert');
            return false;
        }

        try {
            $sql = "INSERT INTO visit_tracking 
                    (vehicle_id, vehicle_name, customer_id, customer_name, 
                     distance_meters, calendar_event_id, visit_time,
                     vehicle_latitude, vehicle_longitude, customer_latitude, customer_longitude) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";

            $this->trackingConnection->executeStatement($sql, [
                $vehicle['id'],
                $vehicle['name'],
                $customer['customer_id'],
                $customer['customer_name'],
                $distance,
                $calendarEventId,
                $vehicle['latitude'],
                $vehicle['longitude'],
                $customer['latitude'],
                $customer['longitude']
            ]);

            $this->logger->info('Besuchseintrag gespeichert', [
                'vehicle' => $vehicle['name'],
                'customer' => $customer['customer_name'],
                'distance' => $distance
            ]);

            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Speichern des Besuchseintrags', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Prüft ob bereits ein kürzlicher Besuchseintrag existiert
     */
    public function hasRecentVisit(array $vehicle, array $customer, int $hoursBack = 24): bool
    {
        if (!$this->trackingConnection) {
            return false;
        }

        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM visit_tracking 
                    WHERE vehicle_id = ? 
                    AND customer_id = ? 
                    AND visit_time > DATE_SUB(NOW(), INTERVAL ? HOUR)";

            $result = $this->trackingConnection->executeQuery($sql, [
                $vehicle['id'],
                $customer['customer_id'],
                $hoursBack
            ]);

            $count = $result->fetchOne();
            return $count > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Duplikatsprüfung', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Berechnet und speichert Aufenthaltsdauer wenn Fahrzeug sich entfernt
     */
    public function updateVisitDuration(array $vehicle, array $customer): void
    {
        if (!$this->trackingConnection) {
            return;
        }

        try {
            // Letzten Besuch finden ohne Abfahrtszeit
            $sql = "SELECT id, visit_time 
                    FROM visit_tracking 
                    WHERE vehicle_id = ? 
                    AND customer_id = ? 
                    AND departure_time IS NULL 
                    ORDER BY visit_time DESC 
                    LIMIT 1";

            $result = $this->trackingConnection->executeQuery($sql, [
                $vehicle['id'],
                $customer['customer_id']
            ]);

            $visit = $result->fetchAssociative();
            
            if ($visit) {
                $arrivalTime = new \DateTime($visit['visit_time']);
                $departureTime = new \DateTime();
                $durationMinutes = ($departureTime->getTimestamp() - $arrivalTime->getTimestamp()) / 60;

                // Aktualisiere Besuchseintrag
                $updateSql = "UPDATE visit_tracking 
                             SET departure_time = NOW(), 
                                 duration_minutes = ? 
                             WHERE id = ?";

                $this->trackingConnection->executeStatement($updateSql, [
                    round($durationMinutes),
                    $visit['id']
                ]);

                $this->logger->info('Aufenthaltsdauer aktualisiert', [
                    'vehicle' => $vehicle['name'],
                    'customer' => $customer['customer_name'],
                    'duration_minutes' => round($durationMinutes)
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren der Aufenthaltsdauer', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Erstellt die Tracking-Tabellen
     */
    public function createTrackingTables(): void
    {
        if (!$this->trackingConnection) {
            throw new \Exception('Tracking-Datenbank nicht konfiguriert');
        }

        $sql = "CREATE TABLE IF NOT EXISTS visit_tracking (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id VARCHAR(255) NOT NULL,
            vehicle_name VARCHAR(255) NOT NULL,
            customer_id VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            distance_meters DECIMAL(8, 2) NOT NULL,
            calendar_event_id VARCHAR(255) NOT NULL,
            visit_time TIMESTAMP NOT NULL,
            departure_time TIMESTAMP NULL,
            duration_minutes INT DEFAULT 0,
            vehicle_latitude DECIMAL(10, 8),
            vehicle_longitude DECIMAL(11, 8),
            customer_latitude DECIMAL(10, 8),
            customer_longitude DECIMAL(11, 8),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_vehicle_customer (vehicle_id, customer_id),
            INDEX idx_visit_time (visit_time),
            INDEX idx_vehicle (vehicle_id),
            INDEX idx_customer (customer_id),
            INDEX idx_calendar_event (calendar_event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->trackingConnection->executeStatement($sql);
        
        $this->logger->info('Tracking-Tabellen erstellt');
    }

    /**
     * Holt Besuchs-Statistiken
     */
    public function getVisitStatistics(int $days = 30): array
    {
        if (!$this->trackingConnection) {
            return [];
        }

        try {
            $sql = "SELECT 
                        customer_name,
                        COUNT(*) as visit_count,
                        AVG(duration_minutes) as avg_duration,
                        MAX(visit_time) as last_visit,
                        AVG(distance_meters) as avg_distance
                    FROM visit_tracking 
                    WHERE visit_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY customer_id, customer_name
                    ORDER BY visit_count DESC";

            $result = $this->trackingConnection->executeQuery($sql, [$days]);
            return $result->fetchAllAssociative();
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Statistiken', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
