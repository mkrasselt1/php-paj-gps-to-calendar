<?php

namespace PajGpsCalendar\Services;

use Monolog\Logger;

class GeoService
{
    private ConfigService $config;
    private Logger $logger;

    public function __construct(ConfigService $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Berechnet die Entfernung zwischen zwei GPS-Koordinaten in Metern
     * Verwendet die Haversine-Formel
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Erdradius in Metern

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLatRad = deg2rad($lat2 - $lat1);
        $deltaLonRad = deg2rad($lon2 - $lon1);

        $a = sin($deltaLatRad / 2) * sin($deltaLatRad / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLonRad / 2) * sin($deltaLonRad / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        $this->logger->debug('Distanz berechnet', [
            'from' => [$lat1, $lon1],
            'to' => [$lat2, $lon2],
            'distance_meters' => $distance
        ]);

        return $distance;
    }

    /**
     * Prüft ob eine Position innerhalb eines Radius um eine andere Position liegt
     */
    public function isWithinRadius(float $lat1, float $lon1, float $lat2, float $lon2, float $radiusMeters): bool
    {
        $distance = $this->calculateDistance($lat1, $lon1, $lat2, $lon2);
        return $distance <= $radiusMeters;
    }

    /**
     * Findet alle Kundenstandorte in der Nähe einer Position
     */
    public function findNearbyLocations(float $latitude, float $longitude, array $locations, float $radiusMeters): array
    {
        $nearbyLocations = [];

        foreach ($locations as $location) {
            $distance = $this->calculateDistance(
                $latitude,
                $longitude,
                $location['latitude'],
                $location['longitude']
            );

            if ($distance <= $radiusMeters) {
                $location['distance'] = $distance;
                $nearbyLocations[] = $location;
            }
        }

        // Sortierung nach Entfernung (nähere zuerst)
        usort($nearbyLocations, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });

        return $nearbyLocations;
    }

    /**
     * Konvertiert GPS-Koordinaten zu einer Adresse (Reverse Geocoding)
     * Verwendet einen kostenlosen Service wie Nominatim
     */
    public function coordinatesToAddress(float $latitude, float $longitude): string
    {
        try {
            $url = "https://nominatim.openstreetmap.org/reverse";
            $params = [
                'format' => 'json',
                'lat' => $latitude,
                'lon' => $longitude,
                'zoom' => 18,
                'addressdetails' => 1
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: PAJ-GPS-Calendar/1.0',
                    'timeout' => 10
                ]
            ]);

            $response = file_get_contents($url . '?' . http_build_query($params), false, $context);
            
            if ($response === false) {
                throw new \Exception('Keine Antwort vom Geocoding-Service');
            }

            $data = json_decode($response, true);
            
            if (isset($data['display_name'])) {
                return $data['display_name'];
            }

            return 'Unbekannte Adresse';
            
        } catch (\Exception $e) {
            $this->logger->warning('Reverse Geocoding fehlgeschlagen', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $e->getMessage()
            ]);
            
            return "Koordinaten: {$latitude}, {$longitude}";
        }
    }

    /**
     * Konvertiert eine Adresse zu GPS-Koordinaten (Geocoding)
     */
    public function addressToCoordinates(string $address): ?array
    {
        try {
            $url = "https://nominatim.openstreetmap.org/search";
            $params = [
                'format' => 'json',
                'q' => $address,
                'limit' => 1,
                'addressdetails' => 1
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: PAJ-GPS-Calendar/1.0',
                    'timeout' => 10
                ]
            ]);

            $response = file_get_contents($url . '?' . http_build_query($params), false, $context);
            
            if ($response === false) {
                throw new \Exception('Keine Antwort vom Geocoding-Service');
            }

            $data = json_decode($response, true);
            
            if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
                return [
                    'latitude' => (float) $data[0]['lat'],
                    'longitude' => (float) $data[0]['lon']
                ];
            }

            return null;
            
        } catch (\Exception $e) {
            $this->logger->warning('Geocoding fehlgeschlagen', [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}
