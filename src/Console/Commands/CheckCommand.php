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
            
            foreach ($vehicles as $vehicle) {
                $output->writeln(sprintf('Prüfe Fahrzeug: %s', $vehicle['name']));
                
                // ✨ ERWEITERTE PAJ-ANALYSE mit detaillierter Stopp-Erkennung
                $detailedAnalysis = $pajApi->getDetailedStopAnalysis($vehicle['id'], 30);
                
                $engineStatus = $detailedAnalysis['engine_running'] ?? $vehicle['engine_running'] ?? null;
                $stopDuration = $detailedAnalysis['stop_duration_minutes'] ?? $vehicle['stop_duration_minutes'] ?? 0;
                $batteryPercent = $detailedAnalysis['current_battery'] ?? $vehicle['battery_percent'] ?? 'unbekannt';
                $speed = $detailedAnalysis['average_speed'] ?? $vehicle['speed'] ?? 0;
                $batteryTrend = $detailedAnalysis['battery_trend'] ?? 'unknown';
                $movementDetected = $detailedAnalysis['movement_detected'] ?? false;
                
                $engineIcon = $engineStatus === true ? '🟢' : ($engineStatus === false ? '🔴' : '🟡');
                $engineText = $engineStatus === true ? 'Motor AN' : ($engineStatus === false ? 'Motor AUS' : 'unbekannt');
                $batteryIcon = $batteryTrend === 'increasing' ? '📈' : ($batteryTrend === 'decreasing' ? '📉' : '➡️');
                $movementIcon = $movementDetected ? '🚗' : '🛑';
                
                $output->writeln(sprintf(
                    '  📊 Status: %s %s | %s %s%% | %s %.1f km/h | ⏱️ %d Min | 📍 %d Pos',
                    $engineIcon,
                    $engineText,
                    $batteryIcon,
                    $batteryPercent,
                    $movementIcon,
                    $speed,
                    $stopDuration,
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
                
                foreach ($nearbyCustomers as $customer) {
                    $output->writeln(sprintf(
                        '  -> In der Nähe von %s (%.0fm)', 
                        $customer['customer_name'], 
                        $customer['distance_meters']
                    ));
                    
                    // ✨ SUPER-INTELLIGENTE BESUCHSERKENNUNG mit PAJ-Daten:
                    $shouldCreateEntry = false;
                    $reason = '';
                    
                    // 1. PAJ-basierte Erkennung: Motor definitiv aus + gestanden
                    if ($engineStatus === false && $stopDuration >= 5) {
                        $shouldCreateEntry = true;
                        $reason = sprintf('✅ PAJ: Motor AUS seit %d Min (Batterie: %s)', $stopDuration, $batteryTrend);
                    }
                    // 2. PAJ-basierte Erkennung: Keine Bewegung + längerer Stopp + fallende Batterie
                    else if (!$movementDetected && $stopDuration >= 5 && $batteryTrend === 'decreasing') {
                        $shouldCreateEntry = true;
                        $reason = sprintf('✅ PAJ: Steht %d Min, Batterie fällt (Motor wahrscheinlich AUS)', $stopDuration);
                    }
                    // 3. Konservative PAJ-Erkennung: Längerer Stopp ohne Bewegung
                    else if (!$movementDetected && $stopDuration >= 10 && $speed < 1) {
                        $shouldCreateEntry = true;
                        $reason = sprintf('✅ PAJ: Lange gestanden (%d Min), keine Bewegung', $stopDuration);
                    }
                    // 4. Keine PAJ-Kriterien erfüllt - warten
                    else {
                        $reason = sprintf('⏱️ PAJ: Warte noch (%d Min gestanden, Motor %s, Bewegung %s, %d Positionen)', 
                            $stopDuration, 
                            $engineText,
                            $movementDetected ? 'erkannt' : 'keine',
                            $detailedAnalysis['position_count']
                        );
                    }
                    
                    $output->writeln(sprintf('    🎯 Entscheidung: %s', $reason));
                    
                    // Nur Kalendereintrag erstellen wenn:
                    // 1. Besuchskriterien erfüllt
                    // 2. Noch kein Eintrag für diesen Besuch
                    if ($shouldCreateEntry && 
                        !$visitDuration->isVisitAlreadyConfirmed($vehicle['id'], $customer['customer_id'])) {
                        
                        if (!$dryRun) {
                            if ($calendar->createEntry($vehicle, $customer, $customer['distance_meters'])) {
                                // Besuch bestätigen und in Tracking-DB speichern
                                $eventId = $calendar->getLastCreatedEventId();
                                $visitDuration->confirmVisit($vehicle['id'], $customer['customer_id'], $eventId);
                                $crm->saveVisitEntry($vehicle, $customer, $customer['distance_meters'], $eventId);
                                
                                $entriesCreated++;
                                $output->writeln('    ✅ Kalendereintrag erstellt');
                            }
                        } else {
                            $output->writeln('    📅 Kalendereintrag würde erstellt werden (Dry-Run)');
                        }
                    } else if ($shouldCreateEntry) {
                        $output->writeln('    ⏭️  Besuch bereits bestätigt - überspringe');
                    } else {
                        $output->writeln('    ⏱️  Besuchskriterien noch nicht erfüllt');
                    }
                }
                
                // Prüfe beendete Besuche (Fahrzeug hat sich von Kunden entfernt)
                $currentStats = $visitDuration->getCurrentVisitStatistics();
                foreach ($currentStats as $stat) {
                    if ($stat['vehicle_id'] === $vehicle['id']) {
                        // Prüfe ob Fahrzeug noch in der Nähe ist
                        $stillNearby = false;
                        foreach ($nearbyCustomers as $nearby) {
                            if ($nearby['customer_id'] === $stat['customer_id']) {
                                $stillNearby = true;
                                break;
                            }
                        }
                        
                        if (!$stillNearby) {
                            $visitDuration->endVisit($stat['vehicle_id'], $stat['customer_id']);
                            $output->writeln(sprintf(
                                '    🚗 Besuch bei %s beendet', 
                                $stat['customer_name']
                            ));
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
