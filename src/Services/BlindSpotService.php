<?php

namespace PajGpsCalendar\Services;

class BlindSpotService
{
    private ConfigService $config;

    public function __construct(ConfigService $config)
    {
        $this->config = $config;
    }

    /**
     * Prüft ob eine Position in einem blinden Fleck liegt
     */
    public function isInBlindSpot(float $latitude, float $longitude): ?array
    {
        $blindSpots = $this->config->get('settings.blind_spots', []);
        
        foreach ($blindSpots as $blindSpot) {
            if (!isset($blindSpot['latitude'], $blindSpot['longitude'], $blindSpot['radius_meters'])) {
                continue;
            }
            
            $distance = $this->calculateDistance(
                $latitude, $longitude,
                $blindSpot['latitude'], $blindSpot['longitude']
            );
            
            if ($distance <= $blindSpot['radius_meters']) {
                return [
                    'name' => $blindSpot['name'] ?? 'Unbenannter blinder Fleck',
                    'distance_meters' => $distance,
                    'radius_meters' => $blindSpot['radius_meters']
                ];
            }
        }
        
        return null;
    }

    /**
     * Berechnet die Entfernung zwischen zwei GPS-Koordinaten in Metern
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Erdradius in Metern

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }

    /**
     * Holt alle konfigurierten blinden Flecken
     */
    public function getBlindSpots(): array
    {
        return $this->config->get('settings.blind_spots', []);
    }
}
