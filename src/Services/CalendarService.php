<?php

namespace PajGpsCalendar\Services;

use Sabre\DAV\Client;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Monolog\Logger;

class CalendarService
{
    private ConfigService $config;
    private Logger $logger;
    private ?Client $davClient = null;
    private ?GoogleClient $googleClient = null;
    private ?GoogleCalendar $googleCalendarService = null;
    private DatabaseService $database;
    private string $lastCreatedEventId = '';

    public function __construct(ConfigService $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        
        if ($this->config->get('calendar.type') === 'caldav') {
            $this->initCalDavClient();
        }
        
        if ($this->config->get('calendar.google_calendar.enabled', false)) {
            $this->initGoogleClient();
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

    private function initGoogleClient(): void
    {
        $credentialsPath = $this->config->get('calendar.google_calendar.credentials_path');
        
        if (!file_exists($credentialsPath)) {
            $this->logger->warning('Google Calendar Credentials nicht gefunden', [
                'credentials_path' => $credentialsPath
            ]);
            return;
        }

        $this->googleClient = new GoogleClient();
        $this->googleClient->setApplicationName($this->config->get('calendar.google_calendar.application_name', 'PAJ GPS to Calendar'));
        $this->googleClient->setScopes([GoogleCalendar::CALENDAR_EVENTS]);
        $this->googleClient->setAuthConfig($credentialsPath);
        $this->googleClient->setAccessType('offline');
        $this->googleClient->setPrompt('select_account consent');
        $this->googleClient->setRedirectUri('urn:ietf:wg:oauth:2.0:oob'); // Für Desktop-Apps
        
        // Token-Datei für gespeicherte Tokens
        $tokenPath = dirname($credentialsPath) . '/google-token.json';
        
        // Prüfe ob Token bereits vorhanden ist
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $this->googleClient->setAccessToken($accessToken);
            
            // Token refreshen falls nötig
            if ($this->googleClient->isAccessTokenExpired()) {
                $this->logger->info('Google Access Token ist abgelaufen, versuche Refresh');
                try {
                    $this->googleClient->fetchAccessTokenWithRefreshToken($this->googleClient->getRefreshToken());
                    file_put_contents($tokenPath, json_encode($this->googleClient->getAccessToken()));
                    $this->logger->info('Google Access Token erfolgreich erneuert');
                } catch (\Exception $e) {
                    $this->logger->error('Fehler beim Refresh des Google Access Tokens', [
                        'error' => $e->getMessage()
                    ]);
                    // Token löschen, damit OAuth-Flow neu gestartet werden kann
                    unlink($tokenPath);
                    throw new \Exception('Google Access Token konnte nicht erneuert werden. Bitte führen Sie die Authentifizierung erneut durch.');
                }
            }
        } else {
            throw new \Exception('Google Calendar ist aktiviert, aber nicht authentifiziert. Führen Sie zuerst "php bin/paj-gps-calendar google-auth" aus.');
        }
        
        $this->googleCalendarService = new GoogleCalendar($this->googleClient);
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
            // Prüfe CalDAV falls verfügbar
            if ($this->davClient) {
                $expectedEventId = $this->generateHistoricalEventId($vehicle, $location, $timestamp);
                $response = $this->davClient->request('GET', $expectedEventId . '.ics');
                if ($response['statusCode'] === 200) {
                    return true;
                }
            }

            // Prüfe Google Calendar falls verfügbar
            if ($this->googleCalendarService) {
                return $this->hasExistingGoogleCalendarHistoricalEntry($vehicle, $location, $timestamp);
            }

            // Wenn kein Kalendersystem verfügbar ist, gehe davon aus dass kein Event existiert
            return false;

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

            // Erstelle Eintrag in CalDAV Kalender (falls konfiguriert)
            if ($this->config->get('calendar.type') === 'caldav' && $this->davClient) {
                $this->createCalDavEvent($eventId, $icalContent);
            }

            // Erstelle Eintrag in Google Calendar (falls aktiviert)
            if ($this->config->get('calendar.google_calendar.enabled', false)) {
                $this->createGoogleCalendarEvent($vehicle, $location, $distance);
            }

            // Stelle sicher, dass mindestens ein Kalendersystem konfiguriert ist
            $hasCalDav = $this->config->get('calendar.type') === 'caldav' && $this->davClient;
            $hasGoogle = $this->config->get('calendar.google_calendar.enabled', false) && $this->googleCalendarService;
            
            if (!$hasCalDav && !$hasGoogle) {
                throw new \Exception('Kein Kalendersystem konfiguriert oder verfügbar. Überprüfen Sie die calendar.type oder calendar.google_calendar.enabled Einstellungen.');
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
    public function createHistoricalEntry(array $vehicle, array $location, float $distance, int $startTimestamp, int $endTimestamp, bool $forceUpdate = false): bool
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

            // Debug-Logging für iCalendar Content (bei Debug-Level)
            $this->logger->debug('iCalendar Content generiert', [
                'event_id' => $eventId,
                'content_length' => strlen($icalContent),
                'line_count' => substr_count($icalContent, "\n"),
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'end_time' => $endTime->format('Y-m-d H:i:s')
            ]);

            // Erstelle historischen Eintrag in CalDAV Kalender (falls konfiguriert)
            if ($this->config->get('calendar.type') === 'caldav' && $this->davClient) {
                if ($forceUpdate) {
                    // Für Update: Erst prüfen ob Eintrag existiert, dann überschreiben
                    $this->updateCalDavEvent($eventId, $icalContent);
                } else {
                    // Normaler Create-Modus
                    $this->createCalDavEvent($eventId, $icalContent);
                }
            }

            // Erstelle historischen Eintrag in Google Calendar (falls aktiviert)
            if ($this->config->get('calendar.google_calendar.enabled', false)) {
                $this->createHistoricalGoogleCalendarEvent($vehicle, $location, $distance, $startTime, $endTime, $forceUpdate);
            }

            // Stelle sicher, dass mindestens ein Kalendersystem konfiguriert ist
            $hasCalDav = $this->config->get('calendar.type') === 'caldav' && $this->davClient;
            $hasGoogle = $this->config->get('calendar.google_calendar.enabled', false) && $this->googleCalendarService;
            
            if (!$hasCalDav && !$hasGoogle) {
                throw new \Exception('Kein Kalendersystem konfiguriert oder verfügbar. Überprüfen Sie die calendar.type oder calendar.google_calendar.enabled Einstellungen.');
            }

            $logAction = $forceUpdate ? 'aktualisiert' : 'erstellt';
            $this->logger->info("Historischer Kalendereintrag {$logAction}", [
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
        
        // Validiere iCalendar Content vor dem Senden
        $this->validateICalendarContent($icalContent, $eventId);
        
        $response = $this->davClient->request('PUT', $eventPath, $icalContent, [
            'Content-Type' => 'text/calendar',
        ]);

        if ($response['statusCode'] >= 400) {
            $errorDetails = [
                'status_code' => $response['statusCode'],
                'response_body' => $response['body'] ?? 'No response body',
                'event_path' => $eventPath,
                'caldav_url' => $this->config->get('calendar.caldav_url'),
                'ical_content_length' => strlen($icalContent),
                'ical_line_count' => substr_count($icalContent, "\n"),
                'event_id' => $eventId
            ];
            
            // Spezifische Fehlermeldungen für häufige CalDAV Probleme
            $errorMessage = $this->getDetailedCalDavError($response['statusCode'], $response['body'] ?? '', $errorDetails);
            
            throw new \Exception($errorMessage);
        }
    }

    private function createGoogleCalendarEvent(array $vehicle, array $location, float $distance): void
    {
        if (!$this->googleCalendarService) {
            throw new \Exception('Google Calendar Service nicht initialisiert');
        }

        $timezone = $this->config->get('settings.timezone', 'Europe/Berlin');
        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $eventStart = clone $now;
        $eventEnd = (clone $now)->add(new \DateInterval('PT1H')); // 1 Stunde Dauer

        $event = new GoogleEvent();
        $event->setSummary("Fahrzeug {$vehicle['name']} bei {$location['customer_name']}");
        $event->setDescription($this->generateEventDescription($vehicle, $location, $distance));
        $event->setLocation($location['full_address'] ?? 'Adresse unbekannt');

        $start = new EventDateTime();
        $start->setDateTime($eventStart->format(\DateTime::RFC3339));
        $start->setTimeZone($timezone);
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDateTime($eventEnd->format(\DateTime::RFC3339));
        $end->setTimeZone($timezone);
        $event->setEnd($end);

        $calendarId = $this->config->get('calendar.google_calendar.calendar_id', 'primary');

        try {
            $createdEvent = $this->googleCalendarService->events->insert($calendarId, $event);
            $this->logger->info('Google Calendar Event erstellt', [
                'event_id' => $createdEvent->getId(),
                'calendar_id' => $calendarId,
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name']
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Erstellen des Google Calendar Events', [
                'calendar_id' => $calendarId,
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function createHistoricalGoogleCalendarEvent(array $vehicle, array $location, float $distance, \DateTime $startTime, \DateTime $endTime, bool $forceUpdate = false): void
    {
        if (!$this->googleCalendarService) {
            throw new \Exception('Google Calendar Service nicht initialisiert');
        }

        $event = new GoogleEvent();
        $event->setSummary("Fahrzeug {$vehicle['name']} bei {$location['customer_name']} (historisch)");
        $event->setDescription($this->generateEventDescription($vehicle, $location, $distance) . "\n\nHistorischer Besuch");
        $event->setLocation($location['full_address'] ?? 'Adresse unbekannt');

        $start = new EventDateTime();
        $start->setDateTime($startTime->format(\DateTime::RFC3339));
        $start->setTimeZone($startTime->getTimezone()->getName());
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDateTime($endTime->format(\DateTime::RFC3339));
        $end->setTimeZone($endTime->getTimezone()->getName());
        $event->setEnd($end);

        $calendarId = $this->config->get('calendar.google_calendar.calendar_id', 'primary');

        try {
            if ($forceUpdate) {
                // Versuche vorhandenes Event zu finden und zu aktualisieren
                $existingEvents = $this->googleCalendarService->events->listEvents($calendarId, [
                    'q' => "Fahrzeug {$vehicle['name']} bei {$location['customer_name']}",
                    'timeMin' => $startTime->format(\DateTime::RFC3339),
                    'timeMax' => $endTime->format(\DateTime::RFC3339),
                    'singleEvents' => true
                ]);

                if (count($existingEvents->getItems()) > 0) {
                    $existingEvent = $existingEvents->getItems()[0];
                    $updatedEvent = $this->googleCalendarService->events->update($calendarId, $existingEvent->getId(), $event);
                    $this->logger->info('Google Calendar Event aktualisiert', [
                        'event_id' => $updatedEvent->getId(),
                        'calendar_id' => $calendarId
                    ]);
                } else {
                    $createdEvent = $this->googleCalendarService->events->insert($calendarId, $event);
                    $this->logger->info('Google Calendar Event erstellt', [
                        'event_id' => $createdEvent->getId(),
                        'calendar_id' => $calendarId
                    ]);
                }
            } else {
                $createdEvent = $this->googleCalendarService->events->insert($calendarId, $event);
                $this->logger->info('Historisches Google Calendar Event erstellt', [
                    'event_id' => $createdEvent->getId(),
                    'calendar_id' => $calendarId,
                    'vehicle' => $vehicle['name'],
                    'customer' => $location['customer_name'],
                    'start_time' => $startTime->format('Y-m-d H:i:s'),
                    'end_time' => $endTime->format('Y-m-d H:i:s')
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Erstellen/Aktualisieren des historischen Google Calendar Events', [
                'calendar_id' => $calendarId,
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function hasExistingGoogleCalendarHistoricalEntry(array $vehicle, array $location, int $timestamp): bool
    {
        if (!$this->googleCalendarService) {
            return false;
        }

        try {
            $calendarId = $this->config->get('calendar.google_calendar.calendar_id', 'primary');

            // Erstelle Suchkriterien für ähnliche Events
            $startTime = date('c', $timestamp); // ISO 8601 Format
            $endTime = date('c', $timestamp + 3600); // 1 Stunde später

            // Suche nach Events mit ähnlichem Titel im Zeitbereich
            $eventTitle = "Fahrzeug {$vehicle['name']} bei {$location['customer_name']}";

            $events = $this->googleCalendarService->events->listEvents($calendarId, [
                'q' => $eventTitle,
                'timeMin' => $startTime,
                'timeMax' => $endTime,
                'singleEvents' => true,
                'orderBy' => 'startTime'
            ]);

            if ($events->getItems()) {
                $this->logger->debug('Existierendes Google Calendar Event gefunden', [
                    'vehicle' => $vehicle['name'],
                    'customer' => $location['customer_name'],
                    'timestamp' => date('Y-m-d H:i:s', $timestamp),
                    'found_events' => count($events->getItems())
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->logger->debug('Google Calendar Event-Prüfung fehlgeschlagen', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'timestamp' => date('Y-m-d H:i:s', $timestamp),
                'error' => $e->getMessage()
            ]);
            return false;
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
        $ical .= $this->foldICalLine("SUMMARY", $summary);
        $ical .= $this->foldICalLine("DESCRIPTION", $description);
        $ical .= $this->foldICalLine("LOCATION", $locationText);
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
        $ical .= $this->foldICalLine("SUMMARY", $summary);
        $ical .= $this->foldICalLine("DESCRIPTION", $description);
        $ical .= $this->foldICalLine("LOCATION", $locationText);
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
        
        // Begrenzt auf vernünftige Länge für iCalendar (RFC empfiehlt max 998 Zeichen pro Zeile)
        // Aber für DESCRIPTION können wir mehr verwenden da es gefaltet wird
        if (strlen($text) > 2000) {
            $text = substr($text, 0, 1997) . '...';
        }
        
        return $text;
    }
    
    /**
     * Faltet iCalendar-Zeilen gemäß RFC 5545 (max 75 Zeichen pro Zeile)
     */
    private function foldICalLine(string $property, string $value): string
    {
        $line = $property . ':' . $value;
        
        // Wenn die Zeile kürzer als 75 Zeichen ist, keine Faltung nötig
        if (strlen($line) <= 75) {
            return $line . "\r\n";
        }
        
        $result = '';
        $pos = 0;
        $lineLength = strlen($line);
        
        // Erste Zeile: max 75 Zeichen
        $firstLineLength = min(75, $lineLength);
        $result .= substr($line, 0, $firstLineLength) . "\r\n";
        $pos += $firstLineLength;
        
        // Fortsetzungszeilen: max 74 Zeichen (wegen Leerzeichen am Anfang)
        while ($pos < $lineLength) {
            $remainingLength = $lineLength - $pos;
            $chunkLength = min(74, $remainingLength);
            
            // Fortsetzungszeile mit führendem Leerzeichen
            $result .= ' ' . substr($line, $pos, $chunkLength) . "\r\n";
            $pos += $chunkLength;
        }
        
        return $result;
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
        $ical .= $this->foldICalLine("SUMMARY", $summary);
        $ical .= $this->foldICalLine("DESCRIPTION", $description);
        $ical .= $this->foldICalLine("LOCATION", $location['full_address'] ?? 'Adresse unbekannt');
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
        
        // Validiere iCalendar Content vor dem Senden
        $this->validateICalendarContent($icalContent, $eventId);

        // Versuche zuerst mit If-Match Header
        $response = $this->davClient->request('PUT', $eventPath, $icalContent, [
            'Content-Type' => 'text/calendar',
            'If-Match' => '*'
        ]);

        // Wenn 412 (Precondition Failed), versuche ohne If-Match Header
        if ($response['statusCode'] === 412) {
            $this->logger->debug('If-Match fehlgeschlagen, versuche ohne Precondition', [
                'event_id' => $eventId,
                'event_path' => $eventPath
            ]);
            
            $response = $this->davClient->request('PUT', $eventPath, $icalContent, [
                'Content-Type' => 'text/calendar'
            ]);
        }

        if ($response['statusCode'] >= 400) {
            $errorDetails = [
                'status_code' => $response['statusCode'],
                'response_body' => $response['body'] ?? 'No response body',
                'event_path' => $eventPath,
                'caldav_url' => $this->config->get('calendar.caldav_url'),
                'event_id' => $eventId
            ];
            
            $errorMessage = $this->getDetailedCalDavError($response['statusCode'], $response['body'] ?? '', $errorDetails);
            throw new \Exception($errorMessage);
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
                // Prüfe Google Calendar falls verfügbar
                if ($this->googleCalendarService && $this->hasExistingGoogleCalendarEntry($vehicle, $location)) {
                    return 'google-calendar-event';
                }
                throw new \Exception('CalDAV Client nicht initialisiert und kein Google Calendar verfügbar');
            }
            
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

    private function hasExistingGoogleCalendarEntry(array $vehicle, array $location): bool
    {
        if (!$this->googleCalendarService) {
            return false;
        }

        try {
            $calendarId = $this->config->get('calendar.google_calendar.calendar_id', 'primary');

            // Suche nach Events mit ähnlichem Titel für heute
            $today = date('Y-m-d');
            $startTime = $today . 'T00:00:00Z';
            $endTime = $today . 'T23:59:59Z';

            $eventTitle = "Fahrzeug {$vehicle['name']} bei {$location['customer_name']}";

            $events = $this->googleCalendarService->events->listEvents($calendarId, [
                'q' => $eventTitle,
                'timeMin' => $startTime,
                'timeMax' => $endTime,
                'singleEvents' => true,
                'orderBy' => 'startTime'
            ]);

            if ($events->getItems()) {
                $this->logger->debug('Existierendes Google Calendar Event gefunden', [
                    'vehicle' => $vehicle['name'],
                    'customer' => $location['customer_name'],
                    'found_events' => count($events->getItems())
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->logger->debug('Google Calendar Event-Prüfung fehlgeschlagen', [
                'vehicle' => $vehicle['name'],
                'customer' => $location['customer_name'],
                'error' => $e->getMessage()
            ]);
            return false;
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
    
    /**
     * Validiert iCalendar Content auf häufige Probleme
     */
    private function validateICalendarContent(string $icalContent, string $eventId): void
    {
        // Grundlegende Strukturprüfung
        if (!str_contains($icalContent, 'BEGIN:VCALENDAR')) {
            throw new \Exception("iCalendar Content ungültig: Fehlendes BEGIN:VCALENDAR (Event ID: {$eventId})");
        }
        
        if (!str_contains($icalContent, 'END:VCALENDAR')) {
            throw new \Exception("iCalendar Content ungültig: Fehlendes END:VCALENDAR (Event ID: {$eventId})");
        }
        
        if (!str_contains($icalContent, 'BEGIN:VEVENT')) {
            throw new \Exception("iCalendar Content ungültig: Fehlendes BEGIN:VEVENT (Event ID: {$eventId})");
        }
        
        if (!str_contains($icalContent, 'END:VEVENT')) {
            throw new \Exception("iCalendar Content ungültig: Fehlendes END:VEVENT (Event ID: {$eventId})");
        }
        
        // Größenprüfung (iCalendar RFC empfiehlt max 998 Zeichen pro Zeile)
        $lines = explode("\n", $icalContent);
        foreach ($lines as $lineNum => $line) {
            if (strlen($line) > 998) {
                throw new \Exception("iCalendar Zeile zu lang (Zeile " . ($lineNum + 1) . ", " . strlen($line) . " Zeichen): " . substr($line, 0, 100) . "... (Event ID: {$eventId})");
            }
        }
        
        // Content-Größenprüfung (NextCloud hat oft Limits)
        if (strlen($icalContent) > 65536) { // 64KB Limit
            throw new \Exception("iCalendar Content zu groß (" . strlen($icalContent) . " Bytes, max 64KB): Event ID {$eventId}");
        }
        
        // Prüfe auf problematische Zeichen
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $icalContent)) {
            throw new \Exception("iCalendar Content enthält ungültige Steuerzeichen (Event ID: {$eventId})");
        }
    }
    
    /**
     * Gibt detaillierte Fehlermeldungen für CalDAV Probleme zurück
     */
    private function getDetailedCalDavError(int $statusCode, string $responseBody, array $errorDetails): string
    {
        $baseMessage = "CalDAV Fehler (Status {$statusCode})";
        
        switch ($statusCode) {
            case 400:
                return "{$baseMessage}: Ungültige Anfrage - iCalendar Format fehlerhaft. " .
                       "Prüfen Sie Datum/Zeit-Formate und Zeichen-Escaping. " .
                       "Details: " . json_encode($errorDetails);
                       
            case 401:
                return "{$baseMessage}: Authentifizierung fehlgeschlagen. " .
                       "Prüfen Sie Benutzername und Passwort in der Konfiguration.";
                       
            case 403:
                return "{$baseMessage}: Zugriff verweigert. " .
                       "Der Benutzer hat keine Berechtigung zum Schreiben in diesen Kalender.";
                       
            case 404:
                return "{$baseMessage}: Kalender nicht gefunden. " .
                       "Prüfen Sie die CalDAV URL: " . $errorDetails['caldav_url'];
                       
            case 409:
                return "{$baseMessage}: Konflikt - Event existiert bereits oder Versionsfehler. " .
                       "Event ID: " . $errorDetails['event_id'];
                       
            case 412:
                return "{$baseMessage}: Precondition Failed - If-Match Header Problem. " .
                       "Der Event existiert möglicherweise nicht oder wurde zwischenzeitlich geändert. " .
                       "Event ID: " . $errorDetails['event_id'];
                       
            case 413:
                return "{$baseMessage}: Payload zu groß. " .
                       "iCalendar Content ist zu umfangreich (" . $errorDetails['ical_content_length'] . " Bytes). " .
                       "Kürzen Sie die Beschreibung oder entfernen Sie Details.";
                       
            case 415:
                return "{$baseMessage}: Unsupported Media Type. " .
                       "CalDAV Server akzeptiert das iCalendar Format nicht. " .
                       "Möglicherweise fehlerhafte MIME-Type oder Content-Encoding.";
                       
            case 422:
                return "{$baseMessage}: Unverarbeitbare Entität. " .
                       "iCalendar ist syntaktisch korrekt, aber semantisch ungültig. " .
                       "Prüfen Sie Datum/Zeit-Bereiche und Zeitzone-Definitionen.";
                       
            case 500:
                return "{$baseMessage}: Server-Fehler. " .
                       "Der CalDAV Server hat einen internen Fehler. " .
                       "Response: " . substr($responseBody, 0, 200);
                       
            case 502:
            case 503:
            case 504:
                return "{$baseMessage}: Server temporär nicht verfügbar. " .
                       "Versuchen Sie es später erneut.";
                       
            default:
                return "{$baseMessage}: Unbekannter Fehler. " .
                       "Response: " . substr($responseBody, 0, 200) . " " .
                       "Details: " . json_encode($errorDetails);
        }
    }
}
