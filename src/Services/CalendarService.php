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
        // Prüfe direkt im Kalender ob bereits ein Eintrag existiert
        $existingEventId = $this->hasExistingEntry($vehicle, $location);
        return $existingEventId !== null;
    }

    /**
     * Prüft ob bereits ein historischer Kalendereintrag für diesen Besuch existiert
     */
    public function hasExistingHistoricalEntry(array $vehicle, array $location, int $timestamp): bool
    {
        try {
            if (!$this->davClient) {
                throw new \Exception('CalDAV Client nicht initialisiert');
            }

            $expectedEventId = $this->generateHistoricalEventId($vehicle, $location, $timestamp);
            
            // Prüfe auf spezifischen historischen Event
            $response = $this->davClient->request('GET', $expectedEventId . '.ics');
            
            return $response['statusCode'] === 200;
            
        } catch (\Exception $e) {
            $this->logger->debug('Historische Event-Prüfung fehlgeschlagen', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'timestamp' => date('Y-m-d H:i:s', $timestamp),
                'error' => $e->getMessage()
            ]);
            return false;
        }
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
     * Erstellt einen historischen Kalendereintrag mit den tatsächlichen Besuchszeiten
     */
    public function createHistoricalEntry(array $vehicle, array $location, float $distance, int $startTimestamp, int $endTimestamp): bool
    {
        try {
            $eventId = $this->generateHistoricalEventId($vehicle, $location, $startTimestamp);
            $this->lastCreatedEventId = $eventId;
            
            $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
            $startTime = new \DateTime('@' . $startTimestamp);
            $startTime->setTimezone(new \DateTimeZone($timezone));
            $endTime = new \DateTime('@' . $endTimestamp);
            $endTime->setTimezone(new \DateTimeZone($timezone));
            
            $icalContent = $this->generateHistoricalICalContent($vehicle, $location, $distance, $startTime, $endTime);

            if ($this->config->get('calendar.type') === 'caldav') {
                $this->createCalDavEvent($eventId, $icalContent);
            } else {
                throw new \Exception('Kalendertyp nicht unterstützt: ' . $this->config->get('calendar.type'));
            }

            $this->logger->info('Historischer Kalendereintrag erstellt', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'distance' => $distance,
                'event_id' => $eventId,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'end_time' => $endTime->format('Y-m-d H:i:s'),
                'duration_minutes' => round(($endTimestamp - $startTimestamp) / 60)
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Erstellen des historischen Kalendereintrags', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'error_code' => $e->getCode(),
                'event_id' => $eventId ?? 'unknown',
                'start_timestamp' => $startTimestamp,
                'end_timestamp' => $endTimestamp,
                'stacktrace' => $e->getTraceAsString()
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
            'Content-Type' => 'text/calendar',
        ]);

        if ($response['statusCode'] >= 400) {
            $errorDetails = [
                'status_code' => $response['statusCode'],
                'response_body' => $response['body'] ?? 'No response body',
                'event_path' => $eventPath,
                'caldav_url' => $this->config->get('calendar.caldav_url'),
                'ical_content_length' => strlen($icalContent)
            ];
            throw new \Exception('CalDAV Event konnte nicht erstellt werden: ' . json_encode($errorDetails));
        }
    }

    public function generateEventId(array $vehicle, array $location): string
    {
        // Generiere eine eindeutige aber reproduzierbare ID
        $vehicleId = preg_replace('/[^a-zA-Z0-9]/', '', $vehicle['id'] ?? 'unknown');
        $locationId = $location['customer_id'] ?? 'unknown';
        
        // Verwende nur das Datum (ohne Zeit) für Reproduzierbarkeit am selben Tag
        $dateOnly = date('Ymd'); // Format: 20250821
        
        return "paj-gps-{$vehicleId}-{$locationId}-{$dateOnly}";
    }

    /**
     * Generiert Event-ID für historische Einträge mit dem tatsächlichen Besuchsdatum
     */
    public function generateHistoricalEventId(array $vehicle, array $location, int $timestamp): string
    {
        $vehicleId = preg_replace('/[^a-zA-Z0-9]/', '', $vehicle['id'] ?? 'unknown');
        $locationId = $location['customer_id'] ?? 'unknown';
        
        // Verwende das tatsächliche Besuchsdatum
        $dateOnly = date('Ymd', $timestamp);
        $timeHour = date('Hi', $timestamp); // Stunde+Minute für Eindeutigkeit bei mehreren Besuchen am Tag
        
        return "paj-gps-{$vehicleId}-{$locationId}-{$dateOnly}-{$timeHour}";
    }

    private function generateICalContent(array $vehicle, array $location, float $distance): string
    {
        $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $eventStart = clone $now;
        $eventEnd = (clone $now)->add(new \DateInterval('PT1H')); // 1 Stunde Dauer

        $uid = $this->generateEventId($vehicle, $location);
        
        // iCalendar-konformes Escaping für alle Textfelder
        $summary = $this->escapeICalText("Fahrzeug {$vehicle['name']} bei {$location['customer_name']}");
        $description = $this->escapeICalText($this->generateEventDescription($vehicle, $location, $distance));
        $locationText = $this->escapeICalText($location['full_address'] ?? 'Adresse unbekannt');
        
        // Vereinfachte iCal-Version ohne komplexe Timezone-Definitionen
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//PAJ GPS Calendar//PAJ GPS Calendar 1.0//DE\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:" . $now->format('Ymd\THis\Z') . "\r\n";
        $ical .= "DTSTART:" . $eventStart->format('Ymd\THis') . "\r\n";
        $ical .= "DTEND:" . $eventEnd->format('Ymd\THis') . "\r\n";
        $ical .= "SUMMARY:{$summary}\r\n";
        $ical .= "DESCRIPTION:{$description}\r\n";
        $ical .= "LOCATION:{$locationText}\r\n";
        $ical .= "CATEGORIES:PAJ-GPS,Fahrzeugverfolgung\r\n";
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "TRANSP:TRANSPARENT\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Generiert iCal-Content für historische Einträge mit den tatsächlichen Besuchszeiten
     */
    private function generateHistoricalICalContent(array $vehicle, array $location, float $distance, \DateTime $startTime, \DateTime $endTime): string
    {
        $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
        $now = new \DateTime('now', new \DateTimeZone($timezone));

        $uid = $this->generateHistoricalEventId($vehicle, $location, $startTime->getTimestamp());
        
        // iCalendar-konformes Escaping für alle Textfelder
        $summary = $this->escapeICalText("Fahrzeug {$vehicle['name']} bei {$location['customer_name']}");
        $description = $this->escapeICalText($this->generateHistoricalEventDescription($vehicle, $location, $distance, $startTime, $endTime));
        $locationText = $this->escapeICalText($location['full_address'] ?? 'Adresse unbekannt');
        
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
        $ical .= "DTSTART;TZID={$timezone}:" . $startTime->format('Ymd\THis') . "\r\n";
        $ical .= "DTEND;TZID={$timezone}:" . $endTime->format('Ymd\THis') . "\r\n";
        $ical .= "SUMMARY:{$summary}\r\n";
        $ical .= "DESCRIPTION:{$description}\r\n";
        $ical .= "LOCATION:{$locationText}\r\n";
        $ical .= "CATEGORIES:PAJ-GPS,Fahrzeugverfolgung,Historisch\r\n";
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }
    
    /**
     * Escaped Text für iCalendar-Format (RFC 5545 konform)
     */
    private function escapeICalText(string $text): string
    {
        // Entferne oder ersetze problematische Zeichen
        $text = trim($text);
        
        // Backslashes zuerst escapen (wichtig: vor anderen Escapes!)
        $text = str_replace('\\', '\\\\', $text);
        
        // Spezielle iCalendar-Zeichen escapen
        $text = str_replace(';', '\\;', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace('"', '\\"', $text);
        
        // Zeilenwechsel normalisieren
        $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
        
        // Entferne Steuerzeichen die Probleme machen können
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Begrenzt auf vernünftige Länge
        if (strlen($text) > 200) {
            $text = substr($text, 0, 197) . '...';
        }
        
        return $text;
    }

    private function generateEventDescription(array $vehicle, array $location, float $distance): string
    {
        $description = "Fahrzeugbesuch bei Kunde erfasst\n\n";
        
        // Fahrzeug-Informationen
        $description .= "FAHRZEUG:\n";
        $description .= "- Name: {$vehicle['name']}\n";
        $description .= "- ID: " . ($vehicle['id'] ?? 'unbekannt') . "\n";
        $description .= "- Entfernung zum Kunden: " . round($distance) . " Meter\n";
        
        if (isset($vehicle['speed'])) {
            $description .= "- Geschwindigkeit: {$vehicle['speed']} km/h\n";
        }
        
        if (isset($vehicle['timestamp'])) {
            $description .= "- Erkannt am: " . date('d.m.Y H:i:s', strtotime($vehicle['timestamp'])) . "\n";
        }
        
        $description .= "\n";
        
        // Kunden-Informationen (erweitert)
        $description .= "KUNDE:\n";
        $description .= "- Name: {$location['customer_name']}\n";
        
        if (!empty($location['customer_id'])) {
            $description .= "- Kundennummer: {$location['customer_id']}\n";
        }
        
        $description .= "- Adresse: " . ($location['full_address'] ?? 'Adresse unbekannt') . "\n";
        
        if (!empty($location['contact_person'])) {
            $description .= "- Ansprechpartner: {$location['contact_person']}\n";
        }
        
        if (!empty($location['phone'])) {
            $description .= "- Telefon: {$location['phone']}\n";
        }
        
        if (!empty($location['email'])) {
            $description .= "- E-Mail: {$location['email']}\n";
        }
        
        if (!empty($location['notes'])) {
            $description .= "- Notizen: {$location['notes']}\n";
        }
        
        $description .= "\n";
        
        // GPS-Koordinaten
        $description .= "GPS-POSITION:\n";
        $description .= "- Fahrzeug: {$vehicle['latitude']}, {$vehicle['longitude']}\n";
        $description .= "- Kunde: {$location['latitude']}, {$location['longitude']}\n";
        
        $description .= "\n";
        $description .= "Dieser Eintrag wurde automatisch durch das PAJ GPS to Calendar System erstellt.";
        
        return $description;
    }

    /**
     * Generiert Event-Beschreibung für historische Einträge mit tatsächlicher Aufenthaltsdauer
     */
    private function generateHistoricalEventDescription(array $vehicle, array $location, float $distance, \DateTime $startTime, \DateTime $endTime): string
    {
        $durationMinutes = round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
        
        $description = "Fahrzeugbesuch bei Kunde erfasst (Historische Daten)\n\n";
        
        // Besuchszeiten
        $description .= "BESUCHSZEITEN:\n";
        $description .= "- Ankunft: " . $startTime->format('d.m.Y H:i:s') . "\n";
        $description .= "- Abfahrt: " . $endTime->format('d.m.Y H:i:s') . "\n";
        $description .= "- Aufenthaltsdauer: {$durationMinutes} Minuten\n\n";
        
        // Fahrzeug-Informationen
        $description .= "FAHRZEUG:\n";
        $description .= "- Name: {$vehicle['name']}\n";
        $description .= "- ID: " . ($vehicle['id'] ?? 'unbekannt') . "\n";
        $description .= "- Entfernung zum Kunden: " . round($distance) . " Meter\n";
        $description .= "- Geschwindigkeit: " . ($vehicle['speed'] ?? 0) . " km/h\n\n";
        
        // Kunden-Informationen
        $description .= "KUNDE:\n";
        $description .= "- Name: {$location['customer_name']}\n";
        
        if (!empty($location['customer_id'])) {
            $description .= "- Kundennummer: {$location['customer_id']}\n";
        }
        
        $description .= "- Adresse: " . ($location['full_address'] ?? 'Adresse unbekannt') . "\n";
        
        if (!empty($location['contact_person'])) {
            $description .= "- Ansprechpartner: {$location['contact_person']}\n";
        }
        
        if (!empty($location['phone'])) {
            $description .= "- Telefon: {$location['phone']}\n";
        }
        
        if (!empty($location['email'])) {
            $description .= "- E-Mail: {$location['email']}\n";
        }
        
        if (!empty($location['notes'])) {
            $description .= "- Notizen: {$location['notes']}\n";
        }
        
        $description .= "\n";
        
        // GPS-Koordinaten
        $description .= "GPS-POSITION:\n";
        $description .= "- Fahrzeug: {$vehicle['latitude']}, {$vehicle['longitude']}\n";
        $description .= "- Kunde: {$location['latitude']}, {$location['longitude']}\n";
        
        $description .= "\n";
        $description .= "Dieser Eintrag wurde automatisch durch das PAJ GPS to Calendar System erstellt.\n";
        $description .= "Datenquelle: PAJ GPS Historische Analyse";
        
        return $description;
    }

    public function testConnection(): bool
    {
        try {
            if ($this->config->get('calendar.type') === 'caldav') {
                if (!$this->davClient) {
                    $this->initCalDavClient();
                }
                
                $response = $this->davClient->options();
                return $response['statusCode'] < 400;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Kalenderverbindung fehlgeschlagen', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Aktualisiert einen bestehenden Kalendereintrag mit der tatsächlichen Endzeit
     */
    public function updateEntryWithEndTime(string $eventId, array $vehicle, array $location, float $distance): bool
    {
        try {
            // Hole den existierenden Event um die Start-Zeit zu bekommen
            $existingEvent = $this->getEventContent($eventId);
            if (!$existingEvent) {
                throw new \Exception('Existierender Event konnte nicht gefunden werden');
            }
            
            $startTime = $existingEvent['start_time'];
            $endTime = new \DateTime(); // Jetzt als Endzeit
            
            $icalContent = $this->generateICalContentWithTimes($eventId, $vehicle, $location, $distance, $startTime, $endTime);

            if ($this->config->get('calendar.type') === 'caldav') {
                $this->updateCalDavEvent($eventId, $icalContent);
            } else {
                throw new \Exception('Kalendertyp nicht unterstützt: ' . $this->config->get('calendar.type'));
            }

            $this->logger->info('Kalendereintrag mit Endzeit aktualisiert', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'event_id' => $eventId,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'end_time' => $endTime->format('Y-m-d H:i:s'),
                'duration_minutes' => round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60)
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren des Kalendereintrags', [
                'event_id' => $eventId,
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generiert iCal-Content mit spezifischen Start- und Endzeiten
     */
    private function generateICalContentWithTimes(string $eventId, array $vehicle, array $location, float $distance, \DateTime $startTime, \DateTime $endTime): string
    {
        $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
        $now = new \DateTime('now', new \DateTimeZone($timezone));

        $uid = $eventId; // Verwende die übergebene Event-ID
        $summary = "Fahrzeug {$vehicle['name']} bei {$location['customer_name']}";
        $description = $this->generateEventDescriptionWithDuration($vehicle, $location, $distance, $startTime, $endTime);
        
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
        $ical .= "DTSTART;TZID={$timezone}:" . $startTime->format('Ymd\THis') . "\r\n";
        $ical .= "DTEND;TZID={$timezone}:" . $endTime->format('Ymd\THis') . "\r\n";
        $ical .= "SUMMARY:{$summary}\r\n";
        $ical .= "DESCRIPTION:{$description}\r\n";
        $ical .= "LOCATION:" . ($location['full_address'] ?? 'Adresse unbekannt') . "\r\n";
        $ical .= "CATEGORIES:PAJ-GPS,Fahrzeugverfolgung\r\n";
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "SEQUENCE:1\r\n"; // Erhöhe Sequence für Update
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Aktualisiert einen CalDAV-Event
     */
    private function updateCalDavEvent(string $eventId, string $icalContent): void
    {
        if (!$this->davClient) {
            throw new \Exception('CalDAV Client nicht initialisiert');
        }

        $eventPath = $eventId . '.ics';

        $response = $this->davClient->request('PUT', $eventPath, $icalContent, [
            'Content-Type' => 'text/calendar',
            'If-Match' => '*'  // Überschreibe existierenden Event
        ]);

        if ($response['statusCode'] >= 300) {
            throw new \Exception('CalDAV Event konnte nicht aktualisiert werden: ' . $response['statusCode']);
        }
    }

    /**
     * Generiert Event-Beschreibung mit tatsächlicher Aufenthaltsdauer
     */
    private function generateEventDescriptionWithDuration(array $vehicle, array $location, float $distance, \DateTime $startTime, \DateTime $endTime): string
    {
        $durationMinutes = round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
        $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
        
        $description = "Fahrzeugbesuch bei Kunde erfasst\\n\\n";
        $description .= "BESUCHSZEITEN:\\n";
        $description .= "- Ankunft: " . $startTime->format('d.m.Y H:i:s') . "\\n";
        $description .= "- Abfahrt: " . $endTime->format('d.m.Y H:i:s') . "\\n";
        $description .= "- Aufenthaltsdauer: {$durationMinutes} Minuten\\n\\n";
        $description .= "FAHRZEUG:\\n";
        $description .= "- Name: " . ($vehicle['name'] ?? 'Unbekannt') . "\\n";
        $description .= "- ID: " . ($vehicle['id'] ?? 'Unbekannt') . "\\n";
        $description .= "- Entfernung zum Kunden: " . round($distance) . " Meter\\n";
        $description .= "- Geschwindigkeit: " . ($vehicle['speed'] ?? 0) . " km/h\\n\\n";
        $description .= "KUNDE:\\n";
        $description .= "- Name: " . ($location['customer_name'] ?? 'Unbekannt') . "\\n";
        $description .= "- Kundennummer: " . ($location['customer_id'] ?? 'Unbekannt') . "\\n";
        $description .= "- Adresse: " . ($location['full_address'] ?? 'Adresse unbekannt') . "\\n";
        $description .= "- Ansprechpartner: " . ($location['contact_person'] ?? 'Unbekannt') . "\\n";
        $description .= "- Telefon: " . ($location['phone'] ?? 'Unbekannt') . "\\n";
        $description .= "- E-Mail: " . ($location['email'] ?? 'Unbekannt') . "\\n\\n";
        $description .= "GPS-POSITION:\\n";
        $description .= "- Fahrzeug: " . ($vehicle['latitude'] ?? 0) . ", " . ($vehicle['longitude'] ?? 0) . "\\n";
        $description .= "- Kunde: " . ($location['latitude'] ?? 0) . ", " . ($location['longitude'] ?? 0) . "\\n\\n";
        $description .= "Automatisch generiert von PAJ GPS to Calendar";

        return $description;
    }

    /**
     * Prüft ob bereits ein Kalendereintrag für diesen Besuch existiert
     */
    public function hasExistingEntry(array $vehicle, array $location): ?string
    {
        try {
            if (!$this->davClient) {
                throw new \Exception('CalDAV Client nicht initialisiert');
            }

            $expectedEventId = $this->generateEventId($vehicle, $location);
            
            // Einfachere Duplikatserkennung: Prüfe auf Event-Name-Pattern für heute
            $today = date('Ymd');
            $vehicleIdClean = preg_replace('/[^a-zA-Z0-9]/', '', $vehicle['id']);
            $customerId = $location['customer_id'] ?? $location['id'] ?? 0;
            
            // PROPFIND Anfrage für Events mit ähnlichem Namen
            $response = $this->davClient->propfind('', [
                '{DAV:}displayname',
                '{DAV:}getcontenttype'
            ], 1);
            
            if (isset($response)) {
                foreach ($response as $path => $properties) {
                    if (is_string($path)) {
                        $fileName = basename($path, '.ics');
                        
                        // Prüfe auf Pattern: paj-gps-[vehicleId]-[customerId]-[heute oder timestamp]
                        if (strpos($fileName, "paj-gps-{$vehicleIdClean}-{$customerId}-") === 0 &&
                            (strpos($fileName, $today) !== false || 
                             preg_match("/paj-gps-{$vehicleIdClean}-{$customerId}-\d{8}/", $fileName))) {
                            
                            $this->logger->debug('Existierender Event gefunden', [
                                'vehicle' => $vehicle['name'],
                                'customer' => $location['customer_name'],
                                'found_event' => $fileName
                            ]);
                            return $fileName;
                        }
                    }
                }
            }
            
            $this->logger->debug('Kein existierender Event gefunden', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'expected_pattern' => "paj-gps-{$vehicleIdClean}-{$customerId}-{$today}*"
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->debug('Event-Prüfung fehlgeschlagen', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Holt alle heute erstellten Events für ein Fahrzeug
     */
    public function getTodaysEventsForVehicle(string $vehicleId): array
    {
        try {
            if (!$this->davClient) {
                throw new \Exception('CalDAV Client nicht initialisiert');
            }

            // PROPFIND Anfrage für alle Events des heutigen Tages
            $today = date('Ymd');
            $vehicleIdClean = preg_replace('/[^a-zA-Z0-9]/', '', $vehicleId);
            
            $response = $this->davClient->propfind('', [
                '{DAV:}displayname',
                '{DAV:}getcontenttype',
                '{DAV:}getetag'
            ], 1);
            
            $events = [];
            if (isset($response)) {
                foreach ($response as $path => $properties) {
                    if (is_string($path) && 
                        strpos($path, "paj-gps-{$vehicleIdClean}") !== false && 
                        strpos($path, $today) !== false) {
                        
                        // Hol den Event-Inhalt um die UID zu extrahieren
                        $eventPath = basename($path);
                        $eventContent = $this->getEventContent($eventPath);
                        $uid = null;
                        if ($eventContent && isset($eventContent['ical_content'])) {
                            $icalContent = $eventContent['ical_content'];
                            if (preg_match('/UID:(.+)/m', $icalContent, $matches)) {
                                $uid = trim($matches[1]);
                            }
                        }
                        
                        $events[] = [
                            'path' => $path,
                            'uid' => $uid,
                            'event_id' => basename($path, '.ics'),
                            'etag' => $properties['{DAV:}getetag'] ?? null
                        ];
                    }
                }
            }
            
            return $events;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Fahrzeug-Events', [
                'vehicle_id' => $vehicleId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Holt den Inhalt eines bestehenden Events
     */
    public function getEventContent(string $eventId): ?array
    {
        try {
            if (!$this->davClient) {
                throw new \Exception('CalDAV Client nicht initialisiert');
            }

            // Stelle sicher, dass eventId die .ics Endung hat
            $eventPath = str_ends_with($eventId, '.ics') ? $eventId : $eventId . '.ics';
            $response = $this->davClient->request('GET', $eventPath);
            
            if ($response['statusCode'] === 200) {
                $icalContent = $response['body'];
                
                // Parse Start-Zeit aus iCal
                if (preg_match('/DTSTART;TZID=.*?:(\d{8}T\d{6})/', $icalContent, $matches)) {
                    $startTime = \DateTime::createFromFormat('Ymd\THis', $matches[1]);
                    
                    return [
                        'event_id' => $eventId,
                        'start_time' => $startTime,
                        'ical_content' => $icalContent
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen des Event-Inhalts', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
