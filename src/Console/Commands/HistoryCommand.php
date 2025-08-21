<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\PajApiService;
use PajGpsCalendar\Services\CrmService;
use PajGpsCalendar\Services\CalendarService;
use PajGpsCalendar\Services\GeoService;
use PajGpsCalendar\Services\BlindSpotService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class HistoryCommand extends Command
{
    protected static $defaultName = 'history';
    
    private ConfigService $config;
    private Logger $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Analysiert historische Fahrzeugdaten und erstellt Kalendereinträge')
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Anzahl der Tage in der Vergangenheit die analysiert werden sollen',
                7
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Startdatum der Analyse (Format: YYYY-MM-DD oder DD.MM.YYYY)'
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Enddatum der Analyse (Format: YYYY-MM-DD oder DD.MM.YYYY)'
            )
            ->addOption(
                'vehicle-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Spezifische Fahrzeug-ID die analysiert werden soll'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Führt eine Testausführung durch ohne Kalendereinträge zu erstellen'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeServices();
            
            $days = (int) $input->getOption('days');
            $fromDate = $input->getOption('from');
            $toDate = $input->getOption('to');
            $vehicleId = $input->getOption('vehicle-id');
            $dryRun = $input->getOption('dry-run');
            
            $output->writeln('<info>PAJ GPS to Calendar - Historische Datenanalyse</info>');
            
            if ($dryRun) {
                $output->writeln('<comment>Dry-Run Modus aktiviert</comment>');
            }

            // Services initialisieren
            $pajApi = new PajApiService($this->config, $this->logger);
            $geo = new GeoService($this->config, $this->logger);
            $crm = new CrmService($this->config, $this->logger, $geo);
            $calendar = new CalendarService($this->config, $this->logger);
            $blindSpot = new BlindSpotService($this->config);

            // Zeitraum definieren
            $timezone = new \DateTimeZone($this->config->get('settings.timezone', 'Europe/Berlin'));
            
            if ($fromDate && $toDate) {
                // Von-Bis Datumsbereich verwenden
                $startDate = $this->parseDate($fromDate, $timezone);
                $endDate = $this->parseDate($toDate, $timezone);
                
                // Validierung: Start-Datum muss vor End-Datum liegen
                if ($startDate > $endDate) {
                    $output->writeln('<error>Das Start-Datum muss vor dem End-Datum liegen!</error>');
                    return Command::FAILURE;
                }
                
                // Endzeit auf Ende des Tages setzen
                $endDate->setTime(23, 59, 59);
                
                $daysDiff = $endDate->diff($startDate)->days + 1;
                $output->writeln(sprintf('Zeitraum: %s bis %s (%d Tage)', 
                    $startDate->format('d.m.Y'), 
                    $endDate->format('d.m.Y'),
                    $daysDiff
                ));
            } else {
                // Traditioneller Tage-Parameter
                $endDate = new \DateTime('now', $timezone);
                $startDate = (clone $endDate)->sub(new \DateInterval("P{$days}D"));
                $output->writeln(sprintf('Zeitraum: %d Tage zurück', $days));
            }
            
            $output->writeln(sprintf('Von: %s bis: %s', 
                $startDate->format('d.m.Y H:i'), 
                $endDate->format('d.m.Y H:i')
            ));

            // Fahrzeuge abrufen
            $vehicles = $pajApi->getVehicles();
            
            if ($vehicleId) {
                $vehicles = array_filter($vehicles, function($v) use ($vehicleId) {
                    return $v['id'] === $vehicleId;
                });
                
                if (empty($vehicles)) {
                    $output->writeln('<error>Fahrzeug mit ID ' . $vehicleId . ' nicht gefunden</error>');
                    return Command::FAILURE;
                }
            }

            // CRM-System testen
            $output->writeln(sprintf('Verbinde mit CRM-System: %s', $crm->getCrmSystemName()));
            if (!$crm->testCrmConnection()) {
                $output->writeln('<error>CRM-Verbindung fehlgeschlagen</error>');
                return Command::FAILURE;
            }

            $totalEntriesCreated = 0;
            $totalVisitsFound = 0;
            $proximityThreshold = $this->config->get('settings.proximity_threshold_meters', 500);
            $minimumVisitDuration = $this->config->get('settings.minimum_visit_duration_minutes', 2);

            // Zeitraum für PAJ API konvertieren
            $dateStartUnix = $startDate->getTimestamp();
            $dateEndUnix = $endDate->getTimestamp();

            foreach ($vehicles as $vehicle) {
                $output->writeln(sprintf('Analysiere Fahrzeug: %s (%s)', $vehicle['name'], $vehicle['id']));
                
                // Historische Besuche analysieren
                $visits = $pajApi->analyzeHistoricalVisits(
                    $vehicle['id'], 
                    $dateStartUnix, 
                    $dateEndUnix, 
                    $minimumVisitDuration
                );
                
                $output->writeln(sprintf('  Gefundene Besuche: %d', count($visits)));
                $totalVisitsFound += count($visits);
                
                $entriesForVehicle = 0;
                
                foreach ($visits as $visit) {
                    // Prüfe ob Position in blindem Fleck liegt
                    $blindSpotInfo = $blindSpot->isInBlindSpot($visit['latitude'], $visit['longitude']);
                    if ($blindSpotInfo) {
                        $output->writeln(sprintf(
                            '    🚫 Besuch in blindem Fleck "%s" - überspringe (%s)',
                            $blindSpotInfo['name'],
                            $visit['start_time_formatted']
                        ));
                        continue;
                    }
                    
                    // Kunden in der Nähe finden
                    $nearbyCustomers = $crm->findCustomersNearLocation(
                        $visit['latitude'],
                        $visit['longitude'],
                        $proximityThreshold
                    );
                    
                    if (empty($nearbyCustomers)) {
                        $output->writeln(sprintf(
                            '    📍 Kein Kunde in der Nähe (%s, %.0f Min)',
                            $visit['start_time_formatted'],
                            $visit['duration_minutes']
                        ));
                        continue;
                    }
                    
                    // Nächstgelegenen Kunden wählen
                    $closestCustomer = null;
                    $minDistance = PHP_FLOAT_MAX;
                    
                    foreach ($nearbyCustomers as $customer) {
                        if ($customer['distance_meters'] < $minDistance) {
                            $minDistance = $customer['distance_meters'];
                            $closestCustomer = $customer;
                        }
                    }
                    
                    $output->writeln(sprintf(
                        '    🎯 Besuch bei %s (%s bis %s, %.0f Min, %.0fm)',
                        $closestCustomer['customer_name'],
                        $visit['start_time_formatted'],
                        $visit['end_time_formatted'],
                        $visit['duration_minutes'],
                        $closestCustomer['distance_meters']
                    ));
                    
                    // Kalendereintrag erstellen
                    if (!$dryRun) {
                        // Erstelle Fahrzeugdaten im erwarteten Format
                        $vehicleData = [
                            'id' => $vehicle['id'],
                            'name' => $vehicle['name'],
                            'latitude' => $visit['latitude'],
                            'longitude' => $visit['longitude'],
                            'speed' => 0
                        ];
                        
                        // Prüfe auf existierenden historischen Eintrag
                        if (!$calendar->hasExistingHistoricalEntry($vehicleData, $closestCustomer, $visit['start_time'])) {
                            // Verwende die neue historische Methode mit tatsächlichen Zeiten
                            if ($calendar->createHistoricalEntry(
                                $vehicleData, 
                                $closestCustomer, 
                                $closestCustomer['distance_meters'], 
                                $visit['start_time'], 
                                $visit['end_time']
                            )) {
                                $entriesForVehicle++;
                                $totalEntriesCreated++;
                                $output->writeln('      ✅ Kalendereintrag erstellt');
                            } else {
                                $output->writeln('      ❌ Fehler beim Erstellen des Kalendereintrags');
                            }
                        } else {
                            $output->writeln('      ⏭️  Kalendereintrag bereits vorhanden');
                        }
                    } else {
                        $output->writeln('      📅 Kalendereintrag würde erstellt werden (Dry-Run)');
                    }
                }
                
                $output->writeln(sprintf('  %d Einträge für Fahrzeug %s erstellt', 
                    $entriesForVehicle, $vehicle['name']
                ));
            }

            $output->writeln(sprintf('<success>Historische Analyse abgeschlossen!</success>'));
            $output->writeln(sprintf('Besuche gefunden: %d', $totalVisitsFound));
            $output->writeln(sprintf('Kalendereinträge erstellt: %d', $totalEntriesCreated));

            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Fehler: %s</error>', $e->getMessage()));
            $this->logger->error('History command execution failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
    
    /**
     * Parst ein Datum in verschiedenen Formaten
     */
    private function parseDate(string $dateString, \DateTimeZone $timezone): \DateTime
    {
        $dateString = trim($dateString);
        
        // Unterstützte Formate
        $formats = [
            'Y-m-d',        // 2025-08-15
            'd.m.Y',        // 15.08.2025
            'd/m/Y',        // 15/08/2025
            'd-m-Y',        // 15-08-2025
            'Y/m/d',        // 2025/08/15
            'd.m.y',        // 15.08.25
            'd/m/y',        // 15/08/25
        ];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateString, $timezone);
            if ($date !== false) {
                // Startzeit auf Beginn des Tages setzen
                $date->setTime(0, 0, 0);
                return $date;
            }
        }
        
        // Fallback: Versuche natürliche Sprache
        try {
            $date = new \DateTime($dateString, $timezone);
            $date->setTime(0, 0, 0);
            return $date;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Ungültiges Datumsformat: '{$dateString}'. " . 
                "Unterstützte Formate: YYYY-MM-DD, DD.MM.YYYY, DD/MM/YYYY, etc."
            );
        }
    }    private function initializeServices(): void
    {
        $this->config = new ConfigService();
        
        // Logger einrichten
        $this->logger = new Logger('paj-gps-calendar');
        $logFile = $this->config->get('settings.log_file', 'logs/application.log');
        $logLevel = $this->config->get('settings.log_level', 'info');
        
        $this->logger->pushHandler(new StreamHandler($logFile, $logLevel));
    }
}
