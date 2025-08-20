<?php

namespace PajGpsCalendar\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;

class DatabaseService
{
    private ConfigService $config;
    private Logger $logger;
    private Connection $connection;

    public function __construct(ConfigService $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->initConnection();
    }

    private function initConnection(): void
    {
        $connectionParams = [
            'dbname' => $this->config->get('database.database'),
            'user' => $this->config->get('database.username'),
            'password' => $this->config->get('database.password'),
            'host' => $this->config->get('database.host'),
            'port' => $this->config->get('database.port', 3306),
            'driver' => 'pdo_' . $this->config->get('database.driver', 'mysql'),
        ];

        $this->connection = DriverManager::getConnection($connectionParams);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getCustomerLocations(): array
    {
        try {
            $sql = "SELECT 
                        id,
                        customer_name,
                        address,
                        latitude,
                        longitude,
                        created_at
                    FROM customer_locations 
                    WHERE latitude IS NOT NULL 
                    AND longitude IS NOT NULL
                    ORDER BY customer_name";

            $result = $this->connection->executeQuery($sql);
            $locations = $result->fetchAllAssociative();

            $this->logger->info('Kundenstandorte geladen', ['count' => count($locations)]);
            
            return $locations;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Laden der Kundenstandorte', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function saveCalendarEntry(array $vehicle, array $location, float $distance, string $calendarEventId): bool
    {
        try {
            $sql = "INSERT INTO calendar_entries 
                    (vehicle_id, vehicle_name, customer_location_id, customer_name, 
                     distance_meters, calendar_event_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";

            $this->connection->executeStatement($sql, [
                $vehicle['id'],
                $vehicle['name'],
                $location['id'],
                $location['customer_name'],
                $distance,
                $calendarEventId
            ]);

            $this->logger->info('Kalendereintrag in Datenbank gespeichert', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'distance' => $distance
            ]);

            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Speichern des Kalendereintrags', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function hasRecentCalendarEntry(array $vehicle, array $location, int $hoursBack = 24): bool
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM calendar_entries 
                    WHERE vehicle_id = ? 
                    AND customer_location_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";

            $result = $this->connection->executeQuery($sql, [
                $vehicle['id'],
                $location['id'],
                $hoursBack
            ]);

            $count = $result->fetchOne();
            return $count > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Duplikatsprüfung', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function createTables(): void
    {
        $this->createCustomerLocationsTable();
        $this->createCalendarEntriesTable();
    }

    private function createCustomerLocationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS customer_locations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            customer_name VARCHAR(255) NOT NULL,
            address VARCHAR(500) NOT NULL,
            latitude DECIMAL(10, 8) NOT NULL,
            longitude DECIMAL(11, 8) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_coordinates (latitude, longitude),
            INDEX idx_customer (customer_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->connection->executeStatement($sql);
    }

    private function createCalendarEntriesTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS calendar_entries (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id VARCHAR(255) NOT NULL,
            vehicle_name VARCHAR(255) NOT NULL,
            customer_location_id INT NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            distance_meters DECIMAL(8, 2) NOT NULL,
            calendar_event_id VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vehicle_customer (vehicle_id, customer_location_id),
            INDEX idx_created (created_at),
            FOREIGN KEY (customer_location_id) REFERENCES customer_locations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->connection->executeStatement($sql);
    }
}
