<?php

namespace PajGpsCalendar\Services;

use Sabre\DAV\Client;
use Monolog\Logger;

class CalendarService
{
    private ConfigService $config;
    private Logger $logger;
    private ?Client $davClient = null;
    private DatabaseService $database;
    private string $lastCreatedEventId = '';

    public function __construct(ConfigService $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        
        if ($this->config->get('calendar.type') === 'caldav') {
            $this->initCalDavClient();
        }
    }

    private function initCalDavClient(): void
    {
        $settings = [
            'baseUri' => $this->config->get('calendar.caldav_url'),
            'userName' => $this->config->get('calendar.username'),
            'password' => $this->config->get('calendar.password'),
        ];

        $this->davClient = new Client($settings);
    }

    public function hasRecentEntry(array $vehicle, array $location): bool
    {
        // Diese Methode wird jetzt von CrmService übernommen
        // Hier nur als Fallback, falls tracking_database nicht konfiguriert ist
        return false;
    }

    public function createEntry(array $vehicle, array $location, float $distance): bool
    {
        try {
            $eventId = $this->generateEventId($vehicle, $location);
            $this->lastCreatedEventId = $eventId; // Speichere für spätere Verwendung
            
            $icalContent = $this->generateICalContent($vehicle, $location, $distance);

            if ($this->config->get('calendar.type') === 'caldav') {
                $this->createCalDavEvent($eventId, $icalContent);
            } else {
                throw new \Exception('Kalendertyp nicht unterstützt: ' . $this->config->get('calendar.type'));
            }

            $this->logger->info('Kalendereintrag erstellt', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'distance' => $distance,
                'event_id' => $eventId
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Erstellen des Kalendereintrags', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Gibt die ID des zuletzt erstellten Events zurück
     */
    public function getLastCreatedEventId(): string
    {
        return $this->lastCreatedEventId;
    }

    private function createCalDavEvent(string $eventId, string $icalContent): void
    {
        if (!$this->davClient) {
            throw new \Exception('CalDAV Client nicht initialisiert');
        }

        $eventPath = $eventId . '.ics';
        
        $response = $this->davClient->request('PUT', $eventPath, $icalContent, [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);

        if ($response['statusCode'] >= 400) {
            throw new \Exception('CalDAV Event konnte nicht erstellt werden: ' . $response['statusCode']);
        }
    }

    private function generateEventId(array $vehicle, array $location): string
    {
        $timestamp = date('YmdHis');
        $vehicleId = preg_replace('/[^a-zA-Z0-9]/', '', $vehicle['id']);
        $locationId = $location['id'];
        
        return "paj-gps-{$vehicleId}-{$locationId}-{$timestamp}";
    }

    private function generateICalContent(array $vehicle, array $location, float $distance): string
    {
        $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $eventStart = clone $now;
        $eventEnd = (clone $now)->add(new \DateInterval('PT1H')); // 1 Stunde Dauer

        $uid = $this->generateEventId($vehicle, $location);
        $summary = "Fahrzeug {$vehicle['name']} bei {$location['customer_name']}";
        $description = $this->generateEventDescription($vehicle, $location, $distance);
        
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//PAJ GPS Calendar//PAJ GPS Calendar 1.0//DE\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "BEGIN:VTIMEZONE\r\n";
        $ical .= "TZID:{$timezone}\r\n";
        $ical .= "BEGIN:STANDARD\r\n";
        $ical .= "DTSTART:20231029T030000\r\n";
        $ical .= "TZOFFSETFROM:+0200\r\n";
        $ical .= "TZOFFSETTO:+0100\r\n";
        $ical .= "TZNAME:CET\r\n";
        $ical .= "END:STANDARD\r\n";
        $ical .= "BEGIN:DAYLIGHT\r\n";
        $ical .= "DTSTART:20240331T020000\r\n";
        $ical .= "TZOFFSETFROM:+0100\r\n";
        $ical .= "TZOFFSETTO:+0200\r\n";
        $ical .= "TZNAME:CEST\r\n";
        $ical .= "END:DAYLIGHT\r\n";
        $ical .= "END:VTIMEZONE\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:" . $now->format('Ymd\THis\Z') . "\r\n";
        $ical .= "DTSTART;TZID={$timezone}:" . $eventStart->format('Ymd\THis') . "\r\n";
        $ical .= "DTEND;TZID={$timezone}:" . $eventEnd->format('Ymd\THis') . "\r\n";
        $ical .= "SUMMARY:{$summary}\r\n";
        $ical .= "DESCRIPTION:{$description}\r\n";
        $ical .= "LOCATION:{$location['address']}\r\n";
        $ical .= "CATEGORIES:PAJ-GPS,Fahrzeugverfolgung\r\n";
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "TRANSP:TRANSPARENT\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    private function generateEventDescription(array $vehicle, array $location, float $distance): string
    {
        $description = "Fahrzeugbesuch bei Kunde erfasst\\n\\n";
        
        // Fahrzeug-Informationen
        $description .= "FAHRZEUG:\\n";
        $description .= "- Name: {$vehicle['name']}\\n";
        $description .= "- ID: {$vehicle['id']}\\n";
        $description .= "- Entfernung zum Kunden: " . round($distance) . " Meter\\n";
        
        if (isset($vehicle['speed'])) {
            $description .= "- Geschwindigkeit: {$vehicle['speed']} km/h\\n";
        }
        
        if (isset($vehicle['timestamp'])) {
            $description .= "- Erkannt am: " . date('d.m.Y H:i:s', strtotime($vehicle['timestamp'])) . "\\n";
        }
        
        $description .= "\\n";
        
        // Kunden-Informationen (erweitert)
        $description .= "KUNDE:\\n";
        $description .= "- Name: {$location['customer_name']}\\n";
        
        if (!empty($location['customer_id'])) {
            $description .= "- Kundennummer: {$location['customer_id']}\\n";
        }
        
        $description .= "- Adresse: {$location['full_address']}\\n";
        
        if (!empty($location['contact_person'])) {
            $description .= "- Ansprechpartner: {$location['contact_person']}\\n";
        }
        
        if (!empty($location['phone'])) {
            $description .= "- Telefon: {$location['phone']}\\n";
        }
        
        if (!empty($location['email'])) {
            $description .= "- E-Mail: {$location['email']}\\n";
        }
        
        if (!empty($location['notes'])) {
            $description .= "- Notizen: {$location['notes']}\\n";
        }
        
        $description .= "\\n";
        
        // GPS-Koordinaten
        $description .= "GPS-POSITION:\\n";
        $description .= "- Fahrzeug: {$vehicle['latitude']}, {$vehicle['longitude']}\\n";
        $description .= "- Kunde: {$location['latitude']}, {$location['longitude']}\\n";
        
        $description .= "\\n";
        $description .= "Dieser Eintrag wurde automatisch durch das PAJ GPS to Calendar System erstellt.";
        
        return $description;
    }

    public function testConnection(): bool
    {
        try {
            if ($this->config->get('calendar.type') === 'caldav') {
                if (!$this->davClient) {
                    $this->initCalDavClient();
                }
                
                $response = $this->davClient->options('');
                return $response['statusCode'] < 400;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Kalenderverbindung fehlgeschlagen', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
