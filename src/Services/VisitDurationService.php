<?php

namespace PajGpsCalendar\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;

/**
 * Service für intelligente Aufenthaltsdauer-Erkennung
 * 
 * Verhindert Kalendereinträge für kurze "Vorbeifahrten" durch:
 * - Speicherung von Zwischenpositionen
 * - Berechnung der tatsächlichen Aufenthaltsdauer
 * - Mindestaufenthaltsdauer vor Kalendereintrag
 */
class VisitDurationService
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ConfigService $config;
    private int $minimumDurationMinutes;
    private int $checkIntervalMinutes;

    public function __construct(ConfigService $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->minimumDurationMinutes = $config->get('settings.minimum_visit_duration_minutes', 5);
        $this->checkIntervalMinutes = $config->get('settings.check_interval_minutes', 3);
        
        $this->initializeConnection();
        $this->createTables();
    }

    private function initializeConnection(): void
    {
        // Verwende Tracking-DB falls konfiguriert, sonst temporäre lokale DB
        $trackingConfig = $this->config->get('tracking_database');
        
        if ($trackingConfig) {
            $connectionParams = [
                'dbname' => $trackingConfig['database'],
                'user' => $trackingConfig['username'],
                'password' => $trackingConfig['password'],
                'host' => $trackingConfig['host'],
                'port' => $trackingConfig['port'],
                'driver' => 'pdo_mysql',
            ];
        } else {
            // Fallback: Verwende CRM-Datenbank für Tracking (temporäre Tabellen)
            $crmConfig = $this->config->get('crm.database');
            $connectionParams = [
                'dbname' => $crmConfig['database'],
                'user' => $crmConfig['username'],
                'password' => $crmConfig['password'],
                'host' => $crmConfig['host'],
                'port' => $crmConfig['port'],
                'driver' => 'pdo_mysql',
            ];
        }

        $this->connection = DriverManager::getConnection($connectionParams);
    }

    private function createTables(): void
    {
        // Tabelle für Positions-Tracking (MySQL-kompatibel)
        $sql = "CREATE TABLE IF NOT EXISTS position_tracking (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id VARCHAR(255) NOT NULL,
            vehicle_name VARCHAR(255) NOT NULL,
            customer_id VARCHAR(255) NULL,
            customer_name VARCHAR(255) NULL,
            vehicle_latitude DECIMAL(10, 8) NOT NULL,
            vehicle_longitude DECIMAL(11, 8) NOT NULL,
            customer_latitude DECIMAL(10, 8) NULL,
            customer_longitude DECIMAL(11, 8) NULL,
            distance_meters DECIMAL(8, 2) NULL,
            position_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vehicle_time (vehicle_id, position_time),
            INDEX idx_customer_time (customer_id, position_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->connection->executeStatement($sql);

        // Tabelle für bestätigte Besuche (nach Mindestaufenthaltsdauer)
        $sql2 = "CREATE TABLE IF NOT EXISTS confirmed_visits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id VARCHAR(255) NOT NULL,
            customer_id VARCHAR(255) NOT NULL,
            start_time TIMESTAMP NOT NULL,
            end_time TIMESTAMP NULL,
            duration_minutes INT DEFAULT 0,
            calendar_created BOOLEAN DEFAULT 0,
            calendar_event_id VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vehicle_customer (vehicle_id, customer_id),
            INDEX idx_start_time (start_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->connection->executeStatement($sql2);
    }

    /**
     * Speichert aktuelle Position und prüft Aufenthaltsdauer
     */
    public function trackPosition(array $vehicle, ?array $customer = null): array
    {
        $this->logger->debug('Tracking Position', [
            'vehicle' => $vehicle['name'],
            'customer' => $customer ? $customer['customer_name'] : 'none',
            'lat' => $vehicle['latitude'],
            'lng' => $vehicle['longitude']
        ]);

        // Aktuelle Position speichern
        $this->savePosition($vehicle, $customer);
        
        // Prüfe Aufenthaltsdauer für alle aktuellen Kunden-Nähen
        $result = [
            'should_create_calendar_entry' => false,
            'visit_duration_minutes' => 0,
            'reason' => ''
        ];

        if ($customer) {
            $duration = $this->calculateVisitDuration($vehicle['id'], $customer['customer_id']);
            $result['visit_duration_minutes'] = $duration;
            
            if ($duration >= $this->minimumDurationMinutes) {
                $result['should_create_calendar_entry'] = true;
                $result['reason'] = sprintf(
                    'Aufenthaltsdauer %d Min >= Minimum %d Min', 
                    $duration, 
                    $this->minimumDurationMinutes
                );
            } else {
                $result['reason'] = sprintf(
                    'Aufenthaltsdauer %d Min < Minimum %d Min - warte noch %d Min', 
                    $duration, 
                    $this->minimumDurationMinutes,
                    $this->minimumDurationMinutes - $duration
                );
            }
        }

        // Cleanup alter Einträge
        $this->cleanupOldEntries();
        
        return $result;
    }

    /**
     * Speichert aktuelle Position
     */
    private function savePosition(array $vehicle, ?array $customer = null): void
    {
        $sql = "INSERT INTO position_tracking 
                (vehicle_id, vehicle_name, customer_id, customer_name, 
                 vehicle_latitude, vehicle_longitude, customer_latitude, customer_longitude, 
                 distance_meters, position_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . 
                ($this->connection->getDatabasePlatform()->getName() === 'mysql' ? 'NOW()' : 'datetime("now")') . ")";

        $this->connection->executeStatement($sql, [
            $vehicle['id'],
            $vehicle['name'],
            $customer ? $customer['customer_id'] : null,
            $customer ? $customer['customer_name'] : null,
            $vehicle['latitude'],
            $vehicle['longitude'],
            $customer ? $customer['latitude'] : null,
            $customer ? $customer['longitude'] : null,
            $customer ? $customer['distance_meters'] ?? 0 : null
        ]);
    }

    /**
     * Berechnet wie lange ein Fahrzeug bereits bei einem Kunden ist
     */
    private function calculateVisitDuration(string $vehicleId, string $customerId): int
    {
        // Finde erste Position bei diesem Kunden in den letzten 2 Stunden
        $sql = "SELECT MIN(position_time) as first_position 
                FROM position_tracking 
                WHERE vehicle_id = ? 
                AND customer_id = ? 
                AND position_time >= " . 
                ($this->connection->getDatabasePlatform()->getName() === 'mysql' 
                    ? 'DATE_SUB(NOW(), INTERVAL 2 HOUR)' 
                    : 'datetime("now", "-2 hours")');

        $result = $this->connection->executeQuery($sql, [$vehicleId, $customerId]);
        $firstPosition = $result->fetchOne();

        if (!$firstPosition) {
            return 0;
        }

        // Berechne Dauer bis jetzt
        $firstTime = new \DateTime($firstPosition);
        $now = new \DateTime();
        
        return (int) round(($now->getTimestamp() - $firstTime->getTimestamp()) / 60);
    }

    /**
     * Markiert Besuch als bestätigt (Kalendereintrag erstellt)
     */
    public function confirmVisit(string $vehicleId, string $customerId, string $calendarEventId): void
    {
        // Prüfe ob bereits bestätigt
        $checkSql = "SELECT id FROM confirmed_visits 
                     WHERE vehicle_id = ? AND customer_id = ? 
                     AND end_time IS NULL";
        
        $existing = $this->connection->executeQuery($checkSql, [$vehicleId, $customerId])->fetchOne();
        
        if ($existing) {
            // Update bestehenden Eintrag
            $updateSql = "UPDATE confirmed_visits 
                         SET calendar_created = 1, calendar_event_id = ? 
                         WHERE id = ?";
            $this->connection->executeStatement($updateSql, [$calendarEventId, $existing]);
        } else {
            // Erstelle neuen bestätigten Besuch
            $duration = $this->calculateVisitDuration($vehicleId, $customerId);
            
            $insertSql = "INSERT INTO confirmed_visits 
                         (vehicle_id, customer_id, start_time, duration_minutes, 
                          calendar_created, calendar_event_id) 
                         VALUES (?, ?, " . 
                         ($this->connection->getDatabasePlatform()->getName() === 'mysql' 
                             ? 'DATE_SUB(NOW(), INTERVAL ? MINUTE)' 
                             : 'datetime("now", "-" || ? || " minutes")') . ", ?, 1, ?)";
            
            $this->connection->executeStatement($insertSql, [
                $vehicleId, 
                $customerId, 
                $duration, 
                $duration, 
                $calendarEventId
            ]);
        }

        $this->logger->info('Besuch bestätigt', [
            'vehicle' => $vehicleId,
            'customer' => $customerId,
            'calendar_event' => $calendarEventId
        ]);
    }

    /**
     * Markiert Besuch als beendet wenn Fahrzeug sich entfernt
     */
    public function endVisit(string $vehicleId, string $customerId): void
    {
        $sql = "UPDATE confirmed_visits 
                SET end_time = " . 
                ($this->connection->getDatabasePlatform()->getName() === 'mysql' ? 'NOW()' : 'datetime("now")') . ",
                    duration_minutes = " . 
                ($this->connection->getDatabasePlatform()->getName() === 'mysql' 
                    ? 'TIMESTAMPDIFF(MINUTE, start_time, NOW())' 
                    : 'cast((julianday("now") - julianday(start_time)) * 24 * 60 as integer)') . "
                WHERE vehicle_id = ? AND customer_id = ? AND end_time IS NULL";

        $this->connection->executeStatement($sql, [$vehicleId, $customerId]);
        
        $this->logger->info('Besuch beendet', [
            'vehicle' => $vehicleId,
            'customer' => $customerId
        ]);
    }

    /**
     * Räumt alte Tracking-Einträge auf
     */
    private function cleanupOldEntries(): void
    {
        $maxEntries = $this->config->get('visit_tracking.max_tracking_entries', 1000);
        
        // Lösche Einträge älter als 24 Stunden
        $sql = "DELETE FROM position_tracking 
                WHERE position_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        $this->connection->executeStatement($sql);

        // Begrenze Anzahl der Einträge (MySQL-kompatibel)
        $countSql = "SELECT COUNT(*) FROM position_tracking";
        $count = $this->connection->executeQuery($countSql)->fetchOne();
        
        if ($count > $maxEntries) {
            $deleteSql = "DELETE FROM position_tracking 
                         ORDER BY position_time ASC 
                         LIMIT " . ($count - $maxEntries);
            $this->connection->executeStatement($deleteSql);
        }
    }

    /**
     * Holt Statistiken über aktuelle Besuche
     */
    public function getCurrentVisitStatistics(): array
    {
        $sql = "SELECT 
                    vehicle_id,
                    customer_id,
                    customer_name,
                    MIN(position_time) as visit_start,
                    MAX(position_time) as last_seen,
                    COUNT(*) as position_count,
                    AVG(distance_meters) as avg_distance
                FROM position_tracking 
                WHERE customer_id IS NOT NULL 
                AND position_time >= " . 
                ($this->connection->getDatabasePlatform()->getName() === 'mysql' 
                    ? 'DATE_SUB(NOW(), INTERVAL 2 HOUR)' 
                    : 'datetime("now", "-2 hours")') . "
                GROUP BY vehicle_id, customer_id
                ORDER BY visit_start";

        $result = $this->connection->executeQuery($sql);
        return $result->fetchAllAssociative();
    }

    /**
     * Prüft ob ein Besuch bereits bestätigt wurde
     */
    public function isVisitAlreadyConfirmed(string $vehicleId, string $customerId): bool
    {
        $sql = "SELECT COUNT(*) FROM confirmed_visits 
                WHERE vehicle_id = ? AND customer_id = ? 
                AND calendar_created = 1 
                AND start_time >= " . 
                ($this->connection->getDatabasePlatform()->getName() === 'mysql' 
                    ? 'DATE_SUB(NOW(), INTERVAL 24 HOUR)' 
                    : 'datetime("now", "-24 hours")');

        $count = $this->connection->executeQuery($sql, [$vehicleId, $customerId])->fetchOne();
        return $count > 0;
    }

    /**
     * Holt Besuchsinformationen für Kalender-Update
     */
    public function getVisitInfo(string $vehicleId, string $customerId): ?array
    {
        try {
            $sql = "SELECT cv.*, pt.vehicle_latitude, pt.vehicle_longitude, pt.distance_meters
                    FROM confirmed_visits cv
                    LEFT JOIN position_tracking pt ON (
                        cv.vehicle_id = pt.vehicle_id 
                        AND cv.customer_id = pt.customer_id
                        AND pt.position_time >= cv.start_time
                        ORDER BY pt.position_time LIMIT 1
                    )
                    WHERE cv.vehicle_id = ? AND cv.customer_id = ? 
                    AND cv.end_time IS NULL 
                    ORDER BY cv.start_time DESC 
                    LIMIT 1";

            $result = $this->connection->executeQuery($sql, [$vehicleId, $customerId]);
            $visit = $result->fetchAssociative();
            
            if ($visit) {
                return [
                    'start_time' => $visit['start_time'],
                    'calendar_event_id' => $visit['calendar_event_id'],
                    'vehicle_latitude' => $visit['vehicle_latitude'],
                    'vehicle_longitude' => $visit['vehicle_longitude'],
                    'distance_meters' => $visit['distance_meters'] ?? 0
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Besuchsinfo', [
                'vehicle' => $vehicleId,
                'customer' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
