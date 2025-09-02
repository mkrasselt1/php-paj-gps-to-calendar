<?php

namespace PajGpsCalendar\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PajGpsCalendar\Services\ConfigService;
use PajGpsCalendar\Services\PajApiService;
use PajGpsCalendar\Services\DatabaseService;
use PajGpsCalendar\Services\CalendarService;
use PajGpsCalendar\Services\CrmService;
use PajGpsCalendar\Services\GeoService;
use PajGpsCalendar\Services\VisitDurationService;
use PajGpsCalendar\Services\BlindSpotService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CheckCommand extends Command
{
    protected static $defaultName = 'check';
    
    private ConfigService $config;
    private Logger $logger;

    protected function configure(): void
    {
        $this
            ->setDescription('Überprüft aktuelle Fahrzeugpositionen und erstellt Kalendereinträge')
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
            
            $output->writeln('<info>PAJ GPS to Calendar - Überprüfung gestartet</info>');
            
            $dryRun = $input->getOption('dry-run');
            if ($dryRun) {
                $output->writeln('<comment>Dry-Run Modus aktiviert - keine Kalendereinträge werden erstellt</comment>');
            }

            // Services initialisieren
            $pajApi = new PajApiService($this->config, $this->logger);
            $crm = new CrmService($this->config, $this->logger, new GeoService($this->config, $this->logger));
            $calendar = new CalendarService($this->config, $this->logger);
            $visitDuration = new VisitDurationService($this->config, $this->logger);
            $blindSpot = new BlindSpotService($this->config);

            // Fahrzeuge abrufen
            $output->writeln('Lade Fahrzeugdaten...');
            $vehicles = $pajApi->getVehicles();
            $output->writeln(sprintf('Gefunden: %d Fahrzeuge', count($vehicles)));

            // CRM-System testen
            $output->writeln(sprintf('Verbinde mit CRM-System: %s', $crm->getCrmSystemName()));
            if (!$crm->testCrmConnection()) {
                $output->writeln('<error>CRM-Verbindung fehlgeschlagen</error>');
                return Command::FAILURE;
            }

            $entriesCreated = 0;
            $proximityThreshold = $this->config->get('settings.proximity_threshold_meters', 500);
            $minimumVisitDuration = $this->config->get('settings.minimum_visit_duration_minutes', 2);
            
            foreach ($vehicles as $vehicle) {
                $output->writeln(sprintf('Prüfe Fahrzeug: %s', $vehicle['name']));
                
                // Prüfe ob Fahrzeug in einem blinden Fleck steht
                $blindSpotInfo = $blindSpot->isInBlindSpot($vehicle['latitude'], $vehicle['longitude']);
                if ($blindSpotInfo) {
                    $output->writeln(sprintf(
                        '  🚫 Fahrzeug in blindem Fleck "%s" (%.0fm) - überspringe',
                        $blindSpotInfo['name'],
                        $blindSpotInfo['distance_meters']
                    ));
                    continue;
                }
                
                // ✨ VERBESSERTE STOPDURATION: Priorisiere PAJ-API-Daten
                $speed = $vehicle['speed'] ?? 0;
                $pajStopDuration = $vehicle['stop_duration_minutes'] ?? 0;
                $movementDetected = $speed > 1;

                // Intelligente Logik: PAJ-API-Daten haben Priorität
                $stopDuration = $pajStopDuration;

                // Nur wenn PAJ-Daten gering sind UND Fahrzeug steht: detaillierte Analyse
                if ($pajStopDuration < 1 && !$movementDetected) {
                    try {
                        $detailedDuration = $pajApi->getStopDuration(
                            $vehicle['id'],
                            $vehicle['timestamp'] ?? time(),
                            $speed
                        );
                        if ($detailedDuration > $stopDuration) {
                            $stopDuration = $detailedDuration;
                        }
                    } catch (\Exception $e) {
                        // Bei Fehler: PAJ-Daten behalten
                    }
                }
                
                $movementIcon = $movementDetected ? '�' : '�';
                $speedIcon = $speed > 5 ? '�' : ($speed > 1 ? '�' : '🛑');
                
                $output->writeln(sprintf(
                    '  📊 Status: %s %.1f km/h | ⏱️ %d Min gestanden | %s | 📍 %d Positionen',
                    $speedIcon,
                    $speed,
                    $stopDuration,
                    $movementDetected ? 'Bewegung erkannt' : 'Keine Bewegung',
                    $detailedAnalysis['position_count'] ?? 0
                ));
                
                // Kunden in der Nähe finden
                $nearbyCustomers = $crm->findCustomersNearLocation(
                    $vehicle['latitude'],
                    $vehicle['longitude'],
                    $proximityThreshold
                );
                
                if (empty($nearbyCustomers)) {
                    $output->writeln('  -> Keine Kunden in der Nähe');
                    continue;
                }
                
                // *** NEUE LOGIK: Nur den nächstgelegenen Kunden berücksichtigen ***
                $closestCustomer = null;
                $minDistance = PHP_FLOAT_MAX;
                
                foreach ($nearbyCustomers as $customer) {
                    if ($customer['distance_meters'] < $minDistance) {
                        $minDistance = $customer['distance_meters'];
                        $closestCustomer = $customer;
                    }
                }
                
                // Zeige alle Kunden, aber verarbeite nur den nächsten
                foreach ($nearbyCustomers as $customer) {
                    $isClosest = ($customer === $closestCustomer);
                    $indicator = $isClosest ? '🎯' : '📍';
                    
                    $output->writeln(sprintf(
                        '  %s In der Nähe von %s (%.0fm)%s', 
                        $indicator,
                        $customer['customer_name'], 
                        $customer['distance_meters'],
                        $isClosest ? ' - NÄCHSTER' : ''
                    ));
                    
                    // Nur für den nächsten Kunden Besuchslogik ausführen
                    if (!$isClosest) {
                        continue;
                    }
                    
                    // ✨ VERBESSERTE BESUCHSERKENNUNG mit intelligenter StopDuration
                    $shouldCreateEntry = false;
                    $reason = '';

                    // 1. Haupt-Kriterium: Mindestaufenthaltsdauer erreicht
                    if ($stopDuration >= 1 && $speed < 2) {
                        $shouldCreateEntry = true;
                        $reason = sprintf('✅ Besuch erkannt: %d Min gestanden, %.1f km/h', $stopDuration, $speed);
                    }
                    // 2. Längerer Stopp mit geringer Bewegung (GPS-Drift erlaubt)
                    else if ($stopDuration >= 3 && $speed < 5) {
                        $shouldCreateEntry = true;
                        $reason = sprintf('✅ Besuch erkannt: %d Min gestanden, geringe Bewegung (%.1f km/h)', $stopDuration, $speed);
                    }
                    // 3. Sehr langer Stopp - definitiv ein Besuch
                    else if ($stopDuration >= 10) {
                        $shouldCreateEntry = true;
                        $reason = sprintf('✅ Besuch erkannt: Sehr langer Aufenthalt (%d Min)', $stopDuration);
                    }
                    // 4. Kriterien noch nicht erfüllt - warten
                    else {
                        $reason = sprintf('⏱️ Warte noch: %d Min gestanden (braucht 1 Min), %.1f km/h',
                            $stopDuration, $speed
                        );
                    }
                    
                    $output->writeln(sprintf('    🎯 Entscheidung: %s', $reason));
                    
                    // Prüfe ob bereits ein Kalendereintrag existiert
                    $hasExistingEntry = $calendar->hasRecentEntry($vehicle, $customer);
                    
                    // Nur Kalendereintrag erstellen wenn:
                    // 1. Besuchskriterien erfüllt
                    // 2. Noch kein Eintrag im Kalender für diesen Besuch
                    if ($shouldCreateEntry && !$hasExistingEntry) {
                        
                        if (!$dryRun) {
                            if ($calendar->createEntry($vehicle, $customer, $customer['distance_meters'])) {
                                $entriesCreated++;
                                $output->writeln('    ✅ Kalendereintrag erstellt');
                            } else {
                                $output->writeln('    ❌ Fehler beim Erstellen des Kalendereintrags');
                            }
                        } else {
                            $output->writeln('    📅 Kalendereintrag würde erstellt werden (Dry-Run)');
                        }
                    } else if ($shouldCreateEntry && $hasExistingEntry) {
                        $output->writeln('    ⏭️  Besuch bereits im Kalender - überspringe');
                    } else {
                        $output->writeln('    ⏱️  Besuchskriterien noch nicht erfüllt');
                    }
                }
                
                // Prüfe beendete Besuche: Fahrzeuge die heute Events haben aber nicht mehr in der Nähe sind
                $todaysEvents = $calendar->getTodaysEventsForVehicle($vehicle['id']);
                foreach ($todaysEvents as $event) {
                    // Prüfe ob Event noch einem aktuellen Besuch entspricht
                    $eventStillActive = false;
                    foreach ($nearbyCustomers as $customer) {
                        $expectedEventId = $calendar->generateEventId($vehicle, $customer);
                        if ($event['event_id'] === $expectedEventId) {
                            $eventStillActive = true;
                            break;
                        }
                    }
                    
                    // Event existiert, aber Fahrzeug ist nicht mehr in der Nähe → Update mit Endzeit
                    if (!$eventStillActive && !$dryRun) {
                        // Parse Kunden-ID aus Event-ID
                        if (preg_match('/paj-gps-.*?-(.+?)-[a-f0-9]+$/', $event['event_id'], $matches)) {
                            $customerId = $matches[1];
                            $customer = $crm->getCustomerById($customerId);
                            if ($customer) {
                                $calendar->updateEntryWithEndTime(
                                    $event['event_id'],
                                    $vehicle,
                                    $customer,
                                    0 // Distanz unbekannt bei Update
                                );
                                
                                $output->writeln(sprintf(
                                    '    🚗 Besuch bei %s beendet und Kalendereintrag aktualisiert', 
                                    $customer['customer_name']
                                ));
                            }
                        }
                    }
                }
            }

            $output->writeln(sprintf('<success>Fertig! %d neue Kalendereinträge erstellt</success>', $entriesCreated));
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Fehler: %s</error>', $e->getMessage()));
            $this->logger->error('Command execution failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
    
    private function initializeServices(): void
    {
        $this->config = new ConfigService();
        
        // Logger einrichten
        $this->logger = new Logger('paj-gps-calendar');
        $logFile = $this->config->get('settings.log_file', 'logs/application.log');
        $logLevel = $this->config->get('settings.log_level', 'info');
        
        $this->logger->pushHandler(new StreamHandler($logFile, $logLevel));
    }
}
