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
     * ÖFFENTLICHE Methode zur Berechnung der Stopp-Dauer (für CheckCommand)
     */
    public function getStopDuration(string $deviceId, ?int $currentTimestamp, ?float $currentSpeed): int
    {
        return $this->calculateStopDuration($deviceId, $currentTimestamp, $currentSpeed);
    }

    /**
     * Berechnet wie lange ein Fahrzeug bereits steht - KORRIGIERTE VERSION
     */
    private function calculateStopDuration(string $deviceId, ?int $currentTimestamp, ?float $currentSpeed): int
    {
        if (!$currentTimestamp || $currentSpeed === null || $currentSpeed >= 5) {
            return 0; // Fahrzeug bewegt sich deutlich (>5 km/h)
        }

        try {
            // Hole die letzten 100 Positionen für bessere Standzeit-Erkennung
            $response = $this->httpClient->get("/api/v1/trackerdata/{$deviceId}/last_points", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'query' => [
                    'lastPoints' => 100,  // Mehr Datenpunkte für genauere Analyse
                    'gps' => 1
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $positions = $data['success'] ?? [];

            if (empty($positions) || !is_array($positions)) {
                return 0;
            }

            $this->logger->debug('Stopp-Dauer Analyse', [
                'device_id' => $deviceId,
                'positions_count' => count($positions),
                'current_speed' => $currentSpeed
            ]);

            // PAJ API gibt Positionen in umgekehrter Reihenfolge (neueste zuerst)
            // Wir müssen vom aktuellen Zeitpunkt rückwärts gehen

            $stopStartTime = $currentTimestamp;
            $minSpeedThreshold = 1.0; // 1 km/h als Grenze für "stehend"
            $maxDriftDistance = 50; // 50 Meter Drift ist noch akzeptabel

            // Referenzposition (aktuellste Position)
            $referenceLat = $positions[0]['lat'] ?? null;
            $referenceLon = $positions[0]['lng'] ?? null;

            if ($referenceLat === null || $referenceLon === null) {
                return 0;
            }

            // Gehe rückwärts durch die Positionen
            for ($i = 0; $i < count($positions); $i++) {
                $pos = $positions[$i];
                $speed = $pos['speed'] ?? 0;
                $timestamp = $pos['dateunix'] ?? null;
                $lat = $pos['lat'] ?? null;
                $lon = $pos['lng'] ?? null;

                if ($timestamp === null || $lat === null || $lon === null) {
                    continue;
                }

                // Prüfe ob Position noch in akzeptablem Drift-Bereich
                $distance = $this->calculateDistance($referenceLat, $referenceLon, $lat, $lon);

                // Prüfe ob Fahrzeug noch als "stehend" gilt
                $isStillStanding = $speed < $minSpeedThreshold && $distance < $maxDriftDistance;

                if ($isStillStanding) {
                    // Fahrzeug steht noch - erweitere Stopp-Zeit
                    $stopStartTime = $timestamp;
                    $this->logger->debug('Stopp-Zeit erweitert', [
                        'index' => $i,
                        'timestamp' => date('H:i:s', $timestamp),
                        'speed' => $speed,
                        'distance' => round($distance, 1)
                    ]);
                } else {
                    // Fahrzeug hat sich bewegt - Stopp endet hier
                    $this->logger->debug('Stopp beendet durch Bewegung', [
                        'index' => $i,
                        'timestamp' => date('H:i:s', $timestamp),
                        'speed' => $speed,
                        'distance' => round($distance, 1)
                    ]);
                    break;
                }
            }

            // Berechne Stopp-Dauer in Minuten
            $stopDurationSeconds = $currentTimestamp - $stopStartTime;
            $stopDurationMinutes = round($stopDurationSeconds / 60);

            $this->logger->debug('Stopp-Dauer berechnet', [
                'device_id' => $deviceId,
                'stop_duration_minutes' => $stopDurationMinutes,
                'stop_start' => date('H:i:s', $stopStartTime),
                'current_time' => date('H:i:s', $currentTimestamp)
            ]);

            return max(0, $stopDurationMinutes);

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Berechnen der Stopp-Dauer', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }    public function getVehiclePosition(string $vehicleId): array
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
     * Holt historische Tracking-Daten für ein Fahrzeug
     */
    public function getHistoricalTrackingData(string $deviceId, int $dateStart, int $dateEnd, bool $includeGps = true, bool $includeWifi = false, string $sort = 'desc'): array
    {
        try {
            if (!$this->accessToken) {
                if (!$this->authenticate()) {
                    throw new \Exception('PAJ API Authentifizierung fehlgeschlagen');
                }
            }

            $response = $this->httpClient->get("/api/v1/trackerdata/{$deviceId}/date_range", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'dateStart' => (string)$dateStart,
                    'dateEnd' => (string)$dateEnd,
                    'gps' => $includeGps ? '1' : '0',
                    'wifi' => $includeWifi ? '1' : '0',
                    'sort' => $sort
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['success'])) {
                $this->logger->info('Historische Tracking-Daten abgerufen', [
                    'device_id' => $deviceId,
                    'date_start' => date('Y-m-d H:i:s', $dateStart),
                    'date_end' => date('Y-m-d H:i:s', $dateEnd),
                    'data_points' => count($data['success'])
                ]);
                
                return $data['success'];
            }
            
            throw new \Exception('Keine historischen Daten erhalten: ' . json_encode($data));
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen historischer Tracking-Daten', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Analysiert historische Daten um Besuche zu identifizieren - basiert auf Zeitlücken zwischen GPS-Punkten
     */
    public function analyzeHistoricalVisits(string $deviceId, int $dateStart, int $dateEnd, int $minimumStopDurationMinutes = 2): array
    {
        $this->logger->info('Starte historische Besuchsanalyse (Zeitlücken-basiert)', [
            'device_id' => $deviceId,
            'date_start' => date('Y-m-d H:i:s', $dateStart),
            'date_end' => date('Y-m-d H:i:s', $dateEnd),
            'minimum_stop_duration_minutes' => $minimumStopDurationMinutes
        ]);
        
        $trackingData = $this->getHistoricalTrackingData($deviceId, $dateStart, $dateEnd, true, false, 'asc');
        
        $this->logger->info('Historische Tracking-Daten geladen', [
            'device_id' => $deviceId,
            'data_points' => count($trackingData)
        ]);
        
        if (empty($trackingData)) {
            $this->logger->warning('Keine historischen Tracking-Daten gefunden', [
                'device_id' => $deviceId,
                'date_range' => date('Y-m-d', $dateStart) . ' bis ' . date('Y-m-d', $dateEnd)
            ]);
            return [];
        }
        
        // Sortiere nach Zeitstempel (aufsteigend)
        usort($trackingData, function($a, $b) {
            return ($a['dateunix'] ?? 0) <=> ($b['dateunix'] ?? 0);
        });
        
        $visits = [];
        $normalIntervalSeconds = 60; // PAJ sendet normalerweise alle 60 Sekunden
        $maxNormalGap = $normalIntervalSeconds * 2; // 2 Minuten = normale Toleranz
        $debugCount = 0;
        
        $this->logger->debug('Beginne Zeitlücken-Analyse', [
            'device_id' => $deviceId,
            'total_points' => count($trackingData),
            'normal_interval_seconds' => $normalIntervalSeconds,
            'max_normal_gap_seconds' => $maxNormalGap
        ]);
        
        // Debug: Schaue dir die ersten paar Datenpunkte an
        for ($j = 0; $j < min(5, count($trackingData)); $j++) {
            $point = $trackingData[$j];
            $this->logger->debug('Rohdaten-Beispiel', [
                'index' => $j,
                'timestamp' => $point['dateunix'] ?? 'null',
                'latitude' => $point['lat'] ?? 'null',
                'longitude' => $point['lng'] ?? 'null',
                'speed' => $point['speed'] ?? 'null',
                'formatted_time' => isset($point['dateunix']) ? date('Y-m-d H:i:s', $point['dateunix']) : 'null',
                'raw_point' => array_slice($point, 0, 5) // Nur die ersten 5 Felder zeigen
            ]);
        }
        
        for ($i = 1; $i < count($trackingData); $i++) {
            $prevPoint = $trackingData[$i - 1];
            $currentPoint = $trackingData[$i];
            
            $prevTimestamp = $prevPoint['dateunix'] ?? 0;
            $currentTimestamp = $currentPoint['dateunix'] ?? 0;
            $prevLat = (float)($prevPoint['lat'] ?? 0);
            $prevLon = (float)($prevPoint['lng'] ?? 0);
            $currentLat = (float)($currentPoint['lat'] ?? 0);
            $currentLon = (float)($currentPoint['lng'] ?? 0);
            
            // Überspringe ungültige Datenpunkte
            if ($prevLat == 0 || $prevLon == 0 || $currentLat == 0 || $currentLon == 0) {
                continue;
            }
            
            $timeDifference = $currentTimestamp - $prevTimestamp;
            
            // Debug: Zeige ein paar Beispiele der Zeitintervalle
            if ($debugCount < 10) {
                $this->logger->debug('Zeitintervall-Beispiel', [
                    'point_index' => $i,
                    'time_difference_seconds' => $timeDifference,
                    'time_difference_minutes' => round($timeDifference / 60, 1),
                    'prev_time' => date('H:i:s', $prevTimestamp),
                    'current_time' => date('H:i:s', $currentTimestamp)
                ]);
                $debugCount++;
            }
            
            // Erkenne Zeitlücke = Fahrzeug war längere Zeit an einer Position
            if ($timeDifference > $maxNormalGap) {
                $stopDurationMinutes = $timeDifference / 60;
                
                // Prüfe ob die Stopp-Dauer lang genug ist
                if ($stopDurationMinutes >= $minimumStopDurationMinutes) {
                    // Berechne durchschnittliche Position während des Stopps
                    $avgLat = ($prevLat + $currentLat) / 2;
                    $avgLon = ($prevLon + $currentLon) / 2;
                    
                    // Prüfe ob es wirklich ein Stopp war (Position nicht stark verändert)
                    $distance = $this->calculateDistance($prevLat, $prevLon, $currentLat, $currentLon);
                    
                    // Wenn sich das Fahrzeug weniger als 200m bewegt hat = Stopp
                    if ($distance < 200) {
                        $visits[] = [
                            'device_id' => $deviceId,
                            'start_time' => $prevTimestamp,
                            'end_time' => $currentTimestamp,
                            'duration_minutes' => round($stopDurationMinutes, 1),
                            'latitude' => $avgLat,
                            'longitude' => $avgLon,
                            'start_time_formatted' => date('d.m.Y H:i', $prevTimestamp),
                            'end_time_formatted' => date('d.m.Y H:i', $currentTimestamp),
                            'detection_method' => 'time_gap',
                            'time_gap_seconds' => $timeDifference,
                            'position_drift_meters' => round($distance, 1),
                            'data_points_gap' => $i
                        ];
                        
                        $this->logger->debug('Zeitlücken-Besuch erkannt', [
                            'device_id' => $deviceId,
                            'duration_minutes' => round($stopDurationMinutes, 1),
                            'time_gap_seconds' => $timeDifference,
                            'position_drift_meters' => round($distance, 1),
                            'start_time' => date('d.m.Y H:i', $prevTimestamp)
                        ]);
                    } else {
                        $this->logger->debug('Zeitlücke übersprungen - zu viel Bewegung', [
                            'device_id' => $deviceId,
                            'duration_minutes' => round($stopDurationMinutes, 1),
                            'position_drift_meters' => round($distance, 1),
                            'threshold_meters' => 200
                        ]);
                    }
                }
            }
        }
        
        // Zusätzlich: Erkenne Cluster von GPS-Punkten mit ähnlicher Position
        $clusterVisits = $this->findPositionClusters($trackingData, $minimumStopDurationMinutes, $deviceId);
        
        // KORREKTUR: Verwende nur Cluster-Methode, nicht die fehlerhafte Zeitlücken-Methode
        // NEUE METHODE: Linearer Ansatz (viel einfacher und robuster)
        $linearVisits = $this->findVisitsLinear($trackingData, $minimumStopDurationMinutes, $deviceId);
        
        $this->logger->info('Verwende neuen linearen Ansatz (Cluster-Methode deaktiviert)', [
            'time_gap_visits_ignored' => count($visits),
            'cluster_visits_ignored' => count($clusterVisits),
            'linear_visits_used' => count($linearVisits)
        ]);
        
        // Nur lineare Besuche verwenden
        $uniqueVisits = $linearVisits;
        
        $this->logger->info('Historische Besuchsanalyse abgeschlossen', [
            'device_id' => $deviceId,
            'time_gap_visits' => count($visits),
            'cluster_visits' => count($clusterVisits),
            'total_unique_visits' => count($uniqueVisits),
            'date_range' => date('Y-m-d', $dateStart) . ' bis ' . date('Y-m-d', $dateEnd)
        ]);
        
        return $uniqueVisits;
    }
    
    /**
     * Analysiert Bewegungsmuster in GPS-Daten
     */
    private function analyzeMovementPattern(array $positions): array
    {
        if (empty($positions)) {
            return [
                'pattern_type' => 'unknown',
                'movement_detected' => false,
                'battery_trend' => 'unknown',
                'position_count' => 0
            ];
        }
        
        $positionCount = count($positions);
        $movementDetected = false;
        $totalDistance = 0;
        $avgSpeed = 0;
        
        // Berechne Gesamtstrecke und Geschwindigkeiten
        for ($i = 1; $i < $positionCount; $i++) {
            $prev = $positions[$i - 1];
            $curr = $positions[$i];
            
            $distance = $this->calculateDistance(
                $prev['latitude'], $prev['longitude'],
                $curr['latitude'], $curr['longitude']
            );
            
            $totalDistance += $distance;
            
            if ($distance > 10) { // Mehr als 10 Meter Bewegung
                $movementDetected = true;
            }
        }
        
        // Durchschnittsgeschwindigkeit berechnen
        if ($positionCount > 1) {
            $timeSpan = $positions[$positionCount - 1]['timestamp'] - $positions[0]['timestamp'];
            if ($timeSpan > 0) {
                $avgSpeed = ($totalDistance / 1000) / ($timeSpan / 3600); // km/h
            }
        }
        
        // Bewegungsmuster klassifizieren
        $patternType = 'stationary';
        if ($avgSpeed > 5) {
            $patternType = 'moving';
        } elseif ($avgSpeed > 1) {
            $patternType = 'slow_movement';
        }
        
        // Batterie-Trend (vereinfacht)
        $batteryTrend = 'stable';
        if ($positionCount > 2) {
            $firstBattery = $positions[0]['battery'] ?? 100;
            $lastBattery = $positions[$positionCount - 1]['battery'] ?? 100;
            
            if ($lastBattery < $firstBattery - 5) {
                $batteryTrend = 'declining';
            } elseif ($lastBattery > $firstBattery + 5) {
                $batteryTrend = 'charging';
            }
        }
        
        return [
            'pattern_type' => $patternType,
            'movement_detected' => $movementDetected,
            'battery_trend' => $batteryTrend,
            'position_count' => $positionCount,
            'total_distance_meters' => round($totalDistance),
            'avg_speed_kmh' => round($avgSpeed, 2)
        ];
    }
    
    /**
     * Berechnet die Entfernung zwischen zwei GPS-Koordinaten in Metern
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Erdradius in Metern
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    /**
     * Findet Positionscluster in GPS-Daten (korrigierte Methode mit Zeitbasierte Trennung und Cluster-Verschmelzung)
     */
    private function findPositionClusters(array $trackingData, int $minimumStopDurationMinutes, string $deviceId): array
    {
        $visits = [];
        $clusterRadius = 50; // 50 Meter Radius für Position-Cluster
        $maxTimeGapMinutes = 120; // 2 Stunden max Zeitlücke im Cluster
        $clusters = [];
        
        foreach ($trackingData as $point) {
            $timestamp = $point['dateunix'] ?? 0;
            $latitude = (float)($point['lat'] ?? 0);
            $longitude = (float)($point['lng'] ?? 0);
            
            if ($latitude == 0 || $longitude == 0) {
                continue;
            }
            
            // Finde passenden Cluster oder erstelle neuen
            $foundCluster = false;
            foreach ($clusters as &$cluster) {
                $distance = $this->calculateDistance(
                    $cluster['center_lat'], $cluster['center_lon'],
                    $latitude, $longitude
                );
                
                // KORREKTUR: Prüfe auch Zeitabstand zum letzten Punkt im Cluster
                $timeSinceLastPoint = ($timestamp - $cluster['end_time']) / 60; // in Minuten
                
                if ($distance <= $clusterRadius && $timeSinceLastPoint <= $maxTimeGapMinutes) {
                    // Punkt gehört zu diesem Cluster (räumlich UND zeitlich nah)
                    $cluster['points'][] = $point;
                    $cluster['end_time'] = max($cluster['end_time'], $timestamp);
                    $cluster['start_time'] = min($cluster['start_time'], $timestamp);
                    
                    // Aktualisiere Cluster-Zentrum basierend auf allen Punkten
                    $totalLat = 0;
                    $totalLon = 0;
                    foreach ($cluster['points'] as $clusterPoint) {
                        $totalLat += (float)($clusterPoint['lat'] ?? 0);
                        $totalLon += (float)($clusterPoint['lng'] ?? 0);
                    }
                    $cluster['center_lat'] = $totalLat / count($cluster['points']);
                    $cluster['center_lon'] = $totalLon / count($cluster['points']);
                    
                    $foundCluster = true;
                    break;
                }
            }
            
            if (!$foundCluster) {
                // Neuer Cluster (entweder räumlich oder zeitlich getrennt)
                $clusters[] = [
                    'center_lat' => $latitude,
                    'center_lon' => $longitude,
                    'start_time' => $timestamp,
                    'end_time' => $timestamp,
                    'points' => [$point]
                ];
            }
        }
        
        // NEUE FUNKTION: Verschmelze überlappende Cluster
        $this->logger->debug('Cluster vor Verschmelzung', [
            'cluster_count' => count($clusters),
            'device_id' => $deviceId
        ]);
        
        $mergedClusters = $this->mergeOverlappingClusters($clusters, $clusterRadius * 1.5); // 75m für Verschmelzung
        
        $this->logger->debug('Cluster nach Verschmelzung', [
            'original_count' => count($clusters),
            'merged_count' => count($mergedClusters),
            'device_id' => $deviceId
        ]);
        
        // Analysiere verschmolzene Cluster auf Besuche
        foreach ($mergedClusters as $cluster) {
            $durationMinutes = ($cluster['end_time'] - $cluster['start_time']) / 60;
            
            if ($durationMinutes >= $minimumStopDurationMinutes && count($cluster['points']) >= 3) {
                // Verwende bereits berechnetes Zentrum
                $visits[] = [
                    'device_id' => $deviceId,
                    'start_time' => $cluster['start_time'],
                    'end_time' => $cluster['end_time'],
                    'duration_minutes' => round($durationMinutes, 1),
                    'latitude' => $cluster['center_lat'],
                    'longitude' => $cluster['center_lon'],
                    'start_time_formatted' => date('d.m.Y H:i', $cluster['start_time']),
                    'end_time_formatted' => date('d.m.Y H:i', $cluster['end_time']),
                    'detection_method' => 'position_cluster_merged',
                    'cluster_points' => count($cluster['points']),
                    'cluster_radius_meters' => $clusterRadius,
                    'max_time_gap_minutes' => $maxTimeGapMinutes,
                    'merged_from_clusters' => isset($cluster['merged_count']) ? $cluster['merged_count'] : 1
                ];
            }
        }
        
        return $visits;
    }
    
    /**
     * Verschmelzt überlappende Cluster zu einzelnen Besuchen
     */
    private function mergeOverlappingClusters(array $clusters, float $mergeRadius): array
    {
        $this->logger->debug('Starte Cluster-Verschmelzung', [
            'input_clusters' => count($clusters),
            'merge_radius' => $mergeRadius
        ]);
        
        $merged = [];
        $used = [];
        
        foreach ($clusters as $i => $cluster) {
            if (isset($used[$i])) {
                continue; // Cluster bereits verwendet
            }
            
            $mergedCluster = $cluster;
            $mergedCluster['merged_count'] = 1;
            $used[$i] = true;
            
            $this->logger->debug('Prüfe Cluster für Verschmelzung', [
                'cluster_index' => $i,
                'center_lat' => $cluster['center_lat'],
                'center_lon' => $cluster['center_lon'],
                'start_time' => date('H:i', $cluster['start_time']),
                'end_time' => date('H:i', $cluster['end_time'])
            ]);
            
            // Finde alle überlappenden Cluster
            foreach ($clusters as $j => $otherCluster) {
                if ($i === $j || isset($used[$j])) {
                    continue;
                }
                
                $distance = $this->calculateDistance(
                    $cluster['center_lat'], $cluster['center_lon'],
                    $otherCluster['center_lat'], $otherCluster['center_lon']
                );
                
                // Prüfe zeitliche Überlappung oder direkte Nachbarschaft (max 30 Min Lücke)
                $timeGap = min(
                    abs($cluster['start_time'] - $otherCluster['end_time']),
                    abs($otherCluster['start_time'] - $cluster['end_time'])
                ) / 60; // Minuten
                
                $timeOverlap = !(
                    $cluster['end_time'] < $otherCluster['start_time'] || 
                    $otherCluster['end_time'] < $cluster['start_time']
                );
                
                $this->logger->debug('Prüfe Cluster-Nachbarn', [
                    'cluster_a' => $i,
                    'cluster_b' => $j,
                    'distance' => round($distance, 1),
                    'time_gap_minutes' => round($timeGap, 1),
                    'time_overlap' => $timeOverlap,
                    'merge_radius' => $mergeRadius,
                    'should_merge' => ($distance <= $mergeRadius && ($timeOverlap || $timeGap <= 30))
                ]);
                
                if ($distance <= $mergeRadius && ($timeOverlap || $timeGap <= 30)) {
                    // Cluster verschmelzen
                    $this->logger->debug('Verschmelze Cluster', [
                        'cluster_a' => $i,
                        'cluster_b' => $j,
                        'distance' => round($distance, 1),
                        'time_gap' => round($timeGap, 1)
                    ]);
                    
                    $mergedCluster['points'] = array_merge($mergedCluster['points'], $otherCluster['points']);
                    $mergedCluster['start_time'] = min($mergedCluster['start_time'], $otherCluster['start_time']);
                    $mergedCluster['end_time'] = max($mergedCluster['end_time'], $otherCluster['end_time']);
                    $mergedCluster['merged_count']++;
                    
                    // Neues Zentrum berechnen
                    $totalLat = 0;
                    $totalLon = 0;
                    foreach ($mergedCluster['points'] as $point) {
                        $totalLat += (float)($point['lat'] ?? 0);
                        $totalLon += (float)($point['lng'] ?? 0);
                    }
                    $mergedCluster['center_lat'] = $totalLat / count($mergedCluster['points']);
                    $mergedCluster['center_lon'] = $totalLon / count($mergedCluster['points']);
                    
                    $used[$j] = true;
                }
            }
            
            $merged[] = $mergedCluster;
        }
        
        $this->logger->debug('Cluster-Verschmelzung abgeschlossen', [
            'input_clusters' => count($clusters),
            'output_clusters' => count($merged),
            'reduction' => count($clusters) - count($merged)
        ]);
        
        return $merged;
    }
    
    /**
     * 🚀 NEUE EINFACHE METHODE: Lineare Besuchserkennung (ohne komplexes Clustering)
     */
    private function findVisitsLinear(array $trackingData, int $minimumStopDurationMinutes, string $deviceId): array
    {
        $this->logger->info('Starte lineare Besuchsanalyse (einfacher Ansatz)', [
            'device_id' => $deviceId,
            'data_points' => count($trackingData),
            'minimum_duration_minutes' => $minimumStopDurationMinutes
        ]);
        
        if (empty($trackingData)) {
            return [];
        }
        
        // Sortiere nach Zeitstempel
        usort($trackingData, function($a, $b) {
            return ($a['dateunix'] ?? 0) <=> ($b['dateunix'] ?? 0);
        });
        
        $visits = [];
        $currentStay = null;
        $maxMovementRadius = 75; // 75 Meter = als "am gleichen Ort" betrachtet
        $maxSpeedKmh = 5; // Unter 5 km/h = Stillstand
        
        foreach ($trackingData as $point) {
            $timestamp = $point['dateunix'] ?? 0;
            $latitude = (float)($point['lat'] ?? 0);
            $longitude = (float)($point['lng'] ?? 0);
            $speed = (float)($point['speed'] ?? 0);
            
            if ($latitude == 0 || $longitude == 0) {
                continue;
            }
            
            // Ist das Fahrzeug in Bewegung?
            $isMoving = $speed > $maxSpeedKmh;
            
            if ($currentStay === null) {
                // Ersten Aufenthalt starten (wenn nicht in Bewegung)
                if (!$isMoving) {
                    $currentStay = [
                        'start_time' => $timestamp,
                        'end_time' => $timestamp,
                        'points' => [$point],
                        'center_lat' => $latitude,
                        'center_lon' => $longitude
                    ];
                }
            } else {
                // Prüfe ob Punkt zum aktuellen Aufenthalt gehört
                $distance = $this->calculateDistance(
                    $currentStay['center_lat'], $currentStay['center_lon'],
                    $latitude, $longitude
                );
                
                $timeGapMinutes = ($timestamp - $currentStay['end_time']) / 60;
                
                if (!$isMoving && $distance <= $maxMovementRadius && $timeGapMinutes <= 60) {
                    // Punkt gehört zum aktuellen Aufenthalt
                    $currentStay['end_time'] = $timestamp;
                    $currentStay['points'][] = $point;
                    
                    // Zentrum neu berechnen (gewichteter Durchschnitt)
                    $totalLat = 0;
                    $totalLon = 0;
                    foreach ($currentStay['points'] as $stayPoint) {
                        $totalLat += (float)($stayPoint['lat'] ?? 0);
                        $totalLon += (float)($stayPoint['lng'] ?? 0);
                    }
                    $currentStay['center_lat'] = $totalLat / count($currentStay['points']);
                    $currentStay['center_lon'] = $totalLon / count($currentStay['points']);
                } else {
                    // Aufenthalt beendet - Prüfe ob lang genug für Besuch
                    $durationMinutes = ($currentStay['end_time'] - $currentStay['start_time']) / 60;
                    
                    if ($durationMinutes >= $minimumStopDurationMinutes) {
                        $visits[] = [
                            'device_id' => $deviceId,
                            'start_time' => $currentStay['start_time'],
                            'end_time' => $currentStay['end_time'],
                            'duration_minutes' => round($durationMinutes, 1),
                            'latitude' => $currentStay['center_lat'],
                            'longitude' => $currentStay['center_lon'],
                            'start_time_formatted' => date('d.m.Y H:i', $currentStay['start_time']),
                            'end_time_formatted' => date('d.m.Y H:i', $currentStay['end_time']),
                            'detection_method' => 'linear_simple',
                            'points_count' => count($currentStay['points']),
                            'max_movement_radius' => $maxMovementRadius,
                            'avg_speed_kmh' => 0 // Stillstand
                        ];
                        
                        $this->logger->debug('Linearer Besuch erkannt', [
                            'duration_minutes' => round($durationMinutes, 1),
                            'points' => count($currentStay['points']),
                            'center' => round($currentStay['center_lat'], 5) . ',' . round($currentStay['center_lon'], 5)
                        ]);
                    }
                    
                    // Neuen Aufenthalt starten (wenn nicht in Bewegung)
                    if (!$isMoving) {
                        $currentStay = [
                            'start_time' => $timestamp,
                            'end_time' => $timestamp,
                            'points' => [$point],
                            'center_lat' => $latitude,
                            'center_lon' => $longitude
                        ];
                    } else {
                        $currentStay = null;
                    }
                }
            }
        }
        
        // Letzten Aufenthalt verarbeiten
        if ($currentStay !== null) {
            $durationMinutes = ($currentStay['end_time'] - $currentStay['start_time']) / 60;
            
            if ($durationMinutes >= $minimumStopDurationMinutes) {
                $visits[] = [
                    'device_id' => $deviceId,
                    'start_time' => $currentStay['start_time'],
                    'end_time' => $currentStay['end_time'],
                    'duration_minutes' => round($durationMinutes, 1),
                    'latitude' => $currentStay['center_lat'],
                    'longitude' => $currentStay['center_lon'],
                    'start_time_formatted' => date('d.m.Y H:i', $currentStay['start_time']),
                    'end_time_formatted' => date('d.m.Y H:i', $currentStay['end_time']),
                    'detection_method' => 'linear_simple',
                    'points_count' => count($currentStay['points']),
                    'max_movement_radius' => $maxMovementRadius,
                    'avg_speed_kmh' => 0
                ];
            }
        }
        
        $this->logger->info('Lineare Besuchsanalyse abgeschlossen', [
            'device_id' => $deviceId,
            'visits_found' => count($visits),
            'total_points_processed' => count($trackingData)
        ]);
        
        return $visits;
    }
    
    /**
     * Entfernt doppelte Besuche basierend auf Zeit und Position
     */
    private function removeDuplicateVisits(array $visits): array
    {
        $uniqueVisits = [];
        $timeToleranceMinutes = 30; // 30 Minuten Toleranz
        $distanceToleranceMeters = 100; // 100 Meter Toleranz
        
        foreach ($visits as $visit) {
            $isDuplicate = false;
            
            foreach ($uniqueVisits as $existingVisit) {
                $timeDiff = abs($visit['start_time'] - $existingVisit['start_time']) / 60;
                $distance = $this->calculateDistance(
                    $visit['latitude'], $visit['longitude'],
                    $existingVisit['latitude'], $existingVisit['longitude']
                );
                
                if ($timeDiff <= $timeToleranceMinutes && $distance <= $distanceToleranceMeters) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $uniqueVisits[] = $visit;
            }
        }
        
        // Sortiere nach Startzeit
        usort($uniqueVisits, function($a, $b) {
            return $a['start_time'] <=> $b['start_time'];
        });
        
        return $uniqueVisits;
    }
}
