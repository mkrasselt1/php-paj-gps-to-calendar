<?php

namespace PajGpsCalendar\Services;

use GuzzleHttp\Client;
use Monolog\Logger;

class PajApiService
{
    private ConfigService $config;
    private Logger $logger;
    private Client $httpClient;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    public function __construct(ConfigService $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = new Client([
            'base_uri' => $this->config->get('paj_api.base_url'),
            'timeout' => 30,
        ]);
    }

    public function authenticate(): bool
    {
        try {
            // PAJ API verwendet query-Parameter statt JSON body
            $response = $this->httpClient->post('/api/v1/login', [
                'query' => [
                    'email' => $this->config->get('paj_api.email'),
                    'password' => $this->config->get('paj_api.password'),
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // PAJ API Antwortformat: {"success": {"token": "...", "refresh_token": "...", "userID": 1, "routeIcon": "circle"}}
            if (isset($data['success']['token'])) {
                $this->accessToken = $data['success']['token'];
                $this->refreshToken = $data['success']['refresh_token'] ?? null;
                $this->logger->info('PAJ API Authentifizierung erfolgreich', [
                    'userID' => $data['success']['userID'] ?? null
                ]);
                return true;
            }
            
            throw new \Exception('Kein Access Token erhalten: ' . json_encode($data));
            
        } catch (\Exception $e) {
            $this->logger->error('PAJ API Authentifizierung fehlgeschlagen', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getVehicles(): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            // PAJ API: Hole erst die verfügbaren Geräte
            $response = $this->httpClient->get('/api/v1/device', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $vehicles = [];
            
            // PAJ API Antwortformat: {"success": [array of devices], "number_of_records": n}
            $devices = $data['success'] ?? [];
            if (!is_array($devices)) {
                $devices = [$devices];
            }
            
            foreach ($devices as $device) {
                // Hole aktuelle Position für jedes Gerät
                $position = $this->getDeviceLastPosition((string)$device['id']);
                
                $vehicles[] = [
                    'id' => (string)$device['id'],
                    'name' => $device['name'] ?? 'Device ' . $device['id'],
                    'imei' => $device['imei'] ?? '',
                    'latitude' => $position['lat'] ?? null,
                    'longitude' => $position['lng'] ?? null,
                    'timestamp' => $position['dateunix'] ?? null,
                    'speed' => $position['speed'] ?? 0,
                    'battery' => $position['battery'] ?? null,
                    'battery_percent' => $position['battery'] ?? null, // Alias für Kompatibilität
                    'direction' => $position['direction'] ?? null,
                    // ✨ ERWEITERTE PAJ-DATEN
                    'is_stopped' => $position['is_stopped'] ?? false,
                    'engine_running' => $position['engine_running'] ?? null,
                    'stop_duration_minutes' => $position['stop_duration_minutes'] ?? 0,
                    'raw_position_data' => $position['raw_data'] ?? null,
                ];
            }
            
            $this->logger->info('Fahrzeugdaten abgerufen', ['count' => count($vehicles)]);
            return $vehicles;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Fahrzeugdaten', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getDeviceLastPosition(string $deviceId): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            // PAJ API: Hole die letzten Tracking-Daten (letzte Position)
            $response = $this->httpClient->get("/api/v1/trackerdata/{$deviceId}/last_points", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'query' => [
                    'lastPoints' => 1,
                    'gps' => 1
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // PAJ API Antwortformat: {"success": [array of positions], ...}
            $positions = $data['success'] ?? [];
            
            if (empty($positions) || !is_array($positions)) {
                return [
                    'lat' => null,
                    'lng' => null,
                    'dateunix' => null,
                    'speed' => 0,
                    'battery' => null,
                    'direction' => null,
                    'is_stopped' => false,
                    'engine_running' => null,
                    'stop_duration_minutes' => 0,
                ];
            }
            
            $position = $positions[0]; // Erstes Element ist die neueste Position
            
            // ✨ ERWEITERTE ANALYSE
            $speed = $position['speed'] ?? 0;
            $battery = $position['battery'] ?? null;
            $timestamp = $position['dateunix'] ?? null;
            
            // Motor-Status basierend auf Geschwindigkeit und Batterie ableiten
            $isStopped = $speed < 2; // Unter 2 km/h = stehend
            $engineRunning = $this->detectEngineStatus($speed, $battery, $timestamp);
            $stopDuration = $this->calculateStopDuration($deviceId, $timestamp, $speed);
            
            return [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
                'dateunix' => $timestamp,
                'speed' => $speed,
                'battery' => $battery,
                'direction' => $position['direction'] ?? null,
                'is_stopped' => $isStopped,
                'engine_running' => $engineRunning,
                'stop_duration_minutes' => $stopDuration,
                'raw_data' => $position, // Vollständige PAJ Daten für Debug
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Geräteposition', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Erkennt Motor-Status basierend auf Geschwindigkeit, Batterie und weiteren Faktoren
     */
    private function detectEngineStatus(?float $speed, ?int $battery, ?int $timestamp): ?bool
    {
        // Wenn keine Daten vorhanden
        if ($speed === null || $battery === null) {
            return null;
        }
        
        // Eindeutige Fälle
        if ($speed > 5) {
            return true; // Motor läuft definitiv wenn Geschwindigkeit > 5 km/h
        }
        
        if ($speed < 1) {
            // Fahrzeug steht - Motor-Status anhand Batterie ableiten
            
            // Hohe Batteriespannung deutet auf laufenden Motor hin
            if ($battery > 85) {
                return true; // Motor läuft (lädt Batterie)
            }
            
            // Niedrige Batteriespannung deutet auf ausgeschalteten Motor hin
            if ($battery < 50) {
                return false; // Motor aus
            }
            
            // Mittlere Batteriespannung - unklarer Status
            // Könnte Motor aus sein oder Standheizung/Klimaanlage
            return null; // Unbekannt
        }
        
        // Geringe Bewegung (1-5 km/h) - Motor wahrscheinlich an
        return true;
    }

    /**
     * Berechnet wie lange ein Fahrzeug bereits steht
     */
    private function calculateStopDuration(string $deviceId, ?int $currentTimestamp, ?float $currentSpeed): int
    {
        if (!$currentTimestamp || $currentSpeed === null || $currentSpeed >= 2) {
            return 0; // Fahrzeug bewegt sich
        }
        
        try {
            // Hole die letzten 10 Positionen um Stopp-Beginn zu finden
            $response = $this->httpClient->get("/api/v1/trackerdata/{$deviceId}/last_points", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'query' => [
                    'lastPoints' => 10,
                    'gps' => 1
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $positions = $data['success'] ?? [];
            
            if (empty($positions) || !is_array($positions)) {
                return 0;
            }
            
            // Finde den Zeitpunkt wo das Fahrzeug gestoppt ist
            $stopStartTime = $currentTimestamp;
            
            // Iteriere rückwärts durch die Positionen
            for ($i = 0; $i < count($positions); $i++) {
                $pos = $positions[$i];
                $speed = $pos['speed'] ?? 0;
                
                if ($speed >= 2) {
                    // Hier hat sich das Fahrzeug noch bewegt
                    break;
                }
                
                // Fahrzeug stand auch hier - setze Stopp-Start früher
                $stopStartTime = $pos['dateunix'] ?? $stopStartTime;
            }
            
            // Berechne Stopp-Dauer in Minuten
            $stopDurationSeconds = $currentTimestamp - $stopStartTime;
            return max(0, round($stopDurationSeconds / 60));
            
        } catch (\Exception $e) {
            $this->logger->debug('Fehler beim Berechnen der Stopp-Dauer', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function getVehiclePosition(string $vehicleId): array
    {
        // Wrapper für Rückwärtskompatibilität
        return $this->getDeviceLastPosition($vehicleId);
    }

    public function getVehicleHistory(string $vehicleId, \DateTime $from, \DateTime $to): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            // PAJ API: Hole Tracking-Daten für Zeitraum
            $response = $this->httpClient->get("/api/v1/trackerdata/{$vehicleId}/date_range", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'query' => [
                    'dateStart' => $from->getTimestamp(),
                    'dateEnd' => $to->getTimestamp(),
                    'gps' => 1,
                    'sort' => 'asc'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $history = [];
            
            // PAJ API Antwortformat: {"success": {...}} oder Array
            $positions = isset($data['success']) ? [$data['success']] : $data;
            if (!is_array($positions)) {
                $positions = [$positions];
            }
            
            foreach ($positions as $position) {
                $history[] = [
                    'latitude' => $position['lat'] ?? null,
                    'longitude' => $position['lng'] ?? null,
                    'timestamp' => isset($position['dateunix']) ? date('Y-m-d H:i:s', $position['dateunix']) : null,
                    'speed' => $position['speed'] ?? 0,
                    'direction' => $position['direction'] ?? null,
                    'battery' => $position['battery'] ?? null,
                    'address' => '', // PAJ API liefert keine Adressen in den Tracking-Daten
                ];
            }
            
            $this->logger->info('Fahrzeughistorie abgerufen', [
                'vehicle_id' => $vehicleId,
                'count' => count($history),
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s')
            ]);
            
            return $history;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Fahrzeughistorie', [
                'vehicle_id' => $vehicleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * ✨ NEUE METHODE: Hole PAJ Kunden/Standorte
     */
    public function getPajCustomers(): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            $response = $this->httpClient->get('/api/v1/customers', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // PAJ API Antwortformat für Kunden
            $customers = $data['success'] ?? [];
            if (!is_array($customers)) {
                $customers = [$customers];
            }
            
            $this->logger->info('PAJ Kunden abgerufen', ['count' => count($customers)]);
            return $customers;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der PAJ Kunden', ['error' => $e->getMessage()]);
            // Return empty array wenn Endpoint nicht existiert (Fallback)
            return [];
        }
    }

    /**
     * ✨ NEUE METHODE: Erstelle/Update PAJ Kunde (CRM → PAJ Sync)
     */
    public function syncCustomerToPaj(array $crmCustomer): bool
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            // Prüfe ob Kunde bereits in PAJ existiert
            $existingCustomers = $this->getPajCustomers();
            $existingCustomer = null;
            
            foreach ($existingCustomers as $customer) {
                if (isset($customer['external_id']) && $customer['external_id'] === $crmCustomer['customer_id']) {
                    $existingCustomer = $customer;
                    break;
                }
            }

            $customerData = [
                'name' => $crmCustomer['customer_name'],
                'address' => $crmCustomer['full_address'] ?? '',
                'latitude' => $crmCustomer['latitude'],
                'longitude' => $crmCustomer['longitude'],
                'external_id' => $crmCustomer['customer_id'], // CRM-ID als Referenz
                'phone' => $crmCustomer['phone'] ?? '',
                'email' => $crmCustomer['email'] ?? '',
                'notes' => $crmCustomer['notes'] ?? '',
            ];

            if ($existingCustomer) {
                // Update bestehenden Kunden
                $response = $this->httpClient->put("/api/v1/customers/{$existingCustomer['id']}", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $customerData
                ]);
                
                $this->logger->info('PAJ Kunde aktualisiert', [
                    'customer_id' => $crmCustomer['customer_id'],
                    'name' => $crmCustomer['customer_name']
                ]);
            } else {
                // Erstelle neuen Kunden
                $response = $this->httpClient->post('/api/v1/customers', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $customerData
                ]);
                
                $this->logger->info('PAJ Kunde erstellt', [
                    'customer_id' => $crmCustomer['customer_id'],
                    'name' => $crmCustomer['customer_name']
                ]);
            }

            return $response->getStatusCode() < 300;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Sync des Kunden zu PAJ', [
                'customer_id' => $crmCustomer['customer_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * ✨ VERBESSERTE STOPP-ERKENNUNG: Verwende PAJ last_minutes API
     */
    public function getDetailedStopAnalysis(string $deviceId, int $minutesBack = 30): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            // PAJ API: Hole detaillierte Tracking-Daten der letzten X Minuten
            $response = $this->httpClient->get("/api/v1/trackerdata/{$deviceId}/last_minutes", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'query' => [
                    'lastMinutes' => $minutesBack,  // Korrigierter Parameter
                    'gps' => 1
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $positions = $data['success'] ?? [];
            
            if (empty($positions) || !is_array($positions)) {
                $this->logger->debug('Keine PAJ last_minutes Daten erhalten', [
                    'device_id' => $deviceId,
                    'response' => $data
                ]);
                return [
                    'is_stopped' => false,
                    'stop_duration_minutes' => 0,
                    'engine_running' => null,
                    'movement_detected' => false,
                    'battery_trend' => 'unknown',
                    'position_count' => 0
                ];
            }

            return $this->analyzeMovementPattern($positions);
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der detaillierten Stopp-Analyse', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return [
                'is_stopped' => false,
                'stop_duration_minutes' => 0,
                'engine_running' => null,
                'movement_detected' => false,
                'battery_trend' => 'unknown',
                'position_count' => 0
            ];
        }
    }

    /**
     * Analysiert Bewegungsmuster basierend auf PAJ Tracking-Daten
     */
    private function analyzeMovementPattern(array $positions): array
    {
        if (empty($positions)) {
            return [
                'is_stopped' => false,
                'stop_duration_minutes' => 0,
                'engine_running' => null,
                'movement_detected' => false,
                'battery_trend' => 'unknown',
                'position_count' => 0
            ];
        }

        $positionCount = count($positions);
        $latestPosition = $positions[0]; // Neueste Position zuerst
        $oldestPosition = $positions[$positionCount - 1];

        // Bewegungsanalyse
        $totalMovement = 0;
        $speedValues = [];
        $batteryValues = [];
        $stopStartTime = null;

        for ($i = 0; $i < $positionCount - 1; $i++) {
            $pos1 = $positions[$i];
            $pos2 = $positions[$i + 1];
            
            // GPS-Distanz berechnen (einfache Approximation)
            $latDiff = abs($pos1['lat'] - $pos2['lat']);
            $lngDiff = abs($pos1['lng'] - $pos2['lng']);
            $distance = sqrt($latDiff * $latDiff + $lngDiff * $lngDiff) * 111000; // Ungefähr in Metern
            
            $totalMovement += $distance;
            
            if (isset($pos1['speed'])) {
                $speedValues[] = $pos1['speed'];
            }
            if (isset($pos1['battery'])) {
                $batteryValues[] = $pos1['battery'];
            }
            
            // Finde Stopp-Beginn
            if (($pos1['speed'] ?? 0) < 2 && $stopStartTime === null) {
                $stopStartTime = $pos1['dateunix'] ?? null;
            }
        }

        // Stopp-Dauer berechnen
        $currentTime = time();
        $stopDurationMinutes = 0;
        if ($stopStartTime && ($latestPosition['speed'] ?? 0) < 2) {
            $stopDurationMinutes = max(0, round(($currentTime - $stopStartTime) / 60));
        }

        // Bewegung erkannt?
        $movementDetected = $totalMovement > 50; // Mehr als 50m Bewegung
        $isStopped = !$movementDetected && (($latestPosition['speed'] ?? 0) < 2);

        // Motor-Status ableiten
        $avgSpeed = !empty($speedValues) ? array_sum($speedValues) / count($speedValues) : 0;
        $currentBattery = $latestPosition['battery'] ?? null;
        $batteryTrend = $this->analyzeBatteryTrend($batteryValues);
        
        $engineRunning = null;
        if ($avgSpeed > 5) {
            $engineRunning = true;
        } elseif ($isStopped && $currentBattery !== null) {
            if ($batteryTrend === 'increasing' || $currentBattery > 85) {
                $engineRunning = true; // Motor läuft, lädt Batterie
            } elseif ($batteryTrend === 'decreasing' && $currentBattery < 60) {
                $engineRunning = false; // Motor aus, Batterie entlädt sich
            }
        }

        return [
            'is_stopped' => $isStopped,
            'stop_duration_minutes' => $stopDurationMinutes,
            'engine_running' => $engineRunning,
            'movement_detected' => $movementDetected,
            'battery_trend' => $batteryTrend,
            'position_count' => $positionCount,
            'total_movement_meters' => round($totalMovement, 2),
            'average_speed' => round($avgSpeed, 1),
            'current_battery' => $currentBattery
        ];
    }

    /**
     * Analysiert Batterie-Trend (steigend/fallend)
     */
    private function analyzeBatteryTrend(array $batteryValues): string
    {
        if (count($batteryValues) < 3) {
            return 'unknown';
        }

        $first = array_slice($batteryValues, 0, 3);
        $last = array_slice($batteryValues, -3);
        
        $firstAvg = array_sum($first) / count($first);
        $lastAvg = array_sum($last) / count($last);
        
        if ($lastAvg > $firstAvg + 2) {
            return 'increasing';
        } elseif ($lastAvg < $firstAvg - 2) {
            return 'decreasing';
        }
        
        return 'stable';
    }
}
